<?php

namespace FlowEngine\Application\MCP;

use FlowEngine\AI\Export\ExportOptions;
use FlowEngine\Application\AppMap\ApplicationMapBuilder;
use FlowEngine\Application\DTO\StalenessReport;
use FlowEngine\Application\InfraMap\InfraMapBuilder;
use FlowEngine\Application\ProjectMap\ProjectFindBuilder;
use FlowEngine\Application\ProjectMap\ProjectLookupBuilder;
use FlowEngine\Application\ProjectMap\ProjectMapBuilder;
use FlowEngine\Bootstrap\CatalogServiceBuilder;
use FlowEngine\Bootstrap\ConfigResolution;
use FlowEngine\Bootstrap\Container;
use FlowEngine\Bootstrap\InfraServices;
use FlowEngine\Bootstrap\LanguageSupportCatalog;
use FlowEngine\Domain\Contracts\Flow as FlowContract;
use FlowEngine\Domain\Flow\FlowTracer;
use FlowEngine\Domain\Flow\Node;

final class McpServer
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const SERVER_NAME      = 'flow-engine';
    private const SERVER_VERSION   = '4.44.3';
    private const NODE_ID_DESCRIPTION =
        'Node identifier as returned by flow_map or flow_find. PHP uses `Namespace\\Class::method` (no prefix); '
        . 'every other code language prefixes the id, in the form `<lang>:<module.path>[.<Class>]::<member>` '
        . '(e.g. `typescript:user%2Eservice.UserService::getUser`, `python:App.src.sample::a`, `go:main::Hello`, '
        . '`dart:lib.presentation.bloc.library_bloc::module`). Blade templates appear only as edge sources (`blade:...`), '
        . 'not as standalone nodes — do not pass them here. Use flow_find first if you do not know the exact ID.';

    /** @var McpTool[] */
    private array $tools;

    public function __construct()
    {
        $this->tools = $this->buildTools();
    }

    public function run(): void
    {
        $hasPosix = function_exists('posix_getppid');
        $originalParent = $hasPosix ? posix_getppid() : null;

        while (true) {
            if ($hasPosix) {
                $current = posix_getppid();
                if ($current === 1 || ($originalParent !== null && $current !== $originalParent)) {
                    return;
                }
            }

            $read = [STDIN];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);

            if ($ready === false) {
                return;
            }

            if ($ready === 0) {
                continue;
            }

            $line = fgets(STDIN);
            if ($line === false) {
                return;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);

            if ($message === null) {
                $this->writeResponse($this->errorResponse(null, -32700, 'Parse error'));
                continue;
            }

            $id       = $message['id'] ?? null;
            $response = $this->dispatch($message);

            if ($response !== null) {
                $this->writeResponse($response);
            }
        }
    }

    /**
     * Dispatch a decoded JSON-RPC message and return a response array or null
     * (notifications return null — no response is sent).
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function dispatch(array $message): ?array
    {
        $id     = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        return match ($method) {
            'initialize'               => $this->handleInitialize($id),
            'initialized'              => null,
            'notifications/initialized' => null,
            'notifications/cancelled'  => null,
            'ping'                     => $this->respond($id, (object) []),
            'tools/list'               => $this->handleToolsList($id),
            'tools/call'               => $this->handleToolCall($id, $params),
            default                    => $id === null
                ? null
                : $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function handleInitialize(mixed $id): array
    {
        return $this->respond($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'Flow Engine indexes structure across PHP, Python, TypeScript/JavaScript, Go, Dart, and Blade — its own implementation language (PHP) is incidental and does not constrain target projects. Node IDs use one of two shapes: PHP emits `Namespace\\Class::method` with no prefix; every other code language prefixes the id with `<lang>:<module.path>[.<Class>]::<member>`. Examples (taken from real analyzers): `typescript:user%2Eservice.UserService::getUser`, `typescript:helpers::formatDate`, `python:App.src.sample::a`, `python:App.src.sample.C::m`, `go:main::Hello`, `go:~path~.users.UserService::GetUser`, `dart:lib.presentation.bloc.library_bloc::module`. Blade templates appear only as edge sources (`blade:livewire.backup.b2-manager` style) — they have no standalone nodes, so node-only tools (`flow_impact`, `flow_risk`, `flow_refactor_plan`) reject `blade:` ids. Always call `flow_map` first to see the exact IDs in the analyzed project; never invent identifiers.',
        ]);
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        $tools = array_map(
            fn(McpTool $t) => [
                'name'        => $t->name,
                'description' => $t->description,
                'inputSchema' => $t->inputSchema,
                'annotations' => $t->annotations,
            ],
            $this->tools
        );

        return $this->respond($id, ['tools' => $tools]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $tool = null;
        foreach ($this->tools as $t) {
            if ($t->name === $name) {
                $tool = $t;
                break;
            }
        }

        if ($tool === null) {
            return $this->respond($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => "Unknown tool: {$name}"]],
            ]);
        }

        // Validate required parameters
        $required = $tool->inputSchema['required'] ?? [];
        foreach ($required as $param) {
            if (!isset($args[$param]) || $args[$param] === '') {
                return $this->respond($id, [
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => "Missing required parameter: {$param}"]],
                ]);
            }
        }

        try {
            $text = ($tool->handler)($args);
            return $this->respond($id, [
                'content' => [['type' => 'text', 'text' => $text]],
            ]);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Tool error [{$name}]: " . $e->getMessage() . "\n");
            return $this->respond($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Tool definitions
    // -------------------------------------------------------------------------

    /** @return McpTool[] */
    private function buildTools(): array
    {
        $languageSupportCatalog = new LanguageSupportCatalog();
        $supportSummary = $languageSupportCatalog->descriptionSummary();

        return [
            new McpTool(
                name: 'flow_map',
                description: '[STEP 1 - PRIMARY] Use first, before opening code files, to understand the project or catalog. It returns a deterministic structural map that saves tokens, avoids blind exploration, and points to the next precise lookup target. After this, drill down with flow_lookup (exact ID) or flow_find (substring search). ' . $supportSummary,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to one project root.'],
                        'catalog' => ['type' => 'string', 'description' => 'Path to a flow-services.json catalog for multi-service mapping.'],
                        'scope' => ['type' => 'string', 'description' => 'Optional scope: auto, single, or catalog.'],
                        'mode' => ['type' => 'string', 'description' => 'Optional mode: summary or full. Default: summary.'],
                        'depth' => ['type' => 'integer', 'description' => 'Optional structural depth. Default: 1.'],
                    ],
                ],
                handler: function (array $args) use ($languageSupportCatalog): string {
                    $extraWarnings = $this->extractDeprecatedWarnings($args);
                    $scope = $this->resolveScope($args);
                    $mode = $this->normalizeMode($args['mode'] ?? 'summary');
                    $depth = $this->normalizeDepth($args['depth'] ?? 1);
                    $builder = new ProjectMapBuilder();

                    $jsonFlags = $mode === 'summary'
                        ? JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

                    if ($scope === 'catalog') {
                        $catalogPath = $this->normalizeCatalogPath((string) ($args['catalog'] ?? ''));
                        $services = (new CatalogServiceBuilder())->buildServices($catalogPath, true);
                        $appmap = (new ApplicationMapBuilder())->build($services);
                        $capabilities = $this->catalogCapabilities($languageSupportCatalog, $services);
                        $payload = $builder->buildForCatalog($catalogPath, $appmap, $services, $capabilities, $mode, $depth, $extraWarnings)->toArray();

                        return $this->encodeJson(
                            $this->attachCatalogMetadata($payload, $services),
                            $jsonFlags
                        );
                    }

                    $projectPath = $this->normalizeProjectPath((string) ($args['project'] ?? ''));
                    $this->assertProjectPathExists($projectPath);
                    [$container, $stalenessReport] = $this->prepareProject($projectPath);
                    $capabilities = $this->singleProjectCapabilities($languageSupportCatalog, $container);

                    $payload = $builder->buildForProject($projectPath, $container->getFlow(), $capabilities, $mode, $depth, $extraWarnings)->toArray();
                    $payload = $this->attachProjectWarnings($payload, $container, $stalenessReport);

                    return $this->encodeJson(
                        $this->attachProjectMetadata($payload, $container->configResolution()),
                        $jsonFlags
                    );
                }
            ),

            new McpTool(
                name: 'flow_infra_map',
                description: '[STEP 1B - INFRASTRUCTURE] Use when flow_map finds few/no analyzable code nodes or when you need Docker, compose, proxy, crawl rules, scripts, and file inventory. Returns a read-only infrastructure graph for one project or a flow-services catalog. It never executes Docker, scripts, or services.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to one project root. Mutually exclusive with catalog.'],
                        'catalog' => ['type' => 'string', 'description' => 'Path to a flow-services.json catalog. Mutually exclusive with project.'],
                        'scope' => ['type' => 'string', 'description' => 'Optional scope: auto, single, or catalog.'],
                        'mode' => ['type' => 'string', 'description' => 'Optional mode: summary or full. Default: summary.'],
                        'sections' => [
                            'oneOf' => [
                                ['type' => 'string'],
                                [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                            'description' => 'Optional sections: all, files, docker, proxy, web, scripts. Accepts comma-separated string or array.',
                        ],
                    ],
                ],
                handler: function (array $args): string {
                    $scope = $this->resolveScope($args);
                    $mode = $this->normalizeMode($args['mode'] ?? 'summary');
                    $sections = $this->normalizeInfraSections($args['sections'] ?? ['all']);
                    $builder = (new InfraServices())->infraMapBuilder();

                    $jsonFlags = $mode === 'summary'
                        ? JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

                    if ($scope === 'catalog') {
                        $catalogPath = $this->normalizeCatalogPath((string) ($args['catalog'] ?? ''));

                        return $this->encodeJson(
                            $builder->buildForCatalog($catalogPath, $mode, $sections),
                            $jsonFlags
                        );
                    }

                    $projectPath = $this->normalizeProjectPath((string) ($args['project'] ?? ''));
                    $this->assertProjectPathExists($projectPath);

                    return $this->encodeJson(
                        $builder->buildForProject($projectPath, $mode, $sections),
                        $jsonFlags
                    );
                }
            ),

            new McpTool(
                name: 'flow_lookup',
                description: <<<'DESC'
[STEP 2 - PRIMARY] Use after flow_map to inspect one or more targets in depth without dumping the whole project.

SINGLE TARGET (default, backward-compatible):
  Pass `target` (string) to look up exactly one item. Returns a focused ProjectLookupDTO.
  Not-found throws an error — use flow_find first if you are unsure of the exact identifier.

BATCH (multiple targets):
  Pass `targets` as a JSON array of FQN strings to inspect several items in one call.
  Examples (PHP):  "targets": ["App\\Http\\Controllers\\UserController::index", "App\\Services\\UserService::create"]
  Examples (TS):   "targets": ["typescript:user%2Eservice.UserService::getUser", "typescript:helpers::formatDate"]
  Examples (Python): "targets": ["python:App.src.sample::a", "python:App.src.sample.C::m"]
  - Results are returned in input order; duplicates are deduplicated before processing.
  - Not-found items are collected in `notFound[]` and do NOT abort the other results.
  - `limit` caps how many are processed (default 20, clamp 1–50). Excess goes to `droppedTargets[]`.
  - When batch size > 5, `format` is coerced to `summary` automatically (warning added).
  - `format=diagram` is PROHIBITED in batch mode (diagram is a single-target visual).

ALL TARGETS (enumerate every item of a type):
  Pass `targets: "all"` (string literal) to auto-expand all known items for the given targetType.
  Example: "targets": "all", "targetType": "boundary"
  - Supported targetTypes: area, entrypoint, boundary, service (catalog only).
  - REJECTED targetTypes: node ("too many nodes — use flow_find or targetType=entrypoint"),
    path ("path requires a single entrypoint — not batchable").
  - `limit` still applies (default 20).

MUTUAL EXCLUSION: Provide EITHER `target` OR `targets`, never both.
  Missing both → error: "Missing required parameter: target or targets"
  Both present  → error: "Provide either target or targets, not both"

RETURN SHAPE:
  - Single `target` → kind="lookup_result" (ProjectLookupDTO, same as before)
  - `targets` array or "all" → kind="lookup_batch_result" (ProjectBatchLookupDTO)
DESC . ' ' . $supportSummary,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'project' => [
                            'type' => 'string',
                            'description' => 'Absolute path to one project root. Mutually exclusive with catalog.',
                        ],
                        'catalog' => [
                            'type' => 'string',
                            'description' => 'Path to a flow-services.json catalog for multi-service lookup. Mutually exclusive with project.',
                        ],
                        'targetType' => [
                            'type' => 'string',
                            'description' => 'Required. One of: area, entrypoint, node, service, boundary, path. '
                                . 'Note: targets="all" is NOT supported for node or path — use flow_find or targetType=entrypoint instead.',
                        ],
                        'target' => [
                            'type' => 'string',
                            'description' => 'Single target identifier (FQN, area name, boundary type, etc.) returned by flow_map. '
                                . 'Mutually exclusive with `targets`. Not-found throws an error.',
                        ],
                        'targets' => [
                            'oneOf' => [
                                [
                                    'type'  => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                [
                                    'type' => 'string',
                                    'enum' => ['all'],
                                ],
                            ],
                            'description' => 'List of target identifiers OR the string literal "all". '
                                . 'Mutually exclusive with `target`. '
                                . 'Array: ["FQN1","FQN2",...] — batch lookup, not-found collected in notFound[]. '
                                . '"all": auto-expands all known identifiers for the given targetType (area/entrypoint/boundary/service). '
                                . 'limit applies in both cases. format=diagram is prohibited; format coerced to summary when >5 items.',
                        ],
                        'format' => [
                            'type' => 'string',
                            'description' => 'Output format: summary (default), context, or diagram. '
                                . 'In batch/all mode: diagram is prohibited; summary is auto-enforced when >5 targets.',
                        ],
                        'depth' => [
                            'type' => 'integer',
                            'description' => 'Structural traversal depth. Default: 1.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of items to process in batch or all mode. Default: 20, clamp [1, 50]. Ignored when using single `target`.',
                        ],
                    ],
                    'required' => ['targetType'],
                ],
                handler: function (array $args) use ($languageSupportCatalog): string {
                    $scope = $this->resolveScope($args);
                    $depth = $this->normalizeDepth($args['depth'] ?? 1);
                    $targetType = strtolower(trim((string) ($args['targetType'] ?? '')));

                    [$isBatch, $resolvedTargets] = $this->normalizeLookupTargets($args);

                    // Batch/all: validate format restrictions up front
                    if ($isBatch) {
                        $limit = $this->normalizeLookupLimit($args['limit'] ?? 20);
                        $warnings = [];

                        $rawFormat = strtolower(trim((string) ($args['format'] ?? 'summary')));
                        if ($rawFormat === 'diagram') {
                            throw new \InvalidArgumentException(
                                'format=diagram is only valid with a single target. '
                                . 'In batch or all mode use format=summary or format=context.'
                            );
                        }

                        $format = $this->normalizeLookupFormat($rawFormat !== '' ? $rawFormat : 'summary');

                        // Early coerce for explicit lists (>5 before expand); "all" (null) is coerced after expand
                        if ($resolvedTargets !== null && count($resolvedTargets) > 5 && $format !== 'summary') {
                            $warnings[] = 'Batch size > 5: format coerced to summary.';
                            $format = 'summary';
                        }

                        $builder = new ProjectLookupBuilder();

                        if ($scope === 'catalog') {
                            $catalogPath = $this->normalizeCatalogPath((string) ($args['catalog'] ?? ''));
                            $services = (new CatalogServiceBuilder())->buildServices($catalogPath, true);
                            $appmap = (new ApplicationMapBuilder())->build($services);

                            // Expand "all" for catalog
                            if ($resolvedTargets === null) {
                                $resolvedTargets = $builder->expandAllCatalogTargets($services, $targetType, $appmap);
                            }

                            // Coerce format=summary when >5 targets (may have grown after expand)
                            if (count($resolvedTargets) > 5 && $format !== 'summary') {
                                $warnings[] = 'Batch size > 5 after "all" expansion: format coerced to summary.';
                                $format = 'summary';
                            }

                            $batchResult = $builder->lookupCatalogBatch(
                                $catalogPath,
                                $appmap,
                                $services,
                                $targetType,
                                $resolvedTargets,
                                $format,
                                $depth,
                                $limit
                            );

                            $payload = $batchResult->toArray();
                            $payload['warnings'] = array_merge($warnings, $payload['warnings']);
                            $payload = $this->attachCatalogMetadata($payload, $services);

                            return $this->encodeJson(
                                $payload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                            );
                        }

                        $projectPath = $this->normalizeProjectPath((string) ($args['project'] ?? ''));
                        $this->assertProjectPathExists($projectPath);
                        [$container, $stalenessReport] = $this->prepareProject($projectPath);
                        $flow = $container->getFlow();

                        // Expand "all" for project
                        if ($resolvedTargets === null) {
                            $resolvedTargets = $builder->expandAllTargets($flow, $targetType);
                        }

                        // Coerce format=summary when >5 targets (may have grown after expand)
                        if (count($resolvedTargets) > 5 && $format !== 'summary') {
                            $warnings[] = 'Batch size > 5: format coerced to summary.';
                            $format = 'summary';
                        }

                        $batchResult = $builder->lookupProjectBatch(
                            $projectPath,
                            $flow,
                            $targetType,
                            $resolvedTargets,
                            $format,
                            $depth,
                            $limit
                        );

                        $payload = $batchResult->toArray();
                        $payload['warnings'] = array_merge($warnings, $payload['warnings']);
                        $payload = $this->attachProjectWarnings($payload, $container, $stalenessReport);

                        $payload = $this->attachProjectMetadata($payload, $container->configResolution());

                        return $this->encodeJson(
                            $payload,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        );
                    }

                    // --- Single target path (original behavior, backward-compatible) ---
                    $target = trim((string) ($args['target'] ?? ''));
                    $format = $this->normalizeLookupFormat($args['format'] ?? 'summary');
                    $builder = new ProjectLookupBuilder();

                    if ($scope === 'catalog') {
                        $catalogPath = $this->normalizeCatalogPath((string) ($args['catalog'] ?? ''));
                        $services = (new CatalogServiceBuilder())->buildServices($catalogPath, true);
                        $appmap = (new ApplicationMapBuilder())->build($services);
                        $result = $builder->lookupCatalog($catalogPath, $appmap, $services, $targetType, $target, $format, $depth);
                        if (is_string($result)) {
                            return $this->prefixCatalogFallbackNote($result, $services);
                        }

                        return $this->encodeJson(
                            $this->attachCatalogMetadata($result->toArray(), $services),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        );
                    }

                    $projectPath = $this->normalizeProjectPath((string) ($args['project'] ?? ''));
                    $this->assertProjectPathExists($projectPath);
                    [$container, $stalenessReport] = $this->prepareProject($projectPath);

                    $result = $builder->lookupProject($projectPath, $container->getFlow(), $targetType, $target, $format, $depth);

                    if (is_string($result)) {
                        $diagram = $this->prefixDiagramFallbackNote($result, $container->configResolution());
                        return $this->prefixProjectWarnings($diagram, $container, $stalenessReport);
                    }

                    $resultArray = $this->attachProjectWarnings(
                        $result->toArray(),
                        $container,
                        $stalenessReport,
                    );

                    return $this->encodeJson(
                        $this->attachProjectMetadata($resultArray, $container->configResolution()),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                }
            ),

            new McpTool(
                name: 'flow_find',
                description: '[STEP 2 - PRIMARY ALT] Use after flow_map when you know an approximate class or method name but not the exact ID. Pass query for one term or queries for several terms in one analyzed project. Returns matching nodes by substring, grouped by class. Also searches imports and top-level identifiers (type=symbol) — ideal for locating framework icons, utility functions, or grep-style searches (e.g. TriangleAlertIcon, lastError). Complements flow_lookup (which requires an exact ID): use flow_find first to discover the ID, then flow_lookup for depth. ' . $supportSummary,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to one project root.'],
                        'query'   => ['type' => 'string', 'description' => 'Single non-empty case-insensitive substring to search for in class and method names. Mutually exclusive with queries.'],
                        'queries' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Batch of case-insensitive substrings to search in one analyzed project. Mutually exclusive with query.',
                        ],
                        'type'    => ['type' => 'string', 'description' => 'Optional filter: class, method, namespace, or symbol. Default: all. Use symbol to search imports and top-level identifiers (functions, consts).'],
                        'limit'   => ['type' => 'integer', 'description' => 'Max results to return (default 10, max 50).'],
                    ],
                    'required' => ['project'],
                ],
                handler: function (array $args): string {
                    $projectPath = $this->normalizeProjectPath((string) ($args['project'] ?? ''));
                    $this->assertProjectPathExists($projectPath);
                    [$isBatch, $queries] = $this->normalizeFindQueries($args);
                    $type = isset($args['type']) ? strtolower(trim((string) $args['type'])) : null;
                    $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));

                    if ($type !== null && !in_array($type, ['class', 'method', 'namespace', 'symbol'], true)) {
                        throw new \InvalidArgumentException(
                            "Invalid type '{$type}': must be one of class, method, namespace, symbol."
                        );
                    }

                    [$container, $stalenessReport] = $this->prepareProject($projectPath);

                    $builder = new ProjectFindBuilder();
                    $result = $isBatch
                        ? $builder->findManyInProject($projectPath, $container->getFlow(), $queries, $type ?: null, $limit)
                        : $builder->findInProject($projectPath, $container->getFlow(), $queries[0], $type ?: null, $limit);

                    $result = $this->attachProjectWarnings($result, $container, $stalenessReport);

                    return $this->encodeJson($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            ),

            new McpTool(
                name: 'flow_context',
                description: '[STEP 3 - SECONDARY] Use after flow_map ONLY when the target is an area, namespace, or entrypoint subgraph (not a single node). For single-node inspection prefer flow_lookup or flow_find. Returns focused architectural context as Markdown for one project. ' . $supportSummary,
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'project'    => ['type' => 'string', 'description' => 'Absolute path to the project root.'],
                        'entrypoint' => ['type' => 'string', 'description' => 'Optional entrypoint identifier (FQN, e.g. PHP `Namespace\\Class::method` or `<lang>:<module.path>::<member>` such as `typescript:user%2Eservice.UserService::getUser`) to scope the context subgraph.'],
                        'namespace'  => ['type' => 'string', 'description' => 'Optional namespace filter.'],
                        'minimal'    => ['type' => 'boolean', 'description' => 'When true, return a compact context (metrics only).'],
                    ],
                    'required' => ['project'],
                ],
                handler: function (array $args): string {
                    [$container, $stalenessReport] = $this->prepareProject((string) $args['project']);

                    $minimal    = (bool) ($args['minimal'] ?? false);
                    $entrypoint = $args['entrypoint'] ?? null;
                    $namespace  = $args['namespace'] ?? null;

                    if ($minimal) {
                        $markdown = $this->buildMinimalContextMarkdown(
                            $container->getFlow(),
                            is_string($entrypoint) && $entrypoint !== '' ? $entrypoint : null,
                            is_string($namespace) && $namespace !== '' ? $namespace : null
                        );

                        $markdown = $this->prefixContextFallbackNote($markdown, $container->configResolution());
                        return $this->prefixProjectWarnings($markdown, $container, $stalenessReport);
                    }

                    $options = $minimal ? ExportOptions::minimal() : ExportOptions::all();

                    if ($entrypoint !== null || $namespace !== null) {
                        $options = new ExportOptions(
                            includeMetrics:      !$minimal,
                            includeComplexity:   !$minimal,
                            includeCycles:       !$minimal,
                            includeArchitecture: !$minimal,
                            includeOrphans:      !$minimal,
                            focusNamespace:      $namespace,
                            entrypoint:          $entrypoint,
                        );
                    }

                    $dto = $container->exportContext()->execute($options);
                    $markdown = $dto->markdown;

                    $markdown = $this->prefixContextFallbackNote($markdown, $container->configResolution());
                    return $this->prefixProjectWarnings($markdown, $container, $stalenessReport);
                }
            ),

            new McpTool(
                name: 'flow_impact',
                description: '[ON-DEMAND] Use only when you already identified a node you may change and need its blast radius (callers + callees). Returns deterministic caller/callee impact data as JSON. ' . $supportSummary,
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to the project root.'],
                        'node'    => ['type' => 'string', 'description' => self::NODE_ID_DESCRIPTION],
                    ],
                    'required' => ['project', 'node'],
                ],
                handler: function (array $args): string {
                    [$container, $stalenessReport] = $this->prepareProject((string) $args['project']);
                    $dto = $container->assessNodeImpact()->execute($args['node']);

                    $payload = $this->attachProjectWarnings($dto->toArray(), $container, $stalenessReport);

                    return $this->encodeJson(
                        $this->attachMetadataOnly($payload, $container->configResolution()),
                        JSON_THROW_ON_ERROR
                    );
                }
            ),

            new McpTool(
                name: 'flow_risk',
                description: '[ON-DEMAND] Use after flow_map/flow_lookup when you are evaluating the risk of a specific code change. Returns deterministic change-risk factors for one node. ' . $supportSummary,
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to the project root.'],
                        'node'    => ['type' => 'string', 'description' => self::NODE_ID_DESCRIPTION],
                    ],
                    'required' => ['project', 'node'],
                ],
                handler: function (array $args): string {
                    [$container, $stalenessReport] = $this->prepareProject((string) $args['project']);
                    $dto = $container->scoreChangeRisk()->execute($args['node']);

                    $payload = $this->attachProjectWarnings($dto->toArray(), $container, $stalenessReport);

                    return $this->encodeJson(
                        $this->attachMetadataOnly($payload, $container->configResolution()),
                        JSON_THROW_ON_ERROR
                    );
                }
            ),

            new McpTool(
                name: 'flow_refactor_plan',
                description: '[ON-DEMAND] Use only after you have identified the specific node that should change. Returns a deterministic refactor plan grounded in the analyzed graph. ' . $supportSummary,
                inputSchema: [
                    'type'       => 'object',
                    'properties' => [
                        'project' => ['type' => 'string', 'description' => 'Absolute path to the project root.'],
                        'node'    => ['type' => 'string', 'description' => self::NODE_ID_DESCRIPTION],
                    ],
                    'required' => ['project', 'node'],
                ],
                handler: function (array $args): string {
                    [$container, $stalenessReport] = $this->prepareProject((string) $args['project']);
                    $dto = $container->generateRefactorPlan()->execute($args['node']);

                    $payload = $this->attachProjectWarnings($dto->toArray(), $container, $stalenessReport);

                    return $this->encodeJson(
                        $this->attachMetadataOnly($payload, $container->configResolution()),
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                    );
                }
            ),

        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleProjectCapabilities(LanguageSupportCatalog $languageSupportCatalog, Container $container): array
    {
        $configuredScanLanguages = $languageSupportCatalog->supportedConfiguredLanguages($container->configuredScanExtensions());
        $detectedProjectLanguages = $languageSupportCatalog->detectFromFiles($container->projectFiles());

        return [
            'supportedLanguages' => $languageSupportCatalog->supportedLanguagesPayload(),
            'configuredScanLanguages' => $configuredScanLanguages,
            'detectedProjectLanguages' => $detectedProjectLanguages,
            'summary' => $languageSupportCatalog->payloadSummary(),
        ];
    }

    /**
     * @return array{Container, StalenessReport}
     */
    private function prepareProject(string $projectPath): array
    {
        $projectPath = $this->normalizeProjectPath($projectPath);
        $this->assertProjectPathExists($projectPath);

        $container = new Container($projectPath, true);
        $stalenessReport = $container->checkStaleness()->execute();
        $container->analyzeProject()->execute();

        return [$container, $stalenessReport];
    }

    /** @param array<string, mixed> $payload */
    private function attachProjectWarnings(
        array $payload,
        Container $container,
        StalenessReport $stalenessReport,
    ): array {
        $warnings = array_merge(
            is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
            $container->analysisWarnings(),
        );

        if ($stalenessReport->stale) {
            $payload['staleness'] = $stalenessReport->toArray();
            $warnings[] = $stalenessReport->summaryWarning();
        }

        $payload['warnings'] = array_values(array_unique(array_filter($warnings, 'is_string')));
        return $payload;
    }

    private function prefixProjectWarnings(
        string $markdown,
        Container $container,
        StalenessReport $stalenessReport,
    ): string {
        $warnings = $container->analysisWarnings();
        if ($stalenessReport->stale) {
            $warnings[] = $stalenessReport->summaryWarning();
        }
        $warnings = array_values(array_unique($warnings));
        if ($warnings === []) {
            return $markdown;
        }

        $prefix = implode("\n", array_map(
            static fn(string $warning): string => '> **Warning:** ' . $warning,
            $warnings,
        ));

        return $prefix . "\n\n" . $markdown;
    }

    /**
     * @param array<int, \FlowEngine\Application\AppMap\ServiceInfo> $services
     * @return array<string, mixed>
     */
    private function catalogCapabilities(LanguageSupportCatalog $languageSupportCatalog, array $services): array
    {
        $configured = [];
        $detected = [];

        foreach ($services as $service) {
            foreach ($service->configuredScanLanguages as $languageId) {
                $configured[$languageId] = true;
            }
            foreach ($service->languages() as $languageId) {
                $detected[$languageId] = true;
            }
        }

        $configuredLanguages = $languageSupportCatalog->sortLanguageIds(array_keys($configured));
        $detectedProjectLanguages = $languageSupportCatalog->sortLanguageIds(array_keys($detected));

        return [
            'supportedLanguages' => $languageSupportCatalog->supportedLanguagesPayload(),
            'configuredScanLanguages' => $configuredLanguages,
            'detectedProjectLanguages' => $detectedProjectLanguages,
            'summary' => $languageSupportCatalog->payloadSummary(),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return 'single'|'catalog'
     */
    private function resolveScope(array $args): string
    {
        $project = trim((string) ($args['project'] ?? ''));
        $catalog = trim((string) ($args['catalog'] ?? ''));
        $scope = strtolower(trim((string) ($args['scope'] ?? 'auto')));

        if ($project !== '' && $catalog !== '') {
            throw new \InvalidArgumentException('Provide either project or catalog, not both.');
        }

        if ($project === '' && $catalog === '') {
            throw new \InvalidArgumentException('Missing required parameter: project or catalog');
        }

        if ($scope !== '' && !in_array($scope, ['auto', 'single', 'catalog'], true)) {
            throw new \InvalidArgumentException('Invalid scope. Use auto, single, or catalog.');
        }

        return $scope === 'catalog' || ($scope === 'auto' && $catalog !== '')
            ? 'catalog'
            : 'single';
    }

    private function normalizeMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === '') {
            return 'summary';
        }
        if (!in_array($mode, ['summary', 'full'], true)) {
            throw new \InvalidArgumentException('Invalid mode. Use summary or full.');
        }

        return $mode;
    }

    /**
     * @return string[]
     */
    private function normalizeInfraSections(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/\s*,\s*/', $value) ?: [];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            throw new \InvalidArgumentException('sections must be a comma-separated string or an array.');
        }

        $sections = [];
        foreach ($items as $item) {
            $section = strtolower(trim((string) $item));
            if ($section === '') {
                continue;
            }
            if ($section === 'all') {
                return ['all'];
            }
            if (!in_array($section, ['files', 'docker', 'proxy', 'web', 'scripts'], true)) {
                throw new \InvalidArgumentException('Invalid sections value. Use all, files, docker, proxy, web, or scripts.');
            }
            $sections[] = $section;
        }

        return $sections === [] ? ['all'] : array_values(array_unique($sections));
    }

    private function normalizeLookupFormat(mixed $value): string
    {
        $format = strtolower(trim((string) $value));
        if ($format === '') {
            return 'summary';
        }
        if (!in_array($format, ['summary', 'context', 'diagram'], true)) {
            throw new \InvalidArgumentException('Invalid format. Use summary, context, or diagram.');
        }

        return $format;
    }

    /**
     * Resolve the `target` / `targets` input into a [isBatch, targets|null] tuple.
     *
     * Returns:
     *   [false, null]         — single `target` mode (original path, no batch)
     *   [true, string[]]      — batch mode with explicit list (may be empty after dedup)
     *   [true, null]          — all mode ("all" string literal; caller must expand)
     *
     * @param array<string, mixed> $args
     * @return array{bool, string[]|null}
     */
    private function normalizeLookupTargets(array $args): array
    {
        $hasSingular = array_key_exists('target', $args) && trim((string) ($args['target'] ?? '')) !== '';
        $hasTargets  = array_key_exists('targets', $args);

        if ($hasSingular && $hasTargets) {
            throw new \InvalidArgumentException(
                'Provide either target or targets, not both.'
            );
        }

        if (!$hasSingular && !$hasTargets) {
            throw new \InvalidArgumentException(
                'Missing required parameter: target or targets. '
                . 'Pass target="FQN" for a single lookup, targets=["FQN1","FQN2"] for batch, '
                . 'or targets="all" to enumerate all items of the given targetType.'
            );
        }

        if ($hasSingular) {
            return [false, null];
        }

        // targets is present
        $raw = $args['targets'];

        if (is_string($raw)) {
            if (trim($raw) === 'all') {
                return [true, null]; // null signals "expand all"
            }
            throw new \InvalidArgumentException(
                'targets as a string only accepts the literal "all". '
                . 'To look up a single item use `target` instead. '
                . 'To look up multiple items pass targets as a JSON array.'
            );
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException(
                'targets must be a JSON array of FQN strings or the string literal "all".'
            );
        }

        $list = array_values(array_map('strval', $raw));

        return [true, $list];
    }

    /**
     * Resolve the `query` / `queries` input into a [isBatch, queries] tuple.
     *
     * @param array<string, mixed> $args
     * @return array{bool, string[]}
     */
    private function normalizeFindQueries(array $args): array
    {
        $hasQuery = array_key_exists('query', $args);
        $hasQueries = array_key_exists('queries', $args);

        if ($hasQuery && $hasQueries) {
            throw new \InvalidArgumentException(
                'Provide either query or queries, not both.'
            );
        }

        if (!$hasQuery && !$hasQueries) {
            throw new \InvalidArgumentException(
                'Missing required parameter: query or queries. '
                . 'Pass query="Name" for a single search or queries=["Name1","Name2"] for batch search.'
            );
        }

        if ($hasQuery) {
            $query = trim((string) ($args['query'] ?? ''));
            if ($query === '') {
                throw new \InvalidArgumentException('query must not be empty.');
            }

            return [false, [$query]];
        }

        $raw = $args['queries'];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('queries must be a JSON array of non-empty strings.');
        }

        if ($raw === []) {
            throw new \InvalidArgumentException('queries must contain at least one search term.');
        }

        $queries = [];
        foreach ($raw as $query) {
            if (!is_string($query)) {
                throw new \InvalidArgumentException('queries must be a JSON array of non-empty strings.');
            }

            $query = trim($query);
            if ($query === '') {
                throw new \InvalidArgumentException('queries must not contain empty search terms.');
            }

            if (!in_array($query, $queries, true)) {
                $queries[] = $query;
            }
        }

        return [true, $queries];
    }

    /**
     * Clamp a user-supplied limit to [1, 50], defaulting to 20.
     */
    private function normalizeLookupLimit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1) {
            return 20;
        }

        return min(50, $limit);
    }

    private function normalizeDepth(mixed $value): int
    {
        $depth = (int) $value;
        if ($depth < 1) {
            return 1;
        }

        return $depth;
    }

    private function normalizeProjectPath(string $projectPath): string
    {
        if ($projectPath === '') {
            throw new \InvalidArgumentException('Missing required parameter: project');
        }

        return realpath($projectPath) ?: $projectPath;
    }

    /**
     * Throw a JSON-RPC-style exception if the resolved project path is not an existing directory.
     */
    private function assertProjectPathExists(string $projectPath): void
    {
        if (!file_exists($projectPath)) {
            throw new \InvalidArgumentException("Project path does not exist: {$projectPath}");
        }
        if (!is_dir($projectPath)) {
            throw new \InvalidArgumentException("Project path is not a directory: {$projectPath}");
        }
        if (!is_readable($projectPath)) {
            throw new \InvalidArgumentException("Project path is not readable (permission denied): {$projectPath}");
        }
    }

    /**
     * Collect warnings for removed/deprecated input parameters (e.g. include_hotspots).
     *
     * @param array<string, mixed> $args
     * @return string[]
     */
    private function extractDeprecatedWarnings(array $args): array
    {
        $warnings = [];

        if (array_key_exists('include_hotspots', $args)) {
            $warnings[] = 'include_hotspots is no longer supported; use mode=summary|full';
        }

        return $warnings;
    }

    private function normalizeCatalogPath(string $catalogPath): string
    {
        if ($catalogPath === '') {
            throw new \InvalidArgumentException('Missing required parameter: catalog');
        }

        return realpath($catalogPath) ?: $catalogPath;
    }

    private function buildMinimalContextMarkdown(FlowContract $flow, ?string $entrypoint, ?string $namespace): string
    {
        $mapBuilder = new ProjectMapBuilder();
        $nodes = $this->focusedContextNodes($flow, $entrypoint, $namespace);
        $nodeIds = array_fill_keys(array_map(static fn(Node $node): string => $node->id(), $nodes), true);
        $edges = array_values(array_filter(
            $flow->edges(),
            static fn($edge): bool => isset($nodeIds[$edge->from()]) || isset($nodeIds[$edge->to()])
        ));
        $internalEdges = array_values(array_filter(
            $edges,
            static fn($edge): bool => isset($nodeIds[$edge->from()]) && isset($nodeIds[$edge->to()])
        ));
        $entrypoints = array_values(array_filter(
            $mapBuilder->actionableEntrypoints($flow),
            static fn(Node $node): bool => isset($nodeIds[$node->id()])
        ));

        $areaCounts = [];
        foreach ($nodes as $node) {
            $area = $mapBuilder->areaNameForNode($node);
            $areaCounts[$area] = ($areaCounts[$area] ?? 0) + 1;
        }
        arsort($areaCounts);

        $boundaryCounts = [];
        foreach ($entrypoints as $node) {
            $boundary = $mapBuilder->classifyEntrypoint($node);
            $boundaryCounts[$boundary] = ($boundaryCounts[$boundary] ?? 0) + 1;
        }
        arsort($boundaryCounts);

        $fanIn = [];
        $fanOut = [];
        foreach ($flow->edges() as $edge) {
            $fanOut[$edge->from()] = ($fanOut[$edge->from()] ?? 0) + 1;
            $fanIn[$edge->to()] = ($fanIn[$edge->to()] ?? 0) + 1;
        }

        $hotspots = [];
        foreach ($nodes as $node) {
            $score = ($fanIn[$node->id()] ?? 0) + ($fanOut[$node->id()] ?? 0);
            if ($score === 0) {
                continue;
            }
            $hotspots[] = [
                'id' => $node->id(),
                'fanIn' => $fanIn[$node->id()] ?? 0,
                'fanOut' => $fanOut[$node->id()] ?? 0,
                'score' => $score,
            ];
        }
        usort($hotspots, static fn(array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        $externalDependencies = [];
        foreach ($edges as $edge) {
            if (isset($nodeIds[$edge->from()]) && !isset($nodeIds[$edge->to()])) {
                $externalDependencies[$edge->to()] = ($externalDependencies[$edge->to()] ?? 0) + 1;
            }
        }
        arsort($externalDependencies);

        $title = '# Flow Engine Minimal Context';
        $focus = $entrypoint !== null
            ? "Entrypoint: `{$entrypoint}`"
            : ($namespace !== null ? "Focus: `{$namespace}`" : 'Focus: whole project');

        $lines = [
            $title,
            '',
            $focus,
            '',
            '## Metrics',
            '',
            '| Metric | Value |',
            '|---|---|',
            '| Nodes | ' . count($nodes) . ' |',
            '| Internal edges | ' . count($internalEdges) . ' |',
            '| Related edges | ' . count($edges) . ' |',
            '| Actionable entrypoints | ' . count($entrypoints) . ' |',
            '',
            '## Top Areas',
            '',
        ];

        $lines = array_merge($lines, $this->markdownCountList($areaCounts, 8, 'No areas found.'));
        $lines[] = '';
        $lines[] = '## Boundaries';
        $lines[] = '';
        $lines = array_merge($lines, $this->markdownCountList($boundaryCounts, 8, 'No actionable boundaries detected.'));
        $lines[] = '';
        $lines[] = '## Hotspots';
        $lines[] = '';

        if ($hotspots === []) {
            $lines[] = 'No coupled nodes found in this focus.';
        } else {
            foreach (array_slice($hotspots, 0, 8) as $hotspot) {
                $lines[] = sprintf(
                    '- `%s` (fan-in: %d, fan-out: %d)',
                    $hotspot['id'],
                    $hotspot['fanIn'],
                    $hotspot['fanOut']
                );
            }
        }

        $lines[] = '';
        $lines[] = '## External Dependencies';
        $lines[] = '';
        $lines = array_merge($lines, $this->markdownCountList($externalDependencies, 8, 'No outgoing dependencies outside this focus.'));
        $lines[] = '';
        $lines[] = '## Recommended Next Lookups';
        $lines[] = '';

        $recommendedLookups = $this->minimalContextRecommendedLookups($entrypoints, $areaCounts);
        if ($recommendedLookups === []) {
            $lines[] = 'No recommended lookup is available for this focus.';
        } else {
            foreach ($recommendedLookups as $lookup) {
                $lines[] = sprintf('- `%s` `%s` - %s', $lookup['targetType'], $lookup['target'], $lookup['reason']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return Node[]
     */
    private function focusedContextNodes(FlowContract $flow, ?string $entrypoint, ?string $namespace): array
    {
        if ($entrypoint !== null) {
            $ids = (new FlowTracer($flow))->extractSubgraph($entrypoint, 3);
            return array_values(array_filter(
                $flow->nodes(),
                static fn(Node $node): bool => isset($ids[$node->id()])
            ));
        }

        if ($namespace !== null) {
            return array_values(array_filter(
                $flow->nodes(),
                static fn(Node $node): bool => str_starts_with($node->class(), $namespace)
            ));
        }

        return $flow->nodes();
    }

    /**
     * @param array<string, int> $counts
     * @return string[]
     */
    private function markdownCountList(array $counts, int $limit, string $emptyText): array
    {
        if ($counts === []) {
            return [$emptyText];
        }

        $lines = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $lines[] = sprintf('- `%s`: %d', $name, $count);
        }

        return $lines;
    }

    /**
     * @param Node[] $entrypoints
     * @param array<string, int> $areaCounts
     * @return array<int, array{targetType: string, target: string, reason: string}>
     */
    private function minimalContextRecommendedLookups(array $entrypoints, array $areaCounts): array
    {
        $lookups = [];
        foreach (array_slice($entrypoints, 0, 5) as $entrypoint) {
            $lookups[] = [
                'targetType' => 'entrypoint',
                'target' => $entrypoint->id(),
                'reason' => 'Expand this runtime path with flow_lookup.',
            ];
        }

        if ($lookups !== []) {
            return $lookups;
        }

        foreach (array_slice(array_keys($areaCounts), 0, 5) as $area) {
            $lookups[] = [
                'targetType' => 'area',
                'target' => $area,
                'reason' => 'Inspect this structural area with flow_lookup.',
            ];
        }

        return $lookups;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attachProjectMetadata(array $payload, ConfigResolution $configResolution): array
    {
        $payload['metadata'] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $payload['metadata']['configResolution'] = $configResolution->toArray();

        if ($configResolution->mode !== 'inferred_read_only') {
            return $payload;
        }

        $payload['warnings'] = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
        $payload['warnings'][] = $this->projectInferenceWarning($configResolution);
        foreach ($configResolution->warnings as $warning) {
            $payload['warnings'][] = $warning;
        }
        $payload['warnings'] = array_values(array_unique($payload['warnings']));

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, \FlowEngine\Application\AppMap\ServiceInfo> $services
     * @return array<string, mixed>
     */
    private function attachCatalogMetadata(array $payload, array $services): array
    {
        $payload['metadata'] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $payload['metadata']['configResolution'] = $this->catalogConfigResolution($services);

        $warnings = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
        $staleServices = [];
        foreach ($services as $service) {
            foreach ($service->analysisWarnings as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = sprintf('%s: %s', $service->name, $warning);
                }
            }
            if ($service->staleness !== null) {
                $staleServices[$service->name] = $service->staleness;
            }
        }
        if ($staleServices !== []) {
            $payload['metadata']['staleness'] = $staleServices;
        }

        $inferred = array_values(array_filter(
            $services,
            static fn($service): bool => (($service->configResolution['mode'] ?? 'explicit_config') === 'inferred_read_only')
        ));

        if ($inferred !== []) {
            $warnings[] = sprintf(
                'Catalog includes services analyzed without flow-engine.json: %s.',
                implode(', ', array_map(static fn($service): string => $service->name, $inferred))
            );

            foreach ($inferred as $service) {
                foreach (($service->configResolution['warnings'] ?? []) as $warning) {
                    if (is_string($warning) && $warning !== '') {
                        $warnings[] = sprintf('%s: %s', $service->name, $warning);
                    }
                }
            }
        }

        if ($warnings !== []) {
            $payload['warnings'] = array_values(array_unique($warnings));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attachMetadataOnly(array $payload, ConfigResolution $configResolution): array
    {
        $payload['metadata'] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $payload['metadata']['configResolution'] = $configResolution->toArray();

        return $payload;
    }

    private function prefixContextFallbackNote(string $markdown, ConfigResolution $configResolution): string
    {
        if ($configResolution->mode !== 'inferred_read_only') {
            return $markdown;
        }

        return '> Note: ' . $this->projectInferenceWarning($configResolution) . "\n\n" . $markdown;
    }

    private function prefixDiagramFallbackNote(string $markdown, ConfigResolution $configResolution): string
    {
        if ($configResolution->mode !== 'inferred_read_only') {
            return $markdown;
        }

        return "Note: " . $this->projectInferenceWarning($configResolution) . "\n\n" . $markdown;
    }

    /**
     * @param array<int, \FlowEngine\Application\AppMap\ServiceInfo> $services
     */
    private function prefixCatalogFallbackNote(string $markdown, array $services): string
    {
        $warnings = [];
        foreach ($services as $service) {
            foreach ($service->analysisWarnings as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $warnings[] = sprintf('%s: %s', $service->name, $warning);
                }
            }
        }

        $inferred = array_values(array_filter(
            $services,
            static fn($service): bool => (($service->configResolution['mode'] ?? 'explicit_config') === 'inferred_read_only')
        ));

        if ($inferred !== []) {
            $warnings[] = sprintf(
                'Catalog includes services analyzed without flow-engine.json: %s.',
                implode(', ', array_map(static fn($service): string => $service->name, $inferred))
            );
        }

        if ($warnings === []) {
            return $markdown;
        }

        return implode("\n", array_map(
            static fn(string $warning): string => '> **Warning:** ' . $warning,
            array_values(array_unique($warnings)),
        )) . "\n\n" . $markdown;
    }

    private function projectInferenceWarning(ConfigResolution $configResolution): string
    {
        return sprintf(
            'No flow-engine.json found; using dynamic inferred read-only scan (context: %s, include: %s, extensions: %s)',
            $configResolution->detectedContext,
            $this->csvOrFallback($configResolution->includePaths, 'none'),
            $this->csvOrFallback($configResolution->extensions, 'none')
        );
    }

    /**
     * @param array<int, \FlowEngine\Application\AppMap\ServiceInfo> $services
     * @return array<string, mixed>
     */
    private function catalogConfigResolution(array $services): array
    {
        $servicePayload = array_map(
            static fn($service): array => [
                'name' => $service->name,
                'mode' => $service->configResolution['mode'] ?? 'explicit_config',
                'detectedContext' => $service->configResolution['detectedContext'] ?? 'unknown',
                'hasConfigFile' => (bool) ($service->configResolution['hasConfigFile'] ?? false),
            ],
            $services
        );

        $modes = array_values(array_unique(array_map(
            static fn(array $item): string => $item['mode'],
            $servicePayload
        )));

        return [
            'mode' => count($modes) === 1 ? $modes[0] : 'mixed',
            'services' => $servicePayload,
        ];
    }

    /**
     * @param string[] $items
     */
    private function csvOrFallback(array $items, string $fallback): string
    {
        return $items === [] ? $fallback : implode(', ', $items);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload, int $flags): string
    {
        return json_encode($payload, $flags);
    }

    // -------------------------------------------------------------------------
    // Protocol helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|object $result
     * @return array<string, mixed>
     */
    private function respond(mixed $id, array|object $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }

    private function writeResponse(array $response): void
    {
        fwrite(STDOUT, json_encode($response) . "\n");
    }
}
