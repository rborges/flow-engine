<?php

namespace Tests\Contract;

use FlowEngine\Application\CLI\Command\AppMapCommand;
use FlowEngine\Console\ConsoleIO;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

final class AppMapOutputSchemaTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-appmap-contract-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }
            // Objetos do git são somente leitura; no Windows o unlink falha sem isso.
            chmod($item->getPathname(), 0666);
            unlink($item->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function test_real_appmap_output_matches_the_published_schema(): void
    {
        $web = $this->service('web', 'composer', ['php'], [
            'src/sync.php' => "<?php\n\$ch = curl_init('https://api.example.com/sync');\n",
            'src/Page.php' => <<<'PHP'
<?php
class Page
{
    public function render()
    {
        exec('python3 ../../jobs/src/report.py');
        return '<link href="https://cdn.jsdelivr.net/npm/bootstrap/dist/css/bootstrap.min.css">';
    }
}
PHP,
        ]);
        $jobs = $this->service('jobs', 'python', ['py'], [
            'src/report.py' => "def build():\n    return 1\n",
        ]);
        $empty = $this->service('empty', 'composer', ['php'], []);
        $broken = $this->service('broken', 'unsupported-context', ['php'], []);

        $payload = null;
        $io = $this->createStub(ConsoleIO::class);
        $io->method('json')->willReturnCallback(function (array $data) use (&$payload): void {
            $payload = $data;
        });

        (new AppMapCommand($io))->handle(['bin/engine.php', 'appmap', $web, $jobs, $empty, $broken]);

        $this->assertValid($payload, 'appmap-output.schema.v2.json');

        $edges = $payload['appmap']['integrationEdges'];
        self::assertEqualsCanonicalizing(['http', 'script'], array_values(array_unique(array_column($edges, 'type'))));
        self::assertContains('asset', array_map(fn(array $e) => $e['metadata']['kind'] ?? null, $edges));
        self::assertContains('file:src/sync.php', array_column($edges, 'fromNode'));
        self::assertNotSame([], $payload['appmap']['serviceEdges']);
        self::assertEqualsCanonicalizing(['EMPTY_SERVICE', 'SERVICE_FAILED'], array_column($payload['appmap']['warnings'], 'type'));
    }

    public function test_schema_rejects_an_undocumented_field(): void
    {
        $payload = $this->emptyMap();
        $this->assertValid($payload, 'appmap-output.schema.v2.json');

        $payload['appmap']['version'] = 2;
        $result = (new Validator())->validate($this->toObject($payload), $this->schema('appmap-output.schema.v2.json'));

        self::assertFalse($result->isValid());
    }

    public function test_v2_schema_rejects_a_v1_map_and_v1_rejects_a_v2_map(): void
    {
        $v2 = $this->emptyMap();
        $v1 = $v2;
        unset($v1['appmap']['schemaVersion'], $v1['appmap']['sourceVersion']);

        $this->assertValid($v1, 'appmap-output.schema.v1.json');
        self::assertFalse((new Validator())->validate($this->toObject($v1), $this->schema('appmap-output.schema.v2.json'))->isValid());
        self::assertFalse((new Validator())->validate($this->toObject($v2), $this->schema('appmap-output.schema.v1.json'))->isValid());
    }

    public function test_real_output_carries_contract_and_engine_versions(): void
    {
        $web = $this->service('web', 'composer', ['php'], ['src/a.php' => "<?php\n"]);
        $jobs = $this->service('jobs', 'python', ['py'], ['src/b.py' => "def f():\n    return 1\n"]);
        $git = static function (string ...$args) use ($web): string {
            exec('git -C ' . escapeshellarg($web) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, implode("\n", $out));

            return trim(implode("\n", $out));
        };
        $git('init', '--quiet');
        $git('add', '.');
        $git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=', 'commit', '--quiet', '-m', 'init');
        $git('remote', 'add', 'origin', 'https://someone:secret-token@git.example.invalid/team/web.git');

        $payload = null;
        $io = $this->createStub(ConsoleIO::class);
        $io->method('json')->willReturnCallback(function (array $data) use (&$payload): void {
            $payload = $data;
        });
        (new AppMapCommand($io))->handle(['bin/engine.php', 'appmap', $web, $jobs]);

        self::assertSame('2.0', $payload['appmap']['schemaVersion']);
        self::assertSame(\FlowEngine\Application\EngineVersion::VALUE, $payload['appmap']['sourceVersion']);
        $this->assertValid($payload, 'appmap-output.schema.v2.json');
        $services = array_column($payload['appmap']['services'], null, 'name');
        self::assertSame('https://git.example.invalid/team/web.git', $services['web']['repository']);
        self::assertSame($git('rev-parse', 'HEAD'), $services['web']['commitSha']);
        self::assertNull($services['jobs']['commitSha']);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMap(): array
    {
        return ['status' => 'ok', 'appmap' => [
            'schemaVersion' => '2.0',
            'sourceVersion' => '4.44.3',
            'generatedAt' => date('c'),
            'services' => [],
            'integrationEdges' => [],
            'serviceEdges' => [],
            'inconsistencies' => [],
            'warnings' => [],
        ]];
    }

    public function test_documented_appmap_example_matches_the_schema(): void
    {
        $markdown = (string) file_get_contents(self::ROOT . '/docs/appmap.md');
        self::assertSame(1, preg_match('/```json\n(.*?)\n```/s', $markdown, $m), 'docs/appmap.md must have a JSON example');

        $this->assertValid(json_decode($m[1], true, 512, JSON_THROW_ON_ERROR), 'appmap-output.schema.v2.json');
    }

    public function test_catalog_fixture_matches_the_service_catalog_schema(): void
    {
        $catalog = [
            'version' => '1.0',
            'services' => [
                ['name' => 'web', 'path' => '../web'],
                ['name' => 'jobs', 'path' => '../jobs'],
            ],
        ];

        $this->assertValid($catalog, 'flow-services.schema.v1.json');
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function assertValid(?array $payload, string $schema): void
    {
        self::assertNotNull($payload);

        $result = (new Validator())->validate($this->toObject($payload), $this->schema($schema));

        self::assertTrue(
            $result->isValid(),
            $result->isValid() ? '' : (string) json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT)
        );
    }

    private function schema(string $file): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $file);
    }

    /**
     * Validador trabalha com objetos stdClass, como o JSON publicado.
     *
     * @param array<string, mixed> $payload
     */
    private function toObject(array $payload): mixed
    {
        return json_decode((string) json_encode($payload), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string[] $extensions
     * @param array<string, string> $files
     */
    private function service(string $name, string $type, array $extensions, array $files): string
    {
        $root = $this->tmpDir . '/' . $name;
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => $type],
            'scan' => ['include' => ['src'], 'extensions' => $extensions],
        ]));
        foreach ($files as $path => $code) {
            file_put_contents($root . '/' . $path, $code);
        }

        return $root;
    }
}
