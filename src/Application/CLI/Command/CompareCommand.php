<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Bootstrap\Container;

final class CompareCommand implements Command
{
    public function __construct(
        private \FlowEngine\Console\ConsoleIO $io
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'compare';
    }

    public function handle(array $argv): void
    {
        [$pathA, $pathB] = [$argv[2] ?? null, $argv[3] ?? null];

        if (!$pathA || !$pathB) {
            $this->io->error('Usage: flow compare <project_path_a> <project_path_b>');
            return;
        }

        $resultA = $this->analyzeProject($pathA);
        $resultB = $this->analyzeProject($pathB);

        $flowDiff = $this->diffFlows($resultA['flow'], $resultB['flow']);
        $reportDiffs = $this->diffReports($resultA['reports'], $resultB['reports']);

        $this->io->json([
            'status' => 'ok',
            'projects' => [
                'before' => $pathA,
                'after' => $pathB,
            ],
            'flow' => $flowDiff,
            'reports' => $reportDiffs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeProject(string $path): array
    {
        $container = new Container($path);
        $container->analyzeProject()->execute();

        $flow = $container->flowSnapshotExporter()->export($container->getFlow());

        $hash = $container->cacheHash();
        $cache = $container->reportCache();

        $reports = $hash ? $cache->loadValid($hash) : null;
        if ($reports === null) {
            $reports = [
                'complexity' => $container->analyzeComplexity()->execute()->toArray(),
                'cycles' => $container->analyzeCycles()->execute()->toArray(),
                'architecture' => $container->analyzeArchitecture()->execute()->toArray(),
                'metrics' => $container->analyzeMetrics()->execute()->toArray(),
                'orphans' => $container->analyzeOrphans()->execute()->toArray(),
            ];

            if ($hash) {
                $cache->save($reports, $hash);
            }
        }

        return [
            'flow' => $flow,
            'reports' => $reports,
        ];
    }

    /**
     * @param array<string, mixed> $flowA
     * @param array<string, mixed> $flowB
     * @return array<string, mixed>
     */
    private function diffFlows(array $flowA, array $flowB): array
    {
        $nodesA = $this->indexNodes($flowA['nodes'] ?? []);
        $nodesB = $this->indexNodes($flowB['nodes'] ?? []);

        $edgesA = $this->indexEdges($flowA['edges'] ?? []);
        $edgesB = $this->indexEdges($flowB['edges'] ?? []);

        $addedNodes = array_values(array_diff(array_keys($nodesB), array_keys($nodesA)));
        $removedNodes = array_values(array_diff(array_keys($nodesA), array_keys($nodesB)));

        $addedEdges = $this->diffEdgeSet($edgesA, $edgesB);
        $removedEdges = $this->diffEdgeSet($edgesB, $edgesA);

        $statsA = $flowA['stats'] ?? ['nodeCount' => 0, 'edgeCount' => 0];
        $statsB = $flowB['stats'] ?? ['nodeCount' => 0, 'edgeCount' => 0];

        return [
            'before' => $statsA,
            'after' => $statsB,
            'delta' => [
                'nodeCount' => ($statsB['nodeCount'] ?? 0) - ($statsA['nodeCount'] ?? 0),
                'edgeCount' => ($statsB['edgeCount'] ?? 0) - ($statsA['edgeCount'] ?? 0),
            ],
            'nodesAdded' => $addedNodes,
            'nodesRemoved' => $removedNodes,
            'edgesAdded' => $addedEdges,
            'edgesRemoved' => $removedEdges,
        ];
    }

    /**
     * @param array<string, mixed> $reportsA
     * @param array<string, mixed> $reportsB
     * @return array<string, mixed>
     */
    private function diffReports(array $reportsA, array $reportsB): array
    {
        $result = [];
        $keys = array_unique(array_merge(array_keys($reportsA), array_keys($reportsB)));

        foreach ($keys as $key) {
            $before = $reportsA[$key] ?? [];
            $after = $reportsB[$key] ?? [];

            $result[$key] = [
                'before' => $before,
                'after' => $after,
                'delta' => $this->numericDelta($before, $after),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function indexNodes(array $nodes): array
    {
        $indexed = [];

        foreach ($nodes as $node) {
            $id = $node['id'] ?? ($node['class'] ?? '') . '::' . ($node['method'] ?? '');
            $indexed[$id] = $node;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, array<string, mixed>>
     */
    private function indexEdges(array $edges): array
    {
        $indexed = [];

        foreach ($edges as $edge) {
            $key = ($edge['from'] ?? '') . '|' . ($edge['to'] ?? '') . '|' . ($edge['type'] ?? '') . '|' . ($edge['method'] ?? '');
            $indexed[$key] = $edge;
        }

        return $indexed;
    }

    /**
     * @param array<string, array<string, mixed>> $base
     * @param array<string, array<string, mixed>> $compare
     * @return array<int, array<string, mixed>>
     */
    private function diffEdgeSet(array $base, array $compare): array
    {
        $diff = [];

        foreach ($compare as $key => $edge) {
            if (!isset($base[$key])) {
                $diff[] = $edge;
            }
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function numericDelta(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $key => $value) {
            if (is_numeric($value) && isset($before[$key]) && is_numeric($before[$key])) {
                $delta[$key] = $value - $before[$key];
            }
        }

        return $delta;
    }
}
