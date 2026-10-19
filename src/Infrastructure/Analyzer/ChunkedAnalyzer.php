<?php

namespace FlowEngine\Infrastructure\Analyzer;

use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\DefaultNodeFactory;
use FlowEngine\Infrastructure\Cache\AtomicFileWriter;

/**
 * Analisa projetos grandes em chunks para controlar memória.
 * 
 * Features:
 * - Divide arquivos em chunks (ex: 100 por vez)
 * - Merge incremental de grafos
 * - Progress tracking
 * - Snapshot/resume capability
 * 
 * Uso:
 * $analyzer = new ChunkedAnalyzer($projectPath);
 * $flow = $analyzer->analyze(chunkSize: 100, onProgress: fn($p) => echo "{$p}%\n");
 */
final class ChunkedAnalyzer
{
    private string $projectPath;
    private $progressCallback = null; // callable não pode ser type-hinted como propriedade

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
    }

    /**
     * Analisa projeto em chunks.
     * 
     * @api
     * @param int $chunkSize Número de arquivos por chunk
     * @param callable|null $onProgress Callback de progresso: fn(int $percentage, int $current, int $total)
     * @return Flow
     */
    public function analyze(int $chunkSize = 100, ?callable $onProgress = null): Flow
    {
        $this->progressCallback = $onProgress;
        
        // 1. Listar todos arquivos PHP
        $phpFiles = $this->findPhpFiles($this->projectPath);
        $totalFiles = count($phpFiles);
        
        if ($totalFiles === 0) {
            return new Flow([], []);
        }
        
        // 2. Dividir em chunks
        $chunks = array_chunk($phpFiles, $chunkSize);
        $totalChunks = count($chunks);
        
        // 3. Processar chunks
        $allNodes = [];
        $allEdges = [];
        
        $astParser = new AstParser(new DefaultNodeFactory());
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNum = $chunkIndex + 1;

            // Progress callback
            $filesProcessed = min($chunkNum * $chunkSize, $totalFiles);
            $percentage = round(($filesProcessed / $totalFiles) * 100);
            
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $percentage, $filesProcessed, $totalFiles);
            }
            
            // Parse chunk
            foreach ($chunk as $file) {
                try {
                    $result = $astParser->parse($file);
                    
                    // Merge nodes (evitar duplicatas)
                    foreach ($result['nodes'] as $node) {
                        $allNodes[$node->id()] = $node;
                    }
                    
                    // Merge edges
                    $allEdges = array_merge($allEdges, $result['edges']);
                    
                } catch (\Throwable $error) {
                    throw new \RuntimeException(
                        sprintf('Failed to parse chunk file: %s', $file),
                        previous: $error,
                    );
                }
            }
            
            // Optional: Salvar snapshot a cada N chunks
            if ($chunkNum % 10 === 0) {
                $this->saveSnapshot($allNodes, $allEdges, $chunkNum, $totalChunks);
            }
            
            // Force garbage collection
            if ($chunkNum % 5 === 0) {
                gc_collect_cycles();
            }
        }
        
        // 4. Criar Flow final
        return new Flow(array_values($allNodes), $allEdges);
    }

    /**
     * Analisa de forma incremental com analyzers pesados só no final.
     * 
     * Para projetos MUITO grandes, roda analyzers pesados só depois de tudo parseado.
     */
    public function analyzeWithDeferredAnalyzers(int $chunkSize = 100, ?callable $onProgress = null): array
    {
        // 1. Parse incremental
        $flow = $this->analyze($chunkSize, $onProgress);
        
        // 2. Decidir quais analyzers rodar baseado em tamanho
        $nodeCount = $flow->nodeCount();
        $isLarge = $nodeCount > 5000;
        $isHuge = $nodeCount > 20000;
        
        $results = [
            'flow' => $flow,
            'nodeCount' => $nodeCount,
            'edgeCount' => $flow->edgeCount(),
            'analyzers' => [],
        ];
        
        // Analyzers leves (sempre rodar)
        $results['analyzers']['metrics'] = true;
        $results['analyzers']['complexity'] = true;
        $results['analyzers']['orphans'] = true;
        
        // Analyzers pesados (condicional)
        $results['analyzers']['cycles'] = !$isHuge; // Skip se > 20k nodes
        $results['analyzers']['architecture'] = !$isLarge; // Skip se > 5k nodes
        
        return $results;
    }

    /**
     * Encontra todos arquivos PHP recursivamente.
     */
    private function findPhpFiles(string $path): array
    {
        $phpFiles = [];
        
        if (!is_dir($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Chunked analysis root is not readable: %s', $path));
        }

        $directories = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            static function (\SplFileInfo $entry): bool {
                if (!$entry->isDir()) {
                    return true;
                }

                if (in_array($entry->getFilename(), ['vendor', 'node_modules'], true)) {
                    return false;
                }

                if (!is_readable($entry->getPathname())) {
                    throw new \RuntimeException(sprintf('Chunked analysis directory is not readable: %s', $entry->getPathname()));
                }

                return true;
            }
        );
        $iterator = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower((string) $file->getExtension()) === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
        
        return $phpFiles;
    }

    /**
     * Salva snapshot do progresso.
     */
    private function saveSnapshot(array $nodes, array $edges, int $chunkNum, int $totalChunks): void
    {
        $snapshotDir = sys_get_temp_dir() . '/flow-engine-snapshots';
        
        $snapshotFile = $snapshotDir . '/' . basename($this->projectPath) . "-chunk-{$chunkNum}-of-{$totalChunks}.json";
        
        $data = [
            'timestamp' => time(),
            'chunk' => $chunkNum,
            'totalChunks' => $totalChunks,
            'nodeCount' => count($nodes),
            'edgeCount' => count($edges),
            'progress' => round(($chunkNum / $totalChunks) * 100, 1),
        ];
        
        AtomicFileWriter::write(
            $snapshotFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
