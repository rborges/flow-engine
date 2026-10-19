<?php

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\UseCase\InterpretImpact;
use FlowEngine\Application\UseCase\AnalyzeImpact;
use FlowEngine\AI\Context\ContextAssembler;
use FlowEngine\AI\LLM\LLMProvider;
use FlowEngine\AI\LLM\LLMResponse;
use FlowEngine\AI\LLM\NullLLMProvider;
use FlowEngine\AI\Prompt\PromptBuilder;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use Tests\Support\InMemoryFlowRepository;

final class InterpretImpactTest extends TestCase
{
    public function test_early_return_when_no_dependencies(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        $useCase = new InterpretImpact(
            new AnalyzeImpact($repo),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Service::handle');

        $this->assertSame('impact', $result->type);
        $this->assertStringContainsString('no upstream or downstream', $result->interpretation);
        $this->assertSame(0, $result->tokensUsed);
    }

    public function test_calls_llm_when_dependencies_exist(): void
    {
        $repo = new InMemoryFlowRepository(
            [
                new Node('App\\Controller', 'index', __FILE__, 1),
                new Node('App\\Service', 'handle', __FILE__, 2),
                new Node('App\\Repository', 'find', __FILE__, 3),
            ],
            [
                new Edge('App\\Controller::index', 'App\\Service::handle', 'handle', 'method_call'),
                new Edge('App\\Service::handle', 'App\\Repository::find', 'find', 'method_call'),
            ]
        );

        $provider = $this->createStub(LLMProvider::class);
        $provider->method('send')->willReturn(new LLMResponse(
            content: 'Service::handle is a central node.',
            tokensUsed: 150,
            metadata: ['provider' => 'mock']
        ));

        $useCase = new InterpretImpact(
            new AnalyzeImpact($repo),
            $provider,
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Service::handle');

        $this->assertSame('impact', $result->type);
        $this->assertSame('Service::handle is a central node.', $result->interpretation);
        $this->assertSame(150, $result->tokensUsed);
        $this->assertArrayHasKey('nodeId', $result->context);
        $this->assertArrayHasKey('upstream', $result->context);
        $this->assertArrayHasKey('downstream', $result->context);
    }

    public function test_result_serializes_to_json(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        $useCase = new InterpretImpact(
            new AnalyzeImpact($repo),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Service::handle');
        $json = $result->toJson();

        $this->assertJson($json);
    }
}
