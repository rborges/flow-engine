<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\MermaidDiagramGenerator;
use PHPUnit\Framework\TestCase;

final class MermaidDiagramGeneratorC4Test extends TestCase
{
    private MermaidDiagramGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MermaidDiagramGenerator();
    }

    // -------------------------------------------------------------------------
    // c4Container — structure
    // -------------------------------------------------------------------------

    public function test_c4container_starts_with_keyword(): void
    {
        $diagram = $this->generator->c4Container([]);

        $this->assertStringStartsWith('C4Container', $diagram);
    }

    public function test_c4container_includes_title(): void
    {
        $diagram = $this->generator->c4Container([]);

        $this->assertStringContainsString('title Container Diagram', $diagram);
    }

    public function test_c4container_renders_service_as_container_element(): void
    {
        $appmap = $this->buildAppmap(
            services: [['name' => 'api', 'languages' => ['php'], 'stats' => ['nodeCount' => 50], 'endpoints' => []]],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertStringContainsString('Container(', $diagram);
        $this->assertStringContainsString('api', $diagram);
        $this->assertStringContainsString('php', $diagram);
    }

    public function test_c4container_renders_endpoint_count_in_description(): void
    {
        $appmap = $this->buildAppmap(
            services: [[
                'name'      => 'api',
                'languages' => ['php'],
                'stats'     => ['nodeCount' => 10],
                'endpoints' => [['method' => 'GET', 'path' => '/users', 'handler' => 'X']],
            ]],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertStringContainsString('1 endpoints', $diagram);
    }

    public function test_c4container_renders_service_edge_as_rel(): void
    {
        $appmap = $this->buildAppmap(
            services: [
                ['name' => 'frontend', 'languages' => ['typescript'], 'stats' => ['nodeCount' => 20], 'endpoints' => []],
                ['name' => 'backend',  'languages' => ['php'],        'stats' => ['nodeCount' => 80], 'endpoints' => []],
            ],
            serviceEdges: [['from' => 'frontend', 'to' => 'backend', 'type' => 'http', 'count' => 3]],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertStringContainsString('Rel(', $diagram);
        $this->assertStringContainsString('http', $diagram);
    }

    public function test_c4container_deduplicates_service_edges(): void
    {
        $appmap = $this->buildAppmap(
            services: [
                ['name' => 'a', 'languages' => ['php'], 'stats' => ['nodeCount' => 10], 'endpoints' => []],
                ['name' => 'b', 'languages' => ['php'], 'stats' => ['nodeCount' => 10], 'endpoints' => []],
            ],
            serviceEdges: [
                ['from' => 'a', 'to' => 'b', 'type' => 'http', 'count' => 1],
                ['from' => 'a', 'to' => 'b', 'type' => 'http', 'count' => 2],
            ],
        );

        $diagram = $this->generator->c4Container($appmap);

        // Only one Rel between a and b
        $this->assertSame(1, substr_count($diagram, 'Rel(svc_'));
    }

    public function test_c4container_adds_system_ext_for_unresolved_external_http(): void
    {
        $appmap = $this->buildAppmap(
            services: [
                ['name' => 'backend', 'languages' => ['php'], 'stats' => ['nodeCount' => 80], 'endpoints' => []],
            ],
            integrationEdges: [[
                'type'        => 'http',
                'fromService' => 'backend',
                'toService'   => null,
                'target'      => 'https://payments.example.test/v1/charges',
                'fromNode'    => 'Backend::pay',
            ]],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertStringContainsString('System_Ext(', $diagram);
        $this->assertStringContainsString('payments.example.test', $diagram);
        $this->assertStringContainsString('Rel(', $diagram);
    }

    public function test_c4container_skips_resolved_integration_edges_for_system_ext(): void
    {
        // toService is set → already a Container relationship, not external
        $appmap = $this->buildAppmap(
            services: [
                ['name' => 'a', 'languages' => ['php'], 'stats' => ['nodeCount' => 10], 'endpoints' => []],
                ['name' => 'b', 'languages' => ['php'], 'stats' => ['nodeCount' => 10], 'endpoints' => []],
            ],
            integrationEdges: [[
                'type'        => 'http',
                'fromService' => 'a',
                'toService'   => 'b',
                'target'      => 'https://b.internal/api',
                'fromNode'    => 'A::call',
            ]],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertStringNotContainsString('System_Ext(', $diagram);
    }

    public function test_c4container_deduplicates_external_hosts(): void
    {
        $appmap = $this->buildAppmap(
            services: [
                ['name' => 'svc', 'languages' => ['php'], 'stats' => ['nodeCount' => 10], 'endpoints' => []],
            ],
            integrationEdges: [
                ['type' => 'http', 'fromService' => 'svc', 'toService' => null,
                 'target' => 'https://payments.example.test/charges', 'fromNode' => 'X::a'],
                ['type' => 'http', 'fromService' => 'svc', 'toService' => null,
                 'target' => 'https://payments.example.test/refunds', 'fromNode' => 'X::b'],
            ],
        );

        $diagram = $this->generator->c4Container($appmap);

        $this->assertSame(1, substr_count($diagram, 'payments.example.test'));
    }

    public function test_c4container_empty_appmap_produces_valid_output(): void
    {
        $diagram = $this->generator->c4Container([]);

        $this->assertIsString($diagram);
        $this->assertStringStartsWith('C4Container', $diagram);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>>      $services
     * @param array<int, array<string, mixed>>      $serviceEdges
     * @param array<int, array<string, mixed>>      $integrationEdges
     * @return array<string, mixed>
     */
    private function buildAppmap(
        array $services = [],
        array $serviceEdges = [],
        array $integrationEdges = []
    ): array {
        return [
            'services'         => $services,
            'serviceEdges'     => $serviceEdges,
            'integrationEdges' => $integrationEdges,
            'inconsistencies'  => [],
            'generatedAt'      => '2026-02-19T00:00:00+00:00',
        ];
    }

    public function test_failed_service_is_marked_in_diagrams(): void
    {
        $appmap = $this->buildAppmap(
            services: [['name' => 'billing', 'status' => 'failed', 'languages' => [], 'stats' => ['nodeCount' => 0], 'endpoints' => []]],
        );

        $this->assertStringContainsString('analysis failed', $this->generator->c4Container($appmap));
        $this->assertStringContainsString('analysis failed', $this->generator->dependencyGraph($appmap));
        $this->assertStringNotContainsString('0 nodes', $this->generator->c4Container($appmap));
    }
}
