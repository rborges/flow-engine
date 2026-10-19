<?php

namespace Tests\Bootstrap;

use FlowEngine\Bootstrap\CatalogServiceBuilder;
use PHPUnit\Framework\TestCase;

final class CatalogServiceBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-catalog-builder-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_static_analysis_does_not_load_project_autoload_with_explicit_config(): void
    {
        $root = $this->createService('orders', autoloadThrows: true);

        $services = (new CatalogServiceBuilder())->buildFromEntries([$this->entry($root, 'orders')]);

        $payload = $services[0]->toArray();
        self::assertSame('ok', $payload['status']);
        self::assertArrayNotHasKey('error', $payload);
        self::assertGreaterThan(0, $payload['stats']['nodeCount']);
    }

    public function test_failed_service_is_reported_without_aborting_the_others(): void
    {
        $broken = $this->createService('broken', contextType: 'unsupported-context');
        $healthy = $this->createService('healthy');

        $services = (new CatalogServiceBuilder())->buildFromEntries([
            $this->entry($broken, 'broken'),
            $this->entry($healthy, 'healthy'),
        ]);

        self::assertCount(2, $services);

        $failed = $services[0]->toArray();
        self::assertSame('broken', $failed['name']);
        self::assertSame('failed', $failed['status']);
        self::assertStringContainsString('unsupported-context', $failed['error']);
        self::assertSame(0, $failed['stats']['nodeCount']);

        $ok = $services[1]->toArray();
        self::assertSame('ok', $ok['status']);
        self::assertGreaterThan(0, $ok['stats']['nodeCount']);
    }

    public function test_strict_build_reports_config_resolution(): void
    {
        $root = $this->createService('orders');

        $strict = (new CatalogServiceBuilder())->buildFromEntries([$this->entry($root, 'orders')]);

        self::assertSame('explicit_config', $strict[0]->toArray()['configResolution']['mode']);
    }

    private function createService(string $name, bool $autoloadThrows = false, string $contextType = 'composer'): string
    {
        $root = $this->tmpDir . '/' . $name;

        $this->writeFile($root . '/flow-engine.json', json_encode([
            'version' => '1.0',
            'context' => ['type' => $contextType, 'autoload' => 'vendor/autoload.php'],
            'scan' => ['include' => ['src'], 'exclude' => ['vendor'], 'extensions' => ['php']],
        ], JSON_PRETTY_PRINT));

        $this->writeFile($root . '/vendor/autoload.php', $autoloadThrows
            ? "<?php\n\nthrow new RuntimeException('autoload should not run');\n"
            : "<?php\n");

        $this->writeFile($root . '/src/OrderController.php', <<<'PHP'
<?php

namespace App;

final class OrderController
{
    public function index(): array
    {
        return [];
    }
}
PHP);

        return $root;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $root, string $name): array
    {
        return ['path' => $root, 'name' => $name, 'hostnames' => [], 'contractEndpoints' => null];
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
