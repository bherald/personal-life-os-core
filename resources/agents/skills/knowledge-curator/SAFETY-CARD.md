# Safety Card: knowledge-curator
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | Knowledge Curation Agent |
| **Workflow Mode** | agentic |
| **Schedule** | 6hr |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | rag:read, rag:write, system:read, system:write |
| **Risk Level** | MEDIUM |

**Capabilities:** Maintains RAG pipeline quality, entity dedup, knowledge graph health, document freshness.

**Constraints:**
- RAG write limited to metadata and indexing operations. Cannot delete documents. Entity merge proposals require human review.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%knowledge-curator%'`
- Clear session lock: `DEL agent_session_lock:knowledge-curator`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
