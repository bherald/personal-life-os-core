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
HOME_DIR="$(realpath -m "${HOME:-/nonexistent-home-for-public-export}")"

case "$DEST" in
    /|"$ROOT"|"$ROOT"/*)
        printf 'Refusing to export into %s. Choose a fresh directory outside %s.\n' "$DEST" "$ROOT" >&2
        exit 2
        ;;
esac

critical_destinations=(
    "$HOME_DIR"
    "$HOME_DIR/.config"
    "$HOME_DIR/.ssh"
    /bin
    /boot
    /dev
    /etc
    /home
    /lib
    /lib64
    /opt
    /proc
    /root
    /run
    /sbin
    /sys
    /tmp
    /usr
    /var
)

for critical_destination in "${critical_destinations[@]}"; do
    if [[ -n "$critical_destination" && "$DEST" == "$critical_destination" ]]; then
        printf 'Refusing to export into unsafe destination: %s\nChoose a fresh dedicated directory, such as $HOME/tmp/personal-life-os-core.\n' "$DEST" >&2
        exit 2
    fi
done

case "$DEST" in
    "$HOME_DIR/.config"/*|"$HOME_DIR/.ssh"/*|/bin/*|/boot/*|/dev/*|/etc/*|/lib/*|/lib64/*|/proc/*|/root/*|/run/*|/sbin/*|/sys/*|/usr/*)
        printf 'Refusing to export into unsafe destination: %s\nChoose a fresh dedicated directory, such as $HOME/tmp/personal-life-os-core.\n' "$DEST" >&2
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
    LICENSES
    NOTICE.md
    README.md
    SECURITY.md
    THIRD_PARTY.md
    app
    examples/photo-knowledge
    examples/offline-controller
    examples/nightly
    examples/nightly-use-cases
    examples/skills
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
    docs/public-release/npm-reviewed-metadata.json
    docs/public-release/dependency-gate-2026-10-12.md
    docs/public-release/ui-dependency-upgrade-2026-10-12.md
    docs/public-release/npm-license-notes-2026-10-12.md
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
    docs/research-workbench.md
    docs/articles/plos-v2.1.md
    docs/photo-knowledge-demo.md
    docs/connectors-and-ui.md
    docs/nightly-review-example.md
    docs/nightly-use-cases.md
    docs/portable-methods.md
    docs/core-cli-helpers.md
    tests/fixtures/plos-cli-mcp-server.mjs
    docs/public-methods/skills-and-mcp.md
    docs/releases
    docs/public-release/audit-allowances.json
    docs/public-release/privacy-secret-scan-baseline-2026-10-12.md
    docs/schema-reference.md
    docs/troubleshooting.md
    mcp-server/.env.example
    mcp-server/README.md
    mcp-server/package-lock.json
    mcp-server/package.json
    mcp-server/src
    mcp-server/test
    mcp-server/tsconfig.json
    mcp-servers/plos/.gitignore
    mcp-servers/plos/.env.example
    mcp-servers/plos/README.md
    mcp-servers/plos/package-lock.json
    mcp-servers/plos/package.json
    mcp-servers/plos/src
    mcp-servers/plos/tsconfig.json
    mcp-servers/plos/tool-contract-baseline.json
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
    tests/Feature/Console/GenealogyTypedRemediationMaterializeCommandTest.php
    tests/Feature/Console/OpsMcpHealthCommandTest.php
    tests/Feature/Console/OpsReviewBacklogReportCommandTest.php
    tests/Feature/Quality/FixturesProvenanceTest.php
    tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php
    tests/Feature/Quality/KnowledgeFrontendContractTest.php
    tests/Feature/Quality/MediaLightboxFrontendContractTest.php
    tests/Feature/Quality/PublicExportPackagingTest.php
    tests/Feature/Quality/PublicGithubMonitorScriptTest.php
    tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php
    tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php
    tests/Feature/Quality/RepositoryGovernanceTest.php
    tests/Fixtures
    tests/Support/PreservesSchemaTables.php
    tests/Support/ScenarioHarness
    tests/Support/FakesPoolWireCheckpoints.php
    tests/Unit/PublicNightlyJobDefaultsTest.php
    tests/Unit/Nodes/YouTube/YouTubeKeyPointsOutputContractTest.php
    tests/Unit/Services/AgentLoopCleanupStopPreservationTest.php
    tests/Unit/Services/AgentLoopOperatorReviewOwnershipTest.php
    tests/Unit/Services/DistributedAgentTypedStopTest.php
    tests/Unit/Nodes/YouTube/YouTubeTranscriptFormatterTest.php
    tests/Unit/Services/AIServiceGenerationCacheIdentityTest.php
    tests/Unit/Services/AIServicePreferredCodexPrivacyAdmissionTest.php
    tests/Unit/Services/AgentActionabilityGateServiceTest.php
    tests/Unit/Services/AgentLoopAdmissionFailureReceiptTest.php
    tests/Unit/Services/AgentLoopGenealogyNoAutoApproveTest.php
    tests/Unit/Services/ContextualRetrievalServiceTest.php
    tests/Unit/Commands/CacheClearCustodyGuardTest.php
    tests/Unit/Console/ReleaseCacheInvalidateCommandTest.php
    tests/Unit/Services/UnifiedCliSystemConfigTest.php
    tests/Unit/Logging/SensitiveLogContextProcessorTest.php
    tests/Unit/Services/RagSourceReferenceServiceTest.php
    tests/Unit/Engine/MCPRouterHierarchicalRagToolTest.php
    tests/Unit/Services/RagChunkingProvenanceServiceTest.php
    tests/Unit/Services/PromptIntegrityPolicyTest.php
    tests/Unit/Services/UnifiedCliCompactionTest.php
    tests/Unit/Services/UnifiedCliFrameworkServicesTest.php
    tests/Unit/Services/TaskCustodyExpiredOwnerSafetyTest.php
    tests/Unit/Services/TypeSafeJevDecisionServiceTest.php
    tests/Feature/Console/ColibriToolContractEvalCommandTest.php
    tests/Feature/Console/WorkflowPortableDefinitionCommandTest.php
    tests/Support/CreatesYouTubeSourceCustodySchema.php
    tests/Unit/Commands/RagYouTubeSourceIdentityTest.php
    tests/Unit/Services/YouTubeMissingOriginalRetentionTest.php
    tests/Unit/Services/RAGPublicationGuardTest.php
    tests/Unit/Services/RAGServiceTest.php
    tests/Unit/Services/SemDeDupServiceTest.php
    tests/Unit/Services/YouTubeTranscriptStorageServiceTest.php
    tests/Unit/Nodes/YouTube/YouTubeNightlyPipelineTest.php
    tests/Unit/Nodes/YouTube/YouTubeKeyPointsScopeTest.php
    tests/Unit/Nodes/YouTube/YouTubeKeyPointsCompleteSourceTest.php
    tests/Unit/Nodes/YouTube/YouTubeKeyPointsWorkflowPriorityTest.php
    tests/Unit/Nodes/YouTube/YouTubeKeyPointsPostProcessorRuntimePolicyTest.php
    tests/Unit/Services/JoplinYouTubeKnowledgeServiceTest.php
    tests/Unit/Commands/JoplinTopicAndTranscriptCommandsTest.php
    tests/Unit/Services/UnifiedCliRagIndexPostgresTest.php
    tests/Unit/Services/AgentRoutingShadowServiceTest.php
    tests/Unit/Controllers/Api/ChatControllerRagSourceReferenceTest.php
    tests/Feature/Quality/RagSourceReferenceFrontendContractTest.php
    tests/Unit/Nodes/ResearchTopicRunnerPromptGuardrailTest.php
    tests/Unit/Nodes/ResearchQueryOutputControlTest.php
    tests/Unit/Controllers/Api/ResearchTopicRefinementContractTest.php
    tests/Unit/Services/ResearchOutcomePropagationTest.php
    tests/Unit/Services/UniversalResearchRuntimePolicyServiceTest.php
    scripts/chat-sse.test.mjs
    scripts/chat-stream-activity.test.mjs
    scripts/chat-auto-follow.test.mjs
    scripts/chat-run-failure.test.mjs
    scripts/chat-tool-activity.test.mjs
    scripts/latest-conversation-load.test.mjs
    scripts/review-queue.test.mjs
    scripts/research-hub-loading.test.mjs
    scripts/research-hub-loading-ui.test.mjs
    scripts/patches/codex/LICENSE
    scripts/patches/codex/NOTICE
    scripts/patches/codex/plos-frontend-0.153.4.patch
    scripts/patches/codex/plos-native-executor-0.153.4.patch
    scripts/patches/codex/windows-vt/CROSSTERM-LICENSE
    scripts/patches/codex/windows-vt/plos_vt_decoder.rs
    scripts/patches/codex/windows-vt/plos_vt_parse.rs
    scripts/patches/codex/windows-vt/plos_windows_vt.rs
    scripts/patches/codex/windows-vt/provenance.json
    scripts/plos-cli/adopt-profile.mjs
    scripts/plos-cli/backend.php
    scripts/plos-cli/build-frontend.py
    scripts/plos-cli/check-runtime.php
    scripts/plos-cli/count-prompt.py
    scripts/plos-cli/entry.mjs
    scripts/plos-cli/framework-service.php
    scripts/plos-cli/frontend-arguments.mjs
    scripts/plos-cli/frontend-build.mjs
    scripts/plos-cli/host-profile.mjs
    scripts/plos-cli/internal-mcp.php
    scripts/plos-cli/internal-operation.mjs
    scripts/plos-cli/internal-operation.test.mjs
    scripts/plos-cli/launch.mjs
    scripts/plos-cli/managed-input.mjs
    scripts/plos-cli/mcp-operation.mjs
    scripts/plos-cli/mcp-operation.test.mjs
    scripts/plos-cli/native-codex.mjs
    scripts/plos-cli/native-control.php
    scripts/plos-cli/native-presentation.mjs
    scripts/plos-cli/native-processes.mjs
    scripts/plos-cli/native-turn-worker.mjs
    scripts/plos-cli/native-turn.mjs
    scripts/plos-cli/native-turn.test.mjs
    scripts/plos-cli/registry-export-gate.py
    scripts/plos-cli/registry-export-gate.test.py
    scripts/plos-cli/runtime-check.mjs
    scripts/plos-cli/runtime-check.test.mjs
    scripts/plos-cli/scripted.mjs
    scripts/plos-cli/ssh-tunnel.mjs
    scripts/plos-cli/startup-sync.php
    scripts/plos-cli/state-directory.mjs
    scripts/plos-cli/state-directory.ps1
    scripts/plos-cli/sync-dev-database.php
    scripts/plos-cli/sync-registry.py
    scripts/plos-cli/sync-registry.test.py
    scripts/plos-cli/sync-runtime.mjs
    scripts/plos-cli/transport.mjs
    scripts/plos-cli/tunnel-policy.php
    tests/TestCase.php
    tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php
    tests/Unit/Commands/RagScaleReviewCommandTest.php
    tests/Unit/Nodes/PushoverNotifyTest.php
    tests/Unit/Setup
    tests/Unit/Services/GedZipExportTest.php
    tests/Unit/Services/MetadataWritebackSafetyTest.php
    vite.config.js
)

excludes=(
    ':(exclude)database/seeders/MorningWeatherWorkflowSeeder.php'
    ':(exclude)database/seeders/NewsBriefWorkflowSeeder.php'
    ':(exclude)database/seeders/AddRSSFeedsToWorkflowsSeeder.php'
    ':(exclude)database/seeders/UpdatePARegionalFeedsSeeder.php'
    ':(exclude)database/migrations/2026_06_07_080000_update_morning_weather_bloomsburg_location.php'
    ':(exclude)database/migrations/2026_06_07_081000_make_morning_weather_preformatted_passthrough.php'
    ':(exclude)database/migrations/2026_06_10_090000_make_morning_weather_nws_primary.php'
    ':(exclude)database/migrations/2026_04_30_191500_seed_real_news_no_bullshit_bias_rating.php'
    ':(exclude)database/migrations/2026_07_26_100000_register_host_only_offline_assistant_route.php'
    ':(exclude)config/local-engine-candidates'
    ':(exclude)config/local-ingress'
    ':(exclude)config/*.87.json'
    ':(exclude)database/migrations/2026_07_16_220000_seed_resilient_local_first_routing.php'
    ':(exclude)database/migrations/2026_07_21_150000_register_colibri_glm52_deep_offline_candidate.php'
    ':(exclude)database/migrations/2026_07_22_230000_adopt_colibri_native_stop_runtime.php'
    ':(exclude)database/migrations/2026_07_23_170000_upgrade_colibri_dev_pr556_runtime.php'
    ':(exclude)database/migrations/2026_07_23_230000_upgrade_colibri_dev_pr535_runtime.php'
    ':(exclude)database/migrations/2026_07_24_091800_adopt_colibri_finish_reason_runtime.php'
    ':(exclude)database/migrations/2026_08_09_120000_register_kimi_k3_colibri_staged_candidate.php'
    ':(exclude)database/migrations/2026_08_12_073000_restore_operator_approved_ollama_87_routes.php'
    ':(exclude)database/migrations/2026_08_18_034500_stage_qwen38_bench_provider.php'
    ':(exclude)database/migrations/2026_09_05_180000_restore_operator_selected_colibri_glm52_lane.php'
    ':(exclude)database/migrations/2026_09_09_000000_seed_unified_cli_workflow_services.php'
    ':(exclude)database/migrations/2026_09_09_020000_seed_unified_cli_framework_services.php'
    ':(exclude)database/migrations/2026_09_15_030000_register_cyber_lan_observation_candidate.php'
    ':(exclude)database/migrations/2026_09_17_200000_stage_measured_ollama87_context_profile.php'
    ':(exclude)database/migrations/2026_09_17_210000_stage_measured_ollama87_cli_contract.php'
    ':(exclude)database/migrations/2026_09_18_030000_stage_native_coder_controller.php'
    ':(exclude)database/migrations/2026_09_26_230000_stage_installed_engine_controller_candidates.php'
    ':(exclude)database/migrations/2026_09_28_120000_enforce_controller_desktop_reserve_and_wait_policy.php'
    ':(exclude)database/migrations/2026_09_28_160200_register_controller_image_workloads.php'
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
    ':(exclude)storage/app/dev-agent'
    ':(exclude)storage/app/testing-offline-dev-assist-traces'
    ':(exclude)storage/app/dev-agent/traces'
    ':(exclude)storage/backups'
    ':(exclude)storage/claude-work'
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
scripts/guards/dependency-provenance-check.sh
git diff --check --cached
docker compose --env-file .env.example config --quiet
bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/guards/dependency-provenance-check.sh scripts/guards/production-fix-commit-message-check.sh scripts/guards/public-github-monitor.sh scripts/guards/github-auth-storage-audit.sh scripts/guards/public-temp-artifact-cleanup.sh scripts/guards/public-workflow-push-preflight.sh
php artisan setup:doctor --profile=core --skip-services --json
php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Unit/Logging/SensitiveLogContextProcessorTest.php tests/Unit/Services/RagSourceReferenceServiceTest.php tests/Unit/Services/AgentRoutingShadowServiceTest.php tests/Unit/Controllers/Api/ChatControllerRagSourceReferenceTest.php tests/Feature/Quality/RagSourceReferenceFrontendContractTest.php tests/Unit/Nodes/ResearchTopicRunnerPromptGuardrailTest.php tests/Unit/Nodes/ResearchQueryOutputControlTest.php tests/Unit/Controllers/Api/ResearchTopicRefinementContractTest.php tests/Unit/Services/ResearchOutcomePropagationTest.php tests/Unit/Services/UniversalResearchRuntimePolicyServiceTest.php tests/Unit/Setup tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php tests/Unit/Commands/RagScaleReviewCommandTest.php tests/Unit/Nodes/PushoverNotifyTest.php tests/Unit/Services/GedZipExportTest.php tests/Unit/Services/MetadataWritebackSafetyTest.php tests/Feature/Console/AgentMemoryStatsCommandTest.php tests/Feature/Console/AwoReplayCommandTest.php tests/Feature/Console/SetupDoctorCommandTest.php tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php tests/Feature/Console/GenealogyTypedRemediationMaterializeCommandTest.php tests/Feature/Console/OpsMcpHealthCommandTest.php tests/Feature/Console/OpsReviewBacklogReportCommandTest.php tests/Feature/Quality/FixturesProvenanceTest.php tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php tests/Feature/Quality/KnowledgeFrontendContractTest.php tests/Feature/Quality/MediaLightboxFrontendContractTest.php tests/Feature/Quality/PublicExportPackagingTest.php tests/Feature/Quality/PublicGithubMonitorScriptTest.php tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php tests/Feature/Quality/RepositoryGovernanceTest.php tests/Unit/Engine/MCPRouterHierarchicalRagToolTest.php tests/Unit/Services/RagChunkingProvenanceServiceTest.php tests/Unit/Services/PromptIntegrityPolicyTest.php tests/Unit/Services/UnifiedCliCompactionTest.php tests/Unit/Services/UnifiedCliFrameworkServicesTest.php tests/Unit/Services/TaskCustodyExpiredOwnerSafetyTest.php tests/Unit/Services/TypeSafeJevDecisionServiceTest.php tests/Feature/Console/ColibriToolContractEvalCommandTest.php tests/Feature/Console/WorkflowPortableDefinitionCommandTest.php tests/Unit/Commands/JoplinTopicAndTranscriptCommandsTest.php tests/Unit/Commands/RagYouTubeSourceIdentityTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsPostProcessorRuntimePolicyTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsCompleteSourceTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsScopeTest.php tests/Unit/Nodes/YouTube/YouTubeKeyPointsWorkflowPriorityTest.php tests/Unit/Nodes/YouTube/YouTubeNightlyPipelineTest.php tests/Unit/Services/JoplinYouTubeKnowledgeServiceTest.php tests/Unit/Services/RAGPublicationGuardTest.php tests/Unit/Services/RAGServiceTest.php tests/Unit/Services/SemDeDupServiceTest.php tests/Unit/Services/UnifiedCliRagIndexPostgresTest.php tests/Unit/Services/YouTubeMissingOriginalRetentionTest.php tests/Unit/Services/YouTubeTranscriptStorageServiceTest.php
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
