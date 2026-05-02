# Safety Card: genealogy-web
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-04-12

| Field | Value |
|-------|-------|
| **Role** | Community/web genealogy worker — WikiTree, web search, FAN and graph context |
| **Workflow Mode** | hybrid |
| **Schedule** | Every 4 hours at minute 30 |
| **Max Iterations** | 15 |
| **Max Tokens** | 30,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, system:read |
| **Notifications** | Pushover |

**Capabilities:** Search community genealogy sites, web search, local RAG/graph context, and FAN evidence to find indirect research leads.

**Constraints:**
- Community genealogy profiles are not authoritative by themselves
- FAN and graph evidence are supportive, not self-sufficient proof
- Must not auto-promote web/community claims into accepted facts

**Failure Modes:**
- WikiTree/web data may be stale or unsourced
- FAN links can imply association without proving identity
- Search noise can consume budget with low-value hits

**Escalation Paths:**
- HIGH: strong web/community conflict with accepted facts -> review queue
- MEDIUM: plausible lead or FAN-supported hypothesis -> review queue
- LOW: weak or no findings -> log only

**Kill Switches:**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-web`

**External Dependencies:** WikiTree, SearXNG, MCP genealogy search, local graph/RAG systems, Pushover

**Human Authority Points:** Human review required before web/community/FAN findings become accepted genealogy facts.
