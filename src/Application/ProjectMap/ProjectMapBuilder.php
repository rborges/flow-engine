<?php

namespace FlowEngine\Application\ProjectMap;

use FlowEngine\Application\AppMap\ServiceInfo;
use FlowEngine\Application\DTO\ProjectMapDTO;
use FlowEngine\Domain\Analysis\ScriptEntrypointClassifier;
use FlowEngine\Domain\Contracts\Flow as FlowContract;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\FlowTracer;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\TraceDirection;

final class ProjectMapBuilder
{
    /**
     * @param array<string, mixed> $capabilities
     * @param string[] $extraWarnings  Extra warnings to prepend (e.g. include_hotspots deprecation).
     */
    public function buildForProject(string $projectRoot, FlowContract $flow, array $capabilities, string $mode = 'summary', int $depth = 1, array $extraWarnings = []): ProjectMapDTO
    {
        $depth = max(1, $depth);
        $mode = $mode === 'full' ? 'full' : 'summary';
        $tracer = new FlowTracer($flow);
        $entrypoints = $this->actionableEntrypoints($flow);
        $entrypointIds = array_fill_keys(array_map(static fn(Node $node): string => $node->id(), $entrypoints), true);
        $areaSummaries = $this->buildAreaSummaries($flow, $entrypointIds, $depth, $mode);
        $entrypointSummaries = $this->buildEntrypointSummaries($entrypoints, $depth, $mode);
        $warnings = [];
        $infraMapHint = 'If this project is mostly Docker, proxy config, scripts, or static files, call flow_infra_map with the same project path.';

        if ($flow->nodeCount() === 0) {
            $warnings[] = 'No analyzable nodes were found in the project.';
            $warnings[] = $infraMapHint;
        }

        if ($entrypoints === []) {
            $warnings[] = 'No actionable entrypoints were detected. Use areas and boundaries to choose the next lookup.';
            $warnings[] = $infraMapHint;
        }

        $warnings = array_values(array_unique(array_merge($extraWarnings, $warnings, $this->capabilityWarnings($capabilities))));

        // Summary: trim heavy fields, omit capabilities
        if ($mode === 'summary') {
            $areaSummariesTruncated = array_slice($areaSummaries, 0, 5);
            $entrypointSummariesAll = $entrypointSummaries;
            $recommendedReads = array_slice($this->recommendedReadsForProject($entrypoints, $areaSummaries, $depth, $mode), 0, 5);

            return new ProjectMapDTO(
                kind: 'project_map',
                scope: 'single',
                mode: $mode,
                purpose: $this->purposePayloadSummary(),
                project: [
                    'name' => basename(rtrim($projectRoot, DIRECTORY_SEPARATOR)),
                    'root' => $projectRoot,
                    'languages' => $capabilities['detectedProjectLanguages'] ?? $this->projectLanguages($flow),
                    'detectedFrameworks' => $this->projectFrameworks($flow, $projectRoot),
                ],
                capabilities: [],
                structure: [
                    'areas' => $areaSummariesTruncated,
                    'entrypoints' => array_slice($entrypointSummariesAll, 0, 10),
                ],
                navigation: [
                    'recommendedReads' => $recommendedReads,
                    'nextStep' => $entrypoints !== []
                        ? 'Call flow_lookup on an entrypoint or area before opening code files.'
                        : 'Call flow_infra_map for infrastructure/files or flow_lookup on an area/boundary for code.',
                ],
                warnings: $warnings,
            );
        }

        // Full mode: include everything
        $boundarySummaries = $this->buildSingleProjectBoundaries($flow, $entrypoints, $depth, $mode);
        $criticalPaths = $this->buildSingleProjectPaths($entrypoints, $tracer, $depth, $mode);
        $integrationPoints = $this->buildSingleProjectIntegrationPoints($flow, $depth, $mode);

        return new ProjectMapDTO(
            kind: 'project_map',
            scope: 'single',
            mode: $mode,
            purpose: $this->purposePayload(),
            project: [
                'name' => basename(rtrim($projectRoot, DIRECTORY_SEPARATOR)),
                'root' => $projectRoot,
                'analysisTimestamp' => date('c'),
                'languages' => $capabilities['detectedProjectLanguages'] ?? $this->projectLanguages($flow),
                'detectedFrameworks' => $this->projectFrameworks($flow, $projectRoot),
            ],
            capabilities: $capabilities,
            structure: [
                'areas' => $areaSummaries,
                'entrypoints' => $entrypointSummaries,
                'boundaries' => $boundarySummaries,
                'criticalPaths' => $criticalPaths,
                'services' => [],
                'integrationPoints' => $integrationPoints,
            ],
            navigation: [
                'recommendedReads' => $this->recommendedReadsForProject($entrypoints, $areaSummaries, $depth, $mode),
                'recommendedLookupTargets' => $this->recommendedLookupsForProject($entrypoints, $areaSummaries, $criticalPaths),
                'nextStep' => $entrypoints !== []
                    ? 'Call flow_lookup on an entrypoint or area before opening code files.'
                    : 'Call flow_infra_map for infrastructure/files or flow_lookup on an area/boundary for code.',
            ],
            warnings: $warnings,
        );
    }

    /**
     * @param array<string, mixed> $appmap
     * @param ServiceInfo[] $services
     * @param array<string, mixed> $capabilities
     */
    public function buildForCatalog(string $catalogPath, array $appmap, array $services, array $capabilities, string $mode = 'summary', int $depth = 1, array $extraWarnings = []): ProjectMapDTO
    {
        $depth = max(1, $depth);
        $mode = $mode === 'full' ? 'full' : 'summary';
        $serviceAreas = $this->buildServiceAreas($services, $depth, $mode);
        $serviceEntries = $this->buildServiceEntries($services, $depth, $mode);
        $entrypoints = $this->buildCatalogEntrypoints($services, $depth, $mode);
        $boundaries = $this->buildCatalogBoundaries($services, $appmap, $depth, $mode);
        $criticalPaths = $this->buildCatalogCriticalPaths($appmap, $depth, $mode);
        $integrationPoints = $this->buildCatalogIntegrationPoints($appmap, $depth, $mode);
        $warnings = [];

        if (count($services) === 0) {
            $warnings[] = 'Catalog did not resolve to any analyzable services.';
        }

        if (($appmap['inconsistencies'] ?? []) !== []) {
            $warnings[] = 'Catalog contains integration inconsistencies. Review integrationPoints before trusting cross-service flow.';
        }

        $warnings = array_values(array_unique(array_merge($extraWarnings, $warnings, $this->capabilityWarnings($capabilities))));

        return new ProjectMapDTO(
            kind: 'project_map',
            scope: 'catalog',
            mode: $mode,
            purpose: $this->purposePayload(),
            project: [
                'name' => basename($catalogPath),
                'root' => dirname($catalogPath),
                'analysisTimestamp' => date('c'),
                'languages' => $capabilities['detectedProjectLanguages'] ?? $this->catalogLanguages($services),
                'detectedFrameworks' => [],
            ],
            capabilities: $capabilities,
            structure: [
                'areas' => $serviceAreas,
                'entrypoints' => $entrypoints,
                'boundaries' => $boundaries,
                'criticalPaths' => $criticalPaths,
                'services' => $serviceEntries,
                'integrationPoints' => $integrationPoints,
            ],
            navigation: [
                'recommendedReads' => $this->recommendedReadsForCatalog($services, $appmap),
                'recommendedLookupTargets' => $this->recommendedLookupsForCatalog($services, $criticalPaths, $integrationPoints),
                'nextStep' => 'Call flow_lookup on a service, boundary, or path before reading implementation files.',
            ],
            warnings: $warnings,
        );
    }

    /**
     * @return Node[]
     */
    public function actionableEntrypoints(FlowContract $flow): array
    {
        $result = [];
        foreach ($flow->query()->entrypoints()->all() as $node) {
            // Construtores e métodos mágicos são raízes do grafo, não entrypoints de
            // runtime. Nós de arquivo usam o método sintético __file e são exatamente
            // o oposto: a página que o usuário acessa.
            if ($node->type() === Node::TYPE_METHOD && str_starts_with($node->method(), '__')) {
                continue;
            }

            $type = $this->classifyEntrypoint($node);
            if ($type === 'custom') {
                continue;
            }

            $result[] = $node;
        }

        usort($result, fn(Node $a, Node $b): int => strcmp($a->id(), $b->id()));

        return $result;
    }

    public function classifyEntrypoint(Node $node): string
    {
        if ($node->type() === Node::TYPE_FILE) {
            return ScriptEntrypointClassifier::typeFor($node);
        }

        $meta = $node->metadata() ?? [];
        $entrypointType = $meta['entrypoint_type'] ?? null;

        if ($entrypointType !== null) {
            return match ($entrypointType) {
                'http' => 'http',
                'cli', 'script' => 'cli',
                'async' => 'event',
                'ui' => 'ui',
                default => 'custom',
            };
        }

        if (isset($meta['http_path']) || isset($meta['http_method'])) {
            return 'http';
        }

        foreach (($meta['attributes'] ?? []) as $attribute) {
            if (preg_match('/Route|Get|Post|Put|Patch|Delete|ApiResource/', $attribute)) {
                return 'http';
            }
            if (str_contains($attribute, 'AsCommand')) {
                return 'cli';
            }
            if (str_contains($attribute, 'WpHook') || str_contains($attribute, 'WpAction') || str_contains($attribute, 'WpFilter')) {
                return 'event';
            }
        }

        $method = $node->method();
        $class = $node->class();
        $language = $node->language();

        if (str_ends_with($class, 'Controller') || str_ends_with($class, 'Action')) {
            return 'http';
        }
        if (in_array($language, ['typescript', 'javascript'], true)) {
            $tsType = $this->classifyTypeScriptEntrypoint($node);
            if ($tsType !== null) {
                return $tsType;
            }
        }
        if ($method === 'handle' && (str_ends_with($class, 'Command') || str_contains($class, '\\Command\\'))) {
            return 'cli';
        }
        if (str_ends_with($class, 'Listener') || str_ends_with($class, 'Subscriber') || str_ends_with($class, 'Handler')) {
            return 'event';
        }
        if (str_contains($class, 'Livewire') || str_ends_with($class, 'Component')) {
            return 'ui';
        }

        return 'custom';
    }

    private function classifyTypeScriptEntrypoint(Node $node): ?string
    {
        $method = $node->method();
        $class = $node->class();
        $file = str_replace('\\', '/', strtolower($node->file()));
        $lowerMethod = strtolower($method);
        $lowerClass = strtolower($class);

        if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            return 'http';
        }

        if ((str_contains($file, '/api/') || str_contains($file, '/routes/') || str_ends_with($file, '/route.ts') || str_ends_with($file, '/route.tsx'))
            && in_array($lowerMethod, ['handler', 'loader', 'action', 'middleware'], true)
        ) {
            return 'http';
        }

        if (str_ends_with($lowerClass, 'controller') || str_contains($lowerClass, '.routes.')) {
            return 'http';
        }

        if (in_array($lowerMethod, ['main', 'start', 'bootstrap', 'serve', 'run', 'runserver', 'listen'], true)) {
            return 'cli';
        }

        if ((str_ends_with($file, '.tsx') || str_contains($file, '/components/') || str_contains($file, '/routes/'))
            && preg_match('/^[A-Z]/', $method) === 1
        ) {
            return 'ui';
        }

        return null;
    }

    public function areaNameForNode(Node $node): string
    {
        $class = $node->class();

        // Scripts: a classe já é o caminho relativo do arquivo.
        if ($node->type() === Node::TYPE_FILE) {
            return $this->areaFromPath($class);
        }

        if (str_contains($class, '\\')) {
            $parts = array_values(array_filter(explode('\\', $class), static fn(string $part): bool => $part !== ''));
            if (count($parts) >= 2) {
                return $parts[0] . '\\' . $parts[1];
            }
            return $parts[0] ?? $class;
        }

        if (str_contains($class, '.')) {
            $parts = array_values(array_filter(explode('.', $class), static fn(string $part): bool => $part !== ''));
            if (count($parts) >= 2) {
                return $parts[0] . '.' . $parts[1];
            }
            return $parts[0] ?? $class;
        }

        // Sem namespace (PHP legado) ou função global: a classe não carrega
        // estrutura alguma, e usá-la como área produz uma área por classe. O
        // diretório é a única organização que esse código tem.
        $relPath = $node->metadata()['relPath'] ?? null;

        if (is_string($relPath) && $relPath !== '') {
            return $this->areaFromPath($relPath);
        }

        return $class !== '' ? $class : '(root)';
    }

    /**
     * Área a partir de um caminho relativo: o diretório que contém o arquivo.
     */
    private function areaFromPath(string $relativePath): string
    {
        $dir = str_replace('\\', '/', dirname($relativePath));

        return ($dir === '.' || $dir === '' || $dir === '/') ? '(root)' : $dir;
    }

    private function purposePayload(): array
    {
        return [
            'what_this_is_for' => 'Use this MCP to map the project structure before reading code.',
            'when_to_use' => 'Call flow_map first to understand the project or catalog, then use flow_lookup to drill into one target.',
            'why_to_use' => 'It provides deterministic structure so the AI can go straight to the right files, save tokens, and reduce guesswork.',
            'limits' => [
                'This map is structural. It does not replace implementation-level code reading.',
                'If a target is ambiguous, use flow_lookup before opening more files.',
            ],
        ];
    }

    private function purposePayloadSummary(): array
    {
        return [
            'what_this_is_for' => 'Minimal orientation map. Use mode=full for deep exploration.',
            'hint' => 'summary is a minimal orientation payload; use mode=full for deep exploration',
        ];
    }

    /**
     * @param array<string, true> $entrypointIds
     * @return array<int, array<string, mixed>>
     */
    private function buildAreaSummaries(FlowContract $flow, array $entrypointIds, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $areas = [];

        foreach ($flow->nodes() as $node) {
            $area = $this->areaNameForNode($node);
            $areas[$area] ??= [
                'name' => $area,
                'nodeCount' => 0,
                'entrypointCount' => 0,
                'languages' => [],
                'sampleNodes' => [],
                'sampleFiles' => [],
            ];

            $areas[$area]['nodeCount']++;
            $areas[$area]['languages'][$node->language()] = true;

            if (isset($entrypointIds[$node->id()])) {
                $areas[$area]['entrypointCount']++;
            }

            if (count($areas[$area]['sampleNodes']) < $limit) {
                $areas[$area]['sampleNodes'][] = $this->nodeReference($node);
            }
            if (count($areas[$area]['sampleFiles']) < $limit) {
                $areas[$area]['sampleFiles'][] = $node->file();
            }
        }

        $result = array_values(array_map(function (array $area) use ($mode): array {
            sort($area['sampleFiles']);
            $area['sampleFiles'] = array_values(array_unique($area['sampleFiles']));
            $area['languages'] = array_values(array_keys($area['languages']));
            sort($area['languages']);
            // In summary mode, omit sampleNodes and sampleFiles to keep payload small.
            if ($mode === 'summary') {
                unset($area['sampleNodes'], $area['sampleFiles']);
            }
            return $area;
        }, $areas));

        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['nodeCount'], $b['entrypointCount'], $a['name']] <=> [$a['nodeCount'], $a['entrypointCount'], $b['name']]
        );

        return $result;
    }

    /**
     * @param Node[] $entrypoints
     * @return array<int, array<string, mixed>>
     */
    private function buildEntrypointSummaries(array $entrypoints, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $groups = [];

        foreach ($entrypoints as $node) {
            $type = $this->classifyEntrypoint($node);
            $groups[$type] ??= [
                'type' => $type,
                'count' => 0,
                'examples' => [],
            ];

            $groups[$type]['count']++;
            if (count($groups[$type]['examples']) < $limit) {
                $groups[$type]['examples'][] = array_merge(
                    $this->nodeReference($node),
                    ['area' => $this->areaNameForNode($node)]
                );
            }
        }

        $result = array_values($groups);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['count'], $a['type']] <=> [$a['count'], $b['type']]
        );

        return $result;
    }

    /**
     * @param Node[] $entrypoints
     * @return array<int, array<string, mixed>>
     */
    private function buildSingleProjectBoundaries(FlowContract $flow, array $entrypoints, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $boundaries = [];
        $nodeIds = array_fill_keys(array_map(static fn(Node $node): string => $node->id(), $flow->nodes()), true);

        foreach ($entrypoints as $entrypoint) {
            $type = $this->classifyEntrypoint($entrypoint);
            $boundaries[$type] ??= [
                'name' => $type,
                'kind' => 'entrypoint',
                'count' => 0,
                'examples' => [],
            ];
            $boundaries[$type]['count']++;
            if (count($boundaries[$type]['examples']) < $limit) {
                $boundaries[$type]['examples'][] = $this->nodeReference($entrypoint);
            }
        }

        foreach ($flow->edges() as $edge) {
            if ($edge->type() !== 'http_call' || isset($nodeIds[$edge->to()])) {
                continue;
            }

            $boundaryName = 'external-http';
            $boundaries[$boundaryName] ??= [
                'name' => $boundaryName,
                'kind' => 'integration',
                'count' => 0,
                'examples' => [],
            ];
            $boundaries[$boundaryName]['count']++;
            if (count($boundaries[$boundaryName]['examples']) < $limit) {
                $boundaries[$boundaryName]['examples'][] = [
                    'fromNode' => $edge->from(),
                    'target' => $this->httpTargetFromVirtualNode($edge->to()) ?? $edge->to(),
                ];
            }
        }

        $result = array_values($boundaries);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['count'], $a['name']] <=> [$a['count'], $b['name']]
        );

        return $result;
    }

    /**
     * @param Node[] $entrypoints
     * @return array<int, array<string, mixed>>
     */
    private function buildSingleProjectPaths(array $entrypoints, FlowTracer $tracer, int $depth, string $mode): array
    {
        $paths = [];
        $previewLength = $mode === 'full' ? max(2, $depth + 2) : max(2, $depth + 1);

        foreach ($entrypoints as $entrypoint) {
            $trace = $tracer->trace($entrypoint->id(), TraceDirection::DOWNSTREAM, max(1, $depth + 1));
            $path = $this->longestPath($trace->paths());

            $item = [
                'id' => 'path:' . $entrypoint->id(),
                'entrypoint' => $entrypoint->id(),
                'boundary' => $this->classifyEntrypoint($entrypoint),
                'depth' => max(0, count($path) - 1),
                'preview' => array_slice(array_map([$this, 'shortNodeId'], $path), 0, $previewLength),
            ];

            if ($mode === 'full') {
                $item['pathNodes'] = $path;
            }

            $paths[] = $item;
        }

        return $paths;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSingleProjectIntegrationPoints(FlowContract $flow, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $nodeIds = array_fill_keys(array_map(static fn(Node $node): string => $node->id(), $flow->nodes()), true);
        $points = [];

        foreach ($flow->edges() as $edge) {
            if ($edge->type() !== 'http_call' || isset($nodeIds[$edge->to()])) {
                continue;
            }

            $target = $this->httpTargetFromVirtualNode($edge->to()) ?? $edge->to();
            $host = parse_url($target, PHP_URL_HOST);
            $key = is_string($host) && $host !== '' ? $host : $target;

            $points[$key] ??= [
                'type' => 'external-http',
                'target' => $key,
                'count' => 0,
                'callers' => [],
            ];

            $points[$key]['count']++;
            if (count($points[$key]['callers']) < $limit) {
                $points[$key]['callers'][] = $edge->from();
            }
        }

        $result = array_values($points);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['count'], $a['target']] <=> [$a['count'], $b['target']]
        );

        return $result;
    }

    /**
     * @param Node[] $entrypoints
     * @param array<int, array<string, mixed>> $areas
     * @return array<int, array<string, mixed>>
     */
    private function recommendedReadsForProject(array $entrypoints, array $areas, int $depth, string $mode = 'full'): array
    {
        $reads = [];
        $includeFile = $mode === 'full';

        foreach ($entrypoints as $entrypoint) {
            $type = $this->classifyEntrypoint($entrypoint);
            $key = $type . ':' . $this->areaNameForNode($entrypoint);
            if (isset($reads[$key])) {
                continue;
            }

            $entry = [
                'targetType' => 'entrypoint',
                'target' => $entrypoint->id(),
                'reason' => "Start with this {$type} entrypoint to understand a real runtime path.",
            ];
            if ($includeFile) {
                $entry['file'] = $entrypoint->file();
            }
            $reads[$key] = $entry;
        }

        if ($reads === []) {
            foreach ($areas as $area) {
                $entry = [
                    'targetType' => 'area',
                    'target' => $area['name'],
                    'reason' => 'No actionable entrypoints were found here. Start from a representative structural area.',
                ];
                if ($includeFile) {
                    $entry['file'] = $area['sampleFiles'][0] ?? null;
                }
                $reads[$area['name']] = $entry;
            }
        }

        return array_values($reads);
    }

    /**
     * @param Node[] $entrypoints
     * @param array<int, array<string, mixed>> $areas
     * @param array<int, array<string, mixed>> $paths
     * @return array<int, array<string, mixed>>
     */
    private function recommendedLookupsForProject(array $entrypoints, array $areas, array $paths): array
    {
        $targets = [];

        foreach ($entrypoints as $entrypoint) {
            $targets['entrypoint:' . $entrypoint->id()] = [
                'targetType' => 'entrypoint',
                'target' => $entrypoint->id(),
                'reason' => 'Use flow_lookup here to expand one concrete runtime path.',
            ];
        }

        foreach ($areas as $area) {
            $targets['area:' . $area['name']] = [
                'targetType' => 'area',
                'target' => $area['name'],
                'reason' => 'Use flow_lookup here to inspect a structural area without reading its files yet.',
            ];
        }

        foreach ($paths as $path) {
            $targets['path:' . $path['id']] = [
                'targetType' => 'path',
                'target' => $path['id'],
                'reason' => 'Use flow_lookup here to expand one critical path preview.',
            ];
        }

        return array_values($targets);
    }

    /**
     * @param ServiceInfo[] $services
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceAreas(array $services, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $areas = [];

        foreach ($services as $service) {
            $area = $this->serviceAreaName($service->name);
            $areas[$area] ??= [
                'name' => $area,
                'serviceCount' => 0,
                'services' => [],
                'languages' => [],
            ];

            $areas[$area]['serviceCount']++;
            foreach ($service->languages() as $language) {
                $areas[$area]['languages'][$language] = true;
            }
            if (count($areas[$area]['services']) < $limit) {
                $areas[$area]['services'][] = $service->name;
            }
        }

        $result = array_values(array_map(function (array $area): array {
            $area['languages'] = array_values(array_keys($area['languages']));
            sort($area['languages']);
            return $area;
        }, $areas));

        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['serviceCount'], $a['name']] <=> [$a['serviceCount'], $b['name']]
        );

        return $result;
    }

    /**
     * @param ServiceInfo[] $services
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceEntries(array $services, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $result = [];

        foreach ($services as $service) {
            $entrypoints = $this->actionableEntrypoints($service->flow);
            $samples = [];
            foreach (array_slice($entrypoints, 0, $limit) as $node) {
                $samples[] = $this->nodeReference($node);
            }

            $result[] = [
                'name' => $service->name,
                'root' => $service->root,
                'languages' => $service->languages(),
                'nodeCount' => $service->flow->nodeCount(),
                'edgeCount' => $service->flow->edgeCount(),
                'hostnames' => $service->hostnames,
                'entrypointSamples' => $samples,
                'configResolution' => $service->configResolution,
            ];
        }

        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['nodeCount'], $a['name']] <=> [$a['nodeCount'], $b['name']]
        );

        return $result;
    }

    /**
     * @param ServiceInfo[] $services
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalogEntrypoints(array $services, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $groups = [];

        foreach ($services as $service) {
            foreach ($this->actionableEntrypoints($service->flow) as $node) {
                $type = $this->classifyEntrypoint($node);
                $groupKey = $service->name . ':' . $type;
                $groups[$groupKey] ??= [
                    'service' => $service->name,
                    'type' => $type,
                    'count' => 0,
                    'examples' => [],
                ];

                $groups[$groupKey]['count']++;
                if (count($groups[$groupKey]['examples']) < $limit) {
                    $groups[$groupKey]['examples'][] = $this->nodeReference($node);
                }
            }
        }

        $result = array_values($groups);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$a['service'], $b['count'], $b['type']] <=> [$b['service'], $a['count'], $a['type']]
        );

        return $result;
    }

    /**
     * @param ServiceInfo[] $services
     * @param array<string, mixed> $appmap
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalogBoundaries(array $services, array $appmap, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $boundaries = [];

        foreach ($services as $service) {
            foreach ($this->actionableEntrypoints($service->flow) as $node) {
                $type = $this->classifyEntrypoint($node);
                $key = $type;
                $boundaries[$key] ??= [
                    'name' => $type,
                    'kind' => 'entrypoint',
                    'count' => 0,
                    'services' => [],
                    'examples' => [],
                ];

                $boundaries[$key]['count']++;
                $boundaries[$key]['services'][$service->name] = true;
                if (count($boundaries[$key]['examples']) < $limit) {
                    $boundaries[$key]['examples'][] = [
                        'service' => $service->name,
                        'nodeId' => $node->id(),
                    ];
                }
            }
        }

        foreach (($appmap['integrationEdges'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $type = (string) ($edge['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $key = 'integration:' . $type;
            $boundaries[$key] ??= [
                'name' => $type,
                'kind' => 'integration',
                'count' => 0,
                'services' => [],
                'examples' => [],
            ];
            $boundaries[$key]['count']++;
            $fromService = (string) ($edge['fromService'] ?? '');
            if ($fromService !== '') {
                $boundaries[$key]['services'][$fromService] = true;
            }
            if (count($boundaries[$key]['examples']) < $limit) {
                $boundaries[$key]['examples'][] = [
                    'fromService' => $fromService,
                    'toService' => $edge['toService'] ?? null,
                    'target' => $edge['target'] ?? null,
                ];
            }
        }

        $result = array_values(array_map(function (array $boundary): array {
            $boundary['services'] = array_values(array_keys($boundary['services']));
            sort($boundary['services']);
            return $boundary;
        }, $boundaries));

        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['count'], $a['name']] <=> [$a['count'], $b['name']]
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $appmap
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalogCriticalPaths(array $appmap, int $depth, string $mode): array
    {
        $includeInconsistencyFlag = $mode === 'full';
        $paths = [];

        foreach (($appmap['serviceEdges'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $type = (string) ($edge['type'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }

            $item = [
                'id' => sprintf('path:%s->%s:%s', $from, $to, $type),
                'fromService' => $from,
                'toService' => $to,
                'type' => $type,
                'count' => (int) ($edge['count'] ?? 0),
                'preview' => [$from, $to],
                'depth' => max(1, $depth),
            ];

            if ($includeInconsistencyFlag) {
                $item['notes'] = 'Use flow_lookup(path) to inspect this cross-service dependency in more detail.';
            }

            $paths[] = $item;
        }

        usort(
            $paths,
            static fn(array $a, array $b): int =>
                [$b['count'], $a['fromService'], $a['toService']] <=> [$a['count'], $b['fromService'], $b['toService']]
        );

        return $paths;
    }

    /**
     * @param array<string, mixed> $appmap
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalogIntegrationPoints(array $appmap, int $depth, string $mode): array
    {
        $limit = $this->exampleLimit($depth, $mode);
        $points = [];

        foreach (($appmap['integrationEdges'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $from = (string) ($edge['fromService'] ?? '');
            $toService = $edge['toService'] ?? null;
            $target = (string) ($edge['target'] ?? '');
            $type = (string) ($edge['type'] ?? '');
            $key = $toService !== null ? "{$from}:{$toService}:{$type}" : "{$from}:external:{$type}:{$target}";

            $points[$key] ??= [
                'fromService' => $from,
                'toService' => $toService,
                'type' => $type,
                'target' => $target,
                'count' => 0,
                'examples' => [],
            ];

            $points[$key]['count']++;
            if (count($points[$key]['examples']) < $limit) {
                $points[$key]['examples'][] = [
                    'fromNode' => $edge['fromNodeId'] ?? null,
                    'target' => $target,
                ];
            }
        }

        $result = array_values($points);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                [$b['count'], (string) ($a['fromService'] ?? '')] <=> [$a['count'], (string) ($b['fromService'] ?? '')]
        );

        return $result;
    }

    /**
     * @param ServiceInfo[] $services
     * @param array<string, mixed> $appmap
     * @return array<int, array<string, mixed>>
     */
    private function recommendedReadsForCatalog(array $services, array $appmap): array
    {
        $degree = [];
        foreach (($appmap['serviceEdges'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $count = (int) ($edge['count'] ?? 1);
            if ($from !== '') {
                $degree[$from] = ($degree[$from] ?? 0) + $count;
            }
            if ($to !== '') {
                $degree[$to] = ($degree[$to] ?? 0) + $count;
            }
        }

        $reads = [];
        foreach ($services as $service) {
            $reads[] = [
                'targetType' => 'service',
                'target' => $service->name,
                'reason' => 'Start from this service to inspect one concrete root in the catalog.',
                'root' => $service->root,
                'priority' => $degree[$service->name] ?? 0,
            ];
        }

        usort(
            $reads,
            static fn(array $a, array $b): int =>
                [$b['priority'], $a['target']] <=> [$a['priority'], $b['target']]
        );

        return $reads;
    }

    /**
     * @param ServiceInfo[] $services
     * @param array<int, array<string, mixed>> $paths
     * @param array<int, array<string, mixed>> $integrationPoints
     * @return array<int, array<string, mixed>>
     */
    private function recommendedLookupsForCatalog(array $services, array $paths, array $integrationPoints): array
    {
        $targets = [];

        foreach ($services as $service) {
            $targets['service:' . $service->name] = [
                'targetType' => 'service',
                'target' => $service->name,
                'reason' => 'Use flow_lookup here to inspect one service without reading every repo in the catalog.',
            ];
        }

        foreach ($paths as $path) {
            $targets['path:' . $path['id']] = [
                'targetType' => 'path',
                'target' => $path['id'],
                'reason' => 'Use flow_lookup here to inspect one cross-service path.',
            ];
        }

        foreach ($integrationPoints as $point) {
            $type = (string) ($point['type'] ?? 'integration');
            $targets['boundary:' . $type] = [
                'targetType' => 'boundary',
                'target' => $type,
                'reason' => 'Use flow_lookup here to inspect one integration boundary shared by the catalog.',
            ];
        }

        return array_values($targets);
    }

    /**
     * @return string[]
     */
    private function projectLanguages(FlowContract $flow): array
    {
        $languages = [];
        foreach ($flow->nodes() as $node) {
            $languages[$node->language()] = true;
        }

        $result = array_values(array_filter(array_keys($languages), static fn(string $language): bool => $language !== ''));
        sort($result);

        return $result;
    }

    /**
     * Detect frameworks from composer.json require + require-dev.
     * Falls back to scanning node metadata if no composer.json found.
     *
     * @return string[]
     */
    private function projectFrameworks(FlowContract $flow, string $projectRoot = ''): array
    {
        if ($projectRoot !== '') {
            $detected = $this->detectFrameworksFromComposer($projectRoot);
            if ($detected !== []) {
                return $detected;
            }
        }

        $frameworks = [];
        foreach ($flow->nodes() as $node) {
            $framework = $node->metadata()['framework'] ?? null;
            if (is_string($framework) && $framework !== '') {
                $frameworks[$framework] = true;
            }
        }

        $result = array_values(array_keys($frameworks));
        sort($result);

        return $result;
    }

    /**
     * Parse composer.json and map known packages to framework names.
     *
     * @return string[]
     */
    private function detectFrameworksFromComposer(string $projectRoot): array
    {
        $composerPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $content = @file_get_contents($composerPath);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allRequires = array_merge(
            array_keys((array) ($decoded['require'] ?? [])),
            array_keys((array) ($decoded['require-dev'] ?? []))
        );

        $frameworkMap = [
            'laravel/framework'      => 'Laravel',
            'symfony/framework-bundle' => 'Symfony',
        ];

        $found = [];
        foreach ($allRequires as $package) {
            if (isset($frameworkMap[$package])) {
                $found[$frameworkMap[$package]] = true;
            }
        }

        $result = array_values(array_keys($found));
        sort($result);

        return $result;
    }

    /**
     * @param ServiceInfo[] $services
     * @return string[]
     */
    private function catalogLanguages(array $services): array
    {
        $languages = [];
        foreach ($services as $service) {
            foreach ($service->languages() as $language) {
                $languages[$language] = true;
            }
        }

        $result = array_values(array_keys($languages));
        sort($result);

        return $result;
    }

    /**
     * @param string[][] $paths
     * @return string[]
     */
    private function longestPath(array $paths): array
    {
        usort(
            $paths,
            static fn(array $a, array $b): int =>
                [count($b), implode('|', $a)] <=> [count($a), implode('|', $b)]
        );

        return $paths[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeReference(Node $node): array
    {
        return [
            'nodeId' => $node->id(),
            'class' => $node->class(),
            'method' => $node->method(),
            'file' => $node->file(),
            'line' => $node->line(),
            'language' => $node->language(),
        ];
    }

    private function shortNodeId(string $nodeId): string
    {
        if (!str_contains($nodeId, '::')) {
            return $nodeId;
        }

        [$class, $method] = explode('::', $nodeId, 2);
        $classParts = preg_split('/[\\\\.]/', $class) ?: [$class];
        $shortClass = end($classParts);

        return $shortClass . '::' . $method;
    }

    private function exampleLimit(int $depth, string $mode): int
    {
        return $mode === 'full'
            ? max(2, $depth * 2)
            : max(1, $depth);
    }

    private function serviceAreaName(string $serviceName): string
    {
        $parts = preg_split('/[-_.]/', $serviceName) ?: [$serviceName];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return $serviceName;
        }

        return implode('-', array_slice($parts, 0, min(2, count($parts))));
    }

    private function httpTargetFromVirtualNode(string $value): ?string
    {
        if (!str_starts_with($value, 'http:')) {
            return null;
        }

        $parts = explode(':', $value, 3);
        return $parts[2] ?? null;
    }

    /**
     * @param array<string, mixed> $capabilities
     * @return string[]
     */
    private function capabilityWarnings(array $capabilities): array
    {
        $warnings = [];
        $configured = array_values(array_filter((array) ($capabilities['configuredScanLanguages'] ?? []), 'is_string'));
        $detected = array_values(array_filter((array) ($capabilities['detectedProjectLanguages'] ?? []), 'is_string'));
        $supported = [];

        foreach ((array) ($capabilities['supportedLanguages'] ?? []) as $language) {
            if (!is_array($language)) {
                continue;
            }
            $languageId = (string) ($language['id'] ?? '');
            if ($languageId !== '') {
                $supported[$languageId] = (string) ($language['supportLevel'] ?? 'full');
            }
        }

        if ($detected === []) {
            $warnings[] = 'No supported source language was detected in the scanned project files.';
        }

        if ($detected !== []) {
            $fullSupportDetected = false;
            foreach ($detected as $languageId) {
                if (($supported[$languageId] ?? 'full') === 'full') {
                    $fullSupportDetected = true;
                    break;
                }
            }

            if (!$fullSupportDetected) {
                $warnings[] = 'Detected only partial-support inputs such as Blade templates. Results may be limited to edge extraction.';
            }
        }

        if ($configured !== [] && $detected === []) {
            $warnings[] = 'Configured scan languages were found, but no matching analyzable files were detected.';
        }

        return $warnings;
    }
}
