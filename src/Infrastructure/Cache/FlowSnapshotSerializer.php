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
        $nodes = [];
        $edges = [];

        foreach ($data['nodes'] ?? [] as $nodeData) {
            $class = $nodeData['class'] ?? null;
            $method = $nodeData['method'] ?? null;
            $language = $nodeData['language'] ?? null;
            $id = (string) ($nodeData['id'] ?? '');

            // Só reconstrói a partir do ID quando os campos estão AUSENTES (payload
            // antigo). Uma função global tem classe vazia — e string vazia é falsy —,
            // então testar por falsy aqui corromperia o nó para "func:func:nome\".
            if ($class === null || $method === null) {
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
                // "func:" e "file:" são prefixos de tipo, não de linguagem.
                if (!str_starts_with($id, 'func:')
                    && !str_starts_with($id, 'file:')
                    && preg_match('/^([a-z]+):/', $id, $m)
                ) {
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
                $metadata,
                $nodeData['type'] ?? Node::TYPE_METHOD
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
}
