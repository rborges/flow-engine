<?php

namespace FlowEngine\Application\AppMap;

/**
 * Troca comentários por espaços, mantendo as quebras de linha para que a linha de cada achado não mude.
 */
final class CommentStripper
{
    /**
     * line: marcador de comentário de linha; block: delimitadores de bloco; quotes: delimitadores de string;
     * triple: aceita '''/"""; raw: prefixo r desliga escapes; rawQuotes: aspas sem escape;
     * interpolation: aspas em que `${...}` é código; docstring: string tripla que abre a linha é comentário.
     */
    private const JS = ['line' => '//', 'block' => true, 'quotes' => ["'", '"', '`'], 'interpolation' => ['`'], 'regex' => true];

    private const SYNTAX = [
        'dart' => ['line' => '//', 'block' => true, 'quotes' => ["'", '"'], 'triple' => true, 'raw' => true, 'interpolation' => ["'", '"']],
        'py' => ['line' => '#', 'block' => false, 'quotes' => ["'", '"'], 'triple' => true, 'docstring' => true],
        'go' => ['line' => '//', 'block' => true, 'quotes' => ["'", '"', '`'], 'rawQuotes' => ['`']],
        'ts' => self::JS,
        'tsx' => self::JS,
        'js' => self::JS,
        'jsx' => self::JS,
    ];

    public function strip(string $content, string $extension): string
    {
        if ($extension === 'php') {
            return $this->stripPhp($content);
        }

        $syntax = self::SYNTAX[$extension] ?? null;

        return $syntax === null ? $content : (new CommentScanner($content, $syntax))->strip();
    }

    private function stripPhp(string $content): string
    {
        $out = '';
        foreach (token_get_all($content) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $out .= CommentScanner::blank($token[1]);
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
