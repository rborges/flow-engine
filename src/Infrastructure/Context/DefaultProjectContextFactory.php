<?php

namespace FlowEngine\Infrastructure\Context;

use FlowEngine\Domain\Contracts\ProjectConfig;
use FlowEngine\Domain\Contracts\ProjectContext;
use LogicException;

final class DefaultProjectContextFactory implements ProjectContextFactory
{
    /**
     * @internal
     */
    public function create(ProjectConfig $config): ProjectContext
    {
        return match ($config->contextType()) {
            // Schema-supported context types
            'composer' => new ComposerProjectContext($config),
            'custom' => new ComposerProjectContext($config), // best-effort boot (autoload optional)
            'flutter' => new FlutterProjectContext($config),
            'wordpress' => new WordPressProjectContext($config->rootPath()),
            'python', 'typescript' => new StaticProjectContext($config),

            // Legacy alias (some configs used "php")
            'php' => new ComposerProjectContext($config),

            default => throw new LogicException(
                'Unsupported project context: ' . $config->contextType()
            )
        };
    }
}
