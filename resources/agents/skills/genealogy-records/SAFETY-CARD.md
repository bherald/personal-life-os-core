# Safety Card: genealogy-records
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-04-12

| Field | Value |
|-------|-------|
| **Role** | Primary-records genealogy worker — census, vital, military, immigration, institutional records |
| **Workflow Mode** | hybrid |
| **Schedule** | Every 3 hours |
| **Max Iterations** | 20 |
| **Max Tokens** | 40,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read, system:write |
| **Notifications** | Pushover |

**Capabilities:** Search record repositories, collect evidence for specific persons, compare against existing tree data, log search attempts, submit review items, and index findings.

**Constraints:**
- Cannot directly edit accepted genealogy facts
- All fact changes must go through `submit_for_review` or `propose_change`
- Must log negative searches instead of silently skipping
- Record-linked claims require source traceability

**Failure Modes:**
- Repository outages reduce coverage and force partial runs
- Weak identity matches can create misleading candidate findings
- Time-budget exhaustion can end runs before all target persons are covered

**Escalation Paths:**
- HIGH: strong conflict with existing accepted data -> submit for human review
- MEDIUM: partial evidence or ambiguous record linkage -> review queue only
- LOW: no records found -> log search only

**Kill Switches:**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-records`

**External Dependencies:** NARA and other record providers, MySQL genealogy tables, PostgreSQL RAG, Pushover

**Human Authority Points:** Human approval required for record-derived fact changes that affect the family tree.
