# Agent Safety Cards
**Personal Life OS (PLOS) public-core safety baseline**
**Updated:** 2026-04-27 | **Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753)

Formal safety documentation for PLOS agents. Each card documents capabilities, constraints, failure modes, escalation paths, and kill switches per MIT-style safety-card practice.

**Framework-Level Safeguards (apply to ALL agents):**
- **Session Lock:** Redis lock per agent, 10min TTL — prevents concurrent runs
- **Nesting Depth:** Max 5 levels of agent-to-agent delegation
- **Blocked Operations:** `system_command`, `shell_exec`, `process_kill`, `env_modify`, `credential_access`
- **Confirmation Required:** `file_delete`, `file_overwrite`, `database_drop`, `database_truncate`, `email_send_bulk`, `workflow_delete`, `user_delete`
- **Dangerous Paths Blocked:** `/etc/*`, `/sys/*`, `/proc/*`, `/dev/*`, `/boot/*`, `/root/*`, `~/.ssh/*`, `.env`, `.htaccess`, `/.git/*`, credentials/password/secret patterns
- **Dangerous Commands Blocked:** `rm -rf /`, `dd if=`, `mkfs`, `fdisk`, `chmod 777`, `sudo`, pipe-to-shell patterns
- **Tool Risk Levels:** `read` (no side effects) → `write` (audited modification) → `destructive` (confirmation required) → `blocked` (hard deny)
- **Tool Phase Gating:** Agents see only tools for their current phase (assess→act→report), preventing premature action
- **Permission Enforcement:** Tools filtered by agent's declared permissions before LLM sees them
- **Procedural Memory:** Failed sequences stored as negative examples, preventing repeat failures
- **Provider Privacy:** Agents with email/health/finance permissions auto-filter to `sensitive_safe=true` LLM providers only
- **Privacy Routing Audit:** `php artisan ops:audit-privacy-routing --strict --json` checks enabled sensitive tool permissions against currently reachable provider classes

---

## ai-ops

| Field | Value |
|-------|-------|
| **Role** | AI service capacity, pipeline throughput, workload balancing |
| **Workflow Mode** | auto (adaptive — selects agentic/hybrid/deterministic per run) |
| **Default Mode** | agentic |
| **Schedule** | Every 15 minutes |
| **Max Iterations** | 15 |
| **Max Tokens** | 50,000 |
| **Permissions** | system:read, system:write |
| **Notifications** | configured notification |

**Capabilities:** Monitor GPU status, pipeline throughput, enrichment job configs, processing rates. Fix stalled jobs, adjust job configs. Request speculative execution. Discover tool compositions. Handoff tasks to peer agents.

**Constraints:**
- Cannot modify LLM provider configs or API keys
- Cannot restart system services or Horizon directly
- Job config adjustments limited to batch sizes and scheduling, not service code
- Speculative execution gated by GPU utilization (<90%) and Ollama lock availability

**Tool Phases:**
- assess (8 tools): pipeline_status, ai_capacity, gpu_status, enrichment_job_configs, processing_rates, ai_health_stats, ai_system_load, system_health_check
- act (8 tools): stalled_jobs, fix_stalled_job, adjust_job_config, agent_health_check, discover_compositions, composition_stats, request_speculative, speculative_stats
- report (9 tools): post_agent_message, submit_for_review, propose_tool, pending_tool_proposals, handoff_to_agent, route_task, get_handoff_stats, recall_procedures, save_procedure, procedure_stats

**Failure Modes:**
- GPU locked by whisper/ollama → cannot assess AI capacity → degrades to queue-only monitoring
- Ollama unresponsive → stalled job fixes may fail → escalate via configured notification
- Redis unavailable → session lock fails → run aborts with "session busy" error

**Escalation Paths:**
- CRITICAL (service outage): configured notification immediate alert + submit_for_review
- HIGH (stalled jobs, GPU contention): Autonomous fix attempt → configured notification if fix fails
- MEDIUM (degraded throughput): Log + agent message for next cycle
- LOW (normal variation): Log only

**Kill Switches:**
- Disable in `scheduled_jobs` table (`enabled=0`)
- Redis: `DEL agent_session_lock:ai-ops`
- Max 15 iterations hard stop per run

**Handoff Capability:** Can delegate to workflow-ops, system-guardian. Can receive from file-ops, workflow-ops.

**External Dependencies:** Ollama API (localhost:11434), Redis, MySQL, Horizon queue system

---

## system-guardian

| Field | Value |
|-------|-------|
| **Role** | Infrastructure health monitoring — services, AI, workflows, alerts |
| **Workflow Mode** | auto (adaptive) |
| **Default Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 15 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, rag:read |
| **Notifications** | configured notification |

**Capabilities:** System health checks and trends, AI health stats, queue metrics, active alerts, workflow health, RSS feed health, RAG stats, code quality checks. Can run alert checks, request speculative execution, propose new tools.

**Constraints:**
- Cannot restart services, modify configs, or touch infrastructure directly
- Cannot modify RAG data (read-only RAG access)
- Health checks are observational — remediation requires human or peer agent
- SearXNG search limited to diagnostics context

**Tool Phases:**
- health_check (6 tools): system_health_check, system_health_trend, ai_health_stats, ai_system_load, queue_metrics, alerts_get_active
- diagnostics (14 tools): system_health_snapshot, system_unhealthy_snapshots, alerts_run_checks, alerts_statistics, workflow_health_summary, workflow_failing, rss_health_summary, rss_feeds_needing_attention, rag_stats, code_quality_check, mcp_searxng_search, request_speculative, speculative_stats
- report (9 tools): submit_for_review, get_pending_reviews, post_agent_message, get_agent_messages, propose_tool, pending_tool_proposals, handoff_to_agent, route_task, recall_procedures, save_procedure

**Failure Modes:**
- MySQL/Redis down → health checks return errors → configured notification alert with raw error
- Ollama unresponsive → AI health reports degraded → escalate to ai-ops via handoff
- All external services down → limited to queue/alert monitoring only

**Escalation Paths:**
- CRITICAL (database/Redis/Nginx down): Immediate configured notification + submit_for_review
- HIGH (workflow failures, RSS feed health): Handoff to workflow-ops or research-ops
- MEDIUM (degraded performance): Agent message for trending
- LOW (recovered issues): Log only

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:system-guardian`
- Max 15 iterations hard stop

**Handoff Capability:** Can delegate to research-ops (engine failures), email-ops (bounce spikes), workflow-ops (workflow issues). Primary infrastructure sentinel.

**External Dependencies:** MySQL, PostgreSQL, Redis, Ollama, Nginx, Horizon, SearXNG (port 8888)

---

## knowledge-curator

| Field | Value |
|-------|-------|
| **Role** | Knowledge base quality — RAG indexing, RAPTOR hierarchies, knowledge graph, content quality |
| **Workflow Mode** | agentic |
| **Schedule** | Every 6 hours |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | rag:read, rag:write, system:read, system:write |
| **Notifications** | configured notification |

**Capabilities:** RAG stats/evaluation, RAPTOR hierarchy building, content extraction, knowledge graph stats/quality metrics, graph community reports, procedural memory consolidation, skill performance analysis and optimization proposals, tool composition discovery.

**Constraints:**
- `rag_delete_documents` is destructive — requires confirmation
- Cannot modify source documents, only index/re-index
- Skill optimization proposals require human approval via review queue
- RAPTOR builds are compute-intensive — limited to 50 docs/run
- Knowledge graph builds limited to 500 docs/run

**Tool Phases:**
- assess (13 tools): rag_stats, rag_eval_stats, rag_eval_history, raptor_get_pending, content_extract_status, rss_health_summary, rss_feeds_needing_attention, procedure_stats, mcp_web_search, mcp_searxng_news, graph_stats, graph_community_stats, graph_quality_metrics
- maintain (29 tools): rag_search, rag_deep_search, rag_index, rag_delete_documents, raptor_build, raptor_get_hierarchy, content_extract, mcp_web_search, mcp_searxng_news, consolidate_procedures, recall_procedures, save_procedure, analyze_skill_performance, propose_skill_changes, optimization_stats, pending_skill_proposals, discover_compositions, propose_composition, composition_stats, pending_compositions, graph_local_search, graph_global_search, graph_drift_search, graph_entity_search, graph_community_details, graph_quality_metrics, graph_quality_stats, graph_extraction_status, graph_trigger_build
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- PostgreSQL (pgvector) unavailable → all RAG/graph operations fail → configured notification alert
- Ollama unavailable → RAPTOR/graph extraction stalls → queued for next cycle
- Tika down → content extraction fails → PhpOffice fallback for docx/xlsx only
- Embedding model changes → vector space mismatch → requires full re-index (manual)

**Escalation Paths:**
- CRITICAL (RAG search broken, graph corruption): Immediate configured notification
- HIGH (stale indexes, extraction failures): Autonomous re-index attempt → configured notification if persistent
- MEDIUM (quality metric regression): Submit for review with analysis
- LOW (normal churn): Log metrics for trending

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:knowledge-curator`
- Max 12 iterations hard stop

**Data Sensitivity:** RAG corpus contains personal documents. Provider filtering ensures `sensitive_safe=true` models only for any content analysis.

**External Dependencies:** PostgreSQL (pgvector), Ollama (embeddings + LLM), Tika (port 9998), SearXNG, Redis

---

## genealogy-researcher

| Field | Value |
|-------|-------|
| **Role** | Expert genealogical researcher following GPS methodology |
| **Workflow Mode** | hybrid (framework-driven phases, LLM analyzes between) |
| **Schedule** | Daily at 4 AM |
| **Max Iterations** | 40 |
| **Max Tokens** | 60,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read, system:write |
| **Notifications** | configured notification |

**Capabilities:** Full genealogical research cycle — assess tree gaps, search supported record sources (newspapers, NARA, Internet Archive, WikiTree, SearXNG), analyze evidence chains, propose family connections, propose fact updates/events/sources on existing persons (`propose_change`). Per-person review items are submitted individually, and every `genealogy_finding` requires human review. Review expiry: 7 days with revive capability.

**Constraints:**
- Auto-approve removed for `genealogy_finding` — all findings require human review regardless of confidence
- Confidence score is still recorded and displayed to the reviewer; it may inform urgency, but it does not gate approval
- Cannot delete persons, families, or sources from genealogy database
- Cannot modify existing verified records
- NARA downloads consume API quota — rate-limited
- FamilySearch, Ancestry, Fold3, NEHGS/AmericanAncestors, FindMyPast, and default MyHeritage automation are manual/browser-only sources; no automated API, OAuth, login, or scraping tools are available
- Newspapers.com automation is private/personal-only and requires explicit local configuration
- Hybrid mode: framework controls phase transitions, not LLM

**Tool Phases:**
- assess (6 tools): get_tree_statistics, get_missing_data_report, get_research_hints, get_open_research_tasks, mcp_genealogy_stats, list_persons
- research (17 tools): generate_record_hints, generate_tree_hints, newspaper_search, newspaper_search_obituaries, internet_archive_search, nara_search, nara_get_objects, nara_download_best, nara_copy_to_tree, mcp_searxng_search, mcp_genealogy_search, rag_search, graph_local_search, graph_global_search, ai_research_person, ai_research_brick_wall
- analyze (25 tools): get_person, get_person_events, get_person_sources, evidence_build_chain, + additional analysis tools
- report (8 tools): submit_for_review, propose_relationship, propose_change, post_agent_message, update_hint_status, create_research_task, log_research_search, rag_index

**Failure Modes:**
- Manual-only source unavailable in browser → continue with NARA/newspapers/WikiTree/SearXNG and log negative evidence
- NARA API down → cannot download federal records → continues with other sources
- Ollama unavailable → hybrid phase analysis fails → run aborts, retries next day
- Phase validation failure → max 2 retries per phase → abort with partial results logged

**Escalation Paths:**
- CRITICAL (data corruption, wrong person matched): Immediate configured notification + submit_for_review (never auto-approve)
- HIGH (conflicting evidence): Submit for human GPS review with evidence chain
- MEDIUM (low-confidence findings 0.3-0.7): Queue for research-analyst review
- LOW (no new findings): Log, skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:genealogy-researcher`
- Max 40 iterations hard stop (highest of all agents — complex multi-phase research)

**Data Sensitivity:** Genealogy data contains PII (names, dates, locations of living and deceased persons). All LLM calls routed through `sensitive_safe=true` providers. Reports reference persons by ID only.

**External Dependencies:** NARA API v2, Internet Archive, SearXNG for free/public sites, configured private Newspapers.com adapter only when enabled, PostgreSQL (pgvector), Ollama

---

## email-ops

| Field | Value |
|-------|-------|
| **Role** | Email system health — Thunderbird MCP, drafts, bounces, follow-ups, rate limits |
| **Workflow Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, email:read |
| **Notifications** | None |

**Capabilities:** Email service status, analytics, bounce stats, rate limits, draft queue, follow-up tracking, urgent email detection, sentiment analysis, sender risk assessment.

**Constraints:**
- **Read-only email access** — cannot send, delete, or modify emails directly
- No email:write permission — limited to monitoring and escalation
- `email_process_reminders` limited to 1 autonomous action per run
- No personal email content in reports — reference by subject/sender only
- Cannot modify Thunderbird settings or MCP server config

**Tool Phases:**
- assess (9 tools): email_service_status, email_analytics_dashboard, email_bounce_stats, email_rate_limit_stats, email_draft_queue_stats, email_followup_stats, email_urgent_emails, email_sentiment_stats, get_agent_messages
- act (8 tools): email_pending_drafts, email_overdue_followups, email_process_reminders, email_pending_retries, email_high_risk_senders, email_unsubscribeable_senders, email_search, email_scheduled_upcoming
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Thunderbird MCP (port 8766) unavailable → all email operations fail → circuit breaker opens
- Circuit breaker open → email monitoring paused → system-guardian detects via health check
- Rate limit exceeded → retry scheduling kicks in → no data loss

**Escalation Paths:**
- CRITICAL (MCP down, bounce spike >50%): Post agent message for system-guardian
- HIGH (draft queue backup, overdue follow-ups): Submit for human review
- MEDIUM (sender risk detected): Log + next-cycle check
- LOW (normal stats): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:email-ops`
- Max 12 iterations hard stop

**Data Sensitivity:** HIGH — email content is personal. Provider filtering enforces `sensitive_safe=true`. No email body text in reports or agent messages.

**External Dependencies:** Thunderbird MCP (port 8766), MySQL, Redis

---

## workflow-ops

| Field | Value |
|-------|-------|
| **Role** | Workflow execution pipeline health — success rates, jobs, webhooks, node performance |
| **Workflow Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, workflow:read |
| **Notifications** | None |

**Capabilities:** Workflow health summary, failing workflows, compensation stats, job stats, webhook stats, error patterns, slow nodes. Can fix stuck jobs, refresh diagnostics, and resume executions within current workflow controls.

**Constraints:**
- Cannot create, delete, or modify workflow definitions
- Workflow resumption requires human review via submit_for_review
- Cannot modify workflow node code or configurations

**Tool Phases:**
- assess (9 tools): workflow_health_summary, workflow_failing_workflows, workflow_metrics_dashboard, workflow_compensation_stats, workflow_job_stats, workflow_webhook_stats, workflow_error_patterns, workflow_slow_nodes, get_agent_messages
- act (5 tools): workflow_analyze, workflow_fix_stuck_jobs, workflow_refresh_diagnostics, workflow_resume_execution, workflow_execution_history
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- MySQL down → all workflow queries fail → run aborts
- Horizon down → jobs not processing → detectable via queue_metrics
- Legacy DLQ references should be treated as historical only; current workflow failures surface through job/error diagnostics instead.

**Escalation Paths:**
- CRITICAL (all workflows failing or widespread stuck jobs): Post agent message + submit_for_review
- HIGH (specific workflow failure, stuck jobs): Autonomous fix → escalate if persistent
- MEDIUM (slow nodes, webhook delays): Log for trending
- LOW (normal variation): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:workflow-ops`
- Max 12 iterations hard stop

**Handoff Capability:** Can delegate to ai-ops (LLM node failures), system-guardian (infrastructure issues).

**External Dependencies:** MySQL, Redis, Horizon

---

## file-ops

| Field | Value |
|-------|-------|
| **Role** | File registry health — enrichment pipelines, thumbnails, duplicates, faces, quarantine, EXIF, RAG |
| **Workflow Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, file:read |
| **Notifications** | None |

**Capabilities:** File registry stats, maintenance stats, AI tag stats, thumbnail/phash/face stats, quarantine stats, EXIF writeback stats, RAG index stats, GPU contention status. Can verify batches, detect removed files, cleanup orphaned, scan suspicious, manage quarantine, review face clusters, sync RAG.

**Constraints:**
- `file_cleanup_orphaned` is destructive — requires confirmation
- No file:write permission — cannot modify file content directly
- Quarantine operations submit to review queue, not autonomous deletion
- GPU contention check prevents running face/AI operations during high load
- Cannot modify enrichment pipeline configurations

**Tool Phases:**
- assess (11 tools): file_registry_stats, file_maintenance_stats, file_ai_tag_stats, file_thumbnail_stats, file_phash_stats, file_face_stats, file_quarantine_stats, file_exif_writeback_stats, file_rag_index_stats, file_gpu_contention_status, get_agent_messages
- act (10 tools): file_verify_batch, file_detect_removed, file_cleanup_orphaned, file_scan_suspicious, file_quarantine_pending, file_thumbnail_cleanup, file_duplicates_stats, file_visual_duplicates, file_face_clusters_review, file_rag_sync
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Nextcloud unavailable → file verification fails → retry next cycle
- GPU locked → face/AI enrichment stats only (no processing) → degrades gracefully
- PostgreSQL down → RAG sync fails → other file operations continue on MySQL

**Escalation Paths:**
- CRITICAL (data integrity failure, mass quarantine): Submit for human review
- HIGH (enrichment pipeline stalled, orphaned files detected): Autonomous cleanup attempt → review if large batch
- MEDIUM (thumbnail gaps, duplicate candidates): Log for file-curator
- LOW (normal stats): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:file-ops`
- Max 12 iterations hard stop

**Handoff Capability:** Can delegate to ai-ops (enrichment backlog).

**External Dependencies:** Nextcloud (WebDAV), MySQL, PostgreSQL, Redis, Ollama (face detection), Tika

---

## file-curator

| Field | Value |
|-------|-------|
| **Role** | File curation — AI tagging quality, categorization, duplicate resolution |
| **Workflow Mode** | agentic |
| **Schedule** | Every 4 hours |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | file:read, system:read, system:write |
| **Notifications** | configured notification |

**Capabilities:** File registry stats, AI tag stats, uncategorized file detection, tag quality reports, folder distribution analysis, recent ingestions, duplicate detection. Can suggest categories, review AI tags, check tag consistency, recommend duplicate actions.

**Constraints:**
- Read-only curation — suggestions submitted to review queue, not applied directly
- No file:write permission — cannot modify tags or categories autonomously
- Duplicate recommendations require human approval
- Cannot access file content, only metadata and tags

**Tool Phases:**
- assess (8 tools): file_registry_stats, file_ai_tag_stats, file_uncategorized_files, file_tag_quality_report, file_folder_distribution, file_recent_ingestions, file_duplicates_pending, get_agent_messages
- curate (4 tools): file_suggest_categories, file_review_ai_tags, file_tag_consistency_check, file_duplicates_recommend
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Ollama unavailable → AI tag suggestions fail → curation limited to rule-based checks
- Large uncategorized backlog → iteration limit reached before completion → continues next cycle

**Escalation Paths:**
- HIGH (tag quality regression, mass miscategorization): configured notification + submit_for_review
- MEDIUM (uncategorized backlog growing): Agent message for trending
- LOW (normal curation): Submit suggestions to review queue

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:file-curator`
- Max 12 iterations hard stop

**External Dependencies:** MySQL, Redis, Ollama (AI tagging)

---

## research-ops

| Field | Value |
|-------|-------|
| **Role** | Research pipeline health — engine fallback, circuit breakers, topic scheduling, source credibility, dedup |
| **Workflow Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, research:read |
| **Notifications** | None |

**Capabilities:** Engine status, circuit breaker monitoring, topic stats, dedup stats, result quality, source credibility, cache stats, archive stats. Can reset circuit breakers, enable/disable engines, run topics, discover sources, manage failed results.

**Constraints:**
- `research_run_topic` limited to 2 per run (API quota protection)
- Cannot modify research topic definitions or priorities
- Circuit breaker resets are autonomous but logged
- Engine enable/disable affects all downstream consumers

**Tool Phases:**
- assess (10 tools): research_engine_status, research_circuit_breaker_status, research_topic_stats, research_dedup_stats, research_result_quality, research_source_credibility, research_cache_stats, research_archive_stats, research_update_engine_health, get_agent_messages
- act (8 tools): research_reset_circuit_breaker, research_disable_engine, research_enable_engine, research_stale_topics, research_failed_results, research_run_topic, research_archive_sources, research_discover_sources
- report (6 tools): submit_for_review, post_agent_message, get_pending_reviews, handoff_to_agent, route_task, recall_procedures, save_procedure, procedure_stats

**Failure Modes:**
- All search engines down → circuit breakers open → research halted → configured notification via system-guardian
- NewsAPI/GNews quota exceeded → fallback to SearXNG/Wikipedia → degraded but functional
- PostgreSQL down → research results inaccessible → run aborts

**Escalation Paths:**
- CRITICAL (all engines down, complete research halt): Handoff to knowledge-curator (indexing impact)
- HIGH (multiple circuit breakers open): Autonomous reset attempt → escalate if persistent
- MEDIUM (stale topics, quality degradation): Agent message
- LOW (normal engine cycling): Log only

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:research-ops`
- Max 12 iterations hard stop

**Handoff Capability:** Can delegate to knowledge-curator (indexing failures).

**External Dependencies:** NewsAPI, GNews, Wikipedia, SearXNG (port 8888), PostgreSQL, Redis

---

## research-analyst

| Field | Value |
|-------|-------|
| **Role** | Research quality analysis — result review, knowledge gaps, coverage assessment |
| **Workflow Mode** | agentic |
| **Schedule** | Every 6 hours |
| **Max Iterations** | 15 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, research:read, research:write, rag:read |
| **Notifications** | configured notification |

**Capabilities:** Topic coverage analysis, pending result review, trend detection, result quality assessment, source credibility analysis, knowledge graph search, NARA/Internet Archive search. Can approve/skip results, run topics, discover new sources.

**Constraints:**
- Auto-approve: quality score clearly above threshold only
- Auto-skip: quality score clearly below threshold only
- Ambiguous scores (0.3-0.7): submit for human review
- `research_run_topic` limited to 2 per run
- Cannot modify topic definitions or scoring algorithms
- NARA downloads consume federal API quota

**Tool Phases:**
- assess (6 tools): research_topic_coverage, research_pending_results, research_trends, research_result_quality, research_source_credibility, get_agent_messages
- analyze (9 tools): research_result_detail, research_knowledge_search, research_dedup_stats, nara_search, nara_get_objects, nara_download_best, internet_archive_search, graph_local_search, graph_global_search
- act (4 tools): research_approve_result, research_skip_result, research_run_topic, research_discover_sources
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Ollama unavailable → quality assessment degrades → falls back to rule-based scoring
- NARA/Internet Archive down → reduced source analysis → continues with RAG/graph sources
- PostgreSQL down → all research queries fail → run aborts

**Escalation Paths:**
- HIGH (quality regression across topics, credibility concerns): configured notification + submit_for_review
- MEDIUM (coverage gaps, pending result backlog): Agent message
- LOW (normal analysis): Approve/skip autonomously

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:research-analyst`
- Max 15 iterations hard stop

**External Dependencies:** NARA API v2, Internet Archive, PostgreSQL (pgvector), Ollama, Redis

---

## factcheck-ops

| Field | Value |
|-------|-------|
| **Role** | Fact-check pipeline health — claim decomposition, evidence retrieval, NLI ranking, verdicts |
| **Workflow Mode** | agentic |
| **Schedule** | Every 6 hours |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, factcheck:read |
| **Notifications** | None |

**Capabilities:** Pipeline stats, claim quality assessment, evidence health, verdict distribution, source credibility overview, contradiction queue monitoring, review backlog tracking. Can rerun failed claims, flag low-confidence verdicts, refresh stale sources, search NARA/Internet Archive, query knowledge graph.

**Constraints:**
- Read-only fact-check access — cannot modify verdicts or evidence scores
- Cannot add/remove claims from pipeline
- `factcheck_rerun_failed_claims` limited to 3 per run (compute cost)
- NARA/Internet Archive searches for evidence gathering only, not autonomous conclusions

**Tool Phases:**
- assess (8 tools): factcheck_pipeline_stats, factcheck_claim_quality, factcheck_evidence_health, factcheck_verdict_distribution, factcheck_source_credibility_overview, factcheck_contradiction_queue, factcheck_review_backlog, get_agent_messages
- act (9 tools): factcheck_rerun_failed_claims, factcheck_flag_low_confidence_verdicts, factcheck_refresh_stale_sources, nara_search, nara_get_objects, nara_download_best, internet_archive_search, graph_local_search, graph_global_search
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Ollama unavailable → decomposition/NLI/verdict stages stall → pipeline halted
- Evidence retrieval engines down → incomplete evidence → lower confidence verdicts
- PostgreSQL down → all fact-check data inaccessible → run aborts

**Escalation Paths:**
- CRITICAL (pipeline completely stalled): Submit for review
- HIGH (low-confidence verdict spike, evidence gaps): Flag verdicts + submit_for_review
- MEDIUM (stale sources, queue growth): Agent message
- LOW (normal distribution): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:factcheck-ops`
- Max 12 iterations hard stop

**External Dependencies:** PostgreSQL (pgvector), Ollama, NARA API v2, Internet Archive, SearXNG

---

## data-removal-ops

| Field | Value |
|-------|-------|
| **Role** | Data removal pipeline health — broker health, removal tracking, relisting detection, proof archival |
| **Workflow Mode** | agentic |
| **Schedule** | Every 4 hours |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, privacy:read |
| **Notifications** | None |

**Capabilities:** Removal pipeline stats, broker health, request status, effectiveness metrics, relisting detection, proof coverage, review queue monitoring. Can trigger broker health checks, flag stale requests, flag relistings.

**Constraints:**
- **Never autonomously submits removal requests** — monitoring only
- **Never triggers scans** — observation and alerting only
- Read-only privacy access — cannot modify removal records
- Broker health checks are passive (check status, don't interact)
- No personal data (names, emails, addresses, profile URLs) in any reports

**Tool Phases:**
- assess (8 tools): removal_pipeline_stats, removal_broker_health, removal_request_status, removal_effectiveness_metrics, removal_relisting_detection, removal_proof_coverage, removal_review_queue, get_agent_messages
- act (3 tools): removal_trigger_broker_health_check, removal_flag_stale_requests, removal_flag_relistings
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- Browser automation unavailable → broker health checks fail → status unknown
- Proof storage full → archival fails → flagged for human attention
- Broker site changes → detection patterns break → requires manual rule update

**Escalation Paths:**
- CRITICAL (relisting detected — privacy breach): Immediate submit_for_review
- HIGH (broker health degraded, stale requests): Flag + submit_for_review
- MEDIUM (proof gaps, effectiveness drop): Agent message
- LOW (normal monitoring): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:data-removal-ops`
- Max 12 iterations hard stop

**Data Sensitivity:** HIGHEST — this domain directly handles PII removal. Zero tolerance for data leakage in reports.

**External Dependencies:** MySQL, Redis, Firefox extension (browser automation)

---

## youtube-ops

| Field | Value |
|-------|-------|
| **Role** | Watch Later pipeline health — transcripts, Joplin sync, key points, RAG indexing |
| **Workflow Mode** | agentic |
| **Schedule** | Every 30 minutes |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write, youtube:read |
| **Notifications** | None |

**Capabilities:** Watch Later health, transcript stats, Joplin sync status, RAG index status, recent run history. Can check transcript quality, verify Joplin integrity, retry failed videos, cleanup stale transcripts.

**Constraints:**
- Read-only YouTube access — cannot modify playlists or subscriptions
- `youtube_retry_failed_videos` requires human review (consumes API quota)
- `youtube_cleanup_stale_transcripts` requires human review (data deletion)
- No video content or personal viewing data in reports — reference by ID/title only

**Tool Phases:**
- assess (6 tools): youtube_watchlater_health, youtube_transcript_stats, youtube_joplin_sync_status, youtube_rag_index_status, youtube_recent_runs, get_agent_messages
- act (4 tools): youtube_transcript_quality_check, youtube_joplin_integrity_check, youtube_retry_failed_videos, youtube_cleanup_stale_transcripts
- report (3 tools): submit_for_review, post_agent_message, get_pending_reviews

**Failure Modes:**
- YouTube API rate limited → transcript fallback chain activates (5 methods)
- Joplin sync unavailable → notes queue locally → sync on next available cycle
- All transcript methods fail → video flagged for manual review

**Escalation Paths:**
- HIGH (pipeline stalled, sync failures): Submit for review
- MEDIUM (transcript quality issues, stale data): Agent message
- LOW (normal stats): Skip report

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:youtube-ops`
- Max 12 iterations hard stop

**External Dependencies:** YouTube API, Joplin (port 41184), MySQL, PostgreSQL (RAG), Ollama

---

## log-analyst

| Field | Value |
|-------|-------|
| **Role** | Production log analysis — error parsing, clustering, classification, bug detection |
| **Workflow Mode** | auto (adaptive) |
| **Default Mode** | agentic |
| **Schedule** | Every 2 hours (offset :15) |
| **Max Iterations** | 12 |
| **Max Tokens** | 40,000 |
| **Permissions** | system:read, system:write |
| **Notifications** | configured notification |

**Capabilities:** Log file scanning, error parsing, signature clustering, error timeline analysis, cross-log correlation, baseline comparison. Can save analysis snapshots, handoff to peer agents.

**Constraints:**
- Read-only log access — cannot modify or rotate log files
- Cannot restart services or fix errors directly — detection and reporting only
- Snapshot storage is append-only (historical record)
- Cannot access application databases directly — only log file content

**Tool Phases:**
- scan (3 tools): log_scan_files, log_parse_errors, log_cluster_signatures
- analyze (3 tools): log_error_timeline, log_correlate_across, log_compare_baseline
- report (10 tools): log_save_snapshot, submit_for_review, post_agent_message, get_agent_messages, handoff_to_agent, route_task, recall_procedures, save_procedure, procedure_stats

**Failure Modes:**
- Log files rotated mid-scan → partial results → retries next cycle
- Disk full → log writes fail → detected by file size anomaly
- New error pattern unrecognized → falls back to raw signature clustering

**Escalation Paths:**
- CRITICAL (new error spike, cascading failure pattern): configured notification + handoff to system-guardian
- HIGH (error rate above baseline): Submit for review with correlation analysis
- MEDIUM (new error signatures): Save snapshot + agent message
- LOW (normal baseline): Log only

**Kill Switches:**
- Disable in `scheduled_jobs` table
- Redis: `DEL agent_session_lock:log-analyst`
- Max 12 iterations hard stop

**Handoff Capability:** Can delegate to system-guardian (infrastructure issues), ai-ops (LLM errors).

**External Dependencies:** Log files (storage/logs/), MySQL, Redis

---

## Revision History

| Date | Change |
|------|--------|
| 2026-02-28 | Initial creation — 14 agent safety cards per MIT 2025 AI Agent Index |

---

*Per MIT 2025 AI Agent Index (arXiv:2602.17753): "87% of deployed agents lack safety documentation." This document ensures PLOS is in the 13%.*
