<?php

use PHPUnit\Framework\TestCase;
use FlowEngine\Application\UseCase\AskQuestion;
use FlowEngine\Application\UseCase\ExportContext;
use FlowEngine\Application\UseCase\AnalyzeMetrics;
use FlowEngine\Application\UseCase\AnalyzeComplexity;
use FlowEngine\Application\UseCase\AnalyzeCycles;
use FlowEngine\Application\UseCase\AnalyzeArchitecture;
use FlowEngine\Application\UseCase\AnalyzeOrphans;
use FlowEngine\AI\LLM\NullLLMProvider;
use FlowEngine\AI\LLM\LLMProvider;
use FlowEngine\AI\LLM\LLMRequest;
use FlowEngine\AI\LLM\LLMResponse;
use FlowEngine\AI\Export\ContextExporter;
use FlowEngine\AI\Export\MarkdownFormatter;
use FlowEngine\AI\Export\ExportOptions;
use Tests\Support\InMemoryFlowRepository;
use FlowEngine\Domain\Flow\Node;

final class AskQuestionTest extends TestCase
{
    public function test_ask_with_null_provider(): void
    {
        $useCase = new AskQuestion(
            new NullLLMProvider(),
            $this->buildExportContext()
        );

        $result = $useCase->execute('What are the hotspots?');

        $this->assertSame('What are the hotspots?', $result->question);
        $this->assertStringContainsString('not configured', $result->answer);
        $this->assertSame(0, $result->tokensUsed);
        $this->assertFalse($result->metadata['providerConfigured']);
    }

    public function test_ask_with_mock_provider(): void
    {
        $provider = $this->createStub(LLMProvider::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('send')->willReturn(new LLMResponse(
            content: 'The main hotspot is UserService.',
            tokensUsed: 150,
            metadata: ['provider' => 'anthropic']
        ));

        $useCase = new AskQuestion(
            $provider,
            $this->buildExportContext()
        );

        $result = $useCase->execute('What are the hotspots?');

        $this->assertSame('What are the hotspots?', $result->question);
        $this->assertSame('The main hotspot is UserService.', $result->answer);
        $this->assertSame(150, $result->tokensUsed);
        $this->assertTrue($result->metadata['providerConfigured']);
        $this->assertContains('metrics', $result->metadata['includedSections']);
    }

    public function test_response_to_array_and_json(): void
    {
        $useCase = new AskQuestion(
            new NullLLMProvider(),
            $this->buildExportContext()
        );

        $result = $useCase->execute('test');

        $array = $result->toArray();
        $this->assertArrayHasKey('question', $array);
        $this->assertArrayHasKey('answer', $array);
        $this->assertArrayHasKey('tokensUsed', $array);
        $this->assertArrayHasKey('metadata', $array);

        $json = $result->toJson();
        $this->assertJson($json);
    }

    public function test_ask_with_minimal_options(): void
    {
        $useCase = new AskQuestion(
            new NullLLMProvider(),
            $this->buildExportContext()
        );

        $result = $useCase->execute('test', ExportOptions::minimal());

        $this->assertContains('metrics', $result->metadata['includedSections']);
        $this->assertNotContains('complexity', $result->metadata['includedSections']);
    }

    private function buildExportContext(): ExportContext
    {
        $repo = new InMemoryFlowRepository([
            new Node('App\\Service', 'handle', __FILE__, 1),
        ]);

        return new ExportContext(
            new AnalyzeMetrics($repo),
            new AnalyzeComplexity($repo),
            new AnalyzeCycles($repo),
            new AnalyzeArchitecture($repo),
            new AnalyzeOrphans($repo),
            new ContextExporter(new MarkdownFormatter())
        );
    }
}
