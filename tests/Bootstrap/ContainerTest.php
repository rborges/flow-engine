<?php

namespace Tests\Bootstrap;

use FlowEngine\Bootstrap\Container;
use FlowEngine\Application\UseCase\AnalyzeProject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FlowEngine\Bootstrap\Container
 */
final class ContainerTest extends TestCase
{
    private string $tmpDir;
    private string $originalStateDirectory;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flow-engine-container-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
        $this->originalStateDirectory = getenv('FLOW_ENGINE_STATE_DIR') ?: '';
        putenv('FLOW_ENGINE_STATE_DIR=' . $this->tmpDir . '/state');
    }

    protected function tearDown(): void
    {
        $json = $this->tmpDir . '/flow-engine.json';
        if (file_exists($json)) {
            unlink($json);
        }
        putenv($this->originalStateDirectory === ''
            ? 'FLOW_ENGINE_STATE_DIR'
            : 'FLOW_ENGINE_STATE_DIR=' . $this->originalStateDirectory);
        $this->removeDirectory($this->tmpDir);
    }

    public function test_instancia_sem_flow_engine_json(): void
    {
        $container = new Container($this->tmpDir);

        $this->assertInstanceOf(Container::class, $container);
    }

    public function test_analyze_project_disponivel_sem_config(): void
    {
        $container = new Container($this->tmpDir);

        $this->assertInstanceOf(AnalyzeProject::class, $container->analyzeProject());
    }

    public function test_project_root_retorna_path_correto(): void
    {
        $container = new Container($this->tmpDir);

        $this->assertSame($this->tmpDir, $container->projectRoot());
    }

    public function test_cache_is_current_immediately_after_analysis(): void
    {
        $project = realpath(__DIR__ . '/../fixtures/simple-project');
        self::assertNotFalse($project);
        $container = new Container($project);
        $container->analyzeProject()->execute();

        self::assertTrue($container->areFlowCacheInputsCurrent());
    }

    public function test_cache_becomes_stale_when_first_source_is_added_to_empty_project(): void
    {
        $container = new Container($this->tmpDir);
        $container->analyzeProject()->execute();
        self::assertTrue($container->areFlowCacheInputsCurrent());

        mkdir($this->tmpDir . '/src');
        file_put_contents($this->tmpDir . '/src/First.php', "<?php\nfunction first(): void {}\n");

        self::assertFalse((new Container($this->tmpDir))->areFlowCacheInputsCurrent());
    }

    public function test_cache_becomes_stale_when_framework_marker_is_created_or_removed(): void
    {
        $container = new Container($this->tmpDir);
        $container->analyzeProject()->execute();
        self::assertTrue($container->areFlowCacheInputsCurrent());

        $artisan = $this->tmpDir . '/artisan';
        file_put_contents($artisan, '#!/usr/bin/env php');
        self::assertFalse((new Container($this->tmpDir))->areFlowCacheInputsCurrent());

        $laravelContainer = new Container($this->tmpDir);
        $laravelContainer->analyzeProject()->execute();
        self::assertTrue($laravelContainer->areFlowCacheInputsCurrent());

        unlink($artisan);
        self::assertFalse((new Container($this->tmpDir))->areFlowCacheInputsCurrent());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
