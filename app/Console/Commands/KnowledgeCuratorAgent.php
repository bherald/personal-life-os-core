<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class KnowledgeCuratorAgent extends Command
{
    protected $signature = 'knowledge:curator
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Run the knowledge curator agent to monitor RAG health, RAPTOR coverage, and content pipeline';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Perform scheduled knowledge base health check. Steps:\n"
            . "1. Get RAG statistics (document counts, types, storage)\n"
            . "2. Find documents needing RAPTOR hierarchical summarization\n"
            . "3. Check RAG evaluation quality metrics\n"
            . "4. Verify content extraction service health (Tika, circuit breakers)\n"
            . "5. Check RSS feed health for content pipeline integrity\n"
            . "6. Summarize findings and flag any issues needing attention";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting knowledge curator agent...');

        $result = $agentLoop->execute('knowledge-curator', $task, [
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
