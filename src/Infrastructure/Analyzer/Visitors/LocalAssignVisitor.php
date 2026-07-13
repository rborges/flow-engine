<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Tracks local variable assignments of the form $var = new Foo() within method bodies.
 *
 * Populates VisitorContext::localVariableTypes so that MethodCallVisitor can resolve
 * $var->method() calls to the correct class when the variable type is known from
 * a constructor call (e.g. $serializer = new FlowSnapshotSerializer()).
 *
 * @internal
 */
final class LocalAssignVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\Assign;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\Assign $node */

        if ($context->currentNodeId() === null) {
            return;
        }

        // Extract the New_ expression from the RHS.
        // Handles both:
        //   $var = new Foo()
        //   $var = $something ?? new Foo()
        $newExpr = $this->extractNewExpr($node->expr);
        if ($newExpr === null) {
            return;
        }

        $className = $newExpr->class->toString();
        if ($className === 'self' || $className === 'static') {
            $fqn = $context->currentClass() ?? $className;
        } else {
            $fqn = $context->resolveClass($className);
        }

        // ── Local variable: $var = new Foo() ─────────────────────────────────
        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $context->setLocalVariableType($node->var->name, $fqn);
        }

        // ── Property assignment: $this->prop = ... ?? new Foo() ─────────────
        if (
            $node->var instanceof Node\Expr\PropertyFetch &&
            $node->var->var instanceof Node\Expr\Variable &&
            $node->var->var->name === 'this' &&
            $node->var->name instanceof Node\Identifier
        ) {
            $context->setPropertyType($node->var->name->toString(), $fqn);
        }
    }

    /**
     * Extracts the New_ expression from an assignment RHS, supporting:
     * - new Foo()
     * - $something ?? new Foo()
     */
    private function extractNewExpr(Node\Expr $expr): ?Node\Expr\New_
    {
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr;
        }

        if (
            $expr instanceof Node\Expr\NullCoalesce &&
            $expr->right instanceof Node\Expr\New_ &&
            $expr->right->class instanceof Node\Name
        ) {
            return $expr->right;
        }

        return null;
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        // Nothing to do
    }
}
