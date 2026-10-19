<?php

namespace FlowEngine\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Infrastructure\Cache\AtomicFileWriter;
use FlowEngine\Infrastructure\Cache\ContentFingerprint;
use FlowEngine\Infrastructure\Paths\StateDirectory;

final class IrCache
{
    private string $cacheDir;
    private string $cacheFile;
    private string $metaFile;
    private string $lockFile;

    public function __construct(ProjectContext $context)
    {
        $this->cacheDir  = StateDirectory::forProjectRoot($context->rootPath()) . DIRECTORY_SEPARATOR . 'cache';
        $this->cacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'ir.json';
        $this->metaFile  = $this->cacheDir . DIRECTORY_SEPARATOR . 'ir-meta.json';
        $this->lockFile  = $this->cacheDir . DIRECTORY_SEPARATOR . 'ir.lock';
    }

    /**
     * @param string[] $files
     */
    public function isValid(array $files): bool
    {
        return $this->withLock(LOCK_SH, fn(): bool => $this->isValidUnlocked($files));
    }

    public function load(): array
    {
        return $this->withLock(LOCK_SH, fn(): array => $this->loadUnlocked());
    }

    /**
     * @param string[] $files
     */
    public function loadValid(array $files): ?array
    {
        return $this->withLock(LOCK_SH, function () use ($files): ?array {
            return $this->isValidUnlocked($files) ? $this->loadUnlocked() : null;
        });
    }

    private function loadUnlocked(): array
    {
        $contents = file_get_contents($this->cacheFile);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Failed to read IR cache: %s', $this->cacheFile));
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Invalid IR cache payload: %s', $this->cacheFile));
        }

        return $data;
    }

    /**
     * @param array    $ir
     * @param string[] $files
     */
    public function save(array $ir, array $files): void
    {
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = ContentFingerprint::file($file, false);
        }

        $payload = json_encode($ir, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $meta = json_encode([
                'payloadHash' => hash('sha256', $payload),
                'fileFingerprints' => $fingerprints,
                'generatedAt'  => time(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $this->withLock(LOCK_EX, function () use ($payload, $meta): void {
            AtomicFileWriter::write($this->cacheFile, $payload);
            AtomicFileWriter::write($this->metaFile, $meta);
        });
    }

    /** @param string[] $files */
    private function isValidUnlocked(array $files): bool
    {
        if (!file_exists($this->cacheFile) || !file_exists($this->metaFile)) {
            return false;
        }

        $meta = json_decode((string) file_get_contents($this->metaFile), true);
        if (!is_array($meta) || !is_array($meta['fileFingerprints'] ?? null)) {
            return false;
        }

        $payloadHash = hash_file('sha256', $this->cacheFile);
        if ($payloadHash === false || !hash_equals((string) ($meta['payloadHash'] ?? ''), $payloadHash)) {
            return false;
        }

        foreach ($files as $file) {
            if (($meta['fileFingerprints'][$file] ?? null) !== ContentFingerprint::file($file, false)) {
                return false;
            }
        }

        return count($meta['fileFingerprints']) === count($files);
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        AtomicFileWriter::ensurePrivateDirectory($this->cacheDir);
        $handle = fopen($this->lockFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open IR cache lock: %s', $this->lockFile));
        }

        try {
            if (!flock($handle, $operation)) {
                throw new \RuntimeException(sprintf('Failed to acquire IR cache lock: %s', $this->lockFile));
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
