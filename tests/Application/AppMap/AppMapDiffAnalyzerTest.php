<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\AppMapDiffAnalyzer;
use PHPUnit\Framework\TestCase;

final class AppMapDiffAnalyzerTest extends TestCase
{
    public function test_it_computes_drift_summary(): void
    {
        $before = [
            'appmap' => [
                'services' => [
                    ['name' => 'a'],
                    ['name' => 'b'],
                ],
                'serviceEdges' => [
                    ['from' => 'a', 'to' => 'b', 'type' => 'script', 'count' => 1],
                ],
                'inconsistencies' => [
                    ['type' => 'SCRIPT_NOT_FOUND', 'fromService' => 'a', 'target' => 'x.py', 'message' => 'missing'],
                ],
            ],
        ];

        $after = [
            'appmap' => [
                'services' => [
                    ['name' => 'a'],
                    ['name' => 'b'],
                    ['name' => 'c'],
                ],
                'serviceEdges' => [
                    ['from' => 'a', 'to' => 'b', 'type' => 'script', 'count' => 2],
                    ['from' => 'b', 'to' => 'c', 'type' => 'http', 'count' => 1],
                ],
                'inconsistencies' => [],
            ],
        ];

        $diff = (new AppMapDiffAnalyzer())->diff($before, $after);

        self::assertSame(1, $diff['summary']['servicesAdded']);
        self::assertSame(0, $diff['summary']['servicesRemoved']);
        self::assertSame(2, $diff['summary']['serviceEdgesAdded']);
        self::assertSame(1, $diff['summary']['serviceEdgesRemoved']);
        self::assertSame(0, $diff['summary']['inconsistenciesAdded']);
        self::assertSame(1, $diff['summary']['inconsistenciesResolved']);
        self::assertSame(1, $diff['summary']['breakingChanges']);
        self::assertCount(0, $diff['breakingChanges']['servicesRemoved']);
        self::assertCount(1, $diff['breakingChanges']['serviceEdgesRemoved']);
    }

    public function test_it_lists_services_that_failed_analysis(): void
    {
        $appmap = ['appmap' => ['services' => [
            ['name' => 'a', 'status' => 'ok'],
            ['name' => 'b', 'status' => 'failed', 'error' => 'boom'],
            ['name' => 'legacy'],
        ]]];

        self::assertSame(['b'], (new AppMapDiffAnalyzer())->failedServices($appmap));
    }
}
