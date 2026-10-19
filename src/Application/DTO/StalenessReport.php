<?php

namespace FlowEngine\Application\DTO;

/** @api */
final readonly class StalenessReport
{
    private const FILE_EXAMPLE_LIMIT = 20;
    private const DIRECTORY_EXAMPLE_LIMIT = 10;

    /**
     * @param string[] $changedFiles
     * @param string[] $newFiles
     * @param string[] $deletedFiles
     */
    public function __construct(
        public bool $stale,
        public array $changedFiles,
        public array $newFiles,
        public array $deletedFiles,
        public int $totalChanged,
        public bool $configChanged = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $changedFiles = array_slice($this->changedFiles, 0, self::FILE_EXAMPLE_LIMIT);
        $newFiles = array_slice($this->newFiles, 0, self::FILE_EXAMPLE_LIMIT);
        $deletedFiles = array_slice($this->deletedFiles, 0, self::FILE_EXAMPLE_LIMIT);
        $shown = count($changedFiles) + count($newFiles) + count($deletedFiles) + ($this->configChanged ? 1 : 0);

        return [
            'stale' => $this->stale,
            'counts' => [
                'changed' => count($this->changedFiles),
                'new' => count($this->newFiles),
                'deleted' => count($this->deletedFiles),
                'configuration' => $this->configChanged ? 1 : 0,
            ],
            'changedFiles' => $changedFiles,
            'newFiles' => $newFiles,
            'deletedFiles' => $deletedFiles,
            'totalChanged' => $this->totalChanged,
            'configChanged' => $this->configChanged,
            'truncated' => $shown < $this->totalChanged,
            'directories' => $this->directorySummary(),
        ];
    }

    public function summaryWarning(): string
    {
        if (!$this->stale) {
            return '';
        }

        $all = array_merge($this->changedFiles, $this->newFiles, $this->deletedFiles);
        if ($this->configChanged) {
            $all[] = 'flow-engine.json';
        }
        $total = $this->totalChanged;
        $shown = array_slice($all, 0, 5);
        $suffix = $total > 5 ? sprintf(' (and %d more)', $total - 5) : '';

        return sprintf(
            '%d project input%s changed since the previous cache: %s%s. Cache refreshed automatically before producing these results.',
            $total,
            $total === 1 ? '' : 's',
            implode(', ', array_map('basename', $shown)),
            $suffix
        );
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array{directory: string, count: int}>
     */
    private function directorySummary(): array
    {
        $counts = [];
        foreach (array_merge($this->changedFiles, $this->newFiles, $this->deletedFiles) as $file) {
            $dir = str_replace('\\', '/', dirname($file));
            if ($dir === '.' || $dir === '') {
                $dir = '[root]';
            }
            $counts[$dir] = ($counts[$dir] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];
        foreach (array_slice($counts, 0, self::DIRECTORY_EXAMPLE_LIMIT, true) as $directory => $count) {
            $result[] = [
                'directory' => $directory,
                'count' => $count,
            ];
        }

        return $result;
    }
}
