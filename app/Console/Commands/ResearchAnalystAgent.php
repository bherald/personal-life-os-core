<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class ResearchAnalystAgent extends Command
{
    protected $signature = 'research:analyst
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Research Analyst agent - reviews pending results, approves/skips by quality score, identifies coverage gaps';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Analyze research content quality and coverage. Steps:\n"
            . "1. Check inter-agent messages for alerts from research-ops or knowledge-curator\n"
            . "2. Get topic coverage — per-topic result counts, quality scores, coverage gaps\n"
            . "3. Check pending results — sorted by AI quality score, assess approval readiness\n"
            . "4. Review research trends — category distribution, weekly volume, fact extraction rates\n"
            . "5. Check overall result quality — approval rates, confidence trends\n"
            . "6. Check source credibility — trust scores affecting result quality\n"
            . "7. Auto-approve high-quality results (score >= 0.7, has findings)\n"
            . "8. Auto-skip low-quality results (score < 0.3, no findings, or duplicates)\n"
            . "9. Analyze ambiguous results (0.3-0.7) — read detail, check knowledge base for novelty\n"
            . "10. Trigger research runs for high-value topics with coverage gaps (limit 2)\n"
            . "11. Escalate ambiguous results and declining quality trends for human review\n"
            . "12. Summarize findings with content status, quality metrics, and actions taken";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting Research Analyst agent...');

        $result = $agentLoop->execute('research-analyst', $task, [
            'notify' => $notify,
            'max_iterations' => 15,
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
