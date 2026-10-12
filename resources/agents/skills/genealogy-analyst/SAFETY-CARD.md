# Safety Card: genealogy-analyst

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-05-12

| Field | Value |
|-------|-------|
| **Role** | Genealogy evidence analyst — GPS compliance, conflict analysis, proof generation |
| **Workflow Mode** | agentic |
| **Schedule** | Every 8 hours |
| **Max Iterations** | 10 |
| **Max Tokens** | 40,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read, system:write |
| **Notifications** | Pushover |

**Capabilities:** Evaluate evidence chains, assess GPS compliance, detect conflicting sources, generate proof summaries, surface data-quality issues for human review, and save reusable analysis procedures.

**Constraints:**
- Cannot auto-merge duplicates
- Cannot directly rewrite accepted genealogy truth
- Generated proof text must stay tied to cited evidence
- Conflict resolution proposals must remain review-gated
- Local PLOS may use owned private/living FT data internally; export/publish/share workflows own privacy and redaction gates

**Failure Modes:**
- Sparse source coverage produces weak proof outputs
- Conflicting sources may be underdetermined and require human judgment
- Place or duplicate-resolution helpers may return partial results

**Escalation Paths:**
- HIGH: unresolved source conflict on accepted facts -> review queue
- MEDIUM: duplicate suspicion or incomplete proof -> review queue
- LOW: insufficient evidence for conclusion -> log only

**Operator-approved recovery options (review exact scope before execution):**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-analyst`

**External Dependencies:** MySQL genealogy tables, PostgreSQL RAG, local graph search, Pushover

**Human Authority Points:** Human approval required for proof-based fact changes, conflict resolutions, and duplicate handling.
