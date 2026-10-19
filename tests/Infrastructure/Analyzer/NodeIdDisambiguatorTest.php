<?php

declare(strict_types=1);

namespace FlowEngine\Tests\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Analyzer\NodeIdDisambiguator;
use PHPUnit\Framework\TestCase;

/**
 * Símbolos globais homônimos em arquivos diferentes.
 *
 * Dois arquivos legados declarando `atualizar()` são funções distintas — nunca são
 * carregados juntos. Antes disto, os dois viravam o mesmo nó `func:atualizar`, com
 * fan-in somado e raio de alcance sem sentido, e qual deles sobrevivia dependia da
 * ordem de iteração do sistema de arquivos.
 */
final class NodeIdDisambiguatorTest extends TestCase
{
    private const ROOT = '/app';

    private function freeFunction(string $name): Node
    {
        return new Node('', $name, __FILE__, 1, 'php', null, Node::TYPE_FUNCTION);
    }

    private function script(string $relPath): Node
    {
        return new Node($relPath, '__file', __FILE__, null, 'php', null, Node::TYPE_FILE);
    }

    /**
     * @param array<string, Node[]> $nodes
     * @param array<string, Edge[]> $edges
     * @return array{nodes: Node[], edges: Edge[], warnings: string[]}
     */
    private function disambiguate(array $nodes, array $edges): array
    {
        return (new NodeIdDisambiguator())->apply($nodes, $edges, self::ROOT);
    }

    /**
     * @param Edge[] $edges
     * @return Edge[]
     */
    private function functionCalls(array $edges): array
    {
        return array_values(array_filter($edges, fn(Edge $e): bool => $e->type() === 'function_call'));
    }

    public function test_sem_colisao_nada_muda(): void
    {
        $result = $this->disambiguate(
            ['/app/a.php' => [$this->freeFunction('unica')]],
            ['/app/a.php' => []]
        );

        $this->assertSame('func:unica', $result['nodes'][0]->id());
        $this->assertSame([], $result['warnings']);
    }

    public function test_homonimos_em_arquivos_diferentes_ganham_qualificador(): void
    {
        $result = $this->disambiguate(
            [
                '/app/a.php' => [$this->freeFunction('atualizar')],
                '/app/b.php' => [$this->freeFunction('atualizar')],
            ],
            ['/app/a.php' => [], '/app/b.php' => []]
        );

        $ids = array_map(fn(Node $n): string => $n->id(), $result['nodes']);
        sort($ids);

        $this->assertSame(['func:atualizar@a.php', 'func:atualizar@b.php'], $ids);
        $this->assertStringContainsString('1 symbols are declared in more than one file', $result['warnings'][0]);
    }

    public function test_chamada_resolve_para_a_declaracao_do_proprio_arquivo(): void
    {
        $result = $this->disambiguate(
            [
                '/app/a.php' => [$this->freeFunction('atualizar')],
                '/app/b.php' => [$this->freeFunction('atualizar')],
            ],
            [
                // a.php chama atualizar(): so pode ser a sua propria.
                '/app/a.php' => [new Edge('file:a.php', 'func:atualizar', 'atualizar', 'function_call')],
                '/app/b.php' => [],
            ]
        );

        $calls = $this->functionCalls($result['edges']);

        $this->assertCount(1, $calls);
        $this->assertSame('func:atualizar@a.php', $calls[0]->to());
    }

    public function test_include_desambigua_a_chamada(): void
    {
        $result = $this->disambiguate(
            [
                '/app/lib1.php' => [$this->freeFunction('atualizar')],
                '/app/lib2.php' => [$this->freeFunction('atualizar')],
                '/app/pagina.php' => [$this->script('pagina.php')],
            ],
            [
                '/app/lib1.php' => [],
                '/app/lib2.php' => [],
                // pagina.php carrega apenas lib2: e essa a funcao que ela chama.
                '/app/pagina.php' => [
                    new Edge('file:pagina.php', 'file:lib2.php', 'require', 'include'),
                    new Edge('file:pagina.php', 'func:atualizar', 'atualizar', 'function_call'),
                ],
            ]
        );

        $calls = $this->functionCalls($result['edges']);

        $this->assertCount(1, $calls);
        $this->assertSame('func:atualizar@lib2.php', $calls[0]->to());
        $this->assertCount(1, $result['warnings'], 'Nada ficou ambiguo');
    }

    public function test_include_transitivo_tambem_desambigua(): void
    {
        $result = $this->disambiguate(
            [
                '/app/lib1.php' => [$this->freeFunction('atualizar')],
                '/app/lib2.php' => [$this->freeFunction('atualizar')],
                '/app/cabecalho.php' => [$this->script('cabecalho.php')],
                '/app/pagina.php' => [$this->script('pagina.php')],
            ],
            [
                '/app/lib1.php' => [],
                '/app/lib2.php' => [],
                '/app/cabecalho.php' => [new Edge('file:cabecalho.php', 'file:lib1.php', 'require', 'include')],
                '/app/pagina.php' => [
                    new Edge('file:pagina.php', 'file:cabecalho.php', 'require', 'include'),
                    new Edge('file:pagina.php', 'func:atualizar', 'atualizar', 'function_call'),
                ],
            ]
        );

        $calls = $this->functionCalls($result['edges']);

        $this->assertSame('func:atualizar@lib1.php', $calls[0]->to());
    }

    public function test_chamada_ambigua_mantem_aresta_para_todas_as_candidatas(): void
    {
        // pagina.php nao carrega nenhuma das duas (include dinamico, por exemplo).
        // Perder a aresta faria as duas funcoes parecerem mortas, e um falso "codigo
        // morto" custa mais caro que um fan-in inflado.
        $result = $this->disambiguate(
            [
                '/app/lib1.php' => [$this->freeFunction('atualizar')],
                '/app/lib2.php' => [$this->freeFunction('atualizar')],
                '/app/pagina.php' => [$this->script('pagina.php')],
            ],
            [
                '/app/lib1.php' => [],
                '/app/lib2.php' => [],
                '/app/pagina.php' => [new Edge('file:pagina.php', 'func:atualizar', 'atualizar', 'function_call')],
            ]
        );

        $targets = array_map(fn(Edge $e): string => $e->to(), $this->functionCalls($result['edges']));
        sort($targets);

        $this->assertSame(['func:atualizar@lib1.php', 'func:atualizar@lib2.php'], $targets);
        $this->assertStringContainsString('could not be resolved', $result['warnings'][1]);
    }

    public function test_classes_homonimas_sem_namespace_tambem_sao_separadas(): void
    {
        $result = $this->disambiguate(
            [
                '/app/v1/Relatorio.php' => [new Node('Relatorio', 'gerar', __FILE__, 5)],
                '/app/v2/Relatorio.php' => [new Node('Relatorio', 'gerar', __FILE__, 5)],
            ],
            ['/app/v1/Relatorio.php' => [], '/app/v2/Relatorio.php' => []]
        );

        $ids = array_map(fn(Node $n): string => $n->id(), $result['nodes']);
        sort($ids);

        // O qualificador entra do lado da classe: explode('::') segue dando o metodo limpo.
        $this->assertSame(
            ['Relatorio@v1/Relatorio.php::gerar', 'Relatorio@v2/Relatorio.php::gerar'],
            $ids
        );
    }
}
