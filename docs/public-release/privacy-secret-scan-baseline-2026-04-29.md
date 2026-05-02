# Privacy And Secret Scan Baseline

Date: 2026-04-29

Scope: `personal-life-os-core` first public-push prep. This is release evidence,
not a legal warranty. The baseline intentionally avoids repeating private
literal values; the guard owns those exact blocker patterns.

## Surfaces Checked

| Surface | Command Shape | Result |
|---|---|---|
| Private source public-candidate set | `PUBLIC_AUDIT_LIMIT=200 scripts/guards/public-release-audit.sh` | Passed |
| History-free export tree | `scripts/public-export.sh --force "$HOME/tmp/personal-life-os-core-privacy-check"` then run the same guard inside that export | Passed |
| Fixture provenance | Covered by `Tests\Feature\Quality\FixturesProvenanceTest` in public smoke | Passed in smoke |
| Public manifest review | `git status --short` and `PUBLIC_EXPORT_MANIFEST.md` in the export | Staged public files plus generated manifest only |

## Guard Coverage

The public release audit currently blocks these high-confidence classes in
public-bound files:

- tracked private control files and local environment files;
- generated archives, screenshots, front-end bundles, extensions, vendored
  dependency trees, and tracked runtime storage artifacts;
- non-placeholder secret assignments, provider token shapes, private keys,
  certificates, encrypted key material, and credentialed URLs;
- private paths, LAN hosts, usernames, compute labels, database names, and
  historical credential literals;
- operator-specific personal names and genealogy/private-data literals;
- unsupported FamilySearch/Ancestry runtime API surfaces;
- risky copied-provenance language for media/reference projects and dev-agent
  reference projects;
- private fixture tokens under `tests/Fixtures/**`.

## Current Baseline

- Public audit result: pass.
- Full public smoke result: pass.
- License audit result: pass with 12 documented warnings.
- npm audit result: zero vulnerabilities for root, `mcp-server`, and
  `mcp-servers/plos`.
- Remaining first-push evidence: GitHub `Public Readiness` workflow on the
  fresh public repository and any GitHub secret-scanning result enabled there.

## Negative-Test Posture

`Tests\Feature\Quality\PublicExportPackagingTest` asserts that the guard keeps
the privacy/secret blocker groups and that public-release docs reference this
baseline. For manual spot checks, inject a private-looking literal only in a
throwaway public-bound test copy, confirm the guard fails with the matching
blocker group, then discard the copy before committing.
