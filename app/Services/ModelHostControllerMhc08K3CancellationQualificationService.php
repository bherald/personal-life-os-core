<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Candidate-only, fixed-input proof of cross-process K3 stream cancellation and recovery. */
class ModelHostControllerMhc08K3CancellationQualificationService
{
    public const RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_cancellation_qualification.v1';

    public const WORKER_SCHEMA = 'plos.model_host_controller.mhc08_k3_cancellation_qualification_worker.v1';

    private const REQUESTER_TYPE = 'mhc08_qualification';

    private const RECOVERY_PROMPT = 'Reply with exactly MHC08-K3-RECOVERY-OK.';

    private const RECOVERY_OUTPUT = 'MHC08-K3-RECOVERY-OK';

    private const TTL_SECONDS = 26100;

    private const ACTIVE_GENERATIONS_KEY = 'llm:openai_compatible:active_generations';

    public function __construct(
        private readonly ModelHostControllerMhc08K3PolicyService $policies,
        private readonly ModelHostControllerMhc08K3LifecycleService $lifecycle,
        private readonly ModelHostControllerMhc08K3InferenceService $inference,
        private readonly ModelHostControllerMhc08K3AdmissionService $admission,
        private readonly ChatStreamCancellationService $cancellations,
        private readonly TaskCustodyService $custody,
        private readonly ModelHostControllerMhc08K3JobService $jobs,
        private readonly ModelHostControllerMhc08K3StateContractService $state,
    ) {}

    /** @return array<string,string> */
    public function prepare(): array
    {
        $this->assertInactivePolicy($this->policies->required());
        $stateIdentity = $this->stateIdentity($this->state->finalized());
        $identities = [
            'task_id' => (string) Str::uuid(),
            'requester_id' => (string) Str::uuid(),
            'cancel_request_id' => (string) Str::uuid(),
            'cancel_lease_id' => (string) Str::uuid(),
            'recovery_request_id' => (string) Str::uuid(),
            'recovery_lease_id' => (string) Str::uuid(),
            'recovery_cancellation_id' => (string) Str::uuid(),
        ];
        if (count(array_unique($identities)) !== count($identities)) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification identities are not unique.');
        }

        $now = now();
        DB::table('distributed_tasks')->insert([
            'task_id' => $identities['task_id'],
            'task_type' => ModelHostControllerMhc08K3JobService::TASK_TYPE,
            'payload' => json_encode([
                'schema' => ModelHostControllerMhc08K3JobService::PAYLOAD_SCHEMA,
                'role' => 'ultra_deep',
                'prompt' => 'MHC-08 fixed candidate cancellation qualification.',
                'system_prompt' => null,
                'max_tokens' => 128,
                'requester_type' => self::REQUESTER_TYPE,
                'requester_id' => $identities['requester_id'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'required_capabilities' => '[]',
            'assigned_agent_id' => null,
            'status' => DistributedAgentService::TASK_PENDING,
            'priority' => 0,
            'retry_count' => 0,
            'max_retries' => 0,
            'result' => null,
            'error_message' => null,
            'assigned_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'timeout_at' => $now->copy()->addSeconds(25200),
        ]);
        try {
            $stored = Cache::put($this->coordinationKey($identities['task_id']), [
                ...$identities,
                'state_identity' => $stateIdentity,
                'phase' => 'prepared',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ], self::TTL_SECONDS);
            if ($stored !== true) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification coordination was not stored.');
            }
        } catch (Throwable $exception) {
            DB::table('distributed_tasks')
                ->where('task_id', $identities['task_id'])
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->where('status', DistributedAgentService::TASK_PENDING)
                ->delete();
            throw $exception;
        }

        return $identities;
    }

    public function workerReady(string $taskId, string $requesterId): bool
    {
        $state = $this->coordination($taskId, $requesterId);
        $row = DB::table('distributed_tasks')
            ->where('task_id', $taskId)
            ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
            ->first(['status', 'started_at']);

        if (($state['phase'] ?? null) !== 'awaiting_stream'
            || ! is_int($state['stream_not_before_epoch'] ?? null)
            || ($row->status ?? null) !== DistributedAgentService::TASK_RUNNING
            || ($row->started_at ?? null) === null) {
            return false;
        }
        $active = Cache::get(self::ACTIVE_GENERATIONS_KEY, []);
        foreach (is_array($active) ? $active : [] as $generation) {
            if (is_array($generation)
                && ($generation['provider'] ?? null) === 'kimi_k3_87'
                && ($generation['model'] ?? null) === 'kimi-k3-colibri'
                && in_array($generation['state'] ?? null, ['waiting_for_first_chunk', 'receiving'], true)
                && ($generation['cancellation_run_sha256'] ?? null) === hash('sha256', $taskId)
                && (int) ($generation['started_at_epoch'] ?? 0) >= $state['stream_not_before_epoch']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function signalCancellation(string $taskId, string $requesterId): array
    {
        if (! $this->workerReady($taskId, $requesterId)) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification worker is not ready.');
        }
        $receipt = $this->jobs->cancel($taskId, self::REQUESTER_TYPE, $requesterId);
        if (($receipt['state'] ?? null) !== DistributedAgentService::TASK_CANCELLED
            || ($receipt['content_free'] ?? false) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 owned cancellation signal was not exact.');
        }
        $state = $this->coordination($taskId, $requesterId);
        $this->setPhase($state, 'cancellation_signalled');

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function work(string $taskId, string $requesterId): array
    {
        $state = $this->coordination($taskId, $requesterId);
        $custody = null;
        $cancelReceipt = null;
        $recoveryReceipt = null;
        $admissionCleanup = false;
        $controllerCleanup = false;
        $recoveryPassed = false;

        try {
            $task = $this->claim($taskId, $requesterId);
            $ownerToken = $this->custody->scheduledJobOwnerToken(getmypid(), (int) $task->id);
            $custody = $this->custody->acquire(
                TaskCustodyService::SURFACE_MHC08_K3_JOB,
                $taskId,
                $ownerToken,
                25200,
            );
            if ($custody === null) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification custody was not acquired.');
            }

            $this->cancellations->registerOwned($taskId, self::REQUESTER_TYPE, $requesterId);
            $ready = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('task_id', $taskId)
                ->where('status', DistributedAgentService::TASK_RUNNING)
                ->whereNull('started_at')
                ->update(['started_at' => now(), 'updated_at' => now()]);
            if ($ready !== 1) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification readiness lost task custody.');
            }
            $state['stream_not_before_epoch'] = time();
            $state['phase'] = 'awaiting_stream';
            $this->setPhase($state, 'awaiting_stream');

            $cancellationExecution = null;
            $cancelReceipt = $this->lifecycle->runCandidateCanary(
                'ultra_deep',
                $state['cancel_request_id'],
                $state['cancel_lease_id'],
                function (array $target) use ($taskId, $state, &$cancellationExecution): array {
                    $cancellationExecution = $this->inference->executeCandidateCancellation(
                        $taskId,
                        $state['cancel_request_id'],
                        $target,
                    );

                    return $cancellationExecution['execution_receipt'];
                },
            );
            $this->assertCancellation($cancellationExecution, $cancelReceipt, $state);
            $admissionCleanup = $this->admissionClean($this->admission->recoverInterrupted($state['cancel_request_id']));
            $controllerCleanup = $this->controllerClean($cancelReceipt, $state['state_identity']);
            $this->cancellations->clear($taskId);

            $this->setPhase($state, 'recovery_inference');
            $this->cancellations->registerOwned(
                $state['recovery_cancellation_id'],
                self::REQUESTER_TYPE,
                $requesterId,
            );
            $recoveryExecution = null;
            $recoveryReceipt = $this->lifecycle->runCandidateCanary(
                'ultra_deep',
                $state['recovery_request_id'],
                $state['recovery_lease_id'],
                function (array $target) use ($state, &$recoveryExecution): array {
                    $recoveryExecution = $this->inference->executeCandidateCanary(
                        self::RECOVERY_PROMPT,
                        [
                            'max_tokens' => 128,
                            'cancellation_run_id' => $state['recovery_cancellation_id'],
                        ],
                        $state['recovery_request_id'],
                        $target,
                    );

                    return $recoveryExecution['execution_receipt'];
                },
            );
            $this->assertRecovery($recoveryExecution, $recoveryReceipt, $state);
            $admissionCleanup = $admissionCleanup
                && $this->admissionClean($this->admission->recoverInterrupted($state['recovery_request_id']));
            $controllerCleanup = $controllerCleanup
                && $this->controllerClean($recoveryReceipt, $state['state_identity']);
            $recoveryPassed = true;
            $this->cancellations->clear($state['recovery_cancellation_id']);

            $receipt = $this->workerReceipt(
                $taskId,
                'green',
                $controllerCleanup,
                $admissionCleanup,
                false,
                $recoveryPassed,
                hash('sha256', self::RECOVERY_OUTPUT),
            );
            if (! $this->custody->release((int) $custody->id, 'cancel', $receipt)) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification custody release was not proven.');
            }
            $custody = null;

            return [...$receipt, 'custody_released' => true];
        } catch (Throwable) {
            $cleanup = $this->recoverKnownRequests($state);
            if ($custody !== null) {
                $released = $this->custody->release(
                    (int) $custody->id,
                    'failure',
                    $this->workerReceipt($taskId, 'rejected', false, false, false, false, null),
                );
                $custody = $released ? null : $custody;
            }

            return $this->workerReceipt(
                $taskId,
                'rejected',
                $cleanup,
                $cleanup,
                $custody === null,
                false,
                null,
            );
        } finally {
            $this->cancellations->clear($taskId);
            if (isset($state['recovery_cancellation_id'])) {
                $this->cancellations->clear($state['recovery_cancellation_id']);
            }
        }
    }

    /** @param array<string,string> $prepared @param array<string,mixed> $workerReceipt @return array<string,mixed> */
    public function finalize(array $prepared, array $workerReceipt): array
    {
        $taskId = (string) ($prepared['task_id'] ?? '');
        $requesterId = (string) ($prepared['requester_id'] ?? '');
        $coordination = $this->coordination($taskId, $requesterId);
        $stateIdentity = $this->stateIdentity($this->state->finalized());
        if (($coordination['state_identity'] ?? null) !== $stateIdentity) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification State identity drifted.');
        }
        $this->assertWorkerReceipt($workerReceipt, $taskId);
        if ($this->custody->findUnreleased(TaskCustodyService::SURFACE_MHC08_K3_JOB, $taskId) !== null) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification custody remains unreleased.');
        }
        $task = DB::transaction(function () use ($taskId, $requesterId): object {
            $task = DB::table('distributed_tasks')
                ->where('task_id', $taskId)
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->where('status', DistributedAgentService::TASK_CANCELLED)
                ->lockForUpdate()
                ->first(['id', 'payload', 'result']);
            $payload = is_string($task->payload ?? null) ? json_decode($task->payload, true) : null;
            $terminal = is_string($task->result ?? null) ? json_decode($task->result, true) : null;
            if (! is_array($payload)
                || ($payload['requester_type'] ?? null) !== self::REQUESTER_TYPE
                || ($payload['requester_id'] ?? null) !== $requesterId
                || ! is_array($terminal)
                || ($terminal['schema'] ?? null) !== ModelHostControllerMhc08K3JobService::TERMINAL_SCHEMA
                || ($terminal['state'] ?? null) !== DistributedAgentService::TASK_CANCELLED
                || array_key_exists('output', $terminal)) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification terminal ownership is inexact.');
            }
            $terminal['cleanup_result'] = 'proven';
            $terminal['controller_receipt'] = [
                'schema' => 'plos.model_host_controller.mhc08_k3_cancellation_cleanup.v1',
                'task_id' => $taskId,
                'controller_cleanup_proven' => true,
                'admission_cleanup_proven' => true,
                'custody_released' => true,
                'recovery_inference_passed' => true,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ];
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('status', DistributedAgentService::TASK_CANCELLED)
                ->update([
                    'result' => json_encode($terminal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification terminal cleanup lost custody.');
            }

            return $task;
        });
        $deleted = DB::table('distributed_tasks')
            ->where('id', (int) $task->id)
            ->where('task_id', $taskId)
            ->where('status', DistributedAgentService::TASK_CANCELLED)
            ->delete();
        if ($deleted !== 1) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification task cleanup failed.');
        }
        Cache::forget($this->coordinationKey($taskId));

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'task_id' => $taskId,
            'result' => 'green',
            'controls_passed' => true,
            'cross_process_owned_cancellation' => true,
            'stream_cancelled' => true,
            'partial_output_preserved' => false,
            'controller_cleanup_proven' => true,
            'admission_cleanup_proven' => true,
            'custody_released' => true,
            'terminal_cleanup_proven' => true,
            'recovery_inference_passed' => true,
            'recovery_output_sha256' => $workerReceipt['recovery_output_sha256'],
            'temporary_task_removed' => true,
            'policy_persisted' => false,
            'worker_enabled' => false,
            'normal_routing_changed' => false,
            'fallback_used' => false,
            'tools_used' => false,
            ...$stateIdentity,
            'completed_at' => now()->toIso8601String(),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,string> $prepared */
    public function cleanup(array $prepared): bool
    {
        $taskId = (string) ($prepared['task_id'] ?? '');
        $requesterId = (string) ($prepared['requester_id'] ?? '');
        if (! Str::isUuid($taskId) || ! Str::isUuid($requesterId)) {
            return false;
        }
        try {
            $task = DB::table('distributed_tasks')
                ->where('task_id', $taskId)
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->first(['status']);
            if (in_array($task->status ?? null, [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING], true)) {
                $this->jobs->cancel($taskId, self::REQUESTER_TYPE, $requesterId);
            }
        } catch (Throwable) {
            // Exact recovery below remains mandatory.
        }
        $state = Cache::get($this->coordinationKey($taskId), $prepared);
        $clean = is_array($state) && $this->recoverKnownRequests($state);
        $custody = $this->custody->findUnreleased(TaskCustodyService::SURFACE_MHC08_K3_JOB, $taskId);
        if ($custody !== null) {
            if ($this->custody->hasLiveOwner($custody)) {
                return false;
            }
            $clean = $this->custody->release(
                (int) $custody->id,
                'failure',
                $this->workerReceipt($taskId, 'rejected', $clean, $clean, false, false, null),
            ) && $clean;
        }
        if (! $clean) {
            return false;
        }
        $task = DB::table('distributed_tasks')
            ->where('task_id', $taskId)
            ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
            ->first(['id', 'status']);
        if ($task !== null && in_array($task->status, [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING], true)) {
            $terminal = [
                'schema' => ModelHostControllerMhc08K3JobService::TERMINAL_SCHEMA,
                'task_id' => $taskId,
                'state' => DistributedAgentService::TASK_FAILED,
                'reason' => 'qualification_aborted',
                'cleanup_result' => 'proven',
                'controller_receipt' => null,
                'completed_at' => now()->toIso8601String(),
            ];
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('status', $task->status)
                ->update([
                    'status' => DistributedAgentService::TASK_FAILED,
                    'result' => json_encode($terminal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'error_message' => 'qualification_aborted',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return false;
            }
        }
        $deleted = DB::table('distributed_tasks')
            ->where('task_id', $taskId)
            ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
            ->whereIn('status', [DistributedAgentService::TASK_CANCELLED, DistributedAgentService::TASK_FAILED])
            ->delete();
        if ($task !== null && $deleted !== 1) {
            return false;
        }
        Cache::forget($this->coordinationKey($taskId));

        return true;
    }

    private function claim(string $taskId, string $requesterId): object
    {
        return DB::transaction(function () use ($taskId, $requesterId): object {
            $task = DB::table('distributed_tasks')
                ->where('task_id', $taskId)
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->lockForUpdate()
                ->first();
            $payload = is_string($task->payload ?? null) ? json_decode($task->payload, true) : null;
            if ($task === null
                || ($task->status ?? null) !== DistributedAgentService::TASK_PENDING
                || ! is_array($payload)
                || ($payload['schema'] ?? null) !== ModelHostControllerMhc08K3JobService::PAYLOAD_SCHEMA
                || ($payload['role'] ?? null) !== 'ultra_deep'
                || ! array_key_exists('system_prompt', $payload)
                || $payload['system_prompt'] !== null
                || ($payload['max_tokens'] ?? null) !== 128
                || ($payload['requester_type'] ?? null) !== self::REQUESTER_TYPE
                || ($payload['requester_id'] ?? null) !== $requesterId
                || array_keys($payload) !== ['schema', 'role', 'prompt', 'system_prompt', 'max_tokens', 'requester_type', 'requester_id']) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification task is inexact.');
            }
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('status', DistributedAgentService::TASK_PENDING)
                ->update([
                    'status' => DistributedAgentService::TASK_RUNNING,
                    'assigned_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification claim lost task custody.');
            }

            return $task;
        });
    }

    /** @param array<string,mixed> $state */
    private function setPhase(array $state, string $phase): void
    {
        Cache::put($this->coordinationKey((string) $state['task_id']), [
            ...$state,
            'phase' => $phase,
        ], self::TTL_SECONDS);
    }

    /** @return array<string,mixed> */
    private function coordination(string $taskId, string $requesterId): array
    {
        if (! Str::isUuid($taskId) || ! Str::isUuid($requesterId)) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification identity is invalid.');
        }
        $state = Cache::get($this->coordinationKey($taskId));
        if (! is_array($state)
            || ($state['task_id'] ?? null) !== $taskId
            || ($state['requester_id'] ?? null) !== $requesterId
            || ($state['content_free'] ?? false) !== true
            || ($state['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 cross-process qualification coordination is unavailable.');
        }

        return $state;
    }

    /** @param array<string,mixed>|null $execution @param array<string,mixed> $receipt @param array<string,mixed> $state */
    private function assertCancellation(?array $execution, array $receipt, array $state): void
    {
        $executionReceipt = is_array($execution['execution_receipt'] ?? null) ? $execution['execution_receipt'] : [];
        if (($executionReceipt['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_candidate_cancellation_execution.v1'
            || ($executionReceipt['result'] ?? null) !== 'cancelled'
            || ($executionReceipt['stream_cancelled'] ?? false) !== true
            || ($executionReceipt['partial_output_preserved'] ?? true) !== false
            || ($executionReceipt['fallback_suppressed'] ?? false) !== true
            || ($executionReceipt['content_free'] ?? false) !== true
            || ($executionReceipt['contains_prompt_or_response_content'] ?? true) !== false
            || ($receipt['request_id'] ?? null) !== $state['cancel_request_id']
            || ($receipt['lease_id'] ?? null) !== $state['cancel_lease_id']
            || ! $this->controllerClean($receipt, $state['state_identity'] ?? [])) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification did not prove exact cancellation and cleanup.');
        }
    }

    /** @param array<string,mixed>|null $execution @param array<string,mixed> $receipt @param array<string,mixed> $state */
    private function assertRecovery(?array $execution, array $receipt, array $state): void
    {
        $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
        $telemetry = is_array($response['transport_telemetry'] ?? null) ? $response['transport_telemetry'] : [];
        if (($response['success'] ?? false) !== true
            || ($response['response'] ?? null) !== self::RECOVERY_OUTPUT
            || ($response['provider'] ?? null) !== 'kimi_k3_87'
            || ($response['model'] ?? null) !== 'kimi-k3-colibri'
            || ($response['fallback_suppressed'] ?? false) !== true
            || ($telemetry['finish_reason'] ?? null) !== 'stop'
            || ! is_int($telemetry['completion_tokens'] ?? null)
            || (int) $telemetry['completion_tokens'] < 1
            || (int) $telemetry['completion_tokens'] > 128
            || ($receipt['request_id'] ?? null) !== $state['recovery_request_id']
            || ($receipt['lease_id'] ?? null) !== $state['recovery_lease_id']
            || ! $this->controllerClean($receipt, $state['state_identity'] ?? [])) {
            throw new RuntimeException('MHC-08 K3 cancellation recovery inference was not exact.');
        }
    }

    /** @param array<string,mixed> $receipt */
    private function controllerClean(array $receipt, array $stateIdentity): bool
    {
        return ($receipt['schema'] ?? null) === 'plos.model_host_controller.mhc08_k3_receipt.v1'
            && ($receipt['revision'] ?? null) === ModelHostControllerMhc08K3LifecycleService::CANDIDATE_REVISION
            && ($receipt['result'] ?? null) === 'green'
            && in_array($receipt['release_result'] ?? null, ['released', 'released_status_verified', 'recovered_status_verified'], true)
            && ($receipt['normal_routing'] ?? true) === false
            && ($receipt['tools_enabled'] ?? true) === false
            && ($receipt['fallback_enabled'] ?? true) === false
            && ($receipt['runtime_profile'] ?? null) === ($stateIdentity['runtime_profile'] ?? null)
            && ($receipt['runtime_revision'] ?? null) === ($stateIdentity['runtime_revision'] ?? null)
            && ($receipt['engine_sha256'] ?? null) === ($stateIdentity['state_engine_sha256'] ?? null)
            && ($receipt['patch_sha256'] ?? null) === ($stateIdentity['state_patch_sha256'] ?? null)
            && ($receipt['patched_tree'] ?? null) === ($stateIdentity['state_patched_tree'] ?? null)
            && ($receipt['content_free'] ?? false) === true
            && ($receipt['contains_prompt_or_response_content'] ?? true) === false;
    }

    /** @param array<string,mixed> $contract @return array<string,string> */
    private function stateIdentity(array $contract): array
    {
        $identity = [
            'runtime_profile' => $contract['runtime_profile'] ?? null,
            'runtime_revision' => $contract['runtime_revision'] ?? null,
            'state_engine_sha256' => $contract['engine_sha256'] ?? null,
            'state_patch_sha256' => $contract['patch_sha256'] ?? null,
            'state_patched_tree' => $contract['patched_tree'] ?? null,
            'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'] ?? null,
        ];
        if ($identity['runtime_profile'] !== 'State'
            || $identity['runtime_revision'] !== ModelHostControllerMhc08K3StateContractService::STATE_RUNTIME
            || $identity['state_patch_sha256'] !== ModelHostControllerMhc08K3StateContractService::STATE_PATCH
            || $identity['state_patched_tree'] !== ModelHostControllerMhc08K3StateContractService::STATE_TREE) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification requires finalized State identity.');
        }
        foreach (['state_engine_sha256', 'state_build_receipt_sha256'] as $field) {
            if (! is_string($identity[$field]) || preg_match('/\A[a-f0-9]{64}\z/', $identity[$field]) !== 1) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification State hash is invalid.');
            }
        }

        /** @var array<string,string> $identity */
        return $identity;
    }

    /** @param array<string,mixed> $receipt */
    private function admissionClean(array $receipt): bool
    {
        return ($receipt['schema'] ?? null) === 'plos.model_host_controller.mhc08_k3_admission_recovery.v1'
            && in_array($receipt['result'] ?? null, ['restored', 'not_required'], true)
            && ($receipt['provider_closed'] ?? false) === true
            && ($receipt['profile_gated'] ?? false) === true
            && ($receipt['guard_revoked'] ?? false) === true
            && ($receipt['journal_removed'] ?? false) === true
            && ($receipt['content_free'] ?? false) === true
            && ($receipt['contains_prompt_or_response_content'] ?? true) === false;
    }

    /** @param array<string,mixed> $state */
    private function recoverKnownRequests(array $state): bool
    {
        $clean = true;
        foreach ([
            ['request' => $state['cancel_request_id'] ?? null, 'lease' => $state['cancel_lease_id'] ?? null],
            ['request' => $state['recovery_request_id'] ?? null, 'lease' => $state['recovery_lease_id'] ?? null],
        ] as $identity) {
            if (! is_string($identity['request']) || ! is_string($identity['lease'])) {
                $clean = false;

                continue;
            }
            try {
                $clean = $this->admissionClean($this->admission->recoverInterrupted($identity['request'])) && $clean;
                $recovery = $this->lifecycle->recoverInterruptedCandidateCanary(
                    'ultra_deep',
                    $identity['request'],
                    $identity['lease'],
                );
                $clean = in_array($recovery['result'] ?? null, ['restored', 'not_required'], true)
                    && ($recovery['content_free'] ?? false) === true
                    && ($recovery['contains_prompt_or_response_content'] ?? true) === false
                    && $clean;
            } catch (Throwable) {
                $clean = false;
            }
        }

        return $clean;
    }

    /** @param array<string,mixed> $workerReceipt */
    private function assertWorkerReceipt(array $workerReceipt, string $taskId): void
    {
        if (($workerReceipt['schema'] ?? null) !== self::WORKER_SCHEMA
            || ($workerReceipt['task_id'] ?? null) !== $taskId
            || ($workerReceipt['result'] ?? null) !== 'green'
            || ($workerReceipt['stream_cancelled'] ?? false) !== true
            || ($workerReceipt['partial_output_preserved'] ?? true) !== false
            || ($workerReceipt['controller_cleanup_proven'] ?? false) !== true
            || ($workerReceipt['admission_cleanup_proven'] ?? false) !== true
            || ($workerReceipt['custody_released'] ?? false) !== true
            || ($workerReceipt['recovery_inference_passed'] ?? false) !== true
            || ($workerReceipt['recovery_output_sha256'] ?? null) !== hash('sha256', self::RECOVERY_OUTPUT)
            || ($workerReceipt['content_free'] ?? false) !== true
            || ($workerReceipt['contains_prompt_or_response_content'] ?? true) !== false
            || array_key_exists('prompt', $workerReceipt)
            || array_key_exists('output', $workerReceipt)) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification worker receipt is inexact.');
        }
    }

    /** @return array<string,mixed> */
    private function workerReceipt(
        string $taskId,
        string $result,
        bool $controllerCleanup,
        bool $admissionCleanup,
        bool $custodyReleased,
        bool $recoveryPassed,
        ?string $recoveryOutputHash,
    ): array {
        return [
            'schema' => self::WORKER_SCHEMA,
            'task_id' => $taskId,
            'result' => $result,
            'stream_cancelled' => $result === 'green',
            'partial_output_preserved' => false,
            'controller_cleanup_proven' => $controllerCleanup,
            'admission_cleanup_proven' => $admissionCleanup,
            'custody_released' => $custodyReleased,
            'recovery_inference_passed' => $recoveryPassed,
            'recovery_output_sha256' => $recoveryOutputHash,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,mixed> $policy */
    private function assertInactivePolicy(array $policy): void
    {
        $expectedKeys = [
            'schema', 'revision', 'enabled', 'automatic_selection_enabled', 'normal_routing',
            'execution_mode', 'concurrent_inference_enabled', 'tools_enabled', 'fallback_enabled',
            'queue_on_failure', 'max_concurrent', 'max_tokens', 'lease_ttl_seconds',
            'startup_timeout_seconds', 'roles', 'target',
        ];
        $actualKeys = array_keys($policy);
        sort($expectedKeys);
        sort($actualKeys);
        $expectedTarget = [
            'target_id' => 'kimi_k3_87',
            'provider_instance_id' => 'kimi_k3_87',
            'provider_model_role' => 'deep_offline',
            'model' => 'kimi-k3-colibri',
            'engine' => 'colibri-k3',
            'requires_explicit_target' => true,
        ];
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
            || ($policy['target'] ?? null) !== $expectedTarget) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification requires exact inactive policy.');
        }
    }

    private function coordinationKey(string $taskId): string
    {
        return 'model_host_controller:mhc08:k3:cancellation_qualification:'.hash('sha256', $taskId);
    }
}
