---
name: cyber-native-job-control
version: 1.0.0
description: Inspect and control an already submitted owned native CYBER job without replay.
permissions:
  - cyber_jobs:status
  - cyber_jobs:cancel
  - cyber_jobs:refresh
  - cyber_jobs:reconcile
tools:
  - cyber_job_status
  - cyber_job_cancel
  - cyber_job_refresh
  - cyber_job_reconcile
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Keep the original task handle. Status reads saved local evidence; it never contacts the remote worker. Explicit refresh contacts only that job's original worker once. Explicit cancel records intent and requests the original owned process to stop; requested does not mean stopped. Reconcile verifies the original result and native process-tree terminal and completes only the held parent receipt. It never resumes a workflow, repeats a tool, closes findings or changes the task's execution settings. Use the existing exact operator confirmation for cancel, refresh and reconcile. An unknown control outcome retains custody; do not retry automatically. Show the admitted deadline and verified terminal evidence when available. This skill grants no arbitrary command, PID, path, host or new execution authority.
