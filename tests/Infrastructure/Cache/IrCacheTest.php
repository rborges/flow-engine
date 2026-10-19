<?php

namespace Tests\Infrastructure\Cache;

use FlowEngine\Cache\IrCache;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Infrastructure\Cache\FlowCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestProjectContext;

final class IrCacheTest extends TestCase
{
    private string $temporaryDirectory;
    private string $originalStateDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/ir-cache-test-' . uniqid();
        mkdir($this->temporaryDirectory . '/src', 0777, true);
        $this->originalStateDirectory = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $this->temporaryDirectory . '/state');
    }

    protected function tearDown(): void
    {
        putenv($this->originalStateDirectory === ''
            ? 'FLOW_ENGINE_STATE_DIR'
            : 'FLOW_ENGINE_STATE_DIR=' . $this->originalStateDirectory);
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function test_round_trip_and_content_based_invalidation(): void
    {
        $source = $this->temporaryDirectory . '/src/App.php';
        file_put_contents($source, 'alpha');
        $mtime = filemtime($source);
        $cache = new IrCache(new TestProjectContext($this->temporaryDirectory));

        $cache->save(['nodes' => ['A']], [$source]);
        self::assertTrue($cache->isValid([$source]));
        self::assertSame(['nodes' => ['A']], $cache->load());
        self::assertSame(['nodes' => ['A']], $cache->loadValid([$source]));

        file_put_contents($source, 'bravo');
        touch($source, $mtime);
        clearstatcache(true, $source);

        self::assertFalse($cache->isValid([$source]));
        self::assertNull($cache->loadValid([$source]));
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/.flow-engine');
    }

    public function test_ir_and_flow_metadata_do_not_overwrite_each_other(): void
    {
        $source = $this->temporaryDirectory . '/src/App.php';
        file_put_contents($source, 'alpha');
        $context = new TestProjectContext($this->temporaryDirectory);
        $irCache = new IrCache($context);
        $flowCache = new FlowCache($context);
        $config = $this->temporaryDirectory . '/flow-engine.json';

        $irCache->save(['nodes' => ['A']], [$source]);
        $flowCache->saveFlow(new Flow([], []), [$source], $config);

        self::assertTrue($irCache->isValid([$source]));
        self::assertNotNull($flowCache->loadValidFlow([$source], $config));
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
