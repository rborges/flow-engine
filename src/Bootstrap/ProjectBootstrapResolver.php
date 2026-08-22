<?php

namespace FlowEngine\Bootstrap;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Contracts\ProjectConfig;
use FlowEngine\Infrastructure\Config\FileProjectConfig;
use FlowEngine\Infrastructure\Config\JsonProjectConfig;
use FlowEngine\Infrastructure\Config\SchemaValidator;
use FlowEngine\Infrastructure\Context\DefaultProjectContextFactory;
use FlowEngine\Infrastructure\Context\FlutterProjectContext;
use FlowEngine\Infrastructure\Context\InferredReadOnlyProjectContext;

final class ProjectBootstrapResolver
{
    private const GENERIC_INCLUDE_CANDIDATES = [
        'src',
        'app',
        'lib',
        'routes',
        'resources',
        'modules',
        'packages',
        'backend/app',
        'backend/src',
        'cmd',
        'internal',
        'pkg',
    ];

    private const COMMON_IGNORES = [
        'vendor',
        'node_modules',
        '.git',
        'build',
        'dist',
        'coverage',
        '.dart_tool',
        '.pub-cache',
        'storage',
        'bootstrap/cache',
        'ios/Pods',
    ];

    private const CONTEXTUAL_WORKTREE_IGNORE = '.worktrees';

    private const MARKER_FILES = [
        'artisan',
        'composer.json',
        'package.json',
        'pyproject.toml',
        'requirements.txt',
        'go.mod',
        'pubspec.yaml',
    ];

    private const SOURCE_ROOT_CANDIDATES = [
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

    private const MAX_DYNAMIC_INCLUDE_PATHS = 40;
    private const MAX_SCAN_PROBE_FILES = 5000;

    public function __construct(
        private readonly SchemaValidator $schemaValidator,
        private readonly ?DefaultProjectContextFactory $contextFactory = null,
        private readonly ?LanguageSupportCatalog $languageCatalog = null,
    ) {
    }

    public function resolve(string $projectPath, bool $allowReadOnlyInference = false): ProjectBootstrap
    {
        $root = realpath($projectPath) ?: $projectPath;
        $configPath = $root . DIRECTORY_SEPARATOR . 'flow-engine.json';

        if (is_file($configPath) || !$allowReadOnlyInference) {
            return $this->resolveStrict($root, $configPath);
        }

        return $this->resolveReadOnlyInference($root, $configPath);
    }

    private function resolveStrict(string $root, string $configPath): ProjectBootstrap
    {
        $config = new JsonProjectConfig($root, $this->schemaValidator);
        $context = ($this->contextFactory ?? new DefaultProjectContextFactory())->create($config);

        return new ProjectBootstrap(
            config: $config,
            context: $context,
            configResolution: $this->buildConfigResolution(
                mode: 'explicit_config',
                configPath: $configPath,
                hasConfigFile: true,
                detectedContext: $config->contextType(),
                context: $context,
                warnings: []
            ),
        );
    }

    private function resolveReadOnlyInference(string $root, string $configPath): ProjectBootstrap
    {
        $profile = $this->inferProfile($root);
        $config = new FileProjectConfig([], $root);

        return new ProjectBootstrap(
            config: $config,
            context: $profile['context'],
            configResolution: new ConfigResolution(
                mode: 'inferred_read_only',
                configPath: $configPath,
                hasConfigFile: false,
                detectedContext: $profile['detectedContext'],
                includePaths: $profile['context']->includePaths(),
                ignoredPaths: $profile['context']->ignoredPaths(),
                extensions: $profile['context']->extensions(),
                warnings: $profile['warnings'],
            ),
        );
    }

    /**
     * @return array{context: InferredReadOnlyProjectContext|FlutterProjectContext, detectedContext: string, warnings: string[]}
     */
    private function inferProfile(string $root): array
    {
        $commonIgnored = $this->contextualCommonIgnores($root);

        if (is_file($root . DIRECTORY_SEPARATOR . 'pubspec.yaml')) {
            $context = new FlutterProjectContext($root);
            return [
                'context' => $context,
                'detectedContext' => 'flutter',
                'warnings' => $this->inferenceWarnings($context->extensions()),
            ];
        }

        if (is_file($root . DIRECTORY_SEPARATOR . 'wp-config.php')) {
            $ignored = array_values(array_unique(array_merge(
                $commonIgnored,
                ['wp-admin', 'wp-includes', 'wp-content/cache']
            )));
            $context = new InferredReadOnlyProjectContext(
                rootPath: $root,
                includePaths: $this->filterExistingPaths($root, ['wp-content']),
                ignoredPaths: $ignored,
                extensions: ['php'],
                defineWordPressConstants: true,
            );

            return [
                'context' => $context,
                'detectedContext' => 'wordpress',
                'warnings' => [],
            ];
        }

        if (is_file($root . DIRECTORY_SEPARATOR . 'artisan')) {
            $includePaths = $this->filterExistingPaths($root, ['app', 'routes', 'resources/views']);
            if ($includePaths === []) {
                $includePaths = $this->genericIncludePaths($root);
            }

            $ignored = $commonIgnored;
            $extensions = $this->detectExtensions($root, $includePaths, $ignored);

            return [
                'context' => new InferredReadOnlyProjectContext(
                    rootPath: $root,
                    includePaths: $includePaths,
                    ignoredPaths: $ignored,
                    extensions: $extensions,
                ),
                'detectedContext' => 'laravel',
                'warnings' => $this->inferenceWarnings($extensions),
            ];
        }

        $ignored = $commonIgnored;
        $dynamic = $this->dynamicInferenceProfile($root, $ignored);
        $includePaths = $dynamic['includePaths'];
        $extensions = $this->detectExtensions($root, $includePaths, $ignored);
        if ($extensions === []) {
            $extensions = $this->allSupportedSimpleExtensions();
        }
        $warnings = array_merge(
            $dynamic['warnings'],
            $this->inferenceWarnings($extensions)
        );

        return [
            'context' => new InferredReadOnlyProjectContext(
                rootPath: $root,
                includePaths: $includePaths,
                ignoredPaths: $ignored,
                extensions: $extensions,
            ),
            'detectedContext' => $dynamic['detectedContext'],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function buildConfigResolution(
        string $mode,
        string $configPath,
        bool $hasConfigFile,
        string $detectedContext,
        ProjectContext $context,
        array $warnings
    ): ConfigResolution {
        return new ConfigResolution(
            mode: $mode,
            configPath: $configPath,
            hasConfigFile: $hasConfigFile,
            detectedContext: $detectedContext,
            includePaths: $context->includePaths(),
            ignoredPaths: $context->ignoredPaths(),
            extensions: $context->extensions(),
            warnings: $warnings,
        );
    }

    /**
     * @return string[]
     */
    private function genericIncludePaths(string $root): array
    {
        $paths = $this->filterExistingPaths($root, self::GENERIC_INCLUDE_CANDIDATES);

        return $paths === [] ? ['.'] : $paths;
    }

    /**
     * @param string[] $ignoredPaths
     * @return array{includePaths: string[], detectedContext: string, warnings: string[]}
     */
    private function dynamicInferenceProfile(string $root, array $ignoredPaths): array
    {
        $includePaths = [];
        $markers = [];
        $warnings = [];

        foreach ($this->candidateProjectRoots($root, $ignoredPaths) as $candidateRoot) {
            $relativeRoot = $this->relativePath($root, $candidateRoot);
            $marker = $this->markerForRoot($candidateRoot);
            if ($marker !== null) {
                $markers[$marker] = true;
            }

            foreach ($this->sourceRootsForCandidate($candidateRoot, $marker) as $sourceRoot) {
                $relative = $this->relativePath($root, $sourceRoot);
                if ($relative !== '' && !$this->isIgnored($relative, $ignoredPaths)) {
                    $includePaths[$relative] = true;
                }
            }

            if ($marker !== null && $relativeRoot !== '' && !isset($includePaths[$relativeRoot])) {
                $includePaths[$relativeRoot] = true;
            }
        }

        if ($includePaths === []) {
            foreach (self::SOURCE_ROOT_CANDIDATES as $candidate) {
                $path = $root . DIRECTORY_SEPARATOR . $candidate;
                if (!is_dir($path) || $this->isIgnored($candidate, $ignoredPaths)) {
                    continue;
                }
                if ($this->containsSupportedSource($path, $root, $ignoredPaths)) {
                    $includePaths[$candidate] = true;
                }
            }
        }

        if ($includePaths === [] && $this->rootHasSupportedSourceFile($root)) {
            $includePaths['.'] = true;
        }

        if ($includePaths === []) {
            $includePaths['.'] = true;
        }

        $includePaths = $this->normalizeIncludePaths(array_keys($includePaths));

        if (count($includePaths) > self::MAX_DYNAMIC_INCLUDE_PATHS) {
            $originalCount = count($includePaths);
            $includePaths = array_slice($includePaths, 0, self::MAX_DYNAMIC_INCLUDE_PATHS);
            $warnings[] = sprintf(
                'Dynamic inferred scan found %d source roots and limited the initial scan to %d roots. Call flow_map on a narrower subdirectory for the skipped roots.',
                $originalCount,
                self::MAX_DYNAMIC_INCLUDE_PATHS
            );
        }

        if (in_array(self::CONTEXTUAL_WORKTREE_IGNORE, $ignoredPaths, true)) {
            $warnings[] = 'Repository-level inferred scan skipped .worktrees to avoid mixing parallel branches. To analyze a worktree, pass its .worktrees/<name> path as project.';
        }

        return [
            'includePaths' => $includePaths,
            'detectedContext' => $this->detectedContextFromMarkers($markers, $includePaths, $root),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param string[] $ignoredPaths
     * @return string[]
     */
    private function candidateProjectRoots(string $root, array $ignoredPaths): array
    {
        $roots = [$root => $root];
        $queue = [[$root, 0]];
        $maxDepth = 3;
        $visited = [$root => true];

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if (!is_dir($current) || $depth >= $maxDepth) {
                continue;
            }

            $entries = scandir($current);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $absolute = $current . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($absolute)) {
                    continue;
                }

                $relative = $this->relativePath($root, $absolute);
                if ($relative === '' || $this->isIgnored($relative, $ignoredPaths)) {
                    continue;
                }

                if ($this->markerForRoot($absolute) !== null) {
                    $roots[$absolute] = $absolute;
                }

                if (!isset($visited[$absolute]) && $this->shouldDescendForDynamicDiscovery($relative, $depth)) {
                    $visited[$absolute] = true;
                    $queue[] = [$absolute, $depth + 1];
                }
            }
        }

        return array_values($roots);
    }

    private function shouldDescendForDynamicDiscovery(string $relative, int $depth): bool
    {
        if ($depth >= 2) {
            return false;
        }

        $first = explode('/', str_replace('\\', '/', $relative))[0] ?? '';

        return in_array($first, [
            'apps',
            'packages',
            'services',
            'backend',
            'frontend',
            'server',
            'client',
            'laravel',
            'web',
            'src',
        ], true);
    }

    private function markerForRoot(string $root): ?string
    {
        foreach (self::MARKER_FILES as $marker) {
            if (is_file($root . DIRECTORY_SEPARATOR . $marker)) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function sourceRootsForCandidate(string $candidateRoot, ?string $marker): array
    {
        $relativeCandidates = match ($marker) {
            'artisan' => ['app', 'routes', 'resources/views'],
            'pubspec.yaml' => ['lib', 'test', 'integration_test', 'backend/app'],
            'go.mod' => ['cmd', 'internal', 'pkg'],
            'package.json' => ['src', 'app', 'server', 'client', 'routes', 'pages'],
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
     * @param array<string, true> $markers
     * @param string[] $includePaths
     */
    private function detectedContextFromMarkers(array $markers, array $includePaths, string $root): string
    {
        if (isset($markers['artisan'])) {
            return count($markers) > 1 || $this->hasNestedSourceRoots($includePaths) ? 'monorepo-laravel' : 'laravel';
        }

        if (isset($markers['pubspec.yaml'])) {
            return 'flutter';
        }

        if (isset($markers['package.json']) && (isset($markers['composer.json']) || isset($markers['go.mod']) || isset($markers['pyproject.toml']))) {
            return 'monorepo';
        }

        if (isset($markers['package.json'])) {
            return $this->hasNestedSourceRoots($includePaths) ? 'typescript-monorepo' : 'typescript';
        }

        if (isset($markers['composer.json']) || is_file($root . DIRECTORY_SEPARATOR . 'vendor/autoload.php')) {
            return 'composer';
        }

        if (isset($markers['go.mod'])) {
            return 'go';
        }

        if (isset($markers['pyproject.toml']) || isset($markers['requirements.txt'])) {
            return 'python';
        }

        return 'generic';
    }

    /**
     * @param string[] $includePaths
     */
    private function hasNestedSourceRoots(array $includePaths): bool
    {
        foreach ($includePaths as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (str_starts_with($normalized, 'apps/') || str_starts_with($normalized, 'packages/') || str_starts_with($normalized, 'services/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function normalizeIncludePaths(array $paths): array
    {
        $paths = array_values(array_unique(array_map(
            static fn(string $path): string => str_replace('\\', '/', trim($path, "/\\")),
            $paths
        )));
        $paths = array_values(array_filter($paths, static fn(string $path): bool => $path !== ''));

        usort($paths, static function (string $a, string $b): int {
            $depthA = substr_count($a, '/');
            $depthB = substr_count($b, '/');
            return [$depthA, $a] <=> [$depthB, $b];
        });

        $kept = [];
        foreach ($paths as $path) {
            foreach ($kept as $existing) {
                if ($path === $existing || str_starts_with($path, $existing . '/')) {
                    continue 2;
                }
            }
            $kept[] = $path;
        }

        return $kept === [] ? ['.'] : $kept;
    }

    private function containsSupportedSource(string $base, string $root, array $ignoredPaths): bool
    {
        $seen = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $relative = $this->relativePath($root, $entry->getPathname());
            if ($this->isIgnored($relative, $ignoredPaths)) {
                continue;
            }

            if (in_array(strtolower((string) $entry->getExtension()), $this->allSupportedSimpleExtensions(), true)) {
                return true;
            }

            $seen++;
            if ($seen >= self::MAX_SCAN_PROBE_FILES) {
                return false;
            }
        }

        return false;
    }

    private function rootHasSupportedSourceFile(string $root): bool
    {
        $entries = scandir($root);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), $this->allSupportedSimpleExtensions(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function contextualCommonIgnores(string $root): array
    {
        $ignores = self::COMMON_IGNORES;
        $normalized = str_replace('\\', '/', $root);

        if (!str_contains($normalized, '/.worktrees/') && is_dir($root . DIRECTORY_SEPARATOR . self::CONTEXTUAL_WORKTREE_IGNORE)) {
            $ignores[] = self::CONTEXTUAL_WORKTREE_IGNORE;
        }

        return $ignores;
    }

    private function relativePath(string $root, string $path): string
    {
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        $path = str_replace('\\', '/', rtrim($path, '/\\'));

        if ($path === $root) {
            return '';
        }

        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function filterExistingPaths(string $root, array $paths): array
    {
        $existing = [];

        foreach ($paths as $path) {
            if ($path === '.') {
                $existing[] = '.';
                continue;
            }

            if (file_exists($root . DIRECTORY_SEPARATOR . $path)) {
                $existing[] = $path;
            }
        }

        return array_values(array_unique($existing));
    }

    /**
     * @param string[] $includePaths
     * @param string[] $ignoredPaths
     * @return string[]
     */
    private function detectExtensions(string $root, array $includePaths, array $ignoredPaths): array
    {
        $detected = [];

        foreach ($includePaths as $includePath) {
            $base = $includePath === '.'
                ? $root
                : $root . DIRECTORY_SEPARATOR . $includePath;

            if (!is_dir($base)) {
                continue;
            }

            if (!is_readable($base)) {
                throw new \RuntimeException(sprintf('Configured scan root is not readable: %s', $base));
            }

            $directoryIterator = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
            $prunedIterator = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                function (\SplFileInfo $entry) use ($root, $ignoredPaths): bool {
                    if (!$entry->isDir()) {
                        return true;
                    }

                    $relative = $this->relativePath($root, $entry->getPathname());
                    return !$this->isIgnored($relative, $ignoredPaths) && is_readable($entry->getPathname());
                }
            );
            $iterator = new \RecursiveIteratorIterator(
                $prunedIterator,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $entry->getPathname());
                if ($this->isIgnored($relative, $ignoredPaths)) {
                    continue;
                }

                $extension = strtolower((string) $entry->getExtension());
                if (in_array($extension, $this->allSupportedSimpleExtensions(), true)) {
                    $detected[$extension] = true;
                }
            }
        }

        return array_values(array_keys($detected));
    }

    /**
     * @param string[] $ignoredPaths
     */
    private function isIgnored(string $relativePath, array $ignoredPaths): bool
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

    /**
     * @return string[]
     */
    private function allSupportedSimpleExtensions(): array
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

    /**
     * @param string[] $extensions
     * @return string[]
     */
    private function inferenceWarnings(array $extensions): array
    {
        if ($extensions !== []) {
            return [];
        }

        return ['No supported source language was detected in the inferred scan roots.'];
    }
}
