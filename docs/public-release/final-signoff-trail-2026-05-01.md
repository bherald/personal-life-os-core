# Public Release Final Signoff Trail - 2026-05-01

Scope: TODO-022 final public-release diligence. This document is a checklist for
the final operator/legal/common-sense review before the first public tag. It
does not replace `docs/public-release-readiness.md` or
`docs/public-github-first-push-checklist.md`.

## Evidence To Attach

- Latest history-free public export manifest from `$HOME/tmp/personal-life-os-core`.
- Latest repeatable smoke from `$HOME/tmp/personal-life-os-core-smoke`.
- First public GitHub Actions `Public Readiness` run.
- `scripts/guards/public-release-audit.sh` output.
- `scripts/audit-licenses.sh` output.
- `docs/public-release/privacy-secret-scan-baseline-2026-04-29.md`.
- `docs/public-release/npm-license-snapshot.md`.
- `docs/public-release/python-license-snapshot-core.md`.
- `docs/public-release/python-license-snapshot-media.md` if media add-ons are
  advertised.
- `THIRD_PARTY.md`, `NOTICE.md`, `docs/native-ml-package-review.md`, and
  `docs/model-runtime-license-map.md`.
- Clean proof VM evidence artifact if media add-on claims are included in the
  release notes.

## Signoff Questions

1. Is the public repo built from the history-free export, not private history?
2. Are all public docs free of private paths, private hostnames, private tokens,
   private people data, and private workflow details?
3. Are GPL/LGPL and optional media/ML dependencies represented as documented
   runtime/operator-installed extras where appropriate?
4. Are the 12 current license warnings classified as accepted, optional,
   fixed, or blocking?
5. Are GPU claims limited to optional/experimental until clean-host GPU proof
   exists?
6. Are media claims limited to the proof VM evidence actually collected?
7. Is the support posture clear: personal/open-source project, best-effort
   issues, security contact, and unsupported optional profiles?
8. Are announcement drafts consistent with the proven quickstart path?

## Current Recommendation

Keep MIT as the project source posture. Keep optional GPL/LGPL-signaled Python
and media packages documented as operator-installed extras until final
dependency/license signoff. For first public push, claim only the proven core,
Docker core, and clean-host media evidence already recorded. Do not advertise
supported GPU behavior yet.

Final signoff owner is the operator/project maintainer, with outside counsel or
a license specialist recommended before company use, packaged binaries/images,
commercial distribution, or broad public adoption.
