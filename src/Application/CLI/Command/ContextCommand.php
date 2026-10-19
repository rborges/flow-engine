<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\AI\Export\ExportOptions;
use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Bootstrap\InfraServices;
use FlowEngine\Console\ConsoleIO;

final class ContextCommand implements Command
{
    public function __construct(
        private ConsoleIO $io
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'context';
    }

    public function handle(array $argv): void
    {
        $projectPath = $argv[2] ?? null;

        if (!$projectPath) {
            $this->io->error('Usage: flow context <project_path> [--minimal] [--sections=<s1,s2>] [--focus=<namespace>] [--entrypoint=<Class::method>] [--depth=N] [--catalog=<path>]');
            return;
        }

        $minimal = in_array('--minimal', $argv, true);
        $focusNamespace = null;
        $catalogPath = null;
        $entrypoint = null;
        $depth = 5;
        $strictEntrypoint = false;
        $sections = null;

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--focus=')) {
                $focusNamespace = substr($arg, 8);
            }
            if (str_starts_with($arg, '--catalog=')) {
                $catalogPath = substr($arg, 10);
            }
            if (str_starts_with($arg, '--entrypoint=')) {
                $entrypoint = substr($arg, 13);
            }
            if (str_starts_with($arg, '--depth=')) {
                $depth = (int) substr($arg, 8);
            }
            if ($arg === '--strict-entrypoint') {
                $strictEntrypoint = true;
            }
            if (str_starts_with($arg, '--sections=')) {
                $sections = array_map('trim', explode(',', substr($arg, 11)));
            }
        }

        $includeServiceMap = $catalogPath !== null;

        if ($sections !== null) {
            $has = static fn(string $s): bool => in_array($s, $sections, true);
            $options = new ExportOptions(
                includeMetrics: $has('metrics'),
                includeComplexity: $has('complexity'),
                includeCycles: $has('cycles'),
                includeArchitecture: $has('architecture'),
                includeOrphans: $has('orphans'),
                includeServiceMap: $has('serviceMap') || $includeServiceMap,
                includeDataModel: $has('dataModel'),
                includeRoutes: $has('routes'),
                focusNamespace: $focusNamespace,
                catalogPath: $catalogPath,
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strictEntrypoint,
            );
        } elseif ($minimal) {
            $options = new ExportOptions(
                includeMetrics: true,
                includeComplexity: false,
                includeCycles: false,
                includeArchitecture: false,
                includeOrphans: false,
                includeServiceMap: $includeServiceMap,
                includeDataModel: false,
                includeRoutes: false,
                focusNamespace: $focusNamespace,
                catalogPath: $catalogPath,
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strictEntrypoint,
            );
        } else {
            $options = new ExportOptions(
                includeServiceMap: $includeServiceMap,
                includeDataModel: true,
                includeRoutes: true,
                focusNamespace: $focusNamespace,
                catalogPath: $catalogPath,
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strictEntrypoint,
            );
        }

        $container = new Container($projectPath);
        $container->analyzeProject()->execute();

        $appmapData = null;
        if ($includeServiceMap && $catalogPath !== null) {
            $appmapData = $this->buildAppmap($catalogPath);
        }

        $exportContext = $container->exportContext($appmapData);
        $result = $exportContext->execute($options);

        echo $result->markdown;
        fwrite(STDERR, "[INFO] Token estimate: {$result->tokenEstimate}\n");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAppmap(string $catalogPath): ?array
    {
        $entries = $this->entriesFromCatalog($catalogPath);
        if (count($entries) < 2) {
            return null;
        }

        $services = (new CatalogServiceBuilder())->buildFromEntries($entries);

        return (new ApplicationMapBuilder())->build($services);
    }

    /**
     * @return array<int, array{path: string, name: string|null, hostnames: string[]}>
     */
    private function entriesFromCatalog(string $catalogPath): array
    {
        $enriched = (new InfraServices())->resolveCatalogServices()->enrichedEntries($catalogPath);

        return array_map(static fn(array $entry): array => [
            'path' => $entry['path'],
            'name' => $entry['name'],
            'hostnames' => $entry['hostnames'] ?? [],
        ], $enriched);
    }
}
