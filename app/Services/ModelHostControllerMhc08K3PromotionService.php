<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Final, reversible PLOS-side MHC-08 gate.
 *
 * Host State transfer is intentionally not performed here.  This service
 * consumes the finalized tracked evidence, keeps K3 closed while State is
 * staged, enters a synthetic-only acceptance policy with the schedule disabled,
 * then atomically enables durable explicit admission after the real smoke.
 */
class ModelHostControllerMhc08K3PromotionService
{
    public const RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_promotion.v1';

    public const STAGE_RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_state_stage.v1';

    private const SECTION = 'ai';

    private const PROMOTION_JOURNAL = 'model_host_controller_mhc08_k3_promotion_journal';

    private const STAGE_JOURNAL = 'model_host_controller_mhc08_k3_state_stage_journal';

    private const POLICY_KEY = 'model_host_controller_mhc08_k3_policy';

    private const WORKER_NAME = 'model_host_controller_mhc08_k3_worker';

    private const ADMISSION_JOURNAL = 'model_host_controller_mhc08_k3_admission_journal';

    private const PROFILE = 'deep_offline_kimi_k3';

    private const CANARY_SCHEMA = 'plos.model_host_controller.mhc08_k3_canary_batch.v1';

    private const CANCELLATION_SCHEMA = 'plos.model_host_controller.mhc08_k3_cancellation_qualification.v1';

    private const HOST_CLEAN_SCHEMA = 'plos.model_host_controller.mhc08_k3_host_clean_qualification.v1';

    private const FINAL_SMOKE_SCHEMA = 'plos.model_host_controller.mhc08_k3_explicit_job_smoke.v1';

    public function __construct(
        private readonly ModelHostControllerMhc08K3PolicyService $policy,
        private readonly ?ModelHostControllerMhc08K3StateContractService $state = null,
        private readonly ?string $evidenceRoot = null,
        private readonly ?ModelHostControllerClient $controller = null,
    ) {}

    /** Stage State-qualified, still-closed provider/profile rows. */
    public function stageState(): array
    {
        $contract = $this->stateContract()->finalized();
        $this->requireTables();
        $this->assertCustodyClean();

        return DB::transaction(function () use ($contract): array {
            $this->assertNoJournal(self::STAGE_JOURNAL);
            $this->assertNoJournal(self::PROMOTION_JOURNAL);
            $this->assertNoJournal(self::ADMISSION_JOURNAL);
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            $policyRow = $this->policyRow(true);
            $worker = $this->worker(true);
            $inactive = $this->policy->normalize($this->decode($policyRow['config_value'] ?? null));
            if ($inactive['revision'] !== ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION
                || $inactive['enabled'] !== false || (int) ($worker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 State staging requires the exact inactive policy and disabled worker.');
            }
            $this->assertMappedClosedPreimage($provider, $profile);

            $config = $this->decode($provider['config'] ?? null);
            $config['admission'] = array_replace(is_array($config['admission'] ?? null) ? $config['admission'] : [], [
                'phase' => 'MHC-08-State-staged',
                'runtime' => $contract['runtime_revision'],
                'runtime_commit' => ModelHostControllerMhc08K3StateContractService::BASE_COMMIT,
                'runtime_profile' => 'State',
                'runtime_revision' => $contract['runtime_revision'],
                'patch_sha256' => $contract['patch_sha256'],
                'patched_tree' => $contract['patched_tree'],
                'engine_sha256' => $contract['engine_sha256'],
                'runtime_receipt_sha256' => $contract['runtime_receipt_sha256'],
                'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
                'mhc06_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                'state_staged' => true,
            ]);
            $metadata = $this->decode($profile['metadata'] ?? null);
            $metadata['runtime'] = array_replace(is_array($metadata['runtime'] ?? null) ? $metadata['runtime'] : [], [
                'profile' => 'State',
                'runtime_revision' => $contract['runtime_revision'],
                'engine_sha256' => $contract['engine_sha256'],
                'patch_sha256' => $contract['patch_sha256'],
                'patched_tree' => $contract['patched_tree'],
            ]);
            $metadata['admission_state'] = 'state_staged';

            $journal = [
                'schema' => 'plos.model_host_controller.mhc08_k3_state_stage_journal.v1',
                'provider_id' => (int) $provider['id'],
                'profile_id' => (int) $profile['id'],
                'provider' => $provider,
                'profile' => $profile,
                'provider_sha256' => $this->hash($provider),
                'profile_sha256' => $this->hash($profile),
                'evidence' => [
                    'base_ref' => $contract['base_ref'],
                    'staging_sha256' => $contract['staging_sha256'],
                    'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
                    'mhc06_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                ],
            ];
            $this->insertJournal(self::STAGE_JOURNAL, $journal);
            $this->exactUpdate('llm_instances', (int) $provider['id'], [
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->exactUpdate('llm_model_profiles', (int) $profile['id'], [
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            $stagedProvider = $this->provider(true);
            $stagedProfile = $this->profile(true);
            if ((int) ($stagedProvider['is_active'] ?? 1) !== 0
                || (string) ($stagedProvider['routability'] ?? '') !== 'bench_only'
                || (int) ($stagedProfile['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 State staging crossed the closed-provider boundary.');
            }
            $this->stateContract()->assertClosedProvider(
                $stagedProvider,
                $this->decode($stagedProvider['config'] ?? null),
            );
            $this->stateContract()->assertClosedProfile($stagedProfile);
            $journal['post_provider_sha256'] = $this->hash($stagedProvider);
            $journal['post_profile_sha256'] = $this->hash($stagedProfile);
            $stageJournal = $this->journal(self::STAGE_JOURNAL, true);
            if ($stageJournal === null || $this->updateJournal(self::STAGE_JOURNAL, (int) $stageJournal['id'], $journal) !== 1) {
                throw new RuntimeException('MHC-08 State-stage postimage journal update was not exact.');
            }

            $this->clearCaches();

            return [
                'schema' => self::STAGE_RECEIPT_SCHEMA,
                'result' => 'staged',
                'provider_closed' => true,
                'profile_gated' => true,
                'policy_inactive' => true,
                'worker_disabled' => true,
                'state_engine_sha256' => $contract['engine_sha256'],
                'staging_sha256' => $contract['staging_sha256'],
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ];
        });
    }

    /** Enter synthetic-only acceptance mode; durable worker scheduling remains disabled. */
    public function promote(
        ?string $canaryReceipt = null,
        ?string $cancellationReceipt = null,
        ?string $hostReceipt = null,
    ): array {
        $contract = $this->stateContract()->finalized();
        $this->assertEvidenceReceipt($canaryReceipt, self::CANARY_SCHEMA, 'canary', $contract);
        $this->assertEvidenceReceipt(
            $cancellationReceipt,
            self::CANCELLATION_SCHEMA,
            'cancellation',
            $contract,
        );
        $this->assertEvidenceReceipt($hostReceipt, self::HOST_CLEAN_SCHEMA, 'host', $contract);
        $gatePaths = [
            'canary_sha256' => realpath((string) $canaryReceipt),
            'cancellation_sha256' => realpath((string) $cancellationReceipt),
            'host_clean_sha256' => realpath((string) $hostReceipt),
        ];
        if (in_array(false, $gatePaths, true)) {
            throw new RuntimeException('MHC-08 promotion evidence identity is unavailable.');
        }
        $gateHashes = array_map(static fn (string $path): string => hash_file('sha256', $path), $gatePaths);
        $hostEvidence = json_decode((string) file_get_contents($gatePaths['host_clean_sha256']), true);
        if (! is_array($hostEvidence)
            || ! $this->sha($hostEvidence['qualification_state_sha256'] ?? null)
            || ! $this->sha($hostEvidence['final_state_sha256'] ?? null)) {
            throw new RuntimeException('MHC-08 promotion State evidence binding is unavailable.');
        }
        $this->requireTables();
        $this->assertCustodyClean();

        return DB::transaction(function () use ($contract, $gatePaths, $gateHashes, $hostEvidence): array {
            foreach ($gatePaths as $key => $path) {
                if (hash_file('sha256', $path) !== $gateHashes[$key]) {
                    throw new RuntimeException('MHC-08 promotion evidence changed after validation.');
                }
            }
            $this->assertNoJournal(self::PROMOTION_JOURNAL);
            $this->assertNoJournal(self::ADMISSION_JOURNAL);
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            $stageJournal = $this->journal(self::STAGE_JOURNAL, true);
            if ($stageJournal === null) {
                throw new RuntimeException('MHC-08 promotion requires the owned State-stage journal.');
            }
            $stageSnapshot = $this->decode($stageJournal['config_value'] ?? null);
            $stageEvidence = $stageSnapshot['evidence'] ?? [];
            if (($stageSnapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_stage_journal.v1'
                || (int) ($stageSnapshot['provider_id'] ?? 0) !== (int) ($provider['id'] ?? -1)
                || (int) ($stageSnapshot['profile_id'] ?? 0) !== (int) ($profile['id'] ?? -1)
                || ($stageEvidence['base_ref'] ?? null) !== $contract['base_ref']
                || ($stageEvidence['staging_sha256'] ?? null) !== $contract['staging_sha256']
                || ($stageEvidence['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ($stageEvidence['mhc06_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
                || ($stageSnapshot['post_provider_sha256'] ?? null) !== $this->hash($provider)
                || ($stageSnapshot['post_profile_sha256'] ?? null) !== $this->hash($profile)) {
                throw new RuntimeException('MHC-08 State-stage ownership or evidence binding drifted.');
            }
            $this->stateContract()->assertClosedProvider($provider, $this->decode($provider['config'] ?? null));
            $this->stateContract()->assertClosedProfile($profile);
            $policyRow = $this->policyRow(true);
            $worker = $this->worker(true);
            $inactive = $this->policy->normalize($this->decode($policyRow['config_value'] ?? null));
            if ($inactive['revision'] !== ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION
                || $inactive['enabled'] !== false
                || (int) ($worker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 promotion requires the exact inactive policy and worker preimage.');
            }

            $acceptance = $inactive;
            $acceptance['revision'] = ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION;
            $acceptance['enabled'] = true;
            $acceptance = $this->policy->normalize($acceptance);
            $preimages = [
                'policy' => $policyRow,
                'worker' => $worker,
                'provider_sha256' => $this->hash($provider),
                'profile_sha256' => $this->hash($profile),
            ];
            $attemptId = (string) Str::uuid();
            $promotedAt = now()->toIso8601String();
            $this->insertJournal(self::PROMOTION_JOURNAL, [
                'schema' => 'plos.model_host_controller.mhc08_k3_promotion_journal.v1',
                'phase' => 'promoted_pending_smoke',
                'attempt_id' => $attemptId,
                'promoted_at' => $promotedAt,
                'finalized_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                'qualification_state_sha256' => $hostEvidence['qualification_state_sha256'],
                'final_state_sha256' => $hostEvidence['final_state_sha256'],
                'preimages' => $preimages,
                'post_policy_sha256' => hash('sha256', $this->canonical($acceptance)),
                'post_provider_sha256' => $preimages['provider_sha256'],
                'post_profile_sha256' => $preimages['profile_sha256'],
                'evidence' => [
                    'base_ref' => $contract['base_ref'],
                    'staging_sha256' => $contract['staging_sha256'],
                    'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
                    'mhc06_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                    ...$gateHashes,
                ],
            ]);
            $this->exactUpdate('system_configs', (int) $policyRow['id'], [
                'config_value' => json_encode($acceptance, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

            $afterPolicy = $this->policyRow(true);
            $afterWorker = $this->worker(true);
            if ($this->hash($this->policy->normalize($this->decode($afterPolicy['config_value'] ?? null)))
                !== hash('sha256', $this->canonical($acceptance))
                || (int) ($afterWorker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 promotion postimage verification failed.');
            }
            $promotionJournal = $this->journal(self::PROMOTION_JOURNAL, true);
            if ($promotionJournal === null) {
                throw new RuntimeException('MHC-08 promotion journal disappeared before postimage binding.');
            }
            $promotionSnapshot = $this->decode($promotionJournal['config_value'] ?? null);
            $promotionSnapshot['post_policy_control_sha256'] = $this->hash($this->policyControl($afterPolicy));
            $promotionSnapshot['post_worker_control_sha256'] = $this->hash($this->workerControl($afterWorker));
            $promotionSnapshot['acceptance_context_sha256'] = $this->acceptanceContextSha($promotionSnapshot);
            if ($this->updateJournal(
                self::PROMOTION_JOURNAL,
                (int) $promotionJournal['id'],
                $promotionSnapshot,
            ) !== 1) {
                throw new RuntimeException('MHC-08 promotion postimage journal update was not exact.');
            }

            return $this->receipt('promoted', [
                'policy_enabled' => true,
                'policy_revision' => ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION,
                'normal_explicit_admission_enabled' => false,
                'worker_enabled' => false,
                'worker_enablement_pending_final_smoke' => true,
                'provider_closed' => true,
                'profile_gated' => true,
                'automatic_selection_enabled' => false,
                'normal_routing' => false,
                'tools_enabled' => false,
                'fallback_enabled' => false,
                'concurrent_inference_enabled' => false,
                'runtime_profile' => $contract['runtime_profile'],
                'runtime_revision' => $contract['runtime_revision'],
                'state_patch_sha256' => $contract['patch_sha256'],
                'state_patched_tree' => $contract['patched_tree'],
                'state_engine_sha256' => $contract['engine_sha256'],
                'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
                'mhc06_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                'attempt_id' => $attemptId,
                'acceptance_context_sha256' => $promotionSnapshot['acceptance_context_sha256'],
                'promoted_at' => $promotedAt,
                'finalized_provenance_sha256' => $contract['mhc06_provenance_sha256'],
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);
        });
    }

    /** Enable durable one-shot scheduling only after the real API/worker smoke is green. */
    public function complete(?string $finalSmokeReceipt = null): array
    {
        $contract = $this->stateContract()->finalized();
        $this->assertEvidenceReceipt($finalSmokeReceipt, self::FINAL_SMOKE_SCHEMA, 'final-smoke', $contract);
        $this->requireTables();
        $this->assertCustodyClean();
        $resolved = realpath((string) $finalSmokeReceipt);
        if ($resolved === false) {
            throw new RuntimeException('MHC-08 final-smoke receipt is unavailable.');
        }
        $finalSmokeSha = hash_file('sha256', $resolved);

        return DB::transaction(function () use ($resolved, $finalSmokeSha, $contract): array {
            if (hash_file('sha256', $resolved) !== $finalSmokeSha) {
                throw new RuntimeException('MHC-08 final-smoke evidence changed after validation.');
            }
            $journal = $this->journal(self::PROMOTION_JOURNAL, true);
            if ($journal === null) {
                throw new RuntimeException('MHC-08 completion requires the owned promotion journal.');
            }
            $snapshot = $this->decode($journal['config_value'] ?? null);
            if (($snapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_promotion_journal.v1'
                || ($snapshot['phase'] ?? null) !== 'promoted_pending_smoke'
                || ! $this->uuid($snapshot['attempt_id'] ?? null)
                || ! $this->sha($snapshot['acceptance_context_sha256'] ?? null)
                || ! $this->sha($snapshot['finalized_provenance_sha256'] ?? null)
                || ! $this->sha($snapshot['qualification_state_sha256'] ?? null)
                || ! $this->sha($snapshot['final_state_sha256'] ?? null)
                || strtotime((string) ($snapshot['promoted_at'] ?? '')) === false
                || ! $this->sha($snapshot['post_policy_control_sha256'] ?? null)
                || ! $this->sha($snapshot['post_worker_control_sha256'] ?? null)
                || ! is_array($snapshot['evidence'] ?? null)
                || ! $this->sha($snapshot['evidence']['canary_sha256'] ?? null)
                || ! $this->sha($snapshot['evidence']['cancellation_sha256'] ?? null)
                || ! $this->sha($snapshot['evidence']['host_clean_sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 completion promotion journal is not exact and pending smoke.');
            }
            if ($this->acceptanceContextSha($snapshot) !== $snapshot['acceptance_context_sha256']) {
                throw new RuntimeException('MHC-08 completion promotion context binding drifted.');
            }
            $finalReceipt = json_decode((string) file_get_contents($resolved), true);
            if (! is_array($finalReceipt)
                || ($finalReceipt['promotion_attempt_id'] ?? null) !== $snapshot['attempt_id']
                || ($finalReceipt['acceptance_context_sha256'] ?? null) !== $snapshot['acceptance_context_sha256']
                || ($finalReceipt['promoted_at'] ?? null) !== $snapshot['promoted_at']
                || ($finalReceipt['finalized_provenance_sha256'] ?? null) !== $snapshot['finalized_provenance_sha256']
                || ($finalReceipt['final_state_sha256'] ?? null) !== $snapshot['final_state_sha256']
                || strtotime((string) ($finalReceipt['completed_at'] ?? '')) < strtotime($snapshot['promoted_at'])) {
                throw new RuntimeException('MHC-08 final-smoke receipt does not belong to the active promotion attempt.');
            }
            $freshContract = $this->stateContract()->finalized();
            $this->assertSameFinalizedContract($contract, $freshContract);
            if ($snapshot['finalized_provenance_sha256'] !== $freshContract['mhc06_provenance_sha256']) {
                throw new RuntimeException('MHC-08 completion finalized provenance changed after promotion.');
            }
            $this->assertCurrentHostClean(
                $this->controllerClient()->statusK3(),
                $freshContract,
                $snapshot['final_state_sha256'],
            );
            $this->assertCustodyClean();
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            $policyRow = $this->policyRow(true);
            $worker = $this->worker(true);
            $policy = $this->policy->normalize($this->decode($policyRow['config_value'] ?? null));
            if ($this->hash($provider) !== ($snapshot['post_provider_sha256'] ?? null)
                || $this->hash($profile) !== ($snapshot['post_profile_sha256'] ?? null)
                || $this->hash($this->policyControl($policyRow)) !== $snapshot['post_policy_control_sha256']
                || $this->hash($this->workerControl($worker)) !== $snapshot['post_worker_control_sha256']
                || ($policy['revision'] ?? null) !== ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION
                || ($policy['enabled'] ?? null) !== true || (int) ($worker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 completion policy, worker, provider, or profile drifted.');
            }

            $explicit = $policy;
            $explicit['revision'] = ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION;
            $explicit = $this->policy->normalize($explicit);
            $this->exactUpdate('system_configs', (int) $policyRow['id'], [
                'config_value' => json_encode($explicit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->exactUpdate('scheduled_jobs', (int) $worker['id'], ['enabled' => 1]);
            $explicitRow = $this->policyRow(true);
            $enabledWorker = $this->worker(true);
            if ((int) ($enabledWorker['enabled'] ?? 0) !== 1
                || $this->policy->normalize($this->decode($explicitRow['config_value'] ?? null)) !== $explicit) {
                throw new RuntimeException('MHC-08 completion explicit policy and worker enablement were not exact.');
            }
            $snapshot['phase'] = 'completed';
            if (hash_file('sha256', $resolved) !== $finalSmokeSha) {
                throw new RuntimeException('MHC-08 final-smoke evidence changed during completion.');
            }
            $snapshot['final_smoke_sha256'] = $finalSmokeSha;
            $snapshot['completed_at'] = now()->toIso8601String();
            $snapshot['acceptance_post_policy_sha256'] = $snapshot['post_policy_sha256'];
            $snapshot['acceptance_post_policy_control_sha256'] = $snapshot['post_policy_control_sha256'];
            $snapshot['acceptance_post_worker_control_sha256'] = $snapshot['post_worker_control_sha256'];
            $snapshot['post_policy_sha256'] = hash('sha256', $this->canonical($explicit));
            $snapshot['post_policy_control_sha256'] = $this->hash($this->policyControl($explicitRow));
            $snapshot['post_worker_control_sha256'] = $this->hash($this->workerControl($enabledWorker));
            if ($this->updateJournal(self::PROMOTION_JOURNAL, (int) $journal['id'], $snapshot) !== 1) {
                throw new RuntimeException('MHC-08 completion journal update was not exact.');
            }
            $this->clearCaches();

            return $this->receipt('completed', [
                'policy_enabled' => true,
                'policy_revision' => ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION,
                'worker_enabled' => true,
                'provider_closed' => true,
                'profile_gated' => true,
                'final_smoke_sha256' => $snapshot['final_smoke_sha256'],
                'attempt_id' => $snapshot['attempt_id'],
                'acceptance_context_sha256' => $snapshot['acceptance_context_sha256'],
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);
        });
    }

    /** Deterministically restore only rows owned by the promotion journal. */
    public function rollback(): array
    {
        $this->requireTables();

        DB::transaction(function (): void {
            $journal = $this->journal(self::PROMOTION_JOURNAL, true);
            if ($journal === null) {
                throw new RuntimeException('MHC-08 promotion rollback has no owned journal.');
            }
            $snapshot = $this->decode($journal['config_value'] ?? null);
            if (($snapshot['phase'] ?? null) === 'rollback_draining') {
                $this->assertRollbackJournal($snapshot);

                return;
            }
            $preimages = $snapshot['preimages'] ?? null;
            if (($snapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_promotion_journal.v1'
                || ! in_array($snapshot['phase'] ?? null, ['promoted_pending_smoke', 'completed'], true)
                || ! is_array($preimages)
                || ! is_array($preimages['policy'] ?? null)
                || ! is_array($preimages['worker'] ?? null)
                || ! $this->sha($snapshot['post_policy_control_sha256'] ?? null)
                || ! $this->sha($snapshot['post_worker_control_sha256'] ?? null)
                || ! is_array($snapshot['evidence'] ?? null)
                || ! $this->sha($snapshot['evidence']['canary_sha256'] ?? null)
                || ! $this->sha($snapshot['evidence']['cancellation_sha256'] ?? null)
                || ! $this->sha($snapshot['evidence']['host_clean_sha256'] ?? null)
                || ! $this->uuid($snapshot['attempt_id'] ?? null)
                || ! $this->sha($snapshot['acceptance_context_sha256'] ?? null)
                || $this->acceptanceContextSha($snapshot) !== $snapshot['acceptance_context_sha256']
                || ! $this->sha($snapshot['finalized_provenance_sha256'] ?? null)
                || ! $this->sha($snapshot['qualification_state_sha256'] ?? null)
                || ! $this->sha($snapshot['final_state_sha256'] ?? null)
                || (($snapshot['phase'] ?? null) === 'completed'
                    && ! $this->sha($snapshot['final_smoke_sha256'] ?? null))) {
                throw new RuntimeException('MHC-08 promotion journal preimages are invalid.');
            }
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            if ($this->hash($provider) !== ($snapshot['post_provider_sha256'] ?? null)
                || $this->hash($profile) !== ($snapshot['post_profile_sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 rollback found provider/profile drift.');
            }
            $policyRow = $this->policyRow(true);
            $worker = $this->worker(true);
            if ($this->hash($this->policyControl($policyRow)) !== $snapshot['post_policy_control_sha256']
                || $this->hash($this->workerControl($worker)) !== $snapshot['post_worker_control_sha256']) {
                throw new RuntimeException('MHC-08 rollback found policy/worker control drift.');
            }
            $currentPolicy = $this->policy->normalize($this->decode($policyRow['config_value'] ?? null));
            $expectedRevision = ($snapshot['phase'] ?? null) === 'completed'
                ? ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION
                : ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION;
            if ($currentPolicy['revision'] !== $expectedRevision
                || $currentPolicy['enabled'] !== true
                || (int) ($worker['enabled'] ?? -1) !== (($snapshot['phase'] ?? null) === 'completed' ? 1 : 0)) {
                throw new RuntimeException('MHC-08 rollback postimage is not exact.');
            }

            $snapshot['acceptance_post_policy_sha256'] ??= $snapshot['post_policy_sha256'];
            $snapshot['acceptance_post_policy_control_sha256'] ??= $snapshot['post_policy_control_sha256'];
            $snapshot['acceptance_post_worker_control_sha256'] ??= $snapshot['post_worker_control_sha256'];
            $originPhase = $snapshot['phase'];
            $draining = $currentPolicy;
            $draining['revision'] = ModelHostControllerMhc08K3PolicyService::DRAINING_REVISION;
            $draining['enabled'] = false;
            $draining = $this->policy->normalize($draining);
            if ((int) ($worker['enabled'] ?? 0) !== 0) {
                $this->exactUpdate('scheduled_jobs', (int) $worker['id'], ['enabled' => 0]);
            }
            $this->exactUpdate('system_configs', (int) $policyRow['id'], [
                'config_value' => json_encode($draining, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $drainingPolicyRow = $this->policyRow(true);
            $disabledWorker = $this->worker(true);
            if ($this->policy->normalize($this->decode($drainingPolicyRow['config_value'] ?? null)) !== $draining
                || (int) ($disabledWorker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 rollback draining transition was not exact.');
            }
            $snapshot['phase'] = 'rollback_draining';
            $snapshot['rollback_origin_phase'] = $originPhase;
            $snapshot['rollback_draining_at'] = now()->toIso8601String();
            $snapshot['post_policy_sha256'] = hash('sha256', $this->canonical($draining));
            $snapshot['post_policy_control_sha256'] = $this->hash($this->policyControl($drainingPolicyRow));
            $snapshot['post_worker_control_sha256'] = $this->hash($this->workerControl($disabledWorker));
            if ($this->updateJournal(self::PROMOTION_JOURNAL, (int) $journal['id'], $snapshot) !== 1) {
                throw new RuntimeException('MHC-08 rollback draining journal update was not exact.');
            }
            $this->clearCaches();
        });

        // The committed draining policy closes API admission and scheduling before this check.
        $this->assertCustodyClean();

        return DB::transaction(function (): array {
            $journal = $this->journal(self::PROMOTION_JOURNAL, true);
            if ($journal === null) {
                throw new RuntimeException('MHC-08 promotion rollback lost its owned journal.');
            }
            $snapshot = $this->decode($journal['config_value'] ?? null);
            $this->assertRollbackJournal($snapshot);
            $preimages = $snapshot['preimages'];
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            $policyRow = $this->policyRow(true);
            $worker = $this->worker(true);
            if ($this->hash($provider) !== ($snapshot['post_provider_sha256'] ?? null)
                || $this->hash($profile) !== ($snapshot['post_profile_sha256'] ?? null)
                || $this->hash($this->policyControl($policyRow)) !== $snapshot['post_policy_control_sha256']
                || $this->hash($this->workerControl($worker)) !== $snapshot['post_worker_control_sha256']) {
                throw new RuntimeException('MHC-08 rollback draining postimage drifted.');
            }
            $draining = $this->policy->normalize($this->decode($policyRow['config_value'] ?? null));
            if ($draining['revision'] !== ModelHostControllerMhc08K3PolicyService::DRAINING_REVISION
                || $draining['enabled'] !== false || (int) ($worker['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 rollback is not fail-closed in draining state.');
            }
            $this->assertCustodyClean();
            $this->exactUpdate('system_configs', (int) $policyRow['id'], [
                'config_value' => $preimages['policy']['config_value'],
            ]);
            $this->exactUpdate('scheduled_jobs', (int) $worker['id'], [
                'enabled' => $preimages['worker']['enabled'],
            ]);
            $restoredPolicy = $this->policy->normalize($this->decode($this->policyRow(true)['config_value'] ?? null));
            if ($restoredPolicy['revision'] !== ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION
                || $restoredPolicy['enabled'] !== false
                || (int) ($this->worker(true)['enabled'] ?? 1) !== 0) {
                throw new RuntimeException('MHC-08 rollback preimage verification failed.');
            }
            $deleted = DB::table('system_configs')->where('id', (int) $journal['id'])->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('MHC-08 promotion journal removal was not exact.');
            }
            $this->clearCaches();

            return $this->receipt('rolled_back', [
                'policy_enabled' => false,
                'worker_enabled' => false,
                'provider_closed' => true,
                'profile_gated' => true,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);
        });
    }

    /** Restore the closed Mapped provider/profile preimage owned by stage-state. */
    public function rollbackState(): array
    {
        $this->requireTables();
        $this->assertCustodyClean();

        return DB::transaction(function (): array {
            $journal = $this->journal(self::STAGE_JOURNAL, true);
            if ($journal === null) {
                throw new RuntimeException('MHC-08 State-stage rollback has no owned journal.');
            }
            if ($this->journal(self::PROMOTION_JOURNAL) !== null || $this->journal(self::ADMISSION_JOURNAL) !== null) {
                throw new RuntimeException('MHC-08 State-stage rollback requires no active promotion or admission journal.');
            }
            $snapshot = $this->decode($journal['config_value'] ?? null);
            $provider = $this->provider(true);
            $profile = $this->profile(true);
            $originalProvider = $snapshot['provider'] ?? null;
            $originalProfile = $snapshot['profile'] ?? null;
            if (($snapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_stage_journal.v1'
                || ! is_array($originalProvider) || ! is_array($originalProfile)
                || (int) ($provider['id'] ?? 0) !== (int) ($snapshot['provider_id'] ?? -1)
                || (int) ($profile['id'] ?? 0) !== (int) ($snapshot['profile_id'] ?? -1)) {
                throw new RuntimeException('MHC-08 State-stage ownership changed before rollback.');
            }
            if ($this->hash($provider) !== ($snapshot['post_provider_sha256'] ?? null)
                || $this->hash($profile) !== ($snapshot['post_profile_sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 State-stage rollback found provider/profile drift.');
            }
            $this->exactUpdate('llm_instances', (int) $provider['id'], $this->withoutId($originalProvider), false);
            $this->exactUpdate('llm_model_profiles', (int) $profile['id'], $this->withoutId($originalProfile), false);
            if ($this->hash($this->provider(true)) !== ($snapshot['provider_sha256'] ?? null)
                || $this->hash($this->profile(true)) !== ($snapshot['profile_sha256'] ?? null)) {
                throw new RuntimeException('MHC-08 State-stage rollback preimage verification failed.');
            }
            if (DB::table('system_configs')->where('id', (int) $journal['id'])->delete() !== 1) {
                throw new RuntimeException('MHC-08 State-stage journal removal was not exact.');
            }
            $this->clearCaches();

            return [
                'schema' => self::STAGE_RECEIPT_SCHEMA,
                'result' => 'rolled_back',
                'provider_closed' => true,
                'profile_gated' => true,
                'policy_inactive' => true,
                'worker_disabled' => true,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $hasConfigs = false;
        try {
            $hasConfigs = Schema::hasTable('system_configs');
        } catch (Throwable) {
            // Local status remains content-free when the application database is unavailable.
        }
        $policy = null;
        $worker = null;
        try {
            $policy = $hasConfigs ? $this->policyRow() : null;
            $worker = Schema::hasTable('scheduled_jobs') ? $this->worker() : null;
        } catch (Throwable) {
            // Status is observational; absent staged rows are represented as null.
        }
        $policyValue = $policy === null ? [] : $this->decode($policy['config_value'] ?? null);
        $promotionJournal = false;
        $stageJournal = false;
        try {
            $promotionJournal = $hasConfigs && $this->journal(self::PROMOTION_JOURNAL) !== null;
            $stageJournal = $hasConfigs && $this->journal(self::STAGE_JOURNAL) !== null;
        } catch (Throwable) {
            // A partially migrated local database is still a safe, compact status.
        }

        return $this->receipt('status', [
            'policy_revision' => $policyValue['revision'] ?? null,
            'policy_enabled' => $policyValue['enabled'] ?? null,
            'worker_enabled' => $worker === null ? null : (int) ($worker['enabled'] ?? 0) === 1,
            'promotion_journal_present' => $promotionJournal,
            'state_stage_journal_present' => $stageJournal,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ]);
    }

    private function stateContract(): ModelHostControllerMhc08K3StateContractService
    {
        return $this->state ?? new ModelHostControllerMhc08K3StateContractService($this->evidenceRoot);
    }

    private function controllerClient(): ModelHostControllerClient
    {
        return $this->controller ?? app(ModelHostControllerClient::class);
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $contract */
    private function assertCurrentHostClean(array $host, array $contract, string $finalStateSha256): void
    {
        $ingress = is_array($host['ingress'] ?? null) ? $host['ingress'] : [];
        $state = is_array($host['state'] ?? null) ? $host['state'] : [];
        $resources = is_array($host['resources'] ?? null) ? $host['resources'] : [];
        $health = is_array($host['health'] ?? null) ? $host['health'] : [];
        $restore = is_array($host['coexistence_restore_proof'] ?? null)
            ? $host['coexistence_restore_proof'] : [];
        $targets = is_array($restore['targets'] ?? null) ? $restore['targets'] : [];
        $currentTargets = is_array($host['coexistence_current_targets'] ?? null)
            ? $host['coexistence_current_targets'] : [];
        if (($host['runtime_profile'] ?? null) !== $contract['runtime_profile']
            || ($host['runtime_revision'] ?? null) !== $contract['runtime_revision']
            || ($host['engine_sha256'] ?? null) !== $contract['engine_sha256']
            || ($host['patch_sha256'] ?? null) !== $contract['patch_sha256']
            || ($host['patched_tree'] ?? null) !== $contract['patched_tree']
            || ($host['task_exact'] ?? null) !== true || ($host['task_enabled'] ?? null) !== false
            || in_array($host['task_state'] ?? null, ['Running', 'Queued'], true)
            || ($host['candidate_task_present'] ?? null) !== true
            || ($host['candidate_task_exact'] ?? null) !== true
            || ($host['candidate_task_enabled'] ?? null) !== false
            || in_array($host['candidate_task_state'] ?? null, ['Running', 'Queued'], true)
            || ($host['restart_state_present'] ?? null) !== true
            || ($host['restart_state_sha256'] ?? null) !== $finalStateSha256
            || ($host['restart_state_temporary_present'] ?? null) !== false
            || ($host['health_required'] ?? null) !== false || ($health['healthy'] ?? null) !== false
            || (int) ($host['process_count'] ?? -1) !== 0 || (int) ($host['listener_count'] ?? -1) !== 0
            || ($ingress['ingress_open'] ?? null) !== false || ($ingress['firewall_present'] ?? null) !== false
            || ($ingress['exact_proxy'] ?? null) !== false
            || (int) ($ingress['port_8001_proxy_count'] ?? -1) !== 0
            || (int) ($ingress['other_port_8001_proxy_count'] ?? -1) !== 0
            || is_array($state['lease'] ?? null) || ($resources['gate_passed'] ?? null) !== true
            || ($restore['schema'] ?? null) !== 'plos.model_host_controller.mhc08_coexistence_restore.v1'
            || ($restore['result'] ?? null) !== 'green' || ($restore['mode'] ?? null) !== 'Restored'
            || array_map(static fn (mixed $target): mixed => is_array($target)
                ? ($target['target'] ?? null) : null, $targets)
                !== array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
            || ! collect($targets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true))
            || ($host['coexistence_current'] ?? null) !== true
            || array_map(static fn (mixed $target): mixed => is_array($target)
                ? ($target['target'] ?? null) : null, $currentTargets)
                !== array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
            || ! collect($currentTargets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true))) {
            throw new RuntimeException('MHC-08 completion current host cleanup is not exact and green.');
        }
    }

    private function requireTables(): void
    {
        foreach (['system_configs', 'scheduled_jobs', 'llm_instances', 'llm_model_profiles'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('MHC-08 promotion tables are unavailable.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function provider(bool $lock = false): array
    {
        $query = DB::table('llm_instances')->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'));
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 provider row is missing.');
        }

        return (array) $row;
    }

    /** @return array<string,mixed> */
    private function profile(bool $lock = false): array
    {
        $query = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 profile row is missing.');
        }

        return (array) $row;
    }

    /** @return array<string,mixed> */
    private function policyRow(bool $lock = false): array
    {
        $query = DB::table('system_configs')->where('section', self::SECTION)->where('config_key', self::POLICY_KEY);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 policy row is missing.');
        }

        return (array) $row;
    }

    /** @return array<string,mixed> */
    private function worker(bool $lock = false): array
    {
        $query = DB::table('scheduled_jobs')->where('name', self::WORKER_NAME);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 worker row is missing.');
        }

        return (array) $row;
    }

    /** @param array<string,mixed> $provider @param array<string,mixed> $profile */
    private function assertMappedClosedPreimage(array $provider, array $profile): void
    {
        $config = $this->decode($provider['config'] ?? null);
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
        $route = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
        $url = parse_url((string) ($provider['base_url'] ?? ''));
        if (($provider['instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($provider['instance_type'] ?? null) !== 'local_llm'
            || ($provider['gpu_target'] ?? null) !== 'none'
            || ($provider['host_affinity'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host_affinity')
            || ($provider['compat_backend'] ?? null) !== 'openai_compat_sse'
            || ($provider['compat_runtime_family'] ?? null) !== 'colibri-k3-47a749d'
            || ($provider['compat_status'] ?? null) !== 'provisional'
            || ! is_array($url) || ($url['scheme'] ?? null) !== 'http' || ($url['host'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host')
            || (int) ($url['port'] ?? 0) !== (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port') || ($url['path'] ?? null) !== '/v1'
            || isset($url['query']) || isset($url['fragment']) || (int) ($provider['port'] ?? 0) !== (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            || $this->jsonList($provider['capabilities'] ?? null) !== ['text', 'chat', 'streaming', 'local_llm', 'deep_offline']
            || $this->jsonList($provider['supported_models'] ?? null) !== [app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')]
            || (int) ($provider['context_length'] ?? 0) !== 4096
            || (int) ($provider['max_concurrent'] ?? 0) !== 1 || (int) ($provider['rate_limit_rpm'] ?? 0) !== 1
            || (int) ($provider['is_active'] ?? 1) !== 0
            || (int) ($provider['is_healthy'] ?? 1) !== 0
            || ($provider['routability'] ?? null) !== 'bench_only'
            || ($config['adapter'] ?? null) !== 'openai_compatible'
            || ($config['transport'] ?? null) !== 'openai_sse_monitored'
            || ! $this->exactHealthEndpoint($config)
            || ($config['stream_policy'] ?? null) !== [
                'idle_timeout_seconds' => 7200,
                'absolute_timeout_seconds' => 0,
                'connect_timeout_seconds' => 10,
                'log_interval_seconds' => 30,
            ]
            || (int) ($config['max_tokens'] ?? 0) !== 256 || (int) ($config['temperature'] ?? -1) !== 0
            || ($admission['state'] ?? null) !== 'staged'
            || ($admission['phase'] ?? null) !== 'K3-05-batch-2-complete'
            || ($admission['runtime'] ?? null) !== 'dev-33302b8-kimi-k3-mmap-prefix-v162-cancelacct-cpu'
            || ($admission['runtime_commit'] ?? null) !== '47a749d8225b815adc9622c964ba386639320973'
            || ($admission['model_revision'] ?? null) !== '9f62e4e9fffbd0a83ddd60e1c209d828994b3569'
            || ($admission['patch_sha256'] ?? null) !== '11d362dd53381dfded4a168fc3c07070597f0265a27bfbdb1452e2fbb0178adb'
            || ($admission['patched_tree'] ?? null) !== 'e49b98fdfdd44e8e12e2f026b651fc1cd006f2b1'
            || ($admission['context_receipt_sha256'] ?? null) !== '8a73b228ece6ca8ff412a05d51003c8cf74af227780ed9411e6b8b9957620241'
            || ($admission['baseline'] ?? null) !== 'cpu_no_fallback'
            || ($admission['normal_routing'] ?? null) !== false
            || ($admission['tools_enabled'] ?? null) !== false || ($admission['fallback_enabled'] ?? null) !== false
            || ($config['default_model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || (($config['models']['deep_offline'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'))
            || ($config['extra_headers']['Host'] ?? null) !== '127.0.0.1'
            || ($route['allowed_model_roles'] ?? null) !== ['deep_offline']
            || ($route['require_model_role'] ?? null) !== true
            || ($route['require_explicit_instance'] ?? null) !== true
            || ($route['allow_generic_fallback'] ?? null) !== false
            || ($route['tools_enabled'] ?? null) !== false
            || ($admission['runtime_profile'] ?? null) === 'State'
            || ($profile['profile_name'] ?? null) !== self::PROFILE
            || (int) ($profile['enabled'] ?? 1) !== 0
            || ($profile['status'] ?? null) !== 'staged'
            || (int) ($profile['requires_override'] ?? 0) !== 1) {
            throw new RuntimeException('MHC-08 State staging requires the exact closed Mapped preimage.');
        }
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @param array<string,mixed> $config */
    private function exactHealthEndpoint(array $config): bool
    {
        $health = parse_url((string) ($config['health_base_url'] ?? ''));

        return is_array($health) && ($health['scheme'] ?? null) === 'http'
            && ($health['host'] ?? null) === app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'host') && (int) ($health['port'] ?? 0) === (int) app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'port')
            && (! isset($health['path']) || $health['path'] === '')
            && ! isset($health['query']) && ! isset($health['fragment'])
            && ($config['health_path'] ?? null) === '/health';
    }

    private function assertCustodyClean(): void
    {
        $journal = $this->journal(self::ADMISSION_JOURNAL);
        if ($journal !== null) {
            throw new RuntimeException('MHC-08 admission cleanup journal is active.');
        }
        $worker = $this->worker();
        $workerId = (int) ($worker['id'] ?? 0);
        if (Schema::hasColumn('scheduled_jobs', 'running_count') && (int) ($worker['running_count'] ?? 0) !== 0) {
            throw new RuntimeException('MHC-08 worker has an active run.');
        }
        if (Schema::hasColumn('scheduled_jobs', 'running_pids')) {
            $pids = json_decode((string) ($worker['running_pids'] ?? '[]'), true);
            if (is_array($pids) && $pids !== []) {
                throw new RuntimeException('MHC-08 worker has an active process.');
            }
        }
        if (Schema::hasTable('scheduled_job_runs') && Schema::hasColumn('scheduled_job_runs', 'completed_at')
            && Schema::hasColumn('scheduled_job_runs', 'scheduled_job_id')
            && DB::table('scheduled_job_runs')->where('scheduled_job_id', $workerId)->whereNull('completed_at')->exists()) {
            throw new RuntimeException('MHC-08 scheduler cleanup is active.');
        }
        if (Schema::hasTable('task_custody_records') && Schema::hasColumn('task_custody_records', 'released_at')
            && Schema::hasColumn('task_custody_records', 'surface')
            && DB::table('task_custody_records')
                ->whereNull('released_at')
                ->where('surface', TaskCustodyService::SURFACE_MHC08_K3_JOB)
                ->exists()) {
            throw new RuntimeException('MHC-08 task custody is active.');
        }
        if (Schema::hasTable('distributed_tasks')
            && Schema::hasColumn('distributed_tasks', 'task_type')
            && Schema::hasColumn('distributed_tasks', 'status')
            && DB::table('distributed_tasks')
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->whereIn('status', [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING])
                ->exists()) {
            throw new RuntimeException('MHC-08 explicit task work is still pending or running.');
        }
        if (Schema::hasTable('distributed_tasks')
            && Schema::hasColumn('distributed_tasks', 'result')) {
            $terminals = DB::table('distributed_tasks')
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->whereIn('status', [DistributedAgentService::TASK_FAILED, DistributedAgentService::TASK_CANCELLED])
                ->get(['task_id', 'result']);
            foreach ($terminals as $terminal) {
                $result = is_string($terminal->result ?? null)
                    ? json_decode($terminal->result, true)
                    : null;
                if (! is_array($result)
                    || ($result['schema'] ?? null) !== ModelHostControllerMhc08K3JobService::TERMINAL_SCHEMA
                    || ($result['task_id'] ?? null) !== (string) $terminal->task_id
                    || ! in_array($result['cleanup_result'] ?? null, ['proven', 'not_required'], true)
                    || array_key_exists('output', $result)) {
                    throw new RuntimeException('MHC-08 terminal task cleanup remains unproven.');
                }
            }
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertRollbackJournal(array $snapshot): void
    {
        $preimages = $snapshot['preimages'] ?? null;
        $evidence = $snapshot['evidence'] ?? null;
        $origin = $snapshot['rollback_origin_phase'] ?? null;
        if (($snapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_promotion_journal.v1'
            || ($snapshot['phase'] ?? null) !== 'rollback_draining'
            || ! in_array($origin, ['promoted_pending_smoke', 'completed'], true)
            || strtotime((string) ($snapshot['rollback_draining_at'] ?? '')) === false
            || ! is_array($preimages) || ! is_array($preimages['policy'] ?? null)
            || ! is_array($preimages['worker'] ?? null) || ! is_array($evidence)
            || ! $this->uuid($snapshot['attempt_id'] ?? null)
            || ! $this->sha($snapshot['acceptance_context_sha256'] ?? null)
            || $this->acceptanceContextSha($snapshot) !== $snapshot['acceptance_context_sha256']
            || ! $this->sha($snapshot['finalized_provenance_sha256'] ?? null)
            || ! $this->sha($snapshot['qualification_state_sha256'] ?? null)
            || ! $this->sha($snapshot['final_state_sha256'] ?? null)
            || ! $this->sha($snapshot['acceptance_post_policy_sha256'] ?? null)
            || ! $this->sha($snapshot['acceptance_post_policy_control_sha256'] ?? null)
            || ! $this->sha($snapshot['acceptance_post_worker_control_sha256'] ?? null)
            || ! $this->sha($snapshot['post_policy_control_sha256'] ?? null)
            || ! $this->sha($snapshot['post_worker_control_sha256'] ?? null)
            || ! $this->sha($snapshot['post_provider_sha256'] ?? null)
            || ! $this->sha($snapshot['post_profile_sha256'] ?? null)
            || ! $this->sha($evidence['canary_sha256'] ?? null)
            || ! $this->sha($evidence['cancellation_sha256'] ?? null)
            || ! $this->sha($evidence['host_clean_sha256'] ?? null)
            || ($origin === 'completed' && ! $this->sha($snapshot['final_smoke_sha256'] ?? null))) {
            throw new RuntimeException('MHC-08 rollback draining journal is invalid.');
        }
    }

    /** @param array<string,mixed> $contract */
    private function assertEvidenceReceipt(?string $path, string $schema, string $kind, array $contract): void
    {
        if ($path === null || trim($path) === '') {
            throw new RuntimeException("MHC-08 {$kind} receipt is required.");
        }
        $root = realpath($this->evidenceRoot ?? base_path('scripts/patches/colibri/k3-selective-v162'));
        $resolved = realpath($path);
        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.'/') || is_link($path)) {
            throw new RuntimeException("MHC-08 {$kind} receipt must be a tracked private evidence file.");
        }
        $receipt = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($receipt)) {
            throw new RuntimeException("MHC-08 {$kind} receipt is not valid JSON evidence.");
        }
        $resultGreen = in_array($kind, ['host', 'final-smoke'], true)
            ? ($receipt['result'] ?? null) === 'green'
            : ($receipt['controls_passed'] ?? null) === true;
        if (($receipt['schema'] ?? null) !== $schema
            || ($receipt['content_free'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
            || $this->containsProhibitedContent($receipt)
            || ! $resultGreen) {
            throw new RuntimeException("MHC-08 {$kind} receipt is not exact and green.");
        }
        if ($kind === 'canary') {
            $expectedCases = [
                'exact_identity', 'bounded_arithmetic', 'bounded_sequence',
                'archival_instruction_boundary', 'archival_readiness',
            ];
            $cases = $receipt['cases'] ?? null;
            $seenCancellations = [];
            if (($receipt['batch_id'] ?? null) !== 'MHC-08D-canary-batch-1'
                || ($receipt['case_ids'] ?? null) !== $expectedCases
                || (int) ($receipt['case_count'] ?? 0) !== 5
                || (int) ($receipt['requested_max_tokens'] ?? 0) !== 128
                || ($receipt['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
                || ($receipt['provider_instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
                || ($receipt['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
                || ($receipt['engine'] ?? null) !== 'colibri-k3'
                || ($receipt['runtime_profile'] ?? null) !== $contract['runtime_profile']
                || ($receipt['runtime_revision'] ?? null) !== $contract['runtime_revision']
                || ($receipt['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($receipt['state_patch_sha256'] ?? null) !== $contract['patch_sha256']
                || ($receipt['state_patched_tree'] ?? null) !== $contract['patched_tree']
                || ($receipt['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ! $this->freshReceipt($receipt['completed_at'] ?? null)
                || ($receipt['execution_mode'] ?? null) !== 'serial'
                || ($receipt['controls_passed'] ?? null) !== true
                || ($receipt['natural_stop_passed'] ?? null) !== true
                || ($receipt['cleanup_proven'] ?? null) !== true
                || ($receipt['normal_routing_changed'] ?? null) !== false
                || ($receipt['tools_used'] ?? null) !== false
                || ($receipt['fallback_used'] ?? null) !== false
                || ($receipt['promotion_applied'] ?? null) !== false
                || ! $this->sha($receipt['contract_sha256'] ?? null)
                || ! is_array($cases) || count($cases) !== 5) {
                throw new RuntimeException('MHC-08 canary five-case contract is not exact and green.');
            }
            foreach (array_values($cases) as $index => $case) {
                if (! is_array($case)
                    || ($case['case_id'] ?? null) !== ($expectedCases[$index] ?? null)
                    || ($case['passed'] ?? null) !== true
                    || ! $this->sha($case['output_sha256'] ?? null)
                    || ! is_string($case['cancellation_run_id'] ?? null)
                    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $case['cancellation_run_id']) !== 1
                    || isset($case['prompt'], $case['output'])) {
                    throw new RuntimeException('MHC-08 canary cancellation/cleanup evidence is incomplete.');
                }
                if (isset($seenCancellations[$case['cancellation_run_id']])) {
                    throw new RuntimeException('MHC-08 canary cancellation identities are not unique.');
                }
                $seenCancellations[$case['cancellation_run_id']] = true;
            }
        }
        if ($kind === 'cancellation') {
            if (! is_string($receipt['task_id'] ?? null)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $receipt['task_id']) !== 1
                || ($receipt['result'] ?? null) !== 'green'
                || ($receipt['cross_process_owned_cancellation'] ?? null) !== true
                || ($receipt['stream_cancelled'] ?? null) !== true
                || ($receipt['partial_output_preserved'] ?? null) !== false
                || ($receipt['controller_cleanup_proven'] ?? null) !== true
                || ($receipt['admission_cleanup_proven'] ?? null) !== true
                || ($receipt['custody_released'] ?? null) !== true
                || ($receipt['terminal_cleanup_proven'] ?? null) !== true
                || ($receipt['recovery_inference_passed'] ?? null) !== true
                || ! $this->sha($receipt['recovery_output_sha256'] ?? null)
                || ($receipt['temporary_task_removed'] ?? null) !== true
                || ($receipt['policy_persisted'] ?? null) !== false
                || ($receipt['worker_enabled'] ?? null) !== false
                || ($receipt['normal_routing_changed'] ?? null) !== false
                || ($receipt['fallback_used'] ?? null) !== false
                || ($receipt['tools_used'] ?? null) !== false) {
                throw new RuntimeException('MHC-08 cross-process cancellation receipt is not exact and green.');
            }
            if (($receipt['runtime_profile'] ?? null) !== $contract['runtime_profile']
                || ($receipt['runtime_revision'] ?? null) !== $contract['runtime_revision']
                || ($receipt['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($receipt['state_patch_sha256'] ?? null) !== $contract['patch_sha256']
                || ($receipt['state_patched_tree'] ?? null) !== $contract['patched_tree']
                || ($receipt['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ! $this->freshReceipt($receipt['completed_at'] ?? null)) {
                throw new RuntimeException('MHC-08 cancellation receipt State identity or freshness drifted.');
            }
        }
        if ($kind === 'host') {
            if (array_keys($receipt) !== [
                'schema', 'result', 'host_id', 'target', 'runtime_profile', 'runtime_revision',
                'state_engine_sha256', 'state_patch_sha256', 'state_patched_tree',
                'state_build_receipt_sha256', 'mhc06_provenance_sha256',
                'activation_mhc06_provenance_sha256', 'activation_transfer_sha256',
                'mhc08c_qualification_sha256', 'qualification_state_sha256', 'final_state_sha256',
                'restart_state_sha256', 'controller_clean', 'lease_inactive', 'process_count',
                'listener_count', 'ingress_closed', 'coexistence_restored', 'coexistence_current',
                'stable_task_exact', 'stable_task_disabled', 'resource_guards_green', 'completed_at',
                'content_free', 'contains_prompt_or_response_content',
            ]) {
                throw new RuntimeException('MHC-08 host-clean receipt fields are not exact.');
            }
            $activationPath = $root.'/MHC-08-STATE-ACTIVATION-TRANSFER.json';
            $qualificationPath = $root.'/MHC-08C-STATE-QUALIFICATION.json';
            if (! is_file($activationPath) || is_link($activationPath)
                || ! is_file($qualificationPath) || is_link($qualificationPath)) {
                throw new RuntimeException('MHC-08 host-clean predecessor evidence is missing or unsafe.');
            }
            $activation = json_decode((string) file_get_contents($activationPath), true);
            $qualification = json_decode((string) file_get_contents($qualificationPath), true);
            if (! is_array($activation) || ! is_array($qualification)
                || array_keys($activation) !== [
                    'schema', 'action', 'result', 'host_id', 'baseline_sha256', 'staging_sha256',
                    'private_package_identity', 'executor_sha256', 'state_build_receipt_sha256',
                    'mhc06_provenance_sha256', 'activation_mhc06_provenance_sha256',
                    'checkpoint_state', 'activation_allowed', 'completed_at', 'destination_count',
                    'content_free', 'contains_prompt_or_response_content',
                ]
                || array_keys($qualification) !== [
                    'schema', 'result', 'controls_passed', 'host_id', 'target', 'runtime_profile',
                    'runtime_revision', 'state_engine_sha256', 'state_patch_sha256', 'state_patched_tree',
                    'first_publication_passed', 'restart_admission_passed', 'restart_republished',
                    'divergent_clean_miss_passed', 'corrupt_state_refused', 'state_restored_exactly',
                    'stable_task_rollback_reinstall_passed', 'resource_guards_green', 'coexistence_targets',
                    'coexistence_restored', 'process_count', 'listener_count', 'ingress_closed', 'lease_inactive',
                    'first_runner_receipt_sha256', 'restart_runner_receipt_sha256',
                    'divergent_runner_receipt_sha256', 'corrupt_runner_receipt_sha256',
                    'qualification_state_sha256', 'final_state_sha256', 'preexisting_state_restored',
                    'preexisting_state_sha256', 'completed_at', 'content_free',
                    'contains_prompt_or_response_content',
                ]
                || ($activation['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_activation_transfer.v1'
                || ($activation['action'] ?? null) !== 'transfer'
                || ($activation['result'] ?? null) !== 'transferred'
                || ($activation['host_id'] ?? null) !== '.87'
                || ($activation['checkpoint_state'] ?? null) !== 'transferred'
                || ($activation['activation_allowed'] ?? null) !== true
                || (int) ($activation['destination_count'] ?? 0) !== 4
                || ($activation['baseline_sha256'] ?? null) !== $contract['baseline_sha256']
                || ($activation['staging_sha256'] ?? null) !== $contract['staging_sha256']
                || ($activation['private_package_identity'] ?? null) !== $contract['base_ref']
                || ! $this->sha($activation['executor_sha256'] ?? null)
                || ($activation['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ($activation['mhc06_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
                || ! $this->sha($activation['activation_mhc06_provenance_sha256'] ?? null)
                || strtotime((string) ($activation['completed_at'] ?? '')) === false
                || ($activation['content_free'] ?? null) !== true
                || ($activation['contains_prompt_or_response_content'] ?? null) !== false
                || $this->containsProhibitedContent($activation)
                || ($qualification['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_qualification.v1'
                || ($qualification['result'] ?? null) !== 'green'
                || ($qualification['controls_passed'] ?? null) !== true
                || ($qualification['host_id'] ?? null) !== '.87'
                || ($qualification['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
                || ($qualification['runtime_profile'] ?? null) !== $contract['runtime_profile']
                || ($qualification['runtime_revision'] ?? null) !== $contract['runtime_revision']
                || ($qualification['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($qualification['state_patch_sha256'] ?? null) !== $contract['patch_sha256']
                || ($qualification['state_patched_tree'] ?? null) !== $contract['patched_tree']
                || ($qualification['first_publication_passed'] ?? null) !== true
                || ($qualification['restart_admission_passed'] ?? null) !== true
                || ($qualification['restart_republished'] ?? null) !== true
                || ($qualification['divergent_clean_miss_passed'] ?? null) !== true
                || ($qualification['corrupt_state_refused'] ?? null) !== true
                || ($qualification['state_restored_exactly'] ?? null) !== true
                || ($qualification['stable_task_rollback_reinstall_passed'] ?? null) !== true
                || ($qualification['resource_guards_green'] ?? null) !== true
                || ($qualification['coexistence_targets'] ?? null) !== array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
                || ($qualification['coexistence_restored'] ?? null) !== true
                || (int) ($qualification['process_count'] ?? -1) !== 0
                || (int) ($qualification['listener_count'] ?? -1) !== 0
                || ($qualification['ingress_closed'] ?? null) !== true
                || ($qualification['lease_inactive'] ?? null) !== true
                || ! $this->sha($qualification['first_runner_receipt_sha256'] ?? null)
                || ! $this->sha($qualification['restart_runner_receipt_sha256'] ?? null)
                || ! $this->sha($qualification['divergent_runner_receipt_sha256'] ?? null)
                || ! $this->sha($qualification['corrupt_runner_receipt_sha256'] ?? null)
                || ! $this->sha($qualification['qualification_state_sha256'] ?? null)
                || ! $this->sha($qualification['final_state_sha256'] ?? null)
                || ! is_bool($qualification['preexisting_state_restored'] ?? null)
                || (($qualification['preexisting_state_restored'] ?? null) === true
                    && (! $this->sha($qualification['preexisting_state_sha256'] ?? null)
                        || ($qualification['preexisting_state_sha256'] ?? null) !== $qualification['final_state_sha256']))
                || (($qualification['preexisting_state_restored'] ?? null) === false
                    && (($qualification['preexisting_state_sha256'] ?? null) !== null
                        || ($qualification['qualification_state_sha256'] ?? null) !== $qualification['final_state_sha256']))
                || ($qualification['content_free'] ?? null) !== true
                || ($qualification['contains_prompt_or_response_content'] ?? null) !== false
                || strtotime((string) ($qualification['completed_at'] ?? '')) === false
                || $this->containsProhibitedContent($qualification)) {
                throw new RuntimeException('MHC-08 host-clean predecessor evidence is not exact and green.');
            }
            if (($receipt['host_id'] ?? null) !== '.87'
                || ($receipt['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
                || ($receipt['runtime_profile'] ?? null) !== $contract['runtime_profile']
                || ($receipt['runtime_revision'] ?? null) !== $contract['runtime_revision']
                || ($receipt['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($receipt['state_patch_sha256'] ?? null) !== $contract['patch_sha256']
                || ($receipt['state_patched_tree'] ?? null) !== $contract['patched_tree']
                || ($receipt['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ($receipt['mhc06_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
                || ($receipt['activation_mhc06_provenance_sha256'] ?? null)
                    !== ($activation['activation_mhc06_provenance_sha256'] ?? null)
                || ($receipt['activation_transfer_sha256'] ?? null) !== hash_file('sha256', $activationPath)
                || ($receipt['mhc08c_qualification_sha256'] ?? null) !== hash_file('sha256', $qualificationPath)
                || ($receipt['qualification_state_sha256'] ?? null) !== $qualification['qualification_state_sha256']
                || ($receipt['final_state_sha256'] ?? null) !== $qualification['final_state_sha256']
                || ($receipt['restart_state_sha256'] ?? null) !== $qualification['final_state_sha256']
                || ($receipt['controller_clean'] ?? null) !== true
                || ($receipt['lease_inactive'] ?? null) !== true
                || (int) ($receipt['process_count'] ?? -1) !== 0
                || (int) ($receipt['listener_count'] ?? -1) !== 0
                || ($receipt['ingress_closed'] ?? null) !== true
                || ($receipt['coexistence_restored'] ?? null) !== true
                || ($receipt['coexistence_current'] ?? null) !== true
                || ($receipt['stable_task_exact'] ?? null) !== true
                || ($receipt['stable_task_disabled'] ?? null) !== true
                || ($receipt['resource_guards_green'] ?? null) !== true
                || ! $this->freshReceipt($receipt['completed_at'] ?? null)) {
                throw new RuntimeException('MHC-08 host-clean qualification is stale, drifted, or incomplete.');
            }
        }
        if ($kind === 'final-smoke') {
            $expectedKeys = [
                'schema', 'result', 'target', 'runtime_profile', 'runtime_revision', 'state_engine_sha256',
                'state_build_receipt_sha256', 'final_state_sha256', 'promotion_attempt_id',
                'acceptance_context_sha256', 'promoted_at', 'finalized_provenance_sha256',
                'roles', 'case_count', 'cases', 'cancel_task_id',
                'cancel_owner_proven', 'cross_owner_cancel_rejected', 'cancelled_before_worker',
                'cancelled_output_omitted', 'unauthenticated_rejected',
                'acceptance_policy_enforced', 'normal_authenticated_submission_rejected',
                'worker_schedule_disabled',
                'api_route_and_auth_middleware_exercised', 'one_shot_worker_exercised',
                'pending_or_running_tasks', 'custody_released', 'admission_journal_absent',
                'controller_cleanup_proven', 'normal_routing_changed', 'tools_used', 'fallback_used',
                'completed_at', 'content_free', 'contains_prompt_or_response_content',
            ];
            $cases = $receipt['cases'] ?? null;
            if (array_keys($receipt) !== $expectedKeys
                || ($receipt['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
                || ($receipt['runtime_profile'] ?? null) !== $contract['runtime_profile']
                || ($receipt['runtime_revision'] ?? null) !== $contract['runtime_revision']
                || ($receipt['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($receipt['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
                || ! $this->sha($receipt['final_state_sha256'] ?? null)
                || ! $this->uuid($receipt['promotion_attempt_id'] ?? null)
                || ! $this->sha($receipt['acceptance_context_sha256'] ?? null)
                || strtotime((string) ($receipt['promoted_at'] ?? '')) === false
                || ($receipt['finalized_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
                || ($receipt['roles'] ?? null) !== ['ultra_deep', 'archival']
                || (int) ($receipt['case_count'] ?? 0) !== 2 || ! is_array($cases) || count($cases) !== 2
                || ! $this->uuid($receipt['cancel_task_id'] ?? null)
                || ($receipt['cancel_owner_proven'] ?? null) !== true
                || ($receipt['cross_owner_cancel_rejected'] ?? null) !== true
                || ($receipt['cancelled_before_worker'] ?? null) !== true
                || ($receipt['cancelled_output_omitted'] ?? null) !== true
                || ($receipt['unauthenticated_rejected'] ?? null) !== true
                || ($receipt['acceptance_policy_enforced'] ?? null) !== true
                || ($receipt['normal_authenticated_submission_rejected'] ?? null) !== true
                || ($receipt['worker_schedule_disabled'] ?? null) !== true
                || ($receipt['api_route_and_auth_middleware_exercised'] ?? null) !== true
                || ($receipt['one_shot_worker_exercised'] ?? null) !== true
                || (int) ($receipt['pending_or_running_tasks'] ?? -1) !== 0
                || ($receipt['custody_released'] ?? null) !== true
                || ($receipt['admission_journal_absent'] ?? null) !== true
                || ($receipt['controller_cleanup_proven'] ?? null) !== true
                || ($receipt['normal_routing_changed'] ?? null) !== false
                || ($receipt['tools_used'] ?? null) !== false || ($receipt['fallback_used'] ?? null) !== false
                || ! $this->freshReceipt($receipt['completed_at'] ?? null)) {
                throw new RuntimeException('MHC-08 final-smoke receipt is stale, drifted, or incomplete.');
            }
            foreach (array_values($cases) as $index => $case) {
                $expected = [
                    ['ultra_deep_job', 'ultra_deep'], ['archival_job', 'archival'],
                ][$index];
                if (! is_array($case) || array_keys($case) !== [
                    'case_id', 'role', 'task_id', 'state', 'finish_reason', 'output_sha256',
                    'owner_status_proven', 'cross_owner_rejected', 'worker_one_shot', 'cleanup_proven',
                ]
                    || ($case['case_id'] ?? null) !== $expected[0] || ($case['role'] ?? null) !== $expected[1]
                    || ! $this->uuid($case['task_id'] ?? null) || ($case['state'] ?? null) !== 'completed'
                    || ($case['finish_reason'] ?? null) !== 'stop' || ! $this->sha($case['output_sha256'] ?? null)
                    || ($case['owner_status_proven'] ?? null) !== true
                    || ($case['cross_owner_rejected'] ?? null) !== true
                    || ($case['worker_one_shot'] ?? null) !== true || ($case['cleanup_proven'] ?? null) !== true) {
                    throw new RuntimeException('MHC-08 final-smoke case evidence is not exact.');
                }
            }
        }
    }

    private function assertNoJournal(string $key): void
    {
        if ($this->journal($key, true) !== null) {
            throw new RuntimeException("MHC-08 journal {$key} is already active.");
        }
    }

    private function freshReceipt(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $completed = strtotime($value);
        $age = $completed === false ? -1 : time() - $completed;

        return $age >= 0 && $age <= 43200;
    }

    /** @param array<string,mixed> $value */
    private function containsProhibitedContent(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['prompt', 'system_prompt', 'output', 'response', 'messages'], true)) {
                return true;
            }
            if (is_array($item) && $this->containsProhibitedContent($item)) {
                return true;
            }
        }

        return false;
    }

    private function sha(mixed $value, int $length = 64): bool
    {
        return is_string($value)
            && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function policyControl(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'section', 'config_key', 'config_value']));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function workerControl(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'name', 'enabled']));
    }

    /** @return array<string,mixed>|null */
    private function journal(string $key, bool $lock = false): ?array
    {
        $query = DB::table('system_configs')->where('section', self::SECTION)->where('config_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : (array) $row;
    }

    /** @param array<string,mixed> $value */
    private function insertJournal(string $key, array $value): void
    {
        $inserted = DB::table('system_configs')->insert($this->filter('system_configs', [
            'section' => self::SECTION,
            'config_key' => $key,
            'config_value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => 'Private MHC-08 exact promotion preimage journal.',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        if (! $inserted) {
            throw new RuntimeException('MHC-08 journal creation failed.');
        }
    }

    /** @param array<string,mixed> $value */
    private function updateJournal(string $key, int $id, array $value): int
    {
        return DB::table('system_configs')->where('id', $id)->where('section', self::SECTION)->where('config_key', $key)
            ->update($this->filter('system_configs', [
                'config_value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]));
    }

    /** @param array<string,mixed> $values */
    private function exactUpdate(string $table, int $id, array $values, bool $touchTimestamp = true): void
    {
        if ($touchTimestamp) {
            $values['updated_at'] = now();
        }
        $affected = DB::table($table)->where('id', $id)->update($this->filter($table, $values));
        if ($affected !== 1) {
            throw new RuntimeException("MHC-08 {$table} postimage update was not exact.");
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withoutId(array $row): array
    {
        unset($row['id']);

        return $row;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function filter(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $snapshot */
    private function acceptanceContextSha(array $snapshot): string
    {
        $evidence = is_array($snapshot['evidence'] ?? null) ? $snapshot['evidence'] : [];

        return $this->hash([
            'schema' => 'plos.model_host_controller.mhc08_k3_acceptance_context.v1',
            'attempt_id' => $snapshot['attempt_id'] ?? null,
            'promoted_at' => $snapshot['promoted_at'] ?? null,
            'finalized_provenance_sha256' => $snapshot['finalized_provenance_sha256'] ?? null,
            'qualification_state_sha256' => $snapshot['qualification_state_sha256'] ?? null,
            'final_state_sha256' => $snapshot['final_state_sha256'] ?? null,
            'post_policy_sha256' => $snapshot['acceptance_post_policy_sha256']
                ?? $snapshot['post_policy_sha256'] ?? null,
            'post_provider_sha256' => $snapshot['post_provider_sha256'] ?? null,
            'post_profile_sha256' => $snapshot['post_profile_sha256'] ?? null,
            'post_policy_control_sha256' => $snapshot['acceptance_post_policy_control_sha256']
                ?? $snapshot['post_policy_control_sha256'] ?? null,
            'post_worker_control_sha256' => $snapshot['acceptance_post_worker_control_sha256']
                ?? $snapshot['post_worker_control_sha256'] ?? null,
            'evidence' => [
                'base_ref' => $evidence['base_ref'] ?? null,
                'staging_sha256' => $evidence['staging_sha256'] ?? null,
                'state_build_receipt_sha256' => $evidence['state_build_receipt_sha256'] ?? null,
                'mhc06_provenance_sha256' => $evidence['mhc06_provenance_sha256'] ?? null,
                'canary_sha256' => $evidence['canary_sha256'] ?? null,
                'cancellation_sha256' => $evidence['cancellation_sha256'] ?? null,
                'host_clean_sha256' => $evidence['host_clean_sha256'] ?? null,
            ],
        ]);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function assertSameFinalizedContract(array $expected, array $actual): void
    {
        foreach ([
            'base_ref', 'runtime_profile', 'runtime_revision', 'engine_sha256', 'patch_sha256',
            'patched_tree', 'state_build_receipt_sha256', 'mhc06_provenance_sha256',
        ] as $field) {
            if (($actual[$field] ?? null) !== ($expected[$field] ?? null)) {
                throw new RuntimeException('MHC-08 finalized State contract changed during acceptance.');
            }
        }
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->canonical($value));
    }

    /** @param array<string,mixed> $value */
    private function canonical(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);

            return array_map($normalize, $item);
        };

        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $extra */
    private function receipt(string $result, array $extra): array
    {
        return array_merge([
            'schema' => self::RECEIPT_SCHEMA,
            'result' => $result,
            'target_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'provider_instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'model' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'),
            'engine' => 'colibri-k3',
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ], $extra);
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }
}
