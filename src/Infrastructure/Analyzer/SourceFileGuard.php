<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Analyzer;

/**
 * Recusa arquivos que não são código-fonte legível antes de tentar parseá-los.
 *
 * Bundles minificados e gerados (pdfmake.js: 2,7 MB numa linha só) não têm valor
 * analítico algum — e derrubavam o processo: o TypeScriptParser carrega o arquivo
 * inteiro e ainda o quebra num array de linhas, estourando o memory_limit padrão
 * de 128 MB com um fatal error, sem mensagem útil.
 *
 * Pular esses arquivos é melhor em todos os aspectos: o grafo não perde nada de
 * real, a análise não morre, e os arquivos ignorados são reportados em vez de
 * sumirem em silêncio.
 *
 * @internal
 */
final class SourceFileGuard
{
    /**
     * Acima disto não é código escrito à mão. O maior arquivo do próprio
     * flow-engine tem ~60 KB.
     */
    private const MAX_BYTES = 1_500_000;

    /** Uma linha maior que isto denuncia código minificado. */
    private const MAX_LINE_LENGTH = 5_000;

    /** Quanto do início do arquivo basta ler para reconhecer minificação. */
    private const PROBE_BYTES = 8_192;

    /**
     * Motivo para ignorar o arquivo, ou null se ele deve ser analisado.
     */
    public function skipReason(string $file): ?string
    {
        $name = strtolower(basename($file));

        if (preg_match('/\.(min|bundle|pack)\.(js|ts|css)$/', $name) === 1) {
            return 'minified bundle';
        }

        $size = @filesize($file);

        if ($size === false) {
            return null;
        }

        if ($size > self::MAX_BYTES) {
            return sprintf('too large (%.1f MB)', $size / 1_048_576);
        }

        if ($this->looksMinified($file)) {
            return 'generated or minified (single very long line)';
        }

        return null;
    }

    private function looksMinified(string $file): bool
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        $probe = (string) fread($handle, self::PROBE_BYTES);
        fclose($handle);

        if ($probe === '') {
            return false;
        }

        foreach (preg_split("/\r\n|\n|\r/", $probe) ?: [] as $line) {
            if (strlen($line) > self::MAX_LINE_LENGTH) {
                return true;
            }
        }

        return false;
    }
}
