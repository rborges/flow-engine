<?php

namespace FlowEngine\Domain\Flow;

use FlowEngine\Domain\Node\NodeVisibility;

/**
 * Representa um nó no grafo de execução (método público de uma classe).
 * 
 * IMUTABILIDADE:
 * - Todas as propriedades são readonly após construção
 * - withVisibility() retorna NOVA instância
 * - Visibilidade é aplicada UMA vez pelo FlowBuilder
 * 
 * LIFECYCLE:
 * 1. AstParser cria Node básico (sem visibilidade)
 * 2. FlowBuilder aplica policy e marca como avaliado
 * 3. Node entra no Flow em estado final (imutável)
 */
final class Node
{
    /** Método de uma classe (Class::method) — o tipo histórico e default. */
    public const TYPE_METHOD = 'method';

    /** Função livre declarada fora de qualquer classe. */
    public const TYPE_FUNCTION = 'function';

    /** Arquivo com código executável de topo (script procedural). */
    public const TYPE_FILE = 'file';

    private ?NodeVisibility $visibility = null;

    /**
     * Marca se a visibilidade foi explicitamente avaliada por uma policy.
     * 
     * FALSE = visibilidade default (nunca passou por policy)
     * TRUE = visibilidade foi determinada por NodeVisibilityPolicy
     */
    private bool $visibilityEvaluated = false;

    /**
     * @param array<string, mixed>|null $metadata Extra metadata (e.g. FastAPI decorator info)
     */
    public function __construct(
        private readonly string $class,
        private readonly string $method,
        private readonly string $file,
        private readonly ?int $line,
        private readonly string $language = 'php',
        private readonly ?array $metadata = null,
        private readonly string $type = self::TYPE_METHOD,
        private readonly ?string $qualifier = null
    ) {}

    /**
     * Caminho que desambigua este nó quando o nome sozinho colide.
     *
     * Null no caso normal. Preenchido apenas quando dois arquivos declaram o mesmo
     * símbolo global — situação comum em PHP sem namespace, onde dois arquivos
     * definem `atualizar()` e nunca são carregados juntos.
     *
     * @api
     */
    public function qualifier(): ?string
    {
        return $this->qualifier;
    }

    /**
     * Nova instância com o qualificador de arquivo aplicado.
     *
     * @internal Uso exclusivo do desambiguador de IDs.
     */
    public function withQualifier(string $qualifier): self
    {
        return new self(
            $this->class,
            $this->method,
            $this->file,
            $this->line,
            $this->language,
            $this->metadata,
            $this->type,
            $qualifier
        );
    }

    /**
     * Identificador único do nó.
     *
     * - method:   Class::method            (PHP) / lang:Class::method (demais)
     * - function: func:Namespace\nome      (classe vazia = função global)
     * - file:     file:caminho/relativo.php
     *
     * IMPORTANTE: Este ID deve ser único dentro de um Flow.
     * Duplicatas indicam problema no scan ou na estrutura do projeto.
     *
     * @api
     */
    public function id(): string
    {
        // O qualificador entra do lado da CLASSE, nunca do método: assim quem faz
        // explode('::', $id) continua obtendo um nome de método limpo.
        $suffix = $this->qualifier !== null ? '@' . $this->qualifier : '';

        $base = match ($this->type) {
            self::TYPE_FILE     => 'file:' . $this->class,
            self::TYPE_FUNCTION => 'func:' . ($this->class !== ''
                ? $this->class . '\\' . $this->method
                : $this->method) . $suffix,
            default             => $this->class . $suffix . '::' . $this->method,
        };

        if ($this->language === 'php') {
            return $base;
        }

        return $this->language . ':' . $base;
    }

    /**
     * Tipo do nó: method, function ou file.
     *
     * @api
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Returns the node language (php, python, ...).
     *
     * @api
     */
    public function language(): string
    {
        return $this->language;
    }

    /**
     * @api
     */
    public function class(): string
    {
        return $this->class;
    }

    public function method(): string
    {
        return $this->method;
    }
    /**
     * @api
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @api
     */
    public function file(): string
    {
        return $this->file;
    }
    /**
     * @api
     */
    public function line(): ?int
    {
        return $this->line;
    }

    /**
     * Retorna visibilidade do nó.
     * 
     * Se nunca foi avaliada por policy, retorna PUBLIC (default).
     * @api
     */
    public function visibility(): NodeVisibility
    {
        return $this->visibility
            ?? new NodeVisibility(NodeVisibility::PUBLIC);
    }

    /**
     * Verifica se visibilidade é PUBLIC.
     * @api
     */
    public function isPublic(): bool
    {
        return $this->visibility()->isPublic();
    }

    /**
     * Verifica se este nó teve sua visibilidade avaliada por policy.
     * @api
     * @return bool TRUE se passou por NodeVisibilityPolicy
     */
    public function hasEvaluatedVisibility(): bool
    {
        return $this->visibilityEvaluated;
    }

    /**
     * Cria nova instância com visibilidade aplicada.
     * 
     * IMPORTANTE: Este método deve ser chamado APENAS por FlowBuilder.
     * Marca o nó como "avaliado por policy".
     * 
     * @internal Uso exclusivo de FlowBuilder
     */
    public function withVisibility(NodeVisibility $visibility): self
    {
        $clone = clone $this;
        $clone->visibility = $visibility;
        $clone->visibilityEvaluated = true;

        return $clone;
    }

    /**
     * Validação: garante que Node está em estado final válido.
     * 
     * IMPORTANTE: Esta validação é redundante se Node foi criado via NodeFactory,
     * pois a factory já valida inputs. No entanto, é útil como safety check
     * antes de adicionar ao Flow (FlowBuilder chama isso).
     * 
     * @api
     * @throws \InvalidArgumentException Se node estiver em estado inválido
     */
    public function validate(): void
    {
        // Funções globais não têm classe; para os demais tipos ela é obrigatória.
        if (empty($this->class) && $this->type !== self::TYPE_FUNCTION) {
            throw new \InvalidArgumentException('Node class cannot be empty');
        }

        if (empty($this->method)) {
            throw new \InvalidArgumentException('Node method cannot be empty');
        }

        if (empty($this->file)) {
            throw new \InvalidArgumentException('Node file cannot be empty');
        }

        if (!file_exists($this->file)) {
            throw new \InvalidArgumentException(
                "Node file does not exist: {$this->file}"
            );
        }
    }
}
