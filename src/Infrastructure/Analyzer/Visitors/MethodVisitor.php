<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use PhpParser\Node;

/**
 * Captura declaracoes de metodos e cria Nodes.
 *
 * Responsabilidades:
 * - Capturar nome do metodo
 * - Extrair assinatura (params + return type)
 * - Criar Node via NodeFactory
 * - Adicionar node ao contexto
 * - Guardar metodo atual (para edges)
 *
 * @internal
 */
final class MethodVisitor implements NodeVisitor
{
    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassMethod;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        /** @var Node\Stmt\ClassMethod $node */

        if (!$context->isInsideClass()) {
            return;
        }

        $methodName = $node->name->toString();
        $metadata = $this->extractSignature($node);

        // Sem namespace (PHP legado) a classe não diz nada sobre onde ela vive, e a
        // "área" do nó acabaria sendo o próprio nome da classe. O diretório é a única
        // estrutura que esse código tem — registre-o. Código com namespace não recebe
        // esta chave, então seu grafo permanece idêntico.
        if ($context->currentNamespace() === null) {
            $metadata['relPath'] = $context->relativeFile();
        }

        // Mark nodes that belong to interface declarations so the orphan detector can suppress them.
        if ($context->currentClassIsInterface()) {
            $metadata['isInterface'] = true;
        }

        if ($context->currentClassIsTrait()) {
            $metadata['isTrait'] = true;
        }

        // Mark static methods; static call FQN resolution is unreliable in the edge graph.
        if ($node->isStatic()) {
            $metadata['isStatic'] = true;
        }

        // Populate property-type map from constructor parameters so that
        // MethodCallVisitor can resolve $this->prop->method() to the correct class.
        //
        // We register ALL typed __construct params (not just promoted) because:
        // - Promoted:  property is created automatically → resolve immediately
        // - Regular:   usually followed by $this->prop = $arg in the body → still useful
        if ($methodName === '__construct') {
            foreach ($node->params as $param) {
                if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }
                // Handle both Name and NullableType (?Foo)
                $typeNode = $param->type;
                if ($typeNode instanceof Node\NullableType) {
                    $typeNode = $typeNode->type;
                }
                if (!$typeNode instanceof Node\Name) {
                    continue;
                }
                $fqn = $context->resolveClass($typeNode->toString());
                $context->setPropertyType($param->var->name, $fqn);
            }
        }

        // Extract HTTP route metadata from method-level PHP 8 attributes (#[Get], #[Route], etc.)
        $routeMeta = $this->extractRouteMetadata($node->attrGroups, $context->classHttpBasePath());
        if ($routeMeta !== []) {
            $metadata = array_merge($metadata, $routeMeta);
        }

        // Merge class-level + method-level PHP 8 attribute names
        $attributes = array_values(array_unique(array_merge(
            $context->getClassAttributes(),
            $this->extractAttributeNames($node->attrGroups)
        )));
        if ($attributes !== []) {
            $metadata['attributes'] = $attributes;
        }

        $endLine = $node->getEndLine();
        if ($endLine > 0) {
            $metadata['endLine'] = $endLine;
        }

        $flowNode = $context->nodeFactory()->create(
            $context->currentClass(),
            $methodName,
            $context->file(),
            $node->getStartLine(),
            'php',
            $metadata !== [] ? $metadata : null
        );

        $context->addNode($flowNode);
        $context->setMethod($methodName);
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        $context->setMethod(null);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSignature(Node\Stmt\ClassMethod $node): array
    {
        $meta = [];
        $params = [];

        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $p = ['name' => '$' . $param->var->name];
            if ($param->type !== null) {
                $p['type'] = $this->typeToString($param->type);
            }
            $params[] = $p;
        }

        if ($params !== []) {
            $meta['params'] = $params;
        }

        if ($node->returnType !== null) {
            $meta['returnType'] = $this->typeToString($node->returnType);
        }

        return $meta;
    }

    /**
     * Extrai nomes simples de atributos PHP 8.
     *
     * @param \PhpParser\Node\AttributeGroup[] $attrGroups
     * @return string[]
     */
    private function extractAttributeNames(array $attrGroups): array
    {
        $names = [];
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $names[] = $attr->name->getLast();
            }
        }
        return $names;
    }

    /**
     * Extract HTTP route metadata from method-level PHP 8 route attributes.
     *
     * Recognises:
     * - #[Route('/path')] or #[Route('/path', methods: ['GET', 'POST'])]
     * - #[Get('/path')], #[Post('/path')], #[Put('/path')], #[Patch('/path')],
     *   #[Delete('/path')], #[Head('/path')], #[Options('/path')]
     *
     * Returns an array with 'http_path' and 'http_method' keys, or [] if no route
     * attribute is found.
     *
     * @param \PhpParser\Node\AttributeGroup[] $attrGroups
     * @return array<string, string>
     */
    private function extractRouteMetadata(array $attrGroups, ?string $basePath): array
    {
        $verbAttributes = ['Get', 'Post', 'Put', 'Patch', 'Delete', 'Head', 'Options'];

        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attrName = $attr->name->getLast();

                // #[Get('/path')], #[Post('/path')], etc.
                if (in_array($attrName, $verbAttributes, true)) {
                    $path = $this->firstStringArg($attr->args);
                    if ($path !== null) {
                        return [
                            'http_path'   => $this->joinPaths($basePath, $path),
                            'http_method' => strtoupper($attrName),
                        ];
                    }
                }

                // #[Route('/path')] or #[Route('/path', methods: ['GET', 'POST'])]
                if ($attrName === 'Route') {
                    $path    = null;
                    $methods = ['GET'];

                    foreach ($attr->args as $arg) {
                        $argName = $arg->name?->toString();

                        if ($path === null
                            && ($argName === null || $argName === 'path' || $argName === 'value')
                            && $arg->value instanceof Node\Scalar\String_
                        ) {
                            $path = $arg->value->value;
                        } elseif ($argName === 'methods'
                            && $arg->value instanceof Node\Expr\Array_
                        ) {
                            $methods = [];
                            foreach ($arg->value->items as $item) {
                                if ($item instanceof Node\Expr\ArrayItem
                                    && $item->value instanceof Node\Scalar\String_
                                ) {
                                    $methods[] = strtoupper($item->value->value);
                                }
                            }
                        }
                    }

                    if ($path !== null) {
                        return [
                            'http_path'   => $this->joinPaths($basePath, $path),
                            'http_method' => implode(',', $methods ?: ['GET']),
                        ];
                    }
                }
            }
        }

        return [];
    }

    /**
     * Returns the string value of the first string argument, or null.
     *
     * @param \PhpParser\Node\Arg[] $args
     */
    private function firstStringArg(array $args): ?string
    {
        foreach ($args as $arg) {
            if ($arg->value instanceof Node\Scalar\String_) {
                return $arg->value->value;
            }
        }
        return null;
    }

    /**
     * Join a class-level base path with a method-level path, normalising slashes.
     */
    private function joinPaths(?string $basePath, string $methodPath): string
    {
        if ($basePath === null || $basePath === '') {
            return $methodPath;
        }
        return rtrim($basePath, '/') . '/' . ltrim($methodPath, '/');
    }

    private function typeToString(Node $type): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        if ($type instanceof Node\Name) {
            return $type->toString();
        }

        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(
                fn(Node $t) => $this->typeToString($t),
                $type->types
            ));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(
                fn(Node $t) => $this->typeToString($t),
                $type->types
            ));
        }

        return (string) $type;
    }
}
