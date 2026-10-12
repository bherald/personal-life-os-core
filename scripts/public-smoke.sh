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
python3 scripts/guards/tests/test_public_audit_filter.py
python3 scripts/guards/tests/test_photo_demo.py
scripts/snapshot-npm-licenses.sh --check
scripts/snapshot-python-licenses.sh --tier=core --check
scripts/guards/dependency-provenance-check.sh
scripts/audit-licenses.sh
bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/snapshot-npm-licenses.sh scripts/snapshot-python-licenses.sh scripts/audit-licenses.sh scripts/guards/dependency-provenance-check.sh scripts/guards/production-fix-commit-message-check.sh scripts/guards/public-github-monitor.sh scripts/guards/github-auth-storage-audit.sh scripts/guards/public-temp-artifact-cleanup.sh scripts/guards/public-workflow-push-preflight.sh
git diff --check --cached

if [[ "${PLOS_PUBLIC_SMOKE_SKIP_TESTS:-0}" != "1" ]]; then
    DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Unit/Logging/SensitiveLogContextProcessorTest.php tests/Unit/Services/RagSourceReferenceServiceTest.php tests/Unit/Services/AgentRoutingShadowServiceTest.php tests/Unit/Controllers/Api/ChatControllerRagSourceReferenceTest.php tests/Feature/Quality/RagSourceReferenceFrontendContractTest.php tests/Unit/Nodes/ResearchTopicRunnerPromptGuardrailTest.php tests/Unit/Nodes/ResearchQueryOutputControlTest.php tests/Unit/Controllers/Api/ResearchTopicRefinementContractTest.php tests/Unit/Services/ResearchOutcomePropagationTest.php tests/Unit/Services/UniversalResearchRuntimePolicyServiceTest.php tests/Unit/Setup tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php tests/Unit/Commands/RagScaleReviewCommandTest.php tests/Unit/Nodes/PushoverNotifyTest.php tests/Unit/Services/GedZipExportTest.php tests/Unit/Services/MetadataWritebackSafetyTest.php tests/Feature/Console/AgentMemoryStatsCommandTest.php tests/Feature/Console/AwoReplayCommandTest.php tests/Feature/Console/SetupDoctorCommandTest.php tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php tests/Feature/Console/GenealogyTypedRemediationMaterializeCommandTest.php tests/Feature/Console/OpsMcpHealthCommandTest.php tests/Feature/Console/OpsReviewBacklogReportCommandTest.php tests/Feature/Quality/FixturesProvenanceTest.php tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php tests/Feature/Quality/KnowledgeFrontendContractTest.php tests/Feature/Quality/MediaLightboxFrontendContractTest.php tests/Feature/Quality/PublicExportPackagingTest.php tests/Feature/Quality/PublicGithubMonitorScriptTest.php tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php tests/Feature/Quality/RepositoryGovernanceTest.php tests/Unit/Engine/MCPRouterHierarchicalRagToolTest.php tests/Unit/Services/RagChunkingProvenanceServiceTest.php tests/Unit/Services/PromptIntegrityPolicyTest.php tests/Unit/Services/UnifiedCliCompactionTest.php tests/Unit/Services/UnifiedCliFrameworkServicesTest.php tests/Unit/Services/TaskCustodyExpiredOwnerSafetyTest.php tests/Unit/Services/TypeSafeJevDecisionServiceTest.php tests/Feature/Console/ColibriToolContractEvalCommandTest.php tests/Feature/Console/WorkflowPortableDefinitionCommandTest.php tests/Unit/Commands/JoplinTopicAndTranscriptCommandsTest.php tests/Unit/Commands/RagYouTubeSourceIdentityTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsPostProcessorRuntimePolicyTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsCompleteSourceTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsScopeTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsWorkflowPriorityTest.php tests/Unit/Nodes/YouTube/YouTubeNightlyPipelineTest.php tests/Unit/Services/JoplinYouTubeKnowledgeServiceTest.php tests/Unit/Services/RAGPublicationGuardTest.php tests/Unit/Services/RAGServiceTest.php tests/Unit/Services/SemDeDupServiceTest.php tests/Unit/Services/UnifiedCliRagIndexPostgresTest.php tests/Unit/Services/YouTubeMissingOriginalRetentionTest.php tests/Unit/Services/YouTubeTranscriptStorageServiceTest.php
    npm test --prefix mcp-server
    PLOS_MCP_DOTENV_MODE=skip npm test --prefix mcp-servers/plos
    node --test scripts/plos-cli/internal-operation.test.mjs scripts/plos-cli/mcp-operation.test.mjs scripts/plos-cli/native-turn.test.mjs scripts/plos-cli/runtime-check.test.mjs
    python3 scripts/plos-cli/registry-export-gate.test.py
    python3 scripts/plos-cli/sync-registry.test.py
    python3 -m unittest discover -s examples/offline-controller/tests

fi

printf 'Public smoke passed: %s\n' "$DEST"
