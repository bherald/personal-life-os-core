<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class WorkflowOpsAgent extends Command
{
    protected $signature = 'workflow:operations
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Workflow Operations agent - monitors workflow health, stuck jobs, compensation, webhooks, and node performance';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Monitor workflow pipeline health. Steps:\n"
            ."1. Check inter-agent messages for alerts from system-guardian or ai-ops\n"
            ."2. Get workflow health summary — success rates and health status for all workflows\n"
            ."3. Check failing workflows below health threshold\n"
            ."4. Review metrics dashboard — execution times, throughput, slow nodes\n"
            ."5. Review compensation/saga rollback activity\n"
            ."6. Check scheduled job health — stuck jobs, consecutive failures\n"
            ."7. Check webhook trigger reliability\n"
            ."8. Analyze error patterns across workflows\n"
            ."9. Fix stuck jobs if detected, investigate degraded workflows\n"
            .'10. Summarize findings with pipeline status and recommended actions';

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line('Notify: '.($notify ? 'yes' : 'no'));

            return 0;
        }

        $this->info('Starting Workflow Operations agent...');

        $result = $agentLoop->execute('workflow-ops', $task, [
            'notify' => $notify,
            'max_iterations' => 12,
        ]);

        if ($result['success']) {
            $this->info('Agent completed in '.round($result['duration_ms'] / 1000, 1).'s');
            $this->info('Tokens used: '.($result['tokens_used'] ?? 0));
            $this->info('Tool calls: '.count($result['tool_calls'] ?? []));

            if (! empty($result['tool_calls'])) {
                $this->line("\nTool calls made:");
                foreach ($result['tool_calls'] as $tc) {
                    $status = $tc['success'] ? 'OK' : 'FAIL';
                    $this->line("  [{$status}] {$tc['tool']}");
                }
            }

            $this->line("\n--- Agent Response ---");
            $this->line($result['response']);
        } else {
            $this->error('Agent failed: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }

        return 0;
    }
}
