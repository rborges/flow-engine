<?php

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\UseCase\InterpretCycles;
use FlowEngine\Application\UseCase\AnalyzeCycles;
use FlowEngine\AI\Context\ContextAssembler;
use FlowEngine\AI\LLM\LLMProvider;
use FlowEngine\AI\LLM\LLMResponse;
use FlowEngine\AI\LLM\NullLLMProvider;
use FlowEngine\AI\Prompt\PromptBuilder;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use Tests\Support\InMemoryFlowRepository;

final class InterpretCyclesTest extends TestCase
{
    public function test_early_return_when_no_cycles(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        $useCase = new InterpretCycles(
            new AnalyzeCycles($repo),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute();

        $this->assertSame('cycles', $result->type);
        $this->assertSame('No dependency cycles detected.', $result->interpretation);
        $this->assertSame(0, $result->tokensUsed);
    }

    public function test_calls_llm_when_cycles_exist(): void
    {
        $repo = new InMemoryFlowRepository(
            [
                new Node('App\\A', 'call', __FILE__, 1),
                new Node('App\\B', 'call', __FILE__, 2),
            ],
            [
                new Edge('App\\A::call', 'App\\B::call', 'call', 'method_call'),
                new Edge('App\\B::call', 'App\\A::call', 'call', 'method_call'),
            ]
        );

        $provider = $this->createStub(LLMProvider::class);
        $provider->method('send')->willReturn(new LLMResponse(
            content: 'Cycle between A and B detected.',
            tokensUsed: 200,
            metadata: ['provider' => 'mock']
        ));

        $useCase = new InterpretCycles(
            new AnalyzeCycles($repo),
            $provider,
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute();

        $this->assertSame('cycles', $result->type);
        $this->assertSame('Cycle between A and B detected.', $result->interpretation);
        $this->assertSame(200, $result->tokensUsed);
        $this->assertArrayHasKey('totalCycles', $result->context);
    }

    public function test_result_serializes_to_json(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        $useCase = new InterpretCycles(
            new AnalyzeCycles($repo),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute();
        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('cycles', $decoded['type']);
    }
}
