#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"
DEST="${PLOS_PUBLIC_SMOKE_DIR:-$HOME/tmp/personal-life-os-core-smoke}"
force=false

usage() {
    cat <<'USAGE'
Usage: scripts/public-smoke.sh [options] [destination]

Run the public export smoke test against a fresh history-free tree.

Options:
  --force        Replace the destination if it already exists.
  -h, --help     Show this help text.

Environment skip flags:
  PLOS_PUBLIC_SMOKE_SKIP_INSTALL=1  Skip composer/npm/python dependency install.
  PLOS_PUBLIC_SMOKE_SKIP_BUILD=1    Skip npm run build.
  PLOS_PUBLIC_SMOKE_SKIP_TESTS=1    Skip focused PHPUnit tests.

Default destination: ~/tmp/personal-life-os-core-smoke
USAGE
}

while (($#)); do
    case "$1" in
        --force)
            force=true
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
            DEST="$1"
            ;;
    esac
    shift
done

export_args=()
if [[ "$force" == true ]]; then
    export_args+=(--force)
fi

"$ROOT/scripts/public-export.sh" "${export_args[@]}" "$DEST"

cd "$DEST"

if [[ "${PLOS_PUBLIC_SMOKE_SKIP_INSTALL:-0}" != "1" ]]; then
    composer install --no-interaction --prefer-dist --no-progress
    npm ci
    npm ci --prefix mcp-server
    npm ci --prefix mcp-servers/plos
    npm audit
    npm audit --prefix mcp-server
    npm audit --prefix mcp-servers/plos
    python3 -m venv .venv
    .venv/bin/python -m pip install -c requirements-core.constraints.txt -r requirements-core.txt
fi

set_env_var() {
    local key="$1"
    local value="$2"
    local file="$3"

    if grep -qE "^${key}=" "$file"; then
        sed -i "s#^${key}=.*#${key}=${value}#" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

cp .env.example .env
set_env_var WEB_UI_MASTER_PASSWORD public-smoke-password .env
set_env_var PYTHON_BINARY .venv/bin/python .env
php artisan key:generate --force
php artisan passport:keys --force --no-interaction

test -x .venv/bin/python

if [[ "${PLOS_PUBLIC_SMOKE_SKIP_BUILD:-0}" != "1" ]]; then
    npm run build
fi

# Core profile without live service probes; proves first-boot config shape.
php artisan setup:doctor --profile=core --skip-services --json
# Media local slice only; optional live service proofs remain tag-gate work.
php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json

PUBLIC_AUDIT_LIMIT="${PUBLIC_AUDIT_LIMIT:-120}" scripts/guards/public-release-audit.sh
scripts/snapshot-npm-licenses.sh --check
scripts/snapshot-python-licenses.sh --tier=core --check
scripts/audit-licenses.sh
bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/snapshot-npm-licenses.sh scripts/snapshot-python-licenses.sh scripts/audit-licenses.sh scripts/guards/production-fix-commit-message-check.sh scripts/guards/public-github-monitor.sh scripts/guards/github-auth-storage-audit.sh
git diff --check --cached

if [[ "${PLOS_PUBLIC_SMOKE_SKIP_TESTS:-0}" != "1" ]]; then
    php artisan test tests/Unit/Setup tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php tests/Unit/Commands/RagScaleReviewCommandTest.php tests/Unit/Nodes/PushoverNotifyTest.php tests/Unit/Services/MetadataWritebackSafetyTest.php tests/Feature/Console/AwoReplayCommandTest.php tests/Feature/Console/SetupDoctorCommandTest.php tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php tests/Feature/Console/OpsMcpHealthCommandTest.php tests/Feature/Console/OpsReviewBacklogReportCommandTest.php tests/Feature/Quality/FixturesProvenanceTest.php tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php tests/Feature/Quality/PublicExportPackagingTest.php tests/Feature/Quality/PublicGithubMonitorScriptTest.php tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php tests/Feature/Quality/RepositoryGovernanceTest.php
fi

printf 'Public smoke passed: %s\n' "$DEST"
