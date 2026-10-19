<?php

namespace FlowEngine\Bootstrap;

use FlowEngine\Application\InfraMap\InfraMapBuilder;
use FlowEngine\Application\UseCase\ResolveCatalogServices;
use FlowEngine\Infrastructure\Config\FlowServiceCatalogLoader;
use FlowEngine\Infrastructure\Config\SchemaValidator;
use FlowEngine\Infrastructure\Config\SourceConfigurationInspector;
use FlowEngine\Infrastructure\Docker\DockerTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\CaddyTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\FileInventoryAnalyzer;
use FlowEngine\Infrastructure\Infra\ScriptTopologyAnalyzer;
use FlowEngine\Infrastructure\Infra\WebCrawlRulesAnalyzer;

/**
 * Composition root for infrastructure analyzers that read topology from paths
 * (not tied to a project Container). Keeps Application free of direct
 * Infrastructure instantiation.
 */
final class InfraServices
{
    public function resolveCatalogServices(): ResolveCatalogServices
    {
        return new ResolveCatalogServices(
            new FlowServiceCatalogLoader(),
            new DockerTopologyAnalyzer(),
        );
    }

    public function infraMapBuilder(): InfraMapBuilder
    {
        return new InfraMapBuilder(
            new FileInventoryAnalyzer(),
            new DockerTopologyAnalyzer(),
            new CaddyTopologyAnalyzer(),
            new WebCrawlRulesAnalyzer(),
            new ScriptTopologyAnalyzer(),
            new FlowServiceCatalogLoader(),
            new SourceConfigurationInspector(new SchemaValidator(
                __DIR__ . '/../../schema/flow-engine.v1.json',
            )),
        );
    }
}
