<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class LogAnalystAgent extends Command
{
    protected $signature = 'log:analyst
                            {--task= : Custom task description for the agent}
                            {--notify : Send Pushover notification on completion}
                            {--dry-run : Show what the agent would do without executing}';

    protected $description = 'Log Analyst agent - parses log files, clusters error signatures, detects bugs and config issues';

    public function handle(AgentLoopService $agentLoop): int
    {
        $customTask = $this->option('task');
        $notify = $this->option('notify');
        $dryRun = $this->option('dry-run');

        $task = $customTask ?: "Analyze production log files for errors and anomalies. Steps:\n"
            . "1. Check inter-agent messages for alerts from system-guardian or ai-ops\n"
            . "2. Scan all log files for activity in the last 2 hours (log_scan_files)\n"
            . "3. Parse errors from each active log file (log_parse_errors)\n"
            . "4. Cluster error signatures to deduplicate (log_cluster_signatures)\n"
            . "5. Check error timeline for trend direction (log_error_timeline)\n"
            . "6. Correlate errors across log files within 30s window (log_correlate_across)\n"
            . "7. Compare current 2h window against 48h baseline (log_compare_baseline)\n"
            . "8. Classify each finding: bug / config_issue / transient / alert_by_design / unknown\n"
            . "9. Save analysis snapshot (log_save_snapshot)\n"
            . "10. Notify on bugs, config issues, or 3x+ spikes — stay silent on transient/alert_by_design\n"
            . "11. Hand off domain-specific issues to specialist agents\n"
            . "12. Summarize with file stats, signature counts, new errors, spikes, and quality assessment";

        if ($dryRun) {
            $this->warn('[DRY RUN]');
            $this->line("Task: {$task}");
            $this->line("Notify: " . ($notify ? 'yes' : 'no'));
            return 0;
        }

        $this->info('Starting Log Analyst agent...');

        $result = $agentLoop->execute('log-analyst', $task, [
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

            // Monitoring pre-screen short-circuits return a success payload
            // with `pre_screened=true` and no `response` key — surface its
            // `result` summary instead so the command doesn't crash on
            // "Undefined array key 'response'" when all log files are clean.
            $this->line("\n--- Agent Response ---");
            if (! empty($result['pre_screened'])) {
                $summary = is_array($result['result'] ?? null)
                    ? json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    : (string) ($result['result'] ?? 'pre-screened clean, no LLM needed');
                $this->line("[PRE-SCREENED] " . $summary);
            } else {
                $this->line((string) ($result['response'] ?? '(no response captured)'));
            }
        } else {
            $this->error("Agent failed: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }

        return 0;
    }
}
