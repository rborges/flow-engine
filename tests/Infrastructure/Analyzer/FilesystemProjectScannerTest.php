<?php

namespace Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;
use PHPUnit\Framework\TestCase;

final class FilesystemProjectScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-scanner-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
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

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function createFile(string $relativePath, string $content = '<?php'): void
    {
        $full = $this->tmpDir . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($full);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($full, $content);
    }

    private function makeContext(
        array $includePaths = ['.'],
        array $ignoredPaths = [],
        array $extensions = ['php']
    ): ProjectContext {
        return new class($this->tmpDir, $includePaths, $ignoredPaths, $extensions) implements ProjectContext {
            public function __construct(
                private string $root,
                private array $includes,
                private array $ignored,
                private array $exts,
            ) {
            }

            public function boot(): void {}
            public function rootPath(): string { return $this->root; }
            public function includePaths(): array { return $this->includes; }
            public function ignoredPaths(): array { return $this->ignored; }
            public function extensions(): array { return $this->exts; }
        };
    }

    public function test_scan_returns_php_file_in_root(): void
    {
        $this->createFile('src/Foo.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['src']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('Foo.php', $result[0]);
    }

    public function test_node_modules_is_excluded_by_default(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('node_modules/vite/dist/index.php');
        $this->createFile('node_modules/wasm-util/src/lib.php');

        $scanner = new FilesystemProjectScanner();
        // Context does NOT list node_modules in ignoredPaths -- default must catch it
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $paths = array_map('basename', $result);
        $this->assertContains('App.php', $paths);
        $this->assertNotContains('index.php', $paths);
        $this->assertNotContains('lib.php', $paths);
    }

    public function test_vendor_is_excluded_by_default(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('vendor/nikic/php-parser/Parser.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('App.php', $result[0]);
    }

    public function test_git_directory_is_excluded_by_default(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('.git/hooks/pre-commit.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('App.php', $result[0]);
    }

    public function test_context_ignored_paths_are_also_excluded(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('storage/logs/app.php');

        $scanner = new FilesystemProjectScanner();
        // Context provides its own ignore in addition to defaults
        $result = $scanner->scan($this->makeContext(
            includePaths: ['.'],
            ignoredPaths: ['storage'],
        ));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('App.php', $result[0]);
    }

    public function test_default_ignores_do_not_block_similarly_named_app_dirs(): void
    {
        // A directory named e.g. "build-tools" should not be blocked by "build"
        $this->createFile('build-tools/Compiler.php');
        $this->createFile('src/App.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $basenames = array_map('basename', $result);
        $this->assertContains('App.php', $basenames);
        $this->assertContains('Compiler.php', $basenames);
    }

    public function test_build_directory_is_excluded_by_default(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('build/output/Compiled.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('App.php', $result[0]);
    }

    public function test_nested_node_modules_is_excluded(): void
    {
        // Laravel project might have resources/js/node_modules or similar
        $this->createFile('app/Http/Controller.php');
        $this->createFile('resources/js/node_modules/axios/lib/axios.php');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('Controller.php', $result[0]);
    }

    public function test_python_virtualenv_with_pyvenv_cfg_is_excluded_regardless_of_name(): void
    {
        // Real-world case: a virtualenv named `pvenv` (not in DEFAULT_IGNORED_DIRS)
        // must still be pruned because it contains pyvenv.cfg.
        $this->createFile('src/app.py', '# app');
        $this->createFile('pvenv/pyvenv.cfg', "home = /usr/bin\nversion = 3.12.3\n");
        $this->createFile('pvenv/lib/python3.12/site-packages/requests/__init__.py', '# requests');
        $this->createFile('pvenv/lib/python3.12/os.py', '# stdlib');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(
            includePaths: ['.'],
            extensions: ['py'],
        ));

        $basenames = array_map('basename', $result);
        $this->assertContains('app.py', $basenames);
        $this->assertNotContains('__init__.py', $basenames);
        $this->assertNotContains('os.py', $basenames);
    }

    public function test_include_path_pointing_at_virtualenv_root_is_skipped(): void
    {
        // Edge case: includePaths configured directly to a virtualenv root.
        // The callback only sees children, so the root must be checked up front.
        $this->createFile('pvenv/pyvenv.cfg', "version = 3.12\n");
        $this->createFile('pvenv/lib/python3.12/os.py', '# stdlib');

        $scanner = new FilesystemProjectScanner();
        $result = $scanner->scan($this->makeContext(
            includePaths: ['pvenv'],
            extensions: ['py'],
        ));

        $this->assertSame([], $result);
    }

    public function test_reports_unreadable_non_ignored_directory_and_keeps_scanning(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('blocked/Secret.php');
        $blocked = $this->tmpDir . DIRECTORY_SEPARATOR . 'blocked';
        $scanner = new FilesystemProjectScanner(
            static fn(string $path): bool => $path !== $blocked
        );

        $result = $scanner->scan($this->makeContext(includePaths: ['.']));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('App.php', $result[0]);
        $this->assertSame(['Skipped unreadable directory: blocked'], $scanner->scanWarnings());
    }

    public function test_prunes_ignored_directory_before_readability_check(): void
    {
        $this->createFile('src/App.php');
        $this->createFile('storage/private/Secret.php');
        $checks = [];
        $scanner = new FilesystemProjectScanner(
            static function (string $path) use (&$checks): bool {
                $checks[] = $path;
                return true;
            }
        );

        $result = $scanner->scan($this->makeContext(includePaths: ['.'], ignoredPaths: ['storage']));

        $this->assertCount(1, $result);
        $this->assertSame([], $scanner->scanWarnings());
        $this->assertNotContains($this->tmpDir . DIRECTORY_SEPARATOR . 'storage', $checks);
    }
}
