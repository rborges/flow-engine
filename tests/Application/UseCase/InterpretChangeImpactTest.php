<?php

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\UseCase\InterpretChangeImpact;
use FlowEngine\Application\UseCase\AssessNodeImpact;
use FlowEngine\Application\UseCase\AnalyzeImpact;
use FlowEngine\AI\Context\ContextAssembler;
use FlowEngine\AI\LLM\LLMProvider;
use FlowEngine\AI\LLM\LLMResponse;
use FlowEngine\AI\LLM\NullLLMProvider;
use FlowEngine\AI\Prompt\PromptBuilder;
use FlowEngine\Domain\Analysis\RiskScorer;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use Tests\Support\InMemoryFlowRepository;

final class InterpretChangeImpactTest extends TestCase
{
    public function test_early_return_when_no_dependencies(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Isolated', 'run', __FILE__, 1),
        ]);

        $useCase = new InterpretChangeImpact(
            new AssessNodeImpact(
                new AnalyzeImpact($repo),
                new RiskScorer(),
                $repo
            ),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Isolated::run');

        $this->assertSame('changeImpact', $result->type);
        $this->assertStringContains('no dependencies', $result->interpretation);
        $this->assertSame(0, $result->tokensUsed);
    }

    public function test_calls_llm_when_dependencies_exist(): void
    {
        $repo = new InMemoryFlowRepository(
            [
                new Node('App\\Controller', 'index', __FILE__, 1),
                new Node('App\\Service', 'handle', __FILE__, 10),
                new Node('App\\Repository', 'find', __FILE__, 20),
            ],
            [
                new Edge('App\\Controller::index', 'App\\Service::handle', 'handle', 'method_call'),
                new Edge('App\\Service::handle', 'App\\Repository::find', 'find', 'method_call'),
            ]
        );

        $provider = $this->createStub(LLMProvider::class);
        $provider->method('send')->willReturn(new LLMResponse(
            content: 'Service::handle is a moderate-risk node with blast radius of 1.',
            tokensUsed: 350,
            metadata: ['provider' => 'mock']
        ));

        $useCase = new InterpretChangeImpact(
            new AssessNodeImpact(
                new AnalyzeImpact($repo),
                new RiskScorer(),
                $repo
            ),
            $provider,
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Service::handle');

        $this->assertSame('changeImpact', $result->type);
        $this->assertStringContains('moderate-risk', $result->interpretation);
        $this->assertSame(350, $result->tokensUsed);
        $this->assertArrayHasKey('nodeId', $result->context);
        $this->assertArrayHasKey('riskLevel', $result->context);
        $this->assertArrayHasKey('riskSummary', $result->context);
    }

    public function test_result_serializes_to_json(): void
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        $useCase = new InterpretChangeImpact(
            new AssessNodeImpact(
                new AnalyzeImpact($repo),
                new RiskScorer(),
                $repo
            ),
            new NullLLMProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $repo->getFlow()
        );

        $result = $useCase->execute('App\\Service::handle');
        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('changeImpact', $decoded['type']);
    }

    /**
     * @param string $needle
     * @param string $haystack
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
