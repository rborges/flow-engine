<?php

namespace Tests\Infrastructure\Context;

use PHPUnit\Framework\TestCase;
use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Framework\Framework;
use FlowEngine\Infrastructure\Context\DefaultFrameworkDetector;

final class DefaultFrameworkDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/flow-engine-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_detects_laravel_by_artisan_file(): void
    {
        file_put_contents($this->tempDir . '/artisan', '<?php // artisan');

        $context = $this->createContextForPath($this->tempDir);
        $detector = new DefaultFrameworkDetector();

        $framework = $detector->detect($context);

        $this->assertTrue($framework->is(Framework::laravel()));
    }

    public function test_detects_wordpress_by_wp_config(): void
    {
        file_put_contents($this->tempDir . '/wp-config.php', '<?php // wp');

        $context = $this->createContextForPath($this->tempDir);
        $detector = new DefaultFrameworkDetector();

        $framework = $detector->detect($context);

        $this->assertTrue($framework->is(Framework::wordpress()));
    }

    public function test_detects_composer_by_composer_json(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{}');

        $context = $this->createContextForPath($this->tempDir);
        $detector = new DefaultFrameworkDetector();

        $framework = $detector->detect($context);

        $this->assertTrue($framework->is(Framework::composer()));
    }

    public function test_falls_back_to_custom(): void
    {
        $context = $this->createContextForPath($this->tempDir);
        $detector = new DefaultFrameworkDetector();

        $framework = $detector->detect($context);

        $this->assertTrue($framework->is(Framework::custom()));
    }

    public function test_laravel_takes_priority_over_composer(): void
    {
        file_put_contents($this->tempDir . '/artisan', '<?php // artisan');
        file_put_contents($this->tempDir . '/composer.json', '{}');

        $context = $this->createContextForPath($this->tempDir);
        $detector = new DefaultFrameworkDetector();

        $framework = $detector->detect($context);

        $this->assertTrue($framework->is(Framework::laravel()));
    }

    private function createContextForPath(string $path): ProjectContext
    {
        $context = $this->createStub(ProjectContext::class);
        $context->method('rootPath')->willReturn($path);

        return $context;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
