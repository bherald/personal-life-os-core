<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScheduledJobService;

/**
 * Background runner for manual job execution from the UI.
 * Spawned by ScheduledJobController::run() to avoid HTTP timeout (504).
 */
class ScheduledJobRunNowCommand extends Command
{
    protected $signature = 'scheduled-job:run-now {jobId} {runId}';
    protected $description = 'Run a scheduled job in background (called by API controller)';
    protected $hidden = true;

    public function handle(ScheduledJobService $service): int
    {
        $jobId = (int) $this->argument('jobId');
        $runId = (int) $this->argument('runId');

        $result = $service->runJobNow($jobId, 'api', $runId);

        return $result['success'] ? 0 : 1;
    }
}
