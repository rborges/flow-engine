<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Infrastructure\Paths\StateDirectory;
use RuntimeException;

/**
 * Per-file incremental cache for parser output.
 *
 * Storage: <stateDir>/cache/per-file.json.gz
 *
 * Each entry is keyed by absolute file path and stores:
 *   - fp: content fingerprint string
 *   - nodes: raw serialized node arrays (pre-visibility)
 *   - edges: raw serialized edge arrays
 *
 * Nodes are stored pre-visibility so FlowBuilder always re-applies the policy.
 */
final class PerFileCache
{
    private string $cacheFile;
    /** @var string[] */
    private array $warnings = [];

    public function __construct(ProjectContext $context)
    {
        $stateDir = StateDirectory::forProjectRoot($context->rootPath());
        $this->cacheFile = $stateDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'per-file.json.gz';
    }

    /**
     * Returns a fingerprint string for the given file path.
     * The path and file content are both represented in the fingerprint.
     */
    public static function fingerprint(string $path): string
    {
        return ContentFingerprint::file($path);
    }

    /**
     * Load the per-file cache map.
     *
     * @return array<string, array{fp: string, nodes: array, edges: array}>
     */
    public function load(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [];
        }

        $compressed = file_get_contents($this->cacheFile);

        if ($compressed === false) {
            $this->warnings[] = sprintf('Per-file cache could not be read and will be rebuilt: %s', $this->cacheFile);
            return [];
        }

        $json = @gzdecode($compressed);

        if ($json === false) {
            $this->warnings[] = sprintf('Per-file cache is corrupt and will be rebuilt: %s', $this->cacheFile);
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->warnings[] = sprintf('Per-file cache contains invalid JSON and will be rebuilt: %s', $this->cacheFile);
            return [];
        }

        if (!$this->isValidCacheMap($data)) {
            $this->warnings[] = sprintf('Per-file cache has an invalid structure and will be rebuilt: %s', $this->cacheFile);
            return [];
        }

        return $data;
    }

    /**
     * Persist the per-file cache map.
     *
     * @param array<string, array{fp: string, nodes: array, edges: array}> $results
     */
    public function save(array $results): void
    {
        $json = json_encode($results, JSON_THROW_ON_ERROR);
        $compressed = gzencode($json, 6);

        if ($compressed === false) {
            throw new RuntimeException('Failed to compress per-file cache');
        }

        AtomicFileWriter::write($this->cacheFile, $compressed);
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** @param array<mixed> $data */
    private function isValidCacheMap(array $data): bool
    {
        foreach ($data as $path => $entry) {
            if (
                !is_string($path)
                || !is_array($entry)
                || !is_string($entry['fp'] ?? null)
                || !is_array($entry['nodes'] ?? null)
                || !is_array($entry['edges'] ?? null)
                || (isset($entry['symbols']) && !is_array($entry['symbols']))
            ) {
                return false;
            }

            foreach ($entry['nodes'] as $node) {
                if (!$this->isValidNode($node)) {
                    return false;
                }
            }
            foreach ($entry['edges'] as $edge) {
                if (!$this->hasStringFields($edge, ['from', 'to', 'method', 'type'])) {
                    return false;
                }
            }
            foreach ($entry['symbols'] ?? [] as $symbol) {
                if (
                    !$this->hasStringFields($symbol, ['id', 'name', 'kind', 'file'])
                    || !is_int($symbol['line'] ?? null)
                    || (isset($symbol['sourceModule']) && !is_string($symbol['sourceModule']))
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isValidNode(mixed $node): bool
    {
        return $this->hasStringFields($node, ['class', 'method', 'file', 'lang'])
            && is_int($node['line'] ?? null)
            && (!isset($node['meta']) || is_array($node['meta']));
    }

    /** @param string[] $fields */
    private function hasStringFields(mixed $value, array $fields): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($fields as $field) {
            if (!isset($value[$field]) || !is_string($value[$field])) {
                return false;
            }
        }

        return true;
    }
}
