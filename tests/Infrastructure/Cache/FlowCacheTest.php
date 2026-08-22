<?php

namespace Tests\Infrastructure\Cache;

use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Paths\StateDirectory;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestProjectContext;

final class FlowCacheTest extends TestCase
{
    private string $temporaryDirectory;
    private string $originalStateDirectory;
    private string $source;
    private FlowCache $cache;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/flow-cache-test-' . uniqid();
        mkdir($this->temporaryDirectory . '/src', 0777, true);
        $this->source = $this->temporaryDirectory . '/src/App.php';
        file_put_contents($this->source, '<?php final class App {}');
        $this->originalStateDirectory = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $this->temporaryDirectory . '/state');
        $this->cache = new FlowCache(new TestProjectContext($this->temporaryDirectory));
    }

    protected function tearDown(): void
    {
        putenv($this->originalStateDirectory === ''
            ? 'FLOW_ENGINE_STATE_DIR'
            : 'FLOW_ENGINE_STATE_DIR=' . $this->originalStateDirectory);
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function test_analyzer_signature_invalidates_full_cache(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config, [], 'parser-a');

        self::assertNotNull($this->cache->loadValidFlow([$this->source], $config, 'parser-a'));
        self::assertNull($this->cache->loadValidFlow([$this->source], $config, 'parser-b'));
    }

    public function test_input_metadata_check_does_not_deserialize_the_graph(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config, [], 'parser-a');
        $fingerprints = $this->cache->captureFileFingerprints([$this->source]);
        $flowFile = (new \ReflectionProperty($this->cache, 'flowFile'))->getValue($this->cache);
        file_put_contents($flowFile, 'corrupt-payload');

        self::assertTrue($this->cache->inputsMatch($fingerprints, $config, 'parser-a'));
        self::assertFalse($this->cache->inputsMatch($fingerprints, $config, 'parser-b'));

        file_put_contents($this->source, '<?php final class Changed {}');
        self::assertFalse($this->cache->inputsMatch(
            $this->cache->captureFileFingerprints([$this->source]),
            $config,
            'parser-a',
        ));
    }

    public function test_corrupt_flow_cache_is_reported_and_rebuilt_as_miss(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config, [], 'parser-a');
        $flowFile = (new \ReflectionProperty($this->cache, 'flowFile'))->getValue($this->cache);
        file_put_contents($flowFile, 'not-gzip');

        self::assertFalse($this->cache->isValid([$this->source], $config, 'parser-a'));
        self::assertNull($this->cache->loadValidFlow([$this->source], $config, 'parser-a'));
        self::assertStringContainsString('corrupt', implode("\n", $this->cache->warnings()));
    }

    public function test_valid_gzip_with_mismatched_payload_is_reported(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config, [], 'parser-a');
        $flowFile = (new \ReflectionProperty($this->cache, 'flowFile'))->getValue($this->cache);
        file_put_contents($flowFile, gzencode('{"nodes":[],"edges":[],"stats":{"nodeCount":99}}'));

        self::assertNull($this->cache->loadValidFlow([$this->source], $config, 'parser-a'));
        self::assertStringContainsString('does not match', implode("\n", $this->cache->warnings()));
    }

    public function test_hash_consistent_payload_with_invalid_schema_is_rebuilt_as_miss(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config, [], 'parser-a');
        $flowFile = (new \ReflectionProperty($this->cache, 'flowFile'))->getValue($this->cache);
        $metaFile = (new \ReflectionProperty($this->cache, 'metaFile'))->getValue($this->cache);
        $payload = gzencode('[]');
        self::assertNotFalse($payload);
        file_put_contents($flowFile, $payload);
        $meta = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        $meta['payloadHash'] = hash('sha256', $payload);
        file_put_contents($metaFile, json_encode($meta, JSON_THROW_ON_ERROR));

        self::assertNull($this->cache->loadValidFlow([$this->source], $config, 'parser-a'));
        self::assertStringContainsString('Invalid flow cache structure', implode("\n", $this->cache->warnings()));
    }

    public function test_hash_consistent_payload_with_contradictory_node_identity_is_a_miss(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $flow = new Flow([new Node('Actual', 'run', $this->source, 1)], []);
        $this->cache->saveFlow($flow, [$this->source], $config, [], 'parser-a');
        $flowFile = (new \ReflectionProperty($this->cache, 'flowFile'))->getValue($this->cache);
        $metaFile = (new \ReflectionProperty($this->cache, 'metaFile'))->getValue($this->cache);
        $snapshot = json_decode((string) gzdecode((string) file_get_contents($flowFile)), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['nodes'][0]['id'] = 'Expected::other';
        $snapshot['nodes'][0]['visibility'] = 'hidden';
        $snapshot['nodes'][0]['isPublic'] = true;
        $payload = gzencode(json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertNotFalse($payload);
        file_put_contents($flowFile, $payload);
        $meta = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        $meta['payloadHash'] = hash('sha256', $payload);
        file_put_contents($metaFile, json_encode($meta, JSON_THROW_ON_ERROR));

        self::assertNull($this->cache->loadValidFlow([$this->source], $config, 'parser-a'));
        self::assertStringContainsString('node structure', implode("\n", $this->cache->warnings()));
    }

    public function test_cache_directory_is_private(): void
    {
        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config);
        $cacheDirectory = StateDirectory::forProjectRoot($this->temporaryDirectory) . '/cache';

        self::assertSame('0700', substr(sprintf('%o', fileperms($cacheDirectory)), -4));
    }

    public function test_existing_cache_directory_permissions_are_tightened(): void
    {
        $cacheDirectory = StateDirectory::forProjectRoot($this->temporaryDirectory) . '/cache';
        mkdir($cacheDirectory, 0777, true);
        chmod($cacheDirectory, 0777);

        $config = $this->temporaryDirectory . '/flow-engine.json';
        $this->cache->saveFlow(new Flow([], []), [$this->source], $config);
        clearstatcache(true, $cacheDirectory);

        self::assertSame('0700', substr(sprintf('%o', fileperms($cacheDirectory)), -4));
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
