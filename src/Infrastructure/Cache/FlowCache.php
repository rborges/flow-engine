<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Infrastructure\Paths\StateDirectory;

final class FlowCache
{
    private const SCHEMA_VERSION = 'v4.45.0';

    private string $cacheDir;
    private string $flowFile;
    private string $metaFile;
    private string $lockFile;
    /** @var string[] */
    private array $warnings = [];

    public function __construct(ProjectContext $context)
    {
        $root = $context->rootPath();
        $stateDir = StateDirectory::forProjectRoot($root);
        $this->cacheDir = $stateDir . DIRECTORY_SEPARATOR . 'cache';
        $this->flowFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'flow.json.gz';
        $this->metaFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'meta.json';
        $this->lockFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'flow.lock';
    }

    /**
     * @param string[] $files
     */
    public function isValid(array $files, string $configPath, string $analysisSignature = ''): bool
    {
        return $this->loadValidFlow($files, $configPath, $analysisSignature) !== null;
    }

    /**
     * @param string[] $files
     */
    public function computeHash(array $files, string $configPath, string $analysisSignature = ''): string
    {
        return $this->computeHashFromFingerprints(
            $this->captureFileFingerprints($files),
            $this->fingerprintFile($configPath),
            $analysisSignature,
        );
    }

    /**
     * @param string[] $files
     * @return array<string, string>
     */
    public function captureFileFingerprints(array $files): array
    {
        sort($files);
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = ContentFingerprint::file($file, false);
        }

        return $fingerprints;
    }

    /**
     * Checks only whether the current inputs match the cache metadata.
     * Payload integrity is validated when the graph is loaded.
     *
     * @param array<string, string> $fileFingerprints
     */
    public function inputsMatch(
        array $fileFingerprints,
        string $configPath,
        string $analysisSignature = '',
    ): bool {
        if (!file_exists($this->flowFile) || !file_exists($this->metaFile)) {
            return false;
        }

        try {
            return $this->withLock(LOCK_SH, function () use (
                $fileFingerprints,
                $configPath,
                $analysisSignature,
            ): bool {
                $meta = $this->readMetaStrict();
                $hash = $this->computeHashFromFingerprints(
                    $fileFingerprints,
                    $this->fingerprintFile($configPath),
                    $analysisSignature,
                );

                return isset($meta['hash']) && hash_equals((string) $meta['hash'], $hash);
            });
        } catch (\Throwable $error) {
            $this->warnings[] = sprintf(
                'Flow cache metadata is corrupt and will be rebuilt: %s',
                $error->getMessage(),
            );
            return false;
        }
    }

    /**
     * @param array<string, string> $fileFingerprints
     */
    private function computeHashFromFingerprints(
        array $fileFingerprints,
        string $configFingerprint,
        string $analysisSignature,
    ): string {
        $hasher = hash_init('sha1');

        hash_update($hasher, 'schema:' . self::SCHEMA_VERSION);
        hash_update($hasher, '|analyzers:' . $analysisSignature);
        hash_update($hasher, $configFingerprint);

        ksort($fileFingerprints);
        foreach ($fileFingerprints as $file => $fingerprint) {
            hash_update($hasher, $file . '|' . $fingerprint);
        }

        return hash_final($hasher);
    }

    public function loadFlow(): Flow
    {
        $raw = (string) file_get_contents($this->flowFile);
        $json = @gzdecode($raw);

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
    public function saveFlow(
        Flow $flow,
        array $files,
        string $configPath,
        array $duplicateIds = [],
        string $analysisSignature = '',
        ?array $expectedFileFingerprints = null,
        ?string $expectedConfigFingerprint = null,
    ): string
    {
        AtomicFileWriter::ensurePrivateDirectory($this->cacheDir);

        $serializer = new FlowSnapshotSerializer();
        $data = $serializer->toArray($flow);

        $fingerprints = $expectedFileFingerprints ?? $this->captureFileFingerprints($files);
        $configFingerprint = $expectedConfigFingerprint ?? $this->fingerprintFile($configPath);
        $hash = $this->computeHashFromFingerprints($fingerprints, $configFingerprint, $analysisSignature);

        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $compressed = gzencode($payload, 6);

        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress flow cache');
        }

        $meta = json_encode([
                'hash' => $hash,
                'generatedAt' => time(),
                'nodeCount' => $data['stats']['nodeCount'] ?? 0,
                'edgeCount' => $data['stats']['edgeCount'] ?? 0,
                'payloadHash' => hash('sha256', $compressed),
                'duplicateIds' => array_values($duplicateIds),
                'fileFingerprints' => $fingerprints,
                'configFingerprint' => $configFingerprint,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (
            $this->captureFileFingerprints($files) !== $fingerprints
            || $this->fingerprintFile($configPath) !== $configFingerprint
        ) {
            throw new \RuntimeException('Project sources changed before flow cache publication');
        }

        $this->withLock(LOCK_EX, function () use ($compressed, $meta): void {
            AtomicFileWriter::write($this->flowFile, $compressed);
            AtomicFileWriter::write($this->metaFile, $meta);
        });

        return $hash;
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

    public function hasStalenessBaseline(): bool
    {
        return array_key_exists('fileFingerprints', $this->readMeta());
    }

    public function loadConfigFingerprint(): ?string
    {
        $fingerprint = $this->readMeta()['configFingerprint'] ?? null;
        return is_string($fingerprint) ? $fingerprint : null;
    }

    /**
     * @param string[] $files
     * @return array{flow: Flow, hash: string, duplicateIds: string[]}|null
     */
    public function loadValidFlow(array $files, string $configPath, string $analysisSignature = ''): ?array
    {
        if (!file_exists($this->flowFile) || !file_exists($this->metaFile)) {
            return null;
        }

        try {
            return $this->withLock(LOCK_SH, function () use ($files, $configPath, $analysisSignature): ?array {
                $meta = $this->readMetaStrict();
                $fileFingerprints = $this->captureFileFingerprints($files);
                $configFingerprint = $this->fingerprintFile($configPath);
                $hash = $this->computeHashFromFingerprints(
                    $fileFingerprints,
                    $configFingerprint,
                    $analysisSignature,
                );
                if (($meta['hash'] ?? null) !== $hash) {
                    return null;
                }

                $payloadHash = hash_file('sha256', $this->flowFile);
                if (
                    $payloadHash === false
                    || !isset($meta['payloadHash'])
                    || !hash_equals((string) $meta['payloadHash'], $payloadHash)
                ) {
                    throw new \RuntimeException('Flow cache payload does not match its metadata');
                }

                $ids = $meta['duplicateIds'] ?? [];
                $flow = $this->loadFlow();
                if (
                    $this->captureFileFingerprints($files) !== $fileFingerprints
                    || $this->fingerprintFile($configPath) !== $configFingerprint
                ) {
                    return null;
                }

                return [
                    'flow' => $flow,
                    'hash' => $hash,
                    'duplicateIds' => is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [],
                ];
            });
        } catch (\Throwable $error) {
            $this->warnings[] = sprintf('Flow cache is corrupt and will be rebuilt: %s', $error->getMessage());
            return null;
        }
    }

    /** @return string[] */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    private function fingerprintFile(string $path): string
    {
        if (!file_exists($path)) {
            return $path . '|missing';
        }

        return ContentFingerprint::file($path);
    }

    private function readMetaStrict(): array
    {
        $contents = file_get_contents($this->metaFile);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read flow cache metadata');
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid flow cache metadata');
        }

        return $data;
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        AtomicFileWriter::ensurePrivateDirectory($this->cacheDir);
        $handle = fopen($this->lockFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open cache lock: %s', $this->lockFile));
        }

        try {
            if (!flock($handle, $operation)) {
                throw new \RuntimeException(sprintf('Failed to acquire cache lock: %s', $this->lockFile));
            }
            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
