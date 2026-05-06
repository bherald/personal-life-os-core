# Operation

This document covers public, generic operation after installation. It does not
replace private production runbooks.

## Service Lifecycle

Docker installs:

```bash
docker compose ps
docker compose up -d
docker compose logs -f app worker scheduler
docker compose down
docker compose build app
```

Bare-metal installs should run the Laravel app server, queue worker or Horizon,
Redis, scheduler, MySQL/MariaDB, and PostgreSQL/pgvector using the host's
normal service manager.

## Health Checks

Use setup doctor first:

```bash
php artisan setup:doctor --profile=core --json
php artisan setup:doctor --profile=media --skip-services
php artisan setup:doctor --profile=full --only=services,database,python
```

Check these surfaces before trusting scheduled automation:

- application key and Passport keys;
- MySQL/MariaDB and PostgreSQL/pgvector connectivity;
- Redis connectivity;
- writable `storage/` and cache directories;
- queue worker and scheduler status;
- optional browser, Python, media, and local AI assets.

## Queues And Scheduler

PLOS uses queues for long-running and reviewable work. Keep failed jobs visible
and investigate repeated failures before increasing automation.

Public-safe checks include:

```bash
php artisan queue:failed
php artisan schedule:list
php artisan setup:doctor --profile=core
```

Queue placement rules are documented in `docs/queue-placement-policy.md`.
Task lease behavior is documented in `docs/plos-task-lease-contract.md`.

## Review Queues

Sensitive actions should be proposed for review before they mutate private
state or send notifications. Treat completed agent runs as uptime only unless
the output is source-backed, reviewable, and approved by an operator.

Agent safety expectations live in `docs/AGENT-SAFETY-CARDS.md`.

Use the review-backlog report when Agent Doctor reports aged pending reviews or
when planning cleanup work:

```bash
php artisan ops:review-backlog-report --compact
php artisan ops:review-backlog-report --next-target
php artisan ops:review-backlog-report --json --compact
php artisan ops:review-backlog-report --json
php artisan ops:review-backlog-report --markdown
php artisan ops:review-backlog-report --dry-run
```

The report groups pending review rows by age, review type, finding type, agent,
high-priority status, typed-remediation readiness, and aggregate
`genealogy_review_packet` readiness. It is read-only and does not approve,
reject, expire, archive, notify, or mutate review rows. Use `--compact` for
routine operator or MCP checks that only need aggregate counts and cleanup
guidance.

Typed remediation materialization has a separate, dry-run-first operator
handoff. Use it only when the next backlog target is a pending
`genealogy_finding` typed-remediation candidate:

```bash
php artisan ops:review-backlog-report --next-target
php artisan ops:review-backlog-report --json --next-target
php artisan genealogy:materialize-typed-remediation --id=SOURCE_REVIEW_QUEUE_ID --json --compact
php artisan genealogy:materialize-typed-remediation --token=SOURCE_REVIEW_QUEUE_TOKEN --json --compact
```

The first command selects one sanitized review target only. If its
classification is high-priority, check `underlying_classification` and
`underlying_next_action`; typed remediation work should proceed only when that
underlying path is `typed_preview_needed` or the target is otherwise confirmed
as a typed-remediation `genealogy_finding`. Run the materializer in its default
dry-run mode with exactly one source selector, either `--id` or `--token`. The
compact dry run must show the planned action, supported operation types,
operation/guard counts, failed guard names, row-touch count,
`no_canonical_write=true`, and `apply_held=true` without raw source URLs,
tokens, ids, current-state rows, stale hashes, or evidence text. Use non-compact
JSON only for direct operator inspection when the compact proof is insufficient.

Only after an operator reviews that dry-run output should the same selector be
run with `--execute`:

```bash
php artisan genealogy:materialize-typed-remediation --id=SOURCE_REVIEW_QUEUE_ID --execute --json
php artisan genealogy:materialize-typed-remediation --token=SOURCE_REVIEW_QUEUE_TOKEN --execute --json
```

`--execute` may create or reuse a pending `genealogy_review_packet` row for
review. It still must not write canonical genealogy tables, apply repairs,
unlock apply/writeback, bulk approve, reject, or clear the source advisory row.
Treat the materialized packet as review context only until a separate operator
decision path explicitly supports apply.

For already-authored source-backed packet JSON, use the direct packet
materializer. Its default mode validates the file and reports whether it would
create or reuse one pending review packet:

```bash
php artisan genealogy:materialize-review-packet --file=/path/to/packet.json --json --compact
```

Only after reviewing that dry-run output should the same file be run with
`--execute`:

```bash
php artisan genealogy:materialize-review-packet --file=/path/to/packet.json --execute --json
```

This command is limited to `agent_review_queue` packet materialization. It must
not write canonical genealogy tables, enable apply/writeback, or approve packet
decisions.

Use genealogy agent triage before any genealogy research-agent re-enablement
discussion:

```bash
php artisan genealogy:agent-triage --compact
php artisan genealogy:agent-triage --json --compact
php artisan genealogy:agent-triage --json
```

The compact report is read-only. It keeps target counts, per-target enabled
state, sessions/reviews/AWO headline counts, next action, and recommendation
count without dumping full scheduler, episode, nested review, or AWO details.
Its validation proof also exposes gate counts and compact blocking gate ids so
operators can see why future enablement remains blocked without opening the full
payload.

Use compact reviewer-feedback summaries when checking Review Packet UX feedback
signals:

```bash
php artisan genealogy:packet-reason-codes --compact
php artisan genealogy:packet-reason-codes --json --compact
php artisan genealogy:reject-codes --compact
php artisan genealogy:reject-codes --json --compact
php artisan genealogy:review-feedback --compact
php artisan genealogy:review-feedback --json --compact
```

These commands are read-only. Compact output keeps only window, agent scope,
row/agent counts, review/decision count, accepted/rejected proposal counts
where applicable, acceptance rate where applicable, and top action/reason
counts without dumping full daily rollup or per-agent summary arrays.

## RAG And KG Evidence

Use the compact backlog report for routine KG/RAG checks:

```bash
php artisan rag:backlog-report --compact
php artisan rag:backlog-report --json --compact
php artisan rag:backlog-report --json
```

The compact report is read-only. It keeps document count, RAPTOR/sentence/KG
pending counts, KG fresh/stale/entity counts, throughput/ETA, net-burn trend,
and evidence-error count without dumping the full net-burn lane payload.

## Local AI And Offline Mode

Local Ollama-compatible providers are the preferred default for public installs.
External providers should stay opt-in and should respect sensitivity and
offline-mode policy gates.

Use the read-only offline status surface when checking current operator
authority or degraded-mode posture:

```bash
php artisan ops:offline-status
php artisan ops:offline-status --json
php artisan ops:offline-smoke --json
php artisan ops:operator-evidence --json
```

`ops:offline-status` returns only the Operator Evidence offline/degraded
section. The authenticated `GET /api/ops/offline-status` route returns the same
narrow payload for UI consumers without collecting every Operator Evidence
section. The full `/operator-evidence` screen and `GET /api/ops/operator-evidence`
include the same offline/degraded section beside queue, backlog, review, and
other operational evidence.

`ops:offline-smoke --json` is a manual report-only companion. It joins the
offline-status payload, audit summary, profile-filtered MCP catalog boundary,
and local runtime scorecard. It does not switch profiles, execute network
calls, run remediation, or write audit receipts, and it is not scheduled by
default.

Key fields to check:

- `active_profile`: the authority profile, such as `default`,
  `offline_review`, `offline_dev_assist`, `hybrid_review`,
  `hybrid_dev_assist`, or `cloud_escalation_only`;
- `runtime_state`: the derived state, such as `normal`,
  `offline_profile_without_kill_switch`, `offline_mode_enabled`, or
  `hybrid_profile`;
- `offline_mode_active`: whether the cloud-provider kill switch is active;
- `policy_denials_24h` and `mode_changes_24h`: recent policy/audit activity;
- `local_runtime_status`, `local_availability_state`,
  `healthy_local_instances`, and `selected_local_model`: the compact local
  Ollama scorecard from `LLMPoolManagerService`, derived from monitoring rows
  without executing a routing selection;
- `capabilities`: the current profile's allowed tool, MCP trust, path,
  provider, remote-domain, and confirmation classes;
- `recent_audit_events`: sanitized recent receipts without private targets or
  actor detail.

Treat these surfaces as observation tools. They do not switch profiles, enable
providers, run remediation, or change scheduler behavior.

### Offline/Dev-Agent Compact Flow

Use this sequence for the current local/offline dev-agent scorecard pass:

```bash
php artisan ops:agent-doctor --json --compact --since=24
php artisan ops:mcp-health --compact
php artisan plos:agent-trace-tail --limit=20 --since=24 --json
php artisan plos:agent-trace-read trc_example --since=168 --json
php artisan plos:agent-trace-read --event=evt_example --since=168 --json
php artisan offline:dev-assist /doctor --json
```

`ops:agent-doctor --json --compact --since=24` is the first stop. It keeps only
aggregate status and counts: agent totals, active/stalled sessions, pending
reviews, scheduled-output signals, memory evidence, recursion status, compact
trace-readiness, and capped critical/warning agent ids. It does not dump raw
scheduled output, prompts, completions, trace content, full per-agent payloads,
or review-row details.

`ops:mcp-health --compact` checks configured MCP scorecards, local entry/path
readiness, process presence, process matchability, marker counts, enabled versus
disabled missing entries, and disabled external processes that are still
running. It does not start, stop, restart, or dynamically exercise MCP servers,
and it does not print env values, tokens, or raw process lines.

Use `plos:agent-trace-tail` and `plos:agent-trace-read` only after Agent Doctor,
MCP Health, or `offline:dev-assist` reports a trace id or trace-readiness issue.
The trace commands read sanitized append-only NDJSON envelopes under local
storage. They do not grant tool access, execute remediation, delete trace files,
or promote dev-agent autonomy.

`offline:dev-assist` is local opt-in, not part of routine observation. For
status work, prefer `/doctor --json` with the default `read-only` approval mode.
One-shot model requests return `trace_id` and `trace_written`; their trace
envelopes store request `prompt_hash`, response `output_hash`, status, policy,
model role, and classification metadata rather than raw prompts, completions,
stdout/stderr, stack traces, tool parameters, secrets, or diffs. `repo-write`
approval is a separate explicit operator choice and should not be used to clear
scorecard warnings.

Prompt Compressor MCP remains disabled by default and should be enabled only in
trusted local/operator environments:

```bash
cd mcp-servers/prompt-compressor
npm install
npm run build
```

Then set `PROMPT_COMPRESSOR_MCP_ENABLED=true` in the local environment and
confirm visibility with `php artisan ops:mcp-health --compact`. Treat it as
context reduction only: token counting, prompt/diff/file compression, and local
context store/retrieve/list. File reads stay inside configured allowed roots,
`context_store` is a bounded local write, protected reads stay refused unless an
operator deliberately overrides them, and stored context must not contain
secrets, credentials, private release material, or living-person source text.

A warning or critical scorecard is evidence to inspect configuration, backlog,
or local process posture. It is not permission to widen offline profiles, enable
cloud providers, run broader MCP tools, approve review rows, change scheduler
behavior, or promote agent autonomy.

For trend evidence, preview aggregate readiness snapshots before writing rows:

```bash
php artisan ops:agent-doctor-snapshot --dry-run --json
php artisan ops:agent-doctor-history --json --days=7
```

Without `--dry-run`, `ops:agent-doctor-snapshot` appends one aggregate row to
`dev_agent_readiness_snapshots`. The stored row contains only status/count
fields, trace-readiness aggregates, recursion status/count, check ids, and
scheduled output-quality counts. `ops:agent-doctor-history` reads that table
only and does not re-run live diagnostics.

## Agent Output Quality Replay

Use the AWO replay surface when checking whether agent output has enough
operator-reviewed evidence to support later autonomy decisions:

```bash
php artisan awo:replay --window=7d --json --compact
php artisan awo:replay --window=7d --limit=500 --json
php artisan awo:replay --window=7d --limit=500 --markdown
php artisan awo:replay --compare-scheduled --window=7d --limit=500 --json
```

These commands are read-only. They scan `agent_review_queue`, report aggregate
approval-worthy-output counts, and leave `promotion_decisions` empty. They do
not enable `awo.recording_enabled`, approve or reject review rows, promote
agents, or change scheduler behavior.

The summary keeps completed-review metrics separate from scanned row signals.
`hard_fail_count` is the legacy alias for completed-review hard fails, matching
`completed_hard_fail_count`. `scanned_hard_fail_signal_count` includes every
scanned item with a replay hard-fail signal, including pending rows, and
`pending_hard_fail_signal_count` isolates signals that do not yet have a final
operator decision.

For one-at-a-time review backlog cleanup, use
`php artisan ops:review-backlog-report --next-target`. The payload is
observe-only and sanitized. `classification` reflects the ordering reason, so a
high-priority row may show `high_priority_pending_review`; when that happens,
`underlying_classification` and `underlying_next_action` preserve the normal
cleanup path such as typed-preview or source-backed packet work.

Use `--compare-scheduled` after the weekly `awo_replay_weekly_report` has at
least one successful retained run. It compares the latest scheduled Markdown
summary to a current replay and emits stop rules only; mismatches are review
evidence, not an automation trigger.

Treat fewer than 10 completed reviews as insufficient data. Also require at
least 10 completed reviews for an individual agent before using that agent's
AWO rate as promotion evidence. Item-level JSON can include private review
context, so public notes should use aggregate counts only. A pending item with
`hard_fail=true` is review evidence, not a completed-review hard fail, until the
operator decision is finalized.

## Database Telemetry

Use the observe-only DBA telemetry report before considering retention,
partition, cleanup, backup, or Redis remediation work:

```bash
php artisan ops:dba-telemetry-report --json
php artisan ops:dba-telemetry-report --dry-run
```

The default report collects MySQL/MariaDB table sizes, the
`agent_recursion_calls` storage signal, PostgreSQL/pgvector relation health,
and Redis memory/fragmentation counters. It is metadata-only and does not
delete rows, alter tables, optimize/vacuum databases, flush Redis, change
backups, or modify scheduler behavior.

Do not use `--deep` as a routine health check. It may run heavier raw
aggregation over `agent_recursion_calls`; reserve it for an off-peak window
after the default report shows that exact recent growth evidence is needed.

For the approved `agent_recursion_calls` retention cleanup path, use the
bounded dry-run-first command:

```bash
php artisan ops:arc-retention --json
php artisan ops:arc-retention --execute --max-rows=50000 --batch=5000 --sleep-ms=100 --json
```

The command defaults to dry-run, requires `--execute` to delete rows, uses the
`created_at` retention index, avoids count-first cleanup, caps each execution,
and preserves `recursion_effectiveness`. See
`docs/todo-012-arc-retention-cleanup-plan-2026-05-01.md` before running larger
off-peak chunks.

Relevant docs:

- `docs/AIService-LLM-Gateway.md`
- `docs/OLLAMA-COMPATIBILITY.md`
- `docs/security-privacy.md`

## Backups

Back up the databases and any local storage paths you configure. A public core
install generally needs:

- MySQL/MariaDB application database;
- PostgreSQL/pgvector RAG database;
- configured `storage/` contents that are not reproducible;
- private connector volumes if you enabled them locally.

Do not publish backup scripts, snapshots, or personal data in public issues or
fixtures.

## Maintenance Cadence

Before publishing or tagging:

```bash
scripts/guards/public-release-audit.sh
scripts/audit-licenses.sh
php artisan setup:doctor --profile=core
```

Keep schema dumps, fixture provenance, dependency lockfiles, and public docs
aligned with the release you intend to support.
