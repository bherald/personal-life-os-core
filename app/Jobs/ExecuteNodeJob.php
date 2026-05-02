<?php

namespace App\Jobs;

use App\Engine\NodeLoader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a single workflow node asynchronously.
 *
 * Used by FanOutNode to run parallel branch executions.
 * Each job processes one item from the fan-out array.
 */
class ExecuteNodeJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes per node
    public $tries = 3;
    public $backoff = [30, 60, 120];

    protected int $runId;
    protected int $nodeExecutionId;
    protected string $nodeType;
    protected array $nodeConfig;
    protected array $input;
    protected int $branchIndex;
    protected string $parentFanOutId;

    public function __construct(
        int $runId,
        int $nodeExecutionId,
        string $nodeType,
        array $nodeConfig,
        array $input,
        int $branchIndex,
        string $parentFanOutId
    ) {
        $this->runId = $runId;
        $this->nodeExecutionId = $nodeExecutionId;
        $this->nodeType = $nodeType;
        $this->nodeConfig = $nodeConfig;
        $this->input = $input;
        $this->branchIndex = $branchIndex;
        $this->parentFanOutId = $parentFanOutId;

        $this->onQueue('workflow');
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        Log::info('ExecuteNodeJob: Starting branch execution', [
            'run_id' => $this->runId,
            'node_execution_id' => $this->nodeExecutionId,
            'node_type' => $this->nodeType,
            'branch_index' => $this->branchIndex,
            'parent_fan_out_id' => $this->parentFanOutId,
        ]);

        // Mark as running
        DB::update(
            'UPDATE node_executions SET state = ? WHERE id = ?',
            ['running', $this->nodeExecutionId]
        );

        try {
            // Load and execute the node
            $nodeLoader = new NodeLoader();
            $nodeInstance = $nodeLoader->loadNode($this->nodeType, $this->nodeConfig);
            $output = $nodeInstance->execute($this->input);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Mark as success with output
            DB::update(
                'UPDATE node_executions SET state = ?, output = ?, duration_ms = ? WHERE id = ?',
                ['success', json_encode($output), $durationMs, $this->nodeExecutionId]
            );

            Log::info('ExecuteNodeJob: Branch completed successfully', [
                'node_execution_id' => $this->nodeExecutionId,
                'branch_index' => $this->branchIndex,
                'duration_ms' => $durationMs,
            ]);

        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Mark as failed
            DB::update(
                'UPDATE node_executions SET state = ?, error_message = ?, duration_ms = ? WHERE id = ?',
                ['failed', mb_substr($e->getMessage(), 0, 65000), $durationMs, $this->nodeExecutionId]
            );

            Log::error('ExecuteNodeJob: Branch execution failed', [
                'node_execution_id' => $this->nodeExecutionId,
                'branch_index' => $this->branchIndex,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Ensure state is marked as failed even if job permanently fails
        DB::update(
            'UPDATE node_executions SET state = ?, error_message = ? WHERE id = ?',
            ['failed', 'Job failed permanently: ' . ($exception?->getMessage() ?? 'Unknown'), $this->nodeExecutionId]
        );

        Log::error('ExecuteNodeJob: Permanent failure', [
            'node_execution_id' => $this->nodeExecutionId,
            'branch_index' => $this->branchIndex,
            'parent_fan_out_id' => $this->parentFanOutId,
            'error' => $exception?->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return [
            'workflow',
            'node:' . $this->nodeType,
            'fanout:' . $this->parentFanOutId,
            'branch:' . $this->branchIndex,
        ];
    }
}
