<?php

namespace FlowEngine\Domain\Flow;

/**
 * Factory padrão para criação de Nodes.
 * 
 * RESPONSABILIDADES:
 * - Validar inputs antes de criar Node
 * - Garantir que apenas Nodes válidos são criados
 * - Fornecer mensagens de erro claras
 * 
 * VALIDAÇÕES:
 * - class não pode ser vazio
 * - method não pode ser vazio
 * - file não pode ser vazio
 * - file deve existir no filesystem
 * - line deve ser null ou positivo
 */
final class DefaultNodeFactory implements NodeFactory
{
    /**
     * Cria um Node validado.
     * 
     * @param string $class Nome da classe
     * @param string $method Nome do método
     * @param string $file Caminho absoluto do arquivo
     * @param int|null $line Linha onde o método inicia (opcional)
     * 
     * @return Node Instância válida
     * 
     * @throws \InvalidArgumentException Se qualquer input for inválido
     */
    /**
     * @param array<string, mixed>|null $metadata Extra metadata (e.g. FastAPI decorator info)
     */
    public function create(
        string $class,
        string $method,
        string $file,
        ?int $line,
        string $language = 'php',
        ?array $metadata = null,
        string $type = Node::TYPE_METHOD
    ): Node {
        // Funções globais não têm classe; para os demais tipos ela é obrigatória.
        if ($type !== Node::TYPE_FUNCTION) {
            $this->validateClass($class);
        }

        $this->validateMethod($method);
        $this->validateFile($file);
        $this->validateLine($line);

        return new Node($class, $method, $file, $line, $language, $metadata, $type);
    }

    /**
     * Valida nome da classe.
     * 
     * @throws \InvalidArgumentException Se classe for vazia
     */
    private function validateClass(string $class): void
    {
        if (trim($class) === '') {
            throw new \InvalidArgumentException(
                'Cannot create Node: class name cannot be empty'
            );
        }
    }

    /**
     * Valida nome do método.
     * 
     * @throws \InvalidArgumentException Se método for vazio
     */
    private function validateMethod(string $method): void
    {
        if (trim($method) === '') {
            throw new \InvalidArgumentException(
                'Cannot create Node: method name cannot be empty'
            );
        }
    }

    /**
     * Valida caminho do arquivo.
     * 
     * @throws \InvalidArgumentException Se arquivo for vazio ou não existir
     */
    private function validateFile(string $file): void
    {
        if (trim($file) === '') {
            throw new \InvalidArgumentException(
                'Cannot create Node: file path cannot be empty'
            );
        }

        if (!file_exists($file)) {
            throw new \InvalidArgumentException(
                "Cannot create Node: file does not exist: {$file}"
            );
        }
    }

    /**
     * Valida número da linha.
     * 
     * @throws \InvalidArgumentException Se linha for negativa
     */
    private function validateLine(?int $line): void
    {
        if ($line !== null && $line < 1) {
            throw new \InvalidArgumentException(
                "Cannot create Node: line number must be positive, got {$line}"
            );
        }
    }
}
