# Safety Card: workflow-ops
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | Workflow Operations Monitor |
| **Workflow Mode** | agentic |
| **Schedule** | 30min |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, system:write, workflow:read |
| **Risk Level** | MEDIUM |

**Capabilities:** Monitors workflow execution health, node failures, dead letter queue, trigger status.

**Constraints:**
- Cannot modify workflow definitions or trigger workflows. Read-only workflow access. Flags failing nodes for investigation.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%workflow-ops%'`
- Clear session lock: `DEL agent_session_lock:workflow-ops`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
