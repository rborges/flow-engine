<?php

namespace FlowEngine\Infrastructure\Cache;

use FlowEngine\Application\DTO\EdgeDTO;
use FlowEngine\Application\DTO\NodeDTO;
use FlowEngine\Application\DTO\SymbolDTO;
use FlowEngine\Domain\Contracts\Flow as FlowContract;
use FlowEngine\Domain\Contracts\FlowSnapshotExporterPort;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\SymbolIndex;
use FlowEngine\Domain\Node\NodeVisibility;

final class FlowSnapshotSerializer implements FlowSnapshotExporterPort
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(FlowContract $flow): array
    {
        $nodes = array_map(
            fn(Node $node) => NodeDTO::fromNode($node)->toArray(),
            $flow->nodes()
        );

        $edges = array_map(
            fn(Edge $edge) => EdgeDTO::fromEdge($edge)->toArray(),
            $flow->edges()
        );

        $symbols = array_map(
            fn(SymbolDTO $s) => $s->toArray(),
            $flow->symbols()->all()
        );

        return [
            'stats' => [
                'nodeCount'   => $flow->nodeCount(),
                'edgeCount'   => $flow->edgeCount(),
                'symbolCount' => $flow->symbols()->count(),
            ],
            'nodes'   => $nodes,
            'edges'   => $edges,
            'symbols' => $symbols,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(FlowContract $flow): array
    {
        return $this->toArray($flow);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function toFlow(array $data): Flow
    {
        $this->assertValidSnapshot($data);

        $nodes = [];
        $edges = [];

        foreach ($data['nodes'] ?? [] as $nodeData) {
            $class = $nodeData['class'] ?? null;
            $method = $nodeData['method'] ?? null;
            $language = $nodeData['language'] ?? null;
            $id = (string) ($nodeData['id'] ?? '');

            if (!$class || !$method) {
                [$class, $method] = explode('::', $id) + ['', ''];

                if ($language === null) {
                    $prefixPos = strpos((string) $class, ':');
                    if ($prefixPos !== false) {
                        $language = substr((string) $class, 0, $prefixPos);
                        $class = substr((string) $class, $prefixPos + 1);
                    }
                }
            }

            if ($language === null) {
                if (preg_match('/^([a-z]+):/', $id, $m)) {
                    $language = $m[1];
                } else {
                    $language = 'php';
                }
            }

            $metadata = $nodeData['metadata'] ?? null;

            $node = new Node(
                $class,
                $method,
                $nodeData['file'] ?? '',
                $nodeData['line'] ?? null,
                $language,
                $metadata
            );

            if (!empty($nodeData['visibility'])) {
                $node = $node->withVisibility(
                    new NodeVisibility($nodeData['visibility'])
                );
            }

            $nodes[] = $node;
        }

        foreach ($data['edges'] ?? [] as $edgeData) {
            $edges[] = new Edge(
                $edgeData['from'] ?? '',
                $edgeData['to'] ?? '',
                $edgeData['method'] ?? '',
                $edgeData['type'] ?? 'method_call'
            );
        }

        $symbols = [];
        foreach ($data['symbols'] ?? [] as $sd) {
            $symbols[] = new SymbolDTO(
                $sd['id'],
                $sd['name'],
                $sd['kind'],
                $sd['file'],
                $sd['line'],
                $sd['sourceModule'] ?? null
            );
        }

        return new Flow($nodes, $edges, new SymbolIndex($symbols));
    }

    /** @param array<string, mixed> $data */
    private function assertValidSnapshot(array $data): void
    {
        if (
            !is_array($data['stats'] ?? null)
            || !is_array($data['nodes'] ?? null)
            || !is_array($data['edges'] ?? null)
            || !is_array($data['symbols'] ?? null)
            || !is_int($data['stats']['nodeCount'] ?? null)
            || !is_int($data['stats']['edgeCount'] ?? null)
            || !is_int($data['stats']['symbolCount'] ?? null)
            || $data['stats']['nodeCount'] !== count($data['nodes'])
            || $data['stats']['edgeCount'] !== count($data['edges'])
            || $data['stats']['symbolCount'] !== count($data['symbols'])
        ) {
            throw new \RuntimeException('Invalid flow cache structure');
        }

        $nodeIds = [];
        foreach ($data['nodes'] as $node) {
            if (
                !$this->hasStringFields($node, ['id', 'class', 'method', 'file', 'visibility', 'language'])
                || !array_key_exists('line', $node)
                || ($node['line'] !== null && !is_int($node['line']))
                || !is_bool($node['isPublic'] ?? null)
                || (isset($node['metadata']) && !is_array($node['metadata']))
                || !in_array($node['visibility'], [NodeVisibility::PUBLIC, NodeVisibility::HIDDEN], true)
                || $node['isPublic'] !== ($node['visibility'] === NodeVisibility::PUBLIC)
                || $node['id'] !== $this->expectedNodeId($node['language'], $node['class'], $node['method'])
                || isset($nodeIds[$node['id']])
            ) {
                throw new \RuntimeException('Invalid flow cache node structure');
            }
            $nodeIds[$node['id']] = true;
        }
        foreach ($data['edges'] as $edge) {
            if (!$this->hasStringFields($edge, ['from', 'to', 'method', 'type'])) {
                throw new \RuntimeException('Invalid flow cache edge structure');
            }
        }
        foreach ($data['symbols'] as $symbol) {
            if (
                !$this->hasStringFields($symbol, ['id', 'name', 'kind', 'file'])
                || !is_int($symbol['line'] ?? null)
                || (isset($symbol['sourceModule']) && !is_string($symbol['sourceModule']))
            ) {
                throw new \RuntimeException('Invalid flow cache symbol structure');
            }
        }
    }

    /** @param string[] $fields */
    private function hasStringFields(mixed $value, array $fields): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($fields as $field) {
            if (!isset($value[$field]) || !is_string($value[$field])) {
                return false;
            }
        }

        return true;
    }

    private function expectedNodeId(string $language, string $class, string $method): string
    {
        $base = $class . '::' . $method;

        return $language === 'php' ? $base : $language . ':' . $base;
    }
}
