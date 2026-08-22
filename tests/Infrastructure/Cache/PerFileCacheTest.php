<?php

namespace Tests\Infrastructure\Cache;

use FlowEngine\Infrastructure\Cache\PerFileCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestProjectContext;

final class PerFileCacheTest extends TestCase
{
    private string $tempDir;
    private PerFileCache $cache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/per-file-cache-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->cache = new PerFileCache(new TestProjectContext($this->tempDir));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // load() when no cache file exists
    // -------------------------------------------------------------------------

    public function test_returns_empty_when_no_cache_file(): void
    {
        $result = $this->cache->load();
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // save() + load() round-trip
    // -------------------------------------------------------------------------

    public function test_save_and_load_roundtrip(): void
    {
        $nodeArr = [
            'class'  => 'MyClass',
            'method' => 'myMethod',
            'file'   => '/some/file.php',
            'line'   => 42,
            'lang'   => 'php',
            'meta'   => null,
        ];
        $edgeArr = [
            'from'   => 'MyClass::myMethod',
            'to'     => 'OtherClass::otherMethod',
            'method' => 'otherMethod',
            'type'   => 'method_call',
        ];

        $fp  = '/some/file.php|1708300000|4096';
        $map = [
            '/some/file.php' => [
                'fp'    => $fp,
                'nodes' => [$nodeArr],
                'edges' => [$edgeArr],
            ],
        ];

        $this->cache->save($map);
        $loaded = $this->cache->load();

        $this->assertArrayHasKey('/some/file.php', $loaded);
        $this->assertSame($fp, $loaded['/some/file.php']['fp']);
        $this->assertSame([$nodeArr], $loaded['/some/file.php']['nodes']);
        $this->assertSame([$edgeArr], $loaded['/some/file.php']['edges']);
    }

    // -------------------------------------------------------------------------
    // fingerprint()
    // -------------------------------------------------------------------------

    public function test_missing_file_fingerprint(): void
    {
        $path = $this->tempDir . '/nonexistent.php';
        $fp   = PerFileCache::fingerprint($path);

        $this->assertSame($path . '|missing', $fp);
    }

    public function test_fingerprint_stable_for_unchanged_file(): void
    {
        $path = $this->tempDir . '/stable.php';
        file_put_contents($path, '<?php echo 1;');

        $fp1 = PerFileCache::fingerprint($path);
        $fp2 = PerFileCache::fingerprint($path);

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_does_not_change_when_only_mtime_changes(): void
    {
        $path = $this->tempDir . '/mtime.php';
        file_put_contents($path, '<?php echo 1;');

        $fp1 = PerFileCache::fingerprint($path);

        // Force a different mtime by touching the file one second in the future
        touch($path, time() + 1);
        clearstatcache(true, $path);

        $fp2 = PerFileCache::fingerprint($path);

        $this->assertSame($fp1, $fp2);
    }

    public function test_fingerprint_changes_for_same_size_content_with_restored_mtime(): void
    {
        $path = $this->tempDir . '/info.php';
        file_put_contents($path, 'alpha');
        $mtime = filemtime($path);
        $fp1 = PerFileCache::fingerprint($path);

        file_put_contents($path, 'bravo');
        touch($path, $mtime);
        clearstatcache(true, $path);
        $fp2 = PerFileCache::fingerprint($path);

        $this->assertNotSame($fp1, $fp2);
        $this->assertStringStartsWith($path . '|', $fp2);
    }

    public function test_corrupt_cache_is_reported_before_rebuild(): void
    {
        $this->cache->save(['/file.php' => ['fp' => 'x', 'nodes' => [], 'edges' => []]]);

        $reflection = new \ReflectionProperty($this->cache, 'cacheFile');
        $cacheFile = $reflection->getValue($this->cache);
        file_put_contents($cacheFile, 'not-gzip');

        $this->assertSame([], $this->cache->load());
        $this->assertStringContainsString('corrupt', implode("\n", $this->cache->warnings()));
    }

    public function test_structurally_invalid_cache_entries_are_rebuilt(): void
    {
        $this->cache->save(['/seed.php' => ['fp' => 'seed', 'nodes' => [], 'edges' => []]]);
        $reflection = new \ReflectionProperty($this->cache, 'cacheFile');
        $cacheFile = $reflection->getValue($this->cache);
        $validEntry = ['fp' => 'x', 'nodes' => [], 'edges' => [], 'symbols' => []];
        $invalidMaps = [
            ['/file.php' => 'broken-shape'],
            ['/file.php' => array_replace($validEntry, ['fp' => 1])],
            ['/file.php' => array_replace($validEntry, ['nodes' => [['class' => 'OnlyClass']]])],
            ['/file.php' => array_replace($validEntry, ['edges' => [['from' => 'A']]])],
            ['/file.php' => array_replace($validEntry, ['symbols' => [['id' => 'symbol']]])],
        ];

        foreach ($invalidMaps as $invalidMap) {
            $payload = gzencode(json_encode($invalidMap, JSON_THROW_ON_ERROR));
            self::assertNotFalse($payload);
            file_put_contents($cacheFile, $payload);
            self::assertSame([], $this->cache->load());
        }

        self::assertStringContainsString('invalid structure', implode("\n", $this->cache->warnings()));
    }
}
