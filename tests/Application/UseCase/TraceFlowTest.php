<?php

namespace Tests\Application\UseCase;

use FlowEngine\Application\DTO\FlowTraceDTO;
use FlowEngine\Application\UseCase\TraceFlow;
use FlowEngine\Domain\Contracts\FlowRepository;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

final class TraceFlowTest extends TestCase
{
    private function buildRepository(): FlowRepository
    {
        $nodes = [
            new Node('Controller', 'store', __FILE__, 1),
            new Node('Service', 'save', __FILE__, 2),
            new Node('Repository', 'persist', __FILE__, 3),
            new Node('Cache', 'put', __FILE__, 4),
        ];

        $edges = [
            new Edge('Controller::store', 'Service::save', 'save', 'method_call'),
            new Edge('Service::save', 'Repository::persist', 'persist', 'method_call'),
            new Edge('Service::save', 'Cache::put', 'put', 'property_access'),
        ];

        $flow = new Flow($nodes, $edges);

        $repository = $this->createStub(FlowRepository::class);
        $repository->method('getFlow')->willReturn($flow);

        return $repository;
    }

    /** @test */
    public function test_trace_returns_dto_with_correct_structure(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $result = $useCase->execute('Controller::store');

        $this->assertInstanceOf(FlowTraceDTO::class, $result);
        $this->assertEquals('Controller::store', $result->startNodeId);
        $this->assertEquals('downstream', $result->direction);
        $this->assertEquals(10, $result->maxDepth);
        $this->assertGreaterThan(0, $result->actualDepth);
        $this->assertGreaterThan(0, $result->nodes->count());
        $this->assertIsArray($result->edges);
        $this->assertIsArray($result->paths);
    }

    /** @test */
    public function test_trace_unknown_node_throws_exception(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Node not found: Unknown::method');

        $useCase->execute('Unknown::method');
    }

    /** @test */
    public function test_trace_with_edge_type_filter(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $result = $useCase->execute(
            'Controller::store',
            'downstream',
            10,
            ['method_call']
        );

        // Should find Repository::persist but NOT Cache::put
        $nodeIds = $result->nodes->map(fn($n) => $n->id);

        $this->assertContains('Repository::persist', $nodeIds);
        $this->assertNotContains('Cache::put', $nodeIds);
    }

    /** @test */
    public function test_trace_returns_paths(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $result = $useCase->execute('Controller::store');

        $this->assertNotEmpty($result->paths);

        // All paths should start with Controller::store
        foreach ($result->paths as $path) {
            $this->assertEquals('Controller::store', $path[0]);
        }
    }

    /** @test */
    public function test_trace_default_direction_is_downstream(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $result = $useCase->execute('Controller::store');

        $this->assertEquals('downstream', $result->direction);
    }

    /** @test */
    public function test_trace_dto_serialization(): void
    {
        $useCase = new TraceFlow($this->buildRepository());

        $result = $useCase->execute('Controller::store');
        $array = $result->toArray();

        $this->assertArrayHasKey('startNodeId', $array);
        $this->assertArrayHasKey('direction', $array);
        $this->assertArrayHasKey('maxDepth', $array);
        $this->assertArrayHasKey('actualDepth', $array);
        $this->assertArrayHasKey('nodeCount', $array);
        $this->assertArrayHasKey('edgeCount', $array);
        $this->assertArrayHasKey('nodes', $array);
        $this->assertArrayHasKey('edges', $array);
        $this->assertArrayHasKey('paths', $array);

        $json = $result->toJson();
        $this->assertJson($json);
    }
}
