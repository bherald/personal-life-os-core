#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"

usage() {
    cat <<'USAGE'
Usage: scripts/public-export.sh [options] <destination>

Seed a fresh public PLOS working tree from reviewed, tracked files only.

Options:
  --dry-run      Print the allowlisted file set and do not copy files.
  --force        Replace the destination if it already exists.
  --no-verify    Skip the lightweight public export checks after copying.
  -h, --help     Show this help text.

The destination must be outside the private repository. This script copies the
current tracked worktree contents, initializes a new git repository in the
destination, stages the export, and runs the public audit guard unless disabled.
USAGE
}

dry_run=false
force=false
verify=true
destination="${PLOS_PUBLIC_EXPORT_DIR:-}"

while (($#)); do
    case "$1" in
        --dry-run)
            dry_run=true
            ;;
        --force)
            force=true
            ;;
        --no-verify)
            verify=false
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        -*)
            printf 'Unknown option: %s\n\n' "$1" >&2
            usage >&2
            exit 2
            ;;
        *)
            if [[ -n "$destination" ]]; then
                printf 'Only one destination may be provided.\n\n' >&2
                usage >&2
                exit 2
            fi
            destination="$1"
            ;;
    esac
    shift
done

if [[ -z "$destination" ]]; then
    usage >&2
    exit 2
fi

DEST="$(realpath -m "$destination")"

case "$DEST" in
    /|"$ROOT"|"$ROOT"/*)
        printf 'Refusing to export into %s. Choose a fresh directory outside %s.\n' "$DEST" "$ROOT" >&2
        exit 2
        ;;
esac

includes=(
    .dockerignore
    .editorconfig
    .env.example
    .env.testing.example
    .gitattributes
    .github
    .gitignore
    .gitmessage
    AGENTS.md
    CONTRIBUTING.md
    LICENSE
    NOTICE.md
    README.md
    SECURITY.md
    THIRD_PARTY.md
    app
    artisan
    bootstrap
    composer.json
    composer.lock
    config
    database
    docker
    docker-compose.yml
    docker-compose.personal.example.yml
    docs/AGENT-SAFETY-CARDS.md
    docs/AIService-LLM-Gateway.md
    docs/clean-room-references.md
    docs/FACE-RECOGNITION.md
    docs/face-metadata-writeback.md
    docs/model-runtime-license-map.md
    docs/native-ml-package-review.md
    docs/OLLAMA-COMPATIBILITY.md
    docs/operation.md
    docs/personal-connectors.md
    docs/public-release/privacy-secret-scan-baseline-2026-04-29.md
    docs/public-release/npm-license-snapshot.json
    docs/public-release/npm-license-snapshot.md
    docs/public-release/python-license-snapshot-core.json
    docs/public-release/python-license-snapshot-core.md
    docs/public-release/python-license-snapshot-media.json
    docs/public-release/python-license-snapshot-media.md
    docs/public-release/final-signoff-trail-2026-05-01.md
    docs/public-github-first-push-checklist.md
    docs/python-constraints-license-snapshot.md
    docs/quickstart.md
    docs/README.md
    docs/roadmap.md
    docs/security-privacy.md
    docs/architecture.md
    docs/plos-runtime-architecture.md
    docs/plos-task-lease-contract.md
    docs/public-install-prerequisites.md
    docs/public-release-readiness.md
    docs/queue-placement-policy.md
    docs/research-provenance.md
    docs/schema-reference.md
    docs/troubleshooting.md
    mcp-server/.env.example
    mcp-server/README.md
    mcp-server/package-lock.json
    mcp-server/package.json
    mcp-server/src
    mcp-server/tsconfig.json
    mcp-servers/plos/.gitignore
    mcp-servers/plos/.env.example
    mcp-servers/plos/README.md
    mcp-servers/plos/package-lock.json
    mcp-servers/plos/package.json
    mcp-servers/plos/src
    mcp-servers/plos/tsconfig.json
    package-lock.json
    package.json
    phpstan-baseline.neon
    phpstan.neon
    phpunit.xml
    postcss.config.js
    public/.htaccess
    public/index.php
    requirements-core.txt
    requirements-core.constraints.txt
    requirements-gpu.constraints.txt
    requirements-gpu.txt
    requirements-media.constraints.txt
    requirements-media.txt
    resources
    routes
    scripts/browser-server
    scripts/community_detection.py
    scripts/embeddings
    scripts/face_clusterer.py
    scripts/face_detector.py
    scripts/face_detector_batch.py
    scripts/guards
    scripts/htr_transcribe.py
    scripts/install-tika.sh
    scripts/nlp_extract.py
    scripts/audit-licenses.sh
    scripts/public-export.sh
    scripts/public-smoke.sh
    scripts/snapshot-npm-licenses.sh
    scripts/snapshot-python-licenses.sh
    scripts/splade_encode.py
    storage/app/.gitignore
    storage/app/private/.gitignore
    storage/app/public/.gitignore
    storage/framework/.gitignore
    storage/framework/cache/.gitignore
    storage/framework/cache/data/.gitignore
    storage/framework/sessions/.gitignore
    storage/framework/testing/.gitignore
    storage/framework/views/.gitignore
    storage/logs/.gitignore
    tailwind.config.js
    tests/Feature/Console/SetupDoctorCommandTest.php
    tests/Feature/Console/AgentMemoryStatsCommandTest.php
    tests/Feature/Console/AwoReplayCommandTest.php
    tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php
    tests/Feature/Console/OpsMcpHealthCommandTest.php
    tests/Feature/Console/OpsReviewBacklogReportCommandTest.php
    tests/Feature/Quality/FixturesProvenanceTest.php
    tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php
    tests/Feature/Quality/PublicExportPackagingTest.php
    tests/Feature/Quality/PublicGithubMonitorScriptTest.php
    tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php
    tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php
    tests/Feature/Quality/RepositoryGovernanceTest.php
    tests/Fixtures
    tests/Support/PreservesSchemaTables.php
    tests/Support/ScenarioHarness
    tests/TestCase.php
    tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php
    tests/Unit/Commands/RagScaleReviewCommandTest.php
    tests/Unit/Nodes/PushoverNotifyTest.php
    tests/Unit/Setup
    tests/Unit/Services/MetadataWritebackSafetyTest.php
    vite.config.js
)

excludes=(
    ':(exclude).claude.json'
    ':(exclude).mcp.json'
    ':(exclude).env'
    ':(exclude).env.production'
    ':(exclude)CLAUDE.md'
    ':(exclude)docs/PROJECT.md'
    ':(exclude)docs/PROD-MAINTENANCE.md'
    ':(exclude)docs/canonical-docs-archive-*.zip'
    ':(exclude)docs/claude-*.md'
    ':(exclude)docs/plos-focus-report-*'
    ':(exclude)app/Nodes/PressEnterpriseScraper.php'
    ':(exclude)app/Services/PressEnterpriseScraperService.php'
    ':(exclude)scripts/newspapers-scraper.cjs'
    ':(exclude)database/migrations/2026_04_04_173000_stabilize_news_workflows.php'
    ':(exclude)database/seeders/UpdateAntiHallucinationPromptsSeeder.php'
    ':(exclude)mcp-server/.env'
    ':(exclude)mcp-server/.env.thunderbird.example'
    ':(exclude)mcp-server/dist'
    ':(exclude)mcp-server/node_modules'
    ':(exclude)mcp-servers/plos/.env'
    ':(exclude)mcp-servers/plos/dist'
    ':(exclude)mcp-servers/plos/node_modules'
    ':(exclude)node_modules'
    ':(exclude)public/build'
    ':(exclude)public/hot'
    ':(exclude)public/storage'
    ':(exclude)scripts/bench'
    ':(exclude)scripts/__pycache__'
    ':(exclude)storage/agent-handoffs'
    ':(exclude)storage/app/dev-agent/traces'
    ':(exclude)storage/backups'
    ':(exclude)storage/pail'
    ':(exclude)storage/tools'
    ':(exclude)storage/*.key'
    ':(exclude)storage/*.zip'
    ':(exclude)storage/*.xpi'
    ':(exclude)storage/workflows/*.json'
    ':(exclude)vendor'
)

mapfile -d '' files < <(git -C "$ROOT" ls-files -z -- "${includes[@]}" "${excludes[@]}")
existing_files=()
for file in "${files[@]}"; do
    if [[ -e "$ROOT/$file" ]]; then
        existing_files+=("$file")
    fi
done
files=("${existing_files[@]}")

if ((${#files[@]} == 0)); then
    printf 'No tracked files matched the public export allowlist.\n' >&2
    exit 1
fi

if [[ "$dry_run" == true ]]; then
    printf 'Public export destination: %s\n' "$DEST"
    printf 'Allowlisted tracked files: %d\n\n' "${#files[@]}"
    printf '%s\n' "${files[@]}"
    exit 0
fi

if [[ -e "$DEST" && "$force" != true ]]; then
    printf 'Destination already exists: %s\nUse --force to replace it.\n' "$DEST" >&2
    exit 2
fi

if [[ "$force" == true ]]; then
    rm -rf -- "$DEST"
fi

mkdir -p "$DEST"

(
    cd "$ROOT"
    printf '%s\0' "${files[@]}" | tar --null -T - -cf -
) | tar -C "$DEST" -xf -

cat > "$DEST/PUBLIC_EXPORT_MANIFEST.md" <<EOF
# PLOS Public Export Manifest

Generated: $(date -u +"%Y-%m-%dT%H:%M:%SZ")
Source commit: $(git -C "$ROOT" rev-parse HEAD)
Source tree status: reviewed tracked worktree contents copied from the source tree
Tracked files copied: ${#files[@]}

This export is a fresh public-candidate tree. It intentionally omits private
repository history, production operations docs, local Claude/MCP control files,
private credentials, generated dependency directories, personal archives,
operator-only stabilization checks, and private deployment paths.

The export helper remains in the public tree so maintainers can reproduce the
allowlist/audit workflow from their own internal source repositories.

First public GitHub push checklist:

\`\`\`bash
sed -n '1,220p' docs/public-github-first-push-checklist.md
git commit -m "chore: seed public plos core"
scripts/guards/public-workflow-push-preflight.sh
git remote add origin <new-public-repo-url>
git push -u origin main
\`\`\`

After pushing, confirm the GitHub Actions "Public Readiness" workflow passes,
including the "Docker Compose Config" job. Do not add a public remote to the
private source repository.

Suggested verification for maintainers preparing a public export:

\`\`\`bash
scripts/public-smoke.sh --force "\$HOME/tmp/personal-life-os-core-smoke"
\`\`\`

For a shorter local check inside this exported tree, run:

\`\`\`bash
PUBLIC_AUDIT_LIMIT=120 scripts/guards/public-release-audit.sh
git diff --check --cached
docker compose --env-file .env.example config --quiet
bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/guards/production-fix-commit-message-check.sh scripts/guards/public-github-monitor.sh scripts/guards/github-auth-storage-audit.sh scripts/guards/public-temp-artifact-cleanup.sh scripts/guards/public-workflow-push-preflight.sh
php artisan setup:doctor --profile=core --skip-services --json
php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json
php artisan test tests/Unit/Setup tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php tests/Unit/Commands/RagScaleReviewCommandTest.php tests/Unit/Nodes/PushoverNotifyTest.php tests/Unit/Services/MetadataWritebackSafetyTest.php tests/Feature/Console/AgentMemoryStatsCommandTest.php tests/Feature/Console/AwoReplayCommandTest.php tests/Feature/Console/SetupDoctorCommandTest.php tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php tests/Feature/Console/OpsMcpHealthCommandTest.php tests/Feature/Console/OpsReviewBacklogReportCommandTest.php tests/Feature/Quality/FixturesProvenanceTest.php tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php tests/Feature/Quality/PublicExportPackagingTest.php tests/Feature/Quality/PublicGithubMonitorScriptTest.php tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php tests/Feature/Quality/RepositoryGovernanceTest.php
\`\`\`
EOF

git -C "$DEST" init -q -b main
git -C "$DEST" add -A

if [[ "$verify" == true ]]; then
    git -C "$DEST" diff --cached --check
    bash -n "$DEST/scripts/public-export.sh"
    PUBLIC_AUDIT_LIMIT="${PUBLIC_AUDIT_LIMIT:-120}" "$DEST/scripts/guards/public-release-audit.sh"
fi

printf 'Public export created at %s (%d tracked files staged).\n' "$DEST" "${#files[@]}"
