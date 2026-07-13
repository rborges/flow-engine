<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Application\Policy\NodeVisibilityPolicy;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\SymbolIndex;

/**
 * Constrói o Flow aplicando policies de visibilidade.
 *
 * IDs duplicados são deduplicados silenciosamente (mantém última ocorrência).
 * A preferência pela última ocorrência alinha com TypeScript overloads, onde
 * o parser emite as assinaturas antes da implementação — manter a última
 * significa conservar a linha do corpo real. Preservar ordem de inserção
 * para os demais nós.
 *
 * A lista dos IDs duplicados encontrados fica disponível via lastDuplicateIds()
 * para que camadas superiores (repository/MCP) possam emitir warning.
 */
final class FlowBuilder
{
    /** @var string[] */
    private array $lastDuplicateIds = [];

    public function __construct(
        private NodeVisibilityPolicy $visibilityPolicy
    ) {
    }

    /**
     * @api
     * @param Node[] $nodes
     * @param \FlowEngine\Domain\Flow\Edge[] $edges
     */
    public function build(array $nodes, array $edges, ?SymbolIndex $symbols = null): Flow
    {
        $nodes = array_map(
            fn(Node $node) => $node->withVisibility(
                $this->visibilityPolicy->visibility($node)
            ),
            $nodes
        );

        foreach ($nodes as $node) {
            $node->validate();
        }

        $deduped = $this->dedupeById($nodes);

        return new Flow($deduped, $this->resolveFunctionCalls($deduped, $edges), $symbols);
    }

    /**
     * Resolve — ou descarta — as arestas function_call.
     *
     * Ao visitar a chamada não há como saber se a função é nativa do PHP
     * (str_replace), vem de um arquivo fora do scan (vendor) ou é do projeto.
     * Aqui, com o grafo completo, a resposta é trivial: se não virou nó, a aresta
     * não interessa. Evita depender de uma lista de builtins sempre incompleta.
     *
     * Antes de descartar, aplica a regra de resolução do próprio PHP: uma chamada
     * não-qualificada dentro de um namespace cai para a função global quando não
     * existe a versão namespaced. Sem isso, um arquivo com namespace chamando uma
     * função global do projeto perderia a aresta.
     *
     * Só afeta function_call — arestas como __parent/__trait apontam de propósito
     * para âncoras sem nó.
     *
     * @param Node[] $nodes
     * @param \FlowEngine\Domain\Flow\Edge[] $edges
     * @return \FlowEngine\Domain\Flow\Edge[]
     */
    private function resolveFunctionCalls(array $nodes, array $edges): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[$node->id()] = true;
        }

        $resolved = [];

        foreach ($edges as $edge) {
            if ($edge->type() !== 'function_call') {
                $resolved[] = $edge;
                continue;
            }

            if (isset($ids[$edge->to()])) {
                $resolved[] = $edge;
                continue;
            }

            $global = $this->globalFunctionId($edge->to());

            if ($global !== null && isset($ids[$global])) {
                $resolved[] = new \FlowEngine\Domain\Flow\Edge(
                    $edge->from(),
                    $global,
                    $edge->method(),
                    $edge->type()
                );
            }
        }

        return $resolved;
    }

    /**
     * func:Namespace\nome → func:nome. Null quando o ID já é global.
     */
    private function globalFunctionId(string $functionId): ?string
    {
        if (!str_starts_with($functionId, 'func:')) {
            return null;
        }

        $fqn = substr($functionId, 5);
        $lastSeparator = strrpos($fqn, '\\');

        if ($lastSeparator === false) {
            return null;
        }

        return 'func:' . substr($fqn, $lastSeparator + 1);
    }

    /**
     * IDs duplicados detectados na última chamada a build().
     * Cada ID aparece uma única vez, ordenado alfabeticamente.
     *
     * @api
     * @return string[]
     */
    public function lastDuplicateIds(): array
    {
        return $this->lastDuplicateIds;
    }

    public function visibilityPolicy(): NodeVisibilityPolicy
    {
        return $this->visibilityPolicy;
    }

    /**
     * @param Node[] $nodes
     * @return Node[]
     */
    private function dedupeById(array $nodes): array
    {
        $positions = [];
        $duplicates = [];
        $unique = [];

        foreach ($nodes as $node) {
            $id = $node->id();
            if (isset($positions[$id])) {
                $duplicates[$id] = true;
                $unique[$positions[$id]] = $node;
                continue;
            }
            $positions[$id] = count($unique);
            $unique[] = $node;
        }

        $ids = array_keys($duplicates);
        sort($ids);
        $this->lastDuplicateIds = $ids;

        return array_values($unique);
    }
}
