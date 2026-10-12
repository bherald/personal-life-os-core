<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable, content-free custody inside the existing task table; never dispatches a queue job. */
class ModelHostControllerSelectionJournal
{
    public const TASK_TYPE = 'mhc06_selection_journal_v1';

    public const SLOT_ID = 'mhc06-selection-custody';

    public const SCHEMA = 'plos.model_host_controller.selection_journal.v1';

    /** Called with the exact wait mutex/ticket locked; never invokes the host controller. */
    public function fenceReservedApplicationRequest(string $host, string $requestId, array $waitPreimage,
        string $evidenceRef, ?string $expectedDigest, bool $apply): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Reserved fence requires the existing wait transaction.');
        }
        $slot = $this->slot();
        $state = $this->decode($slot->result);
        $key = $this->requestKey($requestId);
        if (($state['phase'] ?? null) !== 'owned' || ! Str::isUuid($state['owner'] ?? '')
            || ($state['request_keys'] ?? null) !== [$key]
            || array_diff(array_keys($state), ['schema', 'phase', 'owner', 'request_keys']) !== []) {
            throw new RuntimeException('Exact single owned reserved custody is unavailable.');
        }
        $row = $this->ownedRequest($state, $state['owner'], $requestId);
        $payload = $this->decode($row->payload);
        $prior = $this->decode($row->result);
        if (($prior['phase'] ?? null) !== 'reserved' || array_diff(array_keys($prior), ['schema', 'phase']) !== []
            || ($payload['controller']['host_id'] ?? null) !== $host
            || ! is_string($payload['controller']['target'] ?? null) || $payload['controller']['target'] === ''
            || ! is_string($payload['lease_id'] ?? null) || $payload['lease_id'] === ''
            || ! is_string($payload['role'] ?? null) || $payload['role'] === ''
            || array_diff(array_keys($payload), ['schema', 'owner', 'request_id', 'lease_id', 'role', 'controller']) !== []
            || array_diff(array_keys($payload['controller']), ['host_id', 'target']) !== []
            || $row->assigned_agent_id !== null || $row->timeout_at !== null || (int) $row->max_retries !== 0) {
            throw new RuntimeException('Reserved journal has advanced or inconsistent evidence.');
        }
        $preimage = [...$waitPreimage, 'slot' => (array) $slot, 'journal' => (array) $row];
        $digest = ModelHostControllerResourcePolicyService::requestContractIdentity(['preimage' => $preimage, 'evidence_ref' => $evidenceRef]);
        if (($apply && $expectedDigest === null) || ($expectedDigest !== null && ! hash_equals($digest, $expectedDigest))) {
            throw new RuntimeException('Reserved fence preview changed; no application state was retired.');
        }
        $receipt = ['schema' => 'plos.model_host_controller.reserved_application_fence.v1',
            'ticket_id' => $waitPreimage['ticket']['id'], 'host_id' => $host, 'request_id' => $requestId,
            'journal_task_id' => $key, 'target' => $payload['controller']['target'],
            'lease_id' => $payload['lease_id'], 'preview_digest' => $digest, 'evidence_ref' => $evidenceRef,
            'result' => $apply ? 'fenced' : 'ready', 'applied' => $apply,
            'application_admission_retired' => $apply, 'host_observed' => false, 'host_mutations' => false,
            'model_replayed' => false, 'original_request_replay_forbidden' => true,
            'inference_outcome' => 'not_dispatched_by_this_reservation'];
        if ($apply) {
            // Keep the request row as a permanent no-replay record and retain every full preimage.
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode(['schema' => self::SCHEMA, 'phase' => 'application_reserved_fenced',
                    'fence' => $receipt, 'preimage' => $preimage], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->writeSlot($slot, ['phase' => 'idle']);
        }

        return $receipt;
    }

    /** Read-only diagnosis; does not acquire, recover, clear, or replay custody. */
    public function inspect(): array
    {
        $row = DB::table('distributed_tasks')->where('task_id', self::SLOT_ID)->first();
        if ($row === null) {
            return ['registered' => false, 'phase' => 'unavailable', 'content_free' => true];
        }
        if ($row->task_type !== self::TASK_TYPE || $row->status !== 'held') {
            throw new RuntimeException('Controller custody registration is invalid.');
        }
        $state = $this->decode($row->result);

        return ['registered' => true, 'phase' => $state['phase'] ?? 'invalid',
            'operation' => $state['operation'] ?? null, 'heartbeat' => $state['heartbeat'] ?? null, 'request_key' => $state['request_key'] ?? null,
            'request_count' => count($state['request_keys'] ?? []),
            'reconciliation_required' => ($state['phase'] ?? null) !== 'idle', 'content_free' => true];
    }

    /** Reserve before controller I/O. A lost process never frees this slot by elapsed time. */
    public function begin(array $requests): ?string
    {
        return DB::transaction(function () use ($requests): ?string {
            $slot = $this->slot();
            if (app()->isDownForMaintenance()) {
                return null;
            }
            $state = $this->decode($slot->result);
            if (($state['phase'] ?? null) !== 'idle') {
                return null;
            }
            $ids = array_map(fn (array $request): string => $this->requestKey($request['request_id']), $requests);
            if (count(array_unique($ids)) !== count($ids)) {
                throw new RuntimeException('Controller request identities must be unique within a batch.');
            }
            // Retained completions and unresolved attempts both forbid replay, even with a different lease.
            if (DB::table('distributed_tasks')->whereIn('task_id', $ids)->exists()) {
                return null;
            }
            $owner = (string) Str::uuid();
            foreach ($requests as $request) {
                DB::table('distributed_tasks')->insert([
                    ...self::baseRow($this->requestKey($request['request_id'])),
                    'payload' => json_encode(['schema' => self::SCHEMA, 'owner' => $owner,
                        'request_id' => $request['request_id'], 'lease_id' => $request['lease_id'],
                        'role' => $request['role'],
                        'controller' => isset($request['capacity_binding']) ? [
                            'host_id' => $request['capacity_binding']['host_id'] ?? null,
                            'target' => $request['capacity_binding']['target'] ?? null,
                        ] : null], JSON_THROW_ON_ERROR),
                    'result' => json_encode(['schema' => self::SCHEMA, 'phase' => 'reserved'], JSON_THROW_ON_ERROR),
                ]);
            }
            $this->writeSlot($slot, ['phase' => 'owned', 'owner' => $owner, 'request_keys' => $ids]);

            return $owner;
        });
    }

    /** Read-only app admission clearance under the same lock as begin; no physical host claim. */
    public function maintenanceAdmissionCheck(): array
    {
        $report = static fn (?string $reason): array => ['schema' => 'plos.maintenance_admission_check.v1',
            'success' => $reason === null, 'reason_code' => $reason];
        try {
            return DB::transaction(function () use ($report): array {
                $slot = $this->slot();
                if (! app()->isDownForMaintenance()) {
                    return $report('maintenance_not_active');
                }
                // Pool concurrency custody is independent of the controller's physical journal.
                if (DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_LLM_POOL_EXECUTION)
                    ->whereNull('released_at')->exists()) {
                    return $report('pool_execution_custody_held');
                }
                $state = $this->decode($slot->result);
                if (($state['phase'] ?? null) !== 'idle') {
                    return $report('custody_not_idle');
                }
                if (array_diff(array_keys($state), ['schema', 'phase']) !== []) {
                    return $report('guard_unavailable');
                }
                // Wait mutex owners may next acquire the slot: read committed wait state without
                // reversing wait→slot lock order. Down mode prevents any new journal reservation.
                foreach (DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                    ->where('status', 'held')->get(['result']) as $ticket) {
                    $state = json_decode($ticket->result ?? '', true, flags: JSON_THROW_ON_ERROR);
                    if (in_array($state['phase'] ?? null, ['attempting', 'outcome_unknown'], true)) {
                        return $report('wait_attempt_inflight');
                    }
                    if (! in_array($state['phase'] ?? null, ['waiting', 'expired', 'finished', 'admitted', 'cancelled'], true)) {
                        return $report('guard_unavailable');
                    }
                }

                return $report(app()->isDownForMaintenance() ? null : 'maintenance_not_active');
            });
        } catch (\Throwable) {
            return $report('guard_unavailable');
        }
    }

    /** Persist intent before activation, executor dispatch or release crosses its boundary. */
    public function checkpoint(string $owner, string $requestId, string $phase): void
    {
        if (! in_array($phase, ['activation_pending', 'dispatch_pending', 'release_pending'], true)) {
            throw new RuntimeException('Controller journal phase is invalid.');
        }
        DB::transaction(function () use ($owner, $requestId, $phase): void {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            if (($state['heartbeat']['phase'] ?? null) === 'pending') {
                throw new RuntimeException('Unresolved heartbeat forbids further lifecycle mutations.');
            }
            $key = $this->requestKey($requestId);
            if (! in_array($key, $state['request_keys'] ?? [], true)) {
                throw new RuntimeException('Controller request is outside journal custody.');
            }
            $row = DB::table('distributed_tasks')->where('task_id', $key)->lockForUpdate()->first();
            $payload = $row === null ? [] : $this->decode($row->payload);
            if ($row === null || $row->task_type !== self::TASK_TYPE || $row->status !== 'held'
                || ($payload['owner'] ?? null) !== $owner || ($payload['request_id'] ?? null) !== $requestId) {
                throw new RuntimeException('Controller request journal identity changed.');
            }
            $prior = $this->decode($row->result);
            if (($prior['caller_closed'] ?? false) === true) {
                throw new RuntimeException('Closed controller caller cannot resume lifecycle work.');
            }
            if ($phase === 'dispatch_pending' && ($prior['dispatched'] ?? false)) {
                throw new RuntimeException('Controller request was already dispatched.');
            }
            $intents = $prior['lifecycle_intents'] ?? [];
            if ($phase === 'activation_pending') {
                $intents['activate'] = $requestId.'-activate';
            } elseif ($phase === 'release_pending') {
                $intents['release'] = $requestId.'-release';
            }
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode([...$prior, 'schema' => self::SCHEMA, 'phase' => $phase, 'lifecycle_intents' => $intents,
                    'dispatched' => $phase === 'dispatch_pending' || ($prior['dispatched'] ?? false)], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->writeSlot($slot, [...$state, 'operation' => $phase, 'request_key' => $key]);
        });
    }

    /** Close one exact caller and reserve cleanup; an unresolved renewal is retained, never retried. */
    public function releaseIntent(string $owner, string $requestId, string $leaseId, bool $executionCompleted): bool
    {
        return DB::transaction(function () use ($owner, $requestId, $leaseId, $executionCompleted): bool {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            $row = $this->ownedRequest($state, $owner, $requestId);
            $payload = $this->decode($row->payload);
            $prior = $this->decode($row->result);
            if (($payload['lease_id'] ?? null) !== $leaseId || ($prior['caller_closed'] ?? false)
                || ! in_array($prior['phase'] ?? null, ['activation_pending', 'dispatch_pending'], true)) {
                throw new RuntimeException('Cleanup requires the original open caller and exact lease.');
            }
            $pending = ($state['heartbeat']['phase'] ?? null) === 'pending';
            if ($pending && (($state['request_key'] ?? null) !== $this->requestKey($requestId)
                || ($prior['heartbeat'] ?? null) !== $state['heartbeat']
                || ($state['operation'] ?? null) !== 'heartbeat_pending'
                || ($state['heartbeat']['lease_id'] ?? null) !== $leaseId
                || ($state['heartbeat']['target'] ?? null) !== ($payload['controller']['target'] ?? null)
                || ($state['heartbeat']['host_id'] ?? null) !== ($payload['controller']['host_id'] ?? null))) {
                throw new RuntimeException('Pending renewal differs from exact cleanup custody.');
            }
            if (($prior['heartbeat']['phase'] ?? null) === 'pending' && ! $pending) {
                throw new RuntimeException('Request renewal custody differs from the journal slot.');
            }
            $intents = $prior['lifecycle_intents'] ?? [];
            $intents['release'] = $requestId.'-release';
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode([...$prior, 'phase' => 'release_pending', 'caller_closed' => true,
                    'execution_completed' => ! $pending && $executionCompleted && ($prior['dispatched'] ?? false),
                    'lifecycle_intents' => $intents], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->writeSlot($slot, [...$state, 'operation' => 'release_pending', 'request_key' => $this->requestKey($requestId)]);

            return $pending;
        });
    }

    /** Record that the synchronous executor returned; this does not persist or recover its answer. */
    public function executionComplete(string $owner, string $requestId): void
    {
        DB::transaction(function () use ($owner, $requestId): void {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            $row = $this->ownedRequest($state, $owner, $requestId);
            $prior = $this->decode($row->result);
            if (($prior['dispatched'] ?? null) !== true || ($prior['phase'] ?? null) !== 'dispatch_pending'
                || ($state['heartbeat']['phase'] ?? null) === 'pending') {
                throw new RuntimeException('Executor completion is outside verified dispatch custody.');
            }
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode([...$prior, 'execution_completed' => true], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
    }

    /** Only a terminal invocation can be reconciled; age alone never fences a live or crashed writer. */
    public function reconciliationSnapshot(): array
    {
        return DB::transaction(fn (): array => $this->snapshot($this->slot()));
    }

    private function snapshot(object $slot): array
    {
        $state = $this->decode($slot->result);
        if (($state['phase'] ?? null) !== 'outcome_unknown') {
            throw new RuntimeException('Custody requires a terminal held invocation before reconciliation.');
        }
        $requests = [];
        foreach ($state['request_keys'] ?? [] as $key) {
            $row = DB::table('distributed_tasks')->where('task_id', $key)->lockForUpdate()->first();
            $payload = $row === null ? [] : $this->decode($row->payload);
            if ($row === null || $row->task_type !== self::TASK_TYPE || $row->status !== 'held'
                || ($payload['owner'] ?? null) !== ($state['owner'] ?? null)
                || ! is_string($payload['request_id'] ?? null) || $this->requestKey($payload['request_id']) !== $key) {
                throw new RuntimeException('Reconciliation request custody is inconsistent.');
            }
            $requests[$key] = ['payload' => $payload, 'state' => $this->decode($row->result)];
        }
        if ($requests === []) {
            throw new RuntimeException('Reconciliation has no durable request evidence.');
        }
        $snapshot = ['state' => $state, 'requests' => $requests];

        return [...$snapshot, 'digest' => ModelHostControllerResourcePolicyService::requestContractIdentity($snapshot)];
    }

    /** Free only the batch slot; retain every original request as a no-replay record. */
    public function completeReconciliation(string $expectedDigest, array $proofs): void
    {
        DB::transaction(function () use ($expectedDigest, $proofs): void {
            $slot = $this->slot();
            $snapshot = $this->snapshot($slot);
            if (! hash_equals($snapshot['digest'], $expectedDigest)
                || array_diff(array_keys($snapshot['requests']), array_keys($proofs)) !== []
                || count($proofs) !== count($snapshot['requests'])) {
                throw new RuntimeException('Reconciliation custody changed or evidence is incomplete.');
            }
            foreach ($snapshot['requests'] as $key => $request) {
                $proof = $proofs[$key];
                $prior = $request['state'];
                if (($proof['request_id'] ?? null) !== $request['payload']['request_id']
                    || ($proof['lease_id'] ?? null) !== $request['payload']['lease_id']
                    || ($proof['custody_digest'] ?? null) !== $expectedDigest
                    || ($proof['host_custody_clear'] ?? null) !== true
                    || ($proof['owned_work_ended'] ?? null) !== true) {
                    throw new RuntimeException('Reconciliation proof does not cover original ownership.');
                }
                // No result content is invented or replayed. Answers remain owned by the original caller.
                $outcome = ($prior['execution_completed'] ?? false) ? 'executor_returned'
                    : (($prior['dispatched'] ?? false) ? 'unknown' : 'not_dispatched');
                DB::table('distributed_tasks')->where('task_id', $key)->update([
                    'result' => json_encode([...$prior, 'phase' => 'reconciled',
                        'inference_outcome' => $outcome, 'reconciliation' => $proof], JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            $this->writeSlot($slot, ['phase' => 'idle']);
        });
    }

    /** Retain the exact renewal intent before the controller mutation; never retry it. */
    public function heartbeatIntent(string $owner, string $requestId, string $heartbeatId, array $binding): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $heartbeatId) !== 1) {
            throw new RuntimeException('Controller heartbeat identity is invalid.');
        }
        DB::transaction(function () use ($owner, $requestId, $heartbeatId, $binding): void {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            if (($state['heartbeat']['phase'] ?? null) === 'pending') {
                throw new RuntimeException('Controller heartbeat outcome is unresolved.');
            }
            $row = $this->ownedRequest($state, $owner, $requestId);
            $prior = $this->decode($row->result);
            if (($prior['dispatched'] ?? null) !== true || ($prior['phase'] ?? null) !== 'dispatch_pending'
                || ($prior['heartbeat']['request_id'] ?? null) === $heartbeatId) {
                throw new RuntimeException('Controller heartbeat is outside active dispatch or was already used.');
            }
            $payload = $this->decode($row->payload);
            $heartbeat = ['phase' => 'pending', 'request_id' => $heartbeatId, 'lease_id' => $payload['lease_id'],
                'host_id' => $binding['host_id'], 'target' => $binding['target'],
                'binding_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($binding),
                'sequence' => ($prior['heartbeat']['sequence'] ?? 0) + 1];
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode([...$prior, 'heartbeat' => $heartbeat], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->writeSlot($slot, [...$state, 'operation' => 'heartbeat_pending',
                'request_key' => $this->requestKey($requestId), 'heartbeat' => $heartbeat]);
        });
    }

    /** Store only content-free receipt identity after full caller validation. */
    public function heartbeatComplete(string $owner, string $requestId, string $heartbeatId, array $receipt): void
    {
        DB::transaction(function () use ($owner, $requestId, $heartbeatId, $receipt): void {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            $row = $this->ownedRequest($state, $owner, $requestId);
            $prior = $this->decode($row->result);
            $heartbeat = $prior['heartbeat'] ?? [];
            if (($state['operation'] ?? null) !== 'heartbeat_pending'
                || ($state['request_key'] ?? null) !== $this->requestKey($requestId)
                || ($state['heartbeat'] ?? null) !== $heartbeat || ($heartbeat['phase'] ?? null) !== 'pending'
                || ($heartbeat['request_id'] ?? null) !== $heartbeatId
                || ($receipt['request_id'] ?? null) !== $heartbeatId
                || ($receipt['lease_id'] ?? null) !== $heartbeat['lease_id']
                || ($receipt['host_id'] ?? null) !== $heartbeat['host_id']
                || ($receipt['target'] ?? null) !== $heartbeat['target']
                || ($receipt['result'] ?? null) !== 'renewed'
                || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
                || ! is_string($receipt['lease_expires_at'] ?? null)
                || ! is_array($receipt['resource_assessment']['request_binding'] ?? null)
                || ModelHostControllerResourcePolicyService::requestContractIdentity($receipt['resource_assessment']['request_binding']) !== $heartbeat['binding_sha256']) {
                throw new RuntimeException('Controller heartbeat receipt differs from durable intent.');
            }
            $heartbeat = [...$heartbeat, 'phase' => 'renewed', 'lease_expires_at' => $receipt['lease_expires_at']];
            DB::table('distributed_tasks')->where('id', $row->id)->update([
                'result' => json_encode([...$prior, 'heartbeat' => $heartbeat], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $this->writeSlot($slot, [...$state, 'operation' => 'dispatch_pending', 'heartbeat' => $heartbeat]);
        });
    }

    private function ownedRequest(array $state, string $owner, string $requestId): object
    {
        $key = $this->requestKey($requestId);
        $row = DB::table('distributed_tasks')->where('task_id', $key)->lockForUpdate()->first();
        $payload = $row === null ? [] : $this->decode($row->payload);
        if (! in_array($key, $state['request_keys'] ?? [], true) || $row === null
            || $row->task_type !== self::TASK_TYPE || $row->status !== 'held'
            || ($payload['owner'] ?? null) !== $owner || ($payload['request_id'] ?? null) !== $requestId) {
            throw new RuntimeException('Controller request journal identity changed.');
        }

        return $row;
    }

    /** Release only a verified terminal batch. Any exception leaves durable custody intact. */
    public function finish(string $owner, array $receipt): void
    {
        DB::transaction(function () use ($owner, $receipt): void {
            $slot = $this->slot();
            $state = $this->owned($slot, $owner);
            if (($receipt['result'] ?? null) === 'held') {
                $this->writeSlot($slot, [...$state, 'phase' => 'outcome_unknown', 'receipt' => $receipt]);

                return;
            }
            if (($state['heartbeat']['phase'] ?? null) === 'pending') {
                throw new RuntimeException('Unresolved heartbeat cannot complete custody.');
            }
            $results = $receipt['results'] ?? [];
            $keys = array_map(fn (array $result): string => $this->requestKey($result['request_id']), $results);
            if (count(array_unique($keys)) !== count($keys)
                || count($keys) !== count($state['request_keys'])
                || array_diff($keys, $state['request_keys']) !== []) {
                throw new RuntimeException('Controller terminal receipt does not cover its complete batch.');
            }
            foreach ($results as $result) {
                $key = $this->requestKey($result['request_id']);
                $row = DB::table('distributed_tasks')->where('task_id', $key)->lockForUpdate()->first();
                $payload = $row === null ? [] : $this->decode($row->payload);
                if ($row === null || $row->task_type !== self::TASK_TYPE || $row->status !== 'held'
                    || ($payload['owner'] ?? null) !== $owner
                    || ($payload['lease_id'] ?? null) !== ($result['lease_id'] ?? null)
                    || ($payload['role'] ?? null) !== ($result['role'] ?? null)) {
                    throw new RuntimeException('Controller terminal request identity changed.');
                }
                $prior = $this->decode($row->result);
                if (($result['result'] ?? null) === 'green') {
                    // Remain outside the generic queue and terminal-task retention sweeper.
                    DB::table('distributed_tasks')->where('id', $row->id)->update([
                        'result' => json_encode([...$prior, 'schema' => self::SCHEMA, 'phase' => 'completed', 'receipt' => $result], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                } elseif (($result['result'] ?? null) === 'queued' && ($prior['dispatched'] ?? false) === false) {
                    // Verified pre-dispatch refusal can be retried with the original request identity.
                    DB::table('distributed_tasks')->where('id', $row->id)->delete();
                } else {
                    throw new RuntimeException('Dispatched or uncertain controller work cannot be retried.');
                }
            }
            $this->writeSlot($slot, ['phase' => 'idle']);
        });
    }

    public static function baseRow(string $taskId): array
    {
        return ['task_id' => $taskId, 'task_type' => self::TASK_TYPE,
            'payload' => json_encode(['schema' => self::SCHEMA], JSON_THROW_ON_ERROR),
            'result' => json_encode(['schema' => self::SCHEMA, 'phase' => 'idle'], JSON_THROW_ON_ERROR),
            'required_capabilities' => '[]', 'assigned_agent_id' => null, 'status' => 'held',
            'priority' => 0, 'retry_count' => 0, 'max_retries' => 0, 'timeout_at' => null,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function slot(): object
    {
        $row = DB::table('distributed_tasks')->where('task_id', self::SLOT_ID)->lockForUpdate()->first();
        if ($row === null || $row->task_type !== self::TASK_TYPE || $row->status !== 'held'
            || $row->assigned_agent_id !== null || $row->timeout_at !== null || (int) $row->max_retries !== 0) {
            throw new RuntimeException('Durable controller custody is missing or invalid.');
        }
        $this->decode($row->payload);

        return $row;
    }

    private function owned(object $slot, string $owner): array
    {
        $state = $this->decode($slot->result);
        if (($state['phase'] ?? null) !== 'owned' || ($state['owner'] ?? null) !== $owner) {
            throw new RuntimeException('Durable controller custody differs or is unresolved.');
        }

        return $state;
    }

    private function writeSlot(object $slot, array $state): void
    {
        DB::table('distributed_tasks')->where('id', $slot->id)->update([
            'result' => json_encode(['schema' => self::SCHEMA, ...$state], JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    private function decode(?string $json): array
    {
        $state = json_decode($json ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state) || ($state['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Controller journal envelope is invalid.');
        }

        return $state;
    }

    private function requestKey(string $requestId): string
    {
        return hash('sha256', self::TASK_TYPE.':'.$requestId);
    }
}
