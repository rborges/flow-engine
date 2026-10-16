<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\AI\Export\FlowDiagramGenerator;
use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Application\AppMap\MermaidDiagramGenerator;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Bootstrap\InfraServices;
use FlowEngine\Console\ConsoleIO;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\FlowTracer;

final class DiagramCommand implements Command
{
    public function __construct(
        private ConsoleIO $io
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'diagram';
    }

    public function handle(array $argv): void
    {
        $catalog = $this->extractOption($argv, '--catalog');

        $entries = $catalog !== null
            ? $this->entriesFromCatalog($catalog)
            : array_map(
                fn($p) => [
                    'path' => $p,
                    'name' => null,
                    'hostnames' => [],
                    'contractEndpoints' => [],
                ],
                array_values(array_filter(
                    array_slice($argv, 2),
                    fn($p) => $p !== '' && !str_starts_with($p, '--')
                ))
            );

        if (count($entries) === 0) {
            $this->io->error('Usage: flow diagram <project_path> [--view=flowchart|class|component] [--entrypoint=Class::method]');
            $this->io->info('Or:    flow diagram <project_path_a> <project_path_b> [--type=dependency|sequence]');
            $this->io->info('Or:    flow diagram --catalog=flow-services.json');
            return;
        }

        if (count($entries) === 1) {
            $this->handleSingleProject($entries[0]['path'], $argv);
            return;
        }

        $this->handleMultiProject($entries, $argv);
    }

    // -------------------------------------------------------------------------
    // Single-project mode (v4.3)
    // -------------------------------------------------------------------------

    private function handleSingleProject(string $path, array $argv): void
    {
        $view       = strtolower((string) ($this->extractOption($argv, '--view') ?? 'flowchart'));
        $entrypoint = $this->extractOption($argv, '--entrypoint');
        $namespace  = $this->extractOption($argv, '--namespace');
        $depth      = (int) ($this->extractOption($argv, '--depth') ?? 5);

        if (!in_array($view, ['flowchart', 'class', 'component', 'c4context'], true)) {
            $this->io->error('Invalid --view. Use --view=flowchart, --view=class, --view=component, or --view=c4context');
            return;
        }

        if ($view === 'flowchart' && $entrypoint === null) {
            $this->io->error('--view=flowchart requires --entrypoint=<Class::method>');
            $this->io->info('Tip: run `flow entrypoints ' . $path . '` to list available entrypoints');
            return;
        }

        $root = realpath($path) ?: $path;
        $container = new Container($root);
        $container->analyzeProject()->execute();

        /** @var Flow $flow */
        $flow      = $container->getFlow();
        $generator = new FlowDiagramGenerator();

        $mermaid = match ($view) {
            'flowchart' => $generator->flowchart(
                $flow,
                new FlowTracer($flow),
                (string) $entrypoint,
                $depth
            ),
            'class'     => $generator->classDiagram($flow, $namespace),
            'component' => $generator->componentDiagram($flow, $namespace),
            'c4context' => $generator->c4Context($flow, basename($root)),
        };

        $title = match ($view) {
            'flowchart' => 'Entry-Point Flowchart: ' . $entrypoint,
            'class'     => 'Class Diagram' . ($namespace !== null ? ": {$namespace}" : ''),
            'component' => 'Component Diagram' . ($namespace !== null ? ": {$namespace}" : ''),
            'c4context' => 'C4 Context Diagram: ' . basename($root),
        };

        $markdown  = "# {$title}\n\n";
        $markdown .= 'Generated: ' . date('c') . "\n\n";
        $markdown .= "```mermaid\n{$mermaid}\n```\n";

        echo $markdown;
    }

    // -------------------------------------------------------------------------
    // Multi-project mode (original behaviour)
    // -------------------------------------------------------------------------

    private function handleMultiProject(array $entries, array $argv): void
    {
        $type = strtolower((string) ($this->extractOption($argv, '--type') ?? 'dependency'));
        if (!in_array($type, ['dependency', 'sequence', 'c4container'], true)) {
            $this->io->error('Invalid --type. Use --type=dependency, --type=sequence, or --type=c4container');
            return;
        }

        $services = (new CatalogServiceBuilder())->buildFromEntries(array_map(
            static fn(array $entry): array => ['contractEndpoints' => $entry['contractEndpoints'] ?? []] + $entry,
            $entries
        ));

        $appmap    = (new ApplicationMapBuilder())->build($services);
        $generator = new MermaidDiagramGenerator();
        $mermaid   = match ($type) {
            'sequence'    => $generator->sequenceDiagram($appmap),
            'c4container' => $generator->c4Container($appmap),
            default       => $generator->dependencyGraph($appmap),
        };

        $title = match ($type) {
            'sequence'    => 'System Sequence Diagram',
            'c4container' => 'C4 Container Diagram',
            default       => 'System Dependency Diagram',
        };

        $markdown  = "# {$title}\n\n";
        $markdown .= 'Generated: ' . date('c') . "\n\n";
        $markdown .= "```mermaid\n{$mermaid}\n```\n\n";

        $incons = $appmap['inconsistencies'] ?? [];
        if (is_array($incons) && count($incons) > 0) {
            $markdown .= "## Inconsistencies\n\n";
            foreach ($incons as $i) {
                if (!is_array($i)) {
                    continue;
                }
                $markdown .= '- ' . (($i['type'] ?? '') ?: 'ISSUE') . ': ' . (($i['message'] ?? '') ?: '') . "\n";
            }
            $markdown .= "\n";
        }

        echo $markdown;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * @return array<int, array{path: string, name: string|null, hostnames: string[], contractEndpoints: array<int, array{method: string, path: string, summary: string}>}>
     */
    private function entriesFromCatalog(string $catalogPath): array
    {
        return (new InfraServices())->resolveCatalogServices()->enrichedEntries($catalogPath);
    }
}
