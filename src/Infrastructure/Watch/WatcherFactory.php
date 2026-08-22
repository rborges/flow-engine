<?php

namespace FlowEngine\Infrastructure\Watch;

final class WatcherFactory
{
    public function __construct(
        private EnvironmentDetector $environment,
        private bool $inotifyAvailable
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            EnvironmentDetector::createDefault(),
            function_exists('inotify_init')
        );
    }

    /**
     * @param callable():bool $hasChanged
     * @param string[] $paths
     */
    public function create(
        string $mode,
        callable $hasChanged,
        array $paths,
        array $ignoredPaths = [],
        ?string $projectRoot = null,
    ): Watcher
    {
        return match ($mode) {
            'polling' => new PollingWatcher($hasChanged),
            'native' => $this->createNative($paths, $hasChanged, $ignoredPaths, $projectRoot),
            default => $this->createAuto($paths, $hasChanged, $ignoredPaths, $projectRoot),
        };
    }

    /**
     * @param string[] $paths
     */
    private function createAuto(array $paths, callable $hasChanged, array $ignoredPaths, ?string $projectRoot): Watcher
    {
        if ($this->environment->isDocker()) {
            return new PollingWatcher($hasChanged);
        }

        if ($this->inotifyAvailable) {
            try {
                return new InotifyWatcher($paths, $hasChanged, $ignoredPaths, $projectRoot);
            } catch (\Throwable $e) {
                return new PollingWatcher($hasChanged);
            }
        }

        return new PollingWatcher($hasChanged);
    }

    /**
     * @param string[] $paths
     */
    private function createNative(array $paths, callable $hasChanged, array $ignoredPaths, ?string $projectRoot): Watcher
    {
        if ($this->inotifyAvailable) {
            return new InotifyWatcher($paths, $hasChanged, $ignoredPaths, $projectRoot);
        }

        return new PollingWatcher($hasChanged);
    }
}
