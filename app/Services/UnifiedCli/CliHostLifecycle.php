<?php

namespace App\Services\UnifiedCli;

use App\Services\ModelHostControllerResourcePolicyService;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Operator-requested lifecycle actions through the existing controller. */
final class CliHostLifecycle
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliModelHostStatus $connection,
        private readonly ?Closure $monotonicClock = null,
        private readonly ?Closure $waitSleeper = null,
    ) {}

    public function inspect(): array
    {
        $lease = $this->store->session($this->sessionId)['host_lease'] ?? null;

        return ['lease' => $lease === null ? null : array_intersect_key($lease, array_flip([
            'target', 'model', 'phase', 'expires_at', 'controller_identity', 'operation_id', 'operation', 'startup_failure',
            'heartbeat_outcome_unknown',
        ])), 'runtime_admission_checked' => false];
    }

    public function activate(string $target, bool $override = false, ?array $capacityBinding = null): array
    {
        $session = $this->store->session($this->sessionId);
        $existing = $session['host_lease'] ?? null;
        if ($existing !== null && ! in_array($existing['phase'], ['released', 'not_started'], true)) {
            throw new RuntimeException('This session already has a lease or unresolved lifecycle action. Review /host lease.');
        }
        $policy = $this->connection->policy();
        $spec = $policy['targets'][$target] ?? null;
        if (! $policy['enabled'] || ! is_array($spec) || ! $spec['activation_allowed']
            || ! in_array($spec['state'], ['production', 'staged'], true) || $target === 'kimi_k3_87') {
            throw new RuntimeException('This target is not admitted to the generic controller lifecycle. Dedicated K3 gates remain required.');
        }
        if ($spec['requires_operator_override'] && ! $override) {
            throw new RuntimeException('This target requires the explicit --override option.');
        }
        $capacityBinding ??= $this->selectedCapacityBinding($session, $target);
        $this->awaitAdmission($target, $policy, $spec, $capacityBinding);
        $lease = ['lease_id' => 'cli-lease-'.Str::uuid(), 'target' => $target, 'model' => $spec['model'],
            'host_id' => $policy['host_id'], 'phase' => 'not_started', 'controller_identity' => $this->connection->identity()];

        if ($capacityBinding !== null) {
            $lease['capacity_binding'] = $capacityBinding;
        }

        return $this->mutate('activate', $lease);
    }

    /** Read-only polling stops before the first lifecycle mutation; never retries activation. */
    private function awaitAdmission(string $target, array $policy, array $spec, ?array $binding): void
    {
        $session = $this->store->session($this->sessionId);
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? null;
        if (($turn['status'] ?? null) !== 'inProgress') {
            throw new RuntimeException('An active operator turn is required for controller admission.');
        }
        $wait = $this->waitPolicy();
        $identity = $this->connection->identity();
        $clock = $this->monotonicClock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $deadline = $clock() + ($wait['max_wait_seconds'] ?? 0);
        $lastReason = null;
        while (true) {
            $this->store->assertTurnNotCancelled($this->sessionId, $turn['id']);
            if ($this->connection->identity() !== $identity || $this->connection->policy() !== $policy
                || $this->waitPolicy() !== $wait) {
                throw new RuntimeException('Controller admission policy changed while waiting.');
            }
            if (($wait['enabled'] ?? false) && $clock() >= $deadline) {
                $this->waitEvent($target, $turn['id'], 'expired');
                throw new RuntimeException('Controller waiting deadline reached; no activation or model request was sent.');
            }
            $status = $this->connection->status($target)['controller'];
            $this->store->assertTurnNotCancelled($this->sessionId, $turn['id']);
            $ordinary = $status['lifecycle']['lease_present'] ?? null;
            $dedicated = $status['k3_lease_active'] ?? null;
            if (! is_bool($ordinary) || ! is_bool($dedicated)) {
                throw new RuntimeException('Existing or unverified host ownership blocks activation; no foreign lease was recovered.');
            }
            $reason = $ordinary || $dedicated ? 'busy' : null;
            if ($reason === null) {
                $plan = $this->connection->client()->plan($target, 'cli-plan-'.Str::uuid());
                $this->store->assertTurnNotCancelled($this->sessionId, $turn['id']);
                ModelHostControllerResourcePolicyService::assertReceiptBinding($binding, $plan['resource_assessment']['request_binding'] ?? null);
                $shortage = ModelHostControllerResourcePolicyService::temporaryShortage($plan, $target, $spec['model'], $spec['engine']);
                if ($shortage !== null) {
                    $reason = 'capacity';
                    if ($lastReason !== $reason) {
                        $this->store->recordEvent($this->sessionId, 'controller_capacity_unavailable', ['target' => $target, 'requirements' => $shortage]);
                    }
                } elseif (($plan['result'] ?? null) !== 'ready' || ($plan['model'] ?? null) !== $spec['model']
                    || ($plan['engine'] ?? null) !== $spec['engine'] || ($plan['activation_allowed'] ?? null) !== true
                    || ($plan['basic_resource_gate_passed'] ?? null) !== true || ($plan['lifecycle_resource_gate_passed'] ?? null) !== true
                    || ($plan['candidate_eligible_after_future_gate'] ?? null) !== true) {
                    throw new RuntimeException('Current exact-target controller plan denied activation.');
                }
            }
            if ($reason === null) {
                if (($wait['enabled'] ?? false) && $clock() >= $deadline) {
                    $this->waitEvent($target, $turn['id'], 'expired');
                    throw new RuntimeException('Controller waiting deadline reached; no activation or model request was sent.');
                }
                if ($lastReason !== null) {
                    $this->waitEvent($target, $turn['id'], 'ready');
                }

                return;
            }
            if (! ($wait['enabled'] ?? false)) {
                throw new RuntimeException($reason === 'busy'
                    ? 'Existing or unverified host ownership blocks activation; no foreign lease was recovered.'
                    : 'Host capacity is temporarily unavailable. No activation or model request was sent. Retry when resources are available; no alternate model was selected.');
            }
            if ($lastReason !== $reason) {
                $this->waitEvent($target, $turn['id'], $reason);
            }
            $lastReason = $reason;
            $milliseconds = (int) min($wait['poll_interval_ms'], max(0, ($deadline - $clock()) * 1000));
            if ($milliseconds > 0) {
                if ($this->waitSleeper) {
                    ($this->waitSleeper)($milliseconds);
                } else {
                    usleep($milliseconds * 1000);
                }
            }
        }
    }

    private function waitPolicy(): array
    {
        $policy = (new CliSystemConfigService($this->store, $this->sessionId))->get('ai.model_host_controller_wait_policy');
        if ($policy === null || ($policy['enabled'] ?? null) === false) {
            return ['enabled' => false];
        }
        if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.model_host_controller.wait_policy.v1'
            || ($policy['enabled'] ?? null) !== true) {
            throw new RuntimeException('Controller wait policy is invalid.');
        }
        foreach (['poll_interval_ms' => [100, 10000], 'max_wait_seconds' => [1, 900]] as $key => [$min, $max]) {
            if (! is_int($policy[$key] ?? null) || $policy[$key] < $min || $policy[$key] > $max) {
                throw new RuntimeException('Controller wait policy bounds are invalid.');
            }
        }

        return $policy;
    }

    private function waitEvent(string $target, string $turn, string $state): void
    {
        $this->store->recordEvent($this->sessionId, 'controller_wait', [
            'target' => $target, 'turn_id' => $turn, 'state' => $state, 'model_request_sent' => false,
        ]);
    }

    /** Manual activation proves the same selected request contract as automatic activation. */
    private function selectedCapacityBinding(array $session, string $target): ?array
    {
        $configuration = new CliConfiguration($this->store);
        $values = $configuration->effective($session)['values'];
        $policy = $values['ai.'.ModelHostControllerResourcePolicyService::CONFIG_KEY] ?? null;
        if ($policy === null || ($policy['enabled'] ?? null) === false) {
            return null;
        }
        $selection = $values['cli.model'] ?? null;
        if (! is_string($selection) || $selection === 'auto') {
            throw new RuntimeException('Select the exact model and role with /model and /role before manual host activation.');
        }
        $candidates = (new CliRouteResolver($configuration))->inspect($session)['candidates'];
        if (count($candidates) !== 1 || ! $candidates[0]['configuration_eligible']) {
            throw new RuntimeException('Manual activation requires one eligible selected model. Inspect /route.');
        }
        $candidate = $candidates[0];
        $contract = match (true) {
            ($candidate['instance']['instance_type'] ?? null) === 'ollama' => (new CliOllamaRuntimePolicy)->resolve($candidate, $values),
            ! CliAgentRuntime::isColibriEngine($candidate['engine']) && CliProviderRuntimePolicy::supports($candidate['instance']) => (new CliProviderRuntimePolicy)->resolve($candidate, $values),
            default => CliAgentRuntime::validateContract($candidate),
        };
        if (($contract['controller_target'] ?? null) !== $target) {
            throw new RuntimeException('Selected model belongs to a different controller target. No activation attempted.');
        }

        return CliAgentRuntime::assertCapacityContract($this->store, $session, $contract);
    }

    public function heartbeat(): array
    {
        return $this->mutate('heartbeat', $this->ownedLease(false));
    }

    /** Maintain this already-owned lease during I/O; never activate or recover. */
    public function keeper(string $target, string $model): Closure
    {
        $lease = $this->knownLease();
        if ($lease['target'] !== $target || $lease['model'] !== $model) {
            throw new RuntimeException('The selected model does not match this session lease.');
        }
        $session = $this->store->session($this->sessionId);
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? null;
        if (($turn['status'] ?? null) !== 'inProgress') {
            throw new RuntimeException('Lease maintenance requires an active terminal turn.');
        }
        $timing = $this->connection->renewalTiming();
        $clock = $this->monotonicClock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $next = 0;

        $maintain = function () use ($lease, $turn, $timing, $clock, &$next): void {
            $session = $this->store->session($this->sessionId);
            $current = $session['host_lease'] ?? [];
            $active = $session['turns'][array_key_last($session['turns'] ?? [])] ?? null;
            foreach (['lease_id', 'controller_identity', 'target', 'model', 'host_id'] as $key) {
                if (($current[$key] ?? null) !== $lease[$key]) {
                    throw new RuntimeException('Lease identity changed during the model request.');
                }
            }
            $expiry = strtotime($current['expires_at'] ?? '');
            if (($current['phase'] ?? null) !== 'owned' || $expiry === false || $expiry <= time()
                || ($active['id'] ?? null) !== $turn['id'] || ($active['status'] ?? null) !== 'inProgress'
                || ($active['cancel_requested'] ?? false) === true
                || $this->connection->identity() !== $lease['controller_identity']) {
                throw new RuntimeException('Lease or terminal turn is no longer valid; no recovery was attempted.');
            }
            if ($this->connection->renewalTiming() !== $timing) {
                throw new RuntimeException('Controller renewal timing changed during the model request.');
            }
            $timeout = $timing['heartbeat_timeout_seconds'];
            $interval = $timing['heartbeat_interval_seconds'];
            if ($expiry - time() <= $timeout) {
                throw new RuntimeException('Owned lease cannot cover its registered heartbeat timeout.');
            }
            if ($clock() < $next && $expiry - time() > $interval + $timeout) {
                return;
            }
            // Existing heartbeat journals the exact intent before HTTP. A lost
            // reply leaves heartbeat_pending and propagates; it is never retried.
            // The heartbeat endpoint itself atomically verifies this exact lease,
            // target health and resources without a second full status scan.
            $renewed = $this->mutate('heartbeat', $current, $timeout);
            if (strtotime($renewed['lease']['expires_at']) - time() <= $interval + $timeout) {
                throw new RuntimeException('Renewed lease is too short for its registered timing.');
            }
            $next = $clock() + $interval;
        };
        // Establish remote custody before returning a callback to model/tool I/O.
        $maintain();

        return $maintain;
    }

    public function release(): array
    {
        return $this->mutate('release', $this->ownedLease(true));
    }

    /** Reconcile evidence without replaying a request or touching foreign leases. */
    public function reconcile(): array
    {
        $session = $this->store->session($this->sessionId);
        $lease = $session['host_lease'] ?? null;
        if ($lease === null || ! str_ends_with($lease['phase'], '_pending')) {
            return $this->inspect();
        }
        $receipt = $this->store->actionReceipts($this->sessionId, $lease['turn_id'])[$lease['operation_id']] ?? null;
        if (($receipt['state'] ?? null) !== 'completed') {
            if (($receipt['state'] ?? null) === 'started' && $lease['operation'] === 'heartbeat'
                && $lease['controller_identity'] === $this->connection->identity()) {
                // A lost heartbeat reply does not prove renewal. Exact current
                // ownership is sufficient to release the lease, while the
                // original heartbeat receipt remains unresolved and unreplayed.
                $status = $this->connection->status()['controller'];
                $session['host_lease'] = array_replace($this->leaseFromStatus($lease, $status),
                    ['heartbeat_outcome_unknown' => true]);
                $this->store->saveSession($session);

                return $this->inspect();
            }
            if (($receipt['state'] ?? null) !== 'started' || $lease['operation'] !== 'activate'
                || $lease['controller_identity'] !== $this->connection->identity()) {
                throw new RuntimeException('Lifecycle outcome is unknown. Review private receipts and current host status; automatic replay or foreign cleanup is prohibited.');
            }
            $status = $this->connection->status()['controller'];
            if (($status['lifecycle']['lease_present'] ?? null) === false) {
                $lookup = $this->connection->client()->receipt('activate', $lease['target'], $lease['operation_id'], $lease['lease_id']);
                $remote = $lookup['receipt'] ?? null;
                if (($lookup['record_found'] ?? null) !== true || ($remote['result'] ?? null) !== 'activation_failed') {
                    throw new RuntimeException('No exact failed-start receipt proves cleanup; lifecycle outcome remains unknown.');
                }
                $completed = $this->completedLease($lease, $remote, true);
                $evidence = $remote;
            } else {
                $completed = $this->leaseFromStatus($lease, $status);
                $evidence = ['reconciled_from_status' => $status];
            }
            $this->store->completeAction($this->sessionId, $lease['turn_id'], $lease['operation_id'],
                ['success' => $completed['phase'] !== 'not_started', 'output' => json_encode($evidence, JSON_THROW_ON_ERROR)]);
            $session = $this->store->session($this->sessionId);
            $session['host_lease'] = $completed;
            $this->store->saveSession($session);

            return $this->inspect();
        }
        $response = json_decode($receipt['document']['result']['output'], true, flags: JSON_THROW_ON_ERROR);
        $session['host_lease'] = $this->completedLease($lease, $response, true);
        $this->store->saveSession($session);

        return $this->inspect();
    }

    private function knownLease(): array
    {
        $lease = $this->store->session($this->sessionId)['host_lease'] ?? null;
        if (($lease['phase'] ?? null) !== 'owned' || $lease['controller_identity'] !== $this->connection->identity()) {
            throw new RuntimeException('An exact known lease on the currently bound controller is required.');
        }

        return $lease;
    }

    private function ownedLease(bool $allowExpired): array
    {
        $lease = $this->knownLease();
        // Heartbeat and release need fresh custody for this owned target.
        // Keep the legacy path for removed/dedicated targets, chosen before I/O;
        // a refused selected read is never retried with a global scan.
        $target = $lease['target'] !== 'kimi_k3_87'
            && isset($this->connection->policy()['targets'][$lease['target']]) ? $lease['target'] : null;
        $controller = $this->connection->status($target)['controller'];
        if ($target !== null && ($controller['k3_lease_active'] ?? null) !== false) {
            throw new RuntimeException('Selected controller custody is incomplete or occupied.');
        }
        $status = $controller['lifecycle'] ?? [];
        $targetMatches = ($status['active_target'] ?? null) === $lease['target']
            || ($allowExpired && ($status['lease_expired'] ?? null) === true && ($status['active_target'] ?? null) === null);
        if (($status['lease_present'] ?? null) !== true || ! $targetMatches
            || ($status['lease_id_sha256'] ?? null) !== hash('sha256', $lease['lease_id'])
            || (! $allowExpired && ($status['lease_expired'] ?? null) !== false)) {
            throw new RuntimeException('Current controller ownership differs or is unverified; no foreign lease was touched.');
        }

        return $lease;
    }

    private function mutate(string $operation, array $lease, ?int $heartbeatTimeout = null): array
    {
        $session = $this->store->session($this->sessionId);
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? null;
        if (($turn['status'] ?? null) !== 'inProgress') {
            throw new RuntimeException('An active operator command turn is required for lifecycle changes.');
        }
        $request = 'cli-request-'.Str::uuid();
        $payload = ['request_id' => $request, 'lease_id' => $lease['lease_id'], 'execute' => true];
        if ($operation === 'activate') {
            $payload = ['target' => $lease['target']] + $payload;
        }
        $action = ['id' => $request, 'name' => 'host.'.$operation, 'arguments' => $payload, 'origin' => 'controller_lifecycle'];
        $this->store->startAction($this->sessionId, $turn['id'], $action, ['instance_id' => $lease['target'], 'model' => $lease['model']]);
        $previous = $lease['phase'];
        $lease = array_replace($lease, ['phase' => $operation.'_pending', 'operation' => $operation,
            'operation_id' => $request, 'turn_id' => $turn['id']]);
        $session['host_lease'] = $lease;
        $this->store->saveSession($session);
        $sent = false;
        $client = $this->connection->client(function ($method, $path, $actual) use ($lease, $payload, $operation, &$sent): bool {
            $current = $this->store->session($this->sessionId)['host_lease'] ?? null;
            $receipt = $this->store->actionReceipts($this->sessionId, $lease['turn_id'])[$lease['operation_id']] ?? null;
            if ($current !== $lease || ($receipt['state'] ?? null) !== 'started'
                || $method !== 'post' || $path !== '/v1/'.$operation || $actual !== $payload
                || $lease['controller_identity'] !== $this->connection->identity()) {
                return false;
            }
            // Recheck cancellation after admission reads and immediately before any mutation.
            // A fresh cleanup turn can still release an earlier cancelled job.
            $this->store->assertTurnNotCancelled($this->sessionId, $lease['turn_id']);
            $sent = true;

            return true;
        });
        try {
            $response = match ($operation) {
                'activate' => $client->activate($lease['target'], $request, $lease['lease_id']),
                'heartbeat' => $client->heartbeat($request, $lease['lease_id'], $heartbeatTimeout),
                'release' => $client->release($request, $lease['lease_id']),
            };
            $completed = $this->completedLease($lease, $response);
            $this->store->completeAction($this->sessionId, $turn['id'], $request,
                ['success' => $completed['phase'] !== 'not_started', 'output' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            $session = $this->store->session($this->sessionId);
            $session['host_lease'] = $completed;
            $this->store->saveSession($session);
        } catch (Throwable $error) {
            if (! $sent) {
                $this->store->completeAction($this->sessionId, $turn['id'], $request, ['success' => false, 'output' => 'Controller request was not sent.']);
                $session = $this->store->session($this->sessionId);
                $session['host_lease']['phase'] = $previous;
                $this->store->saveSession($session);
            }
            $diagnostic = $error instanceof CliControllerGateFailure ? $error->getMessage().' ' : '';
            throw new RuntimeException($diagnostic.($sent ? 'Lifecycle outcome is unknown; inspect /host lease and private receipts. No automatic retry or cleanup was attempted.'
                : 'Lifecycle request was refused before HTTP. Review current controller policy, endpoint and credentials.'));
        }

        if ($completed['phase'] === 'not_started') {
            throw new RuntimeException('Model startup failed; owned cleanup is verified. Inspect /host lease for the phase. No automatic retry or fallback was attempted.');
        }

        return $this->inspect();
    }

    private function completedLease(array $lease, array $response, bool $recorded = false): array
    {
        if (isset($response['reconciled_from_status']) && $lease['operation'] === 'activate') {
            return $this->leaseFromStatus($lease, $response['reconciled_from_status'], $recorded);
        }
        if (($response['target'] ?? null) !== $lease['target'] || ($response['lease_id'] ?? null) !== $lease['lease_id']
            || ($response['request_id'] ?? null) !== $lease['operation_id'] || ($response['fallback_enabled'] ?? null) !== false) {
            throw new RuntimeException('Controller returned an inexact lifecycle outcome.');
        }
        if ($lease['operation'] === 'release') {
            if (($response['result'] ?? null) !== 'released' || ($response['cleanup_verified'] ?? null) !== true) {
                throw new RuntimeException('Controller cleanup is unverified.');
            }
            $lease['phase'] = 'released';

            return $lease;
        }
        if ($lease['operation'] === 'activate' && ($response['result'] ?? null) === 'activation_failed') {
            $failure = $response['startup_failure'] ?? [];
            if (($response['cleanup_verified'] ?? null) !== true || ($response['lease_present'] ?? null) !== false
                || ($response['tools_enabled'] ?? null) !== false || ($failure['cleanup_verified'] ?? null) !== true
                || ! in_array($failure['phase'] ?? null, ['verification', 'readiness'], true)
                || ! in_array($failure['code'] ?? null, ['verification_timeout', 'readiness_timeout', 'task_exited', 'startup_error'], true)
                || ! is_numeric($failure['elapsed_seconds'] ?? null) || ! is_finite((float) $failure['elapsed_seconds'])
                || $failure['elapsed_seconds'] < 0) {
                throw new RuntimeException('Controller failed-start cleanup is unverified.');
            }

            return array_replace($lease, ['phase' => 'not_started', 'expires_at' => null,
                'startup_failure' => array_intersect_key($failure, array_flip(['phase', 'code', 'elapsed_seconds']))]);
        }
        ModelHostControllerResourcePolicyService::assertReceiptBinding($lease['capacity_binding'] ?? null,
            $response['resource_assessment']['request_binding'] ?? null);
        $results = $lease['operation'] === 'activate' ? ['activated', 'already_ready'] : ['renewed'];
        $expiry = strtotime($response['lease_expires_at'] ?? '');
        if (! in_array($response['result'] ?? null, $results, true) || ($response['exact_identity'] ?? null) !== true
            || ($response['resource_gate_passed'] ?? null) !== true || $expiry === false || (! $recorded && $expiry <= time())) {
            throw new RuntimeException('Controller lease identity, resources or expiry are unverified.');
        }

        return array_replace($lease, ['phase' => 'owned', 'expires_at' => $response['lease_expires_at']]);
    }

    private function leaseFromStatus(array $lease, array $status, bool $recorded = false): array
    {
        $current = $status['lifecycle'] ?? [];
        $expiry = strtotime($current['lease_expires_at'] ?? '');
        if (($status['schema'] ?? null) !== 'plos.model_host_controller.status.v1' || ($status['host_id'] ?? null) !== $lease['host_id']
            || ($current['lease_present'] ?? null) !== true || ($current['lease_expired'] ?? null) !== false
            || ($current['active_target'] ?? null) !== $lease['target']
            || ($current['lease_id_sha256'] ?? null) !== hash('sha256', $lease['lease_id'])
            || $expiry === false || (! $recorded && $expiry <= time())) {
            throw new RuntimeException('Current status does not prove this exact lease; unknown ownership remains unresolved.');
        }

        ModelHostControllerResourcePolicyService::assertReceiptBinding($lease['capacity_binding'] ?? null, $current['capacity_binding'] ?? null);

        return array_replace($lease, ['phase' => 'owned', 'expires_at' => $current['lease_expires_at'], 'reconciled_from_status' => true]);
    }
}
