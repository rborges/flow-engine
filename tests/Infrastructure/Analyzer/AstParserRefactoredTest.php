<?php

namespace FlowEngine\Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Analyzer\AstParser;
use FlowEngine\Infrastructure\Analyzer\Visitors\ArrayAccessVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\FunctionCallVisitor;
use FlowEngine\Infrastructure\Analyzer\Visitors\NewInstanceVisitor;
use PHPUnit\Framework\TestCase;

/**
 * Testes de integração para AstParser v3.0 refatorado.
 * 
 * Valida que a refatoração mantém comportamento idêntico à v2.2.
 */
final class AstParserRefactoredTest extends TestCase
{
    private AstParser $parser;
    private string $tempDir;

    protected function setUp(): void
    {
        $factory = new DefaultNodeFactory();
        $this->parser = new AstParser($factory);

        // Criar diretório temporário para arquivos de teste
        $this->tempDir = sys_get_temp_dir() . '/ast-parser-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Limpar arquivos temporários
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
    }

    public function testParsesSimpleClass(): void
    {
        $file = $this->createTempFile('SimpleClass.php', <<<'PHP'
        <?php
        namespace App;
        
        class SimpleClass {
            public function hello() {
                return 'world';
            }
            
            public function greet() {
                $this->hello();
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // Deve criar 2 nodes
        $this->assertCount(2, $result['nodes']);

        // Deve criar 1 edge (greet -> hello)
        $this->assertCount(1, $result['edges']);

        // Verificar IDs dos nodes
        $nodeIds = array_map(fn($n) => $n->id(), $result['nodes']);
        $this->assertContains('App\SimpleClass::hello', $nodeIds);
        $this->assertContains('App\SimpleClass::greet', $nodeIds);

        // Verificar edge
        $edge = $result['edges'][0];
        $this->assertEquals('App\SimpleClass::greet', $edge->from());
        $this->assertEquals('App\SimpleClass::hello', $edge->to());
        $this->assertEquals('method_call', $edge->type());
    }

    public function testDetectsStaticCalls(): void
    {
        $file = $this->createTempFile('StaticExample.php', <<<'PHP'
        <?php
        namespace App;
        
        class Helper {
            public static function format($data) {
                return strtoupper($data);
            }
        }
        
        class User {
            public function getName() {
                return Helper::format('john');
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // 2 nodes
        $this->assertCount(2, $result['nodes']);

        // 1 static_call edge (getName -> Helper::format).
        // O parser também emite a chamada a strtoupper() como function_call; ela é
        // descartada pelo FlowBuilder por não ter nó correspondente.
        $staticCalls = array_values(array_filter(
            $result['edges'],
            fn($e) => $e->type() === 'static_call'
        ));
        $this->assertCount(1, $staticCalls);

        $edge = $staticCalls[0];
        $this->assertEquals('App\User::getName', $edge->from());
        $this->assertEquals('App\Helper::format', $edge->to());
    }

    public function testDetectsPropertyAccess(): void
    {
        $file = $this->createTempFile('PropertyExample.php', <<<'PHP'
        <?php
        namespace App;
        
        class User {
            private $name;
            
            public function getName() {
                return $this->name;
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // 1 node
        $this->assertCount(1, $result['nodes']);

        // 1 edge (getName -> $name)
        $this->assertCount(1, $result['edges']);

        $edge = $result['edges'][0];
        $this->assertEquals('App\User::getName', $edge->from());
        $this->assertEquals('App\User::$name', $edge->to());
        $this->assertEquals('property_access', $edge->type());
    }

    public function testDetectsTraitUsage(): void
    {
        $file = $this->createTempFile('TraitExample.php', <<<'PHP'
    <?php
    namespace App;
    
    trait LoggerTrait {
        public function log($msg) {}
    }
    
    class Service {
        use LoggerTrait;
        
        public function process() {}
    }
    PHP);

        $result = $this->parser->parse($file);

        // Desde v4.35.0 traits geram nodes pros seus métodos: LoggerTrait::log + Service::process
        $this->assertCount(2, $result['nodes']);

        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        $this->assertContains('App\Service::process', $ids);
        $this->assertContains('App\LoggerTrait::log', $ids);

        // 1 edge (Service usa LoggerTrait)
        $edges = array_filter($result['edges'], fn($e) => $e->type() === 'trait_usage');
        $this->assertCount(1, $edges);

        $edge = array_values($edges)[0];
        $this->assertEquals('App\Service::__construct', $edge->from());
        $this->assertEquals('App\LoggerTrait::__trait', $edge->to());
    }
    
    public function testDetectsInterfaceImplementation(): void
    {
        $file = $this->createTempFile('InterfaceExample.php', <<<'PHP'
        <?php
        namespace App;
        
        interface ServiceInterface {
            public function handle();
        }
        
        class Service implements ServiceInterface {
            public function handle() {}
        }
        PHP);

        $result = $this->parser->parse($file);

        // 2 nodes: ServiceInterface::handle (isInterface:true) + Service::handle
        $this->assertCount(2, $result['nodes']);

        $nodeIds = array_map(fn($n) => $n->id(), $result['nodes']);
        $this->assertContains('App\\ServiceInterface::handle', $nodeIds);
        $this->assertContains('App\\Service::handle', $nodeIds);

        // Interface method node is marked with isInterface:true
        $interfaceNode = null;
        foreach ($result['nodes'] as $n) {
            if ($n->id() === 'App\\ServiceInterface::handle') {
                $interfaceNode = $n;
                break;
            }
        }
        $this->assertNotNull($interfaceNode);
        $this->assertTrue($interfaceNode->metadata()['isInterface'] ?? false);

        // 1 edge (Service implementa ServiceInterface)
        $edges = array_filter($result['edges'], fn($e) => $e->type() === 'interface_implementation');
        $this->assertCount(1, $edges);

        $edge = array_values($edges)[0];
        $this->assertEquals('App\Service::__construct', $edge->from());
        $this->assertEquals('App\ServiceInterface::__interface', $edge->to());
    }

    public function testCanAddCustomVisitor(): void
    {
        // Adicionar NewInstanceVisitor
        $this->parser->addVisitor(new NewInstanceVisitor());

        $file = $this->createTempFile('NewExample.php', <<<'PHP'
        <?php
        namespace App;
        
        class User {
            public function create() {
                return new User();
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // 1 node
        $this->assertCount(1, $result['nodes']);

        // 1 edge (create -> User::__construct)
        $edges = array_filter($result['edges'], fn($e) => $e->type() === 'instantiation');
        $this->assertCount(1, $edges);

        $edge = array_values($edges)[0];
        $this->assertEquals('App\User::create', $edge->from());
        $this->assertEquals('App\User::__construct', $edge->to());
    }

    public function testFunctionCallDetection(): void
    {
        $file = $this->createTempFile('FunctionExample.php', <<<'PHP'
        <?php
        namespace App;
        
        function helper($data) {
            return strtoupper($data);
        }
        
        class Service {
            public function process() {
                return helper('test');
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // 2 nodes: a função livre helper() e o método process().
        // Funções livres são nós de primeira classe, não apenas símbolos.
        $ids = array_map(fn($n) => $n->id(), $result['nodes']);
        sort($ids);
        $this->assertSame(['App\Service::process', 'func:App\helper'], $ids);

        // process() -> helper()
        $calls = array_values(array_filter(
            $result['edges'],
            fn($e) => $e->type() === 'function_call' && $e->from() === 'App\Service::process'
        ));
        $this->assertCount(1, $calls);
        $this->assertEquals('func:App\helper', $calls[0]->to());
    }

    public function testArrayAccessDetection(): void
    {
        $this->parser->addVisitor(new ArrayAccessVisitor());

        $file = $this->createTempFile('ArrayExample.php', <<<'PHP'
        <?php
        namespace App;
        
        class Config {
            private $data = [];
            
            public function get($key) {
                return $this->data[$key];
            }
        }
        PHP);

        $result = $this->parser->parse($file);

        // 1 node
        $this->assertCount(1, $result['nodes']);

        // 2 edges (property access + array access)
        $this->assertGreaterThanOrEqual(1, count($result['edges']));

        // Verificar que array_access existe
        $arrayEdges = array_filter($result['edges'], fn($e) => $e->type() === 'array_access');
        $this->assertCount(1, $arrayEdges);
    }

    public function testHandlesEmptyFile(): void
    {
        $file = $this->createTempFile('Empty.php', '<?php');

        $result = $this->parser->parse($file);

        $this->assertCount(0, $result['nodes']);
        $this->assertCount(0, $result['edges']);
    }

    public function testHandlesInvalidSyntax(): void
    {
        $file = $this->createTempFile('Invalid.php', '<?php class Invalid {');

        // Não deve lançar exceção, deve retornar vazio
        $result = $this->parser->parse($file);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('edges', $result);
    }

    /**
     * Cria arquivo temporário para teste.
     */
    private function createTempFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    // --- v4.0: PHP 8 attribute detection ---

    public function test_method_level_attribute_stored_in_metadata(): void
    {
        $file = $this->createTempFile('Attr.php', '<?php
namespace App\Http;

class UserController {
    #[Route("/users", methods: ["GET"])]
    public function index(): void {}
}
');
        $result = $this->parser->parse($file);

        $node = array_values(array_filter(
            $result['nodes'],
            fn($n) => $n->method() === 'index'
        ))[0] ?? null;

        $this->assertNotNull($node);
        $attrs = $node->metadata()['attributes'] ?? [];
        $this->assertContains('Route', $attrs);
    }

    public function test_class_level_attribute_propagated_to_all_methods(): void
    {
        $file = $this->createTempFile('ClassAttr.php', '<?php
namespace App\Http;

#[Controller]
class ProductController {
    public function index(): void {}
    public function show(): void {}
}
');
        $result = $this->parser->parse($file);

        foreach ($result['nodes'] as $node) {
            $attrs = $node->metadata()['attributes'] ?? [];
            $this->assertContains('Controller', $attrs, "Method {$node->method()} should have Controller attr");
        }
    }

    public function test_class_and_method_attributes_are_merged(): void
    {
        $file = $this->createTempFile('MergedAttr.php', '<?php
namespace App;

#[Controller]
class OrderController {
    #[Route("/orders")]
    public function list(): void {}
}
');
        $result = $this->parser->parse($file);

        $node = $result['nodes'][0] ?? null;
        $this->assertNotNull($node);

        $attrs = $node->metadata()['attributes'] ?? [];
        $this->assertContains('Controller', $attrs);
        $this->assertContains('Route', $attrs);
    }
}
