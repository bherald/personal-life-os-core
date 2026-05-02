# Safety Card: file-curator
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | File Curation Agent |
| **Workflow Mode** | agentic |
| **Schedule** | 4hr |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | file:read, system:read, system:write |
| **Risk Level** | MEDIUM |

**Capabilities:** Monitors file metadata quality, tag consistency, duplicate detection, enrichment pipeline gaps.

**Constraints:**
- Cannot delete files. Write access limited to metadata updates and review submissions. Tag drift detection is advisory.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%file-curator%'`
- Clear session lock: `DEL agent_session_lock:file-curator`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
