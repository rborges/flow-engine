# Roadmap

## Current Focus

Flow Engine is an open-source, local-first dependency graph and architecture analysis engine.
The public package focuses on deterministic code facts that developers and AI assistants can inspect
without sending source code to a remote service.

## Public Core

- Multi-language parsing and dependency graph construction
- Metrics, coupling, cycles, architecture checks, orphan detection, impact, and risk
- Local CLI workflows
- Local read-only API
- MCP server
- Docker usage
- Snapshot, compare, drift, cleanup, and architecture-gate commands backed by local files
- Context and report exports for AI assistants

## Near-Term Work

- Improve parser coverage and cross-language edge detection
- Expand framework-aware entrypoint and orphan suppression rules
- Tighten architecture policy configuration
- Improve compact context exports for AI workflows
- Add more examples for CI and local development
- Keep docs aligned with the public API and CLI

## Found In Real Use (2026-10-15)

Issues hit while running `appmap --catalog` over 23 services (PHP, Python, TypeScript) and consuming
the output from another tool.

- **appmap ignores non-PHP code.** `IntegrationDetector` only scans `.php` and `.dart` files, so
  Python and TypeScript services get nodes but zero integration edges, even with literal URLs in
  `requests`/`httpx` or `fetch`/`axios` calls. Extend URL detection to the other parsed languages.
- **appmap counts every URL literal as an HTTP call.** URLs in comments, docblocks, license headers
  and static asset links (images, CDNs) become `http` edges; one legacy PHP service produced thousands
  of edges to license and documentation hosts. Skip comments and tag asset URLs.
- **`init` always writes a PHP config.** Outside Flutter, it emits `type: composer`,
  `extensions: ["php"]` and `include: ["app"]` when that folder exists, so a Python or TypeScript
  repository is analyzed with zero nodes. Detect the language from `requirements.txt`/`pyproject.toml`
  and `package.json`/`tsconfig.json`, reusing the detection the inferred read-only mode already has.
- **Static analysis loads the project's autoload.** `AstFlowRepository::analyze()` boots the project
  context first, so the Composer context `require`s `vendor/autoload.php` (and `autoload: null` falls
  back to that same file). Static analysis never needs it — only `inputs`/`run-guided` do. When the
  project's Composer platform check needs a newer PHP than the engine runtime, the process exits and
  the whole multi-service appmap is lost. Boot lazily (execution only) and isolate failures per
  service in the three catalog loops (CLI, HTTP API, MCP).
- **Empty services are silent.** A service analyzed with `nodeCount: 0` should produce a warning in
  the appmap output.
- **`fromNode` is `::unknown` for procedural code.** File nodes are created with `line: null`, so
  `NodeLocator` skips them; it also ignores `endLine`, attributing code after a method to that method.
- **Document the appmap output contract.** The CLI writes `{status, appmap}`; edges carry `fromNode`
  (not `fromNodeId`), `fromFile`, `fromLine` and `metadata.path` (no `metadata.url`). No output schema
  exists yet.

## Found In mapp Integration (2026-10-20)

Gaps seen while consuming the appmap from a cross-system catalog tool. Not prioritized yet.

- **Endpoints beyond the decorator-based frameworks.** Flask/FastAPI and Nest decorators, Go routers
  and PHP route attributes already set `http_path`; Laravel route files reach `services[].endpoints`
  since `faa9dfa`. Still missing: Django `urls.py`, Express-style `app.get('/x', handler)` registrations
  and Java/Spring (no Java analyzer), so calls into those services cannot be matched to a handler node.
- **Orphan suppression from external callers.** A route called only by another system is reported as
  an orphan. Accept a list of externally called routes or nodes (e.g. from the appmap or a catalog)
  and suppress those candidates, with the caller as evidence.
- **appmap v2: data and env edges.** Emit edges for SQL table/procedure access and for URLs read from
  environment variables, so consumers stop reimplementing URL and SQL detection with diverging rules.
  URLs written in configuration files (`.env` examples, Laravel `config/*.php`, `.ini`) are emitted
  since the config-file pass (`metadata.source = "config"`); variables read in code are still open.
- **Schedule in script topology.** Crontab entries are recognized; expose schedule and command per
  entry so a consumer can attach "when it runs" to a job.
- **Stable evidence anchors.** Document node ids as the stable anchor for edge evidence (file and
  line change on every edit) and keep them stable across releases.

## Longer-Term Public Work

- More deterministic reports
- Better large-repository performance
- More language fixtures and regression tests
- Additional local export formats
- Stronger MCP discovery and lookup workflows

## Not In The Public Core

Some advanced product workflows may live outside this repository later. The public core should stay
useful on its own: local, scriptable, inspectable, and safe to run in private codebases.
