<?php

namespace App\Console\Commands;

use App\Services\SpeculativeExecutionService;
use Illuminate\Console\Command;

class SpeculativeRunCommand extends Command
{
    protected $signature = 'speculative:run
        {agent? : Agent ID to run speculatively}
        {--task= : Task description for the speculative run}
        {--status= : Check status of a running speculative execution}
        {--stats : Show aggregate statistics}
        {--agent-filter= : Filter stats/history by agent ID}
        {--history : Show recent speculative runs}
        {--cancel= : Cancel a running speculative run}
        {--wait-for= : Block until a specific speculative run completes (by run ID)}
        {--wait : After dispatching, block until the run completes}';

    protected $description = 'Manage speculative execution runs (S19: parallel mode comparison)';

    public function handle(SpeculativeExecutionService $service): int
    {
        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        if ($this->option('history')) {
            return $this->showHistory($service);
        }

        if ($statusId = $this->option('status')) {
            return $this->checkStatus($service, $statusId);
        }

        if ($cancelId = $this->option('cancel')) {
            return $this->cancelRun($service, $cancelId);
        }

        if ($waitId = $this->option('wait-for')) {
            return $this->waitForCompletion($service, $waitId);
        }

        // Run mode: requires agent + task
        $agent = $this->argument('agent');
        $task = $this->option('task');

        if (!$agent) {
            $this->error('Agent ID required. Usage: speculative:run {agent} --task="description"');
            return 1;
        }

        if (!$task) {
            $this->error('Task description required. Usage: speculative:run {agent} --task="description"');
            return 1;
        }

        $this->info("Dispatching speculative execution for {$agent}...");
        $this->line("  Task: {$task}");

        $result = $service->execute($agent, $task, ['trigger_type' => 'manual']);

        if ($result['success'] ?? false) {
            $this->info("Speculative run dispatched!");
            $this->line("  Run ID:   {$result['spec_run_id']}");
            $this->line("  Branch A: {$result['branch_a_mode']}");
            $this->line("  Branch B: {$result['branch_b_mode']}");

            if ($this->option('wait')) {
                $this->line('');
                return $this->waitForCompletion($service, $result['spec_run_id']);
            }

            $this->line('');
            $this->comment("Monitor: php artisan speculative:run --status={$result['spec_run_id']}");
            $this->comment("Wait:    php artisan speculative:run --wait-for={$result['spec_run_id']}");
        } else {
            $this->error("Failed: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }

        return 0;
    }

    private function checkStatus(SpeculativeExecutionService $service, string $specRunId): int
    {
        $result = $service->getResult($specRunId);
        if ($result) {
            return $this->displayResult($result);
        }

        $run = $service->getRun($specRunId);
        if (!$run) {
            $this->error("Speculative run not found: {$specRunId}");
            return 1;
        }

        $this->info("Status: {$run->status}");
        $this->line("  Agent:    {$run->agent_id}");
        $this->line("  Branch A: {$run->branch_a_mode} [{$run->branch_a_status}]");
        $this->line("  Branch B: {$run->branch_b_mode} [{$run->branch_b_status}]");
        $this->line("  Started:  {$run->created_at}");

        return 0;
    }

    private function waitForCompletion(SpeculativeExecutionService $service, string $specRunId): int
    {
        $run = $service->getRun($specRunId);
        if (!$run) {
            $this->error("Speculative run not found: {$specRunId}");
            return 1;
        }

        $this->info("Waiting for {$specRunId} to complete...");
        $this->line("  Agent: {$run->agent_id} | A: {$run->branch_a_mode} | B: {$run->branch_b_mode}");

        $maxWait = 1200; // 20 minutes
        $elapsed = 0;
        $interval = 5;

        while ($elapsed < $maxWait) {
            $result = $service->getResult($specRunId);
            if ($result) {
                $this->line('');
                return $this->displayResult($result);
            }

            // Show progress
            $run = $service->getRun($specRunId);
            if ($run) {
                $this->output->write("\r  [{$elapsed}s] A: {$run->branch_a_status} | B: {$run->branch_b_status} | Status: {$run->status}  ");
            }

            sleep($interval);
            $elapsed += $interval;
        }

        $this->line('');
        $this->error("Timeout after {$maxWait}s. Use --status to check later.");
        return 1;
    }

    private function displayResult(array $result): int
    {
        $status = $result['status'] ?? 'unknown';

        if ($status === 'completed') {
            $this->info("COMPLETED — Winner: {$result['winner']} ({$result['winning_mode']})");
            $this->line("  Quality Uplift: {$result['quality_uplift_pct']}%");
            $this->line("  Total Tokens:   {$result['total_cost_tokens']}");
            $this->line("  Branch A ({$result['branch_a_mode']}): {$result['branch_a_tokens']} tokens, {$result['branch_a_duration_ms']}ms");
            $this->line("  Branch B ({$result['branch_b_mode']}): {$result['branch_b_tokens']} tokens, {$result['branch_b_duration_ms']}ms");
            $this->line("  Reasoning: {$result['reasoning']}");
        } elseif ($status === 'failed') {
            $this->error("FAILED");
            $this->line("  Reason: " . ($result['reasoning'] ?? $result['error'] ?? 'Unknown'));
        } else {
            $this->warn("Status: {$status}");
        }

        return 0;
    }

    private function showStats(SpeculativeExecutionService $service): int
    {
        $agentId = $this->option('agent-filter');
        $stats = $service->getStats($agentId);
        $overall = $stats['overall'];

        $this->info('Speculative Execution Dashboard' . ($agentId ? " ({$agentId})" : ''));
        $this->line("  Total: {$overall->total_runs} | Completed: {$overall->completed} | Failed: {$overall->failed} | Cancelled: {$overall->cancelled} | Active: {$overall->active}");

        if ($overall->completed > 0) {
            $this->line("  Avg Uplift: " . round($overall->avg_uplift ?? 0, 2) . "% | Avg Cost: " . round($overall->avg_cost_tokens ?? 0) . " tokens");
        }

        if (!empty($stats['mode_wins'])) {
            $this->line('');
            $this->comment('Mode Win Rates:');
            $rows = [];
            foreach ($stats['mode_wins'] as $mw) {
                $rows[] = [$mw->winning_mode, $mw->wins, round($mw->avg_uplift ?? 0, 2) . '%'];
            }
            $this->table(['Mode', 'Wins', 'Avg Uplift'], $rows);
        }

        if (!empty($stats['trigger_breakdown'])) {
            $this->line('');
            $this->comment('Trigger Breakdown:');
            $rows = [];
            foreach ($stats['trigger_breakdown'] as $tb) {
                $rows[] = [$tb->trigger_type, $tb->runs, $tb->completed, round($tb->avg_uplift ?? 0, 2) . '%'];
            }
            $this->table(['Trigger', 'Runs', 'Completed', 'Avg Uplift'], $rows);
        }

        if (!empty($stats['disabled_agents'])) {
            $this->line('');
            $this->warn('Auto-Disabled Agents: ' . implode(', ', $stats['disabled_agents']));
        }

        return 0;
    }

    private function showHistory(SpeculativeExecutionService $service): int
    {
        $agentId = $this->option('agent-filter');
        $runs = $service->getHistory(20, $agentId);

        if (empty($runs)) {
            $this->comment('No speculative runs found.');
            return 0;
        }

        $this->info('Recent Speculative Runs' . ($agentId ? " ({$agentId})" : '') . ':');

        $rows = [];
        foreach ($runs as $r) {
            $winner = $r->winner ? "{$r->winner} ({$r->winning_mode})" : '-';
            $uplift = $r->quality_uplift_pct !== null ? round($r->quality_uplift_pct, 1) . '%' : '-';
            $duration = $r->branch_a_duration_ms + $r->branch_b_duration_ms;
            $rows[] = [
                $r->spec_run_id,
                $r->agent_id,
                "{$r->branch_a_mode}/{$r->branch_b_mode}",
                $r->status,
                $winner,
                $uplift,
                $r->total_cost_tokens ?? '-',
                $duration ? round($duration / 1000, 1) . 's' : '-',
                $r->trigger_type,
            ];
        }

        $this->table(
            ['Run ID', 'Agent', 'Modes', 'Status', 'Winner', 'Uplift', 'Tokens', 'Duration', 'Trigger'],
            $rows
        );

        return 0;
    }

    private function cancelRun(SpeculativeExecutionService $service, string $specRunId): int
    {
        if ($service->cancel($specRunId)) {
            $this->info("Speculative run {$specRunId} cancelled.");
            return 0;
        } else {
            $this->error("Could not cancel {$specRunId} (not found or not running).");
            return 1;
        }
    }
}
