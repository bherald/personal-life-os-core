# Safety Card: genealogy-assessor
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-05-12

| Field | Value |
|-------|-------|
| **Role** | Genealogy queue coordinator — selects who should be researched next |
| **Workflow Mode** | agentic |
| **Schedule** | Every 4 hours |
| **Max Iterations** | 8 |
| **Max Tokens** | 30,000 |
| **Permissions** | genealogy:read, genealogy:write, system:read |
| **Notifications** | Pushover |

**Capabilities:** Survey tree health, missing-data backlog, recent searches, source coverage, and open tasks. Prioritize persons for follow-up research, create research tasks, and save queueing procedures.

**Constraints:**
- Cannot directly modify person facts, relationships, or sources
- Cannot approve genealogy findings
- Task creation must stay within queue-management scope
- Must prioritize across the tree fairly, not lock onto one line indefinitely
- Local PLOS may use owned private/living FT data internally; export/publish/share workflows own privacy and redaction gates

**Failure Modes:**
- Empty or stale queue metrics produce no-op runs
- Missing coverage metrics reduces prioritization quality
- Tool/DB failures should abort queue mutation and fall back to read-only summary

**Escalation Paths:**
- HIGH: tree-wide queue corruption or impossible prioritization state -> Pushover + agent message
- MEDIUM: missing coverage data or repeated task creation failures -> agent message
- LOW: no candidates or no new work -> log only

**Kill Switches:**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-assessor`

**External Dependencies:** MySQL genealogy tables, queue/task tables, Pushover

**Human Authority Points:** Human still decides whether proposed downstream findings become accepted genealogy truth.
