<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Application\DTO\SymbolDTO;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\NodeFactory;

/**
 * TypeScriptParser (prototype)
 *
 * Minimal, best-effort parser for TypeScript/JavaScript files:
 * - Collects classes, methods, and exported functions
 * - Detects NestJS-style decorators (@Controller, @Get, @Post, etc.)
 * - Detects fetch/axios HTTP calls as cross-language edges
 *
 * Language tag: 'typescript' for .ts/.tsx, 'javascript' for .js/.jsx
 */
final class TypeScriptParser implements FileParser
{
    private const NAMESPACE_PATTERN = '/^(?:(?:(?:export|declare)\s+)*(?:namespace|module)\s+(?<name>[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*|["\'][^"\']+["\'])|declare\s+(?<global>global))\s*(?<open>\{)?\s*(?<trivia>(?:\/\/.*|\/\*.*)?)$/';

    private bool $jsxSyntax = false;

    public function __construct(
        private readonly NodeFactory $nodeFactory,
        private readonly string $projectRoot,
        private readonly string $language = 'typescript'
    ) {
    }

    /**
     * @return array{nodes: Node[], edges: Edge[]}
     */
    public function parse(string $file): array
    {
        $this->jsxSyntax = in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jsx', 'tsx'], true);
        $content = @file_get_contents($file);
        if ($content === false) {
            return ['nodes' => [], 'edges' => [], 'symbols' => []];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (!is_array($lines)) {
            return ['nodes' => [], 'edges' => [], 'symbols' => []];
        }

        $module = $this->moduleNameFromPath($file);

        $nodes = [];
        $edges = [];

        /** @var array<string, string> functionName => nodeId */
        $topLevel = [];

        $currentClass     = null;
        $classDepth       = null;
        $depth            = 0;
        $classDeclarationDepths = [0];
        $suppressedClassDepth = null;
        /** @var array<int, array{name: string, depth: int}> */
        $namespaceStack = [];
        /** @var string[]|null */
        $pendingNamespaceNames = null;
        $pendingNamespaceBlockComment = false;
        $lexicalState = $this->newLexicalState();

        /** @var array<array{type: string, value: string}> */
        $pendingDecorators = [];

        /** @var array<array{type: string, value: string}> */
        $classDecorators = [];

        /** @var array<int, array{startDepth: int, endLine: int|null, bodyOpened: bool, expressionBody: bool, expressionNesting: int, arrowLine: int|null}> */
        $nodeTracking = [];

        $arrowDeclarationStarts = $this->arrowDeclarationStarts($lines);

        // Pre-pass: collect local import statements for edge emission after node collection.
        $localImports = $this->parseImports($lines, $file, $arrowDeclarationStarts);

        // Pre-pass: collect all symbols (imports including npm, exports, top-level identifiers).
        $symbols = $this->collectSymbols($lines, $file, $arrowDeclarationStarts);

        // Pass 1: collect nodes.
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            $trim   = trim($line);

            if ($pendingNamespaceNames === null && $pendingNamespaceBlockComment) {
                $namespaceToken = $this->stripNamespaceTrivia($trim, $pendingNamespaceBlockComment);
                if ($namespaceToken === null) {
                    continue;
                }
                $line = $namespaceToken;
                $trim = trim($line);
            }

            $openedPendingNamespaceNames = null;
            if ($pendingNamespaceNames !== null) {
                $namespaceToken = $this->stripNamespaceTrivia($trim, $pendingNamespaceBlockComment);
                if ($namespaceToken === null) {
                    continue;
                }
                if ($this->isNamespaceBlockOpenToken($namespaceToken, $pendingNamespaceBlockComment)) {
                    $openedPendingNamespaceNames = $pendingNamespaceNames;
                    $pendingNamespaceNames = null;
                    $line = '{';
                    $trim = '{';
                } else {
                    $pendingNamespaceNames = null;
                    $pendingNamespaceBlockComment = false;
                }
            }

            if (preg_match(self::NAMESPACE_PATTERN, $trim, $namespaceLineMatch)
                && ($namespaceLineMatch['open'] ?? '') === '{'
                && ($namespaceLineMatch['trivia'] ?? '') !== ''
            ) {
                $namespaceLineComment = $namespaceLineMatch['trivia'];
                $namespaceSuffix = $this->stripNamespaceTrivia(
                    $namespaceLineComment,
                    $pendingNamespaceBlockComment
                );
                $line = substr($line, 0, (int) strpos($line, $namespaceLineComment))
                    . ($namespaceSuffix ?? '');
                $trim = trim($line);
            }

            // Track brace depth (capture pre-update value for endLine tracking)
            $depthBefore = $depth;
            $structuralLine = $this->structuralCode(
                $line,
                $lexicalState,
                isset($arrowDeclarationStarts[$lineNo]),
            );
            $structuralLine = $this->withTemplateMarker($structuralLine, $lexicalState);
            $templateOpen = $lexicalState['templateStack'] !== [];
            $depth += substr_count($structuralLine, '{') - substr_count($structuralLine, '}');
            $classDeclarationDepths = array_values(array_filter(
                $classDeclarationDepths,
                static fn(int $allowedDepth): bool => $allowedDepth <= $depth
            ));
            if ($suppressedClassDepth !== null && $depth <= $suppressedClassDepth) {
                $suppressedClassDepth = null;
            }
            while ($namespaceStack !== [] && $depth < $namespaceStack[array_key_last($namespaceStack)]['depth']) {
                array_pop($namespaceStack);
            }

            if ($openedPendingNamespaceNames !== null) {
                $classDeclarationDepths[] = $depth;
                foreach ($openedPendingNamespaceNames as $namespaceName) {
                    $namespaceStack[] = ['name' => $namespaceName, 'depth' => $depth];
                }
                continue;
            }

            if (trim($structuralLine) === '') {
                continue;
            }

            if ($suppressedClassDepth !== null) {
                continue;
            }

            // Exit class scope when depth falls back to class entry depth
            if ($currentClass !== null && $classDepth !== null && $depth <= $classDepth) {
                $currentClass  = null;
                $classDepth    = null;
                $classDecorators = [];
            }

            // Decorator: @Controller('/path'), @Get('/sub'), @Injectable(), etc.
            if (preg_match('/^\s*@([A-Za-z]+)\s*\(?\s*[\'"]?([^\'")\s]*)[\'"]?/', $line, $dm)) {
                $pendingDecorators[] = ['type' => $dm[1], 'value' => $dm[2]];
                continue;
            }

            if (preg_match(self::NAMESPACE_PATTERN, $trim, $namespaceMatch)) {
                if (in_array($depthBefore, $classDeclarationDepths, true)) {
                    $namespaceNames = ($namespaceMatch['global'] ?? '') === 'global'
                        ? []
                        : $this->namespaceNames($namespaceMatch['name']);
                    if (($namespaceMatch['open'] ?? '') === '{') {
                        $classDeclarationDepths[] = $depth;
                        foreach ($namespaceNames as $namespaceName) {
                            $namespaceStack[] = ['name' => $namespaceName, 'depth' => $depth];
                        }
                    } else {
                        $pendingNamespaceNames = $namespaceNames;
                    }
                }
                continue;
            }

            // Class declaration
            if (preg_match('/^(?:export\s+)?(?:abstract\s+)?class\s+([A-Za-z_$][\w$]*)/', $trim, $m)) {
                if (!in_array($depthBefore, $classDeclarationDepths, true)) {
                    $suppressedClassDepth = $depthBefore;
                    $pendingDecorators = [];
                    continue;
                }
                $currentClass = $m[1];
                $classDepth       = $depthBefore;
                $classDecorators  = $pendingDecorators;
                $pendingDecorators = [];
                continue;
            }

            // Export default function: export default function foo() or anonymous
            if (preg_match('/^export\s+default\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $fn       = $m[1];
                $functionModule = $this->namespacedModule($module, $namespaceStack);
                $metadata = $this->withInferredFunctionEntrypointMetadata(
                    $this->buildFunctionMetadata($pendingDecorators, []),
                    $file,
                    $functionModule,
                    $fn
                );
                $node     = $this->nodeFactory->create($functionModule, $fn, $file, $lineNo, $this->language, $metadata ?: null);
                $nodes[]  = $node;
                $nodeTracking[count($nodes) - 1] = $this->initialTracking($trim, $depthBefore, $lineNo, $lexicalState['lastOpenedBraceIsBody']);
                $topLevel[$this->namespacedSymbolKey($namespaceStack, $fn)] = $node->id();
                $pendingDecorators = [];
                continue;
            }

            // Export function: export function foo() or export async function foo()
            if (preg_match('/^export\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $fn       = $m[1];
                $functionModule = $this->namespacedModule($module, $namespaceStack);
                $metadata = $this->withInferredFunctionEntrypointMetadata(
                    $this->buildFunctionMetadata($pendingDecorators, []),
                    $file,
                    $functionModule,
                    $fn
                );
                $node     = $this->nodeFactory->create($functionModule, $fn, $file, $lineNo, $this->language, $metadata ?: null);
                $nodes[]  = $node;
                $nodeTracking[count($nodes) - 1] = $this->initialTracking($trim, $depthBefore, $lineNo, $lexicalState['lastOpenedBraceIsBody']);
                $topLevel[$this->namespacedSymbolKey($namespaceStack, $fn)] = $node->id();
                $pendingDecorators = [];
                continue;
            }

            // Export arrow const: export const foo = (async)? (
            if (preg_match('/^export\s+const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $trim, $m)
                && isset($arrowDeclarationStarts[$lineNo])
            ) {
                $fn       = $m[1];
                $functionModule = $this->namespacedModule($module, $namespaceStack);
                $metadata = $this->withInferredFunctionEntrypointMetadata(
                    $this->buildFunctionMetadata($pendingDecorators, []),
                    $file,
                    $functionModule,
                    $fn
                );
                $node     = $this->nodeFactory->create($functionModule, $fn, $file, $lineNo, $this->language, $metadata ?: null);
                $nodes[]  = $node;
                $nodeTracking[count($nodes) - 1] = $this->initialTracking(
                    $trim,
                    $depthBefore,
                    $lineNo,
                    $lexicalState['lastOpenedBraceIsBody'],
                    $arrowDeclarationStarts[$lineNo],
                    $this->nextStructuralLine($lines, $idx + 1, $lexicalState),
                    $templateOpen,
                );
                $topLevel[$this->namespacedSymbolKey($namespaceStack, $fn)] = $node->id();
                $pendingDecorators = [];
                continue;
            }

            // Top-level non-exported function. Many TS/JS CLIs and servers expose main/start/bootstrap
            // functions without exporting them.
            if ($depthBefore === 0 && preg_match('/^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $fn = $m[1];
                $metadata = $this->withInferredFunctionEntrypointMetadata([], $file, $module, $fn);
                $node = $this->nodeFactory->create($module, $fn, $file, $lineNo, $this->language, $metadata ?: null);
                $nodes[] = $node;
                $nodeTracking[count($nodes) - 1] = $this->initialTracking($trim, $depthBefore, $lineNo, $lexicalState['lastOpenedBraceIsBody']);
                $topLevel[$fn] = $node->id();
                $pendingDecorators = [];
                continue;
            }

            // Top-level non-exported arrow const.
            if ($depthBefore === 0
                && preg_match('/^const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $trim, $m)
                && isset($arrowDeclarationStarts[$lineNo])
            ) {
                $fn = $m[1];
                $metadata = $this->withInferredFunctionEntrypointMetadata([], $file, $module, $fn);
                $node = $this->nodeFactory->create($module, $fn, $file, $lineNo, $this->language, $metadata ?: null);
                $nodes[] = $node;
                $nodeTracking[count($nodes) - 1] = $this->initialTracking(
                    $trim,
                    $depthBefore,
                    $lineNo,
                    $lexicalState['lastOpenedBraceIsBody'],
                    $arrowDeclarationStarts[$lineNo],
                    $this->nextStructuralLine($lines, $idx + 1, $lexicalState),
                    $templateOpen,
                );
                $topLevel[$fn] = $node->id();
                $pendingDecorators = [];
                continue;
            }

            // Class method or arrow property (must be inside a class)
            if ($currentClass !== null
                && $classDepth !== null
                && $depthBefore === $classDepth + 1
                && $suppressedClassDepth === null
            ) {
                $className = $this->namespacedModule($module, $namespaceStack) . '.' . $currentClass;

                // Class method: [modifiers] methodName( or [modifiers] methodName<
                if (preg_match(
                    '/^\s+(?:async\s+)?(?:(?:public|private|protected|readonly|static|abstract|override)\s+)*([A-Za-z_$][\w$]*)\s*[(<]/',
                    $line,
                    $m
                )) {
                    $name = $m[1];
                    // Skip keywords that are not method names
                    if (!in_array($name, ['if', 'for', 'while', 'switch', 'return', 'const', 'let', 'var', 'new', 'throw', 'catch', 'try'], true)
                        && $this->looksLikeMethodDeclaration($trim)
                    ) {
                        $metadata = $this->buildMethodMetadata($pendingDecorators, $classDecorators);
                        $node     = $this->nodeFactory->create($className, $name, $file, $lineNo, $this->language, $metadata ?: null);
                        $nodes[]  = $node;
                        $nodeTracking[count($nodes) - 1] = $this->initialTracking($trim, $depthBefore, $lineNo, $lexicalState['lastOpenedBraceIsBody']);
                        $pendingDecorators = [];
                        continue;
                    }
                }

                // Arrow property: [modifiers] name = (async)? (
                if (preg_match('/^\s+(?:(?:public|private|protected|readonly|static|abstract|override|declare|accessor|async)\s+)*([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $line, $m)) {
                    $name = $m[1];
                    if (!in_array($name, ['if', 'for', 'while', 'switch', 'return', 'const', 'let', 'var'], true)
                        && isset($arrowDeclarationStarts[$lineNo])
                    ) {
                        $metadata = $this->buildMethodMetadata($pendingDecorators, $classDecorators);
                        $node     = $this->nodeFactory->create($className, $name, $file, $lineNo, $this->language, $metadata ?: null);
                        $nodes[]  = $node;
                        $nodeTracking[count($nodes) - 1] = $this->initialTracking(
                            $trim,
                            $depthBefore,
                            $lineNo,
                            $lexicalState['lastOpenedBraceIsBody'],
                            $arrowDeclarationStarts[$lineNo],
                            $this->nextStructuralLine($lines, $idx + 1, $lexicalState),
                            $templateOpen,
                        );
                        $pendingDecorators = [];
                        continue;
                    }
                }
            }

            // Reset pending decorators if line is not a decorator and not a blank/comment
            if ($pendingDecorators !== [] && $trim !== '' && !str_starts_with($trim, '//') && !str_starts_with($trim, '*')) {
                $pendingDecorators = [];
            }

            // Close any tracked nodes whose body returned to its startDepth on this line.
            // Two-state tracking: bodyOpened=false means we're still in a multi-line signature
            // and must wait for `{` before considering depth returns as a real close.
            foreach ($nodeTracking as $nodeIdx => $tracking) {
                if ($tracking['endLine'] !== null) {
                    continue;
                }
                if (!$tracking['bodyOpened']) {
                    if ($lexicalState['openedBodyCount'] > 0) {
                        $nodeTracking[$nodeIdx]['bodyOpened'] = true;
                        if ($depth <= $tracking['startDepth']) {
                            $nodeTracking[$nodeIdx]['endLine'] = $lineNo;
                        }
                    } elseif ($tracking['arrowLine'] === $lineNo && str_contains($structuralLine, '=>')) {
                        $nodeTracking[$nodeIdx]['bodyOpened'] = true;
                        $nodeTracking[$nodeIdx]['expressionBody'] = true;
                        $expression = substr($structuralLine, (int) strrpos($structuralLine, '=>') + 2);
                        $nodeTracking[$nodeIdx]['expressionNesting'] = $this->expressionNestingDelta($expression);
                        if (!$templateOpen && $this->expressionEndsOnLine(
                            $expression,
                            $nodeTracking[$nodeIdx]['expressionNesting'],
                            $this->nextStructuralLine($lines, $idx + 1, $lexicalState),
                        )) {
                            $nodeTracking[$nodeIdx]['endLine'] = $lineNo;
                        }
                    }
                    continue;
                }
                if ($tracking['expressionBody']) {
                    $nodeTracking[$nodeIdx]['expressionNesting'] += $this->expressionNestingDelta($structuralLine);
                    if (!$templateOpen && $this->expressionEndsOnLine(
                        $structuralLine,
                        $nodeTracking[$nodeIdx]['expressionNesting'],
                        $this->nextStructuralLine($lines, $idx + 1, $lexicalState),
                    )) {
                        $nodeTracking[$nodeIdx]['endLine'] = $lineNo;
                    }
                    continue;
                }
                if ($depth <= $tracking['startDepth']) {
                    $nodeTracking[$nodeIdx]['endLine'] = $lineNo;
                }
            }
        }

        // Reconstitute nodes with endLine in metadata for those that closed during Pass 1.
        foreach ($nodeTracking as $idx => $tracking) {
            if ($tracking['endLine'] === null) {
                continue;
            }
            $old = $nodes[$idx];
            $newMetadata = ($old->metadata() ?? []) + ['endLine' => $tracking['endLine']];
            $nodes[$idx] = $this->nodeFactory->create(
                $old->class(),
                $old->method(),
                $old->file(),
                $old->line(),
                $old->language(),
                $newMetadata
            );
        }

        $nodeEndLines = [];
        foreach ($nodes as $node) {
            $endLine = $node->metadata()['endLine'] ?? null;
            if (is_int($endLine)) {
                $nodeEndLines[$node->id()] = $endLine;
            }
        }

        // Pass 2: collect edges.
        $currentNodeId = null;
        $currentClass  = null;
        $classDepth    = null;
        $depth         = 0;
        $classDeclarationDepths = [0];
        $suppressedClassDepth = null;
        $suppressedParentNodeId = null;
        /** @var array<int, array{name: string, depth: int}> */
        $namespaceStack = [];
        /** @var string[]|null */
        $pendingNamespaceNames = null;
        $pendingNamespaceBlockComment = false;
        $lexicalState = $this->newLexicalState();
        /** @var array<int, array{type: string, closing?: bool, braceDepth?: int, lastNonSpace?: string, expressionDepth?: int, attributeQuote?: string|null}> $jsxEdgeContexts */
        $jsxEdgeContexts = [['type' => 'code']];
        $jsxEdgeCodeHistory = '';

        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if ($currentNodeId !== null
                && isset($nodeEndLines[$currentNodeId])
                && $lineNo > $nodeEndLines[$currentNodeId]
            ) {
                $currentNodeId = null;
            }
            $trim = trim($line);

            if ($pendingNamespaceNames === null && $pendingNamespaceBlockComment) {
                $namespaceToken = $this->stripNamespaceTrivia($trim, $pendingNamespaceBlockComment);
                if ($namespaceToken === null) {
                    continue;
                }
                $line = $namespaceToken;
                $trim = trim($line);
            }

            $openedPendingNamespaceNames = null;
            if ($pendingNamespaceNames !== null) {
                $namespaceToken = $this->stripNamespaceTrivia($trim, $pendingNamespaceBlockComment);
                if ($namespaceToken === null) {
                    continue;
                }
                if ($this->isNamespaceBlockOpenToken($namespaceToken, $pendingNamespaceBlockComment)) {
                    $openedPendingNamespaceNames = $pendingNamespaceNames;
                    $pendingNamespaceNames = null;
                    $line = '{';
                    $trim = '{';
                } else {
                    $pendingNamespaceNames = null;
                    $pendingNamespaceBlockComment = false;
                }
            }

            if (preg_match(self::NAMESPACE_PATTERN, $trim, $namespaceLineMatch)
                && ($namespaceLineMatch['open'] ?? '') === '{'
                && ($namespaceLineMatch['trivia'] ?? '') !== ''
            ) {
                $namespaceLineComment = $namespaceLineMatch['trivia'];
                $namespaceSuffix = $this->stripNamespaceTrivia(
                    $namespaceLineComment,
                    $pendingNamespaceBlockComment
                );
                $line = substr($line, 0, (int) strpos($line, $namespaceLineComment))
                    . ($namespaceSuffix ?? '');
                $trim = trim($line);
            }

            $depthBefore = $depth;
            $structuralMask = $this->structuralCode(
                $line,
                $lexicalState,
                isset($arrowDeclarationStarts[$lineNo]),
            );
            $structuralLine = $this->withTemplateMarker($structuralMask, $lexicalState);
            $edgeMask = $this->maskJsxTextForEdges(
                $structuralMask,
                $jsxEdgeContexts,
                $jsxEdgeCodeHistory,
                $lexicalState['genericOffsets'],
            );
            $depth += substr_count($structuralLine, '{') - substr_count($structuralLine, '}');
            $classDeclarationDepths = array_values(array_filter(
                $classDeclarationDepths,
                static fn(int $allowedDepth): bool => $allowedDepth <= $depth
            ));
            if ($suppressedClassDepth !== null && $depth <= $suppressedClassDepth) {
                $suppressedClassDepth = null;
                $currentNodeId = $suppressedParentNodeId;
                $suppressedParentNodeId = null;
            }
            while ($namespaceStack !== [] && $depth < $namespaceStack[array_key_last($namespaceStack)]['depth']) {
                array_pop($namespaceStack);
            }

            if ($openedPendingNamespaceNames !== null) {
                $classDeclarationDepths[] = $depth;
                foreach ($openedPendingNamespaceNames as $namespaceName) {
                    $namespaceStack[] = ['name' => $namespaceName, 'depth' => $depth];
                }
                continue;
            }

            if (trim($structuralLine) === '') {
                continue;
            }

            if ($suppressedClassDepth !== null) {
                continue;
            }

            if ($currentClass !== null && $classDepth !== null && $depth <= $classDepth) {
                $currentClass = null;
                $classDepth   = null;
            }

            if (preg_match(self::NAMESPACE_PATTERN, $trim, $namespaceMatch)) {
                if (in_array($depthBefore, $classDeclarationDepths, true)) {
                    $namespaceNames = ($namespaceMatch['global'] ?? '') === 'global'
                        ? []
                        : $this->namespaceNames($namespaceMatch['name']);
                    if (($namespaceMatch['open'] ?? '') === '{') {
                        $classDeclarationDepths[] = $depth;
                        foreach ($namespaceNames as $namespaceName) {
                            $namespaceStack[] = ['name' => $namespaceName, 'depth' => $depth];
                        }
                    } else {
                        $pendingNamespaceNames = $namespaceNames;
                    }
                }
                continue;
            }

            // Class declaration
            if (preg_match('/^(?:export\s+)?(?:abstract\s+)?class\s+([A-Za-z_$][\w$]*)/', $trim, $m)) {
                if (!in_array($depthBefore, $classDeclarationDepths, true)) {
                    $suppressedClassDepth = $depthBefore;
                    $suppressedParentNodeId = $currentNodeId;
                    $currentNodeId = null;
                    continue;
                }
                $currentClass = $m[1];
                $classDepth   = $depthBefore;
                $currentNodeId = null;
                continue;
            }

            // Export default function
            if (preg_match('/^export\s+default\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $currentNodeId = $topLevel[$this->namespacedSymbolKey($namespaceStack, $m[1])] ?? null;
                continue;
            }

            // Export function
            if (preg_match('/^export\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $currentNodeId = $topLevel[$this->namespacedSymbolKey($namespaceStack, $m[1])] ?? null;
                continue;
            }

            // Export arrow const
            if (preg_match('/^export\s+const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $trim, $m)
                && isset($arrowDeclarationStarts[$lineNo])
            ) {
                $currentNodeId = $topLevel[$this->namespacedSymbolKey($namespaceStack, $m[1])] ?? null;
            }

            // Top-level non-exported function
            if (preg_match('/^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                if (isset($topLevel[$m[1]])) {
                    $currentNodeId = $topLevel[$m[1]];
                    continue;
                }
            }

            // Top-level non-exported arrow const
            if (preg_match('/^const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $trim, $m)
                && isset($arrowDeclarationStarts[$lineNo])
            ) {
                if (isset($topLevel[$m[1]])) {
                    $currentNodeId = $topLevel[$m[1]];
                }
            }

            // Class method start
            if ($currentClass !== null
                && $classDepth !== null
                && $depthBefore === $classDepth + 1
                && $suppressedClassDepth === null
            ) {
                if (preg_match(
                    '/^\s+(?:async\s+)?(?:(?:public|private|protected|readonly|static|abstract|override)\s+)*([A-Za-z_$][\w$]*)\s*[(<]/',
                    $line,
                    $m
                )) {
                    $name = $m[1];
                    if (!in_array($name, ['if', 'for', 'while', 'switch', 'return', 'const', 'let', 'var', 'new', 'throw', 'catch', 'try'], true)
                        && $this->looksLikeMethodDeclaration($trim)
                    ) {
                        $className     = $this->namespacedModule($module, $namespaceStack) . '.' . $currentClass;
                        $tmp           = $this->nodeFactory->create($className, $name, $file, ($idx + 1), $this->language);
                        $currentNodeId = $tmp->id();
                        continue;
                    }
                }

                if (preg_match('/^\s+(?:(?:public|private|protected|readonly|static|abstract|override|declare|accessor|async)\s+)*([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?[(<]/', $line, $m)
                    && isset($arrowDeclarationStarts[$lineNo])
                ) {
                    $className = $this->namespacedModule($module, $namespaceStack) . '.' . $currentClass;
                    $tmp = $this->nodeFactory->create($className, $m[1], $file, ($idx + 1), $this->language);
                    $currentNodeId = $tmp->id();
                }
            }

            if ($currentNodeId === null) {
                continue;
            }

            // fetch('/url') → http:GET:{url}
            if (preg_match_all('/\bfetch\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $httpMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($httpMatches as $httpMatch) {
                    $offset = $httpMatch[0][1];
                    if (substr($edgeMask, $offset, 5) !== 'fetch') {
                        continue;
                    }
                    $edges[] = new Edge($currentNodeId, 'http:GET:' . $httpMatch[1][0], 'fetch', 'http_call');
                }
            }

            // axios.get/post/put/delete/patch('/url')
            if (preg_match_all('/\baxios\.(get|post|put|delete|patch)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $httpMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($httpMatches as $httpMatch) {
                    $offset = $httpMatch[0][1];
                    if (substr($edgeMask, $offset, 6) !== 'axios.') {
                        continue;
                    }
                    $method = strtoupper($httpMatch[1][0]);
                    $edges[] = new Edge(
                        $currentNodeId,
                        'http:' . $method . ':' . $httpMatch[2][0],
                        'axios.' . $httpMatch[1][0],
                        'http_call',
                    );
                }
            }

            // Intra-file call to known top-level functions
            if (preg_match_all('/\b([A-Za-z_$][\w$]*)\s*\(/', $edgeMask, $allM)) {
                foreach ($allM[1] as $called) {
                    $targetNodeId = $this->resolveTopLevelCall($topLevel, $namespaceStack, $called);
                    if ($targetNodeId !== null && $targetNodeId !== $currentNodeId) {
                        $edges[] = new Edge($currentNodeId, $targetNodeId, $called, 'ts_call');
                    }
                }
            }

            // A call may continue on the next structural line: `invoke\n(args)`.
            if (preg_match('/\b([A-Za-z_$][\w$]*)\s*$/', $edgeMask, $continuedCall)
                && preg_match('/^(?:\(|\?\.\s*\()/', $this->nextStructuralLine($lines, $idx + 1, $lexicalState) ?? '')
            ) {
                $targetNodeId = $this->resolveTopLevelCall($topLevel, $namespaceStack, $continuedCall[1]);
                if ($targetNodeId !== null && $targetNodeId !== $currentNodeId) {
                    $edges[] = new Edge($currentNodeId, $targetNodeId, $continuedCall[1], 'ts_call');
                }
            }
        }

        // Post-pass: emit virtual import_call edges from the first node in this file.
        // CrossLanguageEdgeDetector resolves ts_import:{module}::{symbol} targets to real node IDs.
        if ($localImports !== [] && $nodes !== []) {
            $fromNodeId = $nodes[0]->id();
            foreach ($localImports as $import) {
                foreach ($import['symbols'] as $symbol) {
                    $edges[] = new Edge(
                        $fromNodeId,
                        'ts_import:' . $import['resolvedModule'] . '::' . $symbol,
                        $symbol,
                        'import_call'
                    );
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'symbols' => $symbols];
    }

    /**
     * Pre-pass: collect local import statements from the file.
     * Skips type-only imports, side-effect imports, and npm package specifiers.
     *
     * @param string[] $lines
     * @return array<int, array{symbols: string[], resolvedModule: string}>
     */
    private function parseImports(array $lines, string $file, array $arrowDeclarationStarts): array
    {
        $imports  = [];
        $buffer   = '';
        $buffering = false;
        $lexicalState = $this->newLexicalState();

        foreach ($lines as $idx => $line) {
            $trim = trim($line);
            if (trim($this->structuralCode(
                $line,
                $lexicalState,
                isset($arrowDeclarationStarts[$idx + 1]),
            )) === '') {
                continue;
            }

            // Assemble multi-line import: import {\n  X,\n  Y,\n} from '...'
            if ($buffering) {
                $buffer .= ' ' . $trim;
                if (str_contains($trim, '}')) {
                    $buffering = false;
                    $this->processImportLine($buffer, $file, $imports);
                    $buffer = '';
                }
                continue;
            }

            if (!str_starts_with($trim, 'import ')) {
                continue;
            }

            // Skip type-only imports: import type { ... }
            if (preg_match('/^import\s+type\b/', $trim)) {
                continue;
            }

            if (str_contains($trim, 'from')) {
                $this->processImportLine($trim, $file, $imports);
            } elseif (str_contains($trim, '{') && !str_contains($trim, '}')) {
                // Multi-line named import starts here
                $buffering = true;
                $buffer    = $trim;
            }
        }

        return $imports;
    }

    /**
     * Parse one (possibly assembled multi-line) import statement and append to $imports.
     *
     * @param array<int, array{symbols: string[], resolvedModule: string}> $imports
     */
    private function processImportLine(string $line, string $file, array &$imports): void
    {
        if (!preg_match('/from\s+[\'"]([^\'"]+)[\'"]/', $line, $fromMatch)) {
            return;
        }

        $specifier = $fromMatch[1];

        // Skip npm packages (no relative prefix and not the @/ project alias)
        if (!str_starts_with($specifier, '.') && !str_starts_with($specifier, '@/')) {
            return;
        }

        // Skip non-code asset imports
        if (preg_match('/\.(css|scss|sass|less|json|svg|png|jpg|gif|woff|ttf|eot)$/', $specifier)) {
            return;
        }

        $resolvedModule = $this->resolveModulePath($file, $specifier);
        if ($resolvedModule === null) {
            return;
        }

        $symbols = [];

        // Named import: import { X, Y as Z } from '...'
        if (preg_match('/import\s+\{([^}]+)\}/', $line, $namedMatch)) {
            foreach (preg_split('/\s*,\s*/', trim($namedMatch[1])) as $sym) {
                $sym = trim($sym);
                // Skip inline type-only members: { type Foo, Bar }
                if (str_starts_with($sym, 'type ')) {
                    continue;
                }
                // Strip alias "X as localName" → keep the exported name X
                $sym = trim((string) preg_replace('/\s+as\s+\S+.*/', '', $sym));
                if ($sym !== '') {
                    $symbols[] = $sym;
                }
            }
        }

        // Default import: import X from '...' (no braces, no *)
        if ($symbols === [] && preg_match('/^import\s+([A-Za-z_$][\w$]*)\s+from/', $line, $defMatch)) {
            $symbols[] = $defMatch[1];
        }

        // Namespace import: import * as X from '...'
        if ($symbols === [] && preg_match('/import\s+\*\s+as\s+([A-Za-z_$][\w$]*)/', $line, $nsMatch)) {
            // Wildcard: resolver will pick the first exported symbol from the target module
            $symbols[] = '__namespace';
        }

        if ($symbols === []) {
            return;
        }

        $imports[] = ['symbols' => $symbols, 'resolvedModule' => $resolvedModule];
    }

    /**
     * Collect all top-level symbols from a TS/JS file:
     * - All import statements (including npm packages) → kind=import, sourceModule=specifier
     * - export function / export default function → kind=export_function
     * - export const (arrow fn or plain value) → kind=export_const
     * - top-level function (no export) → kind=function
     * - top-level const (no export, no arrow fn) → kind=const
     *
     * @param string[] $lines
     * @return SymbolDTO[]
     */
    private function collectSymbols(array $lines, string $file, array $arrowDeclarationStarts): array
    {
        $symbols    = [];
        $depth      = 0;
        $buffer     = '';
        $buffering  = false;
        $bufferLine = 0;
        $lexicalState = $this->newLexicalState();

        foreach ($lines as $idx => $line) {
            $lineNo      = $idx + 1;
            $trim        = trim($line);
            $depthBefore = $depth;

            $structuralLine = $this->structuralCode(
                $line,
                $lexicalState,
                isset($arrowDeclarationStarts[$lineNo]),
            );
            $depth += substr_count($structuralLine, '{') - substr_count($structuralLine, '}');

            if (trim($structuralLine) === '') {
                continue;
            }

            // Assemble multi-line import for symbol collection
            if ($buffering) {
                $buffer .= ' ' . $trim;
                if (str_contains($trim, '}')) {
                    $buffering = false;
                    $this->processImportLineForSymbols($buffer, $bufferLine, $file, $symbols);
                    $buffer    = '';
                    $bufferLine = 0;
                }
                continue;
            }

            // Only collect top-level declarations (depth was 0 at start of this line)
            if ($depthBefore !== 0) {
                continue;
            }

            // Import statement (includes npm packages — unlike parseImports which filters them)
            if (str_starts_with($trim, 'import ')) {
                // Skip type-only imports
                if (preg_match('/^import\s+type\b/', $trim)) {
                    continue;
                }
                if (str_contains($trim, 'from')) {
                    $this->processImportLineForSymbols($trim, $lineNo, $file, $symbols);
                } elseif (str_contains($trim, '{') && !str_contains($trim, '}')) {
                    $buffering  = true;
                    $buffer     = $trim;
                    $bufferLine = $lineNo;
                }
                continue;
            }

            // export default function foo()
            if (preg_match('/^export\s+default\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $symbols[] = SymbolDTO::make($m[1], 'export_function', $file, $lineNo);
                continue;
            }

            // export function foo()
            if (preg_match('/^export\s+(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $symbols[] = SymbolDTO::make($m[1], 'export_function', $file, $lineNo);
                continue;
            }

            // export const foo = (...) => or export const foo = value
            if (preg_match('/^export\s+const\s+([A-Za-z_$][\w$]*)/', $trim, $m)) {
                $symbols[] = SymbolDTO::make($m[1], 'export_const', $file, $lineNo);
                continue;
            }

            // Top-level non-exported function
            if (preg_match('/^(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*[(<]/', $trim, $m)) {
                $symbols[] = SymbolDTO::make($m[1], 'function', $file, $lineNo);
                continue;
            }

            // Top-level non-exported const (skip arrow functions — they'd be caught above as export_const)
            if (preg_match('/^const\s+([A-Za-z_$][\w$]*)\s*=/', $trim, $m)) {
                // Only include plain consts, not arrow functions (to avoid duplicating flow nodes)
                if (!preg_match('/=\s*(?:async\s*)?[(<]/', $trim) && !preg_match('/=>\s*/', $trim)) {
                    $symbols[] = SymbolDTO::make($m[1], 'const', $file, $lineNo);
                }
            }
        }

        return $symbols;
    }

    /**
     * Parse one import line and append SymbolDTOs to $symbols.
     * Unlike processImportLine(), this includes npm package specifiers.
     *
     * @param SymbolDTO[] $symbols
     */
    private function processImportLineForSymbols(string $line, int $lineNo, string $file, array &$symbols): void
    {
        if (!preg_match('/from\s+[\'"]([^\'"]+)[\'"]/', $line, $fromMatch)) {
            return;
        }

        $specifier = $fromMatch[1];

        // Skip non-code asset imports
        if (preg_match('/\.(css|scss|sass|less|json|svg|png|jpg|gif|woff|ttf|eot)$/', $specifier)) {
            return;
        }

        $names = [];

        // Named imports: import { X, Y as Z } from '...' (also matches mixed: import Foo, { X } from '...')
        if (preg_match('/import\s+(?:[A-Za-z_$][\w$]*\s*,\s*)?\{([^}]+)\}/', $line, $namedMatch)) {
            foreach (preg_split('/\s*,\s*/', trim($namedMatch[1])) as $sym) {
                $sym = trim($sym);
                if (str_starts_with($sym, 'type ')) {
                    continue;
                }
                // Keep local alias after "as": import { Foo as Bar } → use "Bar" (local name)
                if (preg_match('/^(\S+)\s+as\s+(\S+)$/', $sym, $aliasM)) {
                    $sym = $aliasM[2];
                }
                if ($sym !== '') {
                    $names[] = $sym;
                }
            }
        }

        // Default import: import X from '...' or mixed import X, { ... } from '...'
        if (preg_match('/^import\s+([A-Za-z_$][\w$]*)\s*(?:,\s*\{|from)/', $line, $defMatch)) {
            $names[] = $defMatch[1];
        }

        // Namespace import: import * as X from '...'
        if ($names === [] && preg_match('/import\s+\*\s+as\s+([A-Za-z_$][\w$]*)/', $line, $nsMatch)) {
            $names[] = $nsMatch[1];
        }

        foreach ($names as $name) {
            $symbols[] = SymbolDTO::make($name, 'import', $file, $lineNo, $specifier);
        }
    }

    /**
     * Resolve an import specifier to a dot-notation module path relative to the project root.
     * Returns null for unresolvable paths (e.g. when the resolved path falls outside the project root).
     */
    private function resolveModulePath(string $currentFile, string $specifier): ?string
    {
        $root = str_replace(['\\', '/'], '/', rtrim($this->projectRoot, '/\\'));
        $dir  = str_replace(['\\', '/'], '/', dirname($currentFile));

        if (str_starts_with($specifier, '@/')) {
            // @/ alias: Next.js / Vite convention maps @/ to {root}/src/
            $rest     = substr($specifier, 2);
            $resolved = $root . '/src/' . $rest;
        } else {
            $resolved = $this->normalizePath($dir . '/' . ltrim($specifier, '/'));
        }

        // Strip TS/JS extension if present
        $resolved = (string) preg_replace('/\.(tsx?|jsx?)$/', '', $resolved);

        $rootSlash = $root . '/';
        if (str_starts_with($resolved, $rootSlash)) {
            $relative = substr($resolved, strlen($rootSlash));
        } else {
            return null;
        }

        $relative = trim($relative, '/');
        if ($relative === '') {
            return null;
        }

        return implode('.', array_map(
            $this->encodeIdentitySegment(...),
            explode('/', $relative),
        ));
    }

    /**
     * Resolve . and .. segments in a forward-slash path.
     */
    private function normalizePath(string $path): string
    {
        $leading = str_starts_with($path, '/') ? '/' : '';
        $parts   = explode('/', $path);
        $result  = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($result);
            } else {
                $result[] = $part;
            }
        }

        return $leading . implode('/', $result);
    }

    /**
     * Build metadata for a class method by merging class-level and method-level decorators.
     *
     * @param array<array{type: string, value: string}> $methodDecorators
     * @param array<array{type: string, value: string}> $classDecorators
     * @return array<string, mixed>
     */
    private function buildMethodMetadata(array $methodDecorators, array $classDecorators): array
    {
        $meta = [];

        // Class-level controller path
        $classPath = '';
        foreach ($classDecorators as $dec) {
            if ($dec['type'] === 'Controller') {
                $classPath = $dec['value'];
                $meta['framework'] = 'nestjs';
            }
            if (in_array($dec['type'], ['Injectable', 'Component', 'Pipe'], true)) {
                $meta['di'] = true;
            }
        }

        // Method-level HTTP decorators
        $httpMethods = ['Get', 'Post', 'Put', 'Delete', 'Patch'];
        foreach ($methodDecorators as $dec) {
            if (in_array($dec['type'], $httpMethods, true)) {
                $meta['http_method'] = strtoupper($dec['type']);
                $meta['http_path']   = $classPath . $dec['value'];
                $meta['entrypoint_type'] = 'http';
            }
            if (in_array($dec['type'], ['Injectable', 'Component', 'Pipe'], true)) {
                $meta['di'] = true;
            }
        }

        return $meta;
    }

    /**
     * Build metadata for standalone/exported functions.
     *
     * @param array<array{type: string, value: string}> $decorators
     * @param array<array{type: string, value: string}> $classDecorators
     * @return array<string, mixed>
     */
    private function buildFunctionMetadata(array $decorators, array $classDecorators): array
    {
        $meta = [];

        foreach ($decorators as $dec) {
            if ($dec['type'] === 'Controller') {
                $meta['http_path'] = $dec['value'];
                $meta['framework'] = 'nestjs';
            }
            $httpMethods = ['Get', 'Post', 'Put', 'Delete', 'Patch'];
            if (in_array($dec['type'], $httpMethods, true)) {
                $meta['http_method'] = strtoupper($dec['type']);
                $meta['http_path']   = $dec['value'];
                $meta['entrypoint_type'] = 'http';
            }
            if (in_array($dec['type'], ['Injectable', 'Component', 'Pipe'], true)) {
                $meta['di'] = true;
            }
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function withInferredFunctionEntrypointMetadata(array $metadata, string $file, string $module, string $functionName): array
    {
        if (isset($metadata['entrypoint_type'])) {
            return $metadata;
        }

        $lowerName = strtolower($functionName);
        $normalizedFile = str_replace('\\', '/', strtolower($file));
        $normalizedModule = strtolower($module);

        if (in_array($functionName, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $metadata['entrypoint_type'] = 'http';
            $metadata['http_method'] = $functionName;
            return $metadata;
        }

        $isRouteFile = preg_match('/(?:^|\/)[^\/]+\.route\.(?:tsx?|jsx?)$/', $normalizedFile) === 1;
        if (in_array($lowerName, ['handler', 'loader', 'action', 'middleware'], true)
            && ($isRouteFile || str_contains($normalizedFile, '/api/') || str_contains($normalizedFile, '/routes/') || str_contains($normalizedModule, '.route'))
        ) {
            $metadata['entrypoint_type'] = 'http';
            return $metadata;
        }

        if (in_array($lowerName, ['main', 'start', 'bootstrap', 'serve', 'runserver'], true)) {
            $metadata['entrypoint_type'] = 'cli';
            return $metadata;
        }

        if (($this->language === 'typescript' || $this->language === 'javascript')
            && str_ends_with($normalizedFile, '.tsx')
            && preg_match('/^[A-Z]/', $functionName) === 1
        ) {
            $metadata['entrypoint_type'] = 'ui';
        }

        return $metadata;
    }

    private function moduleNameFromPath(string $file): string
    {
        // Normalize both paths to forward slashes before comparing so that
        // mixed separators (e.g. Windows sys_get_temp_dir() + '/' . $name)
        // do not cause str_starts_with to fail.
        $root           = str_replace(['\\', '/'], '/', rtrim($this->projectRoot, '/\\'));
        $normalizedFile = str_replace(['\\', '/'], '/', $file);

        $relative = $normalizedFile;
        if (str_starts_with($normalizedFile, $root . '/')) {
            $relative = substr($normalizedFile, strlen($root . '/'));
        }

        // Strip known TS/JS extensions
        $relative = preg_replace('/\.(tsx?|jsx?)$/', '', $relative) ?? $relative;

        $relative = trim($relative, '/');
        if ($relative === '') {
            return 'module';
        }

        return implode('.', array_map(
            $this->encodeIdentitySegment(...),
            explode('/', $relative),
        ));
    }

    /**
     * @param array<int, array{name: string, depth: int}> $namespaceStack
     */
    private function namespacedModule(string $module, array $namespaceStack): string
    {
        if ($namespaceStack === []) {
            return $module;
        }

        $encodedNamespace = implode('.', array_map(
            $this->encodeIdentitySegment(...),
            array_column($namespaceStack, 'name'),
        ));

        return $module . '.~namespace~.' . $encodedNamespace;
    }

    /**
     * @param array<int, array{name: string, depth: int}> $namespaceStack
     */
    private function namespacedSymbolKey(array $namespaceStack, string $symbol): string
    {
        $namespace = implode('.', array_column($namespaceStack, 'name'));

        return $namespace === '' ? $symbol : $namespace . '::' . $symbol;
    }

    /**
     * Resolve a local call from the innermost namespace through each lexical ancestor.
     *
     * @param array<string, string> $topLevel
     * @param array<int, array{name: string, depth: int}> $namespaceStack
     */
    private function resolveTopLevelCall(array $topLevel, array $namespaceStack, string $symbol): ?string
    {
        for ($depth = count($namespaceStack); $depth >= 0; $depth--) {
            $key = $this->namespacedSymbolKey(array_slice($namespaceStack, 0, $depth), $symbol);
            if (isset($topLevel[$key])) {
                return $topLevel[$key];
            }
        }

        return null;
    }

    private function encodeIdentitySegment(string $segment): string
    {
        return str_replace(
            ['%', '.', '~', '/'],
            ['%25', '%2E', '%7E', '%2F'],
            $segment,
        );
    }

    /** @return string[] */
    private function namespaceNames(string $declarationName): array
    {
        if (
            (str_starts_with($declarationName, '"') && str_ends_with($declarationName, '"'))
            || (str_starts_with($declarationName, "'") && str_ends_with($declarationName, "'"))
        ) {
            return [substr($declarationName, 1, -1)];
        }

        return explode('.', $declarationName);
    }

    /**
     * @return array{
     *     blockComment: bool,
     *     quote: string|null,
     *     escaped: bool,
     *     regex: bool,
     *     regexClass: bool,
     *     templateStack: array<int, int|null>,
     *     templateEscaped: bool,
     *     templateDelimiterSeen: bool,
     *     genericDepth: int,
     *     genericOffsets: int[],
     *     braceKinds: string[],
     *     braceScopes: string[],
     *     lastClosedBraceKind: string|null,
     *     lastOpenedBraceIsBody: bool,
     *     openedBodyCount: int,
     *     previousCode: string,
     *     jsxContexts: array<int, array{type: string, closing?: bool, braceDepth?: int, lastNonSpace?: string, expressionDepth?: int, attributeQuote?: string|null}>,
     *     jsxCodeHistory: string
     * }
     */
    private function newLexicalState(): array
    {
        return [
            'blockComment' => false,
            'quote' => null,
            'escaped' => false,
            'regex' => false,
            'regexClass' => false,
            'templateStack' => [],
            'templateEscaped' => false,
            'templateDelimiterSeen' => false,
            'genericDepth' => 0,
            'genericOffsets' => [],
            'braceKinds' => [],
            'braceScopes' => [],
            'lastClosedBraceKind' => null,
            'lastOpenedBraceIsBody' => false,
            'openedBodyCount' => 0,
            'previousCode' => '',
            'jsxContexts' => [['type' => 'code']],
            'jsxCodeHistory' => '',
        ];
    }

    /**
     * Removes lexical trivia whose braces do not change JavaScript/TypeScript block depth.
     *
     * @param array{
     *     blockComment: bool,
     *     quote: string|null,
     *     escaped: bool,
     *     regex: bool,
     *     regexClass: bool,
     *     templateStack: array<int, int|null>,
     *     templateEscaped: bool,
     *     templateDelimiterSeen: bool,
     *     genericDepth: int,
     *     genericOffsets: int[],
     *     braceKinds: string[],
     *     braceScopes: string[],
     *     lastClosedBraceKind: string|null,
     *     lastOpenedBraceIsBody: bool,
     *     openedBodyCount: int,
     *     previousCode: string,
     *     jsxContexts: array<int, array{type: string, closing?: bool, braceDepth?: int, lastNonSpace?: string, expressionDepth?: int, attributeQuote?: string|null}>,
     *     jsxCodeHistory: string
     * } $state
     */
    private function structuralCode(string $line, array &$state, bool $assignmentStartsArrow = false): string
    {
        $code = '';
        $length = strlen($line);
        $state['lastOpenedBraceIsBody'] = false;
        $state['openedBodyCount'] = 0;
        $state['templateDelimiterSeen'] = false;
        $state['genericOffsets'] = [];
        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];
            $next = $index + 1 < $length ? $line[$index + 1] : '';

            if ($state['blockComment']) {
                $code .= ' ';
                if ($char === '*' && $next === '/') {
                    $code .= ' ';
                    $state['blockComment'] = false;
                    $index++;
                }
                continue;
            }

            if ($state['quote'] !== null) {
                $code .= ' ';
                if ($state['escaped']) {
                    $state['escaped'] = false;
                } elseif ($char === '\\') {
                    $state['escaped'] = true;
                } elseif ($char === $state['quote']) {
                    $state['quote'] = null;
                }
                continue;
            }

            if ($state['templateStack'] !== []) {
                $templateIndex = array_key_last($state['templateStack']);
                if ($state['templateStack'][$templateIndex] === null) {
                    $code .= ' ';
                    if ($state['templateEscaped']) {
                        $state['templateEscaped'] = false;
                    } elseif ($char === '\\') {
                        $state['templateEscaped'] = true;
                    } elseif ($char === '`') {
                        $state['templateDelimiterSeen'] = true;
                        array_pop($state['templateStack']);
                    } elseif ($char === '$' && $next === '{') {
                        $code .= ' ';
                        $state['templateStack'][$templateIndex] = 0;
                        $index++;
                    }
                    continue;
                }
            }

            if ($state['regex']) {
                $code .= ' ';
                if ($state['escaped']) {
                    $state['escaped'] = false;
                } elseif ($char === '\\') {
                    $state['escaped'] = true;
                } elseif ($char === '[') {
                    $state['regexClass'] = true;
                } elseif ($char === ']') {
                    $state['regexClass'] = false;
                } elseif ($char === '/' && !$state['regexClass']) {
                    $state['regex'] = false;
                }
                continue;
            }

            $jsxContextIndex = array_key_last($state['jsxContexts']);
            $jsxContext = $state['jsxContexts'][$jsxContextIndex];
            if (
                $this->jsxSyntax
                && $jsxContext['type'] === 'tag'
                && ($jsxContext['braceDepth'] ?? 0) === 0
                && ($jsxContext['attributeQuote'] ?? null) !== null
            ) {
                $code .= ' ';
                if ($char === $jsxContext['attributeQuote']) {
                    $state['jsxContexts'][$jsxContextIndex]['attributeQuote'] = null;
                }
                continue;
            }

            $jsxContextIndex = array_key_last($state['jsxContexts']);
            $jsxContext = $state['jsxContexts'][$jsxContextIndex];
            $jsxExpressionDepth = $jsxContext['type'] === 'children'
                ? ($jsxContext['expressionDepth'] ?? 0)
                : ($jsxContext['braceDepth'] ?? 0);
            $inJsxText = $this->jsxSyntax
                && $jsxContext['type'] === 'children'
                && $jsxExpressionDepth === 0;
            if ($inJsxText) {
                if ($char === '<') {
                    $state['jsxContexts'][] = $this->newJsxTagContext($next);
                } elseif ($char !== '{') {
                    $code .= $char;
                    continue;
                }
            }

            $jsxContextIndex = array_key_last($state['jsxContexts']);
            $jsxContext = $state['jsxContexts'][$jsxContextIndex];
            $inJsxTagMarkup = $this->jsxSyntax
                && $jsxContext['type'] === 'tag'
                && ($jsxContext['braceDepth'] ?? 0) === 0;
            if ($inJsxTagMarkup && in_array($char, ["'", '"'], true)) {
                $state['jsxContexts'][$jsxContextIndex]['attributeQuote'] = $char;
                $code .= ' ';
                continue;
            }
            if ($inJsxTagMarkup && !in_array($char, ['{', '>'], true)) {
                if ($char === '<' && ($jsxContext['lastNonSpace'] ?? '') !== '<') {
                    $state['jsxContexts'][$jsxContextIndex]['typeArgumentDepth']++;
                }
                if (!ctype_space($char)) {
                    $state['jsxContexts'][$jsxContextIndex]['lastNonSpace'] = $char;
                }
                $code .= $char;
                continue;
            }

            if ($char === '/' && $next === '/') {
                $code .= str_repeat(' ', $length - $index);
                break;
            }
            if ($char === '/' && $next === '*') {
                $code .= '  ';
                $state['blockComment'] = true;
                $index++;
                continue;
            }
            if ($char === '`') {
                $code .= ' ';
                $state['templateDelimiterSeen'] = true;
                $state['templateStack'][] = null;
                $state['templateEscaped'] = false;
                continue;
            }
            if (in_array($char, ["'", '"'], true)) {
                $code .= ' ';
                $state['quote'] = $char;
                $state['escaped'] = false;
                continue;
            }

            if ($state['templateStack'] !== []) {
                $templateIndex = array_key_last($state['templateStack']);
                $templateDepth = $state['templateStack'][$templateIndex];
                if ($templateDepth !== null) {
                    if ($char === '}' && $templateDepth === 0) {
                        $code .= ' ';
                        $state['templateStack'][$templateIndex] = null;
                        continue;
                    }
                    if ($char === '{') {
                        $state['templateStack'][$templateIndex]++;
                    } elseif ($char === '}') {
                        $state['templateStack'][$templateIndex]--;
                    }
                }
            }
            if ($char === '/' && $this->isJsxClosingTagStart($code, $next)) {
                $code .= $char;
                continue;
            }
            $regexContext = trim($state['previousCode'] . ' ' . $code);
            if ($char === '/' && $this->startsRegexLiteral($regexContext, $state['lastClosedBraceKind'])) {
                $code .= ' ';
                $state['regex'] = true;
                $state['regexClass'] = false;
                $state['escaped'] = false;
                continue;
            }

            $startsGeneric = false;
            if ($char === '<' && !$inJsxText) {
                $genericContext = rtrim($state['previousCode'] . ' ' . $code);
                $inDeclarationBody = end($state['braceScopes']) === 'declaration';
                $startsGeneric = $state['genericDepth'] > 0
                    || $this->startsGenericParameterList(
                        $genericContext,
                        $inDeclarationBody,
                        $assignmentStartsArrow,
                    );
            }

            $openedJsxTag = false;
            if ($this->jsxSyntax && $char === '<' && !$startsGeneric && !$inJsxText && !$inJsxTagMarkup) {
                $jsxContextIndex = array_key_last($state['jsxContexts']);
                $insideJsxExpression = ($state['jsxContexts'][$jsxContextIndex]['type'] ?? '') !== 'code';
                if ($this->startsJsxTagAt($line, $index, $state['jsxCodeHistory'], $insideJsxExpression)) {
                    $state['jsxContexts'][] = $this->newJsxTagContext($next);
                    $openedJsxTag = true;
                }
            }

            if ($char === '<' && !$openedJsxTag && $startsGeneric) {
                $state['genericDepth']++;
                $state['genericOffsets'][] = $index;
            } elseif (
                $char === '>'
                && $state['genericDepth'] > 0
                && !str_ends_with(rtrim($code), '=')
            ) {
                $state['genericDepth']--;
            } elseif ($char === ';' && $state['genericDepth'] > 0) {
                $state['genericDepth'] = 0;
            }

            if ($char === '{') {
                $braceContext = trim($state['previousCode'] . ' ' . $code);
                $braceKind = $state['genericDepth'] > 0 ? 'expression' : $this->openingBraceKind($braceContext);
                $state['braceKinds'][] = $braceKind;
                $state['braceScopes'][] = $this->openingBraceScope($braceContext);
                $braceIsBody = $state['genericDepth'] === 0
                    && ($braceKind === 'block' || $this->isCallableBodyContext($braceContext));
                $state['lastOpenedBraceIsBody'] = $braceIsBody;
                if ($braceIsBody) {
                    $state['openedBodyCount']++;
                }
                $state['lastClosedBraceKind'] = null;
            } elseif ($char === '}') {
                $state['lastClosedBraceKind'] = array_pop($state['braceKinds']) ?? 'block';
                array_pop($state['braceScopes']);
            } elseif (!ctype_space($char)) {
                $state['lastClosedBraceKind'] = null;
            }

            $this->advanceStructuralJsxContext(
                $state['jsxContexts'],
                $char,
                $inJsxText,
                $inJsxTagMarkup,
                $openedJsxTag,
            );

            $code .= $char;
        }

        if (!$state['escaped']) {
            $state['quote'] = null;
        }
        $state['escaped'] = false;
        $state['regex'] = false;
        $state['regexClass'] = false;
        if (trim($code) !== '') {
            $state['previousCode'] = substr(
                trim($state['previousCode'] . ' ' . $code),
                -4096,
            );
            $state['jsxCodeHistory'] = substr(
                trim($state['jsxCodeHistory'] . ' ' . $code),
                -4096,
            );
        }

        return $code;
    }

    private function startsGenericParameterList(
        string $code,
        bool $inDeclarationBody,
        bool $assignmentStartsArrow,
    ): bool
    {
        if (preg_match('/\b(?:function|class|interface|type)\s+[A-Za-z_$][\w$]*\s*$/', $code) === 1) {
            return true;
        }

        if (
            preg_match(
                '/(?:^|[;{}])\s*'
                . '(?:(?:export|public|private|protected|readonly|static|abstract|override|declare|accessor)\s+)*'
                . '(?:(?:const|let|var)\s+)?[A-Za-z_$][\w$]*\s*=\s*(?:async\s*)?$/',
                $code,
            ) === 1
        ) {
            return $assignmentStartsArrow;
        }

        if (!$inDeclarationBody) {
            return false;
        }

        if (
            preg_match(
                '/(?:^|[;{}])\s*(?:(?:public|private|protected|readonly|static|abstract|override|async|get|set)\s+)*'
                . '([A-Za-z_$][\w$]*)\s*$/',
                $code,
                $match,
            ) !== 1
        ) {
            return false;
        }

        return !in_array(
            $match[1],
            ['if', 'for', 'while', 'switch', 'return', 'const', 'let', 'var', 'new', 'throw', 'catch', 'try'],
            true,
        );
    }

    private function openingBraceScope(string $code): string
    {
        if (preg_match('/\b(?:class|interface)\b[^{};]*$/', $code) === 1) {
            return 'declaration';
        }

        return $this->isCallableBodyContext($code) ? 'callable' : 'other';
    }

    private function startsRegexLiteral(string $code, ?string $lastClosedBraceKind): bool
    {
        $trimmed = rtrim($code);
        if ($trimmed === '') {
            return true;
        }

        if (str_ends_with($trimmed, '}')) {
            return $lastClosedBraceKind === 'block';
        }

        if (str_ends_with($trimmed, '...')) {
            return true;
        }

        if (str_ends_with($trimmed, '++') || str_ends_with($trimmed, '--')) {
            return false;
        }

        if (preg_match('/(?:^|[=(:,!&|?{}\[\];+*%~^<>\/-]|=>)\s*$/', $trimmed) === 1) {
            return true;
        }

        if ($this->followsControlHeader($trimmed)) {
            return true;
        }

        return preg_match('/\b(?:return|throw|case|delete|void|typeof|instanceof|in|of|yield|await|else|do|new|debugger)\s*$/', $trimmed) === 1
            || preg_match('/\b(?:break|continue)(?:\s+[A-Za-z_$][\w$]*)?\s*$/', $trimmed) === 1;
    }

    private function openingBraceKind(string $code): string
    {
        $trimmed = rtrim($code);
        if ($trimmed === '') {
            return 'block';
        }

        if (str_ends_with($trimmed, '=>')) {
            return 'expression';
        }

        $constructKind = $this->functionOrClassBraceKind($trimmed);
        if ($constructKind !== null) {
            return $constructKind;
        }

        if (preg_match('/\b(?:interface|enum|namespace|module|try|finally|else|do)\s*$/', $trimmed) === 1) {
            return 'block';
        }

        if (preg_match('/\b(?:class|interface|enum|namespace|module)\s+[A-Za-z_$][\w$]*(?:\s*<[^{}]+>)?\s*$/', $trimmed) === 1) {
            return 'block';
        }

        if (
            preg_match('/(?:^|[=(:,\[!&|?;+*%~^<>\/-])\s*$/', $trimmed) === 1
            || preg_match('/\b(?:return|throw|case|yield|await|new|void|typeof|delete|default)\s*$/', $trimmed) === 1
        ) {
            return 'expression';
        }

        return 'block';
    }

    private function functionOrClassBraceKind(string $code): ?string
    {
        if (preg_match_all('/\b(?:function|class)\b/', $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        for ($index = count($matches[0]) - 1; $index >= 0; $index--) {
            [$keyword, $offset] = $matches[0][$index];
            $header = substr($code, $offset + strlen($keyword));
            if (
                $this->delimiterDepth(substr($code, 0, $offset)) !== $this->delimiterDepth($code)
                || $this->containsTopLevelSemicolon($header)
                || substr_count($header, '{') !== substr_count($header, '}')
                || $this->hasCompletedConstructBody($keyword, $header)
                || ($keyword === 'function' && preg_match('/:\s*$/', $header) === 1)
                || ($keyword === 'function' && !str_contains($header, '('))
            ) {
                continue;
            }

            $prefix = rtrim(substr($code, 0, $offset));
            if ($keyword === 'function') {
                $prefix = preg_replace('/\basync\s*$/', '', $prefix) ?? $prefix;
            }

            return $this->constructStartsExpression(rtrim($prefix)) ? 'expression' : 'block';
        }

        return null;
    }

    private function isCallableBodyContext(string $code): bool
    {
        if (str_ends_with(rtrim($code), '=>')) {
            return true;
        }

        if ($this->functionOrClassBraceKind($code) !== null) {
            return true;
        }

        return preg_match(
            '/(?:^|[{};])\s*(?:(?:public|private|protected|readonly|static|abstract|override|async|get|set)\s+)*'
            . '[A-Za-z_$][\w$]*(?:\s*<[^;{}]*>)?\s*\([\s\S]*\)\s*(?::\s*[^=;{}][^=;]*)?$/',
            $code,
        ) === 1;
    }

    /** @return array{parentheses: int, brackets: int} */
    private function delimiterDepth(string $code): array
    {
        $parentheses = 0;
        $brackets = 0;
        foreach (str_split($code) as $char) {
            if ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses = max(0, $parentheses - 1);
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            }
        }

        return ['parentheses' => $parentheses, 'brackets' => $brackets];
    }

    private function hasCompletedConstructBody(string $keyword, string $header): bool
    {
        $parentheses = 0;
        $brackets = 0;
        $bracePairs = 0;
        $openTopLevelBrace = false;
        foreach (str_split($header) as $char) {
            if ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses = max(0, $parentheses - 1);
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($char === '{' && $parentheses === 0 && $brackets === 0) {
                $openTopLevelBrace = true;
            } elseif ($char === '}' && $openTopLevelBrace && $parentheses === 0 && $brackets === 0) {
                $bracePairs++;
                $openTopLevelBrace = false;
            }
        }

        if ($bracePairs === 0) {
            return false;
        }

        if ($keyword !== 'function' || $bracePairs > 1) {
            return true;
        }

        $lastParameter = strrpos($header, ')');
        return $lastParameter === false || !str_contains(substr($header, $lastParameter + 1), ':');
    }

    private function containsTopLevelSemicolon(string $code): bool
    {
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;
        foreach (str_split($code) as $char) {
            if ($char === '(') {
                $parentheses++;
            } elseif ($char === ')') {
                $parentheses = max(0, $parentheses - 1);
            } elseif ($char === '[') {
                $brackets++;
            } elseif ($char === ']') {
                $brackets = max(0, $brackets - 1);
            } elseif ($char === '{') {
                $braces++;
            } elseif ($char === '}') {
                $braces = max(0, $braces - 1);
            } elseif ($char === ';' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                return true;
            }
        }

        return false;
    }

    private function constructStartsExpression(string $prefix): bool
    {
        if ($prefix === '' || preg_match('/\b(?:export|export\s+default|declare)\s*$/', $prefix) === 1) {
            return false;
        }

        return preg_match('/(?:[=(:,\[!&|?;+*%~^<>\/-]|=>)\s*$/', $prefix) === 1
            || preg_match('/\b(?:return|throw|case|yield|await|new|void|typeof|delete)\s*$/', $prefix) === 1;
    }

    private function isJsxClosingTagStart(string $code, string $next): bool
    {
        return str_ends_with(rtrim($code), '<')
            && preg_match('/[A-Za-z_$>]/', $next) === 1;
    }

    /**
     * @param array<int, array{type: string, closing?: bool, braceDepth?: int, lastNonSpace?: string, expressionDepth?: int, attributeQuote?: string|null}> $contexts
     */
    private function maskJsxTextForEdges(
        string $structuralMask,
        array &$contexts,
        string &$codeHistory,
        array $genericOffsets = [],
    ): string
    {
        if (!$this->jsxSyntax || $structuralMask === '') {
            if (trim($structuralMask) !== '') {
                $codeHistory = substr(trim($codeHistory . ' ' . $structuralMask), -4096);
            }
            return $structuralMask;
        }

        $edgeMask = $structuralMask;
        $length = strlen($structuralMask);
        for ($index = 0; $index < $length; $index++) {
            $char = $structuralMask[$index];
            $contextIndex = array_key_last($contexts);
            $context = $contexts[$contextIndex];

            if ($context['type'] === 'code') {
                if ($char === '<'
                    && !in_array($index, $genericOffsets, true)
                    && $this->startsJsxTagAt($structuralMask, $index, $codeHistory, false)
                ) {
                    $edgeMask[$index] = ' ';
                    $contexts[] = $this->newJsxTagContext($structuralMask[$index + 1] ?? '');
                }
                continue;
            }

            if ($context['type'] === 'children') {
                $expressionDepth = $context['expressionDepth'] ?? 0;
                if ($expressionDepth === 0) {
                    if ($char === '<') {
                        $edgeMask[$index] = ' ';
                        $contexts[] = $this->newJsxTagContext($structuralMask[$index + 1] ?? '');
                    } elseif ($char === '{') {
                        $contexts[$contextIndex]['expressionDepth'] = 1;
                    } else {
                        $edgeMask[$index] = ' ';
                    }
                    continue;
                }

                if ($char === '<'
                    && !in_array($index, $genericOffsets, true)
                    && $this->startsJsxTagAt($structuralMask, $index, $codeHistory, true)
                ) {
                    $edgeMask[$index] = ' ';
                    $contexts[] = $this->newJsxTagContext($structuralMask[$index + 1] ?? '');
                } elseif ($char === '{') {
                    $contexts[$contextIndex]['expressionDepth'] = $expressionDepth + 1;
                } elseif ($char === '}') {
                    $contexts[$contextIndex]['expressionDepth'] = max(0, $expressionDepth - 1);
                }
                continue;
            }

            $braceDepth = $context['braceDepth'] ?? 0;
            if ($braceDepth > 0) {
                if ($char === '<'
                    && !in_array($index, $genericOffsets, true)
                    && $this->startsJsxTagAt($structuralMask, $index, $codeHistory, true)
                ) {
                    $edgeMask[$index] = ' ';
                    $contexts[] = $this->newJsxTagContext($structuralMask[$index + 1] ?? '');
                } elseif ($char === '{') {
                    $contexts[$contextIndex]['braceDepth'] = $braceDepth + 1;
                } elseif ($char === '}') {
                    $contexts[$contextIndex]['braceDepth'] = $braceDepth - 1;
                }
                continue;
            }

            $edgeMask[$index] = ' ';
            if ($char === '{') {
                $edgeMask[$index] = '{';
                $contexts[$contextIndex]['braceDepth'] = 1;
                continue;
            }
            if ($char === '<') {
                $contexts[$contextIndex]['typeArgumentDepth']++;
                continue;
            }
            if ($char === '>') {
                if (($context['typeArgumentDepth'] ?? 0) > 0) {
                    if (($context['lastNonSpace'] ?? '') === '=') {
                        continue;
                    }
                    $contexts[$contextIndex]['typeArgumentDepth']--;
                    continue;
                }
                $closing = $context['closing'] ?? false;
                $selfClosing = ($context['lastNonSpace'] ?? '') === '/';
                array_pop($contexts);
                if ($closing) {
                    if (($contexts[array_key_last($contexts)]['type'] ?? '') === 'children') {
                        array_pop($contexts);
                    }
                } elseif (!$selfClosing) {
                    $contexts[] = ['type' => 'children', 'expressionDepth' => 0];
                }
                continue;
            }
            if (!ctype_space($char)) {
                $contexts[$contextIndex]['lastNonSpace'] = $char;
            }
        }

        if (trim($structuralMask) !== '') {
            $codeHistory = substr(trim($codeHistory . ' ' . $structuralMask), -4096);
        }

        return $edgeMask;
    }

    /** @return array{type: string, closing: bool, braceDepth: int, lastNonSpace: string, attributeQuote: string|null, typeArgumentDepth: int} */
    private function newJsxTagContext(string $nextChar): array
    {
        return [
            'type' => 'tag',
            'closing' => $nextChar === '/',
            'braceDepth' => 0,
            'lastNonSpace' => '<',
            'attributeQuote' => null,
            'typeArgumentDepth' => 0,
        ];
    }

    /**
     * @param array<int, array{type: string, closing?: bool, braceDepth?: int, lastNonSpace?: string, expressionDepth?: int, attributeQuote?: string|null}> $contexts
     */
    private function advanceStructuralJsxContext(
        array &$contexts,
        string $char,
        bool $wasText,
        bool $wasTagMarkup,
        bool $openedTag,
    ): void {
        if (!$this->jsxSyntax || $openedTag) {
            return;
        }

        $contextIndex = array_key_last($contexts);
        $context = $contexts[$contextIndex];
        if ($wasText && $char === '{') {
            $contexts[$contextIndex]['expressionDepth'] = 1;
            return;
        }

        if ($wasTagMarkup) {
            if ($char === '{') {
                $contexts[$contextIndex]['braceDepth'] = 1;
                return;
            }
            if ($char !== '>') {
                return;
            }
            if (($context['typeArgumentDepth'] ?? 0) > 0) {
                if (($context['lastNonSpace'] ?? '') === '=') {
                    return;
                }
                $contexts[$contextIndex]['typeArgumentDepth']--;
                return;
            }

            $closing = $context['closing'] ?? false;
            $selfClosing = ($context['lastNonSpace'] ?? '') === '/';
            array_pop($contexts);
            if ($closing) {
                if (($contexts[array_key_last($contexts)]['type'] ?? '') === 'children') {
                    array_pop($contexts);
                }
            } elseif (!$selfClosing) {
                $contexts[] = ['type' => 'children', 'expressionDepth' => 0];
            }
            return;
        }

        if ($context['type'] === 'children') {
            $depth = $context['expressionDepth'] ?? 0;
            if ($depth > 0) {
                if ($char === '{') {
                    $contexts[$contextIndex]['expressionDepth'] = $depth + 1;
                } elseif ($char === '}') {
                    $contexts[$contextIndex]['expressionDepth'] = $depth - 1;
                }
            }
            return;
        }

        if ($context['type'] === 'tag') {
            $depth = $context['braceDepth'] ?? 0;
            if ($depth > 0) {
                if ($char === '{') {
                    $contexts[$contextIndex]['braceDepth'] = $depth + 1;
                } elseif ($char === '}') {
                    $contexts[$contextIndex]['braceDepth'] = $depth - 1;
                }
            }
        }
    }

    private function startsJsxTagAt(
        string $structuralMask,
        int $offset,
        string $codeHistory,
        bool $insideJsxExpression,
    ): bool {
        $next = $structuralMask[$offset + 1] ?? '';
        if ($next === '/' || $next === '>') {
            return $insideJsxExpression || $next === '>';
        }
        if (preg_match('/[A-Za-z_$]/', $next) !== 1) {
            return false;
        }
        if (preg_match(
            '/^<[A-Za-z_$][\w$]*(?:(?:\s+extends\b[^>\r\n]*)|(?:\s*,\s*))>\s*\(/',
            substr($structuralMask, $offset),
        ) === 1) {
            return false;
        }
        if ($insideJsxExpression) {
            $prefix = rtrim($codeHistory . ' ' . substr($structuralMask, 0, $offset));
            return $this->constructStartsExpression($prefix);
        }

        $prefix = rtrim($codeHistory . ' ' . substr($structuralMask, 0, $offset));
        return $this->constructStartsExpression($prefix);
    }

    private function followsControlHeader(string $code): bool
    {
        if (!str_ends_with($code, ')')) {
            return false;
        }

        $depth = 0;
        for ($index = strlen($code) - 1; $index >= 0; $index--) {
            if ($code[$index] === ')') {
                $depth++;
                continue;
            }
            if ($code[$index] !== '(') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                $prefix = rtrim(substr($code, 0, $index));

                return preg_match('/\b(?:if|while|for(?:\s+await)?|with)$/', $prefix) === 1;
            }
        }

        return false;
    }

    private function stripNamespaceTrivia(string $line, bool &$inBlockComment): ?string
    {
        $remaining = trim($line);
        while (true) {
            if ($inBlockComment) {
                $end = strpos($remaining, '*/');
                if ($end === false) {
                    return null;
                }
                $inBlockComment = false;
                $remaining = trim(substr($remaining, $end + 2));
                continue;
            }

            if ($remaining === '' || str_starts_with($remaining, '//')) {
                return null;
            }
            if (!str_starts_with($remaining, '/*')) {
                return $remaining;
            }

            $end = strpos($remaining, '*/', 2);
            if ($end === false) {
                $inBlockComment = true;
                return null;
            }
            $remaining = trim(substr($remaining, $end + 2));
        }
    }

    private function isNamespaceBlockOpenToken(string $token, bool &$inBlockComment): bool
    {
        if (!str_starts_with($token, '{')) {
            return false;
        }

        $trailingTrivia = trim(substr($token, 1));
        if ($trailingTrivia === '') {
            return true;
        }

        return $this->stripNamespaceTrivia($trailingTrivia, $inBlockComment) === null;
    }

    /**
     * Compute the initial endLine tracking state for a node based on its declaration line.
     *
     * Uses the last non-whitespace char of the trimmed declaration line as a discriminator:
     *  - `{`    body opens this line, multi-line body expected
     *  - `}`    inline body closes this line
     *  - `;`    abstract/overload, expression-bodied arrow, or inline statement — no further body
     *  - `,`,`(` multi-line signature continues; body has not opened yet
     *
     * Avoids confusing TypeScript type literals (e.g. `arg: { id: string },`) with function bodies.
     *
     * @return array{startDepth: int, endLine: int|null, bodyOpened: bool, expressionBody: bool, expressionNesting: int, arrowLine: int|null}
     */
    private function initialTracking(
        string $trim,
        int $depthBefore,
        int $lineNo,
        bool $lastOpenedBraceIsBody,
        ?int $arrowLine = null,
        ?string $nextStructuralLine = null,
        bool $templateOpen = false,
    ): array
    {
        // Expression-bodied arrow without `{` (relies on ASI for the trailing semicolon):
        // `const f = (x) => x + 1` — body is the rest of the same line.
        if ($arrowLine === $lineNo && str_contains($trim, '=>')) {
            $expression = substr($trim, (int) strrpos($trim, '=>') + 2);
            if (!str_starts_with(ltrim($expression), '{')) {
                $nesting = $this->expressionNestingDelta($expression);
                return [
                    'startDepth' => $depthBefore,
                    'endLine' => !$templateOpen && $this->expressionEndsOnLine($expression, $nesting, $nextStructuralLine)
                        ? $lineNo
                        : null,
                    'bodyOpened' => true,
                    'expressionBody' => true,
                    'expressionNesting' => $nesting,
                    'arrowLine' => $arrowLine,
                ];
            }
        }

        $lastChar = $trim !== '' ? $trim[strlen($trim) - 1] : '';

        return match ($lastChar) {
            '{'      => ['startDepth' => $depthBefore, 'endLine' => null,    'bodyOpened' => $lastOpenedBraceIsBody, 'expressionBody' => false, 'expressionNesting' => 0, 'arrowLine' => $arrowLine],
            '}', ';' => ['startDepth' => $depthBefore, 'endLine' => $lineNo, 'bodyOpened' => true, 'expressionBody' => false, 'expressionNesting' => 0, 'arrowLine' => $arrowLine],
            default  => ['startDepth' => $depthBefore, 'endLine' => null,    'bodyOpened' => false, 'expressionBody' => false, 'expressionNesting' => 0, 'arrowLine' => $arrowLine],
        };
    }

    /**
     * Distinguishes a method declaration from a function call.
     * Declarations end with `{` (body open), `}` (inline body like `foo() {}`),
     * `,`/`(`/`<` (multi-line signature), or `;` preceded by a non-`)` char
     * (abstract/overload with return type). Calls typically end with `);` or `),`.
     */
    private function looksLikeMethodDeclaration(string $trimmed): bool
    {
        if ($trimmed === '') {
            return false;
        }
        $last = $trimmed[strlen($trimmed) - 1];
        return match ($last) {
            '{', '}', ',', '(', '<', ':' => true,
            ';' => strlen($trimmed) >= 2 && $trimmed[strlen($trimmed) - 2] !== ')',
            default => false,
        };
    }

    /**
     * Finds declarations whose assigned value is itself an arrow function.
     * The arrow must appear after the outer parameter list closes; this rejects
     * ordinary multiline expressions that merely contain a nested callback.
     *
     * @param string[] $lines
     * @return array<int, int> Declaration line => outer arrow line.
     */
    private function arrowDeclarationStarts(array $lines): array
    {
        $starts = [];
        $lineCount = count($lines);
        $candidatePattern = '/^(?:(?:export|public|private|protected|readonly|static|abstract|override|declare|accessor)\s+)*'
            . '(?:(?:const|let|var)\s+)?[A-Za-z_$][\w$]*\s*=\s*(?:async\s*)?[(<]/';

        for ($start = 0; $start < $lineCount; $start++) {
            if (!preg_match($candidatePattern, trim($lines[$start]))) {
                continue;
            }
            if (!$this->isDisambiguatedTsxGenericArrowCandidate($lines, $start)) {
                continue;
            }

            $state = $this->newLexicalState();
            $parentheses = 0;
            $braces = 0;
            $brackets = 0;
            $angles = 0;
            $trackAngles = false;
            $awaitingParameters = false;
            $parameterListStarted = false;
            $parameterListClosed = false;
            $returnTypeStarted = false;
            $arrowLine = null;

            for ($idx = $start; $idx < $lineCount; $idx++) {
                $code = $this->structuralCode($lines[$idx], $state, $idx === $start);
                if ($idx === $start) {
                    $equals = strpos($code, '=');
                    $code = $equals === false ? '' : substr($code, $equals + 1);
                    $trackAngles = str_starts_with(ltrim($code), '<');
                }

                $length = strlen($code);
                for ($offset = 0; $offset < $length; $offset++) {
                    if ($parameterListClosed && !$returnTypeStarted
                        && $parentheses === 0 && $braces === 0 && $brackets === 0 && $angles === 0
                        && !ctype_space($code[$offset])
                        && !($code[$offset] === '=' && ($code[$offset + 1] ?? '') === '>')
                    ) {
                        if ($code[$offset] !== ':') {
                            break 2;
                        }
                        $returnTypeStarted = true;
                    }
                    if ($code[$offset] === '=' && ($code[$offset + 1] ?? '') === '>'
                        && $parentheses === 0 && $braces === 0 && $brackets === 0 && $angles === 0
                        && $parameterListClosed
                    ) {
                        $arrowLine = $idx + 1;
                        break 2;
                    }
                    if ($code[$offset] === '=' && ($code[$offset + 1] ?? '') === '>') {
                        $offset++;
                        continue;
                    }

                    if ($awaitingParameters && !ctype_space($code[$offset])) {
                        if ($code[$offset] !== '(') {
                            break 2;
                        }
                        $awaitingParameters = false;
                    }

                    if (!$trackAngles && $code[$offset] === '(' && $parentheses === 0 && !$parameterListStarted) {
                        $parameterListStarted = true;
                    }

                    match ($code[$offset]) {
                        '(' => $parentheses++,
                        ')' => $parentheses = max(0, $parentheses - 1),
                        '{' => $braces++,
                        '}' => $braces = max(0, $braces - 1),
                        '[' => $brackets++,
                        ']' => $brackets = max(0, $brackets - 1),
                        default => null,
                    };
                    if ($trackAngles && $code[$offset] === '<') {
                        $angles++;
                    } elseif ($trackAngles && $code[$offset] === '>') {
                        $angles = max(0, $angles - 1);
                        if ($angles === 0) {
                            $trackAngles = false;
                            $awaitingParameters = true;
                        }
                    }
                    if ($parameterListStarted && $code[$offset] === ')' && $parentheses === 0) {
                        $parameterListClosed = true;
                    }

                    if ($code[$offset] === ';'
                        && $parentheses === 0 && $braces === 0 && $brackets === 0
                    ) {
                        break 2;
                    }
                }
            }

            if ($arrowLine !== null) {
                $starts[$start + 1] = $arrowLine;
            }
        }

        return $starts;
    }

    /** @param string[] $lines */
    private function isDisambiguatedTsxGenericArrowCandidate(array $lines, int $start): bool
    {
        if (!$this->jsxSyntax) {
            return true;
        }

        $candidate = implode("\n", array_slice($lines, $start, 64));
        $equals = strpos($candidate, '=');
        if ($equals === false) {
            return false;
        }

        $value = ltrim(substr($candidate, $equals + 1));
        $value = preg_replace('/\/\*[\s\S]*?\*\/|\/\/[^\r\n]*/', ' ', $value) ?? $value;
        $value = ltrim($value);
        if (preg_match('/^async\b/', $value) === 1) {
            $value = ltrim(substr($value, strlen('async')));
        }
        if (!str_starts_with($value, '<')) {
            return true;
        }

        return preg_match(
            '/^<\s*(?:(?:const|in|out)\s+)*[A-Za-z_$][\w$]*\s*(?:,|\bextends\b\s++(?![>=]|\{\s*\.\.\.)|=)/',
            $value,
        ) === 1;
    }

    private function expressionNestingDelta(string $structuralLine): int
    {
        return substr_count($structuralLine, '(')
            + substr_count($structuralLine, '[')
            + substr_count($structuralLine, '{')
            - substr_count($structuralLine, ')')
            - substr_count($structuralLine, ']')
            - substr_count($structuralLine, '}');
    }

    private function expressionEndsOnLine(
        string $structuralLine,
        int $nesting,
        ?string $nextLine,
    ): bool
    {
        $current = rtrim($structuralLine);
        if ($current === '' || $nesting > 0) {
            return false;
        }
        if (str_ends_with($current, ';')) {
            return true;
        }
        if (preg_match('/(?:=>|[({\[.,?:=+*\/%&|!<>-]|\b(?:await|yield|new|typeof|void|delete))$/', $current)) {
            return false;
        }

        $next = ltrim((string) $nextLine);
        return $next === '' || !preg_match(
            '/^(?:[`(\[.?,:]|!\s*[.(\[]|\?\?|\?\.|&&|\|\||\*\*|===?|!==?|<=?|>=?|\+|-|\*|\/|%|&|\||(?:as|satisfies|instanceof|in)\b)/',
            $next,
        );
    }

    /**
     * @param string[] $lines
     */
    private function nextStructuralLine(array $lines, int $startIndex, array $state): ?string
    {
        for ($idx = $startIndex, $count = count($lines); $idx < $count; $idx++) {
            $structural = $this->structuralCode($lines[$idx], $state);
            $structural = trim($this->withTemplateMarker($structural, $state));
            if ($structural !== '') {
                return $structural;
            }
        }

        return null;
    }

    /**
     * @param array{templateDelimiterSeen: bool} $state
     */
    private function withTemplateMarker(string $structuralLine, array $state): string
    {
        if (!$state['templateDelimiterSeen']) {
            return $structuralLine;
        }

        return '` ' . $structuralLine;
    }
}
