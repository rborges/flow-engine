<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Bootstrap\Container;
use FlowEngine\Domain\Contracts\BundledLibraryScanner;

final class InteractiveCommand implements Command
{
    public function __construct(
        private \FlowEngine\Console\ConsoleIO $io,
        private BundledLibraryScanner $libraryScanner
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'interactive';
    }

    public function handle(array $argv): void
    {
        $projectPath = $argv[2] ?? null;

        if (!$projectPath) {
            $projectPath = $this->prompt("Project path (default: .): ");
            if (!$projectPath) {
                $projectPath = '.';
            }
        }

        $this->io->info("Select an action:");
        $this->io->info("1) analyze");
        $this->io->info("2) complexity");
        $this->io->info("3) cycles");
        $this->io->info("4) architecture");
        $this->io->info("5) metrics");
        $this->io->info("6) orphans");
        $this->io->info("7) compare");
        $this->io->info("8) watch");
        $this->io->info("9) doctor");
        $this->io->info("10) init");

        $choice = $this->prompt("Choice: ");

        if (!$choice) {
            $this->io->error('No option selected');
            return;
        }

        $action = match ($choice) {
            '1', 'analyze' => 'analyze',
            '2', 'complexity' => 'complexity',
            '3', 'cycles' => 'cycles',
            '4', 'architecture' => 'architecture',
            '5', 'metrics' => 'metrics',
            '6', 'orphans' => 'orphans',
            '7', 'compare' => 'compare',
            '8', 'watch' => 'watch',
            '9', 'doctor' => 'doctor',
            '10', 'init' => 'init',
            default => null,
        };

        if (!$action) {
            $this->io->error('Invalid option');
            return;
        }

        match ($action) {
            'analyze' => $this->runAnalyze($projectPath),
            'complexity' => $this->runAnalysis($projectPath, 'complexity'),
            'cycles' => $this->runAnalysis($projectPath, 'cycles'),
            'architecture' => $this->runAnalysis($projectPath, 'architecture'),
            'metrics' => $this->runAnalysis($projectPath, 'metrics'),
            'orphans' => $this->runAnalysis($projectPath, 'orphans'),
            'compare' => $this->runCompare($projectPath),
            'watch' => $this->runWatch($projectPath),
            'doctor' => $this->runDoctor($projectPath),
            'init' => $this->runInit($projectPath),
        };
    }

    private function prompt(string $message): ?string
    {
        echo $message;
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }

        $value = trim($line);
        return $value !== '' ? $value : null;
    }

    private function runAnalyze(string $path): void
    {
        $container = new Container($path);
        $container->analyzeProject()->execute();

        $this->io->json([
            'status' => 'ok',
            'message' => 'Project analyzed successfully',
        ]);
    }

    private function runAnalysis(string $path, string $analysis): void
    {
        $container = new Container($path);
        $container->analyzeProject()->execute();

        $report = match ($analysis) {
            'complexity' => $container->analyzeComplexity()->execute()->toArray(),
            'cycles' => $container->analyzeCycles()->execute()->toArray(),
            'architecture' => $container->analyzeArchitecture()->execute()->toArray(),
            'metrics' => $container->analyzeMetrics()->execute()->toArray(),
            'orphans' => $container->analyzeOrphans()->execute()->toArray(),
            default => ['status' => 'error', 'message' => "Unknown analysis: {$analysis}"],
        };

        $this->io->json($report);
    }

    private function runCompare(string $pathA): void
    {
        $pathB = $this->prompt("Second project path: ");

        if (!$pathB) {
            $this->io->error('Second project path required');
            return;
        }

        $command = new CompareCommand($this->io);
        $command->handle(['bin/engine.php', 'compare', $pathA, $pathB]);
    }

    private function runWatch(string $path): void
    {
        $analysis = $this->prompt("Analysis (metrics, complexity, cycles, architecture, orphans): ") ?? 'metrics';
        $interval = $this->prompt("Interval seconds (default 2): ") ?? '2';

        $command = new WatchCommand($this->io);
        $command->handle(['bin/engine.php', 'watch', $path, $analysis, "--interval={$interval}"]);
    }

    private function runDoctor(string $path): void
    {
        $command = new DoctorCommand($this->io);
        $command->handle(['bin/engine.php', 'doctor', $path]);
    }

    private function runInit(string $path): void
    {
        $command = new InitCommand($this->io, $this->libraryScanner);
        $command->handle(['bin/engine.php', 'init', $path]);
    }
}
