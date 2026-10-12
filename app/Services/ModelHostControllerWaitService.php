<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Per-database FIFO waiting; host controller custody still serializes DEV and PROD globally. */
class ModelHostControllerWaitService
{
    public const TASK_TYPE = 'model_host_controller_wait_v1';

    public const MUTEX_TYPE = 'model_host_controller_wait_mutex_v1';

    /** Operator fence of application admission only; makes no assertion about physical host work. */
    public function fenceReserved(int $ticketId, string $host, string $request, string $evidenceRef,
        ?string $expectedDigest = null, bool $apply = false): array
    {
        if ($ticketId < 1 || $this->identity($host) !== $host || $this->identity($request) !== $request
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,199}\z/', $evidenceRef) !== 1
            || ($expectedDigest !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1)
            || ($apply && $expectedDigest === null)) {
            throw new RuntimeException('Exact reserved fence arguments and apply preview digest are required.');
        }

        return DB::transaction(function () use ($ticketId, $host, $request, $evidenceRef, $expectedDigest, $apply): array {
            // Same wait-before-journal order as admission; journal checkpoint locks slot before request.
            $mutex = DB::table('distributed_tasks')->where('task_id', hash('sha256', self::MUTEX_TYPE.':'.$host))->lockForUpdate()->first();
            $ticket = DB::table('distributed_tasks')->where('id', $ticketId)->lockForUpdate()->first();
            $payload = $ticket === null ? [] : json_decode($ticket->payload, true, flags: JSON_THROW_ON_ERROR);
            $state = $ticket === null ? [] : json_decode($ticket->result, true, flags: JSON_THROW_ON_ERROR);
            if ($mutex === null || $mutex->task_type !== self::MUTEX_TYPE || $mutex->status !== 'held'
                || (json_decode($mutex->payload, true, flags: JSON_THROW_ON_ERROR)['host_id'] ?? null) !== $host
                || $ticket === null || $ticket->task_type !== self::TASK_TYPE || $ticket->status !== 'held'
                || $ticket->task_id !== hash('sha256', self::TASK_TYPE.':'.$host.':'.$request)
                || ($payload['host_id'] ?? null) !== $host || ($payload['request_id'] ?? null) !== $request
                || array_diff(array_keys($payload), ['host_id', 'request_id', 'deadline_at']) !== []
                || ($state['phase'] ?? null) !== 'attempting'
                || array_diff(array_keys($state), ['phase', 'reason']) !== [] || ($state['reason'] ?? null) !== null) {
                throw new RuntimeException('Exact attempting wait reservation is unavailable or changed.');
            }
            $receipt = app(ModelHostControllerSelectionJournal::class)->fenceReservedApplicationRequest(
                $host, $request, ['mutex' => (array) $mutex, 'ticket' => (array) $ticket],
                $evidenceRef, $expectedDigest, $apply);
            if ($apply) {
                DB::table('distributed_tasks')->where('id', $ticketId)->update([
                    'status' => 'completed', 'result' => json_encode(['phase' => 'finished',
                        'reason' => 'reserved_application_fence', 'proof' => $receipt], JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }

            return $receipt;
        });
    }

    /** Operator-only recovery of a held FIFO ticket after independent host evidence. */
    public function reconcileOrphan(
        int $ticketId,
        string $host,
        string $request,
        string $laterLeaseAcquiredAt,
        string $evidenceRef,
        bool $apply = false,
    ): array {
        if ($ticketId < 1 || $this->identity($host) !== $host || $this->identity($request) !== $request
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $laterLeaseAcquiredAt) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,199}\z/', $evidenceRef) !== 1) {
            throw new RuntimeException('Exact wait reconciliation arguments are invalid.');
        }
        $laterLease = new DateTimeImmutable($laterLeaseAcquiredAt);

        return DB::transaction(function () use ($ticketId, $host, $request, $laterLease, $laterLeaseAcquiredAt, $evidenceRef, $apply): array {
            [$ticket, $journalKey] = $this->lockedOrphan($ticketId, $host, $request);
            $ticketUpdated = new DateTimeImmutable((string) $ticket->updated_at, new DateTimeZone((string) config('app.timezone')));
            if ($laterLease <= $ticketUpdated) {
                throw new RuntimeException('Independent host lease must postdate the uncertain ticket.');
            }
            $receipt = ['schema' => 'plos.model_host_controller.wait_operator_reconciliation.v1',
                'ticket_id' => $ticketId, 'host_id' => $host, 'request_id' => $request,
                'journal_task_id' => $journalKey, 'journal_absent' => true,
                'global_custody_phase' => 'idle', 'later_host_lease_acquired_at' => $laterLeaseAcquiredAt,
                'evidence_ref' => $evidenceRef, 'original_request_outcome' => 'unknown_not_replayed',
                'result' => $apply ? 'completed' : 'ready', 'applied' => $apply];
            if ($apply) {
                $updated = DB::table('distributed_tasks')->where('id', $ticketId)->where('task_id', $ticket->task_id)
                    ->where('status', 'held')->update([
                        'status' => 'completed',
                        'result' => json_encode(['phase' => 'finished', 'reason' => 'exact_operator_reconciliation',
                            'proof' => $receipt], JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Exact wait ticket compare-and-set failed.');
                }
            }

            return $receipt;
        });
    }

    /** Settle one exact unknown wait ticket after an independently evidenced later host boot. */
    public function reconcileOrphanAfterHostBoot(
        int $ticketId,
        string $host,
        string $request,
        string $hostBootAtUtc,
        string $evidenceRef,
        string $evidenceSha256,
        ?string $expectedDigest = null,
        bool $apply = false,
    ): array {
        if ($ticketId < 1 || $this->identity($host) !== $host || $this->identity($request) !== $request
            || preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\z/', $hostBootAtUtc) !== 1
            || preg_match('/\\A[a-zA-Z0-9][a-zA-Z0-9._\\/-]{0,199}\\z/', $evidenceRef) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/', $evidenceSha256) !== 1
            || ($expectedDigest !== null && preg_match('/\\A[a-f0-9]{64}\\z/', $expectedDigest) !== 1)
            || ($apply && $expectedDigest === null)) {
            throw new RuntimeException('Exact host-boot reconciliation arguments and apply preview digest are required.');
        }
        $utc = new DateTimeZone('UTC');
        $bootAt = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $hostBootAtUtc, $utc);
        $parseErrors = DateTimeImmutable::getLastErrors();
        if ($bootAt === false || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
            || $bootAt->format('Y-m-d\\TH:i:s\\Z') !== $hostBootAtUtc) {
            throw new RuntimeException('Host boot time must be a valid UTC calendar timestamp.');
        }

        return DB::transaction(function () use ($ticketId, $host, $request, $hostBootAtUtc, $bootAt, $utc,
            $evidenceRef, $evidenceSha256, $expectedDigest, $apply): array {
            [$ticket, $journalKey, $mutex, $slot] = $this->lockedOrphan($ticketId, $host, $request);
            $ticketUpdated = new DateTimeImmutable((string) $ticket->updated_at, new DateTimeZone((string) config('app.timezone')));
            if ($bootAt <= $ticketUpdated || $bootAt > new DateTimeImmutable('now', $utc)) {
                throw new RuntimeException('Independent host boot must postdate the uncertain ticket and not be in the future.');
            }
            $digest = ModelHostControllerResourcePolicyService::requestContractIdentity([
                'ticket' => (array) $ticket, 'host_mutex' => (array) $mutex, 'global_custody' => (array) $slot,
                'host_boot_at_utc' => $hostBootAtUtc, 'evidence_ref' => $evidenceRef, 'evidence_sha256' => $evidenceSha256,
            ]);
            if ($expectedDigest !== null && ! hash_equals($expectedDigest, $digest)) {
                throw new RuntimeException('Preview ticket, custody, or host-boot evidence changed; preview again before apply.');
            }
            $proof = ['schema' => 'plos.model_host_controller.wait_operator_reconciliation.v1',
                'ticket_id' => $ticketId, 'host_id' => $host, 'request_id' => $request,
                'journal_task_id' => $journalKey, 'journal_absent' => true, 'global_custody_phase' => 'idle',
                'host_boot_at_utc' => $hostBootAtUtc, 'evidence_ref' => $evidenceRef, 'evidence_sha256' => $evidenceSha256,
                'preview_digest' => $digest, 'original_request_outcome' => 'unknown_not_replayed',
                'original_ticket' => (array) $ticket, 'host_mutex_snapshot' => (array) $mutex,
                'global_custody_snapshot' => (array) $slot, 'work_end_evidence' => 'independent_host_boot_after_ticket',
                'host_mutations' => false, 'model_replayed' => false,
                'result' => $apply ? 'completed' : 'ready', 'applied' => $apply];
            if ($apply) {
                $updated = DB::table('distributed_tasks')->where('id', $ticketId)->where('task_id', $ticket->task_id)
                    ->where('status', 'held')->where('created_at', $ticket->created_at)->where('updated_at', $ticket->updated_at)
                    ->update(['status' => 'completed',
                        'result' => json_encode(['phase' => 'finished', 'reason' => 'exact_operator_host_boot_reconciliation',
                            'proof' => $proof], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('Exact wait ticket compare-and-set failed.');
                }
            }

            return $proof;
        });
    }

    /** Settle only the original FIFO ticket; never mutate host custody or replay inference. */
    public function reconcileOrphanFromReceipts(
        int $ticketId,
        string $host,
        string $request,
        string $target,
        string $leaseId,
        string $evidenceRef,
        ?string $expectedDigest = null,
        bool $apply = false,
    ): array {
        if ($ticketId < 1 || $this->identity($host) !== $host || $this->identity($request) !== $request
            || $this->identity($target) !== $target || $this->identity($leaseId) !== $leaseId
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]{0,199}\z/', $evidenceRef) !== 1
            || ($expectedDigest !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) !== 1)
            || ($apply && $expectedDigest === null)) {
            throw new RuntimeException('Exact receipt reconciliation arguments and apply preview digest are required.');
        }

        return DB::transaction(function () use ($ticketId, $host, $request, $target, $leaseId, $evidenceRef, $expectedDigest, $apply): array {
            [$ticket, $journalKey] = $this->lockedOrphan($ticketId, $host, $request);
            $controller = app(ModelHostControllerClient::class);
            $evidence = [];
            foreach (['activate', 'release'] as $operation) {
                $lookup = $controller->receipt($operation, $target, $request.'-'.$operation, $leaseId);
                $receipt = $lookup['receipt'] ?? [];
                if (($lookup['schema'] ?? null) !== 'plos.model_host_controller.receipt_lookup.v1'
                    || ($lookup['host_id'] ?? null) !== $host || ($lookup['target'] ?? null) !== $target
                    || ($lookup['request_id'] ?? null) !== $request.'-'.$operation || ($lookup['lease_id'] ?? null) !== $leaseId
                    || ($lookup['operation'] ?? null) !== $operation || ($lookup['record_found'] ?? null) !== true
                    || ($lookup['result'] ?? null) !== 'found' || ($lookup['mutation_available'] ?? null) !== false
                    || ($lookup['contains_prompt_or_response_content'] ?? null) !== false
                    || ($receipt['schema'] ?? null) !== 'plos.model_host_controller.'.($operation === 'activate' ? 'activation' : 'release').'.v1'
                    || ($receipt['host_id'] ?? null) !== $host || ($receipt['target'] ?? null) !== $target
                    || ($receipt['request_id'] ?? null) !== $request.'-'.$operation || ($receipt['lease_id'] ?? null) !== $leaseId
                    || ($receipt['execution_mode'] ?? null) !== 'serial' || ($receipt['fallback_enabled'] ?? null) !== false
                    || ($receipt['tools_enabled'] ?? null) !== false || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
                    || ($operation === 'activate' && (! in_array($receipt['result'] ?? null, ['activated', 'already_ready'], true)
                        || ($receipt['exact_identity'] ?? null) !== true || ($receipt['resource_gate_passed'] ?? null) !== true))
                    || ($operation === 'release' && (($receipt['result'] ?? null) !== 'released'
                        || ($receipt['cleanup_verified'] ?? null) !== true
                        || ! is_bool($receipt['stopped_controller_owned_runtime'] ?? null)
                        || ! is_bool($receipt['unloaded_controller_owned_model'] ?? null)))) {
                    throw new RuntimeException('Exact original activation and release receipts are unavailable or invalid.');
                }
                $evidence[$operation] = $lookup;
            }
            $status = $controller->targetStatus($target);
            $lifecycle = $status['lifecycle'] ?? [];
            $targets = $lifecycle['targets'] ?? [];
            $observed = is_array($targets) && count($targets) === 1 ? reset($targets) : [];
            if (($status['schema'] ?? null) !== 'plos.model_host_controller.target_status.v1'
                || ($status['host_id'] ?? null) !== $host || ($status['target'] ?? null) !== $target
                || ($status['target_descriptor']['target'] ?? null) !== $target
                || ($status['target_descriptor']['engine'] ?? null) !== 'ollama'
                || ! is_string($status['target_descriptor']['model'] ?? null) || trim($status['target_descriptor']['model']) === ''
                || ($status['k3_lease_active'] ?? null) !== false || ($status['mutation_available'] ?? null) !== false
                || ($status['contains_prompt_or_response_content'] ?? null) !== false || ($status['result'] ?? null) !== 'guarded_target'
                || ($status['execution_mode'] ?? null) !== 'serial' || ($status['concurrent_inference_enabled'] ?? null) !== false
                || ($lifecycle['schema'] ?? null) !== 'plos.model_host_controller.lifecycle_status.v1'
                || ($lifecycle['host_id'] ?? null) !== $host || ($lifecycle['lease_present'] ?? null) !== false
                || ($lifecycle['lease_expired'] ?? null) !== false || ! array_key_exists('lease_id_sha256', $lifecycle)
                || $lifecycle['lease_id_sha256'] !== null || ! array_key_exists('active_target', $lifecycle) || $lifecycle['active_target'] !== null
                || ! is_int($lifecycle['generation'] ?? null) || $lifecycle['generation'] < 0
                || ! is_string($lifecycle['state_generation_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $lifecycle['state_generation_sha256']) !== 1
                || ($lifecycle['execution_mode'] ?? null) !== 'serial' || ($lifecycle['compatible_residency_enabled'] ?? null) !== false
                || ($lifecycle['concurrent_inference_enabled'] ?? null) !== false || ($lifecycle['fallback_enabled'] ?? null) !== false
                || ($lifecycle['tools_enabled'] ?? null) !== false || ($lifecycle['contains_prompt_or_response_content'] ?? null) !== false
                || ($observed['target'] ?? null) !== $target || ($observed['task_exact'] ?? null) !== true
                || ($observed['control_exact'] ?? null) !== true || ($observed['executable_exact'] ?? null) !== true
                || ($observed['healthy'] ?? null) !== true || ($observed['model_exact'] ?? null) !== true
                || ($observed['running_exact'] ?? null) !== true || ($observed['owned_process_count'] ?? null) !== 1
                || ($observed['loopback_listener_count'] ?? null) !== 1 || ($observed['model_loaded_exact'] ?? null) !== false
                || ($observed['residency_verified'] ?? null) !== true || ($observed['foreign_loaded_count'] ?? null) !== 0) {
                throw new RuntimeException('Fresh exact unloaded target and clear host custody are not proven.');
            }
            $evidence['target_status'] = $status;
            $pinnedEvidence = $evidence;
            // Retain the complete observation below. Changing free RAM or a moving
            // switch window cannot change original cleanup, custody or residency.
            unset($pinnedEvidence['target_status']['lifecycle']['resources'], $pinnedEvidence['target_status']['lifecycle']['switches_last_hour']);
            $digest = ModelHostControllerResourcePolicyService::requestContractIdentity([
                'ticket' => (array) $ticket, 'evidence_ref' => $evidenceRef, 'evidence' => $pinnedEvidence,
            ]);
            if ($expectedDigest !== null && ! hash_equals($expectedDigest, $digest)) {
                throw new RuntimeException('Preview ticket or host evidence changed; preview again before apply.');
            }
            $proof = ['schema' => 'plos.model_host_controller.wait_operator_reconciliation.v1',
                'ticket_id' => $ticketId, 'host_id' => $host, 'request_id' => $request, 'target' => $target, 'lease_id' => $leaseId,
                'journal_task_id' => $journalKey, 'journal_absent' => true, 'global_custody_phase' => 'idle',
                'evidence_ref' => $evidenceRef, 'preview_digest' => $digest, 'original_request_outcome' => 'unknown_not_replayed',
                'original_ticket' => (array) $ticket, 'evidence' => $evidence,
                'work_end_evidence' => 'exact_original_release_and_fresh_unloaded_residency',
                'controller_mutations' => false, 'model_replayed' => false,
                'result' => $apply ? 'completed' : 'ready', 'applied' => $apply];
            if ($apply) {
                $updated = DB::table('distributed_tasks')->where('id', $ticketId)->where('task_id', $ticket->task_id)
                    ->where('status', 'held')->update(['status' => 'completed',
                        'result' => json_encode(['phase' => 'finished', 'reason' => 'exact_operator_receipt_reconciliation', 'proof' => $proof], JSON_THROW_ON_ERROR),
                        'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('Exact wait ticket compare-and-set failed.');
                }
            }

            return $proof;
        });
    }

    /** Caller holds a transaction; keep all recovery routes on the same exact locks and gates. */
    private function lockedOrphan(int $ticketId, string $host, string $request): array
    {
        $mutexKey = hash('sha256', self::MUTEX_TYPE.':'.$host);
        $mutex = DB::table('distributed_tasks')->where('task_id', $mutexKey)->lockForUpdate()->first();
        if ($mutex === null || $mutex->task_type !== self::MUTEX_TYPE || $mutex->status !== 'held') {
            throw new RuntimeException('Exact host wait mutex is unavailable.');
        }
        $ticket = DB::table('distributed_tasks')->where('id', $ticketId)->lockForUpdate()->first();
        if ($ticket === null || $ticket->task_type !== self::TASK_TYPE || $ticket->status !== 'held'
            || $ticket->task_id !== hash('sha256', self::TASK_TYPE.':'.$host.':'.$request)) {
            throw new RuntimeException('Exact held wait ticket changed.');
        }
        $payload = json_decode($ticket->payload, true, flags: JSON_THROW_ON_ERROR);
        $state = json_decode($ticket->result, true, flags: JSON_THROW_ON_ERROR);
        if (($payload['host_id'] ?? null) !== $host || ($payload['request_id'] ?? null) !== $request
            || ($state['phase'] ?? null) !== 'outcome_unknown') {
            throw new RuntimeException('Wait ticket identity or uncertain phase changed.');
        }
        $journalKey = hash('sha256', ModelHostControllerSelectionJournal::TASK_TYPE.':'.$request);
        if (DB::table('distributed_tasks')->where('task_id', $journalKey)->lockForUpdate()->exists()) {
            throw new RuntimeException('Exact request has a durable journal; use normal reconciliation.');
        }
        $slot = DB::table('distributed_tasks')->where('task_id', ModelHostControllerSelectionJournal::SLOT_ID)->lockForUpdate()->first();
        if ($slot === null || $slot->task_type !== ModelHostControllerSelectionJournal::TASK_TYPE
            || $slot->status !== 'held'
            || (json_decode($slot->result, true, flags: JSON_THROW_ON_ERROR)['phase'] ?? null) !== 'idle') {
            throw new RuntimeException('Global controller custody is not idle.');
        }

        return [$ticket, $journalKey, $mutex, $slot];
    }

    public function __construct(
        private readonly ?Closure $policyReader = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $sleeper = null,
    ) {}

    /** begin must return admission, or explicitly prove that no dispatch/custody remains. */
    public function await(callable $begin, array $context = []): mixed
    {
        $policy = $this->policy();
        if (! $policy['enabled']) {
            return $begin();
        }
        $host = $this->identity($context['host_id'] ?? null);
        $request = $this->identity($context['request_id'] ?? null);
        $deadline = min($this->time() + $policy['max_wait_seconds'], (float) ($context['deadline_at'] ?? INF));
        if (! is_finite($deadline)) {
            throw new RuntimeException('Model-host waiting deadline is invalid.');
        }
        $key = hash('sha256', self::TASK_TYPE.':'.$host.':'.$request);
        $this->enqueue($key, $host, $request, $deadline, $policy['max_queue_depth']);
        $attempting = false;
        $terminal = false;
        $lastWaitReason = null;
        $lastCapacityShortage = [];
        $lastCapacityCustodyCleared = false;
        try {
            while (true) {
                if (($context['cancelled'] ?? static fn (): bool => false)()) {
                    $this->state($key, 'cancelled');
                    $terminal = true;
                    throw new ModelHostControllerUnavailableException('cancelled');
                }
                if ($this->time() >= $deadline) {
                    $terminalRows = $this->state($key, 'expired', 'wait_deadline', $lastWaitReason, $lastCapacityShortage);
                    $terminal = true;
                    throw new ModelHostControllerUnavailableException('wait_deadline', false, $lastWaitReason, capacityShortage: $lastCapacityShortage,
                        capacityCustodyCleared: $terminalRows === 1 && $lastWaitReason === 'capacity' && $lastCapacityCustodyCleared);
                }
                if ($this->claimHead($key, $host)) {
                    $attempting = true;
                    try {
                        $scope = $begin();
                    } catch (ModelHostControllerUnavailableException $e) {
                        if ($e->dispatched) {
                            throw $e;
                        }
                        if (! in_array($e->reason, ['busy', 'capacity'], true)) {
                            // The exception contract proves custody is clear. Refuse without
                            // retrying, but do not leave unrelated work behind a held ticket.
                            $this->state($key, 'finished', $e->reason);
                            $terminal = true;
                            throw $e;
                        }
                        $lastCapacityShortage = $e->reason === 'capacity' ? $e->capacityShortage : [];
                        $lastCapacityCustodyCleared = $e->reason === 'capacity' && $e->capacityCustodyCleared;
                        $this->state($key, 'waiting', $e->reason, capacityShortage: $lastCapacityShortage);
                        $lastWaitReason = $e->reason;
                        $attempting = false;
                        $scope = null;
                    }
                    if ($attempting) {
                        try {
                            $this->state($key, 'admitted');
                        } catch (Throwable $e) {
                            if (isset($context['admission_failed'])) {
                                ($context['admission_failed'])($scope);
                            }
                            throw $e;
                        }

                        return $scope;
                    }
                }
                if (isset($context['on_wait'])) {
                    ($context['on_wait'])(['state' => 'waiting', 'request_id' => $request]);
                }
                $milliseconds = (int) min($policy['poll_interval_ms'], max(0, ($deadline - $this->time()) * 1000));
                if ($milliseconds > 0) {
                    $this->sleep($milliseconds);
                }
            }
        } catch (Throwable $e) {
            // An interrupted admission is never retried or cleared by age.
            if (! $terminal) {
                $this->state($key, $attempting ? 'outcome_unknown' : 'finished');
            }
            throw $e;
        }
    }

    private function enqueue(string $key, string $host, string $request, float $deadline, int $limit): void
    {
        DB::transaction(function () use ($key, $host, $request, $deadline, $limit): void {
            $this->lockHost($host);
            if (DB::table('distributed_tasks')->where('task_id', $key)->exists()) {
                throw new RuntimeException('Model-host waiting request identity was already used.');
            }
            if (count($this->active($host)) >= $limit) {
                throw new ModelHostControllerUnavailableException('queue_full');
            }
            DB::table('distributed_tasks')->insert([
                ...ModelHostControllerSelectionJournal::baseRow($key),
                'task_type' => self::TASK_TYPE,
                'payload' => json_encode(['host_id' => $host, 'request_id' => $request, 'deadline_at' => $deadline], JSON_THROW_ON_ERROR),
                'result' => json_encode(['phase' => 'waiting'], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    private function claimHead(string $key, string $host): bool
    {
        return DB::transaction(function () use ($key, $host): bool {
            $this->lockHost($host);
            $rows = $this->active($host);
            if ($rows === [] || $rows[0]->task_id !== $key) {
                return false;
            }
            $phase = json_decode($rows[0]->result, true, flags: JSON_THROW_ON_ERROR)['phase'];
            if ($phase !== 'waiting') {
                return false;
            }
            $this->state($key, 'attempting');

            return true;
        });
    }

    /** Expire only requests proven never inside an admission attempt. */
    private function active(string $host): array
    {
        $active = [];
        foreach (DB::table('distributed_tasks')->where('task_type', self::TASK_TYPE)->where('status', 'held')->orderBy('id')->lockForUpdate()->get() as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $state = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['host_id'] ?? null) !== $host || ! in_array($state['phase'] ?? null, ['waiting', 'attempting', 'outcome_unknown'], true)) {
                continue;
            }
            if ($state['phase'] === 'outcome_unknown' && $this->settleReconciledUndispatched($row, $payload)) {
                continue;
            }
            if ($state['phase'] === 'waiting' && ($payload['deadline_at'] ?? 0) <= $this->time()) {
                $this->state($row->task_id, 'expired');
            } else {
                $active[] = $row;
            }
        }

        return $active;
    }

    /** Called under the host mutex; durable reconciliation, never age or absence, ends this wait. */
    private function settleReconciledUndispatched(object $ticket, array $payload): bool
    {
        $host = $payload['host_id'] ?? null;
        $request = $payload['request_id'] ?? null;
        if (! is_string($host) || ! is_string($request)
            || $ticket->task_id !== hash('sha256', self::TASK_TYPE.':'.$host.':'.$request)) {
            return false;
        }
        $journalKey = hash('sha256', ModelHostControllerSelectionJournal::TASK_TYPE.':'.$request);
        $journal = DB::table('distributed_tasks')->where('task_id', $journalKey)->lockForUpdate()->first();
        if ($journal === null || $journal->task_type !== ModelHostControllerSelectionJournal::TASK_TYPE || $journal->status !== 'held') {
            return false;
        }
        $identity = json_decode($journal->payload, true, flags: JSON_THROW_ON_ERROR);
        $state = json_decode($journal->result, true, flags: JSON_THROW_ON_ERROR);
        $proof = $state['reconciliation'] ?? [];
        if (($identity['schema'] ?? null) !== ModelHostControllerSelectionJournal::SCHEMA
            || ($state['schema'] ?? null) !== ModelHostControllerSelectionJournal::SCHEMA
            || ($identity['request_id'] ?? null) !== $request
            || ($identity['controller']['host_id'] ?? null) !== $host
            || ! is_string($identity['lease_id'] ?? null) || $identity['lease_id'] === ''
            || ($state['phase'] ?? null) !== 'reconciled'
            || ($state['dispatched'] ?? null) !== false
            || ($state['execution_completed'] ?? false) !== false
            || ($state['heartbeat']['phase'] ?? null) === 'pending'
            || ($state['inference_outcome'] ?? null) !== 'not_dispatched'
            || ($proof['request_id'] ?? null) !== $request
            || ($proof['lease_id'] ?? null) !== $identity['lease_id']
            || ($proof['host_custody_clear'] ?? null) !== true
            || ($proof['owned_work_ended'] ?? null) !== true
            || ! is_string($proof['custody_digest'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $proof['custody_digest']) !== 1) {
            return false;
        }
        DB::table('distributed_tasks')->where('id', $ticket->id)->update([
            'status' => 'completed',
            'result' => json_encode(['phase' => 'finished', 'reason' => 'reconciled_not_dispatched',
                'journal_task_id' => $journalKey,
                'journal_digest' => ModelHostControllerResourcePolicyService::requestContractIdentity([
                    'payload' => $identity, 'state' => $state,
                ])], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function state(string $key, string $phase, ?string $reason = null, ?string $lastWaitReason = null, array $capacityShortage = []): int
    {
        $result = ['phase' => $phase, 'reason' => $reason];
        if (in_array($lastWaitReason, ['busy', 'capacity'], true)) {
            $result['last_wait_reason'] = $lastWaitReason;
        }
        $diagnostic = InferenceAdmissionException::sanitizeControllerFailure([
            'wait_reason' => $reason, 'last_wait_reason' => $lastWaitReason, 'capacity_shortage' => $capacityShortage,
        ]);
        if (isset($diagnostic['capacity_shortage'])) {
            $result['capacity_shortage'] = $diagnostic['capacity_shortage'];
        }
        // A fenced terminal ticket cannot be overwritten by its resumed original caller.
        $updated = DB::table('distributed_tasks')->where('task_id', $key)->where('task_type', self::TASK_TYPE)
            ->where(fn ($query) => $query->where('status', '!=', 'completed')->orWhereNull('status')
                ->orWhere('result->phase', '!=', 'finished')->orWhereNull('result->phase')
                ->orWhere('result->reason', '!=', 'reserved_application_fence')->orWhereNull('result->reason')
                ->orWhere('result->proof->schema', '!=', 'plos.model_host_controller.reserved_application_fence.v1')->orWhereNull('result->proof->schema')
                ->orWhere('result->proof->applied', '!=', true)->orWhereNull('result->proof->applied'))->update([
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'status' => match ($phase) {
                        'admitted', 'finished' => 'completed',
                        'cancelled', 'expired' => 'cancelled',
                        default => 'held',
                    },
                    // Retain identity without presenting a completed inference result to ordinary retention.
                    'updated_at' => now(),
                ]);
        if ($updated === 0 && $phase === 'admitted') {
            throw new RuntimeException('Wait identity was fenced before admission could be recorded.');
        }

        return $updated;
    }

    /** A unique host row serializes queue depth and head claims, including an empty queue. */
    private function lockHost(string $host): void
    {
        $key = hash('sha256', self::MUTEX_TYPE.':'.$host);
        DB::table('distributed_tasks')->insertOrIgnore([
            ...ModelHostControllerSelectionJournal::baseRow($key),
            'task_type' => self::MUTEX_TYPE,
            'payload' => json_encode(['host_id' => $host], JSON_THROW_ON_ERROR),
        ]);
        $row = DB::table('distributed_tasks')->where('task_id', $key)->lockForUpdate()->first();
        if ($row === null || $row->task_type !== self::MUTEX_TYPE || $row->status !== 'held') {
            throw new RuntimeException('Model-host queue mutex is invalid.');
        }
    }

    private function policy(): array
    {
        $raw = $this->policyReader ? ($this->policyReader)() : DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_wait_policy')->value('config_value');
        $policy = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : $raw;
        if ($policy === null || ($policy['enabled'] ?? null) === false) {
            return ['enabled' => false];
        }
        if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.model_host_controller.wait_policy.v1' || ($policy['enabled'] ?? null) !== true) {
            throw new RuntimeException('Model-host wait policy is invalid.');
        }
        foreach (['poll_interval_ms' => [100, 10000], 'max_wait_seconds' => [1, 900], 'max_queue_depth' => [1, 128]] as $name => [$min, $max]) {
            if (! is_int($policy[$name] ?? null) || $policy[$name] < $min || $policy[$name] > $max) {
                throw new RuntimeException('Model-host wait policy bounds are invalid.');
            }
        }

        return $policy;
    }

    private function identity(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-zA-Z0-9.][a-zA-Z0-9._:-]{0,127}\z/', $value) !== 1) {
            throw new RuntimeException('Model-host waiting identity is invalid.');
        }

        return $value;
    }

    private function time(): float
    {
        return $this->clock ? ($this->clock)() : microtime(true);
    }

    private function sleep(int $milliseconds): void
    {
        if ($this->sleeper) {
            ($this->sleeper)($milliseconds);
        } else {
            usleep($milliseconds * 1000);
        }
    }
}
