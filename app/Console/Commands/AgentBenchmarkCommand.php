<?php

namespace App\Console\Commands;

use App\Services\AgentBenchmarkService;
use Illuminate\Console\Command;

class AgentBenchmarkCommand extends Command
{
    protected $signature = 'agent:benchmark
        {agent? : Agent ID to benchmark (omit to list available agents)}
        {--task= : Run single task key only (e.g., health_assessment)}
        {--mode= : Run single mode only (agentic, hybrid, deterministic)}
        {--analyze= : Analyze results for a run ID}
        {--score= : Auto-score results for a run ID using LLM}
        {--runs : List all benchmark runs}
        {--model= : Override LLM model for benchmark}
        {--max-iterations=10 : Max iterations per run}';

    protected $description = 'Benchmark agent workflow modes — compare agentic vs hybrid vs deterministic';

    public function handle(AgentBenchmarkService $benchmarkService): int
    {
        // List runs
        if ($this->option('runs')) {
            return $this->listRuns($benchmarkService);
        }

        // Analyze existing results
        if ($this->option('analyze')) {
            return $this->analyzeResults($benchmarkService);
        }

        // Auto-score results
        if ($this->option('score')) {
            return $this->scoreResults($benchmarkService);
        }

        $agentId = $this->argument('agent');

        // No agent specified: list benchmarkable agents
        if (!$agentId) {
            return $this->listAgents($benchmarkService);
        }

        // Run benchmark
        return $this->runBenchmark($benchmarkService, $agentId);
    }

    private function listAgents(AgentBenchmarkService $benchmarkService): int
    {
        $agents = $benchmarkService->getBenchmarkableAgents();

        if (empty($agents)) {
            $this->warn('No benchmarkable agents found (need tool_phases defined in SKILL.md).');
            return self::SUCCESS;
        }

        $this->info('Benchmarkable Agents (have tool_phases for hybrid mode):');
        $this->newLine();

        $rows = [];
        foreach ($agents as $agent) {
            $rows[] = [
                $agent['name'],
                $agent['mode'],
                $agent['tools'],
                $agent['phases'],
                substr($agent['description'], 0, 60),
            ];
        }

        $this->table(['Agent', 'Native Mode', 'Tools', 'Phases', 'Description'], $rows);

        $this->newLine();
        $this->line('Usage: php artisan agent:benchmark <agent-id>');
        $this->line('       php artisan agent:benchmark ai-ops --task=health_assessment --mode=agentic');

        return self::SUCCESS;
    }

    private function runBenchmark(AgentBenchmarkService $benchmarkService, string $agentId): int
    {
        $taskFilter = $this->option('task');
        $modeFilter = $this->option('mode');
        $model = $this->option('model');
        $maxIterations = (int) $this->option('max-iterations');

        $tasks = $benchmarkService->getTestTasks($agentId);

        if ($taskFilter) {
            if (!isset($tasks[$taskFilter])) {
                $this->error("Unknown task key: {$taskFilter}");
                $this->line('Available: ' . implode(', ', array_keys($tasks)));
                return self::FAILURE;
            }
            $tasks = [$taskFilter => $tasks[$taskFilter]];
        }

        $modes = ['agentic', 'hybrid', 'deterministic'];
        if ($modeFilter) {
            if (!in_array($modeFilter, $modes)) {
                $this->error("Invalid mode: {$modeFilter}. Use: agentic, hybrid, deterministic");
                return self::FAILURE;
            }
            $modes = [$modeFilter];
        }

        $totalRuns = count($tasks) * count($modes);
        $this->info("Benchmarking '{$agentId}': {$totalRuns} runs (" . count($tasks) . " tasks × " . count($modes) . " modes)");
        if ($model) {
            $this->line("  Model override: {$model}");
        }
        $this->newLine();

        $options = ['max_iterations' => $maxIterations];
        if ($model) {
            $options['model'] = $model;
        }

        $runId = 'bench_' . \Illuminate\Support\Str::random(12);
        $results = [];
        $current = 0;

        foreach ($tasks as $taskKey => $taskDescription) {
            foreach ($modes as $mode) {
                $current++;
                $this->line("[{$current}/{$totalRuns}] {$taskKey} × {$mode}...");

                try {
                    $result = $benchmarkService->runSingle(
                        $agentId, $taskKey, $taskDescription, $mode, $runId, $options
                    );
                    $results[] = $result;

                    $status = $result['success'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
                    $this->line("  {$status} | tokens={$result['tokens_used']} | {$result['duration_ms']}ms | tools={$result['tool_calls_count']} | iter={$result['iterations']}");
                } catch (\Throwable $e) {
                    $this->error("  EXCEPTION: {$e->getMessage()}");
                    $results[] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        $this->newLine();
        $this->info("Run complete: {$runId}");
        $this->line("  Total: {$totalRuns} | Successes: " . count(array_filter($results, fn($r) => $r['success'] ?? false)));
        $this->newLine();
        $this->line("Analyze: php artisan agent:benchmark --analyze={$runId}");
        $this->line("Score:   php artisan agent:benchmark --score={$runId}");

        return self::SUCCESS;
    }

    private function analyzeResults(AgentBenchmarkService $benchmarkService): int
    {
        $runId = $this->option('analyze');
        $analysis = $benchmarkService->analyze($runId);

        if (empty($analysis['mode_summary'])) {
            $this->warn("No results found for run: {$runId}");
            return self::SUCCESS;
        }

        $this->info("Benchmark Analysis: {$runId}");
        $this->newLine();

        // Mode summary table
        $this->info('Mode Comparison:');
        $rows = [];
        foreach ($analysis['mode_summary'] as $m) {
            $successRate = $m->runs > 0 ? round(($m->successes / $m->runs) * 100) : 0;
            $rows[] = [
                strtoupper($m->workflow_mode),
                $m->runs,
                "{$successRate}%",
                number_format($m->avg_tokens, 0),
                number_format($m->avg_duration_ms, 0) . 'ms',
                number_format($m->avg_iterations, 1),
                number_format($m->avg_tool_calls, 1),
                $m->avg_accuracy ? number_format($m->avg_accuracy, 1) : '-',
                $m->avg_completeness ? number_format($m->avg_completeness, 1) : '-',
                $m->avg_relevance ? number_format($m->avg_relevance, 1) : '-',
            ];
        }
        $this->table(
            ['Mode', 'Runs', 'Success', 'Avg Tokens', 'Avg Duration', 'Avg Iter', 'Avg Tools', 'Accuracy', 'Complete', 'Relevant'],
            $rows
        );

        // Per-task breakdown
        if (!empty($analysis['task_comparison'])) {
            $this->newLine();
            $this->info('Per-Task Breakdown:');

            foreach ($analysis['task_comparison'] as $taskKey => $modes) {
                $this->line("  {$taskKey}:");
                foreach ($modes as $mode => $data) {
                    $status = $data->success ? 'OK' : 'FAIL';
                    $scores = '';
                    if ($data->accuracy_score) {
                        $scores = " | scores={$data->accuracy_score}/{$data->completeness_score}/{$data->relevance_score}";
                    }
                    $this->line("    {$mode}: tokens={$data->tokens_used} | {$data->duration_ms}ms | iter={$data->iterations} | tools={$data->tool_calls_count} | {$status}{$scores}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function scoreResults(AgentBenchmarkService $benchmarkService): int
    {
        $runId = $this->option('score');
        $this->info("Auto-scoring results for: {$runId}");

        $scored = $benchmarkService->autoScore($runId);

        $this->info("Scored {$scored} benchmark results.");
        if ($scored > 0) {
            $this->line("View: php artisan agent:benchmark --analyze={$runId}");
        }

        return self::SUCCESS;
    }

    private function listRuns(AgentBenchmarkService $benchmarkService): int
    {
        $runs = $benchmarkService->getRuns();

        if (empty($runs)) {
            $this->warn('No benchmark runs found.');
            return self::SUCCESS;
        }

        $this->info('Benchmark Runs:');
        $rows = [];
        foreach ($runs as $run) {
            $rows[] = [
                $run->run_id,
                $run->agent_id,
                $run->total_runs,
                $run->successes . '/' . $run->total_runs,
                $run->modes_tested,
                $run->tasks_tested,
                number_format($run->avg_tokens, 0),
                number_format($run->avg_duration_ms, 0) . 'ms',
                $run->started_at,
            ];
        }
        $this->table(
            ['Run ID', 'Agent', 'Runs', 'Success', 'Modes', 'Tasks', 'Avg Tokens', 'Avg Duration', 'Started'],
            $rows
        );

        return self::SUCCESS;
    }
}
