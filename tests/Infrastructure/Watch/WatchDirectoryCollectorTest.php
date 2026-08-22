<?php

namespace Tests\Infrastructure\Watch;

use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;
use FlowEngine\Infrastructure\Watch\WatchDirectoryCollector;
use PHPUnit\Framework\TestCase;

final class WatchDirectoryCollectorTest extends TestCase
{
    public function test_collects_source_tree_and_prunes_ignored_directories(): void
    {
        $root = sys_get_temp_dir() . '/watch-directory-collector-' . uniqid();
        mkdir($root . '/src/New/Deep', 0777, true);
        mkdir($root . '/src/vendor/package', 0777, true);

        try {
            $directories = array_map(
                static fn(string $path): string => str_replace('\\', '/', $path),
                (new WatchDirectoryCollector())->collect([$root . '/src'], ['vendor']),
            );

            self::assertContains(str_replace('\\', '/', $root . '/src'), $directories);
            self::assertContains(str_replace('\\', '/', $root . '/src/New'), $directories);
            self::assertContains(str_replace('\\', '/', $root . '/src/New/Deep'), $directories);
            self::assertNotContains(str_replace('\\', '/', $root . '/src/vendor'), $directories);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_prunes_the_same_default_dependency_directories_as_the_scanner(): void
    {
        $root = sys_get_temp_dir() . '/watch-directory-defaults-' . uniqid();
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/node_modules/package/deep', 0777, true);
        mkdir($root . '/vendor/package/deep', 0777, true);

        try {
            $directories = (new WatchDirectoryCollector())->collect(
                [$root],
                FilesystemProjectScanner::effectiveIgnoredPaths([]),
            );

            self::assertContains($root . '/src', $directories);
            self::assertNotContains($root . '/node_modules', $directories);
            self::assertNotContains($root . '/vendor', $directories);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_does_not_treat_a_parent_directory_name_as_a_project_exclusion(): void
    {
        $base = sys_get_temp_dir() . '/vendor/watch-project-' . uniqid();
        mkdir($base . '/src/deep', 0777, true);

        try {
            $directories = (new WatchDirectoryCollector())->collect(
                [$base],
                FilesystemProjectScanner::effectiveIgnoredPaths([]),
                $base,
            );

            self::assertContains($base . '/src', $directories);
            self::assertContains($base . '/src/deep', $directories);
        } finally {
            $this->removeDirectory($base);
        }
    }

    public function test_rejects_an_ignored_directory_when_it_is_a_new_collection_root(): void
    {
        $root = sys_get_temp_dir() . '/watch-new-ignored-' . uniqid();
        mkdir($root . '/node_modules/package', 0777, true);

        try {
            $directories = (new WatchDirectoryCollector())->collect(
                [$root . '/node_modules'],
                FilesystemProjectScanner::effectiveIgnoredPaths([]),
                $root,
            );

            self::assertSame([], $directories);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_matches_an_explicitly_excluded_file_event(): void
    {
        $root = '/project';
        $collector = new WatchDirectoryCollector();

        self::assertTrue($collector->isIgnoredPath(
            '/project/src/generated.ts',
            ['src/generated.ts'],
            $root,
        ));
        self::assertFalse($collector->isIgnoredPath(
            '/project/src/application.ts',
            ['src/generated.ts'],
            $root,
        ));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
