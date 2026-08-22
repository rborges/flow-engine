<?php

namespace FlowEngine\Infrastructure\Watch;

use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;

final class WatchDirectoryCollector
{
    /**
     * @param string[] $roots
     * @param string[] $ignoredPaths
     * @return string[]
     */
    public function collect(array $roots, array $ignoredPaths, ?string $projectRoot = null): array
    {
        $directories = [];
        foreach ($roots as $root) {
            if (
                !is_dir($root)
                || $this->isIgnoredPath($root, $ignoredPaths, $projectRoot ?? $root)
            ) {
                continue;
            }

            $directories[] = $root;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn(\SplFileInfo $entry): bool => !$entry->isDir()
                        || !$this->isIgnoredPath($entry->getPathname(), $ignoredPaths, $projectRoot ?? $root),
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    $directories[] = $entry->getPathname();
                }
            }
        }

        return array_values(array_unique($directories));
    }

    /** @param string[] $ignoredPaths */
    public function isIgnoredPath(string $path, array $ignoredPaths, string $projectRoot): bool
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $projectRoot = str_replace('\\', '/', rtrim($projectRoot, '/\\'));
        $relativePath = $path === $projectRoot
            ? ''
            : (str_starts_with($path, $projectRoot . '/')
                ? substr($path, strlen($projectRoot) + 1)
                : $path);
        return FilesystemProjectScanner::isPathIgnored($relativePath, $ignoredPaths);
    }
}
