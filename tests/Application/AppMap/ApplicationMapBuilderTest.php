<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Application\AppMap\IntegrationDetector;
use FlowEngine\Application\AppMap\ServiceInfo;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

final class ApplicationMapBuilderTest extends TestCase
{
    public function test_it_links_php_script_call_to_python_service(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-appmap-' . uniqid();
        $phpRoot = $base . DIRECTORY_SEPARATOR . 'php-service';
        $pyRoot = $base . DIRECTORY_SEPARATOR . 'py-service';

        mkdir($phpRoot . DIRECTORY_SEPARATOR . 'src', 0777, true);
        mkdir($pyRoot, 0777, true);

        $phpFile = $phpRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controller.php';
        $pyFile = $pyRoot . DIRECTORY_SEPARATOR . 'app.py';

        file_put_contents($pyFile, "def hello():\n    return 1\n");

        // exec is on line 4
        file_put_contents($phpFile, "<?php\nclass Controller {\n  public function index() {\n    exec(\"python3 ../py-service/app.py\");\n  }\n}\n");

        $phpFlow = new Flow([
            new Node('Controller', 'index', $phpFile, 3, 'php'),
        ], []);

        $pyFlow = new Flow([], []);

        $phpService = new ServiceInfo('php-service', $phpRoot, $phpFlow, [$phpFile]);
        $pyService = new ServiceInfo('py-service', $pyRoot, $pyFlow, [$pyFile]);

        $map = (new ApplicationMapBuilder())->build([$phpService, $pyService]);

        self::assertNotEmpty($map['integrationEdges']);

        $scriptEdges = array_values(array_filter(
            $map['integrationEdges'],
            fn(array $e) => ($e['type'] ?? '') === 'script'
        ));

        self::assertNotEmpty($scriptEdges);

        $edge = $scriptEdges[0];
        self::assertSame('php-service', $edge['fromService']);
        self::assertSame('py-service', $edge['toService']);
        self::assertSame('Controller::index', $edge['fromNode']);

        self::assertEmpty($map['inconsistencies']);
        self::assertNotEmpty($map['serviceEdges']);
    }

    public function test_it_resolves_http_edge_to_service_via_hostname_alias(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-appmap-http-' . uniqid();
        $phpRoot = $base . DIRECTORY_SEPARATOR . 'laravel-web';
        $pyRoot = $base . DIRECTORY_SEPARATOR . 'backup-manager';

        mkdir($phpRoot . DIRECTORY_SEPARATOR . 'src', 0777, true);
        mkdir($pyRoot, 0777, true);

        $phpFile = $phpRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'BackupApiService.php';
        file_put_contents(
            $phpFile,
            "<?php\nclass BackupApiService {\n  public function getClients() {\n    \$url = 'http://noc_backup_manager:8000/clients';\n  }\n}\n"
        );

        $pyFile = $pyRoot . DIRECTORY_SEPARATOR . 'main.py';
        file_put_contents($pyFile, "def list_clients():\n    return []\n");

        $phpFlow = new Flow([
            new Node('BackupApiService', 'getClients', $phpFile, 3, 'php'),
        ], []);

        $pyFlow = new Flow([], []);

        $phpService = new ServiceInfo('laravel-web', $phpRoot, $phpFlow, [$phpFile]);
        $pyService = new ServiceInfo('backup-manager', $pyRoot, $pyFlow, [$pyFile], ['noc_backup_manager']);

        $map = (new ApplicationMapBuilder())->build([$phpService, $pyService]);

        $httpEdges = array_values(array_filter(
            $map['integrationEdges'],
            fn(array $e) => ($e['type'] ?? '') === 'http'
        ));

        self::assertNotEmpty($httpEdges);

        $resolved = array_values(array_filter(
            $httpEdges,
            fn(array $e) => ($e['toService'] ?? null) === 'backup-manager'
        ));

        self::assertNotEmpty($resolved, 'HTTP edge should resolve toService via hostname alias');
        self::assertSame('laravel-web', $resolved[0]['fromService']);
        self::assertSame('backup-manager', $resolved[0]['toService']);

        $serviceEdges = array_values(array_filter(
            $map['serviceEdges'],
            fn(array $e) => ($e['from'] ?? '') === 'laravel-web' && ($e['to'] ?? '') === 'backup-manager'
        ));

        self::assertNotEmpty($serviceEdges, 'Service-level edge should exist for HTTP dependency');
    }

    public function test_failed_service_skips_contract_consistency(): void
    {
        $contract = [['method' => 'GET', 'path' => '/orders', 'summary' => 'list']];
        $services = [
            ServiceInfo::failed('orders', sys_get_temp_dir(), 'LogicException: boom', contractEndpoints: $contract),
            new ServiceInfo('billing', sys_get_temp_dir(), new Flow([], []), []),
        ];

        $map = (new ApplicationMapBuilder())->build($services);

        self::assertSame([], $map['inconsistencies']);
        self::assertSame('failed', $map['services'][0]['status']);
        self::assertArrayNotHasKey('contract', $map['services'][0]);
    }

    public function test_warns_about_empty_and_failed_services_only(): void
    {
        $root = sys_get_temp_dir();
        $services = [
            ServiceInfo::failed('orders', $root, 'LogicException: boom'),
            new ServiceInfo('billing', $root, new Flow([], []), [], configResolution: [
                'detectedContext' => 'python',
                'extensions' => ['py'],
            ]),
            new ServiceInfo('legacy', $root, new Flow([], []), []),
            new ServiceInfo('users', $root, new Flow([new Node('Users', 'list', $root . '/Users.php', 3, 'php')], []), []),
        ];

        $warnings = (new ApplicationMapBuilder())->build($services)['warnings'];

        self::assertSame([
            ['type' => 'SERVICE_FAILED', 'service' => 'orders', 'message' => 'analysis failed: LogicException: boom'],
            ['type' => 'EMPTY_SERVICE', 'service' => 'billing', 'message' => 'analyzed with 0 nodes (context: python, extensions: py). Check scan.include and scan.extensions in its flow-engine.json.'],
            ['type' => 'EMPTY_SERVICE', 'service' => 'legacy', 'message' => 'analyzed with 0 nodes. Check scan.include and scan.extensions in its flow-engine.json.'],
        ], $warnings);
    }

    public function test_warnings_is_an_empty_list_when_every_service_has_nodes(): void
    {
        $root = sys_get_temp_dir();
        $flow = new Flow([new Node('Users', 'list', $root . '/Users.php', 3, 'php')], []);

        self::assertSame([], (new ApplicationMapBuilder())->build([new ServiceInfo('users', $root, $flow, [])])['warnings']);
    }
}
