# Flow Engine Documentation

Start with the [project README](../README.md) for the short overview. Use this page as the detailed documentation map.

![Flow Engine dependency graph](assets/flow-engine-graph.svg)

## First Use

- [Getting started](getting-started.md): install Flow Engine and run the first analysis on Windows, Linux/macOS, or Docker.
- [Overview](overview.md): what Flow Engine does and where each interface fits.
- [Configuration](configuration.md): create and edit `flow-engine.json`.
- [CLI commands](CLI_COMMANDS.md): command reference and common examples.

## AI And Integrations

- [MCP server](mcp.md): expose local graph tools to an MCP-compatible AI client.
- [Local read-only API](../public-api.md): HTTP endpoints for local tools.
- [Docker](docker.md): run the CLI or API without installing PHP locally.

## Analysis Guides

- [Analysis tools](analysis-tools.md): metrics, cycles, architecture checks, orphans, impact, risk, and reports.
- [Flow tracing](flow-tracing.md): follow dependencies upstream and downstream from a node.
- [System diagrams](system-diagrams.md): generate Mermaid diagrams and application maps.
- [Application map output](appmap.md): the `appmap` JSON contract and its schema.
- [Concepts](concepts.md): glossary for graph terms used by Flow Engine.
