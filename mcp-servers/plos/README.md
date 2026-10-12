# PLOS MCP Server

Primary MCP server for operating a PLOS instance from Codex or another MCP
client. It exposes guarded database reads, selected operational commands, setup
diagnostics, job/agent health, log search, and local Ollama helpers.

This package is intended for trusted local or LAN use. It can inspect live PLOS
state and some tools can modify configuration or clear caches only when explicit
confirmation fields are supplied.

Every listed tool includes conservative MCP behavior annotations. Read tools
are marked read-only/idempotent; mixed or bounded-write tools are not; cache,
tinker, and SSH tools are marked destructive; and arbitrary command surfaces
are marked open-world. These are client hints only. Server-side validation,
allowlists, confirmation, and offline policy remain authoritative.

The advertised tool contract is pinned in `tool-contract-baseline.json`.
Builds and tests exercise the live local `tools/list` endpoint and block hidden
Unicode, instruction-like metadata, duplicate or confusable names, unsafe
schema/annotation shapes, cross-tool description references, and any unreviewed
name, description, schema, or annotation drift. The audit never reads arbitrary
MCP client configuration, starts third-party MCP servers, uses the network, or
uploads tool metadata.

## Tools

- `plos_schema` reads `docs/schema-reference.md` or live table metadata.
- `plos_query` runs guarded SQL. Reads are default; writes require `allowWrite`.
- `plos_health`, `plos_job_status`, `plos_job_diagnostic`, and
  `plos_agent_status` summarize operational state.
- `plos_log_search` searches Laravel logs.
- `plos_config` reads and writes selected non-secret `system_configs` keys.
- `plos_artisan` runs a small allowlist of Laravel commands.
- The exact read-only artisan allowlist includes
  `rag:chunking-provenance-audit --json --compact` for content-free semantic
  chunk contract coverage; near-miss flags and write variants remain blocked.
- `plos_cache_clear` clears Laravel caches and requires confirmation for Redis
  flushes or worker restarts.
- `plos_tinker` and `plos_ssh` provide operator tools with command filtering.
  Tinker keeps its 120-second subprocess cap; its outer MCP deadline is 125
  seconds to allow timeout output to return. Other tools retain the 90-second
  default unless their registration provides an explicit deadline.
- Lossy prompt compression and automatic decomposition tools are intentionally
  unavailable; prompts reach the selected model without a compression pass.
- `plos_offline_capabilities` reports the offline Codex/PLOS capability
  manifest, including local model posture, MCP availability, sidecar
  coordination, media input/output posture, transcription, embeddings, and
  operator gates.
- `plos_offline_sidecar` plans or runs a read-only multi-model offline Codex
  split. It sends custom prompts through the Laravel command as base64 text,
  runs only read-only Codex lanes, and leaves writeback to the normal approved
  editor path.
- `plos_colibri_research` plans or runs the operator-selected Colibri
  public-web research canary. It defaults to the local checkout for Hyper-V
  host-only use away from the home LAN, base64-transports the public question,
  and retains PLOS's exact SearXNG read allowlist, public-input assertion,
  bounded calls, no writes, and no fallback.
- `genealogy_search` discovers tree-scoped person, family, source, and media IDs
  with bounded literal search, living-person detail redaction, and ready-made
  `genealogy_context` follow-ups.
- `genealogy_context` returns compact genealogy snapshots for selected IDs.
- `genealogy_batch_apply` batches guarded genealogy updates with a dry-run
  default, `confirm=true` for writes, and tree-scoped write checks for
  sources, persons, families, child links, media links, citations, and RAG
  touch targets. Person updates include birth, death, and burial dates/places;
  unknown person fields are rejected instead of silently discarded. Child
  links can also carry natural/adopted/step/foster/unknown parent-relationship
  qualifiers. Person-target citations atomically materialize the corresponding
  person-source link used by source-gap and health audits.
- Database configuration accepts MCP-specific `MYSQL_*`/`PGSQL_*` variables
  first, then Laravel root `.env` aliases `DB_*`/`RAG_DB_*`. Credential values
  remain private environment data and are never returned by tools.
- `ollama_*` tools delegate summarization, error triage, diff explanation, code
  description, commit drafting, and content drafting to local Ollama models.

## Installation qualification

Tool descriptors document the available interface, not completed deployment or
permission to run a tool on a new host. Use the installation's configured tree,
registered command allowlist, data/network policy and explicit confirmations.
Host-specific commands and personal research boundaries are excluded from the
public allowlist; generic guarded commands remain available.

OS evidence and face-readiness diagnostics are bounded reads, not permission to
execute discovered programs, run a model, identify people or change records.
Build/reload is required after a reviewed source or tool-contract change. Qualify
actual external helpers and local bindings before enabling optional MCP routes;
see `docs/public-methods/skills-and-mcp.md` in the repository.

## Install

```bash
cd mcp-servers/plos
npm ci
cp .env.example .env
npm run build
npm test
```

Edit `.env` before starting the server. Use strong local database credentials,
do not commit `.env`, and leave `SKIP_SSH_TUNNEL=true` when the databases are
reachable directly from the MCP host. Set it to `false` only when the server
must open SSH tunnels to a remote PLOS instance.

Private operator installs may also put MCP secrets in
`~/.config/plos/mcp-plos.env` or the file named by `PLOS_MCP_ENV_FILE`. That
private file is loaded after `mcp-servers/plos/.env` and the repo root `.env`,
so it can override local defaults without removing runtime env files from the
checkout. Keep it mode `600`.

## Codex

Keep runtime credentials in the untracked `.env` described above. For Codex,
add the STDIO server to a trusted project's `.codex/config.toml` using absolute
paths:

```toml
[mcp_servers.plos]
command = "node"
args = ["/path/to/plos/mcp-servers/plos/dist/index.js"]
cwd = "/path/to/plos/mcp-servers/plos"
```

Run `codex mcp list` to confirm that Codex sees the configured server. Other MCP
clients may use their equivalent STDIO-server configuration.

## Safety Model

- SQL is read-only by default, rejects multiple statements, blocks destructive
  DDL/DML, and auto-limits unbounded reads.
- Genealogy context reads are scoped to selected IDs and truncated text fields;
  they do not mutate data.
- Genealogy batch writes default to dry-run and require `confirm=true` before
  mutating the selected tree after tree membership checks for sources, persons,
  families, children, media links, citations, and RAG touch targets.
- Graph-changing genealogy batches lock the tree, compare live protected-root
  ancestor and undirected reachability counts and membership before commit, and
  roll the whole transaction back on lost or equal-count replaced membership.
  Omitted family spouse fields are preserved;
  explicit `null` unlink requests remain graph-guarded. Results include the
  content-free `plos.genealogy_graph_guard.v1` receipt.
- Config writes require `confirm_write=true`, only allow selected sections, and
  reject secret-like keys.
- Cache clearing requires `confirm_disruptive=true` for Redis flushes or worker
  restarts.
- Offline Codex sidecar tools are read-only. Agents, supervisors, and genealogy
  managers can use them for planning, independent review, and capability
  discovery, but file/database writes still go through existing approval,
  dry-run, and tree-scoped write gates.
- Shell and tinker tools apply command filtering and timeouts, but still belong
  on trusted machines only.
- Client cancellation is propagated to each tool. Requests cancelled before
  dispatch do not start, active cooperating child processes receive an abort
  signal, and server timeout timers are cleared after every completed request.
- Clients that supply `_meta.progressToken` receive request-scoped,
  content-free `notifications/progress`: `started`, elapsed-time `running`
  heartbeats every 15 seconds, then `completed` or `failed`. PLOS does not
  invent a percentage or total, emit tool inputs/results/errors, or continue
  heartbeats after cancellation. Clients without a token see no notifications,
  and notification transport failure never changes tool completion.
- JSON-object tool results retain their text content for older clients and also
  expose MCP `structuredContent`. `genealogy_search`, `genealogy_context`, and
  `genealogy_batch_apply` advertise bounded object output schemas. Successful
  results for those tools are validated with the existing MCP SDK validator
  and fail closed before return when structured content is missing or does not
  conform.
- Logs are written to `LOG_FILE`, defaulting to `/tmp/plos-mcp.log`.

## Development

```bash
npm run build
npm test
npm start
```

`npm run build` compiles the server and then runs the strict live contract
audit. To inspect the same content-free scorecard through Laravel, run:

```bash
php artisan ops:mcp-tool-contract-audit --json --compact --fail-on-violations
```

When an intentional tool change is complete, first run the failing audit,
inspect the per-tool baseline diff, and review the changed name, description,
schemas, and annotations. Only then run
`npm run refresh:tool-contract-baseline`, review the JSON diff, and rerun
`npm test`. Never refresh the baseline merely to make drift pass.

`npm test` removes stale generated output before compiling and executes both
root-level protocol tests and nested unit tests. The stdio contract test calls
the real `2025-06-18` initialize and `tools/list` endpoints and verifies unique
tool names, the reviewed aggregate contract digest, descriptions,
self-contained JSON Schemas, required fields,
read/write/destructive/open-world annotations, structured Genealogy outputs,
nested genealogy batch objects, output-schema enforcement, rerank union inputs,
and safety defaults such as dry-run writes. Unit tests also pin client
cancellation, server timeout, timer cleanup, and backward-compatible
text-plus-structured results. Progress tests cover token gating, monotonic
elapsed values, content-free terminal states, cancellation cleanup, transport
failure isolation, and a real stdio tool call.

The server uses stdio for MCP transport. Do not write diagnostic logs to stdout
or stderr from tool code; use `src/util/logger.ts`.

## Remote configuration

Set `PLOS_REMOTE_HOST`, `PLOS_REMOTE_USER`, `PLOS_REMOTE_SSH_KEY` and
`PLOS_REMOTE_PROJECT_ROOT` for your own trusted instance. These neutral settings
are independent of the maintainer's deployment. Keep actual credentials private.
