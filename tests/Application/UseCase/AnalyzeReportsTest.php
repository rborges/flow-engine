<?php

namespace Tests\Application\UseCase;

use FlowEngine\Application\DTO\ArchitectureReportDTO;
use FlowEngine\Application\DTO\ComplexityReportDTO;
use FlowEngine\Application\DTO\CycleReportDTO;
use FlowEngine\Application\DTO\MetricsReportDTO;
use FlowEngine\Application\DTO\OrphanReportDTO;
use FlowEngine\Application\UseCase\AnalyzeArchitecture;
use FlowEngine\Application\UseCase\AnalyzeComplexity;
use FlowEngine\Application\UseCase\AnalyzeCycles;
use FlowEngine\Application\UseCase\AnalyzeMetrics;
use FlowEngine\Application\UseCase\AnalyzeOrphans;
use FlowEngine\Domain\Contracts\FlowRepository;
use FlowEngine\Domain\Flow\Flow;
use PHPUnit\Framework\TestCase;

final class AnalyzeReportsTest extends TestCase
{
    private function repositoryWithFlow(Flow $flow): FlowRepository
    {
        $repository = $this->createStub(FlowRepository::class);
        $repository->method('getFlow')->willReturn($flow);
        return $repository;
    }

    public function test_analyze_complexity_returns_dto(): void
    {
        $useCase = new AnalyzeComplexity($this->repositoryWithFlow(new Flow([], [])));
        $result = $useCase->execute();

        $this->assertInstanceOf(ComplexityReportDTO::class, $result);
    }

    public function test_analyze_cycles_returns_dto(): void
    {
        $useCase = new AnalyzeCycles($this->repositoryWithFlow(new Flow([], [])));
        $result = $useCase->execute();

        $this->assertInstanceOf(CycleReportDTO::class, $result);
    }

    public function test_analyze_architecture_returns_dto(): void
    {
        $useCase = new AnalyzeArchitecture($this->repositoryWithFlow(new Flow([], [])));
        $result = $useCase->execute();

        $this->assertInstanceOf(ArchitectureReportDTO::class, $result);
    }

    public function test_analyze_metrics_returns_dto(): void
    {
        $useCase = new AnalyzeMetrics($this->repositoryWithFlow(new Flow([], [])));
        $result = $useCase->execute();

        $this->assertInstanceOf(MetricsReportDTO::class, $result);
    }

    public function test_analyze_orphans_returns_dto(): void
    {
        $useCase = new AnalyzeOrphans($this->repositoryWithFlow(new Flow([], [])));
        $result = $useCase->execute();

        $this->assertInstanceOf(OrphanReportDTO::class, $result);
    }
}
