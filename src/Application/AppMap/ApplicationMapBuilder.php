<?php

namespace FlowEngine\Application\AppMap;

final class ApplicationMapBuilder
{
    private const TRAILING_PARAM = '{/}';

    private IntegrationDetector $detector;
    private LaravelRouteCollector $laravelRoutes;
    /** @var array<string, array<string, string>> */
    private array $fileNodes = [];

    public function __construct(?IntegrationDetector $detector = null, ?LaravelRouteCollector $laravelRoutes = null)
    {
        $this->detector = $detector ?? new IntegrationDetector();
        $this->laravelRoutes = $laravelRoutes ?? new LaravelRouteCollector();
    }

    /**
     * @param ServiceInfo[] $services
     * @return array<string, mixed>
     */
    public function build(array $services): array
    {
        $serviceByRoot = [];
        $fileIndex = [];
        $hostnameToService = [];

        foreach ($services as $service) {
            $serviceByRoot[rtrim($service->root, DIRECTORY_SEPARATOR)] = $service;
            foreach ($service->files as $file) {
                $fileIndex[realpath($file) ?: $file] = $service->name;
            }
            foreach ($service->hostnames as $hostname) {
                $hostnameToService[strtolower($hostname)] = $service->name;
            }
        }

        $this->fileNodes = [];
        $endpoints = [];
        $serviceByName = [];
        foreach ($services as $service) {
            $endpoints[$service->name] = $this->endpoints($service);
            $serviceByName[$service->name] = $service;
        }

        $edges = [];
        $inconsistencies = [];

        foreach ($services as $service) {
            $calls = $this->detector->detect($service->flow, $service->root, $service->files);

            foreach ($calls as $call) {
                $toService = null;

                if ($call->type === 'script' && $call->resolvedPath !== null) {
                    $resolved = realpath($call->resolvedPath) ?: $call->resolvedPath;

                    // Map to service by "file belongs to scanned set" first.
                    if (isset($fileIndex[$resolved])) {
                        $toService = $fileIndex[$resolved];
                    } else {
                        // Fallback: map by prefix root.
                        foreach ($serviceByRoot as $root => $targetService) {
                            if (str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || $resolved === $root) {
                                $toService = $targetService->name;
                                break;
                            }
                        }
                    }

                    if (!file_exists($resolved)) {
                        $inconsistencies[] = [
                            'type' => 'SCRIPT_NOT_FOUND',
                            'severity' => 'high',
                            'message' => "Python script not found: {$resolved}",
                            'fromService' => $service->name,
                            'fromNode' => $call->fromNodeId,
                            'target' => $call->target,
                            'resolvedPath' => $call->resolvedPath,
                        ];
                    } elseif ($toService !== null) {
                        // If target service is known, but it didn't scan this file, flag it.
                        if (!isset($fileIndex[$resolved])) {
                            $inconsistencies[] = [
                                'type' => 'SCRIPT_NOT_SCANNED',
                                'severity' => 'medium',
                                'message' => "Script exists but is not included in target service scan: {$resolved}",
                                'fromService' => $service->name,
                                'fromNode' => $call->fromNodeId,
                                'toService' => $toService,
                                'resolvedPath' => $call->resolvedPath,
                            ];
                        }
                    }
                }

                if ($call->type === 'http' && $toService === null) {
                    $host = strtolower($call->metadata['host'] ?? '');
                    $port = (int) ($call->metadata['port'] ?? 0);

                    if ($host !== '') {
                        $toService = $hostnameToService[$host . ':' . $port]
                                  ?? $hostnameToService[$host]
                                  ?? null;
                    }
                }

                $toNode = null;
                if ($call->type === 'http' && $toService !== null && isset($serviceByName[$toService])) {
                    $toNode = $this->matchEndpoint($call->metadata, $endpoints[$toService])
                        ?? $this->matchLegacyFile($call->metadata, $serviceByName[$toService]);
                }

                $edges[] = array_merge($call->toArray(), [
                    'fromService' => $service->name,
                    'toService' => $toService,
                    'toNode' => $toNode,
                ]);
            }
        }

        // Service-level dependency graph
        $serviceEdges = [];
        foreach ($edges as $e) {
            // Asset servido por outro serviço não é dependência entre serviços.
            if (!isset($e['toService']) || $e['toService'] === null || ($e['metadata']['kind'] ?? null) === 'asset') {
                continue;
            }

            $key = $e['fromService'] . '->' . $e['toService'] . ':' . $e['type'];
            $serviceEdges[$key] ??= [
                'from' => $e['fromService'],
                'to' => $e['toService'],
                'type' => $e['type'],
                'count' => 0,
            ];

            $serviceEdges[$key]['count']++;
        }

        $serviceArrays = array_map(function (ServiceInfo $s) use (&$inconsistencies, $endpoints): array {
            $arr = $s->toArray();

            $codeEndpoints = $endpoints[$s->name];
            $arr['endpoints'] = $codeEndpoints;

            // Serviço sem análise não tem endpoints de código: comparar com o contrato só gera falso positivo.
            if ($s->contractEndpoints !== null && !$s->failedAnalysis()) {
                $arr['contract'] = ['endpointCount' => count($s->contractEndpoints)];
                foreach ($this->checkContractConsistency($codeEndpoints, $s->contractEndpoints, $s->name) as $issue) {
                    $inconsistencies[] = $issue;
                }
            }

            return $arr;
        }, $services);

        return [
            'generatedAt'      => date('c'),
            'services'         => $serviceArrays,
            'integrationEdges' => $edges,
            'serviceEdges'     => array_values($serviceEdges),
            'inconsistencies'  => $inconsistencies,
            'warnings'         => $this->serviceWarnings($services),
        ];
    }

    /**
     * @param ServiceInfo[] $services
     * @return array<int, array{type: string, service: string, message: string}>
     */
    private function serviceWarnings(array $services): array
    {
        $warnings = [];

        foreach ($services as $service) {
            if ($service->failedAnalysis()) {
                $warnings[] = [
                    'type' => 'SERVICE_FAILED',
                    'service' => $service->name,
                    'message' => 'analysis failed: ' . $service->error,
                ];
                continue;
            }

            if ($service->flow->nodeCount() > 0) {
                continue;
            }

            $message = 'analyzed with 0 nodes';
            if ($service->configResolution !== null) {
                $message .= sprintf(
                    ' (context: %s, extensions: %s)',
                    $service->configResolution['detectedContext'] ?? 'unknown',
                    implode(', ', $service->configResolution['extensions'] ?? []) ?: 'none'
                );
            }

            $hint = ($service->configResolution['hasConfigFile'] ?? true)
                ? 'Check scan.include and scan.extensions in its flow-engine.json.'
                : 'No flow-engine.json was found; run init on the service to configure its scan.';

            $warnings[] = [
                'type' => 'EMPTY_SERVICE',
                'service' => $service->name,
                'message' => $message . '. ' . $hint,
            ];
        }

        return $warnings;
    }

    /**
     * Rotas dos nós (`http_method`/`http_path`) mais as rotas Laravel, sem repetir método e caminho.
     *
     * @return array<int, array{method: string, path: string, handler: string}>
     */
    private function endpoints(ServiceInfo $service): array
    {
        if ($service->failedAnalysis()) {
            return [];
        }

        $endpoints = [];
        foreach ($service->flow->nodes() as $node) {
            $meta = $node->metadata();
            if ($meta !== null && isset($meta['http_method'], $meta['http_path'])) {
                $endpoints[] = [
                    'method'  => $meta['http_method'],
                    'path'    => $meta['http_path'],
                    'handler' => $node->id(),
                ];
            }
        }

        $seen = [];
        foreach ($endpoints as $ep) {
            $seen[strtoupper($ep['method']) . ' ' . $ep['path']] = true;
        }
        foreach ($this->laravelRoutes->collect($service->root) as $route) {
            if (!isset($seen[$route['method'] . ' ' . $route['path']])) {
                $endpoints[] = $route;
            }
        }

        return $endpoints;
    }

    /**
     * Handler do endpoint cujo caminho casa com o da chamada; entre vários, o de mais segmentos literais.
     *
     * URL sem caminho (ou só `/`) não casa: quase sempre é link ou base de concatenação, não a rota raiz.
     *
     * @param array<string, mixed> $metadata
     * @param array<int, array{method: string, path: string, handler: string}> $endpoints
     */
    private function matchEndpoint(array $metadata, array $endpoints): ?string
    {
        $path = (string) ($metadata['path'] ?? '');
        $call = self::segments($path);
        if ($call === []) {
            return null;
        }
        $method = isset($metadata['httpMethod']) ? strtoupper((string) $metadata['httpMethod']) : null;

        // `/propostas/` costuma ser `'/propostas/' . $id`: com barra final, tenta primeiro um parâmetro a mais.
        if (str_ends_with($path, '/')) {
            $handler = $this->bestEndpoint([...$call, self::TRAILING_PARAM], $method, $endpoints);
            if ($handler !== null) {
                return $handler;
            }
        }

        return $this->bestEndpoint($call, $method, $endpoints);
    }

    /**
     * @param string[] $call
     * @param array<int, array{method: string, path: string, handler: string}> $endpoints
     */
    private function bestEndpoint(array $call, ?string $method, array $endpoints): ?string
    {
        $best = null;
        $bestScore = -1;
        foreach ($endpoints as $ep) {
            $epMethod = strtoupper($ep['method']);
            if ($method !== null && $epMethod !== $method && $epMethod !== 'ANY') {
                continue;
            }
            $score = self::pathScore($call, self::segments($ep['path']));
            if ($score !== null && $score > $bestScore) {
                $best = $ep['handler'];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Literal igual a literal (sem distinguir maiúsculas) vale 2; segmento montado em tempo de execução
     * (`${x}`, `{x}`) contra `{p}` vale 1 e contra literal, 0; literal contra `{p}`, 0. `{p?}` pode faltar no fim.
     * O parâmetro da barra final só casa com `{p}`: assim uma URL base `/api/` não cai numa rota literal.
     *
     * @param string[] $call
     * @param string[] $route
     */
    private static function pathScore(array $call, array $route): ?int
    {
        if (count($call) > count($route)) {
            return null;
        }
        $score = 0;
        foreach ($route as $i => $segment) {
            $isParam = str_starts_with($segment, '{') && str_ends_with($segment, '}');
            if (!isset($call[$i])) {
                if (!str_ends_with($segment, '?}')) {
                    return null;
                }
                continue;
            }
            $runtime = str_contains($call[$i], '{');
            if ($call[$i] === self::TRAILING_PARAM && !$isParam) {
                return null;
            }
            if ($runtime) {
                $score += $isParam ? 1 : 0;
                continue;
            }
            if ($isParam) {
                continue;
            }
            if (strcasecmp($segment, $call[$i]) !== 0) {
                return null;
            }
            $score += 2;
        }

        return $score;
    }

    /**
     * PHP sem roteador: `/pasta/arquivo.php` casa com o nó-arquivo do destino que termina nesse caminho.
     *
     * @param array<string, mixed> $metadata
     */
    private function matchLegacyFile(array $metadata, ServiceInfo $target): ?string
    {
        $path = trim((string) ($metadata['path'] ?? ''), '/');
        if (!str_ends_with(strtolower($path), '.php')) {
            return null;
        }

        $this->fileNodes[$target->name] ??= $this->fileNodeIndex($target);
        $path = strtolower($path);
        $best = null;
        foreach ($this->fileNodes[$target->name] as $relative => $id) {
            if (($relative === $path || str_ends_with($relative, '/' . $path)) && ($best === null || strlen($id) < strlen($best))) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * @return array<string, string> caminho relativo em minúsculas, com `/` → id do nó-arquivo
     */
    private function fileNodeIndex(ServiceInfo $service): array
    {
        $index = [];
        foreach ($service->flow->nodes() as $node) {
            $id = $node->id();
            if (str_starts_with($id, 'file:') && str_ends_with(strtolower($id), '.php')) {
                $index[strtolower(str_replace('\\', '/', substr($id, 5)))] = $id;
            }
        }

        return $index;
    }

    /**
     * @return string[]
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    // -------------------------------------------------------------------------
    // Contract consistency
    // -------------------------------------------------------------------------

    /**
     * Compare code-detected endpoints against OpenAPI contract endpoints and
     * return inconsistency records for any mismatches.
     *
     * @param array<int, array{method: string, path: string, handler: string}> $codeEndpoints
     * @param array<int, array{method: string, path: string, summary: string}> $contractEndpoints
     * @return array<int, array<string, mixed>>
     */
    private function checkContractConsistency(
        array $codeEndpoints,
        array $contractEndpoints,
        string $serviceName
    ): array {
        $normalize = static function (string $path): string {
            // Normalise {param} and :param path segments to {*} for comparison.
            $path = (string) preg_replace('/\{[^}]+\}/', '{*}', $path);
            $path = (string) preg_replace('/(?<=\/):[A-Za-z_][A-Za-z0-9_]*/', '{*}', $path);
            return strtolower(rtrim($path, '/') ?: '/');
        };

        $codeSet = [];
        $codeDuplicates = [];
        $codeMethodsByPath = [];
        foreach ($codeEndpoints as $ep) {
            $key = strtoupper($ep['method']) . ':' . $normalize($ep['path']);
            if (isset($codeSet[$key])) {
                $codeDuplicates[$key] = true;
            }
            $codeSet[$key] = $ep;
            $pathKey = $normalize($ep['path']);
            $method = strtoupper($ep['method']);
            $codeMethodsByPath[$pathKey][$method] = true;
        }

        $contractSet = [];
        $contractDuplicates = [];
        $contractMethodsByPath = [];
        foreach ($contractEndpoints as $ep) {
            $key = strtoupper($ep['method']) . ':' . $normalize($ep['path']);
            if (isset($contractSet[$key])) {
                $contractDuplicates[$key] = true;
            }
            $contractSet[$key] = $ep;
            $pathKey = $normalize($ep['path']);
            $method = strtoupper($ep['method']);
            $contractMethodsByPath[$pathKey][$method] = true;
        }

        $inconsistencies = [];

        foreach (array_keys($contractDuplicates) as $key) {
            $ep = $contractSet[$key];
            $inconsistencies[] = [
                'type'     => 'CONTRACT_DUPLICATE_ENDPOINT',
                'severity' => 'medium',
                'message'  => "Contract declares duplicate operation {$ep['method']} {$ep['path']}",
                'service'  => $serviceName,
                'method'   => $ep['method'],
                'path'     => $ep['path'],
            ];
        }

        foreach (array_keys($codeDuplicates) as $key) {
            $ep = $codeSet[$key];
            $inconsistencies[] = [
                'type'     => 'CODE_DUPLICATE_ENDPOINT',
                'severity' => 'high',
                'message'  => "Code has multiple handlers for {$ep['method']} {$ep['path']}",
                'service'  => $serviceName,
                'method'   => $ep['method'],
                'path'     => $ep['path'],
                'handler'  => $ep['handler'],
            ];
        }

        foreach ($contractMethodsByPath as $path => $methods) {
            if (!isset($codeMethodsByPath[$path])) {
                continue;
            }

            $contractMethods = array_keys($methods);
            sort($contractMethods);
            $codeMethods = array_keys($codeMethodsByPath[$path]);
            sort($codeMethods);

            if ($contractMethods !== $codeMethods) {
                $inconsistencies[] = [
                    'type'     => 'CONTRACT_METHOD_SET_MISMATCH',
                    'severity' => 'medium',
                    'message'  => "Route {$path} methods differ between contract and code",
                    'service'  => $serviceName,
                    'path'     => $path,
                    'contractMethods' => $contractMethods,
                    'codeMethods' => $codeMethods,
                ];
            }
        }

        foreach ($contractSet as $key => $ep) {
            if (!isset($codeSet[$key])) {
                $inconsistencies[] = [
                    'type'     => 'CONTRACT_ENDPOINT_NOT_IN_CODE',
                    'severity' => 'high',
                    'message'  => "Contract declares {$ep['method']} {$ep['path']} but no matching route found in code",
                    'service'  => $serviceName,
                    'method'   => $ep['method'],
                    'path'     => $ep['path'],
                ];
            }
        }

        foreach ($codeSet as $key => $ep) {
            if (!isset($contractSet[$key])) {
                $inconsistencies[] = [
                    'type'     => 'CODE_ENDPOINT_NOT_IN_CONTRACT',
                    'severity' => 'medium',
                    'message'  => "Code exposes {$ep['method']} {$ep['path']} but it is not declared in the contract",
                    'service'  => $serviceName,
                    'method'   => $ep['method'],
                    'path'     => $ep['path'],
                    'handler'  => $ep['handler'],
                ];
            }
        }

        return $inconsistencies;
    }
}
