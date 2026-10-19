<?php

namespace Tests\Infrastructure\Cache;

use FlowEngine\Infrastructure\Cache\ReportCache;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestProjectContext;

final class ReportCacheTest extends TestCase
{
    private string $temporaryDirectory;
    private string $originalStateDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/report-cache-test-' . uniqid();
        mkdir($this->temporaryDirectory, 0777, true);
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

    public function test_loads_only_a_payload_that_matches_its_metadata(): void
    {
        $cache = new ReportCache(new TestProjectContext($this->temporaryDirectory));
        $cache->save(['metrics' => ['nodes' => 3]], 'flow-hash');

        self::assertSame(['metrics' => ['nodes' => 3]], $cache->loadValid('flow-hash'));
        self::assertNull($cache->loadValid('other-hash'));
    }

    public function test_treats_a_payload_that_does_not_match_its_metadata_as_a_cache_miss(): void
    {
        $cache = new ReportCache(new TestProjectContext($this->temporaryDirectory));
        $cache->save(['metrics' => ['nodes' => 3]], 'flow-hash');
        $reportsFile = (new \ReflectionProperty($cache, 'reportsFile'))->getValue($cache);
        file_put_contents($reportsFile, gzencode('{"metrics":{"nodes":4}}'));

        self::assertNull($cache->loadValid('flow-hash'));
    }

    public function test_treats_an_invalid_compressed_payload_as_a_cache_miss(): void
    {
        $cache = new ReportCache(new TestProjectContext($this->temporaryDirectory));
        $cache->save(['metrics' => ['nodes' => 3]], 'flow-hash');
        $reportsFile = (new \ReflectionProperty($cache, 'reportsFile'))->getValue($cache);
        $metaFile = (new \ReflectionProperty($cache, 'metaFile'))->getValue($cache);
        file_put_contents($reportsFile, 'not-gzip');
        $meta = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        $meta['payloadHash'] = hash_file('sha256', $reportsFile);
        file_put_contents($metaFile, json_encode($meta, JSON_THROW_ON_ERROR));

        self::assertNull($cache->loadValid('flow-hash'));
    }

    public function test_treats_a_hash_consistent_invalid_report_shape_as_a_cache_miss(): void
    {
        $cache = new ReportCache(new TestProjectContext($this->temporaryDirectory));
        $cache->save(['metrics' => ['nodes' => 3]], 'flow-hash');
        $reportsFile = (new \ReflectionProperty($cache, 'reportsFile'))->getValue($cache);
        $metaFile = (new \ReflectionProperty($cache, 'metaFile'))->getValue($cache);
        $payload = gzencode('{"metrics":"not-a-report"}');
        self::assertNotFalse($payload);
        file_put_contents($reportsFile, $payload);
        $meta = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        $meta['payloadHash'] = hash('sha256', $payload);
        file_put_contents($metaFile, json_encode($meta, JSON_THROW_ON_ERROR));

        self::assertNull($cache->loadValid('flow-hash'));
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
