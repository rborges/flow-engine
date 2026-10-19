<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use PHPUnit\Framework\TestCase;

final class AppMapNonPhpLanguagesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/flow-engine-appmap-langs-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    public function test_non_php_services_produce_http_edges_resolved_by_hostname(): void
    {
        $this->service('orders', 'python', ['py'], 'src/client.py', <<<'PY'
import requests


def sync_orders():
    # https://docs.example.com/orders
    return requests.get("https://svc-b/api/orders")
PY);
        $this->service('web', 'typescript', ['ts'], 'src/api.ts', <<<'TS'
export class Api {
  load() {
    // https://docs.example.com/web
    return fetch("https://svc-b/api/web");
  }
}
TS);
        $this->service('legacy-js', 'typescript', ['js'], 'src/client.js', <<<'JS'
function ping() {
  return fetch('https://svc-b/api/ping');
}
JS);
        $this->service('worker', 'custom', ['go'], 'src/main.go', <<<'GO'
package main

// https://pkg.go.dev/net/http
func Poll() {
	http.Get("https://svc-b/api/poll")
}
GO);
        $this->service('svc-b', 'composer', ['php'], 'src/Api.php', "<?php\nclass Api {}\n");

        $entries = [];
        foreach (['orders', 'web', 'legacy-js', 'worker', 'svc-b'] as $name) {
            $entries[] = [
                'path' => $this->base . '/' . $name,
                'name' => $name,
                'hostnames' => $name === 'svc-b' ? ['svc-b'] : [],
                'contractEndpoints' => null,
            ];
        }

        $map = (new ApplicationMapBuilder())->build((new CatalogServiceBuilder())->buildFromEntries($entries));

        $edges = [];
        foreach ($map['integrationEdges'] as $edge) {
            $edges[$edge['fromService']][] = [$edge['target'], $edge['toService'], $edge['fromNode']];
        }
        ksort($edges);

        self::assertSame([
            'legacy-js' => [['https://svc-b/api/ping', 'svc-b', 'javascript:src.client::ping']],
            'orders' => [['https://svc-b/api/orders', 'svc-b', 'python:src.client::sync_orders']],
            'web' => [['https://svc-b/api/web', 'svc-b', 'typescript:src.api.Api::load']],
            'worker' => [['https://svc-b/api/poll', 'svc-b', 'go:~path~.src@main::Poll']],
        ], $edges);
        self::assertCount(4, $map['serviceEdges']);
    }

    /**
     * @param string[] $extensions
     */
    private function service(string $name, string $type, array $extensions, string $file, string $code): void
    {
        $root = $this->base . '/' . $name;
        mkdir(dirname($root . '/' . $file), 0777, true);
        file_put_contents($root . '/' . $file, $code);
        file_put_contents($root . '/flow-engine.json', (string) json_encode([
            'version' => '1.0',
            'context' => ['type' => $type],
            'scan' => ['include' => ['src'], 'extensions' => $extensions],
        ]));
    }
}
