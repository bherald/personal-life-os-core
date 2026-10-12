# Contributing

PLOS is being prepared for public extraction. Until that extraction is complete,
this repository remains the private operational source of truth.

Public-bound contributions should keep these rules:

- Do not add private hostnames, LAN addresses, usernames, real credentials, or
  absolute operator paths.
- Keep personal-data connectors optional and environment-driven.
- Use GPL/AGPL projects such as Gramps, Gramps Web, webtrees, and PhotoPrism as
  workflow references only unless the public license strategy intentionally
  changes.
- Add or update `tests/Fixtures/PROVENANCE.md` for every public fixture, sample
  media file, GEDCOM, mail fixture, RSS/search seed, or generated test asset.
- Run `scripts/guards/public-release-audit.sh` before proposing public-bound
  changes.
- Run focused Laravel/PHP/JS tests for the changed surface.

## Commit and PR Messages

Use Conventional Commit prefixes such as `fix:`, `perf:`, `docs:`, `test:`,
and `chore:`. Keep subjects imperative and scoped to one change.

When a `fix:` commit changes production behavior, include a useful body with:

- Root cause: what failed, drifted, or was missing.
- Behavior changed: what production will do differently.
- Verification: tests, smoke checks, read-only prod evidence, or log review.
- Deployment/rollback notes when the fix needs migrations, config, queues,
  cron changes, cache clears, or a special rollback path.

The tracked `.gitmessage` file is a local template for this shape. Enable it
with `git config commit.template .gitmessage` if you want Git to prefill the
guidance while writing commits.

GitHub Actions also runs
`scripts/guards/production-fix-commit-message-check.sh` on pushes and pull
requests. Repository rulesets or branch protection should require the
`Repository Governance / Production Fix Commit Messages` check before protected
branches accept direct updates.

The public extraction should start from a clean repository snapshot, not from
this private repository's full history.
