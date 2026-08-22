<?php

namespace Tests\Application\UseCase;

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\UseCase\ReplayExecutionEvents;
use FlowEngine\Infrastructure\Execution\Observability\InMemoryExecutionEventStore;
use FlowEngine\Domain\Execution\ExecutionEvent;
use FlowEngine\Domain\Execution\ExecutionContext;
use FlowEngine\Domain\Execution\ExecutionObserver;

/**
 * @covers \FlowEngine\Application\UseCase\ReplayExecutionEvents
 */
final class ReplayExecutionEventsTest extends TestCase
{
    public function test_replay_all_notifies_observers_for_each_event(): void
    {
        $store = new InMemoryExecutionEventStore();
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'A::x', inputs: [], output: null, durationMs: 1.0,
            context: ExecutionContext::system('test')
        ));
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'B::y', inputs: [], output: null, durationMs: 2.0,
            context: ExecutionContext::system('test')
        ));

        $observer = $this->createMock(ExecutionObserver::class);
        $observer->expects($this->exactly(2))->method('notify');

        $replay = new ReplayExecutionEvents($store, [$observer]);

        $replay->replayAll();
    }

    public function test_replay_all_preserves_event_order(): void
    {
        $store = new InMemoryExecutionEventStore();
        $store->append(ExecutionEvent::started(
            nodeId: 'A::x', inputs: [],
            context: ExecutionContext::system('test')
        ));
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'A::x', inputs: [], output: null, durationMs: 1.0,
            context: ExecutionContext::system('test')
        ));

        $receivedNodeIds = [];
        $observer = $this->createStub(ExecutionObserver::class);
        $observer->method('notify')
            ->willReturnCallback(function (ExecutionEvent $event) use (&$receivedNodeIds) {
                $receivedNodeIds[] = $event->type->value;
            });

        $replay = new ReplayExecutionEvents($store, [$observer]);
        $replay->replayAll();

        $this->assertSame(['execution.started', 'execution.succeeded'], $receivedNodeIds);
    }

    public function test_replay_for_node_only_replays_matching_events(): void
    {
        $store = new InMemoryExecutionEventStore();
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'A::x', inputs: [], output: null, durationMs: 1.0,
            context: ExecutionContext::system('test')
        ));
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'B::y', inputs: [], output: null, durationMs: 2.0,
            context: ExecutionContext::system('test')
        ));
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'A::x', inputs: [], output: null, durationMs: 3.0,
            context: ExecutionContext::system('test')
        ));

        $observer = $this->createMock(ExecutionObserver::class);
        $observer->expects($this->exactly(2))->method('notify');

        $replay = new ReplayExecutionEvents($store, [$observer]);
        $count = $replay->replayForNode('A::x');

        $this->assertSame(2, $count);
    }

    public function test_replay_since_only_replays_recent_events(): void
    {
        $store = new InMemoryExecutionEventStore();
        $store->append(ExecutionEvent::succeeded(
            nodeId: 'A::x', inputs: [], output: null, durationMs: 1.0,
            context: ExecutionContext::system('test')
        ));

        // Wall-clock separation on both sides of the cutoff: microtime() may not
        // advance between adjacent calls, so space A strictly before the cutoff and
        // B strictly after it to keep the inclusive "since" boundary deterministic.
        usleep(1000);
        $cutoff = microtime(true);
        usleep(1000);

        $store->append(ExecutionEvent::succeeded(
            nodeId: 'B::y', inputs: [], output: null, durationMs: 2.0,
            context: ExecutionContext::system('test')
        ));

        $observer = $this->createMock(ExecutionObserver::class);
        $observer->expects($this->once())->method('notify');

        $replay = new ReplayExecutionEvents($store, [$observer]);
        $count = $replay->replaySince($cutoff);

        $this->assertSame(1, $count);
    }

    public function test_replay_returns_event_count(): void
    {
        $store = new InMemoryExecutionEventStore();
        for ($i = 0; $i < 5; $i++) {
            $store->append(ExecutionEvent::succeeded(
                nodeId: "N::{$i}", inputs: [], output: null, durationMs: 1.0,
                context: ExecutionContext::system('test')
            ));
        }

        $observer = $this->createStub(ExecutionObserver::class);
        $replay = new ReplayExecutionEvents($store, [$observer]);

        $this->assertSame(5, $replay->replayAll());
    }

    public function test_replay_empty_store_returns_zero(): void
    {
        $store = new InMemoryExecutionEventStore();
        $observer = $this->createMock(ExecutionObserver::class);
        $observer->expects($this->never())->method('notify');

        $replay = new ReplayExecutionEvents($store, [$observer]);

        $this->assertSame(0, $replay->replayAll());
    }
}
