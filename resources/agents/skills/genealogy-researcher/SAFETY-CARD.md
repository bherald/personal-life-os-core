# Safety Card: genealogy-researcher
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-05-12

| Field | Value |
|-------|-------|
| **Role** | Expert genealogical researcher — GPS-compliant evidence gathering, hint processing, source analysis, and research documentation |
| **Workflow Mode** | hybrid (framework drives tool phases; LLM analyzes via structured JSON) |
| **Schedule** | 4 AM daily (general); 8 AM (direct ancestors); 2 PM (colonial/FAN); 7 PM (hints) |
| **Max Iterations** | 40 |
| **Max Tokens** | 60,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read, system:write |
| **Notifications** | Pushover |

**Capabilities:** Survey tree health and missing data. Process pending research hints (RecordHintService). Search supported external repositories (NARA, WikiTree, Ellis Island, Freedmens Bureau public sources, configured private Newspapers.com, Internet Archive, and other active sources). Transcribe handwritten documents (TrOCR). Analyze FAN clusters and co-occurrences. Detect source conflicts. Generate GPS-standard proof summaries. Log negative-result searches. Propose relationship/data changes for human review. Index findings in RAG for future retrieval. Save successful and failed genealogy procedures for future expert recall.

**Constraints:**
- Cannot directly modify genealogy data — all changes go through propose_change / propose_relationship → human review queue
- Cannot approve its own proposals (genealogy_finding review type requires human approval)
- Cannot access raw files outside the MASTER genealogy folder
- Cannot run DNA analysis (no kit data loaded — DNA tools reserved for future enablement)
- Research budget capped by timeout_minutes (44 min general job); dynamic per-person cap = floor(timeout × 0.70 / min_per_person)
- FamilySearch, Ancestry, Fold3, NEHGS/AmericanAncestors, FindMyPast, and default MyHeritage automation are manual/browser-only sources; no automated API, OAuth, login, or scraping tools are available
- Newspapers.com automation is private/personal-only and requires explicit local configuration
- Negative results MUST be documented via log_research_search — silent skips prohibited
- Local PLOS may use owned private/living FT data internally; export/publish/share workflows own privacy and redaction gates

**Tool Phases:**

| Phase | Tools | Purpose |
|-------|-------|---------|
| assess (13) | recall_procedures, recall_episodes, list_trees, get_research_landscape, get_recent_searches, get_tree_statistics, get_missing_data_report, get_research_hints, get_open_research_tasks, mcp_genealogy_stats, list_persons, get_search_coverage, get_source_metrics | Survey tree state; select research targets; output next_phase_targets |
| research (27) | get_repositories_for_person, source_search_all, wikitree_*, ellis_island_search, freedmens_bureau_search, dar_search, german_church_records_search, europeana_search, htr_status, transcribe_*, generate_record_hints, generate_tree_hints, newspaper_*, internet_archive_search, nara_*, mcp_searxng_search, mcp_genealogy_search, rag_search, graph_*_search, ai_research_* | Per-person external source searches |
| analyze (21) | get_person, get_person_full, get_person_events, get_person_sources, get_siblings, evidence_build_chain, assess_gps_compliance, surname_phonetic_matches, resolve_place, search_places, source_search, detect_duplicates, fan_*, map_*, detect_source_conflicts, get_source_conflicts, find_graph_duplicates, generate_gps_proof, update_search_coverage | Evidence analysis; GPS compliance; FAN/geographic context |
| report (9) | update_hint_status, create_research_task, log_research_search, submit_for_review, propose_relationship, propose_change, post_agent_message, rag_index, save_procedure | Document findings; propose changes; log searches (including negative results); save reusable procedures |

**Failure Modes:**
- Disabled tools should not appear in the active genealogy skill/tool surface during this period
- Ollama busy → AI research tools fall back to external providers or skip
- genealogy_persons table missing (dev environment) → assess phase returns empty; no research targets selected
- Empty next_phase_targets → soft warning logged; research/analyze/report phases execute with 0 persons (no proposals generated)
- buildReportToolParams returns [] → tool skipped (required params cannot be synthesized); logged as debug
- LLM returns string elements in persons_found array → is_array() guard prevents TypeError
- Record search returns 0 results → MUST call log_research_search + update_hint_status(deferred); never silent skip
- Source conflict detection fails → logged, research continues without conflict data

**Escalation Paths:**
- CRITICAL (tree corruption, data loss risk): Do not proceed; post_agent_message + Pushover alert
- HIGH (GPS violation in existing proof, unreviewable source conflict): submit_for_review (genealogy_finding)
- MEDIUM (new finding, source with evidence): submit_for_review (genealogy_finding) — requires human approval
- LOW (negative result, no new evidence): log_research_search + update_hint_status(deferred); NO review submission
- Healthy run (nothing found, nothing to propose): post_agent_message only; NEVER submit_for_review

**Kill Switches:**
- Disable specific run: `UPDATE scheduled_jobs SET enabled=0 WHERE name='genealogy_agent_research'`
- Redis session lock: `DEL agent_session_lock:genealogy-researcher`
- Disable all 4 genealogy jobs: IDs 82, 83, 84, 96 in scheduled_jobs table
- Max 40 iterations hard stop per run (framework-enforced)

**External Dependencies:** MySQL genealogy_* tables (prod only), PostgreSQL rag_documents, Ollama/external LLMs, NARA API, WikiTree API, Internet Archive, SearXNG (port 8888), Pushover. Manual/browser-only references with no automation: Fold3, FamilySearch, Ancestry, NEHGS/AmericanAncestors, FindMyPast, default MyHeritage. Private/personal-gated: Newspapers.com.

**Human Authority Points:** All data changes require human review. Review type `genealogy_finding` never auto-approved. Proposals require confidence ≥ 0.50. Source additions must include a real URL or record ID — narrative text rejected.
