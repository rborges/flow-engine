<?php

namespace FlowEngine\Infrastructure\Config;

use FlowEngine\Domain\Contracts\ProjectConfig;
use RuntimeException;

final class JsonProjectConfig implements ProjectConfig
{
    private array $config;

    public function __construct(
        private string $projectRoot,
        SchemaValidator $validator
    ) {
        $path = $projectRoot . DIRECTORY_SEPARATOR . 'flow-engine.json';

        if (!file_exists($path)) {
            $this->config = [];
            return;
        }

        $raw = json_decode(file_get_contents($path), true);

        if (!is_array($raw)) {
            throw new RuntimeException('Invalid JSON in flow-engine.json');
        }

        $validator->validate($raw);

        $this->config = $raw;
    }

    public function version(): string
    {
        return $this->config['version'] ?? '1.0';
    }

    /**
     * @api
     */
    public function rootPath(): string
    {
        return $this->projectRoot;
    }

    public function scanInclude(): array
    {
        if (isset($this->config['scan']['include']) && is_array($this->config['scan']['include'])) {
            return $this->config['scan']['include'];
        }

        if ($this->contextType() === 'flutter') {
            return $this->defaultFlutterInclude();
        }

        return ['src'];
    }

    public function scanExclude(): array
    {
        if (isset($this->config['scan']['exclude']) && is_array($this->config['scan']['exclude'])) {
            return $this->config['scan']['exclude'];
        }

        if ($this->contextType() === 'flutter') {
            return $this->defaultFlutterExclude();
        }

        return [];
    }

    public function scanExtensions(): array
    {
        if (isset($this->config['scan']['extensions']) && is_array($this->config['scan']['extensions'])) {
            return $this->config['scan']['extensions'];
        }

        if ($this->contextType() === 'flutter') {
            return $this->defaultFlutterExtensions();
        }

        return match ($this->contextType()) {
            'python' => ['py'],
            'typescript' => ['ts', 'tsx', 'js', 'jsx'],
            default => ['php'],
        };
    }

    public function contextType(): string
    {
        return $this->config['context']['type'] ?? 'composer';
    }

    public function autoloadPath(): ?string
    {
        return $this->config['context']['autoload'] ?? null;
    }

    public function nodeVisibilityRules(): array
    {
        return $this->config['nodes']['visibility']['rules'] ?? [];
    }

    public function defaultNodeVisibility(): string
    {
        return $this->config['nodes']['visibility']['default'] ?? 'public';
    }

    public function architectureLayers(): array
    {
        return $this->config['architecture']['layers'] ?? [];
    }

    public function livewireNamespace(): string
    {
        return $this->config['livewire']['namespace'] ?? 'App\\Http\\Livewire';
    }

    public function entrypointPatterns(): array
    {
        return $this->config['entrypoints']['patterns'] ?? [];
    }

    public function snapshotRetention(): ?int
    {
        $value = $this->config['snapshots']['keep'] ?? null;

        if (!is_int($value) || $value < 1) {
            return null;
        }

        return $value;
    }

    public function flutterConfig(): array
    {
        $config = $this->config['flutter'] ?? [];
        return is_array($config) ? $config : [];
    }

    /**
     * @return string[]
     */
    private function defaultFlutterInclude(): array
    {
        $include = ['lib', 'test', 'integration_test'];

        if (is_dir($this->projectRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'app')) {
            $include[] = 'backend/app';
        }

        return $include;
    }

    /**
     * @return string[]
     */
    private function defaultFlutterExclude(): array
    {
        return [
            '.dart_tool',
            '.pub-cache',
            'build',
            'backend/.pytest_cache',
            'backend/tmp',
            'ios/Pods',
        ];
    }

    /**
     * @return string[]
     */
    private function defaultFlutterExtensions(): array
    {
        $extensions = ['dart'];

        if (is_dir($this->projectRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'app')) {
            $extensions[] = 'py';
        }

        return $extensions;
    }
}
