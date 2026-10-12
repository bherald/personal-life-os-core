<?php

namespace App\Jobs;

use App\Engine\WorkflowEngine;
use App\Services\CyberSuiteArtifactService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Existing queue timing/uniqueness and checkpoint claims, with exact profile/artifact binding. */
final class ResumeCyberWorkflow extends ExecuteWorkflow
{
    // Lock contention is not an effect attempt. Permit bounded waiting while stopping the first real exception.
    public $maxExceptions = 1;

    public $failOnTimeout = true;

    private int $queueRetryDeadline;

    public function retryUntil(): int
    {
        return $this->queueRetryDeadline;
    }

    public function __construct(private string $handle, private string $executionId)
    {
        parent::__construct('cyber_qualified_suite_checks', null, ['task_id' => $handle], null, hash('sha256', 'cyber-resume:'.$handle.':'.$executionId));
        $this->queueRetryDeadline = time() + max(1, min(3600, (int) $this->uniqueFor));
    }

    public function handle(): void
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $this->handle)->first();
        if ($row === null) {
            throw new RuntimeException('Durable task binding missing.');
        }
        (new CyberSuiteArtifactService)->assertJobBinding($row);
        (new WorkflowEngine)->resumeWorkflow($this->executionId);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('cyber_workflow_jobs')->where('handle', $this->handle)->update(['dispatch_state' => 'failed_before_or_during_workflow']);
        parent::failed($exception);
    }
}
