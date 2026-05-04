# PLOS Public Export Manifest

Generated: 2026-05-04T16:29:05Z
Source commit: 42b0ce22ea108a6c1fc113ac0764a82ee15dc13f
Source tree status: reviewed tracked worktree contents copied from the source tree
Tracked files copied: 1634

This export is a fresh public-candidate tree. It intentionally omits private
repository history, production operations docs, local Claude/MCP control files,
private credentials, generated dependency directories, personal archives,
operator-only stabilization checks, and private deployment paths.

The export helper remains in the public tree so maintainers can reproduce the
allowlist/audit workflow from their own internal source repositories.

First public GitHub push checklist:

```bash
sed -n '1,220p' docs/public-github-first-push-checklist.md
git commit -m "chore: seed public plos core"
git remote add origin <new-public-repo-url>
git push -u origin main
```

After pushing, confirm the GitHub Actions "Public Readiness" workflow passes,
including the "Docker Compose Config" job. Do not add a public remote to the
private source repository.

Suggested verification for maintainers preparing a public export:

```bash
scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"
```

For a shorter local check inside this exported tree, run:

```bash
PUBLIC_AUDIT_LIMIT=120 scripts/guards/public-release-audit.sh
git diff --check --cached
docker compose --env-file .env.example config --quiet
bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/guards/production-fix-commit-message-check.sh
php artisan setup:doctor --profile=core --skip-services --json
php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json
php artisan test tests/Unit/Setup tests/Unit/Services/MetadataWritebackSafetyTest.php tests/Feature/Console/SetupDoctorCommandTest.php tests/Feature/Quality/FixturesProvenanceTest.php tests/Feature/Quality/PublicExportPackagingTest.php tests/Feature/Quality/PublicGithubMonitorScriptTest.php tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php tests/Feature/Quality/RepositoryGovernanceTest.php
```
