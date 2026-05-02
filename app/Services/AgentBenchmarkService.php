<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agent Benchmark Service
 *
 * Measures agentic vs hybrid vs deterministic workflow modes running identical tasks.
 * Produces quantitative data (tokens, latency, iterations, tool coverage) and
 * qualitative scores (accuracy, completeness, relevance) for mode selection decisions.
 *
 * Design: runs each test task through all 3 modes using the same agent/model,
 * stores results in agent_benchmarks table for cross-mode comparison.
 */
class AgentBenchmarkService
{
    private ?AgentLoopService $agentLoop = null;
    private ?SkillLoaderService $skillLoader = null;

    private function getAgentLoop(): AgentLoopService
    {
        if ($this->agentLoop === null) {
            $this->agentLoop = app(AgentLoopService::class);
        }
        return $this->agentLoop;
    }

    private function getSkillLoader(): SkillLoaderService
    {
        if ($this->skillLoader === null) {
            $this->skillLoader = app(SkillLoaderService::class);
        }
        return $this->skillLoader;
    }

    /**
     * Pre-defined benchmark tasks per agent.
     * Each task: key => description. Tasks should be comparable across modes.
     */
    public function getTestTasks(string $agentId): array
    {
        $commonTasks = [
            'health_assessment' => 'Perform a comprehensive system health assessment. Check all available metrics, identify any issues, and provide a summary report with specific findings and recommendations.',
            'resource_analysis' => 'Analyze current resource utilization and workload distribution. Report on capacity, throughput bottlenecks, and optimization opportunities with specific data.',
            'issue_detection' => 'Scan for and diagnose any active issues, warnings, or degraded services. For each finding, assess severity and suggest remediation steps.',
        ];

        // Agent-specific tasks that leverage each agent's unique toolset
        $agentTasks = [
            'ai-ops' => [
                'health_assessment' => 'Assess AI operations health: check pipeline status, GPU utilization, AI capacity, and processing rates. Report on any bottlenecks or degraded services with specific metrics.',
                'resource_analysis' => 'Analyze AI resource utilization: GPU memory, model load times, queue depths, and enrichment job throughput. Identify optimization opportunities.',
                'issue_detection' => 'Detect AI operations issues: stalled jobs, overloaded queues, failed enrichments, or capacity alerts. Assess severity and recommend fixes.',
            ],
            'system-guardian' => [
                'health_assessment' => 'Perform system health check: infrastructure status, AI services, workflow health, and active alerts. Provide a comprehensive health summary.',
                'resource_analysis' => 'Analyze system resources: queue metrics, AI system load, RSS feed health, and RAG statistics. Identify any resource constraints.',
                'issue_detection' => 'Detect system issues: unhealthy snapshots, failing workflows, active alerts, and degraded services. Prioritize by severity.',
            ],
        ];

        return $agentTasks[$agentId] ?? $commonTasks;
    }

    /**
     * Run a single benchmark: one task × one mode.
     *
     * @param string $agentId Agent to benchmark
     * @param string $taskKey Test task identifier
     * @param string $taskDescription Task prompt
     * @param string $mode Workflow mode to test
     * @param string $runId Groups all modes for comparison
     * @param array $options Extra options (model override, etc.)
     * @return array Benchmark result
     */
    public function runSingle(
        string $agentId,
        string $taskKey,
        string $taskDescription,
        string $mode,
        string $runId,
        array $options = []
    ): array {
        Log::info("AgentBenchmark: Starting", [
            'agent_id' => $agentId,
            'task_key' => $taskKey,
            'mode' => $mode,
            'run_id' => $runId,
        ]);

        // Execute via AgentLoopService with mode override
        $execOptions = [
            'benchmark_mode' => $mode,
            'notify' => false,
            'index_findings' => false, // Don't pollute RAG with benchmark runs
            'max_iterations' => $options['max_iterations'] ?? 10,
        ];

        if (!empty($options['model'])) {
            $execOptions['model'] = $options['model'];
        }

        $result = $this->getAgentLoop()->execute($agentId, $taskDescription, $execOptions);

        // Extract tool names from tool_calls
        $toolNames = [];
        if (!empty($result['tool_calls'])) {
            foreach ($result['tool_calls'] as $tc) {
                $name = $tc['tool'] ?? $tc[0] ?? 'unknown';
                $toolNames[] = $name;
            }
        }

        // Store benchmark result
        $benchmarkData = [
            'run_id' => $runId,
            'agent_id' => $agentId,
            'task_key' => $taskKey,
            'task_description' => $taskDescription,
            'workflow_mode' => $mode,
            'tokens_used' => $result['tokens_used'] ?? 0,
            'duration_ms' => $result['duration_ms'] ?? 0,
            'iterations' => $result['iterations'] ?? 0,
            'tool_calls_count' => count($toolNames),
            'tool_calls_detail' => json_encode(array_count_values($toolNames)),
            'model' => $result['model'] ?? 'unknown',
            'response_summary' => substr($result['response'] ?? '', 0, 1000),
            'success' => $result['success'] ?? false,
            'error_message' => $result['error'] ?? null,
            'metadata' => json_encode([
                'session_id' => $result['session_id'] ?? null,
                'unique_tools' => count(array_unique($toolNames)),
            ]),
        ];

        DB::insert("
            INSERT INTO agent_benchmarks
                (run_id, agent_id, task_key, task_description, workflow_mode,
                 tokens_used, duration_ms, iterations, tool_calls_count, tool_calls_detail,
                 model, response_summary, success, error_message, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $benchmarkData['run_id'],
            $benchmarkData['agent_id'],
            $benchmarkData['task_key'],
            $benchmarkData['task_description'],
            $benchmarkData['workflow_mode'],
            $benchmarkData['tokens_used'],
            $benchmarkData['duration_ms'],
            $benchmarkData['iterations'],
            $benchmarkData['tool_calls_count'],
            $benchmarkData['tool_calls_detail'],
            $benchmarkData['model'],
            $benchmarkData['response_summary'],
            $benchmarkData['success'] ? 1 : 0,
            $benchmarkData['error_message'],
            $benchmarkData['metadata'],
        ]);

        Log::info("AgentBenchmark: Completed", [
            'agent_id' => $agentId,
            'task_key' => $taskKey,
            'mode' => $mode,
            'tokens' => $benchmarkData['tokens_used'],
            'duration_ms' => $benchmarkData['duration_ms'],
            'tool_calls' => $benchmarkData['tool_calls_count'],
            'success' => $benchmarkData['success'],
        ]);

        return $benchmarkData;
    }

    /**
     * Run full benchmark suite: N tasks × 3 modes for an agent.
     *
     * @param string $agentId Agent to benchmark
     * @param array $options Extra options
     * @return array Summary with all results
     */
    public function runSuite(string $agentId, array $options = []): array
    {
        $runId = 'bench_' . Str::random(12);
        $tasks = $this->getTestTasks($agentId);
        $modes = ['agentic', 'hybrid', 'deterministic'];
        $results = [];
        $errors = [];

        foreach ($tasks as $taskKey => $taskDescription) {
            foreach ($modes as $mode) {
                try {
                    $result = $this->runSingle(
                        $agentId, $taskKey, $taskDescription, $mode, $runId, $options
                    );
                    $results[] = $result;
                } catch (\Throwable $e) {
                    Log::error("AgentBenchmark: Run failed", [
                        'agent_id' => $agentId,
                        'task_key' => $taskKey,
                        'mode' => $mode,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = [
                        'task_key' => $taskKey,
                        'mode' => $mode,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'run_id' => $runId,
            'agent_id' => $agentId,
            'total_runs' => count($results),
            'total_errors' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Analyze benchmark results for a run or agent.
     *
     * @param string|null $runId Specific run ID (null = latest)
     * @param string|null $agentId Filter by agent
     * @return array Analysis with per-mode aggregates
     */
    public function analyze(?string $runId = null, ?string $agentId = null): array
    {
        $where = [];
        $params = [];

        if ($runId) {
            $where[] = 'run_id = ?';
            $params[] = $runId;
        }
        if ($agentId) {
            $where[] = 'agent_id = ?';
            $params[] = $agentId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Per-mode aggregates
        $modeStats = DB::select("
            SELECT
                workflow_mode,
                COUNT(*) as runs,
                SUM(success) as successes,
                AVG(tokens_used) as avg_tokens,
                AVG(duration_ms) as avg_duration_ms,
                AVG(iterations) as avg_iterations,
                AVG(tool_calls_count) as avg_tool_calls,
                MIN(tokens_used) as min_tokens,
                MAX(tokens_used) as max_tokens,
                MIN(duration_ms) as min_duration_ms,
                MAX(duration_ms) as max_duration_ms,
                AVG(accuracy_score) as avg_accuracy,
                AVG(completeness_score) as avg_completeness,
                AVG(relevance_score) as avg_relevance
            FROM agent_benchmarks
            {$whereClause}
            GROUP BY workflow_mode
            ORDER BY workflow_mode
        ", $params);

        // Per-task breakdown
        $taskStats = DB::select("
            SELECT
                task_key,
                workflow_mode,
                tokens_used,
                duration_ms,
                iterations,
                tool_calls_count,
                success,
                accuracy_score,
                completeness_score,
                relevance_score,
                model
            FROM agent_benchmarks
            {$whereClause}
            ORDER BY task_key, workflow_mode
        ", $params);

        // Build comparison matrix
        $comparison = [];
        foreach ($taskStats as $row) {
            $comparison[$row->task_key][$row->workflow_mode] = $row;
        }

        return [
            'mode_summary' => $modeStats,
            'task_comparison' => $comparison,
            'run_id' => $runId,
            'agent_id' => $agentId,
        ];
    }

    /**
     * Score benchmark results using LLM evaluation.
     *
     * Reads response_summary for each unscored benchmark and asks LLM to rate
     * accuracy (1-5), completeness (1-5), relevance (1-5).
     *
     * @param string $runId Run to score
     * @return int Number of benchmarks scored
     */
    public function autoScore(string $runId): int
    {
        $unscored = DB::select("
            SELECT id, task_key, task_description, workflow_mode, response_summary
            FROM agent_benchmarks
            WHERE run_id = ? AND accuracy_score IS NULL AND success = 1
        ", [$runId]);

        if (empty($unscored)) {
            return 0;
        }

        $aiService = app(AIService::class);
        $scored = 0;

        foreach ($unscored as $bench) {
            try {
                $prompt = "Rate this agent response on 3 dimensions (1=poor, 5=excellent).\n\n"
                    . "TASK: {$bench->task_description}\n\n"
                    . "MODE: {$bench->workflow_mode}\n\n"
                    . "RESPONSE:\n{$bench->response_summary}\n\n"
                    . "Rate as JSON: {\"accuracy\": N, \"completeness\": N, \"relevance\": N}\n"
                    . "accuracy = factual correctness, specific data cited\n"
                    . "completeness = covers all aspects of the task\n"
                    . "relevance = stays on topic, actionable recommendations\n"
                    . "Respond ONLY with the JSON object.";

                $response = $aiService->process($prompt, [
                    'temperature' => 0.1,
                    'max_tokens' => 100,
                ]);

                $content = $response['content'] ?? $response['response'] ?? '';

                // Extract JSON from response
                if (preg_match('/\{[^}]+\}/', $content, $matches)) {
                    $scores = json_decode($matches[0], true);
                    if ($scores && isset($scores['accuracy'], $scores['completeness'], $scores['relevance'])) {
                        $accuracy = max(1, min(5, (int) $scores['accuracy']));
                        $completeness = max(1, min(5, (int) $scores['completeness']));
                        $relevance = max(1, min(5, (int) $scores['relevance']));

                        DB::update("
                            UPDATE agent_benchmarks
                            SET accuracy_score = ?, completeness_score = ?, relevance_score = ?
                            WHERE id = ?
                        ", [$accuracy, $completeness, $relevance, $bench->id]);

                        $scored++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("AgentBenchmark: Auto-score failed for benchmark {$bench->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $scored;
    }

    /**
     * Get all benchmark runs.
     */
    public function getRuns(int $limit = 20): array
    {
        return DB::select("
            SELECT
                run_id,
                agent_id,
                COUNT(*) as total_runs,
                SUM(success) as successes,
                COUNT(DISTINCT workflow_mode) as modes_tested,
                COUNT(DISTINCT task_key) as tasks_tested,
                MIN(created_at) as started_at,
                MAX(created_at) as finished_at,
                AVG(tokens_used) as avg_tokens,
                AVG(duration_ms) as avg_duration_ms
            FROM agent_benchmarks
            GROUP BY run_id, agent_id
            ORDER BY started_at DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Get supported agents for benchmarking.
     *
     * Returns agents that have tool_phases defined (required for hybrid mode)
     * and enough tools for meaningful comparison.
     */
    public function getBenchmarkableAgents(): array
    {
        $skills = $this->getSkillLoader()->getSkillIndex();
        $agents = [];

        foreach ($skills as $skill) {
            // Must have tool_phases for hybrid mode benchmark
            if (!empty($skill['tool_phases'])) {
                $agents[] = [
                    'name' => $skill['name'],
                    'description' => $skill['description'] ?? '',
                    'mode' => $skill['workflow_mode'] ?? 'agentic',
                    'tools' => count($skill['tools'] ?? []),
                    'phases' => count($skill['tool_phases'] ?? []),
                ];
            }
        }

        return $agents;
    }
}
