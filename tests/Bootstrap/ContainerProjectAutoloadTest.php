<?php

namespace Tests\Bootstrap;

use FlowEngine\Bootstrap\Container;
use PHPUnit\Framework\TestCase;

final class ContainerProjectAutoloadTest extends TestCase
{
    private string $root;

    // Classe global: ReflectionInputIntrospector::resolveFqn duplica o namespace de classes namespaced.
    private string $class;

    protected function setUp(): void
    {
        $this->class = 'AutoloadProbe' . bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/flow-engine-autoload-probe-' . uniqid('', true);
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/vendor', 0777, true);

        file_put_contents($this->root . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => 'composer', 'autoload' => 'vendor/autoload.php'],
            'scan' => ['include' => ['src'], 'exclude' => ['vendor'], 'extensions' => ['php']],
        ]));
        file_put_contents($this->root . '/vendor/autoload.php', <<<PHP
<?php
define('{$this->class}_AUTOLOADED', true);
spl_autoload_register(static function (string \$class): void {
    if (\$class === '{$this->class}') {
        require __DIR__ . '/../src/Calculator.php';
    }
});
PHP);
        file_put_contents($this->root . '/src/Calculator.php', <<<PHP
<?php

final class {$this->class}
{
    public function add(int \$a, int \$b = 1): int
    {
        return \$a + \$b;
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function test_analysis_skips_autoload_but_inputs_still_load_it(): void
    {
        $container = new Container($this->root, useCache: false);

        $container->analyzeProject()->execute();

        self::assertFalse(defined($this->class . '_AUTOLOADED'), 'analysis must not run the project autoload');

        $node = null;
        foreach ($container->getFlow()->nodes() as $candidate) {
            if ($candidate->method() === 'add') {
                $node = $candidate;
            }
        }
        self::assertNotNull($node);

        $inputs = $container->getNodeInputs()->execute($node);

        self::assertTrue(defined($this->class . '_AUTOLOADED'));
        self::assertTrue(class_exists($this->class, false));
        self::assertCount(2, $inputs->inputs);
    }
}
