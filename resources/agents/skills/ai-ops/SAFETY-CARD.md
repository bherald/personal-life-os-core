# Safety Card: ai-ops
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | AI Operations Monitor |
| **Workflow Mode** | auto |
| **Schedule** | 15min |
| **Max Iterations** | 15 |
| **Max Tokens** | 50000 |
| **Permissions** | system:read, system:write |
| **Risk Level** | MEDIUM |

**Capabilities:** Monitors LLM providers, model updates, circuit breakers, agent health. Auto-heals safe ops.

**Constraints:**
- Cannot modify LLM configs directly. Model updates require human review. Rate limit detection only (no API key management).
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%ai-ops%'`
- Clear session lock: `DEL agent_session_lock:ai-ops`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
