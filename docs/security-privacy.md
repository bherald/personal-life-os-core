# Security And Privacy

PLOS is local-first software. Public installs should start with local services,
local data, explicit operator review, and no real credentials committed to git.

## Local-First Boundary

The public core is designed to run on hardware the operator controls. Local
databases, Redis, storage, queues, and Ollama-compatible model hosts are the
preferred default.

External AI providers, notification services, and personal connectors are
optional. Keep them behind explicit `.env` configuration, routing policy, and
operator approval.

## Secrets

Never commit:

- `.env` or production env files;
- API keys, OAuth tokens, webhook URLs, passwords, or private keys;
- real Nextcloud, Joplin, Thunderbird, mail, finance, genealogy, or photo
  paths;
- private compose overlays such as `docker-compose.personal.yml`.

Use `.env.example` and `docker-compose.personal.example.yml` as templates only.

## Public Data Rules

Public fixtures must be synthetic, public-domain with documented source, or
generated locally with enough provenance to reproduce them. See
`tests/Fixtures/PROVENANCE.md`.

Do not publish private photos, real family trees, real mail, private notes,
browser profiles, local machine paths, or personal archives.

## Review And Agent Boundaries

Agent output should be reviewable before sensitive actions occur. Completed
runs alone are not evidence of safe autonomy. Review items should carry enough
source, context, and confidence information for an operator to approve, reject,
or ask for clarification.

See:

- `docs/AGENT-SAFETY-CARDS.md`
- `docs/AIService-LLM-Gateway.md`
- `docs/OLLAMA-COMPATIBILITY.md`

## Offline And Degraded Behavior

Offline mode should prefer local providers and block external network
dependencies unless the operator intentionally enables them. Degraded states
should be visible to operators rather than hidden behind silent fallbacks.

## Public Audit

Before publishing an export:

```bash
scripts/guards/public-release-audit.sh
scripts/audit-licenses.sh
```

The audit is a blocker finder for public extraction. It does not replace
review, but any private literal, credential, copied reference-project language,
or fixture provenance miss should be resolved before pushing.

## Incident Handling

If private data or credentials are exposed in a public artifact:

1. remove the artifact or make the repository private if possible;
2. rotate affected credentials immediately;
3. identify the copied files and source path;
4. add a regression guard or fixture provenance check;
5. publish only redacted details in public issue threads.

Report vulnerabilities using `SECURITY.md`. Do not include secrets or private
data in public issues.
