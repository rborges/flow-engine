<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Detecta acesso a propriedades estáticas: Class::$property
 * 
 * Exemplos:
 * - Config::$instance
 * - self::$cache
 * - static::$count
 * - Database::$connection
 * 
 * Formato da edge:
 * - From: CurrentClass::currentMethod
 * - To: PropertyClass::$propertyName
 * - Type: static_property
 * 
 * Casos especiais:
 * - self:: → resolve para classe atual
 * - static:: → resolve para classe atual
 * - parent:: → ignorado (limitação)
 * 
 * @internal
 */
final class StaticPropertyVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\StaticPropertyFetch;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\StaticPropertyFetch $node */
        
        if ($context->currentNodeId() === null) {
            return;
        }

        // Só rastrear se é variável simples (não dinâmica)
        if (!$node->name instanceof Node\VarLikeIdentifier) {
            return; // Property dinâmica (Class::$$prop), ignorar
        }

        $propertyName = $node->name->toString();

        // Extrair classe
        if (!$node->class instanceof Node\Name) {
            return; // Classe dinâmica ($class::$prop), ignorar
        }

        $className = $node->class->toString();

        // Resolver casos especiais
        if ($className === 'self' || $className === 'static') {
            $propertyClass = $context->currentClass();
        } elseif ($className === 'parent') {
            // Limitação: não sabemos a classe pai
            return;
        } else {
            // Resolver FQN
            $propertyClass = $context->resolveFQN($className);
        }

        // Criar edge
        $fromId = $context->currentNodeId();
        $toId = $propertyClass . '::$' . $propertyName;

        $context->addEdge(new \FlowEngine\Domain\Flow\Edge(
            $fromId,
            $toId,
            '$' . $propertyName,
            'static_property'
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
