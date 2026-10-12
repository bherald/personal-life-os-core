<?php

namespace App\Services;

use RuntimeException;

/** Cheap post-canary proof that the exact State host is clean before promotion. */
class ModelHostControllerMhc08K3HostCleanQualificationService
{
    public const RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_host_clean_qualification.v1';

    public function __construct(
        private readonly ModelHostControllerClient $controller,
        private readonly ModelHostControllerMhc08K3StateContractService $state,
        private readonly ModelHostControllerMhc08K3EvidenceService $evidence,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $contract = $this->state->finalized();
        $activation = $this->evidence->read('activation-transfer');
        $qualification = $this->evidence->read('mhc08c-qualification');
        $this->assertActivation($activation['receipt'], $contract);
        $this->assertQualification($qualification['receipt'], $contract);
        $status = $this->controller->statusK3();
        $ingress = is_array($status['ingress'] ?? null) ? $status['ingress'] : [];
        $resources = is_array($status['resources'] ?? null) ? $status['resources'] : [];
        $health = is_array($status['health'] ?? null) ? $status['health'] : [];
        $lifecycle = is_array($status['state'] ?? null) ? $status['state'] : [];
        $restore = is_array($status['coexistence_restore_proof'] ?? null)
            ? $status['coexistence_restore_proof'] : [];
        $targets = is_array($restore['targets'] ?? null) ? $restore['targets'] : [];
        $coexistenceRestored = ($restore['schema'] ?? null) === 'plos.model_host_controller.mhc08_coexistence_restore.v1'
            && ($restore['result'] ?? null) === 'green'
            && ($restore['mode'] ?? null) === 'Restored'
            && array_map(static fn (array $target): mixed => $target['target'] ?? null, $targets)
                === ['qwen38_87', 'ollama_qwen3_8b_87', 'bitnet_87']
            && collect($targets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true));
        $currentTargets = is_array($status['coexistence_current_targets'] ?? null)
            ? $status['coexistence_current_targets'] : [];
        $coexistenceCurrent = ($status['coexistence_current'] ?? null) === true
            && array_map(static fn (mixed $target): mixed => is_array($target)
                ? ($target['target'] ?? null) : null, $currentTargets)
                === ['qwen38_87', 'ollama_qwen3_8b_87', 'bitnet_87']
            && collect($currentTargets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true));
        $restartStateExact = ($status['restart_state_present'] ?? null) === true
            && $this->sha($status['restart_state_sha256'] ?? null)
            && ($status['restart_state_sha256'] ?? null) === $qualification['receipt']['final_state_sha256'];
        $identityExact = ($status['runtime_profile'] ?? null) === 'State'
            && ($status['runtime_revision'] ?? null) === $contract['runtime_revision']
            && ($status['engine_sha256'] ?? null) === $contract['engine_sha256']
            && ($status['patch_sha256'] ?? null) === $contract['patch_sha256']
            && ($status['patched_tree'] ?? null) === $contract['patched_tree'];
        $leaseInactive = ! is_array($lifecycle['lease'] ?? null);
        $ingressClosed = ($ingress['ingress_open'] ?? null) === false
            && ($ingress['firewall_present'] ?? null) === false
            && ($ingress['exact_proxy'] ?? null) === false
            && (int) ($ingress['port_8001_proxy_count'] ?? 0) === 0
            && (int) ($ingress['other_port_8001_proxy_count'] ?? 0) === 0;
        $clean = $identityExact && $leaseInactive
            && (int) ($status['process_count'] ?? -1) === 0
            && (int) ($status['listener_count'] ?? -1) === 0
            && $ingressClosed && $coexistenceRestored && $coexistenceCurrent && $restartStateExact
            && ($status['task_exact'] ?? null) === true
            && ($status['task_enabled'] ?? null) === false
            && ! in_array($status['task_state'] ?? null, ['Running', 'Queued'], true)
            && ($status['candidate_task_present'] ?? null) === true
            && ($status['candidate_task_exact'] ?? null) === true
            && ($status['candidate_task_enabled'] ?? null) === false
            && ! in_array($status['candidate_task_state'] ?? null, ['Running', 'Queued'], true)
            && ($status['health_required'] ?? null) === false
            && ($health['healthy'] ?? null) === false
            && ($status['restart_state_temporary_present'] ?? null) === false
            && ($resources['gate_passed'] ?? null) === true;
        if (! $clean) {
            throw new RuntimeException('MHC-08 host-clean qualification did not prove exact idle State posture.');
        }

        $receipt = [
            'schema' => self::RECEIPT_SCHEMA,
            'result' => 'green',
            'host_id' => '.87',
            'target' => 'kimi_k3_87',
            'runtime_profile' => 'State',
            'runtime_revision' => $contract['runtime_revision'],
            'state_engine_sha256' => $contract['engine_sha256'],
            'state_patch_sha256' => $contract['patch_sha256'],
            'state_patched_tree' => $contract['patched_tree'],
            'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
            'mhc06_provenance_sha256' => $contract['mhc06_provenance_sha256'],
            'activation_mhc06_provenance_sha256' => $activation['receipt']['activation_mhc06_provenance_sha256'],
            'activation_transfer_sha256' => $activation['sha256'],
            'mhc08c_qualification_sha256' => $qualification['sha256'],
            'qualification_state_sha256' => $qualification['receipt']['qualification_state_sha256'],
            'final_state_sha256' => $qualification['receipt']['final_state_sha256'],
            'restart_state_sha256' => $status['restart_state_sha256'],
            'controller_clean' => true,
            'lease_inactive' => true,
            'process_count' => 0,
            'listener_count' => 0,
            'ingress_closed' => true,
            'coexistence_restored' => true,
            'coexistence_current' => true,
            'stable_task_exact' => true,
            'stable_task_disabled' => true,
            'resource_guards_green' => true,
            'completed_at' => now()->toIso8601String(),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
        $this->evidence->store('host-clean', $receipt);

        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $contract */
    private function assertActivation(array $receipt, array $contract): void
    {
        if (array_keys($receipt) !== [
            'schema', 'action', 'result', 'host_id', 'baseline_sha256', 'staging_sha256',
            'private_package_identity', 'executor_sha256', 'state_build_receipt_sha256',
            'mhc06_provenance_sha256', 'activation_mhc06_provenance_sha256',
            'checkpoint_state', 'activation_allowed', 'completed_at',
            'destination_count', 'content_free', 'contains_prompt_or_response_content',
        ]
            || ($receipt['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_activation_transfer.v1'
            || ($receipt['action'] ?? null) !== 'transfer' || ($receipt['result'] ?? null) !== 'transferred'
            || ($receipt['host_id'] ?? null) !== '.87' || ($receipt['checkpoint_state'] ?? null) !== 'transferred'
            || ($receipt['activation_allowed'] ?? null) !== true || (int) ($receipt['destination_count'] ?? 0) !== 4
            || ($receipt['baseline_sha256'] ?? null) !== $contract['baseline_sha256']
            || ($receipt['staging_sha256'] ?? null) !== $contract['staging_sha256']
            || ($receipt['private_package_identity'] ?? null) !== $contract['base_ref']
            || ($receipt['state_build_receipt_sha256'] ?? null) !== $contract['state_build_receipt_sha256']
            || ($receipt['mhc06_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
            || ! $this->sha($receipt['activation_mhc06_provenance_sha256'] ?? null)
            || ! $this->sha($receipt['executor_sha256'] ?? null)
            || strtotime((string) ($receipt['completed_at'] ?? '')) === false) {
            throw new RuntimeException('MHC-08 activation-transfer ancestry is not exact.');
        }
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $contract */
    private function assertQualification(array $receipt, array $contract): void
    {
        if (array_keys($receipt) !== [
            'schema', 'result', 'controls_passed', 'host_id', 'target', 'runtime_profile',
            'runtime_revision', 'state_engine_sha256', 'state_patch_sha256', 'state_patched_tree',
            'first_publication_passed', 'restart_admission_passed', 'restart_republished',
            'divergent_clean_miss_passed', 'corrupt_state_refused', 'state_restored_exactly',
            'stable_task_rollback_reinstall_passed', 'resource_guards_green', 'coexistence_targets',
            'coexistence_restored', 'process_count', 'listener_count', 'ingress_closed', 'lease_inactive',
            'first_runner_receipt_sha256', 'restart_runner_receipt_sha256',
            'divergent_runner_receipt_sha256', 'corrupt_runner_receipt_sha256',
            'qualification_state_sha256', 'final_state_sha256', 'preexisting_state_restored',
            'preexisting_state_sha256', 'completed_at', 'content_free', 'contains_prompt_or_response_content',
        ]) {
            throw new RuntimeException('MHC-08C qualification receipt fields are not exact.');
        }
        foreach ([
            'first_runner_receipt_sha256', 'restart_runner_receipt_sha256',
            'divergent_runner_receipt_sha256', 'corrupt_runner_receipt_sha256',
            'qualification_state_sha256', 'final_state_sha256',
        ] as $field) {
            if (! $this->sha($receipt[$field] ?? null)) {
                throw new RuntimeException('MHC-08C qualification receipt hash is invalid.');
            }
        }
        if (($receipt['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_state_qualification.v1'
            || ($receipt['result'] ?? null) !== 'green' || ($receipt['controls_passed'] ?? null) !== true
            || ($receipt['host_id'] ?? null) !== '.87' || ($receipt['target'] ?? null) !== 'kimi_k3_87'
            || ($receipt['runtime_profile'] ?? null) !== 'State'
            || ($receipt['runtime_revision'] ?? null) !== $contract['runtime_revision']
            || ($receipt['state_engine_sha256'] ?? null) !== $contract['engine_sha256']
            || ($receipt['state_patch_sha256'] ?? null) !== $contract['patch_sha256']
            || ($receipt['state_patched_tree'] ?? null) !== $contract['patched_tree']
            || ($receipt['first_publication_passed'] ?? null) !== true
            || ($receipt['restart_admission_passed'] ?? null) !== true
            || ($receipt['restart_republished'] ?? null) !== true
            || ($receipt['divergent_clean_miss_passed'] ?? null) !== true
            || ($receipt['corrupt_state_refused'] ?? null) !== true
            || ($receipt['state_restored_exactly'] ?? null) !== true
            || ($receipt['stable_task_rollback_reinstall_passed'] ?? null) !== true
            || ($receipt['resource_guards_green'] ?? null) !== true
            || ($receipt['coexistence_targets'] ?? null) !== ['qwen38_87', 'ollama_qwen3_8b_87', 'bitnet_87']
            || ($receipt['coexistence_restored'] ?? null) !== true
            || (int) ($receipt['process_count'] ?? -1) !== 0
            || (int) ($receipt['listener_count'] ?? -1) !== 0
            || ($receipt['ingress_closed'] ?? null) !== true || ($receipt['lease_inactive'] ?? null) !== true
            || ! is_bool($receipt['preexisting_state_restored'] ?? null)
            || (($receipt['preexisting_state_restored'] ?? null) === true
                && (! $this->sha($receipt['preexisting_state_sha256'] ?? null)
                    || ($receipt['preexisting_state_sha256'] ?? null) !== $receipt['final_state_sha256']))
            || (($receipt['preexisting_state_restored'] ?? null) === false
                && (($receipt['preexisting_state_sha256'] ?? null) !== null
                    || ($receipt['qualification_state_sha256'] ?? null) !== $receipt['final_state_sha256']))
            || ($receipt['content_free'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
            || strtotime((string) ($receipt['completed_at'] ?? '')) === false) {
            throw new RuntimeException('MHC-08C State qualification is incomplete or identity-drifted.');
        }
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
