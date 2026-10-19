<?php

namespace FlowEngine\Infrastructure\Cache;

final class ContentFingerprint
{
    public static function file(string $path, bool $includePath = true): string
    {
        if (!is_file($path)) {
            return ($includePath ? $path . '|' : '') . 'missing';
        }

        $algorithm = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $digest = hash_file($algorithm, $path);
        if ($digest === false) {
            throw new \RuntimeException(sprintf('Failed to fingerprint file: %s', $path));
        }

        $fingerprint = $algorithm . ':' . $digest;
        return $includePath ? $path . '|' . $fingerprint : $fingerprint;
    }
}
