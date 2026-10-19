<?php

namespace FlowEngine\Infrastructure\Cache;

final class AnalysisSignature
{
    public static function compute(string $runtimeContext = ''): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $files = self::phpFiles($projectRoot . DIRECTORY_SEPARATOR . 'src');

        foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.php'] as $relativePath) {
            $path = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        sort($files);
        $hash = hash_init('sha256');
        hash_update($hash, 'runtime-context:' . $runtimeContext);
        foreach ($files as $file) {
            hash_update($hash, ContentFingerprint::file($file));
        }

        return hash_final($hash);
    }

    /** @return string[] */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf('Analysis source directory does not exist: %s', $directory));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
