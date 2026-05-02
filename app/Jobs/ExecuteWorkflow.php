<?php

namespace App\Jobs;

use App\Engine\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteWorkflow implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Job timeout in seconds.
     * Match Horizon's dedicated workflow supervisor timeout.
     */
    public $timeout = 900;

    /**
     * Number of retry attempts
     */
    public $tries = 2;

    /**
     * Keep one queued/executing copy of the same workflow admitted at a time.
     */
    public $uniqueFor = 1800;

    /**
     * Retry backoff in seconds (wait 60s, then 120s)
     */
    public $backoff = [60, 120];

    /**
     * Delete job if models missing
     */
    public $deleteWhenMissingModels = true;

    protected string $workflowName;

    protected ?int $workflowId;

    protected array $input;

    /**
     * Create a new job instance.
     */
    public function __construct(string $workflowName, ?int $workflowId = null, array $input = [])
    {
        $this->workflowName = $workflowName;
        $this->workflowId = $workflowId;
        $this->input = $input;

        // Use the dedicated workflow queue to isolate orchestration work.
        $this->onQueue('workflow');
    }

    /**
     * Get middleware for the job.
     * Prevents duplicate workflow executions.
     */
    public function middleware(): array
    {
        return [
            // Prevent same workflow from running concurrently
            (new WithoutOverlapping($this->workflowName))
                ->releaseAfter(300) // Release lock after 5 minutes if stuck
                ->expireAfter(1800), // Lock expires after 30 minutes
        ];
    }

    /**
     * Determine the unique ID for the job.
     */
    public function uniqueId(): string
    {
        if (empty($this->input)) {
            return $this->workflowName;
        }

        return $this->workflowName.':'.hash('sha256', json_encode($this->recursiveKeySort($this->input)));
    }

    /**
     * Keep queued uniqueness stable for equivalent payloads with different key order.
     */
    private function recursiveKeySort(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->recursiveKeySort($value);
            }
        }

        return $array;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting queued workflow execution', [
            'workflow' => $this->workflowName,
            'id' => $this->workflowId,
            'attempt' => $this->attempts(),
            'input_keys' => array_keys($this->input),
        ]);

        $engine = new WorkflowEngine;
        $result = $engine->executeWorkflow($this->workflowName, $this->input);

        Log::info('Queued workflow completed successfully', [
            'workflow' => $this->workflowName,
            'result' => $result,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Queued workflow execution failed permanently', [
            'workflow' => $this->workflowName,
            'id' => $this->workflowId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);

        // Could send notification here
        // NotificationController::send('pushover', [...])
    }

    /**
     * Get tags for Horizon monitoring.
     */
    public function tags(): array
    {
        return ['workflow', 'workflow:'.$this->workflowName];
    }
}
