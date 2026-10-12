---
name: cyber-lan-observation
version: 0.1.0
description: Prepare and interpret the configured operator-reviewed target profile.
permissions:
  - cyber_lan:observe
tools:
  - cyber_lan_observe
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Candidate disabled until qualified. Use only the selected reviewed target profile and exact profile hash. Explain the two HEAD-only cases (`web_root` and `cyber_unauthenticated`) before requesting existing CLI confirmation. Never invent approval arguments, hostname, target, path or profile hash. Use the exact reviewed transport: the operator-approved anonymous HTTP profile targets only the configured anonymous HTTP origin; the HTTPS profile pins the reviewed hostname to the configured reviewed target. Local testing does not require HTTPS/TLS. Never alter model/controller transport settings. Preserve the same task/action receipt; no retries, redirects, credentials, arbitrary shell or target changes. Stop after a refusal, timeout, incomplete response or server error; ask the operator to review before any new action. Run each approved case at most once. A completed response is only an observation, not evidence that the site is secure. Record unexpected auth status and missing header observations for operator review; do not auto-fix production. This skill does not authorize port scans, source access, login testing or customer-data collection.
