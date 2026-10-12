# Safety Card: factcheck-ops

Public installation scope: these are method and tool declarations, not an enabled schedule or grant of authority. Resolve the installation's registered task, tool, data and network policy before use. Reads require the configured scope; writes, result approval/skip, cleanup, sync, resets, external messages and execution require explicit human approval for the exact action. Keep credentials and private evidence local unless the installation operator explicitly approves that data class and destination. Preserve dry-runs, identity decisions, custody and denials. See `docs/public-methods/skills-and-mcp.md` for setup and qualification.
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-03-23

| Field | Value |
|-------|-------|
| **Role** | Fact-Check Operations Monitor |
| **Workflow Mode** | agentic |
| **Schedule** | 6hr |
| **Max Iterations** | 12 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, factcheck:read |
| **Risk Level** | LOW |

**Capabilities:** Monitors claim pipeline, verdict quality, evidence health, source credibility.

**Constraints:**
- Cannot create or modify claims/verdicts directly. Read-only fact-check access. Flags low-confidence verdicts for human review.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Operator-approved recovery options (review exact scope before execution):**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%factcheck-ops%'`
- Clear session lock: `DEL agent_session_lock:factcheck-ops`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
