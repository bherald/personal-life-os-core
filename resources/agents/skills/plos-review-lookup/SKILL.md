---
name: plos-review-lookup
version: 1.0.2
description: Look up an exact PLOS Review Desk reference or known alias across review areas, including closed history.
tools:
  - plos_review_lookup
  - typesafe_jev_decide
permissions: []
---

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.


## Use Jev before repeating semantic work

Delegate substantial selection, ranking, triage and evidence checks to the
registered Jev tool early, within this skill's enabled task scopes. Batch
independent questions and use the results without routinely repeating the
analysis. Keep exact lookups and known rules in code; preserve the original
request, sources, contradictions and uncertainty.

On an unavailable tool, skip, timeout, malformed response or provider error,
continue through the existing permitted workflow with the original evidence.
Do not repeat the adapter's bounded retries or keep calling an unavailable tool
in the same task. Escalate uncertain judgments where needed. A skip is not a
completed analysis. Fallback retains all offline, privacy, identity and action
boundaries. Claim savings only from measured usage and quality comparisons.

# Review Desk lookup

Use `plos_review_lookup` when an operator supplies a durable review reference or known alias. Give the exact reference; do not guess from a title or search by private text. Read the returned canonical reference and revision, question, current and proposed state, effect, evidence context, status, history, and receipts. A null result means no exact match. If a field is absent, say it is unavailable rather than inventing it. The reference is immutable; the revision can change. Review Desk display status is `pending`, `followup`, `later`, or `history`; it is not an action authorization.

This tool reads existing Review Desk records only. It cannot approve, reject, mutate, or grant authority. Before any sensitive write, use the existing approval and permission path with the exact current revision; do not act through duplicate status APIs. Face and photo identity decisions remain operator-owned. LAN access follows the installation's configured authentication and network policy. Use only registered TypeSafe/Jev tasks within the data classification, destination and network scope explicitly approved by the installation operator. This skill text grants no private-data disclosure permission. Exclude credentials, authentication material and access tokens; retain original evidence, uncertainty and existing action approvals. If the tool is unavailable, skipped, invalid or timed out, continue the existing permitted route without repeated retries or treating a skip as completed analysis.
