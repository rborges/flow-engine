<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Bootstrap\Container;
use FlowEngine\Console\ConsoleIO;
use FlowEngine\Domain\Analysis\ScriptEntrypointClassifier;
use FlowEngine\Domain\Flow\Node;

final class EntrypointsCommand implements Command
{
    public function __construct(private ConsoleIO $io) {}

    public function supports(string $command): bool
    {
        return $command === 'entrypoints';
    }

    public function handle(array $argv): void
    {
        $projectPath = $argv[2] ?? null;
        $mode = $this->extractOption($argv, '--mode') ?? 'actionable';
        if (!$projectPath) {
            $this->io->error('Usage: flow entrypoints <project_path> [--mode=actionable|raw]');
            return;
        }
        if (!in_array($mode, ['actionable', 'raw'], true)) {
            $this->io->error('Invalid mode. Use --mode=actionable or --mode=raw');
            return;
        }

        $container = new Container($projectPath);
        $container->analyzeProject()->execute();

        $rawNodes = $container->getFlow()->query()->entrypoints()->all();
        $nodes = $mode === 'raw' ? $rawNodes : $this->actionableEntrypoints($rawNodes);

        $byType      = [];
        $entrypoints = [];

        foreach ($nodes as $node) {
            $type      = $this->classifyType($node);
            $meta      = $node->metadata() ?? [];
            $framework = $meta['framework'] ?? null;

            $byType[$type] = ($byType[$type] ?? 0) + 1;

            $ep = [
                'nodeId'   => $node->id(),
                'type'     => $type,
                'class'    => $node->class(),
                'method'   => $node->method(),
                'file'     => $node->file(),
                'language' => $node->language(),
            ];

            if ($framework !== null) {
                $ep['framework'] = $framework;
            }

            unset($meta['entrypoint_type'], $meta['framework']);
            if (!empty($meta)) {
                $ep['metadata'] = $meta;
            }

            $entrypoints[] = $ep;
        }

        $this->io->json([
            'mode' => $mode,
            'rawTotal' => count($rawNodes),
            'filteredOut' => max(0, count($rawNodes) - count($nodes)),
            'total'       => count($nodes),
            'byType'      => $byType,
            'entrypoints' => $entrypoints,
        ]);
    }

    /**
     * @param Node[] $nodes
     * @return Node[]
     */
    private function actionableEntrypoints(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            // Constructors and magic methods are graph roots, not actionable runtime
            // entrypoints. File nodes use the synthetic __file method and are the exact
            // opposite: the page the user actually hits.
            if ($node->type() === Node::TYPE_METHOD && str_starts_with($node->method(), '__')) {
                continue;
            }

            $type = $this->classifyType($node);
            if ($type === 'custom') {
                continue;
            }

            $result[] = $node;
        }

        return $result;
    }

    private function classifyType(Node $node): string
    {
        if ($node->type() === Node::TYPE_FILE) {
            return ScriptEntrypointClassifier::typeFor($node);
        }

        $meta = $node->metadata() ?? [];

        // Parsers (Python, TypeScript) set this directly in metadata
        $epType = $meta['entrypoint_type'] ?? null;
        if ($epType !== null) {
            return match ($epType) {
                'http'            => 'http',
                'cli', 'script'   => 'cli',
                'async'           => 'event',
                default           => 'custom',
            };
        }

        // PHP 8 attributes
        foreach (($meta['attributes'] ?? []) as $attr) {
            if (preg_match('/Route|Get|Post|Put|Patch|Delete|ApiResource/', $attr)) {
                return 'http';
            }
            if (str_contains($attr, 'AsCommand')) {
                return 'cli';
            }
            if (str_contains($attr, 'WpHook') || str_contains($attr, 'WpAction') || str_contains($attr, 'WpFilter')) {
                return 'wp_hook';
            }
        }

        $method = $node->method();
        $class  = $node->class();

        // Eloquent accessors / mutators
        if (preg_match('/^(get|set)[A-Z][a-zA-Z0-9]*Attribute$/', $method)) {
            return 'eloquent';
        }

        // Livewire computed properties / watchers
        if (preg_match('/^(get[A-Z][a-zA-Z0-9]*Property|(updated|updating)[A-Z])/', $method)) {
            return 'livewire';
        }

        // Class-name heuristics
        if (str_ends_with($class, 'Controller') || str_ends_with($class, 'Action')) {
            return 'http';
        }
        if ($method === 'handle' && (str_ends_with($class, 'Command') || str_contains($class, '\\Command\\'))) {
            return 'cli';
        }
        if (str_ends_with($class, 'Listener') || str_ends_with($class, 'Subscriber') || str_ends_with($class, 'Handler')) {
            return 'event';
        }
        if (str_contains($class, 'Livewire') || str_ends_with($class, 'Component')) {
            return 'livewire';
        }

        return 'custom';
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
}
