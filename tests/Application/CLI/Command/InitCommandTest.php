<?php

namespace Tests\Application\CLI\Command;

use FlowEngine\Application\CLI\Command\InitCommand;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Console\ConsoleIO;
use FlowEngine\Infrastructure\Config\BundledLibraryDetector;
use PHPUnit\Framework\TestCase;

final class InitCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/init-command-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_generates_flutter_config_for_pubspec_projects(): void
    {
        file_put_contents($this->tempDir . '/pubspec.yaml', "name: the_singer\n");
        mkdir($this->tempDir . '/backend/app', 0777, true);

        $io = $this->createMock(ConsoleIO::class);
        $io->expects($this->once())
            ->method('json')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['status'] ?? null) === 'ok'
                    && str_ends_with((string) ($payload['path'] ?? ''), 'flow-engine.json');
            }));

        $command = new InitCommand($io, new BundledLibraryDetector());
        $command->handle(['bin/engine.php', 'init', $this->tempDir]);

        $decoded = json_decode((string) file_get_contents($this->tempDir . '/flow-engine.json'), true);

        self::assertSame('flutter', $decoded['context']['type']);
        self::assertContains('lib', $decoded['scan']['include']);
        self::assertContains('backend/app', $decoded['scan']['include']);
        self::assertContains('dart', $decoded['scan']['extensions']);
        self::assertContains('py', $decoded['scan']['extensions']);
    }

    public function test_php_project_with_package_json_without_frontend_sources_stays_php(): void
    {
        $this->write('composer.json', '{}');
        $this->write('app/Service.php', "<?php\nclass Service {}\n");
        $this->write('package.json', '{}');

        $config = $this->init();

        self::assertSame([
            'version' => '1.0',
            'context' => ['type' => 'composer', 'autoload' => null],
            'scan' => ['include' => ['app'], 'exclude' => ['build', 'coverage', 'dist', 'node_modules', 'tests', 'vendor'], 'extensions' => ['php']],
        ], $config);
    }

    public function test_php_only_project_keeps_previous_config(): void
    {
        $this->write('composer.json', '{}');
        $this->write('src/Service.php', "<?php\nclass Service {}\n");

        self::assertSame([
            'version' => '1.0',
            'context' => ['type' => 'composer', 'autoload' => null],
            'scan' => ['include' => ['src'], 'exclude' => ['tests', 'vendor'], 'extensions' => ['php']],
        ], $this->init());
    }

    public function test_python_project_gets_python_config_that_analyzes(): void
    {
        $this->write('requirements.txt', "requests\n");
        $this->write('src/orders.py', "def create_order(total):\n    return total\n");

        $config = $this->init();

        self::assertSame('python', $config['context']['type']);
        self::assertSame(['src'], $config['scan']['include']);
        self::assertSame(['py'], $config['scan']['extensions']);
        foreach (['venv', '.venv', '__pycache__', 'tests'] as $dir) {
            self::assertContains($dir, $config['scan']['exclude']);
        }
        self::assertGreaterThan(0, $this->nodeCount());
    }

    public function test_python_package_outside_conventional_roots_scans_the_root(): void
    {
        $this->write('pyproject.toml', "[project]\nname = 'shop'\n");
        $this->write('shop/orders.py', "def create_order(total):\n    return total\n");

        $config = $this->init();

        self::assertSame(['shop'], $config['scan']['include']);
        self::assertGreaterThan(0, $this->nodeCount());
    }

    public function test_monorepo_with_markers_only_in_subfolders(): void
    {
        $this->write('backend/requirements.txt', "flask\n");
        $this->write('backend/api.py', "def handler():\n    return 1\n");
        $this->write('frontend/package.json', '{}');
        $this->write('frontend/src/main.ts', "export function main() {\n  return 1;\n}\n");

        $config = $this->init();

        self::assertSame('typescript', $config['context']['type']);
        self::assertSame(['frontend', 'backend'], $config['scan']['include']);
        self::assertSame(['ts', 'py'], $config['scan']['extensions']);
        self::assertNotContains('frontend', $config['scan']['exclude']);
        self::assertGreaterThan(0, $this->nodeCount());
    }

    public function test_laravel_with_inertia_frontend_scans_resources_js(): void
    {
        $this->write('artisan', "<?php\n");
        $this->write('composer.json', '{}');
        $this->write('package.json', '{}');
        $this->write('tsconfig.json', '{}');
        $this->write('app/Http/Controller.php', "<?php\nclass Controller {}\n");
        $this->write('resources/js/app.tsx', "export function App() {\n  return null;\n}\n");
        $this->write('public/build/app.js', "var x = 1;\n");

        $config = $this->init();

        self::assertSame('composer', $config['context']['type']);
        self::assertSame(['app', 'resources/js'], $config['scan']['include']);
        self::assertSame(['php', 'tsx'], $config['scan']['extensions']);
    }

    public function test_composer_with_python_package_in_named_folder_keeps_python(): void
    {
        $this->write('composer.json', '{}');
        $this->write('requirements.txt', "requests\n");
        $this->write('src/A.php', "<?php\nclass A {}\n");
        $this->write('mypkg/m.py', "def run():\n    return 1\n");

        $config = $this->init();

        self::assertSame(['src', 'mypkg'], $config['scan']['include']);
        self::assertSame(['php', 'py'], $config['scan']['extensions']);
    }

    public function test_nextjs_layout_includes_every_source_folder(): void
    {
        $this->write('package.json', '{}');
        $this->write('app/page.tsx', "export default function Page() {\n  return null;\n}\n");
        $this->write('components/button.tsx', "export function Button() {\n  return null;\n}\n");
        $this->write('lib/api.ts', "export function load() {\n  return 1;\n}\n");
        $this->write('node_modules/react/index.js', "module.exports = {};\n");

        $config = $this->init();

        self::assertSame(['app', 'components', 'lib'], $config['scan']['include']);
        self::assertSame(['ts', 'tsx'], $config['scan']['extensions']);
    }

    public function test_typescript_project_gets_typescript_config_that_analyzes(): void
    {
        $this->write('tsconfig.json', '{}');
        $this->write('src/user.service.ts', "export class UserService {\n  getUser(id: string) {\n    return id;\n  }\n}\n");
        $this->write('src/app.tsx', "export function App() {\n  return null;\n}\n");

        $config = $this->init();

        self::assertSame('typescript', $config['context']['type']);
        self::assertSame(['src'], $config['scan']['include']);
        self::assertSame(['ts', 'tsx'], $config['scan']['extensions']);
        foreach (['node_modules', 'dist', 'build', 'coverage'] as $dir) {
            self::assertContains($dir, $config['scan']['exclude']);
        }
        self::assertGreaterThan(0, $this->nodeCount());
    }

    public function test_javascript_project_lists_only_present_extensions(): void
    {
        $this->write('package.json', '{}');
        $this->write('server/index.js', "function start() {\n  return 1;\n}\n");

        $config = $this->init();

        self::assertSame('typescript', $config['context']['type']);
        self::assertSame(['server'], $config['scan']['include']);
        self::assertSame(['js'], $config['scan']['extensions']);
        self::assertGreaterThan(0, $this->nodeCount());
    }

    public function test_mixed_project_unions_extensions_and_prefers_typescript_type(): void
    {
        $this->write('package.json', '{}');
        $this->write('requirements.txt', "flask\n");
        $this->write('src/index.ts', "export function main() {\n  return 1;\n}\n");
        $this->write('app/api.py', "def handler():\n    return 1\n");

        $config = $this->init();

        self::assertSame('typescript', $config['context']['type']);
        self::assertSame(['src', 'app'], $config['scan']['include']);
        self::assertSame(['ts', 'py'], $config['scan']['extensions']);
    }

    public function test_laravel_with_frontend_unions_found_extensions(): void
    {
        $this->write('artisan', "<?php\n");
        $this->write('composer.json', '{}');
        $this->write('package.json', '{}');
        $this->write('app/Http/Controller.php', "<?php\nclass Controller {}\n");
        $this->write('src/main.ts', "export const x = 1;\n");

        $config = $this->init();

        self::assertSame('composer', $config['context']['type']);
        self::assertSame(['app', 'src'], $config['scan']['include']);
        self::assertSame(['php', 'ts'], $config['scan']['extensions']);
    }

    /**
     * @return array<string, mixed>
     */
    private function init(): array
    {
        $io = $this->createStub(ConsoleIO::class);
        (new InitCommand($io, new BundledLibraryDetector()))->handle(['bin/engine.php', 'init', $this->tempDir]);

        return json_decode((string) file_get_contents($this->tempDir . '/flow-engine.json'), true);
    }

    private function nodeCount(): int
    {
        $container = new Container($this->tempDir, useCache: false);
        $container->analyzeProject()->execute();

        return count($container->getFlow()->nodes());
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->tempDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
