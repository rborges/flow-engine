<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer;

/**
 * Caminho de arquivo relativo à raiz do projeto, com barras normalizadas.
 *
 * IDs de nó precisam ser estáveis entre máquinas e sistemas de arquivos, então
 * nunca podem carregar o caminho absoluto. Parser e repositório precisam derivar
 * exatamente o mesmo valor — daí este ponto único.
 *
 * Os dois lados são canonicalizados via realpath antes de comparar. Sem isso, um
 * simples confronto de prefixos falha em casos comuns — nomes curtos 8.3 no
 * Windows (RODRIG~1.BOR), symlinks, "." no meio do caminho — e o resultado
 * degrada em silêncio para o caminho absoluto, que é justamente o que se quer
 * evitar.
 *
 * @internal
 */
final class ProjectRelativePath
{
    public static function for(string $file, ?string $projectRoot): string
    {
        $normalizedFile = self::canonicalize($file);

        if ($projectRoot === null) {
            return $normalizedFile;
        }

        $root = rtrim(self::canonicalize($projectRoot), '/') . '/';

        if (str_starts_with($normalizedFile, $root)) {
            return substr($normalizedFile, strlen($root));
        }

        // Windows não diferencia maiúsculas de minúsculas em caminhos.
        if (DIRECTORY_SEPARATOR === '\\' && stripos($normalizedFile, $root) === 0) {
            return substr($normalizedFile, strlen($root));
        }

        return $normalizedFile;
    }

    private static function canonicalize(string $path): string
    {
        $real = realpath($path);

        return str_replace('\\', '/', $real !== false ? $real : $path);
    }
}
