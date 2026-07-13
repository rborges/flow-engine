<?php

namespace FlowEngine\Infrastructure\Analyzer\Visitors;

use FlowEngine\Domain\Flow\Edge;
use PhpParser\Node;

/**
 * Detecta instâncias diretas via `new ClassName()` dentro de métodos.
 *
 * Cria edges do tipo `direct_instantiation` entre o método atual e
 * o construtor da classe instanciada. Usado pelo SolidAnalyzer para
 * detectar violações do Princípio da Inversão de Dependência (DIP).
 *
 * Exemplo detectado:
 *   $gateway = new ConcretePaymentGateway(); // dentro de um método
 *   → edge: CurrentClass::currentMethod → ConcretePaymentGateway::__construct (direct_instantiation)
 *
 * Classes ignoradas (instanciar é legítimo):
 * - Exceções (*Exception)
 * - Value Objects (namespace *\ValueObjects\*)
 * - DTOs (namespace *\DTO\*)
 * - Coleções e primitivos (ArrayObject, stdClass, DateTime, etc.)
 *
 * @internal
 */
final class NewInstantiationVisitor implements NodeVisitor
{
    private const IGNORED_CLASSES = [
        'stdClass', 'ArrayObject', 'DateTime', 'DateTimeImmutable',
        'DateInterval', 'SplStack', 'SplQueue', 'SplMinHeap', 'Carbon',
    ];

    /**
     * @internal
     */
    public function supports(Node $node): bool
    {
        return $node instanceof Node\Expr\New_;
    }

    /**
     * @internal
     */
    public function enter(Node $node, VisitorContext $context): void
    {
        if ($context->currentNodeId() === null) {
            return;
        }

        /** @var Node\Expr\New_ $node */
        // Apenas instâncias com classe nomeada (não `new $variable()`)
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $className = $node->class->toString();
        $resolvedClass = $context->resolveClass($className);

        if ($this->shouldIgnore($resolvedClass)) {
            return;
        }

        $fromId = $context->currentNodeId();
        if ($fromId === null) {
            return;
        }

        $context->addEdge(new Edge(
            $fromId,
            $resolvedClass . '::__construct',
            'new',
            'direct_instantiation'
        ));
    }

    /**
     * @internal
     */
    public function leave(Node $node, VisitorContext $context): void
    {
        // sem estado para limpar
    }

    /**
     * Verifica se a instanciação é considerada legítima (não uma violação de DIP).
     */
    private function shouldIgnore(string $fqn): bool
    {
        $parts = explode('\\', $fqn);
        $shortName = end($parts);

        // Exceções são legítimas de instanciar diretamente
        if (str_ends_with($shortName, 'Exception')) {
            return true;
        }

        // Classes da lista de permitidos explícitos
        if (in_array($shortName, self::IGNORED_CLASSES, true)) {
            return true;
        }

        // Value Objects, DTOs e Enums são legítimos
        if (str_contains($fqn, '\\ValueObject')
            || str_contains($fqn, '\\ValueObjects\\')
            || str_contains($fqn, '\\DTO\\')
            || str_ends_with($shortName, 'DTO')
            || str_ends_with($shortName, 'Enum')
        ) {
            return true;
        }

        return false;
    }
}
