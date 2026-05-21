# Docs Layout

This directory is intentionally curated. On 2026-04-26 the sprint notes,
implementation packets, historical reports, and one-off research files were
compressed into `canonical-docs-archive-2026-04-26.zip`. On 2026-05-21 the
loose planning/scoping docs were pruned again so git history, not live docs,
carries old narrative detail.

## Public-Bound Docs

These docs are intended to remain valid in a public extraction:

- `quickstart.md` — shortest Docker-first first-boot path and setup-doctor profile map
- `public-release-readiness.md` — public extraction blockers and cleanup order
- `public-github-first-push-checklist.md` — first public repository push and CI validation checklist
- `public-install-prerequisites.md` — public install/dependency target inventory
- `operation.md` — generic public operation and maintenance guide
- `troubleshooting.md` — common install, runtime, privacy, and provenance failures
- `roadmap.md` — public-core direction without private TODO details
- `security-privacy.md` — local-first privacy, secrets, audit, and incident guidance
- `clean-room-references.md` — non-derivation rules for reference projects
- `python-constraints-license-snapshot.md` — Python constraints license watch items
- `public-release/privacy-secret-scan-baseline-2026-04-29.md` — sanitized first-push privacy/secret scan baseline
- `public-release/npm-license-snapshot.md` — npm license snapshot summary from installed package manifests
- `public-release/python-license-snapshot-core.md` — core Python license snapshot from installed package metadata
- `public-release/python-license-snapshot-media.md` — media Python license snapshot from clean-host installed package metadata
- `public-release/final-signoff-trail-2026-05-01.md` — compact final dependency/license/governance signoff checklist
- `native-ml-package-review.md` — optional Python/native/ML release posture
- `research-provenance.md` — research citations and referenced-project boundaries
- `personal-connectors.md` — local-first Nextcloud, Joplin, and Thunderbird connector boundary
- `offgrid-genealogy-agent-cli.md` — offline/off-grid command-line genealogy agent guide
- `FACE-RECOGNITION.md` — dlib model setup and face-recognition safety
- `face-metadata-writeback.md` — standards-based face/person metadata writeback contract
- `schema-reference.md` — database schema reference
- `AGENT-SAFETY-CARDS.md` — agent safety/operator rules
- `AIService-LLM-Gateway.md` — LLM gateway/routing reference
- `OLLAMA-COMPATIBILITY.md` — local Ollama compatibility policy
- `architecture.md` — runtime flow and boundary map
- `plos-runtime-architecture.md` — compatibility pointer to `architecture.md`
- `plos-task-lease-contract.md` — task lease and notification contract
- `queue-placement-policy.md` — queue placement rules

Root-level public governance docs include `README.md`, `LICENSE`, `NOTICE.md`,
`SECURITY.md`, `CONTRIBUTING.md`, `THIRD_PARTY.md`, `.gitmessage`, and the
GitHub PR template. Public fixtures are
documented in `tests/Fixtures/PROVENANCE.md`. Research/project provenance that
should be cited by publication drafts lives in `research-provenance.md`.
Run `scripts/audit-licenses.sh` with the public audit before publishing an
export candidate, and run `scripts/guards/dependency-provenance-check.sh` to
confirm dependency inventories still match the public provenance snapshots.

## Private Source Docs

The private source repository keeps operator-only runbooks that the public
extraction must not ship. These include `PROJECT.md`,
`active-priority-list.md`, `PROD-MAINTENANCE.md`,
`plos-runtime-inventory.md`, `rlm-research.md`,
`plos-research-ledger.md`, `genealogy-research-methodology.md`,
`canonical-docs-archive-*.zip`, current private runtime bench notes, and any
operator-side connector runbook.

Keep real Nextcloud bind paths, Joplin notebook paths, Thunderbird mail
profiles, Android sync targets, Pushover credentials, LAN
hostnames, genealogy data, email archives, and local deployment notes in the
private layer. The public-bound `personal-connectors.md` carries only
contracts and placeholders; do not widen it with operator paths.

`scripts/public-export.sh` filters these private docs out of the public
candidate. Do not add them to the allowlist unless they are separately
scrubbed, generalized, and reviewed.

## Publication Drafts

Keep outward-facing writing in `papers-and-newsletters/`. These files were not
folded into the archive because they are publication/article drafts, not
internal sprint notes.

## Adding Docs

Add a new loose doc only when it is expected to remain an active authority. For
short-lived sprint notes, prefer issues, PR notes, or updates to an existing
canonical doc. If a new temporary doc is unavoidable, archive or fold it back
into a canonical doc when the task closes.

Do not keep multiple active queue/roadmap docs that answer "what should we do
next?" differently.

For private work selection, update `active-priority-list.md`. For public-facing
direction, update `roadmap.md`. For external research cadence, update
`plos-research-ledger.md`.
