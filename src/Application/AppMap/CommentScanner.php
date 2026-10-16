<?php

namespace FlowEngine\Application\AppMap;

/**
 * Varredura de um arquivo de sintaxe C/Python separando strings de comentários; ver CommentStripper::SYNTAX.
 */
final class CommentScanner
{
    private int $i = 0;
    private int $length;
    private string $out = '';

    /**
     * @param array<string, mixed> $syntax
     */
    public function __construct(
        private readonly string $src,
        private readonly array $syntax
    ) {
        $this->length = strlen($src);
    }

    public static function blank(string $text): string
    {
        return (string) preg_replace('/[^\r\n]/', ' ', $text);
    }

    public function strip(): string
    {
        $this->code(false);

        return $this->out;
    }

    /**
     * Copia código; com $untilBrace, para depois da `}` que fecha uma interpolação `${...}`.
     */
    private function code(bool $untilBrace): void
    {
        $depth = 0;

        while ($this->i < $this->length) {
            $char = $this->src[$this->i];

            if ($untilBrace && $char === '{') {
                $depth++;
            } elseif ($untilBrace && $char === '}') {
                if ($depth === 0) {
                    $this->emit(1);
                    return;
                }
                $depth--;
            }

            if ($this->at($this->syntax['line'])) {
                $end = $this->i + strcspn($this->src, "\r\n", $this->i);
                $this->skip($end);
                continue;
            }

            if (($this->syntax['block'] ?? false) && $this->at('/*')) {
                $close = strpos($this->src, '*/', $this->i + 2);
                $this->skip($close === false ? $this->length : $close + 2);
                continue;
            }

            if (($this->syntax['raw'] ?? false) && ($char === 'r' || $char === 'R')
                && in_array($this->src[$this->i + 1] ?? '', $this->syntax['quotes'], true)
                && !$this->afterIdentifier()
            ) {
                $this->emit(1);
                $this->string(true);
                continue;
            }

            if (in_array($char, $this->syntax['quotes'], true)) {
                $this->string(in_array($char, $this->syntax['rawQuotes'] ?? [], true));
                continue;
            }

            $this->emit(1);
        }
    }

    private function string(bool $raw): void
    {
        $quote = $this->src[$this->i];
        $triple = ($this->syntax['triple'] ?? false) && substr($this->src, $this->i, 3) === str_repeat($quote, 3);
        $delimiter = $triple ? str_repeat($quote, 3) : $quote;
        $interpolates = !$raw && in_array($quote, $this->syntax['interpolation'] ?? [], true);
        $docstring = $triple && ($this->syntax['docstring'] ?? false) && $this->startsLine();
        $start = strlen($this->out);

        $this->emit(strlen($delimiter));

        while ($this->i < $this->length) {
            if ($this->at($delimiter)) {
                $this->emit(strlen($delimiter));
                break;
            }

            $char = $this->src[$this->i];

            if (!$raw && $char === '\\') {
                $this->emit(2);
                continue;
            }

            if ($interpolates && $this->at('${')) {
                $this->emit(2);
                $this->code(true);
                continue;
            }

            // String de uma linha sem fechamento: o resto é código.
            if (!$triple && $quote !== '`' && ($char === "\n" || $char === "\r")) {
                break;
            }

            $this->emit(1);
        }

        if ($docstring) {
            $this->out = substr($this->out, 0, $start) . self::blank(substr($this->out, $start));
        }
    }

    private function at(string $token): bool
    {
        return substr_compare($this->src, $token, $this->i, strlen($token)) === 0;
    }

    private function emit(int $count): void
    {
        $this->out .= substr($this->src, $this->i, $count);
        $this->i = min($this->i + $count, $this->length);
    }

    private function skip(int $end): void
    {
        $this->out .= self::blank(substr($this->src, $this->i, $end - $this->i));
        $this->i = $end;
    }

    private function afterIdentifier(): bool
    {
        return $this->i > 0 && preg_match('/\w/', $this->src[$this->i - 1]) === 1;
    }

    private function startsLine(): bool
    {
        $lineStart = strrpos($this->out, "\n");
        $prefix = $lineStart === false ? $this->out : substr($this->out, $lineStart + 1);

        return trim($prefix) === '';
    }
}
