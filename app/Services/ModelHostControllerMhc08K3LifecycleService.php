<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Serial, explicit-only lifecycle for MHC-08's retained K3 target.
 * The executor is deliberately supplied by the inference boundary so this
 * service cannot become a normal router or a fallback path.
 */
class ModelHostControllerMhc08K3LifecycleService
{
    public const CANDIDATE_REVISION = 'MHC-08-k3-candidate-canary-v1';

    private const LOCK_KEY = 'model_host_controller:mhc08:k3:lifecycle';

    public function __construct(
        private readonly ModelHostControllerMhc08K3PolicyService $policies,
        private readonly ModelHostControllerClient $controller,
        private readonly ModelHostControllerQueueSnapshotService $queues,
    ) {}

    /** @param callable(array<string,mixed>):array<string,mixed> $executor @return array<string,mixed> */
    public function run(string $role, string $requestId, string $leaseId, callable $executor): array
    {
        $policy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-08 request identity is too long for lifecycle suffixes.');
        }
        if (($policy['enabled'] ?? false) !== true
            || ($policy['revision'] ?? null) !== ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION) {
            return $this->queuedReceipt($policy, $role, $requestId, $leaseId, 'policy_inactive');
        }
        $this->policies->assertExplicitRole($role, $policy);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $result = $lock->get(fn (): array => $this->runLocked($policy, $role, $requestId, $leaseId, $executor));
        if ($result === false) {
            return $this->queuedReceipt($policy, $role, $requestId, $leaseId, 'lifecycle_busy');
        }

        return $result;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $executor @return array<string,mixed> */
    public function runCandidateCanary(string $role, string $requestId, string $leaseId, callable $executor): array
    {
        $inactivePolicy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-08 candidate request identity is too long for lifecycle suffixes.');
        }
        $policy = $this->candidatePolicy($inactivePolicy, $role);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $result = $lock->get(fn (): array => $this->runLocked(
            $policy,
            $role,
            $requestId,
            $leaseId,
            $executor,
            $policy,
        ));
        if ($result === false) {
            return $this->queuedReceipt($policy, $role, $requestId, $leaseId, 'lifecycle_busy');
        }

        return $result;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $executor @return array<string,mixed> */
    public function runAcceptance(string $role, string $requestId, string $leaseId, callable $executor): array
    {
        $policy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-08 acceptance request identity is too long for lifecycle suffixes.');
        }
        $this->policies->assertAcceptanceRole($role, $policy);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $result = $lock->get(fn (): array => $this->runLocked(
            $policy,
            $role,
            $requestId,
            $leaseId,
            $executor,
            $policy,
        ));
        if ($result === false) {
            return $this->queuedReceipt($policy, $role, $requestId, $leaseId, 'lifecycle_busy');
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function recoverInterrupted(string $role, string $requestId, string $leaseId): array
    {
        $policy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110
            || ($policy['enabled'] ?? false) !== true
            || ($policy['revision'] ?? null) !== ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION) {
            throw new RuntimeException('MHC-08 interrupted lifecycle recovery is unavailable.');
        }
        $this->policies->assertExplicitRole($role, $policy);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $receipt = $lock->get(fn (): array => $this->recoverInterruptedLocked($policy, $role, $requestId, $leaseId));
        if ($receipt === false) {
            throw new RuntimeException('MHC-08 interrupted lifecycle recovery could not acquire the serial lock.');
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function recoverInterruptedCandidateCanary(string $role, string $requestId, string $leaseId): array
    {
        $inactivePolicy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-08 candidate recovery request identity is too long for lifecycle suffixes.');
        }
        $policy = $this->candidatePolicy($inactivePolicy, $role);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $receipt = $lock->get(fn (): array => $this->recoverInterruptedLocked(
            $policy,
            $role,
            $requestId,
            $leaseId,
            $policy,
        ));
        if ($receipt === false) {
            throw new RuntimeException('MHC-08 candidate recovery could not acquire the serial lock.');
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function recoverInterruptedAcceptance(string $role, string $requestId, string $leaseId): array
    {
        $policy = $this->policies->required();
        $role = trim($role);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-08 acceptance recovery request identity is too long for lifecycle suffixes.');
        }
        $this->policies->assertAcceptanceRole($role, $policy);

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lease_ttl_seconds']);
        $receipt = $lock->get(fn (): array => $this->recoverInterruptedLocked(
            $policy,
            $role,
            $requestId,
            $leaseId,
            $policy,
        ));
        if ($receipt === false) {
            throw new RuntimeException('MHC-08 acceptance recovery could not acquire the serial lock.');
        }

        return $receipt;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function recoverInterruptedLocked(
        array $policy,
        string $role,
        string $requestId,
        string $leaseId,
        ?array $clientPolicyOverride = null,
    ): array {
        $target = $policy['target'];
        $ownerRequestId = $requestId.'-owner';
        $result = 'not_required';
        $status = $this->controller->statusK3();
        if (! $this->statusProvesClean($status, $ownerRequestId, $leaseId)) {
            $recoveryRequestId = $requestId.'-recover-orphan';
            $recovery = $this->controllerRecover(
                $role,
                $recoveryRequestId,
                $ownerRequestId,
                $leaseId,
                $clientPolicyOverride,
            );
            $this->assertControllerResponse($recovery, $recoveryRequestId, $leaseId, $target);
            $this->assertResult($recovery, ['recovered']);
            $status = $this->controller->statusK3();
            if (! $this->statusProvesClean($status, $ownerRequestId, $leaseId)) {
                throw new RuntimeException('MHC-08 interrupted lifecycle recovery did not prove clean status.');
            }
            $result = 'restored';
        }

        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_interrupted_recovery.v1',
            'role' => $role,
            'target' => $target['target_id'],
            'request_id' => $requestId,
            'owner_request_id' => $ownerRequestId,
            'lease_id' => $leaseId,
            'result' => $result,
            'status_verified' => 'clean',
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,mixed> $policy @param callable(array<string,mixed>):array<string,mixed> $executor @return array<string,mixed> */
    private function runLocked(
        array $policy,
        string $role,
        string $baseRequestId,
        string $leaseId,
        callable $executor,
        ?array $clientPolicyOverride = null,
    ): array {
        $target = $policy['target'];
        $planRequestId = $baseRequestId.'-plan';
        // The host binds the lease to the activation request owner. Heartbeats and
        // release must reuse that exact owner identity; only planning/recovery get
        // distinct request identities.
        $ownerRequestId = $baseRequestId.'-owner';
        $releaseRequestId = $ownerRequestId;
        $before = $this->queues->capture();
        $plan = null;
        $activation = null;
        $release = null;
        $execution = null;
        $activationAttempted = false;
        $activationSucceeded = false;
        $failure = null;

        try {
            $plan = $this->controllerPlan($role, $planRequestId, $clientPolicyOverride);
            $this->assertControllerResponse($plan, $planRequestId, null, $target);
            $this->assertResult($plan, ['ready']);

            $activationAttempted = true;
            $activation = $this->controllerActivate($role, $ownerRequestId, $leaseId, $clientPolicyOverride);
            $this->assertControllerResponse($activation, $ownerRequestId, $leaseId, $target);
            $this->assertResult($activation, ['activated', 'already_ready']);
            $activationSucceeded = true;

            $heartbeatBefore = $this->controllerHeartbeat($role, $ownerRequestId, $leaseId, $clientPolicyOverride);
            $this->assertControllerResponse($heartbeatBefore, $ownerRequestId, $leaseId, $target);
            $this->assertRuntimeIdentityMatches($activation, $heartbeatBefore);
            $this->assertResult($heartbeatBefore, ['renewed']);

            $execution = $executor([
                'role' => $role,
                'target' => $target['target_id'],
                'provider_instance_id' => $target['provider_instance_id'],
                'provider_model_role' => $target['provider_model_role'],
                'model' => $target['model'],
                'engine' => $target['engine'],
            ]);
            if (! is_array($execution)) {
                throw new RuntimeException('MHC-08 K3 inference returned an invalid result.');
            }
            $heartbeatAfter = $this->controllerHeartbeat($role, $ownerRequestId, $leaseId, $clientPolicyOverride);
            $this->assertControllerResponse($heartbeatAfter, $ownerRequestId, $leaseId, $target);
            $this->assertRuntimeIdentityMatches($activation, $heartbeatAfter);
            $this->assertResult($heartbeatAfter, ['renewed']);
        } catch (\Throwable $exception) {
            $failure = $exception;
            if ($activationAttempted && ! $activationSucceeded) {
                try {
                    $status = $this->controller->statusK3();
                    if (! $this->statusProvesClean($status, $ownerRequestId, $leaseId)) {
                        $recovery = $this->controllerRecover(
                            $role,
                            $baseRequestId.'-recover',
                            $ownerRequestId,
                            $leaseId,
                            $clientPolicyOverride,
                        );
                        $this->assertControllerResponse($recovery, $baseRequestId.'-recover', $leaseId, $target);
                        $this->assertResult($recovery, ['recovered']);
                        if (! $this->statusProvesClean($this->controller->statusK3(), $ownerRequestId, $leaseId)) {
                            throw new RuntimeException('MHC-08 K3 activation recovery did not prove clean status.');
                        }
                    }
                } catch (\Throwable $recoveryException) {
                    $failure = new RuntimeException('MHC-08 K3 controller recovery cleanup failed.', 0, $recoveryException);
                }
            }
        } finally {
            if ($activationSucceeded) {
                try {
                    $release = $this->controllerRelease($role, $releaseRequestId, $leaseId, $clientPolicyOverride);
                    $this->assertControllerResponse($release, $releaseRequestId, $leaseId, $target);
                    $this->assertRuntimeIdentityMatches($activation, $release);
                    $this->assertResult($release, ['released']);
                } catch (\Throwable $releaseException) {
                    try {
                        // A lost release response is recoverable only when a read-only
                        // status proves the host is already clean. Otherwise perform one
                        // bounded recovery and prove clean state again before green.
                        $status = $this->controller->statusK3();
                        if ($this->statusProvesClean($status, $ownerRequestId, $leaseId)) {
                            $release = $this->verifiedRelease($releaseRequestId, $leaseId, $status, 'released_status_verified', $ownerRequestId);
                        } else {
                            throw new RuntimeException('MHC-08 K3 release status was not clean.');
                        }
                    } catch (\Throwable $statusException) {
                        try {
                            $recoveryRequestId = $baseRequestId.'-recover-release';
                            $recovery = $this->controllerRecover(
                                $role,
                                $recoveryRequestId,
                                $ownerRequestId,
                                $leaseId,
                                $clientPolicyOverride,
                            );
                            $this->assertControllerResponse($recovery, $recoveryRequestId, $leaseId, $target);
                            $this->assertResult($recovery, ['recovered']);
                            $status = $this->controller->statusK3();
                            $release = $this->verifiedRelease($releaseRequestId, $leaseId, $status, 'recovered_status_verified', $ownerRequestId);
                        } catch (\Throwable $recoveryException) {
                            $failure = new RuntimeException('MHC-08 K3 controller release cleanup failed; clean status was not proven.', 0, $recoveryException);
                        }
                    }
                }
            }
        }

        $after = $this->queues->capture();
        if ($failure !== null) {
            return $this->queuedReceipt(
                $policy,
                $role,
                $baseRequestId,
                $leaseId,
                $this->failureReason($failure),
                $plan,
                $activation,
                $release,
                $before,
                $after,
            );
        }

        if (! is_array($plan) || ! is_array($activation) || ! is_array($release)) {
            return $this->queuedReceipt($policy, $role, $baseRequestId, $leaseId, 'incomplete_lifecycle', $plan, $activation, $release, $before, $after);
        }

        return $this->receipt($policy, $role, $baseRequestId, $leaseId, $plan, $activation, $release, $before, $after);
    }

    /** @param array<string,mixed> $policy @param array<string,mixed>|null $plan @param array<string,mixed>|null $activation @param array<string,mixed>|null $release @param array<string,mixed>|null $before @param array<string,mixed>|null $after @return array<string,mixed> */
    private function queuedReceipt(array $policy, string $role, string $requestId, string $leaseId, string $reason, ?array $plan = null, ?array $activation = null, ?array $release = null, ?array $before = null, ?array $after = null): array
    {
        return [
            ...$this->baseReceipt($policy, $role, $requestId, $leaseId),
            'plan_request_id' => $plan['request_id'] ?? $requestId.'-plan',
            'owner_request_id' => $activation['request_id'] ?? $requestId.'-owner',
            'activation_request_id' => $activation['request_id'] ?? $requestId.'-owner',
            'release_request_id' => $release['request_id'] ?? $requestId.'-owner',
            'plan_result' => $plan['result'] ?? null,
            'activation_result' => $activation['result'] ?? null,
            'release_result' => $release['result'] ?? null,
            // These hashes are content-free observations. Queue activity unrelated to this
            // explicit K3 request is allowed and does not by itself invalidate the receipt.
            'queue_preserved' => $before !== null && $after !== null
                ? $this->preservationHash($before) === $this->preservationHash($after)
                : null,
            'queue_before_sha256' => $before['snapshot_sha256'] ?? null,
            'queue_after_sha256' => $after['snapshot_sha256'] ?? null,
            'reason' => $reason,
            'result' => 'queued',
        ];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $plan @param array<string,mixed> $activation @param array<string,mixed> $release @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,mixed> */
    private function receipt(array $policy, string $role, string $requestId, string $leaseId, array $plan, array $activation, array $release, array $before, array $after): array
    {
        return [
            ...$this->baseReceipt($policy, $role, $requestId, $leaseId),
            'plan_request_id' => $plan['request_id'],
            'owner_request_id' => $activation['request_id'],
            'activation_request_id' => $activation['request_id'],
            'release_request_id' => $release['request_id'],
            'plan_result' => $plan['result'],
            'activation_result' => $activation['result'],
            'release_result' => $release['result'],
            'runtime_profile' => $activation['runtime_profile'],
            'runtime_revision' => $activation['runtime_revision'],
            'engine_sha256' => $activation['engine_sha256'],
            'patch_sha256' => $activation['patch_sha256'],
            'patched_tree' => $activation['patched_tree'],
            // Observational only: unrelated queue churn may change the hashes while the
            // inference and lifecycle still complete successfully.
            'queue_preserved' => $this->preservationHash($before) === $this->preservationHash($after),
            'queue_before_sha256' => $before['snapshot_sha256'] ?? null,
            'queue_after_sha256' => $after['snapshot_sha256'] ?? null,
            'result' => 'green',
        ];
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function baseReceipt(array $policy, string $role, string $requestId, string $leaseId): array
    {
        $target = $policy['target'];

        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_receipt.v1',
            'phase' => 'MHC-08',
            'revision' => $policy['revision'],
            'role' => $role,
            'target' => $target['target_id'],
            'provider_instance_id' => $target['provider_instance_id'],
            'provider_model_role' => $target['provider_model_role'],
            'model' => $target['model'],
            'engine' => $target['engine'],
            'request_id' => $requestId,
            'lease_id' => $leaseId,
            'execution_mode' => 'serial',
            'automatic_selection_enabled' => false,
            'normal_routing' => false,
            'concurrent_inference_enabled' => false,
            'max_concurrent' => 1,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_on_failure' => true,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $target */
    private function assertControllerResponse(array $response, string $requestId, ?string $leaseId, array $target): void
    {
        if (($response['request_id'] ?? null) !== $requestId
            || ($response['target'] ?? null) !== $target['target_id']
            || ($leaseId !== null && ($response['lease_id'] ?? null) !== $leaseId)
            || (isset($response['provider_instance_id']) && $response['provider_instance_id'] !== $target['provider_instance_id'])
            || (isset($response['model']) && $response['model'] !== $target['model'])
            || (isset($response['engine']) && $response['engine'] !== $target['engine'])
            || ($response['contains_prompt_or_response_content'] ?? false) !== false) {
            throw new RuntimeException('MHC-08 K3 controller returned an inexact lifecycle response.');
        }
    }

    /** @param array<string,mixed> $response @param list<string> $allowed */
    private function assertResult(array $response, array $allowed): void
    {
        if (! in_array($response['result'] ?? null, $allowed, true)) {
            throw new RuntimeException('MHC-08 K3 controller returned a non-green lifecycle result.');
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function assertRuntimeIdentityMatches(array $expected, array $actual): void
    {
        foreach (['runtime_profile', 'runtime_revision', 'engine_sha256', 'patch_sha256', 'patched_tree'] as $field) {
            if (! is_string($expected[$field] ?? null)
                || $expected[$field] === ''
                || ($actual[$field] ?? null) !== $expected[$field]) {
                throw new RuntimeException('MHC-08 K3 controller runtime identity drifted during the lifecycle.');
            }
        }
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function candidatePolicy(array $policy, string $role): array
    {
        $expectedTarget = [
            'target_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'provider_instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'provider_model_role' => 'deep_offline',
            'model' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'),
            'engine' => 'colibri-k3',
            'requires_explicit_target' => true,
        ];
        $expectedKeys = [
            'schema', 'revision', 'enabled', 'automatic_selection_enabled', 'normal_routing',
            'execution_mode', 'concurrent_inference_enabled', 'tools_enabled', 'fallback_enabled',
            'queue_on_failure', 'max_concurrent', 'max_tokens', 'lease_ttl_seconds',
            'startup_timeout_seconds', 'roles', 'target',
        ];
        $actualKeys = array_keys($policy);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || ($policy['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_policy.v1'
            || ($policy['revision'] ?? null) !== ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION
            || ($policy['enabled'] ?? null) !== false
            || ($policy['automatic_selection_enabled'] ?? null) !== false
            || ($policy['normal_routing'] ?? null) !== false
            || ($policy['execution_mode'] ?? null) !== 'serial'
            || ($policy['concurrent_inference_enabled'] ?? null) !== false
            || ($policy['tools_enabled'] ?? null) !== false
            || ($policy['fallback_enabled'] ?? null) !== false
            || ($policy['queue_on_failure'] ?? null) !== true
            || ($policy['max_concurrent'] ?? null) !== 1
            || ($policy['max_tokens'] ?? null) !== 128
            || ($policy['lease_ttl_seconds'] ?? null) !== 25200
            || ($policy['startup_timeout_seconds'] ?? null) !== 1800
            || ($policy['roles'] ?? null) !== ['ultra_deep', 'archival']
            || ($policy['target'] ?? null) !== $expectedTarget
            || ! in_array($role, ['ultra_deep', 'archival'], true)) {
            throw new RuntimeException('MHC-08 candidate canary requires the exact inactive policy and role.');
        }

        return [
            ...$policy,
            'revision' => self::CANDIDATE_REVISION,
            'enabled' => true,
        ];
    }

    /** @param array<string,mixed>|null $policy */
    private function controllerPlan(string $role, string $requestId, ?array $policy): array
    {
        return $policy === null
            ? $this->controller->planK3($role, $requestId)
            : $this->controller->planK3($role, $requestId, $policy);
    }

    /** @param array<string,mixed>|null $policy */
    private function controllerActivate(string $role, string $requestId, string $leaseId, ?array $policy): array
    {
        return $policy === null
            ? $this->controller->activateK3($role, $requestId, $leaseId)
            : $this->controller->activateK3($role, $requestId, $leaseId, $policy);
    }

    /** @param array<string,mixed>|null $policy */
    private function controllerHeartbeat(string $role, string $requestId, string $leaseId, ?array $policy): array
    {
        return $policy === null
            ? $this->controller->heartbeatK3($role, $requestId, $leaseId)
            : $this->controller->heartbeatK3($role, $requestId, $leaseId, $policy);
    }

    /** @param array<string,mixed>|null $policy */
    private function controllerRelease(string $role, string $requestId, string $leaseId, ?array $policy): array
    {
        return $policy === null
            ? $this->controller->releaseK3($role, $requestId, $leaseId)
            : $this->controller->releaseK3($role, $requestId, $leaseId, $policy);
    }

    /** @param array<string,mixed>|null $policy */
    private function controllerRecover(
        string $role,
        string $requestId,
        string $ownerRequestId,
        string $leaseId,
        ?array $policy,
    ): array {
        return $policy === null
            ? $this->controller->recoverK3($role, $requestId, $ownerRequestId, $leaseId)
            : $this->controller->recoverK3($role, $requestId, $ownerRequestId, $leaseId, $policy);
    }

    private function identity(string $value, string $kind): string
    {
        $value = trim($value);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $value) !== 1) {
            throw new RuntimeException("MHC-08 {$kind} identity is invalid.");
        }

        return $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function preservationHash(array $snapshot): mixed
    {
        return $snapshot['preservation_sha256'] ?? $snapshot['snapshot_sha256'] ?? null;
    }

    private function failureReason(\Throwable $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'release cleanup') => 'controller_release_cleanup_failed',
            str_contains($exception->getMessage(), 'queue snapshot') => 'queue_snapshot_changed',
            default => 'lifecycle_failed',
        };
    }

    /** @param array<string,mixed> $status */
    private function statusProvesClean(array $status, string $ownerRequestId, string $leaseId): bool
    {
        $state = $status['state'] ?? null;
        $ingress = $status['ingress'] ?? null;
        $restore = $status['coexistence_restore_proof'] ?? null;
        $targets = is_array($restore) ? ($restore['targets'] ?? null) : null;
        $currentTargets = $status['coexistence_current_targets'] ?? null;
        $expectedTargets = array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets']);
        $actualTargets = is_array($targets) ? array_column($targets, 'target') : [];
        $targetsGreen = is_array($targets) && count($targets) === 3;
        foreach (is_array($targets) ? $targets : [] as $target) {
            $targetsGreen = $targetsGreen
                && is_array($target)
                && ($target['task_exact'] ?? false) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? false) === true);
        }
        $currentTargetsGreen = is_array($currentTargets) && count($currentTargets) === 3;
        foreach (is_array($currentTargets) ? $currentTargets : [] as $target) {
            $currentTargetsGreen = $currentTargetsGreen
                && is_array($target)
                && ($target['task_exact'] ?? false) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? false) === true);
        }

        return is_array($state)
            && ($state['lease'] ?? null) === null
            && ($status['task_exact'] ?? null) === true
            && ($status['task_enabled'] ?? null) === false
            && ! in_array($status['task_state'] ?? null, ['Running', 'Queued'], true)
            && ($status['candidate_task_present'] ?? null) === true
            && ($status['candidate_task_exact'] ?? null) === true
            && ($status['candidate_task_enabled'] ?? null) === false
            && ! in_array($status['candidate_task_state'] ?? null, ['Running', 'Queued'], true)
            && ($status['health_required'] ?? null) === false
            && is_array($status['health'] ?? null)
            && ($status['health']['healthy'] ?? null) === false
            && ($status['restart_state_present'] ?? null) === true
            && is_string($status['restart_state_sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $status['restart_state_sha256']) === 1
            && ($status['restart_state_temporary_present'] ?? null) === false
            && (int) ($status['process_count'] ?? -1) === 0
            && (int) ($status['listener_count'] ?? -1) === 0
            && is_array($ingress)
            && ($ingress['ingress_open'] ?? true) === false
            && ($ingress['firewall_present'] ?? true) === false
            && ($ingress['exact_proxy'] ?? true) === false
            && (int) ($ingress['port_8001_proxy_count'] ?? -1) === 0
            && (int) ($ingress['other_port_8001_proxy_count'] ?? -1) === 0
            && is_array($restore)
            && ($restore['schema'] ?? null) === 'plos.model_host_controller.mhc08_coexistence_restore.v1'
            && ($restore['result'] ?? null) === 'green'
            && ($restore['mode'] ?? null) === 'Restored'
            && ($restore['owner_request_id'] ?? null) === $ownerRequestId
            && ($restore['lease_id'] ?? null) === $leaseId
            && $targetsGreen
            && $actualTargets === $expectedTargets
            && ($status['coexistence_current'] ?? null) === true
            && $currentTargetsGreen
            && array_column($currentTargets, 'target') === $expectedTargets
            && ($restore['content_free'] ?? false) === true
            && ($restore['contains_prompt_or_response_content'] ?? true) === false;
    }

    /** @param array<string,mixed> $status */
    private function verifiedRelease(string $requestId, string $leaseId, array $status, string $result, string $ownerRequestId): array
    {
        if (! $this->statusProvesClean($status, $ownerRequestId, $leaseId)) {
            throw new RuntimeException('MHC-08 K3 status did not prove a clean release.');
        }

        return [
            'request_id' => $requestId,
            'lease_id' => $leaseId,
            'result' => $result,
            'status_verified' => 'clean',
            'runtime_profile' => $status['runtime_profile'] ?? null,
            'runtime_revision' => $status['runtime_revision'] ?? null,
            'engine_sha256' => $status['engine_sha256'] ?? null,
            'patch_sha256' => $status['patch_sha256'] ?? null,
            'patched_tree' => $status['patched_tree'] ?? null,
        ];
    }
}
