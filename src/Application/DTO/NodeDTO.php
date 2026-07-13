<?php

namespace FlowEngine\Application\DTO;

/**
 * DTO readonly representando um Node do grafo de execucao.
 *
 * IMPORTANTE:
 * - Apenas leitura (todos os campos sao readonly)
 * - Nao pode ser mutado apos criacao
 * - Nao expoe metodos de Domain (withVisibility, validate, etc)
 * - Serializavel para JSON
 *
 * USO:
 * - Retornado por FlowQuery
 * - Usado em APIs REST/GraphQL
 * - Usado em views/templates
 * - Usado para cache
 */
final readonly class NodeDTO
{
    public function __construct(

        public string $id,
        public string $class,
        public string $method,
        public string $file,
        public ?int $line,
        public string $visibility,
        public bool $isPublic,
        public string $language = 'php',
        public ?array $metadata = null,
        public ?int $endLine = null,
        public ?string $snippet = null,
        public string $type = 'method',
        public ?string $qualifier = null,
    ) {
    }

    /**
     * Cria DTO a partir de entidade de Domain.
     *
     * @param \FlowEngine\Domain\Flow\Node $node
     * @return self
     */
    public static function fromNode(\FlowEngine\Domain\Flow\Node $node): self
    {
        $metadata = $node->metadata();
        $endLine = is_array($metadata) && isset($metadata['endLine']) && is_int($metadata['endLine'])
            ? $metadata['endLine']
            : null;

        return new self(
            id: $node->id(),
            class: $node->class(),
            method: $node->method(),
            file: $node->file(),
            line: $node->line(),
            visibility: $node->visibility()->value(),
            isPublic: $node->isPublic(),
            language: $node->language(),
            metadata: $metadata,
            endLine: $endLine,
            snippet: null,
            type: $node->type(),
            qualifier: $node->qualifier(),
        );
    }

    /**
     * @api
     */
    public function withSnippet(?string $snippet): self
    {
        return new self(
            id: $this->id,
            class: $this->class,
            method: $this->method,
            file: $this->file,
            line: $this->line,
            visibility: $this->visibility,
            isPublic: $this->isPublic,
            language: $this->language,
            metadata: $this->metadata,
            endLine: $this->endLine,
            snippet: $snippet,
            type: $this->type,
            qualifier: $this->qualifier,
        );
    }

    /**
     * Serializa para array (util para JSON).
     *
     * @api
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lastBackslash = strrpos($this->class, '\\');
        $namespace = $lastBackslash !== false ? substr($this->class, 0, $lastBackslash) : '';

        $arr = [
            'id' => $this->id,
            'language' => $this->language,
            'namespace' => $namespace,
            'class' => $this->class,
            'method' => $this->method,
            'file' => $this->file,
            'line' => $this->line,
            'visibility' => $this->visibility,
            'isPublic' => $this->isPublic,
        ];

        // Omitido para 'method' — mantém a saída dos nós de classe idêntica à anterior.
        if ($this->type !== 'method') {
            $arr['type'] = $this->type;
        }

        // Presente só quando o nome do símbolo colide entre arquivos.
        if ($this->qualifier !== null) {
            $arr['qualifier'] = $this->qualifier;
        }

        if ($this->metadata !== null) {
            $arr['metadata'] = $this->metadata;
        }

        if ($this->endLine !== null) {
            $arr['endLine'] = $this->endLine;
        }

        if ($this->snippet !== null) {
            $arr['snippet'] = $this->snippet;
        }

        return $arr;
    }

    /**
     * @api
     * Serializa para JSON.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
