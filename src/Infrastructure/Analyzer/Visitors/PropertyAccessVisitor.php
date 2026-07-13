<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Detecta acesso a propriedades de objetos: $obj->property
 * 
 * NOVO em v2.1 - Rastreia dependências via properties
 * 
 * Exemplos:
 * - $this->repository
 * - $user->name
 * - $this->config->get()
 * 
 * Formato da edge:
 * - From: CurrentClass::currentMethod
 * - To: PropertyClass::$propertyName
 * - Type: property_access
 * 
 * Limitações:
 * - Não resolve tipo de variáveis arbitrárias ($var->property)
 * - Properties dinâmicas ($obj->$prop) são ignoradas
 * 
 * @internal
 */
final class PropertyAccessVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\PropertyFetch;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\PropertyFetch $node */
        
        if ($context->currentNodeId() === null) {
            return;
        }

        // Só rastrear se a property é um Identifier (não dinâmica)
        if (!$node->name instanceof Node\Identifier) {
            return; // Property dinâmica ($obj->$prop), ignorar
        }

        $propertyName = $node->name->toString();

        // Inferir classe dona da property
        $propertyClass = $this->inferClass($node->var, $context);

        if (!$propertyClass) {
            return; // Não conseguimos inferir, ignorar
        }

        // Criar edge especial para property access
        $fromId = $context->currentNodeId();
        $toId = $propertyClass . '::$' . $propertyName;

        $context->addEdge(new \FlowEngine\Domain\Flow\Edge(
            $fromId,
            $toId,
            '$' . $propertyName,
            'property_access'
        ));
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        // Nada a fazer
    }

    /**
     * Tenta inferir a classe de uma variável/expressão.
     * 
     * Casos suportados:
     * - $this->property → classe atual
     * - new Class() → Class
     * - Encadeamento: $obj->foo()->property
     * - Property: $this->property->anotherProperty
     * 
     * @internal
     */
    private function inferClass(Node\Expr $expr, VisitorContext $context): ?string
    {
        // Caso 1: $this->property
        if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
            return $context->currentClass();
        }

        // Caso 2: (new Class())->property
        if ($expr instanceof Node\Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                return $context->resolveFQN($className);
            }
        }

        // Caso 3: Método encadeado ($obj->foo()->property)
        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->inferClass($expr->var, $context);
        }

        // Caso 4: Property encadeada ($this->property->anotherProperty)
        if ($expr instanceof Node\Expr\PropertyFetch) {
            // Se for $this->property, retornar classe atual
            if ($expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this') {
                return $context->currentClass();
            }

            // Tentar resolver recursivamente
            return $this->inferClass($expr->var, $context);
        }

        // Casos não suportados
        return null;
    }
}
