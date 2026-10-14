<?php

namespace App\Services;

use RuntimeException;

/**
 * Reads the finalized, tracked State activation evidence.  This class is
 * deliberately read-only: host transfer and resealing remain owned by the
 * existing State activation scripts.
 */
class ModelHostControllerMhc08K3StateContractService
{
    public const STAGING_SCHEMA = 'plos.model_host_controller.mhc08_k3_state_activation_staging.v1';

    public const BASELINE_SCHEMA = 'plos.model_host_controller.mhc08_k3_state_activation_baseline.v1';

    public const BUILD_SCHEMA = 'plos.model_host_controller.mhc08_k3_state_build.v1';

    public const PROVENANCE_SCHEMA = 'plos.model_host_controller.mhc06_provenance.v2';

    public const STATE_RUNTIME = 'dev-33302b8-kimi-k3-state-oracle-v162-selective-cpu';

    public const STATE_PATCH = 'd3d8d4068a81a822541895ef679939b7dc5c93441fbbd044be43d1449b4494d7';

    public const STATE_TREE = '75bcdac5e58522bac764080ec5fdb3c83cbabad2';

    public const CHECKPOINT_ROOT = 'C:\\ProgramData\\PLOS\\ModelHostController\\mhc08-k3\\state-activation-checkpoint';

    public const V2_CHECKPOINT_ROOT = 'C:\\ProgramData\\PLOS\\ModelHostController\\mhc08-k3-v2\\state-activation-checkpoint-recovery-04';

    public const BASE_COMMIT = '33302b82daaeba895ef8c21a61d6030bdb305a9d';

    public const BASE_TREE = 'a6460e125a6b2b8afd2f8303ae708a3e22845338';

    public const PROMOTION_REF = '7f3b1f1004bd1ceb4385ae5dd21699a6e555dc9a';

    public const POLICY_SHA256 = '411bccfd0f7d528e23d89f5458feb09a44a359af6d63c33199adf78bbd38ab10';

    private const STREAM_POLICY = [
        'idle_timeout_seconds' => 7200,
        'absolute_timeout_seconds' => 0,
        'connect_timeout_seconds' => 10,
        'log_interval_seconds' => 30,
    ];

    public function __construct(private readonly ?string $evidenceRoot = null) {}

    /** @return array<string,mixed> */
    public function finalized(): array
    {
        $root = $this->root();
        $profile = $this->evidenceProfile($root);
        $stagingPath = $root.'/'.$profile['staging'];
        $baselinePath = $root.'/'.$profile['baseline'];
        $buildPath = $root.'/'.$profile['build'];
        $provenancePath = $root.'/'.$profile['provenance'];
        $staging = $this->read($stagingPath);
        $baseline = $this->read($baselinePath);
        $build = $this->read($buildPath);
        $provenance = $this->read($provenancePath);
        $repo = dirname($root, 4);

        $this->assertStaging($staging, $profile['v2']);
        $this->assertBaseline($baseline, $profile['v2']);
        $this->assertBuild($build, $staging, $repo, $profile['v2']);
        $this->assertProvenance($provenance);
        $profileAggregate = json_decode((string) ($provenance['aggregate_json'] ?? ''), true);
        if ($profile['v2'] && ($profileAggregate['schema'] ?? null) !== 'plos.model_host_controller.mhc06_soak_aggregate.v2') {
            throw new RuntimeException('MHC-08 v2 evidence requires the exact ten-hour aggregate profile.');
        }

        $expectedSources = [
            'config/model-host-controller-mhc08-k3.87.json' => $repo.'/config/model-host-controller-mhc08-k3.87.json',
            'scripts/plos-model-host-controller-mhc08-k3.ps1' => $repo.'/scripts/plos-model-host-controller-mhc08-k3.ps1',
            'scripts/plos-model-host-controller.ps1' => $repo.'/scripts/plos-model-host-controller.ps1',
            'scripts/plos-model-host-controller-service.ps1' => $repo.'/scripts/plos-model-host-controller-service.ps1',
            'scripts/plos-kimi-k3-context-gate.ps1' => $repo.'/scripts/plos-kimi-k3-context-gate.ps1',
            'scripts/plos-kimi-k3-alt-qwen-sidecar.ps1' => $repo.'/scripts/plos-kimi-k3-alt-qwen-sidecar.ps1',
        ];
        $expectedDestinations = [
            'C:\\PLOS\\ModelHostController\\config\\model-host-controller-mhc08-k3.87.json',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller-mhc08-k3.ps1',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller.ps1',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller-service.ps1',
            'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-context-gate.ps1',
            'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-alt-qwen-sidecar.ps1',
        ];
        foreach (array_values($staging['files']) as $index => $file) {
            $source = array_keys($expectedSources)[$index] ?? null;
            $sourcePath = $source === null ? null : $expectedSources[$source];
            if (! is_array($file)
                || ($file['source'] ?? null) !== $source
                || ($file['destination'] ?? null) !== ($expectedDestinations[$index] ?? null)
                || $sourcePath === null || ! is_file($sourcePath) || is_link($sourcePath)
                || hash_file('sha256', $sourcePath) !== ($file['sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 State activation source binding drifted.');
            }
        }
        $this->assertConfigAndControllerChain($repo, $staging);
        $executor = $repo.'/scripts/plos-model-host-controller-mhc08-state-activation'.($profile['v2'] ? '-v2' : '').'.ps1';
        if (($baseline['staging_sha256'] ?? null) !== hash_file('sha256', $stagingPath)
            || ($baseline['executor_sha256'] ?? null) !== $this->fileSha($executor)
            || ($baseline['private_package_identity'] ?? null) !== $staging['base_ref']
            || ($baseline['state_build_receipt_sha256'] ?? null) !== $staging['state_build_receipt_sha256']
            || ($baseline['mhc06_provenance_sha256'] ?? null) !== $staging['mhc06_provenance_sha256']) {
            throw new RuntimeException('MHC-08 State activation baseline bindings drifted.');
        }

        $buildSha = hash_file('sha256', $buildPath);
        $provenanceSha = hash_file('sha256', $provenancePath);
        if ($buildSha !== $staging['state_build_receipt_sha256']
            || $provenanceSha !== $staging['mhc06_provenance_sha256']
            || ($build['mhc06_soak_deployed_full_ref'] ?? $build['mhc06_deployed_full_ref'] ?? null) !== ($provenance['soak_deployed_full_ref'] ?? null)
            || ($profile['v2']
                ? ($build['mhc06_envelope_deployed_full_ref'] ?? null) !== ($build['mhc06_deployed_full_ref'] ?? null)
                : ($build['mhc06_envelope_deployed_full_ref'] ?? null) !== ($provenance['envelope_deployed_full_ref'] ?? null))
            || ($build['mhc06_soak_custody_sha256'] ?? null) !== ($provenance['soak_custody_sha256'] ?? null)
            || ($build['mhc06_legacy_provenance_sha256'] ?? null) !== ($provenance['legacy_provenance_sha256'] ?? null)
            || ($build['mhc06_custody_executor_sha256'] ?? null) !== ($provenance['custody_executor_sha256'] ?? null)
            || ($build['mhc06_custody_private_package_identity'] ?? null) !== ($provenance['custody_private_package_identity'] ?? null)
            || ($build['mhc06_custody_production_root_sha256'] ?? null) !== ($provenance['custody_production_root_sha256'] ?? null)) {
            throw new RuntimeException('MHC-08 finalized evidence hash binding drifted.');
        }

        return [
            'runtime_profile' => 'State',
            'runtime_revision' => $staging['state_runtime_revision'],
            'patch_sha256' => $staging['state_patch_sha256'],
            'patched_tree' => $staging['state_patched_tree'],
            'engine_sha256' => $staging['state_engine_sha256'],
            'runtime_receipt_sha256' => $staging['runtime_receipt_sha256'],
            'state_build_receipt_sha256' => $buildSha,
            'mhc06_provenance_sha256' => $provenanceSha,
            'base_ref' => $staging['base_ref'],
            'staging_sha256' => hash_file('sha256', $stagingPath),
            'baseline_sha256' => hash_file('sha256', $baselinePath),
            'evidence_profile' => $profile['v2'] ? 'mhc08-v2' : 'mhc08-v1',
            'baseline' => $baseline,
            'build' => $build,
            'provenance' => $provenance,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $config */
    public function assertClosedProvider(array $row, array $config): void
    {
        $contract = $this->finalized();
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
        $route = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $baseUrl = parse_url((string) ($row['base_url'] ?? ''));
        $capabilities = $this->jsonList($row['capabilities'] ?? null);
        $supportedModels = $this->jsonList($row['supported_models'] ?? null);
        if ((string) ($row['instance_id'] ?? '') !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || (string) ($row['instance_type'] ?? '') !== 'local_llm'
            || (string) ($row['gpu_target'] ?? '') !== 'none'
            || (string) ($row['host_affinity'] ?? '') !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host_affinity')
            || (string) ($row['compat_backend'] ?? '') !== 'openai_compat_sse'
            || (string) ($row['compat_runtime_family'] ?? '') !== 'colibri-k3-47a749d'
            || (string) ($row['compat_status'] ?? '') !== 'provisional'
            || ! is_array($baseUrl) || ($baseUrl['scheme'] ?? null) !== 'http'
            || ($baseUrl['host'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host') || (int) ($baseUrl['port'] ?? 0) !== (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            || ($baseUrl['path'] ?? null) !== '/v1' || isset($baseUrl['query']) || isset($baseUrl['fragment'])
            || (int) ($row['port'] ?? 0) !== (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            || $capabilities !== ['text', 'chat', 'streaming', 'local_llm', 'deep_offline']
            || $supportedModels !== [app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')]
            || (int) ($row['context_length'] ?? 0) !== 4096
            || (int) ($row['max_concurrent'] ?? 0) !== 1
            || (int) ($row['rate_limit_rpm'] ?? 0) !== 1
            || (int) ($row['is_active'] ?? 1) !== 0
            || (int) ($row['is_healthy'] ?? 1) !== 0
            || (string) ($row['routability'] ?? '') !== 'bench_only'
            || (string) ($config['adapter'] ?? '') !== 'openai_compatible'
            || (string) ($config['transport'] ?? '') !== 'openai_sse_monitored'
            || ! $this->healthEndpoint($config)
            || (int) ($config['max_tokens'] ?? 0) !== 256
            || (int) ($config['temperature'] ?? -1) !== 0
            || ($admission['state'] ?? null) !== 'staged'
            || ($admission['phase'] ?? null) !== 'MHC-08-State-staged'
            || ($admission['runtime'] ?? null) !== $contract['runtime_revision']
            || ($admission['runtime_commit'] ?? null) !== self::BASE_COMMIT
            || ($admission['model_revision'] ?? null) !== '9f62e4e9fffbd0a83ddd60e1c209d828994b3569'
            || ($admission['patch_sha256'] ?? null) !== $contract['patch_sha256']
            || ($admission['patched_tree'] ?? null) !== $contract['patched_tree']
            || ($admission['context_receipt_sha256'] ?? null) !== '8a73b228ece6ca8ff412a05d51003c8cf74af227780ed9411e6b8b9957620241'
            || ($admission['baseline'] ?? null) !== 'cpu_no_fallback'
            || ($admission['normal_routing'] ?? null) !== false
            || (int) ($admission['required_shards'] ?? 0) !== 94
            || (int) ($admission['attempt'] ?? 0) !== 6
            || (int) ($admission['batch_number'] ?? 0) !== 2
            || (int) ($admission['batch_case_count'] ?? 0) !== 5
            || (int) ($admission['max_tokens'] ?? 0) !== 128
            || ($admission['tools_enabled'] ?? null) !== false
            || ($admission['fallback_enabled'] ?? null) !== false
            || ($config['default_model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($config['models']['deep_offline'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($config['extra_headers']['Host'] ?? null) !== '127.0.0.1'
            || ($config['stream_policy'] ?? null) !== self::STREAM_POLICY
            || ($route['allowed_model_roles'] ?? null) !== ['deep_offline']
            || ($route['require_model_role'] ?? null) !== true
            || ($route['require_explicit_instance'] ?? null) !== true
            || ($route['allow_generic_fallback'] ?? null) !== false
            || ($route['tools_enabled'] ?? null) !== false
            || ($admission['runtime_profile'] ?? null) !== 'State'
            || ($admission['runtime_revision'] ?? null) !== $contract['runtime_revision']
            || ($admission['patch_sha256'] ?? null) !== $contract['patch_sha256']
            || ($admission['patched_tree'] ?? null) !== $contract['patched_tree']
            || ($admission['engine_sha256'] ?? null) !== $contract['engine_sha256']
            || ($admission['runtime_receipt_sha256'] ?? null) !== $contract['runtime_receipt_sha256']
            || ($admission['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
            || ($admission['mhc06_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
            || ($admission['state_staged'] ?? null) !== true
            || ($route['require_explicit_instance'] ?? null) !== true
            || ($route['allow_generic_fallback'] ?? null) !== false
            || ($route['tools_enabled'] ?? null) !== false) {
            throw new RuntimeException('MHC-08 K3 State provider must remain exact, closed, and explicit-only.');
        }
    }

    /** @param array<string,mixed> $profile */
    public function assertClosedProfile(array $profile): void
    {
        $contract = $this->finalized();
        $metadata = is_string($profile['metadata'] ?? null)
            ? json_decode($profile['metadata'], true)
            : ($profile['metadata'] ?? null);
        $runtime = is_array($metadata) && is_array($metadata['runtime'] ?? null)
            ? $metadata['runtime']
            : [];
        if (($profile['profile_name'] ?? null) !== 'deep_offline_kimi_k3'
            || ($profile['model_name'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || (int) ($profile['enabled'] ?? 1) !== 0
            || ($profile['status'] ?? null) !== 'staged'
            || (int) ($profile['requires_override'] ?? 0) !== 1
            || ! is_array($metadata)
            || ($metadata['instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($metadata['required_provider'] ?? null) !== true
            || ($metadata['route'] ?? null) !== 'required_local_provider_no_fallback'
            || ($metadata['tools_enabled'] ?? null) !== false
            || ($metadata['admission_state'] ?? null) !== 'state_staged'
            || ($runtime['context_length'] ?? null) !== 4096
            || ($runtime['max_tokens'] ?? null) !== 256
            || ($runtime['profile'] ?? null) !== 'State'
            || ($runtime['runtime_revision'] ?? null) !== $contract['runtime_revision']
            || ($runtime['engine_sha256'] ?? null) !== $contract['engine_sha256']
            || ($runtime['patch_sha256'] ?? null) !== $contract['patch_sha256']
            || ($runtime['patched_tree'] ?? null) !== $contract['patched_tree']) {
            throw new RuntimeException('MHC-08 K3 State profile must remain exact, staged, and override-required.');
        }
    }

    /** @param array<string,mixed> $staging */
    private function assertStaging(array $staging, bool $v2): void
    {
        $keys = [
            'schema', 'phase', 'host_id', 'base_ref', 'private_package_commit_required', 'activation_allowed',
            'requires_green_mhc06_provenance', 'requires_state_build_receipt', 'state_runtime_revision',
            'state_patch_sha256', 'state_patched_tree', 'state_engine_sha256', 'runtime_receipt_sha256',
            'state_build_receipt_sha256', 'mhc06_provenance_sha256', 'files',
        ];
        $actualKeys = array_keys($staging);
        sort($keys);
        sort($actualKeys);
        if (($staging['schema'] ?? null) !== self::STAGING_SCHEMA
            || $actualKeys !== $keys
            || ($staging['phase'] ?? null) !== 'state_activation'
            || ($staging['host_id'] ?? null) !== '.87'
            || ($staging['private_package_commit_required'] ?? null) !== true
            || ($staging['activation_allowed'] ?? null) !== true
            || ($staging['requires_green_mhc06_provenance'] ?? null) !== true
            || ($staging['requires_state_build_receipt'] ?? null) !== true
            || ! $this->sha($staging['base_ref'] ?? null, 40)
            || ($staging['state_runtime_revision'] ?? null) !== self::STATE_RUNTIME
            || ($staging['state_patch_sha256'] ?? null) !== self::STATE_PATCH
            || ($staging['state_patched_tree'] ?? null) !== self::STATE_TREE
            || ! $this->sha($staging['state_engine_sha256'] ?? null)
            || ! $this->sha($staging['runtime_receipt_sha256'] ?? null)
            || ! $this->sha($staging['state_build_receipt_sha256'] ?? null)
            || ! $this->sha($staging['mhc06_provenance_sha256'] ?? null)
            || ! is_array($staging['files'] ?? null)
            || ($v2 ? count($staging['files']) !== 6 : ! in_array(count($staging['files']), [4, 6], true))) {
            throw new RuntimeException('MHC-08 State activation staging is not finalized.');
        }
        foreach ($staging['files'] as $file) {
            if (! is_array($file) || ! $this->sha($file['sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 State activation file identity is incomplete.');
            }
        }
    }

    /** @param array<string,mixed> $baseline */
    private function assertBaseline(array $baseline, bool $v2): void
    {
        $keys = [
            'schema', 'phase', 'host_id', 'captured_from', 'requires_exact_reverification',
            'host_checkpoint_required_before_transfer', 'checkpoint_root', 'staging_sha256',
            'executor_sha256', 'private_package_identity', 'state_build_receipt_sha256',
            'mhc06_provenance_sha256', 'files',
        ];
        $actualKeys = array_keys($baseline);
        sort($keys);
        sort($actualKeys);
        $files = $baseline['files'] ?? null;
        $rollbackCounts = array_count_values(is_array($files) ? array_map(
            static fn (mixed $file): string => (string) (is_array($file) ? ($file['rollback'] ?? '') : ''),
            $files,
        ) : []);
        $fileCount = is_array($files) ? count($files) : 0;
        $capturedFromExact = $v2
            ? ($baseline['captured_from'] ?? null) === 'MHC-08 v2 transfer preimage'
            : in_array($baseline['captured_from'] ?? null, [
                'MHC-08 build-stage transferred/reapplied',
                'MHC-08 finalized State controls with pre-compatibility coexistence controls',
            ], true);
        $checkpointExact = $v2
            ? ($baseline['checkpoint_root'] ?? null) === self::V2_CHECKPOINT_ROOT
            : in_array($baseline['checkpoint_root'] ?? null, [
                self::CHECKPOINT_ROOT,
                'C:\\ProgramData\\PLOS\\ModelHostController\\mhc08-k3\\state-activation-checkpoint-v2',
            ], true);
        $rollbackExact = $v2
            ? $fileCount === 6
                && ($rollbackCounts['restore_checkpoint'] ?? 0) === 3
                && ($rollbackCounts['unchanged_verify'] ?? 0) === 3
            : ($fileCount === 4
                && ($rollbackCounts['restore_checkpoint'] ?? 0) === 3
                && ($rollbackCounts['unchanged_verify'] ?? 0) === 1)
                || ($fileCount === 6
                    && ($rollbackCounts['restore_checkpoint'] ?? 0) === 5
                    && ($rollbackCounts['unchanged_verify'] ?? 0) === 1);
        if ($actualKeys !== $keys
            || ($baseline['schema'] ?? null) !== self::BASELINE_SCHEMA
            || ($baseline['phase'] ?? null) !== 'state_activation'
            || ($baseline['host_id'] ?? null) !== '.87'
            || ! $capturedFromExact
            || ($baseline['requires_exact_reverification'] ?? null) !== true
            || ($baseline['host_checkpoint_required_before_transfer'] ?? null) !== true
            || ! $checkpointExact
            || ! $this->sha($baseline['staging_sha256'] ?? null)
            || ! $this->sha($baseline['executor_sha256'] ?? null)
            || ! $this->sha($baseline['private_package_identity'] ?? null, 40)
            || ! $this->sha($baseline['state_build_receipt_sha256'] ?? null)
            || ! $this->sha($baseline['mhc06_provenance_sha256'] ?? null)
            || ! is_array($files) || ! $rollbackExact) {
            throw new RuntimeException('MHC-08 State activation baseline is not finalized.');
        }
        $expectedDestinations = [
            'C:\\PLOS\\ModelHostController\\config\\model-host-controller-mhc08-k3.87.json',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller-mhc08-k3.ps1',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller.ps1',
            'C:\\PLOS\\ModelHostController\\scripts\\plos-model-host-controller-service.ps1',
            'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-context-gate.ps1',
            'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-alt-qwen-sidecar.ps1',
        ];
        $expectedRollback = $v2
            ? ['unchanged_verify', 'restore_checkpoint', 'restore_checkpoint', 'restore_checkpoint', 'unchanged_verify', 'unchanged_verify']
            : ($fileCount === 6
                ? ['unchanged_verify', 'restore_checkpoint', 'restore_checkpoint', 'restore_checkpoint', 'restore_checkpoint', 'restore_checkpoint']
                : ['restore_checkpoint', 'unchanged_verify', 'restore_checkpoint', 'restore_checkpoint']);
        foreach (array_values($files) as $index => $file) {
            if (! is_array($file)
                || ($file['destination'] ?? null) !== ($expectedDestinations[$index] ?? null)
                || ($file['present'] ?? null) !== true
                || ($file['rollback'] ?? null) !== ($expectedRollback[$index] ?? null)
                || ! $this->sha($file['sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 State activation baseline file contract drifted.');
            }
        }
    }

    /** @param array<string,mixed> $build @param array<string,mixed> $staging */
    private function assertBuild(array $build, array $staging, string $repo, bool $v2): void
    {
        $keys = [
            'schema', 'host_id', 'result', 'runtime_profile', 'runtime_revision', 'base_commit', 'base_tree',
            'patch_sha256', 'patched_tree', 'engine_path', 'engine_sha256', 'runtime_receipt_path',
            'runtime_receipt_sha256', 'cpu_only', 'tests_passed', 'reproducible', 'runtime_verified',
            'process_count', 'listener_count', 'ingress_open', 'lease_active', 'content_free',
            'contains_prompt_or_response_content', 'completed_at', 'initial_transfer_receipt_sha256',
            'initial_transfer_rollback_proof_sha256', 'initial_baseline_sha256', 'initial_staging_sha256',
            'initial_private_package_identity', 'initial_transfer_executor_sha256', 'state_build_executor_sha256',
            'mhc06_aggregate_sha256', 'mhc06_embedded_aggregate_sha256', 'mhc06_provenance_sha256',
            'mhc06_deployed_full_ref', 'mhc06_soak_custody_sha256', 'mhc06_soak_deployed_full_ref',
            'mhc06_envelope_deployed_full_ref', 'mhc06_legacy_provenance_sha256', 'rollback_reapply_proven',
            'mhc06_custody_executor_sha256', 'mhc06_custody_private_package_identity',
            'mhc06_custody_production_root_sha256',
        ];
        $actualKeys = array_keys($build);
        sort($keys);
        sort($actualKeys);
        $exact = ($build['schema'] ?? null) === self::BUILD_SCHEMA
            && $actualKeys === $keys
            && ($build['host_id'] ?? null) === '.87'
            && ($build['result'] ?? null) === 'green'
            && ($build['runtime_profile'] ?? null) === 'State'
            && ($build['runtime_revision'] ?? null) === self::STATE_RUNTIME
            && ($build['base_commit'] ?? null) === self::BASE_COMMIT
            && ($build['base_tree'] ?? null) === self::BASE_TREE
            && ($build['patch_sha256'] ?? null) === self::STATE_PATCH
            && ($build['patched_tree'] ?? null) === self::STATE_TREE
            && ($build['cpu_only'] ?? null) === true
            && ($build['tests_passed'] ?? null) === true
            && ($build['reproducible'] ?? null) === true
            && ($build['runtime_verified'] ?? null) === true
            && ($build['process_count'] ?? null) === 0
            && ($build['listener_count'] ?? null) === 0
            && ($build['ingress_open'] ?? null) === false
            && ($build['lease_active'] ?? null) === false
            && ($build['content_free'] ?? null) === true
            && ($build['contains_prompt_or_response_content'] ?? null) === false
            && ($build['rollback_reapply_proven'] ?? null) === true
            && $this->sha($build['mhc06_deployed_full_ref'] ?? null, 40)
            && $this->sha($build['mhc06_soak_custody_sha256'] ?? null)
            && $this->sha($build['mhc06_soak_deployed_full_ref'] ?? null, 40)
            && $this->sha($build['mhc06_envelope_deployed_full_ref'] ?? null, 40)
            && (! $v2 || ($build['mhc06_envelope_deployed_full_ref'] ?? null) === ($build['mhc06_deployed_full_ref'] ?? null))
            && $this->sha($build['mhc06_legacy_provenance_sha256'] ?? null)
            && $this->sha($build['mhc06_custody_executor_sha256'] ?? null)
            && $this->sha($build['mhc06_custody_production_root_sha256'] ?? null)
            && is_string($build['mhc06_custody_private_package_identity'] ?? null)
            && preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $build['mhc06_custody_private_package_identity']) === 1
            && $this->sha($build['engine_sha256'] ?? null)
            && $this->sha($build['runtime_receipt_sha256'] ?? null)
            && ($build['engine_sha256'] ?? null) === $staging['state_engine_sha256']
            && ($build['runtime_receipt_sha256'] ?? null) === $staging['runtime_receipt_sha256'];
        foreach ([
            'initial_transfer_receipt_sha256', 'initial_transfer_rollback_proof_sha256',
            'initial_baseline_sha256', 'initial_staging_sha256', 'initial_transfer_executor_sha256',
            'state_build_executor_sha256', 'mhc06_aggregate_sha256', 'mhc06_embedded_aggregate_sha256',
            'mhc06_provenance_sha256',
        ] as $field) {
            $exact = $exact && $this->sha($build[$field] ?? null);
        }
        $suffix = $v2 ? '-V2' : '';
        $scriptSuffix = $v2 ? '-v2' : '';
        $initialStagingPath = $repo.'/scripts/patches/colibri/k3-selective-v162/MHC-08'.$suffix.'-HOST-STAGING.json';
        $initialBaselinePath = $repo.'/scripts/patches/colibri/k3-selective-v162/MHC-08'.$suffix.'-HOST-BASELINE.json';
        $initialExecutorPath = $repo.'/scripts/plos-model-host-controller-mhc08-transfer'.$scriptSuffix.'.ps1';
        $stateBuildExecutorPath = $repo.'/scripts/plos-kimi-k3-mhc08-state-build'.$scriptSuffix.'.ps1';
        $initialStaging = $this->read($initialStagingPath);
        $initialBaseline = $this->read($initialBaselinePath);
        $exact = $exact
            && ($build['initial_staging_sha256'] ?? null) === $this->fileSha($initialStagingPath)
            && ($build['initial_baseline_sha256'] ?? null) === $this->fileSha($initialBaselinePath)
            && ($build['initial_transfer_executor_sha256'] ?? null) === $this->fileSha($initialExecutorPath)
            && ($build['state_build_executor_sha256'] ?? null) === $this->fileSha($stateBuildExecutorPath)
            && ($build['initial_private_package_identity'] ?? null) === ($initialStaging['base_ref'] ?? null)
            && ($build['initial_private_package_identity'] ?? null) === ($initialBaseline['private_package_identity'] ?? null)
            && ($initialBaseline['staging_sha256'] ?? null) === $this->fileSha($initialStagingPath)
            && ($initialBaseline['executor_sha256'] ?? null) === $this->fileSha($initialExecutorPath);
        $exact = $exact
            && is_string($build['engine_path'] ?? null) && $build['engine_path'] !== ''
            && is_string($build['runtime_receipt_path'] ?? null) && $build['runtime_receipt_path'] !== ''
            && is_string($build['completed_at'] ?? null) && strtotime($build['completed_at']) !== false;
        if (! $exact) {
            throw new RuntimeException('MHC-08 State build evidence is not exact and green.');
        }
    }

    /** @param array<string,mixed> $provenance */
    private function assertProvenance(array $provenance): void
    {
        $keys = [
            'schema', 'generated_at', 'soak_custody_sha256', 'soak_deployed_full_ref',
            'envelope_deployed_full_ref', 'aggregate_json', 'aggregate_sha256',
            'promotion_full_ref', 'promotion_ancestor', 'soak_ancestor', 'policy_revision',
            'policy_sha256', 'policy_updated_at', 'policy_predates_soak', 'custody_captured_at',
            'custody_frozen_log_sha256', 'custody_frozen_log_bytes', 'custody_frozen_log_lines',
            'custody_sample_count', 'legacy_provenance_sha256', 'content_free', 'contains_prompt_or_response_content', 'result',
            'custody_executor_sha256', 'custody_private_package_identity', 'custody_production_root_sha256',
        ];
        $actualKeys = array_keys($provenance);
        sort($keys);
        sort($actualKeys);
        $aggregate = json_decode((string) ($provenance['aggregate_json'] ?? ''), true);
        $aggregateKeys = [
            'schema', 'window_seconds', 'evidence_lookback_seconds', 'cutoff_epoch', 'samples',
            'green_samples', 'blocked_samples', 'invalid_samples', 'minimum_samples', 'span_seconds',
            'minimum_span_seconds', 'cadence_ok', 'target_counts', 'last_observed_at', 'content_free',
            'contains_prompt_or_response_content', 'result',
        ];
        $actualAggregateKeys = is_array($aggregate) ? array_keys($aggregate) : [];
        sort($aggregateKeys);
        sort($actualAggregateKeys);
        $targetCounts = is_array($aggregate['target_counts'] ?? null) ? $aggregate['target_counts'] : [];
        $targetCountsExact = $targetCounts !== [] && ! array_is_list($targetCounts);
        $targetCountTotal = 0;
        foreach ($targetCounts as $target => $count) {
            if (! is_string($target)
                || ! in_array($target, ['idle', app(SystemConfigService::class)->llmRoutingValue('qwen_bench', 'instance_id'), app(SystemConfigService::class)->llmRoutingValue('knowledge_graph', 'managed.target_id'), app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')], true)
                || ! is_int($count)
                || $count <= 0) {
                $targetCountsExact = false;
                break;
            }
            $targetCountTotal += $count;
        }
        $aggregateProfile = match ($aggregate['schema'] ?? null) {
            'plos.model_host_controller.mhc06_soak_aggregate.v1' => [
                'window_seconds' => 86400,
                'evidence_lookback_seconds' => 90000,
                'minimum_samples' => 280,
                'minimum_span_seconds' => 86400,
            ],
            'plos.model_host_controller.mhc06_soak_aggregate.v2' => [
                'window_seconds' => 36000,
                'evidence_lookback_seconds' => 39600,
                'minimum_samples' => 117,
                'minimum_span_seconds' => 36000,
            ],
            default => null,
        };
        $aggregateProfileExact = is_array($aggregateProfile)
            && ($aggregate['window_seconds'] ?? null) === $aggregateProfile['window_seconds']
            && ($aggregate['evidence_lookback_seconds'] ?? null) === $aggregateProfile['evidence_lookback_seconds']
            && ($aggregate['minimum_samples'] ?? null) === $aggregateProfile['minimum_samples']
            && ($aggregate['minimum_span_seconds'] ?? null) === $aggregateProfile['minimum_span_seconds'];
        $minimumSamples = is_array($aggregateProfile) ? $aggregateProfile['minimum_samples'] : PHP_INT_MAX;
        $minimumSpan = is_array($aggregateProfile) ? $aggregateProfile['minimum_span_seconds'] : PHP_INT_MAX;
        if ($actualKeys !== $keys
            || ($provenance['schema'] ?? null) !== self::PROVENANCE_SCHEMA
            || ($provenance['result'] ?? null) !== 'green'
            || ($provenance['promotion_ancestor'] ?? null) !== true
            || ($provenance['soak_ancestor'] ?? null) !== true
            || ! $this->sha($provenance['soak_custody_sha256'] ?? null)
            || ! $this->sha($provenance['soak_deployed_full_ref'] ?? null, 40)
            || ! $this->sha($provenance['envelope_deployed_full_ref'] ?? null, 40)
            || ($provenance['promotion_full_ref'] ?? null) !== self::PROMOTION_REF
            || ($provenance['policy_revision'] ?? null) !== 'MHC-06-automatic-v1'
            || ($provenance['policy_sha256'] ?? null) !== self::POLICY_SHA256
            || ! is_string($provenance['generated_at'] ?? null) || strtotime($provenance['generated_at']) === false
            || ! is_string($provenance['policy_updated_at'] ?? null) || strtotime($provenance['policy_updated_at']) === false
            || ! is_string($provenance['custody_captured_at'] ?? null) || strtotime($provenance['custody_captured_at']) === false
            || ! $this->sha($provenance['custody_frozen_log_sha256'] ?? null)
            || ! $this->sha($provenance['legacy_provenance_sha256'] ?? null)
            || ! $this->sha($provenance['custody_executor_sha256'] ?? null)
            || ! $this->sha($provenance['custody_production_root_sha256'] ?? null)
            || ! is_string($provenance['custody_private_package_identity'] ?? null)
            || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $provenance['custody_private_package_identity']) !== 1
            || ! is_int($provenance['custody_frozen_log_bytes'] ?? null) || $provenance['custody_frozen_log_bytes'] <= 0
            || ! is_int($provenance['custody_frozen_log_lines'] ?? null) || $provenance['custody_frozen_log_lines'] <= 0
            || ! is_int($provenance['custody_sample_count'] ?? null) || $provenance['custody_sample_count'] < $minimumSamples
            || ($provenance['policy_predates_soak'] ?? null) !== true
            || ($provenance['content_free'] ?? null) !== true
            || ($provenance['contains_prompt_or_response_content'] ?? null) !== false
            || ! is_string($provenance['aggregate_json'] ?? null)
            || ! $this->sha($provenance['aggregate_sha256'] ?? null)
            || hash('sha256', $provenance['aggregate_json']) !== $provenance['aggregate_sha256']
            || ! is_array($aggregate)
            || $actualAggregateKeys !== $aggregateKeys
            || ! $aggregateProfileExact
            || ($aggregate['result'] ?? null) !== 'green'
            || ! is_int($aggregate['cutoff_epoch'] ?? null) || $aggregate['cutoff_epoch'] <= 0
            || ! is_int($aggregate['samples'] ?? null) || $aggregate['samples'] < $minimumSamples
            || ! is_int($aggregate['green_samples'] ?? null) || $aggregate['green_samples'] !== $aggregate['samples']
            || ! is_int($aggregate['blocked_samples'] ?? null) || $aggregate['blocked_samples'] !== 0
            || ! is_int($aggregate['invalid_samples'] ?? null) || $aggregate['invalid_samples'] !== 0
            || ! is_int($aggregate['span_seconds'] ?? null) || $aggregate['span_seconds'] < $minimumSpan
            || ($aggregate['cadence_ok'] ?? null) !== true
            || ! $targetCountsExact
            || $targetCountTotal !== ($aggregate['samples'] ?? null)
            || ($provenance['custody_sample_count'] ?? null) !== ($aggregate['samples'] ?? null)
            || ($aggregate['content_free'] ?? null) !== true
            || ($aggregate['contains_prompt_or_response_content'] ?? null) !== false
            || ! is_string($aggregate['last_observed_at'] ?? null)
            || strtotime($aggregate['last_observed_at']) === false) {
            throw new RuntimeException('MHC-06 provenance is not exact and green.');
        }
        $policyUpdatedAt = strtotime($provenance['policy_updated_at']);
        $lastObservedAt = strtotime($aggregate['last_observed_at']);
        $generatedAt = strtotime($provenance['generated_at']);
        $custodyCapturedAt = strtotime($provenance['custody_captured_at']);
        if ($policyUpdatedAt === false || $lastObservedAt === false || $generatedAt === false || $custodyCapturedAt === false
            || $custodyCapturedAt > $generatedAt
            || $policyUpdatedAt > $lastObservedAt - $aggregate['span_seconds']) {
            throw new RuntimeException('MHC-06 policy does not predate the qualified soak.');
        }
    }

    /** @return array<string,mixed> */
    private function read(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Required MHC-08 tracked evidence is missing.');
        }
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $value = json_decode($raw, true);
        if (! is_array($value)) {
            throw new RuntimeException('Required MHC-08 tracked evidence is invalid.');
        }

        return $value;
    }

    /** @return array{v2:bool,staging:string,baseline:string,build:string,provenance:string} */
    private function evidenceProfile(string $root): array
    {
        $v2Staging = $root.'/MHC-08-V2-STATE-ACTIVATION-STAGING.json';
        if (is_file($v2Staging) && ! is_link($v2Staging)) {
            $candidate = $this->read($v2Staging);
            if (($candidate['phase'] ?? null) === 'state_activation') {
                return [
                    'v2' => true,
                    'staging' => 'MHC-08-V2-STATE-ACTIVATION-STAGING.json',
                    'baseline' => 'MHC-08-V2-STATE-ACTIVATION-BASELINE.json',
                    'build' => 'MHC-08-V2-STATE-BUILD-RECEIPT.json',
                    'provenance' => 'MHC-06-V2-PROVENANCE.json',
                ];
            }
            if (($candidate['phase'] ?? null) !== 'pending_state_build') {
                throw new RuntimeException('MHC-08 v2 evidence profile state is invalid.');
            }
        }

        return [
            'v2' => false,
            'staging' => 'MHC-08-STATE-ACTIVATION-STAGING.json',
            'baseline' => 'MHC-08-STATE-ACTIVATION-BASELINE.json',
            'build' => 'MHC-08-STATE-BUILD-RECEIPT.json',
            'provenance' => 'MHC-06-PROVENANCE.json',
        ];
    }

    private function root(): string
    {
        return rtrim($this->evidenceRoot ?? base_path('scripts/patches/colibri/k3-selective-v162'), '/');
    }

    private function fileSha(string $path): ?string
    {
        return is_file($path) && ! is_link($path) ? hash_file('sha256', $path) : null;
    }

    /** @param array<string,mixed> $staging */
    private function assertConfigAndControllerChain(string $repo, array $staging): void
    {
        $configPath = $repo.'/config/model-host-controller-mhc08-k3.87.json';
        $controllerPath = $repo.'/scripts/plos-model-host-controller-mhc08-k3.ps1';
        $mainPath = $repo.'/scripts/plos-model-host-controller.ps1';
        $servicePath = $repo.'/scripts/plos-model-host-controller-service.ps1';
        $config = $this->read($configPath);
        $controller = is_array($config['controller'] ?? null) ? $config['controller'] : [];
        $runtime = is_array($config['runtime'] ?? null) ? $config['runtime'] : [];
        $guards = is_array($config['resource_guards'] ?? null) ? $config['resource_guards'] : [];
        $expectedGuards = [
            'minimum_free_physical_bytes' => 45097156608,
            'minimum_session_free_physical_bytes' => 12884901888,
            'minimum_commit_headroom_bytes' => 8589934592,
            'drive_c_free_bytes' => 42949672960,
            'drive_d_free_bytes' => 42949672960,
            'maximum_pagefile_growth_mib' => 3072,
            'temperature_mode' => 'observation_only',
        ];
        // Retained v1 receipts validate their exact historical manifest, never a changed host.
        // Current source/manifest hash binding remains mandatory for either shape.
        if (! array_key_exists('temperature_mode', $guards)) {
            unset($expectedGuards['temperature_mode']);
            $expectedGuards = array_slice($expectedGuards, 0, 5, true) + [
                'admission_thermal_celsius' => 80,
                'abort_thermal_celsius' => 85,
                'maximum_disk_thermal_celsius' => 70,
            ] + array_slice($expectedGuards, 5, null, true);
        }
        if (array_keys($config) !== [
            'schema', 'revision', 'host_id', 'execution_mode', 'automatic_selection_enabled',
            'normal_routing_enabled', 'concurrent_inference_enabled', 'tools_enabled', 'fallback_enabled',
            'explicit_operator_required', 'state_root', 'controller', 'runtime', 'resource_guards',
            'snapshot_targets',
        ]
            || ($config['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_manifest.v1'
            || ($config['revision'] ?? null) !== 'MHC-08-k3-explicit-v1'
            || ($config['host_id'] ?? null) !== '.87'
            || ($config['execution_mode'] ?? null) !== 'serial'
            || ($config['automatic_selection_enabled'] ?? null) !== false
            || ($config['normal_routing_enabled'] ?? null) !== false
            || ($config['concurrent_inference_enabled'] ?? null) !== false
            || ($config['tools_enabled'] ?? null) !== false
            || ($config['fallback_enabled'] ?? null) !== false
            || ($config['explicit_operator_required'] ?? null) !== true
            || ($config['state_root'] ?? null) !== 'C:\\ProgramData\\PLOS\\ModelHostController\\mhc08-k3'
            || ($config['snapshot_targets'] ?? null) !== array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
            || $guards !== $expectedGuards
            || ($runtime['profile'] ?? null) !== 'State'
            || ($runtime['runtime_revision'] ?? null) !== self::STATE_RUNTIME
            || ($runtime['engine_sha256'] ?? null) !== $staging['state_engine_sha256']
            || ($runtime['patch_sha256'] ?? null) !== self::STATE_PATCH
            || ($runtime['patched_tree'] ?? null) !== self::STATE_TREE
            || ($runtime['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($runtime['model_identity'] ?? null) !== 'revision:9f62e4e9fffbd0a83ddd60e1c209d828994b3569'
            || ($runtime['topk_limit'] ?? null) !== 16
            || ($runtime['vk'] ?? null) !== 0
            || ($runtime['cuda'] ?? null) !== 0
            || ($runtime['mmap'] ?? null) !== 1
            || ($runtime['mmap_layer'] ?? null) !== 1
            || ($controller['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($controller['task_name'] ?? null) !== 'PLOS-KimiK3Sidecar8001'
            || ($controller['candidate_task_name'] ?? null) !== 'PLOS-KimiK3SelectiveCanary8001'
            || ($controller['task_user'] ?? null) !== 'SYSTEM'
            || ($controller['runner_path'] ?? null) !== 'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-selective-run.ps1'
            || ($controller['runner_sha256'] ?? null) !== $this->fileSha($repo.'/scripts/plos-kimi-k3-selective-run.ps1')
            || ($controller['ingress_path'] ?? null) !== 'C:\\PLOS\\KimiK3\\scripts\\plos-kimi-k3-bench-ingress.ps1'
            || ($controller['ingress_sha256'] ?? null) !== $this->fileSha($repo.'/scripts/plos-kimi-k3-bench-ingress.ps1')
            || ($controller['listen_address'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host')
            || ($controller['connect_address'] ?? null) !== '127.0.0.1'
            || ($controller['production_remote'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'production_remote')
            || ($controller['port'] ?? null) !== (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            || ($controller['health_path'] ?? null) !== '/health'
            || ($controller['maximum_request_tokens'] ?? null) !== 128
            || ($controller['maximum_session_minutes'] ?? null) !== 120
            || ($controller['lease_ttl_seconds'] ?? null) !== 25200) {
            throw new RuntimeException('MHC-08 State config posture or resource guards drifted.');
        }
        $controllerSha = $this->fileSha($controllerPath);
        $configSha = $this->fileSha($configPath);
        $mainSha = $this->fileSha($mainPath);
        $main = (string) file_get_contents($mainPath);
        $service = (string) file_get_contents($servicePath);
        if ($this->powerShellPin($main, 'expectedMhc08ScriptSha256') !== $controllerSha
            || $this->powerShellPin($main, 'expectedMhc08ManifestSha256') !== $configSha
            || $this->powerShellPin($service, 'expectedControllerSha256') !== $mainSha
            || $this->powerShellPin($service, 'expectedMhc08ControllerSha256') !== $controllerSha
            || $this->powerShellPin($service, 'expectedMhc08ManifestSha256') !== $configSha) {
            throw new RuntimeException('MHC-08 State controller hash chain drifted.');
        }
    }

    private function powerShellPin(string $script, string $name): ?string
    {
        return preg_match('/^\\$'.preg_quote($name, '/')." = '([a-f0-9]{64})'$/m", $script, $match) === 1
            ? $match[1]
            : null;
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @param array<string,mixed> $config */
    private function healthEndpoint(array $config): bool
    {
        $health = parse_url((string) ($config['health_base_url'] ?? ''));

        return is_array($health)
            && ($health['scheme'] ?? null) === 'http'
            && ($health['host'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host')
            && (int) ($health['port'] ?? 0) === (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            && (! isset($health['path']) || $health['path'] === '')
            && ! isset($health['query']) && ! isset($health['fragment'])
            && ($config['health_path'] ?? null) === '/health';
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }
}
