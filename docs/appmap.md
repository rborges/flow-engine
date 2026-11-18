# Application Map Output

`appmap` (CLI) and `GET /api/v1/appmap` (local API) return the same payload. Its contract is
[`appmap-output.schema.v2.json`](../appmap-output.schema.v2.json) (JSON Schema 2020-12), and a test
validates real output against it, so a format change fails CI before it reaches a consumer.
[`appmap-output.schema.v1.json`](../appmap-output.schema.v1.json) stays to validate maps produced before v2,
which have no `schemaVersion`.

```bash
php bin/engine.php appmap <service-a> <service-b>
php bin/engine.php appmap --catalog=flow-services.json
```

`appmap` prints the map even when a service fails to analyze; it then reports each failure on stderr
and exits with code 1. Usage errors are plain text, not JSON.

## Envelope

| Field | Type | Notes |
|---|---|---|
| `status` | `"ok"` | |
| `appmap.schemaVersion` | `"2.0"` | version of this contract; a consumer refuses a major version it does not know |
| `appmap.sourceVersion` | string | version of the engine that produced the map (the MCP server reports the same) |
| `appmap.generatedAt` | ISO 8601 string | the only field that changes between two runs over the same commits |
| `appmap.services` | array | one entry per analyzed service |
| `appmap.integrationEdges` | array | every call site found, including unresolved and asset ones |
| `appmap.serviceEdges` | array | service-to-service dependencies aggregated from `integrationEdges` |
| `appmap.inconsistencies` | array | script and OpenAPI contract mismatches |
| `appmap.warnings` | array | services analyzed with zero nodes or that failed; always present |

## Services

| Field | Notes |
|---|---|
| `id`, `name` | service name (same value) |
| `root` | absolute path |
| `repository` | URL of the `origin` remote of the repository containing `root`, without user or password; `null` without git, without `origin`, or when git refuses the repository (owned by another user) |
| `commitSha` | `HEAD` of that repository; uncommitted changes are not reflected; `null` without git or without commits |
| `languages`, `technology` | languages of the scanned files; `technology` is the first one or `"unknown"` |
| `domain` | base name of the root directory |
| `kind`, `external` | always `"service"` and `false` |
| `status` | `ok` or `failed` |
| `error` | only when `status` is `failed`: exception class and message |
| `hostnames` | Docker hostnames and aliases used to resolve HTTP targets |
| `stats.nodeCount`, `stats.edgeCount` | size of the service graph |
| `endpoints[]` | HTTP routes found in code: `method`, `path`, `handler` (node id). Laravel routes are read from the route files, see below |
| `contract.endpointCount` | only when the catalog declares an OpenAPI contract |
| `configResolution` | how the scan was configured: `mode` (`explicit_config` or `inferred_read_only`), `configPath`, `hasConfigFile`, `detectedContext`, `includePaths`, `ignoredPaths`, `extensions`, `warnings` |

A failed service keeps its entry, with zero nodes, so the rest of the map stays usable.
`appmap-diff` and the drift endpoints refuse maps with failed services.

Routes come from nodes with `http_method`/`http_path` (FastAPI, Flask, Go, Nest/Express) and, for a
service with `artisan` at its root, from the Laravel route files, read even when `routes/` is outside
`scan.include`. The files and their base prefix come from `app/Providers/RouteServiceProvider.php`
(`->group(base_path(...))` or `require base_path(...)` inside a group), else from `withRouting()` in
`bootstrap/app.php`, else `routes/web.php` and `routes/api.php` (prefix `api`). Groups nest `prefix`,
`namespace` and `controller`; the handler is `Controller::method`. Closure routes have no handler node
and are left out.

## Integration Edges

| Field | Notes |
|---|---|
| `type` | `http` (URL literal) or `script` (PHP `exec`/`shell_exec`/`system`/`passthru`/`proc_open` running a `.py` file) |
| `fromService`, `toService` | `toService` is resolved by hostname (http) or script path (script); `null` when external or unknown |
| `fromNode` | id of the node containing the call, see below |
| `toNode` | id of the handler in `toService`, see below; `null` when unresolved and for `script` |
| `fromFile`, `fromLine` | absolute path and 1-based line |
| `target` | the raw URL or script path; there is no `metadata.url` |
| `resolvedPath` | absolute script path when it exists; `null` for http |
| `metadata` | http: `scheme`, `host`, `port` (`0` when absent), `path`, `query`, `kind`, plus `httpMethod` when the call names it (Dart). script: `command` |

The field is `fromNode`, not `fromNodeId` (the PHP property name).

`fromNode` is the innermost method or function whose line range contains the call. In procedural PHP
outside any function it is the file node `file:<path relative to the service root>`. It is `::unknown`
only when the file has no node at all (for example a URL in a class property of a file whose class has
no methods around it). Python nodes have no end line, so a Python call is attributed to the nearest
preceding function.

`toNode` is the `handler` of the `toService` endpoint whose path matches `metadata.path` segment by
segment: `{p}` matches any segment, `{p?}` may be missing at the end, a call segment built at runtime
(`${id}`) matches any segment, and `metadata.httpMethod`, when present, must match. Among several, the one
with more literal segments wins. With no endpoint and a path ending in `.php`, it is the `file:` node of
the target whose path ends with it (legacy PHP without router). A URL with no path or just `/` gets
`null`: it is usually a link or a base URL concatenated at runtime, not a call to the root route.

`metadata.kind` is `asset` for static files (images, fonts, `css`, `js`, `map`, `ico`, `svg`, `pdf`)
and for well-known CDNs, and `http` otherwise. Asset edges stay in `integrationEdges` but never count in
`serviceEdges`. URLs inside comments, docblocks and Python docstrings are ignored. URLs are detected in
PHP, Dart, Python, TypeScript/JavaScript and Go.

### URLs in configuration files

Configuration files are read from the service root even when they are outside the scan: `.env`
examples (`.env.example`, `.env.dist`, `.env.sample`, `.env.<name>.example`; never `.env` or
`.env.<environment>`), `config/*.php` of a Laravel app (a folder next to `artisan`) and `*.ini`.
Hidden folders (worktrees included) and dependency, build, cache and test folders are skipped, and a
file already in the scan is not read twice. These edges have:

- `metadata.source`: `config`;
- `metadata.configKey`: the variable or key of the line (for `env('X', ...)` in PHP, `X`), when there is one;
- `metadata.commented`: `true` for a commented line of an `.env` example, which usually holds the value
  of another environment; these edges never count in `serviceEdges`. Comments in PHP and `.ini` are
  ignored;
- `fromNode`: `file:<path relative to the service root>`;
- `target`, `path` and `query`: only scheme, host and port are kept, with an empty path and query,
  because paths and queries in configuration often carry secrets (webhook paths, tokens). `kind` is
  computed from the real path before it is dropped.

A line is skipped when a word of its key names a secret (`PASS`, `CREDENTIAL`, or a word ending in `KEY`,
`SECRET`, `TOKEN`, `PASSWORD`, `PWD`), unless another word says it is an address (`URL`, `URI`,
`ENDPOINT`, `HOST`), as in `AUTH_URL_TOKEN`. `PASSPORT_SERVER` and `KEYCLOAK_BASE` are not secrets.
A comma ends a URL (lists of origins), and a host built by interpolation (`${APP_HOST}`) is ignored.

## Order

Arrays come in a fixed order, so two runs over the same commits differ only in `generatedAt`: services by
name; `endpoints` by path, method (`GET`, `ANY`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, then
the others) and handler; `integrationEdges` by `fromService`, `fromFile`, `fromLine`,
`type` and `target`; `serviceEdges` by `from`, `to` and `type`; `inconsistencies` by type and content;
`warnings` follow the services. When two endpoints match a call equally, `toNode` is the first in this order,
so a call that does not name its method resolves to `GET` before `DELETE`. Two services declaring the same
hostname resolve to the first by name.

`repository` and `commitSha` are read only by the commands that publish this payload (`appmap` and
`GET /api/v1/appmap`); `diagram`, `context`, the other API endpoints and the MCP tools build the map without
running git.

## Service Edges

`{from, to, type, count}`: one entry per service pair and edge type, counting the non-asset
`integrationEdges` between them.

## Inconsistencies

Each entry has `type`, `severity` (`high` or `medium`) and `message`, plus context fields that depend
on the type:

- `SCRIPT_NOT_FOUND`: a script call points to a file that does not exist.
- `SCRIPT_NOT_SCANNED`: the script exists in another service but is outside its scan.
- `CONTRACT_DUPLICATE_ENDPOINT`, `CODE_DUPLICATE_ENDPOINT`: the same route appears twice.
- `CONTRACT_METHOD_SET_MISMATCH`: contract and code declare different methods for a path.
- `CONTRACT_ENDPOINT_NOT_IN_CODE`, `CODE_ENDPOINT_NOT_IN_CONTRACT`: a route exists on one side only.

## Warnings

`{type, service, message}` with `type` `EMPTY_SERVICE` (analyzed with zero nodes; the message names the
context and extensions used) or `SERVICE_FAILED`. Catalog `flow_map` repeats them as
`"<service>: <message>"`.

## Example

```json
{
  "status": "ok",
  "appmap": {
    "schemaVersion": "2.0",
    "sourceVersion": "4.44.3",
    "generatedAt": "2026-10-16T10:00:00-03:00",
    "services": [
      {
        "id": "billing",
        "name": "billing",
        "root": "/repos/billing",
        "repository": null,
        "commitSha": null,
        "languages": [],
        "technology": "unknown",
        "domain": "billing",
        "kind": "service",
        "external": false,
        "status": "failed",
        "error": "LogicException: Unsupported project context: rails",
        "hostnames": ["billing"],
        "stats": { "nodeCount": 0, "edgeCount": 0 },
        "endpoints": []
      },
      {
        "id": "web",
        "name": "web",
        "root": "/repos/web",
        "repository": "https://bitbucket.org/example/web.git",
        "commitSha": "4120212f6a7d3c1e9b0a5d8c2e7f1b3a9c6d0e4f",
        "languages": ["php"],
        "technology": "php",
        "domain": "web",
        "kind": "service",
        "external": false,
        "status": "ok",
        "hostnames": [],
        "stats": { "nodeCount": 12, "edgeCount": 20 },
        "endpoints": [],
        "configResolution": {
          "mode": "explicit_config",
          "configPath": "/repos/web/flow-engine.json",
          "hasConfigFile": true,
          "detectedContext": "composer",
          "includePaths": ["src"],
          "ignoredPaths": ["vendor"],
          "extensions": ["php"],
          "warnings": []
        }
      }
    ],
    "integrationEdges": [
      {
        "type": "http",
        "fromNode": "file:src/sync.php",
        "fromFile": "/repos/web/src/sync.php",
        "fromLine": 2,
        "target": "http://billing/api/invoices",
        "resolvedPath": null,
        "metadata": { "scheme": "http", "host": "billing", "port": 0, "path": "/api/invoices", "query": "", "kind": "http" },
        "fromService": "web",
        "toService": "billing",
        "toNode": null
      }
    ],
    "serviceEdges": [
      { "from": "web", "to": "billing", "type": "http", "count": 1 }
    ],
    "inconsistencies": [],
    "warnings": [
      { "type": "SERVICE_FAILED", "service": "billing", "message": "analysis failed: LogicException: Unsupported project context: rails" }
    ]
  }
}
```
