<?php

namespace FlowEngine\Bootstrap;

use FlowEngine\Application\Policy\DefaultNodeVisibilityPolicy;
use FlowEngine\Application\Policy\LaravelNodeVisibilityPolicy;
use FlowEngine\Application\Policy\NodeVisibilityPolicy;
use FlowEngine\Application\Policy\ConfigurableNodeVisibilityPolicy;

use FlowEngine\Application\UseCase\AnalyzeImpact;
use FlowEngine\Application\UseCase\AnalyzeComplexity;
use FlowEngine\Application\UseCase\AnalyzeCycles;
use FlowEngine\Application\UseCase\AnalyzeArchitecture;
use FlowEngine\Application\UseCase\AnalyzeMetrics;
use FlowEngine\Application\UseCase\AnalyzeOrphans;
use FlowEngine\Application\UseCase\MapProjectStructure;
use FlowEngine\Application\UseCase\LookupNodesBySymbol;
use FlowEngine\Application\UseCase\AnalyzeProject;
use FlowEngine\Application\UseCase\ExplainNodeGovernance;
use FlowEngine\Application\UseCase\GetNodeById;
use FlowEngine\Application\UseCase\GetNodeInputs;
use FlowEngine\Application\UseCase\ExecuteNode;
use FlowEngine\Application\UseCase\GetNodes;
use FlowEngine\Application\UseCase\RunNodeGuided;
use FlowEngine\Application\UseCase\ResolveGuidedArguments;
use FlowEngine\Application\UseCase\TraceFlow;

use FlowEngine\Application\Visibility\NodeVisibilityResolver;
use FlowEngine\Application\Visibility\VisibilityExplanationBuilder;

use FlowEngine\Domain\Contracts\BugScannerPort;
use FlowEngine\Domain\Contracts\FlowSnapshotExporterPort;
use FlowEngine\Domain\Contracts\ProjectConfig;
use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Contracts\SnapshotStorePort;
use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Domain\Framework\Framework;

use FlowEngine\Infrastructure\Config\SchemaValidator;
use FlowEngine\Infrastructure\Analyzer\FilesystemProjectScanner;
use FlowEngine\Infrastructure\Analyzer\MultiLanguageParser;
use FlowEngine\Infrastructure\Analyzer\FlowBuilder;
use FlowEngine\Infrastructure\Analyzer\CrossLanguageEdgeDetector;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Cache\FlowSnapshotSerializer;
use FlowEngine\Infrastructure\Cache\PerFileCache;
use FlowEngine\Infrastructure\Cache\ReportCache;
use FlowEngine\Infrastructure\Cache\AnalysisSignature;
use FlowEngine\Infrastructure\Context\DefaultProjectContextFactory;
use FlowEngine\Infrastructure\Context\DefaultFrameworkDetector;
use FlowEngine\Infrastructure\Repository\AstFlowRepository;
use FlowEngine\Infrastructure\Execution\ReflectionInputIntrospector;
use FlowEngine\Infrastructure\Execution\NodeExecutor;
use FlowEngine\Infrastructure\Execution\FlowNodeInvoker;
use FlowEngine\Infrastructure\Execution\Observability\FileExecutionEventStore;
use FlowEngine\Infrastructure\Execution\Observability\ExecutionMonitor;
use FlowEngine\Infrastructure\Execution\Observability\ExecutionEventPersistenceObserver;
use FlowEngine\Infrastructure\Paths\StateDirectory;

use FlowEngine\Domain\Execution\ExecutionEventStore;
use FlowEngine\Domain\Analysis\AnalysisSessionStore;
use FlowEngine\Application\UseCase\ReplayExecutionEvents;
use FlowEngine\Infrastructure\Telemetry\FileAnalysisSessionStore;

use FlowEngine\AI\GuidedAssistant;
use FlowEngine\AI\NullGuidedAssistant;
use FlowEngine\AI\Context\ContextAssembler;
use FlowEngine\AI\Export\ContextExporter;
use FlowEngine\AI\Export\MarkdownFormatter;
use FlowEngine\AI\LLM\LLMProvider;
use FlowEngine\AI\LLM\NullLLMProvider;
use FlowEngine\Application\UseCase\AskQuestion;
use FlowEngine\Application\UseCase\ExportContext;
use FlowEngine\Application\UseCase\InterpretCycles;
use FlowEngine\Application\UseCase\InterpretHotspots;
use FlowEngine\Application\UseCase\InterpretImpact;
use FlowEngine\Application\UseCase\InterpretViolations;
use FlowEngine\Application\UseCase\InterpretChangeImpact;
use FlowEngine\Application\UseCase\AssessNodeImpact;
use FlowEngine\Application\UseCase\CompareSnapshots;
use FlowEngine\Application\UseCase\SaveSnapshot;
use FlowEngine\Application\UseCase\ScoreChangeRisk;
use FlowEngine\Application\UseCase\AssessRefactorSafety;
use FlowEngine\Application\UseCase\AnalyzeBugs;
use FlowEngine\Application\UseCase\GeneratePullRequest;
use FlowEngine\Application\UseCase\GenerateRemediationProposals;
use FlowEngine\Application\UseCase\SaveRemediationProposals;
use FlowEngine\Application\UseCase\ApproveRemediationProposal;
use FlowEngine\Application\UseCase\GetRemediationProposalStatus;
use FlowEngine\Application\UseCase\GenerateRefactorPlan;
use FlowEngine\Application\UseCase\PredictViolations;
use FlowEngine\Application\UseCase\AnalyzeSolid;
use FlowEngine\Application\UseCase\DetectPatterns;
use FlowEngine\Application\UseCase\GetRefactorGuidance;
use FlowEngine\Application\UseCase\RecordRefactorStepCompletion;
use FlowEngine\Application\UseCase\SaveRefactorPlan;
use FlowEngine\Application\UseCase\ValidateRefactorStep;
use FlowEngine\Infrastructure\Diff\StalenessChecker;
use FlowEngine\Application\UseCase\SimulateLargeScaleRefactor;
use FlowEngine\Infrastructure\BugDetection\PhpBugScanner;
use FlowEngine\Infrastructure\BugDetection\PythonBugScanner;
use FlowEngine\Domain\Analysis\RiskScorer;
use FlowEngine\Infrastructure\Cache\SnapshotStore;
use FlowEngine\Infrastructure\Watch\WatcherFactory;
use FlowEngine\AI\Prompt\PromptBuilder;
use FlowEngine\Infrastructure\LLM\AnthropicConfig;
use FlowEngine\Infrastructure\LLM\AnthropicProvider;
use FlowEngine\Infrastructure\LLM\OpenAIConfig;
use FlowEngine\Infrastructure\LLM\OpenAIProvider;
use FlowEngine\Infrastructure\LLM\OllamaConfig;
use FlowEngine\Infrastructure\LLM\OllamaProvider;


final class Container
{
    private ProjectConfig $config;
    private ProjectContext $context;
    private ConfigResolution $configResolution;
    private AstFlowRepository $flowRepository;
    private string $analysisContext;
    private ?FlowCache $flowCache = null;
    private ?ReportCache $reportCache = null;
    private ?SnapshotStorePort $snapshotStore = null;
    private ?FlowSnapshotExporterPort $flowSnapshotExporter = null;

    private NodeVisibilityPolicy $visibilityPolicy;
    private ?GetNodeById $getNodeById = null;
    private ?ExecutionEventStore $eventStore = null;
    private ?ExecutionMonitor $monitor = null;
    private ?AnalysisSessionStore $sessionStore = null;

    public function __construct(string $projectPath, bool $allowReadOnlyInference = false, bool $useCache = true)
    {
        /* 1. Config */
        $schemaValidator = new SchemaValidator(
            __DIR__ . '/../../schema/flow-engine.v1.json'
        );

        $bootstrap = (new ProjectBootstrapResolver(
            $schemaValidator,
            new DefaultProjectContextFactory(),
            new LanguageSupportCatalog()
        ))->resolve($projectPath, $allowReadOnlyInference);

        $this->config = $bootstrap->config;
        $this->context = $bootstrap->context;
        $this->configResolution = $bootstrap->configResolution;

        /* 3. Framework detection */
        $frameworkDetector = new DefaultFrameworkDetector();
        $framework = $frameworkDetector->detect($this->context);
        $this->analysisContext = $framework->value();

        /* 4. Visibility policy (governança) */
        $this->visibilityPolicy = new ConfigurableNodeVisibilityPolicy(
            $this->config,
            match ($framework->value()) {
                Framework::LARAVEL => [
                    new LaravelNodeVisibilityPolicy(),
                    new DefaultNodeVisibilityPolicy(),
                ],
                default => [
                    new DefaultNodeVisibilityPolicy(),
                ],
            }
        );

        /* 5. Flow infrastructure */
        $nodeFactory = new DefaultNodeFactory();
        $languageSupportCatalog = new LanguageSupportCatalog();
        $parserMaps = $languageSupportCatalog->parserMaps(
            $nodeFactory,
            $this->config->rootPath(),
            $this->config->livewireNamespace()
        );

        $parser = new MultiLanguageParser(
            $parserMaps['simple'],
            $parserMaps['compound']
        );

        $this->flowRepository = new AstFlowRepository(
            new FilesystemProjectScanner(),
            $parser,
            new FlowBuilder($this->visibilityPolicy),
            $this->context,
            $useCache ? $this->flowCache() : null,
            new CrossLanguageEdgeDetector(),
            $useCache ? new PerFileCache($this->context) : null,
            $this->analysisContext,
        );
    }

    /* ======================
       UseCases
       ====================== */

    public function analyzeProject(): AnalyzeProject
    {
        return new AnalyzeProject($this->flowRepository);
    }

    public function flowCache(): FlowCache
    {
        return $this->flowCache ??= new FlowCache($this->context);
    }

    public function checkStaleness(): StalenessChecker
    {
        return new StalenessChecker($this->flowCache(), new FilesystemProjectScanner(), $this->context);
    }

    public function reportCache(): ReportCache
    {
        return $this->reportCache ??= new ReportCache($this->context);
    }

    /** @return string[] */
    public function effectiveIgnoredPaths(): array
    {
        return FilesystemProjectScanner::effectiveIgnoredPaths($this->context->ignoredPaths());
    }

    public function cacheHash(): ?string
    {
        return $this->flowRepository->cacheHash();
    }

    public function areFlowCacheInputsCurrent(): bool
    {
        $cache = $this->flowCache();
        $files = $this->projectFiles();
        $fingerprints = $cache->captureFileFingerprints($files);
        $configPath = $this->context->rootPath() . DIRECTORY_SEPARATOR . 'flow-engine.json';

        return $cache->inputsMatch(
            $fingerprints,
            $configPath,
            AnalysisSignature::compute($this->analysisContext),
        );
    }

    /**
     * Warnings coletados durante análise (duplicatas de node IDs, etc.).
     * Vazio quando nada atípico foi detectado.
     *
     * @return string[]
     */
    public function analysisWarnings(): array
    {
        $warnings = array_merge(
            $this->flowRepository->scanWarnings(),
            $this->flowRepository->cacheWarnings(),
        );
        $duplicates = $this->flowRepository->duplicateIds();
        if ($duplicates === []) {
            return $warnings;
        }

        $sample = array_slice($duplicates, 0, 5);
        $suffix = count($duplicates) > 5
            ? sprintf(' and %d more', count($duplicates) - 5)
            : '';

        $warnings[] = sprintf(
            'Parser produced %d duplicate node IDs; deduplicated (kept last occurrence). Examples: %s%s.',
            count($duplicates),
            implode(', ', $sample),
            $suffix
        );

        return $warnings;
    }

    public function projectConfig(): ProjectConfig
    {
        return $this->config;
    }

    public function configResolution(): ConfigResolution
    {
        return $this->configResolution;
    }

    public function projectRoot(): string
    {
        return $this->config->rootPath();
    }

    public function analyzeComplexity(): AnalyzeComplexity
    {
        return new AnalyzeComplexity($this->flowRepository);
    }

    public function analyzeCycles(): AnalyzeCycles
    {
        return new AnalyzeCycles($this->flowRepository);
    }

    public function analyzeArchitecture(): AnalyzeArchitecture
    {
        return new AnalyzeArchitecture(
            $this->flowRepository,
            $this->config->architectureLayers()
        );
    }

    public function analyzeMetrics(): AnalyzeMetrics
    {
        return new AnalyzeMetrics($this->flowRepository);
    }

    public function analyzeSolid(): AnalyzeSolid
    {
        return new AnalyzeSolid($this->flowRepository);
    }

    public function detectPatterns(): DetectPatterns
    {
        return new DetectPatterns($this->flowRepository);
    }

    public function analyzeOrphans(): AnalyzeOrphans
    {
        return new AnalyzeOrphans($this->flowRepository, $this->config->entrypointPatterns());
    }

    public function analyzeBugs(): AnalyzeBugs
    {
        return new AnalyzeBugs(
            $this->flowRepository,
            ...$this->bugScanners()
        );
    }

    /**
     * @return BugScannerPort[]
     */
    public function bugScanners(): array
    {
        $allFiles  = (new FilesystemProjectScanner())->scan($this->context);
        $phpFiles  = array_values(array_filter($allFiles, fn(string $f) => str_ends_with($f, '.php')));
        $pyFiles   = array_values(array_filter($allFiles, fn(string $f) => str_ends_with($f, '.py')));

        return [
            new PhpBugScanner($phpFiles),
            new PythonBugScanner($pyFiles),
        ];
    }

    public function getNodes(): GetNodes
    {
        return new GetNodes($this->flowRepository);
    }

    public function getNodeById(): GetNodeById
    {
        return $this->getNodeById
            ??= new GetNodeById($this->flowRepository);
    }

    public function getNodeInputs(): GetNodeInputs
    {
        return new GetNodeInputs(
            new ReflectionInputIntrospector($this->context)
        );
    }

    public function executeNode(): ExecuteNode
    {
        $executor = new NodeExecutor($this->context);
        $persistenceObserver = new ExecutionEventPersistenceObserver($this->executionEventStore());

        return new ExecuteNode(
            new FlowNodeInvoker(
                $this->flowRepository,
                $executor,
                observers: [$persistenceObserver, $this->executionMonitor()]
            )
        );
    }

    public function runNodeGuided(): RunNodeGuided
    {
        return new RunNodeGuided(
            $this->getNodeById(),
            $this->getNodeInputs(),
            new ResolveGuidedArguments(),
            $this->executeNode()
        );
    }

    public function analyzeImpact(): AnalyzeImpact
    {
        return new AnalyzeImpact($this->flowRepository);
    }

    /**
     * Retorna o use case de trace de fluxos.
     *
     * @return TraceFlow
     */
    public function traceFlow(): TraceFlow
    {
        return new TraceFlow($this->flowRepository);
    }

    /**
     * Retorna o Flow analisado.
     * 
     * @return \FlowEngine\Domain\Contracts\Flow
     */
    public function getFlow(): \FlowEngine\Domain\Contracts\Flow
    {
        return $this->flowRepository->getFlow();
    }

    public function explainNodeGovernance(): ExplainNodeGovernance
    {
        return new ExplainNodeGovernance(
            $this->getNodeById(),
            new NodeVisibilityResolver($this->visibilityPolicy),
            new VisibilityExplanationBuilder()
        );
    }

    public function guidedAssistant(): GuidedAssistant
    {
        return new NullGuidedAssistant();
    }

    /* ======================
       Execution & Observability
       ====================== */

    /**
     * Retorna o event store persistido (JSON-Lines).
     *
     * @return ExecutionEventStore
     */
    public function executionEventStore(): ExecutionEventStore
    {
        $stateDir = StateDirectory::forProjectRoot($this->config->rootPath());

        return $this->eventStore ??= new FileExecutionEventStore(
            $stateDir . '/events.jsonl'
        );
    }

    /**
     * Retorna o monitor de execução em tempo real.
     *
     * @return ExecutionMonitor
     */
    public function executionMonitor(): ExecutionMonitor
    {
        return $this->monitor ??= new ExecutionMonitor();
    }

    /**
     * Retorna o use case de replay de eventos.
     *
     * @param \FlowEngine\Domain\Execution\ExecutionObserver[] $observers Observers a notificar
     *
     * @return ReplayExecutionEvents
     */
    public function replayExecutionEvents(array $observers = []): ReplayExecutionEvents
    {
        $allObservers = $observers;

        if (empty($allObservers)) {
            $allObservers = [$this->executionMonitor()];
        }

        return new ReplayExecutionEvents($this->executionEventStore(), $allObservers);
    }

    /* ======================
       Analysis Sessions
       ====================== */

    /**
     * Retorna o store de sessões de análise (JSON-Lines).
     *
     * @return AnalysisSessionStore
     */
    public function analysisSessionStore(): AnalysisSessionStore
    {
        $stateDir = StateDirectory::forProjectRoot($this->config->rootPath());

        return $this->sessionStore ??= new FileAnalysisSessionStore(
            $stateDir . '/sessions.jsonl'
        );
    }

    /* ======================
       AI & LLM
       ====================== */

    public function llmProvider(): LLMProvider
    {
        $this->applyLocalLlmSettings();

        $forced = getenv('FLOW_ENGINE_LLM_PROVIDER') ?: '';
        $forced = strtolower(trim($forced));

        $anthropic = AnthropicConfig::fromEnvironment();
        $openai = OpenAIConfig::fromEnvironment();
        $ollama = OllamaConfig::fromEnvironment();

        if ($forced !== '') {
            return match ($forced) {
                'anthropic' => $anthropic !== null
                    ? new AnthropicProvider($anthropic)
                    : new NullLLMProvider(),
                'openai' => $openai !== null
                    ? new OpenAIProvider($openai)
                    : new NullLLMProvider(),
                'ollama' => $ollama !== null
                    ? new OllamaProvider($ollama)
                    : new NullLLMProvider(),
                'null' => new NullLLMProvider(),
                default => new NullLLMProvider(),
            };
        }

        if ($anthropic !== null) {
            return new AnthropicProvider($anthropic);
        }

        if ($openai !== null) {
            return new OpenAIProvider($openai);
        }

        if ($ollama !== null) {
            return new OllamaProvider($ollama);
        }

        return new NullLLMProvider();
    }

    /**
     * Local provider settings for development testing.
     * Precedence: explicit env vars override file values.
     * Secrets must stay in environment variables.
     */
    private function applyLocalLlmSettings(): void
    {
        $settings = $this->loadLocalLlmSettings();
        if ($settings === null) {
            return;
        }

        $provider = strtolower(trim((string) ($settings['provider'] ?? '')));
        $sslMode = strtolower(trim((string) ($settings['sslMode'] ?? 'strict')));

        if ($provider !== '' && getenv('FLOW_ENGINE_LLM_PROVIDER') === false) {
            putenv("FLOW_ENGINE_LLM_PROVIDER={$provider}");
        }

        if ($sslMode === 'insecure_dev' && getenv('FLOW_ENGINE_LLM_NO_SSL_VERIFY') === false) {
            putenv('FLOW_ENGINE_LLM_NO_SSL_VERIFY=1');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLocalLlmSettings(): ?array
    {
        $file = getcwd() . DIRECTORY_SEPARATOR . '.flow-engine-llm';
        if (!is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    public function contextExporter(): ContextExporter
    {
        return new ContextExporter(new MarkdownFormatter());
    }

    /**
     * @param array<string, mixed>|null $appmapData Pre-built appmap from ApplicationMapBuilder::build()
     */
    public function exportContext(?array $appmapData = null): ExportContext
    {
        return new ExportContext(
            $this->analyzeMetrics(),
            $this->analyzeComplexity(),
            $this->analyzeCycles(),
            $this->analyzeArchitecture(),
            $this->analyzeOrphans(),
            $this->contextExporter(),
            $appmapData,
            $this->config->rootPath(),
            $this->getFlow()
        );
    }

    public function askQuestion(): AskQuestion
    {
        return new AskQuestion(
            $this->llmProvider(),
            $this->exportContext()
        );
    }

    public function interpretCycles(): InterpretCycles
    {
        return new InterpretCycles(
            $this->analyzeCycles(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    public function interpretHotspots(): InterpretHotspots
    {
        return new InterpretHotspots(
            $this->analyzeComplexity(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    public function interpretImpact(): InterpretImpact
    {
        return new InterpretImpact(
            $this->analyzeImpact(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    public function interpretViolations(): InterpretViolations
    {
        return new InterpretViolations(
            $this->analyzeArchitecture(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    /* ======================
       Change Impact (v2.2)
       ====================== */

    public function snapshotStore(): SnapshotStorePort
    {
        return $this->snapshotStore ??= new SnapshotStore($this->context, $this->config->snapshotRetention());
    }

    public function flowSnapshotExporter(): FlowSnapshotExporterPort
    {
        return $this->flowSnapshotExporter ??= new FlowSnapshotSerializer();
    }

    /**
     * @return string[]
     */
    public function projectFiles(): array
    {
        return (new FilesystemProjectScanner())->scan($this->context);
    }

    /**
     * @return string[]
     */
    public function configuredScanExtensions(): array
    {
        return $this->context->extensions();
    }

    public function watcherFactory(): WatcherFactory
    {
        return WatcherFactory::createDefault();
    }

    public function assessNodeImpact(): AssessNodeImpact
    {
        return new AssessNodeImpact(
            $this->analyzeImpact(),
            new RiskScorer(),
            $this->flowRepository
        );
    }

    public function scoreChangeRisk(): ScoreChangeRisk
    {
        return new ScoreChangeRisk(
            new RiskScorer(),
            $this->flowRepository
        );
    }

    public function assessRefactorSafety(): AssessRefactorSafety
    {
        return new AssessRefactorSafety(
            $this->analyzeImpact(),
            $this->flowRepository
        );
    }

    public function saveSnapshot(): SaveSnapshot
    {
        return new SaveSnapshot(
            $this->analyzeMetrics(),
            $this->analyzeComplexity(),
            $this->analyzeCycles(),
            $this->analyzeArchitecture(),
            $this->analyzeOrphans(),
            $this->snapshotStore()
        );
    }

    public function compareSnapshots(): CompareSnapshots
    {
        return new CompareSnapshots(
            $this->analyzeMetrics(),
            $this->analyzeComplexity(),
            $this->analyzeCycles(),
            $this->analyzeArchitecture(),
            $this->analyzeOrphans(),
            $this->snapshotStore()
        );
    }

    public function interpretChangeImpact(): InterpretChangeImpact
    {
        return new InterpretChangeImpact(
            $this->assessNodeImpact(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    /* ======================
       Refactor Planning (v3.0)
       ====================== */

    public function generateRefactorPlan(): GenerateRefactorPlan
    {
        return new GenerateRefactorPlan(
            $this->assessNodeImpact(),
            $this->assessRefactorSafety(),
            $this->scoreChangeRisk(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->getFlow()
        );
    }

    public function saveRefactorPlan(): SaveRefactorPlan
    {
        return new SaveRefactorPlan($this->snapshotStore());
    }

    public function generatePullRequest(): GeneratePullRequest
    {
        return new GeneratePullRequest(
            $this->snapshotStore(),
            $this->llmProvider(),
            new \FlowEngine\AI\Prompt\PromptBuilder(),
        );
    }

    public function generateRemediationProposals(): GenerateRemediationProposals
    {
        return new GenerateRemediationProposals(
            $this->analyzeArchitecture(),
            $this->analyzeMetrics()
        );
    }

    public function saveRemediationProposals(): SaveRemediationProposals
    {
        return new SaveRemediationProposals($this->snapshotStore());
    }

    public function approveRemediationProposal(): ApproveRemediationProposal
    {
        return new ApproveRemediationProposal($this->snapshotStore());
    }

    public function getRemediationProposalStatus(): GetRemediationProposalStatus
    {
        return new GetRemediationProposalStatus($this->snapshotStore());
    }

    public function predictViolations(): PredictViolations
    {
        return new PredictViolations(
            $this->analyzeArchitecture(),
            $this->snapshotStore(),
        );
    }

    /* ======================
       Refactor Guidance (v3.2)
       ====================== */

    public function getRefactorGuidance(): GetRefactorGuidance
    {
        return new GetRefactorGuidance(
            $this->assessNodeImpact(),
            $this->llmProvider(),
            new ContextAssembler(),
            new PromptBuilder(),
            $this->snapshotStore()
        );
    }

    public function validateRefactorStep(): ValidateRefactorStep
    {
        return new ValidateRefactorStep(
            $this->assessNodeImpact(),
            $this->snapshotStore()
        );
    }

    public function recordRefactorStepCompletion(): RecordRefactorStepCompletion
    {
        return new RecordRefactorStepCompletion($this->snapshotStore());
    }

    /* ======================
       Large-Scale Simulation (v3.5)
       ====================== */

    public function simulateLargeScaleRefactor(): SimulateLargeScaleRefactor
    {
        return new SimulateLargeScaleRefactor(
            $this->flowRepository,
            new RiskScorer(),
        );
    }

    public function mapProjectStructure(): MapProjectStructure
    {
        return new MapProjectStructure($this->flowRepository, $this->config->rootPath());
    }

    public function lookupNodesBySymbol(): LookupNodesBySymbol
    {
        return new LookupNodesBySymbol($this->flowRepository);
    }

    public function buildGuidedInputContext(string $nodeId): \FlowEngine\AI\DTO\GuidedInputContext
    {
        $node = $this->getNodeById()->execute($nodeId);

        if (!$node) {
            throw new \LogicException("Node not found: {$nodeId}");
        }

        $assembler = new ContextAssembler();

        return new \FlowEngine\AI\DTO\GuidedInputContext(
            node: $assembler->node(
                $node->id(),
                $node->class(),
                $node->method(),
                $node->visibility()->value()
            ),
            inputs: [],      // inputs vêm do UseCase GetNodeInputs
            visibility: [],  // explicado via ExplainNodeGovernance
            impact: []       // já resolvido pelo AnalyzeImpact
        );
    }

}
