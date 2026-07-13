<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Domain\Flow\Node as FlowNode;
use PhpParser\Node;

/**
 * Promove funções livres (declaradas fora de classe) a nós do grafo.
 *
 * Antes elas existiam apenas como SymbolDTO — pesquisáveis, mas sem arestas,
 * sem fan-in e invisíveis para impacto/órfãos. Em PHP procedural elas são o
 * tecido conjuntivo do sistema, então precisam ser nós de verdade.
 *
 * Também mantém o escopo de função no contexto, para que os visitors de chamada
 * atribuam corretamente as arestas emitidas de dentro do corpo da função.
 *
 * @internal
 */
final class FreeFunctionVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Stmt\Function_;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Stmt\Function_ $node */

        // Métodos são tratados por MethodVisitor.
        if ($context->isInsideClass()) {
            return;
        }

        $name = $node->name->toString();
        $line = $node->getStartLine();

        $context->addNode($context->nodeFactory()->create(
            class: $context->currentNamespace() ?? '',
            method: $name,
            file: $context->file(),
            line: $line >= 1 ? $line : null,
            language: 'php',
            metadata: [
                'endLine' => $node->getEndLine(),
                'relPath' => $context->relativeFile(),
            ],
            type: FlowNode::TYPE_FUNCTION
        ));

        $context->setFunction($name);
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        if ($context->isInsideClass()) {
            return;
        }

        $context->setFunction(null);
    }
}
