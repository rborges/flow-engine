<?php

namespace FlowEngine\Infrastructure\Repository;

use FlowEngine\Application\DTO\SymbolDTO;
use FlowEngine\Domain\Contracts\FlowRepository;
use FlowEngine\Domain\Contracts\Flow as FlowContract;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\SymbolIndex;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Analyzer\ProjectScanner;
use FlowEngine\Infrastructure\Analyzer\ScanDiagnosticsProvider;
use FlowEngine\Infrastructure\Analyzer\FileParser;
use FlowEngine\Infrastructure\Analyzer\FlowBuilder;
use FlowEngine\Infrastructure\Analyzer\CrossLanguageEdgeDetector;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Cache\PerFileCache;
use FlowEngine\Infrastructure\Cache\AnalysisSignature;
use FlowEngine\Infrastructure\Cache\ContentFingerprint;

final class AstFlowRepository implements FlowRepository
{
    private ?Flow $flow = null;
    private ?string $cacheHash = null;

    /** @var string[] */
    private array $duplicateIds = [];

    /** @var string[] */
    private array $scanWarnings = [];

    public function __construct(
        private ProjectScanner $scanner,
        private FileParser $parser,
        private FlowBuilder $builder,
        private ProjectContext $context,
        private ?FlowCache $cache = null,
        private ?CrossLanguageEdgeDetector $crossLanguageDetector = null,
        private ?PerFileCache $perFileCache = null,
        private string $analysisContext = '',
    ) {
    }

    /**
     * @api 
     */
    public function analyze(): void
    {
        if ($this->flow !== null) {
            return;
        }

        $this->context->boot();

        $files = $this->scanner->scan($this->context);
        $this->scanWarnings = $this->scanner instanceof ScanDiagnosticsProvider
            ? $this->scanner->scanWarnings()
            : [];

        $configPath = $this->context->rootPath() . DIRECTORY_SEPARATOR . 'flow-engine.json';
        $parserSignature = $this->parserSignature();
        $sourceFingerprints = $this->captureSourceFingerprints($files);
        $configFingerprint = ContentFingerprint::file($configPath);

        $cachedFlow = $this->cache?->loadValidFlow($files, $configPath, $parserSignature);
        if ($cachedFlow !== null) {
            $this->assertSourceStateUnchanged($sourceFingerprints, $configPath, $configFingerprint);
            $this->flow = $cachedFlow['flow'];
            $this->cacheHash = $cachedFlow['hash'];
            $this->duplicateIds = $cachedFlow['duplicateIds'];
            return;
        }

        $cached = $this->perFileCache?->load() ?? [];
        $newMap  = [];
        $nodes   = [];
        $edges   = [];
        $symbols = [];

        foreach ($files as $file) {
            $fp = $file . '|' . $sourceFingerprints[$file] . '|parser:' . $parserSignature;

            if (isset($cached[$file]) && $cached[$file]['fp'] === $fp) {
                $entry = $cached[$file];
            } else {
                $ast      = $this->parser->parse($file);
                $rawNodes = array_map(fn(Node $n) => [
                    'class'  => $n->class(),
                    'method' => $n->method(),
                    'file'   => $n->file(),
                    'line'   => $n->line(),
                    'lang'   => $n->language(),
                    'meta'   => $n->metadata(),
                ], $ast['nodes'] ?? []);
                $rawEdges = array_map(fn(Edge $e) => [
                    'from'   => $e->from(),
                    'to'     => $e->to(),
                    'method' => $e->method(),
                    'type'   => $e->type(),
                ], $ast['edges'] ?? []);
                $rawSymbols = array_map(fn(SymbolDTO $s) => $s->toArray(), $ast['symbols'] ?? []);
                $entry = ['fp' => $fp, 'nodes' => $rawNodes, 'edges' => $rawEdges, 'symbols' => $rawSymbols];
            }

            $newMap[$file] = $entry;

            foreach ($entry['nodes'] as $nd) {
                $nodes[] = new Node($nd['class'], $nd['method'], $nd['file'], $nd['line'], $nd['lang'], $nd['meta'] ?? null);
            }
            foreach ($entry['edges'] as $ed) {
                $edges[] = new Edge($ed['from'], $ed['to'], $ed['method'], $ed['type']);
            }
            foreach ($entry['symbols'] ?? [] as $sd) {
                $symbols[] = new SymbolDTO(
                    $sd['id'],
                    $sd['name'],
                    $sd['kind'],
                    $sd['file'],
                    $sd['line'],
                    $sd['sourceModule'] ?? null
                );
            }
        }

        if ($this->crossLanguageDetector !== null) {
            $edges = $this->crossLanguageDetector->detect($nodes, $edges);
        }

        $symbolIndex = new SymbolIndex($symbols);
        $flow = $this->builder->build($nodes, $edges, $symbolIndex);
        $duplicateIds = $this->builder->lastDuplicateIds();

        $this->assertSourceStateUnchanged($sourceFingerprints, $configPath, $configFingerprint);
        $this->perFileCache?->save($newMap);

        if ($this->cache) {
            $cacheHash = $this->cache->saveFlow(
                $flow,
                $files,
                $configPath,
                $duplicateIds,
                $parserSignature,
                $sourceFingerprints,
                $configFingerprint,
            );
        }

        $this->assertSourceStateUnchanged($sourceFingerprints, $configPath, $configFingerprint);
        $this->flow = $flow;
        $this->duplicateIds = $duplicateIds;
        $this->cacheHash = $cacheHash ?? null;
    }

    private function parserSignature(): string
    {
        return AnalysisSignature::compute($this->analysisContext);
    }

    /**
     * @param string[] $files
     * @return array<string, string>
     */
    private function captureSourceFingerprints(array $files): array
    {
        sort($files);
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = ContentFingerprint::file($file, false);
        }

        return $fingerprints;
    }

    /** @param array<string, string> $expectedFingerprints */
    private function assertSourceStateUnchanged(
        array $expectedFingerprints,
        string $configPath,
        string $expectedConfigFingerprint,
    ): void {
        if ($this->cache === null && $this->perFileCache === null) {
            return;
        }

        $currentFiles = $this->scanner->scan($this->context);
        if (
            $this->captureSourceFingerprints($currentFiles) !== $expectedFingerprints
            || ContentFingerprint::file($configPath) !== $expectedConfigFingerprint
        ) {
            throw new \RuntimeException('Project sources changed during analysis; retry to avoid publishing a stale graph');
        }
    }

    public function getNodes(): array
    {
        $this->ensureAnalyzed();
        return $this->flow->nodes();
    }

    public function getNode(string $id): Node
    {
        $this->ensureAnalyzed();

        $node = $this->flow->node($id);

        if (!$node) {
            throw new \LogicException("Node {$id} not found");
        }

        return $node;
    }

    public function findNode(string $id): ?Node
    {
        $this->ensureAnalyzed();
        return $this->flow->node($id);
    }

    private function ensureAnalyzed(): void
    {
        if ($this->flow === null) {
            $this->analyze();
        }
    }

    public function getFlow(): FlowContract
    {
        $this->ensureAnalyzed();
        return $this->flow;
    }

    public function cacheHash(): ?string
    {
        return $this->cacheHash;
    }

    /**
     * @api
     * @return string[]
     */
    public function duplicateIds(): array
    {
        $this->ensureAnalyzed();
        return $this->duplicateIds;
    }

    /**
     * @return string[]
     */
    public function scanWarnings(): array
    {
        $this->ensureAnalyzed();
        return $this->scanWarnings;
    }

    /**
     * @return string[]
     */
    public function cacheWarnings(): array
    {
        $this->ensureAnalyzed();
        return array_values(array_unique(array_merge(
            $this->cache?->warnings() ?? [],
            $this->perFileCache?->warnings() ?? [],
        )));
    }
}
