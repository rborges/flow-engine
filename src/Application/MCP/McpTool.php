<?php

namespace FlowEngine\Application\MCP;

/**
 * @api
 */
final readonly class McpTool
{
    public function __construct(
        public string $name,
        public string $description,
        public array  $inputSchema,
        /** @var callable(array): string */
        public mixed  $handler,
        /**
         * MCP tool annotations (safety hints). Every Flow Engine tool analyzes
         * code without mutating the target project, so the default declares the
         * read-only contract; a future non-read-only tool must override this.
         */
        public array  $annotations = [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ],
    ) {}
}
