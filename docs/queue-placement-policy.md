# Queue Placement Policy

**Status:** in force
**Owner:** operator + scheduler/ops maintenance lane
**Enforcement:** advisory — `queue:audit-placement` flags drift; moves are operator-approved

## Why this exists

A short operator-facing job on the same Horizon queue as a 30-minute AI job
can be starved for its entire duration. Queue choice is a **stability
boundary**, not a convenience default. This policy names the queues, what
belongs on each, and how drift is surfaced.

## Queue inventory

Horizon configures four supervisor pools (`config/horizon.php`):

| Queue(s) | Supervisor | Pool size | Intended work |
|---|---|---|---|
| `high`, `default`, `low` | `supervisor-1` | 1–10 | Short ops work; `high` = latency-sensitive, `default` = normal, `low` = background |
| `long-running` | `supervisor-long` | 1–2, no max time/jobs | File/AI/RAG/scan/agent jobs that hold a worker > 30s |
| `workflow` | `supervisor-workflow` | 2–8 | Workflow node fan-out/fan-in |
| `speculative` | `supervisor-speculative` | 1–4 | Agent speculative branches |

## Placement rules

A job should live on the first queue where all listed signals match:

1. **`workflow`** — class name contains `Workflow` or `Node`, or content references `WorkflowExecution`
2. **`speculative`** — class name contains `Speculative`
3. **`long-running`** — any of:
   - Class name has a CamelCase token in `{scan, rag, ai, face, thumbnail, agent, pdf, export, import, genealogy, research, broker, discovery, mission, attachment, catalog, autotag}`
   - Declares `public $timeout > 60` (seconds)
   - Is known to hit external HTTP in a loop
4. **`high`** — class name contains `OpsMaintenance`, `DailyDigest`, `SchedulerHeartbeat`, `HealthGate`, or notification-specific markers (latency-sensitive ops/notify paths)
5. Otherwise → **`default`**

## How to audit drift

```bash
php artisan queue:audit-placement                  # human-readable table
php artisan queue:audit-placement --only-drift     # show only non-OK rows
php artisan queue:audit-placement --json           # machine-readable
```

The auditor is pure PHP (`App\Services\Scheduler\QueuePlacementAuditor`) with
no DB or Horizon dependency, so it is safe to run anywhere.

**Severity levels:**
- `ok` — declared queue matches recommended
- `high` — job on `default` should move to `long-running` (starvation risk)
- `medium` — any other declared/recommended mismatch (operator judgment call)

## Known drift as of last review

| Job | Declared | Recommended | Action |
|---|---|---|---|
| `ThumbnailGenerateJob` | `long-running` | `long-running` | moved 2026-04-24 to avoid starving default workers during large scans |
| `CalendarSyncRAGJob` | `long-running` | `long-running` | moved 2026-04-24 (`RAG` token + external HTTP) |
| `ContactsSyncRAGJob` | `long-running` | `long-running` | moved 2026-04-24 (`RAG` token + external HTTP) |
| `OpsMaintenanceJob` | `high` | `high` | operator judgment — declares 30-min timeout but is latency-sensitive; the auditor now treats this documented exception as expected. |

## When to review

- On every prod deploy that touches `app/Jobs/*.php`
- Weekly as part of ops-maintenance review
- Whenever the daily report shows `failed_jobs` clustered on a single queue
- Before raising any supervisor's `maxProcesses` (moves hide starvation, they don't cure it)

## Not in scope

- Moving jobs programmatically — this policy is advisory only
- Overriding Horizon supervisor config — that lives in `config/horizon.php`
- Reclassifying scheduled_jobs rows — those are tracked via `workload_family` +
  `resource_profile` typed runtime columns, not this audit
