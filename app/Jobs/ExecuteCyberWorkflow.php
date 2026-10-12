<?php

namespace App\Jobs;

use App\Services\CyberModelCapacityWaitService;
use App\Services\CyberSuiteArtifactService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retains ExecuteWorkflow uniqueness, timing and existing workflow engine. */
final class ExecuteCyberWorkflow extends ExecuteWorkflow
{
    // Lock contention is not an effect attempt. Permit bounded waiting while stopping the first real exception.
    public $maxExceptions = 1;

    public $failOnTimeout = true;

    private int $queueRetryDeadline;

    public function retryUntil(): int
    {
        return $this->queueRetryDeadline;
    }

    public function __construct(string $workflowName, ?int $workflowId = null, array $input = [], ?string $traceSessionId = null, ?string $idempotencyKey = null)
    {
        parent::__construct($workflowName, $workflowId, $input, $traceSessionId, $idempotencyKey);
        // The parent resolves and validates the table-owned uniqueness window.
        // Capacity policy can shorten it; no second fixed ceiling may override it.
        $this->queueRetryDeadline = time() + $this->uniqueFor;
    }

    public function handle(): void
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $this->input['task_id'] ?? '')->first();
        if ($row === null) {
            throw new RuntimeException('Durable task binding missing.');
        }
        $binding = (new CyberSuiteArtifactService)->assertJobBinding($row);
        $admission = app(CyberModelCapacityWaitService::class)->prepare($row, $binding, $this->queueRetryDeadline);
        if ($admission['action'] === 'wait') {
            $this->release($admission['delay_seconds']);

            return;
        }
        if ($admission['action'] !== 'proceed') {
            return;
        }
        parent::handle();
    }

    public function failed(?\Throwable $exception): void
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $this->input['task_id'] ?? '')->first();
        $wait = isset($row->capacity_wait_state) ? json_decode($row->capacity_wait_state, true) : null;
        if (is_array($wait) && in_array($wait['phase'] ?? null, ['expired', 'cancelled', 'held'], true)) {
            return;
        }
        if (is_array($wait) && in_array($wait['phase'] ?? null, ['waiting', 'checking'], true)) {
            $wait['phase'] = now()->getTimestamp() >= ($wait['deadline'] ?? PHP_INT_MAX) ? 'expired' : 'held';
            $wait['next_check_at'] = null;
            DB::table('cyber_workflow_jobs')->where('handle', $row->handle)->where('capacity_wait_state', $row->capacity_wait_state)
                ->whereIn('dispatch_state', ['capacity_waiting', 'capacity_checking'])
                ->update(['dispatch_state' => 'capacity_'.$wait['phase'], 'capacity_wait_state' => json_encode($wait, JSON_THROW_ON_ERROR)]);

            return;
        }
        DB::table('cyber_workflow_jobs')->where('handle', $this->input['task_id'] ?? '')->update(['dispatch_state' => 'failed_before_or_during_workflow']);
        parent::failed($exception);
    }
}
