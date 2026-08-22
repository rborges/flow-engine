<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Contracts\SnapshotStorePort;
use FlowEngine\Infrastructure\Paths\StateDirectory;
use RuntimeException;

/**
 * Persists labeled analysis snapshots as gzip JSON.
 *
 * Storage: state/snapshots/<label>.json.gz
 */
final class SnapshotStore implements SnapshotStorePort
{
    private string $snapshotDir;

    public function __construct(ProjectContext $context, private ?int $keepMax = null)
    {
        $stateDir = StateDirectory::forProjectRoot($context->rootPath());
        $this->snapshotDir = $stateDir . DIRECTORY_SEPARATOR . 'snapshots';
    }

    public function save(string $label, array $reports): void
    {
        $this->ensureDirectory();

        $json = json_encode($reports, JSON_THROW_ON_ERROR);
        $compressed = gzencode($json, 9);

        if ($compressed === false) {
            throw new RuntimeException("Failed to compress snapshot: {$label}");
        }

        $path = $this->pathFor($label);
        AtomicFileWriter::write($path, $compressed);

        if ($this->keepMax !== null) {
            $this->pruneToKeepMax();
        }
    }

    private function pruneToKeepMax(): void
    {
        $snapshots = $this->list();
        $toDelete  = array_slice($snapshots, $this->keepMax);

        foreach ($toDelete as $snapshot) {
            $path = $this->pathFor($snapshot['label']);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function load(string $label): array
    {
        $path = $this->pathFor($label);

        if (!file_exists($path)) {
            throw new RuntimeException("Snapshot not found: {$label}");
        }

        $compressed = file_get_contents($path);

        if ($compressed === false) {
            throw new RuntimeException("Failed to read snapshot: {$path}");
        }

        $json = gzdecode($compressed);

        if ($json === false) {
            throw new RuntimeException("Failed to decompress snapshot: {$label}");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function exists(string $label): bool
    {
        return file_exists($this->pathFor($label));
    }

    /**
     * @return array<int, array{label: string, size: int, created: string}>
     */
    public function list(): array
    {
        if (!is_dir($this->snapshotDir)) {
            return [];
        }

        $files = glob($this->snapshotDir . DIRECTORY_SEPARATOR . '*.json.gz');

        if ($files === false) {
            return [];
        }

        $result = [];

        foreach ($files as $file) {
            $basename = basename($file, '.json.gz');
            $result[] = [
                'label' => $basename,
                'size' => filesize($file),
                'created' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        usort($result, fn($a, $b) => $b['created'] <=> $a['created']);

        return $result;
    }

    public function deleteOlderThan(int $days): int
    {
        if (!is_dir($this->snapshotDir)) {
            return 0;
        }

        $cutoff = time() - max(0, $days) * 86400;
        $deleted = 0;

        foreach ($this->snapshotFiles() as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function deleteKeepLast(int $count): int
    {
        if (!is_dir($this->snapshotDir)) {
            return 0;
        }

        $files = $this->snapshotFiles();
        usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        $deleted = 0;
        foreach (array_slice($files, max(0, $count)) as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array{total: int, bytes: int, oldest: string|null, newest: string|null}
     */
    public function stats(): array
    {
        $files = $this->snapshotFiles();
        $bytes = 0;
        $times = [];

        foreach ($files as $file) {
            $bytes += (int) filesize($file);
            $mtime = filemtime($file);
            if ($mtime !== false) {
                $times[] = $mtime;
            }
        }

        return [
            'total' => count($files),
            'bytes' => $bytes,
            'oldest' => $times !== [] ? date('Y-m-d H:i:s', min($times)) : null,
            'newest' => $times !== [] ? date('Y-m-d H:i:s', max($times)) : null,
        ];
    }

    private function pathFor(string $label): string
    {
        return $this->snapshotDir . DIRECTORY_SEPARATOR . $label . '.json.gz';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->snapshotDir)) {
            AtomicFileWriter::ensurePrivateDirectory($this->snapshotDir);
        }
    }

    /**
     * @return string[]
     */
    private function snapshotFiles(): array
    {
        $files = glob($this->snapshotDir . DIRECTORY_SEPARATOR . '*.json.gz');
        return $files === false ? [] : $files;
    }
}
