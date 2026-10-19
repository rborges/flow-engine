<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use PHPUnit\Framework\TestCase;

final class AppMapFromNodeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flow-engine-from-node-' . uniqid('', true);
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => 'composer'],
            'scan' => ['include' => ['src'], 'extensions' => ['php']],
        ]));
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

    public function test_procedural_and_class_level_urls_resolve_to_the_containing_node(): void
    {
        file_put_contents($this->root . '/src/sync.php', <<<'PHP'
<?php
$ch = curl_init('https://billing.example.com/sync');
curl_exec($ch);
PHP);
        file_put_contents($this->root . '/src/Client.php', <<<'PHP'
<?php
class Client
{
    public function orders()
    {
        return file_get_contents('https://api.example.com/orders');
    }

    private string $fallback = 'https://fallback.example.com/orders';
}
PHP);

        $services = (new CatalogServiceBuilder())->buildFromEntries([
            ['path' => $this->root, 'name' => 'app', 'hostnames' => [], 'contractEndpoints' => null],
        ]);
        $edges = (new ApplicationMapBuilder())->build($services)['integrationEdges'];

        $fromNodeByTarget = array_column($edges, 'fromNode', 'target');
        ksort($fromNodeByTarget);

        self::assertSame([
            'https://api.example.com/orders' => 'Client::orders',
            'https://billing.example.com/sync' => 'file:src/sync.php',
            'https://fallback.example.com/orders' => '::unknown',
        ], $fromNodeByTarget);
    }
}
