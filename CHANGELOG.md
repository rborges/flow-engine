# Changelog

## Unreleased

- Static analysis no longer loads the analyzed project's autoloader, so a Composer platform check that requires a newer PHP no longer aborts `analyze`, `appmap` or `flow_map`. `inputs` and `run-guided` still load it.
- Multi-service commands (`appmap`, `context`, `diagram`, the HTTP API and MCP catalog tools) isolate failures per service: a service that fails to analyze appears in `appmap.services[]` with `status: "failed"` and `error`, and the others are still mapped. Every service now carries `status`. `appmap` still prints the map but reports each failure on stderr and exits 1; `appmap-diff` and the drift/compliance HTTP endpoints (422) refuse maps with failed services instead of reporting their edges as removed; Markdown and Mermaid outputs mark the service as "analysis failed".
- Published the appmap output contract: `appmap-output.schema.v1.json` and [docs/appmap.md](docs/appmap.md), with a test that validates real `appmap` output (and the documented example) against it. `opis/json-schema` is a new dev-only dependency.
- appmap `integrationEdges[].fromNode` now points to the node whose line range contains the call (using `endLine`), to `file:<relative path>` for procedural PHP outside any function, and stays `::unknown` only when the file has no node. Paths are compared ignoring separator and drive-letter case. Values change for existing maps: edges that were `::unknown` or attributed to the preceding method move, so the first `appmap-diff` after upgrading reports them.
- `appmap` detects HTTP edges from URL literals in Python, TypeScript/JavaScript and Go services (comments and Python docstrings ignored), not only PHP and Dart. The scanned languages now come from the language catalog.
- `appmap` no longer turns URLs inside comments and docblocks (PHP, Dart) into HTTP edges, and HTTP edges carry `metadata.kind`: `asset` for static files (images, fonts, css/js, maps, pdf) and well-known CDNs, `http` otherwise. Asset edges stay in `integrationEdges` but no longer count in `serviceEdges`. The first `appmap-diff` against a map produced before this change reports those edges as removed.
- The appmap payload has a new `warnings` list: `EMPTY_SERVICE` for a service analyzed with zero nodes (with the context and extensions used) and `SERVICE_FAILED` for a service whose analysis failed. Catalog `flow_map` repeats them in `warnings` as `<service>: <message>`.
- `init` detects Python (`requirements.txt`, `pyproject.toml`) and TypeScript/JavaScript (`package.json`, `tsconfig.json`) projects, and mixed repositories, instead of always writing a PHP config. New `context.type` values `python` and `typescript` (static-only, default extensions `py` and `ts/tsx/js/jsx`); configs using them fail on earlier engine versions with `Unsupported project context`. Read-only inference now also recognizes `tsconfig.json`.
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
