# Getting Started

This guide assumes you clone Flow Engine once, then point it at the project you want to analyze.

In examples, replace:

- `C:\dev\my-app` with your project path on Windows.
- `/home/me/my-app` with your project path on Linux/macOS.

## Requirements

Choose one path:

- Local PHP: Git, PHP 8.3+, and Composer.
- Docker: Docker Desktop on Windows/macOS or Docker Engine on Linux.

No API key is required for the core CLI, context export, MCP server, or local API.

## Windows PowerShell

Install from source:

```powershell
git clone https://github.com/rborges/flow-engine.git
cd flow-engine
composer install
php .\bin\engine.php help
```

Analyze a project:

```powershell
php .\bin\engine.php init C:\dev\my-app
php .\bin\engine.php analyze C:\dev\my-app
php .\bin\engine.php metrics C:\dev\my-app
php .\bin\engine.php context C:\dev\my-app --minimal
```

## Linux/macOS

Install from source:

```bash
git clone https://github.com/rborges/flow-engine.git
cd flow-engine
composer install
php bin/engine.php help
```

Analyze a project:

```bash
php bin/engine.php init /home/me/my-app
php bin/engine.php analyze /home/me/my-app
php bin/engine.php metrics /home/me/my-app
php bin/engine.php context /home/me/my-app --minimal
```

## Docker

Build the image from the Flow Engine repository:

```bash
docker build -t flow-engine .
```

Analyze a Windows project from PowerShell:

```powershell
docker run --rm -v "C:\dev\my-app:/workspace:ro" flow-engine analyze /workspace
docker run --rm -v "C:\dev\my-app:/workspace:ro" flow-engine context /workspace --minimal
```

Analyze a Linux/macOS project:

```bash
docker run --rm -v "/home/me/my-app:/workspace:ro" flow-engine analyze /workspace
docker run --rm -v "/home/me/my-app:/workspace:ro" flow-engine context /workspace --minimal
```

## Inspect Findings

```bash
php bin/engine.php cycles <project>
php bin/engine.php architecture <project>
php bin/engine.php orphans <project> --audit
php bin/engine.php impact <project> "App\\Service\\OrderService::process"
php bin/engine.php change-risk <project> --node="App\\Service\\OrderService::process"
```

## Export Context For AI

```bash
php bin/engine.php context <project> --minimal
php bin/engine.php context <project> --entrypoint="App\\Service\\OrderService::process"
```

Use `--minimal` when you want compact context to paste into an AI assistant. Use MCP when the assistant should inspect the project through tools.

## Save A Baseline

```bash
php bin/engine.php snapshot <project> --save=before-change
php bin/engine.php snapshot <project> --compare=before-change
php bin/engine.php architecture-gate <project> --baseline=before-change --fail-on=new
```

When contributing to Flow Engine itself, save the main-branch baseline before editing and run the
PR gate after the change:

```bash
composer gate:baseline
composer gate:pr
```

Snapshots and caches live in the user state directory, not inside the analyzed project. Cache
validity follows file content and the complete graph-producing implementation, so a same-size edit
with a restored timestamp is still detected. If project sources change while analysis is running,
the result is rejected instead of being published under metadata for different content.

## Next Steps

- [CLI commands](CLI_COMMANDS.md)
- [MCP server](mcp.md)
- [Configuration](configuration.md)
- [Docker](docker.md)
