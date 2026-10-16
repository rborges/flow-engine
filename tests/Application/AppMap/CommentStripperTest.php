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
