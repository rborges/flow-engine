<?php

namespace FlowEngine\Application\AppMap;

use FlowEngine\Domain\Contracts\Flow;

final readonly class ServiceInfo
{
    /**
     * @param string   $name
     * @param string   $root Absolute root path
     * @param string[] $files Absolute scanned files
     * @param string[] $hostnames Docker hostnames / aliases for HTTP edge resolution
     * @param array<int, array{method: string, path: string, summary: string}>|null $contractEndpoints Endpoints parsed from OpenAPI contract files; null means no contract was provided
     */
    public function __construct(
        public string $name,
        public string $root,
        public Flow $flow,
        public array $files,
        public array $hostnames = [],
        public ?array $contractEndpoints = null,
        public array $configuredScanLanguages = [],
        public array $detectedLanguages = [],
        public ?array $configResolution = null,
        public array $analysisWarnings = [],
        public ?array $staleness = null,
    ) {
    }

    /**
     * @return string[]
     */
    public function languages(): array
    {
        if ($this->detectedLanguages !== []) {
            return $this->detectedLanguages;
        }

        $langs = [];
        foreach ($this->flow->nodes() as $node) {
            $langs[$node->language()] = true;
        }

        if ($langs === []) {
            // Fallback: infer by scanned extensions
            foreach ($this->files as $file) {
                $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                if ($ext === 'php') {
                    $langs['php'] = true;
                } elseif ($ext === 'py') {
                    $langs['python'] = true;
                }
            }
        }

        return array_values(array_keys($langs));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $languages = $this->languages();

        $payload = [
            'id' => $this->name,
            'name' => $this->name,
            'root' => $this->root,
            'languages' => $languages,
            'technology' => $languages[0] ?? 'unknown',
            'domain' => basename(rtrim($this->root, DIRECTORY_SEPARATOR)),
            'kind' => 'service',
            'external' => false,
            'hostnames' => $this->hostnames,
            'stats' => [
                'nodeCount' => $this->flow->nodeCount(),
                'edgeCount' => $this->flow->edgeCount(),
            ],
        ];

        if ($this->configResolution !== null) {
            $payload['configResolution'] = $this->configResolution;
        }
        if ($this->analysisWarnings !== []) {
            $payload['warnings'] = $this->analysisWarnings;
        }
        if ($this->staleness !== null) {
            $payload['staleness'] = $this->staleness;
        }

        return $payload;
    }
}
