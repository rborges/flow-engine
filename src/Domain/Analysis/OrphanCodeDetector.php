<?php

namespace FlowEngine\Domain\Analysis;

use FlowEngine\Domain\Contracts\Flow;
use FlowEngine\Domain\Flow\Node;

/**
 * Detecta código órfão (potencialmente não utilizado).
 *
 * Identifica:
 * - Métodos nunca chamados (exceto entry points legítimos)
 * - Leaf nodes que não são utilities
 * - Classes com baixo uso
 */
final class OrphanCodeDetector
{
    /** @var string[] Padroes de entry points legitimos */
    private const LEGITIMATE_ENTRY_PATTERNS = [
        'Controller',
        'Command',
        'DTO',
        'Handler',
        'Listener',
        'Job',
        'Migration',
        'Seeder',
        'Test',
        '__construct',
        '__invoke',
    ];

    /** @var string[] Metodos chamados por frameworks (Laravel/Livewire) que o parser estatico nao ve */
    private const FRAMEWORK_ENTRY_METHODS = [
        // Livewire lifecycle
        'render', 'mount', 'hydrate', 'dehydrate', 'updating', 'updated',
        // Laravel ServiceProvider
        'register', 'boot',
        // Laravel Kernel
        'schedule', 'commands',
        // Laravel Middleware
        'handle', 'terminate',
        // Laravel FormRequest
        'authorize', 'rules', 'messages', 'attributes', 'prepareForValidation',
        // Laravel Model events
        'booted', 'creating', 'created', 'deleting', 'deleted',
        // Laravel Model
        'casts',
        // Laravel Auth
        'redirectTo',
        // Laravel Route model binding
        'resolveRouteBinding', 'resolveChildRouteBinding',
    ];

    /** @var string[] Padrões de utilities legítimos */
    private const LEGITIMATE_UTILITY_PATTERNS = [
        'Helper',
        'Util',
        'Support',
        'toArray',
        'toJson',
        'toString',
        '__toString',
        'jsonSerialize',
    ];

    /** @var string[] Node ID substrings that indicate a stable public-API surface */
    private const PUBLIC_API_PATTERNS = [
        'DTO::',
        'Query::',
        'Repository::',
    ];

    /** @var string[] PHP 8 attribute names that mark a class/method as an entrypoint */
    private const FRAMEWORK_ATTRIBUTES = [
        // Symfony / Laravel routing
        'Route', 'Get', 'Post', 'Put', 'Delete', 'Patch', 'Head', 'Options', 'Any',
        // Symfony / Laravel class markers
        'Controller', 'AsCommand', 'AsController',
        // Event system
        'EventListener', 'AsEventListener',
        // Console / scheduler
        'Scheduled', 'Schedule',
        // Livewire / Volt
        'Component', 'IsPage',
        // API Platform
        'ApiResource',
        // Livewire v3 method-level attributes
        'On', 'Computed', 'Locked', 'Url',
    ];

    /** @var string[] Eloquent relationship return types called via reflection */
    private const ELOQUENT_RELATION_TYPES = [
        'HasMany', 'HasOne', 'BelongsTo', 'BelongsToMany',
        'HasOneThrough', 'HasManyThrough',
        'MorphTo', 'MorphMany', 'MorphOne', 'MorphToMany', 'MorphedByMany',
    ];

    /** @var array<string, string[]> Concrete class FQN → list of implemented interface FQNs */
    private array $classToInterfaces = [];

    /** @var array<string, bool> FQNs that are known interfaces (never genuine orphans) */
    private array $knownInterfaces = [];

    /** @var array<string, array<string, bool>> Interface FQN -> method names */
    private array $interfaceMethods = [];

    /**
     * Classes that have at least one method called from a *different* class.
     * When the static edge graph proves a class IS used externally, other methods
     * on that class likely are too — they just can't be traced through interface
     * dispatch, factory returns, or dynamic invocation.
     *
     * @var array<string, bool>
     */
    private array $classHasExternalCallers = [];

    public function __construct(
        private Flow $flow,
        private MetricsAnalyzer $metricsAnalyzer,
        /** @var string[] Custom patterns from flow-engine.json entrypoints.patterns */
        private array $entrypointPatterns = []
    ) {
        $this->buildClassMaps();
    }

    /**
     * Retorna todos os métodos órfãos (nunca chamados).
     *
     * @api
     * @return OrphanMethod[]
     */
    public function orphanMethods(): array
    {
        $orphans = [];

        foreach ($this->flow->nodes() as $node) {
            // Um script é alcançável pela URL: ninguém precisa incluí-lo para que ele
            // rode. Fan-in zero é o estado NORMAL de uma página, não indício de código
            // morto — reportá-la como órfã convidaria a apagar o sistema inteiro.
            if ($node->type() === Node::TYPE_FILE) {
                continue;
            }

            $metrics = $this->metricsAnalyzer->metricsFor($node->id());

            // Nunca chamado E não é entry point legítimo E não é utility legítimo
            if ($metrics->fanIn === 0 && !$this->isLegitimateEntryPoint($node) && !$this->isLegitimateUtility($node)) {
                $orphans[] = new OrphanMethod(
                    nodeId: $node->id(),
                    reason: $this->determineReason($node->id(), $metrics),
                    confidence: $this->calculateConfidence($node, $metrics)
                );
            }
        }

        // Ordenar por confiança (maior primeiro)
        usort($orphans, fn($a, $b) => $b->confidence <=> $a->confidence);

        return $orphans;
    }

    /**
     * Retorna leaf nodes suspeitos (não chamam ninguém e não são utilities).
     *
     * @return OrphanMethod[]
     */
    public function suspiciousLeafNodes(): array
    {
        $suspicious = [];

        foreach ($this->flow->nodes() as $node) {
            $metrics = $this->metricsAnalyzer->metricsFor($node->id());

            // Leaf node (fan-out = 0) E não é utility legítimo E não é entry point legítimo
            if ($metrics->fanOut === 0
                && !$this->isLegitimateUtility($node)
                && !$this->isLegitimateEntryPoint($node)
            ) {
                $suspicious[] = new OrphanMethod(
                    nodeId: $node->id(),
                    reason: 'Leaf node with no apparent utility purpose',
                    confidence: $this->calculateLeafConfidence($node->id(), $metrics)
                );
            }
        }

        usort($suspicious, fn($a, $b) => $b->confidence <=> $a->confidence);

        return $suspicious;
    }

    /**
     * Retorna estatísticas sobre código órfão.
     *
     * @api
     */
    public function stats(): array
    {
        $orphans = $this->orphanMethods();
        $suspicious = $this->suspiciousLeafNodes();

        return [
            'totalOrphans' => count($orphans),
            'highConfidenceOrphans' => count(array_filter($orphans, fn($o) => $o->confidence > 0.7)),
            'suspiciousLeafNodes' => count($suspicious),
            'percentageOrphans' => $this->flow->nodeCount() > 0
                ? round((count($orphans) / $this->flow->nodeCount()) * 100, 2)
                : 0,
        ];
    }

    /**
     * Pre-builds lookup maps from the edge graph for O(1) queries during detection.
     */
    private function buildClassMaps(): void
    {
        foreach ($this->flow->nodes() as $node) {
            if (!($node->metadata()['isInterface'] ?? false)) {
                continue;
            }

            $interfaceFqn = $this->extractClassName($node->id());
            $method = $this->extractMethodName($node->id());
            if ($interfaceFqn === '' || $method === '' || $method === '__interface') {
                continue;
            }

            $this->knownInterfaces[$interfaceFqn] = true;
            $this->interfaceMethods[$interfaceFqn][$method] = true;
        }

        foreach ($this->flow->edges() as $edge) {
            if ($edge->isInterfaceImplementation()) {
                // Canonical form: ConcreteClass::__construct → InterfaceFQN::__interface
                $fromClass = $this->extractClassName($edge->from());
                $toId      = $edge->to();
                if (str_ends_with($toId, '::__interface') && $fromClass !== '') {
                    $interfaceFQN = substr($toId, 0, -strlen('::__interface'));
                    $this->classToInterfaces[$fromClass][] = $interfaceFQN;
                    $this->knownInterfaces[$interfaceFQN]  = true;
                }
                continue;
            }

            // Track cross-class callers: from ≠ to class.
            $fromClass = $this->extractClassName($edge->from());
            $toClass   = $this->extractClassName($edge->to());
            if ($fromClass !== '' && $toClass !== '' && $fromClass !== $toClass) {
                $this->classHasExternalCallers[$toClass] = true;
            }
        }
    }

    /**
     * Returns true when the node likely satisfies an interface contract.
     *
     * Interface method nodes are not emitted by the parser, and the FQN stored in the
     * interface_implementation edge may differ from the parsed interface namespace (due to
     * unresolved `use` aliases). Instead we use a structural heuristic:
     *
     * - If the class implements ≥1 interface AND has NO directly-tracked cross-class callers,
     *   ALL of its methods are treated as interface implementations. Callers dispatch through
     *   the interface type — the concrete methods never accumulate fan-in in the static graph.
     *
     * - If the class DOES have directly-tracked callers (edges resolved by the FlowCallVisitor),
     *   we can trust the edge graph for that class and do NOT suppress its methods, so that
     *   genuinely unused methods are still reported.
     */
    private function implementsInterfaceMethod(Node $node): bool
    {
        $class = $this->extractClassName($node->id());
        $method = $this->extractMethodName($node->id());
        $interfaces = $this->classToInterfaces[$class] ?? [];
        if ($method === '' || $interfaces === []) {
            return false;
        }

        foreach ($interfaces as $interfaceFqn) {
            if ($this->interfaceMethods[$interfaceFqn][$method] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identidade lógica do símbolo, para casar os padrões de supressão.
     *
     * Deliberadamente SEM o qualificador de arquivo: ele existe só para desambiguar
     * homônimos e não faz parte da identidade do símbolo. As supressões casam
     * substrings ('Command', 'Test', 'Handler'), então incluir o caminho no alvo
     * faria qualquer nó sob um diretório com esse nome deixar de ser órfão por
     * acidente — um falso-negativo silencioso.
     */
    private function patternTarget(Node $node): string
    {
        return $node->class() . '::' . $node->method();
    }

    /**
     * Verifica se e um entry point legitimo.
     */
    private function isLegitimateEntryPoint(Node $node): bool
    {
        $nodeId = $this->patternTarget($node);
        $method = $this->extractMethodName($nodeId);

        // Engine-synthetic nodes (e.g. __model created by ModelPropertyVisitor) are never orphans
        if ($method === '__model') {
            return true;
        }

        // Interface method nodes: the parser now emits nodes for interface method declarations
        // with isInterface:true so they can be suppressed here even when knownInterfaces misses them.
        if ($node->metadata()['isInterface'] ?? false) {
            return true;
        }

        // Interface method nodes: callers use the interface type, so the method always has fan-in 0.
        $class = $this->extractClassName($nodeId);
        if ($this->knownInterfaces[$class] ?? false) {
            return true;
        }

        // Python dunder methods are protocol methods called by the language runtime — never orphans.
        // Covers: __str__, __repr__, __eq__, __len__, __iter__, __enter__, __exit__, __call__,
        //         __hash__, __bool__, __contains__, __add__, __getitem__, __setitem__, etc.
        if (preg_match('/^__[a-z_]+__$/', $method)) {
            return true;
        }

        // Python framework entrypoints (Flask, FastAPI, Django CBV, Click, Typer, Celery, __main__).
        // The parser sets 'entrypoint_type' in metadata when a decorator or pattern is recognised.
        $entrypointType = $node->metadata()['entrypoint_type'] ?? null;
        if ($entrypointType !== null) {
            return true;
        }

        foreach (self::LEGITIMATE_ENTRY_PATTERNS as $pattern) {
            if (str_contains($nodeId, $pattern)) {
                return true;
            }
        }

        // Framework-aware: metodos chamados implicitamente pelo Laravel/Livewire
        if (in_array($method, self::FRAMEWORK_ENTRY_METHODS, true)) {
            return true;
        }

        // Laravel Model scopes: scopeActive, scopePublished, etc.
        if (str_starts_with($method, 'scope') && strlen($method) > 5 && ctype_upper($method[5])) {
            return true;
        }

        // PHP 8 attribute entrypoints (#[Route], #[Controller], #[AsCommand], etc.)
        $attributes = $node->metadata()['attributes'] ?? [];
        foreach ($attributes as $attr) {
            if (in_array($attr, self::FRAMEWORK_ATTRIBUTES, true)) {
                return true;
            }
        }

        // Livewire computed properties: getXxxProperty() → acessadas como $this->xxx em templates
        if (preg_match('/^get[A-Z][a-zA-Z0-9]*Property$/', $method)) {
            return true;
        }

        // Livewire property watchers: updatedXxx() / updatingXxx() → disparadas por wire:model
        if (preg_match('/^(updated|updating)[A-Z][a-zA-Z0-9]*$/', $method)) {
            return true;
        }

        // Eloquent accessors/mutators: getXxxAttribute() / setXxxAttribute() → $model->xxx
        if (preg_match('/^(get|set)[A-Z][a-zA-Z0-9]*Attribute$/', $method)) {
            return true;
        }

        // Eloquent relationships: método cujo return type é uma classe de relação do Eloquent
        $returnTypeFull = $node->metadata()['returnType'] ?? '';
        $returnTypeLast = str_contains($returnTypeFull, '\\')
            ? substr($returnTypeFull, strrpos($returnTypeFull, '\\') + 1)
            : $returnTypeFull;
        if ($returnTypeLast !== '' && in_array($returnTypeLast, self::ELOQUENT_RELATION_TYPES, true)) {
            return true;
        }

        // Interface implementation: concrete method satisfying an interface contract.
        // Callers dispatch via the interface type — the concrete method never gets direct fan-in.
        if ($this->implementsInterfaceMethod($node)) {
            return true;
        }

        // Named constructors / static factories: forXxx(), fromXxx() are always public-API entry points.
        if (preg_match('/^(for|from)[A-Z]/', $method)) {
            return true;
        }

        // Public API patterns: DTO, Query, and Repository surfaces are stable external contracts.
        foreach (self::PUBLIC_API_PATTERNS as $pattern) {
            if (str_contains($nodeId, $pattern)) {
                return true;
            }
        }

        // Custom entrypoint patterns from flow-engine.json
        foreach ($this->entrypointPatterns as $pattern) {
            if (str_contains($nodeId, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se é um utility legítimo.
     */
    private function isLegitimateUtility(Node $node): bool
    {
        $nodeId = $this->patternTarget($node);
        $method = $this->extractMethodName($nodeId);

        foreach (self::LEGITIMATE_UTILITY_PATTERNS as $pattern) {
            if (str_contains($nodeId, $pattern)) {
                return true;
            }
        }

        // Fluent builder: methods returning static/self are always part of a builder chain.
        $returnType = $node->metadata()['returnType'] ?? '';
        if (in_array($returnType, ['static', 'self'], true)) {
            return true;
        }

        // Immutable wither pattern: withXxx() returns a clone with one field changed.
        if (preg_match('/^with[A-Z]/', $method)) {
            return true;
        }

        return false;
    }

    /**
     * Determina a razão pela qual o método é órfão.
     */
    private function determineReason(string $nodeId, NodeMetrics $metrics): string
    {
        if ($metrics->fanOut === 0) {
            return 'Never called and calls nobody (isolated method)';
        }

        if ($metrics->fanOut > 0) {
            return "Never called but calls {$metrics->fanOut} other method(s)";
        }

        return 'Never called';
    }

    /**
     * Calcula confianca de que e realmente codigo orfao (0.0 - 1.0).
     */
    private function calculateConfidence(Node $node, NodeMetrics $metrics): float
    {
        $nodeId = $this->patternTarget($node);
        $method = $this->extractMethodName($nodeId);

        // Framework methods: confianca zero (nao e orfao)
        if (in_array($method, self::FRAMEWORK_ENTRY_METHODS, true)) {
            return 0.0;
        }

        // Livewire components: render/mount com Livewire no namespace
        if (str_contains($nodeId, 'Livewire') && in_array($method, ['render', 'mount'], true)) {
            return 0.0;
        }

        // Padrões Livewire/Eloquent com reflection — nunca são órfãos reais
        if (preg_match('/^get[A-Z][a-zA-Z0-9]*Property$/', $method) ||
            preg_match('/^(updated|updating)[A-Z][a-zA-Z0-9]*$/', $method) ||
            preg_match('/^(get|set)[A-Z][a-zA-Z0-9]*Attribute$/', $method)
        ) {
            return 0.0;
        }

        $confidence = 0.5; // base

        // 1. Nao chama ninguem (totalmente isolado)
        if ($metrics->fanOut === 0) {
            $confidence += 0.3;
        }

        // 2. Nao esta em namespaces de infra (podem ser chamados externamente)
        if (!str_contains($nodeId, 'Infrastructure') && !str_contains($nodeId, 'Bootstrap')) {
            $confidence += 0.1;
        }

        // 3. Nao e construtor ou metodo magico
        if (!str_contains($nodeId, '__construct') && !str_contains($nodeId, '__')) {
            $confidence += 0.1;
        }

        // 4. If the class IS being called from outside on other methods, the static edge graph
        //    just can't trace all dispatch paths (interface calls, factory returns, etc.).
        //    Reduce confidence to avoid false positives for methods on the same class.
        $class = $this->extractClassName($nodeId);
        if ($this->classHasExternalCallers[$class] ?? false) {
            $confidence -= 0.2;
        }

        // 5. Static methods are called via Class::method() — the edge-graph FQN may be wrong
        //    (resolveFQN vs resolveClass mismatch). Reduce confidence to avoid false positives.
        if ($node->metadata()['isStatic'] ?? false) {
            $confidence -= 0.15;
        }

        return min(1.0, $confidence);
    }

    /**
     * Calcula confiança de que leaf node é suspeito.
     */
    private function calculateLeafConfidence(string $nodeId, NodeMetrics $metrics): float
    {
        $confidence = 0.3; // base baixa (leaf nodes são comuns)

        // Aumentar se também nunca é chamado
        if ($metrics->fanIn === 0) {
            $confidence += 0.4;
        }

        // Diminuir se parece utility
        if (preg_match('/^(get|is|has|to|from)/', $this->extractMethodName($nodeId))) {
            $confidence -= 0.2;
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Extrai o nome da classe do ID do nó (tudo antes de '::').
     */
    private function extractClassName(string $nodeId): string
    {
        return explode('::', $nodeId)[0] ?? '';
    }

    /**
     * Extrai nome do método do ID.
     */
    private function extractMethodName(string $nodeId): string
    {
        $parts = explode('::', $nodeId);
        return $parts[1] ?? '';
    }
}
