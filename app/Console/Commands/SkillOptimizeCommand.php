<?php

namespace App\Console\Commands;

use App\Services\SkillOptimizationService;
use App\Services\SkillLoaderService;
use Illuminate\Console\Command;

class SkillOptimizeCommand extends Command
{
    protected $signature = 'skill:optimize
        {agent? : Agent ID to analyze (omit with --all for all agents)}
        {--analyze : Show analysis only, no proposals}
        {--propose : Generate and submit proposals for review}
        {--all : Run for all agents}
        {--dry-run : Show what would be proposed without submitting}
        {--stats : Show optimization dashboard stats}';

    protected $description = 'Analyze agent skill performance and propose SKILL.md optimizations';

    public function handle(SkillOptimizationService $optimizer, SkillLoaderService $loader): int
    {
        if ($this->option('stats')) {
            return $this->showStats($optimizer);
        }

        $agents = $this->resolveAgents($loader);
        if (empty($agents)) {
            $this->error('No agents specified. Use {agent} argument or --all flag.');
            return 1;
        }

        foreach ($agents as $agentId) {
            $this->line('');
            $this->info("=== {$agentId} ===");

            if ($this->option('propose')) {
                $this->runPropose($optimizer, $agentId);
            } else {
                $this->runAnalyze($optimizer, $agentId);
            }
        }

        return 0;
    }

    private function resolveAgents(SkillLoaderService $loader): array
    {
        if ($this->option('all')) {
            return $loader->listSkills();
        }

        $agent = $this->argument('agent');
        if ($agent) {
            return [$agent];
        }

        return [];
    }

    private function runAnalyze(SkillOptimizationService $optimizer, string $agentId): void
    {
        $result = $optimizer->analyzeAgent($agentId);

        if (!($result['success'] ?? false)) {
            $this->error($result['error'] ?? 'Analysis failed');
            return;
        }

        // Current config
        $config = $result['current_config'];
        $this->line("Mode: {$config['workflow_mode']} | Temp: {$config['temperature']} | Max Iter: {$config['max_iterations']} | Tools: {$config['tools_count']}");

        // Performance profile
        $perf = $result['performance'];
        $this->line('');
        $this->comment("Benchmark runs: {$perf['total_benchmark_runs']}");
        if (!empty($perf['by_mode'])) {
            $headers = ['Mode', 'Runs', 'Accuracy', 'Complete', 'Relevance', 'Avg ms', 'Avg tokens'];
            $rows = [];
            foreach ($perf['by_mode'] as $mode => $data) {
                $rows[] = [$mode, $data['runs'], $data['avg_accuracy'], $data['avg_completeness'], $data['avg_relevance'], $data['avg_duration_ms'], $data['avg_tokens']];
            }
            $this->table($headers, $rows);
        }

        // Tool usage
        $usage = $result['tool_usage'];
        $this->comment("Tool calls (30d): {$usage['total_tool_calls_30d']}");
        if (!empty($usage['unused_tools'])) {
            $this->warn('Unused tools (30d): ' . implode(', ', $usage['unused_tools']));
        }

        // Iteration waste
        $waste = $result['iteration_waste'];
        $this->comment(sprintf(
            'Iterations: avg %.1f / max %d (%.0f%% utilization) | Errors: %d (%.1f%% rate)',
            $waste['avg_iterations_per_session'],
            $waste['max_iterations_configured'],
            $waste['iteration_utilization'] * 100,
            $waste['error_count_30d'],
            $waste['error_rate'] * 100
        ));

        // Failure rates
        $failures = $result['failure_rates'];
        foreach ($failures as $window => $data) {
            if ($data['total_sessions'] > 0) {
                $this->comment(sprintf(
                    'Success rate (%s): %.0f%% (%d/%d sessions)',
                    $window,
                    ($data['success_rate'] ?? 0) * 100,
                    $data['completed'],
                    $data['total_sessions']
                ));
            }
        }

        // Mode recommendation
        $mode = $result['mode_recommendation'];
        if ($mode['sufficient_data'] ?? false) {
            $rec = $mode['overall_recommendation'];
            $conf = $mode['overall_confidence'];
            $this->info(sprintf('Mode recommendation: %s (confidence: %.0f%%)', $rec ?? 'none', $conf * 100));
        } else {
            $this->comment('Mode recommendation: ' . ($mode['message'] ?? 'Insufficient data'));
        }

        // Tool gaps
        $gaps = $result['tool_gaps'];
        if (!empty($gaps['gaps'])) {
            $this->warn(sprintf('Recurring error patterns: %d', $gaps['recurring_error_patterns']));
            foreach ($gaps['gaps'] as $gap) {
                $this->line(sprintf('  [%dx] %s', $gap['count'], implode(', ', $gap['keywords'])));
            }
        }

        // Generate amendment preview
        $amendments = $optimizer->proposeSkillAmendments($agentId);
        $count = $amendments['count'] ?? 0;
        if ($count > 0) {
            $this->line('');
            $this->info("Potential amendments: {$count}");
            foreach ($amendments['amendments'] as $a) {
                $this->line(sprintf('  [%s] %s', $a['type'], $a['reasoning']));
            }
        } else {
            $this->info('No amendments needed.');
        }
    }

    private function runPropose(SkillOptimizationService $optimizer, string $agentId): void
    {
        $dryRun = $this->option('dry-run');

        $result = $optimizer->proposeSkillChanges([
            'target_agent' => $agentId,
            'dry_run' => $dryRun,
        ]);

        if (!($result['success'] ?? false)) {
            $this->error($result['error'] ?? 'Proposal failed');
            return;
        }

        $count = $result['count'] ?? 0;
        if ($count === 0) {
            $this->info($result['message'] ?? 'No amendments needed');
            return;
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Would submit {$count} amendments:");
            foreach ($result['amendments'] as $a) {
                $this->line(sprintf('  [%s] %s -> %s', $a['type'], $a['current_value'] ?? '-', $a['proposed_value'] ?? '-'));
                $this->comment("    {$a['reasoning']}");
            }
        } else {
            $this->info("Submitted {$count} amendments for review:");
            foreach ($result['submitted'] as $s) {
                $status = $s['submitted'] ? 'submitted' : 'FAILED';
                $this->line(sprintf('  [%s] %s (token: %s)', $s['type'], $status, $s['token'] ?? '-'));
            }
        }
    }

    private function showStats(SkillOptimizationService $optimizer): int
    {
        $stats = $optimizer->getOptimizationStats([]);

        $this->info('Skill Optimization Dashboard');
        $this->line(sprintf('Pending: %d | Approved: %d | Rejected: %d',
            $stats['total_pending'],
            $stats['total_approved'],
            $stats['total_rejected']
        ));

        if (!empty($stats['pending_by_agent'])) {
            $this->line('');
            $this->comment('Pending by agent:');
            foreach ($stats['pending_by_agent'] as $p) {
                $this->line("  {$p['agent_id']}: {$p['count']}");
            }
        }

        if (!empty($stats['recent_proposals'])) {
            $this->line('');
            $this->comment('Recent proposals:');
            $headers = ['Agent', 'Title', 'Status', 'Created'];
            $rows = array_map(fn($r) => [$r['agent_id'], substr($r['title'], 0, 50), $r['status'], $r['created_at']], $stats['recent_proposals']);
            $this->table($headers, $rows);
        }

        return 0;
    }
}
