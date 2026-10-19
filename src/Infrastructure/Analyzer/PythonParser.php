<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\NodeFactory;

/**
 * PythonParser
 *
 * Best-effort parser for Python source files. Detects:
 *
 * Nodes:
 *   - Top-level functions and class methods
 *   - Framework entrypoints (Flask/FastAPI HTTP routes, Django CBV methods,
 *     Click/Typer CLI commands, Celery tasks, __main__ script entry blocks)
 *
 * Edges:
 *   - Intra-file function calls
 *   - Cross-module calls via import aliases (Pass 0)
 */
final class PythonParser implements FileParser
{
    public function __construct(
        private readonly NodeFactory $nodeFactory,
        private readonly string $projectRoot
    ) {
    }

    /**
     * @return array{nodes: Node[], edges: Edge[]}
     */
    public function parse(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return ['nodes' => [], 'edges' => []];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (!is_array($lines)) {
            return ['nodes' => [], 'edges' => []];
        }

        $module = $this->moduleNameFromPath($file);

        $nodes = [];
        $edges = [];

        /** @var array<string, string> functionName => nodeId */
        $topLevel = [];

        // Pass 0: collect import aliases for cross-module edge resolution.
        // Handles:
        //   from services.backup import BackupService  → importMap['BackupService'] = 'services.backup'
        //   from services.backup import fn as alias    → importMap['alias'] = 'services.backup'
        //   import db                                   → importMap['db'] = 'db'
        /** @var array<string, string> alias => dotted module path */
        $importMap = $this->collectImportAliases($lines);

        $currentClass = null;
        $currentClassParents = '';
        $classIndent = null;
        $classMethodIndent = null;
        $ignoredScopeIndent = null;
        $functionScopeIndent = null;
        $pendingDecorator = null;
        $pendingPropertyAccessor = null;

        // Pass 1: collect nodes.
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            $indent = $this->indentOf($line);
            $trim = ltrim($line);

            if ($ignoredScopeIndent !== null && trim($line) !== '' && $indent <= $ignoredScopeIndent) {
                $ignoredScopeIndent = null;
            }
            if ($ignoredScopeIndent !== null) {
                continue;
            }
            if ($functionScopeIndent !== null && trim($line) !== '' && $indent <= $functionScopeIndent) {
                $functionScopeIndent = null;
            }
            if ($currentClass !== null && $classIndent !== null && trim($line) !== '' && $indent <= $classIndent) {
                $currentClass = null;
                $currentClassParents = '';
                $classIndent = null;
                $classMethodIndent = null;
            }

            if (preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(([^)]*)\))?\s*:/', $trim, $m)) {
                $isFunctionLocal = $functionScopeIndent !== null && $indent > $functionScopeIndent;
                $isNestedClass = $currentClass !== null && $classIndent !== null && $indent > $classIndent;
                if ($isFunctionLocal || $isNestedClass) {
                    $ignoredScopeIndent = $indent;
                    $pendingDecorator = null;
                    $pendingPropertyAccessor = null;
                    continue;
                }
                $currentClass = $m[1];
                $currentClassParents = $m[2] ?? '';
                $classIndent = $indent;
                $classMethodIndent = null;
                $pendingDecorator = null;
                $pendingPropertyAccessor = null;
                continue;
            }

            // Detect framework decorators (Flask, FastAPI, Click, Typer, Celery)
            if (preg_match('/^@/', $trim)) {
                if ($trim === '@property' || preg_match('/^@[A-Za-z_][A-Za-z0-9_]*\.getter\b/', $trim)) {
                    $pendingPropertyAccessor = 'getter';
                } elseif (preg_match('/^@[A-Za-z_][A-Za-z0-9_]*\.(setter|deleter)\b/', $trim, $propertyMatch)) {
                    $pendingPropertyAccessor = $propertyMatch[1];
                }
                $detected = $this->detectFrameworkDecorator($trim);
                if ($detected !== null) {
                    $pendingDecorator = $detected;
                }
                continue;
            }

            // Detect __main__ script entry block
            if ($indent === 0 && preg_match('/^if\s+__name__\s*==\s*[\'"]__main__[\'"]\s*:/', $trim)) {
                $node = $this->nodeFactory->create($module, '__main__', $file, $lineNo, 'python', [
                    'entrypoint_type' => 'script',
                ]);
                $nodes[] = $node;
                // Not added to $topLevel — synthetic entry block, not a callable function
                continue;
            }

            if (preg_match('/^(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)(?:\s*->\s*(\S+))?\s*:/', $trim, $m)) {
                $fn = $m[1];

                // If indentation is less/equal than class indentation, we're out of the class scope.
                if ($currentClass !== null && $classIndent !== null && $indent <= $classIndent) {
                    $currentClass = null;
                    $currentClassParents = '';
                    $classIndent = null;
                    $classMethodIndent = null;
                }

                if ($ignoredScopeIndent !== null && $indent > $ignoredScopeIndent) {
                    $pendingDecorator = null;
                    $pendingPropertyAccessor = null;
                    continue;
                }

                if ($currentClass !== null && $classIndent !== null && $indent > $classIndent) {
                    $classMethodIndent ??= $indent;
                    if ($indent !== $classMethodIndent) {
                        $pendingDecorator = null;
                        $pendingPropertyAccessor = null;
                        continue;
                    }
                }

                $functionScopeIndent ??= $indent;

                if (in_array($pendingPropertyAccessor, ['setter', 'deleter'], true)) {
                    $pendingDecorator = null;
                    $pendingPropertyAccessor = null;
                    continue;
                }

                $metadata = $pendingDecorator ?? [];
                $pendingDecorator = null;
                $pendingPropertyAccessor = null;

                // Django CBV: HTTP methods in View/ViewSet classes are implicit HTTP entrypoints.
                // Heuristic: class name contains "View"/"ViewSet" OR parents contain a View base class.
                if ($currentClass !== null && $metadata === []) {
                    $isDjangoView = str_contains($currentClass, 'View')
                        || str_contains($currentClass, 'ViewSet')
                        || preg_match('/\b\w*(?:View|ViewSet)\b/', $currentClassParents) === 1;
                    $cbvMethods = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options', 'dispatch'];
                    if ($isDjangoView && in_array($fn, $cbvMethods, true)) {
                        $metadata = [
                            'entrypoint_type' => 'http',
                            'http_method'     => strtoupper($fn),
                        ];
                    }
                }

                $isMethod = ($currentClass !== null && $indent > 0);
                $sigMeta = $this->extractPythonSignature($m[2] ?? '', $m[3] ?? null, $isMethod);
                $metadata = array_merge($metadata, $sigMeta);
                if ($metadata === []) {
                    $metadata = null;
                }

                if ($indent === 0) {
                    $node = $this->nodeFactory->create($module, $fn, $file, $lineNo, 'python', $metadata);
                    $nodes[] = $node;
                    $topLevel[$fn] = $node->id();
                    continue;
                }

                if ($currentClass !== null) {
                    $className = $module . '.' . $currentClass;
                    $node = $this->nodeFactory->create($className, $fn, $file, $lineNo, 'python', $metadata);
                    $nodes[] = $node;
                    continue;
                }
            }

            // Reset pending decorator if line is neither a decorator nor a def
            if ($pendingDecorator !== null && !preg_match('/^\s*@/', $line)) {
                $pendingDecorator = null;
            }
        }

        // Pass 2: collect edges (simple call detection).
        $currentNodeId = null;
        $currentDefIndent = null;
        $currentClass = null;
        $classIndent = null;
        $classMethodIndent = null;
        $ignoredScopeIndent = null;
        $functionScopeIndent = null;
        $pendingPropertyAccessor = null;

        foreach ($lines as $idx => $line) {
            $indent = $this->indentOf($line);
            $trim = ltrim($line);

            if ($ignoredScopeIndent !== null && trim($line) !== '' && $indent <= $ignoredScopeIndent) {
                $ignoredScopeIndent = null;
            }
            if ($ignoredScopeIndent !== null) {
                continue;
            }
            if ($functionScopeIndent !== null && trim($line) !== '' && $indent <= $functionScopeIndent) {
                $functionScopeIndent = null;
            }
            if ($currentClass !== null && $classIndent !== null && trim($line) !== '' && $indent <= $classIndent) {
                $currentClass = null;
                $classIndent = null;
                $classMethodIndent = null;
            }

            if (preg_match('/^@/', $trim)) {
                if ($trim === '@property' || preg_match('/^@[A-Za-z_][A-Za-z0-9_]*\.getter\b/', $trim)) {
                    $pendingPropertyAccessor = 'getter';
                } elseif (preg_match('/^@[A-Za-z_][A-Za-z0-9_]*\.(setter|deleter)\b/', $trim, $propertyMatch)) {
                    $pendingPropertyAccessor = $propertyMatch[1];
                }
                continue;
            }

            if (preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $trim, $m)) {
                $isFunctionLocal = $functionScopeIndent !== null && $indent > $functionScopeIndent;
                $isNestedClass = $currentClass !== null && $classIndent !== null && $indent > $classIndent;
                if ($isFunctionLocal || $isNestedClass) {
                    $ignoredScopeIndent = $indent;
                    $pendingPropertyAccessor = null;
                    continue;
                }
                $currentClass = $m[1];
                $classIndent = $indent;
                $classMethodIndent = null;
                $currentNodeId = null;
                $currentDefIndent = null;
                continue;
            }

            if (preg_match('/^(?:async\s+)?def\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                $fn = $m[1];

                if ($currentNodeId !== null && $currentDefIndent !== null && $indent > $currentDefIndent) {
                    $ignoredScopeIndent = $indent;
                    $pendingPropertyAccessor = null;
                    continue;
                }

                if ($currentClass !== null && $classIndent !== null && $indent <= $classIndent) {
                    $currentClass = null;
                    $classIndent = null;
                    $classMethodIndent = null;
                }

                if ($currentClass !== null && $classIndent !== null && $indent > $classIndent) {
                    $classMethodIndent ??= $indent;
                    if ($indent !== $classMethodIndent) {
                        $ignoredScopeIndent = $indent;
                        $pendingPropertyAccessor = null;
                        continue;
                    }
                }

                $isPropertyMutation = in_array($pendingPropertyAccessor, ['setter', 'deleter'], true);
                $pendingPropertyAccessor = null;

                $currentDefIndent = $indent;
                $functionScopeIndent ??= $indent;

                if ($indent === 0) {
                    $currentNodeId = $topLevel[$fn] ?? null;
                } elseif ($currentClass !== null) {
                    $className = $module . '.' . $currentClass;
                    $tmp = $this->nodeFactory->create($className, $fn, $file, ($idx + 1), 'python');
                    $currentNodeId = $tmp->id();
                } else {
                    $currentNodeId = null;
                }

                if ($isPropertyMutation && $currentClass === null) {
                    $currentNodeId = null;
                }

                continue;
            }

            if ($currentNodeId === null || $currentDefIndent === null) {
                continue;
            }

            // Out of current function body.
            if ($indent <= $currentDefIndent && trim($line) !== '') {
                $currentNodeId = null;
                $currentDefIndent = null;
                continue;
            }

            if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                foreach ($m[1] as $called) {
                    if (isset($topLevel[$called])) {
                        // Intra-file call
                        $edges[] = new Edge(
                            $currentNodeId,
                            $topLevel[$called],
                            $called,
                            'py_call'
                        );
                    } elseif (isset($importMap[$called])) {
                        // Cross-module call — target module is known via import
                        $targetModule = $importMap[$called];
                        $edges[] = new Edge(
                            $currentNodeId,
                            $targetModule . '::' . $called,
                            $called,
                            'py_import_call'
                        );
                    }
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Detect framework-specific Python decorators and return entrypoint metadata.
     *
     * Recognized frameworks:
     *   - FastAPI/Starlette: @router.get("/path"), @app.post("/path")
     *   - Flask: @app.route("/path"), @bp.route("/path", methods=["GET", "POST"])
     *   - Click: @click.command(), @click.group()
     *   - Typer / Click groups: @app.command(), @cli.command("name")
     *   - Celery: @app.task, @shared_task, @celery.task (with or without args)
     *
     * @return array<string, mixed>|null
     */
    private function detectFrameworkDecorator(string $trim): ?array
    {
        // FastAPI / generic HTTP methods: @router.get("/path"), @app.post("/path/{id}")
        if (preg_match('/@\w+\.(get|post|put|delete|patch|head|options)\s*\(\s*["\']([^"\']+)["\']/i', $trim, $m)) {
            return [
                'entrypoint_type' => 'http',
                'http_method'     => strtoupper($m[1]),
                'http_path'       => $m[2],
            ];
        }

        // Flask: @app.route("/path") or @bp.route("/path", methods=["GET", "POST"])
        if (preg_match('/@\w+\.route\s*\(\s*["\']([^"\']+)["\']\s*(?:,\s*methods\s*=\s*\[([^\]]*)\])?/i', $trim, $m)) {
            $rawMethods = $m[2] ?? '';
            if ($rawMethods !== '') {
                preg_match_all('/["\']([A-Za-z]+)["\']/', $rawMethods, $mm);
                $methods = array_values(array_filter(
                    array_map('strtoupper', $mm[1] ?? []),
                    fn($s) => $s !== ''
                ));
                if ($methods === []) {
                    $methods = ['GET'];
                }
            } else {
                $methods = ['GET'];
            }
            return [
                'entrypoint_type' => 'http',
                'http_path'       => $m[1],
                'http_method'     => implode(',', $methods),
            ];
        }

        // Click: @click.command(), @click.group()
        if (preg_match('/@click\.(command|group)\b/i', $trim)) {
            return ['entrypoint_type' => 'cli', 'framework' => 'click'];
        }

        // Typer / Click group commands: @app.command(), @cli.command("name"), @app.command
        if (preg_match('/@\w+\.command\b/i', $trim)) {
            return ['entrypoint_type' => 'cli', 'framework' => 'typer'];
        }

        // Celery: @shared_task, @app.task, @celery.task (with or without parentheses/args)
        if (preg_match('/@(?:shared_task|(?:\w+)\.task)\b/i', $trim)) {
            return ['entrypoint_type' => 'async', 'framework' => 'celery'];
        }

        return null;
    }

    /**
     * Pass 0: scan import lines to build an alias → module map.
     *
     * Supported patterns:
     *   from services.backup import BackupService
     *   from services.backup import BackupService as BS
     *   from services.backup import fn1, fn2
     *   import db
     *   import os.path as osp
     *
     * @param string[] $lines
     * @return array<string, string> alias => dotted module path
     */
    private function collectImportAliases(array $lines): array
    {
        $map = [];

        foreach ($lines as $line) {
            $trim = ltrim($line);

            // `from module import Name [as Alias], Name2 [as Alias2], ...`
            if (preg_match('/^from\s+([\w.]+)\s+import\s+(.+)$/', $trim, $m)) {
                $modulePath = $m[1];
                $imports    = $m[2];

                // Split by comma; handle optional `as alias`
                foreach (explode(',', $imports) as $importPart) {
                    $importPart = trim($importPart);
                    if ($importPart === '' || $importPart === '*') {
                        continue;
                    }

                    if (preg_match('/^(\w+)\s+as\s+(\w+)$/', $importPart, $im)) {
                        // `Name as Alias`
                        $map[$im[2]] = $modulePath;
                    } elseif (preg_match('/^(\w+)$/', $importPart, $im)) {
                        // `Name`
                        $map[$im[1]] = $modulePath;
                    }
                }

                continue;
            }

            // `import module [as alias]`
            if (preg_match('/^import\s+([\w.]+)(?:\s+as\s+(\w+))?/', $trim, $m)) {
                $modulePath = $m[1];
                $alias      = $m[2] ?? $m[1];
                $map[$alias] = $modulePath;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPythonSignature(string $rawParams, ?string $returnType, bool $isMethod): array
    {
        $meta = [];
        $params = [];

        $rawParams = trim($rawParams);
        if ($rawParams !== '') {
            $parts = array_map('trim', explode(',', $rawParams));
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                // Skip self/cls
                $nameOnly = explode(':', $part)[0];
                $nameOnly = explode('=', $nameOnly)[0];
                $nameOnly = trim($nameOnly);
                if ($isMethod && ($nameOnly === 'self' || $nameOnly === 'cls')) {
                    continue;
                }

                $p = ['name' => $nameOnly];
                if (str_contains($part, ':')) {
                    $typeHint = trim(explode(':', $part, 2)[1]);
                    // Remove default value
                    $typeHint = trim(explode('=', $typeHint)[0]);
                    if ($typeHint !== '') {
                        $p['type'] = $typeHint;
                    }
                }
                $params[] = $p;
            }
        }

        if ($params !== []) {
            $meta['params'] = $params;
        }

        if ($returnType !== null) {
            $returnType = rtrim(trim($returnType), ':');
            if ($returnType !== '') {
                $meta['returnType'] = $returnType;
            }
        }

        return $meta;
    }

    private function indentOf(string $line): int
    {
        if (preg_match('/^(\s+)/', $line, $m)) {
            return strlen((string) $m[1]);
        }

        return 0;
    }

    private function moduleNameFromPath(string $file): string
    {
        $root = rtrim($this->projectRoot, DIRECTORY_SEPARATOR);
        $normalizedFile = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);

        $relative = $normalizedFile;
        if (str_starts_with($normalizedFile, $root . DIRECTORY_SEPARATOR)) {
            $relative = substr($normalizedFile, strlen($root . DIRECTORY_SEPARATOR));
        }

        $relative = str_replace(['\\', '/'], '/', $relative);
        $relative = preg_replace('/\.py$/', '', $relative) ?: $relative;

        // Prefer stable module names (path-like -> dotted)
        $relative = trim($relative, '/');
        if ($relative === '') {
            return 'module';
        }

        return str_replace('/', '.', $relative);
    }
}
