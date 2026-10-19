<?php

namespace Tests\Infrastructure\Diff;

use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Context\InferredReadOnlyProjectContext;
use FlowEngine\Infrastructure\Diff\StalenessChecker;
use PHPUnit\Framework\TestCase;

final class StalenessCheckerTest extends TestCase
{
    private string $tempDir;
    private string $oldStateDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/flow-engine-staleness-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        $this->oldStateDir = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $this->tempDir . '/state');
    }

    protected function tearDown(): void
    {
        putenv($this->oldStateDir === '' ? 'FLOW_ENGINE_STATE_DIR' : 'FLOW_ENGINE_STATE_DIR=' . $this->oldStateDir);
        $this->removeDir($this->tempDir);
    }

    public function test_ignores_cached_files_that_are_now_outside_current_scan_scope(): void
    {
        $project = $this->tempDir . '/project';
        $srcFile = $project . '/src/Keep.php';
        $worktreeFile = $project . '/.worktrees/feature/src/Old.php';
        $this->writeFile($srcFile, "<?php\n");
        $this->writeFile($worktreeFile, "<?php\n");

        $oldContext = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['.'],
            ignoredPaths: ['.git'],
            extensions: ['php'],
        );
        $cache = new FlowCache($oldContext);
        $cache->saveFlow(new Flow([], []), [$srcFile, $worktreeFile], $project . '/flow-engine.json');

        $currentContext = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['src'],
            ignoredPaths: ['.git', '.worktrees'],
            extensions: ['php'],
        );

        $report = (new StalenessChecker($cache, new FilesystemProjectScanner(), $currentContext))->execute();

        self::assertFalse($report->stale);
        self::assertSame([], $report->deletedFiles);
    }

    public function test_detects_same_size_content_change_with_restored_mtime(): void
    {
        $project = $this->tempDir . '/project-content';
        $source = $project . '/src/Changed.php';
        $this->writeFile($source, 'alpha');
        $mtime = filemtime($source);

        $context = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['src'],
            ignoredPaths: [],
            extensions: ['php'],
        );
        $cache = new FlowCache($context);
        $cache->saveFlow(new Flow([], []), [$source], $project . '/flow-engine.json');

        file_put_contents($source, 'bravo');
        touch($source, $mtime);
        clearstatcache(true, $source);

        $report = (new StalenessChecker($cache, new FilesystemProjectScanner(), $context))->execute();

        self::assertTrue($report->stale);
        self::assertSame([$source], $report->changedFiles);
    }

    public function test_detects_first_file_created_after_an_empty_cache(): void
    {
        $project = $this->tempDir . '/empty-project';
        mkdir($project, 0777, true);
        $context = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['.'],
            ignoredPaths: [],
            extensions: ['php'],
        );
        $cache = new FlowCache($context);
        $cache->saveFlow(new Flow([], []), [], $project . '/flow-engine.json');
        $source = $project . '/First.php';
        file_put_contents($source, "<?php\n");

        $report = (new StalenessChecker($cache, new FilesystemProjectScanner(), $context))->execute();

        self::assertTrue($report->stale);
        self::assertSame([$source], $report->newFiles);
    }

    public function test_cold_cache_does_not_claim_sources_changed_since_a_previous_cache(): void
    {
        $project = $this->tempDir . '/cold-project';
        $source = $project . '/src/App.php';
        $this->writeFile($source, "<?php\n");
        $context = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['src'],
            ignoredPaths: [],
            extensions: ['php'],
        );

        $report = (new StalenessChecker(
            new FlowCache($context),
            new FilesystemProjectScanner(),
            $context,
        ))->execute();

        self::assertFalse($report->stale);
        self::assertSame(0, $report->totalChanged);
        self::assertSame('', $report->summaryWarning());
    }

    public function test_detects_configuration_only_change(): void
    {
        $project = $this->tempDir . '/config-project';
        $config = $project . '/flow-engine.json';
        $this->writeFile($config, "{\n  \"scan\": {}\n}\n");

        $context = new InferredReadOnlyProjectContext(
            rootPath: $project,
            includePaths: ['src'],
            ignoredPaths: [],
            extensions: ['php'],
        );
        $cache = new FlowCache($context);
        $cache->saveFlow(new Flow([], []), [], $config);

        file_put_contents($config, "{\n  \"scan\": {\"include\": [\"src\"]}\n}\n");

        $report = (new StalenessChecker($cache, new FilesystemProjectScanner(), $context))->execute();

        self::assertTrue($report->stale);
        self::assertTrue($report->configChanged);
        self::assertSame(1, $report->totalChanged);
        self::assertSame([], $report->changedFiles);
        self::assertStringContainsString('flow-engine.json', $report->summaryWarning());
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
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
