<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Wait For Cancellation Node
 *
 * Waits for a specified duration to give user a chance to cancel the workflow.
 * Checks cache for cancellation flag during wait period.
 */
class WaitForCancellation extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            // Get configuration
            $waitMinutes = $this->getConfigValue('wait_minutes', 60);
            $defaultAction = $this->getConfigValue('default_action', 'proceed');
            $workflowRunId = $input['meta']['workflow_run_id'] ?? uniqid('workflow_');

            Log::info('WaitForCancellation: Starting wait period', [
                'workflow_run_id' => $workflowRunId,
                'wait_minutes' => $waitMinutes,
                'default_action' => $defaultAction
            ]);

            // Store workflow run ID in input metadata for cancellation tracking
            $input['meta']['workflow_run_id'] = $workflowRunId;
            $input['meta']['cancellation_deadline'] = now()->addMinutes($waitMinutes)->toISOString();

            // Set cache key for cancellation flag
            $cacheKey = "youtube_workflow_cancel:{$workflowRunId}";

            // Convert minutes to seconds for sleep intervals
            $totalSeconds = $waitMinutes * 60;
            $checkInterval = 10; // Check every 10 seconds
            $elapsed = 0;

            // Wait and periodically check for cancellation
            while ($elapsed < $totalSeconds) {
                // Check if workflow was cancelled
                $cancelled = Cache::get($cacheKey, false);

                if ($cancelled) {
                    Log::info('WaitForCancellation: Workflow cancelled by user', [
                        'workflow_run_id' => $workflowRunId,
                        'elapsed_seconds' => $elapsed
                    ]);

                    // Clear the cancellation flag
                    Cache::forget($cacheKey);

                    // Return with cancellation flag
                    return $this->standardOutput(
                        [],
                        [
                            'cancelled' => true,
                            'workflow_run_id' => $workflowRunId,
                            'elapsed_seconds' => $elapsed,
                            'reason' => 'User cancelled workflow during preview window'
                        ],
                        'Workflow cancelled by user'
                    );
                }

                // Sleep for check interval
                sleep(min($checkInterval, $totalSeconds - $elapsed));
                $elapsed += $checkInterval;
            }

            Log::info('WaitForCancellation: Wait period completed, proceeding', [
                'workflow_run_id' => $workflowRunId,
                'action' => $defaultAction
            ]);

            // Wait period completed without cancellation
            if ($defaultAction === 'proceed') {
                // Pass through input to next node
                return $this->standardOutput($input, [
                    'cancelled' => false,
                    'workflow_run_id' => $workflowRunId,
                    'wait_completed' => true,
                ]);
            } else {
                // Stop workflow
                return $this->standardOutput(
                    [],
                    [
                        'cancelled' => true,
                        'workflow_run_id' => $workflowRunId,
                        'reason' => 'Default action is to cancel'
                    ],
                    'Workflow stopped by default action'
                );
            }

        } catch (Exception $e) {
            Log::error('WaitForCancellation: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // On error, proceed with workflow (fail-open)
            return $this->standardOutput($input, [
                'cancelled' => false,
                'error' => $e->getMessage(),
                'default_action_taken' => true
            ]);
        }
    }

    /**
     * Cancel a workflow run
     * This is called by the cancellation API endpoint
     *
     * @param string $workflowRunId
     * @return bool
     */
    public static function cancelWorkflow(string $workflowRunId): bool
    {
        $cacheKey = "youtube_workflow_cancel:{$workflowRunId}";

        Cache::put($cacheKey, true, now()->addHours(1));

        Log::info('WaitForCancellation: Cancellation flag set', [
            'workflow_run_id' => $workflowRunId
        ]);

        return true;
    }

    /**
     * Check if a workflow is cancelled
     *
     * @param string $workflowRunId
     * @return bool
     */
    public static function isCancelled(string $workflowRunId): bool
    {
        $cacheKey = "youtube_workflow_cancel:{$workflowRunId}";
        return Cache::get($cacheKey, false);
    }
}
