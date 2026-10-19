<?php

namespace FlowEngine\Application\AppMap;

use FlowEngine\Domain\Contracts\Flow;
use FlowEngine\Domain\Flow\Node;

final class NodeLocator
{
    private ?Flow $indexedFlow = null;

    /** @var array<string, Node[]> */
    private array $nodesByFile = [];

    /**
     * Nó do arquivo que contém a linha: o mais interno cujo [line, endLine] a cobre; sem endLine (Python),
     * o de maior line <= linha; fora de qualquer nó, o nó-arquivo do PHP procedural.
     */
    public function locate(Flow $flow, string $file, int $line): ?string
    {
        $bestId = null;
        $bestLine = -1;
        $fileNodeId = null;

        foreach ($this->nodesIn($flow, $file) as $node) {
            if ($node->type() === Node::TYPE_FILE) {
                $fileNodeId = $node->id();
                continue;
            }

            $nodeLine = $node->line();
            if ($nodeLine === null || $nodeLine > $line || $nodeLine <= $bestLine) {
                continue;
            }

            $endLine = $node->metadata()['endLine'] ?? null;
            if (is_int($endLine) && $endLine < $line) {
                continue;
            }

            $bestLine = $nodeLine;
            $bestId = $node->id();
        }

        return $bestId ?? $fileNodeId;
    }

    /**
     * @return Node[]
     */
    private function nodesIn(Flow $flow, string $file): array
    {
        if ($this->indexedFlow !== $flow) {
            $this->indexedFlow = $flow;
            $this->nodesByFile = [];
            foreach ($flow->nodes() as $node) {
                $this->nodesByFile[$this->normalize($node->file())][] = $node;
            }
        }

        return $this->nodesByFile[$this->normalize($file)] ?? [];
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return preg_match('/^[A-Za-z]:\//', $path) === 1 ? lcfirst($path) : $path;
    }
}
