<?php

namespace Tests\Infrastructure\Execution;

use FlowEngine\Domain\Contracts\NodeExecutor;
use FlowEngine\Domain\Execution\ExecutionDeniedException;
use PHPUnit\Framework\TestCase;
use FlowEngine\Infrastructure\Execution\FlowNodeInvoker;
use FlowEngine\Infrastructure\Execution\Policy\NodeBlacklistPolicy;
use FlowEngine\Infrastructure\Execution\Policy\RateLimitExecutionPolicy;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Contracts\FlowRepository;
use RuntimeException;

final class FlowNodeInvokerTest extends TestCase
{
    public function test_it_returns_execution_result_on_success(): void
    {
        $node = new Node('Calculator', 'sum', 'file.php', null);

        $executor = $this->createStub(NodeExecutor::class);
        $executor
            ->method('execute')
            ->willReturn(3);

        $repository = $this->createStub(FlowRepository::class);

        $invoker = new FlowNodeInvoker($repository, $executor);

        $result = $invoker->invoke($node, [1, 2]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3, $result->output);
        $this->assertSame('Calculator::sum', $result->nodeId);
        $this->assertGreaterThan(0, $result->durationMs);
    }

    public function test_it_captures_exception_on_failure(): void
    {
        $node = new Node('Calculator', 'explode', 'file.php', null);

        $executor = $this->createStub(NodeExecutor::class);
        $executor
            ->method('execute')
            ->willThrowException(new RuntimeException('Boom'));

        $repository = $this->createStub(FlowRepository::class);

        $invoker = new FlowNodeInvoker($repository, $executor);

        $result = $invoker->invoke($node, []);

        $this->assertFalse($result->isSuccess());
        $this->assertInstanceOf(RuntimeException::class, $result->exception);
    }

    public function test_it_denies_execution_when_policy_rejects(): void
    {
        $node = new Node('Dangerous', 'delete', 'file.php', null);

        $executor = $this->createMock(NodeExecutor::class);
        $executor->expects($this->never())->method('execute');

        $repository = $this->createStub(FlowRepository::class);

        $invoker = new FlowNodeInvoker(
            $repository,
            $executor,
            observers: [],
            policies: [new NodeBlacklistPolicy(['Dangerous::delete'])]
        );

        $result = $invoker->invoke($node, []);

        $this->assertTrue($result->isFailure());
        $this->assertInstanceOf(ExecutionDeniedException::class, $result->exception);
    }

    public function test_it_allows_execution_when_all_policies_pass(): void
    {
        $node = new Node('Safe', 'process', 'file.php', null);

        $executor = $this->createStub(NodeExecutor::class);
        $executor->method('execute')->willReturn('ok');

        $repository = $this->createStub(FlowRepository::class);

        $invoker = new FlowNodeInvoker(
            $repository,
            $executor,
            observers: [],
            policies: [
                new NodeBlacklistPolicy(['Other::method']),
                new RateLimitExecutionPolicy(maxExecutions: 10, windowSeconds: 60.0),
            ]
        );

        $result = $invoker->invoke($node, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('ok', $result->output);
    }
}
