# Genealogy Reporting Workflow

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Load this reference before creating a review item, relationship proposal, fact/source proposal, or final
research report. The active `SKILL.md` remains authoritative for safety and operator ownership.

## Close Every Search

1. Call `log_research_search` for every person and repository searched, including result count and
   negative evidence. `task_id` must be a real database ID. Reuse an open task or create one first;
   never use `task_id: 0`.
2. Call `update_search_coverage` with repository type and positive/negative outcome.
3. Resolve every researched hint. Defer a negative result with the sources searched; do not leave it
   pending.
4. Create a research task only when no open task already covers the same work.
5. Save durable findings to RAG and useful tool sequences to procedural memory. Procedure memory stores
   search technique and outcome, not unsupported genealogical facts.

## Review And Proposal Rules

- Negative-only results do not belong in `submit_for_review`; coverage and hint notes are sufficient.
- Submit `genealogy_finding` only for actionable evidence or a real conflict requiring operator action.
- `propose_relationship` requires evidence for the new person and relationship. Include explicit marriage
  date/place, occupation, notes, and evidence sources when supported.
- `propose_change` requires a real record, URL, or citation. For `source_add`, `proposed_value` must be a
  real URL or numeric record ID, never narrative text. Use `notes_append` only for durable context.
- `fact_update` requires a source. Confidence describes evidence strength; it never bypasses human review.
- Face and photo identity stays operator-owned. Never use this workflow to auto-identify or override a
  rejection.

## GPS Proposal Sanity

The server validator is a backstop, not a substitute for self-filtering:

1. Extract source years and require overlap with the person's plausible lifetime window. Do not attach
   cross-century same-name material.
2. Write an evidence summary of at least 20 characters identifying who, what, when, where, and why.
3. Supply at least one real evidence source identifier.
4. Search by name plus lifetime/date and place; never by surname alone.
5. When validation rejects a proposal, use the named gate to correct the strategy. Do not blind-retry.

## Review Payload

When evidence supports it, add:

- `source_classification`: `source_type` (original/derivative/authored/unknown), `information_type`
  (primary/secondary/undetermined/unknown), and `evidence_type` (direct/indirect/negative/unknown).
- `fan_members`: only people explicitly named in the record, with their recorded role.
- `search_coverage`: repositories consulted, queries run, and access/evidence gaps.

Keep the established required proposal fields unchanged. Do not infer optional values merely to populate
the UI.

## Output

```text
FINDING: [brief description]
TREE: [tree_id] - [tree_name]
PERSON: [person_id] - [full_name]
CONFIDENCE: [0.0-1.0]
EVIDENCE TYPE: [direct|indirect|negative]
SOURCES: [citations]
CONFLICTS: [unresolved or none]
RECOMMENDATION: [confirmed|probable|possible|needs_review|rejected]
```

End with finding counts by severity, trend, and the next bounded action.
