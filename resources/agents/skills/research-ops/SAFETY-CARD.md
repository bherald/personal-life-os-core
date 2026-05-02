# Safety Card: research-ops
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | Research Operations Monitor |
| **Workflow Mode** | agentic |
| **Schedule** | 30min |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, system:write, research:read |
| **Risk Level** | MEDIUM |

**Capabilities:** Monitors research engine health, circuit breakers, topic scheduling, result quality.

**Constraints:**
- Cannot trigger research runs directly. Monitors engine fallback chains and flags degraded sources.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%research-ops%'`
- Clear session lock: `DEL agent_session_lock:research-ops`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
