<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\CommentStripper;
use PHPUnit\Framework\TestCase;

final class CommentStripperTest extends TestCase
{
    public function test_php_comments_become_blanks_and_lines_are_kept(): void
    {
        $source = <<<'PHP'
<?php
/*
 * License: https://opensource.org/licenses/MIT
 */
// see https://docs.example.com
# legacy https://old.example.com
/** @link https://api.example.com/docs */
$url = 'https://api.example.com/orders'; // trailing https://note.example.com
PHP;

        $stripped = (new CommentStripper())->strip($source, 'php');

        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));
        self::assertSame(['https://api.example.com/orders'], $this->urls($stripped));
        self::assertStringContainsString("'https://api.example.com/orders'", explode("\n", $stripped)[7]);
    }

    public function test_c_style_comments_are_removed_outside_strings(): void
    {
        $source = <<<'TS'
/// docs https://docs.example.com
/* block
   https://block.example.com */
const a = "https://api.example.com/a"; // https://note.example.com
const b = 'it\'s // not a comment https://api.example.com/b';
TS;

        $stripped = (new CommentStripper())->strip($source, 'dart');

        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));
        self::assertSame(
            ['https://api.example.com/a', 'https://api.example.com/b'],
            $this->urls($stripped)
        );
    }

    public function test_dart_triple_raw_and_interpolated_strings_keep_their_urls(): void
    {
        $source = <<<'DART'
var q = '''
see http://api.x/y
''';
final u = '${base ?? 'http://a.com'}/v1/users'; // http://note.example.com
final re = RegExp(r'\\'); final z = 'http://api.z/q';
final w = r"C:\dir\"; final v = "http://api.v/w";
DART;

        $stripped = (new CommentStripper())->strip($source, 'dart');

        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));
        self::assertSame(
            ['http://api.x/y', 'http://a.com', 'http://api.z/q', 'http://api.v/w'],
            $this->urls($stripped)
        );
    }

    public function test_python_comments_and_docstrings_are_removed(): void
    {
        $source = <<<'PY'
"""Module docs: https://docs.example.com"""
import requests  # https://pypi.org/project/requests


def fetch():
    '''
    See https://wiki.example.com
    '''
    query = """
    select 'https://api.example.com/q'
    """
    return requests.get("https://api.example.com/a#frag")
PY;

        $stripped = (new CommentStripper())->strip($source, 'py');

        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));
        self::assertSame(['https://api.example.com/q', 'https://api.example.com/a#frag'], $this->urls($stripped));
    }

    public function test_typescript_template_literals_and_go_raw_strings(): void
    {
        $ts = <<<'TS'
// https://docs.example.com
const url = `https://api.example.com/${id ?? '//x'}/orders`; /* https://note.example.com */
const other = `multi
https://api.example.com/c`;
TS;
        $go = <<<'GO'
// Package docs https://pkg.go.dev
var pattern = `C:\path\` + "https://api.example.com/go"
GO;

        $stripper = new CommentStripper();

        self::assertSame(
            ['https://api.example.com/${id', 'https://api.example.com/c'],
            $this->urls($stripper->strip($ts, 'ts'))
        );
        self::assertSame(['https://api.example.com/go'], $this->urls($stripper->strip($go, 'go')));
    }

    public function test_javascript_regex_literals_are_not_comments_or_strings(): void
    {
        $source = <<<'JS'
s = s.replace(/\/*$/, '');
const A = 'https://a.com';
const r = /`/;
const t = `https://t.com`; // http://comment.com
if (/'/.test(s)) u = 'https://u.com';
const half = total / 2; const v = "https://v.com";
JS;

        $stripped = (new CommentStripper())->strip($source, 'js');

        self::assertSame(substr_count($source, "\n"), substr_count($stripped, "\n"));
        self::assertSame(
            ['https://a.com', 'https://t.com', 'https://u.com', 'https://v.com'],
            $this->urls($stripped)
        );
    }

    public function test_python_triple_quoted_value_on_continuation_line_is_kept(): void
    {
        $source = "x = (\n    \"\"\"https://kept.com\"\"\"\n)\n";

        self::assertSame(['https://kept.com'], $this->urls((new CommentStripper())->strip($source, 'py')));
    }

    public function test_unknown_extension_is_left_untouched(): void
    {
        $source = "# https://example.com\n";

        self::assertSame($source, (new CommentStripper())->strip($source, 'txt'));
    }

    /**
     * @return string[]
     */
    private function urls(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s\'"`;]+/', $text, $m);

        return $m[0];
    }
}
