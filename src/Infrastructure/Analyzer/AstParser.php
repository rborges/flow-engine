<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Domain\Flow\NodeFactory;
use FlowEngine\Infrastructure\Analyzer\Visitors\ClassVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\FreeFunctionVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\FunctionCallVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\IncludeVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\LocalAssignVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\MethodCallVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\MethodVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\NamespaceVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\NodeVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\ModelPropertyVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\PropertyAccessVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\StaticCallVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\HttpFacadeCallVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\StaticPropertyVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\VisitorContext;
use FlowEngine\Infrastructure\Analyzer\Visitors\UseStatementVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\LaravelClassifierVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\NewInstantiationVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\WordPressHookVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\TopLevelIdentifierVisitor;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use FlowEngine\Infrastructure\Analyzer\FileParser;

/**
 * AstParser v3.0 - Refatorado com Visitor Pattern.
 * 
 * RESPONSABILIDADES:
 * - Parse de arquivos PHP via nikic/php-parser
 * - Orquestração de visitors para extração de dados
 * - Construção do grafo de execução (nodes + edges)
 * 
 * ARQUITETURA:
 * - Visitor Pattern para modularidade
 * - VisitorContext para estado compartilhado
 * - Extensível via addVisitor()
 * 
 * VERSÃO: 3.0 (Visitor Pattern)
 * COMPLEXIDADE: 5 (vs 78 na v2.2)
 * 
 * @internal
 */
final class AstParser implements FileParser
{
    /** @var NodeVisitor[] */
    private array $visitors = [];

    public function __construct(
        private readonly NodeFactory $nodeFactory,
        private readonly ?string $projectRoot = null
    ) {
        // Registrar visitors padrão
        // ORDEM IMPORTANTE: Structural primeiro, depois call detection
        $this->visitors = [
            // 0. Import tracking (populates useMap before any other visitor resolves classes)
            new UseStatementVisitor(),

            // 1. Structural (capturam contexto)
            new NamespaceVisitor(),
            new ClassVisitor(),
            new MethodVisitor(),
            new FreeFunctionVisitor(),  // must run before call detection to open the function scope

            // 2. Call Detection (usam contexto)
            new LocalAssignVisitor(),   // must run before MethodCallVisitor to populate localVariableTypes
            new MethodCallVisitor(),
            new StaticCallVisitor(),
            new FunctionCallVisitor(),
            new IncludeVisitor(),
            new HttpFacadeCallVisitor(),
            new PropertyAccessVisitor(),
            new StaticPropertyVisitor(),

            // 3. Model Detection (Eloquent)
            new ModelPropertyVisitor(),

            // 4. Laravel Component Classification
            new LaravelClassifierVisitor(),

            // 5. Direct Instantiation Detection (for DIP analysis)
            new NewInstantiationVisitor(),

            // 6. WordPress Hook Detection
            new WordPressHookVisitor(),

            // 7. Top-level identifier collection (functions, consts — emits SymbolDTOs)
            new TopLevelIdentifierVisitor(),
        ];
    }

    /**
     * Adiciona visitor customizado.
     * 
     * Útil para estender funcionalidade sem modificar código existente.
     * 
     * @api
     */
    public function addVisitor(NodeVisitor $visitor): void
    {
        $this->visitors[] = $visitor;
    }

    /**
     * Analisa um arquivo PHP e retorna nodes e edges.
     * 
     * @internal 
     * @param string $file Caminho absoluto do arquivo
     * @return array{nodes: Node[], edges: Edge[]}
     */
    public function parse(string $file): array
    {
        $code = file_get_contents($file);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        
        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $e) {
            // Sintaxe inválida - retornar vazio
            return ['nodes' => [], 'edges' => [], 'symbols' => []];
        }

        if (!$ast) {
            return ['nodes' => [], 'edges' => [], 'symbols' => []];
        }

        // Um arquivo só vira nó quando é um "script": tem código executável de topo
        // ou declara funções livres. Arquivos que apenas declaram classes continuam
        // fora do grafo, o que mantém o resultado de projetos modernos inalterado.
        $relativePath = ProjectRelativePath::for($file, $this->projectRoot);

        $fileNodeId = $this->isScript($ast)
            ? 'file:' . $relativePath
            : null;

        // Criar contexto compartilhado
        $context = new VisitorContext($file, $this->nodeFactory, $fileNodeId, $this->projectRoot);

        if ($fileNodeId !== null) {
            $context->addNode($this->nodeFactory->create(
                class: $relativePath,
                method: '__file',
                file: $file,
                line: null,
                language: 'php',
                metadata: null,
                type: Node::TYPE_FILE
            ));
        }

        // Criar traverser e adicionar visitor delegador
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->createDelegatingVisitor($context));

        // Traversar AST (visitors processam nodes via contexto)
        $traverser->traverse($ast);

        // Retornar resultados coletados
        return [
            'nodes'   => $context->getNodes(),
            'edges'   => $context->getEdges(),
            'symbols' => $context->getSymbols(),
        ];
    }

    /**
     * Um arquivo é "script" quando executa algo ao ser incluído: código de topo,
     * HTML inline, chamadas, atribuições.
     *
     * Arquivos que apenas declaram (classes, interfaces, traits, funções, consts)
     * não executam nada e não viram nó-arquivo — as declarações em si já são nós.
     * É isso que mantém o grafo de projetos modernos idêntico ao anterior.
     *
     * @param \PhpParser\Node\Stmt[] $stmts
     */
    private function isScript(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            // Wrappers: o que importa está dentro deles.
            if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_
                || $stmt instanceof \PhpParser\Node\Stmt\Declare_
            ) {
                if ($this->isScript($stmt->stmts ?? [])) {
                    return true;
                }
                continue;
            }

            // Declarações puras: não executam nada por si só.
            if ($stmt instanceof \PhpParser\Node\Stmt\Class_
                || $stmt instanceof \PhpParser\Node\Stmt\Interface_
                || $stmt instanceof \PhpParser\Node\Stmt\Trait_
                || $stmt instanceof \PhpParser\Node\Stmt\Enum_
                || $stmt instanceof \PhpParser\Node\Stmt\Function_
                || $stmt instanceof \PhpParser\Node\Stmt\Use_
                || $stmt instanceof \PhpParser\Node\Stmt\GroupUse
                || $stmt instanceof \PhpParser\Node\Stmt\Const_
                || $stmt instanceof \PhpParser\Node\Stmt\Nop
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Cria visitor que delega para visitors registrados.
     *
     * @internal
     */
    private function createDelegatingVisitor(VisitorContext $context): object
    {
        return new class($this->visitors, $context) extends \PhpParser\NodeVisitorAbstract {
            /** @param NodeVisitor[] $visitors */
            public function __construct(
                private array $visitors,
                private VisitorContext $context
            ) {}

            public function enterNode(\PhpParser\Node $node)
            {
                foreach ($this->visitors as $visitor) {
                    if ($visitor->supports($node)) {
                        $visitor->enter($node, $this->context);
                    }
                }
                return null;
            }

            public function leaveNode(\PhpParser\Node $node)
            {
                foreach ($this->visitors as $visitor) {
                    if ($visitor->supports($node)) {
                        $visitor->leave($node, $this->context);
                    }
                }
                return null;
            }
        };
    }
}
