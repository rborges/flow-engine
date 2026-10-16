<?php

namespace Tests\Application\Http;

use FlowEngine\Application\Http\ReadOnlyApi;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadOnlyApiTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->deleteDirectory($this->tmpDir);
        }

    }

    public function test_health_endpoint_returns_ok(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/health');

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
    }

    public function test_metrics_endpoint_returns_report(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/metrics');

        $this->assertSame(200, $response['status']);
        $this->assertArrayHasKey('totalNodes', $response['body']);
        $this->assertArrayHasKey('totalEdges', $response['body']);
    }

    public function test_unknown_route_returns_404(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertSame('Route not found.', $response['body']['error']);
    }

    public function test_post_to_any_route_returns_405(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('POST', '/api/v1/metrics');

        $this->assertSame(405, $response['status']);
        $this->assertSame('Method not allowed. Use GET.', $response['body']['error']);
    }

    public function test_context_endpoint_returns_markdown_and_token_estimate(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/context');

        $this->assertSame(200, $response['status']);
        $this->assertArrayHasKey('markdown', $response['body']);
        $this->assertArrayHasKey('tokenEstimate', $response['body']);
        $this->assertArrayHasKey('includedSections', $response['body']);
        $this->assertGreaterThan(0, $response['body']['tokenEstimate']);
    }

    public function test_context_endpoint_minimal_mode(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/context', ['minimal' => 'true']);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['metrics'], $response['body']['includedSections']);
    }

    public function test_context_endpoint_sections_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/context', ['sections' => 'metrics,cycles']);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['metrics', 'cycles'], $response['body']['includedSections']);
    }

    public function test_context_endpoint_strict_with_unknown_entrypoint_returns_empty(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/context', [
            'entrypoint' => 'Unknown::doesNotExist',
            'strict' => 'true',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('', $response['body']['markdown']);
        $this->assertSame(0, $response['body']['tokenEstimate']);
        $this->assertEmpty($response['body']['includedSections']);
    }

    public function test_appmap_requires_catalog_query_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/appmap');

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required query param: catalog', $response['body']['error']);
    }

    public function test_appmap_diff_requires_baseline_and_current_catalog_query_params(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/appmap-diff');

        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Missing required query params: baselineCatalog and currentCatalog',
            $response['body']['error']
        );
    }

    public function test_compliance_monitor_requires_baseline_current_and_policy_query_params(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/compliance-monitor');

        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Missing required query params: baselineCatalog, currentCatalog, and policy',
            $response['body']['error']
        );
    }

    public function test_deployment_map_requires_catalog_query_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/deployment-map');

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required query param: catalog', $response['body']['error']);
    }

    public function test_devops_map_requires_catalog_query_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/devops-map');

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required query param: catalog', $response['body']['error']);
    }

    public function test_website_map_requires_catalog_query_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $response = $api->handle('GET', '/api/v1/website-map');

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required query param: catalog', $response['body']['error']);
    }

    public function test_diagram_rejects_invalid_type(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());

        $catalog = $this->createCatalogWithTwoServices();
        $response = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'invalid',
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid type. Use dependency, sequence, or c4container', $response['body']['error']);
    }

    public function test_appmap_isolates_failing_service_and_skips_project_autoload(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();
        $base = dirname($catalog);

        file_put_contents($base . '/svc-a/vendor/autoload.php', "<?php\nthrow new RuntimeException('autoload should not run');\n");
        $config = json_decode((string) file_get_contents($base . '/svc-b/flow-engine.json'), true);
        $config['context']['type'] = 'unsupported-context';
        file_put_contents($base . '/svc-b/flow-engine.json', (string) json_encode($config));

        $response = $api->handle('GET', '/api/v1/appmap', ['catalog' => $catalog]);

        $this->assertSame(200, $response['status']);
        $services = array_column($response['body']['appmap']['services'], null, 'name');
        $this->assertSame('ok', $services['svc-a']['status']);
        $this->assertGreaterThan(0, $services['svc-a']['stats']['nodeCount']);
        $this->assertSame('failed', $services['svc-b']['status']);
        $this->assertStringContainsString('unsupported-context', $services['svc-b']['error']);
    }

    public function test_appmap_drift_refuses_maps_with_failed_services(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();
        $config = dirname($catalog) . '/svc-b/flow-engine.json';
        $decoded = json_decode((string) file_get_contents($config), true);
        $decoded['context']['type'] = 'unsupported-context';
        file_put_contents($config, (string) json_encode($decoded));

        $response = $api->handle('GET', '/api/v1/appmap-diff', [
            'baselineCatalog' => $catalog,
            'currentCatalog' => $catalog,
        ]);

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('svc-b', $response['body']['error']);
    }

    public function test_appmap_and_diagram_endpoints_return_data(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $appmap = $api->handle('GET', '/api/v1/appmap', ['catalog' => $catalog]);
        $this->assertSame(200, $appmap['status']);
        $this->assertSame('ok', $appmap['body']['status']);
        $this->assertArrayHasKey('services', $appmap['body']['appmap']);

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'dependency',
        ]);
        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame('dependency', $diagram['body']['type']);
        $this->assertStringContainsString('graph LR', $diagram['body']['mermaid']);
    }

    public function test_deployment_map_returns_docker_backed_metadata(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithDocker();

        $response = $api->handle('GET', '/api/v1/deployment-map', ['catalog' => $catalog]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertArrayHasKey('detectedComposeFiles', $response['body']);
        $this->assertArrayHasKey('dockerfiles', $response['body']);
        $this->assertArrayHasKey('environmentFiles', $response['body']);
        $this->assertArrayHasKey('serviceMappings', $response['body']);
        $this->assertArrayHasKey('containers', $response['body']);
        $this->assertContains('production', $response['body']['environments']);
        $this->assertNotEmpty($response['body']['detectedComposeFiles']);
        $this->assertNotEmpty($response['body']['serviceMappings']);
    }

    public function test_appmap_diff_endpoint_returns_drift_payload(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/appmap-diff', [
            'baselineCatalog' => $catalog,
            'currentCatalog' => $catalog,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertSame($catalog, $response['body']['baselineCatalog']);
        $this->assertSame($catalog, $response['body']['currentCatalog']);
        $this->assertArrayHasKey('drift', $response['body']);
        $this->assertArrayHasKey('summary', $response['body']['drift']);
    }

    public function test_appmap_diff_endpoint_supports_optional_policy_evaluation(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();
        $policyPath = dirname($catalog) . DIRECTORY_SEPARATOR . 'appmap-policy.json';
        file_put_contents($policyPath, (string) json_encode([
            'thresholds' => [
                'servicesAddedMax' => 0,
            ],
            'blockers' => [
                'breakingDependencyChanges' => false,
            ],
        ], JSON_PRETTY_PRINT));

        $response = $api->handle('GET', '/api/v1/appmap-diff', [
            'baselineCatalog' => $catalog,
            'currentCatalog' => $catalog,
            'policy' => $policyPath,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertArrayHasKey('gate', $response['body']);
        $this->assertArrayHasKey('passed', $response['body']['gate']);
        $this->assertArrayHasKey('reasons', $response['body']['gate']);
    }

    public function test_compliance_monitor_endpoint_returns_pass_status_when_policy_passes(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();
        $policyPath = dirname($catalog) . DIRECTORY_SEPARATOR . 'compliance-policy-pass.json';
        file_put_contents($policyPath, (string) json_encode([
            'version' => '1.0',
            'thresholds' => [
                'servicesAddedMax' => 0,
                'breakingChangesMax' => 0,
            ],
            'blockers' => [
                'breakingDependencyChanges' => true,
            ],
        ], JSON_PRETTY_PRINT));

        $response = $api->handle('GET', '/api/v1/compliance-monitor', [
            'baselineCatalog' => $catalog,
            'currentCatalog' => $catalog,
            'policy' => $policyPath,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertArrayHasKey('drift', $response['body']);
        $this->assertArrayHasKey('gate', $response['body']);
        $this->assertArrayHasKey('monitor', $response['body']);
        $this->assertSame('pass', $response['body']['monitor']['status']);
        $this->assertFalse($response['body']['monitor']['approvalRequired']);
    }

    public function test_compliance_monitor_endpoint_returns_warn_status_for_threshold_only_violation(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        [$baselineCatalog, $currentCatalog] = $this->createCatalogPairWithAdditionalService();

        $policyPath = dirname($baselineCatalog) . DIRECTORY_SEPARATOR . 'compliance-policy-warn.json';
        file_put_contents($policyPath, (string) json_encode([
            'version' => '1.0',
            'thresholds' => [
                'servicesAddedMax' => 0,
            ],
            'blockers' => [
                'breakingDependencyChanges' => false,
            ],
        ], JSON_PRETTY_PRINT));

        $response = $api->handle('GET', '/api/v1/compliance-monitor', [
            'baselineCatalog' => $baselineCatalog,
            'currentCatalog' => $currentCatalog,
            'policy' => $policyPath,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('warn', $response['body']['monitor']['status']);
        $this->assertTrue($response['body']['monitor']['approvalRequired']);
        $this->assertGreaterThanOrEqual(1, $response['body']['monitor']['riskSummary']['warnCount']);
        $this->assertSame(0, $response['body']['monitor']['riskSummary']['failCount']);
    }

    public function test_compliance_monitor_endpoint_returns_fail_status_when_blocker_is_triggered(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        [$baselineCatalog, $currentCatalog] = $this->createCatalogPairWithAdditionalService();

        $policyPath = dirname($baselineCatalog) . DIRECTORY_SEPARATOR . 'compliance-policy-fail.json';
        file_put_contents($policyPath, (string) json_encode([
            'version' => '1.0',
            'blockers' => [
                'breakingDependencyChanges' => true,
            ],
        ], JSON_PRETTY_PRINT));

        $response = $api->handle('GET', '/api/v1/compliance-monitor', [
            'baselineCatalog' => $currentCatalog,
            'currentCatalog' => $baselineCatalog,
            'policy' => $policyPath,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertFalse($response['body']['gate']['passed']);
        $this->assertSame('fail', $response['body']['monitor']['status']);
        $this->assertTrue($response['body']['monitor']['approvalRequired']);
        $this->assertGreaterThanOrEqual(1, $response['body']['monitor']['riskSummary']['failCount']);
    }

    public function test_compliance_monitor_endpoint_rejects_invalid_policy_file(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/compliance-monitor', [
            'baselineCatalog' => $catalog,
            'currentCatalog' => $catalog,
            'policy' => 'missing-policy.json',
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Invalid policy file: missing-policy.json', $response['body']['error']);
    }

    public function test_diagram_accepts_c4container_type(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'c4container',
        ]);

        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame('c4container', $diagram['body']['type']);
        $this->assertStringContainsString('C4Container', $diagram['body']['mermaid']);
    }

    public function test_deployment_map_endpoint_returns_mvp_payload(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/deployment-map', [
            'catalog' => $catalog,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertSame($catalog, $response['body']['catalog']);
        $this->assertArrayHasKey('services', $response['body']);
        $this->assertArrayHasKey('environments', $response['body']);
        $this->assertArrayHasKey('runtimeDistribution', $response['body']);
        $this->assertArrayHasKey('infrastructureDependencies', $response['body']);
        $this->assertArrayHasKey('coverageWarnings', $response['body']);
        $this->assertArrayHasKey('generatedAt', $response['body']);
    }

    public function test_devops_map_endpoint_returns_mvp_payload(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/devops-map', [
            'catalog' => $catalog,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertSame($catalog, $response['body']['catalog']);
        $this->assertArrayHasKey('stages', $response['body']);
        $this->assertArrayHasKey('highRiskCount', $response['body']);
        $this->assertArrayHasKey('blockerCount', $response['body']);
        $this->assertArrayHasKey('bottlenecks', $response['body']);
        $this->assertArrayHasKey('releaseSummary', $response['body']);
        $this->assertArrayHasKey('generatedAt', $response['body']);
    }

    public function test_website_map_endpoint_returns_mvp_payload(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/website-map', [
            'catalog' => $catalog,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('ok', $response['body']['status']);
        $this->assertSame($catalog, $response['body']['catalog']);
        $this->assertArrayHasKey('pages', $response['body']);
        $this->assertArrayHasKey('routes', $response['body']);
        $this->assertArrayHasKey('entrypoints', $response['body']);
        $this->assertArrayHasKey('summary', $response['body']);
        $this->assertArrayHasKey('generatedAt', $response['body']);
    }

    public function test_diagram_uses_audience_default_when_type_missing(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'audience' => 'architecture',
        ]);

        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame('c4container', $diagram['body']['type']);
        $this->assertSame('architecture', $diagram['body']['audience']);
        $this->assertStringContainsString('C4Container', $diagram['body']['mermaid']);
    }

    public function test_diagram_can_skip_inconsistencies_for_export_payloads(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'dependency',
            'includeInconsistencies' => 'false',
        ]);

        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame([], $diagram['body']['inconsistencies']);
    }

    public function test_diagram_accepts_include_inconsistencies_alias_param(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'dependency',
            'include_inconsistencies' => 'false',
        ]);

        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame([], $diagram['body']['inconsistencies']);
    }

    public function test_diagram_type_param_takes_precedence_over_audience_default(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $diagram = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'type' => 'sequence',
            'audience' => 'architecture',
        ]);

        $this->assertSame(200, $diagram['status']);
        $this->assertSame('ok', $diagram['body']['status']);
        $this->assertSame('sequence', $diagram['body']['type']);
    }

    public function test_diagram_rejects_invalid_audience(): void
    {
        $api = new ReadOnlyApi($this->exampleProjectPath());
        $catalog = $this->createCatalogWithTwoServices();

        $response = $api->handle('GET', '/api/v1/diagram', [
            'catalog' => $catalog,
            'audience' => 'marketing',
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'Invalid audience. Use engineering or architecture',
            $response['body']['error']
        );
    }

    private function exampleProjectPath(): string
    {
        return __DIR__ . '/../../Infrastructure/Fixtures/ExampleProject';
    }

    private function createCatalogWithTwoServices(): string
    {
        $fixture = $this->exampleProjectPath();
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-api-test-' . uniqid('', true);
        $this->tmpDir = $tmpBase;

        $serviceA = $tmpBase . DIRECTORY_SEPARATOR . 'svc-a';
        $serviceB = $tmpBase . DIRECTORY_SEPARATOR . 'svc-b';

        $this->copyDirectory($fixture, $serviceA);
        $this->copyDirectory($fixture, $serviceB);

        $catalogPath = $tmpBase . DIRECTORY_SEPARATOR . 'flow-services.json';
        $content = [
            'version' => '1.0',
            'services' => [
                ['name' => 'svc-a', 'path' => $serviceA],
                ['name' => 'svc-b', 'path' => $serviceB],
            ],
        ];

        file_put_contents($catalogPath, (string) json_encode($content, JSON_PRETTY_PRINT));

        return $catalogPath;
    }

    private function createCatalogWithDocker(): string
    {
        $catalogPath = $this->createCatalogWithTwoServices();
        $tmpBase = $this->tmpDir ?? dirname($catalogPath);

        file_put_contents($tmpBase . DIRECTORY_SEPARATOR . '.env.production', "APP_ENV=production\n");
        file_put_contents($tmpBase . DIRECTORY_SEPARATOR . 'docker-compose.prod.yml', <<<YAML
services:
  svc-a:
    build:
      context: ./svc-a
      dockerfile: Dockerfile
    env_file:
      - .env.production
    networks:
      system:
        aliases:
          - svc-a.internal
  svc-b:
    build:
      context: ./svc-b
      dockerfile: Dockerfile
    depends_on:
      - svc-a
    networks:
      - system
networks:
  system: {}
YAML);

        return $catalogPath;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createCatalogPairWithAdditionalService(): array
    {
        $baselineCatalog = $this->createCatalogWithTwoServices();
        $tmpBase = $this->tmpDir ?? dirname($baselineCatalog);
        $fixture = $this->exampleProjectPath();

        $serviceC = $tmpBase . DIRECTORY_SEPARATOR . 'svc-c';
        $this->copyDirectory($fixture, $serviceC);

        $currentCatalog = $tmpBase . DIRECTORY_SEPARATOR . 'flow-services-current.json';
        $content = [
            'version' => '1.0',
            'services' => [
                ['name' => 'svc-a', 'path' => $tmpBase . DIRECTORY_SEPARATOR . 'svc-a'],
                ['name' => 'svc-b', 'path' => $tmpBase . DIRECTORY_SEPARATOR . 'svc-b'],
                ['name' => 'svc-c', 'path' => $serviceC],
            ],
        ];
        file_put_contents($currentCatalog, (string) json_encode($content, JSON_PRETTY_PRINT));

        return [$baselineCatalog, $currentCatalog];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException("Source dir not found: {$source}");
        }

        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException("Cannot create dir: {$target}");
        }

        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException("Cannot read dir: {$source}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $target . DIRECTORY_SEPARATOR . $item;

            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }

            copy($from, $to);
        }
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $current = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($current)) {
                $this->deleteDirectory($current);
            } else {
                @unlink($current);
            }
        }

        @rmdir($path);
    }
}
