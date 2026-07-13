<?php

namespace Tests\Application\CLI\Command;

use FlowEngine\Application\CLI\Command\InitCommand;
use FlowEngine\Console\ConsoleIO;
use FlowEngine\Infrastructure\Config\BundledLibraryDetector;
use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/init-command-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_generates_flutter_config_for_pubspec_projects(): void
    {
        file_put_contents($this->tempDir . '/pubspec.yaml', "name: the_singer\n");
        mkdir($this->tempDir . '/backend/app', 0777, true);

        $io = $this->createMock(ConsoleIO::class);
        $io->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['status'] ?? null) === 'ok'
                    && str_ends_with((string) ($payload['path'] ?? ''), 'flow-engine.json');
            }));

        $command = new InitCommand($io, new BundledLibraryDetector());
        $command->handle(['bin/engine.php', 'init', $this->tempDir]);

        $decoded = json_decode((string) file_get_contents($this->tempDir . '/flow-engine.json'), true);

        self::assertSame('flutter', $decoded['context']['type']);
        self::assertContains('lib', $decoded['scan']['include']);
        self::assertContains('backend/app', $decoded['scan']['include']);
        self::assertContains('dart', $decoded['scan']['extensions']);
        self::assertContains('py', $decoded['scan']['extensions']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
