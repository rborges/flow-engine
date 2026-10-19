<?php

namespace FlowEngine\Domain\Flow;
interface NodeFactory
{
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
    ): Node;
}
