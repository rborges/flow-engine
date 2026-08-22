<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Infrastructure\Paths\StateDirectory;

final class ReportCache
{
    private string $cacheDir;
    private string $reportsFile;
    private string $metaFile;
    private string $lockFile;

    public function __construct(ProjectContext $context)
    {
        $root = $context->rootPath();
        $stateDir = StateDirectory::forProjectRoot($root);
        $this->cacheDir = $stateDir . DIRECTORY_SEPARATOR . 'cache';
        $this->reportsFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'reports.json.gz';
        $this->metaFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'reports-meta.json';
        $this->lockFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'reports.lock';
    }

    public function isValid(string $hash): bool
    {
        return $this->withLock(LOCK_SH, function () use ($hash): bool {
            if (!file_exists($this->reportsFile) || !file_exists($this->metaFile)) {
                return false;
            }

            $meta = json_decode((string) file_get_contents($this->metaFile), true);
            if (!is_array($meta) || ($meta['hash'] ?? null) !== $hash) {
                return false;
            }

            $payloadHash = hash_file('sha256', $this->reportsFile);
            return $payloadHash !== false
                && isset($meta['payloadHash'])
                && hash_equals((string) $meta['payloadHash'], $payloadHash);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $raw = (string) file_get_contents($this->reportsFile);
        $json = @gzdecode($raw);

        if ($json === false) {
            throw new \RuntimeException('Failed to decode reports cache');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid reports cache JSON');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadValid(string $hash): ?array
    {
        return $this->withLock(LOCK_SH, function () use ($hash): ?array {
            if (!file_exists($this->reportsFile) || !file_exists($this->metaFile)) {
                return null;
            }

            $meta = json_decode((string) file_get_contents($this->metaFile), true);
            if (!is_array($meta) || ($meta['hash'] ?? null) !== $hash || !isset($meta['payloadHash'])) {
                return null;
            }

            $payloadHash = hash_file('sha256', $this->reportsFile);
            if ($payloadHash === false || !hash_equals((string) $meta['payloadHash'], $payloadHash)) {
                return null;
            }

            try {
                $reports = $this->load();
            } catch (\RuntimeException) {
                return null;
            }

            if ($reports === []) {
                return null;
            }
            foreach ($reports as $name => $report) {
                if (!is_string($name) || !is_array($report)) {
                    return null;
                }
            }

            return $reports;
        });
    }

    /**
     * @param array<string, mixed> $reports
     */
    public function save(array $reports, string $hash): void
    {
        $payload = json_encode($reports, JSON_THROW_ON_ERROR);
        $compressed = gzencode($payload, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress reports cache');
        }

        $meta = json_encode([
            'hash' => $hash,
            'payloadHash' => hash('sha256', $compressed),
            'generatedAt' => time(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $this->withLock(LOCK_EX, function () use ($compressed, $meta): void {
            AtomicFileWriter::write($this->reportsFile, $compressed);
            AtomicFileWriter::write($this->metaFile, $meta);
        });
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        AtomicFileWriter::ensurePrivateDirectory($this->cacheDir);
        $handle = fopen($this->lockFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open reports cache lock: %s', $this->lockFile));
        }

        try {
            if (!flock($handle, $operation)) {
                throw new \RuntimeException(sprintf('Failed to acquire reports cache lock: %s', $this->lockFile));
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
