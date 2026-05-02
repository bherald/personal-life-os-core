# Safety Card: system-guardian
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | System Health Guardian |
| **Workflow Mode** | auto |
| **Schedule** | 30min |
| **Max Iterations** | 15 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, system:write, rag:read |
| **Risk Level** | MEDIUM |

**Capabilities:** Infrastructure health monitoring: GPU, disk, Redis, Horizon, Ollama, scheduled jobs, queue depth.

**Constraints:**
- Cannot restart services or modify infrastructure. Write limited to alerts and review submissions. First responder for system issues.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%system-guardian%'`
- Clear session lock: `DEL agent_session_lock:system-guardian`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
