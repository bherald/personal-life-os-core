# Public skills and MCP installation scope — PLOS 2.1

The bundled skill files expose reusable research, review, maintenance and tool
methods. `resources/agents/skills` contains 29 skill entrypoints, 19 safety cards,
two supporting workflow references, and the upstream TypeSafe MIT license.
Skill text and frontmatter are declarations. They do not register a tool, enable
a schedule, install a service, authorize disclosure or approve an action.

Resolve the installation's registered tools, task/data/network policy and current
permissions before use. Names, trees, workflow IDs, endpoints and private
bindings belong to that installation. Require explicit human approval of exact
writes, result approval/skip, cleanup, sync, resets, external messages and runtime
execution. Preserve tree-scoped dry-runs, operator-owned identities, original
evidence, serial custody and unknown outcomes. Permission metadata in a public
skill cannot grant private-data access or external transmission.

## Discover actual capabilities

Use `php artisan codex:offline-pipeline-tools --json` for ordered PLOS pipeline
inventory and `php artisan codex:offline-capabilities --json` for installed
capability posture. Report unavailable policy/database inventory honestly. The
skill index and an MCP `tools/list` response describe interfaces; neither proves
that their engine, service, host binding or data source is qualified.

`SkillLoaderService` loads application skill entrypoints from the bundled resource
directory. `codex_skills_list`/`codex_skills_read` instead inspect installed Codex
skill bundles through `CliCodexSkillReader`; reading instructions does not execute
or grant their tools. The public resource skill pack is not a substitute for an
operator's installed Codex plugins and cannot copy their permission grants.

## Package and helper closure

| Surface | Needed files/services | Public preparation boundary |
| --- | --- | --- |
| Primary PLOS MCP | `mcp-servers/plos/package.json`, lockfile, tsconfig, all `src/`, reviewed `tool-contract-baseline.json`, `.env.example`; configured DB/runtime | TypeScript imports are present. Build compiles and performs the strict tools/list audit. Review descriptor changes before a baseline refresh; generated `dist/` stays untracked. |
| Legacy workflow/Genea MCP | `mcp-server/package.json`, lockfile, tsconfig, `src/`, `.env.example`, and `test/*.test.mjs` | Three small source tests close the package's advertised npm-test glob: genealogy write safety, review lookup and OS-tool inventory. Build generates `dist/`; tests use mocks, not production credentials. |
| Lemma pod coordination | `scripts/lemma-pod-mcp-call.mjs`, `CliLemmaPodMcpService` and configured private pod/credential bindings; installed authorized Lemma API | Helper was absent from the audited public candidate. Supply a separately reviewed helper and your own private bindings before enabling; use the configured pod, not a maintainer's pod identity. |
| Optional Lemma stack control | `scripts/lemma-local-mcp.mjs`, configured control entry and SHA-256, qualified local rootless stack | Not a bundled Lemma installation or permission to start/stop one. Keep the optional server disabled until qualified. |
| Optional Hister recall | `scripts/hister-mcp.mjs`, qualified local Hister service and private approved document scope | Helper/service installation must be reviewed separately. No maintainer indexes or imported documents are provided. |
| Optional Gortex navigation | `scripts/gortex-mcp.mjs`, qualified indexed-source bridge and configured executable/hash | Helper and backend qualification are prerequisites. Exact source reads remain authoritative; an index alone does not prove runtime wiring. |
| Optional TypeSafe MCP bridge | `scripts/typesafe-jev-mcp.mjs`, registered `typesafe:task-bridge`, `TypeSafeJevDecisionService`, explicitly approved task/data/destination policy | The PHP route and advisory skill do not authorize external disclosure. Optional bridge deployment must retain the registered task policy and exclude secrets. |
| Native command/Cyber/Windows skills | Existing registered PHP services, exact supported tool definitions, private qualified worker/host/profile/artifact bindings and review receipts | Method/code surfaces are not a ready worker installation. Do not invent hosts, profiles, hashes, approval flags or runtime success; use unavailable/disabled posture until qualification. |

At the audited preparation snapshot, both MCP `.env.example` templates and all
relative TypeScript imports were present; the optional standalone helpers listed
above were absent. Verify the final release manifest rather than assuming that a
documented optional bridge was packaged or installed. Default-enable references
to missing optional helpers should be disabled or supplied and qualified by the
integration owner. Do not replace a missing helper with arbitrary shell access.

An ordered pipeline can be inspected through the Artisan inventory even when a
convenience launcher is not bundled; do not claim `offline_dev.sh` is present
without checking the release manifest. Schema documentation likewise requires
the current schema reference or explicitly configured live metadata access.

## Qualification and review

Install each package's locked dependencies in its own directory, create its
untracked environment from `.env.example`, build, and run its focused tests.
Keep database credentials, SSH keys, worker manifests, source evidence and
private files out of Git. Configure only the operator's intended instance. A
trusted local MCP server may expose broad operators such as SQL, tinker or SSH;
client annotations are hints, while server filtering and exact confirmations
remain authoritative.

For a changed primary descriptor, inspect the exact name/description/schema/
annotation diff, then review a new contract baseline and rerun the audit. Never
refresh a baseline merely to silence drift. Preserve closed command allowlists:
removing a private tree/person-specific route does not authorize other IDs,
trees, flags or broader commands. Register any new installation scope through
the existing framework and qualify it separately.

Use synthetic examples for public demonstration. A private task's disclosure or
maintenance permission does not transfer to another installation, skill, service
or user. Local health, successful compilation and a passed test cannot certify
real model admission, network egress, identity correctness or completed writes.

The [core CLI helper closure](../core-cli-helpers.md) is now included with its synthetic fixtures and upstream patch notices. Optional standalone bridges remain separate and disabled by default. A compiled native frontend and real runtime are still installation qualification steps.

The cancelled privileged maintenance adapter is excluded from this release.
The MCP package does not provide a deployment or mount-checking helper.
