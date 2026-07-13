<?php

namespace FlowEngine\Infrastructure\Repository;

use FlowEngine\Application\DTO\SymbolDTO;
use FlowEngine\Domain\Contracts\FlowRepository;
use FlowEngine\Domain\Contracts\Flow as FlowContract;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\SymbolIndex;

use FlowEngine\Domain\Contracts\ProjectContext;
use FlowEngine\Domain\Flow\Edge;
use FlowEngine\Domain\Flow\Node;
use FlowEngine\Infrastructure\Analyzer\ProjectScanner;
use FlowEngine\Infrastructure\Analyzer\FileParser;
use FlowEngine\Infrastructure\Analyzer\FlowBuilder;
use FlowEngine\Infrastructure\Analyzer\CrossLanguageEdgeDetector;
use FlowEngine\Infrastructure\Analyzer\NodeIdDisambiguator;
use FlowEngine\Infrastructure\Analyzer\SourceFileGuard;
use FlowEngine\Infrastructure\Analyzer\ProjectRelativePath;
use FlowEngine\Infrastructure\Cache\FlowCache;
use FlowEngine\Infrastructure\Cache\PerFileCache;

final class AstFlowRepository implements FlowRepository
{
    private ?Flow $flow = null;
    private ?string $cacheHash = null;

    /** @var string[] */
    private array $duplicateIds = [];

    /** @var string[] Avisos estruturais: arquivos ignorados, colisões de ID, chamadas ambíguas. */
    private array $analysisWarnings = [];

    public function __construct(
        private ProjectScanner $scanner,
        private FileParser $parser,
        private FlowBuilder $builder,
        private ProjectContext $context,
        private ?FlowCache $cache = null,
        private ?CrossLanguageEdgeDetector $crossLanguageDetector = null,
        private ?PerFileCache $perFileCache = null
    ) {
    }

    /**
     * @api 
     */
    public function analyze(): void
    {
        if ($this->flow !== null) {
            return;
        }

        $this->context->boot();

        $files = $this->scanner->scan($this->context);

        $configPath = $this->context->rootPath() . DIRECTORY_SEPARATOR . 'flow-engine.json';

        if ($this->cache && $this->cache->isValid($files, $configPath)) {
            $this->flow = $this->cache->loadFlow();
            $this->cacheHash = $this->cache->computeHash($files, $configPath);
            $this->duplicateIds = $this->cache->loadDuplicateIds();
            $this->analysisWarnings = $this->cache->loadAnalysisWarnings();
            return;
        }

        // Ordem estável: sem isso, qual declaração homônima sobrevive depende da ordem
        // de iteração do sistema de arquivos, e o grafo varia entre máquinas.
        sort($files);

        $cached = $this->perFileCache?->load() ?? [];
        $newMap  = [];
        $nodesByFile = [];
        $edgesByFile = [];
        $symbols = [];
        $parserSignature = $this->parserSignature();
        $guard = new SourceFileGuard();
        $skipped = [];

        foreach ($files as $file) {
            // Bundles minificados não têm valor analítico e estouram a memória do
            // parser. Melhor pular e dizer que pulou do que morrer com fatal error.
            $skipReason = $guard->skipReason($file);

            if ($skipReason !== null) {
                $skipped[] = ProjectRelativePath::for($file, $this->context->rootPath())
                    . ' (' . $skipReason . ')';
                continue;
            }

            $fp = PerFileCache::fingerprint($file) . '|parser:' . $parserSignature;

            if (isset($cached[$file]) && $cached[$file]['fp'] === $fp) {
                $entry = $cached[$file];
            } else {
                $ast      = $this->parser->parse($file);
                $rawNodes = array_map(fn(Node $n) => [
                    'class'  => $n->class(),
                    'method' => $n->method(),
                    'file'   => $n->file(),
                    'line'   => $n->line(),
                    'lang'   => $n->language(),
                    'meta'   => $n->metadata(),
                    'type'   => $n->type(),
                ], $ast['nodes'] ?? []);
                $rawEdges = array_map(fn(Edge $e) => [
                    'from'   => $e->from(),
                    'to'     => $e->to(),
                    'method' => $e->method(),
                    'type'   => $e->type(),
                ], $ast['edges'] ?? []);
                $rawSymbols = array_map(fn(SymbolDTO $s) => $s->toArray(), $ast['symbols'] ?? []);
                $entry = ['fp' => $fp, 'nodes' => $rawNodes, 'edges' => $rawEdges, 'symbols' => $rawSymbols];
            }

            $newMap[$file] = $entry;

            $nodesByFile[$file] = [];
            $edgesByFile[$file] = [];

            foreach ($entry['nodes'] as $nd) {
                $nodesByFile[$file][] = new Node(
                    $nd['class'],
                    $nd['method'],
                    $nd['file'],
                    $nd['line'],
                    $nd['lang'],
                    $nd['meta'] ?? null,
                    $nd['type'] ?? Node::TYPE_METHOD
                );
            }
            foreach ($entry['edges'] as $ed) {
                $edgesByFile[$file][] = new Edge($ed['from'], $ed['to'], $ed['method'], $ed['type']);
            }
            foreach ($entry['symbols'] ?? [] as $sd) {
                $symbols[] = new SymbolDTO(
                    $sd['id'],
                    $sd['name'],
                    $sd['kind'],
                    $sd['file'],
                    $sd['line'],
                    $sd['sourceModule'] ?? null
                );
            }
        }

        $this->perFileCache?->save($newMap);

        // Símbolos globais homônimos em arquivos diferentes precisam ser separados
        // ANTES do build; senão o FlowBuilder funde os dois num nó só.
        $disambiguated = (new NodeIdDisambiguator())->apply(
            $nodesByFile,
            $edgesByFile,
            $this->context->rootPath()
        );

        $nodes = $disambiguated['nodes'];
        $edges = $disambiguated['edges'];
        $this->analysisWarnings = array_merge(
            $this->skippedFilesWarning($skipped),
            $disambiguated['warnings']
        );

        $nodes = $this->materializeIncludedFiles($nodes, $edges, $files);

        if ($this->crossLanguageDetector !== null) {
            $edges = $this->crossLanguageDetector->detect($nodes, $edges);
        }

        $symbolIndex = new SymbolIndex($symbols);
        $this->flow = $this->builder->build($nodes, $edges, $symbolIndex);
        $this->duplicateIds = $this->builder->lastDuplicateIds();

        if ($this->cache) {
            $this->cache->saveFlow(
                $this->flow,
                $files,
                $configPath,
                $this->duplicateIds,
                $this->analysisWarnings
            );
            $this->cacheHash = $this->cache->computeHash($files, $configPath);
        }
    }

    /**
     * Warnings estruturais: arquivos ignorados, símbolos homônimos, chamadas ambíguas.
     *
     * @api
     * @return string[]
     */
    public function analysisWarnings(): array
    {
        return $this->analysisWarnings;
    }

    /**
     * @param string[] $skipped
     * @return string[]
     */
    private function skippedFilesWarning(array $skipped): array
    {
        if ($skipped === []) {
            return [];
        }

        $sample = array_slice($skipped, 0, 5);
        $more = count($skipped) > 5 ? sprintf(' and %d more', count($skipped) - 5) : '';

        return [sprintf(
            '%d files were skipped as generated or minified and are absent from the graph: %s%s.',
            count($skipped),
            implode(', ', $sample),
            $more
        )];
    }

    /**
     * Cria o nó-arquivo dos alvos de include que ainda não têm um.
     *
     * O parser só emite nó-arquivo para scripts (código executável de topo), mas
     * `require 'classes/AuthModel.php'` aponta para um arquivo de declaração pura.
     * Sem este passo a aresta apontaria para o vazio — e a dependência real de um
     * script sobre o arquivo que ele carrega se perderia.
     *
     * Só materializa arquivos que estão no escopo do scan: includes de vendor ou
     * de fora do projeto continuam sem nó, e o FlowBuilder descarta suas arestas.
     *
     * @param Node[]   $nodes
     * @param Edge[]   $edges
     * @param string[] $files Arquivos escaneados (caminhos absolutos)
     * @return Node[]
     */
    private function materializeIncludedFiles(array $nodes, array $edges, array $files): array
    {
        $existing = [];
        foreach ($nodes as $node) {
            $existing[$node->id()] = true;
        }

        $root = $this->context->rootPath();
        $scanned = [];
        foreach ($files as $file) {
            $scanned['file:' . ProjectRelativePath::for($file, $root)] = $file;
        }

        $created = [];
        foreach ($edges as $edge) {
            if ($edge->type() !== 'include') {
                continue;
            }

            $target = $edge->to();

            if (isset($existing[$target]) || isset($created[$target]) || !isset($scanned[$target])) {
                continue;
            }

            $created[$target] = new Node(
                substr($target, strlen('file:')),
                '__file',
                $scanned[$target],
                null,
                'php',
                null,
                Node::TYPE_FILE
            );
        }

        return array_merge($nodes, array_values($created));
    }

    private function parserSignature(): string
    {
        $analyzerDir = realpath(__DIR__ . '/../Analyzer');
        if ($analyzerDir === false) {
            return 'no-analyzer-dir';
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($analyzerDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $entry->getPathname();
        }

        sort($files);
        $hash = hash_init('sha1');
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            hash_update($hash, $file . '|' . filemtime($file) . '|' . filesize($file));
        }

        return hash_final($hash);
    }

    public function getNodes(): array
    {
        $this->ensureAnalyzed();
        return $this->flow->nodes();
    }

    public function getNode(string $id): Node
    {
        $this->ensureAnalyzed();

        $node = $this->flow->node($id);

        if (!$node) {
            throw new \LogicException("Node {$id} not found");
        }

        return $node;
    }

    public function findNode(string $id): ?Node
    {
        $this->ensureAnalyzed();
        return $this->flow->node($id);
    }

    private function ensureAnalyzed(): void
    {
        if ($this->flow === null) {
            $this->analyze();
        }
    }

    public function getFlow(): FlowContract
    {
        $this->ensureAnalyzed();
        return $this->flow;
    }

    public function cacheHash(): ?string
    {
        return $this->cacheHash;
    }

    /**
     * @api
     * @return string[]
     */
    public function duplicateIds(): array
    {
        $this->ensureAnalyzed();
        return $this->duplicateIds;
    }
}
