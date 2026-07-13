<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Application\DTO\SymbolDTO;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\NodeFactory;

/**
 * Contexto compartilhado entre todos os visitors.
 * 
 * Armazena:
 * - Estado atual da análise (namespace, classe, método)
 * - Nodes e edges coletados
 * - Informações auxiliares (traits usados, etc.)
 * 
 * IMPORTANTE:
 * - Modificado in-place por visitors
 * - Um contexto por arquivo analisado
 * - Reset entre arquivos
 * 
 * @internal
 */
final class VisitorContext
{
    /** @var Node[] Nodes coletados */
    private array $nodes = [];

    /** @var Edge[] Edges coletadas */
    private array $edges = [];

    /** @var SymbolDTO[] Top-level symbols (imports, exports, functions, consts) */
    private array $symbols = [];

    /** Namespace atual sendo processado */
    private ?string $currentNamespace = null;

    /** @var string[] Pilha de classes (suporta classes aninhadas/anônimas) */
    private array $classStack = [];

    /** Método atual sendo processado */
    private ?string $currentMethod = null;

    /** Função livre atual (declarada fora de classe), se houver */
    private ?string $currentFunction = null;

    /** @var string[] Traits usados pela classe atual */
    private array $currentTraits = [];

    /** @var string[] PHP 8 attribute names on the current class */
    private array $currentClassAttributes = [];

    /** @var array<string, string> alias => FQN from `use` statements */
    private array $useMap = [];

    /** FQN of parent class currently being extended (null if no extends) */
    private ?string $currentParent = null;

    /** True when the current class-like context is an interface declaration */
    private bool $currentClassIsInterface = false;

    private bool $currentClassIsTrait = false;

    /** HTTP base path from class-level #[Route('/prefix')] (null = no class route) */
    private ?string $classHttpBasePath = null;

    /**
     * Maps property name → resolved FQN for constructor-promoted and assigned properties.
     * Populated by MethodVisitor when parsing __construct, reset on each Stmt\Class_.
     *
     * Used by MethodCallVisitor to resolve $this->prop->method() to the correct class.
     *
     * @var array<string, string>
     */
    private array $propertyTypes = [];

    /**
     * Maps local variable name → resolved FQN within the current method.
     * Populated by MethodCallVisitor (or LocalAssignVisitor) when it sees $var = new Foo().
     * Reset on each method entry (setMethod).
     *
     * Used by MethodCallVisitor to resolve $var->method() to the correct class.
     *
     * @var array<string, string>
     */
    private array $localVariableTypes = [];

    /**
     * @param string|null $fileNodeId ID do nó-arquivo deste arquivo (null quando o
     *                                arquivo não tem código executável de topo).
     */
    public function __construct(
        private readonly string $file,
        private readonly NodeFactory $nodeFactory,
        private readonly ?string $fileNodeId = null
    ) {}

    /**
     * ID do nó-arquivo, ou null se este arquivo não é um script.
     *
     * @internal
     */
    public function fileNodeId(): ?string
    {
        return $this->fileNodeId;
    }

    // ==========================================
    // Getters de Estado
    // ==========================================

    /**
     * @internal
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * @internal
     */
    public function currentNamespace(): ?string
    {
        return $this->currentNamespace;
    }

    /**
     * Retorna classe atual (null se fora de classe ou em classe anônima).
     *
     * @internal
     */
    public function currentClass(): ?string
    {
        $current = end($this->classStack);
        // Retorna null se stack vazia (false) ou classe anônima ('')
        return ($current !== false && $current !== '') ? $current : null;
    }

    /**
     * @internal
     */
    public function currentMethod(): ?string
    {
        return $this->currentMethod;
    }

    /**
     * @internal
     * @return string[]
     */
    public function currentTraits(): array
    {
        return $this->currentTraits;
    }

    /**
     * ID do nó que "contém" o ponto atual da AST — a origem de qualquer aresta.
     *
     * Escopo de método   → Class::method
     * Escopo de função   → func:Namespace\nome
     * Escopo de arquivo  → file:caminho/relativo.php (o nó-arquivo)
     *
     * Retorna null dentro do corpo de uma classe mas fora de um método
     * (defaults de propriedade, constantes de classe): nesse caso não há
     * origem de chamada, e nenhuma aresta deve ser emitida.
     *
     * @internal
     */
    public function currentNodeId(): ?string
    {
        // Qualquer corpo de classe — inclusive anônima, onde currentClass() é null —
        // mantém o comportamento histórico: só há origem dentro de um método nomeado.
        if ($this->classStack !== []) {
            if (!$this->isInsideClass() || $this->currentMethod === null) {
                return null;
            }

            return $this->currentClass() . '::' . $this->currentMethod;
        }

        if ($this->currentFunction !== null) {
            return 'func:' . ($this->currentNamespace !== null
                ? $this->currentNamespace . '\\' . $this->currentFunction
                : $this->currentFunction);
        }

        return $this->fileNodeId;
    }

    /**
     * @internal
     */
    public function currentFunction(): ?string
    {
        return $this->currentFunction;
    }

    /**
     * Entra (nome) ou sai (null) de uma função livre.
     *
     * @internal
     */
    public function setFunction(?string $function): void
    {
        $this->currentFunction = $function;
        // Escopo local próprio, como em setMethod().
        $this->localVariableTypes = [];
    }

    /**
     * @internal
     */
    public function nodeFactory(): NodeFactory
    {
        return $this->nodeFactory;
    }

    // ==========================================
    // Setters de Estado
    // ==========================================

    /**
     * @internal
     */
    public function setNamespace(?string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    /**
     * Push classe na pilha (enter) ou pop (leave).
     *
     * @internal
     * @param string|null $class Nome da classe (null para pop)
     */
    public function setClass(?string $class): void
    {
        if ($class !== null) {
            // Push: entrando numa classe
            $this->classStack[] = $class;
        } else {
            // Pop: saindo de uma classe
            array_pop($this->classStack);
        }

        // Limpar traits, atributos, flag de interface, route base e property types quando muda de classe
        $this->currentTraits = [];
        $this->currentClassAttributes = [];
        $this->currentClassIsInterface = false;
        $this->currentClassIsTrait = false;
        $this->classHttpBasePath = null;
        $this->propertyTypes = [];
    }

    /**
     * @internal
     */
    public function setMethod(?string $method): void
    {
        $this->currentMethod = $method;
        // Reset local variable types on each method entry/exit
        $this->localVariableTypes = [];
    }

    /**
     * @internal
     */
    public function addTrait(string $trait): void
    {
        $this->currentTraits[] = $trait;
    }

    /**
     * Armazena os nomes de atributos PHP 8 da classe atual.
     *
     * @internal
     * @param string[] $attributes
     */
    public function setClassAttributes(array $attributes): void
    {
        $this->currentClassAttributes = $attributes;
    }

    /**
     * Retorna os nomes de atributos PHP 8 da classe atual.
     *
     * @internal
     * @return string[]
     */
    public function getClassAttributes(): array
    {
        return $this->currentClassAttributes;
    }

    /**
     * Store the HTTP base path extracted from a class-level #[Route('/prefix')] attribute.
     *
     * @internal
     */
    public function setClassHttpBasePath(?string $basePath): void
    {
        $this->classHttpBasePath = $basePath;
    }

    /**
     * Returns the HTTP base path for the current class, or null if none was found.
     *
     * @internal
     */
    public function classHttpBasePath(): ?string
    {
        return $this->classHttpBasePath;
    }

    // ==========================================
    // Use-Statement / Import Tracking
    // ==========================================

    /**
     * Register a `use` alias → FQN mapping for the current file.
     *
     * @internal
     */
    public function addUse(string $alias, string $fqn): void
    {
        $this->useMap[$alias] = $fqn;
    }

    /**
     * Resolve a class name through the use-statement map, then fall back to namespace FQN.
     *
     * @internal
     */
    public function resolveClass(string $name): string
    {
        // Already globally qualified
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Single-segment: look up exact alias
        if (!str_contains($name, '\\')) {
            if (isset($this->useMap[$name])) {
                return $this->useMap[$name];
            }
        } else {
            // Multi-segment: check if the leading segment is an alias
            $parts     = explode('\\', $name, 2);
            $firstPart = $parts[0];
            if (isset($this->useMap[$firstPart])) {
                return $this->useMap[$firstPart] . '\\' . $parts[1];
            }
        }

        return $this->resolveFQN($name);
    }

    // ==========================================
    // Interface Tracking
    // ==========================================

    /**
     * @internal
     */
    public function setClassIsInterface(bool $isInterface): void
    {
        $this->currentClassIsInterface = $isInterface;
    }

    /**
     * True when parsing an interface declaration (not a concrete class).
     *
     * @internal
     */
    public function currentClassIsInterface(): bool
    {
        return $this->currentClassIsInterface;
    }

    /**
     * @internal
     */
    public function setClassIsTrait(bool $isTrait): void
    {
        $this->currentClassIsTrait = $isTrait;
    }

    /**
     * True when parsing a trait declaration.
     *
     * @internal
     */
    public function currentClassIsTrait(): bool
    {
        return $this->currentClassIsTrait;
    }

    // ==========================================
    // Property Type Tracking
    // ==========================================

    /**
     * Register a property → FQN binding for $this->prop dispatch resolution.
     *
     * Called by MethodVisitor when processing __construct promoted properties.
     *
     * @internal
     */
    public function setPropertyType(string $propName, string $fqn): void
    {
        $this->propertyTypes[$propName] = $fqn;
    }

    /**
     * Returns the resolved FQN for a property, or null if not tracked.
     *
     * @internal
     */
    public function getPropertyType(string $propName): ?string
    {
        return $this->propertyTypes[$propName] ?? null;
    }

    /**
     * Register a local variable → FQN binding within the current method.
     *
     * Called when $var = new Foo() is seen inside a method body.
     *
     * @internal
     */
    public function setLocalVariableType(string $varName, string $fqn): void
    {
        $this->localVariableTypes[$varName] = $fqn;
    }

    /**
     * Returns the resolved FQN for a local variable, or null if not tracked.
     *
     * @internal
     */
    public function getLocalVariableType(string $varName): ?string
    {
        return $this->localVariableTypes[$varName] ?? null;
    }

    // ==========================================
    // Inheritance Tracking
    // ==========================================

    /**
     * @internal
     */
    public function setCurrentParent(?string $fqn): void
    {
        $this->currentParent = $fqn;
    }

    /**
     * @internal
     */
    public function currentParent(): ?string
    {
        return $this->currentParent;
    }

    // ==========================================
    // Collectors (Nodes & Edges)
    // ==========================================

    /**
     * Adiciona node coletado.
     * 
     * @internal
     */
    public function addNode(Node $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * Adiciona edge coletada.
     * 
     * @internal
     */
    public function addEdge(Edge $edge): void
    {
        $this->edges[] = $edge;
    }

    /**
     * Retorna todos os nodes coletados.
     * 
     * @internal
     * @return Node[]
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Retorna todas as edges coletadas.
     *
     * @internal
     * @return Edge[]
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    /**
     * Adiciona um symbol coletado.
     *
     * @internal
     */
    public function addSymbol(SymbolDTO $symbol): void
    {
        $this->symbols[] = $symbol;
    }

    /**
     * Retorna todos os symbols coletados.
     *
     * @internal
     * @return SymbolDTO[]
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    // ==========================================
    // Utilities
    // ==========================================

    /**
     * Resolve FQN (Fully Qualified Name) de uma classe.
     * 
     * Se a classe não tem namespace, adiciona o namespace atual.
     * 
     * @internal
     * @param string $className Nome da classe (pode ser FQN ou relativo)
     * @return string FQN da classe
     */
    public function resolveFQN(string $className): string
    {
        // Já é FQN (tem backslash)
        if (str_contains($className, '\\')) {
            return $className;
        }

        // Adicionar namespace atual
        if ($this->currentNamespace) {
            return $this->currentNamespace . '\\' . $className;
        }

        // Sem namespace (global)
        return $className;
    }

    /**
     * Verifica se estamos dentro de um método.
     *
     * @internal
     */
    public function isInsideMethod(): bool
    {
        return $this->isInsideClass() && $this->currentMethod !== null;
    }

    /**
     * Verifica se estamos dentro de uma classe nomeada (não anônima).
     *
     * @internal
     */
    public function isInsideClass(): bool
    {
        $current = end($this->classStack);
        return $current !== false && $current !== '';
    }
}