<?php

namespace FlowEngine\Bootstrap;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Analyzer\AstParser;
use FlowEngine\Infrastructure\Analyzer\BladeParser;
use FlowEngine\Infrastructure\Analyzer\DartParser;
use FlowEngine\Infrastructure\Analyzer\GoParser;
use FlowEngine\Infrastructure\Analyzer\PythonParser;
use FlowEngine\Infrastructure\Analyzer\TypeScriptParser;

final class LanguageSupportCatalog
{
    /**
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   extensions: string[],
     *   compoundSuffixes: string[],
     *   supportLevel: string,
     *   notes?: string
     * }>
     */
    public function entries(): array
    {
        return [
            [
                'id' => 'php',
                'label' => 'PHP',
                'extensions' => ['php'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'python',
                'label' => 'Python',
                'extensions' => ['py'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'typescript',
                'label' => 'TypeScript',
                'extensions' => ['ts', 'tsx'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'javascript',
                'label' => 'JavaScript',
                'extensions' => ['js', 'jsx'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'go',
                'label' => 'Go',
                'extensions' => ['go'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'dart',
                'label' => 'Dart',
                'extensions' => ['dart'],
                'compoundSuffixes' => [],
                'supportLevel' => 'full',
            ],
            [
                'id' => 'blade',
                'label' => 'Blade Templates',
                'extensions' => [],
                'compoundSuffixes' => ['blade.php'],
                'supportLevel' => 'edge_only',
                'notes' => 'Livewire wire-action edge extraction only',
            ],
        ];
    }

    /**
     * @return array{simple: array<string, object>, compound: array<string, object>}
     */
    public function parserMaps(DefaultNodeFactory $nodeFactory, string $projectRoot, string $livewireNamespace): array
    {
        return [
            'simple' => [
                'php' => new AstParser($nodeFactory),
                'py'  => new PythonParser($nodeFactory, $projectRoot),
                'ts'  => new TypeScriptParser($nodeFactory, $projectRoot, 'typescript'),
                'tsx' => new TypeScriptParser($nodeFactory, $projectRoot, 'typescript'),
                'js'  => new TypeScriptParser($nodeFactory, $projectRoot, 'javascript'),
                'jsx' => new TypeScriptParser($nodeFactory, $projectRoot, 'javascript'),
                'go'  => new GoParser($nodeFactory, $projectRoot),
                'dart' => new DartParser($nodeFactory, $projectRoot),
            ],
            'compound' => [
                'blade.php' => new BladeParser($projectRoot, $livewireNamespace),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function supportedLanguagesPayload(): array
    {
        return array_map(
            static function (array $entry): array {
                $payload = [
                    'id' => $entry['id'],
                    'label' => $entry['label'],
                    'supportLevel' => $entry['supportLevel'],
                ];

                if (($entry['notes'] ?? '') !== '') {
                    $payload['notes'] = $entry['notes'];
                }

                return $payload;
            },
            $this->entries()
        );
    }

    public function descriptionSummary(): string
    {
        return 'Current support: Dart, Go, PHP, Python, TypeScript/JavaScript, plus Blade template edge extraction.';
    }

    public function payloadSummary(): string
    {
        return 'Supports Dart, Go, PHP, Python, TypeScript/JavaScript, plus Blade template edge extraction.';
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    public function detectFromFiles(array $files): array
    {
        $detected = [];

        foreach ($files as $file) {
            $language = $this->detectLanguageForFile($file);
            if ($language === null) {
                continue;
            }

            $detected[$language] = true;
        }

        return $this->sortLanguageIds(array_keys($detected));
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    public function normalizeConfiguredExtensions(array $extensions): array
    {
        $detected = [];
        foreach ($extensions as $extension) {
            $normalized = strtolower(ltrim(trim((string) $extension), '.'));
            if ($normalized === '') {
                continue;
            }

            foreach ($this->entries() as $entry) {
                if (in_array($normalized, $entry['extensions'], true)) {
                    $detected[$entry['id']] = true;
                }
            }
        }

        return $this->sortLanguageIds(array_keys($detected));
    }

    /**
     * @param string[] $configuredExtensions
     * @return string[]
     */
    public function supportedConfiguredLanguages(array $configuredExtensions): array
    {
        $languages = $this->normalizeConfiguredExtensions($configuredExtensions);
        $extensionSet = array_fill_keys(
            array_map(
                static fn(string $extension): string => strtolower(ltrim(trim($extension), '.')),
                $configuredExtensions
            ),
            true
        );

        foreach ($this->entries() as $entry) {
            if (($entry['compoundSuffixes'] ?? []) === []) {
                continue;
            }

            foreach ($entry['compoundSuffixes'] as $suffix) {
                $terminalExtension = strtolower((string) pathinfo($suffix, PATHINFO_EXTENSION));
                if ($terminalExtension !== '' && isset($extensionSet[$terminalExtension])) {
                    $languages[] = $entry['id'];
                    break;
                }
            }
        }

        return $this->sortLanguageIds(array_values(array_unique($languages)));
    }

    private function detectLanguageForFile(string $file): ?string
    {
        $lower = strtolower($file);

        foreach ($this->entries() as $entry) {
            foreach ($entry['compoundSuffixes'] as $suffix) {
                if (str_ends_with($lower, '.' . strtolower($suffix))) {
                    return $entry['id'];
                }
            }
        }

        $extension = strtolower((string) pathinfo($lower, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        foreach ($this->entries() as $entry) {
            if (in_array($extension, $entry['extensions'], true)) {
                return $entry['id'];
            }
        }

        return null;
    }

    /**
     * @param string[] $languageIds
     * @return string[]
     */
    /**
     * @param string[] $languageIds
     * @return string[]
     */
    public function sortLanguageIds(array $languageIds): array
    {
        $order = array_flip(array_map(static fn(array $entry): string => $entry['id'], $this->entries()));

        usort(
            $languageIds,
            static fn(string $left, string $right): int =>
                [$order[$left] ?? PHP_INT_MAX, $left] <=> [$order[$right] ?? PHP_INT_MAX, $right]
        );

        return array_values($languageIds);
    }
}
