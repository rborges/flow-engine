<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Detecta chamadas estáticas de métodos: Class::method()
 * 
 * Exemplos:
 * - Helper::format($data)
 * - self::getInstance()
 * - static::create()
 * - User::find($id)
 * 
 * Formato da edge:
 * - From: CurrentClass::currentMethod
 * - To: CalledClass::calledMethod
 * - Type: static_call
 * 
 * Casos especiais:
 * - self:: → resolve para classe atual
 * - static:: → resolve para classe atual
 * - parent:: → ignorado (limitação)
 * 
 * @internal
 */
final class StaticCallVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\StaticCall;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\StaticCall $node */
        
        if ($context->currentNodeId() === null) {
            return;
        }

        // Extrair nome do método chamado
        if (!$node->name instanceof Node\Identifier) {
            return; // Método dinâmico (Class::$method()), ignorar
        }

        $calledMethod = $node->name->toString();

        // Extrair classe sendo chamada
        if (!$node->class instanceof Node\Name) {
            return; // Classe dinâmica ($class::method()), ignorar
        }

        $className = $node->class->toString();

        // Resolver casos especiais
        if ($className === 'self' || $className === 'static') {
            $calledClass = $context->currentClass();
        } elseif ($className === 'parent') {
            // Limitação: não sabemos a classe pai
            return;
        } else {
            // Resolver FQN
            $calledClass = $context->resolveClass($className);
        }

        // Criar edge
        $fromId = $context->currentNodeId();
        $toId = $calledClass . '::' . $calledMethod;

        $context->addEdge(new \FlowEngine\Domain\Flow\Edge(
            $fromId,
            $toId,
            $calledMethod,
            'static_call'
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
