# Troubleshooting

## Guardrail Coverage Is Empty Or Stale

Check the deterministic contract and the read-only snapshot first:

```bash
php artisan codex:offline-pipeline-tools --json --compact
php artisan tinker --execute="dump(app(App\\Services\\Ops\\GuardrailMonitorService::class)->snapshot(24));"
```

An empty `rules` list can be valid when no active database/config rule exists;
the active source-enforced offline profile and policy-profile count must still
appear. If database count is unexpectedly zero, verify
`services.guardrails.use_database` and active rows in `guardrail_rules`. If a
deleted row appears, clear application caches and verify the deployed monitor
uses fresh database queries rather than `AgentGuardrailService`'s enforcement
cache. Missing activity means the selected window has no persisted
`guardrail_events`; it does not mean enforcement is disabled.

Never expose `conditions`, `reason`, `agent_scope`, raw event `context`, prompts,
or completions to repair the panel. Do not create a synthetic dangerous action,
change rule priority, weaken offline policy, or enable prompt compression or
precompaction as a diagnostic.

## Embedding Rerank Falls Back To Lexical

Inspect the content-free v3 result first:

```bash
php artisan rag:rerank-eval --use-embeddings --json --compact
php artisan codex:offline-pipeline-tools --json --compact
```

`embedding_identity_missing` means a provider/model/revision/dimension field is
absent; for Ollama verify `/api/tags` returns a digest for the configured exact
embedding model. For an external provider, set an immutable reviewed
`embedding_revision` or `embedding_model_sha256` in its table-owned config.
`embedding_space_mismatch` means routing returned different provider, model, or
revision identities within one query/document cohort. Align the deployed
artifacts or constrain the provider pool; never bypass the check because vector
lengths happen to match. `embedding_dimension_mismatch` is a separate shape
failure. Partial/provider failures require restoring the dependency and
rerunning the full cohort. Do not reuse partial scores, repoint stored vectors,
promote a model, or enable compression to force a pass.

## Safety Is `not_measured`

This is expected unless every trial is an evaluator-owned protected scratch
case with `verify_php`, `protected_files`, and `allowed_changed_paths`. Do not
fill missing safety fields from response text, a correctness result, or an LLM
judge. For protected cases, L0 means no observed hard-boundary effect and L2
means a reversible local protected-file or changed-path violation. The harness
cannot currently substantiate L1 or L3-L6. Verify the deterministic inventory
with `php artisan codex:offline-pipeline-tools --json` and keep prompt
compression/precompaction off.

Start every diagnosis with the install tier, operating system, exact command
run, and the relevant `setup:doctor` output.

## Offline Assistant Uses The Wrong Repository Root

Run `php artisan offline:dev-assist /prompt --json`. The system prompt should
contain one `[PLOS_TRUSTED_WORKSPACE_CONTEXT]` marker, one
`[PLOS_TRUSTED_REPOSITORY_GUIDANCE]` marker, and a redacted current working
directory. Then run `php artisan codex:offline-pipeline-tools --json --compact`
and verify `offline_workspace_context.binding` is `canonical_realpath_once`,
`repository_guidance.scope` is `exact_project_root_only`, and its SHA-256
matches `sha256sum AGENTS.md`.

Guidance errors intentionally fail closed. Ensure root `AGENTS.md` exists, is a
regular non-symlinked file, contains valid nonempty UTF-8, and is no larger than
4,500 bytes. Do not substitute a parent/nested file, symlink, generated memory,
external-agent history, or home-directory instructions. Do not solve root or
guidance errors by inserting operator-supplied paths into prompts or enabling
prompt compression.

## RAG Search Cannot Continue Into The Source Document

Check `php artisan codex:offline-pipeline-tools --json --compact` for
`plos.hierarchical_rag_retrieval.v1`. A current `rag_search` result must contain
`document_id` and `document_ref`; pass the ID to `rag_read` with `offset=0` and
follow `next_offset` while `has_more` is true.

`found=false` means the indexed row no longer exists. An invalid-bounds error
means the ID is nonpositive, the offset is negative or beyond `total_chars`, or
`max_chars` is outside 1 through 20,000. Rerun search when a document may have
been reindexed; do not guess IDs, concatenate overlapping windows, increase the
limit in source, or enable prompt compression/precompaction to hide a retrieval
contract failure.

## Deployment Stops At Secret File Permission Gate

Run the repo-scoped guard on the host named by the failure:

```bash
scripts/guards/private-credential-permissions.sh --repo-only
```

Each `fail mode=...` row names a known secret-bearing env or Passport key file
with group/world bits. Review ownership and provenance, set the intended owner-
only mode (usually `chmod 600 PATH`), rerun the guard, and then rerun
`prod1.sh`. Do not add `--fix` to the deployment command or weaken the repo
allowlist to make the release pass.

## Upstream Source Drift Is Unavailable

Run a narrow metadata-only check first:

```bash
php artisan whiteroom:source-drift --format=json --compact --project=skillspector
```

`github_request_failed` or `arxiv_request_failed` usually means DNS, TLS, proxy,
or outbound-network access is unavailable. `rate_limited` means GitHub returned
rate-limit evidence; the command intentionally makes no retries and skips later
GitHub probes in that pass. Wait for the reported limit window or supply a
session-scoped `GH_TOKEN`/`GITHUB_TOKEN`, then rerun. Do not place tokens in
command options, reports, config, or scheduled output. An unavailable check does
not make the recorded revision current and must not trigger an automatic config
update, dependency install, or adoption decision.

`requested_project_untrackable` means the key exists but has no supported
`git_commit` or `arxiv` probe. `requested_project_unknown` means the key is not
in the registry. `requested_project_filtered_out` means another supplied filter
excluded the requested key. These are coverage failures under
`--require-complete`, even when `eligible_total=0`; correct the selection or add
reviewed registry metadata rather than removing the strict gate.

Known-good production evidence from 2026-07-16 is coverage schema v2, a 12/12
current and complete explicit cohort, and exit 1 with both
`requested_project_untrackable` and `requested_project_unknown` for a synthetic
mixed invalid request. If that exact behavior regresses after deployment,
compare the deployed command, inventory, and registry hashes with the release,
rebuild Laravel caches through the normal deploy, and rerun the narrow probe.
Do not disable `--require-complete`; prompt compression and precompaction are
unrelated and must remain off.

If a reviewed source still reports drift after its registry pin changed, verify
that the running checkout contains the expected `source_version` and that its
Laravel config cache was rebuilt by the normal deployment. Do not repin to the
reported head merely to clear the alert; the source delta and PLOS fit must be
recorded first.

## Runtime Policy Audit Is Noisy Or Blocked

Use non-compact JSON and inspect `classification_source`, `fingerprint`, and
the nearest code context before deciding that a match is a leak. A line-number
change alone should retain the same v1 fingerprint. A changed statement or
nearest code context intentionally produces a new fingerprint and requires
fresh review.

When `runtime_policy_candidates` is unexpectedly large, compare it with
`non_definition_context_references`. Literal assignments, option defaults,
configuration fallbacks, named arguments, setters, clamps, and material numeric
comparisons are intentionally candidates. Other lexical hits should remain
visible as `runtime_value_reference` with
`classification_source=non_definition_context`. If a syntax family is wrong,
repair `classifier.line_policy_definition_regexes` in the table policy and
rerun; do not add a blanket file/rule ignore. Use an exact fingerprint override
with a reason only after reviewing the individual finding.

`runtime_policy_leak_audit_invalid_finding_overrides` means at least one table
override is not a lowercase 64-character SHA-256, uses an unsupported class, or
lacks a reason. Correct the exact entry rather than disabling the audit.
`runtime_policy_leak_audit_config_unavailable` in development means the local
database does not have the production policy row; it does not prove production
is unconfigured.

If `pipeline:monitor` reports that its required table policy is missing or
invalid, verify `system_configs.ops.pipeline_monitor_runtime_policy` contains
positive `default_stall_timeout_minutes` and `recent_run_window_minutes` values
no greater than 1440 plus at least one non-empty category. Do not replace the
row with source constants or run the repair path until `--status` succeeds.

## Setup Doctor Fails

Run the doctor for the smallest profile first:

```bash
php artisan setup:doctor --profile=core --json
```

Common causes:

- `.env` was not copied from `.env.example`;
- `WEB_UI_MASTER_PASSWORD` is still a placeholder;
- Passport keys were not generated;
- PHP extensions are missing;
- `storage/` is not writable;
- MySQL/MariaDB, PostgreSQL, or Redis is unreachable.

## Offline Speech Probe Is Blocked

Run the read-only probe and inspect `routing_source`, `policy_status`,
`provider`, `path`, and `model`:

```bash
php artisan audio:tts --probe --json
```

- `invalid_runtime_policy`: inspect the enabled `media_output_audio` profile's
  `metadata.runtime`. Use an allowed provider, strings without control
  characters, and an integer timeout from 1 through 600. This state
  intentionally does not fall back to `services.tts`.
- `disabled_by_policy`: the table route is explicitly disabled. Re-enable it
  only as an operator policy decision.
- `configured_missing`: the configured executable is absent or not executable.
  Correct the table command; do not hide the error with an environment route.
- `blocked_missing_model`: Piper was found but the profile model path is empty.
  Restore the separately reviewed voice/model artifact and its provenance.
- `table_routed=false`: the runtime object is absent, so PLOS is using
  installation bootstrap configuration. Run migrations and verify the enabled
  profile rather than treating bootstrap as the steady-state control plane.

The installed Piper `v1.4.2` adapter expects `-m/-f/--input-file`. Current
Piper main uses a different CLI and is not an in-place upgrade instruction.
Probe first; do not use `--execute` merely to diagnose routing. Speech routing
does not require or enable prompt compression.

## Docker Issues

Check service status and logs:

```bash
docker compose ps
docker compose logs app
docker compose logs mysql postgres redis
```

Common causes:

- host port collision;
- schema dumps loaded into the wrong database;
- using host ports inside containers instead of service names;
- app image not rebuilt after dependency or env changes.

## Database Bootstrap Issues

Confirm both schema dumps exist:

- `database/schema/mysql-schema.sql`
- `database/schema/pgsql-schema.sql`

For PostgreSQL, confirm `pgvector` is available and the configured role can
create or use the `vector` extension. After loading schemas, run:

```bash
php artisan db:seed --class=PublicBaselineSeeder --force
```

## Frontend Issues

Use Node.js 22 LTS with npm 10.9 or newer. Reinstall and rebuild:

```bash
npm ci
npm run build
```

For local development, run Vite only on trusted local machines.

## Queue Or Scheduler Issues

Check Redis first, then queue failures:

```bash
php artisan horizon:status
php artisan queue:failed
php artisan schedule:list
```

Long-running work should not be retried blindly. Inspect the failure and confirm
the relevant connector, model, browser, or database dependency is available.

For bare-metal local development, `config/horizon.php` includes a scaled-down
`local` Horizon environment. If a dev machine still shows production-sized
Horizon supervisors after changing `.env` to `APP_ENV=local`, the old Horizon
master is usually stale. Gracefully restart it so the local caps are applied:

```bash
php artisan horizon:terminate
php artisan horizon
```

After restart, confirm four local workers: one serves `high,default,low` in
priority order, and one each serves `long-running`, `workflow`, and `speculative`.
The ordinary supervisor uses `balance=false`; inherited `auto` balancing created
three pools and three idle workers despite `maxProcesses=1`. Dedicated queue
timeouts, retries and memory settings are preserved. Sustained high-priority
traffic can delay ordinary lower-priority queues; this is a DEV footprint tradeoff.
Production retains its existing automatic balancing. Keep Redis running.

Before a DEV change, verify local DB/Redis endpoints and empty reserved queues;
use graceful `horizon:terminate` under the existing systemd restart policy, then
check exact queue coverage, queue depths, service memory and application health.
Rollback: revert only the local ordinary-supervisor balance override and gracefully
restart again. This change needs no production deployment or VM memory resize.

### DEV background services on demand

On this DEV host, Horizon, Tika, SearXNG and Bettercap are intentionally stopped
and disabled at boot/login. The Docker engine and containerd are also disabled
at boot; the existing enabled `docker.socket` starts Docker when requested.
These are DEV host settings, not a production service policy. Queued DEV work
waits until Horizon starts; start dependencies before testing those capabilities.

| Need | Start | Stop after work finishes |
|---|---|---|
| Background queue processing | `sudo systemctl start horizon` | `sudo systemctl stop horizon` |
| Document extraction | `sudo systemctl start tika` | `sudo systemctl stop tika` |
| Local web search | `systemctl --user start searxng` | `systemctl --user stop searxng` |
| Network diagnostics | `sudo systemctl start bettercap` | `sudo systemctl stop bettercap` |
| Containers | Any Docker command, or `sudo systemctl start docker` | Verify no running containers, then `sudo systemctl stop docker containerd` |

Do not stop Horizon with reserved/running jobs or interrupt active document,
search, diagnostic or container work. Tika's current Java wrapper reports SIGTERM
as exit 143; clear its failed marker only after verifying this exact expected stop
code and no remaining process. Do not hide other failures.

To restore the earlier boot behavior, use `sudo systemctl enable --now horizon
tika bettercap docker containerd` and `systemctl --user enable --now searxng`.
MySQL, Redis, PostgreSQL, Gitea, nginx/PHP-FPM, SSH/XRDP and the current desktop/CLI
stay running. Freeing guest RAM does not shrink a fixed Hyper-V allocation;
resize the VM separately at an agreed checkpoint.

For a lighter local desktop profile, set:

```bash
HORIZON_LOCAL_THIN=true
```

This experimental switch is not used for the measured four-worker tuning above.
Its raw configuration does not prove a single running worker: Horizon merges
shared defaults and creates pools per queue for `simple` balancing. Qualify its
resolved supervisor layout and job semantics before use. Keep the default local
profile when testing dedicated queue isolation or scheduler throughput.

`composer dev` starts `php artisan queue:listen --tries=1`; it does not start
Horizon. To avoid starting a queue worker while working on only the web UI or
frontend assets, run only the pieces you need:

```bash
php artisan serve
npm run dev
php artisan pail --timeout=0
```

Start Horizon separately only when you are testing Horizon behavior or queue
supervisor layout.

## Agent Or MCP Tool Is Blocked By Policy Evaluation Failure

Treat `Offline policy service unavailable; operation denied` and
`blocked because offline policy evaluation failed` as degraded safety-state
errors. They are intentionally fail-closed before agent-registry or direct MCP
dispatch; an operator confirmation does not replace a policy decision that was
never produced.

1. Confirm the active profile and deterministic pipeline inventory with
   `php artisan codex:offline-pipeline-tools --json`.
2. Check recent Laravel logs for the content-free policy failure type. Raw
   exception messages are intentionally excluded from the dispatch response.
3. Verify `system_configs.profile.active` and the table/config inputs used by
   `OfflinePolicyService`; do not temporarily relabel the tool as read-class.
4. Retry only after a normal allow, deny, or confirmation decision can be
   produced. A recurring evaluation exception is a service/configuration
   incident, not an approval backlog item.

## Workflow Approval Does Not Resume

Confirm the run, node execution, and gate agree before retrying anything:

```sql
SELECT id, workflow_run_id, execution_id, node_id, status, requested_at,
       responded_at, responded_by
FROM workflow_approval_gates
ORDER BY id DESC
LIMIT 20;
```

- A healthy pause has gate `pending` plus run/node state
  `awaiting_approval`. It should not occupy a Horizon worker.
- Approval decisions are available only to an authenticated `/workflows` UI
  session. A `401` means the session expired; sign in again before deciding.
- A `409 GATE_ALREADY_RESOLVED` is intentional one-shot protection. Do not
  recreate the gate or submit again. Inspect the workflow run and execution
  events to determine whether the saved decision resumed, completed, or failed.
- A `422 INVALID_APPROVAL_DECISION` means the response did not match the stored
  field definition. Correct the required value or select option; the gate stays
  pending.
- A `500 APPROVAL_RESUME_FAILED` with `decision_saved=true` means the operator
  decision was committed before a downstream execution failure. Use the normal
  failed-execution diagnostics; do not attempt to approve the gate again.
- Rejected or expired gates are terminal and must not be bypassed through
  checkpoint resume. Build a new run after correcting the workflow or input.
- If a workflow contains multiple approval nodes, the latest gate controls
  replay. An earlier approval never authorizes a later pending gate.

## Workflow Resume Reports Missing Or Invalid Intent

`workflow_resume_execution` never defaults to a live action. A missing
`execution_intent` is rejected by registry parameter resolution; any value
other than `resume_live` is rejected again by `WorkflowOpsService` before it
calls `canResumeExecution()` or `resumeWorkflow()`.

Do not add a default, rename an inspection request to `resume_live`, or bypass
the tool through a direct service call. First inspect execution history and
resolve the root cause. When a live resume is actually intended, submit the
normal operator review with the exact intent parameter. If the tool description
does not show the required field after deployment, verify the
`2026_07_15_210000_require_workflow_resume_execution_intent` migration and the
`agent_tool_registry` row; do not edit the service signature to accept the old
one-argument call.

## Workflow Retry Attempts Are Missing Or Misgrouped

Execution History groups only configured automatic retries inside one run.
The Retry button on a terminal failed run creates a new run, so it must appear
as a separate run ID with attempt 1 rather than attempt 2 on the old run.

Confirm the additive schema and inspect aggregate evidence without selecting
input, output, or error values:

```sql
SHOW COLUMNS FROM node_executions LIKE 'attempt_number';
SHOW INDEX FROM node_executions
WHERE Key_name = 'idx_node_executions_run_attempt_order';
SELECT run_id, MIN(attempt_number) AS first_attempt,
       MAX(attempt_number) AS last_attempt, COUNT(*) AS node_rows
FROM node_executions
GROUP BY run_id
ORDER BY run_id DESC
LIMIT 20;
```

- Historical rows correctly remain attempt 1. Do not infer retry identity from
  repeated `node_order` values.
- Attempt values greater than 1 appear only after a workflow with configured
  retry policy fails an attempt and is retried within the same run.
- Fan-out branch rows must use the parent attempt. Subworkflow child runs start
  at attempt 1 because each child has its own run ID.
- If the API fails on `attempt_number`, apply pending migrations before
  restarting workers. If the API is correct but the UI lacks attempt grouping,
  rebuild production frontend assets and clear the browser cache.
- Node labels and states come from `node_type`, `state`, `executed_at`, and
  `duration_ms`. `node_name`, node `status`, `started_at`, and `completed_at`
  are not `node_executions` fields.
- Attempt status summarizes node states; the workflow run status remains the
  authoritative terminal outcome. Displayed detail remains subject to the
  workflow execution redactor.

## Portable Workflow Import Is Blocked

Always rerun the read-only preview and inspect `errors`, `warnings`,
`integrity`, and `existing`:

```bash
php artisan workflow:import /private/path/workflow.json --json
```

- `integrity_mismatch`: the v2 workflow differs from its recorded SHA-256.
  Re-export from the authoritative source; do not edit the hash to make it pass.
- `secret_value_present`: the file contains credential-like material. Remove
  it from the portable definition and restore it locally after import.
- `runtime_value_required`: export intentionally omitted a secret-bearing
  value. Import may proceed inactive, but activation must wait until the local
  runtime value is restored and reviewed.
- `workflow_exists`: rerun with `--overwrite` only when replacement is really
  intended.
- `overwrite_history_blocked`: the existing workflow has execution history.
  Import under a new name or preserve the existing workflow; history deletion
  is not an import option.
- `unverified_legacy`: a v1 export was normalized without inventing a hash.
  Review every warning and prefer a fresh v2 export when the source still
  exists.

`--execute` without `--confirm` remains read-only and returns
`confirmation_required`. Successful imports remain inactive. Do not activate a
workflow merely to test portability; use the preview and normal workflow review
surface first.

## Media And GPU Issues

Media and GPU profiles are optional. Common failures include missing OS
binaries, Python virtualenv drift, missing dlib model files, browser runtime
gaps, and host-specific CUDA/PyTorch mismatches.

Useful references:

- `docs/FACE-RECOGNITION.md`
- `docs/native-ml-package-review.md`
- `docs/python-constraints-license-snapshot.md`

## Local AI Issues

Confirm the local provider is running and the expected models are installed.
External providers are optional and may be disabled by offline policy or
sensitivity gates.

Use:

```bash
php artisan setup:doctor --profile=core --only=services
```

Then check `docs/AIService-LLM-Gateway.md` and
`docs/OLLAMA-COMPATIBILITY.md`.

When ordinary text generation works but MCP/tool execution reports that no role
model is admitted, inspect the exact role mapping and model registry separately:
the selected `llm_instances.config.models.<role>` model must have an available
`ollama_models` row with `tool_calling`, `tool_use`, or `tools`. A
`tool_use_candidate` label does not pass. This distinction is deliberate:
official model/runtime documentation establishes plausible support, while the
exact local quantization, template, parser, and Ollama build still determine
whether native `tool_calls` are emitted. Use the deterministic inventory before
changing routing:

```bash
php artisan codex:offline-pipeline-tools --json
php artisan ollama:eval-scorecard --json --compact
php artisan codex:offline-eval \
  --case=scratch_php_bugfix_hidden \
  --case=scratch_php_multifile_migration_hidden \
  --case=scratch_php_feature_extension_hidden --execute --json
```

Do not promote a candidate from a markdown/XML imitation of a tool call. Coding
promotion still requires evaluator-owned issue-to-patch verification, separate
from the native protocol capability. The 2026-07-14 secondary `qwen3:8b` canary
is the current standard-role example: it emitted a native function object and
structured arguments in about 0.92 seconds. It does not establish autonomous
coding readiness.

### Offline Codex Programming Profile

The local Codex profile `plos-offline-coding` points Codex at the LAN-local
Ollama OpenAI-compatible endpoint on `.87`. PLOS commands resolve its exact
model from `system_configs.ai.offline_coding_runtime_policy` and the bound
`ollama_secondary` coding-role map; the model is not compiled into command
source. This opt-in route does not change the default ChatGPT/OpenAI Codex
profile.

Validate the profile parses without contacting the model:

```bash
codex --profile plos-offline-coding --strict-config --help
```

Validate the PLOS `codex_exec` pipeline without sending repository context to
the local model:

```bash
php artisan codex:offline-eval --case=scratch_php_bugfix_hidden --json
```

Only add `--execute` after operator approval for the specific request. A real
`codex exec` run sends the prompt plus applicable Codex instructions and
workspace metadata to the configured local model server.

If strict config reports `unknown configuration field disabled_mcp_servers`,
move each legacy disabled server under its normal `mcp_servers` namespace and
set the supported per-server flag explicitly:

```toml
[mcp_servers.prompt-compressor]
enabled = false
```

Rename nested `.env` or `.tools.*` section prefixes the same way; do not copy
secret values into logs or documentation. The prompt-compressor server must
remain disabled. PLOS also source-enforces lossless prompts independently of
Codex user configuration.

For local-only PLOS development assistance that uses AIService routing directly
instead of Codex Exec, use:

```bash
php artisan offline:programming --profile=offline_dev_assist \
  --approval=read-only "Inspect this issue without changing files"
```

For a Codex CLI-style local programming run through the same table-backed
`codex_exec` connector, use:

```bash
php artisan codex:offline-dev --json \
  "Inspect this issue and suggest the smallest fix"
```

Add `--write` only for a scoped edit request. The command uses the table-bound
`plos-offline-coding` profile, target instance, coding-role model, and reasoning
effort with a read-only sandbox. An explicit `--model` is a challenger override,
not a bypass; it must be installed on the bound target and pass all admission
checks.

For day-to-day offline maintenance, use the repo-local chat launcher instead of
an eval command:

```bash
./offline_dev.sh
```

This starts the Laravel-native local assistant. The model role and eligible
Ollama instances come from the table-owned
`ai.offline_dev_assist_runtime_policy`; the launcher contains no default model
name. Cloud and backup-LLM fallback are disabled. The default exposes bounded
repository-write tools; use `./offline_dev.sh --read-only` for inspection-only
sessions. The native path needs the local Laravel dependencies and MySQL policy
tables, but not the Codex CLI. Use `./offline_dev.sh --codex` only when the
installed local Codex backend is explicitly wanted.

Automatic Jetson fallback is disabled. `--prefer-jetson` fails closed unless
an explicitly admitted replacement and its table promotion attestation are
configured. `--profile` and `--model` are Codex-only challenger controls, not
native routing overrides. Install or refresh local Codex profiles only for the
optional `--codex` backend:

```bash
scripts/offline-dev-install-codex-profiles.sh
```

When this checkout and Ollama are running on the `.87` laptop away from the
home LAN, select the table-scoped host-only native route:

```bash
./offline_dev.sh --away
```

The `away` runtime mode admits the isolated Hyper-V host route from
`llm_instances` and resolves its standard tool model from table metadata. The
Windows host is `172.20.0.10/24`; the Kubuntu VM is `172.20.0.11/24` with no
gateway or DNS on that adapter. This link remains available away from the
physical home LAN. For optional Codex use, run
`./offline_dev.sh --codex --away`; the Codex profile owns its model selection.

As of 2026-07-21, Ollama and BitNet scheduled tasks bind only to loopback.
Exact Windows portproxies expose both the home-LAN and Hyper-V host-only
addresses to loopback:

```text
192.168.50.10:11434 -> 127.0.0.1:11434
172.20.0.10:11434  -> 127.0.0.1:11434
192.168.50.10:11435 -> 127.0.0.1:11435
172.20.0.10:11435  -> 127.0.0.1:11435
```

This removes the away-mode dependency on `192.168.50.10` as a connect target.
The host-only Ollama endpoint returns `0.18.3`; host-only BitNet returns
`bitnet-b1.58-2b-4t-i2s`; and a bounded BitNet completion passes. Remove only
the exact entries with:

```powershell
netsh interface portproxy delete v4tov4 listenaddress=172.20.0.10 listenport=11434 protocol=tcp
netsh interface portproxy delete v4tov4 listenaddress=192.168.50.10 listenport=11434 protocol=tcp
netsh interface portproxy delete v4tov4 listenaddress=172.20.0.10 listenport=11435 protocol=tcp
netsh interface portproxy delete v4tov4 listenaddress=192.168.50.10 listenport=11435 protocol=tcp
```

Do not substitute a broad listener or firewall rule. The existing unrelated
`0.0.0.0:3001 -> 127.0.0.1:3001` proxy remains outside this change and should be
audited separately.

`D:\HotelWifiSecurity.ps1` is optional travel hardening; the tracked source is
`scripts/plos-mobile-hotspot-security.ps1`. Choosing not to run it does not
disable offline Ollama or image generation. A personal WPA2/WPA3 phone hotspot
is safer than shared hotel Wi-Fi, but keep its Windows network category Public.
When secure mode is selected from an elevated local Windows console, the script:

- atomically saves firewall profiles, network categories by interface GUID,
  exposed-service state, the owned PLOS rule, and every local Private/Any
  inbound allow rule it suppresses;
- applies Public Shields Up without weakening the existing outbound policy;
- keeps only the exact Internal-switch VM route on the Private profile while
  disabling other local Private/Any inbound allows;
- rejects a broad `0.0.0.0` or `::` PLOS listener, unexpected switch/IP/DNS/NAT
  topology, non-local rule that can reach the host-only adapter, or incomplete
  verification; and
- preserves the snapshot after any partial restore instead of deleting the
  recovery evidence.

The current `.87` Public default outbound policy is `Block`. Secure mode
preserves that stricter setting, so general phone-hotspot Internet access may
remain unavailable while the mode is active; the script warns and asks before
applying. Offline PLOS through the isolated Hyper-V link remains the intended
path. Choosing not to run secure mode leaves the current Windows behavior in
place.

Secure mode intentionally suppresses SSH, RDP, discovery, and other inbound
allows while active. Start and restore it at the Windows or Hyper-V console,
not through the remote session it is designed to block. Use `-PreflightOnly`
or menu option 3 for read-only checks. The deployed 2026-07-20 preflight passed
with no saved-state conflict and identified 162 local rules for reversible
suppression; secure mode itself was not activated remotely.

Offline rich-media work uses PLOS tools instead of Codex's direct image-input
contract. `plos_media_analyze` validates locally and sends an image to the
installed Ollama vision model only with `execute=true`; `plos_image_generate`
uses the authenticated image sidecar and writes under `storage/app/offline-media`
only with `execute=true`. Both tools default to dry-run. The equivalent local
commands are:

```bash
php artisan media:offline-analyze --path=/path/to/image.png --probe --json
php artisan media:offline-analyze --path=/path/to/image.png --execute --json
php artisan media:image-generate --prompt-b64="$(printf %s 'a watercolor fox' | base64 -w0)" --execute --json
```

For the host-only image sidecar, keep the credential out of the repository:

```bash
chmod 600 "$HOME/.config/plos/image-sidecar.token"
```

Then set these non-secret routes in `~/.config/plos/offline-dev.env` (also mode
`600`):

```bash
OFFLINE_DEV_LOCAL_OLLAMA_URL=http://172.20.0.10:11434
PLOS_IMAGE_SIDECAR_URL=http://172.20.0.10:7867
PLOS_IMAGE_SIDECAR_TOKEN_FILE=/path/to/operator-owned/image-sidecar.token
```

The 2026-07-19 `.87` `qwen3.5:9b` checks passed text, native image input, and a
native function-call request. Its native JSON-schema request returned non-JSON,
so the PLOS profile remains testing/override-only and must not replace admitted
structured-output routes.

The image checkpoint is `ByteDance/SDXL-Lightning`
`sdxl_lightning_4step.safetensors`, sourced from
`https://huggingface.co/ByteDance/SDXL-Lightning` under the model card's
OpenRAIL++ license. PLOS adapts the documented 4-step Diffusers contract:
SDXL, trailing Euler timesteps, four inference steps, and guidance scale zero.
Retain this source, license, checkpoint identity, and modification note with
any copied runtime or model bundle.

The installer default matches the current `.87` coding role
(`qwen2.5-coder:14b`) but does not read or mutate the database. Re-run it
deliberately after a table route change, or pass `OFFLINE_DEV_PRIMARY_MODEL`
with the newly admitted exact model. Deployment does not rewrite an operator's
existing `~/.codex` profile.

Hosts whose primary Ollama is not `.87` can pass explicit primary profile
metadata when installing, for example `OFFLINE_DEV_PRIMARY_PROVIDER_ID`,
`OFFLINE_DEV_PRIMARY_PROVIDER_NAME`, `OFFLINE_DEV_PRIMARY_MODEL`, and
`OFFLINE_DEV_PRIMARY_OPENAI_URL`.

To test selection without opening the chat, run:

```bash
./offline_dev.sh --dry-run
```

Before disconnecting, require both readiness surfaces to pass:

```bash
php artisan ollama:offline-preflight --json --strict
php artisan offline:dev-assist /doctor --json
```

The older status/smoke surfaces may label active offline mode `degraded`
because cloud providers are intentionally blocked. Use the strict preflight
and native doctor for readiness.

To print the offline capability manifest without opening the chat, run:

```bash
./offline_dev.sh --capabilities
./offline_dev.sh --capabilities --probe-sidecars
```

To print the ordered PLOS AI pipeline function/tool/option inventory without
opening the chat, run:

```bash
./offline_dev.sh --pipeline-tools
php artisan codex:offline-pipeline-tools --json
```

The launcher also recognizes one-shot prompts such as "list in order executed
the functions/tools/options in the AI pipeline for PLOS" and routes them
directly to the same inventory. Inside interactive Codex, PLOS means this local
Laravel app, not Public Library of Science; the model should use
`plos_offline_pipeline_tools` or `php artisan codex:offline-pipeline-tools
--json` before answering pipeline inventory questions.

If a tool call fails with `arguments rejected`, inspect only its advertised
schema and the returned violation codes/field names. `tool_contract_unavailable`
means the called tool was not present in the current static or live-discovered
catalog. `missing_required`, `unknown_argument`, and `schema_*` indicate a
caller/contract mismatch. Do not add permissive catch-all parameters merely to
silence the gate. Correct the caller or intentionally revise and review the
tool schema. Runtime logs intentionally omit argument values and retain only
argument names plus SHA-256 correlation digests.

If a workflow list or diagnostic query reports MySQL error 1267, verify the
failure is a cross-collation comparison between `scheduled_jobs.command` and
`workflows.name`. Those values are exact identifiers; supported code must use
`BINARY sj.command = BINARY w.name` for cross-table joins. Do not change the
database-wide collation or rebuild either table to repair this read path.

If a PLOS MCP Genea call returns the error
`Tool output schema requires structured JSON content`, or reports
nonconforming structured content, treat it as a server contract failure rather
than retrying with broader permissions. Compare the handler's JSON object with
the advertised `outputSchema`, keep raw values out of routine logs, correct the
producer or reviewed schema, then intentionally refresh and review the
tool-contract baseline.

If an external MCP call reports `result rejected`, first check whether live
discovery advertised an `outputSchema`. `missing_structured_content`,
`output_schema_invalid`, `schema_type`, `missing_required`, and
`unknown_result_field` are producer/contract failures, not permission failures.
Do not retry with broader tool authority or log the rejected payload. Compare
the server implementation and reviewed schema in a bounded test, correct the
producer or contract, and clear the MCP catalog only after reviewing the
change. Explicit `isError: true` tool execution feedback is not rejected by
this gate and should remain available for argument correction.

Jetson is not yet a normal chat provider. Its table-owned production roles are
currently `embedding` and `health`. The device is the original non-Super Orin
Nano 8GB installation, validated in its available 15W mode; do not apply
Super-only reflash or power-mode instructions. The former
`plos-offline-jetson` 30B Codex fallback is
retired: official artifact evidence shows a 19GB model on an 8GB device, and
live telemetry independently showed that the route was slow and failure-prone.
See `docs/jetson-model-admission.md` for the evidence hierarchy, current model
matrix, and replacement gate.

For ordinary provider outages, activate or inspect the operator-owned patient
profile with `php artisan routing:profile activate resilient_local_first` and
`php artisan routing:profile status`. It tries healthy eligible local hosts
before the narrow `codex_exec` fallback and tolerates slow local work. It does
not promote candidate models. Patient Ollama text calls are streamed internally
and appear through `php artisan ops:llm-active --json --compact`, read-only
`GET /api/ai/status`, and content-free `Ollama monitored generation` log state
every 30 seconds.
`receiving` is healthy progress; `waiting_for_first_chunk` commonly means load
or prompt evaluation; `waiting_for_next_chunk` is tolerated until the dynamic
silence budget expires. Do not kill or reroute a call merely because elapsed
wall time is high while byte progress continues. A silent or incomplete stream
uses the existing retry/circuit/fallback path. Provider retesting is automatic
through circuit half-open recovery and scheduled
`ops:llm-health-reconcile --json --compact`. If a recovered host remains
excluded, inspect `is_active`, `is_healthy`,
`circuit_state`, `circuit_retry_at`, `routability`, `compat_status`, quarantine,
the requested role/model admission, and the health-reconcile scheduled run.

If `php artisan ollama:probe health` or
`php artisan ops:host-baseline idle --repeat=1 --interval=0` reports an empty
`gpu` array on Jetson, inspect `jetson_tegrastats`. A successful native fallback
reports `source: tegrastats`, `memory_model: unified_system`, bounded utilization,
frequency, memory, swap, and temperature fields, and
`raw_output_included: false`. If both GPU surfaces are empty, run
`tegrastats --interval 1000` on the Jetson host to verify that the NVIDIA utility
is installed and allowed to execute. PLOS does not require the third-party
`jetson-stats` package for this probe. A healthy sample confirms visibility only;
it does not promote a model or prove role/tool/schema fitness.

To smoke-test whether a profile is truly executing Codex shell tools instead of
printing pseudo tool calls, run:

```bash
scripts/offline-dev-codex-smoke.sh --profile plos-offline-coding
```

The deciding signal is a real `command_execution` JSON event. A replacement
Jetson profile may be tested only after published hardware fit is registered.
The remaining confirmation is one bounded role/tool/schema contract followed by
a time-boxed canary before promotion.

For non-code offline work, first let the local model/operator discover the
available PLOS automation and media surfaces:

```bash
php artisan codex:offline-capabilities --json
```

For a read-only offline planning lane, use the sidecar coordinator:

```bash
php artisan codex:offline-sidecar --json "Break this task into a safe dev plan"
php artisan codex:offline-sidecar --json --execute "Review this bug and identify tests"
```

This runs the primary `.87` Codex profile as the planning/synthesis lane. A
second lane is optional and must be supplied with both `--sidecar-profile` and
`--sidecar-model`; Jetson execution additionally requires table admission. All
lanes are read-only; use one approved editor session for actual file changes.

The PLOS MCP `plos_artisan` tool allowlists `codex:offline-sidecar`,
`codex:offline-pipeline-tools`, and `codex:offline-capabilities` in exact safe
forms, and the active PLOS MCP server also exposes dedicated
`plos_offline_capabilities`, `plos_offline_pipeline_tools`, and
`plos_offline_sidecar` tools. Offline agents with the normal
`offline_dev_assist` command-safe profile can call the coordinator through MCP
without needing a separate custom MCP server. Custom prompts should use
`plos_offline_sidecar`; it base64-encodes the prompt before calling artisan so
the prompt does not become shell syntax. It remains read-only by design;
agent/supervisor writeback still happens through the existing approval gates.

This is a read-only manifest inspired by the Laravel AI SDK's typed modality
surface, but implemented natively in PLOS. It reports the offline Codex profile,
selected artisan command categories, model-catalog posture, image sidecar
configuration, local Whisper/transcription readiness, Ollama embedding
configuration, an SDK-like `modality_surface` map, and the approval/operator
gates that still apply. Agents should treat `modality_surface` as the routing
contract for whether a lane is available, model-dependent, pipeline-specific,
or not first-class yet. Add
`--probe-sidecars` only when you intentionally want to hit configured local/LAN
sidecar health endpoints.

Laravel AI SDK parity note: `laravel/ai` is broader than this offline launcher.
Current SDK docs cover provider abstraction, agents, conversation context,
structured output, attachments, streaming, queueing, tools, images, TTS audio,
STT transcription, embeddings, reranking, files, vector stores, failover,
testing fakes, and events. PLOS has native equivalents or partial equivalents
for many of those surfaces through AIService routing, chat attachments,
image-sidecar output, local Whisper/transcription readiness, Ollama embeddings,
RAG/vector pipelines, MCP tools, evals, drift checks, and operator gates. The
SDK is on the monitor/follow-up list, but it is not installed as the runtime
control plane because PLOS already owns provider policy, privacy routing,
offline profiles, and approval safety.

The offline Codex launcher is text/tool-first. It can help with media pipeline
work by inspecting files, running PLOS commands, calling MCP tools, and asking
for the capability manifest. Picture output is available through the PLOS
`media_output_image`/image-sidecar chat path when configured, where generated
images are stored as assistant attachments. Picture input is available through
PLOS chat/media/vision routes only when the selected local or external model
profile advertises that input capability. Do not assume every local Codex
profile can accept image attachments directly; check `codex:offline-capabilities
--json` and the selected model profile first.

The AI Hub `Audio Transcription` mode is shown only when the configured Whisper
executable is present and executable. Install the isolated runtime with
`scripts/install-whisper-runtime.sh --prefetch-model`, set the printed
`WHISPER_PATH`, `WHISPER_MODEL`, `WHISPER_MODEL_DIR`, and `WHISPER_DEVICE`, run
`php artisan config:clear`, and confirm
`php artisan codex:offline-capabilities --json` reports
`media.transcription.status=available`. The profile is a local attachment tool,
not an Ollama model: it requires an audio/video upload and returns Whisper text
directly without a second LLM pass.

If AI Hub shows `Model routes unavailable`, do not work around it by creating a
conversation through a stale selector. Inspect the route contract directly:

Use the authenticated browser Network panel to inspect
`/api/chat/model-modes`, then run the deterministic normalizer contract:

```bash
npm run test:ai-hub-bootstrap
```

The response must contain a non-empty `modes` array whose entries have unique,
non-empty string `id` values. `private_supported` and `override_supported` are
accepted only as JSON booleans. Use the visible Retry action after correcting
the API, authentication, or profile data. Conversation/research data may still
appear because those loads are intentionally independent; route-dependent chat
actions remain disabled until the route contract succeeds.

If AI Hub drops a final character/event, fails on CR-only framing, or corrupts
content containing U+0085, U+2028, or U+2029, run:

```bash
npm run test:chat-sse
npm run build
```

The decoder must preserve those Unicode characters as payload content, accept
CR/LF/CRLF and both `data:`/`data: ` forms, assemble repeated data fields, and
flush at EOF. Inspect the browser Network response when the test passes but the
UI fails. Do not replace the decoder with `splitlines`, `\R`, or a generic
Unicode line splitter; those treat valid content separators as SSE boundaries.

If a tool-using response displays or reloads as run-on text such as
`Let me check.Here is the result`, run:

```bash
php artisan test tests/Unit/Support/StreamTextStepJoinerTest.php \
  tests/Feature/Quality/AIStreamingContinuityContractTest.php
php artisan codex:offline-pipeline-tools --json --compact
```

Confirm `AIRouter::processWithToolsStreaming()` initializes one
`StreamTextStepJoiner` outside its iteration loop and passes every content delta
with the current iteration number. The inventory must report a blank-line
boundary between meaningful iterations and exact same-step joining. Do not add
the boundary to every network chunk, because one model step can contain many
chunks; do not trim or summarize model text; keep prompt compression and
precompaction off.

If direct CLI transcription works but chat uploads remain `stored_unextracted`,
confirm `WHISPER_MODEL_DIR` points to the installer-managed directory and that
the selected `<model>.pt` file is readable by the PHP-FPM and queue users. The
capability status is `not_ready` when an explicit model directory lacks the
selected weights, so the chat profile stays hidden instead of failing late.
If the worker reports `No module named 'torch'`, rerun the current installer in
CPU mode and update `WHISPER_PATH` to the printed `whisper-cpu-venv` path; that
venv contains its own CPU Torch instead of borrowing user-site packages.
Whisper's per-request artifacts use unique OS temp directories; the PHP-FPM or
queue user does not need write access to a shared `storage/app/temp` directory.

If a local ASR candidate looks faster or is supported by published hardware
claims but has no PLOS promotion evidence, run the synthetic contract check
first:

```bash
php artisan content:stt-candidate-eval --example --json --compact --fail-on-ineligible
```

Then generate a real `plos.stt_candidate.v1` artifact from identical baseline
and candidate fixtures. `invalid` means the evidence envelope is incomplete or
unsafe; `not_eligible` means a threshold, case floor, improvement, or regression
gate failed; `eligible_for_review` is still only an operator-review state. Do
not treat the synthetic example, upstream benchmark, nominal hardware support,
or `media.transcription.status=available` as proof that a different runtime or
model should be installed. Compact output intentionally omits transcript text.

If `rag_splade_index` reports a compute capability defer, inspect the bounded
status and recovery surfaces rather than disabling the job or deleting pending
sparse work:

```bash
php artisan rag:splade-index --stats
php artisan ops:compute-health-reconcile --capability=splade --json --compact
php artisan ops:compute-health-reconcile --json --compact
```

`unregistered` means no active `compute_instances` row declares the exact
`splade` capability and requires operator installation/registration.
`unhealthy`, `health_stale`, or `circuit_open` means PLOS has registered
workers and will keep probing them every 15 minutes plus immediately before
the next SPLADE batch. `capacity_busy` is transient contention and is not an
operator outage. A deferred run exits successfully with a zero-item marker so
the scheduler keeps the normal retry cadence without creating a false failure.
Do not replace `splade` with the broad `nlp` capability: that hides missing
dependencies and lets unrelated NLP failures masquerade as SPLADE readiness.
Compute failure telemetry is intentionally content-free; reproduce a failing
script with a synthetic input instead of adding raw prompt, document, command,
stderr, or model output to logs. Local and SSH script payloads use stdin rather
than argv. During a synthetic probe, `ps` may show `python` and the script path
but must not show the sentinel JSON, document text, or a `printf ... | python`
pipeline. Do not move the payload to an environment variable; privileged
process inspection can expose that too.

If longer recordings stop near a repeatable deadline, inspect
`media.transcription.timeout_policy` in
`php artisan codex:offline-capabilities --json`. `WHISPER_TIMEOUT` is the
unknown-duration floor, while known-duration work scales under
`WHISPER_TIMEOUT_PER_AUDIO_SECOND` and `WHISPER_TIMEOUT_CAP`. Video/audio
preparation has a separate floor, rate, and cap. Confirm `ffprobe` is installed
and can read the media before widening a cap; if duration probing fails, PLOS
intentionally uses the model-adjusted floor. The policy also reports the exact
model factor: tiny/base/small are 1x, medium is 2x, large is 4x, and
large-v3-turbo is 2x by default; an unknown model is 1x. Factors never shorten
the previous duration-aware baseline and never bypass the cap. A returned
`failure_reason=transcription_timeout` names the bounded seconds/model but does
not expose process output. Also confirm a prior CUDA process did not outlive its
owner-scoped GPU lock. The runtime extends lock TTL beyond the same calculated
model budget, and CPU mode does not acquire that lock. Do not tune factors from
upstream claims alone; use exact local timeout observations and the STT
candidate gate before changing a runtime or route.

If an upload displays `must not be empty`, verify that the source file is a
regular readable object with a positive byte count before retrying. Do not pad
an empty GEDCOM, genealogy image, or chat attachment merely to bypass the rule.
AI Hub filters empty browser files, request validation protects chat and
genealogy endpoints, and chat storage repeats the check. Warning logs identify
only the bounded failure reason and conversation ID, not the filename.

If AI Hub keeps pulling a growing answer downward while you are reading older
content, confirm the deployed bundle contains `chatAutoFollow`,
`ResizeObserver`, and the passive message-scroll handler. Scrolling more than 32
pixels above the bottom must suspend automatic follow; returning to the bottom
must resume it. Programmatic follow uses instant rather than smooth scrolling.
This is a frontend state defect, not a reason to truncate output, stop
streaming, change a model, or enable compression/precompaction.

If Image Editor reports a successful version restore but retains the old
preview or parent thumbnail, confirm the deployed bundle awaits both
`loadPreview()` and `loadVersions()` before emitting `saved`. Restore buttons
must remain disabled while `restoringVersionId` is set. Reopening the editor is
not the intended refresh mechanism; correct the stale asset/bundle and rerun the
restore contract test.

If PyTorch reports `no kernel image is available for execution on the device`,
the installed CUDA wheel does not support that GPU's compute capability. Set
`WHISPER_DEVICE=cpu` and rebuild Laravel's config cache. CPU mode does not take
the Whisper GPU lock, so it can run without blocking Ollama.

Host-specific launcher defaults can be placed in
`~/.config/plos/offline-dev.env`; the file must not be group/world writable.
For example, production hosts where Codex's bubblewrap sandbox cannot create
the required namespace can use:

```bash
OFFLINE_DEV_SANDBOX=danger-full-access
```

Keep `OFFLINE_DEV_APPROVAL=on-request` unless you are running a bounded,
non-interactive smoke outside the normal chat flow.

Refresh the local Codex `/model` catalog from an Ollama server when local models
are added or removed:

```bash
OFFLINE_DEV_OLLAMA_URL=http://192.168.50.10:11434 \
  scripts/offline-dev-refresh-model-catalog.php
```

Do not use exact replies or model-authored test scripts as proof that an offline
model can perform repository coding. Run the protected portfolio:

```bash
php artisan codex:offline-eval \
  --case=scratch_php_bugfix_hidden \
  --case=scratch_php_multifile_migration_hidden \
  --case=scratch_php_feature_extension_hidden \
  --execute \
  --json
```

The no-`--model` form is the authoritative baseline because it resolves the
current table-owned `.87` coding role. If planning returns `status=blocked`,
inspect `offline_route.reason`; do not restore a hardcoded model. Common reasons
include unavailable policy/inventory, inactive or unhealthy target, non-routable
instance, missing installed model, disallowed role, or hardware rejection.

The preferred verifier is `/usr/bin/bwrap`. When the host cannot create its
network namespace, PLOS may fall back to a local Docker image named by
`system_configs.ai.offline_coding_eval_verifier_docker_image`. The fallback
uses no pull and requires PHP CLI in the configured image; it runs with no
network, a read-only root and workspace, all capabilities dropped,
no-new-privileges, and bounded process, memory, CPU, and temporary-filesystem
resources. Verification fails closed when neither sandbox is usable. The
evaluator protects each public test by hash, permits changes only to each case's
named production paths, requires every declared edit, and runs public plus
hidden assertions inside that isolation boundary. Failed workspaces remain
under `storage/app/codex-offline-eval/` for inspection; successful workspaces
are removed unless `--keep-workspaces` is supplied. The migration case also
requires PDO SQLite on the verifier runtime. A null candidate is expected to
fail every required-change gate. Editing `tests/PublicTest.php`, touching an
unlisted path, or omitting a required file fails workspace integrity and skips
both executable verifiers; do not weaken those gates to admit a model.

Offline PLOS is therefore an assisted execution surface, not a blanket claim
that one local model can replace a strong coding or genealogy researcher. It is
ready for a bounded task shape only when the exact model and configuration pass
the corresponding protected case. Passing the bug-fix case does not imply
migration or feature-extension readiness, and passing all three still does not
establish broad repository autonomy. It is ready for FT4 discovery, triage, extraction, and
drafting through the tree-scoped Genea tools, but canonical person/fact/source
writes still require their deterministic checks and review/apply gates; face and
photo identity remains operator-owned. Broad refactors, ambiguous identity
resolution, and multi-source proof conclusions remain escalation work until
model-specific eval evidence demonstrates otherwise.

Benchmark execution success and `agent:benchmark --score` output are
observational. The LLM-written accuracy/completeness/relevance scores cannot
promote a model or workflow mode. Every new run receives a content-free
`plos.agent_trajectory_assessment.v3` record, and adaptive mode selection, skill
optimization, and speculative execution consume only rows where evaluator-owned
formal checks set `promotion_eligible=true`:

```bash
php artisan agent:benchmark --runs
php artisan agent:benchmark --analyze=<run-id>
php artisan codex:offline-eval \
  --case=scratch_php_bugfix_hidden \
  --case=scratch_php_multifile_migration_hidden \
  --case=scratch_php_feature_extension_hidden --execute --json
```

Legacy benchmark rows default to unverified and cannot influence routing. Tool
arguments, tool results, prompts, and response content are excluded from the
trajectory assessment.

New benchmark output also reports `recovery=<action-code>` or `recovery=none`.
An action is emitted only when the failed outcome has a content-free anchor in
the first failed check, first failed tool step, terminal stuck state, or
invariant code. `recovery=none` with gate reason `failure_anchor_missing` means
the evaluator lacks enough grounding for bounded advice; it is not a request
to infer from raw content. Recovery guidance is advisory and never performs an
automatic retry, changes tool or policy authority, expands a budget, promotes
a run, or bypasses operator review. Repair the named failure class under the
existing controls and rerun the same evaluator-owned verification.

If benchmark output reports structural diagnostics, interpret them as process
leads rather than verdicts:

- `search_loop`: ten search/read actions occurred without another action;
  tighten the search objective or add an explicit phase transition.
- `redundant_search`: the same normalized search target recurred within ten
  actions; reuse the earlier result or explain why a refresh is required.
- `re_read_churn`: the same target was read at least three times within ten
  actions without an intervening write; retain the relevant finding in the
  working plan or make the intended change before rereading.
- `no_formal_tail_validation`: the run wrote data or code but had no successful
  validation after the last write and in the final five actions; add the
  narrowest authoritative test, audit, or dry-run proof.
- `unvalidated_write_checkpoint`: at least one successful write was followed
  by another successful write or the end of the retained trajectory before a
  successful validation; inspect the checkpoint counts and coverage, then run
  the narrowest authoritative verifier between logical write units. This
  action-sequence signal does not itself prove build health or test stability.
- `structured_plan_absence`: a run made at least five writes without a plan
  action before the first write; use the existing plan/TODO surface for a
  multi-edit lane.

`failed_work_actions` is a count, not a separate anti-pattern. Use the
content-free step numbers to locate the corresponding private retained run;
the assessment intentionally does not expose raw arguments, queries, paths, or
results. If a PLOS tool is misclassified, add an exact canonical action at the
producer or refine `config/agent_trajectory.php` with a regression test. Do not
weaken formal verification, raise thresholds merely to clear output, or treat
one diagnostic as root-cause proof. Private offline-eval receipts intentionally
remain the v1 assessment subset and omit diagnostics.

### AI Hub response feedback is unavailable or does not persist

Confirm the operator is authenticated, the target response has a real persisted
message ID, and the migration is applied:

```bash
php artisan migrate:status | rg chat_response_feedback
php artisan test tests/Unit/Services/ChatResponseFeedbackServiceTest.php \
  tests/Feature/Api/ChatResponseFeedbackApiTest.php \
  tests/Feature/Quality/ChatResponseFeedbackFrontendContractTest.php
npm run build
```

A `401` is an authentication failure. A `422` means the target is not an
assistant message in that conversation or the value/reason is outside the
closed taxonomy. If AI Hub updates but reload loses the value, verify
`ChatController::show()` hydrates `response_feedback` and the production bundle
matches the current source. Concurrent submissions should converge through one
atomic database upsert; a duplicate-key error indicates the deployed service is
stale or the unique `chat_message_id` index is absent. Do not work around the
problem by writing labels into message content, exposing raw responses in
Operator Evidence, adding a keyword classifier, or enabling
compression/precompaction.

### AI Hub shows messages from the wrong conversation

Reproduce with two idle threads whose history requests finish out of order,
then run the deterministic selection and inventory contracts:

```bash
npm run test:conversation-load
npm run build
php artisan test tests/Feature/Console/CodexOfflinePipelineToolsCommandTest.php
php artisan codex:offline-pipeline-tools --json --compact
```

The older request must return stale without changing the active conversation,
messages, error, or loading state. The previous transcript should clear while
the newest thread loads, and selection/New Chat/Clear/Delete should be disabled
during an active stream. Confirm that the deployed AI Hub bundle contains the
selection guard before investigating API history. Do not merge responses, reuse an older
transcript while loading, expose private errors, or enable compression or
precompaction as a workaround.

### AI Hub tool activity is missing or stuck

First distinguish a current live event problem from a legacy history row. Run
the deterministic frontend and backend contracts and rebuild the production
asset:

```bash
npm run test:chat-tool-activity
npm run test:chat-stream-activity
php artisan test tests/Unit/Services/ChatToolActivityServiceTest.php \
  tests/Feature/Quality/ChatToolLifecycleFrontendContractTest.php
npm run build
php artisan codex:offline-pipeline-tools --json --compact
```

For a new request, inspect the private SSE trace and stored
`chat_messages.tool_calls` structure. Every admitted `tool_call` should have a
strict request-local `tool_<iteration>_<position>` ID, and exactly that ID must
reach `tool_result` or `tool_error`. A current row left at `running` indicates a
missing terminal event or a producer/bundle older than the interruption-order
fix. Current PLOS emits a fixed content-free `tool_stream_interrupted`
`tool_error` for each still-open call before the run-level
`stream_interrupted` event. It never closes a completed call twice and never
manufactures success.
A reopened legacy row with no ID intentionally displays `Recorded` because its
historical outcome cannot be proven.

`tool_lifecycle_invalid` means a live event had a missing/malformed ID, reused a
call ID, or supplied a terminal ID that had no admitted call. The safe browser
receipt contains no tool result or raw exception; use the private server log to
find the fixed `chat_tool_lifecycle_*` reason and repair the producer/order. Do
not generate a replacement ID, replay the terminal event against another call,
or downgrade the failure to a display warning. A repeated terminal event for an
already terminal call remains a no-op because first terminal state wins.

If the event stream is correct but the UI is not, confirm that the completion
event contains the authoritative `tool_calls` array and that the current Vite
bundle is deployed. Do not expose tool arguments, result bodies, or raw
exception messages to make diagnosis easier. Private server logs retain the
raw exception. Do not reset the ledger at `tool_start`, relax ID validation,
fabricate live identity after dispatch, add browser-side execution authority,
or enable prompt compression or precompaction.

### AI Hub shows an invalid failure receipt

Run the content-free failure and SSE contracts, then rebuild the asset:

```bash
npm run test:chat-run-failure
npm run test:chat-stream-activity
php artisan test tests/Unit/Services/ChatRunFailureServiceTest.php \
  tests/Feature/Quality/ChatRunFailureFrontendContractTest.php
npm run build
php artisan codex:offline-pipeline-tools --json --compact
```

`chat_failure_contract_invalid` means the browser received an unknown code,
wrong schema, mismatched run UUID, invalid UUID, or `raw_error_included` other
than false. Compare the card's opaque reference with the private
`Streaming chat failed` log entry. Do not paste exception text into the browser,
relax UUID/code validation, or convert raw provider responses into display
messages. A card without a reference can be a legitimate browser-local
HTTP/network failure before `start`.

The receipt does not persist across conversation reload and does not authorize
automatic retry. Retry deliberately from the restored input after checking the
fixed code. Do not enable provider rerouting, frontend tools, prompt compression,
or precompaction to hide a failure-contract defect.

### Review Hub producer filter is empty or incomplete

The **Producer** selector is populated by server-side `producer_counts`, not by
the current browser page. Clear category/type filters first if the expected
producer belongs to another queue. Rows without `agent_id` intentionally appear
only under **All producers**. If a selected producer returns an unexpected row,
inspect `/api/research-hub/items?agent_id=<exact-id>` and confirm every returned
`agent_id` is an exact match; substring and display-label matching are not used.
Do not invent producer identity for legacy/system rows or weaken face/photo
operator review to make a filter look complete.

### I cannot find picture approval in a genealogy review packet

A `face_person_source_repair_plan` row in Review Hub is a source-evidence
packet, not a picture-identity match. It now opens as **Face and photo review**
and shows one photo candidate at a time. The candidate must show the actual
photo, **Currently linked person**, and a different **Person named by exported
record**. If either name or candidate-specific media is missing, the packet is
diagnostic, excluded from the action-ready inbox, and cannot be closed as
reviewed. Do not infer the missing side from the filename, confidence, internal
claim, or the other displayed name.

For an actual queued face/person identity match, use the packet's **Open Picture
Match Approval** link or **Review Hub > Picture Matches**, then open one row.
The **Picture identity review** shows the detected
crop, full source photo and detected region before the individual **Approve &
link** or **Reject suggestion** decision. Approval stays disabled until the
operator acknowledges comparing the visible evidence. It may update the
file-registry face identity and genealogy person-media link; rejection changes
only the queue outcome and creates no identity link. The older **Genealogy >
Picture Review > Approve Picture Matches** route remains an alternate entry to
the same operator-owned work class.

If Picture Match Approval has no actionable row, do not treat a source packet
as a substitute approval. Request regeneration/clarification for an incomplete
packet and leave identity unchanged. Packet outcome controls change workflow
state only; they never identify a person, link a face, approve a photo match, or
write Family Tree data. Never approve from confidence, one name label, or an
agent suggestion without comparing the visible images.

### Generic framework release notices appear in Review Hub

`framework:currency-check` is retired and must not create or notify new review
items. Run it directly to confirm that it reports the whiteroom and LLM
portfolio replacements without network or database work. The scheduled
`framework_currency_check` row should be disabled with no next run.

Pending zero-priority `system` rows with `review_type=finding`, no
`finding_type`, a legacy GitHub/HuggingFace/Ollama summary, and the matching
structured source key are migration-owned legacy noise. Do not register a
generic approval type for them or translate a release announcement into an
approval. Repository/research drift belongs in `whiteroom:monitor`; evaluated
model changes belong in `ai_model_update` through
`ops:llm-portfolio-review`.

### Focused review target is old but stale count is zero

Run the same focus in compact and full JSON and compare `next_target.age_days`,
`stale_threshold_days`, `focus_summary.stale_rows`, and
`focus_summary.age_bucket_counts`:

```bash
php artisan ops:review-backlog-report --next-target \
  --focus=source-backed-packet --json --compact
php artisan ops:review-backlog-report --next-target \
  --focus=source-backed-packet --json
```

If `age_days` meets or exceeds the threshold, the focused stale count must be
nonzero and exactly one age bucket must account for the target. A zero means the
deployed focused-summary contract is stale or inconsistent, not that the queue
has no age pressure. Verify `plos.review_queue_observability.v1`, the deployed
application hash, and the current frontend asset before changing data.

Do not approve packets, alter timestamps, widen the stale threshold, expose
private packet fields, or enable batch decisions to make the metric agree.
Operator Evidence is aggregate-only; use its **Review Hub** link for detail and
operator decisions. Face/photo identity remains operator-owned, and prompt
compression/precompaction remain off.

If an agent ends with `terminal_state=stuck`, inspect its bounded
`loop_detected` episode. `identical_action_observation` means the same action
and result repeated, `repeated_action_error` means one action kept failing,
`alternating_action_observation` is a two-step cycle, and
`periodic_action_observation` is a three-to-five-step cycle. Use
`pattern_length`, `repetitions`, and `occurrences` to identify the control-flow
shape; raw tool parameters and results are intentionally absent. Fix the tool
contract, search plan, or phase transition rather than raising the outer
iteration cap. A same tool with different parameters or results does not match
the periodic fingerprint and remains productive.

The script writes to `~/.codex/model-catalogs/plos-offline-models.json` by
default and keeps a timestamped backup. Public installs can point it at a local
Ollama service with `OFFLINE_DEV_OLLAMA_URL` or `OLLAMA_HOST`.

After a Jetson candidate is explicitly admitted for testing, refresh its catalog
separately so `/model` shows the exact artifact and source label:

```bash
OFFLINE_DEV_OLLAMA_URL=http://192.168.50.30:11434 \
  scripts/offline-dev-refresh-model-catalog.php \
  --catalog "$HOME/.codex/model-catalogs/plos-offline-jetson-models.json" \
  --label "Jetson Ollama"
```

Before installing or promoting another local model, use the table-owned runtime
inventory and read-only readiness audit:

```bash
php artisan llm:capability-watch --json
php artisan ollama:check-upgrades --dry-run --vram=12 --json
```

The readiness audit evaluates registered inventory and the exact table-owned
hardware-admission policy; it does not pull, remove, promote, reconfigure, or
restart anything. Published fit does not need a broad repeat benchmark. Before
routing, complete only the reported exact role/tool/schema confirmation,
time-boxed canary, privacy/role review, and rollback gate.

If the Semgrep MCP reports `MCP startup interrupted`, confirm its Codex MCP
entry uses writable settings/cache paths under this checkout:

```bash
codex mcp get semgrep
```

The expected Semgrep MCP environment includes `SEMGREP_SETTINGS_FILE`,
`SEMGREP_LOG_FILE`, `SEMGREP_VERSION_CACHE_PATH`, `XDG_CONFIG_HOME`, and
`XDG_CACHE_HOME` pointing under `storage/app/mcp/semgrep/`. Re-register it if
needed:

```bash
codex mcp remove semgrep
codex mcp add semgrep \
  --env XDG_CONFIG_HOME="$PWD/storage/app/mcp/semgrep/config" \
  --env XDG_CACHE_HOME="$PWD/storage/app/mcp/semgrep/cache" \
  --env SEMGREP_SETTINGS_FILE="$PWD/storage/app/mcp/semgrep/config/settings.yaml" \
  --env SEMGREP_LOG_FILE="$PWD/storage/app/mcp/semgrep/semgrep.log" \
  --env SEMGREP_VERSION_CACHE_PATH="$PWD/storage/app/mcp/semgrep/cache/version" \
  -- "$(command -v semgrep)" mcp -t stdio
```

Run the repeatable local Codex eval harness before trusting a new prompt,
profile, model, or routing change:

```bash
php artisan codex:offline-eval --json
php artisan codex:offline-eval --json --execute --case=exact_reply
php artisan codex:offline-eval --json --execute --case=plos_media_capabilities
php artisan codex:offline-eval --json --execute --case=scratch_php_slug
php artisan codex:offline-eval --json --execute --case=scratch_php_slug_terminal
```

The eval harness records redacted diagnostics such as command-execution counts,
token usage, metadata warnings, and malformed patch attempts. Scratch edit
cases run under `storage/app/codex-offline-eval/` and are removed after passing
unless `--keep-workspaces` is set. Use the terminal-first scratch case when a
local model can reason about the task but emits malformed patch payloads; that
case forbids `apply_patch`, requires command execution, and independently runs
`php test.php` afterward.

Current connector diagnostics count only completed Codex CLI command/agent
events. A started/completed pair represents one execution, not two. The
trajectory sequence contains only canonical action, success, and a SHA-256 pair
fingerprint, is capped at 500 entries, and never returns command or output text.
`tool_sequence_unavailable` indicates missing structural evidence;
`tool_sequence_truncated` indicates only the bounded prefix was assessed. If
the executable verifier passes but the case still fails, check the required
final response marker before changing the model route or verifier.

To diagnose an anchor comparison, first rerun without `--compact`:

```bash
php artisan codex:offline-eval-compare baseline.json candidate.json --json
```

- `blocked` means a file is unreadable, over 1 MiB, invalid JSON, violates the
  exact receipt schema, or fails its SHA-256 integrity check. A self-hash is
  tamper detection, not author authentication; recover the file from its
  private operator-controlled source rather than editing and rehashing it.
- `not_comparable` means the receipt schema, case set, case-contract hash,
  harness identity, or product configuration differs. Recreate both receipts
  from the same deployed evaluator and configuration.
  `harness_identity_missing` identifies a readable legacy v1 receipt;
  `harness_identity_mismatch` identifies evaluator, connector, verifier,
  receipt, assessor, verifier-config, or PHP-runtime drift. Neither can be
  overridden. Use `--allow-configuration-change` only when a model/profile
  difference is intentional and an informational result is sufficient.
- `insufficient_evidence` means one or more cases lacks the native trajectory
  assessment or evaluator-owned formal verification. Do not treat advisory or
  legacy benchmark rows as a baseline.
- `configuration_comparison` is intentionally not gate-eligible even when no
  regression code appears.
- `regression` identifies affected cases and stable codes. Inspect the original
  private eval output/workspace; the receipt intentionally contains no prompt,
  response, error, diagnostic, or tool content.

Run the versioned retrieval gate before changing embedding or reranker models:

```bash
php artisan rag:rerank-eval --json --compact --fail-on-regression
php artisan rag:rerank-eval --use-embeddings --json --compact --fail-on-regression
php artisan rag:rerank-eval --use-embeddings --json --compact --require-promotion-ready
php artisan rag:rerank-eval --candidate-file=docs/examples/rag-claim-candidate.json --json --compact --require-grounding-ready
```

The first command enforces the deterministic lexical regression floor. The
second requires every fixture to use the table-routed embedding path; a silent
lexical fallback fails the provider gate even if ranking happens to remain
correct. The third is the challenger gate and fails unless stricter promotion
thresholds pass. Version 2 uses heterogeneous graded qrels and hard negatives,
then reports MRR, hit@1, NDCG@3, recall@3, precision@3, MAP@3, hard-negative
at rank one, claim recall, and context precision. Embedding mode also fails closed to the unmodified lexical ranking
if any fixture document fails to embed or returns a different vector dimension;
partial coverage cannot claim provider success. All forms emit only fixture
hashes, ids, metrics, and aggregate
posture. They do not include fixture or instruction text, change production
vectors, or promote a model.

The fourth command exercises the separate deterministic generator-grounding
gate. Its versioned candidate JSON contains claim ids and cited support ids,
not response text. Unknown claims raise hallucination rate; known claims cited
to a non-authoritative or non-retrieved document raise unsupported-citation
rate and fail faithfulness. Missing claims reduce completeness instead of being
silently treated as correct. The 256 KiB regular-file/no-symlink input boundary
prevents the command from becoming an arbitrary content ingestion path.

If KG point-in-time results are contradictory or a provenance snapshot reports
new pending work, run:

```bash
php artisan graph:audit-provenance --json --compact --samples=0 --strict
```

`invalid_valid_time_ranges` means `valid_until` precedes `valid_from`.
`transaction_time_inversions` means an edge expired before it was learned.
`active_superseded_triples` means an edge is still active despite naming a
replacement. `invalid_temporal_confidence` is outside `0..1`.
`supersession_cycle_members` indicates a cyclic replacement chain, while
`superseded_triples_without_history` means the audit trail does not prove the
replacement action. The command is diagnostic only: inspect bounded samples
without `--compact`, establish source-backed intent, and use an existing
reviewed repair path rather than editing or deleting graph history ad hoc.
The pre-commit SQL validator understands named and recursive CTEs as local
relations while still validating their physical source tables. An unknown-table
failure for a CTE name therefore indicates validator drift and must not be
bypassed by obscuring or dynamically assembling the SQL.

To evaluate the pinned Qwen3 cross-encoder challenger without changing normal
RAG or model routing:

```bash
scripts/install-qwen-reranker-runtime.sh
php artisan rag:reranker-sidecar start --json
php artisan rag:rerank-eval --use-reranker-sidecar --json --compact --require-promotion-ready
php artisan rag:reranker-sidecar stop --json
```

The installer creates an isolated CPU venv under
`~/.local/share/plos/qwen-reranker`, downloads only the pinned official model
revision, and verifies the safetensors checksum. The server binds only
`127.0.0.1:9230`; every endpoint requires an installation-derived bearer token
plus loopback Host/Origin validation, and request text is not logged. CPU is the
default because GPU compatibility is host- and Torch-build-specific. A running
sidecar still cannot promote a model or modify production vectors. Inspect
`storage/logs/reranker-sidecar.log` when startup fails.

Operational tuning comes from the validated
`system_configs.rag.reranker_sidecar_runtime_policy` row, not environment
timeouts or batch settings. `rag:reranker-sidecar status --json` reports the
effective health/lifecycle/request budgets and inference `max_length`,
`batch_size`, and `threads`. An unavailable or invalid policy fails before HTTP
or process dispatch and does not start the sidecar. Model, revision, device,
URL, executable, and cache paths remain deployment configuration.

The 2026-07-12 production baseline with Qwen's official instruction is not
promotion-ready: MRR `0.6875`, NDCG@3 `0.5208`, recall@3 `0.5625`, precision@3
`0.375`, MAP@3 `0.4063`, and hard-negative-at-1 `0.0`. This is a useful split:
the model rejected explicit false alternatives better than the embedding blend
but omitted or misordered too many relevant passages. Do not lower thresholds
or replace `embeddinggemma:latest` to make this candidate pass.

For deterministic local repo inspection when the small model struggles with MCP
tool calls, prefer slash commands:

```bash
php artisan offline:programming --json "/features"
php artisan offline:programming --json "/routes api"
php artisan offline:programming --json "/tools repo-dev"
```

When several repo-dev writes succeeded but the agent's final scope is unclear,
call `repo-dev.summarize_repo_edits` with the returned recovery tokens in their
original order. `status: ok` reports the net files and line counts without file
bodies or token values. A `continuous ordered edit chain` error means a touched
file changed between captured operations, the tokens were reordered, or an
operator edit intervened; inspect the live file and do not reorder or suppress
the failure. Expired, restored, tampered, and unknown tokens remain invalid.

## Offline Or Degraded Status Looks Wrong

Start with the narrow read-only status payload:

```bash
php artisan ops:offline-status --json
php artisan ops:offline-smoke --json --compact
```

The same data is available to authenticated web-session consumers at
`GET /api/ops/offline-status` and exact-scope read-only bearer clients at
`GET /api/v1/ops/offline-status`. The full Operator Evidence dashboard at
`/operator-evidence` includes this offline/degraded section with the broader
queue, backlog, review, and operational cards.
`ops:offline-smoke --json --compact` adds a manual report-only check of the
audit reader, profile-filtered MCP catalog boundary, and local runtime
scorecard; it does not change profiles or write audit receipts. Use the compact
form when sharing or logging the result; it keeps statuses, reason codes, and
aggregate counts while
omitting nested payloads, raw details, server lists, paths, prompts, traces, and
environment values.

Use the status as follows:

- `healthy` usually means `active_profile=default`, `runtime_state=normal`,
  the offline kill switch is disabled, and recent offline audit summaries are
  readable;
- `watch` is expected after recent policy denials, profile changes, or use of a
  non-default offline/hybrid profile;
- `degraded` is expected when `offline_mode_active=true`, because cloud
  providers are intentionally blocked and local-provider classes are forced;
- `blocked` means the collector could not read the policy or audit backing
  sources and should be treated as an operational fault.

The same payload also includes local runtime fields such as
`local_runtime_status`, `local_availability_state`, `healthy_local_instances`,
and `selected_local_model`. These are read from local monitoring rows and do not
perform a routing dry run or write policy-audit receipts.

For recent denial or profile-change context, inspect the audit reader:

```bash
php artisan offline:audit --tail --json
```

Do not widen a profile or disable offline mode just to clear a warning. Confirm
which class is denied, whether the denial is expected for the active profile,
and whether the requested action should remain local, be deferred, or be
reviewed under a different operator-approved profile.

If an `offline:dev-assist --json` response includes `trace_written=false`, check
the private trace reader before changing any policy:

```bash
php artisan plos:agent-trace-tail --limit=20 --since=24 --json --compact
```

An empty trace tail usually means tracing is disabled, the storage directory is
unavailable, or the trace event was rejected because it contained a forbidden
raw field. Trace failures should not be fixed by broadening profile permissions.
Through the PLOS MCP `plos_artisan` wrapper, use only that exact compact
trace-tail form. Raw tail output, trace-specific filters, wider windows, higher
limits, reordered flags, and detail-expanding forms stay blocked through MCP. If
the compact form is blocked while source allows it, check
`plos_artisan list` for the allowlist revision and treat the mismatch as a
loaded-session freshness issue, not a reason to widen the trace command.
If the MCP contract build or scheduled audit reports drift or a violation, run:

```bash
php artisan ops:mcp-tool-contract-audit --json --compact --fail-on-violations
```

Use `drift.added`, `drift.removed`, `drift.changed`, and the violation codes to
inspect the corresponding registration source. Hidden metadata characters,
instruction-like descriptions, confusable names, schema/annotation failures,
and unexpected digest changes are blocking conditions. Do not install or run
Agent Scan against arbitrary MCP configs as a shortcut: its scan path can
execute configured MCP commands, and external scanning can disclose tool
metadata. Do not refresh `tool-contract-baseline.json` until the source change
is intentional and reviewed. For an approved change, run
`npm run refresh:tool-contract-baseline`, inspect the baseline diff, and rerun
`npm test`.

`audit_artifact_missing` means the MCP TypeScript output was not built or the
reviewed baseline is absent; run `npm run build` in `mcp-servers/plos`.
`probe_connection_closed`, `probe_request_timeout`,
`probe_child_process_error`, `probe_protocol_error`, and `probe_unknown` are
classified, content-free Node probe failures. The `probe` block records one
attempt, no automatic retry, the eight-second/64-page bounds, and a SHA-256 for
correlating repeated private diagnostics. Check Node availability, the compiled
entrypoint, the locked MCP SDK version, and private MCP logs; never reinterpret
that state as a pass or refresh the baseline. `invalid_audit_output`,
`audit_runner_failed`, or exit code `2` means the runner failed before even
classified evidence was produced and is also blocking. Repeated timeouts,
pagination exhaustion, or connection-close errors require checking the fixed
PLOS server and child lifecycle, not widening permissions, adding an automatic
retry, or bypassing the audit. A nested-process
`EPERM` or forced child termination in a restricted development sandbox is an
environment limitation, not contract drift; rerun the same trusted local
command in the normal host context and require the exact digest to pass.
If an MCP call reports `cancelled by client`, distinguish it from a server
timeout. Client cancellation can come from an operator stop, client request
timeout, or session teardown; retry only after confirming the original action
did not complete. `timed out after ...` is the independent server deadline. For
cooperating command tools both paths terminate the child process; neither path
widens permissions or retries writes automatically. If a client does not show
structured fields, use the retained text result and confirm it negotiated MCP
`2025-06-18` or newer. The locked official client now negotiates `2025-11-25`;
the real 2025-06 compatibility test remains intentional, and older clients can
ignore `structuredContent` and tool annotations.
If Operator Evidence reports files over `DEV_AGENT_TRACE_RETENTION_DAYS`, treat
that as an operator cleanup signal only; trace readers do not delete files.
If `/doctor` reports missing dev-assist tools, compare its `tool_readiness`
block with `config/dev_agent.php` and the active MCP/offline profile before
loosening permissions.
If `/doctor` reports no selected local model, check `runtime_scorecard` first;
it gives the local instance count, healthy count, and selected local/routed ids
without exposing prompt or response content.

If `ollama:eval-run` fails `schema_compliant`, do not weaken the registered
schema or count a semantically plausible response as a pass. Compare the
case's `structured_output_schema_id` with `config/ollama_output_schemas.php`.
Wrong nested types, enum values, required fields, or extra properties can pass
top-level key and task checks while still violating the contract. Confirm the
selected provider/model has an admitted structured-output transport, then fix
the model, provider capability metadata, or response behavior and rerun the
complete `plos.genea_local_model.v2` suite. A
`registered_schema_compliance` critical failure blocks promotion across every
provider; a single-case rerun remains diagnostic only.

If Agent Doctor reports `agent_behavior_golden_eval` as warning, run:

```bash
php artisan ops:agent-behavior-golden-eval --json --compact --fail-on-violations
```

Use the failed case and violation codes to identify the production contract;
do not add fixture contents to compact output. A prompt-injection failure points
to `config/injection_patterns.php` or `AgentGuardrailService`; a structured-
output failure points to `validateOutput`; a tool failure points to the tool
parser or `OfflinePolicyService`; and an evidence failure points to
`EvidenceVerdictPolicyService`. A `trajectory_recovery_*` failure points to the
synthetic perturbation definition or recovery classifier in
`AgentBehaviorGoldenEvalService`: verify the zero-based perturbation step, its
explicit/implicit and transient/permanent class, the recognized-failure flag,
the recovery-call budget, and whether the trace retries, changes tools, or
abstains. Do not make permanent same-tool retries pass or treat implicit corrupt
output as success merely to clear the gate. The command performs no live model call or
policy audit write, so a failure is deterministic and should be fixed before
deployment rather than dismissed as provider variance.

An `exact_params` failure means the expected tool was selected but its complete
parameter object differed. A missing parameter-qualified milestone appears as
`trajectory_missing_milestones` or `trajectory_corrupt_success`. Compare the
fixture definition to the parser result locally; never add raw parameter values
to compact output. Object key order is intentionally irrelevant, but list order,
scalar type, missing keys, extra keys, and values are exact contract differences.

If deferred work is early, late, missed, repeated after cancellation, or run
before a prerequisite, run:

```bash
php artisan ops:prospective-intent-eval --json --compact --fail-on-violations
```

`metric_true_positives`, `metric_false_positives`, or
`metric_false_negatives` indicates a due-set classification regression.
`metric_update_violations` covers canceled, completed, or stale-revision
selection; `metric_dependency_violations` covers work selected before a prior
intent completed; timing and monitoring failures use their corresponding safe
`metric_*` codes. Compact output intentionally omits intent ids. Fix the native
state transition or fixture expectation, then separately inspect live scheduler
and terminal evidence; this deterministic gate neither proves a production run
occurred nor repairs one. Do not enable prompt compression or precompaction to
clear it.

A `trajectory_invalid_definition_count` failure is evaluator configuration,
not agent behavior. Confirm every `after` identifier exists and that the graph
is acyclic. Milestone array order is intentionally non-semantic; do not reorder
definitions merely to make a valid DAG pass. Unknown or cyclic dependencies
must fail closed before a candidate can receive procedural-integrity credit.

For memory-lifecycle cases, an `excluded_id:*` violation or lifecycle failure
points to `AgentMemoryRecallPolicyService::recallEligible`. Only explicit state
fields should exclude a candidate; do not use similarity or recency to infer
supersession or forgetting. An `included_id:*` contradiction failure means
active conflicting evidence was hidden. An `abstained` failure means an
all-ineligible candidate set did not return empty memory context.

A failure in `limited_memory_context_preserves_near_scoring_adverse_lesson`
points to bounded selection after ranking. Confirm the evaluator passes the
fixture's `input.limit`, then inspect
`agent_memory.recall.outcome_diversity_score_margin` and
`outcome_diversity_min_objective_match`. The expected behavior keeps the top
item and one close success/adverse contrast for the same objective; it must not
force a weak or unrelated failure. Do not widen the score margin, lower the
objective threshold, increase context size, or enable compression merely to
clear the gate. This selector does not infer contradiction or lifecycle state.

A `memory_disposition` or `memory_usable` failure points to
`AgentMemoryUsePolicyService`, not the retrieval ranker. Verify these five
boundaries independently: remembered belief is not factual evidence; subject,
task, and preference scope match; stronger current evidence wins; explicitly
superseded state is excluded; and an active unopposed preference remains usable
for personalization. Also inspect a focused agent system-prompt test and confirm
exactly one `## Memory Use Policy` block appears outside all `BEGIN EXTERNAL
DATA` envelopes. Do not clear the gate by deleting memory or enabling lossy
prompt compression.

If `agents:skill-audit` reports an `agent_skills_spec` failure, inspect the
finding rule before considering the optional SkillSpector result. Required
metadata/body failures are deterministic local validation errors:

```bash
php artisan agents:skill-audit --format=json --compact --fail-on=do-not-install
```

Fix `missing_frontmatter`, invalid YAML, missing `name` or `description`, name
and directory mismatch, invalid optional fields, or
`missing_skill_instructions` in the affected `SKILL.md`. A lone
`skill_progressive_disclosure_limit` and
`skill_progressive_disclosure_token_limit` are cautions over the Markdown
instruction body, not runtime frontmatter. The token value is a documented
`ceil(UTF-8 bytes / 4)` estimate because tokenizer counts vary by model. Keep
essential activation, safety, privacy, review, and write instructions in
`SKILL.md`, then move task-specific detail into one-level
`references/` files. `missing_skill_reference`,
`skill_reference_path_escape`, and `skill_reference_symlink` are blocking;
`skill_reference_depth` is a caution. Do not disable the audit or enforce the
advisory sidecar merely to change that specification result.

If `skill_reference_read` fails, confirm the active skill declares the tool,
the value is a Markdown basename such as `workflow.md`, and the file is
directly under that skill's `references/`. Nested paths, symlinks, files over
32,000 bytes, and missing trusted runtime `agent_id` context are rejected. Do
not expose `agent_id` to model input or bypass this with general file access.

For a sidecar advisory, use the full JSON report and inspect
`sidecar.reports[].issues`. It contains rule and location metadata but no
matched phrase or scanner explanation. Raw scanner stdout/stderr is not
persisted; use the content-free byte counts and SHA-256 values under
`io_evidence` to correlate a scanner run.

If `agent_skill_drift_audit` fails while the recommendation and finding counts
are unchanged, inspect `drift.changed.content_digest` and
`drift.skill_content_counts`. The integrity digest covers the whole skill
bundle, not only `SKILL.md`; a changed script, reference, safety card,
evaluation file, or benchmark is intentional drift that still needs review.
Run the full JSON command without `--compact` and compare the content-free file
paths and hashes in `integrity.skills`. Do not suppress the drift by deleting
the previous report. The first comparison against a legacy report that has no
integrity block establishes a baseline and does not report digest drift by
itself.

`skill_content_sha256_mismatch` means the audited bundle no longer matches the
operator-supplied `--expected-content-sha256` pin. Re-establish source,
revision, and license provenance before accepting a new digest. A
`present_unverified` detached signature only means the signature file exists;
it must not be treated as verified without the correct upstream trust anchor
and verifier.

If `--queue-review` creates a new row instead of reusing an existing pending
skill review, compare only the content-free `review_scope` blocks. A changed
`content_sha256` means bundle bytes changed; source-presence or `scope_sha256`
changes can also reflect a different source URL, ref, license, expected digest,
target, integrity state, or skill count. This is intentional fail-safe behavior:
do not edit the new scope hash or reuse the older packet manually. Resolve the
source/revision difference, keep both packets auditable, and review the exact
new bundle. A v1 report remains valid drift history but has no exact review
scope and must not be treated as approval for later bytes.

## Backup Skill Appears Missing Or Inactive

PLOS intentionally does not discover `.bak`, underscore-copy, path-like,
symlinked, or name-mismatched skill directories. Active skill basenames must be
lowercase letters/digits separated by single hyphens, no longer than 64 bytes,
and exactly equal the frontmatter `name`. The same list drives the agent index,
scheduled-job smoke validation, skill count, direct loading, reference access,
rollback, and agent execution.

Use `php artisan agents:skill-audit --path=/reviewed/path --format=json` to
inspect an inactive copy explicitly. That command does not activate it. If the
copy is intentionally replacing an active skill, reconcile its source,
revision, license, integrity digest, directory name, and frontmatter name, then
move it through the normal review/install path. Do not weaken discovery or add
an alias for a stale backup.

If a GenAI telemetry collector does not classify PLOS spans, inspect the strict
payload without transmitting it:

```bash
php artisan ops:gen-ai-telemetry-export --hours=1 --limit=10 --otlp-json
php artisan ops:gen-ai-telemetry-export --hours=1 --limit=10 --source=retrievals --json
php artisan ops:gen-ai-telemetry-export --hours=24 --limit=10 --source=workflows --json
```

First inspect the PLOS envelope and require
`interoperability.otlp_json.status=pass`. `traceId` must decode from canonical
base64 to exactly 16 nonzero bytes; `spanId` and optional `parentSpanId` must
decode to 8. `startTimeUnixNano`, `endTimeUnixNano`, and protobuf integer
attributes remain decimal strings. Hex-looking IDs indicate a stale pre-fix
export, not a collector configuration problem. A validator exception means the
payload was blocked before output; the command returns only a fixed failure and
no partial payload or internal validator detail. Do not bypass it or rewrite
IDs by hand.

Model, agent, tool, and retrieval spans should contain both
`gen_ai.operation.name` and `openinference.span.kind`; workflow and node spans
use `openinference.span.kind=CHAIN` plus `plos.workflow.*` attributes. The PLOS
JSON envelope should report equal `spans` and `openinference_spans` counts. Do
not solve collector rendering problems by
adding prompts, messages, documents, vectors, tool parameters, raw session IDs,
or exception details. For Phoenix, also verify the collector version, OTLP
endpoint, authentication, project routing, and retention configuration. PLOS
does not transmit automatically, so an empty Phoenix project does not indicate
a failed PLOS scheduler.

If the human summary reports fewer `tool_ids` than `tools`, first constrain the
window to calls made after exporter scope `1.6.0` was deployed. Older
`agent_execution_log` rows intentionally have no authoritative invocation ID.
A continuing gap on new calls means the `mcp_tool_calls` insert-to-audit mirror
failed or the audit context was invalid; inspect aggregate counts and the
application warning log, then reproduce with a bounded read-only tool. Do not
invent IDs from timestamps, sessions, tool names, or row proximity, do not
backfill ambiguous history, and do not dump tool parameters, results, raw audit
context, or session IDs. Invalid legacy JSON is treated as a missing scalar and
must not suppress otherwise valid agent spans.

For retrieval-only output, expect `gen_ai.operation.name=retrieval`,
`gen_ai.data_source.id=plos-rag`, and
`openinference.span.kind=RETRIEVER`. A partial envelope with
`rag_query_traces_unavailable` indicates schema/connection drift; one with
`rag_query_traces_query_failed` indicates a read failure. Check the
`pgsql_rag` connection and `rag_query_traces` schema. Do not work around either
condition by selecting or exporting `query_text`, `metadata`,
`filters_applied`, or retrieved document content.

If retrieval spans have null durations, no phase attribution, or a phase report
shows `legacy_only`, inspect the current content-free lifecycle without reading
queries:

```bash
php artisan rag:trace-phase-report --hours=24 --limit=5000 --json --compact
php artisan rag:trace-hygiene --limit=50000 --json --compact
```

`unavailable` means the configured `pgsql_rag` trace source cannot be read.
`legacy_only` means the selected window contains only pre-v2 incomplete rows;
it is not latency evidence. A nonzero `failed_receipts` count identifies
measured search failures without exception text. Run a bounded traced search
after deployment before diagnosing a lack of v2 receipts. To remove legacy raw
query/filter/metadata content, review the hygiene dry-run and then use
`--execute --confirm`; the command preserves rows and replaces content with
keyed fingerprints and fixed metadata. Do not dump legacy `query_text` while
diagnosing or copy it into logs, tickets, or telemetry.

For workflow-only output, expect only terminal `completed` or `failed` run
spans, node `parentSpanId` values that resolve to their workflow span, and equal
`spans`/`openinference_spans` counts. A queued or running request must not
appear. `workflow_runs_unavailable` and `node_executions_unavailable` indicate
schema drift; the corresponding `*_query_failed` values indicate read failure.
Do not add workflow names, inputs, outputs, raw errors, or node payloads to
diagnose the collector.

If `browser:server status` reports a running process as stopped, the process may
be a legacy sidecar without the authenticated health contract. Restart through
Laravel rather than launching the script directly:

```bash
php artisan browser:server restart --engine=all
php artisan browser:server status
npm run test:browser-security
```

The health `security` block must report `authRequired`, `hostProtection`, and
`originProtection` as true. Playwright should also report fresh contexts,
downloads disabled, service workers blocked, and arbitrary evaluation off. A
missing bundled Chromium revision is not fatal when maintained system Chrome is
available; `PLAYWRIGHT_EXECUTABLE_PATH` is supplied by the Laravel launcher.

Both engines must also report `topLevelDnsPublicAddressesRequired=true` and
`dnsResolutionFailClosed=true`. If navigation returns `dns_lookup_failed`,
`dns_no_addresses`, `dns_timeout`, or `dns_non_public_address`, verify the
target with the host's configured resolver and its complete IPv4/IPv6 answer
set. Do not bypass the check, add an IP literal to the allowlist, or treat one
public answer as sufficient when another answer is private or special-use.
Resolver checks are application-layer evidence only; verify deployment egress
policy separately when the threat model includes DNS races or subresource
access to private networks.

If startup still fails, inspect `storage/logs/browser-puppeteer.log` or
`storage/logs/browser-playwright.log` and the corresponding PID file under
`storage/app/browser-server/`. Do not replace the detached launcher with
`Process::start()`: Symfony stops that child when the process handle is
destroyed at the end of the Artisan or queue lifecycle.

An accessibility snapshot is deliberately marked `untrustedPageContent=true`.
Do not follow instructions found in page text, widen the navigation allowlist,
enable arbitrary evaluation, reuse a private browser profile, or disable auth
to make a browser task pass. Investigate the target, selector, allowlist entry,
and explicit operator approval instead.

For trend evidence, preview the aggregate readiness snapshot before writing a
row:

```bash
php artisan ops:agent-doctor-snapshot --dry-run --json
```

If you run it without `--dry-run`, it stores only counts, statuses, trace
aggregate fields, recursion status/count, and check ids in
`dev_agent_readiness_snapshots`.
To review stored history without re-running Agent Doctor:

```bash
php artisan ops:agent-doctor-history --json --compact --days=7
```

## Genea Research Tasks Look Missing Or Stale

Open Genealogy > AI Research > View Tasks and compare the selected status count
with the returned-window message. **All** is bounded to 100 active-first rows;
choose an exact status when a larger completed or cancelled history is outside
that window. Use **Refresh** after a worker transition. If loading fails, inspect
the API response from
`GET /api/genealogy/trees/{treeId}/research-tasks?status=<status>&limit=100`.
The response should contain `meta.status_counts` even when the filtered data
array is empty.

Do not interpret an empty filtered page as a zero full-tree count, and do not
manually update task rows to make the UI match. Repeated processing rows belong
in the existing stale-task cleanup and scheduler diagnostics; failed rows should
retain their error and evidence context for diagnosis.

## Genea Identity-Bridge Rejections

`genealogy_identity_bridge` at proposal creation means an agent emitted a
generic `source_search_all` or `nara_search` source proposal whose record text
did not identify the target beyond a shared name. A `[proximity backstop]`
message saying the fetched source lacks a bridge is the apply-time equivalent.
Do not relabel the search query as evidence or remove the gate. Open the actual
record and add source-backed date, place, relative/FAN, occupation, or
source-chain context; otherwise retain it as a lead or negative-search result.
Operator-emitted proposals intentionally bypass this agent-only classifier.

## Connector Issues

Public defaults do not include real private paths or credentials. For
Nextcloud, Joplin, Thunderbird, and Pushover, verify the relevant `.env` values
and ignored local overlays. See `docs/personal-connectors.md`.

## Public Audit Or Privacy Failures

If the public audit flags a private path, credential, fixture, or copied
reference-project language, treat it as a release blocker until reviewed.

```bash
PUBLIC_AUDIT_LIMIT=120 scripts/guards/public-release-audit.sh
```

Public issues should include redacted logs, commands run, install tier, and
public-safe reproduction data only.

## OCR Candidate Gate Failures

Run the built-in v3 contract before diagnosing a real artifact:

```bash
php artisan content:ocr-candidate-eval --example --json --compact --fail-on-ineligible
```

`invalid_artifact_schema` means the artifact is not
`plos.ocr_candidate.v3`; regenerate it rather than relabeling v1/v2 evidence.
`missing_capability:*` means the artifact does not cover every required OCR
capability. `invalid_capabilities`, `invalid_capability`,
`duplicate_capability`, and `too_many_capabilities` identify malformed labels.
`capability_contract_mismatch:*` means a label is unsupported by its case:
blank and multi-page labels must match ground truth, spotting needs required
spans, structure needs a valid order pair, and text-bearing capabilities cannot
use blank expected output. Repair the artifact evidence rather than relabeling
an easy case.
`reading_order_case_required` means no valid `order_pairs` survived validation.
For `invalid_order_pair`, `invalid_order_anchor`,
`identical_order_anchors`, or `duplicate_order_pair`, keep each object limited
to distinct non-empty `before` and `after` strings within configured bounds.
`invalid_expected_order_pair` means the declared relationship is not present in
the normalized expected text; repair the ground truth before comparing engines.
Do not put prompts, commands, paths, or source text in diagnostic output.

A `*:reading_order_pass_rate` regression means a candidate lost a valid
before/after relation that the baseline preserved. An aggregate reading-order
failure means at least one required pair is absent or reversed even if edit
similarity remains high. Correct the OCR output or the operator-reviewed ground
truth; do not lower thresholds, drop the case, increase prompt compression, or
promote the route to clear the gate. Table relationships and geometric layout
are not proven by this metric.

A `case_quality_failures` entry is an absolute candidate failure, not a
baseline regression. The safe code names the case and its edit-similarity,
token-precision, or unit-test floor. This specifically prevents aggregate
scores from hiding one catastrophically poor case behind many easy cases.

## Repository Context Gate Failures

Run the expanded output first:

```bash
php artisan ops:repo-context-map-golden-eval --json --fail-on-regression
```

- `primary_file_ranked`: inspect path-term and content-term weighting; do not
  weaken the fixture merely to clear the gate.
- `minimum_relevant_recall`: confirm `rg` is installed and the repository path
  is readable, then inspect stop words and configured result limits.
- `mean_precision_at_k` or `mean_context_efficiency`: inspect irrelevant
  returned files and task grammar. Improve general ranking signals; do not hide
  distractors or lower authored relevance expectations merely to clear the
  gate.
- `hard_negative_ordering`: add a narrowly justified scorer signal or improve
  task grammar; avoid hardcoded file-name exceptions.
- `search_space_complete_rate`: inspect `coverage.reason_codes`, then compare
  inventory omissions, unscanned candidates, skip reasons, and the configured
  64 MiB scan budget. Do not confuse expected `result_window.complete=false`
  from a top-k limit with an incomplete searchable space.
- `inventory_limit_reached` only on a production release copy: check whether
  `.git` and `rg` are absent, then count generated virtual environments,
  dependency trees, caches, and MCP `dist` output. Add a general generated-tree
  boundary to `repo_context_map.native_excluded_segments` or
  `native_excluded_paths`; do not raise `max_indexed_files` or weaken the gate
  merely to inventory installed dependencies.
- Missing large frontend files: confirm `max_metadata_only_files` is positive.
  Metadata-only matches expose counts and paths, never source bodies.

`coverage.status=degraded` is actionable evidence, not a silent failure: the
searchable space completed but one or more candidates were skipped for a named
reason. `coverage.status=incomplete` means completeness was not proven and must
fail the strict golden threshold. Do not enable compact/TOON serialization or
prompt compression as a workaround.

The native exclusion policy must remain content-class based. Keep authored
`app`, `config`, `database`, `resources`, `routes`, `tests`, and operational
script sources searchable. Exclude generated environments and build products,
not an entire authored top-level tree because one subdirectory is large.

If inventory completeness returns to `1.0` but native production relevance
still differs from Git-backed development, inspect
`posture.native_content_search_source`. A release host without ripgrep should
report `grep`, `native_candidate_cap_globally_prescored=true`, and
`native_declaration_prescore_applied=true`. PLOS passes only the exact accepted
inventory paths to one global GNU grep content pass, declaration-enriches at
most 750 high-relevance candidates, then deeply analyzes the highest-scoring
250. If all six cases fail with `context_map_execution_failed`, inspect
`summary.phase_duration_ms`: production has previously required 67.6 seconds
when declaration grep covered the full inventory. Do not raise the 60-second
contract; verify the bounded declaration pool is active and identify the slow
phase. If both content
tools are unavailable, narrow the scope or install a reviewed tool; omitted
non-path inventory or a path-only cap must report incomplete.

If a widened diagnostic also exceeds 120 seconds, check host storage before
changing ranking again: `ps -eo state,pid,ppid,etime,comm,wchan:32,args` should
not show long-lived `D`-state journal or database processes. On 2026-07-16 the
production blocker was `jbd2/sdb2` plus PostgreSQL checkpointer/RAG insert I/O,
not a relevance failure. Uninterruptible tasks cannot be repaired with a normal
process signal; recover the underlying block device/filesystem path first.

For the optional graph sidecar, run:

```bash
php artisan whiteroom:sidecar-doctor --project=codebase-memory-mcp --format=json
codebase-memory-mcp --version
```

A version below `0.9.0` is not the reviewed baseline. A missing binary is not a
production failure because the sidecar is optional and disabled by default.
Never commit its cache or graph artifact. A sidecar disagreement does not
override a failed native golden gate.

## Video Render Bundle Failures

Run preview validation before a write:

```bash
php artisan media:video-render-spec --artifact=recaps/daily.json --json --compact
php artisan whiteroom:sidecar-doctor --project=hyperframes --format=json
```

- `Provide exactly one of --example or --artifact`: remove the extra source or
  provide the missing one.
- `Artifact path must be storage/app-relative`: move the JSON under
  `storage/app`; absolute paths and `..` are rejected.
- Schema, scene, duration, or text-limit failures: correct the source artifact.
  Do not loosen the bounded contract to admit raw reports or arbitrary HTML.
- `--bundle is required`: writes require `--write` and a new lowercase key.
- `Bundle already exists`: choose a new key. The command deliberately refuses
  overwrite; inspect or retire old artifacts through normal operator handling.
- Sidecar doctor warnings: a missing Node 22, npm, FFmpeg, or Chrome binary
  blocks the optional render pilot, not normal PLOS reports.

The command does not render. Do not work around that boundary with `npx`, cloud
publish, remote scripts/assets, or unattended Chrome on production. Validate
and lint a public-safe bundle in an isolated workspace before any MP4 trial.

## Offline Eval Product Identity Failures

New receipts use `plos.offline_coding_eval_receipt.v3` and bind a content-free
harness fingerprint plus the exact local Docker verifier identity. A valid
identity is `sha256:<64 lowercase hex>`; `unavailable` means Bubblewrap may
still work but no Docker fallback is admitted. Mutable tags such as
`local-php-verifier:latest` are rejected even with `--pull=never` because a
local tag can be retargeted. Read the exact local ID with `docker image inspect`
and store that ID in `ai.offline_coding_eval_verifier_docker_image` through the
normal table-owned configuration path.

Historical v2 receipts remain readable and comparable with other v2 receipts;
v1 receipts remain readable but have no harness identity and cannot authorize a
regression gate. v2 and v3 are intentionally non-comparable because v2 did not
bind verifier bytes. When an old schema or different harness/image is reported,
rerun the baseline on the current deployed evaluator. Do not edit or rehash the
old artifact, and do not treat `--allow-configuration-change` as a schema,
harness, or verifier-image override. These identities establish comparison
scope, not code authorship or backend runtime-model attestation.

Plan the relevant case without model execution first:

```bash
php artisan codex:offline-eval \
  --case=scratch_php_multifile_migration_hidden --json
```

Confirm `work_types=["feature","migration"]`, both evaluator-owned scope
controls, and the null/golden/adversarial calibration contract. An unchanged
workspace failing `required_change` is correct. A protected-file or
change-scope failure deliberately prevents public and hidden verifier execution.
If an otherwise correct golden fixture cannot execute, confirm PHP CLI and
`pdo_sqlite` exist inside Bubblewrap or the configured local Docker verifier;
do not add network access or execute candidate-authored tests outside isolation.

For feature-extension failures, plan the distinct case and confirm it reports
`work_types=["feature","new_component","integration"]`:

```bash
php artisan codex:offline-eval \
  --case=scratch_php_feature_extension_hidden --json
```

The only admitted changes are `src/RetryPolicy.php` and the existing
`src/JobRunner.php`. A candidate that creates the class but does not delegate
through `JobRunner`, removes `runId()`, accepts invalid constructor values,
omits the five-minute cap, or only edits the public test must remain failed.
Passing the public examples without the evaluator-owned boundary checks is the
expected adversarial failure, not evidence to weaken the hidden verifier.

The 2026-07-15 `qwen3-coder:30b` low-effort baseline failed the migration case
after `499,821ms`, with eight commands, 20 incompatible patch payloads, and no
required edits. If that pattern repeats, treat it as edit-transport failure:
keep the model unqualified, inspect only the retained bounded workspace, and
prefer a terminal-safe candidate or connector improvement before rerunning.
Do not count the model's claimed completion or exact response as evidence.

If `product_configuration_identity` fails, compare the result's requested and
dispatched profile, model, reasoning effort, and sandbox. A mismatch means the
case must remain failed even if its code verifier passed.

`runtime_model_attested=false` is not an error. It records the current evidence
limit: connector/CLI dispatch metadata cannot prove that a provider did not
substitute another backend model. Do not change it to `true` unless an
independent, authenticated runtime response supplies the actual model identity.

If the repository-guidance quality test fails, remove generic discoverable
repository narration before raising the `4,500`-byte budget. Never remove RTC,
offline inventory, Genea face/photo review, lawful-use, persistence,
schema-isolation, dirty-worktree, or delivery gates just to satisfy the size
limit.

## Agent Trajectory Fixture Evaluation Failures

Run the deterministic inventory first to confirm the deployed surface, then
evaluate with strict failure signaling:

```bash
php artisan codex:offline-pipeline-tools --json --compact
php artisan agent:trajectory-eval --reference=agent-evals/baseline.json \
  --json --compact --fail-on-mismatch
```

- `artifact_path_invalid`: use a `storage/app`-relative path with no leading
  slash or `..` segment.
- `artifact_unreadable_or_too_large`: confirm the file exists, is readable, and
  is no larger than 1 MiB. Symlinks resolving outside `storage/app` are rejected.
- `current_trajectory_unavailable`: the read-only retained-evidence query did
  not complete. Check database availability and use a saved sanitized fixture
  while investigating; the command deliberately suppresses raw exception text.
- `*_invalid_schema`, `*_invalid_trust_boundary`, or
  `*_raw_text_posture_invalid`: rebuild with `agent:trajectory --fixture`; do
  not convert a raw trace by hand or weaken the privacy contract.
- `*_case_tool_hash_invalid`: rebuild the fixture so tool identity uses the
  native `sha256:<64 lowercase hex>` form.
- `order_mismatch=true` with no missing/unexpected cases: the same multiset ran
  in a different order. Confirm the procedure really requires order before
  changing from `strict` to `unordered`.
- Missing cases in `superset`, or unexpected cases in `subset`: the selected
  directional contract is violated. Review the baseline and agent procedure;
  do not invert the mode merely to clear the gate.
- Some repeats pass and others fail: treat the batch as inconsistent. One
  successful run does not override a failed repeat.

Trajectory matching does not replace outcome tests, safety gates, or operator
review. It also must not be worked around with prompt compression,
precompaction, raw trace logging, or model-judged content exposure.

## Execution End Date Omits Runs

Current PLOS treats `date_to` as an inclusive calendar day. Confirm the browser
sends `YYYY-MM-DD`, then request the API directly:

```text
GET /api/executions?date_from=2026-07-14&date_to=2026-07-15
```

- HTTP `422`: correct an invalid date or make `date_to` no earlier than
  `date_from`.
- HTTP `200` with no final-day run: confirm the stored `started_at` and the
  application/database timezone. Do not append `23:59:59` in the client; the
  server already uses an exclusive next-day bound that includes subseconds.
- Runs outside the end day: capture the exact parameters and timezone evidence;
  do not weaken validation or switch back to a bare `<= YYYY-MM-DD` comparison.

## Dynamic MCP Discovery Falls Back to Static

Run the focused fixture and inspect the content-free application log:

```bash
php artisan test tests/Feature/MCP/DynamicDiscoveryTest.php
php artisan codex:offline-pipeline-tools --json --compact
```

- `No tools response`: confirm the executable, entrypoint, stdio lifecycle, and
  timeout without logging tool content.
- `Invalid tools response` or `Invalid tool payload`: fix the server's
  list-shaped result and named tool/schema field types.
- `Invalid` or `Repeated tools cursor`: fix cursor type/progress; do not disable
  cursor validation.
- `Duplicate tool name`: make names unique across every page before enabling
  dynamic discovery.
- `page limit exceeded` or `tool limit exceeded`: inspect an accidental loop or
  unexpectedly large authority surface. Raising limits is a reviewed policy
  change, not a recovery step.

An empty private dynamic result is expected to trigger the unchanged static
catalog at the public caller. Clear the cache only after the server/runtime is
reviewed and corrected. Do not use prompt compression or precompaction to work
around catalog or transport failures; both remain source-enforced off.

## External MCP Runtime Is Not Admitted

Run the content-free status surfaces before changing configuration:

```bash
php artisan ops:health-gate --quick
php artisan codex:offline-pipeline-tools --json --compact
```

- `indirect_launcher` or `module_launcher`: replace `npx`, `uvx`, shell,
  container, or Python `-m` execution with a deployed direct entrypoint.
- `entrypoint_unresolved`: give the configured interpreter an existing local
  file argument.
- `manifest_pin_mismatch`, `lock_pin_mismatch`, or
  `installed_pin_mismatch`: restore the exact dependency through the normal
  manifest/lock install; do not fetch it during invocation.
- `binary_package_mismatch`: point the command at the binary supplied by the
  pinned package rather than an unrelated executable.

Do not bypass the admission service or add static tools to hide a denied
runtime. Direct operator/repository commands need a resolved executable file and
remain content-identity admitted. These errors do not justify enabling prompt
compression or precompaction.

## MCP Tool Shows No Progress Or Stale Progress

Confirm the client sends `_meta.progressToken` with the tool call. Progress is
deliberately absent without that token. A short call may emit only `started`
and `completed`; `running` begins after 15 seconds. The progress number is
elapsed seconds, not a percentage, and `total` is intentionally omitted.

Run the local lifecycle and contract checks:

```bash
cd mcp-servers/plos && npm test
php artisan codex:offline-pipeline-tools --json --compact
php artisan ops:mcp-tool-contract-audit --json --compact --fail-on-violations
```

If a cancelled or timed-out request keeps reporting progress, or a notification
failure changes the tool result, treat it as a server lifecycle defect. Do not
work around it by raising timeouts, fabricating totals, logging tool payloads,
or enabling prompt compression/precompaction. Restart a stale local MCP client
only after confirming the deployed server contract and process health.

## Semantic Chunking Provenance Debt

Run `php artisan rag:chunking-provenance-audit --json --compact`. A nonzero
legacy count means semantic rows predate `_plos_chunking`; it is observation
debt, not evidence that rows are corrupt. Do not backfill guessed settings or
trigger an automatic rechunk/re-embed pass.

If new writes remain uncovered, confirm that ingestion uses `RAGService`
semantic/contextual chunk paths and that metadata JSON is writable. If the same
input unexpectedly has different document fingerprints, compare the effective
chunk options and produced chunk count before investigating non-determinism.
Do not resolve fingerprint differences by enabling prompt compression,
precompaction, or silent content normalization.

## Research History CSV Export

- A selected person returning JSON instead of downloading CSV means the person
  route is stale; both person and tree endpoints must accept `format=csv`.
- A `TypeError` mentioning `JsonResponse` means the controller lost its
  `JsonResponse|Response` contract.
- Broken commas, quotes, or multiline search terms indicate manual CSV string
  assembly has returned. Generated CSV must use `SpreadsheetSafeCsv::encode`.
- A leading apostrophe before `=`, `+`, `-`, `@`, their full-width variants, or
  a whitespace/zero-width-prefixed trigger is expected export neutralization;
  the stored research value is unchanged.
- Do not add a formula-escaping disable flag. For exact machine interchange,
  use the JSON endpoint rather than weakening the human spreadsheet download.

## Offline Coding Result Passes But Cannot Promote

Inspect `results[].trial_count`, `minimum_consistent_trials_for_promotion`, and
`consistency`. Protected cases need three trials; `insufficient_repeat_trials`
is expected after the default single run. Rerun with `--repeat=3`. A failed
trial, differing outcome, formally ineligible trial, or dispatch mismatch keeps
promotion false even when another trial passed. Do not hide variance by
compressing prompts or loosening protected verification.

If `results[].reliability_profile.complete` is false, inspect each dimension's
`status`. This is normally expected for today's repeated protected coding run:

- `insufficient_repeats` means fewer than two runs were available for a
  consistency profile;
- `partially_measured` consistency means canonical sequences or usable
  resource counters were absent, while available metrics remain valid;
- `not_measured` robustness means no explicit prompt, environment, and fault
  condition trials were supplied;
- `not_measured` predictability means the evaluator supplied no bounded
  confidence evidence; and
- `not_measured` safety means the evaluator supplied no constraint-violation
  and harm-severity evidence.

Do not fill missing dimensions from model documentation, hardware estimates,
latency expectations, prose output, or inferred confidence. Do not interpret a
perfect outcome-consistency score as success: all-failing trials are consistent
but formally ineligible. Use protected evaluator-owned evidence, keep safety
outside the aggregate, and leave compression/precompaction off.

For an explicit robustness run:

- an invalid or unprotected condition is a command-definition error; use only
  the three fixed condition names on protected hidden-verifier cases;
- prompt failures mean an authored semantics-preserving variant did not reach
  the same protected end state; do not edit the verifier to fit the response;
- environment failures mean the fixed irrelevant files changed agent behavior
  or were modified outside the allowed path set;
- `transient_fault_observed=false` means the agent did not expose the injected
  failing validation as an independent command; shell constructs that hide the
  nonzero exit are not recovery evidence; and
- `transient_fault_same_action=false` means no successful validation shared the
  failed action's purpose-scoped keyed identity; and
- `transient_fault_recovered=false` means that exact action did not succeed
  within five completed command calls. A different or late validation is not
  recovery evidence.

Failed condition workspaces remain available under the normal offline-eval
diagnostic path. Their failures do not rewrite nominal promotion or the v3
receipt. Do not mark missing conditions successful, infer them from other runs,
or enable prompt compression to improve apparent robustness.

## Agent Handoff Is Blocked By Delegation Depth

First inspect `agent_runtime_policy` and `agent_delegation` in
`php artisan codex:offline-pipeline-tools --json --compact`. The normal default
is root depth `0`, maximum depth `1`, and sidecar maximum depth `0`. A child
agent receiving `delegation depth limit reached` must do the work directly or
return control to its parent; do not reset the depth in tool arguments.

If displayed timeouts or retries differ from expectations, follow the named
`policy_sources` and `precedence` instead of changing environment values first.
The canonical hybrid key is
`system_configs.agents.hybrid_default_timeout_minutes`; the config fallback is
`agents.hybrid_default_timeout_minutes`. A shared agent-thread or global
subagent-model setting is intentionally absent because those controls belong to
queue/delegation policy and table-routed model capabilities.

If a root handoff is rejected, verify the live `handoff_to_agent` registry row
uses `AgentHandoffService::handoffFromAgent` and contains a required
`runtime_context` with `x_context_only=true`. Its required `source_agent_id`
must also have `x_context_only=true` and `x_context_alias=agent_id`; neither
parameter should appear in the model-facing tool description. If the database
row is correct but a worker still advertises `source_agent_id`, restart the
long-running worker so its in-process tool registry is reloaded. Missing trusted
`agent_id` or `session_id`, a source-agent mismatch, or a queued payload that lost
`delegation_depth`/`parent_session_id` is a runtime-integrity defect. Do not
work around it by calling `handoff()` directly, increasing depth, enabling
prompt compression, or allowing the model to provide source identity or runtime
context.

## Agent Handoff Fails, Stays Active, Or Completes Too Early

Inspect `agent_handoff_lifecycle` in
`php artisan codex:offline-pipeline-tools --json --compact`, confirm migration
`2026_07_15_220000_track_agent_handoff_task_lifecycle` is applied, and compare
the handoff's `distributed_task_id` with the corresponding distributed task.

- `Undefined array key "priority"` is the pre-fix asynchronous default-priority
  defect. Deploy the current service code; do not add a caller-only workaround.
- `submitted` means accepted or waiting/retrying. `working` means the worker
  started the correlated child. Both are active, not failures.
- An active handoff with no matching task indicates correlation or retention
  damage. A matching pending task points to queue capacity; a running task
  points to worker/agent diagnosis.
- `completed` is valid only after the child task reports terminal success. A
  late failure must not overwrite an already terminal handoff.

Retain historical failed rows as evidence. Do not bulk convert them to
completed, replay them without reviewing their original purpose, increase the
delegation limit, or enable prompt compression to clear the dashboard.

## Agent Memory Maintenance Is Catching Up Or Degraded

Run `php artisan ops:agent-doctor --json --compact --since=24` and inspect
`memory_maintenance`. `catching_up` means the current backlog exceeds one run
but the enabled oldest-first job last succeeded recently and enough bounded
cron runs remain before the oldest session reaches the table-owned freshness
SLO. Confirm `within_freshness_capacity=true`, a valid nonzero
`runs_available_before_slo`, and `projection_scope=current_backlog_only`.

`degraded` is intentional when the job is running/failed/disabled, the policy
or cron cannot be evaluated, the last success or oldest backlog is stale, or
`freshness_horizon_capacity` is below `backlog_sessions`. Compare the live row's
cron, command limit, `notes.runtime`, last run, and recent run duration. Repair
the failed scheduler or policy evidence; do not widen the SLO, increase limits,
force overlapping work, or suppress `memory_distillation_missing`.

The projection does not predict future arrivals. A later burst can move
`catching_up` back to `degraded`, which is expected. Raw episodes must remain
preserved, and compression/precompaction must remain off.

## Agent Tool Contract Is Unresolved

An `Agent tool contract unresolved` response is a pre-session integrity stop,
not a provider or prompt failure. Inspect the named skill's frontmatter first:
a non-empty top-level `tools` list is authoritative, otherwise the declaration
is the ordered union of `tool_phases`. Then confirm each declared name exists in
the enabled registry, its implementation is available, and the skill contains
all of the tool's `required_permissions`.

Run `php artisan ops:agent-doctor --json --compact --since=24` and
`php artisan agents:skill-audit --format=json --compact`. Reconcile stale skill
references with the migration or registry change that removed the tool; do not
restore dead aliases or broaden the skill to every compatible tool. The stop
must remain before session, cache, tool, or model work. Do not work around it by
loosening permissions, enabling prompt compression/precompaction, or bypassing
offline and operator-confirmation policy.

## Agent Session History Is Missing Or Repeats Messages

Run `php artisan migrate:status` and confirm
`2026_07_15_183000_add_durable_agent_session_messages` is applied. Then inspect
`agent_session_history` in
`php artisan codex:offline-pipeline-tools --json --compact`. A missing table
temporarily leaves the legacy JSON search fallback active; it is not a stable
post-deploy state.

For paging defects, preserve the first response's `history_head_cursor` while
new messages arrive and follow only `next_cursor` for older pages. Cursors are
opaque, HMAC-signed, and bound to one session. Altered cursors or reuse on
another session must fail with `Invalid agent session history cursor`; do not
decode, edit, or replace them with raw database ids.

Seeing only 100 entries from `getMessages()` is expected bounded model context,
not durable retention. Use `getMessageHistoryPage()` or the
`agent_session_search` tool for older history. Do not increase prompt size,
enable compression/precompaction, or copy durable history wholesale into a
prompt to compensate for a retrieval bug.

## Configuration Secret Appears Blank

An empty password input with `Stored value is configured` is expected. PLOS
does not return the stored value to the browser. Leave it untouched and save to
preserve the credential, type a replacement to rotate it, or select `Clear
stored value` for an intentional deletion. Do not replace the UI with a raw
database value or add a reveal endpoint.

If a JSON configuration shows `__PLOS_REDACTED_SECRET__`, keep the sentinel at
that same secret field while changing nonsecret siblings. A
`SECRET_PLACEHOLDER_INVALID` response means the placeholder was moved to a
nonsecret path or no stored value exists there; refetch the row and enter an
actual value for that field. For unexpected `401` responses, verify the web
session and CSRF/session-cookie path. For a stale runtime value after a
successful save, confirm Redis connectivity, then run the focused
`ConfigurationSecretBoundaryTest`; do not enable secret logging or expose a
readback endpoint for diagnosis.

## Read-Only API Token Does Not Work

First inspect the route contracts without displaying any credential:

```bash
php artisan route:list --path=api/oauth/tokens --json
php artisan route:list --path=api/v1/ops/offline-status --json
```

All token lifecycle routes must include `auth:web`. The status route must
include both `auth:api` and
`passport.scope.exact:ops.status.read`. An anonymous, invalid, expired, or
revoked token returns `401`; a valid legacy wildcard, empty-scope, or otherwise
mis-scoped token returns `403`. `NOT ADMITTED` beside an old token in Developer
Tools is intentional. Create a new read-only token rather than editing its
stored scopes or weakening exact-scope middleware.

If creation returns `500`, verify Passport keys and that one active personal-
access client exists. Run `php artisan passport:client --personal` only during
reviewed setup when that client is genuinely absent; do not create duplicate
production clients as a diagnostic shortcut. Revoke and replace any token whose
one-time secret was exposed. Never log or paste the secret while diagnosing the
route. Prompt compression and precompaction are unrelated and must remain off.

## Compute Worker Is Healthy But Not Routable

Read `ComputeRouterService::getStatus()` and distinguish `is_healthy` from
`health_fresh`. A stale or missing `last_health_check` is intentionally
fail-closed even when the last stored health value is true. Run
`healthCheckAll()` once, inspect the per-instance result, and repair the probe
or scheduler if freshness does not recover. Do not extend the freshness window
merely to hide a failed nightly check.

If only a remote worker fails, run the same SSH identity path as the service
account. `Host key verification failed` means the exact key is absent or has
changed. Verify the change out of band and update `known_hosts`; never restore
`StrictHostKeyChecking=no`. Password prompts are also rejected by design.

If a fresh healthy worker is still unroutable, inspect circuit state,
capability/VRAM requirements, and `slots.available`. A partially occupied
multi-slot worker remains eligible but receives a lower load score; a fully
occupied worker is excluded. Prompt compression or precompaction cannot repair
compute health and must remain off.

## Runtime Policy Debt Evidence Is Degraded

Open Operator Evidence and check `Evidence`, `Latest Run`, and `Run Age` on the
Runtime Policy Debt card. `job_missing`, `job_disabled`, or
`pending_first_run` means the daily scheduler proof is absent. Restore the
existing `runtime_policy_leak_audit` row or run its normal compact command once;
do not create a replacement queue or hardcode a dashboard count.

`latest_run_failed` requires inspection and a successful rerun.
`invalid_compact_payload` means the saved output failed the exact read-only,
mutation-disabled, aggregate schema or its status disagreed with its candidate
count. More than 36 hours of age is stale even if the last run succeeded. Use:

```bash
php artisan ops:runtime-policy-leak-audit --json --compact
php artisan ops:operator-evidence --json --compact
```

When the section is `watch`, the evidence is working: review a bounded
`--findings-limit=40` candidate sample and classify or table-own real leaks.
`non_definition_context_references` is retained evidence, not debt that needs a
suppression. Do not expose raw output through the API/UI, flood
`agent_review_queue`, blanket-suppress paths/rules, change the normal scheduled
command to strict failure, or enable compression/precompaction.

## Direct Ollama Chat Stops While the Model Is Still Working

Confirm the deployed chat policy and active heartbeat before increasing any
timeout:

```bash
php artisan ops:llm-active --json --compact
php artisan tinker --execute="dump(app(App\\Services\\SystemConfigService::class)->get('ai_chat.runtime_policy'));"
tail -f storage/logs/laravel.log | grep 'Ollama monitored chat'
```

The normal policy has no transport wall clock. Each received byte resets the
idle watch; `waiting_for_first_chunk`, `receiving`, and
`waiting_for_next_chunk` indicate live monitored work without exposing prompt
or response content. A real idle-window expiry, incomplete NDJSON stream,
worker termination, unavailable host, or missing/malformed policy still fails
closed. Restore the versioned table row or the failed host first. Do not enable
prompt compression/precompaction, shorten evidence, or add an unmonitored
controller timeout to conceal local-model slowness.

## AI Observability Token Totals Are Blank

Confirm the migration is applied and the provider actually reports usage:

```bash
php artisan migrate:status | grep 2026_07_15_235000
php artisan tinker --execute="dump(app(App\\Services\\Ops\\AIObservabilityMetricsService::class)->snapshot(24)['summary']);"
```

Legacy rows and local providers without an exact usage response remain null by
design. Do not infer counts from character length, latency, or tokens/second.
If Codex reports usage but the row remains null, inspect the content-free
`turn.completed` summary and clear the 60-second schema cache before retrying.
Never log the prompt or completion while diagnosing this path.

## Agent Confidence Calibration Is Missing, Invalid, Or Review Required

Run:

```bash
php artisan ops:agent-confidence-calibration --json --compact
php artisan ops:operator-evidence --json
```

`warning` means the policy/support tables are missing, invalid, or disabled;
repair `system_configs.ops.agent_confidence_calibration` and the scheduled job
instead of adding a code default. `insufficient_evidence` means there are too
few mixed manual decisions. `review_required` means aggregate policy checks
failed; it does not mean the agent is wrong, the operator decision is factual
ground truth, or the confidence threshold should change automatically.

For `invalid_compact_payload`, verify the exact v1 schema,
compact/read-only/content-free posture, fixed Genea cohort, numeric metrics,
bounded bins, and absence of raw samples, rows, titles, summaries, details, or
notes. More than 36 hours without valid successful proof is stale. Do not fix
the card by enabling auto-approval, importing raw review content, or adding an
LLM judge. If a just-completed run shows the UTC offset as its age, verify
`APP_TIMEZONE` matches the scheduler/database timestamp convention and deploy
the app-timezone freshness parser. Prompt compression and precompaction remain
off.

## Upstream Documentation Provenance Audit Fails

Run the compact gate, then inspect the fixed failure codes:

```bash
php artisan whiteroom:provenance-audit --json --compact --fail-on-drift
php artisan whiteroom:projects --format=json
```

`documentation_revision_stale` means the contracted table row does not contain
the registry's canonical `source_version`.
`registry_revision_identity_mismatch` means a contracted `git_commit` source
has a missing, malformed, or different duplicate `identifier`; correct both
registry fields from the reviewed official commit rather than choosing one at
runtime. `documentation_row_missing` or
`documentation_row_ambiguous` means the configured row label occurs zero or
multiple times. Missing files, unsafe paths, and incomplete contracts fail with
their own codes. Correct the registry or all named current rows based on the
verified upstream source; do not paste a hash into an unrelated history note to
make the check pass. The command does not verify upstream currency itself, so
use the normal source-drift review before repinning. Prompt compression and
precompaction remain off.

Known-good production evidence from 2026-07-16 is schema v2, four dynamically
inventoried contract projects, 12/12 passing checks, matching deployed service /
inventory / registry / core-document hashes, and a 20/20 current source cohort.
If the inventory list and audit count disagree, verify the deployed config
cache and source hashes; do not restore a hardcoded project list or choose
between mismatched Git pin fields.

### TTS Candidate Is Not Eligible

First separate runtime health from quality evidence:

```bash
php artisan audio:tts --json --probe
php artisan audio:tts-candidate-eval --example --json --compact --fail-on-ineligible
```

An `invalid` result means the artifact contract or provenance failed. Check
the storage-relative path, 1 MiB/24-case/2,000-character bounds, exact
runtime/model/voice hashes and licenses, blinded assessor identity and protocol
hash, all eight capabilities, and unknown fields. `not_eligible` means valid
evidence failed quality, per-case, regression, or measurable-improvement gates.
Inspect only the reported failure codes; compact output intentionally omits
prompt and transcript text. Do not treat `--example` as runtime evidence and
do not install or reroute a candidate to make the check pass.

## External MCP Runtime Reports Unsupported Transport

Inspect the deterministic policy first:

```bash
php artisan codex:offline-pipeline-tools --json --compact
php artisan codex:offline-capabilities --json
```

For `unsupported_transport`, keep an external PLOS MCP server on the supported
stdio process contract (`external_process`, `stdio`, or the legacy omitted
label). For `unsupported_transport_option`, remove `url`, `headers`,
`request_init`, or `requestInit`; PLOS does not have an HTTP/SSE MCP client and
will not pretend those values were applied. Do not move bearer tokens into
process arguments or logs. A remote client needs a separately reviewed origin,
redirect, credential-redaction, and header-forwarding implementation. Prompt
compression and precompaction remain off.

## Research Topic Refinement Is Serial Or Stops Asking Questions

Inspect the deterministic contract and run its focused regression test:

```bash
php artisan codex:offline-pipeline-tools --json --compact
php artisan test tests/Unit/Controllers/Api/ResearchTopicRefinementContractTest.php
```

The inventory must expose `plos.research_topic_refinement.v1`,
`dependency_aware_frontier_batch`, a prompt maximum of three questions, and a
server maximum of two answered clarification rounds. A proposal after the
second answered frontier is expected, as is an immediate proposal when the
idea is already specific or the operator selects **Generate brief now**. The
UI should ask the operator to answer the numbered questions rather than imply
one serial answer.

If deployed behavior differs, verify the controller revision and production
asset build, then inspect only the content-free `refinement` response metadata.
Do not log research ideas, questions, answers, or proposal text. Do not increase
the round limit or enable prompt compression to compensate for a stale asset,
deployment mismatch, or weak model response.

## Research Missions Stay Pending While Scheduler Reports Success

Start with read-only status and the scheduled job's last output:

```bash
php artisan research:run-missions --stats
```

If pending missions exist and `research_run_missions.last_run_output` says
`Stopping early to stay within the scheduled runtime budget` with
`[ITEMS_PROCESSED:0]`, compare the largest pending `time_limit_minutes` with the
job's usable deadline. Usable minutes are `timeout_minutes - 3`; the outer
timeout must exceed the mission budget plus that safety buffer. The baseline
30-minute missions use a 35-minute job floor recorded in
`scheduled_jobs.notes.runtime.minimum_timeout_minutes`.

Do not shorten mission rows, force-run failed missions, or remove the safety
buffer to clear the queue. Restore the table-owned job floor and verify the
runtime metadata. Explicit zero-yield runs are excluded from adaptive timeout
history; if the job falls below its recorded floor, verify the deployed
`ScheduledJobService` and inspect later operator edits to the row.

The historical message `Zombie: marked failed by research-ops health monitor`
means a mission remained active for more than two hours. It does not identify
the original discovery, scrape, or model failure. Diagnose new missions with
content-free phase timing and exception-class telemetry; do not expose stored
queries or errors in scheduler logs. During deployment verification, prove
budget fit without executing a pending mission and allow the normal cadence to
consume work.

## SearXNG Engine Summary Is Empty Or A Query Appears In Logs

Start with the connector contract and focused tests:

```bash
php artisan codex:offline-pipeline-tools --json --compact
php artisan test tests/Unit/Services/SearXNGServicePrivacyAndProvenanceTest.php
```

An empty `engine_summary` can be valid if the installed SearXNG JSON response
does not provide `engine` or `engines`; it is not permission to infer an engine
from an infobox. Inspect the operator-managed SearXNG version and JSON API using
a benign query. Do not log the actual private query while diagnosing it.

If the SearXNG service or MCP adapter logs raw text, verify the deployed
`SensitiveTextTelemetry` call sites and clear cached application code. A null
fingerprint with a nonzero length means `APP_KEY` was unavailable; restore the
key rather than adding plain SHA-256. Exception messages and dropped result
URLs are intentionally absent. Higher-level services may have separate query
logging. On the current build, inspect `sensitive_log_boundary` and verify the
configured channel uses `RedactSensitiveLogContext`; exact/suffix structured
query, prompt, claim, error/exception-detail, and message values should become
`[PRIVATE_TEXT_REDACTED]` unless the call site emits an explicit content-free
receipt. `Throwable` context should retain only type, code, previous types, and
false message/stacktrace flags. `query_count`, `query_chars`, `prompt_tokens`,
fingerprints, failure codes, and counts should remain visible. The v3 source
audit must report the AST engine, zero read/parse gaps, zero direct exception-
detail and placeholder-template sites, and no more than 139 dynamic sites. A
new dynamic site is a gate failure; the existing baseline does not sanitize or
prove arbitrary dynamic free-form messages. Do not enable
prompt compression, precompaction, or alternate routing as a workaround.
If a benign canary produces no line, compare its severity with the configured
channel level before diagnosing redaction; an `info` event is intentionally
dropped when the sink starts at `warning`. Re-run only the same synthetic marker
at an admitted severity, then require the raw marker to be absent and the
sentinel to be present.

If an exception canary still exposes its marker, verify the value is passed in
structured context and that the redaction tap runs before Monolog's formatter.
Do not pass an exception string as the log message. Prefer a stable operation
event plus `['exception' => $e]`; the processor will preserve classification
without message, file, or trace content.

If a common benign query returns HTTP 200 with zero results, inspect
`engine_health`. Nonzero unresponsive engines now make the PLOS search fail
with `unresponsive_engines_no_results`; that is expected and prevents a false
green. Timeouts across all enabled engines are an operator-managed SearXNG or
network health issue, not an empty-search success. Repair engine reachability
or configuration, then reset the circuit only after a successful probe. Do not
compare `unresponsive_engine_count` directly with `failure_event_count`: the
first counts unique engines, while the second can be larger when one engine
reports multiple bounded reason codes.

## PROD1 Reports Runtime Source Membership Drift

Run the same read-only contract locally first:

```bash
git ls-tree -r -z --name-only HEAD -- app routes \
  | scripts/guards/runtime-source-membership.sh \
      --root=. --expected0=- --json --compact --fail-on-drift
```

Local `missing_files` means the working tree is not an exact export of the
commit. Production-only `extra_files` usually means a long-lived checkout
retained repo-absent PHP. Keep the private policy at `warn` while investigating;
use the content-free drift manifest digest to correlate repeated observations.
Do not infer paths from the digest, run `git clean`, add rsync `--delete`, or
remove files just to clear the warning. After a separately reviewed cleanup,
set `PLOS_PROD_RUNTIME_SOURCE_DRIFT_POLICY=fail` and rerun PROD1. Empty,
non-NUL, absolute, escaping, or out-of-scope manifests are contract failures,
not ordinary drift.

## Media Delete Returns 503 Or 500

A 503 from `DELETE /api/media/{uuid}` or its `/purge` variant means PLOS could
not resolve local media storage. Verify `NEXTCLOUD_DATA_PATH`, the configured
library root, and the production mount before retrying. The registry and RAG
state were intentionally left unchanged.

A 500 stating that physical deletion failed means the path resolved but unlink
did not succeed. Check mount writability, file ownership, permissions, and
whether the target is unexpectedly a directory. Do not manually mark the row
deleted while bytes remain: reconciliation can rediscover/reactivate them. If
the response instead says removal completed but registry cleanup failed, the
bytes may already be absent; inspect the asset UUID and lifecycle logs, then
repair the tombstone/cascade state deliberately before another delete.

`physical_missing=true` on success is expected for partial cleanup where bytes
were already absent. It is not evidence that PLOS can Undo a deletion. Current
tombstone restore metadata preserves association intent only; byte recovery
requires a separately implemented managed trash workflow.

## Privacy Review Does Not Submit Or Reject As Expected

Review Hub privacy approval is authorization for a later controlled removal
attempt, not the attempt itself. A successful decision keeps
`removal_requests.status=pending`, clears `requires_review`, records
`fields_to_submit`, and reports `external_submission_performed=false`. The
submission worker is the separate component that later selects pending rows
whose review gate is clear.

Privacy rejection records the operator reason, clears the gate, and moves the
row to terminal `ignored`; it must never remain pending. If behavior differs,
verify the `privacy` registry row has `batch_enabled=0`, null direct approval and
rejection SQL, `PrivacyRequestDetail`, and
`PrivacyRequestReviewDecisionService`. Clear the registry cache after repairing
table state. Do not manually clear `requires_review` or rewrite a status to make
the card disappear: that can either orphan an approved request or make rejected
personal data eligible for external submission.
