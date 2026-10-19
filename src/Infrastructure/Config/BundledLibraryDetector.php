<?php

declare(strict_types=1);

namespace FlowEngine\Infrastructure\Config;

use FlowEngine\Domain\Contracts\BundledLibraryScanner;

/**
 * Encontra diretórios que devem ficar fora do scan: bibliotecas de terceiros
 * copiadas para dentro do projeto, e diretórios sem código-fonte.
 *
 * Isto existe porque a config gerada pelo `init` decide a qualidade de tudo o que
 * vem depois. Sem excluir a lib de QR Code embutida, a maior "área" do sistema
 * analisado passa a ser `QRcode`, e o mapa some debaixo de código que não é do
 * usuário.
 *
 * O sinal clássico — LICENSE, README ou composer.json próprio — **não funciona**
 * em código legado: nas libs realmente encontradas em projetos assim (html2pdf,
 * phpqrcode) não havia nenhum desses arquivos. O que as denuncia é o nome dos
 * arquivos que elas trazem. Por isso a detecção é por assinatura, e deliberadamente
 * conservadora: na dúvida, NÃO exclui. Esconder código do próprio usuário é um erro
 * bem mais caro do que deixar passar uma biblioteca.
 *
 * @internal
 */
final class BundledLibraryDetector implements BundledLibraryScanner
{
    /** Diretórios que nunca contêm código-fonte do projeto. */
    private const NON_SOURCE_DIRECTORIES = [
        'vendor', 'node_modules', 'bower_components',
        'dist', 'build', 'out',
        'nbproject', '.idea', '.vscode', '.git', '.claude',
        'cache', 'logs', 'tmp',
    ];

    /**
     * Arquivos que identificam uma biblioteca PHP conhecida embutida no projeto.
     * Minúsculas; a comparação é case-insensitive.
     */
    private const LIBRARY_SIGNATURES = [
        'html2pdf.class.php' => 'HTML2PDF',
        'tcpdf.php'          => 'TCPDF',
        'fpdf.php'           => 'FPDF',
        'phpqrcode.php'      => 'phpqrcode',
        'qrlib.php'          => 'phpqrcode',
        'phpmailer.php'      => 'PHPMailer',
        'class.phpmailer.php' => 'PHPMailer',
        'phpexcel.php'       => 'PHPExcel',
        'dompdf.php'         => 'dompdf',
        'mpdf.php'           => 'mPDF',
        'smarty.class.php'   => 'Smarty',
        'swift_required.php' => 'SwiftMailer',
        'jquery.js'          => 'jQuery',
    ];

    /** Profundidade máxima de varredura a partir da raiz. */
    private const MAX_DEPTH = 3;

    /**
     * Diretórios a excluir, relativos à raiz.
     *
     * @return array<string, string> caminho relativo => motivo
     */
    public function detect(string $projectRoot): array
    {
        $found = [];
        $this->scan(rtrim($projectRoot, '/\\'), '', 1, $found);
        ksort($found);

        return $found;
    }

    /**
     * @param array<string, string> $found
     */
    private function scan(string $absolute, string $relative, int $depth, array &$found): void
    {
        $entries = @scandir($absolute);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childAbsolute = $absolute . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($childAbsolute)) {
                continue;
            }

            $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;

            $reason = $this->reasonFor($entry, $childAbsolute);

            if ($reason !== null) {
                $found[$childRelative] = $reason;
                // Não desce: o diretório inteiro já está fora.
                continue;
            }

            if ($depth < self::MAX_DEPTH) {
                $this->scan($childAbsolute, $childRelative, $depth + 1, $found);
            }
        }
    }

    private function reasonFor(string $name, string $absolute): ?string
    {
        if (in_array(strtolower($name), self::NON_SOURCE_DIRECTORIES, true)) {
            return 'not source code';
        }

        $library = $this->librarySignature($absolute);

        if ($library !== null) {
            return 'bundled ' . $library;
        }

        // Biblioteca instalada com seus próprios metadados.
        foreach (['composer.json', 'package.json'] as $manifest) {
            if (is_file($absolute . DIRECTORY_SEPARATOR . $manifest)) {
                return 'self-contained package (' . $manifest . ')';
            }
        }

        return null;
    }

    /**
     * Assinatura procurada APENAS entre os arquivos do próprio diretório.
     *
     * É tentador olhar um nível abaixo, mas isso culpa o pai pelo filho: `helpers/`
     * contém código do usuário e uma lib de QR Code em `helpers/QRCODE/` — olhar
     * para baixo excluiria `helpers` inteiro, escondendo o código de quem usa a
     * ferramenta. A recursão já chega no subdiretório certo e o marca sozinha.
     */
    private function librarySignature(string $absolute): ?string
    {
        foreach ($this->fileNames($absolute) as $file) {
            $signature = self::LIBRARY_SIGNATURES[strtolower($file)] ?? null;

            if ($signature !== null) {
                return $signature;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function fileNames(string $directory): array
    {
        $names = [];

        foreach (@scandir($directory) ?: [] as $entry) {
            if (is_file($directory . DIRECTORY_SEPARATOR . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }
}
