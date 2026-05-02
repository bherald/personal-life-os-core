# Safety Card: log-analyst
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | Log Analysis Agent |
| **Workflow Mode** | auto |
| **Schedule** | 2hr |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, system:write |
| **Risk Level** | LOW |

**Capabilities:** Scans application logs for errors, patterns, anomalies. Classifies severity and suggests remediation.

**Constraints:**
- Read-only log access. Write limited to posting agent messages and review submissions. Cannot modify system config or restart services.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%log-analyst%'`
- Clear session lock: `DEL agent_session_lock:log-analyst`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
