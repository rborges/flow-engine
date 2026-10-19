# Concepts

## Node

A node is a code element Flow Engine can reason about, such as a method, function, class-like unit, route handler, or module-level function.

PHP example: `App\\Service\\OrderService::process`.

Other languages prefix IDs with the language and module path, such as
`python:app.services.orders.OrderService::process` or
`typescript:src.orders.OrderService::process`. Nested Go packages use their path relative to
the analyzed project behind an explicit path boundary, for example
`go:~path~.services.shared.automerge::TestMain`; a root-level Go
file keeps its declared package name, such as `go:main::Run`. When a directory contains a
different declared package, as with an external `_test` package, the ID adds an escaped
`@package` suffix, for example `go:~path~.pkg.auth@auth_5Ftest::New`.
TypeScript namespaces use an explicit identity boundary, for example
`typescript:src.client.~namespace~.Api.Internal.Client::fetch`. This keeps namespace segments
distinct from file and directory segments. Literal `.`, `~`, and `%` characters inside a path
segment are encoded as `%2E`, `%7E`, and `%25`; this also distinguishes `a.b/c.ts` from
`a/b.c.ts`. TypeScript IDs containing dotted filenames therefore change after this release.

## Edge

An edge is a relationship between nodes: calls, framework entrypoints, route links, HTTP calls, imports, and other detected dependencies.

## Flow

A flow is the graph formed by nodes and edges.

Use `flow <path>` or `nodes <path>` to inspect it from the CLI.

## Metrics

Metrics measure graph shape: fan-in, fan-out, coupling, hotspots, and counts.

## Cycle

A cycle is a dependency loop. Flow Engine reports exact cycle members.

## Architecture Rule

Architecture rules define allowed and forbidden dependencies between layers or areas.

Example: domain code should not depend on infrastructure code.

## Orphan Candidate

An orphan candidate is code with no detected incoming path after framework-aware suppression rules are applied.

## Context Export

Context export turns graph facts into compact Markdown for AI assistants.

Use `context <path> --minimal` for a small export, or `context <path> --entrypoint=<node>` for focused context around one code path.
