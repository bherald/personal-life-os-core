<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Private, explicit-only distributed-task surface for retained K3 work.
 *
 * This service deliberately bypasses the generic distributed-agent dispatcher:
 * only the fixed MHC-08 worker may claim these rows, and a failed execution is
 * terminal with no retry, fallback, tools, or normal-routing path.
 */
class ModelHostControllerMhc08K3JobService
{
    public const TASK_TYPE = 'mhc08_k3_explicit_v1';

    public const PAYLOAD_SCHEMA = 'plos.model_host_controller.mhc08_k3_job_payload.v1';

    public const RESULT_SCHEMA = 'plos.model_host_controller.mhc08_k3_job_result.v1';

    public const TERMINAL_SCHEMA = 'plos.model_host_controller.mhc08_k3_job_terminal.v1';

    public const WORK_RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_work_receipt.v1';

    private const PAYLOAD_KEYS = [
        'max_tokens',
        'prompt',
        'requester_id',
        'requester_type',
        'role',
        'schema',
        'system_prompt',
    ];

    private const ACCEPTANCE_REQUESTER_ID = 'user:mhc08-acceptance-owner';

    private const ACCEPTANCE_CASES = [
        ['role' => 'ultra_deep', 'prompt' => 'Reply with exactly MHC08-E-ULTRA.'],
        ['role' => 'archival', 'prompt' => 'Reply with exactly MHC08-E-ARCHIVAL.'],
        ['role' => 'archival', 'prompt' => 'Reply with exactly MHC08-E-CANCEL.'],
    ];

    public function __construct(
        private readonly ModelHostControllerMhc08K3PolicyService $policies,
        private readonly ModelHostControllerMhc08K3InferenceService $inference,
        private readonly ChatStreamCancellationService $cancellations,
        private readonly TaskCustodyService $custody,
        private readonly ModelHostControllerMhc08K3LifecycleService $lifecycle,
        private readonly ModelHostControllerMhc08K3AdmissionService $admission,
    ) {}

    /** @return array<string,mixed> */
    public function submit(
        string $role,
        string $prompt,
        ?string $systemPrompt,
        int $maxTokens,
        string $requesterType,
        string $requesterId,
    ): array {
        $policy = $this->jobPolicy();
        $payload = $this->validatedPayload([
            'schema' => self::PAYLOAD_SCHEMA,
            'role' => $role,
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'max_tokens' => $maxTokens,
            'requester_type' => $requesterType,
            'requester_id' => $requesterId,
        ], $policy);
        $taskId = (string) Str::uuid();
        $now = now();

        DB::table('distributed_tasks')->insert([
            'task_id' => $taskId,
            'task_type' => self::TASK_TYPE,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
            'timeout_at' => $now->copy()->addSeconds((int) $policy['lease_ttl_seconds']),
        ]);

        return [
            'task_id' => $taskId,
            'state' => DistributedAgentService::TASK_PENDING,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function workOne(): array
    {
        $policy = $this->jobPolicy();
        $task = $this->claimOne($policy);
        if ($task === null) {
            return $this->workReceipt(null, 'idle', null, 'not_required');
        }

        $taskId = (string) $task->task_id;
        $controllerReceipt = null;
        $cancellationRegistered = false;
        $custody = null;
        $payload = null;
        $inferenceAttempted = false;
        $cleanupState = 'not_required';

        try {
            $payload = $this->validatedClaim($task, $policy);
            $ownerToken = $this->custody->scheduledJobOwnerToken(getmypid(), (int) $task->id);
            $custody = $this->custody->acquire(
                TaskCustodyService::SURFACE_MHC08_K3_JOB,
                $taskId,
                $ownerToken,
                (int) $policy['lease_ttl_seconds'],
            );
            if ($custody === null) {
                throw new RuntimeException('MHC-08 K3 job custody could not be acquired.');
            }
            $this->cancellations->registerOwned($taskId, $payload['requester_type'], $payload['requester_id']);
            $cancellationRegistered = true;
            if ($this->taskState((int) $task->id) !== DistributedAgentService::TASK_RUNNING) {
                $this->updateTerminalCleanup(
                    (int) $task->id,
                    $taskId,
                    DistributedAgentService::TASK_CANCELLED,
                    'not_required',
                    null,
                );

                return $this->workReceipt($taskId, DistributedAgentService::TASK_CANCELLED, null, 'cancelled_before_execution');
            }

            $config = [
                'max_tokens' => $payload['max_tokens'],
                'cancellation_run_id' => $taskId,
            ];
            if ($payload['system_prompt'] !== null) {
                $config['system_prompt'] = $payload['system_prompt'];
            }

            $inferenceAttempted = true;
            $cleanupState = 'unproven';
            $execution = ($policy['revision'] ?? null) === ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION
                ? $this->inference->processAcceptance(
                    $payload['role'], $payload['prompt'], $config, $taskId, $taskId,
                )
                : $this->inference->process(
                    $payload['role'], $payload['prompt'], $config, $taskId, $taskId,
                );
            $controllerReceipt = $this->contentFreeControllerReceipt($execution['controller_receipt'] ?? null);
            $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
            $output = $response['response'] ?? null;
            $telemetry = is_array($response['transport_telemetry'] ?? null)
                ? $response['transport_telemetry']
                : [];
            if (! is_string($output) || trim($output) === ''
                || ($telemetry['finish_reason'] ?? null) !== 'stop'
                || ! is_int($telemetry['prompt_tokens'] ?? null)
                || (int) $telemetry['prompt_tokens'] < 0
                || ! is_int($telemetry['completion_tokens'] ?? null)
                || (int) $telemetry['completion_tokens'] < 1
                || (int) $telemetry['completion_tokens'] > $payload['max_tokens']) {
                throw new RuntimeException('MHC-08 K3 job returned an inexact complete response.');
            }

            $cleanupResult = $this->cleanupResult($controllerReceipt);
            if (! $this->cleanupIsProven($cleanupResult)) {
                throw new RuntimeException('MHC-08 K3 job cleanup was not proven before output publication.');
            }
            $cleanupState = 'proven';
            $outputHash = hash('sha256', $output);
            $completedAt = now();
            $result = [
                'schema' => self::RESULT_SCHEMA,
                'state' => DistributedAgentService::TASK_COMPLETED,
                'task_id' => $taskId,
                'finish_reason' => 'stop',
                'output' => $output,
                'output_sha256' => $outputHash,
                'prompt_tokens' => (int) $telemetry['prompt_tokens'],
                'completion_tokens' => (int) $telemetry['completion_tokens'],
                'controller_receipt' => $controllerReceipt,
                'completed_at' => $completedAt->toIso8601String(),
            ];
            $completed = DB::transaction(function () use ($task, $taskId, $result, $completedAt, $custody, $outputHash, $cleanupResult): bool {
                $updated = DB::table('distributed_tasks')
                    ->where('id', (int) $task->id)
                    ->where('task_id', $taskId)
                    ->where('task_type', self::TASK_TYPE)
                    ->where('status', DistributedAgentService::TASK_RUNNING)
                    ->update([
                        'status' => DistributedAgentService::TASK_COMPLETED,
                        'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'error_message' => null,
                        'completed_at' => $completedAt,
                        'updated_at' => $completedAt,
                    ]);
                if ($updated !== 1) {
                    return false;
                }
                $envelope = $this->workReceipt($taskId, DistributedAgentService::TASK_COMPLETED, $outputHash, $cleanupResult);
                if ($custody === null || ! $this->custody->release((int) $custody->id, 'success', $envelope)) {
                    throw new RuntimeException('MHC-08 K3 job custody release was not proven before output publication.');
                }

                return true;
            });
            if (! $completed) {
                $state = $this->taskState((int) $task->id);
                if ($state === DistributedAgentService::TASK_CANCELLED) {
                    return $this->workReceipt($taskId, $state, null, $this->cleanupResult($controllerReceipt));
                }

                throw new RuntimeException('MHC-08 K3 job completion lost task custody.');
            }
            $custody = null;

            return $this->workReceipt($taskId, DistributedAgentService::TASK_COMPLETED, $outputHash, $cleanupResult);
        } catch (Throwable) {
            $state = $this->taskState((int) $task->id);
            $cleanupState = $inferenceAttempted ? 'unproven' : 'not_required';
            if ($inferenceAttempted && is_array($payload)) {
                try {
                    $controllerReceipt = $this->proveTaskCleanup($task, $policy, $payload);
                    $cleanupState = 'proven';
                } catch (Throwable) {
                    // Persist an explicit unresolved marker. A later worker must
                    // retry the same exact cleanup before it may claim new work.
                }
            }
            if ($state === DistributedAgentService::TASK_CANCELLED) {
                $this->updateTerminalCleanup((int) $task->id, $taskId, $state, $cleanupState, $controllerReceipt);

                return $this->workReceipt($taskId, $state, null, $cleanupState);
            }

            $this->storeTerminal(
                (int) $task->id,
                $taskId,
                DistributedAgentService::TASK_FAILED,
                'execution_failed',
                $controllerReceipt,
                $cleanupState,
            );

            return $this->workReceipt($taskId, DistributedAgentService::TASK_FAILED, null, $cleanupState);
        } finally {
            if ($cancellationRegistered) {
                $this->cancellations->clear($taskId);
            }
            // Terminal task status and local finally completion do not prove physical cleanup.
            if ($custody !== null && (! $inferenceAttempted || $cleanupState === 'proven')) {
                $state = $this->taskState((int) $task->id);
                $outcome = match ($state) {
                    DistributedAgentService::TASK_COMPLETED => 'success',
                    DistributedAgentService::TASK_CANCELLED => 'cancel',
                    default => 'failure',
                };
                $envelope = $this->workReceipt($taskId, $state ?? 'unknown', null, $inferenceAttempted ? $this->cleanupResult($controllerReceipt) : 'not_required');
                if (! $this->custody->release((int) $custody->id, $outcome, $envelope)) {
                    throw new RuntimeException('MHC-08 K3 job custody release was not proven.');
                }
            }
        }
    }

    /** @return array<string,mixed> */
    public function cancel(string $taskId, string $requesterType, string $requesterId): array
    {
        if (! Str::isUuid($taskId)) {
            throw new RuntimeException('MHC-08 K3 cancellation requires a UUID task identity.');
        }
        $requesterType = $this->requesterType($requesterType);
        $requesterId = $this->requesterId($requesterId);

        return DB::transaction(function () use ($taskId, $requesterType, $requesterId): array {
            $task = DB::table('distributed_tasks')
                ->where('task_id', $taskId)
                ->where('task_type', self::TASK_TYPE)
                ->lockForUpdate()
                ->first();
            if ($task === null) {
                throw new RuntimeException('MHC-08 K3 task was not found.');
            }
            $payload = $this->decodePayload($task->payload);
            if (($payload['requester_type'] ?? null) !== $requesterType
                || ($payload['requester_id'] ?? null) !== $requesterId) {
                throw new RuntimeException('MHC-08 K3 cancellation requester does not own the task.');
            }
            if ($task->status === DistributedAgentService::TASK_CANCELLED) {
                return $this->workReceipt($taskId, DistributedAgentService::TASK_CANCELLED, null, 'cancelled');
            }
            if (! in_array($task->status, [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING], true)) {
                throw new RuntimeException('MHC-08 K3 task is already terminal.');
            }
            if ($task->status === DistributedAgentService::TASK_RUNNING
                && ! $this->cancellations->requestOwned($taskId, $requesterType, $requesterId)) {
                throw new RuntimeException('MHC-08 K3 running cancellation could not be signalled.');
            }

            $completedAt = now();
            $terminal = $this->terminalResult(
                $taskId,
                DistributedAgentService::TASK_CANCELLED,
                'operator_cancelled',
                null,
                $task->status === DistributedAgentService::TASK_PENDING ? 'not_required' : 'unproven',
                $completedAt->toIso8601String(),
            );
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('status', $task->status)
                ->update([
                    'status' => DistributedAgentService::TASK_CANCELLED,
                    'result' => json_encode($terminal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'error_message' => 'operator_cancelled',
                    'completed_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-08 K3 cancellation lost task custody.');
            }

            return $this->workReceipt($taskId, DistributedAgentService::TASK_CANCELLED, null, 'cancelled');
        });
    }

    /** @return array<string,mixed>|null */
    public function status(string $taskId, string $requesterType, string $requesterId): ?array
    {
        if (! Str::isUuid($taskId)) {
            throw new RuntimeException('MHC-08 K3 status requires a UUID task identity.');
        }
        $task = DB::table('distributed_tasks')
            ->where('task_id', $taskId)
            ->where('task_type', self::TASK_TYPE)
            ->first();
        if ($task === null) {
            return null;
        }
        $payload = $this->decodePayload($task->payload);
        if (($payload['requester_type'] ?? null) !== $this->requesterType($requesterType)
            || ($payload['requester_id'] ?? null) !== $this->requesterId($requesterId)) {
            throw new RuntimeException('MHC-08 K3 status requester does not own the task.');
        }

        return [
            'task_id' => $taskId,
            'state' => (string) $task->status,
            'result' => $task->result === null ? null : json_decode((string) $task->result, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string,mixed> */
    private function jobPolicy(): array
    {
        $policy = $this->policies->required();
        if (($policy['enabled'] ?? false) !== true
            || ! in_array($policy['revision'] ?? null, [
                ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION,
                ModelHostControllerMhc08K3PolicyService::EXPLICIT_REVISION,
            ], true)) {
            throw new RuntimeException('MHC-08 K3 explicit job policy is inactive.');
        }

        return $policy;
    }

    /** @param array<string,mixed> $policy */
    private function claimOne(array $policy): ?object
    {
        $cleanupProof = $this->proveExpiredRunningCleanup($policy);
        if (in_array($cleanupProof['cleanup_result'] ?? null, ['blocked_active', 'blocked_live_owner', 'blocked_unknown_owner'], true)) {
            return null;
        }
        if ($cleanupProof === null && ! $this->recoverUnprovenTerminals($policy)) {
            return null;
        }

        return DB::transaction(function () use ($policy, $cleanupProof): ?object {
            $active = DB::table('distributed_tasks')
                ->where('task_type', self::TASK_TYPE)
                ->where('status', DistributedAgentService::TASK_RUNNING)
                ->lockForUpdate()
                ->orderBy('id')
                ->limit(2)
                ->get();
            if ($active->count() > 1) {
                throw new RuntimeException('MHC-08 K3 job queue has multiple running tasks.');
            }
            if ($active->count() === 1) {
                $running = $active->first();
                $timeoutEpoch = is_string($running->timeout_at ?? null)
                    ? strtotime($running->timeout_at)
                    : false;
                if ($timeoutEpoch === false) {
                    throw new RuntimeException('MHC-08 K3 running task has no exact timeout.');
                }
                if ($timeoutEpoch >= now()->timestamp) {
                    return null;
                }
                $runningCustody = $this->custody->findUnreleased(
                    TaskCustodyService::SURFACE_MHC08_K3_JOB,
                    (string) $running->task_id,
                );
                if ($runningCustody === null || $this->custody->hasLiveOwner($runningCustody)) {
                    return null;
                }
                if (($cleanupProof['task_id'] ?? null) !== (string) $running->task_id
                    || ($cleanupProof['cleanup_result'] ?? null) !== 'proven') {
                    throw new RuntimeException('MHC-08 K3 stale task cleanup was not proven; queue remains blocked.');
                }
                $completedAt = now();
                $terminal = $this->terminalResult(
                    (string) $running->task_id,
                    DistributedAgentService::TASK_FAILED,
                    'expired_no_holder',
                    $cleanupProof,
                    'proven',
                    $completedAt->toIso8601String(),
                );
                $recovered = DB::table('distributed_tasks')
                    ->where('id', (int) $running->id)
                    ->where('task_id', (string) $running->task_id)
                    ->where('task_type', self::TASK_TYPE)
                    ->where('status', DistributedAgentService::TASK_RUNNING)
                    ->where('timeout_at', '<', $completedAt)
                    ->update([
                        'status' => DistributedAgentService::TASK_FAILED,
                        'result' => json_encode($terminal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'error_message' => 'expired_no_holder',
                        'completed_at' => $completedAt,
                        'updated_at' => $completedAt,
                    ]);
                if ($recovered !== 1) {
                    return null;
                }

                // Re-enter on the next worker tick so terminal cleanup markers
                // are audited before any pending task can be claimed.
                return null;
            }

            $task = DB::table('distributed_tasks')
                ->where('task_type', self::TASK_TYPE)
                ->where('status', DistributedAgentService::TASK_PENDING)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($task === null) {
                return null;
            }
            $now = now();
            $timeoutAt = $now->copy()->addSeconds((int) $policy['lease_ttl_seconds']);
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('task_id', (string) $task->task_id)
                ->where('task_type', self::TASK_TYPE)
                ->where('status', DistributedAgentService::TASK_PENDING)
                ->update([
                    'status' => DistributedAgentService::TASK_RUNNING,
                    'started_at' => $now,
                    'timeout_at' => $timeoutAt,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                return null;
            }
            $task->status = DistributedAgentService::TASK_RUNNING;
            $task->started_at = $now;
            $task->timeout_at = $timeoutAt;

            return $task;
        });
    }

    /** @param array<string,mixed> $policy @return array<string,mixed>|null */
    private function proveExpiredRunningCleanup(array $policy): ?array
    {
        $active = DB::table('distributed_tasks')
            ->where('task_type', self::TASK_TYPE)
            ->where('status', DistributedAgentService::TASK_RUNNING)
            ->orderBy('id')
            ->limit(2)
            ->get();
        if ($active->count() > 1) {
            throw new RuntimeException('MHC-08 K3 job queue has multiple running tasks.');
        }
        if ($active->count() === 0) {
            return null;
        }

        $running = $active->first();
        $timeoutEpoch = is_string($running->timeout_at ?? null)
            ? strtotime($running->timeout_at)
            : false;
        if ($timeoutEpoch === false) {
            throw new RuntimeException('MHC-08 K3 running task has no exact timeout.');
        }
        if ($timeoutEpoch >= now()->timestamp) {
            return [
                'task_id' => (string) $running->task_id,
                'cleanup_result' => 'blocked_active',
            ];
        }
        $runningCustody = $this->custody->findUnreleased(
            TaskCustodyService::SURFACE_MHC08_K3_JOB,
            (string) $running->task_id,
        );
        if ($runningCustody === null) {
            // Missing custody cannot qualify the original caller or prove its work ended.
            return [
                'task_id' => (string) $running->task_id,
                'cleanup_result' => 'blocked_unknown_owner',
            ];
        }
        if ($this->custody->hasLiveOwner($runningCustody)) {
            return [
                'task_id' => (string) $running->task_id,
                'cleanup_result' => 'blocked_live_owner',
            ];
        }

        $payload = $this->validatedClaim($running, $policy);

        return $this->proveTaskCleanup($running, $policy, $payload);
    }

    /** @param array<string,mixed> $policy @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function proveTaskCleanup(object $task, array $policy, ?array $payload = null): array
    {
        $payload ??= $this->validatedClaim($task, $policy);
        $taskId = (string) $task->task_id;
        $admission = $this->admission->recoverInterrupted($taskId);
        if (($admission['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_admission_recovery.v1'
            || ($admission['request_id'] ?? null) !== $taskId
            || ! in_array($admission['result'] ?? null, ['restored', 'not_required'], true)
            || ($admission['provider_closed'] ?? false) !== true
            || ($admission['profile_gated'] ?? false) !== true
            || ($admission['guard_revoked'] ?? false) !== true
            || ($admission['journal_removed'] ?? false) !== true
            || ($admission['content_free'] ?? false) !== true
            || ($admission['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 stale admission cleanup receipt is not exact.');
        }

        $controller = ($policy['revision'] ?? null) === ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION
            ? $this->lifecycle->recoverInterruptedAcceptance($payload['role'], $taskId, $taskId)
            : $this->lifecycle->recoverInterrupted($payload['role'], $taskId, $taskId);
        if (($controller['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_interrupted_recovery.v1'
            || ($controller['role'] ?? null) !== $payload['role']
            || ($controller['target'] ?? null) !== 'kimi_k3_87'
            || ($controller['request_id'] ?? null) !== $taskId
            || ($controller['owner_request_id'] ?? null) !== $taskId.'-owner'
            || ($controller['lease_id'] ?? null) !== $taskId
            || ! in_array($controller['result'] ?? null, ['restored', 'not_required'], true)
            || ($controller['status_verified'] ?? null) !== 'clean'
            || ($controller['content_free'] ?? false) !== true
            || ($controller['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 stale controller cleanup receipt is not exact.');
        }

        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_stale_cleanup.v1',
            'task_id' => $taskId,
            'controller_result' => $controller['result'],
            'admission_result' => $admission['result'],
            'cleanup_result' => 'proven',
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,mixed> $policy */
    private function recoverUnprovenTerminals(array $policy): bool
    {
        $tasks = DB::table('distributed_tasks')
            ->where('task_type', self::TASK_TYPE)
            ->whereIn('status', [DistributedAgentService::TASK_FAILED, DistributedAgentService::TASK_CANCELLED])
            ->orderBy('id')
            ->limit(101)
            ->get();
        if ($tasks->count() > 100) {
            throw new RuntimeException('MHC-08 K3 unresolved terminal cleanup scan exceeded its safety bound.');
        }

        foreach ($tasks as $task) {
            $result = is_string($task->result ?? null)
                ? json_decode($task->result, true)
                : null;
            if (! is_array($result) || ($result['schema'] ?? null) !== self::TERMINAL_SCHEMA) {
                throw new RuntimeException('MHC-08 K3 terminal cleanup marker is invalid; queue remains blocked.');
            }
            if (in_array($result['cleanup_result'] ?? null, ['proven', 'not_required'], true)) {
                continue;
            }

            $terminalCustody = $this->custody->findUnreleased(
                TaskCustodyService::SURFACE_MHC08_K3_JOB,
                (string) $task->task_id,
            );
            if ($terminalCustody === null) {
                // No qualified ownership evidence: retain the original unresolved terminal.
                return false;
            }
            if ($this->custody->hasLiveOwner($terminalCustody)) {
                // The original worker may have terminalized the task but not
                // reached its finally/release path yet. Never race its exact
                // cleanup ownership or admit another task while it is live.
                return false;
            }

            $payload = $this->validatedClaim($task, $policy);
            $proof = $this->proveTaskCleanup($task, $policy, $payload);
            $result['cleanup_result'] = 'proven';
            $result['controller_receipt'] = $proof;
            $updated = DB::table('distributed_tasks')
                ->where('id', (int) $task->id)
                ->where('task_id', (string) $task->task_id)
                ->where('task_type', self::TASK_TYPE)
                ->where('status', (string) $task->status)
                ->update([
                    'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-08 K3 terminal cleanup proof lost task custody.');
            }
        }

        return true;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function validatedClaim(object $task, array $policy): array
    {
        if (! Str::isUuid((string) $task->task_id)
            || $task->task_type !== self::TASK_TYPE
            || $task->assigned_agent_id !== null
            || $task->assigned_at !== null
            || (int) $task->priority !== 0
            || (int) $task->retry_count !== 0
            || (int) $task->max_retries !== 0
            || json_decode((string) ($task->required_capabilities ?? ''), true) !== []) {
            throw new RuntimeException('MHC-08 K3 job row contract is not exact.');
        }

        return $this->validatedPayload($this->decodePayload($task->payload), $policy);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $policy @return array<string,mixed> */
    private function validatedPayload(array $payload, array $policy): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== self::PAYLOAD_KEYS || ($payload['schema'] ?? null) !== self::PAYLOAD_SCHEMA) {
            throw new RuntimeException('MHC-08 K3 job payload keys are not exact.');
        }
        $role = is_string($payload['role'] ?? null) ? trim($payload['role']) : '';
        $prompt = is_string($payload['prompt'] ?? null) ? trim($payload['prompt']) : '';
        $systemPrompt = $payload['system_prompt'] ?? null;
        if ($systemPrompt !== null && ! is_string($systemPrompt)) {
            throw new RuntimeException('MHC-08 K3 system prompt must be a string or null.');
        }
        $systemPrompt = is_string($systemPrompt) ? trim($systemPrompt) : null;
        if ($prompt === '' || mb_strlen($prompt) > 16000
            || mb_strlen((string) $systemPrompt) > 4000
            || mb_strlen($prompt) + mb_strlen((string) $systemPrompt) > 16000) {
            throw new RuntimeException('MHC-08 K3 job prompt boundary is invalid.');
        }
        $maxTokens = $payload['max_tokens'] ?? null;
        if (! is_int($maxTokens) || $maxTokens < 1 || $maxTokens > min(128, (int) $policy['max_tokens'])) {
            throw new RuntimeException('MHC-08 K3 job max_tokens is outside the guarded range.');
        }

        $validated = [
            'schema' => self::PAYLOAD_SCHEMA,
            'role' => $role,
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'max_tokens' => $maxTokens,
            'requester_type' => $this->requesterType($payload['requester_type'] ?? null),
            'requester_id' => $this->requesterId($payload['requester_id'] ?? null),
        ];
        if (($policy['revision'] ?? null) === ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION) {
            $this->policies->assertAcceptanceRole($role, $policy);
            $caseExact = collect(self::ACCEPTANCE_CASES)->contains(
                static fn (array $case): bool => $case['role'] === $role && $case['prompt'] === $prompt,
            );
            if (! $caseExact || $systemPrompt !== null || $maxTokens !== 128
                || $validated['requester_type'] !== 'user'
                || $validated['requester_id'] !== self::ACCEPTANCE_REQUESTER_ID) {
                throw new RuntimeException('MHC-08 K3 acceptance policy admits only fixed internal synthetic jobs.');
            }
        } else {
            $this->policies->assertExplicitRole($role, $policy);
        }

        return $validated;
    }

    /** @return array<string,mixed> */
    private function decodePayload(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($decoded)) {
            throw new RuntimeException('MHC-08 K3 job payload is invalid.');
        }

        return $decoded;
    }

    private function requesterType(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', trim($value)) !== 1) {
            throw new RuntimeException('MHC-08 K3 requester type is invalid.');
        }

        return trim($value);
    }

    private function requesterId(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:@-]{0,127}\z/', trim($value)) !== 1) {
            throw new RuntimeException('MHC-08 K3 requester id is invalid.');
        }

        return trim($value);
    }

    /** @return array<string,mixed> */
    private function contentFreeControllerReceipt(mixed $receipt): array
    {
        if (! is_array($receipt)
            || ($receipt['result'] ?? null) !== 'green'
            || ($receipt['content_free'] ?? false) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 controller receipt is not green and content-free.');
        }

        return $receipt;
    }

    private function cleanupResult(?array $controllerReceipt): string
    {
        $proof = $controllerReceipt['cleanup_result'] ?? null;
        if (is_string($proof) && $proof !== '') {
            return $proof;
        }
        $result = $controllerReceipt['release_result'] ?? null;

        return is_string($result) && $result !== '' ? $result : 'unproven';
    }

    private function cleanupIsProven(string $result): bool
    {
        return in_array($result, ['proven', 'released', 'released_status_verified', 'recovered_status_verified'], true);
    }

    private function taskState(int $id): ?string
    {
        $state = DB::table('distributed_tasks')->where('id', $id)->value('status');

        return is_string($state) ? $state : null;
    }

    private function updateTerminalCleanup(
        int $id,
        string $taskId,
        string $state,
        string $cleanupResult,
        ?array $controllerReceipt,
    ): void {
        DB::transaction(function () use ($id, $taskId, $state, $cleanupResult, $controllerReceipt): void {
            $task = DB::table('distributed_tasks')
                ->where('id', $id)
                ->where('task_id', $taskId)
                ->where('task_type', self::TASK_TYPE)
                ->where('status', $state)
                ->lockForUpdate()
                ->first(['result']);
            $result = is_string($task->result ?? null) ? json_decode($task->result, true) : null;
            if (! is_array($result)
                || ($result['schema'] ?? null) !== self::TERMINAL_SCHEMA
                || ($result['task_id'] ?? null) !== $taskId
                || ($result['state'] ?? null) !== $state
                || array_key_exists('output', $result)) {
                throw new RuntimeException('MHC-08 K3 terminal cleanup update is not exact.');
            }
            $result['cleanup_result'] = $cleanupResult;
            $result['controller_receipt'] = $controllerReceipt;
            $updated = DB::table('distributed_tasks')
                ->where('id', $id)
                ->where('task_id', $taskId)
                ->where('task_type', self::TASK_TYPE)
                ->where('status', $state)
                ->update([
                    'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('MHC-08 K3 terminal cleanup update lost task custody.');
            }
        });
    }

    private function storeTerminal(
        int $id,
        string $taskId,
        string $state,
        string $reason,
        ?array $controllerReceipt,
        string $cleanupResult,
    ): void {
        $completedAt = now();
        $result = $this->terminalResult(
            $taskId,
            $state,
            $reason,
            $controllerReceipt,
            $cleanupResult,
            $completedAt->toIso8601String(),
        );
        $updated = DB::table('distributed_tasks')
            ->where('id', $id)
            ->where('task_id', $taskId)
            ->where('task_type', self::TASK_TYPE)
            ->where('status', DistributedAgentService::TASK_RUNNING)
            ->update([
                'status' => $state,
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'error_message' => $reason,
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
        if ($updated !== 1
            && $this->taskState($id) !== DistributedAgentService::TASK_CANCELLED) {
            throw new RuntimeException('MHC-08 K3 terminal result lost task custody.');
        }
    }

    /** @return array<string,mixed> */
    private function terminalResult(
        string $taskId,
        string $state,
        string $reason,
        ?array $controllerReceipt,
        string $cleanupResult,
        string $completedAt,
    ): array {
        return [
            'schema' => self::TERMINAL_SCHEMA,
            'task_id' => $taskId,
            'state' => $state,
            'reason' => $reason,
            'cleanup_result' => $cleanupResult,
            'controller_receipt' => $controllerReceipt,
            'completed_at' => $completedAt,
        ];
    }

    /** @return array<string,mixed> */
    private function workReceipt(?string $taskId, string $state, ?string $outputHash, string $cleanupResult): array
    {
        return [
            'schema' => self::WORK_RECEIPT_SCHEMA,
            'task_id' => $taskId,
            'state' => $state,
            'output_sha256' => $outputHash,
            'cleanup_result' => $cleanupResult,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }
}
