<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Infrastructure\Analyzer\ProjectRelativePath;
use PhpParser\Node;

/**
 * Modela include/require como aresta do grafo.
 *
 * Em PHP procedural é assim que um script depende de outro — sem isso, o grafo
 * não tem como saber que login.php depende de classes/AuthModel.php.
 *
 * Só resolve alvos estáticos (string literal, concatenação com __DIR__ ou
 * dirname(__FILE__)). Includes dinâmicos (include $arquivo) não são resolvíveis
 * estaticamente e são ignorados — inventar um alvo seria pior que omitir.
 *
 * O caminho é resolvido como o PHP resolve na prática: relativo ao diretório do
 * arquivo que inclui e, em seguida, à raiz do projeto. Alvos que não existem em
 * disco são descartados aqui; alvos fora do escopo do scan são descartados pelo
 * FlowBuilder, que é quem conhece o grafo completo.
 *
 * @internal
 */
final class IncludeVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\Include_;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Expr\Include_ $node */

        $fromId = $context->currentNodeId();

        if ($fromId === null) {
            return;
        }

        $raw = $this->literalPath($node->expr, $context->file());

        if ($raw === null) {
            return;
        }

        $target = $this->resolve($raw, $context->file(), $context->projectRoot());

        if ($target === null) {
            return;
        }

        $context->addEdge(new Edge(
            $fromId,
            'file:' . ProjectRelativePath::for($target, $context->projectRoot()),
            $this->keyword($node->type),
            'include'
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
     * Avalia a expressão do include quando ela é estaticamente conhecida.
     */
    private function literalPath(Node\Expr $expr, string $currentFile): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($currentFile);
        }

        if ($expr instanceof Node\Scalar\MagicConst\File) {
            return $currentFile;
        }

        // dirname(__FILE__) — a forma pré-__DIR__, onipresente em código legado.
        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && $expr->name->toLowerString() === 'dirname'
            && count($expr->args) === 1
            && $expr->args[0] instanceof Node\Arg
        ) {
            $inner = $this->literalPath($expr->args[0]->value, $currentFile);

            return $inner !== null ? dirname($inner) : null;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->literalPath($expr->left, $currentFile);
            $right = $this->literalPath($expr->right, $currentFile);

            if ($left === null || $right === null) {
                return null;
            }

            return $left . $right;
        }

        return null;
    }

    /**
     * Resolve o caminho contra o diretório do arquivo atual e, depois, a raiz.
     */
    private function resolve(string $path, string $currentFile, ?string $projectRoot): ?string
    {
        $candidates = [$path, dirname($currentFile) . DIRECTORY_SEPARATOR . $path];

        if ($projectRoot !== null) {
            $candidates[] = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    private function keyword(int $type): string
    {
        return match ($type) {
            Node\Expr\Include_::TYPE_REQUIRE      => 'require',
            Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
            Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            default                               => 'include',
        };
    }
}
