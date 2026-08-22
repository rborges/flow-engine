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
