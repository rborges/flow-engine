<?php

declare(strict_types=1);

namespace FlowEngine\Tests\Infrastructure\Analyzer;

use FlowEngine\Infrastructure\Analyzer\SourceFileGuard;
use PHPUnit\Framework\TestCase;

/**
 * Arquivos gerados não são código-fonte.
 *
 * Um bundle como pdfmake.js (2,7 MB numa linha só) não tem valor analítico e ainda
 * derrubava o processo: o parser carregava o arquivo inteiro e o quebrava num array
 * de linhas, estourando o memory_limit com um fatal error sem mensagem útil.
 */
final class SourceFileGuardTest extends TestCase
{
    private string $dir;
    private SourceFileGuard $guard;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fe-guard-' . uniqid();
        mkdir($this->dir);
        $this->guard = new SourceFileGuard();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_codigo_normal_passa(): void
    {
        $file = $this->write('Servico.php', "<?php\n\nclass Servico\n{\n    public function rodar() {}\n}\n");

        $this->assertNull($this->guard->skipReason($file));
    }

    public function test_bundle_minificado_e_pulado_pelo_nome(): void
    {
        $file = $this->write('jquery.min.js', 'var a=1;');

        $this->assertSame('minified bundle', $this->guard->skipReason($file));
    }

    public function test_arquivo_de_uma_linha_gigante_e_pulado(): void
    {
        // O caso do pdfmake.js: sem quebras de linha, tudo numa linha so.
        $file = $this->write('gerado.js', 'var x=' . str_repeat('a', 6000) . ';');

        $this->assertSame(
            'generated or minified (single very long line)',
            $this->guard->skipReason($file)
        );
    }

    public function test_arquivo_grande_demais_e_pulado(): void
    {
        // Linhas curtas, mas volume que nao vem de codigo escrito a mao.
        $file = $this->write('enorme.js', str_repeat("var x = 1;\n", 160_000));

        $this->assertStringContainsString('too large', (string) $this->guard->skipReason($file));
    }
}
