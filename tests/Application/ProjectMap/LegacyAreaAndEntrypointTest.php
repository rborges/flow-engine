<?php

declare(strict_types=1);

namespace Tests\Application\ProjectMap;

use FlowEngine\Application\ProjectMap\ProjectMapBuilder;
use FlowEngine\Domain\Analysis\MetricsAnalyzer;
use FlowEngine\Domain\Analysis\OrphanCodeDetector;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

/**
 * Como o mapa apresenta PHP legado: áreas, entrypoints e órfãos.
 *
 * Sem namespace, a "área" de um nó era o próprio nome da classe — o mapa virava
 * uma lista de uma área por classe, inútil para se orientar. E páginas (.php na
 * raiz web) eram descartadas dos entrypoints e reportadas como órfãs, quando são
 * exatamente o oposto: o ponto de entrada do sistema.
 */
final class LegacyAreaAndEntrypointTest extends TestCase
{
    private ProjectMapBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ProjectMapBuilder();
    }

    private function legacyMethod(string $class, string $method, string $relPath): Node
    {
        return new Node($class, $method, __FILE__, 10, 'php', ['relPath' => $relPath]);
    }

    private function script(string $relPath): Node
    {
        return new Node($relPath, '__file', __FILE__, null, 'php', null, Node::TYPE_FILE);
    }

    public function test_area_de_classe_sem_namespace_vem_do_diretorio(): void
    {
        $node = $this->legacyMethod('AuthModel', 'validarLogin', 'Models/AuthModel.php');

        $this->assertSame('Models', $this->builder->areaNameForNode($node));
    }

    public function test_area_de_script_vem_do_diretorio(): void
    {
        $this->assertSame('ajax', $this->builder->areaNameForNode($this->script('ajax/busca.php')));
    }

    public function test_script_na_raiz_cai_na_area_root(): void
    {
        $this->assertSame('(root)', $this->builder->areaNameForNode($this->script('dashboard.php')));
    }

    public function test_area_com_namespace_continua_vindo_do_namespace(): void
    {
        $node = new Node('App\\Http\\UserController', 'index', __FILE__, 10);

        $this->assertSame('App\Http', $this->builder->areaNameForNode($node));
    }

    public function test_script_web_e_entrypoint_http(): void
    {
        $this->assertSame('http', $this->builder->classifyEntrypoint($this->script('dashboard.php')));
        $this->assertSame('http', $this->builder->classifyEntrypoint($this->script('ajax/busca.php')));
    }

    public function test_script_em_bin_e_entrypoint_cli(): void
    {
        $this->assertSame('cli', $this->builder->classifyEntrypoint($this->script('bin/importar.php')));
        $this->assertSame('cli', $this->builder->classifyEntrypoint($this->script('cron/limpeza.php')));
    }

    public function test_pagina_aparece_entre_os_entrypoints_acionaveis(): void
    {
        $flow = new Flow([$this->script('dashboard.php')], []);

        $ids = array_map(
            fn(Node $n): string => $n->id(),
            $this->builder->actionableEntrypoints($flow)
        );

        $this->assertSame(['file:dashboard.php'], $ids);
    }

    public function test_pagina_sem_quem_a_inclua_nao_e_orfa(): void
    {
        // Fan-in zero é o estado normal de uma pagina: ela e alcancada pela URL.
        $flow = new Flow([$this->script('dashboard.php')], []);
        $detector = new OrphanCodeDetector($flow, new MetricsAnalyzer($flow));

        $this->assertSame([], $detector->orphanMethods());
    }

    public function test_metodo_sem_chamador_continua_orfao(): void
    {
        $flow = new Flow([$this->legacyMethod('Relatorio', 'gerarAntigo', 'classes/Relatorio.php')], []);
        $detector = new OrphanCodeDetector($flow, new MetricsAnalyzer($flow));

        $orphans = $detector->orphanMethods();

        $this->assertCount(1, $orphans);
        $this->assertSame('Relatorio::gerarAntigo', $orphans[0]->nodeId);
    }
}
