<?php

namespace Tests\Infrastructure\Execution;

use PHPUnit\Framework\TestCase;
use FlowEngine\Domain\Execution\{
    ExecutionObserver,
    ExecutionEvent,
    ExecutionEventType
};
use FlowEngine\Infrastructure\Execution\FlowNodeInvoker;
use FlowEngine\Domain\Contracts\NodeExecutor;
use FlowEngine\Domain\Contracts\FlowRepository;
use FlowEngine\Domain\Flow\Node;

final class ExecutionObserverTest extends TestCase
{
    public function test_execution_emits_started_and_succeeded_events(): void
    {
        $collector = new class {
            /** @var ExecutionEvent[] */
            public array $events = [];
        };

        $observer = new class ($collector) implements ExecutionObserver {
            public function __construct(
                private object $collector
            ) {
            }

            public function notify(ExecutionEvent $event): void
            {
                $this->collector->events[] = $event;
            }
        };

        $executor = $this->createStub(NodeExecutor::class);
        $executor
            ->method('execute')
            ->willReturn(42);

        $invoker = new FlowNodeInvoker(
            $this->createStub(FlowRepository::class),
            $executor,
            [$observer]
        );

        $node = new Node('Calculator', 'sum', 'file.php', null);

        $invoker->invoke($node, [1, 2]);

        $this->assertCount(2, $collector->events);
        $this->assertSame(
            ExecutionEventType::STARTED,
            $collector->events[0]->type
        );
        $this->assertSame(
            ExecutionEventType::SUCCEEDED,
            $collector->events[1]->type
        );
    }
}
