# MCP Server

Flow Engine can run as a Model Context Protocol server over stdio.

The server is local and read-only. It lets an AI client ask for graph facts, context, impact, risk, and lookup results without pasting large reports into chat.

## Run Locally

Windows PowerShell:

```powershell
$env:FLOW_ENGINE_BIN = "$PWD\bin\engine.php"
php .\bin\engine.php mcp
```

Linux/macOS:

```bash
export FLOW_ENGINE_BIN="$PWD/bin/engine.php"
php bin/engine.php mcp
```

For most MCP clients, configure:

- Command: `php`
- Arguments: path to `bin/engine.php`, then `mcp`

The repository includes `.mcp.json`:

```json
{
  "mcpServers": {
    "flow-engine": {
      "type": "stdio",
      "command": "php",
      "args": ["${FLOW_ENGINE_BIN}", "mcp"]
    }
  }
}
```

## Available Tools

| Tool | Purpose |
| --- | --- |
| `flow_map` | Structural map of the project: areas, entrypoints, languages. |
| `flow_find` | Find nodes by name substring when the exact ID is unknown. |
| `flow_lookup` | Focused details for a node, area, or entrypoint. |
| `flow_context` | Compact, AI-ready context for the project or an area. |
| `flow_impact` | Change impact (affected nodes) for a target node. |
| `flow_risk` | Deterministic risk score for a target node. |
| `flow_refactor_plan` | Graph-backed refactor plan for a target node. |
| `flow_infra_map` | Infrastructure map for Docker, scripts, and config-heavy repositories. |

All tools are local and read-only.

Code-analysis project tools validate the local content cache and refresh it automatically before building a
response. When source files or `flow-engine.json` changed, the response reports what triggered the refresh; no second tool
call is required. Ignored unreadable directories are pruned, while unreadable directories in the
active source scope are returned as explicit warnings. Every code-analysis project tool propagates analysis
warnings, including cache corruption and duplicate node IDs; Markdown tools render them before the
result and JSON tools return them in `warnings`. `flow_infra_map` remains independent of source
analysis configuration so Docker, proxy, web, scripts, and file inventory stay available when code
configuration is invalid or a source root is unreadable. Invalid source configuration is reported as
a non-blocking warning in the infrastructure response.

## Tool Annotations

Every tool declares the standard [MCP tool annotations](https://modelcontextprotocol.io/specification/2025-03-26/server/tools#tool-annotations)
in `tools/list`: `readOnlyHint: true`, `destructiveHint: false`,
`idempotentHint: true`, `openWorldHint: false`. The engine analyzes code
without mutating the target project, so clients can rely on these hints for
permission decisions.

This matters in Claude Code plan mode: MCP tools that do not declare
`readOnlyHint` are hard-blocked there ("Cannot call ... while in plan mode"),
even when a `permissions.allow` rule matches the tool. With the annotations in
place, plan mode falls back to the regular permission flow, where a
server-level allow rule such as `mcp__flow-engine` removes the prompts.

## Recommended AI Workflow

1. Call `flow_map` to understand project shape.
2. Call `flow_find` when the exact node ID is unknown.
3. Call `flow_lookup` for focused context.
4. Call `flow_context`, `flow_impact`, or `flow_risk` for targeted analysis.
5. Call `flow_refactor_plan` for a graph-backed refactor plan, and `flow_infra_map` for Docker, script, or config-heavy repositories.

## Example System Prompt

Use a prompt like this in an MCP-compatible AI client when you want the assistant to rely on Flow Engine facts before proposing code changes:

```text
You are working in a repository that has Flow Engine available through MCP.

Before making architectural, refactoring, or debugging recommendations, use the
Flow Engine tools to inspect the codebase. Prefer deterministic graph facts over
guessing from filenames.

Workflow:
- Start with flow_map to understand the project structure.
- Use flow_infra_map when the repository is mostly Docker, scripts, static files,
  proxy config, or other infrastructure rather than application code.
- Use flow_find when you need the exact node ID for a class, method, function,
  route, command, or module.
- Use flow_lookup for focused details about a specific node.
- Use flow_context when you need compact project context for a task.
- Use flow_impact before recommending changes to a node.
- Use flow_risk before changing highly connected or architecture-sensitive code.

When answering:
- Cite the Flow Engine findings that influenced your recommendation.
- Distinguish facts from assumptions.
- If Flow Engine cannot find enough evidence, say what is missing.
- Do not assume remote services, accounts, telemetry, or API keys are available.
- Keep recommendations local-first and compatible with the existing codebase.
```

For smaller tasks, the assistant can use only `flow_find` and `flow_lookup`.
For refactors or architecture work, it should usually combine `flow_map`, `flow_impact`,
and `flow_risk`.

## Notes

- Core MCP tools work without an LLM provider key.
- The MCP server reads local project files through Flow Engine.
- Keep machine-specific paths in local MCP client settings, not in committed files.
