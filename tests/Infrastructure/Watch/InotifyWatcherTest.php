<?php

namespace Tests\Infrastructure\Watch;

use FlowEngine\Infrastructure\Watch\InotifyWatcher;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class InotifyWatcherTest extends TestCase
{
    public function test_forgets_both_indexes_when_a_watch_is_invalidated(): void
    {
        $reflection = new \ReflectionClass(InotifyWatcher::class);
        $watcher = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('watches')->setValue($watcher, [42 => '/project/src']);
        $reflection->getProperty('watchedPaths')->setValue($watcher, ['/project/src' => 42]);

        $reflection->getMethod('forgetWatchDescriptor')->invoke($watcher, 42);

        self::assertSame([], $reflection->getProperty('watches')->getValue($watcher));
        self::assertSame([], $reflection->getProperty('watchedPaths')->getValue($watcher));
    }

    public function test_forgets_a_replaced_directory_by_normalized_path(): void
    {
        $reflection = new \ReflectionClass(InotifyWatcher::class);
        $watcher = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('watches')->setValue($watcher, [7 => '/project/src']);
        $reflection->getProperty('watchedPaths')->setValue($watcher, ['/project/src' => 7]);

        $reflection->getMethod('forgetWatchTree')->invoke($watcher, '/project/src/');

        self::assertSame([], $reflection->getProperty('watches')->getValue($watcher));
        self::assertSame([], $reflection->getProperty('watchedPaths')->getValue($watcher));
    }

    public function test_forgets_every_watch_below_a_removed_directory(): void
    {
        $reflection = new \ReflectionClass(InotifyWatcher::class);
        $watcher = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('watches')->setValue($watcher, [
            7 => '/project/src',
            8 => '/project/src/Nested',
            9 => '/project/tests',
        ]);
        $reflection->getProperty('watchedPaths')->setValue($watcher, [
            '/project/src' => 7,
            '/project/src/Nested' => 8,
            '/project/tests' => 9,
        ]);

        $reflection->getMethod('forgetWatchTree')->invoke($watcher, '/project/src');

        self::assertSame([9 => '/project/tests'], $reflection->getProperty('watches')->getValue($watcher));
        self::assertSame(['/project/tests' => 9], $reflection->getProperty('watchedPaths')->getValue($watcher));
    }

    public function test_reused_descriptor_replaces_the_old_path_alias(): void
    {
        $reflection = new \ReflectionClass(InotifyWatcher::class);
        $watcher = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('watches')->setValue($watcher, [7 => '/project/old']);
        $reflection->getProperty('watchedPaths')->setValue($watcher, ['/project/old' => 7]);

        $reflection->getMethod('rememberWatch')->invoke($watcher, 7, '/project/new');

        self::assertSame([7 => '/project/new'], $reflection->getProperty('watches')->getValue($watcher));
        self::assertSame(['/project/new' => 7], $reflection->getProperty('watchedPaths')->getValue($watcher));
    }

    #[RequiresPhpExtension('inotify')]
    public function test_directory_moves_rebuild_native_watches_without_observing_moved_out_files(): void
    {
        $base = sys_get_temp_dir() . '/flow-engine-inotify-' . uniqid('', true);
        $source = $base . '/src';
        mkdir($source . '/old/nested', 0777, true);
        $watcher = null;

        try {
            $watcher = new InotifyWatcher([$source], static fn(): bool => false, [], $base);
            rename($source . '/old', $source . '/new');

            self::assertTrue($watcher->waitForChange(1));
            $watchedPaths = (new \ReflectionClass($watcher))->getProperty('watchedPaths')->getValue($watcher);
            self::assertArrayHasKey($source . '/new', $watchedPaths);
            self::assertArrayHasKey($source . '/new/nested', $watchedPaths);
            self::assertArrayNotHasKey($source . '/old', $watchedPaths);

            rename($source . '/new', $base . '/moved-out');
            self::assertTrue($watcher->waitForChange(1));
            file_put_contents($base . '/moved-out/nested/after-move.php', '<?php');

            self::assertFalse($watcher->waitForChange(1));
        } finally {
            unset($watcher);
            $this->deleteDirectory($base);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
