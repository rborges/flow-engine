<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Config;

use FlowEngine\Infrastructure\Config\BundledLibraryDetector;
use PHPUnit\Framework\TestCase;

/**
 * Detecção das bibliotecas de terceiros copiadas para dentro do projeto.
 *
 * É o que separa uma config útil de um template em branco: sem excluir a lib de QR
 * Code embutida, a maior "área" do sistema analisado passa a ser `QRcode`, e o mapa
 * some debaixo de código que não é do usuário.
 *
 * O detector é deliberadamente conservador: esconder código do próprio usuário é um
 * erro bem mais caro do que deixar passar uma biblioteca.
 */
final class BundledLibraryDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fe-vendor-' . uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    private function file(string $relative): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, '<?php');
    }

    public function test_biblioteca_conhecida_e_detectada_pela_assinatura(): void
    {
        $this->file('html2pdf/html2pdf.class.php');

        $found = (new BundledLibraryDetector())->detect($this->root);

        $this->assertSame(['html2pdf' => 'bundled HTML2PDF'], $found);
    }

    public function test_a_lib_aninhada_e_excluida_sem_levar_junto_o_diretorio_do_usuario(): void
    {
        // helpers/ e codigo do usuario; helpers/QRCODE/ e uma lib embutida.
        // Culpar o pai pelo filho esconderia o codigo de quem usa a ferramenta.
        $this->file('helpers/Formatador.php');
        $this->file('helpers/Validador.php');
        $this->file('helpers/QRCODE/phpqrcode.php');

        $found = (new BundledLibraryDetector())->detect($this->root);

        $this->assertSame(['helpers/QRCODE' => 'bundled phpqrcode'], $found);
        $this->assertArrayNotHasKey('helpers', $found);
    }

    public function test_diretorios_sem_codigo_fonte_sao_excluidos(): void
    {
        $this->file('node_modules/pacote/index.js');
        $this->file('dist/app.js');

        $found = (new BundledLibraryDetector())->detect($this->root);

        $this->assertSame('not source code', $found['node_modules']);
        $this->assertSame('not source code', $found['dist']);
    }

    public function test_pacote_com_manifesto_proprio_e_excluido(): void
    {
        $this->file('libs/alguma-lib/composer.json');

        $found = (new BundledLibraryDetector())->detect($this->root);

        $this->assertSame('self-contained package (composer.json)', $found['libs/alguma-lib']);
    }

    public function test_codigo_do_usuario_nao_e_excluido(): void
    {
        $this->file('Controllers/AuthController.php');
        $this->file('Models/AuthModel.php');
        $this->file('ajax/busca.php');

        $this->assertSame([], (new BundledLibraryDetector())->detect($this->root));
    }
}
