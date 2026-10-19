<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\UrlClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function urls(): array
    {
        return [
            'image' => ['example.com', '/img/logo.PNG', 'asset'],
            'font' => ['example.com', '/fonts/a.woff2', 'asset'],
            'stylesheet' => ['example.com', '/app.css', 'asset'],
            'source map' => ['example.com', '/app.js.map', 'asset'],
            'pdf' => ['example.com', '/manual.pdf', 'asset'],
            'css url() punctuation' => ['example.com', '/bg.png)', 'asset'],
            'cdn without extension' => ['cdn.jsdelivr.net', '/npm/vue@3', 'asset'],
            'google fonts' => ['fonts.googleapis.com', '/css2', 'asset'],
            'api' => ['api.example.com', '/orders', 'http'],
            'api with json' => ['api.example.com', '/orders.json', 'http'],
            'no path' => ['api.example.com', '', 'http'],
        ];
    }

    #[DataProvider('urls')]
    public function test_classifies_url(string $host, string $path, string $kind): void
    {
        self::assertSame($kind, (new UrlClassifier())->kind($host, $path));
    }
}
