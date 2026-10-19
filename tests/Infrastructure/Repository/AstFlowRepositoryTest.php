<?php

namespace Tests\Infrastructure\Repository;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use PHPUnit\Framework\TestCase;
use FlowEngine\Infrastructure\Repository\AstFlowRepository;
use FlowEngine\Infrastructure\Analyzer\ProjectScanner;
use FlowEngine\Infrastructure\Analyzer\FileParser;
use FlowEngine\Infrastructure\Analyzer\AstParser;
use FlowEngine\Infrastructure\Analyzer\FlowBuilder;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Cache\PerFileCache;
use FlowEngine\Infrastructure\Cache\AnalysisSignature;
use FlowEngine\Infrastructure\Paths\StateDirectory;
use FlowEngine\Application\Policy\NodeVisibilityPolicy;
use FlowEngine\Domain\Node\NodeVisibility;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Contracts\ProjectContext;
use Tests\Support\TestProjectContext;
use Tests\Support\AlwaysPublicVisibilityPolicy;

final class AstFlowRepositoryTest extends TestCase
{
    private AstFlowRepository $repository;

    protected function setUp(): void
    {
        $scanner = $this->createMock(ProjectScanner::class);

        $fixtureFile = __DIR__ . '/../Fixtures/ExampleProject/App/src/Calculator.php';

        $scanner
            ->method('scan')
            ->with($this->isInstanceOf(ProjectContext::class))
            ->willReturn([$fixtureFile]);

        $nodeFactory = new DefaultNodeFactory();

        $this->repository = new AstFlowRepository(
            scanner: $scanner,
            parser: new AstParser($nodeFactory),
            builder: new FlowBuilder(
                new AlwaysPublicVisibilityPolicy()
            ),
            context: new TestProjectContext('/irrelevant')
        );
    }

    public function test_it_analyzes_project_and_returns_nodes(): void
    {
        $nodes = $this->repository->getNodes();

        $this->assertNotEmpty($nodes);
        $this->assertContainsOnlyInstancesOf(Node::class, $nodes);
    }

    public function test_it_finds_node_by_id(): void
    {
        $nodes = $this->repository->getNodes();

        // pega o ID REAL gerado pelo AST
        $firstNode = $nodes[0];
        $id = $firstNode->id();

        $node = $this->repository->getNode($id);

        $this->assertInstanceOf(Node::class, $node);
        $this->assertSame($id, $node->id());
    }

    public function test_it_throws_when_node_not_found(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->getNode('Definitely\\Unknown::method');
    }

    public function test_it_does_not_publish_cache_when_source_changes_during_analysis(): void
    {
        $root = sys_get_temp_dir() . '/ast-flow-race-' . uniqid();
        mkdir($root . '/src', 0777, true);
        $source = $root . '/src/App.php';
        file_put_contents($source, '<?php final class App {}');
        $state = $root . '/state';
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $state);

        $scanner = $this->createStub(ProjectScanner::class);
        $scanner->method('scan')->willReturn([$source]);
        $parser = $this->createStub(FileParser::class);
        $parser->method('parse')->willReturnCallback(static function () use ($source): array {
            file_put_contents($source, '<?php final class Changed {}');
            return ['nodes' => [], 'edges' => []];
        });
        $context = new TestProjectContext($root);
        $repository = new AstFlowRepository(
            $scanner,
            $parser,
            new FlowBuilder(new AlwaysPublicVisibilityPolicy()),
            $context,
            new FlowCache($context),
            perFileCache: new PerFileCache($context),
        );

        try {
            $repository->analyze();
            self::fail('Analysis should reject a source mutation');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed during analysis', $error->getMessage());
            $cacheDirectory = StateDirectory::forProjectRoot($root) . '/cache';
            self::assertFileDoesNotExist($cacheDirectory . '/flow.json.gz');
            self::assertFileDoesNotExist($cacheDirectory . '/per-file.json.gz');
        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
            $this->removeDirectory($root);
        }
    }

    public function test_it_rejects_cache_hit_when_scan_membership_changes_before_return(): void
    {
        $root = sys_get_temp_dir() . '/ast-flow-cache-hit-race-' . uniqid();
        mkdir($root . '/src', 0777, true);
        $sourceA = $root . '/src/A.php';
        $sourceB = $root . '/src/B.php';
        file_put_contents($sourceA, '<?php final class A {}');
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $root . '/state');
        $context = new TestProjectContext($root);
        $cache = new FlowCache($context);
        $cache->saveFlow(
            new \FlowEngine\Domain\Flow\Flow([], []),
            [$sourceA],
            $root . '/flow-engine.json',
            analysisSignature: AnalysisSignature::compute(),
        );
        file_put_contents($sourceB, '<?php final class B {}');
        $scanner = $this->createStub(ProjectScanner::class);
        $scanner->method('scan')->willReturnOnConsecutiveCalls([$sourceA], [$sourceA, $sourceB]);
        $parser = $this->createMock(FileParser::class);
        $parser->expects(self::never())->method('parse');
        $repository = new AstFlowRepository(
            $scanner,
            $parser,
            new FlowBuilder(new AlwaysPublicVisibilityPolicy()),
            $context,
            $cache,
        );

        try {
            $repository->analyze();
            self::fail('Cache hit should reject changed scan membership');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed during analysis', $error->getMessage());
        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
            $this->removeDirectory($root);
        }
    }

    public function test_it_rechecks_sources_after_graph_construction(): void
    {
        $root = sys_get_temp_dir() . '/ast-flow-build-race-' . uniqid();
        mkdir($root . '/src', 0777, true);
        $source = $root . '/src/App.php';
        file_put_contents($source, '<?php final class App {}');
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $root . '/state');

        $scanner = $this->createStub(ProjectScanner::class);
        $scanner->method('scan')->willReturn([$source]);
        $parser = $this->createStub(FileParser::class);
        $parser->method('parse')->willReturn([
            'nodes' => [new Node('App', 'run', $source, 1)],
            'edges' => [],
        ]);
        $policy = new class($source) implements NodeVisibilityPolicy {
            public function __construct(private string $source) {}

            public function visibility(Node $node): NodeVisibility
            {
                file_put_contents($this->source, '<?php final class Changed {}');
                return new NodeVisibility(NodeVisibility::PUBLIC);
            }
        };
        $context = new TestProjectContext($root);
        $repository = new AstFlowRepository(
            $scanner,
            $parser,
            new FlowBuilder($policy),
            $context,
            new FlowCache($context),
            perFileCache: new PerFileCache($context),
        );

        try {
            $repository->analyze();
            self::fail('Analysis should reject a mutation during graph construction');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed during analysis', $error->getMessage());
        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
