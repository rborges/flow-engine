<?php

namespace FlowEngine\Infrastructure\Diff;

use FlowEngine\Application\DTO\StalenessReport;
use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Cache\ContentFingerprint;

final class StalenessChecker
{
    public function __construct(
        private FlowCache $cache,
        private FilesystemProjectScanner $scanner,
        private ProjectContext $context,
    ) {}

    public function execute(): StalenessReport
    {
        if (!$this->cache->hasStalenessBaseline()) {
            return new StalenessReport(false, [], [], [], 0);
        }

        $saved = $this->cache->loadFileFingerprints();
        $savedConfigFingerprint = $this->cache->loadConfigFingerprint();
        $configPath = rtrim($this->context->rootPath(), '/\\') . DIRECTORY_SEPARATOR . 'flow-engine.json';
        $configChanged = $savedConfigFingerprint !== null
            && ContentFingerprint::file($configPath) !== $savedConfigFingerprint;

        $currentFiles = $this->scanner->scan($this->context);
        $currentMap = [];
        foreach ($currentFiles as $file) {
            $currentMap[$file] = file_exists($file)
                ? ContentFingerprint::file($file, false)
                : 'missing';
        }

        $changedFiles = [];
        $deletedFiles = [];
        $newFiles = [];

        foreach ($saved as $path => $stamp) {
            $current = $currentMap[$path] ?? null;
            if ($current === null) {
                if (!$this->isInCurrentScanScope($path)) {
                    continue;
                }
                $deletedFiles[] = $path;
            } elseif ($current !== $stamp) {
                $changedFiles[] = $path;
            }
        }

        foreach ($currentMap as $path => $stamp) {
            if (!array_key_exists($path, $saved)) {
                $newFiles[] = $path;
            }
        }

        $total = count($changedFiles) + count($newFiles) + count($deletedFiles) + ($configChanged ? 1 : 0);
        $stale = $total > 0;

        return new StalenessReport($stale, $changedFiles, $newFiles, $deletedFiles, $total, $configChanged);
    }

    private function isInCurrentScanScope(string $absolutePath): bool
    {
        $root = str_replace('\\', '/', rtrim($this->context->rootPath(), '/\\'));
        $path = str_replace('\\', '/', $absolutePath);

        if (!str_starts_with($path, $root . '/')) {
            return false;
        }

        $relative = substr($path, strlen($root) + 1);
        if (FilesystemProjectScanner::isPathIgnored(
            $relative,
            FilesystemProjectScanner::effectiveIgnoredPaths($this->context->ignoredPaths()),
        )) {
            return false;
        }

        $includes = $this->context->includePaths();
        if ($includes === []) {
            $includes = ['.'];
        }

        foreach ($includes as $include) {
            $include = str_replace('\\', '/', trim((string) $include, "/\\"));
            if ($include === '' || $include === '.') {
                return true;
            }

            if ($relative === $include || str_starts_with($relative, $include . '/')) {
                return true;
            }
        }

        return false;
    }

}
