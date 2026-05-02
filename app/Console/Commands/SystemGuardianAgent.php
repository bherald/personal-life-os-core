<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class SystemGuardianAgent extends Command
{
    protected $signature = 'system:guardian
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Run the system guardian agent to monitor infrastructure, AI services, workflows, and alerts';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Perform scheduled system health check. Steps:\n"
            . "1. Run full system health check (database, Redis, Ollama, queue, disk, workflows)\n"
            . "2. Run proactive alert checks (error rates, workflow health, resource utilization)\n"
            . "3. Review active alerts and their severity\n"
            . "4. Check AI service health (Ollama instances, GPU, circuit breakers)\n"
            . "5. Check queue depth and processing status\n"
            . "6. Check workflow success rates\n"
            . "7. Summarize findings with severity classification and recommendations";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting system guardian agent...');

        $result = $agentLoop->execute('system-guardian', $task, [
            'notify' => $notify,
            'max_iterations' => 10,
        ]);

        if ($result['success']) {
            $this->info("Agent completed in " . round($result['duration_ms'] / 1000, 1) . "s");
            $this->info("Tokens used: " . ($result['tokens_used'] ?? 0));
            $this->info("Tool calls: " . count($result['tool_calls'] ?? []));

            if (!empty($result['tool_calls'])) {
                $this->line("\nTool calls made:");
                foreach ($result['tool_calls'] as $tc) {
                    $status = $tc['success'] ? 'OK' : 'FAIL';
                    $this->line("  [{$status}] {$tc['tool']}");
                }
            }

            $this->line("\n--- Agent Response ---");
            $this->line($result['response']);
        } else {
            $this->error("Agent failed: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }

        return 0;
    }
}
