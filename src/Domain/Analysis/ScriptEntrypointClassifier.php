<?php

declare(strict_types=1);

namespace FlowEngine\Domain\Analysis;

use FlowEngine\Domain\Flow\Node;

/**
 * Classifica um nó-arquivo (script) como entrypoint HTTP ou CLI.
 *
 * Num app PHP servido por Apache/nginx, todo .php alcançável pela web é um
 * entrypoint HTTP: o usuário digita a URL e o script roda. Scripts sob diretórios
 * de linha de comando (bin/, cron/, ...) são entrypoints CLI.
 *
 * Existe para que os dois classificadores de entrypoint — o do map e o do CLI —
 * não divirjam neste ponto, como já divergiram em outros.
 */
final class ScriptEntrypointClassifier
{
    /** Diretórios convencionais de scripts executados fora do servidor web. */
    private const CLI_DIRECTORIES = ['bin/', 'cli/', 'console/', 'cron/', 'jobs/', 'scripts/'];

    public static function typeFor(Node $node): string
    {
        // Em nós de arquivo, class() é o caminho relativo à raiz do projeto.
        $path = ltrim(str_replace('\\', '/', $node->class()), './');

        foreach (self::CLI_DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory)) {
                return 'cli';
            }
        }

        return 'http';
    }
}
