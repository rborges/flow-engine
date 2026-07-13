<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Detecta chamadas a funções livres (globais ou de namespace).
 *
 * Exemplos:
 * - custom_helper($data)
 * - Namespace\function_name()
 *
 * Formato da edge:
 * - From: nó corrente (método, função ou arquivo)
 * - To:   func:nome  /  func:Namespace\nome
 * - Type: function_call
 *
 * Chamadas a funções nativas do PHP (str_replace, count, ...) e a funções de
 * arquivos fora do escopo do scan geram arestas para alvos inexistentes; o
 * FlowBuilder as descarta ao construir o grafo. Isso evita manter aqui uma
 * lista de builtins sempre incompleta.
 *
 * Limitações:
 * - Não detecta chamadas dinâmicas: $func()
 *
 * @internal
 */
final class FunctionCallVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\FuncCall;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\FuncCall $node */

        $fromId = $context->currentNodeId();

        if ($fromId === null) {
            return;
        }

        // Ignorar funções dinâmicas ($func())
        if (!$node->name instanceof Node\Name) {
            return;
        }

        $functionName = $node->name->toString();

        if (str_contains($functionName, '\\')) {
            $fqn = ltrim($functionName, '\\');
        } elseif ($context->currentNamespace()) {
            $fqn = $context->currentNamespace() . '\\' . $functionName;
        } else {
            $fqn = $functionName;
        }

        $context->addEdge(new \FlowEngine\Domain\Flow\Edge(
            $fromId,
            'func:' . $fqn,
            $functionName,
            'function_call'
        ));
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        // Nada a fazer
    }
}
