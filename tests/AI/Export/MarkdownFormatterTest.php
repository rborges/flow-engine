<?php

use PHPUnit\Framework\TestCase;
use FlowEngine\AI\Export\MarkdownFormatter;
use FlowEngine\Application\DTO\MetricsReportDTO;
use FlowEngine\Application\DTO\ComplexityReportDTO;
use FlowEngine\Application\DTO\CycleReportDTO;
use FlowEngine\Application\DTO\ArchitectureReportDTO;
use FlowEngine\Application\DTO\OrphanReportDTO;

final class MarkdownFormatterTest extends TestCase
{
    private MarkdownFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MarkdownFormatter();
    }

    public function test_format_metrics(): void
    {
        $report = new MetricsReportDTO(
            totalNodes: 42,
            totalEdges: 85,
            avgFanIn: 2.5,
            avgFanOut: 3.1,
            maxFanIn: 10,
            maxFanOut: 8,
            hotspotCount: 3,
            hotspots: [
                ['nodeId' => 'UserService::handle', 'fanIn' => 10, 'fanOut' => 8],
            ],
            topCoupled: [
                ['nodeId' => 'OrderService::process', 'coupling' => 15],
            ]
        );

        $md = $this->formatter->formatMetrics($report);

        $this->assertStringContainsString('## Metrics', $md);
        $this->assertStringContainsString('42', $md);
        $this->assertStringContainsString('85', $md);
        $this->assertStringContainsString('UserService::handle', $md);
        $this->assertStringContainsString('OrderService::process', $md);
    }

    public function test_format_complexity(): void
    {
        $report = new ComplexityReportDTO(
            totalMethods: 100,
            avgComplexity: 3.5,
            maxComplexity: 25,
            minComplexity: 1,
            byLevel: ['low' => 80, 'medium' => 15, 'high' => 5],
            complexMethods: [
                ['nodeId' => 'Parser::parse', 'complexity' => 25, 'level' => 'high'],
            ]
        );

        $md = $this->formatter->formatComplexity($report);

        $this->assertStringContainsString('## Complexity', $md);
        $this->assertStringContainsString('100', $md);
        $this->assertStringContainsString('Parser::parse', $md);
        $this->assertStringContainsString('high', $md);
    }

    public function test_format_cycles(): void
    {
        $report = new CycleReportDTO(
            totalCycles: 2,
            totalNodesInCycles: 5,
            bySeverity: ['critical' => 1, 'warning' => 1],
            largestCycle: 3,
            cycles: [
                ['nodes' => ['A::x', 'B::y', 'A::x']],
            ]
        );

        $md = $this->formatter->formatCycles($report);

        $this->assertStringContainsString('## Dependency Cycles', $md);
        $this->assertStringContainsString('A::x', $md);
        $this->assertStringContainsString('B::y', $md);
    }

    public function test_format_architecture(): void
    {
        $report = new ArchitectureReportDTO(
            isClean: false,
            totalViolations: 3,
            bySeverity: ['error' => 2, 'warning' => 1],
            byType: ['layer_skip' => 3],
            layerDistribution: ['Domain' => 30, 'Application' => 20, 'Infrastructure' => 15],
            violations: [
                ['from' => 'Domain::A', 'to' => 'Infra::B', 'type' => 'layer_skip'],
            ]
        );

        $md = $this->formatter->formatArchitecture($report);

        $this->assertStringContainsString('## Architecture', $md);
        $this->assertStringContainsString('Violations found', $md);
        $this->assertStringContainsString('Domain::A', $md);
        $this->assertStringContainsString('Domain', $md);
    }

    public function test_format_orphans(): void
    {
        $report = new OrphanReportDTO(
            totalOrphans: 5,
            highConfidenceOrphans: 3,
            suspiciousLeafNodes: 2,
            percentageOrphans: 12.5,
            orphans: [
                ['nodeId' => 'OldService::legacy', 'confidence' => 'high'],
            ],
            suspiciousLeaves: [
                ['nodeId' => 'Helper::unused'],
            ]
        );

        $md = $this->formatter->formatOrphans($report);

        $this->assertStringContainsString('## Orphan Code', $md);
        $this->assertStringContainsString('OldService::legacy', $md);
        $this->assertStringContainsString('Helper::unused', $md);
        $this->assertStringContainsString('12.5%', $md);
    }

    public function test_format_metrics_empty_hotspots(): void
    {
        $report = new MetricsReportDTO(
            totalNodes: 10,
            totalEdges: 5,
            avgFanIn: 1.0,
            avgFanOut: 1.0,
            maxFanIn: 2,
            maxFanOut: 2,
            hotspotCount: 0,
            hotspots: [],
            topCoupled: []
        );

        $md = $this->formatter->formatMetrics($report);

        $this->assertStringContainsString('## Metrics', $md);
        $this->assertStringNotContainsString('### Hotspots', $md);
    }

    public function test_service_map_marks_failed_service(): void
    {
        $md = $this->formatter->formatServiceMap(['services' => [
            ['name' => 'billing', 'status' => 'failed', 'error' => 'LogicException: bad | config', 'stats' => ['nodeCount' => 0, 'edgeCount' => 0]],
        ]]);

        self::assertStringContainsString('| billing | analysis failed: LogicException: bad \| config | - | - |', $md);
    }
}
