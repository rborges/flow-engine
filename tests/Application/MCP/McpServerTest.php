<?php

namespace Tests\Application\MCP;

use FlowEngine\Application\MCP\McpServer;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Infrastructure\Paths\StateDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class McpServerTest extends TestCase
{
    private McpServer $server;
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        $this->server = new McpServer();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->deleteDirectory($this->tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // initialize
    // -------------------------------------------------------------------------

    public function test_initialize_returns_server_info_and_capabilities(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-03-26',
                'clientInfo'      => ['name' => 'test', 'version' => '1.0'],
                'capabilities'    => [],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);

        $result = $response['result'];
        $this->assertSame('2025-03-26', $result['protocolVersion']);
        $this->assertArrayHasKey('capabilities', $result);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertSame('flow-engine', $result['serverInfo']['name']);
        $this->assertNotEmpty($result['serverInfo']['version']);
    }

    // -------------------------------------------------------------------------
    // tools/list
    // -------------------------------------------------------------------------

    public function test_tools_list_returns_eight_tools(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'tools/list',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $tools = $response['result']['tools'];
        $this->assertCount(8, $tools);
    }

    public function test_tools_list_declares_read_only_annotations_on_every_tool(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'tools/list',
        ]);

        $this->assertNotNull($response);
        $tools = $response['result']['tools'];

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('annotations', $tool, "Tool '{$tool['name']}' must declare annotations");
            $annotations = $tool['annotations'];
            $this->assertTrue($annotations['readOnlyHint'], "Tool '{$tool['name']}' must declare readOnlyHint=true");
            $this->assertFalse($annotations['destructiveHint'], "Tool '{$tool['name']}' must declare destructiveHint=false");
            $this->assertTrue($annotations['idempotentHint'], "Tool '{$tool['name']}' must declare idempotentHint=true");
            $this->assertFalse($annotations['openWorldHint'], "Tool '{$tool['name']}' must declare openWorldHint=false");
        }
    }

    public function test_tools_list_old_tools_still_require_project_in_schema(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/list',
        ]);

        $this->assertNotNull($response);
        $tools = $response['result']['tools'];

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            if (in_array($tool['name'], ['flow_map', 'flow_lookup', 'flow_infra_map'], true)) {
                $this->assertArrayHasKey('project', $tool['inputSchema']['properties']);
                $this->assertArrayHasKey('catalog', $tool['inputSchema']['properties']);
                continue;
            }
            $required = $tool['inputSchema']['required'] ?? [];
            $this->assertContains('project', $required, "Tool '{$tool['name']}' must require 'project'");
        }
    }

    public function test_tools_list_tool_names_are_expected(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/list',
        ]);

        $names = array_column($response['result']['tools'], 'name');
        $this->assertContains('flow_context', $names);
        $this->assertContains('flow_map', $names);
        $this->assertContains('flow_lookup', $names);
        $this->assertContains('flow_infra_map', $names);
        $this->assertContains('flow_find', $names);
        $this->assertContains('flow_impact', $names);
        $this->assertContains('flow_risk', $names);
        $this->assertContains('flow_refactor_plan', $names);
    }

    public function test_tools_list_descriptions_explain_when_to_use_new_tools(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 41,
            'method'  => 'tools/list',
        ]);

        $tools = [];
        foreach ($response['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertStringContainsString('Use first', $tools['flow_map']['description']);
        $this->assertStringContainsString('Docker, compose, proxy', $tools['flow_infra_map']['description']);
        $this->assertStringContainsString('Use after flow_map', $tools['flow_lookup']['description']);
        $this->assertStringContainsString('Current support:', $tools['flow_map']['description']);
        $this->assertStringContainsString('TypeScript/JavaScript', $tools['flow_lookup']['description']);
    }

    // -------------------------------------------------------------------------
    // initialized (notification — no response)
    // -------------------------------------------------------------------------

    public function test_initialized_notification_returns_null(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'method'  => 'initialized',
        ]);

        $this->assertNull($response);
    }

    public function test_notifications_initialized_returns_null(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/initialized',
        ]);

        $this->assertNull($response);
    }

    // -------------------------------------------------------------------------
    // ping
    // -------------------------------------------------------------------------

    public function test_ping_returns_empty_result(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 5,
            'method'  => 'ping',
        ]);

        $this->assertNotNull($response);
        $this->assertSame(5, $response['id']);
        $this->assertArrayHasKey('result', $response);
    }

    // -------------------------------------------------------------------------
    // unknown method
    // -------------------------------------------------------------------------

    public function test_unknown_method_returns_error_32601(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 6,
            'method'  => 'nonexistent/method',
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
        $this->assertStringContainsString('Method not found', $response['error']['message']);
    }

    // -------------------------------------------------------------------------
    // tools/call — unknown tool
    // -------------------------------------------------------------------------

    public function test_tool_call_unknown_tool_returns_is_error_true(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 7,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'nonexistent_tool',
                'arguments' => [],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Unknown tool', $response['result']['content'][0]['text']);
    }

    // -------------------------------------------------------------------------
    // tools/call — missing required param
    // -------------------------------------------------------------------------

    public function test_tool_call_missing_required_param_returns_is_error_true(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 8,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_risk',
                'arguments' => [],  // missing 'project' and 'node'
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Missing required parameter', $response['result']['content'][0]['text']);
    }

    public function test_flow_map_project_returns_structural_summary(): void
    {
        $project = $this->createProjectFixture();

        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 9,
            'method'  => 'tools/call',
            'params'  => [
                'name' => 'flow_map',
                'arguments' => [
                    'project' => $project,
                    'depth' => 1,
                ],
            ],
        ]);

        $payload = json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('project_map', $payload['kind']);
        $this->assertSame('single', $payload['scope']);
        $this->assertArrayHasKey('purpose', $payload);
        $this->assertArrayHasKey('structure', $payload);
        $this->assertArrayHasKey('areas', $payload['structure']);
        $this->assertArrayHasKey('entrypoints', $payload['structure']);
        $this->assertArrayHasKey('recommendedReads', $payload['navigation']);
        // In summary mode, capabilities is omitted; language info lives in project
        $this->assertSame(['php', 'blade'], $payload['project']['languages']);
    }

    public function test_flow_map_depth_two_expands_recommended_lookup_targets(): void
    {
        $project = $this->createProjectFixture();

        // recommendedLookupTargets only exists in mode=full
        $depthOne = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
            'mode'    => 'full',
            'depth'   => 1,
        ]);
        $depthTwo = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
            'mode'    => 'full',
            'depth'   => 2,
        ]);

        $this->assertGreaterThanOrEqual(
            count($depthOne['navigation']['recommendedLookupTargets']),
            count($depthTwo['navigation']['recommendedLookupTargets'])
        );
    }

    public function test_flow_infra_map_project_returns_infrastructure_graph(): void
    {
        $project = $this->createInfraOnlyProjectFixture();

        $payload = $this->dispatchJsonTool('flow_infra_map', [
            'project' => $project,
        ]);

        $this->assertSame('infra_map', $payload['kind']);
        $this->assertSame('single', $payload['scope']);
        $this->assertArrayHasKey('files', $payload);
        $this->assertArrayHasKey('docker', $payload);
        $this->assertArrayHasKey('proxy', $payload);
        $this->assertArrayHasKey('web', $payload);
        $this->assertArrayHasKey('scripts', $payload);
        $this->assertNotEmpty($payload['docker']['detectedComposeFiles']);
        $this->assertNotEmpty($payload['docker']['containers']);
        $this->assertNotEmpty($payload['proxy']['caddy']['sites']);
        $this->assertNotEmpty($payload['web']['robots']);
        $this->assertNotEmpty($payload['scripts']['scripts']);
        $this->assertNotEmpty($payload['edges']);

        $containerByName = [];
        foreach ($payload['docker']['containers'] as $container) {
            $containerByName[$container['name']] = $container;
        }

        $this->assertArrayHasKey('app', $containerByName);
        $this->assertSame('unless-stopped', $containerByName['app']['restart']);
        $this->assertArrayHasKey('healthcheck', $containerByName['app']);
        $this->assertArrayHasKey('logging', $containerByName['app']);
        $this->assertContains('APP_ENV', $containerByName['app']['environmentKeys']);

        $composeFiles = implode("\n", $payload['docker']['detectedComposeFiles']);
        $this->assertStringNotContainsString('.worktrees', $composeFiles);
        $this->assertStringNotContainsString('node_modules', $composeFiles);
    }

    public function test_flow_infra_map_does_not_depend_on_valid_source_analysis_config(): void
    {
        $project = $this->createInfraOnlyProjectFixture();
        file_put_contents($project . '/flow-engine.json', '{invalid');

        $payload = $this->dispatchJsonTool('flow_infra_map', [
            'project' => $project,
        ]);

        self::assertSame('infra_map', $payload['kind']);
        self::assertNotEmpty($payload['docker']['detectedComposeFiles']);
        self::assertStringContainsString(
            'Source analysis configuration is invalid',
            implode("\n", $payload['warnings']),
        );
    }

    public function test_flow_map_without_code_nodes_points_to_infra_map(): void
    {
        $project = $this->createUnsupportedProjectFixture();

        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertStringContainsString('flow_infra_map', implode("\n", $payload['warnings']));
        $this->assertStringContainsString('flow_infra_map', $payload['navigation']['nextStep']);
    }

    public function test_flow_lookup_area_summary_returns_focused_json(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'area',
            'target' => 'App\\Http',
            'format' => 'summary',
        ]);

        $this->assertSame('lookup_result', $payload['kind']);
        $this->assertSame('area', $payload['targetType']);
        $this->assertSame('App\\Http', $payload['target']);
        $this->assertNotEmpty($payload['recommendedReads']);
    }

    public function test_flow_lookup_entrypoint_context_returns_trace_context(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'entrypoint',
            'target' => 'App\\Http\\OrderController::store',
            'format' => 'context',
            'depth' => 1,
        ]);

        $this->assertSame('entrypoint', $payload['targetType']);
        $this->assertArrayHasKey('context', $payload);
        $this->assertArrayHasKey('pathPreviews', $payload['context']);
    }

    public function test_flow_lookup_boundary_summary_works_for_single_project(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'boundary',
            'target' => 'http',
            'format' => 'summary',
        ]);

        $this->assertSame('boundary', $payload['targetType']);
        $this->assertSame('http', $payload['target']);
    }

    public function test_flow_lookup_diagram_returns_mermaid_for_entrypoint(): void
    {
        $project = $this->createProjectFixture();
        $markdown = $this->dispatchTextTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'entrypoint',
            'target' => 'App\\Http\\OrderController::store',
            'format' => 'diagram',
            'depth' => 1,
        ]);

        $this->assertStringContainsString('```mermaid', $markdown);
        $this->assertStringContainsString('flowchart LR', $markdown);
    }

    public function test_flow_lookup_area_diagram_returns_mermaid_for_project_area(): void
    {
        $project = $this->createProjectFixture();
        $markdown = $this->dispatchTextTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'area',
            'target' => 'App\\Http',
            'format' => 'diagram',
        ]);

        $this->assertStringContainsString('```mermaid', $markdown);
        $this->assertStringContainsString('graph LR', $markdown);
    }

    public function test_flow_map_catalog_returns_services_and_paths(): void
    {
        $catalog = $this->createCatalogFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'catalog' => $catalog,
            'scope' => 'catalog',
            'depth' => 1,
        ]);

        $this->assertSame('catalog', $payload['scope']);
        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertNotEmpty($payload['structure']['services']);
        $this->assertArrayHasKey('integrationPoints', $payload['structure']);
        $this->assertSame(['php', 'blade'], $payload['capabilities']['configuredScanLanguages']);
        $this->assertSame(['php', 'blade'], $payload['capabilities']['detectedProjectLanguages']);
    }

    public function test_catalog_code_analysis_tools_propagate_service_cache_warnings(): void
    {
        $catalog = $this->createCatalogFixture();
        $serviceRoot = dirname($catalog) . '/svc-a';
        $stateRoot = dirname($catalog) . '/catalog-state';
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $stateRoot);

        try {
            $this->dispatchJsonTool('flow_map', ['catalog' => $catalog]);
            $flowFile = StateDirectory::forProjectRoot($serviceRoot) . '/cache/flow.json.gz';
            file_put_contents($flowFile, 'corrupt');

            $payload = $this->dispatchJsonTool('flow_map', ['catalog' => $catalog]);
            self::assertStringContainsString(
                'svc-a: Flow cache is corrupt',
                implode("\n", $payload['warnings'] ?? []),
            );

            file_put_contents($flowFile, 'corrupt');
            $markdown = $this->dispatchTextTool('flow_lookup', [
                'catalog' => $catalog,
                'targetType' => 'service',
                'target' => 'svc-a',
                'format' => 'diagram',
            ]);
            self::assertStringContainsString('**Warning:** svc-a: Flow cache is corrupt', $markdown);

        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
        }
    }

    public function test_flow_infra_map_catalog_summary_counts_service_sections(): void
    {
        $catalog = $this->createInfraCatalogFixture();

        $payload = $this->dispatchJsonTool('flow_infra_map', [
            'catalog' => $catalog,
            'sections' => ['proxy'],
        ]);

        $this->assertSame('catalog', $payload['scope']);
        $this->assertSame(1, $payload['summary']['proxyFileCount']);
        $this->assertNotEmpty($payload['services'][0]['proxy']['files']);
    }

    public function test_flow_infra_map_catalog_does_not_depend_on_service_source_config(): void
    {
        $catalog = $this->createInfraCatalogFixture();
        file_put_contents(dirname($catalog) . '/infra-catalog-svc/flow-engine.json', '{invalid');

        $payload = $this->dispatchJsonTool('flow_infra_map', ['catalog' => $catalog]);

        self::assertSame('catalog', $payload['scope']);
        self::assertNotEmpty($payload['services'][0]['proxy']['files']);
        self::assertStringContainsString(
            'Source analysis configuration is invalid',
            implode("\n", $payload['warnings']),
        );
    }

    public function test_flow_infra_map_invalid_sections_returns_error(): void
    {
        $project = $this->createInfraOnlyProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 78,
            'method' => 'tools/call',
            'params' => [
                'name' => 'flow_infra_map',
                'arguments' => [
                    'project' => $project,
                    'sections' => ['invalid'],
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Invalid sections value', $response['result']['content'][0]['text']);
    }

    public function test_flow_map_warns_when_only_partial_support_is_detected(): void
    {
        $project = $this->createBladeOnlyProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
            'depth' => 1,
        ]);

        $this->assertSame(['blade'], $payload['project']['languages']);
        $this->assertContains(
            'Detected only partial-support inputs such as Blade templates. Results may be limited to edge extraction.',
            $payload['warnings']
        );
    }

    public function test_flow_map_warns_when_configured_languages_find_no_supported_files(): void
    {
        $project = $this->createUnsupportedProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
            'depth' => 1,
        ]);

        $this->assertSame([], $payload['project']['languages']);
        $this->assertContains('No supported source language was detected in the scanned project files.', $payload['warnings']);
        $this->assertContains('Configured scan languages were found, but no matching analyzable files were detected.', $payload['warnings']);
    }

    public function test_flow_lookup_service_summary_works_for_catalog(): void
    {
        $catalog = $this->createCatalogFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'catalog' => $catalog,
            'targetType' => 'service',
            'target' => 'svc-a',
            'format' => 'summary',
        ]);

        $this->assertSame('service', $payload['targetType']);
        $this->assertSame('svc-a', $payload['target']);
    }

    public function test_flow_lookup_boundary_works_for_catalog(): void
    {
        $catalog = $this->createCatalogFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'catalog' => $catalog,
            'targetType' => 'boundary',
            'target' => 'http',
            'format' => 'summary',
        ]);

        $this->assertSame('boundary', $payload['targetType']);
        $this->assertSame('http', $payload['target']);
    }

    public function test_flow_lookup_diagram_works_for_catalog_service(): void
    {
        $catalog = $this->createCatalogFixture();
        $markdown = $this->dispatchTextTool('flow_lookup', [
            'catalog' => $catalog,
            'targetType' => 'service',
            'target' => 'svc-a',
            'format' => 'diagram',
        ]);

        $this->assertStringContainsString('```mermaid', $markdown);
        $this->assertStringContainsString('graph LR', $markdown);
    }

    public function test_flow_lookup_diagram_works_for_catalog_area(): void
    {
        $catalog = $this->createCatalogFixture();
        $markdown = $this->dispatchTextTool('flow_lookup', [
            'catalog' => $catalog,
            'targetType' => 'area',
            'target' => 'svc-a',
            'format' => 'diagram',
        ]);

        $this->assertStringContainsString('```mermaid', $markdown);
        $this->assertStringContainsString('graph LR', $markdown);
    }

    public function test_flow_lookup_rejects_service_target_without_catalog(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 18,
            'method'  => 'tools/call',
            'params'  => [
                'name' => 'flow_lookup',
                'arguments' => [
                    'project' => $project,
                    'targetType' => 'service',
                    'target' => 'svc-a',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('only valid when using catalog', $response['result']['content'][0]['text']);
    }

    public function test_flow_map_rejects_project_and_catalog_together(): void
    {
        $project = $this->createProjectFixture();
        $catalog = $this->createCatalogFixture();

        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 19,
            'method'  => 'tools/call',
            'params'  => [
                'name' => 'flow_map',
                'arguments' => [
                    'project' => $project,
                    'catalog' => $catalog,
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('either project or catalog', $response['result']['content'][0]['text']);
    }

    public function test_flow_map_without_config_uses_inferred_read_only_scan(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
            'depth' => 1,
        ]);

        $this->assertSame('project_map', $payload['kind']);
        $this->assertSame('inferred_read_only', $payload['metadata']['configResolution']['mode']);
        $this->assertFalse($payload['metadata']['configResolution']['hasConfigFile']);
        $this->assertStringContainsString('No flow-engine.json found; using dynamic inferred read-only scan', implode("\n", $payload['warnings']));
    }

    public function test_flow_map_without_config_does_not_execute_project_autoload(): void
    {
        $project = $this->createConfiglessProjectWithExplodingAutoloadFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 79,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_map',
                'arguments' => [
                    'project' => $project,
                    'depth' => 1,
                ],
            ],
        ]);

        $text = $response['result']['content'][0]['text'] ?? '';

        $this->assertFalse($response['result']['isError'] ?? false, $text);
        $this->assertStringNotContainsString('autoload should not run', $text);

        $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('project_map', $payload['kind']);
        $this->assertSame('inferred_read_only', $payload['metadata']['configResolution']['mode']);
    }

    public function test_flow_lookup_without_config_returns_warning_and_metadata(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project' => $project,
            'targetType' => 'area',
            'target' => 'App\\Http',
            'format' => 'summary',
        ]);

        $this->assertSame('lookup_result', $payload['kind']);
        $this->assertSame('inferred_read_only', $payload['metadata']['configResolution']['mode']);
        $this->assertNotEmpty($payload['recommendedReads']);
        $this->assertStringContainsString('No flow-engine.json found; using dynamic inferred read-only scan', implode("\n", $payload['warnings']));
    }

    public function test_flow_context_without_config_prefixes_note(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();
        $markdown = $this->dispatchTextTool('flow_context', [
            'project' => $project,
            'minimal' => true,
        ]);

        $this->assertStringContainsString('> Note: No flow-engine.json found; using dynamic inferred read-only scan', $markdown);
        $this->assertStringContainsString('## Metrics', $markdown);
        $this->assertStringContainsString('## Recommended Next Lookups', $markdown);
    }

    public function test_flow_context_minimal_invalid_entrypoint_returns_error(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 78,
            'method' => 'tools/call',
            'params' => [
                'name' => 'flow_context',
                'arguments' => [
                    'project' => $project,
                    'minimal' => true,
                    'entrypoint' => 'App\\Http\\MissingController::index',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Node not found', $response['result']['content'][0]['text']);
    }

    public function test_read_only_mcp_tools_include_config_resolution_metadata_without_config(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();

        $impact = $this->dispatchJsonTool('flow_impact', [
            'project' => $project,
            'node' => 'App\\Http\\OrderController::store',
        ]);
        $risk = $this->dispatchJsonTool('flow_risk', [
            'project' => $project,
            'node' => 'App\\Http\\OrderController::store',
        ]);
        $plan = $this->dispatchJsonTool('flow_refactor_plan', [
            'project' => $project,
            'node' => 'App\\Http\\OrderController::store',
        ]);

        $this->assertSame('inferred_read_only', $impact['metadata']['configResolution']['mode']);
        $this->assertSame('inferred_read_only', $risk['metadata']['configResolution']['mode']);
        $this->assertSame('inferred_read_only', $plan['metadata']['configResolution']['mode']);
    }

    public function test_flow_map_without_config_detects_laravel_scan_roots(): void
    {
        $project = $this->createConfiglessLaravelFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame('laravel', $payload['metadata']['configResolution']['detectedContext']);
        $this->assertSame(['app', 'routes', 'resources/views'], $payload['metadata']['configResolution']['includePaths']);
        $this->assertSame(['php', 'blade'], $payload['project']['languages']);
    }

    public function test_flow_map_without_config_detects_laravel_in_subdirectory(): void
    {
        $project = $this->createConfiglessLaravelSubdirectoryFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame('laravel', $payload['metadata']['configResolution']['detectedContext']);
        $this->assertSame(['laravel'], $payload['metadata']['configResolution']['includePaths']);
        $this->assertContains('php', $payload['project']['languages']);
        $this->assertNotEmpty($payload['structure']['areas']);
    }

    public function test_flow_map_without_config_skips_root_worktrees_but_allows_direct_worktree_target(): void
    {
        $project = $this->createConfiglessProjectWithWorktreeFixture();
        $rootPayload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $rootAreas = implode(', ', array_map(static fn(array $area): string => $area['name'], $rootPayload['structure']['areas']));
        $this->assertStringNotContainsString('worktrees.feature', $rootAreas);
        $this->assertContains('.worktrees', $rootPayload['metadata']['configResolution']['ignoredPaths']);

        $worktreePayload = $this->dispatchJsonTool('flow_map', [
            'project' => $project . '/.worktrees/feature',
        ]);

        $this->assertNotContains('.worktrees', $worktreePayload['metadata']['configResolution']['ignoredPaths']);
        $this->assertNotEmpty($worktreePayload['structure']['areas']);
    }

    public function test_flow_map_without_config_detects_flutter_and_python_backend(): void
    {
        $project = $this->createConfiglessFlutterFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame('flutter', $payload['metadata']['configResolution']['detectedContext']);
        $this->assertSame(['python', 'dart'], $payload['project']['languages']);
    }

    public function test_flow_map_without_config_detects_generic_typescript_and_python(): void
    {
        $project = $this->createConfiglessPolyglotFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame('generic', $payload['metadata']['configResolution']['detectedContext']);
        $this->assertSame(['python', 'typescript'], $payload['project']['languages']);
        $this->assertSame(['backend', 'src'], $payload['metadata']['configResolution']['includePaths']);
    }

    public function test_flow_map_without_config_scans_monorepo_apps_and_packages(): void
    {
        $project = $this->createConfiglessMonorepoFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame('generic', $payload['metadata']['configResolution']['detectedContext']);
        $this->assertSame(['apps', 'packages', 'services'], $payload['metadata']['configResolution']['includePaths']);
        $this->assertContains('typescript', $payload['project']['languages']);
        $this->assertContains('go', $payload['project']['languages']);

        $areaNames = array_map(
            static fn(array $area): string => $area['name'],
            $payload['structure']['areas']
        );
        $joined = implode(', ', $areaNames);
        $hasApps = false;
        $hasPackages = false;
        foreach ($areaNames as $name) {
            if (str_starts_with($name, 'apps.')) { $hasApps = true; }
            if (str_starts_with($name, 'packages.')) { $hasPackages = true; }
        }
        $this->assertTrue($hasApps, 'Expected apps.* area, got: ' . $joined);
        $this->assertTrue($hasPackages, 'Expected packages.* area, got: ' . $joined);
    }

    public function test_flow_map_catalog_reports_mixed_config_resolution_per_service(): void
    {
        $catalog = $this->createMixedCatalogFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'catalog' => $catalog,
            'scope' => 'catalog',
        ]);

        $this->assertSame('mixed', $payload['metadata']['configResolution']['mode']);
        $modes = [];
        foreach ($payload['structure']['services'] as $service) {
            $modes[$service['name']] = $service['configResolution']['mode'] ?? null;
        }

        $this->assertSame('explicit_config', $modes['configured']);
        $this->assertSame('inferred_read_only', $modes['configless']);
        $this->assertContains(
            'Catalog includes services analyzed without flow-engine.json: configless.',
            $payload['warnings']
        );
    }

    public function test_flow_map_invalid_existing_config_still_fails_in_read_only_mcp(): void
    {
        $project = $this->createInvalidConfigProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 77,
            'method' => 'tools/call',
            'params' => [
                'name' => 'flow_map',
                'arguments' => ['project' => $project],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Invalid JSON in flow-engine.json', $response['result']['content'][0]['text']);
    }

    public function test_container_without_config_tolerates_missing_file_outside_mcp(): void
    {
        $project = $this->createConfiglessPhpProjectFixture();

        $container = new Container($project);

        $this->assertSame($project, $container->projectRoot());
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function dispatchJsonTool(string $tool, array $arguments): array
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => random_int(100, 999),
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
        ]);

        return json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function dispatchTextTool(string $tool, array $arguments): string
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => random_int(1000, 1999),
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
        ]);

        return $response['result']['content'][0]['text'];
    }

    private function createProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', <<<'JSON'
{
  "version": "1.0",
  "context": {
    "type": "php",
    "autoload": "vendor/autoload.php"
  },
  "scan": {
    "include": ["App/src"],
    "exclude": ["vendor"],
    "extensions": ["php"]
  }
}
JSON);

        $this->writeFile($project . '/vendor/autoload.php', "<?php\n");
        $this->writeFile($project . '/App/src/Views/orders.blade.php', <<<'BLADE'
<div wire:click="save">Save</div>
BLADE);
        $this->writeFile($project . '/App/src/Http/OrderController.php', <<<'PHP'
<?php

namespace App\Http;

use App\Application\OrderService;

final class OrderController
{
    public function __construct(private OrderService $service) {}

    public function store(): void
    {
        $this->service->save();
    }
}
PHP);
        $this->writeFile($project . '/App/src/Application/OrderService.php', <<<'PHP'
<?php

namespace App\Application;

use App\Infrastructure\OrderRepository;

final class OrderService
{
    public function __construct(private OrderRepository $repository) {}

    public function save(): void
    {
        $this->repository->persist();
    }
}
PHP);
        $this->writeFile($project . '/App/src/Infrastructure/OrderRepository.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class OrderRepository
{
    public function persist(): void
    {
    }
}
PHP);
        $this->writeFile($project . '/App/src/Console/SyncCommand.php', <<<'PHP'
<?php

namespace App\Console;

use App\Application\OrderService;

final class SyncCommand
{
    public function __construct(private OrderService $service) {}

    public function handle(): void
    {
        $this->service->save();
    }
}
PHP);

        return $project;
    }

    private function createBladeOnlyProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/blade-only-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', <<<'JSON'
{
  "version": "1.0",
  "context": {
    "type": "php",
    "autoload": "vendor/autoload.php"
  },
  "scan": {
    "include": ["resources/views"],
    "exclude": ["vendor"],
    "extensions": ["php"]
  }
}
JSON);

        $this->writeFile($project . '/vendor/autoload.php', "<?php\n");
        $this->writeFile($project . '/resources/views/livewire/orders/index.blade.php', <<<'BLADE'
<div wire:click="save">Save</div>
BLADE);

        return $project;
    }

    private function createUnsupportedProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/unsupported-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', <<<'JSON'
{
  "version": "1.0",
  "context": {
    "type": "php"
  },
  "scan": {
    "include": ["src"],
    "exclude": [],
    "extensions": ["php"]
  }
}
JSON);

        $this->writeFile($project . '/notes/readme.txt', "ignored\n");

        return $project;
    }

    private function createInfraOnlyProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/infra-only-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/Dockerfile', "FROM php:8.3.1-cli\n");
        $this->writeFile($project . '/docker-compose.yml', <<<'YAML'
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    image: infra-app:1.0.0
    restart: unless-stopped
    environment:
      APP_ENV: production
      SECRET_TOKEN: should-not-be-returned
    ports:
      - "8080:80"
    volumes:
      - ./public:/var/www/html:ro
    depends_on:
      db:
        condition: service_healthy
    networks:
      appnet:
        aliases:
          - app.internal
    healthcheck:
      test: ["CMD", "php", "-v"]
      interval: 10s
      timeout: 5s
      retries: 3
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"
  db:
    image: postgres:16.2-alpine
    networks:
      - appnet
networks:
  appnet: {}
YAML);

        $this->writeFile($project . '/caddy/Caddyfile', <<<'CADDY'
(proxy_headers) {
  header_up Host {host}
}

example.test {
  reverse_proxy app:80 {
    import proxy_headers
  }
}

http://, https:// {
  respond "" 444
}
CADDY);

        $this->writeFile($project . '/public/robots.txt', <<<'ROBOTS'
User-agent: *
Allow: /
Sitemap: https://example.test/sitemap.xml
ROBOTS);
        $this->writeFile($project . '/public/index.html', <<<'HTML'
<!doctype html>
<html>
<head>
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://example.test/">
</head>
</html>
HTML);
        $this->writeFile($project . '/install.sh', <<<'SH'
#!/bin/bash
set -e
docker compose -f docker-compose.yml up -d app
systemctl restart infra-app.service
SH);
        $this->writeFile($project . '/infra-app.service', <<<'SERVICE'
[Service]
ExecStart=/usr/bin/php -S 0.0.0.0:8080
SERVICE);

        $this->writeFile($project . '/.worktrees/feature/docker-compose.yml', <<<'YAML'
services:
  ignored:
    image: busybox:1.36.1
YAML);
        $this->writeFile($project . '/node_modules/pkg/docker-compose.yml', <<<'YAML'
services:
  ignored-node:
    image: busybox:1.36.1
YAML);

        return $project;
    }

    private function createCatalogFixture(): string
    {
        $project = $this->createProjectFixture();
        $serviceA = $this->tmpDir . '/svc-a';
        $serviceB = $this->tmpDir . '/svc-b';

        if (!is_dir($serviceA)) {
            $this->copyDirectory($project, $serviceA);
        }
        if (!is_dir($serviceB)) {
            $this->copyDirectory($project, $serviceB);
        }

        $catalog = $this->tmpDir . '/flow-services.json';
        if (!is_file($catalog)) {
            file_put_contents($catalog, (string) json_encode([
                'version' => '1.0',
                'services' => [
                    ['name' => 'svc-a', 'path' => $serviceA],
                    ['name' => 'svc-b', 'path' => $serviceB],
                ],
            ], JSON_PRETTY_PRINT));
        }

        return $catalog;
    }

    private function createInfraCatalogFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $service = $this->tmpDir . '/infra-catalog-svc';
        $this->writeFile($service . '/caddy/Caddyfile', <<<'CADDY'
example.test {
  reverse_proxy app:80
}
CADDY);

        $catalog = $this->tmpDir . '/infra-flow-services.json';
        if (!is_file($catalog)) {
            file_put_contents($catalog, (string) json_encode([
                'version' => '1.0',
                'services' => [
                    ['name' => 'infra', 'path' => $service],
                ],
            ], JSON_PRETTY_PRINT));
        }

        return $catalog;
    }

    private function createConfiglessPhpProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/src/Http/OrderController.php', <<<'PHP'
<?php

namespace App\Http;

use App\Application\OrderService;

final class OrderController
{
    public function __construct(private OrderService $service) {}

    public function store(): void
    {
        $this->service->save();
    }
}
PHP);
        $this->writeFile($project . '/src/Application/OrderService.php', <<<'PHP'
<?php

namespace App\Application;

use App\Infrastructure\OrderRepository;

final class OrderService
{
    public function __construct(private OrderRepository $repository) {}

    public function save(): void
    {
        $this->repository->persist();
    }
}
PHP);
        $this->writeFile($project . '/src/Infrastructure/OrderRepository.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class OrderRepository
{
    public function persist(): void
    {
    }
}
PHP);

        return $project;
    }

    private function createConfiglessProjectWithExplodingAutoloadFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-project-with-autoload';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/vendor/autoload.php', <<<'PHP'
<?php

throw new RuntimeException('autoload should not run');
PHP);
        $this->writeFile($project . '/src/Http/OrderController.php', <<<'PHP'
<?php

namespace App\Http;

final class OrderController
{
    public function store(): void
    {
    }
}
PHP);

        return $project;
    }

    private function createConfiglessLaravelFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-laravel';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/artisan', "#!/usr/bin/env php\n");
        $this->writeFile($project . '/app/Http/Controllers/OrderController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Application\OrderService;

final class OrderController
{
    public function __construct(private OrderService $service) {}

    public function store(): void
    {
        $this->service->save();
    }
}
PHP);
        $this->writeFile($project . '/app/Application/OrderService.php', <<<'PHP'
<?php

namespace App\Application;

final class OrderService
{
    public function save(): void
    {
    }
}
PHP);
        $this->writeFile($project . '/routes/web.php', <<<'PHP'
<?php

use App\Http\Controllers\OrderController;

Route::post('/orders', [OrderController::class, 'store']);
PHP);
        $this->writeFile($project . '/resources/views/orders/index.blade.php', <<<'BLADE'
<div wire:click="save">Save</div>
BLADE);

        return $project;
    }

    private function createConfiglessLaravelSubdirectoryFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-laravel-subdir';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/laravel/artisan', "#!/usr/bin/env php\n");
        $this->writeFile($project . '/laravel/app/Http/Controllers/OrderController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

final class OrderController
{
    public function index(): void
    {
    }
}
PHP);
        $this->writeFile($project . '/laravel/routes/web.php', <<<'PHP'
<?php

use App\Http\Controllers\OrderController;

Route::get('/orders', [OrderController::class, 'index']);
PHP);

        return $project;
    }

    private function createConfiglessProjectWithWorktreeFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-with-worktree';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/src/App/MainController.php', <<<'PHP'
<?php

namespace App\Main;

final class MainController
{
    public function index(): void
    {
    }
}
PHP);
        $this->writeFile($project . '/.worktrees/feature/src/App/FeatureController.php', <<<'PHP'
<?php

namespace App\Feature;

final class FeatureController
{
    public function index(): void
    {
    }
}
PHP);

        return $project;
    }

    private function createConfiglessFlutterFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-flutter';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/pubspec.yaml', "name: demo_app\n");
        $this->writeFile($project . '/lib/main.dart', <<<'DART'
import 'package:flutter/material.dart';

void main() {
  runApp(const DemoApp());
}

class DemoApp extends StatelessWidget {
  const DemoApp({super.key});

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(home: Placeholder());
  }
}
DART);
        $this->writeFile($project . '/backend/app/service.py', <<<'PY'
def handle() -> None:
    pass
PY);

        return $project;
    }

    private function createConfiglessPolyglotFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-polyglot';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/src/server.ts', <<<'TS'
export function handleRequest(): void {
}
TS);
        $this->writeFile($project . '/backend/app/worker.py', <<<'PY'
def run() -> None:
    pass
PY);

        return $project;
    }

    private function createConfiglessMonorepoFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/configless-monorepo';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/apps/web/src/App.tsx', <<<'TS'
export function App(): string { return 'ok'; }
TS);
        $this->writeFile($project . '/apps/api/src/server.ts', <<<'TS'
export function start(): void {}
TS);
        $this->writeFile($project . '/packages/shared/index.ts', <<<'TS'
export function shared(): number { return 1; }
TS);
        $this->writeFile($project . '/services/worker/main.go', <<<'GO'
package main
func main() {}
GO);

        return $project;
    }

    private function createMixedCatalogFixture(): string
    {
        $configured = $this->createProjectFixture();
        $configless = $this->createConfiglessPhpProjectFixture();

        $catalog = $this->tmpDir . '/mixed-flow-services.json';
        if (!is_file($catalog)) {
            file_put_contents($catalog, (string) json_encode([
                'version' => '1.0',
                'services' => [
                    ['name' => 'configured', 'path' => $configured],
                    ['name' => 'configless', 'path' => $configless],
                ],
            ], JSON_PRETTY_PRINT));
        }

        return $catalog;
    }

    private function createInvalidConfigProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/invalid-config-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', "{\n");
        $this->writeFile($project . '/src/Service.php', <<<'PHP'
<?php

namespace App;

final class Service
{
    public function run(): void
    {
    }
}
PHP);

        return $project;
    }

    // -------------------------------------------------------------------------
    // Bug A — Laravel framework detection
    // -------------------------------------------------------------------------

    public function test_flow_map_detects_laravel_framework_from_composer_json(): void
    {
        $project = $this->createLaravelComposerProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertContains('Laravel', $payload['project']['detectedFrameworks']);
    }

    public function test_flow_map_detects_no_framework_without_composer_json(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project' => $project,
        ]);

        $this->assertSame([], $payload['project']['detectedFrameworks']);
    }

    // -------------------------------------------------------------------------
    // Bug B — path inexistente retorna erro em vez de sucesso
    // -------------------------------------------------------------------------

    public function test_flow_map_nonexistent_path_returns_error(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 90,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_map',
                'arguments' => ['project' => '/tmp/this-path-does-not-exist-flow-engine-test'],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('does not exist', $response['result']['content'][0]['text']);
    }

    public function test_flow_lookup_nonexistent_path_returns_error(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 91,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => '/tmp/this-path-does-not-exist-flow-engine-test',
                    'targetType' => 'area',
                    'target'     => 'App\\Http',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('does not exist', $response['result']['content'][0]['text']);
    }

    public function test_flow_find_nonexistent_path_returns_error(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 92,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => [
                    'project' => '/tmp/this-path-does-not-exist-flow-engine-test',
                    'query'   => 'Controller',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('does not exist', $response['result']['content'][0]['text']);
    }

    // -------------------------------------------------------------------------
    // Bug C — include_hotspots adds warning instead of silent ignore
    // -------------------------------------------------------------------------

    public function test_flow_map_include_hotspots_adds_deprecation_warning(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_map', [
            'project'          => $project,
            'include_hotspots' => true,
        ]);

        $this->assertContains(
            'include_hotspots is no longer supported; use mode=summary|full',
            $payload['warnings']
        );
    }

    // -------------------------------------------------------------------------
    // flow_find tool
    // -------------------------------------------------------------------------

    public function test_flow_find_is_registered_in_tools_list(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 93,
            'method'  => 'tools/list',
        ]);

        $names = array_column($response['result']['tools'], 'name');
        $this->assertContains('flow_find', $names);
    }

    public function test_flow_find_schema_accepts_query_or_queries(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 94,
            'method'  => 'tools/list',
        ]);

        $tools = array_column($response['result']['tools'], null, 'name');
        $schema = $tools['flow_find']['inputSchema'];

        $this->assertSame(['project'], $schema['required']);
        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertArrayHasKey('queries', $schema['properties']);
        $this->assertSame('array', $schema['properties']['queries']['type']);
        $this->assertSame('string', $schema['properties']['queries']['items']['type']);
        $this->assertArrayNotHasKey('oneOf', $schema);
        $this->assertArrayNotHasKey('allOf', $schema);
        $this->assertArrayNotHasKey('anyOf', $schema);
    }

    public function test_flow_lookup_targets_schema_accepts_array_or_all(): void
    {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 95,
            'method'  => 'tools/list',
        ]);

        $tools = array_column($response['result']['tools'], null, 'name');
        $targetsSchema = $tools['flow_lookup']['inputSchema']['properties']['targets'];

        $this->assertSame('array', $targetsSchema['oneOf'][0]['type']);
        $this->assertSame('string', $targetsSchema['oneOf'][0]['items']['type']);
        $this->assertSame('string', $targetsSchema['oneOf'][1]['type']);
        $this->assertSame(['all'], $targetsSchema['oneOf'][1]['enum']);
    }

    public function test_flow_find_returns_matches_by_substring(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_find', [
            'project' => $project,
            'query'   => 'Controller',
        ]);

        $this->assertSame('node_find', $payload['kind']);
        $this->assertNotEmpty($payload['matches']);
        $this->assertFalse($payload['truncated']);

        $ids = array_column($payload['matches'], 'id');
        foreach ($ids as $id) {
            $this->assertStringContainsStringIgnoringCase('controller', strtolower($id));
        }
    }

    public function test_flow_find_batch_returns_node_find_batch(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_find', [
            'project' => $project,
            'queries' => ['Controller', 'Service', 'Controller'],
            'limit'   => 30,
        ]);

        $this->assertSame('node_find_batch', $payload['kind']);
        $this->assertSame(['controller', 'service'], $payload['queries']);
        $this->assertSame(30, $payload['limit']);
        $this->assertSame(2, $payload['totalRequested']);
        $this->assertSame(2, $payload['returned']);
        $this->assertFalse($payload['truncated']);
        $this->assertSame('node_find', $payload['results'][0]['kind']);
        $this->assertSame('controller', $payload['results'][0]['query']);
        $this->assertSame('service', $payload['results'][1]['query']);
        $this->assertNotEmpty($payload['results'][0]['matches']);
        $this->assertNotEmpty($payload['results'][1]['matches']);
    }

    public function test_flow_find_query_and_queries_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 96,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => [
                    'project' => $project,
                    'query'   => 'Controller',
                    'queries' => ['Service'],
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('not both', $response['result']['content'][0]['text']);
    }

    public function test_flow_find_missing_query_and_queries_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 97,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => ['project' => $project],
            ],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('query or queries', $response['result']['content'][0]['text']);
    }

    public function test_flow_find_empty_queries_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 98,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => ['project' => $project, 'queries' => []],
            ],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('at least one', $response['result']['content'][0]['text']);
    }

    public function test_flow_find_queries_with_empty_term_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 99,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => ['project' => $project, 'queries' => ['Controller', '']],
            ],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('empty search terms', $response['result']['content'][0]['text']);
    }

    public function test_flow_find_empty_query_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_find',
                'arguments' => ['project' => $project, 'query' => ''],
            ],
        ]);

        $this->assertTrue($response['result']['isError'] ?? false);
        $this->assertStringContainsString('query', $response['result']['content'][0]['text']);
    }

    // -------------------------------------------------------------------------
    // flow_map summary size assertion
    // -------------------------------------------------------------------------

    public function test_flow_map_summary_is_below_3500_bytes_for_this_project(): void
    {
        // Dogfood: run flow_map against this project's own fixture
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 95,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_map',
                'arguments' => ['project' => $project, 'mode' => 'summary'],
            ],
        ]);

        $text = $response['result']['content'][0]['text'];
        $bytes = strlen($text);

        $this->assertLessThan(
            3500,
            $bytes,
            "flow_map summary for fixture project should be <3500 bytes; got {$bytes}"
        );
    }

    private function createLaravelComposerProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/laravel-composer-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', <<<'JSON'
{
  "version": "1.0",
  "context": {
    "type": "php",
    "autoload": "vendor/autoload.php"
  },
  "scan": {
    "include": ["app"],
    "exclude": ["vendor"],
    "extensions": ["php"]
  }
}
JSON);

        $this->writeFile($project . '/vendor/autoload.php', "<?php\n");
        $this->writeFile($project . '/composer.json', (string) json_encode([
            'require' => [
                'laravel/framework' => '^10.0',
                'php'               => '^8.1',
            ],
        ], JSON_PRETTY_PRINT));
        $this->writeFile($project . '/app/Http/Controllers/UserController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

final class UserController
{
    public function index(): void
    {
    }
}
PHP);

        return $project;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create dir: {$directory}");
        }

        file_put_contents($path, $contents);
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

    // -------------------------------------------------------------------------
    // Batch lookup (Scenarios 10–18)
    // -------------------------------------------------------------------------

    // Scenario 10: targets array → returns kind=lookup_batch_result with multiple results
    public function test_flow_lookup_batch_returns_lookup_batch_result(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'node',
            'targets'    => [
                'App\\Http\\OrderController::store',
                'App\\Application\\OrderService::save',
            ],
            'format' => 'summary',
        ]);

        $this->assertSame('lookup_batch_result', $payload['kind']);
        $this->assertIsArray($payload['results']);
        $this->assertNotEmpty($payload['results']);
        foreach ($payload['results'] as $result) {
            $this->assertSame('lookup_result', $result['kind']);
        }
    }

    // Scenario 11: >5 targets → format coerced to summary, warning present in warnings[]
    public function test_flow_lookup_batch_gt5_targets_coerces_format_to_summary(): void
    {
        $project = $this->createProjectFixture();

        // Provide 6 targets — they may not all exist, but the coerce happens before lookups
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'node',
            'targets'    => [
                'App\\Http\\OrderController::store',
                'App\\Application\\OrderService::save',
                'App\\Infrastructure\\OrderRepository::persist',
                'App\\Console\\SyncCommand::handle',
                'App\\Http\\OrderController::__construct',
                'App\\Application\\OrderService::__construct',
            ],
            'format' => 'context',
        ]);

        $this->assertSame('lookup_batch_result', $payload['kind']);
        // Warning must be present
        $warnings = $payload['warnings'] ?? [];
        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w, 'coerced') || str_contains($w, 'summary')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected coerce warning in batch with >5 targets; got: ' . implode(', ', $warnings));
    }

    // Scenario 12: format=diagram + targets → error with clear message
    public function test_flow_lookup_batch_diagram_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 200,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => $project,
                    'targetType' => 'node',
                    'targets'    => ['App\\Http\\OrderController::store'],
                    'format'     => 'diagram',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('diagram', $response['result']['content'][0]['text']);
    }

    // Scenario 13: "all" + targetType=entrypoint → respects limit default 20
    public function test_flow_lookup_all_entrypoints_respects_limit(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'entrypoint',
            'targets'    => 'all',
            'format'     => 'summary',
        ]);

        $this->assertSame('lookup_batch_result', $payload['kind']);
        $this->assertIsArray($payload['results']);
        $this->assertLessThanOrEqual(20, count($payload['results']));
    }

    // Scenario 14: "all" + targetType=node → error mentioning alternative (flow_find)
    public function test_flow_lookup_all_node_returns_error_with_flow_find_hint(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 201,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => $project,
                    'targetType' => 'node',
                    'targets'    => 'all',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $text = $response['result']['content'][0]['text'];
        $this->assertStringContainsString('flow_find', $text);
    }

    // Scenario 15: Both target + targets present → error "Provide either target or targets, not both"
    public function test_flow_lookup_both_target_and_targets_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 202,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => $project,
                    'targetType' => 'node',
                    'target'     => 'App\\Http\\OrderController::store',
                    'targets'    => ['App\\Http\\OrderController::store'],
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('not both', $response['result']['content'][0]['text']);
    }

    // Scenario 16: Neither target nor targets → error "Missing required parameter: target or targets"
    public function test_flow_lookup_neither_target_nor_targets_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 203,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => $project,
                    'targetType' => 'node',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $text = $response['result']['content'][0]['text'];
        $this->assertStringContainsString('target or targets', $text);
    }

    // Scenario 17: Singular target still returns ProjectLookupDTO (kind=lookup_result, backward-compat)
    public function test_flow_lookup_singular_target_returns_lookup_result(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'node',
            'target'     => 'App\\Http\\OrderController::store',
            'format'     => 'summary',
        ]);

        $this->assertSame('lookup_result', $payload['kind']);
        $this->assertSame('node', $payload['targetType']);
        $this->assertSame('App\\Http\\OrderController::store', $payload['target']);
    }

    public function test_analysis_warnings_are_propagated_by_direct_project_tools(): void
    {
        $project = $this->createProjectFixture();
        $stateRoot = $this->tmpDir . '-state';
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $stateRoot);

        try {
            $this->dispatchJsonTool('flow_map', ['project' => $project]);
            $flowFile = StateDirectory::forProjectRoot($project) . '/cache/flow.json.gz';
            $tools = [
                ['flow_lookup', [
                    'project' => $project,
                    'targetType' => 'node',
                    'target' => 'App\\Http\\OrderController::store',
                ]],
                ['flow_find', ['project' => $project, 'query' => 'OrderController']],
                ['flow_impact', [
                    'project' => $project,
                    'node' => 'App\\Http\\OrderController::store',
                ]],
            ];

            foreach ($tools as [$tool, $arguments]) {
                file_put_contents($flowFile, 'corrupt');
                $payload = $this->dispatchJsonTool($tool, $arguments);
                self::assertStringContainsString(
                    'Flow cache is corrupt',
                    implode("\n", $payload['warnings'] ?? []),
                    $tool,
                );
            }
        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
            $this->deleteDirectory($stateRoot);
        }
    }

    public function test_flow_map_reports_configuration_only_refresh(): void
    {
        $project = $this->createProjectFixture();
        $stateRoot = $this->tmpDir . '-config-state';
        $originalState = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $stateRoot);

        try {
            $this->dispatchJsonTool('flow_map', ['project' => $project]);
            $config = $project . '/flow-engine.json';
            $configuration = json_decode((string) file_get_contents($config), true, flags: JSON_THROW_ON_ERROR);
            $configuration['scan']['exclude'][] = 'generated';
            file_put_contents($config, json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $payload = $this->dispatchJsonTool('flow_map', ['project' => $project]);

            self::assertTrue($payload['staleness']['configChanged']);
            self::assertSame(1, $payload['staleness']['counts']['configuration']);
            self::assertStringContainsString('flow-engine.json', implode("\n", $payload['warnings']));
        } finally {
            putenv($originalState === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $originalState);
            $this->deleteDirectory($stateRoot);
        }
    }

    // Scenario 18: Not-found in batch doesn't abort other results (mix valid + invalid)
    public function test_flow_lookup_batch_not_found_does_not_abort_valid_results(): void
    {
        $project = $this->createProjectFixture();
        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'node',
            'targets'    => [
                'App\\Http\\OrderController::store',
                'App\\This\\Does\\NotExist::missing',
                'App\\Application\\OrderService::save',
            ],
            'format' => 'summary',
        ]);

        $this->assertSame('lookup_batch_result', $payload['kind']);
        // At least the valid ones came back
        $this->assertGreaterThanOrEqual(1, count($payload['results']));
        $this->assertNotEmpty($payload['notFound']);
        $this->assertContains('App\\This\\Does\\NotExist::missing', $payload['notFound']);
    }

    // Scenario P2: batch with targetType=service on single-project returns error (not silently notFound)
    public function test_flow_lookup_batch_invalid_target_type_for_scope_returns_error(): void
    {
        $project = $this->createProjectFixture();
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id'      => 204,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'flow_lookup',
                'arguments' => [
                    'project'    => $project,
                    'targetType' => 'service',
                    'targets'    => ['App\\Http\\OrderController::store'],
                    'format'     => 'summary',
                ],
            ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('catalog', $response['result']['content'][0]['text']);
    }

    // Finding 3: targets="all" with format=context and >5 expanded entrypoints triggers coerce warning
    public function test_flow_lookup_all_with_context_format_and_gt5_entrypoints_coerces_to_summary(): void
    {
        $project = $this->createLargeEntrypointProjectFixture();

        $payload = $this->dispatchJsonTool('flow_lookup', [
            'project'    => $project,
            'targetType' => 'entrypoint',
            'targets'    => 'all',
            'format'     => 'context',
        ]);

        $this->assertSame('lookup_batch_result', $payload['kind']);

        // Format must be coerced to summary
        $this->assertSame('summary', $payload['format']);

        // Warning must mention coercion
        $warnings = $payload['warnings'] ?? [];
        $foundCoerce = false;
        foreach ($warnings as $w) {
            if (str_contains($w, 'coerced') || str_contains($w, 'summary')) {
                $foundCoerce = true;
                break;
            }
        }
        $this->assertTrue($foundCoerce, 'Expected coerce warning after "all" expansion with >5 entrypoints; got: ' . implode(', ', $warnings));
    }

    private function createLargeEntrypointProjectFixture(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/flow-engine-mcp-' . uniqid('', true);
        }

        $project = $this->tmpDir . '/large-entrypoint-project';
        if (is_dir($project)) {
            return $project;
        }

        $this->writeFile($project . '/flow-engine.json', <<<'JSON'
{
  "version": "1.0",
  "context": {
    "type": "php",
    "autoload": "vendor/autoload.php"
  },
  "scan": {
    "include": ["src"],
    "exclude": ["vendor"],
    "extensions": ["php"]
  }
}
JSON);

        $this->writeFile($project . '/vendor/autoload.php', "<?php\n");

        // Create 6 HTTP controllers so targets="all" with targetType=entrypoint returns >5 items
        $controllers = ['Order', 'User', 'Product', 'Invoice', 'Payment', 'Shipment'];
        foreach ($controllers as $name) {
            $this->writeFile($project . "/src/Http/{$name}Controller.php", <<<PHP
<?php
namespace App\Http;
final class {$name}Controller
{
    public function index(): void {}
}
PHP);
        }

        return $project;
    }
}
