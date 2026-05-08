# Public GitHub First-Push Checklist

Use this checklist only from a history-free export created by
`scripts/public-export.sh`. Do not add a public remote to the private source
repository.

## Before Push

1. Create a fresh export from the private source tree:

```bash
scripts/public-export.sh --force "$HOME/tmp/personal-life-os-core"
```

2. Run the full local export smoke:

```bash
scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"
```

The smoke includes `scripts/guards/dependency-provenance-check.sh`; run the
guard directly if dependency inventories or lockfiles changed since the last
export.

The smoke proves `setup:doctor --profile=core --skip-services` and the media
`setup:doctor --profile=media --skip-services --only=assets,browser,docker`
slice. GPU and full profile evidence is tag-gate work, not a first-push
requirement.

3. Review the generated manifest in the exported tree:

```bash
cd "$HOME/tmp/personal-life-os-core"
git status --short
sed -n '1,220p' PUBLIC_EXPORT_MANIFEST.md
```

The export should contain only staged public files plus the generated manifest.
If a private path, credential, personal source, or operator-only doc appears,
fix the private source allowlist/guard and re-export.

The first-push docs IA baseline is already concrete in the private source tree:
root `README.md`, `LICENSE`, `NOTICE.md`, `SECURITY.md`, `CONTRIBUTING.md`,
`THIRD_PARTY.md`, `docs/README.md`, `docs/quickstart.md`,
`docs/operation.md`, `docs/troubleshooting.md`, `docs/roadmap.md`,
`docs/security-privacy.md`, `docs/clean-room-references.md`,
`docs/public-install-prerequisites.md`, `docs/public-release-readiness.md`,
`docs/public-github-first-push-checklist.md`, and
`docs/architecture.md` are present and included by `scripts/public-export.sh`.
`docs/plos-runtime-architecture.md` remains as a compatibility pointer for
historical references. This checklist intentionally remains separate from
`docs/public-release-readiness.md` for first-push validation so the release
operator has one direct runbook.

## First Push

Create an empty GitHub repository with no README, license, or starter files.
Then push only from the exported tree:

```bash
git commit -m "chore: seed public plos core"
scripts/guards/public-workflow-push-preflight.sh
git remote add origin <new-public-repo-url>
git push -u origin main
```

If the preflight reports `.github/workflows` changes, use a session-scoped
`GH_TOKEN` or `GITHUB_TOKEN` with `workflow` scope for that shell. The guard
allows the approved transitional persistent `gh` bridge to remain in place, but
workflow-file pushes must be backed by the session token check.

After the push, confirm the `Public Readiness` workflow passes on GitHub:

- `Public Audit`
- `Docker Compose Config`
- `Setup Doctor And Focused Tests`

If the workflow fails, patch the private source repository, re-run the smoke
path, re-export, and push a follow-up commit from the exported tree. Do not
copy fixes directly into the public export unless they are also applied to the
private source of truth.

## Release Tag Gate

Do not tag a public release until these are complete:

- dependency freeze is declared after the final export smoke; do not change
  `composer.lock`, `package-lock.json`, `mcp-server/package-lock.json`, or
  `requirements*.constraints.txt` unless the smoke, license audit, and signoff
  loop restart
- `scripts/guards/public-release-audit.sh`
- `scripts/audit-licenses.sh`
- `scripts/guards/dependency-provenance-check.sh`
- `scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"`
- GitHub `Public Readiness` workflow on the fresh public repository
- every license warning is triaged as fixed, accepted with rationale, optional
  operator-installed, or release-blocking
- privacy/secret evidence is captured from the passing audit guard, generated
  `PUBLIC_EXPORT_MANIFEST.md`, clean exported `git status --short`,
  `docs/public-release/privacy-secret-scan-baseline-2026-04-29.md`, and GitHub
  secret scanning if enabled on the public repository
- governance diligence packet is reviewed: `README.md`, `LICENSE`,
  `SECURITY.md`, `CONTRIBUTING.md`, `THIRD_PARTY.md`, `.gitmessage`,
  `.github/PULL_REQUEST_TEMPLATE.md`,
  `docs/model-runtime-license-map.md`,
  `docs/python-constraints-license-snapshot.md`,
  `docs/native-ml-package-review.md`, and
  `docs/public-release-readiness.md`
- dependency-license signoff
- public-release diligence posture recorded in `docs/public-release-readiness.md`
- clean-host media install evidence plus native/ML package license review; GPU
  remains optional/experimental unless a clean GPU host proof is completed
- post-release support setup is ready: public issue/security intake, maintainer
  response owner, supported install profile, and known unsupported optional
  profiles are documented before the tag is announced
