<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class ResearchOpsAgent extends Command
{
    protected $signature = 'research:operations
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Research Operations agent - monitors engine health, circuit breakers, topic scheduling, source credibility, dedup';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Monitor research pipeline health. Steps:\n"
            . "1. Check inter-agent messages for alerts from system-guardian or ai-ops\n"
            . "2. Get engine health — all search engines in fallback chain, active/disabled status\n"
            . "3. Check circuit breaker status — open/closed state for each engine\n"
            . "4. Review topic scheduling — active/inactive counts, overdue topics\n"
            . "5. Check deduplication health — effectiveness across all 4 layers\n"
            . "6. Review result quality — pending/approved/skipped counts, approval rates\n"
            . "7. Check source credibility — trust scores, failure patterns\n"
            . "8. Review cache effectiveness — hit rates, expired entries\n"
            . "9. Check archive coverage — Archive.org preservation stats\n"
            . "10. Reset circuit breakers if underlying issues resolved\n"
            . "11. Run stale topics to catch up scheduling gaps (limit 2)\n"
            . "12. Summarize findings with pipeline status and recommended actions";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting Research Operations agent...');

        $result = $agentLoop->execute('research-ops', $task, [
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
