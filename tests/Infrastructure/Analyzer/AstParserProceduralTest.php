<?php

declare(strict_types=1);

namespace FlowEngine\Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Analyzer\AstParser;
use PHPUnit\Framework\TestCase;

/**
 * PHP procedural: scripts de topo, funções livres e classes sem namespace.
 *
 * Antes desta capacidade, um arquivo com 500 linhas de código de topo e alguns
 * require produzia zero nós e zero arestas — o grafo simplesmente não enxergava
 * a maior parte de um sistema legado.
 */
final class AstParserProceduralTest extends TestCase
{
    private string $root;
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fe-procedural-' . uniqid();
        mkdir($this->root . DIRECTORY_SEPARATOR . 'classes', 0777, true);

        $this->parser = new AstParser(new DefaultNodeFactory(), $this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/**/*.php') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'classes');
        @rmdir($this->root);
    }

    private function write(string $relative, string $code): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        file_put_contents($path, $code);

        return $path;
    }

    public function test_script_com_codigo_de_topo_vira_no_de_arquivo(): void
    {
        $file = $this->write('dashboard.php', <<<'PHP'
        <?php
        require_once 'classes/AuthModel.php';

        $auth = new AuthModel();
        echo $auth->validarLogin('joao');
        PHP);

        $result = $this->parser->parse($file);

        $fileNodes = array_values(array_filter(
            $result['nodes'],
            fn(Node $n) => $n->type() === Node::TYPE_FILE
        ));

        $this->assertCount(1, $fileNodes);
        $this->assertSame('file:dashboard.php', $fileNodes[0]->id());
    }

    public function test_chamadas_a_partir_de_codigo_de_topo_geram_arestas(): void
    {
        $file = $this->write('dashboard.php', <<<'PHP'
        <?php
        $auth = new AuthModel();
        echo $auth->validarLogin('joao');
        PHP);

        $result = $this->parser->parse($file);

        $targets = [];
        foreach ($result['edges'] as $edge) {
            if ($edge->from() === 'file:dashboard.php') {
                $targets[] = $edge->to();
            }
        }

        $this->assertContains('AuthModel::__construct', $targets);
        $this->assertContains('AuthModel::validarLogin', $targets);
    }

    public function test_arquivo_que_so_declara_classe_nao_vira_no_de_arquivo(): void
    {
        $file = $this->write('classes/AuthModel.php', <<<'PHP'
        <?php
        class AuthModel {
            public function validarLogin($usuario) { return true; }
        }
        PHP);

        $result = $this->parser->parse($file);

        $fileNodes = array_filter(
            $result['nodes'],
            fn(Node $n) => $n->type() === Node::TYPE_FILE
        );

        $this->assertCount(0, $fileNodes, 'Arquivo de declaração pura não executa nada ao ser incluído');
        $this->assertSame(['AuthModel::validarLogin'], array_map(fn(Node $n) => $n->id(), $result['nodes']));
    }

    public function test_funcao_livre_vira_no_e_recebe_aresta(): void
    {
        $file = $this->write('funcoes.php', <<<'PHP'
        <?php
        function formatarCpf($cpf) {
            return $cpf;
        }

        function exibirCpf($cpf) {
            return formatarCpf($cpf);
        }
        PHP);

        $result = $this->parser->parse($file);

        $ids = array_map(fn(Node $n) => $n->id(), $result['nodes']);
        sort($ids);
        $this->assertSame(['func:exibirCpf', 'func:formatarCpf'], $ids);

        $calls = array_values(array_filter(
            $result['edges'],
            fn($e) => $e->type() === 'function_call'
        ));

        $this->assertCount(1, $calls);
        $this->assertSame('func:exibirCpf', $calls[0]->from());
        $this->assertSame('func:formatarCpf', $calls[0]->to());
    }

    public function test_id_do_no_de_arquivo_e_relativo_a_raiz(): void
    {
        $file = $this->write('classes/bootstrap.php', <<<'PHP'
        <?php
        $x = 1;
        PHP);

        $result = $this->parser->parse($file);

        $this->assertSame('file:classes/bootstrap.php', $result['nodes'][0]->id());
    }
}
