<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;

/**
 * Separa símbolos globais homônimos declarados em arquivos diferentes.
 *
 * Em PHP sem namespace é comum dois arquivos declararem `atualizar()` ou
 * `validaCPF()`. Eles nunca são carregados juntos, então são funções distintas —
 * mas o ID `func:atualizar` é o mesmo para as duas, e o FlowBuilder acabava
 * fundindo dois grafos de chamada sem relação num único nó, com fan-in somado e
 * raio de alcance sem sentido. Pior: qual das duas sobrevivia dependia da ordem
 * de iteração do sistema de arquivos, ou seja, variava entre máquinas.
 *
 * Aqui os nós em colisão ganham um qualificador de arquivo, e cada chamada é
 * religada à declaração correta:
 *
 *   1. mesma declaração no arquivo que chama — o caso mais comum;
 *   2. senão, o fechamento transitivo dos include/require do arquivo que chama:
 *      só se pode chamar aquilo que se carrega;
 *   3. se ainda assim restar ambiguidade, a aresta é mantida para TODAS as
 *      candidatas. É uma superaproximação deliberada: perder a aresta faria a
 *      função parecer morta, e um falso "código morto" é bem mais caro que um
 *      fan-in um pouco inflado.
 *
 * @internal
 */
final class NodeIdDisambiguator
{
    /**
     * @param array<string, Node[]> $nodesByFile Arquivo absoluto => nós declarados nele
     * @param array<string, Edge[]> $edgesByFile Arquivo absoluto => arestas emitidas nele
     * @return array{nodes: Node[], edges: Edge[], warnings: string[]}
     */
    public function apply(array $nodesByFile, array $edgesByFile, string $projectRoot): array
    {
        $relativeOf = [];
        foreach (array_keys($nodesByFile + $edgesByFile) as $file) {
            $relativeOf[$file] = ProjectRelativePath::for($file, $projectRoot);
        }

        $collisions = $this->findCollisions($nodesByFile, $relativeOf);

        if ($collisions === []) {
            return [
                'nodes'    => $this->flatten($nodesByFile),
                'edges'    => $this->flatten($edgesByFile),
                'warnings' => [],
            ];
        }

        // ID base => (arquivo relativo => ID qualificado)
        $qualifiedIds = [];
        $nodes = [];

        foreach ($nodesByFile as $file => $fileNodes) {
            foreach ($fileNodes as $node) {
                $baseId = $node->id();

                if (!isset($collisions[$baseId])) {
                    $nodes[] = $node;
                    continue;
                }

                $qualified = $node->withQualifier($relativeOf[$file]);
                $qualifiedIds[$baseId][$relativeOf[$file]] = $qualified->id();
                $nodes[] = $qualified;
            }
        }

        $includes = $this->includeGraph($edgesByFile);
        $ambiguous = 0;
        $edges = [];

        foreach ($edgesByFile as $file => $fileEdges) {
            $from = $relativeOf[$file];

            foreach ($fileEdges as $edge) {
                $sources = $this->resolve($edge->from(), $from, $qualifiedIds, $includes, $ambiguous);
                $targets = $this->resolve($edge->to(), $from, $qualifiedIds, $includes, $ambiguous);

                foreach ($sources as $source) {
                    foreach ($targets as $target) {
                        $edges[] = new Edge($source, $target, $edge->method(), $edge->type());
                    }
                }
            }
        }

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'warnings' => $this->warnings($collisions, $ambiguous),
        ];
    }

    /**
     * IDs declarados em mais de um arquivo.
     *
     * Duplicatas dentro do MESMO arquivo (sobrecargas de assinatura, por exemplo)
     * não são colisão e seguem com o FlowBuilder.
     *
     * @param array<string, Node[]> $nodesByFile
     * @param array<string, string> $relativeOf
     * @return array<string, string[]> ID base => arquivos relativos que o declaram
     */
    private function findCollisions(array $nodesByFile, array $relativeOf): array
    {
        $declaredIn = [];

        foreach ($nodesByFile as $file => $fileNodes) {
            foreach ($fileNodes as $node) {
                // Nós de arquivo já são identificados pelo caminho: não colidem.
                if ($node->type() === Node::TYPE_FILE) {
                    continue;
                }

                $declaredIn[$node->id()][$relativeOf[$file]] = true;
            }
        }

        $collisions = [];

        foreach ($declaredIn as $id => $files) {
            if (count($files) > 1) {
                $collisions[$id] = array_keys($files);
            }
        }

        ksort($collisions);

        return $collisions;
    }

    /**
     * Grafo de inclusão: arquivo relativo => arquivos relativos que ele carrega.
     *
     * @param array<string, Edge[]> $edgesByFile
     * @return array<string, string[]>
     */
    private function includeGraph(array $edgesByFile): array
    {
        $graph = [];

        foreach ($edgesByFile as $fileEdges) {
            foreach ($fileEdges as $edge) {
                if ($edge->type() !== 'include') {
                    continue;
                }

                $from = $this->filePathFromId($edge->from());
                $to = $this->filePathFromId($edge->to());

                if ($from !== null && $to !== null) {
                    $graph[$from][] = $to;
                }
            }
        }

        return $graph;
    }

    private function filePathFromId(string $id): ?string
    {
        return str_starts_with($id, 'file:') ? substr($id, strlen('file:')) : null;
    }

    /**
     * Endpoints possíveis para um ID, do ponto de vista do arquivo que chama.
     *
     * @param array<string, array<string, string>> $qualifiedIds
     * @param array<string, string[]>              $includes
     * @return string[]
     */
    private function resolve(
        string $id,
        string $callerFile,
        array $qualifiedIds,
        array $includes,
        int &$ambiguous
    ): array {
        if (!isset($qualifiedIds[$id])) {
            return [$id];
        }

        $candidates = $qualifiedIds[$id];

        // 1. A declaração está no próprio arquivo que chama.
        if (isset($candidates[$callerFile])) {
            return [$candidates[$callerFile]];
        }

        // 2. Só é possível chamar o que se carrega.
        $reachable = array_intersect_key($candidates, array_flip($this->closure($callerFile, $includes)));

        if (count($reachable) === 1) {
            return array_values($reachable);
        }

        // 3. Ambíguo: manter a aresta para todas as candidatas.
        $ambiguous++;

        return array_values($candidates);
    }

    /**
     * Fechamento transitivo dos includes a partir de um arquivo.
     *
     * @param array<string, string[]> $includes
     * @return string[]
     */
    private function closure(string $file, array $includes): array
    {
        $seen = [];
        $queue = [$file];

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($includes[$current] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $queue[] = $next;
            }
        }

        return array_keys($seen);
    }

    /**
     * @param array<string, string[]> $collisions
     * @return string[]
     */
    private function warnings(array $collisions, int $ambiguous): array
    {
        $sample = array_slice(array_keys($collisions), 0, 5);
        $more = count($collisions) > 5 ? sprintf(' and %d more', count($collisions) - 5) : '';

        $warnings = [sprintf(
            '%d symbols are declared in more than one file; their node IDs were qualified by file (e.g. %s). Examples: %s%s.',
            count($collisions),
            'func:atualizar@ajax/x.php',
            implode(', ', $sample),
            $more
        )];

        if ($ambiguous > 0) {
            $warnings[] = sprintf(
                '%d call sites to those symbols could not be resolved to a single declaration through include/require; '
                . 'edges were kept to every candidate, so their fan-in is an over-approximation.',
                $ambiguous
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, array<int, mixed>> $byFile
     * @return array<int, mixed>
     */
    private function flatten(array $byFile): array
    {
        return $byFile === [] ? [] : array_merge(...array_values($byFile));
    }
}
