<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Detecta chamadas de métodos: $obj->method()
 * 
 * Exemplos:
 * - $this->helper()
 * - $user->getName()
 * - (new Service())->process()
 * - $this->repository->find()
 * 
 * Formato da edge:
 * - From: CurrentClass::currentMethod
 * - To: CalledClass::calledMethod
 * - Type: method_call
 * 
 * Limitações:
 * - Não resolve tipo de variáveis arbitrárias ($var->method())
 * - Requer análise estática simples (new, $this, encadeamento)
 * 
 * @internal
 */
final class MethodCallVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\MethodCall;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\MethodCall $node */
        
        if ($context->currentNodeId() === null) {
            return;
        }

        // Extrair nome do método chamado
        if (!$node->name instanceof Node\Identifier) {
            return; // Método dinâmico ($obj->$method()), ignorar
        }

        $calledMethod = $node->name->toString();

        // Tentar inferir a classe sendo chamada
        $calledClass = $this->inferClass($node->var, $context, $calledMethod);

        if (!$calledClass) {
            return; // Não conseguimos inferir, ignorar
        }

        // Criar edge
        $fromId = $context->currentNodeId();
        $toId = $calledClass . '::' . $calledMethod;

        $context->addEdge(new \FlowEngine\Domain\Flow\Edge(
            $fromId,
            $toId,
            $calledMethod,
            'method_call'
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
     * - $this->method() → classe atual
     * - new Class() → Class
     * - self/static → classe atual
     * - Encadeamento: $obj->foo()->bar()
     * - Property: $this->property->method()
     * 
     * Limitações:
     * - Não resolve type hints
     * - Não resolve variáveis ($var->method())
     * 
     * @internal
     */
    private function inferClass(Node\Expr $expr, VisitorContext $context, ?string $targetMethod = null): ?string
    {
        // Caso 1: $this->method()
        if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
            return $context->currentClass();
        }

        // Caso 2: (new Class())->method()
        if ($expr instanceof Node\Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                return $context->resolveClass($className);
            }
        }

        // Caso 3: Método encadeado ($obj->foo()->bar())
        if ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->name instanceof Node\Identifier) {
                $factoryMethod = $expr->name->toString();
                $baseClass = $this->inferClass($expr->var, $context, $factoryMethod);

                if ($baseClass !== null) {
                    $resolvedFluentReturn = $this->resolveFluentReturnClass($baseClass, $factoryMethod, $targetMethod);
                    if ($resolvedFluentReturn !== null) {
                        return $resolvedFluentReturn;
                    }
                }
            }

            return $this->inferClass($expr->var, $context, $targetMethod);
        }

        // Caso 4: Property encadeada ($this->property->method())
        if ($expr instanceof Node\Expr\PropertyFetch) {
            if (
                $expr->var instanceof Node\Expr\Variable &&
                $expr->var->name === 'this' &&
                $expr->name instanceof Node\Identifier
            ) {
                // Try to resolve the property to its declared type (via __construct params).
                $propType = $context->getPropertyType($expr->name->toString());
                if ($propType !== null) {
                    return $propType;
                }
                // Fallback: assume same class (e.g. $this->helper() calling a sibling method)
                return $context->currentClass();
            }

            // Tentar resolver recursivamente
            return $this->inferClass($expr->var, $context, $targetMethod);
        }

        // Caso 5: Static property (Class::$instance->method())
        if ($expr instanceof Node\Expr\StaticPropertyFetch) {
            if ($expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                return $context->resolveClass($className);
            }
        }

        // Caso 6: Variable with a known type.
        //
        // Two sources of type information are checked in priority order:
        //   a) Local variable: $var = new Foo() tracked by LocalAssignVisitor.
        //   b) Constructor parameter: __construct(Foo $bar) tracked by MethodVisitor.
        //      This covers both promoted properties and non-promoted params used
        //      directly in the constructor body (e.g. $validator->validate()).
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && $expr->name !== 'this') {
            $type = $context->getLocalVariableType($expr->name)
                 ?? $context->getPropertyType($expr->name);
            if ($type !== null) {
                return $type;
            }
        }

        // Casos não suportados
        return null;
    }

    /**
     * Resolves chained fluent returns for known container-dispatch patterns.
     *
     * Example:
     *   $container->analyzeBugs()->execute()
     * infers:
     *   FlowEngine\Application\UseCase\AnalyzeBugs::execute
     */
    private function resolveFluentReturnClass(string $baseClass, string $factoryMethod, ?string $targetMethod): ?string
    {
        if ($targetMethod !== 'execute') {
            return null;
        }

        if (!str_ends_with($baseClass, '\\Bootstrap\\Container')) {
            return null;
        }

        $root = substr($baseClass, 0, -strlen('\\Bootstrap\\Container'));
        if ($root === '') {
            return null;
        }

        return $root . '\\Application\\UseCase\\' . ucfirst($factoryMethod);
    }
}
