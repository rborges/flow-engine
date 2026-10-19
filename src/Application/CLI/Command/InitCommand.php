<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Bootstrap\ProjectProfileDetector;
use FlowEngine\Domain\Contracts\BundledLibraryScanner;

final class InitCommand implements Command
{
    private const LANGUAGE_EXCLUDES = [
        'composer' => ['vendor'],
        'typescript' => ['node_modules', 'dist', 'build', 'coverage'],
        'python' => ['venv', '.venv', '__pycache__'],
    ];

    // Saída de build, assets públicos e dados: arquivos .js/.py ali não são código do projeto.
    private const NON_SOURCE_DIRS = ['public', 'storage', 'bootstrap', 'database', 'docs'];

    public function __construct(
        private \FlowEngine\Console\ConsoleIO $io,
        private BundledLibraryScanner $libraryScanner,
        private ProjectProfileDetector $profileDetector = new ProjectProfileDetector()
    ) {
    }

    public function supports(string $command): bool
    {
        return $command === 'init';
    }

    public function handle(array $argv): void
    {
        $projectPath = $argv[2] ?? getcwd();
        $configPath = rtrim($projectPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'flow-engine.json';

        if (file_exists($configPath)) {
            $this->io->error("flow-engine.json already exists at {$configPath}");
            return;
        }

        if (file_exists($projectPath . DIRECTORY_SEPARATOR . 'pubspec.yaml')) {
            $config = $this->buildFlutterConfig($projectPath);
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->io->json([
                'status' => 'ok',
                'message' => 'flow-engine.json created',
                'path' => $configPath,
            ]);
            return;
        }

        // Bibliotecas de terceiros copiadas para dentro do projeto dominam o grafo se
        // entrarem no scan — a maior "área" do sistema vira a lib de PDF. Detectá-las
        // aqui é o que separa uma config útil de um template em branco.
        $detected = $this->libraryScanner->detect($projectPath);

        $languages = $this->detectLanguages($projectPath);
        if (array_diff($languages, ['composer']) === []) {
            $config = $this->buildPhpConfig($projectPath, $detected);
        } else {
            // Subprojeto com marcador próprio (monorepo `frontend/`) é código, não biblioteca embutida.
            $detected = array_filter(
                $detected,
                fn(string $path): bool => $this->profileDetector->languagesAt($projectPath . DIRECTORY_SEPARATOR . $path) === [],
                ARRAY_FILTER_USE_KEY
            );
            $config = $this->buildMultiLanguageConfig($projectPath, $languages, $detected);
        }

        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->io->json([
            'status' => 'ok',
            'message' => 'flow-engine.json created',
            'path' => $configPath,
            // O usuário precisa poder conferir o que ficou de fora, e por quê.
            'excluded' => $detected,
        ]);
    }

    /**
     * @param array<string, string> $detected
     * @return array<string, mixed>
     */
    private function buildPhpConfig(string $projectPath, array $detected): array
    {
        $include = ['src'];
        if (is_dir($projectPath . DIRECTORY_SEPARATOR . 'app')) {
            $include = ['app'];
        } elseif (!is_dir($projectPath . DIRECTORY_SEPARATOR . 'src')) {
            $include = ['.'];
        }

        $exclude = array_values(array_unique(array_merge(
            ['vendor', 'tests'],
            array_keys($detected)
        )));
        sort($exclude);

        return [
            'version' => '1.0',
            'context' => [
                'type' => 'composer',
                'autoload' => $this->composerAutoload($projectPath),
            ],
            'scan' => [
                'include' => $include,
                'exclude' => $exclude,
                'extensions' => ['php'],
            ],
        ];
    }

    /**
     * Linguagem principal define o `type` (composer > typescript > python); extensões são a união.
     *
     * @param string[] $languages
     * @param array<string, string> $detected
     * @return array<string, mixed>
     */
    private function buildMultiLanguageConfig(string $projectPath, array $languages, array $detected): array
    {
        $exclude = ['tests'];
        foreach ($languages as $language) {
            $exclude = array_merge($exclude, self::LANGUAGE_EXCLUDES[$language]);
        }
        $exclude = array_values(array_unique(array_merge($exclude, array_keys($detected))));

        $include = in_array('composer', $languages, true)
            ? $this->buildPhpConfig($projectPath, [])['scan']['include']
            : [];
        $extensions = in_array('composer', $languages, true) ? ['php'] : [];

        foreach (array_diff($languages, ['composer']) as $language) {
            $candidates = $this->profileDetector->extensionsFor($language);
            foreach ($this->sourceDirs($projectPath, $exclude) as $dir) {
                $present = array_intersect($candidates, $this->profileDetector->detectExtensions($projectPath, [$dir], $exclude));
                if ($present !== []) {
                    $include[] = $dir;
                    $extensions = array_merge($extensions, $present);
                }
            }
            $atRoot = array_intersect($candidates, $this->rootFileExtensions($projectPath));
            if ($atRoot !== []) {
                $include[] = '.';
                $extensions = array_merge($extensions, $atRoot);
            }
        }

        $include = array_values(array_unique($include));
        if ($include === [] || in_array('.', $include, true)) {
            $include = ['.'];
        }

        sort($exclude);

        $type = $languages[0];
        $extensions = array_values(array_unique($extensions));
        if ($extensions === []) {
            $extensions = $this->profileDetector->extensionsFor($type);
        }
        $extensions = array_values(array_filter(
            array_merge(...array_map(fn(string $l): array => $this->profileDetector->extensionsFor($l), $languages)),
            static fn(string $e): bool => in_array($e, $extensions, true)
        ));

        return [
            'version' => '1.0',
            'context' => [
                'type' => $type,
                'autoload' => $type === 'composer' ? $this->composerAutoload($projectPath) : null,
            ],
            'scan' => [
                'include' => $include,
                'exclude' => $exclude,
                'extensions' => $extensions,
            ],
        ];
    }

    /**
     * Linguagens com marcador na raiz; sem nenhum, as dos subdiretórios diretos (monorepo `backend/` + `frontend/`).
     *
     * @return string[]
     */
    private function detectLanguages(string $projectPath): array
    {
        $languages = $this->profileDetector->languagesAt($projectPath);
        if ($languages !== []) {
            return $languages;
        }

        $found = [];
        foreach ($this->childDirs($projectPath) as $dir) {
            foreach ($this->profileDetector->languagesAt($projectPath . DIRECTORY_SEPARATOR . $dir) as $language) {
                $found[$language] = true;
            }
        }

        return array_values(array_filter(
            ['composer', 'typescript', 'python'],
            static fn(string $language): bool => isset($found[$language])
        ));
    }

    /**
     * Diretórios de primeiro nível candidatos a raiz de código; `resources/` do Laravel entra pelos filhos.
     *
     * @param string[] $exclude
     * @return string[]
     */
    private function sourceDirs(string $projectPath, array $exclude): array
    {
        $dirs = [];
        foreach ($this->childDirs($projectPath) as $dir) {
            if (in_array($dir, self::NON_SOURCE_DIRS, true) || $this->profileDetector->isIgnored($dir, $exclude)) {
                continue;
            }

            if ($dir === 'resources') {
                foreach ($this->childDirs($projectPath . DIRECTORY_SEPARATOR . $dir) as $child) {
                    $dirs[] = $dir . '/' . $child;
                }
                continue;
            }

            $dirs[] = $dir;
        }

        return $dirs;
    }

    /**
     * @return string[]
     */
    private function childDirs(string $path): array
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, static fn(string $entry): bool => $entry[0] !== '.'
            && !in_array($entry, ['vendor', 'node_modules'], true)
            && is_dir($path . DIRECTORY_SEPARATOR . $entry)));
    }

    /**
     * @return string[]
     */
    private function rootFileExtensions(string $projectPath): array
    {
        $extensions = [];
        foreach (@scandir($projectPath) ?: [] as $entry) {
            if (is_file($projectPath . DIRECTORY_SEPARATOR . $entry)) {
                $extensions[] = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            }
        }

        return array_values(array_unique($extensions));
    }

    private function composerAutoload(string $projectPath): ?string
    {
        return file_exists($projectPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')
            ? 'vendor/autoload.php'
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFlutterConfig(string $projectPath): array
    {
        $include = ['lib', 'test', 'integration_test'];
        $extensions = ['dart'];
        $backendRoots = [];

        if (is_dir($projectPath . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'app')) {
            $include[] = 'backend/app';
            $extensions[] = 'py';
            $backendRoots[] = 'backend/app';
        }

        return [
            'version' => '1.0',
            'context' => [
                'type' => 'flutter',
                'autoload' => null,
            ],
            'scan' => [
                'include' => $include,
                'exclude' => [
                    '.dart_tool',
                    '.pub-cache',
                    'build',
                    'backend/.pytest_cache',
                    'backend/tmp',
                    'ios/Pods',
                ],
                'extensions' => $extensions,
            ],
            'flutter' => [
                'libRoot' => 'lib',
                'testRoots' => ['test', 'integration_test'],
                'platformRoots' => ['android', 'ios', 'windows'],
                'backendRoots' => $backendRoots,
                'entryFiles' => ['lib/main.dart'],
            ],
        ];
    }
}
