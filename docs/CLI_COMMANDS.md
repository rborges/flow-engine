# CLI Commands

Run commands with:

```bash
php bin/engine.php <command> [arguments] [options]
```

On Windows PowerShell, use:

```powershell
php .\bin\engine.php <command> [arguments] [options]
```

In this page, `<path>` is the project you want to analyze. It can be a Windows path such as
`C:\dev\my-app`, a Unix path such as `/home/me/my-app`, or `.` for the current directory.

Most users start with `analyze`, `metrics`, `cycles`, `architecture`, `orphans --audit`,
and `context --minimal`. The other commands are useful when you need a deeper graph lookup,
a local baseline, or integration with AI tooling.

## Setup

- `init <path>`: create `flow-engine.json`.
- `doctor <path>`: validate configuration.
- `analyze <path>`: build the dependency graph.

## Analysis

- `metrics <path>`: coupling, fan-in, fan-out, and hotspots.
- `complexity <path>`: complexity findings.
- `cycles <path>`: dependency cycles.
- `architecture <path>`: layer classification and cross-layer dependency findings.
- `orphans <path> [--audit]`: orphan candidates and evidence.
- `bugs <path> [--min-score=N] [--type=TYPE]`: static bug patterns.
- `solid <path>`: SOLID findings.
- `patterns <path>`: design pattern detection.
- `entrypoints <path>`: framework and application entrypoints.

## Node Inspection

- `nodes <path>`: list graph nodes.
- `flow <path>`: export nodes and edges.
- `inputs <path> <node>`: show inputs and return type.
- `impact <path> <node>`: change impact.
- `impact-report <path> --node=<node>`: detailed impact report.
- `change-risk <path> --node=<node>`: deterministic risk score.
- `trace <path> --node=<node>`: upstream and downstream dependencies.
- `explain <path> <node>`: visibility and governance explanation.

## AI Context

- `context <path> [--minimal]`: export compact context.
- `context <path> --entrypoint=<node>`: focused context.
- `ask "<question>" <path>`: ask through an optional LLM provider configured with environment variables.
- `interpret <path> --type=<type>`: optional interpretation for graph reports.

## Advanced Local Planning

- `refactor-plan <path> --node=<node>`: graph-backed refactor plan.
- `refactor-safety <path> --node=<node>`: safety assessment.
- `refactor-execute <path> --plan=<label> --step=<N>`: local step guidance.
- `refactor-validate <path> --plan=<label> --step=<N> [--format=json|markdown]`: validate step completion.
- `refactor-pr <path> --plan=<label>`: generate local PR text.
- `remediation-proposals <path>`: generate local remediation proposals.
- `remediation-approve <path> --plan=<label> --id=<proposal_id>`: mark a proposal approved.
- `remediation-status <path> --plan=<label>`: inspect approval status.

When no LLM provider is configured, `refactor-plan` still creates a deterministic
local safety step from graph analysis so `refactor-execute` and
`refactor-validate` remain usable. Optional LLM providers only enrich the plan.

## Diagrams And Maps

- `diagram <path> --view=class|component|c4context`: generate Mermaid diagram source for one project.
- `diagram <path> --view=flowchart --entrypoint=<Class::method>`: generate entry-point flowchart (requires `--entrypoint`).
- `appmap <service-a> <service-b> ...`: build an application map across multiple project paths.
- `appmap --catalog=flow-services.json`: build the application map from a service catalog.

See [System diagrams](system-diagrams.md) for diagram views, multi-project maps, and catalog usage.

## Snapshots And Gates

- `snapshot <path> --save=<label>`: save current reports.
- `snapshot <path> --compare=<label>`: compare with a saved baseline.
- `snapshot <path> --list`: list local snapshots.
- `drift <path> --baseline=<label>`: detect drift from a baseline.
- `cleanup <path> --older-than=<days>`: delete old snapshots.
- `cleanup <path> --keep-last=<N>`: keep the newest snapshots.
- `architecture-gate <path> --baseline=<label> --fail-on=new`: CI-friendly local gate.

For work on Flow Engine itself, `composer gate:baseline` saves `baseline-main` before changes and
`composer gate:pr` compares the finished tree against it.

## Servers And Integrations

- `api <path> [--host=127.0.0.1] [--port=8080]`: start the local read-only HTTP API.
- `mcp`: start the MCP stdio server.
- `watch <path>`: rerun analysis on file changes.

## Windows Examples

```powershell
php .\bin\engine.php analyze C:\dev\my-app
php .\bin\engine.php metrics C:\dev\my-app
php .\bin\engine.php context C:\dev\my-app --minimal
php .\bin\engine.php snapshot C:\dev\my-app --save=before-refactor
php .\bin\engine.php architecture-gate C:\dev\my-app --baseline=before-refactor --fail-on=new
```

## Linux/macOS Examples

```bash
php bin/engine.php analyze .
php bin/engine.php metrics .
php bin/engine.php context . --minimal
php bin/engine.php snapshot . --save=before-refactor
php bin/engine.php architecture-gate . --baseline=before-refactor --fail-on=new
```

## Output Format

Most analysis commands print JSON or structured text to stdout. You can redirect output to a local file:

```bash
php bin/engine.php context <path> --minimal > flow-context.md
php bin/engine.php diagram <path> --view=class > class-diagram.md
```

## Other Commands

- `help`: list available commands.

Native `watch` recursively monitors configured source roots, adds newly created directories, and
periodically checks content fingerprints so missed filesystem events do not become silent gaps.

The router also registers commands meant for interactive or experimental use — `interactive` (guided menu), `compare`, `simulate`, and `appmap-diff`. They are not part of this stable reference yet and may change.
