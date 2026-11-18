<?php

namespace FlowEngine\Application\AppMap;

/**
 * Remote and HEAD commit of the repository containing a service root.
 *
 * The analyzed repository is untrusted: git is resolved from absolute PATH entries only (Windows would otherwise run a
 * `git.exe` from the current directory), runs without a shell, and `safe.directory` is not overridden, so a repository
 * owned by someone else yields nulls.
 */
final class GitRevisionReader
{
    private const LOCATION_ENV = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_CEILING_DIRECTORIES'];

    private static string|false|null $gitBinary = null;

    public function __construct(private readonly bool $enabled = true)
    {
    }

    /** For builds whose output does not publish repository and commit. */
    public static function disabled(): self
    {
        return new self(false);
    }

    /**
     * @return array{repository: ?string, commitSha: ?string}
     */
    public function read(string $root): array
    {
        $unknown = ['repository' => null, 'commitSha' => null];
        if (!$this->enabled || !is_dir($root)) {
            return $unknown;
        }

        $sha = $this->git($root, ['rev-parse', '--verify', '--quiet', 'HEAD']);
        if ($sha === null || preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $sha) !== 1) {
            return $unknown;
        }

        $remote = $this->git($root, ['remote', 'get-url', 'origin']);

        return [
            'repository' => $remote === null ? null : self::withoutCredentials($remote),
            'commitSha' => $sha,
        ];
    }

    public static function withoutCredentials(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#]*@#i', '$1', $url);
    }

    /**
     * @param string[] $args
     */
    private function git(string $root, array $args): ?string
    {
        $binary = self::gitBinary();
        if ($binary === null) {
            return null;
        }

        $env = getenv();
        foreach (self::LOCATION_ENV as $name) {
            unset($env[$name]);
        }
        $env['GIT_TERMINAL_PROMPT'] = '0';

        $process = @proc_open(
            [$binary, '-C', $root, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $out !== '' ? $out : null;
    }

    private static function gitBinary(): ?string
    {
        if (self::$gitBinary === null) {
            self::$gitBinary = false;
            $windows = PHP_OS_FAMILY === 'Windows';
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                $dir = trim($dir, " \"");
                $absolute = $windows ? preg_match('#^([a-z]:[\\\\/]|\\\\\\\\)#i', $dir) === 1 : str_starts_with($dir, '/');
                if (!$absolute) {
                    continue;
                }
                $candidate = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . ($windows ? 'git.exe' : 'git');
                if (is_file($candidate) && ($windows || is_executable($candidate))) {
                    self::$gitBinary = $candidate;
                    break;
                }
            }
        }

        return self::$gitBinary === false ? null : self::$gitBinary;
    }
}
