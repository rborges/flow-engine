<?php

namespace FlowEngine\Infrastructure\Context;

use FlowEngine\Domain\Contracts\ProjectConfig;
use FlowEngine\Domain\Contracts\ProjectContext;

/**
 * Contexto só de análise estática (Python, TypeScript): nada a carregar no boot.
 */
final class StaticProjectContext implements ProjectContext
{
    public function __construct(
        private ProjectConfig $config
    ) {
    }

    public function boot(): void
    {
    }

    public function rootPath(): string
    {
        return $this->config->rootPath();
    }

    public function includePaths(): array
    {
        return $this->config->scanInclude();
    }

    public function ignoredPaths(): array
    {
        return $this->config->scanExclude();
    }

    public function extensions(): array
    {
        return $this->config->scanExtensions();
    }
}
