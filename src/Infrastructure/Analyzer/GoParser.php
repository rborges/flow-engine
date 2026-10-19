<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\NodeFactory;

/**
 * GoParser (prototype)
 *
 * Minimal, best-effort parser for Go source files:
 * - Collects exported package-level functions and struct methods (uppercase first letter)
 * - Detects net/http and Gin HTTP handler registrations as metadata
 * - Detects intra-package calls between exported functions
 *
 * Node ID pattern:
 *   go:~path~.relative.directory::FuncName
 *   go:~path~.relative.directory.ReceiverType::MethodName
 * Root-level files retain the declared package name.
 */
final class GoParser implements FileParser
{
    public function __construct(
        private readonly NodeFactory $nodeFactory,
        private readonly ?string $projectRoot = null,
    )
    {
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

        $nodes   = [];
        $edges   = [];
        $package = 'main';

        /** @var array<string, string> funcName => nodeId */
        $topLevel = [];

        /**
         * Handler metadata indexed by handler function name.
         * Populated from http.HandleFunc("/path", HandlerName) and Gin routes.
         * This avoids relying on line ordering (registrations are often inside
         * setup functions that appear before the handler definitions).
         *
         * @var array<string, array<string, mixed>>
         */
        $handlerMeta = [];

        // Pass 1: collect nodes.
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            $trim   = trim($line);

            // Package declaration
            if (preg_match('/^package\s+([a-z][a-z0-9_]*)/', $trim, $m)) {
                $package = $m[1];
                continue;
            }

            // net/http handler registration: http.HandleFunc("/path", HandlerName)
            // Extract the handler name from the second argument so we can associate
            // metadata even when the handler is defined after the registration.
            if (preg_match(
                '/\bhttp\.HandleFunc\s*\(\s*["\']([^"\']+)["\']\s*,\s*([A-Z][A-Za-z0-9_]*)\s*\)/',
                $trim,
                $m
            )) {
                $handlerMeta[$m[2]] = ['http_path' => $m[1]];
                continue;
            }

            // Gin route: r.GET("/path", HandlerName) — extract handler name
            if (preg_match(
                '/\.\s*(GET|POST|PUT|DELETE|PATCH)\s*\(\s*["\']([^"\']+)["\']\s*,\s*([A-Z][A-Za-z0-9_]*)\s*\)/',
                $trim,
                $m
            )) {
                $handlerMeta[$m[3]] = [
                    'http_method' => $m[1],
                    'http_path'   => $m[2],
                ];
                continue;
            }

            // Method: func (r *ReceiverType) MethodName(
            if (preg_match('/^func\s+\(\w+\s+\*?([A-Za-z0-9_]+)\)\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                $receiver   = $m[1];
                $methodName = $m[2];
                $className  = $this->moduleName($file, $package) . '.' . $receiver;
                $metadata   = $handlerMeta[$methodName] ?? null;

                $node    = $this->nodeFactory->create($className, $methodName, $file, $lineNo, 'go', $metadata);
                $nodes[] = $node;
                continue;
            }

            // Exported package function: func FuncName(
            if (preg_match('/^func\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                $funcName = $m[1];
                $metadata = $handlerMeta[$funcName] ?? null;

                $node    = $this->nodeFactory->create($this->moduleName($file, $package), $funcName, $file, $lineNo, 'go', $metadata);
                $nodes[] = $node;
                $topLevel[$funcName] = $node->id();
                continue;
            }
        }

        // Pass 2: collect edges (calls to known exported package functions).
        $currentNodeId = null;
        $funcDepth     = 0;
        $inFunc        = false;
        $package       = 'main';

        foreach ($lines as $idx => $line) {
            $trim = trim($line);

            // Re-detect package (for robustness in pass 2)
            if (preg_match('/^package\s+([a-z][a-z0-9_]*)/', $trim, $m)) {
                $package = $m[1];
                continue;
            }

            // Track brace depth for function body scope
            $funcDepth += substr_count($line, '{') - substr_count($line, '}');

            // Method
            if (preg_match('/^func\s+\(\w+\s+\*?([A-Za-z0-9_]+)\)\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                $className     = $this->moduleName($file, $package) . '.' . $m[1];
                $tmp           = $this->nodeFactory->create($className, $m[2], $file, ($idx + 1), 'go');
                $currentNodeId = $tmp->id();
                $inFunc        = true;
                $funcDepth     = 0;
                continue;
            }

            // Exported function
            if (preg_match('/^func\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $trim, $m)) {
                $currentNodeId = $topLevel[$m[1]] ?? null;
                $inFunc        = true;
                $funcDepth     = 0;
                continue;
            }

            if (!$inFunc || $currentNodeId === null) {
                continue;
            }

            // Look for calls to known exported functions: FuncName(
            if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\s*\(/', $trim, $allM)) {
                foreach ($allM[1] as $called) {
                    if (isset($topLevel[$called]) && $topLevel[$called] !== $currentNodeId) {
                        $edges[] = new Edge($currentNodeId, $topLevel[$called], $called, 'go_call');
                    }
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function moduleName(string $file, string $declaredPackage): string
    {
        if ($this->projectRoot === null) {
            return $declaredPackage;
        }

        $root = str_replace('\\', '/', rtrim(realpath($this->projectRoot) ?: $this->projectRoot, '/\\'));
        $path = str_replace('\\', '/', realpath($file) ?: $file);

        if (!str_starts_with($path, $root . '/')) {
            return $declaredPackage;
        }

        $relativeDirectory = dirname(substr($path, strlen($root) + 1));
        if ($relativeDirectory === '.' || $relativeDirectory === '') {
            return $declaredPackage;
        }

        $segments = array_values(array_filter(explode('/', $relativeDirectory), static fn(string $segment): bool => $segment !== ''));
        $segments = array_map(self::encodeIdentifier(...), $segments);
        $module = '~path~.' . implode('.', $segments);
        $directoryPackage = basename($relativeDirectory);

        return $declaredPackage === $directoryPackage
            ? $module
            : $module . '@' . self::encodeIdentifier($declaredPackage);
    }

    private static function encodeIdentifier(string $value): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9]/',
            static fn(array $match): string => sprintf('_%02X', ord($match[0])),
            $value
        ) ?: '_';
    }
}
