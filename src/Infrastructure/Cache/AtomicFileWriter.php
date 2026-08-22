<?php

namespace FlowEngine\Infrastructure\Cache;

final class AtomicFileWriter
{
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        self::ensurePrivateDirectory($directory);

        $temporary = tempnam($directory, '.' . basename($path) . '.tmp-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Failed to create temporary file for: %s', $path));
        }

        try {
            if (!chmod($temporary, 0600)) {
                throw new \RuntimeException(sprintf('Failed to secure temporary file: %s', $temporary));
            }

            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Failed to open temporary file: %s', $temporary));
            }

            try {
                $offset = 0;
                $length = strlen($contents);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($contents, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException(sprintf('Failed to write temporary file: %s', $temporary));
                    }
                    $offset += $written;
                }

                if (!fflush($handle)) {
                    throw new \RuntimeException(sprintf('Failed to flush temporary file: %s', $temporary));
                }
                if (function_exists('fsync') && !fsync($handle)) {
                    throw new \RuntimeException(sprintf('Failed to sync temporary file: %s', $temporary));
                }
            } finally {
                fclose($handle);
            }

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Failed to publish file atomically: %s', $path));
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function ensurePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create state directory: %s', $directory));
        }

        if (!chmod($directory, 0700)) {
            throw new \RuntimeException(sprintf('Failed to secure state directory: %s', $directory));
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(sprintf('State directory is not writable: %s', $directory));
        }
    }
}
