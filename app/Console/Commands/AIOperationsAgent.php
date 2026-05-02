<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class AIOperationsAgent extends Command
{
    protected $signature = 'ai:operations
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'AI Operations agent - manages AI service capacity, pipeline throughput, and workload balancing';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Perform AI operations oversight. Steps:\n"
            . "1. Check pipeline status — identify largest backlogs and stalled pipelines\n"
            . "2. Check AI provider capacity — Ollama health, Claude slot usage, GPU utilization\n"
            . "3. Check enrichment job configs — are batch sizes and frequencies optimal?\n"
            . "4. Detect stalled jobs and fix them\n"
            . "5. Check processing rates — are jobs completing successfully?\n"
            . "6. Adjust batch sizes/frequencies if throughput is below expected rates\n"
            . "7. Ensure at least one local Ollama instance is healthy and responsive\n"
            . "8. Summarize findings with capacity utilization and throughput recommendations";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting AI Operations agent...');

        $result = $agentLoop->execute('ai-ops', $task, [
            'notify' => $notify,
            'max_iterations' => 12,
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
