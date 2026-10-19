<?php

namespace FlowEngine\Infrastructure\Watch;

final class InotifyWatcher implements Watcher
{
    /** @var resource */
    private $inotify;

    /** @var array<int, string> watch descriptor => directory */
    private array $watches = [];
    /** @var array<string, int> directory => watch descriptor */
    private array $watchedPaths = [];
    /** @var string[] */
    private array $rootPaths;
    private \Closure $hasChanged;

    /**
     * @param string[] $paths
     */
    public function __construct(
        array $paths,
        callable $hasChanged,
        private readonly array $ignoredPaths = [],
        private readonly ?string $projectRoot = null,
    )
    {
        if (!function_exists('inotify_init')) {
            throw new \RuntimeException('inotify extension not available');
        }

        $this->inotify = inotify_init();
        stream_set_blocking($this->inotify, false);
        $this->hasChanged = \Closure::fromCallable($hasChanged);
        $this->rootPaths = array_values(array_unique(array_map(
            static fn(string $path): string => str_replace('\\', '/', rtrim($path, '/\\')),
            $paths,
        )));

        foreach ($this->rootPaths as $path) {
            $this->addDirectoryTree($path);
        }
    }

    public function waitForChange(int $intervalSeconds): bool
    {
        $read = [$this->inotify];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, max(0, $intervalSeconds));
        if ($ready === false || $ready === 0) {
            $changed = (bool) ($this->hasChanged)();
            if ($changed) {
                $this->refreshDirectoryTrees();
            }
            return $changed;
        }

        $events = inotify_read($this->inotify);
        $relevantEvent = false;
        $structuralEvent = false;
        $collector = new WatchDirectoryCollector();
        foreach (is_array($events) ? $events : [] as $event) {
            $mask = $event['mask'] ?? 0;
            $parent = $this->watches[$event['wd'] ?? -1] ?? null;
            $name = $event['name'] ?? '';
            $eventPath = $parent !== null && is_string($name) && $name !== ''
                ? $parent . DIRECTORY_SEPARATOR . $name
                : $parent;

            if (
                $eventPath !== null
                && $collector->isIgnoredPath(
                    $eventPath,
                    $this->ignoredPaths,
                    $this->projectRoot ?? $parent ?? $eventPath,
                )
            ) {
                continue;
            }

            $relevantEvent = true;
            $structuralEvent = $structuralEvent
                || ($mask & (IN_Q_OVERFLOW | IN_IGNORED | IN_DELETE_SELF | IN_MOVE_SELF)) !== 0
                || (($mask & IN_ISDIR) !== 0 && ($mask & (IN_CREATE | IN_DELETE | IN_MOVED_FROM | IN_MOVED_TO)) !== 0);
        }

        $changed = (bool) ($this->hasChanged)();
        if ($structuralEvent) {
            $this->rebuildDirectoryWatches();
        } elseif ($changed) {
            $this->refreshDirectoryTrees();
        }

        return $relevantEvent || $changed;
    }

    public function type(): string
    {
        return 'native';
    }

    private function addDirectoryTree(string $root): void
    {
        foreach ((new WatchDirectoryCollector())->collect([$root], $this->ignoredPaths, $this->projectRoot) as $directory) {
            $this->addWatch($directory);
        }
    }

    private function addWatch(string $directory): void
    {
        $directory = str_replace('\\', '/', rtrim($directory, '/\\'));
        if (isset($this->watchedPaths[$directory])) {
            return;
        }

        $descriptor = inotify_add_watch(
            $this->inotify,
            $directory,
            IN_CREATE | IN_MODIFY | IN_DELETE | IN_MOVED_FROM | IN_MOVED_TO | IN_DELETE_SELF | IN_MOVE_SELF,
        );
        if ($descriptor === false) {
            throw new \RuntimeException(sprintf('Failed to watch directory: %s', $directory));
        }

        $this->rememberWatch($descriptor, $directory);
    }

    private function refreshDirectoryTrees(): void
    {
        foreach (array_keys($this->watchedPaths) as $directory) {
            if (!is_dir($directory)) {
                $this->forgetWatchTree($directory);
            }
        }
        foreach ($this->rootPaths as $root) {
            $this->addDirectoryTree($root);
        }
    }

    private function rebuildDirectoryWatches(): void
    {
        fclose($this->inotify);
        $inotify = inotify_init();
        if ($inotify === false) {
            throw new \RuntimeException('Failed to recreate inotify instance');
        }

        $this->inotify = $inotify;
        stream_set_blocking($this->inotify, false);
        $this->watches = [];
        $this->watchedPaths = [];

        foreach ($this->rootPaths as $root) {
            $this->addDirectoryTree($root);
        }
    }

    private function rememberWatch(int $descriptor, string $directory): void
    {
        $previousDirectory = $this->watches[$descriptor] ?? null;
        if ($previousDirectory !== null && ($this->watchedPaths[$previousDirectory] ?? null) === $descriptor) {
            unset($this->watchedPaths[$previousDirectory]);
        }

        $this->watches[$descriptor] = $directory;
        $this->watchedPaths[$directory] = $descriptor;
    }

    private function forgetWatchDescriptor(int $descriptor, bool $removeKernelWatch = false): void
    {
        $directory = $this->watches[$descriptor] ?? null;
        unset($this->watches[$descriptor]);
        if ($directory !== null && ($this->watchedPaths[$directory] ?? null) === $descriptor) {
            unset($this->watchedPaths[$directory]);
        }

        if ($removeKernelWatch && isset($this->inotify) && is_resource($this->inotify)) {
            @inotify_rm_watch($this->inotify, $descriptor);
        }
    }

    private function forgetWatchTree(string $directory): void
    {
        $directory = str_replace('\\', '/', rtrim($directory, '/\\'));
        $prefix = $directory . '/';
        foreach ($this->watchedPaths as $watchedPath => $descriptor) {
            if ($watchedPath === $directory || str_starts_with($watchedPath, $prefix)) {
                $this->forgetWatchDescriptor($descriptor, true);
            }
        }
    }

}
