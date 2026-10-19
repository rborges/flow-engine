<?php

namespace Tests\Application\InfraMap;

use FlowEngine\Application\InfraMap\InfraMapBuilder;
use FlowEngine\Infrastructure\Config\FlowServiceCatalogLoader;
use FlowEngine\Infrastructure\Config\SchemaValidator;
use FlowEngine\Infrastructure\Config\SourceConfigurationInspector;
use FlowEngine\Infrastructure\Docker\DockerTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\CaddyTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\FileInventoryAnalyzer;
use FlowEngine\Infrastructure\Infra\ScriptTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\WebCrawlRulesAnalyzer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InfraMapBuilderTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->deleteDirectory($this->tmpDir);
        }
    }

    public function test_build_for_catalog_summarizes_service_sections(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-infra-map-' . uniqid('', true);
        $this->tmpDir = $base;
        $service = $base . DIRECTORY_SEPARATOR . 'svc';
        mkdir($service . DIRECTORY_SEPARATOR . 'caddy', 0777, true);
        file_put_contents($service . DIRECTORY_SEPARATOR . 'caddy' . DIRECTORY_SEPARATOR . 'Caddyfile', <<<CADDY
example.test {
  reverse_proxy app:80
}
CADDY);

        $catalog = $base . DIRECTORY_SEPARATOR . 'flow-services.json';
        file_put_contents($catalog, (string) json_encode([
            'version' => '1.0',
            'services' => [
                ['name' => 'svc', 'path' => $service],
            ],
        ], JSON_PRETTY_PRINT));

        $result = $this->builder()->buildForCatalog($catalog, 'summary', ['proxy']);

        $this->assertSame('infra_map', $result['kind']);
        $this->assertSame('catalog', $result['scope']);
        $this->assertSame(1, $result['summary']['proxyFileCount']);
        $this->assertNotEmpty($result['services'][0]['proxy']['files']);
    }

    public function test_build_for_project_adds_environment_key_edges_without_values(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-infra-map-' . uniqid('', true);
        $this->tmpDir = $base;
        mkdir($base, 0777, true);

        file_put_contents($base . DIRECTORY_SEPARATOR . 'docker-compose.yml', <<<YAML
services:
  app:
    image: php:8.3-cli
    environment:
      APP_ENV: production
      SECRET_TOKEN: not-returned
    volumes:
      - ./workspace:/workspace:ro
YAML);

        $result = $this->builder()->buildForProject($base, 'full', ['docker']);

        $this->assertContains(
            [
                'from' => 'docker-service:app',
                'to' => 'env-key:SECRET_TOKEN',
                'type' => 'uses_env_key',
                'source' => $base . DIRECTORY_SEPARATOR . 'docker-compose.yml',
            ],
            $result['edges']
        );
        $this->assertSame(['APP_ENV', 'SECRET_TOKEN'], $result['docker']['containers'][0]['environmentKeys']);
        $this->assertSame(['./workspace:/workspace:ro'], $result['docker']['containers'][0]['volumes']);
        $this->assertContains(
            [
                'from' => 'docker-service:app',
                'to' => 'volume:./workspace:/workspace:ro',
                'type' => 'mounts_volume',
                'source' => $base . DIRECTORY_SEPARATOR . 'docker-compose.yml',
            ],
            $result['edges']
        );
    }

    private function builder(): InfraMapBuilder
    {
        return new InfraMapBuilder(
            new FileInventoryAnalyzer(),
            new DockerTopologyAnalyzer(),
            new CaddyTopologyAnalyzer(),
            new WebCrawlRulesAnalyzer(),
            new ScriptTopologyAnalyzer(),
            new FlowServiceCatalogLoader(),
            new SourceConfigurationInspector(new SchemaValidator(
                __DIR__ . '/../../../schema/flow-engine.v1.json',
            )),
        );
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
            if (is_link($current) || is_file($current)) {
                if (!unlink($current)) {
                    throw new RuntimeException("Cannot delete file: {$current}");
                }
                continue;
            }

            if (is_dir($current)) {
                $this->deleteDirectory($current);
            }
        }

        @rmdir($path);
    }
}
