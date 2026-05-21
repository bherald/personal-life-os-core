# Safety Card: genealogy-newspapers
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-05-12

| Field | Value |
|-------|-------|
| **Role** | Newspaper and obituary genealogy worker |
| **Workflow Mode** | hybrid |
| **Schedule** | Every 6 hours |
| **Max Iterations** | 15 |
| **Max Tokens** | 30,000 |
| **Permissions** | genealogy:read, genealogy:write, rag:read, rag:write, system:read, system:write |
| **Notifications** | Pushover |

**Capabilities:** Search newspapers, obituary sources, web archives, and related search surfaces for biographical details and family-network evidence. Save newspaper-search procedures for future recall.

**Constraints:**
- Newspaper content is secondary evidence unless corroborated
- Must not convert obituary or article language into accepted fact without source-backed review
- Must log search coverage and negative results
- Local PLOS may use owned private/living FT data internally; export/publish/share workflows own privacy and redaction gates

**Failure Modes:**
- Search-source outages reduce evidence depth
- OCR/noisy scans can create false-name matches
- Obituary family mentions can overstate relationship certainty

**Escalation Paths:**
- HIGH: article conflicts with accepted vital data -> review queue
- MEDIUM: plausible obituary or notice with incomplete corroboration -> review queue
- LOW: no results -> log only

**Kill Switches:**
- Disable corresponding scheduled job in `scheduled_jobs`
- Redis lock key: `agent_session_lock:genealogy-newspapers`

**External Dependencies:** Newspaper search providers, Internet Archive, SearXNG, MySQL genealogy tables, Pushover

**Human Authority Points:** Human approval required before newspaper-derived findings alter accepted person data.
