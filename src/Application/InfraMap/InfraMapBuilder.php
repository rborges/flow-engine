<?php

namespace FlowEngine\Application\InfraMap;

use FlowEngine\Application\InfraMap\Contract\CatalogLoader;
use FlowEngine\Application\InfraMap\Contract\DockerTopologyReader;
use FlowEngine\Application\InfraMap\Contract\ProjectSectionAnalyzer;
use FlowEngine\Application\InfraMap\Contract\SourceConfigurationInspector;

final class InfraMapBuilder
{
    private const ALL_SECTIONS = ['files', 'docker', 'proxy', 'web', 'scripts'];

    public function __construct(
        private ProjectSectionAnalyzer $fileInventoryAnalyzer,
        private DockerTopologyReader $dockerTopologyAnalyzer,
        private ProjectSectionAnalyzer $proxyAnalyzer,
        private ProjectSectionAnalyzer $webAnalyzer,
        private ProjectSectionAnalyzer $scriptAnalyzer,
        private CatalogLoader $catalogLoader,
        private SourceConfigurationInspector $sourceConfigurationInspector,
    ) {
    }

    /**
     * @param string[] $sections
     * @return array<string, mixed>
     */
    public function buildForProject(string $projectRoot, string $mode = 'summary', array $sections = ['all']): array
    {
        $root = realpath($projectRoot) ?: $projectRoot;
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Project path is not a directory: {$root}");
        }

        $sections = $this->normalizeSections($sections);
        $payload = [
            'kind' => 'infra_map',
            'scope' => 'single',
            'mode' => $this->normalizeMode($mode),
            'project' => [
                'name' => basename(rtrim($root, DIRECTORY_SEPARATOR)),
                'root' => $root,
            ],
            'sections' => $sections,
            'warnings' => $this->sourceConfigurationInspector->warningsFor($root),
            'edges' => [],
        ];

        $this->appendProjectSections($payload, $root, $payload['mode'], $sections);
        $payload['summary'] = $this->summaryFor($payload);
        $payload['warnings'] = array_values(array_unique($payload['warnings']));

        return $payload;
    }

    /**
     * @param string[] $sections
     * @return array<string, mixed>
     */
    public function buildForCatalog(string $catalogPath, string $mode = 'summary', array $sections = ['all'], ?string $projectPath = null): array
    {
        $catalog = $this->catalogLoader->load($catalogPath, $projectPath);
        if ($catalog === null) {
            throw new \InvalidArgumentException("Catalog file is invalid or could not be loaded: {$catalogPath}");
        }

        $mode = $this->normalizeMode($mode);
        $sections = $this->normalizeSections($sections);
        $payload = [
            'kind' => 'infra_map',
            'scope' => 'catalog',
            'mode' => $mode,
            'catalog' => [
                'path' => $catalog['catalogPath'],
                'baseDir' => $catalog['baseDir'],
            ],
            'sections' => $sections,
            'services' => [],
            'warnings' => [],
            'edges' => [],
        ];

        if (in_array('docker', $sections, true)) {
            $docker = $this->dockerTopologyAnalyzer->analyze($catalog['baseDir'], $catalog['entries']);
            $payload['docker'] = $this->limitDocker($docker, $mode);
            $payload['edges'] = array_merge($payload['edges'], $this->dockerEdges($docker));
            $payload['warnings'] = array_merge($payload['warnings'], $this->sectionWarnings('docker', $docker['warnings'] ?? [], (int) count($docker['detectedComposeFiles'] ?? [])));
        }

        foreach ($catalog['entries'] as $entry) {
            $root = realpath($entry['path']) ?: $entry['path'];
            if (!is_dir($root)) {
                $payload['warnings'][] = "Catalog service path is not a directory: {$root}";
                continue;
            }

            $servicePayload = [
                'name' => $entry['name'] ?? basename(rtrim($root, DIRECTORY_SEPARATOR)),
                'root' => $root,
                'warnings' => $this->sourceConfigurationInspector->warningsFor($root),
            ];
            $serviceSections = array_values(array_diff($sections, ['docker']));
            $this->appendProjectSections($servicePayload, $root, $mode, $serviceSections);
            $payload['edges'] = array_merge($payload['edges'], $servicePayload['edges'] ?? []);
            $payload['warnings'] = array_merge($payload['warnings'], $servicePayload['warnings'] ?? []);
            unset($servicePayload['edges'], $servicePayload['warnings']);
            $payload['services'][] = $servicePayload;
        }

        if ($mode === 'summary') {
            $payload['edges'] = array_slice($payload['edges'], 0, 250);
        }

        $payload['summary'] = $this->summaryFor($payload);
        $payload['warnings'] = array_values(array_unique($payload['warnings']));

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $sections
     */
    private function appendProjectSections(array &$payload, string $root, string $mode, array $sections): void
    {
        $payload['warnings'] = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
        $payload['edges'] = is_array($payload['edges'] ?? null) ? $payload['edges'] : [];

        if (in_array('files', $sections, true)) {
            $files = $this->fileInventoryAnalyzer->analyze($root, $mode);
            $payload['files'] = $files;
            $payload['warnings'] = array_merge($payload['warnings'], $files['warnings'] ?? []);
        }

        if (in_array('docker', $sections, true)) {
            $docker = $this->dockerTopologyAnalyzer->analyzeProject($root);
            $payload['docker'] = $this->limitDocker($docker, $mode);
            $payload['edges'] = array_merge($payload['edges'], $this->dockerEdges($docker));
            $payload['warnings'] = array_merge($payload['warnings'], $this->sectionWarnings('docker', $docker['warnings'] ?? [], (int) count($docker['detectedComposeFiles'] ?? [])));
        }

        if (in_array('proxy', $sections, true)) {
            $proxy = $this->proxyAnalyzer->analyze($root, $mode);
            $payload['proxy'] = $proxy;
            $payload['edges'] = array_merge($payload['edges'], $proxy['edges'] ?? []);
            $payload['warnings'] = array_merge($payload['warnings'], $this->sectionWarnings('proxy', $proxy['warnings'] ?? [], (int) count($proxy['files'] ?? [])));
        }

        if (in_array('web', $sections, true)) {
            $web = $this->webAnalyzer->analyze($root, $mode);
            $payload['web'] = $web;
            $payload['edges'] = array_merge($payload['edges'], $web['edges'] ?? []);
            $payload['warnings'] = array_merge($payload['warnings'], $this->sectionWarnings('web', $web['warnings'] ?? [], (int) (($web['summary']['robotsCount'] ?? 0) + ($web['summary']['sitemapCount'] ?? 0) + ($web['summary']['metaRobotsCount'] ?? 0))));
        }

        if (in_array('scripts', $sections, true)) {
            $scripts = $this->scriptAnalyzer->analyze($root, $mode);
            $payload['scripts'] = $scripts;
            $payload['edges'] = array_merge($payload['edges'], $scripts['edges'] ?? []);
            $payload['warnings'] = array_merge($payload['warnings'], $this->sectionWarnings('scripts', $scripts['warnings'] ?? [], (int) (($scripts['summary']['scriptCount'] ?? 0) + ($scripts['summary']['systemdUnitCount'] ?? 0))));
        }

        if ($mode === 'summary') {
            $payload['edges'] = array_slice($payload['edges'], 0, 250);
        }
    }

    /**
     * @param string[] $sections
     * @return string[]
     */
    private function normalizeSections(array $sections): array
    {
        $normalized = [];
        foreach ($sections as $section) {
            $section = strtolower(trim((string) $section));
            if ($section === '') {
                continue;
            }
            if ($section === 'all') {
                return self::ALL_SECTIONS;
            }
            if (!in_array($section, self::ALL_SECTIONS, true)) {
                throw new \InvalidArgumentException('Invalid sections value. Use all, files, docker, proxy, web, or scripts.');
            }
            $normalized[] = $section;
        }

        return $normalized === [] ? self::ALL_SECTIONS : array_values(array_unique($normalized));
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === '') {
            return 'summary';
        }
        if (!in_array($mode, ['summary', 'full'], true)) {
            throw new \InvalidArgumentException('Invalid mode. Use summary or full.');
        }

        return $mode;
    }

    /**
     * @param array<string, mixed> $docker
     * @return array<string, mixed>
     */
    private function limitDocker(array $docker, string $mode): array
    {
        if ($mode !== 'summary') {
            return $docker;
        }

        $docker['detectedComposeFiles'] = array_slice((array) ($docker['detectedComposeFiles'] ?? []), 0, 30);
        $docker['dockerfiles'] = array_slice((array) ($docker['dockerfiles'] ?? []), 0, 50);
        $docker['environmentFiles'] = array_slice((array) ($docker['environmentFiles'] ?? []), 0, 50);
        $docker['containers'] = array_slice((array) ($docker['containers'] ?? []), 0, 80);
        $docker['networks'] = array_slice((array) ($docker['networks'] ?? []), 0, 80);
        $docker['serviceMappings'] = array_slice((array) ($docker['serviceMappings'] ?? []), 0, 80);

        return $docker;
    }

    /**
     * @param array<string, mixed> $docker
     * @return array<int, array<string, mixed>>
     */
    private function dockerEdges(array $docker): array
    {
        $edges = [];

        foreach ((array) ($docker['containers'] ?? []) as $container) {
            if (!is_array($container)) {
                continue;
            }

            $serviceName = (string) ($container['name'] ?? '');
            if ($serviceName === '') {
                continue;
            }

            $from = 'docker-service:' . $serviceName;
            $source = (string) ($container['composeFile'] ?? '');

            foreach ((array) ($container['dockerfiles'] ?? []) as $dockerfile) {
                if (is_string($dockerfile) && $dockerfile !== '') {
                    $edges[] = ['from' => $from, 'to' => 'file:' . $dockerfile, 'type' => 'built_from', 'source' => $source];
                }
            }
            foreach ((array) ($container['networks'] ?? []) as $network) {
                if (is_string($network) && $network !== '') {
                    $edges[] = ['from' => $from, 'to' => 'docker-network:' . $network, 'type' => 'attached_to_network', 'source' => $source];
                }
            }
            foreach ((array) ($container['volumes'] ?? []) as $volume) {
                if (is_string($volume) && $volume !== '') {
                    $edges[] = ['from' => $from, 'to' => 'volume:' . $volume, 'type' => 'mounts_volume', 'source' => $source];
                }
            }
            foreach ((array) ($container['environmentFiles'] ?? []) as $envFile) {
                if (is_string($envFile) && $envFile !== '') {
                    $edges[] = ['from' => $from, 'to' => 'env-file:' . $envFile, 'type' => 'uses_env_file', 'source' => $source];
                }
            }
            foreach ((array) ($container['environmentKeys'] ?? []) as $envKey) {
                if (is_string($envKey) && $envKey !== '') {
                    $edges[] = ['from' => $from, 'to' => 'env-key:' . $envKey, 'type' => 'uses_env_key', 'source' => $source];
                }
            }
            foreach ((array) ($container['dependsOn'] ?? []) as $dependency) {
                if (is_string($dependency) && $dependency !== '') {
                    $edges[] = ['from' => $from, 'to' => 'docker-service:' . $dependency, 'type' => 'depends_on', 'source' => $source];
                }
            }
            foreach ((array) ($container['hostnames'] ?? []) as $hostname) {
                if (is_string($hostname) && $hostname !== '') {
                    $edges[] = ['from' => 'host:' . $hostname, 'to' => $from, 'type' => 'resolves_to', 'source' => $source];
                }
            }
        }

        return $edges;
    }

    /**
     * @param mixed $existingWarnings
     * @return string[]
     */
    private function sectionWarnings(string $section, mixed $existingWarnings, int $detectedCount): array
    {
        $warnings = is_array($existingWarnings) ? array_values(array_filter($existingWarnings, 'is_string')) : [];
        if ($detectedCount === 0) {
            $warnings[] = match ($section) {
                'docker' => 'No Docker compose files were detected.',
                'proxy' => 'No proxy configuration files were detected.',
                'web' => 'No robots, sitemap, meta robots, or canonical signals were detected.',
                'scripts' => 'No automation scripts or systemd units were detected.',
                default => "No {$section} signals were detected.",
            };
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summaryFor(array $payload): array
    {
        $services = array_values(array_filter((array) ($payload['services'] ?? []), 'is_array'));
        $serviceFileMarkerCount = 0;
        $serviceProxyFileCount = 0;
        $serviceWebSignalCount = 0;
        $serviceScriptCount = 0;
        $serviceSystemdUnitCount = 0;

        foreach ($services as $service) {
            $serviceFileMarkerCount += count((array) ($service['files']['markers'] ?? []));
            $serviceProxyFileCount += count((array) ($service['proxy']['files'] ?? []));
            $serviceWebSignalCount += (int) (($service['web']['summary']['robotsCount'] ?? 0) + ($service['web']['summary']['sitemapCount'] ?? 0) + ($service['web']['summary']['metaRobotsCount'] ?? 0) + ($service['web']['summary']['canonicalCount'] ?? 0));
            $serviceScriptCount += (int) ($service['scripts']['summary']['scriptCount'] ?? 0);
            $serviceSystemdUnitCount += (int) ($service['scripts']['summary']['systemdUnitCount'] ?? 0);
        }

        return [
            'fileMarkerCount' => count((array) ($payload['files']['markers'] ?? [])) + $serviceFileMarkerCount,
            'composeFileCount' => count((array) ($payload['docker']['detectedComposeFiles'] ?? [])),
            'dockerfileCount' => count((array) ($payload['docker']['dockerfiles'] ?? [])),
            'containerCount' => count((array) ($payload['docker']['containers'] ?? [])),
            'proxyFileCount' => count((array) ($payload['proxy']['files'] ?? [])) + $serviceProxyFileCount,
            'webSignalCount' => (int) (($payload['web']['summary']['robotsCount'] ?? 0) + ($payload['web']['summary']['sitemapCount'] ?? 0) + ($payload['web']['summary']['metaRobotsCount'] ?? 0) + ($payload['web']['summary']['canonicalCount'] ?? 0)) + $serviceWebSignalCount,
            'scriptCount' => (int) ($payload['scripts']['summary']['scriptCount'] ?? 0) + $serviceScriptCount,
            'systemdUnitCount' => (int) ($payload['scripts']['summary']['systemdUnitCount'] ?? 0) + $serviceSystemdUnitCount,
            'edgeCount' => count((array) ($payload['edges'] ?? [])),
        ];
    }
}
