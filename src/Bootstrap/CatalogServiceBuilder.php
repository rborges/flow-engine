<?php

namespace FlowEngine\Bootstrap;

use FlowEngine\Application\AppMap\ServiceInfo;
use FlowEngine\Infrastructure\Config\FlowServiceCatalogLoader;
use FlowEngine\Infrastructure\Docker\DockerTopologyAnalyzer;

final class CatalogServiceBuilder
{
    private LanguageSupportCatalog $languageSupportCatalog;

    public function __construct(?LanguageSupportCatalog $languageSupportCatalog = null)
    {
        $this->languageSupportCatalog = $languageSupportCatalog ?? new LanguageSupportCatalog();
    }

    /**
     * @return array<int, ServiceInfo>
     */
    public function buildServices(string $catalogPath, bool $allowReadOnlyInference = false): array
    {
        $entries = $this->entriesFromCatalog($catalogPath);
        if ($entries === []) {
            throw new \InvalidArgumentException("Catalog file is invalid or could not be loaded: {$catalogPath}");
        }

        return $this->buildFromEntries($entries, $allowReadOnlyInference);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, ServiceInfo>
     */
    public function buildFromEntries(array $entries, bool $allowReadOnlyInference = false): array
    {
        return array_map(
            fn(array $entry): ServiceInfo => $this->buildService($entry, $allowReadOnlyInference),
            array_values($entries)
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function buildService(array $entry, bool $allowReadOnlyInference): ServiceInfo
    {
        $root = realpath($entry['path']) ?: $entry['path'];
        $name = $entry['name'] ?? basename(rtrim($root, DIRECTORY_SEPARATOR));
        $hostnames = $entry['hostnames'] ?? [];
        $contractEndpoints = $entry['contractEndpoints'] ?? null;

        // Um serviço que não analisa não pode derrubar o mapa dos demais.
        try {
            $container = new Container($root, $allowReadOnlyInference);
            $container->analyzeProject()->execute();
            $files = $container->projectFiles();

            return new ServiceInfo(
                name: $name,
                root: $root,
                flow: $container->getFlow(),
                files: $files,
                hostnames: $hostnames,
                contractEndpoints: $contractEndpoints,
                configuredScanLanguages: $this->languageSupportCatalog->supportedConfiguredLanguages($container->configuredScanExtensions()),
                detectedLanguages: $this->languageSupportCatalog->detectFromFiles($files),
                configResolution: $container->configResolution()->toArray(),
            );
        } catch (\Throwable $e) {
            // A classe distingue erro de config do projeto de bug do engine (TypeError, Error).
            return ServiceInfo::failed($name, $root, $e::class . ': ' . $e->getMessage(), $hostnames, $contractEndpoints);
        }
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
    private function entriesFromCatalog(string $catalogPath): array
    {
        $catalog = (new FlowServiceCatalogLoader())->load($catalogPath);
        if ($catalog === null) {
            return [];
        }

        $entries = $catalog['entries'];
        $docker = (new DockerTopologyAnalyzer())->analyze($catalog['baseDir'], $entries);
        $hostnamesByService = [];
        foreach ($docker['serviceMappings'] as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $service = (string) ($mapping['service'] ?? '');
            if ($service === '') {
                continue;
            }

            $hostnamesByService[$service] = is_array($mapping['hostnames'] ?? null)
                ? array_values(array_filter($mapping['hostnames'], 'is_string'))
                : [];
        }

        return array_map(function (array $entry) use ($hostnamesByService): array {
            $serviceName = $entry['name'] ?? basename(rtrim($entry['path'], DIRECTORY_SEPARATOR));
            $entry['hostnames'] = array_values(array_unique(array_merge(
                $entry['hostnames'] ?? [],
                $hostnamesByService[$serviceName] ?? []
            )));
            return $entry;
        }, $entries);
    }
}
