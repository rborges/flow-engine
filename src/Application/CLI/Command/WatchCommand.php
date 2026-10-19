<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Bootstrap\Container;

final class WatchCommand implements Command
{
    public function __construct(
        private \FlowEngine\Console\ConsoleIO $io
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'watch';
    }

    public function handle(array $argv): void
    {
        $projectPath = $argv[2] ?? null;
        $analysis = $argv[3] ?? 'metrics';
        $interval = $this->parseInterval($argv) ?? 2;
        $watcherMode = $this->parseWatcherMode($argv) ?? 'auto';

        if (!$projectPath) {
            $this->io->error('Usage: flow watch <project_path> [analysis] [--interval=2] [--watcher=auto|polling|native]');
            return;
        }

        $container = new Container($projectPath);
        $container->analyzeProject()->execute();

        $hasChanged = function () use ($projectPath): bool {
            return !(new Container($projectPath))->areFlowCacheInputsCurrent();
        };

        $configResolution = $container->configResolution();

        $watcher = $container->watcherFactory()
            ->create(
                $watcherMode,
                $hasChanged,
                $this->watchPaths($projectPath, $configResolution->includePaths),
                $container->effectiveIgnoredPaths(),
                $projectPath,
            );

        $this->io->json([
            'event' => 'watcher',
            'mode' => $watcherMode,
            'selected' => $watcher->type(),
        ]);

        while (true) {
            if (!$watcher->waitForChange($interval)) {
                continue;
            }

            $container = new Container($projectPath);
            $container->analyzeProject()->execute();
            $payload = $this->runAnalysis($container, $analysis);

            $this->io->json([
                'event' => 'change',
                'timestamp' => date('c'),
                'analysis' => $analysis,
                'hash' => $container->cacheHash(),
                'report' => $payload,
            ]);
        }
    }

    private function parseInterval(array $argv): ?int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--interval=')) {
                $value = (int) substr($arg, strlen('--interval='));
                return $value > 0 ? $value : null;
            }
        }

        return null;
    }

    private function parseWatcherMode(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--watcher=')) {
                $value = substr($arg, strlen('--watcher='));
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    /** @param string[] $includePaths */
    private function watchPaths(string $projectPath, array $includePaths): array
    {
        if ($includePaths === []) {
            return [$projectPath];
        }

        $paths = [];
        foreach ($includePaths as $includePath) {
            $includePath = trim((string) $includePath, "/\\");
            $path = $includePath === '' || $includePath === '.'
                ? $projectPath
                : $projectPath . DIRECTORY_SEPARATOR . $includePath;
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        return $paths === [] ? [$projectPath] : array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private function runAnalysis(Container $container, string $analysis): array
    {
        return match ($analysis) {
            'complexity' => $container->analyzeComplexity()->execute()->toArray(),
            'cycles' => $container->analyzeCycles()->execute()->toArray(),
            'architecture' => $container->analyzeArchitecture()->execute()->toArray(),
            'metrics' => $container->analyzeMetrics()->execute()->toArray(),
            'orphans' => $container->analyzeOrphans()->execute()->toArray(),
            default => [
                'status' => 'error',
                'message' => "Unknown analysis: {$analysis}",
            ],
        };
    }
}
