# Configuration

Flow Engine reads `flow-engine.json` from the project being analyzed. This file tells the engine what to scan, how to resolve symbols, and how your code maps to architectural layers.

Create one on Windows PowerShell:

```powershell
php .\bin\engine.php init C:\dev\my-app
```

Create one on Linux/macOS:

```bash
php bin/engine.php init /home/me/my-app
```

`init` writes a minimal, valid file for the detected project type (Composer or Flutter).

## Minimal File

Three fields are required: `version`, `context.type`, and `scan.include`. A typical PHP project looks like this:

```json
{
  "version": "1.0",
  "context": {
    "type": "composer",
    "autoload": "vendor/autoload.php"
  },
  "scan": {
    "include": ["src"],
    "exclude": ["vendor", "tests"],
    "extensions": ["php"]
  }
}
```

## Fields

### Required

- `version`: configuration schema version. Must be `"1.0"`.
- `context.type`: project context. Use `composer` for PHP projects or `flutter` for Flutter/Dart projects.
- `scan.include`: directories to scan inside the analyzed project.

### Optional

- `context.autoload`: path to a Composer autoloader, for example `vendor/autoload.php`. Static analysis never loads it; only `inputs` and `run-guided`, which reflect on or execute project code, do. When `null` or omitted, those commands fall back to `vendor/autoload.php` if it exists.
- `scan.exclude`: directories or files to ignore. Default: none for Composer projects; Flutter projects (`context.type: flutter`) fall back to Flutter-specific exclusions (`.dart_tool`, `.pub-cache`, `build`, ...).
- `scan.extensions`: file extensions to parse (not language names). Default: `["php"]` for Composer projects; Flutter projects default to `["dart"]`, plus `"py"` when a `backend/app` directory exists.
- `architecture.layers`: named layers mapped to namespace prefixes (see below).
- `snapshots.keep`: maximum number of labeled snapshots to keep (integer, minimum 1). Omit for unlimited.
- `nodes.visibility`: node visibility policy, with `default` (`public` or `hidden`) and `rules` (`[{ "match": "...", "visibility": "..." }]`).
- `entrypoints.patterns`: extra class or method name patterns that should never be reported as orphans.

## Architecture Layers

`architecture.layers` maps a layer name to one or more **namespace prefixes**. Flow Engine uses it to classify nodes into layers and report dependencies that cross layer boundaries.

```json
{
  "version": "1.0",
  "context": { "type": "composer", "autoload": "vendor/autoload.php" },
  "scan": { "include": ["src"], "exclude": ["vendor", "tests"], "extensions": ["php"] },
  "architecture": {
    "layers": {
      "Domain": ["App\\Domain\\"],
      "Application": ["App\\Application\\"],
      "Infrastructure": ["App\\Infrastructure\\"]
    }
  }
}
```

Then run:

```bash
php bin/engine.php architecture <project>
php bin/engine.php architecture-gate <project> --fail-on=any
```

`architecture` reports how nodes are distributed across the configured layers and which dependencies cross between them. `architecture-gate` turns that into a CI-friendly pass/fail check.

## Secrets

LLM providers are optional and configured only through environment variables. Core analysis, API, MCP, and context export work without them.

Do not store API keys, tokens, or machine-specific secrets in `flow-engine.json`.
