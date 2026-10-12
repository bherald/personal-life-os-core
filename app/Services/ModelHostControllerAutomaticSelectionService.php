<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MHC-06's guarded, serial selection boundary. It is intentionally not wired
 * into normal routing; the separate table policy must explicitly enable it.
 */
class ModelHostControllerAutomaticSelectionService
{
    private const LOCK_KEY = 'model_host_controller:mhc06:automatic_selection';

    private const ACTIVE_KEY = 'model_host_controller:mhc06:active_selection';

    private const CURRENT_TARGET_KEY = 'model_host_controller:mhc06:current_target';

    private const SWITCHES_KEY = 'model_host_controller:mhc06:switches';

    private ?array $activeInference = null;

    public function __construct(
        private readonly ModelHostControllerAutomaticSelectionPolicyService $policies,
        private readonly ModelHostControllerCoordinatorService $coordinator,
        private readonly ModelHostControllerClient $controller,
        private readonly ModelHostControllerSelectionJournal $journal,
        private readonly ?\Closure $monotonicClock = null,
    ) {}

    /** Acquire the existing durable slot for an exact, table-admitted transport. */
    public function beginExact(array $request, array $target): array
    {
        try {
            $request = $this->request($request);
            $policy = $this->policies->required();
            $controllerPolicy = app(ModelHostControllerPolicyService::class)->required();
            $targetId = $request['request_contract']['controller_target'] ?? null;
            $registered = $controllerPolicy['targets'][$targetId] ?? null;
            if (! $policy['enabled'] || ! $policy['automatic_selection_enabled']
                || ! $controllerPolicy['enabled'] || ! $controllerPolicy['automatic_activation_enabled']
                || ! is_array($registered) || ! $registered['activation_allowed']
                || ($registered['requires_operator_override'] ?? true)
                || ! in_array($request['role'], $registered['roles'] ?? [], true)
                || ($target['model'] ?? null) !== $registered['model']
                || ($target['engine'] ?? null) !== $registered['engine']) {
                throw new RuntimeException('Exact controller transport is not admitted by current tables.');
            }
            ModelHostControllerResourcePolicyService::assertReceiptBinding($request['capacity_binding'],
                app(ModelHostControllerResourcePolicyService::class)->assertRequestContract(
                    $controllerPolicy['host_id'], $targetId, $request['request_contract']));
        } catch (\Throwable) {
            // Preparation above reads tables only; no journal, lifecycle or model action started.
            throw new ModelHostControllerUnavailableException('rejected', dispatched: false);
        }
        $owner = $this->journal->begin([$request]);
        if ($owner === null) {
            $state = $this->journal->inspect();
            // begin returned without acquiring this caller's journal or host custody.
            // Another invocation's unresolved work must not create a second unknown owner.
            if (in_array($state['phase'] ?? null, ['owned', 'outcome_unknown'], true)) {
                throw new ModelHostControllerUnavailableException('busy');
            }
            if (($state['phase'] ?? null) === 'idle') {
                // Do not replay an idle refusal, including a retained request identity.
                throw new ModelHostControllerUnavailableException('rejected', dispatched: false);
            }
            throw new RuntimeException('Existing controller work needs reconciliation; no replay is allowed.');
        }
        $scope = ['owner' => $owner, 'request' => $request, 'policy' => $policy,
            'controller_policy' => $controllerPolicy, 'target' => $targetId,
            'target_policy' => $target, 'activation' => null, 'activation_attempted' => false,
            'dispatched' => false, 'completed' => false, 'closed' => false];
        try {
            $status = $this->controller->targetStatus($targetId);
            if (! is_bool($status['lifecycle']['lease_present'] ?? null) || ! is_bool($status['k3_lease_active'] ?? null)) {
                throw new RuntimeException('Controller ownership observation is incomplete.');
            }
            if ($status['lifecycle']['lease_present'] || $status['k3_lease_active']) {
                throw new ModelHostControllerUnavailableException('busy');
            }
            $id = $request['request_id'].'-plan';
            $plan = $this->controller->plan($targetId, $id);
            $this->assertControllerResponse($plan, $id, $targetId, null, $target);
            ModelHostControllerResourcePolicyService::assertReceiptBinding($request['capacity_binding'], $plan['resource_assessment']['request_binding'] ?? null);
            $shortage = ModelHostControllerResourcePolicyService::temporaryShortage($plan, $targetId, $target['model'], $target['engine']);
            if ($shortage !== null) {
                throw new ModelHostControllerUnavailableException('capacity', capacityShortage: [
                    'phase' => $plan['resource_assessment']['phase'], 'requirements' => $shortage,
                ]);
            }
            if (! ModelHostControllerResourcePolicyService::availableCapacity($plan, $targetId, $target['model'], $target['engine'])) {
                throw new RuntimeException('Exact controller plan did not prove capacity.');
            }
            $this->assertResult($plan, ['ready']);
            $this->consumeSwitchBudget($targetId, $controllerPolicy['max_switches_per_hour']);
            $this->journal->checkpoint($owner, $request['request_id'], 'activation_pending');
            $scope['activation_attempted'] = true;
            $id = $request['request_id'].'-activate';
            $activation = $this->controller->activate($targetId, $id, $request['lease_id']);
            $this->assertControllerResponse($activation, $id, $targetId, $request['lease_id'], $target);
            $this->assertResult($activation, ['activated', 'already_ready']);
            ModelHostControllerResourcePolicyService::assertReceiptBinding($request['capacity_binding'], $activation['resource_assessment']['request_binding'] ?? null);
            $scope['activation'] = $activation;
            $scope['expires_at'] = $this->leaseDeadline($activation, $policy);
            $scope['next_renewal_at'] = $this->clock();

            return $scope;
        } catch (\Throwable $error) {
            $result = $scope['activation_attempted'] ? 'held' : 'queued';
            $this->journal->finish($owner, ['result' => $result, 'results' => [[...$request, 'result' => $result]]]);
            if ($scope['activation_attempted']) {
                throw new InferenceAdmissionException('Controller activation outcome requires reconciliation; no replay is allowed.', 0, $error);
            }
            if ($error instanceof ModelHostControllerUnavailableException) {
                if ($error->reason === 'capacity' && ! $error->dispatched) {
                    // Only this completed original journal cleanup proves capacity custody clear.
                    throw new ModelHostControllerUnavailableException('capacity', capacityShortage: $error->capacityShortage,
                        capacityCustodyCleared: true);
                }
                throw $error;
            }
            // No activation was attempted and our durable reservation was cleared above.
            throw new ModelHostControllerUnavailableException('rejected', dispatched: false, previous: $error);
        }
    }

    /** Renew while reading a stream as well as before dispatch; never store request content. */
    public function renewExact(array &$scope, bool $force = false): void
    {
        if ($scope['closed'] || $this->policies->required() !== $scope['policy']
            || app(ModelHostControllerPolicyService::class)->required() !== $scope['controller_policy']) {
            throw new RuntimeException('Controller transport scope or table policy changed.');
        }
        if (! $force && $this->clock() < $scope['next_renewal_at']) {
            return;
        }
        $request = $scope['request'];
        $binding = $request['capacity_binding'];
        ModelHostControllerResourcePolicyService::assertReceiptBinding($binding,
            app(ModelHostControllerResourcePolicyService::class)->assertRequestContract($binding['host_id'], $scope['target'], $request['request_contract']));
        $timeout = $scope['policy']['heartbeat_timeout_seconds'];
        if ($timeout >= $scope['expires_at'] - $this->clock()) {
            throw new RuntimeException('Controller lease cannot cover its renewal timeout.');
        }
        $id = 'mhc-heartbeat-'.Str::uuid();
        $this->journal->heartbeatIntent($scope['owner'], $request['request_id'], $id, $binding);
        $dispatch = ($scope['dispatched'] && ($scope['renewed'] ?? false) && ! $scope['completed']
            && $scope['target_policy']['engine'] === 'ollama') ? $this->dispatchDeclaration($request, $binding) : null;
        $receipt = $dispatch === null
            ? $this->controller->heartbeat($id, $request['lease_id'], $timeout)
            : $this->controller->heartbeat($id, $request['lease_id'], $timeout, $dispatch);
        $this->assertControllerResponse($receipt, $id, $scope['target'], $request['lease_id']);
        $this->assertResult($receipt, ['renewed']);
        if (($receipt['schema'] ?? null) !== 'plos.model_host_controller.heartbeat.v1'
            || ($receipt['host_id'] ?? null) !== $binding['host_id']
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
            || ($receipt['resource_assessment']['table_owned'] ?? null) !== true
            || ($receipt['exact_identity'] ?? null) !== true || ($receipt['resource_gate_passed'] ?? null) !== true
            || ($receipt['resource_assessment']['eligible'] ?? null) !== true
            || ($receipt['resource_assessment']['result'] ?? null) !== 'capacity_available') {
            throw new RuntimeException('Controller renewal did not prove capacity and ownership.');
        }
        ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $receipt['resource_assessment']['request_binding'] ?? null);
        $scope['expires_at'] = $this->leaseDeadline($receipt, $scope['policy']);
        $scope['next_renewal_at'] = $this->clock() + $scope['policy']['heartbeat_interval_seconds'];
        $this->journal->heartbeatComplete($scope['owner'], $request['request_id'], $id, $receipt);
        $scope['renewed'] = true;
    }

    public function dispatchExact(array &$scope): void
    {
        if ($scope['dispatched']) {
            throw new InferenceAdmissionException('One admitted HTTP request cannot be replayed or reused for another tool turn.');
        }
        $this->journal->checkpoint($scope['owner'], $scope['request']['request_id'], 'dispatch_pending');
        $scope['dispatched'] = true;
        $this->renewExact($scope, true);
    }

    /** Close inference before exact cleanup; unresolved execution is never replayed or called successful. */
    public function finishExact(array &$scope, bool $completed): void
    {
        if ($scope['closed']) {
            return;
        }
        $request = $scope['request'];
        $result = 'held';
        $releaseRequested = false;
        $failure = null;
        $scope['closed'] = true;
        try {
            // The executor has ended. Renewing admission here can strand cleanup
            // behind a lost heartbeat; no more inference is permitted in this scope.
            $pendingRenewal = $this->journal->releaseIntent($scope['owner'], $request['request_id'],
                $request['lease_id'], $completed && $scope['dispatched']);
            $releaseRequested = true;
            $id = $request['request_id'].'-release';
            $receipt = $this->controller->release($id, $request['lease_id']);
            $this->assertControllerResponse($receipt, $id, $scope['target'], $request['lease_id']);
            $this->assertResult($receipt, ['released']);
            if (($receipt['cleanup_verified'] ?? null) !== true) {
                throw new RuntimeException('Controller release did not prove exact cleanup.');
            }
            $result = $pendingRenewal ? 'held'
                : ($completed && $scope['dispatched'] ? 'green' : ($scope['dispatched'] ? 'held' : 'queued'));
            if ($result === 'green') {
                Cache::forever(self::CURRENT_TARGET_KEY, $scope['target']);
            }
        } catch (\Throwable $error) {
            $result = 'held';
            $failure = $error;
        } finally {
            $this->journal->finish($scope['owner'], ['result' => $result, 'results' => [[...$request, 'result' => $result]]]);
        }
        if ($result === 'held') {
            $settled = false;
            if ($releaseRequested) {
                try {
                    // Only the existing persisted receipt + fresh clear-custody proof
                    // can settle a lost renewal/release. Never issue release a second time.
                    $snapshot = $this->journal->reconciliationSnapshot();
                    $settled = ($this->reconcileCustody(true, $snapshot['digest'])['result'] ?? null) === 'reconciled';
                } catch (\Throwable) {
                    // Unverified cleanup retains the original durable hold.
                }
            }
            throw new InferenceAdmissionException($settled
                ? 'Controller cleanup was verified; the original inference outcome remains uncertain and cannot be replayed.'
                : 'Controller transport outcome requires reconciliation; automatic replay is forbidden.', 0, $failure);
        }
    }

    /** Fresh host capacity/lease proof at dispatch and at each table-owned renewal interval. */
    public function renewInferenceCapacity(array $request, array $binding): void
    {
        $active = $this->activeInference;
        if ($active === null || ($request['request_id'] ?? null) !== $active['request']['request_id']
            || ($request['lease_id'] ?? null) !== $active['request']['lease_id']) {
            throw new RuntimeException('Inference renewal requires active durable dispatch custody.');
        }
        ModelHostControllerResourcePolicyService::assertReceiptBinding($active['binding'], $binding);
        $policy = $this->policies->required();
        if ($policy !== $active['policy']) {
            throw new RuntimeException('Inference renewal policy changed during owned dispatch.');
        }
        $timeout = $policy['heartbeat_timeout_seconds'];
        if ($timeout >= $active['expires_at'] - $this->clock()) {
            throw new RuntimeException('Inference lease cannot cover the registered heartbeat timeout.');
        }
        $contract = $active['request']['request_contract'];
        ModelHostControllerResourcePolicyService::assertReceiptBinding($binding,
            app(ModelHostControllerResourcePolicyService::class)->assertRequestContract($binding['host_id'], $binding['target'], $contract));
        $requestId = 'mhc-heartbeat-'.Str::uuid();
        $leaseId = $this->identity($request['lease_id'], 'lease');
        $this->journal->heartbeatIntent($active['owner'], $request['request_id'], $requestId, $binding);
        $dispatch = ($active['renewed'] && ! ($active['completed'] ?? false)
            && ($policy['targets'][$binding['target']]['engine'] ?? null) === 'ollama')
            ? $this->dispatchDeclaration($request, $binding) : null;
        $receipt = $dispatch === null
            ? $this->controller->heartbeat($requestId, $leaseId, $timeout)
            : $this->controller->heartbeat($requestId, $leaseId, $timeout, $dispatch);
        $this->assertControllerResponse($receipt, $requestId, $binding['target'], $leaseId);
        $this->assertResult($receipt, ['renewed']);
        if (($receipt['schema'] ?? null) !== 'plos.model_host_controller.heartbeat.v1'
            || ($receipt['host_id'] ?? null) !== $binding['host_id']
            || ($receipt['exact_identity'] ?? null) !== true
            || ($receipt['resource_gate_passed'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
            || ($receipt['resource_assessment']['table_owned'] ?? null) !== true
            || ($receipt['resource_assessment']['eligible'] ?? null) !== true
            || ($receipt['resource_assessment']['result'] ?? null) !== 'capacity_available') {
            throw new RuntimeException('Fresh inference capacity and custody are not proven.');
        }
        ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $receipt['resource_assessment']['request_binding'] ?? null);
        $expiresAt = $this->leaseDeadline($receipt, $policy);
        $this->journal->heartbeatComplete($active['owner'], $request['request_id'], $requestId, $receipt);
        $this->activeInference['expires_at'] = $expiresAt;
        $this->activeInference['lease_expires_at'] = $receipt['lease_expires_at'];
        $this->activeInference['next_renewal_at'] = $this->clock() + $policy['heartbeat_interval_seconds'];
        $this->activeInference['renewed'] = true;
    }

    /** Reuse the model clients' progress callbacks, including silent prefill, without a background writer. */
    private function withInferenceLease(string $owner, array $policy, array $request, array &$activation, callable $operation): mixed
    {
        if ($this->activeInference !== null) {
            throw new RuntimeException('Concurrent or nested inference lease ownership is forbidden.');
        }
        $this->activeInference = ['owner' => $owner, 'policy' => $policy, 'request' => $request,
            'binding' => $request['capacity_binding'], 'renewed' => false,
            'next_renewal_at' => $this->clock(), 'expires_at' => $this->leaseDeadline($activation, $policy)];
        try {
            return AIService::withInferenceProgressKeeper(function () use ($operation, $request, $owner) {
                $result = $operation();
                $this->journal->executionComplete($owner, $request['request_id']);
                $this->activeInference['completed'] = true;
                if (! $this->activeInference['renewed']) {
                    throw new RuntimeException('Managed inference did not prove dispatch-time custody.');
                }
                // A successful body cannot hide an expired or changed lease.
                $this->renewInferenceCapacity($request, $request['capacity_binding']);

                return $result;
            }, function () use ($request): void {
                if (! $this->activeInference['renewed']) {
                    throw new RuntimeException('Model HTTP began without dispatch-time custody.');
                }
                if ($this->clock() >= $this->activeInference['next_renewal_at']) {
                    $this->renewInferenceCapacity($request, $request['capacity_binding']);
                }
            });
        } finally {
            if (isset($this->activeInference['lease_expires_at'])) {
                $activation['lease_expires_at'] = $this->activeInference['lease_expires_at'];
            }
            $this->activeInference = null;
        }
    }

    /** Only callers with durable dispatch intent and a completed first renewal enter here. */
    private function dispatchDeclaration(array $request, array $binding): array
    {
        return ['schema' => 'plos.model_host_controller.dispatch.v1', 'phase' => 'in_flight',
            'original_request_id' => $request['request_id'], 'lease_id' => $request['lease_id'],
            'target' => $binding['target'], 'capacity_binding' => $binding];
    }

    private function clock(): float
    {
        return $this->monotonicClock === null ? hrtime(true) / 1_000_000_000 : ($this->monotonicClock)();
    }

    private function leaseDeadline(array $receipt, array $policy): float
    {
        $expiry = $receipt['lease_expires_at'] ?? null;
        if (! is_string($expiry) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})\z/', $expiry) !== 1) {
            throw new RuntimeException('Controller lease expiry is unavailable.');
        }
        $date = new \DateTimeImmutable($expiry);
        if (\DateTimeImmutable::getLastErrors() !== false) {
            throw new RuntimeException('Controller lease expiry is malformed.');
        }
        $remaining = (float) $date->format('U.u') - microtime(true);
        if ($remaining <= $policy['heartbeat_interval_seconds'] + $policy['heartbeat_timeout_seconds']) {
            throw new RuntimeException('Controller lease is too short for the registered renewal timing.');
        }

        return $this->clock() + $remaining;
    }

    /** Verify already-completed cleanup; never recover, release, retry or infer a missing model answer. */
    public function reconcileCustody(bool $execute = false, ?string $expectedDigest = null, ?array $correctiveRelease = null): array
    {
        $result = ['schema' => 'plos.model_host_controller.custody_reconciliation.v1',
            'result' => 'held', 'executed' => false, 'controller_mutations' => false,
            'model_replayed' => false, 'contains_prompt_or_response_content' => false];
        if (($execute || $correctiveRelease !== null) && (! is_string($expectedDigest) || preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1)) {
            return [...$result, 'reason' => 'exact_custody_digest_required'];
        }
        if (Cache::get(self::ACTIVE_KEY) !== null) {
            return [...$result, 'reason' => 'legacy_writer_custody_unverified'];
        }
        try {
            $snapshot = $this->journal->reconciliationSnapshot();
        } catch (\Throwable) {
            return [...$result, 'reason' => 'terminal_durable_custody_unavailable'];
        }
        $digest = $snapshot['digest'];
        $result['custody_digest'] = $digest;
        $result['request_count'] = count($snapshot['requests']);
        if (($execute || $correctiveRelease !== null) && ! hash_equals($digest, $expectedDigest)) {
            return [...$result, 'reason' => 'custody_changed'];
        }
        if ($correctiveRelease !== null) {
            $keys = array_keys($correctiveRelease);
            sort($keys);
            if ($keys !== ['lease_id', 'original_request_id', 'release_request_id'] || count($snapshot['requests']) !== 1) {
                return [...$result, 'reason' => 'corrective_cleanup_identity_invalid'];
            }
            foreach ($correctiveRelease as $identity) {
                if (! is_string($identity) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/', $identity)) {
                    return [...$result, 'reason' => 'corrective_cleanup_identity_invalid'];
                }
            }
            $original = reset($snapshot['requests']);
            $prior = $original['state'];
            $intents = $prior['lifecycle_intents'] ?? null;
            if (is_array($intents)) {
                ksort($intents);
            }
            if ($original['payload']['request_id'] !== $correctiveRelease['original_request_id']
                || $original['payload']['lease_id'] !== $correctiveRelease['lease_id']
                || ($prior['caller_closed'] ?? null) !== true || ($prior['dispatched'] ?? null) !== true
                || ($prior['phase'] ?? null) !== 'release_pending'
                || $intents !== ['activate' => $correctiveRelease['original_request_id'].'-activate',
                    'release' => $correctiveRelease['original_request_id'].'-release']
                || in_array($correctiveRelease['release_request_id'], [$correctiveRelease['original_request_id'], $prior['heartbeat']['request_id'] ?? null,
                    ...array_values($prior['lifecycle_intents'] ?? [])], true)) {
                return [...$result, 'reason' => 'corrective_cleanup_original_unproven'];
            }
        }
        try {
            $groups = [];
            foreach ($snapshot['requests'] as $key => $request) {
                $groups[$request['payload']['lease_id']][$key] = $request;
            }
            $proofs = [];
            foreach ($groups as $leaseId => $requests) {
                $releaseIds = [];
                $identities = [];
                $untouched = true;
                foreach ($requests as $request) {
                    $prior = $request['state'];
                    $untouched = $untouched && ($prior['phase'] ?? null) === 'reserved'
                        && ($prior['dispatched'] ?? false) === false && empty($prior['lifecycle_intents']);
                    if (isset($prior['lifecycle_intents']['release'])) {
                        $releaseIds[] = $prior['lifecycle_intents']['release'];
                    }
                    $identities[] = $request['payload']['controller'] ?? null;
                }
                $cleanup = null;
                $failedActivation = count($requests) === 1;
                foreach ($requests as $request) {
                    $prior = $request['state'];
                    $failedActivation = $failedActivation && ($prior['phase'] ?? null) === 'activation_pending'
                        && ($prior['dispatched'] ?? null) === false
                        && ($prior['execution_completed'] ?? false) === false
                        && ($prior['heartbeat']['phase'] ?? null) !== 'pending'
                        && ($prior['lifecycle_intents'] ?? null) === ['activate' => $request['payload']['request_id'].'-activate'];
                }
                $failedActivation = $failedActivation && ($snapshot['state']['heartbeat']['phase'] ?? null) !== 'pending';
                if (! $untouched) {
                    $identity = $identities[0];
                    if (! is_array($identity) || ! is_string($identity['host_id'] ?? null) || ! is_string($identity['target'] ?? null)
                        || array_filter($identities, static fn ($other) => $other !== $identity) !== []
                        || (! $failedActivation && count(array_unique($releaseIds)) !== 1)) {
                        return [...$result, 'reason' => 'original_cleanup_identity_unavailable'];
                    }
                    $operation = $failedActivation ? 'activate' : 'release';
                    $cleanupId = $failedActivation ? reset($requests)['state']['lifecycle_intents']['activate']
                        : ($correctiveRelease['release_request_id'] ?? $releaseIds[0]);
                    $lookup = $this->controller->receipt($operation, $identity['target'], $cleanupId, $leaseId);
                    if (($lookup['schema'] ?? null) !== 'plos.model_host_controller.receipt_lookup.v1'
                        || ($lookup['host_id'] ?? null) !== $identity['host_id']
                        || ($lookup['request_id'] ?? null) !== $cleanupId || ($lookup['lease_id'] ?? null) !== $leaseId
                        || ($lookup['target'] ?? null) !== $identity['target'] || ($lookup['operation'] ?? null) !== $operation
                        || ($lookup['record_found'] ?? null) !== true || ($lookup['result'] ?? null) !== 'found'
                        || ($lookup['mutation_available'] ?? null) !== false || ($lookup['contains_prompt_or_response_content'] ?? null) !== false) {
                        return [...$result, 'reason' => 'exact_cleanup_receipt_unavailable'];
                    }
                    $cleanup = $lookup['receipt'] ?? [];
                    if (($cleanup['schema'] ?? null) !== 'plos.model_host_controller.'.($failedActivation ? 'activation' : 'release').'.v1'
                        || ($cleanup['host_id'] ?? null) !== $identity['host_id'] || ($cleanup['target'] ?? null) !== $identity['target']
                        || ($cleanup['request_id'] ?? null) !== $cleanupId || ($cleanup['lease_id'] ?? null) !== $leaseId
                        || ($cleanup['result'] ?? null) !== ($failedActivation ? 'activation_failed' : 'released') || ($cleanup['cleanup_verified'] ?? null) !== true
                        || ($cleanup['contains_prompt_or_response_content'] ?? null) !== false
                        || (! $failedActivation && ($cleanup['execution_mode'] ?? null) !== 'serial') || ($cleanup['fallback_enabled'] ?? null) !== false
                        || ($cleanup['tools_enabled'] ?? null) !== false
                        || (! $failedActivation && (! is_bool($cleanup['stopped_controller_owned_runtime'] ?? null)
                            || ! is_bool($cleanup['unloaded_controller_owned_model'] ?? null)))
                        || ($failedActivation && (($cleanup['lease_present'] ?? null) !== false
                            || ($cleanup['startup_failure']['cleanup_verified'] ?? null) !== true))) {
                        return [...$result, 'reason' => 'cleanup_receipt_invalid'];
                    }
                    $status = $this->controller->targetStatus($identity['target']);
                    $lease = $status['lifecycle'] ?? [];
                    if (($status['schema'] ?? null) !== 'plos.model_host_controller.target_status.v1'
                        || ($status['host_id'] ?? null) !== $identity['host_id'] || ($status['target'] ?? null) !== $identity['target']
                        || ($status['k3_lease_active'] ?? null) !== false || ($status['mutation_available'] ?? null) !== false
                        || ($status['contains_prompt_or_response_content'] ?? null) !== false || ($status['result'] ?? null) !== 'guarded_target'
                        || ($lease['schema'] ?? null) !== 'plos.model_host_controller.lifecycle_status.v1'
                        || ($lease['host_id'] ?? null) !== $identity['host_id'] || ($lease['lease_present'] ?? null) !== false
                        || ($lease['lease_expired'] ?? null) !== false
                        || ! array_key_exists('lease_id_sha256', $lease) || $lease['lease_id_sha256'] !== null
                        || ! array_key_exists('active_target', $lease) || $lease['active_target'] !== null) {
                        return [...$result, 'reason' => 'host_custody_not_proven_clear'];
                    }
                    if ($failedActivation) {
                        $targets = $lease['targets'] ?? [];
                        $observed = count($targets) === 1 ? reset($targets) : [];
                        if (($observed['target'] ?? null) !== $identity['target']
                            || ($observed['task_exact'] ?? null) !== true || ($observed['control_exact'] ?? null) !== true
                            || ($observed['executable_exact'] ?? null) !== true
                            || ($observed['task_enabled'] ?? null) !== false || ($observed['task_state'] ?? null) !== 'Disabled'
                            || ($observed['owned_process_count'] ?? null) !== 0 || ($observed['loopback_listener_count'] ?? null) !== 0
                            || ($observed['running_exact'] ?? null) !== false) {
                            return [...$result, 'reason' => 'failed_activation_quiescence_unverified'];
                        }
                    }
                }
                foreach ($requests as $key => $request) {
                    $prior = $request['state'];
                    $ended = ($prior['dispatched'] ?? false) === false || ($prior['execution_completed'] ?? false) === true
                        || ($cleanup['stopped_controller_owned_runtime'] ?? false) === true
                        || ($cleanup['unloaded_controller_owned_model'] ?? false) === true;
                    $unloadedResidency = false;
                    if (($correctiveRelease !== null || ! $ended) && ($prior['caller_closed'] ?? null) === true) {
                        // Shared Ollama may already be unloaded when release executes.
                        // Only a fresh exact empty-residency observation fences that case.
                        $targets = $status['lifecycle']['targets'] ?? [];
                        $observed = is_array($targets) && count($targets) === 1 ? reset($targets) : [];
                        $unloadedResidency = ($observed['target'] ?? null) === ($request['payload']['controller']['target'] ?? null)
                            && ($observed['task_exact'] ?? null) === true && ($observed['control_exact'] ?? null) === true
                            && ($observed['executable_exact'] ?? null) === true && ($observed['healthy'] ?? null) === true
                            && ($observed['model_exact'] ?? null) === true && ($observed['running_exact'] ?? null) === true
                            && ($observed['owned_process_count'] ?? null) === 1 && ($observed['loopback_listener_count'] ?? null) === 1
                            && ($observed['model_loaded_exact'] ?? null) === false
                            && ($observed['residency_verified'] ?? null) === true && ($observed['foreign_loaded_count'] ?? null) === 0;
                        $ended = $unloadedResidency;
                    }
                    if (! $ended) {
                        return [...$result, 'reason' => 'model_work_quiescence_unverified'];
                    }
                    $proofs[$key] = ['custody_digest' => $digest, 'request_id' => $request['payload']['request_id'],
                        'lease_id' => $leaseId, 'host_custody_clear' => true, 'owned_work_ended' => true,
                        'release_request_id' => $failedActivation ? null : ($cleanup['request_id'] ?? null),
                        ...($correctiveRelease !== null ? ['corrective_release_request_id' => $correctiveRelease['release_request_id'],
                            'original_release_outcome' => 'unknown'] : []),
                        'release_receipt_sha256' => $cleanup === null || $failedActivation ? null : ModelHostControllerResourcePolicyService::requestContractIdentity($cleanup),
                        ...($failedActivation ? ['activation_request_id' => $cleanup['request_id'],
                            'activation_receipt_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($cleanup),
                            'quiescent_status_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($status)] : []),
                        ...($unloadedResidency ? ['work_end_evidence' => 'closed_caller_and_fresh_unloaded_residency',
                            'quiescent_status_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($status)] : []),
                        'evidence' => $untouched ? 'never_started' : ($failedActivation ? 'persisted_failed_activation_and_fresh_idle_status' : 'persisted_release_and_fresh_idle_status')];
                }
            }
            if ($execute) {
                $this->journal->completeReconciliation($digest, $proofs);
            }

            return [...$result, 'result' => $execute ? 'reconciled' : 'ready', 'executed' => $execute,
                'original_requests_retained' => true, 'model_answers_recovered' => false];
        } catch (\Throwable) {
            return [...$result, 'reason' => 'reconciliation_evidence_unavailable'];
        }
    }

    /** Exact read-only preparation for the separately approved technical fence. */
    public function planTechnicalFence(): array
    {
        $snapshot = $this->journal->reconciliationSnapshot();
        $request = $this->technicalFenceRequest($snapshot);
        $target = $request['payload']['controller']['target'];
        $status = $this->controller->targetStatus($target);
        $this->assertTechnicalFenceQuiescence($status, $target);

        return ['success' => true, 'dry_run' => true, 'owner' => $snapshot['state']['owner'],
            'requestId' => $request['payload']['request_id'], 'leaseId' => $request['payload']['lease_id'],
            'target' => $target, 'custodyDigest' => $snapshot['digest'],
            'hostGeneration' => $status['lifecycle']['state_generation_sha256'],
            'activation_outcome' => 'unknown', 'inference_outcome' => 'not_dispatched'];
    }

    /** Retire only one exact terminal undispatched reservation; never replay its action. */
    public function fenceActivation(string $owner, string $requestId, string $leaseId, string $target,
        string $custodyDigest, string $hostGeneration, string $idempotencyKey, bool $dryRun = true): array
    {
        $fenceDispatched = false;
        $fenceConfirmed = false;
        try {
            foreach ([$owner, $idempotencyKey] as $uuid) {
                if (! Str::isUuid($uuid)) {
                    throw new RuntimeException('Exact technical fence owner and idempotency UUID required.');
                }
            }
            foreach ([$custodyDigest, $hostGeneration] as $hash) {
                if (! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                    throw new RuntimeException('Exact custody and host generation hashes required.');
                }
            }
            $caller = null;
            if (! $dryRun) {
                $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
                $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
                if (! is_array($caller) || count($caller) !== 5 || array_diff(array_keys($caller), $keys)) {
                    throw new RuntimeException('Trusted registered operator technical fence authority required.');
                }
                foreach ($keys as $key) {
                    if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                        || preg_match('/[\x00-\x20\x7f]/', $caller[$key])
                        || (str_ends_with($key, 'sha256') && ! preg_match('/\A[a-f0-9]{64}\z/', $caller[$key]))) {
                        throw new RuntimeException('Trusted registered operator technical fence authority required.');
                    }
                }
            }

            return DB::transaction(function () use ($owner, $requestId, $leaseId, $target, $custodyDigest,
                $hostGeneration, $idempotencyKey, $dryRun, $caller, &$fenceDispatched, &$fenceConfirmed): array {
                // Retain the existing slot/request locks through the controller fence and readback.
                if (Cache::get(self::ACTIVE_KEY) !== null) {
                    throw new RuntimeException('Legacy writer custody remains unverified.');
                }
                $snapshot = $this->journal->reconciliationSnapshot();
                $request = $this->technicalFenceRequest($snapshot);
                $payload = $request['payload'];
                if ($snapshot['digest'] !== $custodyDigest || $snapshot['state']['owner'] !== $owner
                    || $payload['request_id'] !== $requestId || $payload['lease_id'] !== $leaseId
                    || $payload['controller']['target'] !== $target) {
                    throw new RuntimeException('Exact technical fence reservation changed.');
                }
                if ($dryRun) {
                    $status = $this->controller->targetStatus($target);
                    $this->assertTechnicalFenceQuiescence($status, $target);
                    if ($status['lifecycle']['state_generation_sha256'] !== $hostGeneration) {
                        throw new RuntimeException('Host generation changed.');
                    }

                    return ['success' => true, 'dry_run' => true, 'executed' => false,
                        'activation_outcome' => 'unknown', 'inference_outcome' => 'not_dispatched'];
                }
                $binding = ['owner' => $owner, 'original_request_id' => $requestId,
                    'activation_request_id' => $requestId.'-activate', 'lease_id' => $leaseId, 'target' => $target,
                    'request_id' => 'mhc-fence-'.$idempotencyKey, 'custody_digest' => $custodyDigest,
                    'expected_host_generation' => $hostGeneration,
                    'operator_authority_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity(array_intersect_key($caller, array_flip(['arguments_sha256', 'approval_scope_sha256'])))];
                // A lost fence reply is recovered from its distinct durable receipt.
                // It never retries the original activation or creates another fence ID.
                $lookup = $this->controller->receipt('technical_fence', $target, $binding['request_id'], $leaseId);
                if (($lookup['record_found'] ?? null) === false) {
                    $status = $this->controller->targetStatus($target);
                    $this->assertTechnicalFenceQuiescence($status, $target);
                    if ($status['lifecycle']['state_generation_sha256'] !== $hostGeneration) {
                        throw new RuntimeException('Host generation changed before technical fencing.');
                    }
                    $fenceDispatched = true;
                    $written = $this->controller->technicalFence($binding);
                    $lookup = $this->controller->receipt('technical_fence', $target, $binding['request_id'], $leaseId);
                }
                $receipt = $lookup['receipt'] ?? null;
                if (($lookup['record_found'] ?? null) !== true || ! is_array($receipt)
                    || (isset($written) && $written !== $receipt)) {
                    throw new RuntimeException('Exact durable technical fence receipt is unavailable.');
                }
                foreach ($binding as $key => $value) {
                    if (($receipt[$key] ?? null) !== $value) {
                        throw new RuntimeException('Technical fence receipt binding changed.');
                    }
                }
                if (($receipt['schema'] ?? null) !== 'plos.model_host_controller.technical_fence.v1'
                    || ($receipt['result'] ?? null) !== 'fenced' || ($receipt['host_custody_clear'] ?? null) !== true
                    || ($receipt['owned_work_ended'] ?? null) !== true || ($receipt['activation_outcome'] ?? null) !== 'unknown'
                    || ($receipt['inference_outcome'] ?? null) !== 'not_dispatched'
                    || ($receipt['model_replayed'] ?? null) !== false || ($receipt['runtime_mutated'] ?? null) !== false
                    || ! is_int($receipt['fenced_generation'] ?? null)) {
                    throw new RuntimeException('Technical fence receipt is not a truthful terminal fence.');
                }
                $fenceConfirmed = true;
                // Clearance is a point-in-time proof for this permanently fenced original request.
                // A later authorized host lease is never released by retiring this old app reservation;
                // subsequent inference still performs fresh controller custody and activation checks.
                $status = $this->controller->targetStatus($target);
                $this->assertTechnicalFenceQuiescence($status, $target);
                if ($status['lifecycle']['generation'] !== $receipt['fenced_generation']) {
                    throw new RuntimeException('Host changed after technical fencing; retain application custody.');
                }
                $key = array_key_first($snapshot['requests']);
                $this->journal->completeReconciliation($custodyDigest, [$key => [
                    'custody_digest' => $custodyDigest, 'request_id' => $requestId, 'lease_id' => $leaseId,
                    'host_custody_clear' => true, 'owned_work_ended' => true,
                    'activation_outcome' => 'unknown', 'evidence' => 'operator_conditional_technical_fence',
                    'technical_fence_receipt' => $receipt]]);

                return ['success' => true, 'dry_run' => false, 'executed' => true,
                    'reservation_reconciled' => true, 'activation_outcome' => 'unknown',
                    'inference_outcome' => 'not_dispatched', 'model_replayed' => false, 'receipt' => $receipt];
            });
        } catch (\Throwable $error) {
            return ['success' => false, 'status' => 'held', 'executed' => false,
                'reservation_reconciled' => false,
                'technical_fence_outcome' => $fenceConfirmed ? 'fenced' : ($fenceDispatched ? 'unknown' : 'not_attempted'),
                'activation_outcome' => 'unknown', 'inference_outcome' => 'not_dispatched',
                'model_replayed' => false, 'error' => $error->getMessage()];
        }
    }

    private function technicalFenceRequest(array $snapshot): array
    {
        if (count($snapshot['requests']) !== 1 || ($snapshot['state']['heartbeat']['phase'] ?? null) === 'pending') {
            throw new RuntimeException('Technical fence requires one exact terminal activation reservation.');
        }
        $request = reset($snapshot['requests']);
        $state = $request['state'];
        if (($state['phase'] ?? null) !== 'activation_pending' || ($state['dispatched'] ?? null) !== false
            || ($state['execution_completed'] ?? false) !== false || ($state['heartbeat']['phase'] ?? null) === 'pending'
            || ($state['lifecycle_intents'] ?? null) !== ['activate' => $request['payload']['request_id'].'-activate']) {
            throw new RuntimeException('Technical fence cannot retire dispatched or ambiguous work.');
        }

        return $request;
    }

    private function assertTechnicalFenceQuiescence(array $status, string $target): void
    {
        $lease = $status['lifecycle'] ?? [];
        $observed = count($lease['targets'] ?? []) === 1 ? reset($lease['targets']) : [];
        $disabled = ($observed['task_enabled'] ?? null) === false && ($observed['task_state'] ?? null) === 'Disabled'
            && ($observed['owned_process_count'] ?? null) === 0 && ($observed['loopback_listener_count'] ?? null) === 0
            && ($observed['running_exact'] ?? null) === false;
        // An exact empty Ollama residency proves no generation can be active.
        $unloaded = ($observed['healthy'] ?? null) === true && ($observed['running_exact'] ?? null) === true
            && ($observed['owned_process_count'] ?? null) === 1 && ($observed['loopback_listener_count'] ?? null) === 1
            && ($observed['model_loaded_exact'] ?? null) === false && ($observed['residency_verified'] ?? null) === true
            && ($observed['foreign_loaded_count'] ?? null) === 0;
        if (($status['schema'] ?? null) !== 'plos.model_host_controller.target_status.v1'
            || ($status['target'] ?? null) !== $target || ($status['contains_prompt_or_response_content'] ?? null) !== false
            || ($status['k3_lease_active'] ?? null) !== false || ($status['mutation_available'] ?? null) !== false
            || ($lease['lease_present'] ?? null) !== false || ($lease['lease_expired'] ?? null) !== false
            || ! array_key_exists('active_target', $lease) || $lease['active_target'] !== null
            || ! array_key_exists('lease_id_sha256', $lease) || $lease['lease_id_sha256'] !== null
            || ($observed['target'] ?? null) !== $target || ($observed['task_exact'] ?? null) !== true
            || ($observed['control_exact'] ?? null) !== true || ($observed['executable_exact'] ?? null) !== true
            || (! $disabled && ! $unloaded) || (array_key_exists('busy', $observed) && $observed['busy'] !== false)
            || ! is_int($lease['generation'] ?? null) || $lease['generation'] < 0
            || ! is_string($lease['state_generation_sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $lease['state_generation_sha256'])) {
            throw new RuntimeException('Fresh exact host custody and generation quiescence are unproven.');
        }
    }

    public function inspectCustody(): array
    {
        return $this->journal->inspect();
    }

    /**
     * @param  list<array{role:string,request_id:string,lease_id:string}>  $requests
     * @return array<string,mixed>
     */
    public function run(array $requests, ?callable $executor = null): array
    {
        $policy = $this->policies->required();
        if ($requests === []) {
            return $this->receipt($policy, 'empty', [], null);
        }
        if (count($requests) > $policy['max_batch_size']) {
            throw new RuntimeException('MHC-06 automatic selection batch exceeds the policy limit.');
        }
        $normalized = array_map(fn (mixed $request): array => $this->request($request), $requests);
        if (! $policy['enabled'] || ! $policy['automatic_selection_enabled']) {
            return $this->receipt($policy, 'inactive', $normalized, 'automatic_selection_disabled');
        }

        $lock = Cache::lock(self::LOCK_KEY, $policy['lock_ttl_seconds']);
        $result = $lock->get(fn (): array => $this->runLocked($policy, $normalized, $executor));
        if ($result === false) {
            return $this->receipt($policy, 'queued', $normalized, 'automatic_selection_busy');
        }

        return $result;
    }

    /** @param array<string,mixed> $policy @param list<array<string,string>> $requests @return array<string,mixed> */
    private function runLocked(array $policy, array $requests, ?callable $executor): array
    {
        $active = Cache::get(self::ACTIVE_KEY);
        if (is_array($active)) {
            if (($active['phase'] ?? null) === 'outcome_unknown') {
                return $this->receipt($policy, 'held', $requests, 'unresolved_lifecycle_outcome');
            }
            $heartbeat = (int) ($active['heartbeat_at'] ?? 0);
            $age = time() - $heartbeat;
            if ($heartbeat > 0 && $age <= $policy['recovery_after_seconds']) {
                return $this->receipt($policy, 'queued', $requests, 'active_selection_exists');
            }

            return $this->receipt($policy, 'held', $requests, 'unresolved_lifecycle_outcome');
        }

        $owner = null;
        try {
            $owner = $this->journal->begin($requests);
            if ($owner === null) {
                return $this->receipt($policy, 'held', $requests, 'unresolved_lifecycle_outcome');
            }
            $receipt = $this->runReserved($policy, $requests, $executor, $owner);
            $this->journal->finish($owner, $receipt);

            return $receipt;
        } catch (\Throwable) {
            // Never fall back to cache or replay after durable custody becomes unavailable.
            $held = $this->receipt($policy, 'held', $requests, 'durable_journal_unavailable');
            if ($owner !== null) {
                try {
                    // Preserve the exact owner and request evidence for normal reconciliation.
                    // If persistence still fails, the original durable reservation remains held.
                    $this->journal->finish($owner, $held);
                } catch (\Throwable) {
                    // A changed owner or unavailable journal must never authorize clearing custody.
                }
            }

            return $held;
        }
    }

    private function runReserved(array $policy, array $requests, ?callable $executor, string $owner): array
    {
        $groups = [];
        $results = [];
        foreach ($requests as $request) {
            $target = $policy['role_targets'][$request['role']] ?? null;
            $targetPolicy = is_string($target) ? ($policy['targets'][$target] ?? null) : null;
            if (! is_string($target) || ! is_array($targetPolicy)) {
                $results[] = $this->failedResult($request, 'role_not_admitted');

                continue;
            }
            try {
                if (! isset($request['request_contract']) && app(ModelHostControllerResourcePolicyService::class)->required()['enabled']) {
                    throw new RuntimeException('Enabled capacity policy requires a resolved inference request contract.');
                }
                if (isset($request['target_policy_sha256'])
                    && ! hash_equals($request['target_policy_sha256'], ModelHostControllerResourcePolicyService::requestContractIdentity($targetPolicy))) {
                    throw new RuntimeException('Coordinator target policy changed after request preparation.');
                }
                $selection = $this->coordinator->selectForRole($request['role'], $target);
                $this->assertSelection($selection, $target, $targetPolicy, $request['role']);
                if (isset($request['request_contract'])) {
                    $contract = $request['request_contract'];
                    if (($contract['controller_host_id'] ?? null) !== ($selection['host_id'] ?? null)
                        || ($contract['controller_target'] ?? null) !== $target) {
                        throw new RuntimeException('Prepared request host or target differs from selection.');
                    }
                    ModelHostControllerResourcePolicyService::assertReceiptBinding($request['capacity_binding'],
                        app(ModelHostControllerResourcePolicyService::class)->assertRequestContract($selection['host_id'], $target, $contract));
                }
                $groups[$target][] = [...$request, 'selection' => $selection];
            } catch (\Throwable $exception) {
                $results[] = $this->failedResult($request, $this->failureReason($exception));
            }
        }
        foreach ($groups as $target => $group) {
            if (array_filter($results, static fn (array $result): bool => ($result['result'] ?? null) === 'held') !== []) {
                foreach ($group as $request) {
                    $results[] = $this->failedResult($request, 'unresolved_lifecycle_outcome');
                }

                continue;
            }
            $leases = array_values(array_unique(array_column($group, 'lease_id')));
            if (count($leases) !== 1) {
                foreach ($group as $request) {
                    $results[] = $this->failedResult($request, 'batch_lease_mismatch');
                }

                continue;
            }
            array_push($results, ...$this->runGroup($policy, $target, $group, $executor, $owner));
        }

        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['result'] ?? null) !== 'green'
        ));

        return $this->receipt(
            $policy,
            $failed === [] ? 'green' : (array_filter($failed, static fn (array $result): bool => $result['result'] === 'held') !== [] ? 'held' : 'queued'),
            $requests,
            $failed === [] ? null : 'target_selection_failed',
            $results
        );
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  list<array<string,mixed>>  $requests
     * @return list<array<string,mixed>>
     */
    private function runGroup(array $policy, string $target, array $requests, ?callable $executor, string $owner): array
    {
        $targetPolicy = $policy['targets'][$target] ?? null;
        if (! is_array($targetPolicy)) {
            return array_map(fn (array $request): array => $this->failedResult($request, 'target_not_admitted'), $requests);
        }
        $first = $requests[0];

        $plan = null;
        $activation = null;
        $release = null;
        $activated = false;
        $activationAttempted = false;
        $dispatchStarted = false;
        $reason = null;
        try {
            $binding = $first['capacity_binding'] ?? null;
            if ($binding !== null && (! is_int($policy['heartbeat_timeout_seconds'] ?? null) || $policy['heartbeat_timeout_seconds'] < 1)) {
                throw new RuntimeException('Managed inference requires a table-owned heartbeat timeout.');
            }
            foreach ($requests as $request) {
                ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $request['capacity_binding'] ?? null);
            }
            // Use the cheap selected read; foreign or expired custody is never auto-recovered.
            $status = $this->controller->targetStatus($target);
            if (($status['lifecycle']['lease_present'] ?? null) !== false
                || ($status['k3_lease_active'] ?? null) !== false) {
                throw new RuntimeException('Controller custody is not proven free.');
            }
            $planRequestId = $first['request_id'].'-plan';
            $activationRequestId = $first['request_id'].'-activate';
            $plan = $this->controller->plan($target, $planRequestId);
            $this->assertControllerResponse($plan, $planRequestId, $target, null, $targetPolicy);
            // Role-only callers still cannot use a host profile bound to actual request settings.
            ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $plan['resource_assessment']['request_binding'] ?? null);
            $shortage = ModelHostControllerResourcePolicyService::temporaryShortage($plan, $target, $targetPolicy['model'], $targetPolicy['engine']);
            if ($shortage !== null) {
                $reason = 'temporarily_unavailable';
            } else {
                $this->assertResult($plan, ['ready']);
                if ($binding !== null && ! ModelHostControllerResourcePolicyService::availableCapacity($plan, $target, $targetPolicy['model'], $targetPolicy['engine'])) {
                    throw new RuntimeException('Bound inference requires a complete eligible capacity plan.');
                }
            }
            if ($reason !== null) {
                return array_map(fn (array $request): array => $this->failedResult($request, $reason), $requests);
            }
            $this->consumeSwitchBudget($target, $policy['max_switches_per_hour']);
            $this->journal->checkpoint($owner, $first['request_id'], 'activation_pending');
            $activationAttempted = true;
            $activation = $this->controller->activate($target, $activationRequestId, $first['lease_id']);
            $this->assertControllerResponse($activation, $activationRequestId, $target, $first['lease_id'], $targetPolicy);
            $this->assertResult($activation, ['activated', 'already_ready']);
            ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $activation['resource_assessment']['request_binding'] ?? null);
            $activated = true;
            if ($binding === null) {
                $heartbeatRequestId = $first['request_id'].'-heartbeat';
                $heartbeat = $this->controller->heartbeat($heartbeatRequestId, $first['lease_id']);
                $this->assertControllerResponse($heartbeat, $heartbeatRequestId, $target, $first['lease_id'], $targetPolicy);
                $this->assertResult($heartbeat, ['renewed']);
                ModelHostControllerResourcePolicyService::assertReceiptBinding(null, $heartbeat['resource_assessment']['request_binding'] ?? null);
            }
            // Bound inference renews at its actual transport boundary, after gateway preparation.
            if ($executor !== null) {
                foreach ($requests as $request) {
                    if (isset($request['request_contract'])) {
                        ModelHostControllerResourcePolicyService::assertReceiptBinding($binding,
                            app(ModelHostControllerResourcePolicyService::class)->assertRequestContract(
                                $request['selection']['host_id'], $target, $request['request_contract']));
                    }
                    $this->journal->checkpoint($owner, $request['request_id'], 'dispatch_pending');
                    $dispatchStarted = true;
                    $execute = fn () => AIService::withNonReplayableInference(fn () => $executor($request, [
                        ...$request['selection'],
                        'provider_instance_id' => $targetPolicy['provider_instance_id'],
                        'provider_model_role' => $targetPolicy['provider_model_role'],
                        'inference_adapter' => $targetPolicy['inference_adapter'],
                        'inference_limits' => $targetPolicy['inference_limits'],
                    ]));
                    if ($binding !== null) {
                        $this->withInferenceLease($owner, $policy, $request, $activation, $execute);

                        continue;
                    }
                    $execute();
                    $this->journal->executionComplete($owner, $request['request_id']);
                    $batchHeartbeatRequestId = $request['request_id'].'-batch-heartbeat';
                    $batchHeartbeat = $this->controller->heartbeat($batchHeartbeatRequestId, $first['lease_id']);
                    $this->assertControllerResponse(
                        $batchHeartbeat,
                        $batchHeartbeatRequestId,
                        $target,
                        $first['lease_id'],
                        $targetPolicy
                    );
                    $this->assertResult($batchHeartbeat, ['renewed']);
                    ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $batchHeartbeat['resource_assessment']['request_binding'] ?? null);
                }
            }
        } catch (\Throwable $exception) {
            $reason = $activationAttempted && ! $activated ? 'activation_outcome_unknown'
                : ($dispatchStarted ? 'execution_outcome_unknown' : $this->failureReason($exception));
        } finally {
            if ($activated) {
                try {
                    $this->journal->checkpoint($owner, $first['request_id'], 'release_pending');
                    $release = $this->controller->release($first['request_id'].'-release', $first['lease_id']);
                    $this->assertControllerResponse($release, $first['request_id'].'-release', null, $first['lease_id']);
                    $this->assertResult($release, ['released']);
                } catch (\Throwable) {
                    $reason = 'cleanup_outcome_unknown';
                }
            }
        }

        if ($reason !== null) {
            return array_map(fn (array $request): array => $this->failedResult($request, $reason), $requests);
        }

        // This is the last selected target, not an expiring policy setting.
        Cache::forever(self::CURRENT_TARGET_KEY, $target);

        return array_map(static fn (array $request): array => [
            'schema' => 'plos.model_host_controller.automatic_selection_result.v1',
            'request_id' => $request['request_id'],
            'lease_id' => $request['lease_id'],
            'role' => $request['role'],
            'target' => $target,
            'provider_instance_id' => $targetPolicy['provider_instance_id'],
            'model' => $targetPolicy['model'],
            'engine' => $targetPolicy['engine'],
            'plan_result' => $plan['result'] ?? null,
            'activation_result' => $activation['result'] ?? null,
            'release_result' => $release['result'] ?? null,
            'batched_request_count' => count($requests),
            'idle_ttl_seconds' => $targetPolicy['idle_ttl_seconds'],
            'heartbeat_interval_seconds' => $policy['heartbeat_interval_seconds'],
            'execution_mode' => 'serial',
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_on_failure' => true,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => 'green',
        ], $requests);
    }

    private function consumeSwitchBudget(string $target, int $limit): void
    {
        $current = Cache::get(self::CURRENT_TARGET_KEY);
        if ($current === null || $current === $target) {
            return;
        }
        $now = time();
        $switches = array_values(array_filter(
            (array) Cache::get(self::SWITCHES_KEY, []),
            static fn (mixed $stamp): bool => is_int($stamp) && $stamp > ($now - 3600)
        ));
        if (count($switches) >= $limit) {
            throw new RuntimeException('MHC-06 automatic selection switch-rate budget is exhausted.');
        }
        $switches[] = $now;
        Cache::put(self::SWITCHES_KEY, $switches, 3600);
    }

    /** @param mixed $value @return array<string,string> */
    private function request(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('MHC-06 automatic selection request is invalid.');
        }
        $role = trim((string) ($value['role'] ?? ''));
        $requestId = $this->identity($value['request_id'] ?? '', 'request');
        $leaseId = $this->identity($value['lease_id'] ?? '', 'lease');
        if (preg_match('/\A[a-z][a-z0-9_]{1,47}\z/', $role) !== 1) {
            throw new RuntimeException('MHC-06 automatic selection role is invalid.');
        }
        if (strlen($requestId) > 105) {
            throw new RuntimeException('MHC-06 automatic selection request identity is too long for lifecycle suffixes.');
        }

        $request = ['role' => $role, 'request_id' => $requestId, 'lease_id' => $leaseId];
        if (array_key_exists('request_contract', $value) || array_key_exists('capacity_binding', $value)) {
            if (! is_array($value['request_contract'] ?? null) || ! is_array($value['capacity_binding'] ?? null)) {
                throw new RuntimeException('Managed request contract and capacity binding must both be provided.');
            }
            $request['request_contract'] = $value['request_contract'];
            $request['capacity_binding'] = $value['capacity_binding'];
        }
        if (array_key_exists('target_policy_sha256', $value)) {
            if (! is_string($value['target_policy_sha256']) || preg_match('/\A[a-f0-9]{64}\z/', $value['target_policy_sha256']) !== 1) {
                throw new RuntimeException('MHC-06 prepared target policy identity is invalid.');
            }
            $request['target_policy_sha256'] = $value['target_policy_sha256'];
        }

        return $request;
    }

    private function identity(mixed $value, string $kind): string
    {
        $value = trim((string) $value);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $value) !== 1) {
            throw new RuntimeException("MHC-06 {$kind} identity is invalid.");
        }

        return $value;
    }

    /** @param array<string,mixed> $selection @param array<string,mixed> $targetPolicy */
    private function assertSelection(array $selection, string $target, array $targetPolicy, string $role): void
    {
        if (($selection['target'] ?? null) !== $target
            || ($selection['role'] ?? null) !== $role
            || ($selection['model'] ?? null) !== $targetPolicy['model']
            || ($selection['engine'] ?? null) !== $targetPolicy['engine']
            || ($selection['contains_prompt_or_response_content'] ?? true) !== false
            || ($selection['controller_enabled'] ?? null) !== true
            || ($selection['automatic_activation_enabled'] ?? null) !== true
            || ($selection['requires_operator_override'] ?? null) !== false
            || ($selection['execution_mode'] ?? null) !== 'serial') {
            throw new RuntimeException('MHC-06 coordinator returned an inexact selection.');
        }
    }

    /** @param array<string,mixed> $response @param array<string,mixed> $targetPolicy */
    private function assertControllerResponse(array $response, string $requestId, ?string $target, ?string $leaseId, ?array $targetPolicy = null): void
    {
        if (($response['request_id'] ?? null) !== $requestId
            || ($target !== null && ($response['target'] ?? null) !== $target)
            || ($leaseId !== null && ($response['lease_id'] ?? null) !== $leaseId)
            || ! is_string($response['result'] ?? null)
            || ($targetPolicy !== null && isset($response['model']) && $response['model'] !== $targetPolicy['model'])
            || ($targetPolicy !== null && isset($response['engine']) && $response['engine'] !== $targetPolicy['engine'])) {
            throw new RuntimeException('MHC-06 controller returned an inexact lifecycle response.');
        }
    }

    /** @param array<string,mixed> $response @param list<string> $allowed */
    private function assertResult(array $response, array $allowed): void
    {
        if (! in_array($response['result'] ?? null, $allowed, true)) {
            throw new RuntimeException('MHC-06 controller returned a non-green lifecycle result.');
        }
    }

    /** @param array<string,string> $request @return array<string,mixed> */
    private function failedResult(array $request, string $reason): array
    {
        return [
            'schema' => 'plos.model_host_controller.automatic_selection_result.v1',
            'request_id' => $request['request_id'],
            'lease_id' => $request['lease_id'],
            'role' => $request['role'],
            'reason' => $reason,
            'queue_on_failure' => ! $this->requiresReview($reason),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => $this->requiresReview($reason) ? 'held' : 'queued',
        ];
    }

    private function requiresReview(string $reason): bool
    {
        return in_array($reason, ['activation_outcome_unknown', 'execution_outcome_unknown',
            'cleanup_outcome_unknown', 'unresolved_lifecycle_outcome', 'durable_journal_unavailable'], true);
    }

    private function failureReason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'switch-rate')) {
            return 'switch_rate_exhausted';
        }
        if (str_contains($message, 'controller')) {
            return 'controller_rejected';
        }
        if (str_contains($message, 'coordinator')) {
            return 'selection_rejected';
        }

        return 'lifecycle_failed';
    }

    /** @param array<string,mixed> $policy @param list<array<string,string>> $requests @param list<array<string,mixed>> $results|null $results @return array<string,mixed> */
    private function receipt(array $policy, string $result, array $requests, ?string $reason, ?array $results = null): array
    {
        return [
            'schema' => 'plos.model_host_controller.automatic_selection_receipt.v1',
            'phase' => 'MHC-06',
            'policy_revision' => $policy['revision'],
            'automatic_selection_enabled' => $policy['automatic_selection_enabled'],
            'normal_routing' => false,
            'execution_mode' => 'serial',
            'concurrent_inference_enabled' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_on_failure' => $result !== 'held',
            'batch_size' => count($requests),
            'requests' => array_map(static fn (array $request): array => [
                'role' => $request['role'],
                'request_id' => $request['request_id'],
                'lease_id' => $request['lease_id'],
            ], $requests),
            'results' => $results ?? [],
            'reason' => $reason,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => $result,
        ];
    }
}
