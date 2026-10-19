# System Diagrams

Flow Engine can emit Mermaid source for dependency and application maps.

Use diagrams when you need a visual artifact for review, planning, or onboarding.
Output is local Markdown with Mermaid source, so it can be committed, pasted into documentation,
or rendered by tools that support Mermaid.

Single-project diagrams:

```bash
php bin/engine.php diagram <project> --view=class
php bin/engine.php diagram <project> --view=component
php bin/engine.php diagram <project> --view=c4context
php bin/engine.php diagram <project> --view=flowchart --entrypoint="App\\Http\\Controller\\OrderController::store"
```

Multiple-project maps:

```bash
php bin/engine.php appmap <service-a> <service-b>
php bin/engine.php diagram <service-a> <service-b> --type=dependency
```

Catalog-based maps:

```bash
php bin/engine.php appmap --catalog=flow-services.json
php bin/engine.php diagram --catalog=flow-services.json --type=c4container
```

The JSON produced by `appmap` is described in [Application map output](appmap.md).

A catalog is optional. Use it when several repositories should be analyzed as one system.
It points Flow Engine at service paths and metadata instead of passing every service path
on the command line.

Diagram output is Markdown with Mermaid source. It can be saved to a local file:

```bash
php bin/engine.php diagram <project> --view=class > class-diagram.md
```
