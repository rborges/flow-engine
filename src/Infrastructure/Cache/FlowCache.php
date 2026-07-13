<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Infrastructure\Paths\StateDirectory;

final class FlowCache
{
    // v4.44.0: nós de arquivo/função e arestas a partir de código procedural.
    // O hash não cobre o código do analisador, então mudanças de formato de nó
    // exigem bump manual — sem isso um grafo antigo é servido em silêncio.
    private const SCHEMA_VERSION = 'v4.44.0';

    private string $cacheDir;
    private string $flowFile;
    private string $metaFile;

    public function __construct(ProjectContext $context)
    {
        $root = $context->rootPath();
        $stateDir = StateDirectory::forProjectRoot($root);
        $this->cacheDir = $stateDir . DIRECTORY_SEPARATOR . 'cache';
        $this->flowFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'flow.json.gz';
        $this->metaFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'meta.json';
    }

    /**
     * @param string[] $files
     */
    public function isValid(array $files, string $configPath): bool
    {
        if (!file_exists($this->flowFile) || !file_exists($this->metaFile)) {
            return false;
        }

        $meta = json_decode((string) file_get_contents($this->metaFile), true);
        $cachedHash = $meta['hash'] ?? null;

        if (!$cachedHash) {
            return false;
        }

        return $cachedHash === $this->computeHash($files, $configPath);
    }

    /**
     * @param string[] $files
     */
    public function computeHash(array $files, string $configPath): string
    {
        $hasher = hash_init('sha1');

        hash_update($hasher, 'schema:' . self::SCHEMA_VERSION);

        $configFingerprint = $this->fingerprintFile($configPath);
        hash_update($hasher, $configFingerprint);

        sort($files);

        foreach ($files as $file) {
            hash_update($hasher, $this->fingerprintFile($file));
        }

        return hash_final($hasher);
    }

    public function loadFlow(): Flow
    {
        $raw = (string) file_get_contents($this->flowFile);
        $json = gzdecode($raw);

        if ($json === false) {
            throw new \RuntimeException('Failed to decode flow cache');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid flow cache JSON');
        }

        $serializer = new FlowSnapshotSerializer();

        return $serializer->toFlow($data);
    }

    /**
     * @param string[] $files
     * @param string[] $duplicateIds
     */
    public function saveFlow(Flow $flow, array $files, string $configPath, array $duplicateIds = []): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $serializer = new FlowSnapshotSerializer();
        $data = $serializer->toArray($flow);

        $hash = $this->computeHash($files, $configPath);

        $sortedFiles = $files;
        sort($sortedFiles);
        $fingerprints = [];
        foreach ($sortedFiles as $file) {
            $fingerprints[$file] = $this->fileStamp($file);
        }

        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $compressed = gzencode($payload, 6);

        file_put_contents($this->flowFile, $compressed);
        file_put_contents(
            $this->metaFile,
            json_encode([
                'hash' => $hash,
                'generatedAt' => time(),
                'nodeCount' => $data['stats']['nodeCount'] ?? 0,
                'edgeCount' => $data['stats']['edgeCount'] ?? 0,
                'duplicateIds' => array_values($duplicateIds),
                'fileFingerprints' => $fingerprints,
            ], JSON_PRETTY_PRINT)
        );
    }

    /**
     * @return string[]
     */
    public function loadDuplicateIds(): array
    {
        $meta = $this->readMeta();
        $ids = $meta['duplicateIds'] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_filter($ids, 'is_string'));
    }

    public function readMeta(): array
    {
        if (!file_exists($this->metaFile)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->metaFile), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, string>
     */
    public function loadFileFingerprints(): array
    {
        $meta = $this->readMeta();
        $fps = $meta['fileFingerprints'] ?? [];
        return is_array($fps) ? $fps : [];
    }

    private function fingerprintFile(string $path): string
    {
        if (!file_exists($path)) {
            return $path . '|missing';
        }

        return $path . '|' . filemtime($path) . '|' . filesize($path);
    }

    private function fileStamp(string $path): string
    {
        if (!file_exists($path)) {
            return 'missing';
        }

        return filemtime($path) . '|' . filesize($path);
    }
}
