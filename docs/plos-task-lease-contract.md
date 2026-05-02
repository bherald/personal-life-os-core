# PLOS Task Lease and Notification Contract

**Created:** 2026-04-17
**Status:** Public contract. Implementation can land incrementally by surface.

## Purpose

PLOS runs long-running work across three separate surfaces — scheduled jobs,
agent sessions, and Horizon queue jobs — each with its own conventions for
"who owns this work right now" and "did it finish." Today, ownership is
implicit: `scheduled_jobs.last_pid` + `running_pids` + a Redis deadline key,
`agent_sessions` + a Redis `agent_session_lock:*` key, and Horizon's own
tracking. There is no shared contract for detecting a leaked slot, recording
a durable outcome, or firing a completion / failure / cancel notification
that survives a process restart.

This contract defines the PLOS-native primitive that unifies those semantics:
**the Task Custody Record (TCR)** — a single row that names an owner, an
expiry, a result envelope, and a notification state. Every long-running task
surface that opts in uses the same acquire / hold / release / expire flow.

## Non-goals

- No scheduler self-tuning. Leases make leaks visible; they do not change
  scheduling decisions.
- No distributed coordination. PLOS is single-host. A custody record is a
  MySQL row plus an advisory Redis hint — not a consensus protocol.
- No replacement of existing columns. `last_pid`, `running_pids`,
  `running_count`, `timeout_minutes`, and `timeout_locked` stay authoritative
  for the scheduler. TCR augments; it does not supplant.
- No Horizon rewrite. Horizon already has durable failure tracking via
  `failed_jobs`. TCR maps onto Horizon only as a read-side observation.
- No changes to the existing zombie detector's thresholds. Expiry uses the
  same `timeout_minutes + 15` slack already in `detectStalledProcesses` and
  `fixStuckJobs`.

## Mapped surfaces

| Surface | Today | TCR role |
| --- | --- | --- |
| `scheduled_jobs` + `scheduled_job_runs` | PID columns, Redis deadline key, zombie detector at T+15 | **First implementation target.** Custody record written at job start, released at completion, expired by recovery sweep. |
| `agent_sessions` via `AgentLoopService` | Redis `agent_session_lock:{session_id}` only; no durable completion beyond row updates | Second implementation target. Custody row bridges the gap between Redis lock (volatile) and `agent_sessions.status` (persisted but not notification-carrying). |
| Horizon queue jobs | Laravel's `failed_jobs` + retry semantics | Read-only mapping. Horizon stays authoritative. TCR surface exposes Horizon state in the same envelope for diagnostics. |

The rest of this doc is written against the scheduled-jobs surface first,
with agent-sessions differences called out under **Sketch — agent_sessions**.

## Lease semantics

A custody record names **one owner** holding **one task** for a **bounded time
window**, and carries the outcome once released.

### Acquire

Caller requests custody by providing:

- `surface` — enum: `scheduled_job`, `agent_session`, `horizon_job`
- `surface_ref` — the surface's primary key (scheduled_jobs.id, session_id, etc.)
- `owner_token` — opaque string that identifies the holder. For scheduled
  jobs: `host:{hostname}:pid:{pid}`. For agent sessions: the agent's session
  lock token. PLOS generates this; callers do not supply arbitrary strings.
- `expires_at` — absolute timestamp. For scheduled jobs this is
  `now() + timeout_minutes + 15m`. For agent sessions: `now() + session TTL`.

Acquire succeeds iff **no unreleased, unexpired custody record exists for
`(surface, surface_ref)`**. This is an idempotent upsert guarded by a unique
index on `(surface, surface_ref, released_at IS NULL)` expressed as a
generated column or a partial unique constraint emulated via a nullable
`active_key`. Implementation detail left open; the invariant is
one-live-owner-per-task.

### Hold

Holder may extend `expires_at` at most once per natural progress event
(phase advance in a hybrid agent run; `extendAlarm` call in the scheduler).
Extension writes a new `expires_at`, never a new row. Extension is bounded
by the skill or job's `max_timeout_minutes` ceiling.

Extension is **monotonic**: the new `expires_at` is
`DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL extendBySeconds SECOND)`.
A `hold()` call with a small `extendBySeconds` that arrives before the
existing lease expires pushes expiry forward from the remaining lease,
never resets it backward. Holders heartbeating early with short
extensions cannot accidentally shorten a long lease they already held.

Holder may write interim `progress_note` strings (short, bounded — 255 char).
These are advisory for operator visibility; they are not part of the final
result envelope.

### Release

Holder marks the record released by writing:

- `released_at` — timestamp
- `outcome` — enum: `success`, `failure`, `cancel`
- `result_envelope` — JSON, bounded to 16KB (truncated with an indicator if
  exceeded). Schema in **Result handoff metadata** below.
- `notification_state` — enum: `pending`, `delivered`, `suppressed`

Release is a single UPDATE. If the row is already released with a matching
`outcome`, the call is a no-op (idempotent — see **Idempotency**).

### Expiry

A custody record is **expired** when `expires_at < now()` AND `released_at
IS NULL`. Expiry is never self-declared by the holder; it is observed by
the recovery sweep. An expired record can still be released normally if the
holder comes back alive — but the recovery sweep may have already claimed
it first.

### Idempotency

- **Acquire:** calling acquire twice with the same `(surface, surface_ref,
  owner_token)` returns the existing active record instead of erroring. A
  second acquire with a different `owner_token` while the first is live
  **fails closed** (returns null / false). Callers must not retry across
  different tokens without first observing release.
- **Release:** releasing an already-released record with the same outcome
  is a no-op. Releasing with a different outcome is an error (a holder
  should not flip `success` to `failure` post-hoc; issue a new task instead).
- **Expire:** the recovery sweep's transition is guarded by a conditional
  UPDATE (`WHERE released_at IS NULL AND expires_at < NOW()`). Two sweeps
  racing on the same record both succeed at the SQL level; the second one
  updates zero rows.

## Stale-slot recovery

### Detection

A recovery sweep reads custody records where `released_at IS NULL AND
expires_at < NOW()`. For each expired record, the sweep checks the owner
token's liveness:

- `scheduled_job` surface: parse `pid:{n}` from token, check
  `/proc/{pid}` (same `isProcessAlive` logic the scheduler uses today).
- `agent_session` surface: check whether the Redis lock key still exists.
- `horizon_job` surface: no liveness probe — Horizon owns this. The sweep
  only annotates; it does not force-release.

### Recovery action

When the owner is confirmed dead (or not probeable):

1. Sweep writes `released_at = NOW()`, `outcome = 'failure'`,
   `result_envelope = { reason: 'expired_no_holder', detected_by: 'custody_sweep' }`,
   `notification_state = 'pending'`.
2. Sweep emits a durable notification row (see **Notification states**).
3. Sweep calls the surface's existing cleanup path — for `scheduled_job`,
   that is `fixStuckJobs` logic mutatis mutandis; it clears
   `running_pids`, decrements `running_count`, flips `last_run_status =
   'timeout'`, and forgets the `scheduler:job:{id}:deadline` Redis key.

TCR does not replace the scheduler's recovery. It **coordinates** it. The
authoritative state mutations still happen where they happen today.

### Cadence

Recovery sweep runs on the same tick as today's scheduler maintenance — once
per minute via the existing scheduler loop, invoked from
`SchedulerRunCommand`. No new cron entry is required
to start; an `ops:custody-sweep` command ships alongside for manual runs
and dry-run diagnostics.

## Result handoff metadata

`result_envelope` JSON shape (advisory — exact keys finalized at implementation):

```
{
  "outcome_detail": "string, one-line human summary",
  "items_processed": int | null,
  "duration_ms": int,
  "exit_signal": "string | null",           // SIGALRM, SIGTERM, normal, etc.
  "error_class": "string | null",           // exception FQCN if failure
  "error_message": "string | null, 512 char max",
  "next_action_hint": "string | null",      // e.g. 'retry_ok', 'needs_review'
  "surface_specific": { ... }               // scheduler: pid, run_id; agent: session_id, phase, review_items
}
```

Durability: the envelope lives in the custody row itself. It is not cache.
A process restart loses nothing. `result_envelope` is the **single source
of truth for downstream consumers** (daily report, diagnostics, review
queue) once release happens.

## Notification states

Every custody record carries a `notification_state` that tracks whether the
outcome has been handed off to the notification layer.

### Completion

`outcome = success`, `notification_state` transitions `pending` →
`delivered` (or `suppressed` if the surface's `notification_mode` is
`silent`). Delivery target: the configured digest channel for digest-mode
jobs; no per-task push unless `notification_mode = high_priority`. This
matches PLOS's priority-based notification model.

### Failure

`outcome = failure`. Same transition rules. Failure notifications always
land in the daily report even when `notification_state = suppressed` for
push delivery.

### Cancel

`outcome = cancel`. Used when an operator or a guardrail aborts a run
before completion. Cancel is a first-class outcome — it is not a failure.
Notifications are digest-only by default.

### Durability

`notification_state = pending` is the durable queue. A notification
publisher (new or existing, TBD at implementation) reads pending rows and
delivers. Process restart leaves `pending` rows intact — the publisher
re-picks them on next tick. Delivered / suppressed states are terminal.

No in-memory queue. No Redis-only state for final outcome. Redis is only
used for the liveness hint (the existing `scheduler:job:{id}:deadline`
key), which is redundant with `expires_at` and may be retired once TCR is
live.

## Operator-facing observables

After implementation, the operator can answer these via artisan commands
or the B8 diagnostics slice:

- Which tasks currently hold an active custody record (who owns what).
- Which tasks expired without being released (the leak feed).
- Which outcomes in the last N minutes are still `notification_state =
  pending` (the delivery lag signal).
- Per-surface release-latency percentile (acquire → release time vs.
  `timeout_minutes`).

## Sketch — scheduled_jobs

Custody record joins to `scheduled_jobs.id` and optionally
`scheduled_job_runs.id`. No existing columns change semantics.

**New column proposed (one, on the TCR table itself, not on
`scheduled_jobs`):** the TCR table is new — `task_custody_records` — and
carries all lease state. **No new column on `scheduled_jobs` is required.**
Justification: the scheduler's existing columns are authoritative for
scheduling decisions; adding lease fields to that row would conflate two
concerns. A sibling table is cleaner and keeps the idempotency guard
(one-live-owner-per-task) on a dedicated index.

Indicative shape (raw SQL sketch only — not a migration):

```
CREATE TABLE task_custody_records (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  surface ENUM('scheduled_job','agent_session','horizon_job') NOT NULL,
  surface_ref VARCHAR(100) NOT NULL,
  owner_token VARCHAR(200) NOT NULL,
  acquired_at TIMESTAMP NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  released_at TIMESTAMP NULL,
  outcome ENUM('success','failure','cancel') NULL,
  result_envelope JSON NULL,
  progress_note VARCHAR(255) NULL,
  notification_state ENUM('pending','delivered','suppressed') NULL,
  created_at TIMESTAMP NOT NULL,
  UNIQUE KEY uniq_active_owner (surface, surface_ref, released_at),
  INDEX idx_sweep (released_at, expires_at),
  INDEX idx_notif (notification_state, released_at)
);
```

The `uniq_active_owner` unique index on `(surface, surface_ref,
released_at)` enforces one-live-owner because MySQL treats NULL as
non-equal in unique keys — so multiple released rows (released_at NOT
NULL) coexist, but at most one active row (released_at NULL) per
`(surface, surface_ref)` pair.

## Sketch — agent_sessions

For the agent surface, `surface_ref` is `agent_sessions.session_id`.
`owner_token` is the Redis lock token already used by `AgentLoopService`
at `agent_session_lock:{session_id}`. Acquire happens right after the
current Redis lock succeeds (line ~252 of `AgentLoopService.php`);
release happens in the existing `finally` block around the agent loop.

**No new column on `agent_sessions`.** The existing `status` enum
(`active`, `paused`, `expired`, `completed`) stays authoritative for
session lifecycle. TCR's `outcome` is about this particular run — a
session can span multiple runs over its 24h TTL.

## Sketch — Horizon integration (note only)

Horizon's `failed_jobs` table already satisfies durable failure tracking.
For TCR, the Horizon surface is **read-only**: a custody record is
written at job dispatch and released when the Horizon supervisor reports
terminal state. No changes to Horizon itself. No failover logic for
Horizon jobs — Laravel's retry primitives handle that. This integration
is explicitly out of scope for the first implementation sprint.

## Open questions

1. **Publisher location.** Is the notification publisher a new scheduled
   job, a hook inside `SchedulerRunCommand`, or an event listener on
   `released_at` transitions? All three are viable. Decision deferred to
   implementation.
2. **Retention.** How long do released custody records live before
   archival? Candidate: 30 days hot, then pruned. Mirrors
   `scheduled_job_runs` retention.
3. **Multi-tree agents.** When `ScheduledJobService::runGenealogyAgentAllTrees`
   fans out to N trees, is that one custody record or N? Proposal: N —
   each per-tree run is a distinct task with a distinct `surface_ref`
   like `agent:genealogy-researcher:tree:{tree_id}`.
4. **Migration gating.** Does the first TCR migration co-ship with its
   own recovery sweep, or ship dormant and get activated a release later?
   Safer is dormant-first; sprint plan will decide.

## What this contract unblocks

- **C1 — compact run-memory slice.** Needs a durable boundary between
  "run in progress" and "run terminal" to know when to compact. TCR
  provides that boundary via `released_at`.
- **C2 — runtime recovery / reassertion.** The recovery sweep defined
  here is the primitive C2 builds on.
- **C3 — bounded fairness primitive.** Fairness requires knowing which
  tasks are holding slots right now. Active custody records are the
  answer.

## What this contract explicitly does not commit to

- Does not commit to removing the existing Redis deadline key. That
  cleanup is a later, separate change with its own test gate.
- Does not commit to changing `last_run_status` semantics. TCR's
  `outcome` is adjacent, not replacement.
- Does not commit to a specific notification transport. The publisher is
  pluggable.
- Does not commit to scheduler autonomy. Leases make leaks diagnosable.
  Any autonomous action on top of that is a separate backlog item.
