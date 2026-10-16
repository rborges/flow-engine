<?php

namespace FlowEngine\Application\AppMap;

final class ApplicationMapBuilder
{
    private IntegrationDetector $detector;

    public function __construct(?IntegrationDetector $detector = null)
    {
        $this->detector = $detector ?? new IntegrationDetector();
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

                $edges[] = array_merge($call->toArray(), [
                    'fromService' => $service->name,
                    'toService' => $toService,
                ]);
            }
        }

        // Service-level dependency graph
        $serviceEdges = [];
        foreach ($edges as $e) {
            if (!isset($e['toService']) || $e['toService'] === null) {
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

        $serviceArrays = array_map(function (ServiceInfo $s) use (&$inconsistencies): array {
            $arr = $s->toArray();

            $codeEndpoints = [];
            foreach ($s->flow->nodes() as $node) {
                $meta = $node->metadata();
                if ($meta !== null && isset($meta['http_method'], $meta['http_path'])) {
                    $codeEndpoints[] = [
                        'method'  => $meta['http_method'],
                        'path'    => $meta['http_path'],
                        'handler' => $node->id(),
                    ];
                }
            }
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

            $warnings[] = [
                'type' => 'EMPTY_SERVICE',
                'service' => $service->name,
                'message' => $message . '. Check scan.include and scan.extensions in its flow-engine.json.',
            ];
        }

        return $warnings;
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
