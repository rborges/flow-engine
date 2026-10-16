<?php

namespace FlowEngine\Application\AppMap;

use FlowEngine\Domain\Contracts\Flow;

final class IntegrationDetector
{
    private NodeLocator $locator;
    private CommentStripper $commentStripper;
    private UrlClassifier $urlClassifier;

    public function __construct(?NodeLocator $locator = null)
    {
        $this->locator = $locator ?? new NodeLocator();
        $this->commentStripper = new CommentStripper();
        $this->urlClassifier = new UrlClassifier();
    }

    /**
     * @param string   $projectRoot Absolute project root
     * @param string[] $files Absolute file paths (scanned)
     *
     * @return IntegrationCall[]
     */
    public function detect(Flow $flow, string $projectRoot, array $files): array
    {
        $calls = [];

        foreach ($files as $file) {
            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, ['php', 'dart'], true)) {
                continue;
            }

            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $content = $this->commentStripper->strip($content, $extension);

            $lines = preg_split("/\r\n|\n|\r/", $content);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $idx => $line) {
                $lineNo = $idx + 1;

                // script calls (best-effort): exec/system/shell_exec/passthru/proc_open with a string literal
                if ($extension === 'php' && preg_match('/\b(exec|shell_exec|system|passthru|proc_open)\s*\(\s*([\'"])(.+?)\\2/s', $line, $m)) {
                    $cmd = (string) $m[3];
                    $script = $this->extractPythonScript($cmd);

                    if ($script !== null) {
                        $fromNode = $this->locator->locate($flow, $file, $lineNo) ?? '::unknown';
                        $resolved = $this->resolvePath($script, $projectRoot, dirname($file));

                        $calls[] = new IntegrationCall(
                            type: 'script',
                            fromNodeId: $fromNode,
                            fromFile: $file,
                            fromLine: $lineNo,
                            target: $script,
                            resolvedPath: $resolved,
                            metadata: [
                                'command' => $cmd,
                            ]
                        );
                    }
                }

                // http calls (very naive): any URL literal in the line
                if (preg_match_all('/https?:\\/\\/[^\\s\'"<>]+/i', $line, $um)) {
                    foreach (($um[0] ?? []) as $url) {
                        $url = (string) $url;
                        $fromNode = $this->locator->locate($flow, $file, $lineNo) ?? '::unknown';
                        $parts = parse_url($url);

                        $metadata = [
                            'scheme' => (string) ($parts['scheme'] ?? ''),
                            'host' => (string) ($parts['host'] ?? ''),
                            'port' => (int) ($parts['port'] ?? 0),
                            'path' => (string) ($parts['path'] ?? ''),
                            'query' => (string) ($parts['query'] ?? ''),
                        ];
                        $metadata['kind'] = $this->urlClassifier->kind($metadata['host'], $metadata['path']);

                        $calls[] = new IntegrationCall(
                            type: 'http',
                            fromNodeId: $fromNode,
                            fromFile: $file,
                            fromLine: $lineNo,
                            target: $url,
                            resolvedPath: null,
                            metadata: $metadata
                        );
                    }
                }

                if ($extension === 'dart') {
                    foreach ($this->detectDartHttpTargets($line, $projectRoot) as $target) {
                        $fromNode = $this->locator->locate($flow, $file, $lineNo) ?? '::unknown';
                        $parts = parse_url($target['url']);

                        $calls[] = new IntegrationCall(
                            type: 'http',
                            fromNodeId: $fromNode,
                            fromFile: $file,
                            fromLine: $lineNo,
                            target: $target['url'],
                            resolvedPath: null,
                            metadata: [
                                'scheme' => (string) ($parts['scheme'] ?? ''),
                                'host' => (string) ($parts['host'] ?? ''),
                                'port' => (int) ($parts['port'] ?? 0),
                                'path' => (string) ($parts['path'] ?? ''),
                                'query' => (string) ($parts['query'] ?? ''),
                                'httpMethod' => $target['method'],
                                'kind' => $this->urlClassifier->kind((string) ($parts['host'] ?? ''), (string) ($parts['path'] ?? '')),
                            ]
                        );
                    }
                }
            }
        }

        return $calls;
    }

    private function extractPythonScript(string $cmd): ?string
    {
        // Matches: python3 path/to/script.py ... OR just path/to/script.py
        if (preg_match('/(?:\\bpython(?:\\d+(?:\\.\\d+)*)?\\b\\s+)?([^\\s\'"]+\\.py)\\b/i', $cmd, $m)) {
            return (string) $m[1];
        }

        return null;
    }

    private function resolvePath(string $path, string $projectRoot, string $baseDir): ?string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);

        // Absolute path
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            $real = realpath($path);
            return $real !== false ? $real : null;
        }

        // Relative to file dir, then project root
        $cand1 = realpath($baseDir . DIRECTORY_SEPARATOR . $path);
        if ($cand1 !== false) {
            return $cand1;
        }

        $cand2 = realpath(rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path);
        if ($cand2 !== false) {
            return $cand2;
        }

        return null;
    }

    /**
     * @return array<int, array{method: string, url: string}>
     */
    private function detectDartHttpTargets(string $line, string $projectRoot): array
    {
        if (!preg_match_all('/\.\s*(get|post|put|patch|delete)\s*\(\s*([^,\)]+)/i', $line, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $constants = $this->loadDartApiConstants($projectRoot);
        $targets = [];

        foreach ($matches as $match) {
            $method = strtoupper($match[1]);
            $url = $this->resolveDartHttpExpression(trim($match[2]), $constants);
            if ($url === null) {
                continue;
            }

            $targets[] = [
                'method' => $method,
                'url' => $url,
            ];
        }

        return $targets;
    }

    /**
     * @param array<string, string> $constants
     */
    private function resolveDartHttpExpression(string $expression, array $constants): ?string
    {
        $expr = trim(rtrim($expression, ';'));
        $expr = preg_replace_callback(
            '/\$\{ApiConstants\.([A-Za-z0-9_]+)\}|ApiConstants\.([A-Za-z0-9_]+)/',
            static function (array $matches) use ($constants): string {
                $key = $matches[1] !== '' ? $matches[1] : $matches[2];
                return $constants[$key] ?? $matches[0];
            },
            $expr
        ) ?? $expr;

        $expr = trim($expr, "\"'");
        if ($expr === '') {
            return null;
        }

        $expr = preg_replace('/\$\{[^}]+\}/', '{*}', $expr) ?? $expr;
        $expr = preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*/', '{*}', $expr) ?? $expr;

        if (preg_match('/^https?:\/\//i', $expr)) {
            return $expr;
        }

        if (preg_match('/^\/[A-Za-z0-9_\/\-\{\}\.\*]+$/', $expr)) {
            return 'http://localhost' . $expr;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function loadDartApiConstants(string $projectRoot): array
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'constants' . DIRECTORY_SEPARATOR . 'api_constants.dart';
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return [];
        }

        $baseUrl = '';
        if (preg_match('/static\s+const\s+String\s+baseUrl\s*=\s*String\.fromEnvironment\([^)]*defaultValue:\s*[\'"]([^\'"]+)[\'"]/s', $content, $match)) {
            $baseUrl = $match[1];
        }

        $constants = [];
        if (!preg_match_all('/static\s+const\s+String\s+([A-Za-z0-9_]+)\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches, \PREG_SET_ORDER)) {
            return $constants;
        }

        foreach ($matches as $match) {
            $value = $match[2];
            if ($baseUrl !== '' && str_contains($value, '$baseUrl')) {
                $value = str_replace('$baseUrl', $baseUrl, $value);
            }
            $constants[$match[1]] = $value;
        }

        return $constants;
    }
}
