# Genealogy Research Workflow

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.

Load this reference for a full, queue-targeted, or repository-intensive research run. The active
`SKILL.md` remains authoritative for factuality, identity, privacy, face/photo, review, and write gates.

## Assess

1. Call `get_source_metrics`; de-prioritize an unhealthy provider or open circuit.
2. Recall procedures, episodes, and recent searches. Use `agent_session_search` and
   `agent_trajectory_build` only as historical leads, never as genealogical evidence.
3. Use the task tree ID when supplied. Otherwise call `list_trees`; never assume an ID. Orient from
   each `root_person_id`, then inspect landscape, statistics, missing data, hints, and open tasks.
4. Call `get_priority_persons(tree_id, limit=max_persons_per_run)`. Preserve returned rotation order:
   never-worked people, oldest agent-worked people, then priority score. Do not re-sort only by score.
5. Skip `all_hints_deferred` and recently exhausted negative-search targets. Fill the bounded person
   budget when credible targets remain. Queue mode stays on its preselected person.

Priority score already includes bloodline tier, data gaps, staleness, and exhaustion. Tier 1 is direct
ancestry; Tier 2 is siblings/children of direct ancestors; Tier 3 is collateral; Tier 4 is married-in.

## Plan Each Person

1. If relevant media is handwritten, check `htr_status`, then use the configured transcription tool.
2. Call `get_repositories_for_person` before source searches and follow its era/location ranking.
3. Call `surname_phonetic_matches`; search maiden names and supported variants.
4. Build queries from name variant plus lifetime/year window, place, relative/FAN associate,
   occupation, record type, or source chain. Never rely on a surname-only query.
5. Use at least three relevant tools for each person and avoid identical repeated calls. For a broad
   full run, meet the main skill's 10-tool phase mix; narrow tasks need relevance, not artificial calls.

## Source Routing

- `source_search_all`: use anchored queries and circuit-breaker results. Prefer configured tree blank-fact,
  relationship, source, and media gaps over generic coverage.
- `generate_record_hints`: database record candidates; corroborate before proposing.
- `nara_search`, `nara_search_census`: US federal records. Use surname, year, and state; for a proven
  result enumerate objects, download the best original, then copy it into the tree when allowed.
- `newspaper_search`, `newspaper_search_obituaries`: LOC and obituary discovery. Skip eras outside
  collection coverage. Use `newspapers_media_download` only for an identity-matched result and an
  operator-configured private adapter.
- `wikitree_search`: strongest as a sourced lead for colonial US and European work. Retrieve the
  person and bounded ancestors for a credible profile; collaborative assertions remain derivative.
- `internet_archive_search`: books and compiled/local histories, especially early periods.
- `ellis_island_search`: New York immigration with origin and surname variants.
- `freedmens_bureau_search`: 1865-1872 labor, marriage, ration, and hospital records.
- `dar_search`: Revolutionary-era patriot lead; corroborate lineage and service.
- `german_church_records_search`: German-language Protestant/Catholic church discovery.
- `europeana_search`: European digitized collections when configured.
- `mcp_searxng_search`: source-scoped web discovery with name, date, and place anchors.
- `rag_search`: indexed local documents; retain document provenance.
- `ai_research_person`, `ai_research_brick_wall`: bounded synthesis and planning, not source authority.

Fold3, Ancestry, FamilySearch, NEHGS/AmericanAncestors, FindMyPast, and similar provider surfaces must
use the configured operator-owned adapter/capture path. If unavailable, record a specific follow-up:
repository, person, date/place, record target, and artifact needed. Never expose credentials.

## Evidence Gates

- A URL query echo, catalog row, or same-name result is only a lead.
- Before review or `source_add`, require record title/text/object/citation plus an identity bridge:
  lifetime fit, place, spouse, parent, child, sibling, FAN associate, occupation, or source chain.
- Scheduled proof-grade review additionally requires a stable record locator, exact live configured tree current
  value, different proposed value, record excerpt naming the target, explicit name anchor, a second
  corroborating anchor, and confidence at or above the live actionability threshold. Missing proof is
  a deferred research outcome, not review work.
- Prefer two identity anchors for common names, nicknames, and possible duplicates.
- A catalog can justify searching a repository; it cannot establish a fact.
- When a stable original image, PDF, object, OCR/text export, or document is available, validate
  identity and capture it before finalizing the citation. Otherwise record the exact capture blocker.
- Empty results are negative coverage. Log repository, query, date range, geography, and outcome;
  do not fabricate, re-run the same search, or create a weak review item.

## Analyze

For each person with evidence, use `get_person_full`, events, sources, siblings, variants, and coverage.
Build the evidence chain; resolve places; compare source conflicts; inspect duplicates and graph anchors;
use FAN co-occurrences only when explicitly present in records. Run `assess_gps_compliance`. Generate a
GPS proof only after correlation and conflict treatment are clear.
