<?php

namespace App\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Post-promotion proof of the real owner-bound API and one-shot worker surface. */
class ModelHostControllerMhc08K3ExplicitJobSmokeService
{
    public const RECEIPT_SCHEMA = 'plos.model_host_controller.mhc08_k3_explicit_job_smoke.v1';

    private const OWNER = 'mhc08-acceptance-owner';

    private const OTHER_OWNER = 'mhc08-acceptance-other';

    private const PROMOTION_JOURNAL = 'model_host_controller_mhc08_k3_promotion_journal';

    private const CASES = [
        ['case_id' => 'ultra_deep_job', 'role' => 'ultra_deep', 'expected' => 'MHC08-E-ULTRA'],
        ['case_id' => 'archival_job', 'role' => 'archival', 'expected' => 'MHC08-E-ARCHIVAL'],
    ];

    public function __construct(
        private readonly Kernel $kernel,
        private readonly ModelHostControllerMhc08K3JobService $jobs,
        private readonly ModelHostControllerClient $controller,
        private readonly TaskCustodyService $custody,
        private readonly ModelHostControllerMhc08K3LifecycleService $lifecycle,
        private readonly ModelHostControllerMhc08K3AdmissionService $admission,
        private readonly ModelHostControllerMhc08K3PolicyService $policy,
        private readonly ModelHostControllerMhc08K3StateContractService $state,
        private readonly ModelHostControllerMhc08K3EvidenceService $evidence,
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $contract = $this->state->finalized();
        $promotionContext = $this->promotionContext($contract);
        $acceptance = $this->policy->required();
        if (DB::table('distributed_tasks')->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
            ->whereIn('status', [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING])->exists()
            || DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_mhc08_k3_admission_journal')->exists()
            || (int) DB::table('scheduled_jobs')->where('name', 'model_host_controller_mhc08_k3_worker')
                ->value('enabled') !== 0
            || ($acceptance['revision'] ?? null) !== ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION
            || ($acceptance['enabled'] ?? null) !== true) {
            throw new RuntimeException('MHC-08 explicit smoke requires the synthetic-only acceptance policy and disabled schedule.');
        }
        $cases = [];
        $taskIds = [];
        try {
            return $this->runQualified($contract, $promotionContext, $taskIds, $cases);
        } catch (Throwable $failure) {
            if (! $this->recoverFailure($taskIds, $promotionContext)) {
                throw new RuntimeException('MHC-08 explicit smoke failure cleanup is unproven.', 0, $failure);
            }

            throw $failure;
        }
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $promotionContext @param list<string> $taskIds @param list<array<string,mixed>> $cases @return array<string,mixed> */
    private function runQualified(array $contract, array $promotionContext, array &$taskIds, array &$cases): array
    {
        $unauthenticated = $this->routed('POST', '/api/ops/model-host/k3/jobs', [
            'role' => 'ultra_deep',
            'prompt' => 'Reply with exactly MHC08-E-AUTH.',
            'system_prompt' => null,
            'max_tokens' => 128,
        ], null);
        if ($unauthenticated['status'] !== 401) {
            throw new RuntimeException('MHC-08 explicit API authentication middleware was not enforced.');
        }
        $normalOwner = $this->routed('POST', '/api/ops/model-host/k3/jobs', [
            'role' => 'ultra_deep',
            'prompt' => 'Normal authenticated request must remain closed during acceptance.',
            'system_prompt' => null,
            'max_tokens' => 128,
        ], '42');
        if ($normalOwner['status'] !== 409
            || DB::table('distributed_tasks')->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)->exists()) {
            throw new RuntimeException('MHC-08 acceptance policy admitted a normal authenticated submission.');
        }

        foreach (self::CASES as $case) {
            $submitted = $this->submit($case['role'], 'Reply with exactly '.$case['expected'].'.', self::OWNER);
            $taskId = $this->taskId($submitted);
            $taskIds[] = $taskId;
            $this->assertOtherOwnerCannotRead($taskId);
            $worker = $this->runWorker($taskId, 'completed');
            $status = $this->status($taskId, self::OWNER, 200);
            $result = data_get($status, 'data.result');
            $output = is_array($result) ? ($result['output'] ?? null) : null;
            if (! is_string($output) || $output !== $case['expected']
                || ($result['state'] ?? null) !== DistributedAgentService::TASK_COMPLETED
                || ($result['finish_reason'] ?? null) !== 'stop'
                || ($result['output_sha256'] ?? null) !== hash('sha256', $output)
                || ($worker['output_sha256'] ?? null) !== hash('sha256', $output)) {
                throw new RuntimeException('MHC-08 explicit job smoke output contract failed.');
            }
            $cases[] = [
                'case_id' => $case['case_id'],
                'role' => $case['role'],
                'task_id' => $taskId,
                'state' => 'completed',
                'finish_reason' => 'stop',
                'output_sha256' => hash('sha256', $output),
                'owner_status_proven' => true,
                'cross_owner_rejected' => true,
                'worker_one_shot' => true,
                'cleanup_proven' => true,
            ];
            unset($output, $result, $status);
        }

        $cancel = $this->submit('archival', 'Reply with exactly MHC08-E-CANCEL.', self::OWNER);
        $cancelTaskId = $this->taskId($cancel);
        $taskIds[] = $cancelTaskId;
        $this->assertOtherOwnerCannotCancel($cancelTaskId);
        $cancelled = $this->cancel($cancelTaskId, self::OWNER, 200);
        if (data_get($cancelled, 'data.state') !== DistributedAgentService::TASK_CANCELLED) {
            throw new RuntimeException('MHC-08 explicit job cancellation was not terminal.');
        }
        $idle = $this->runWorker(null, 'idle');
        $cancelStatus = $this->status($cancelTaskId, self::OWNER, 200);
        $cancelResult = data_get($cancelStatus, 'data.result');
        if (data_get($cancelStatus, 'data.state') !== DistributedAgentService::TASK_CANCELLED
            || ! is_array($cancelResult) || array_key_exists('output', $cancelResult)
            || ($idle['task_id'] ?? null) !== null) {
            throw new RuntimeException('MHC-08 cancelled smoke task leaked output or reached the worker.');
        }

        $host = $this->controller->statusK3();
        $this->assertClean($host, $contract, $promotionContext, $taskIds);
        if ($this->promotionContext($contract) !== $promotionContext) {
            throw new RuntimeException('MHC-08 promotion attempt changed during explicit smoke.');
        }
        $receipt = [
            'schema' => self::RECEIPT_SCHEMA,
            'result' => 'green',
            'target' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'runtime_profile' => 'State',
            'runtime_revision' => $contract['runtime_revision'],
            'state_engine_sha256' => $contract['engine_sha256'],
            'state_build_receipt_sha256' => $contract['state_build_receipt_sha256'],
            'final_state_sha256' => $promotionContext['final_state_sha256'],
            'promotion_attempt_id' => $promotionContext['attempt_id'],
            'acceptance_context_sha256' => $promotionContext['acceptance_context_sha256'],
            'promoted_at' => $promotionContext['promoted_at'],
            'finalized_provenance_sha256' => $promotionContext['finalized_provenance_sha256'],
            'roles' => ['ultra_deep', 'archival'],
            'case_count' => 2,
            'cases' => $cases,
            'cancel_task_id' => $cancelTaskId,
            'cancel_owner_proven' => true,
            'cross_owner_cancel_rejected' => true,
            'cancelled_before_worker' => true,
            'cancelled_output_omitted' => true,
            'unauthenticated_rejected' => true,
            'acceptance_policy_enforced' => true,
            'normal_authenticated_submission_rejected' => true,
            'worker_schedule_disabled' => true,
            'api_route_and_auth_middleware_exercised' => true,
            'one_shot_worker_exercised' => true,
            'pending_or_running_tasks' => 0,
            'custody_released' => true,
            'admission_journal_absent' => true,
            'controller_cleanup_proven' => true,
            'normal_routing_changed' => false,
            'tools_used' => false,
            'fallback_used' => false,
            'completed_at' => now()->toIso8601String(),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
        $this->evidence->store('final-smoke', $receipt);

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function submit(string $role, string $prompt, string $owner): array
    {
        $response = $this->routed('POST', '/api/ops/model-host/k3/jobs', [
            'role' => $role,
            'prompt' => $prompt,
            'system_prompt' => null,
            'max_tokens' => 128,
        ], $owner);
        if ($response['status'] !== 202) {
            throw new RuntimeException('MHC-08 explicit API submission failed.');
        }

        return $response['body'];
    }

    /** @return array<string,mixed> */
    private function status(string $taskId, string $owner, int $expectedStatus): array
    {
        $response = $this->routed('GET', '/api/ops/model-host/k3/jobs/'.$taskId, [], $owner);
        if ($response['status'] !== $expectedStatus) {
            throw new RuntimeException('MHC-08 explicit API status contract failed.');
        }

        return $response['body'];
    }

    /** @return array<string,mixed> */
    private function cancel(string $taskId, string $owner, int $expectedStatus): array
    {
        $response = $this->routed('POST', '/api/ops/model-host/k3/jobs/'.$taskId.'/cancel', [], $owner);
        if ($response['status'] !== $expectedStatus) {
            throw new RuntimeException('MHC-08 explicit API cancellation contract failed.');
        }

        return $response['body'];
    }

    private function assertOtherOwnerCannotRead(string $taskId): void
    {
        $this->status($taskId, self::OTHER_OWNER, 404);
    }

    private function assertOtherOwnerCannotCancel(string $taskId): void
    {
        $this->cancel($taskId, self::OTHER_OWNER, 409);
    }

    /** @return array<string,mixed> */
    private function runWorker(?string $expectedTaskId, string $expectedState): array
    {
        $process = Process::path(base_path())->timeout(26100)->run([
            PHP_BINARY, 'artisan', 'model-host-controller:mhc08-k3-work',
        ]);
        if (! $process->successful()) {
            throw new RuntimeException('MHC-08 explicit one-shot worker failed.');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $process->output()) ?: [])));
        if (count($lines) !== 1) {
            throw new RuntimeException('MHC-08 explicit worker emitted non-receipt output.');
        }
        $receipt = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($receipt)
            || array_keys($receipt) !== [
                'schema', 'task_id', 'state', 'output_sha256', 'cleanup_result',
                'content_free', 'contains_prompt_or_response_content',
            ]
            || ($receipt['schema'] ?? null) !== ModelHostControllerMhc08K3JobService::WORK_RECEIPT_SCHEMA
            || ($receipt['task_id'] ?? null) !== $expectedTaskId
            || ($receipt['state'] ?? null) !== $expectedState
            || ($receipt['cleanup_result'] ?? null) !== ($expectedState === 'idle' ? 'not_required' : 'proven')
            || ($expectedState === 'idle' && ($receipt['output_sha256'] ?? null) !== null)
            || ($expectedState === 'completed' && ! is_string($receipt['output_sha256'] ?? null))
            || ($receipt['content_free'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false) {
            throw new RuntimeException('MHC-08 explicit worker receipt was not exact.');
        }

        return $receipt;
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>} */
    private function routed(string $method, string $uri, array $payload, ?string $owner): array
    {
        $request = Request::create($uri, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Auth::forgetGuards();
        if ($owner !== null) {
            Auth::guard('web')->setUser(new GenericUser(['id' => $owner]));
        }
        $response = null;
        try {
            $response = $this->kernel->handle($request);
            $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new RuntimeException('MHC-08 routed API response is not JSON.');
            }

            return ['status' => $response->getStatusCode(), 'body' => $body];
        } finally {
            if ($response !== null) {
                $this->kernel->terminate($request, $response);
            }
            Auth::forgetGuards();
        }
    }

    /** @param list<string> $taskIds @param array<string,mixed> $promotionContext */
    private function recoverFailure(array $taskIds, array $promotionContext): bool
    {
        $clean = true;
        foreach ($taskIds as $taskId) {
            $task = DB::table('distributed_tasks')->where('task_id', $taskId)
                ->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)->first();
            if ($task === null || ! in_array((string) $task->status, [
                DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING,
            ], true)) {
                continue;
            }
            $payload = json_decode((string) $task->payload, true);
            $role = is_array($payload) ? ($payload['role'] ?? null) : null;
            try {
                if ((string) $task->status === DistributedAgentService::TASK_PENDING) {
                    $this->jobs->cancel($taskId, 'user', 'user:'.self::OWNER);

                    continue;
                }
                if (! is_string($role)) {
                    throw new RuntimeException('MHC-08 failure cleanup role is missing.');
                }
                $this->admission->recoverInterrupted($taskId);
                $controllerReceipt = $this->lifecycle->recoverInterruptedAcceptance($role, $taskId, $taskId);
                $custody = $this->custody->findUnreleased(TaskCustodyService::SURFACE_MHC08_K3_JOB, $taskId);
                if ($custody !== null && $this->custody->hasLiveOwner($custody)) {
                    throw new RuntimeException('MHC-08 failure cleanup found a live custody owner.');
                }
                $completedAt = now();
                $terminal = [
                    'schema' => ModelHostControllerMhc08K3JobService::TERMINAL_SCHEMA,
                    'task_id' => $taskId,
                    'state' => DistributedAgentService::TASK_CANCELLED,
                    'reason' => 'acceptance_smoke_cleanup',
                    'cleanup_result' => 'proven',
                    'controller_receipt' => $controllerReceipt,
                    'completed_at' => $completedAt->toIso8601String(),
                ];
                $updated = DB::table('distributed_tasks')->where('id', (int) $task->id)
                    ->where('status', DistributedAgentService::TASK_RUNNING)->update([
                        'status' => DistributedAgentService::TASK_CANCELLED,
                        'result' => json_encode($terminal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'error_message' => 'acceptance_smoke_cleanup',
                        'completed_at' => $completedAt,
                        'updated_at' => $completedAt,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('MHC-08 failure cleanup lost the running task.');
                }
                if ($custody !== null && ! $this->custody->release((int) $custody->id, 'cancel', $terminal)) {
                    throw new RuntimeException('MHC-08 failure cleanup custody release failed.');
                }
            } catch (Throwable) {
                $clean = false;
            }
        }
        try {
            $host = $this->controller->statusK3();
            $clean = $clean && $this->controllerIsClean(
                $host,
                $this->state->finalized(),
                $promotionContext['final_state_sha256'],
            )
                && ! DB::table('distributed_tasks')->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                    ->whereIn('status', [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING])->exists()
                && ! DB::table('system_configs')->where('section', 'ai')
                    ->where('config_key', 'model_host_controller_mhc08_k3_admission_journal')->exists();
        } catch (Throwable) {
            $clean = false;
        }

        return $clean;
    }

    /** @param array<string,mixed> $response */
    private function taskId(array $response): string
    {
        $taskId = data_get($response, 'data.task_id');
        if (! is_string($taskId) || ! Str::isUuid($taskId)
            || data_get($response, 'data.state') !== DistributedAgentService::TASK_PENDING) {
            throw new RuntimeException('MHC-08 explicit API did not return a queued UUID task.');
        }

        return $taskId;
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $contract @param array<string,mixed> $promotionContext @param list<string> $taskIds */
    private function assertClean(array $host, array $contract, array $promotionContext, array $taskIds): void
    {
        if (! $this->controllerIsClean($host, $contract, $promotionContext['final_state_sha256'])
            || DB::table('distributed_tasks')->where('task_type', ModelHostControllerMhc08K3JobService::TASK_TYPE)
                ->whereIn('status', [DistributedAgentService::TASK_PENDING, DistributedAgentService::TASK_RUNNING])->exists()
            || DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_mhc08_k3_admission_journal')->exists()) {
            throw new RuntimeException('MHC-08 explicit job smoke cleanup was not proven.');
        }
        foreach ($taskIds as $taskId) {
            if ($this->custody->findUnreleased(TaskCustodyService::SURFACE_MHC08_K3_JOB, $taskId) !== null) {
                throw new RuntimeException('MHC-08 explicit job smoke custody remains unreleased.');
            }
        }
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $contract */
    private function controllerIsClean(array $host, array $contract, string $finalStateSha256): bool
    {
        $ingress = is_array($host['ingress'] ?? null) ? $host['ingress'] : [];
        $state = is_array($host['state'] ?? null) ? $host['state'] : [];
        $resources = is_array($host['resources'] ?? null) ? $host['resources'] : [];
        $health = is_array($host['health'] ?? null) ? $host['health'] : [];
        $restore = is_array($host['coexistence_restore_proof'] ?? null)
            ? $host['coexistence_restore_proof'] : [];
        $targets = is_array($restore['targets'] ?? null) ? $restore['targets'] : [];
        $targetNames = array_map(static fn (mixed $target): mixed => is_array($target)
            ? ($target['target'] ?? null) : null, $targets);
        $coexistence = ($restore['schema'] ?? null) === 'plos.model_host_controller.mhc08_coexistence_restore.v1'
            && ($restore['result'] ?? null) === 'green' && ($restore['mode'] ?? null) === 'Restored'
            && $targetNames === array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
            && collect($targets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true));
        $currentTargets = is_array($host['coexistence_current_targets'] ?? null)
            ? $host['coexistence_current_targets'] : [];
        $coexistenceCurrent = ($host['coexistence_current'] ?? null) === true
            && array_map(static fn (mixed $target): mixed => is_array($target)
                ? ($target['target'] ?? null) : null, $currentTargets)
                === array_keys(app(SystemConfigService::class)->llmRoutingBinding('controller_canary')['targets'])
            && collect($currentTargets)->every(static fn (mixed $target): bool => is_array($target)
                && ($target['task_exact'] ?? null) === true
                && (($target['health_required'] ?? false) !== true || ($target['health_healthy'] ?? null) === true));

        return ($host['runtime_profile'] ?? null) === 'State'
            && ($host['runtime_revision'] ?? null) === $contract['runtime_revision']
            && ($host['engine_sha256'] ?? null) === $contract['engine_sha256']
            && ($host['patch_sha256'] ?? null) === $contract['patch_sha256']
            && ($host['patched_tree'] ?? null) === $contract['patched_tree']
            && ($host['task_exact'] ?? null) === true && ($host['task_enabled'] ?? null) === false
            && ! in_array($host['task_state'] ?? null, ['Running', 'Queued'], true)
            && ($host['candidate_task_present'] ?? null) === true
            && ($host['candidate_task_exact'] ?? null) === true
            && ($host['candidate_task_enabled'] ?? null) === false
            && ! in_array($host['candidate_task_state'] ?? null, ['Running', 'Queued'], true)
            && ($host['restart_state_present'] ?? null) === true
            && ($host['restart_state_sha256'] ?? null) === $finalStateSha256
            && ($host['restart_state_temporary_present'] ?? null) === false
            && ($host['health_required'] ?? null) === false
            && ($health['healthy'] ?? null) === false
            && (int) ($host['process_count'] ?? -1) === 0 && (int) ($host['listener_count'] ?? -1) === 0
            && ($ingress['ingress_open'] ?? null) === false
            && ($ingress['firewall_present'] ?? null) === false
            && ($ingress['exact_proxy'] ?? null) === false
            && (int) ($ingress['port_8001_proxy_count'] ?? -1) === 0
            && (int) ($ingress['other_port_8001_proxy_count'] ?? -1) === 0
            && ! is_array($state['lease'] ?? null) && ($resources['gate_passed'] ?? null) === true
            && $coexistence && $coexistenceCurrent;
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function promotionContext(array $contract): array
    {
        return DB::transaction(function () use ($contract): array {
            $journal = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', self::PROMOTION_JOURNAL)->lockForUpdate()->first();
            $snapshot = $journal === null ? null : json_decode((string) $journal->config_value, true);
            $worker = DB::table('scheduled_jobs')->where('name', 'model_host_controller_mhc08_k3_worker')
                ->lockForUpdate()->first();
            if (! is_array($snapshot)
                || ($snapshot['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_promotion_journal.v1'
                || ($snapshot['phase'] ?? null) !== 'promoted_pending_smoke'
                || ! is_string($snapshot['attempt_id'] ?? null) || ! Str::isUuid($snapshot['attempt_id'])
                || ! $this->sha($snapshot['acceptance_context_sha256'] ?? null)
                || ! $this->sha($snapshot['finalized_provenance_sha256'] ?? null)
                || ! $this->sha($snapshot['final_state_sha256'] ?? null)
                || ($snapshot['finalized_provenance_sha256'] ?? null) !== $contract['mhc06_provenance_sha256']
                || strtotime((string) ($snapshot['promoted_at'] ?? '')) === false
                || $worker === null || (int) $worker->enabled !== 0) {
                throw new RuntimeException('MHC-08 explicit smoke promotion attempt context is unavailable or drifted.');
            }

            return [
                'attempt_id' => $snapshot['attempt_id'],
                'acceptance_context_sha256' => $snapshot['acceptance_context_sha256'],
                'promoted_at' => $snapshot['promoted_at'],
                'finalized_provenance_sha256' => $snapshot['finalized_provenance_sha256'],
                'final_state_sha256' => $snapshot['final_state_sha256'],
            ];
        });
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
