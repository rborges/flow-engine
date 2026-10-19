<?php

declare(strict_types=1);

namespace FlowEngine\Domain\Contracts;

/**
 * Descobre diretórios que devem ficar fora do scan: bibliotecas de terceiros
 * copiadas para dentro do projeto e diretórios sem código-fonte.
 *
 * Contrato no Domain para que o `init` (Application) possa usar a detecção sem se
 * acoplar à varredura do sistema de arquivos, que é detalhe de Infrastructure.
 */
interface BundledLibraryScanner
{
    /**
     * @return array<string, string> caminho relativo à raiz => motivo da exclusão
     */
    public function detect(string $projectRoot): array;
}
