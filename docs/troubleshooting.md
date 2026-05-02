# Troubleshooting

Start every diagnosis with the install tier, operating system, exact command
run, and the relevant `setup:doctor` output.

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

Use Node.js 20 or newer. Reinstall and rebuild:

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

After restart, inspect the process list or Horizon UI and confirm the local
supervisors have `max-processes=1`. Keep Redis running; the goal is to reduce
worker pressure, not to hide queue failures.

For a lighter local desktop profile, set:

```bash
HORIZON_LOCAL_THIN=true
```

Then restart Horizon. This keeps Horizon and Redis active but runs one
low-priority local worker across `high`, `default`, `low`, `long-running`,
`workflow`, and `speculative` queues. Use the default local profile again when
testing queue isolation or scheduler throughput.

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

## Offline Or Degraded Status Looks Wrong

Start with the narrow read-only status payload:

```bash
php artisan ops:offline-status --json
php artisan ops:offline-smoke --json
```

The same data is available to authenticated UI/API consumers at
`GET /api/ops/offline-status`. The full Operator Evidence dashboard at
`/operator-evidence` includes this offline/degraded section with the broader
queue, backlog, review, and operational cards.
`ops:offline-smoke --json` adds a manual report-only check of the audit reader,
profile-filtered MCP catalog boundary, and local runtime scorecard; it does not
change profiles or write audit receipts.

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
php artisan plos:agent-trace-tail --json
```

An empty trace tail usually means tracing is disabled, the storage directory is
unavailable, or the trace event was rejected because it contained a forbidden
raw field. Trace failures should not be fixed by broadening profile permissions.
If Operator Evidence reports files over `DEV_AGENT_TRACE_RETENTION_DAYS`, treat
that as an operator cleanup signal only; trace readers do not delete files.
If `/doctor` reports missing dev-assist tools, compare its `tool_readiness`
block with `config/dev_agent.php` and the active MCP/offline profile before
loosening permissions.
If `/doctor` reports no selected local model, check `runtime_scorecard` first;
it gives the local instance count, healthy count, and selected local/routed ids
without exposing prompt or response content.
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
php artisan ops:agent-doctor-history --json --days=7
```

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
