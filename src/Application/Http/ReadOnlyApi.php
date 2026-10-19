<?php

namespace FlowEngine\Application\Http;

use FlowEngine\AI\Export\ExportOptions;
use FlowEngine\Application\AppMap\AppMapDiffAnalyzer;
use FlowEngine\Application\AppMap\AppMapDriftPolicyEvaluator;
use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Application\AppMap\ComplianceMonitorEvaluator;
use FlowEngine\Application\AppMap\MermaidDiagramGenerator;
use FlowEngine\Application\AppMap\ServiceInfo;
use FlowEngine\Application\DTO\EdgeDTO;
use FlowEngine\Application\DTO\NodeDTO;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Bootstrap\InfraServices;
use RuntimeException;
use Throwable;

final class ReadOnlyApi
{
    private ?Container $container = null;

    public function __construct(
        private string $projectPath
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = []
    ): array {
        $normalizedMethod = strtoupper($method);

        if ($normalizedMethod !== 'GET') {
            return $this->error(405, 'Method not allowed. Use GET.');
        }

        try {
            return $this->handleGet($path, $query);
        } catch (Throwable $e) {
            return $this->error(500, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function handleGet(string $path, array $query): array
    {
        return match ($path) {
            '/', '/health' => $this->ok([
                'status' => 'ok',
                'service' => 'flow-engine-api',
                'version' => '2.8.0-alpha',
            ]),
            '/api/v1/metrics' => $this->metrics(),
            '/api/v1/cycles' => $this->cycles(),
            '/api/v1/architecture' => $this->architecture(),
            '/api/v1/orphans' => $this->orphans(),
            '/api/v1/nodes' => $this->nodes($query),
            '/api/v1/edges' => $this->edges(),
            '/api/v1/flow' => $this->flow(),
            '/api/v1/snapshots' => $this->snapshots(),
            '/api/v1/context' => $this->context($query),
            '/api/v1/bugs' => $this->bugs($query),
            '/api/v1/appmap' => $this->appmap($query),
            '/api/v1/appmap-diff' => $this->appmapDiff($query),
            '/api/v1/compliance-monitor' => $this->complianceMonitor($query),
            '/api/v1/deployment-map' => $this->deploymentMap($query),
            '/api/v1/devops-map' => $this->devOpsMap($query),
            '/api/v1/website-map' => $this->websiteMap($query),
            '/api/v1/diagram' => $this->diagram($query),
            default => $this->error(404, 'Route not found.'),
        };
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function metrics(): array
    {
        $this->analyze();
        return $this->ok($this->container()->analyzeMetrics()->execute()->toArray());
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function cycles(): array
    {
        $this->analyze();
        return $this->ok($this->container()->analyzeCycles()->execute()->toArray());
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function architecture(): array
    {
        $this->analyze();
        return $this->ok($this->container()->analyzeArchitecture()->execute()->toArray());
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function orphans(): array
    {
        $this->analyze();
        return $this->ok($this->container()->analyzeOrphans()->execute()->toArray());
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function nodes(array $query): array
    {
        $this->analyze();
        $flow = $this->container()->getFlow();
        $reader = $flow->query()->publicNodes();

        $namespace = trim((string) ($query['namespace'] ?? ''));
        if ($namespace !== '') {
            $reader = $reader->byNamespace($namespace);
        }

        $nodes = array_map(
            static fn($node) => NodeDTO::fromNode($node)->toArray(),
            $reader->all()
        );

        return $this->ok([
            'nodes' => array_values($nodes),
            'count' => count($nodes),
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function edges(): array
    {
        $this->analyze();
        $flow = $this->container()->getFlow();

        $edges = array_map(
            static fn($edge) => EdgeDTO::fromEdge($edge)->toArray(),
            $flow->edges()
        );

        return $this->ok([
            'edges' => array_values($edges),
            'count' => count($edges),
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function flow(): array
    {
        $this->analyze();
        $flow = $this->container()->getFlow();

        $nodes = array_map(
            static fn($node) => NodeDTO::fromNode($node)->toArray(),
            $flow->nodes()
        );

        $edges = array_map(
            static fn($edge) => EdgeDTO::fromEdge($edge)->toArray(),
            $flow->edges()
        );

        return $this->ok([
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'nodeCount' => $flow->nodeCount(),
            'edgeCount' => $flow->edgeCount(),
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function snapshots(): array
    {
        return $this->ok([
            'snapshots' => $this->container()->snapshotStore()->list(),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function context(array $query): array
    {
        $this->analyze();

        $entrypoint = trim((string) ($query['entrypoint'] ?? ''));
        $entrypoint = $entrypoint !== '' ? $entrypoint : null;

        $depth = isset($query['depth']) ? (int) $query['depth'] : 5;
        $strict = filter_var($query['strict'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $minimal = filter_var($query['minimal'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $sectionsRaw = trim((string) ($query['sections'] ?? ''));
        $sections = $sectionsRaw !== '' ? array_map('trim', explode(',', $sectionsRaw)) : null;

        if ($sections !== null) {
            $has = static fn(string $s): bool => in_array($s, $sections, true);
            $options = new ExportOptions(
                includeMetrics: $has('metrics'),
                includeComplexity: $has('complexity'),
                includeCycles: $has('cycles'),
                includeArchitecture: $has('architecture'),
                includeOrphans: $has('orphans'),
                includeServiceMap: $has('serviceMap'),
                includeDataModel: $has('dataModel'),
                includeRoutes: $has('routes'),
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strict,
            );
        } elseif ($minimal) {
            $options = new ExportOptions(
                includeMetrics: true,
                includeComplexity: false,
                includeCycles: false,
                includeArchitecture: false,
                includeOrphans: false,
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strict,
            );
        } else {
            $options = new ExportOptions(
                entrypoint: $entrypoint,
                entrypointDepth: $depth,
                strictEntrypoint: $strict,
            );
        }

        $result = $this->container()->exportContext()->execute($options);

        return $this->ok($result->toArray());
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function appmap(array $query): array
    {
        $catalog = trim((string) ($query['catalog'] ?? ''));
        if ($catalog === '') {
            return $this->error(400, 'Missing required query param: catalog');
        }

        $entries = $this->catalogEntriesFromPath($catalog);
        if (count($entries) < 2) {
            return $this->error(400, 'Catalog must include at least 2 services with valid paths');
        }

        $services = $this->buildServices($entries);
        $appmap = (new ApplicationMapBuilder())->build($services);

        return $this->ok([
            'status' => 'ok',
            'appmap' => $appmap,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function appmapDiff(array $query): array
    {
        $baselineCatalog = trim((string) ($query['baselineCatalog'] ?? ''));
        $currentCatalog = trim((string) ($query['currentCatalog'] ?? ''));

        if ($baselineCatalog === '' || $currentCatalog === '') {
            return $this->error(400, 'Missing required query params: baselineCatalog and currentCatalog');
        }

        $baselineMap = $this->buildAppMapFromCatalog($baselineCatalog);
        if ($baselineMap === null) {
            return $this->error(400, 'Baseline catalog must include at least 2 services with valid paths');
        }

        $currentMap = $this->buildAppMapFromCatalog($currentCatalog);
        if ($currentMap === null) {
            return $this->error(400, 'Current catalog must include at least 2 services with valid paths');
        }

        $incomplete = $this->incompleteMapsError($baselineMap, $currentMap);
        if ($incomplete !== null) {
            return $incomplete;
        }

        $drift = (new AppMapDiffAnalyzer())->diff(
            ['appmap' => $baselineMap],
            ['appmap' => $currentMap]
        );

        $payload = [
            'status' => 'ok',
            'baselineCatalog' => $baselineCatalog,
            'currentCatalog' => $currentCatalog,
            'drift' => $drift,
        ];

        $policyPath = trim((string) ($query['policy'] ?? ''));
        if ($policyPath !== '') {
            $policy = $this->loadJsonObjectFile($policyPath);
            if ($policy === null) {
                return $this->error(400, "Invalid policy file: {$policyPath}");
            }
            $payload['gate'] = (new AppMapDriftPolicyEvaluator())->evaluate($drift, $policy);
        }

        return $this->ok($payload);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function complianceMonitor(array $query): array
    {
        $baselineCatalog = trim((string) ($query['baselineCatalog'] ?? ''));
        $currentCatalog = trim((string) ($query['currentCatalog'] ?? ''));
        $policyPath = trim((string) ($query['policy'] ?? ''));

        if ($baselineCatalog === '' || $currentCatalog === '' || $policyPath === '') {
            return $this->error(400, 'Missing required query params: baselineCatalog, currentCatalog, and policy');
        }

        $baselineMap = $this->buildAppMapFromCatalog($baselineCatalog);
        if ($baselineMap === null) {
            return $this->error(400, 'Baseline catalog must include at least 2 services with valid paths');
        }

        $currentMap = $this->buildAppMapFromCatalog($currentCatalog);
        if ($currentMap === null) {
            return $this->error(400, 'Current catalog must include at least 2 services with valid paths');
        }

        $incomplete = $this->incompleteMapsError($baselineMap, $currentMap);
        if ($incomplete !== null) {
            return $incomplete;
        }

        $policy = $this->loadJsonObjectFile($policyPath);
        if ($policy === null) {
            return $this->error(400, "Invalid policy file: {$policyPath}");
        }

        $drift = (new AppMapDiffAnalyzer())->diff(
            ['appmap' => $baselineMap],
            ['appmap' => $currentMap]
        );

        $gate = (new AppMapDriftPolicyEvaluator())->evaluate($drift, $policy);
        $monitor = (new ComplianceMonitorEvaluator())->evaluate($drift, $policy);

        return $this->ok([
            'status' => 'ok',
            'baselineCatalog' => $baselineCatalog,
            'currentCatalog' => $currentCatalog,
            'policy' => $policyPath,
            'drift' => $drift,
            'gate' => $gate,
            'monitor' => $monitor,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function diagram(array $query): array
    {
        $catalog = trim((string) ($query['catalog'] ?? ''));
        if ($catalog === '') {
            return $this->error(400, 'Missing required query param: catalog');
        }

        $audience = strtolower(trim((string) ($query['audience'] ?? '')));
        if ($audience !== '' && !in_array($audience, ['engineering', 'architecture'], true)) {
            return $this->error(400, 'Invalid audience. Use engineering or architecture');
        }

        $typeRaw = strtolower(trim((string) ($query['type'] ?? '')));
        $type = $typeRaw !== '' ? $typeRaw : $this->defaultDiagramTypeForAudience($audience);
        if (!in_array($type, ['dependency', 'sequence', 'c4container'], true)) {
            return $this->error(400, 'Invalid type. Use dependency, sequence, or c4container');
        }

        $includeInconsistenciesRaw = $query['includeInconsistencies'] ?? $query['include_inconsistencies'] ?? true;
        $includeInconsistencies = filter_var($includeInconsistenciesRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $includeInconsistencies = $includeInconsistencies ?? true;

        $entries = $this->catalogEntriesFromPath($catalog);
        if (count($entries) < 2) {
            return $this->error(400, 'Catalog must include at least 2 services with valid paths');
        }

        $services = $this->buildServices($entries);
        $appmap = (new ApplicationMapBuilder())->build($services);
        $generator = new MermaidDiagramGenerator();
        $mermaid = match ($type) {
            'sequence' => $generator->sequenceDiagram($appmap),
            'c4container' => $generator->c4Container($appmap),
            default => $generator->dependencyGraph($appmap),
        };

        return $this->ok([
            'status' => 'ok',
            'type' => $type,
            'audience' => $audience !== '' ? $audience : null,
            'generatedAt' => date('c'),
            'mermaid' => $mermaid,
            'inconsistencies' => $includeInconsistencies ? ($appmap['inconsistencies'] ?? []) : [],
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deploymentMap(array $query): array
    {
        $catalog = trim((string) ($query['catalog'] ?? ''));
        if ($catalog === '') {
            return $this->error(400, 'Missing required query param: catalog');
        }

        $resolved = (new InfraServices())->resolveCatalogServices()
            ->enrichedEntriesWithDocker($catalog, $this->projectPath);
        $entries = $resolved['entries'];
        if (count($entries) < 2) {
            return $this->error(400, 'Catalog must include at least 2 services with valid paths');
        }

        $services = $this->buildServices($entries);
        $appmap = (new ApplicationMapBuilder())->build($services);

        $docker = $resolved['docker'];
        $deployment = $this->buildDeploymentPayload($appmap, $docker, $catalog);

        return $this->ok([
            'status' => 'ok',
            'catalog' => $deployment['catalog'],
            'services' => $deployment['services'],
            'environments' => $deployment['environments'],
            'runtimeDistribution' => $deployment['runtimeDistribution'],
            'infrastructureDependencies' => $deployment['infrastructureDependencies'],
            'coverageWarnings' => $deployment['coverageWarnings'],
            'detectedComposeFiles' => $deployment['detectedComposeFiles'],
            'dockerfiles' => $deployment['dockerfiles'],
            'environmentFiles' => $deployment['environmentFiles'],
            'containers' => $deployment['containers'],
            'networks' => $deployment['networks'],
            'serviceMappings' => $deployment['serviceMappings'],
            'warnings' => $deployment['warnings'],
            'generatedAt' => date('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function devOpsMap(array $query): array
    {
        $catalog = trim((string) ($query['catalog'] ?? ''));
        if ($catalog === '') {
            return $this->error(400, 'Missing required query param: catalog');
        }

        $entries = $this->catalogEntriesFromPath($catalog);
        if (count($entries) < 2) {
            return $this->error(400, 'Catalog must include at least 2 services with valid paths');
        }

        $services = $this->buildServices($entries);
        $appmap = (new ApplicationMapBuilder())->build($services);

        $inconsistencies = is_array($appmap['inconsistencies'] ?? null)
            ? $appmap['inconsistencies']
            : [];

        $highSeverity = array_values(array_filter(
            $inconsistencies,
            static fn($item): bool =>
                is_array($item)
                && in_array(strtolower((string) ($item['severity'] ?? '')), ['high', 'critical'], true)
        ));
        $mediumSeverity = array_values(array_filter(
            $inconsistencies,
            static fn($item): bool => is_array($item) && strtolower((string) ($item['severity'] ?? '')) === 'medium'
        ));

        $buildBlockers = $this->messagesByKeyword($highSeverity, 'schema');
        $testBlockers = $this->messagesByKeyword($highSeverity, 'contract');
        $deployBlockers = $this->messagesByKeyword($highSeverity, 'breaking');

        $buildRisks = $this->messagesByKeyword($mediumSeverity, 'schema');
        $testRisks = $this->messagesByKeyword($mediumSeverity, 'contract');
        $deployRisks = $this->messagesByKeyword($mediumSeverity, 'breaking');

        $stages = [
            [
                'name' => 'build',
                'status' => $this->inferDevOpsStageStatus($buildBlockers, $buildRisks),
                'blockers' => array_slice($buildBlockers, 0, 4),
                'risks' => array_slice($buildRisks, 0, 4),
                'durationHint' => '5-10m',
            ],
            [
                'name' => 'test',
                'status' => $this->inferDevOpsStageStatus($testBlockers, $testRisks),
                'blockers' => array_slice($testBlockers, 0, 4),
                'risks' => array_slice($testRisks, 0, 4),
                'durationHint' => '10-25m',
            ],
            [
                'name' => 'deploy',
                'status' => $this->inferDevOpsStageStatus($deployBlockers, $deployRisks),
                'blockers' => array_slice($deployBlockers, 0, 4),
                'risks' => array_slice($deployRisks, 0, 4),
                'durationHint' => '5-15m',
            ],
        ];

        $bottlenecks = [];
        foreach ($stages as $stage) {
            if (($stage['status'] ?? 'healthy') === 'healthy' && count((array) ($stage['blockers'] ?? [])) === 0) {
                continue;
            }
            $issues = count((array) ($stage['blockers'] ?? [])) > 0 ? $stage['blockers'] : $stage['risks'];
            $bottlenecks[] = "{$stage['name']}: " . implode(', ', $issues);
        }

        $highRiskCount = count(array_filter(
            $stages,
            static fn(array $stage): bool => ($stage['status'] ?? '') === 'critical'
        ));
        $blockerCount = array_sum(array_map(
            static fn(array $stage): int => count((array) ($stage['blockers'] ?? [])),
            $stages
        ));

        $releaseSummary = [];
        if ($highRiskCount > 0) {
            $releaseSummary[] = "{$highRiskCount} critical DevOps stage(s) require release manager review before deployment.";
        }
        if ($blockerCount > 0) {
            $releaseSummary[] = "{$blockerCount} blocker(s) mapped to build/test/deploy stages.";
        }
        if ($highRiskCount === 0 && $blockerCount === 0) {
            $releaseSummary[] = 'No critical blockers detected across build-test-deploy pipeline.';
        }

        return $this->ok([
            'status' => 'ok',
            'catalog' => $catalog,
            'stages' => $stages,
            'highRiskCount' => $highRiskCount,
            'blockerCount' => $blockerCount,
            'bottlenecks' => $bottlenecks,
            'releaseSummary' => $releaseSummary,
            'generatedAt' => date('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function websiteMap(array $query): array
    {
        $catalog = trim((string) ($query['catalog'] ?? ''));
        if ($catalog === '') {
            return $this->error(400, 'Missing required query param: catalog');
        }

        $entries = $this->catalogEntriesFromPath($catalog);
        if (count($entries) < 2) {
            return $this->error(400, 'Catalog must include at least 2 services with valid paths');
        }

        $services = $this->buildServices($entries);
        $routes = [];
        $entrypoints = [];
        $pagesByKey = [];
        $frameworkSet = [];
        $serviceSet = [];

        foreach ($services as $service) {
            $serviceName = $service->name;
            $serviceSet[$serviceName] = true;

            foreach ($service->flow->nodes() as $node) {
                $meta = $node->metadata();
                if (!is_array($meta) || !isset($meta['http_path'])) {
                    continue;
                }

                $httpPath = $this->normalizeWebsitePath((string) ($meta['http_path'] ?? ''));
                if ($httpPath === '') {
                    continue;
                }
                $httpMethod = strtoupper(trim((string) ($meta['http_method'] ?? 'GET')));
                if ($httpMethod === '') {
                    $httpMethod = 'GET';
                }
                $framework = $this->inferWebsiteFramework($node->language(), $node->file());
                $frameworkSet[$framework] = true;

                $route = [
                    'path' => $httpPath,
                    'method' => $httpMethod,
                    'service' => $serviceName,
                    'framework' => $framework,
                    'entrypoint' => $node->id(),
                ];
                $routes[] = $route;

                $entrypoints[] = [
                    'service' => $serviceName,
                    'nodeId' => $node->id(),
                    'type' => 'http',
                    'method' => $httpMethod,
                    'path' => $httpPath,
                    'framework' => $framework,
                ];

                $pageKey = "{$serviceName}|{$httpPath}";
                if (!isset($pagesByKey[$pageKey])) {
                    $pagesByKey[$pageKey] = [
                        'id' => sha1($pageKey),
                        'path' => $httpPath,
                        'service' => $serviceName,
                        'framework' => $framework,
                        'entrypointCount' => 0,
                    ];
                }
                $pagesByKey[$pageKey]['entrypointCount']++;
            }
        }

        usort($routes, static fn(array $a, array $b): int => strcmp($a['path'] . $a['method'], $b['path'] . $b['method']));
        usort($entrypoints, static fn(array $a, array $b): int => strcmp($a['path'] . $a['method'], $b['path'] . $b['method']));
        $pages = array_values($pagesByKey);
        usort($pages, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        $frameworks = array_keys($frameworkSet);
        sort($frameworks);

        return $this->ok([
            'status' => 'ok',
            'catalog' => $catalog,
            'pages' => $pages,
            'routes' => $routes,
            'entrypoints' => $entrypoints,
            'summary' => [
                'totalPages' => count($pages),
                'totalRoutes' => count($routes),
                'totalEntrypoints' => count($entrypoints),
                'frameworks' => $frameworks,
                'services' => count($serviceSet),
            ],
            'generatedAt' => date('c'),
        ]);
    }

    private function defaultDiagramTypeForAudience(string $audience): string
    {
        return match ($audience) {
            'architecture' => 'c4container',
            default => 'dependency',
        };
    }

    private function inferDeploymentEnvironment(string $value): string
    {
        $lower = strtolower($value);
        if (str_contains($lower, 'prod')) {
            return 'production';
        }
        if (str_contains($lower, 'stag')) {
            return 'staging';
        }
        if (str_contains($lower, 'dev')) {
            return 'development';
        }
        if (str_contains($lower, 'test')) {
            return 'test';
        }
        return 'unknown';
    }

    /**
     * @return 'complete'|'partial'|'missing'
     */
    private function inferDeploymentCoverage(string $runtime, string $environment): string
    {
        $hasRuntime = trim($runtime) !== '' && strtolower($runtime) !== 'unknown';
        $hasEnvironment = trim($environment) !== '' && strtolower($environment) !== 'unknown';
        if ($hasRuntime && $hasEnvironment) {
            return 'complete';
        }
        if ($hasRuntime || $hasEnvironment) {
            return 'partial';
        }
        return 'missing';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function messagesByKeyword(array $items, string $keyword): array
    {
        $messages = [];
        foreach ($items as $item) {
            $type = strtolower((string) ($item['type'] ?? ''));
            if (!str_contains($type, $keyword)) {
                continue;
            }
            $message = trim((string) ($item['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    /**
     * @param array<int, string> $blockers
     * @param array<int, string> $risks
     * @return 'healthy'|'warning'|'critical'
     */
    private function inferDevOpsStageStatus(array $blockers, array $risks): string
    {
        if (count($blockers) > 0) {
            return 'critical';
        }
        if (count($risks) > 0) {
            return 'warning';
        }
        return 'healthy';
    }

    private function normalizeWebsitePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }
        if (!str_starts_with($trimmed, '/')) {
            return '/' . $trimmed;
        }
        return $trimmed;
    }

    private function inferWebsiteFramework(string $language, string $file): string
    {
        $lowerFile = strtolower($file);
        if ($language === 'typescript' || $language === 'javascript') {
            if (str_contains($lowerFile, '/app/') || str_contains($lowerFile, '\\app\\')) {
                return 'nextjs';
            }
            if (str_contains($lowerFile, '/pages/') || str_contains($lowerFile, '\\pages\\')) {
                return 'nextjs';
            }
            return 'node';
        }
        if ($language === 'python') {
            if (str_contains($lowerFile, 'fastapi')) {
                return 'fastapi';
            }
            if (str_contains($lowerFile, 'flask')) {
                return 'flask';
            }
            if (str_contains($lowerFile, 'django')) {
                return 'django';
            }
            return 'python';
        }
        if ($language === 'go') {
            return 'go-http';
        }
        if ($language === 'php') {
            if (str_contains($lowerFile, 'laravel')) {
                return 'laravel';
            }
            if (str_contains($lowerFile, 'symfony')) {
                return 'symfony';
            }
            return 'php-web';
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function bugs(array $query): array
    {
        $this->analyze();

        $minScore = isset($query['min_score']) ? (int) $query['min_score'] : 0;
        $type = trim((string) ($query['type'] ?? ''));

        return $this->ok($this->container()->analyzeBugs()->execute($minScore, $type)->toArray());
    }


    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ok(array $data): array
    {
        return [
            'status' => 200,
            'body' => $data,
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    /**
     * @param array<string, mixed> $baselineMap
     * @param array<string, mixed> $currentMap
     * @return array<string, mixed>|null
     */
    private function incompleteMapsError(array $baselineMap, array $currentMap): ?array
    {
        $analyzer = new AppMapDiffAnalyzer();
        $failed = array_unique(array_merge($analyzer->failedServices($baselineMap), $analyzer->failedServices($currentMap)));

        return $failed === []
            ? null
            : $this->error(422, 'Cannot compute drift: services failed analysis: ' . implode(', ', $failed));
    }

    private function error(int $status, string $message): array
    {
        return [
            'status' => $status,
            'body' => ['error' => $message],
        ];
    }

    private function analyze(): void
    {
        $this->container()->analyzeProject()->execute();
    }

    private function container(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $real = realpath($this->projectPath);
        if ($real === false) {
            throw new RuntimeException("Project path not found: {$this->projectPath}");
        }

        return $this->container = new Container($real);
    }

    /**
     * @return array<int, string>
     */
    private function pathsFromCatalog(string $catalogPath): array
    {
        $full = $this->resolveCatalogPath($catalogPath);
        if ($full === null || !file_exists($full)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($full), true);
        if (!is_array($raw)) {
            return [];
        }

        $baseDir = dirname($full);
        $paths = [];

        foreach (($raw['services'] ?? []) as $svc) {
            if (!is_array($svc)) {
                continue;
            }

            $path = trim((string) ($svc['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $candidate = $path;
            if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:\\\\/', $path) !== 1) {
                $candidate = $baseDir . DIRECTORY_SEPARATOR . $path;
            }

            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)) {
                $paths[] = $resolved;
            }
        }

        return array_values(array_unique($paths));
    }

    private function resolveCatalogPath(string $path): ?string
    {
        $direct = realpath($path);
        if ($direct !== false) {
            return $direct;
        }

        $projectRelative = realpath($this->projectPath . DIRECTORY_SEPARATOR . $path);
        if ($projectRelative !== false) {
            return $projectRelative;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadJsonObjectFile(string $path): ?array
    {
        $full = $this->resolveCatalogPath($path);
        if ($full === null || !file_exists($full)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($full), true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAppMapFromCatalog(string $catalog): ?array
    {
        $entries = $this->catalogEntriesFromPath($catalog);
        if (count($entries) < 2) {
            return null;
        }

        $services = $this->buildServices($entries);
        return (new ApplicationMapBuilder())->build($services);
    }

    /**
     * @return array<int, array{
     *   path: string,
     *   name: string|null,
     *   hostnames: string[],
     *   contractEndpoints: array<int, array{method: string, path: string, summary: string}>|null,
     *   docker: array{
     *     composeFiles: string[],
     *     dockerfiles: string[],
     *     envFiles: string[],
     *     serviceNames: string[]
     *   }
     * }>
     */
    private function catalogEntriesFromPath(string $catalogPath): array
    {
        return (new InfraServices())->resolveCatalogServices()->enrichedEntries($catalogPath, $this->projectPath);
    }

    /**
     * @param array<int, array{
     *   path: string,
     *   name: string|null,
     *   hostnames: string[],
     *   contractEndpoints: array<int, array{method: string, path: string, summary: string}>|null,
     *   docker: array{
     *     composeFiles: string[],
     *     dockerfiles: string[],
     *     envFiles: string[],
     *     serviceNames: string[]
     *   }
     * }> $entries
     * @return array<int, ServiceInfo>
     */
    private function buildServices(array $entries): array
    {
        return (new CatalogServiceBuilder())->buildFromEntries($entries);
    }

    /**
     * @param array<string, mixed> $appmap
     * @param array<string, mixed> $docker
     * @return array<string, mixed>
     */
    private function buildDeploymentPayload(array $appmap, array $docker, string $catalog): array
    {
        $mappingByService = [];
        foreach (($docker['serviceMappings'] ?? []) as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $serviceName = (string) ($mapping['service'] ?? '');
            if ($serviceName !== '') {
                $mappingByService[$serviceName] = $mapping;
            }
        }

        $infraBySource = [];
        foreach (($appmap['serviceEdges'] ?? []) as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $source = (string) ($edge['from'] ?? '');
            $target = (string) ($edge['to'] ?? '');
            $type = strtolower((string) ($edge['type'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }

            if (!in_array($type, ['http', 'script'], true)) {
                continue;
            }

            $infraBySource[$source] ??= [];
            if (!in_array($target, $infraBySource[$source], true)) {
                $infraBySource[$source][] = $target;
            }
        }

        $services = [];
        $runtimeDistribution = [];
        $environmentSet = [];
        $infraSet = [];
        $coverageWarnings = [];

        foreach (($appmap['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }

            $id = (string) (($service['id'] ?? '') ?: ($service['name'] ?? ''));
            if ($id === '') {
                continue;
            }

            $mapping = $mappingByService[$id] ?? $mappingByService[(string) ($service['name'] ?? '')] ?? null;
            $environments = is_array($mapping['environments'] ?? null)
                ? array_values(array_filter($mapping['environments'], 'is_string'))
                : [];
            $environment = $environments[0] ?? $this->inferDeploymentEnvironment((string) (($service['status'] ?? '') ?: ($service['domain'] ?? '')));
            $runtime = (string) (($service['technology'] ?? '') ?: ((is_array($service['languages'] ?? null) && isset($service['languages'][0])) ? $service['languages'][0] : 'unknown'));
            $infrastructureDependencies = array_values(array_unique(array_merge(
                $infraBySource[$id] ?? [],
                is_array($mapping['composeServices'] ?? null) ? $mapping['composeServices'] : []
            )));
            $coverage = $this->inferDeploymentCoverage($runtime, $environment);

            $services[] = [
                'id' => $id,
                'name' => (string) (($service['name'] ?? '') ?: $id),
                'runtime' => $runtime !== '' ? $runtime : 'unknown',
                'environment' => $environment,
                'infrastructureDependencies' => $infrastructureDependencies,
                'coverage' => $coverage,
                'composeServices' => is_array($mapping['composeServices'] ?? null) ? $mapping['composeServices'] : [],
                'hostnames' => is_array($mapping['hostnames'] ?? null) ? $mapping['hostnames'] : [],
                'dockerfiles' => is_array($mapping['dockerfiles'] ?? null) ? $mapping['dockerfiles'] : [],
                'environmentFiles' => is_array($mapping['environmentFiles'] ?? null) ? $mapping['environmentFiles'] : [],
                'containers' => is_array($mapping['containers'] ?? null) ? $mapping['containers'] : [],
            ];

            $runtimeDistribution[$runtime !== '' ? $runtime : 'unknown'] = (int) ($runtimeDistribution[$runtime !== '' ? $runtime : 'unknown'] ?? 0) + 1;
            $environmentSet[$environment] = true;
            foreach ($infrastructureDependencies as $infra) {
                $infraSet[(string) $infra] = true;
            }
            if ($coverage !== 'complete') {
                $coverageWarnings[] = "Service \"{$id}\" has {$coverage} deployment metadata coverage.";
            }
        }

        ksort($runtimeDistribution);
        $environments = array_keys($environmentSet);
        sort($environments);
        $infrastructureDependencies = array_keys($infraSet);
        sort($infrastructureDependencies);

        return [
            'catalog' => $catalog,
            'services' => $services,
            'environments' => $environments,
            'runtimeDistribution' => $runtimeDistribution,
            'infrastructureDependencies' => $infrastructureDependencies,
            'coverageWarnings' => array_values(array_unique(array_merge(
                $coverageWarnings,
                is_array($docker['warnings'] ?? null) ? $docker['warnings'] : []
            ))),
            'detectedComposeFiles' => array_values(array_unique(is_array($docker['detectedComposeFiles'] ?? null) ? $docker['detectedComposeFiles'] : [])),
            'dockerfiles' => array_values(array_unique(is_array($docker['dockerfiles'] ?? null) ? $docker['dockerfiles'] : [])),
            'environmentFiles' => array_values(array_unique(is_array($docker['environmentFiles'] ?? null) ? $docker['environmentFiles'] : [])),
            'containers' => array_values(is_array($docker['containers'] ?? null) ? $docker['containers'] : []),
            'networks' => array_values(is_array($docker['networks'] ?? null) ? $docker['networks'] : []),
            'serviceMappings' => array_values(is_array($docker['serviceMappings'] ?? null) ? $docker['serviceMappings'] : []),
            'warnings' => array_values(array_unique(is_array($docker['warnings'] ?? null) ? $docker['warnings'] : [])),
        ];
    }
}
