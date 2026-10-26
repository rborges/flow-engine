# Application Map Output

`appmap` (CLI) and `GET /api/v1/appmap` (local API) return the same payload. Its contract is
[`appmap-output.schema.v1.json`](../appmap-output.schema.v1.json) (JSON Schema 2020-12), and a test
validates real output against it, so a format change fails CI before it reaches a consumer.

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
| `appmap.generatedAt` | ISO 8601 string | |
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
    "generatedAt": "2026-10-16T10:00:00-03:00",
    "services": [
      {
        "id": "web",
        "name": "web",
        "root": "/repos/web",
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
      },
      {
        "id": "billing",
        "name": "billing",
        "root": "/repos/billing",
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
