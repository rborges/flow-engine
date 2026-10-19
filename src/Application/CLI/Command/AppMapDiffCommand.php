<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Application\AppMap\AppMapDiffAnalyzer;
use FlowEngine\Application\AppMap\AppMapDriftPolicyEvaluator;
use FlowEngine\Console\ConsoleIO;

final class AppMapDiffCommand implements Command
{
    public function __construct(
        private ConsoleIO $io
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'appmap-diff';
    }

    public function handle(array $argv): void
    {
        $beforePath = $argv[2] ?? null;
        $afterPath = $argv[3] ?? null;

        if (!$beforePath || !$afterPath) {
            $this->io->error('Usage: flow appmap-diff <before_appmap.json> <after_appmap.json>');
            return;
        }

        $before = $this->loadJsonFile($beforePath);
        $after = $this->loadJsonFile($afterPath);

        if ($before === null || $after === null) {
            return;
        }

        $analyzer = new AppMapDiffAnalyzer();
        $failed = array_merge($analyzer->failedServices($before), $analyzer->failedServices($after));
        if ($failed !== []) {
            $this->io->error('Cannot diff appmaps with services that failed analysis: ' . implode(', ', array_unique($failed)));
            return;
        }

        $diff = $analyzer->diff($before, $after);
        $policyPath = $this->extractOption($argv, '--policy');

        $payload = [
            'status' => 'ok',
            'drift' => $diff,
        ];

        if ($policyPath !== null) {
            $policy = $this->loadJsonFile($policyPath);
            if ($policy === null) {
                return;
            }

            $payload['gate'] = (new AppMapDriftPolicyEvaluator())->evaluate($diff, $policy);
        }

        $this->io->json($payload);

        if (($payload['gate']['passed'] ?? true) === false) {
            exit(1);
        }
    }

    private function extractOption(array $argv, string $prefix): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, $prefix . '=')) {
                return substr($arg, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            $this->io->error("File not found: {$path}");
            return null;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            $this->io->error("Invalid JSON: {$path}");
            return null;
        }

        return $raw;
    }
}
