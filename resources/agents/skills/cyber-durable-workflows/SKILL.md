---
name: cyber-durable-workflows
version: 1.0.0
description: Submit and monitor the qualified synthetic CYBER workflow through its scoped API.
permissions:
  - cyber_jobs:submit
  - cyber_jobs:status
  - cyber_jobs:cancel
  - cyber_jobs:resume
tools:
  - cyber_job_submit
  - cyber_job_status
  - cyber_job_cancel
  - cyber_job_resume
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Use only the registered CYBER task tools and the operator-selected installation. Read the qualified artifact fingerprint. Preserve the same submission UUID when reconnecting. Submission, cancellation and resume require the existing operator approval; do not invent approval arguments. Poll status by its exact handle. Completed checking leaves security findings open for review. Do not replay unknown effects or change a task's artifact, workflow profile or execution scope. Resume only after the root cause is resolved and the operator approves the exact continuation. This skill does not authorize arbitrary websites, shell commands, model activation or customer data.
