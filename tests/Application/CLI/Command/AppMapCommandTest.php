<?php

namespace Tests\Application\CLI\Command;

use FlowEngine\Application\CLI\Command\AppMapCommand;
use FlowEngine\Console\ConsoleIO;
use PHPUnit\Framework\TestCase;

final class AppMapCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-appmap-command-' . uniqid('', true);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmpDir);
    }

    public function test_appmap_reports_failed_service_and_keeps_the_others(): void
    {
        $healthy = $this->createService('orders', 'composer', autoloadThrows: true);
        $broken = $this->createService('billing', 'unsupported-context');

        $payload = null;
        $io = $this->createMock(ConsoleIO::class);
        $io->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Service billing failed analysis: LogicException'));
        $io->expects($this->once())
            ->method('json')
            ->willReturnCallback(function (array $data) use (&$payload): void {
                $payload = $data;
            });

        (new AppMapCommand($io))->handle(['bin/engine.php', 'appmap', $healthy, $broken]);

        self::assertSame('ok', $payload['status']);
        $services = array_column($payload['appmap']['services'], null, 'name');

        self::assertSame('ok', $services['orders']['status']);
        self::assertGreaterThan(0, $services['orders']['stats']['nodeCount']);
        self::assertSame('explicit_config', $services['orders']['configResolution']['mode']);

        self::assertSame('failed', $services['billing']['status']);
        self::assertStringContainsString('unsupported-context', $services['billing']['error']);
        self::assertSame(0, $services['billing']['stats']['nodeCount']);
    }

    private function createService(string $name, string $contextType, bool $autoloadThrows = false): string
    {
        $root = $this->tmpDir . '/' . $name;
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/vendor', 0777, true);

        file_put_contents($root . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => $contextType, 'autoload' => 'vendor/autoload.php'],
            'scan' => ['include' => ['src'], 'exclude' => ['vendor'], 'extensions' => ['php']],
        ]));
        file_put_contents($root . '/vendor/autoload.php', $autoloadThrows
            ? "<?php\nthrow new RuntimeException('autoload should not run');\n"
            : "<?php\n");
        file_put_contents($root . '/src/Handler.php', "<?php\n\nnamespace App;\n\nfinal class Handler\n{\n    public function run(): void\n    {\n    }\n}\n");

        return $root;
    }
}
