<?php

namespace FlowEngine\Bootstrap;

/**
 * Marcadores de projeto, raízes de código e extensões presentes, compartilhados por `init` e pelo modo inferido.
 */
final class ProjectProfileDetector
{
    public const MARKER_FILES = [
        'artisan',
        'composer.json',
        'package.json',
        'pyproject.toml',
        'requirements.txt',
        'go.mod',
        'pubspec.yaml',
        'tsconfig.json',
    ];

    public const SOURCE_ROOT_CANDIDATES = [
        'src',
        'app',
        'lib',
        'routes',
        'resources/views',
        'backend',
        'backend/app',
        'backend/src',
        'frontend',
        'frontend/src',
        'server',
        'server/src',
        'client',
        'client/src',
        'apps',
        'packages',
        'services',
        'cmd',
        'internal',
        'pkg',
    ];

    private const LANGUAGE_MARKERS = [
        'composer' => ['artisan', 'composer.json', 'vendor/autoload.php'],
        'typescript' => ['package.json', 'tsconfig.json'],
        'python' => ['pyproject.toml', 'requirements.txt'],
    ];

    private const LANGUAGE_EXTENSIONS = [
        'composer' => ['php'],
        'typescript' => ['ts', 'tsx', 'js', 'jsx'],
        'python' => ['py'],
    ];

    public function __construct(
        private readonly ?LanguageSupportCatalog $languageCatalog = null,
    ) {
    }

    public function markerForRoot(string $root): ?string
    {
        foreach (self::MARKER_FILES as $marker) {
            if (is_file($root . DIRECTORY_SEPARATOR . $marker)) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Linguagens com marcador na raiz, na prioridade composer > typescript > python.
     *
     * @return string[]
     */
    public function languagesAt(string $root): array
    {
        $languages = [];
        foreach (self::LANGUAGE_MARKERS as $language => $markers) {
            foreach ($markers as $marker) {
                if (is_file($root . DIRECTORY_SEPARATOR . $marker)) {
                    $languages[] = $language;
                    break;
                }
            }
        }

        return $languages;
    }

    /**
     * @return string[]
     */
    public function extensionsFor(string $language): array
    {
        return self::LANGUAGE_EXTENSIONS[$language] ?? [];
    }

    /**
     * @return string[] caminhos absolutos
     */
    public function sourceRootsForCandidate(string $candidateRoot, ?string $marker): array
    {
        $relativeCandidates = match ($marker) {
            'artisan' => ['app', 'routes', 'resources/views'],
            'pubspec.yaml' => ['lib', 'test', 'integration_test', 'backend/app'],
            'go.mod' => ['cmd', 'internal', 'pkg'],
            'package.json', 'tsconfig.json' => ['src', 'app', 'server', 'client', 'routes', 'pages'],
            'composer.json' => ['src', 'app', 'routes', 'resources/views'],
            'pyproject.toml', 'requirements.txt' => ['src', 'app'],
            default => self::SOURCE_ROOT_CANDIDATES,
        };

        $roots = [];
        foreach ($relativeCandidates as $relative) {
            $path = $candidateRoot . DIRECTORY_SEPARATOR . $relative;
            if (is_dir($path)) {
                $roots[] = $path;
            }
        }

        return $roots;
    }

    /**
     * @param string[] $includePaths
     * @param string[] $ignoredPaths
     * @return string[]
     */
    public function detectExtensions(string $root, array $includePaths, array $ignoredPaths): array
    {
        $detected = [];
        $supported = $this->supportedExtensions();

        foreach ($includePaths as $includePath) {
            $base = $includePath === '.'
                ? $root
                : $root . DIRECTORY_SEPARATOR . $includePath;

            if (!is_dir($base)) {
                continue;
            }

            // Poda os diretórios ignorados: sem isso um include "." percorre node_modules/.venv inteiros.
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    fn(\SplFileInfo $entry): bool => !$entry->isDir()
                        || !$this->isIgnored($this->relativeTo($root, $entry->getPathname()), $ignoredPaths)
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                if ($this->isIgnored($this->relativeTo($root, $entry->getPathname()), $ignoredPaths)) {
                    continue;
                }

                $extension = strtolower((string) $entry->getExtension());
                if (in_array($extension, $supported, true)) {
                    $detected[$extension] = true;
                }
            }
        }

        return array_values(array_keys($detected));
    }

    /**
     * @param string[] $ignoredPaths
     */
    public function isIgnored(string $relativePath, array $ignoredPaths): bool
    {
        foreach ($ignoredPaths as $ignoredPath) {
            $normalized = trim(str_replace('\\', '/', $ignoredPath), '/');
            if ($normalized === '') {
                continue;
            }

            $relative = trim(str_replace('\\', '/', $relativePath), '/');
            if ($relative === $normalized || str_starts_with($relative, $normalized . '/')) {
                return true;
            }

            if (!str_contains($normalized, '/')
                && (str_contains($relative, '/' . $normalized . '/') || str_ends_with($relative, '/' . $normalized))
            ) {
                return true;
            }
        }

        return false;
    }

    private function relativeTo(string $root, string $path): string
    {
        return str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    }

    /**
     * @return string[]
     */
    public function supportedExtensions(): array
    {
        $catalog = $this->languageCatalog ?? new LanguageSupportCatalog();
        $extensions = [];
        foreach ($catalog->entries() as $entry) {
            foreach ($entry['extensions'] as $extension) {
                $extensions[$extension] = true;
            }
        }

        return array_keys($extensions);
    }
}
