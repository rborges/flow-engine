<?php

namespace FlowEngine\Application\CLI\Command;

use FlowEngine\Domain\Contracts\BundledLibraryScanner;

final class InitCommand implements Command
{
    public function __construct(
        private \FlowEngine\Console\ConsoleIO $io,
        private BundledLibraryScanner $libraryScanner
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

        $include = ['src'];
        if (is_dir($projectPath . DIRECTORY_SEPARATOR . 'app')) {
            $include = ['app'];
        } elseif (!is_dir($projectPath . DIRECTORY_SEPARATOR . 'src')) {
            $include = ['.'];
        }

        $autoload = null;
        if (file_exists($projectPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
            $autoload = 'vendor/autoload.php';
        }

        // Bibliotecas de terceiros copiadas para dentro do projeto dominam o grafo se
        // entrarem no scan — a maior "área" do sistema vira a lib de PDF. Detectá-las
        // aqui é o que separa uma config útil de um template em branco.
        $detected = $this->libraryScanner->detect($projectPath);

        $exclude = array_values(array_unique(array_merge(
            ['vendor', 'tests'],
            array_keys($detected)
        )));
        sort($exclude);

        $config = [
            'version' => '1.0',
            'context' => [
                'type' => 'composer',
                'autoload' => $autoload,
            ],
            'scan' => [
                'include' => $include,
                'exclude' => $exclude,
                'extensions' => ['php'],
            ],
        ];

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
