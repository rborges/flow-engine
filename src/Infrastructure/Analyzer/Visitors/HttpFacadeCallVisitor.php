<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Domain\Flow\Edge;
use PhpParser\Node;

/**
 * Detecta chamadas ao Laravel Http facade (Illuminate\Support\Facades\Http)
 * e emite edges do tipo `http_call` para alimentar o boundary `external-http`.
 *
 * Reconhece:
 *   - Chamada direta: Http::get('/url'), Http::post('/url')
 *   - Chain: Http::timeout(30)->withHeaders([...])->post('/url')
 *   - baseUrl: Http::baseUrl('https://x')->get('/users') -> https://x/users
 *   - PendingRequest em variável: $c = Http::timeout(5); $c->post('/url')
 *   - send(): Http::send('DELETE', '/url')
 *
 * Suporta URL literal, interpolada ("{$id}" -> {*}), concat ($a . $b -> {*}),
 * ou totalmente dinamica (<dynamic>).
 */
final class HttpFacadeCallVisitor implements NodeVisitor
{
    private const HTTP_FACADE_FQN = 'Illuminate\\Support\\Facades\\Http';

    private const TERMINAL_METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'send',
    ];

    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\Assign;
    }

    public function enter(Node $node, VisitorContext $context): void
    {
        if ($context->currentNodeId() === null) {
            return;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->maybeMarkPendingRequest($node, $context);
            return;
        }

        if ($node instanceof Node\Expr\StaticCall) {
            if (! $this->isHttpFacadeRoot($node, $context)) {
                return;
            }
            $this->emitIfTerminal($node, $context);
            return;
        }

        if ($node instanceof Node\Expr\MethodCall) {
            if (! $this->chainRootIsHttpFacade($node->var, $context)) {
                return;
            }
            $this->emitIfTerminal($node, $context);
        }
    }

    public function leave(Node $node, VisitorContext $context): void
    {
        // no-op
    }

    private function maybeMarkPendingRequest(Node\Expr\Assign $assign, VisitorContext $context): void
    {
        if (! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)) {
            return;
        }

        $expr = $assign->expr;

        if ($expr instanceof Node\Expr\StaticCall && $this->isHttpFacadeRoot($expr, $context)) {
            $context->setLocalVariableType($assign->var->name, self::HTTP_FACADE_FQN);
            return;
        }

        if ($expr instanceof Node\Expr\MethodCall && $this->chainRootIsHttpFacade($expr->var, $context)) {
            $context->setLocalVariableType($assign->var->name, self::HTTP_FACADE_FQN);
        }
    }

    private function isHttpFacadeRoot(Node\Expr\StaticCall $node, VisitorContext $context): bool
    {
        if (! $node->class instanceof Node\Name) {
            return false;
        }

        $resolved = $context->resolveClass($node->class->toString());

        return ltrim($resolved, '\\') === self::HTTP_FACADE_FQN;
    }

    private function chainRootIsHttpFacade(Node $expr, VisitorContext $context): bool
    {
        while ($expr instanceof Node\Expr\MethodCall) {
            $expr = $expr->var;
        }

        if ($expr instanceof Node\Expr\StaticCall) {
            return $this->isHttpFacadeRoot($expr, $context);
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $type = $context->getLocalVariableType($expr->name);
            return $type !== null && ltrim($type, '\\') === self::HTTP_FACADE_FQN;
        }

        return false;
    }

    private function emitIfTerminal(Node $node, VisitorContext $context): void
    {
        $methodName = $this->getMethodName($node);
        if ($methodName === null) {
            return;
        }

        $lower = strtolower($methodName);
        if (! in_array($lower, self::TERMINAL_METHODS, true)) {
            return;
        }

        $from = $context->currentNodeId();
        if ($from === null) {
            return;
        }

        $args = $this->getArgs($node);

        if ($lower === 'send') {
            if (count($args) < 2) {
                return;
            }
            $httpMethod = strtoupper($this->extractLiteralOrDynamic($args[0]->value));
            $url = $this->extractUrl($args[1]->value);
        } else {
            if (count($args) < 1) {
                return;
            }
            $httpMethod = strtoupper($lower);
            $url = $this->extractUrl($args[0]->value);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $baseUrl = $this->extractBaseUrlFromChain($node);
            if ($baseUrl !== null && $url !== '<dynamic>' && ! $this->isAbsoluteUrl($url)) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }
        }

        $target = 'http:' . $httpMethod . ':' . $url;

        $context->addEdge(new Edge($from, $target, $methodName, 'http_call'));
    }

    private function extractBaseUrlFromChain(Node\Expr\MethodCall $terminal): ?string
    {
        $expr = $terminal->var;

        while (true) {
            if ($expr instanceof Node\Expr\MethodCall) {
                if ($expr->name instanceof Node\Identifier && $expr->name->toString() === 'baseUrl') {
                    $args = $this->getArgs($expr);
                    if ($args !== [] && $args[0]->value instanceof Node\Scalar\String_) {
                        return $args[0]->value->value;
                    }
                    return null;
                }
                $expr = $expr->var;
                continue;
            }

            if ($expr instanceof Node\Expr\StaticCall) {
                if ($expr->name instanceof Node\Identifier && $expr->name->toString() === 'baseUrl') {
                    $args = $this->getArgs($expr);
                    if ($args !== [] && $args[0]->value instanceof Node\Scalar\String_) {
                        return $args[0]->value->value;
                    }
                }
                return null;
            }

            return null;
        }
    }

    private function isAbsoluteUrl(string $url): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $url);
    }

    private function getMethodName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    /**
     * @return Node\Arg[]
     */
    private function getArgs(Node $node): array
    {
        if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
            return array_values(array_filter(
                $node->args,
                static fn ($arg) => $arg instanceof Node\Arg
            ));
        }

        return [];
    }

    private function extractUrl(Node $expr, bool $isRoot = true): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            $parts = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $parts[] = $part->value;
                } else {
                    $parts[] = '{*}';
                }
            }
            return implode('', $parts);
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->extractUrl($expr->left, false) . $this->extractUrl($expr->right, false);
        }

        return $isRoot ? '<dynamic>' : '{*}';
    }

    private function extractLiteralOrDynamic(Node $expr): string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        return '<dynamic>';
    }
}
