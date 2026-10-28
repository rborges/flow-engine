<?php

namespace FlowEngine\Application\AppMap;

use FlowEngine\Infrastructure\Analyzer\ProjectRelativePath;

/**
 * URLs em arquivos de configuração fora do scan: `.env` de exemplo, `config/*.php` do Laravel e `.ini`.
 *
 * Emite só esquema, host e porta: caminho e query de configuração podem carregar segredo (webhook, token).
 * Nunca lê `.env` real.
 */
final class ConfigUrlDetector
{
    // Pastas ocultas também ficam de fora: `.git`, `.worktrees`, `.claude/worktrees`, `.venv`.
    private const SKIP_DIRS = [
        'vendor', 'node_modules', 'venv', '__pycache__', 'dist', 'build', 'coverage', 'storage', 'cache', 'tmp',
        'test', 'tests',
    ];

    private const ENV_EXAMPLE = '/^\.env(?:\.[\w-]+)?\.(?:example|dist|sample)$/i';
    // Por palavra da chave: `PASSPORT_SERVER` e `KEYCLOAK_BASE` não são segredo; `ACCESS_KEY_ID` e `APIKEY` são.
    private const SECRET_WORD = '/^(?:PASS|CREDENTIALS?|\w*(?:KEY|SECRET|TOKEN|PASSWORD|PASSWD|PWD))$/';
    private const ADDRESS_WORDS = ['URL', 'URI', 'ENDPOINT', 'HOST'];
    // Vírgula separa listas (`CORS_ORIGINS=https://a,https://b`).
    private const URL = '/https?:\/\/[^\s\'"<>,]+/i';

    private CommentStripper $commentStripper;
    private UrlClassifier $urlClassifier;

    public function __construct()
    {
        $this->commentStripper = new CommentStripper();
        $this->urlClassifier = new UrlClassifier();
    }

    /**
     * @param string[] $readFiles Arquivos que o detector de código já leu; não são lidos de novo.
     *
     * @return IntegrationCall[]
     */
    public function detect(string $projectRoot, array $readFiles): array
    {
        $root = rtrim($projectRoot, '/\\');
        if (!is_dir($root)) {
            return [];
        }

        $read = [];
        foreach ($readFiles as $file) {
            $read[strtolower(ProjectRelativePath::for($file, $root))] = true;
        }

        $calls = [];
        foreach ($this->configFiles($root) as [$file, $relative, $format]) {
            if (!isset($read[strtolower($relative)])) {
                array_push($calls, ...$this->detectInFile($file, $relative, $format));
            }
        }

        return $calls;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> [arquivo, caminho relativo, formato]
     */
    private function configFiles(string $root): array
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $entry): bool => !$entry->isDir() || $this->descends($entry)
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $files = [];
        foreach ($entries as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $dir = $entry->getPath();
            $extension = strtolower($entry->getExtension());
            $format = match (true) {
                preg_match(self::ENV_EXAMPLE, $entry->getFilename()) === 1 => 'env',
                $extension === 'ini' => 'ini',
                $extension === 'php' && strtolower(basename($dir)) === 'config'
                    && is_file(dirname($dir) . DIRECTORY_SEPARATOR . 'artisan') => 'php',
                default => null,
            };
            if ($format !== null) {
                $files[] = [$entry->getPathname(), ProjectRelativePath::for($entry->getPathname(), $root), $format];
            }
        }

        // Pelo caminho relativo com `/`: a mesma ordem no Windows e no Linux.
        usort($files, fn(array $a, array $b): int => strcmp($a[1], $b[1]));

        return $files;
    }

    /**
     * @return IntegrationCall[]
     */
    private function detectInFile(string $file, string $relative, string $format): array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        if ($format === 'php') {
            $content = $this->commentStripper->strip($content, 'php');
        }

        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (!is_array($lines)) {
            return [];
        }

        $calls = [];
        foreach ($lines as $idx => $line) {
            $trimmed = ltrim($line);
            $commented = $trimmed !== '' && ($trimmed[0] === '#' || ($format === 'ini' && $trimmed[0] === ';'));
            // No `.ini` comentário é anotação; no `.env` de exemplo costuma ser o valor de outro ambiente.
            if ($commented && $format !== 'env') {
                continue;
            }

            $key = $this->key($commented ? ltrim($trimmed, "# \t") : $trimmed, $format);
            if ($key !== null && $this->isSecret($key)) {
                continue;
            }

            if (!preg_match_all(self::URL, $line, $matches)) {
                continue;
            }

            foreach ($matches[0] as $url) {
                $parts = parse_url(rtrim((string) $url, ').;'));
                $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
                // `https://${APP_HOST}`: host montado por interpolação não é endereço.
                if ($host === '' || preg_match('/[$\{\}%]/', $host) === 1) {
                    continue;
                }

                $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
                $port = (int) ($parts['port'] ?? 0);
                $metadata = [
                    'scheme' => $scheme,
                    'host' => $host,
                    'port' => $port,
                    'path' => '',
                    'query' => '',
                    'kind' => $this->urlClassifier->kind($host, (string) ($parts['path'] ?? '')),
                    'source' => 'config',
                ];
                if ($key !== null) {
                    $metadata['configKey'] = $key;
                }
                if ($commented) {
                    $metadata['commented'] = true;
                }

                $calls[] = new IntegrationCall(
                    type: 'http',
                    fromNodeId: 'file:' . $relative,
                    fromFile: $file,
                    fromLine: $idx + 1,
                    target: $scheme . '://' . $host . ($port !== 0 ? ':' . $port : ''),
                    resolvedPath: null,
                    metadata: $metadata
                );
            }
        }

        return $calls;
    }

    /**
     * Nome da chave da linha: `X=` no `.env`, `x =` no `.ini`, `'x' =>` no `config/*.php`; a variável de
     * `env('X', ...)` vence a chave do array, porque é ela que diz o que o valor é.
     */
    private function key(string $line, string $format): ?string
    {
        if ($format === 'php') {
            if (preg_match('/\benv\s*\(\s*[\'"]([A-Za-z_][\w.]*)[\'"]/', $line, $m)) {
                return $m[1];
            }

            return preg_match('/^[\'"]([^\'"]+)[\'"]\s*=>/', $line, $m) ? $m[1] : null;
        }

        return preg_match('/^(?:export\s+)?([A-Za-z_][\w.-]*)\s*=/', $line, $m) ? $m[1] : null;
    }

    private function descends(\SplFileInfo $dir): bool
    {
        $name = $dir->getFilename();

        return !$dir->isLink() && !str_starts_with($name, '.') && !in_array(strtolower($name), self::SKIP_DIRS, true);
    }

    private function isSecret(string $key): bool
    {
        $words = preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map('strtoupper', $words);

        return array_intersect($words, self::ADDRESS_WORDS) === []
            && preg_grep(self::SECRET_WORD, $words) !== [];
    }
}
