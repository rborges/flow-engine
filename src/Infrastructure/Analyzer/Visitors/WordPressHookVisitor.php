<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Domain\Flow\Edge;
use PhpParser\Node;

/**
 * Detecta registros de hooks WordPress: add_action() e add_filter().
 *
 * Cria edges do tipo `wp_hook` do método que registra o hook para o
 * método callback, evitando que callbacks registrados via hook sejam
 * classificados como código órfão.
 *
 * Padrões suportados:
 *   add_action('hook', [$this, 'myCallback'])
 *   add_filter('hook', [$this, 'myFilter'])
 *   add_action('hook', 'ClassName::myMethod')
 *   add_action('hook', [ClassName::class, 'myMethod'])
 *
 * @internal
 */
final class WordPressHookVisitor implements NodeVisitor
{
    private const HOOK_FUNCTIONS = ['add_action', 'add_filter'];

    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        if (!$node instanceof Node\Expr\FuncCall) {
            return false;
        }

        if (!$node->name instanceof Node\Name) {
            return false;
        }

        return in_array($node->name->getLast(), self::HOOK_FUNCTIONS, true);
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\FuncCall $node */

        if ($context->currentNodeId() === null) {
            return;
        }

        // Callback is the second argument
        $callbackArg = $node->args[1] ?? null;
        if (!$callbackArg instanceof Node\Arg) {
            return;
        }

        $fromId = $context->currentNodeId();
        $toId = $this->resolveCallback($callbackArg->value, $context);

        if ($toId === null || $fromId === null) {
            return;
        }

        $context->addEdge(new Edge($fromId, $toId, 'callback', 'wp_hook'));
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        // Nothing to clean up
    }

    /**
     * Resolve the callback argument to a nodeId (Class::method).
     *
     * Supported forms:
     *   [$this, 'methodName']
     *   [ClassName::class, 'methodName']
     *   'ClassName::methodName'
     */
    private function resolveCallback(Node\Expr $expr, VisitorContext $context): ?string
    {
        // Case 1: [$this, 'methodName'] or [ClassName::class, 'methodName']
        if ($expr instanceof Node\Expr\Array_ && count($expr->items) === 2) {
            $classExpr = $expr->items[0]?->value ?? null;
            $methodExpr = $expr->items[1]?->value ?? null;

            if (!$methodExpr instanceof Node\Scalar\String_) {
                return null;
            }

            $methodName = $methodExpr->value;

            // [$this, 'method']
            if ($classExpr instanceof Node\Expr\Variable && $classExpr->name === 'this') {
                $class = $context->currentClass();
                return $class !== null ? $class . '::' . $methodName : null;
            }

            // [ClassName::class, 'method']
            if ($classExpr instanceof Node\Expr\ClassConstFetch
                && $classExpr->class instanceof Node\Name
                && $classExpr->name instanceof Node\Identifier
                && $classExpr->name->toString() === 'class'
            ) {
                $class = $context->resolveFQN($classExpr->class->toString());
                return $class . '::' . $methodName;
            }
        }

        // Case 2: 'ClassName::methodName'
        if ($expr instanceof Node\Scalar\String_ && str_contains($expr->value, '::')) {
            return $expr->value;
        }

        return null;
    }
}
