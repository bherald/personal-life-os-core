# Safety Card: genealogy-analyst
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-04-12

| Field | Value |
|-------|-------|
| **Role** | Genealogy evidence analyst — GPS compliance, conflict analysis, proof generation |
| **Workflow Mode** | agentic |
| **Schedule** | Every 8 hours |
| **Max Iterations** | 10 |
| **Max Tokens** | 40,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read |
| **Notifications** | Pushover |

**Capabilities:** Evaluate evidence chains, assess GPS compliance, detect conflicting sources, generate proof summaries, and surface data-quality issues for human review.

**Constraints:**
- Cannot auto-merge duplicates
- Cannot directly rewrite accepted genealogy truth
- Generated proof text must stay tied to cited evidence
- Conflict resolution proposals must remain review-gated

**Failure Modes:**
- Sparse source coverage produces weak proof outputs
- Conflicting sources may be underdetermined and require human judgment
- Place or duplicate-resolution helpers may return partial results

**Escalation Paths:**
- HIGH: unresolved source conflict on accepted facts -> review queue
- MEDIUM: duplicate suspicion or incomplete proof -> review queue
- LOW: insufficient evidence for conclusion -> log only

**Kill Switches:**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-analyst`

**External Dependencies:** MySQL genealogy tables, PostgreSQL RAG, local graph search, Pushover

**Human Authority Points:** Human approval required for proof-based fact changes, conflict resolutions, and duplicate handling.
