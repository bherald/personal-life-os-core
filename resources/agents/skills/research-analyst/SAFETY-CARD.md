# Safety Card: research-analyst
**Standard:** MIT 2025 AI Agent Index (arXiv:2602.17753) | **Updated:** 2026-04-10

| Field | Value |
|-------|-------|
| **Role** | Research Analysis Agent |
| **Workflow Mode** | agentic |
| **Schedule** | 6hr |
| **Max Iterations** | 4 |
| **Max Tokens** | 40000 |
| **Permissions** | system:read, system:write, research:read, research:write, rag:read |
| **Risk Level** | MEDIUM |

**Capabilities:** Lean analysis of research findings, approval/skip triage, and coverage-gap detection across research topics.

**Constraints:**
- Cannot modify external sources. Research write limited to result annotations. RAG read for cross-referencing findings.
- All review submissions require human approval
- Session lock prevents concurrent runs (Redis)
- Max iterations enforced by framework (hard stop)

**Escalation Paths:**
- CRITICAL: post_agent_message + Pushover alert; do not auto-remediate
- HIGH: submit_for_review with detailed evidence
- MEDIUM: submit_for_review for human decision
- LOW: post_agent_message only; no review submission for routine status

**Kill Switches:**
- Disable job: `UPDATE scheduled_jobs SET enabled=0 WHERE command LIKE '%research-analyst%'`
- Clear session lock: `DEL agent_session_lock:research-analyst`
- Max iterations hard stop per run (framework-enforced)

**Human Authority Points:** All proposals and findings require human review. No autonomous critical actions.
