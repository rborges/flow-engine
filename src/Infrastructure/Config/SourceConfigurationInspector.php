<?php

namespace FlowEngine\Infrastructure\Config;

use FlowEngine\Application\InfraMap\Contract\SourceConfigurationInspector as SourceConfigurationInspectorContract;

final class SourceConfigurationInspector implements SourceConfigurationInspectorContract
{
    public function __construct(private readonly SchemaValidator $schemaValidator)
    {
    }

    public function warningsFor(string $projectRoot): array
    {
        $configPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'flow-engine.json';
        if (!file_exists($configPath)) {
            return [];
        }

        try {
            new JsonProjectConfig($projectRoot, $this->schemaValidator);
        } catch (\Throwable $exception) {
            return [sprintf(
                'Source analysis configuration is invalid at %s: %s Infrastructure mapping continued.',
                $configPath,
                $exception->getMessage(),
            )];
        }

        return [];
    }
}
