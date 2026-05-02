<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class EmailOperationsAgent extends Command
{
    protected $signature = 'email:operations
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Email Operations agent - monitors email health, bounces, draft queue, follow-ups, and rate limits';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Perform email system health check. Steps:\n"
            . "1. Check inter-agent messages for alerts about email infrastructure\n"
            . "2. Check email service status — Thunderbird MCP connectivity, circuit breaker state\n"
            . "3. Review email analytics — volume trends, classification accuracy, failure rates\n"
            . "4. Check bounce health — hard/soft rates, suppression list growth, pending retries\n"
            . "5. Check rate limits — mailboxes in cooldown, domain throttles, quota utilization\n"
            . "6. Review draft queue — pending drafts by source and age\n"
            . "7. Check follow-ups — overdue follow-ups, process reminders if needed\n"
            . "8. Check for urgent emails flagged by sentiment analysis\n"
            . "9. Summarize findings with system status and recommended actions";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting Email Operations agent...');

        $result = $agentLoop->execute('email-ops', $task, [
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
