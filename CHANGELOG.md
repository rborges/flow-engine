# Changelog

## Unreleased - Reliability hardening

- Pruned ignored directories before recursive descent, reported unreadable source directories, and kept explicitly configured unreadable roots as hard failures.
- Replaced timestamp-and-size cache keys with content fingerprints, included all graph-producing code and installed dependency metadata in cache validity, rejected source changes during analysis, added private state directories, made multi-file caches transactional, and made corrupt or structurally invalid flow, per-file, and report payloads recover as cache misses.
- Made MCP project preparation refresh stale caches before returning results and propagate source-file and `flow-engine.json` refreshes, corruption, duplicate-ID, and unreadable-directory warnings through every code-analysis project tool.
- Kept infrastructure mapping independent of source-analysis configuration failures while reporting them as non-blocking warnings.
- Reduced watch heartbeats to one input fingerprint pass without graph deserialization and repaired native watches after directory replacement.
- Corrected Python and TypeScript lexical-scope detection so local helpers and test doubles do not become graph nodes; Python property setters no longer duplicate their getter node, and TypeScript namespace, module, and path IDs use reversible segment boundaries and resolve calls through lexical ancestors. TypeScript IDs for dotted filenames are intentionally migrated (`users.controller` becomes `users%2Econtroller`).
- Disambiguated Go node IDs by an explicit relative-path boundary and declared package when needed. Nested Go IDs intentionally migrate to the `go:~path~.<directory>` form; root-level Go files retain their declared package name, and the original one-argument parser constructor remains supported.
- Made PHPUnit notices fail the test command, added Composer metadata validation to CI, and documented the local PR baseline workflow.
- Made native watch recursive across configured source roots, track newly created non-ignored directories, reuse the scanner's mandatory exclusions, detect the first file after an empty analysis, and periodically verify content as a safety net.
- Included the detected framework in the analysis signature so adding or removing framework markers invalidates visibility-sensitive flow caches.
- Made TypeScript/JavaScript parsing linear on long lines: function/class brace detection now derives every keyword's delimiter state from one pass instead of rescanning the context per keyword, and the per-character heuristics read the same 4096-byte lookbehind within a line that they already used across lines. A minified bundle no longer pins a CPU core for hours; files without lines over 4096 bytes parse exactly as before.
- Static analysis no longer loads the analyzed project's autoloader, so a Composer platform check that requires a newer PHP no longer aborts `analyze`, `appmap` or `flow_map`. `inputs` and `run-guided` still load it.
- Multi-service commands (`appmap`, `context`, `diagram`, the HTTP API and MCP catalog tools) isolate failures per service: a service that fails to analyze appears in `appmap.services[]` with `status: "failed"` and `error`, and the others are still mapped. Every service now carries `status`. `appmap` still prints the map but reports each failure on stderr and exits 1; `appmap-diff` and the drift/compliance HTTP endpoints (422) refuse maps with failed services instead of reporting their edges as removed; Markdown and Mermaid outputs mark the service as "analysis failed".
- Published the appmap output contract: `appmap-output.schema.v1.json` and [docs/appmap.md](docs/appmap.md), with a test that validates real `appmap` output (and the documented example) against it. `opis/json-schema` is a new dev-only dependency.
- appmap `integrationEdges[].fromNode` now points to the node whose line range contains the call (using `endLine`), to `file:<relative path>` for procedural PHP outside any function, and stays `::unknown` only when the file has no node. Paths are compared ignoring separator and drive-letter case. Values change for existing maps: edges that were `::unknown` or attributed to the preceding method move, so the first `appmap-diff` after upgrading reports them.
- `appmap` detects HTTP edges from URL literals in Python, TypeScript/JavaScript and Go services (comments and Python docstrings ignored), not only PHP and Dart. The scanned languages now come from the language catalog.
- `appmap` no longer turns URLs inside comments and docblocks (PHP, Dart) into HTTP edges, and HTTP edges carry `metadata.kind`: `asset` for static files (images, fonts, css/js, maps, pdf) and well-known CDNs, `http` otherwise. Asset edges stay in `integrationEdges` but no longer count in `serviceEdges`. The first `appmap-diff` against a map produced before this change reports those edges as removed.
- The appmap payload has a new `warnings` list: `EMPTY_SERVICE` for a service analyzed with zero nodes (with the context and extensions used) and `SERVICE_FAILED` for a service whose analysis failed. Catalog `flow_map` repeats them in `warnings` as `<service>: <message>`.
- `init` detects Python (`requirements.txt`, `pyproject.toml`) and TypeScript/JavaScript (`package.json`, `tsconfig.json`) projects, and mixed repositories, instead of always writing a PHP config. New `context.type` values `python` and `typescript` (static-only, default extensions `py` and `ts/tsx/js/jsx`); configs using them fail on earlier engine versions with `Unsupported project context`. Read-only inference now also recognizes `tsconfig.json`.
- appmap reads Laravel routes into `services[].endpoints` for services with `artisan` at the root, from the route files even when `routes/` is outside `scan.include`: files and base prefix from `RouteServiceProvider` (`group(base_path(...))`, `require` inside a group, `->namespace($this->namespace)`), else `withRouting()` in `bootstrap/app.php`, else `routes/web.php` and `routes/api.php`; nested `prefix`/`namespace`/`controller` groups, string actions and resources. Closure routes are left out. Laravel services that had no endpoints now have them, so OpenAPI contract checks and the first `appmap-diff` see new routes.
- appmap `integrationEdges[].toNode` (always present, `null` when unresolved): the handler of the `toService` endpoint whose path matches the call (parameters, optional trailing parameter, trailing slash as a concatenated segment, `httpMethod` when known, most literal segments wins), or the `file:` node of a legacy PHP file for paths ending in `.php`. A URL with no path or just `/` is not matched.
- appmap reads HTTP URLs from configuration files outside the scan: `.env` examples (never `.env` or `.env.<environment>`), Laravel `config/*.php` and `.ini`. These edges carry `metadata.source = "config"`, `configKey`, `commented` for commented `.env` example lines (never counted in `serviceEdges`), `fromNode` `file:<path>`, and keep only scheme, host and port. Lines whose key names a secret are skipped. The first `appmap-diff` after upgrading reports the new edges, and a `serviceEdges` entry whose count grows shows as removed and added, because the diff keys service edges by count.
- The CLI `appmap` and the HTTP API now include `configResolution` per service and derive `languages` from the scanned files, matching the MCP catalog output.

## 0.1.3 - Documentation coherence

- Rewrote `docs/configuration.md` to match the real `flow-engine.json` schema (`version`, `context.type`, `scan.include/exclude/extensions`, `architecture.layers` as namespace prefixes, `snapshots.keep`) and dropped fields that were never read (`paths`, top-level `exclude`, `languages`, `architecture.rules`, `snapshots.retention`).
- Documented the previously missing `appmap` and `diagram` CLI commands and the `flow_refactor_plan` MCP tool, and added an "Available Tools" reference to the MCP page.
- Added a documentation-coherence test gate that fails CI when the config schema, CLI command list, or MCP tool list drift from the code.
- Removed the orphaned documentation validator (`DocumentationValidator`, `DocumentationUpdater`, `ValidateDocsCommand`, and supporting types) — unreachable and now superseded by the coherence gate. This also retires the 0.1.2 `DocumentationUpdater` exception fix, since the class is removed entirely.

## 0.1.2 - Guardrail fixes

- `DocumentationUpdater` now throws a clear exception for a missing or unreadable docs file instead of emitting a PHP warning.
- `flow_infra_map` preserves Compose volumes: YAML key detection was tightened so `- host:container` volume entries are no longer misread as map keys.
- Restored the `simple-project` test fixture and added a deterministic no-LLM `refactor-plan` fallback plus markdown output for `refactor-validate` (reconnecting `MarkdownFormatter::formatRefactorValidation` to a real command).

## 0.1.1 - Passes its own gate

- Added a GitHub Actions self-gate workflow (tests, architecture, unclassified nodes, and cross-class cycles) with a CI status badge; the engine now passes its own analysis gate, tested on PHP 8.3, 8.4, and 8.5.
- Classified every source namespace into an architecture layer (no remaining "Unknown" nodes) and inverted Application -> Infrastructure dependencies through ports.
- Recalibrated the analyzers to stop flagging deliberate patterns: graceful-degradation fallbacks and intra-class mutual recursion are now reported as INFO, and change risk is capped at HIGH for nodes with no callers and zero blast radius.
- Removed dead code surfaced by orphan analysis.
- Built the deployment map in a single catalog pass, avoiding a duplicate catalog load and Docker topology computation.

## 0.1.0 - Initial open-source release

- Published the local-first Flow Engine core under the MIT license.
- Included CLI, MCP server, Docker support, read-only local API, parsers, dependency graph analysis,
  metrics, cycles, risk, impact, architecture checks, orphan detection, and AI context exports.
- Removed private release material, operational archives, UI artifacts, and non-core deployment notes.
- Made `.claude/` integration opt-in through `settings.example.json`.
- Kept LLM providers optional; core analysis and exports work without network access or API keys.
