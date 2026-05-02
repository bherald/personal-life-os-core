<?php

namespace App\Services;

use App\Contracts\ReviewApprovalHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Skill Optimization Service (S17: Self-Improving Skill Configs)
 *
 * Agents analyze their own execution patterns, propose SKILL.md amendments + new tools.
 * Human reviews all changes. Closed-loop optimization with rollback safety.
 *
 * Data sources: agent_benchmarks, agent_episodes, agent_procedures, skill_versions
 * Output: review_queue entries (skill_optimization type) for human approval
 *
 * No LLM calls — pure data-driven analysis. Only LLM usage occurs when
 * knowledge-curator agent runs optimization tools during its scheduled cycle.
 */
class SkillOptimizationService implements ReviewApprovalHandler
{
    private const MIN_BENCHMARK_SAMPLES = 3;
    private const UNUSED_TOOL_DAYS = 30;
    private const PHASE_IMBALANCE_HIGH = 0.80;
    private const PHASE_IMBALANCE_LOW = 0.05;
    private const ITERATION_WASTE_THRESHOLD = 0.50;
    private const TOOL_GAP_MIN_OCCURRENCES = 3;
    private const SIGNIFICANCE_THRESHOLD = 0.15; // Minimum relative change to submit review
    private const REJECTED_REVIEW_TTL_DAYS = 14; // Auto-delete rejected items after 14 days

    private ?SkillLoaderService $skillLoader = null;
    private ?SkillVersionService $skillVersion = null;
    private ?ToolProposalService $toolProposal = null;

    private function getSkillLoader(): SkillLoaderService
    {
        if ($this->skillLoader === null) {
            $this->skillLoader = app(SkillLoaderService::class);
        }
        return $this->skillLoader;
    }

    private function getSkillVersion(): SkillVersionService
    {
        if ($this->skillVersion === null) {
            $this->skillVersion = app(SkillVersionService::class);
        }
        return $this->skillVersion;
    }

    private function getToolProposal(): ToolProposalService
    {
        if ($this->toolProposal === null) {
            $this->toolProposal = app(ToolProposalService::class);
        }
        return $this->toolProposal;
    }

    // =========================================================================
    // PHASE 1: Core Analysis Engine
    // =========================================================================

    /**
     * Master analysis: calls all sub-analyzers, returns structured report.
     */
    public function analyzeAgent(string $agentId): array
    {
        $skill = $this->getSkillLoader()->loadSkill($agentId);
        if (!$skill) {
            return ['success' => false, 'error' => "Skill not found: {$agentId}"];
        }

        $profile = $this->getPerformanceProfile($agentId);
        $heatmap = $this->getToolUsageHeatmap($agentId);
        $waste = $this->getIterationWaste($agentId);
        $failures = $this->getEpisodeFailureRate($agentId);
        $modeRec = $this->recommendMode($agentId);
        $gaps = $this->analyzeToolGaps($agentId);

        $config = $skill['frontmatter'] ?? [];

        return [
            'success' => true,
            'agent_id' => $agentId,
            'current_config' => [
                'workflow_mode' => $config['workflow_mode'] ?? 'agentic',
                'temperature' => $config['temperature'] ?? 0.7,
                'max_iterations' => $config['max_iterations'] ?? 15,
                'tools_count' => count($config['tools'] ?? []),
                'phases_count' => count($config['tool_phases'] ?? []),
            ],
            'performance' => $profile,
            'tool_usage' => $heatmap,
            'iteration_waste' => $waste,
            'failure_rates' => $failures,
            'mode_recommendation' => $modeRec,
            'tool_gaps' => $gaps,
        ];
    }

    /**
     * Benchmark aggregates: avg scores per mode, score trends, best/worst tasks.
     */
    public function getPerformanceProfile(string $agentId): array
    {
        $benchmarks = DB::select("
            SELECT workflow_mode,
                   COUNT(*) as runs,
                   AVG(accuracy_score) as avg_accuracy,
                   AVG(completeness_score) as avg_completeness,
                   AVG(relevance_score) as avg_relevance,
                   AVG(duration_ms) as avg_duration_ms,
                   AVG(tokens_used) as avg_tokens,
                   AVG(tool_calls_count) as avg_tool_calls
            FROM agent_benchmarks
            WHERE agent_id = ? AND accuracy_score IS NOT NULL
            GROUP BY workflow_mode
        ", [$agentId]);

        $bestWorst = DB::select("
            SELECT task_key, workflow_mode,
                   accuracy_score, completeness_score, relevance_score,
                   duration_ms, tokens_used
            FROM agent_benchmarks
            WHERE agent_id = ? AND accuracy_score IS NOT NULL
            ORDER BY (accuracy_score + completeness_score + relevance_score) DESC
            LIMIT 10
        ", [$agentId]);

        $totalRuns = 0;
        $modeData = [];
        foreach ($benchmarks as $b) {
            $totalRuns += $b->runs;
            $modeData[$b->workflow_mode] = [
                'runs' => (int) $b->runs,
                'avg_accuracy' => round((float) $b->avg_accuracy, 2),
                'avg_completeness' => round((float) $b->avg_completeness, 2),
                'avg_relevance' => round((float) $b->avg_relevance, 2),
                'avg_duration_ms' => (int) $b->avg_duration_ms,
                'avg_tokens' => (int) $b->avg_tokens,
                'avg_tool_calls' => round((float) $b->avg_tool_calls, 1),
            ];
        }

        return [
            'total_benchmark_runs' => $totalRuns,
            'by_mode' => $modeData,
            'task_rankings' => array_map(fn($r) => [
                'task' => $r->task_key,
                'mode' => $r->workflow_mode,
                'score' => (float) $r->accuracy_score + (float) $r->completeness_score + (float) $r->relevance_score,
            ], $bestWorst),
        ];
    }

    /**
     * Tool usage frequency and success rates from episode data.
     */
    public function getToolUsageHeatmap(string $agentId): array
    {
        $usage = DB::select("
            SELECT
                JSON_UNQUOTE(JSON_EXTRACT(details, '$.tool')) as tool_name,
                COUNT(*) as call_count,
                JSON_UNQUOTE(JSON_EXTRACT(details, '$.phase')) as phase,
                MIN(created_at) as first_used,
                MAX(created_at) as last_used
            FROM agent_episodes
            WHERE agent_id = ? AND event_type = 'tool_call'
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY tool_name, phase
            ORDER BY call_count DESC
        ", [$agentId]);

        $tools = [];
        foreach ($usage as $u) {
            $name = $u->tool_name;
            if (!$name) continue;
            if (!isset($tools[$name])) {
                $tools[$name] = [
                    'total_calls' => 0,
                    'phases' => [],
                    'first_used' => $u->first_used,
                    'last_used' => $u->last_used,
                ];
            }
            $tools[$name]['total_calls'] += (int) $u->call_count;
            if ($u->phase) {
                $tools[$name]['phases'][$u->phase] = (int) $u->call_count;
            }
            if ($u->last_used > $tools[$name]['last_used']) {
                $tools[$name]['last_used'] = $u->last_used;
            }
        }

        // Find tools declared in SKILL.md but never used
        $skill = $this->getSkillLoader()->getSkillConfig($agentId);
        $declaredTools = $skill['tools'] ?? [];
        $unusedTools = array_diff($declaredTools, array_keys($tools));

        return [
            'active_tools' => $tools,
            'unused_tools' => array_values($unusedTools),
            'total_tool_calls_30d' => array_sum(array_column(array_values($tools), 'total_calls')),
        ];
    }

    /**
     * Tool calls that produced empty/error results.
     */
    public function getIterationWaste(string $agentId): array
    {
        $total = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM agent_episodes
            WHERE agent_id = ? AND event_type = 'tool_call'
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", [$agentId]);

        $errors = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM agent_episodes
            WHERE agent_id = ? AND event_type = 'error'
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", [$agentId]);

        $sessions = DB::select("
            SELECT session_id,
                   SUM(CASE WHEN event_type = 'tool_call' THEN 1 ELSE 0 END) as tool_calls,
                   SUM(tokens_used) as total_tokens,
                   MAX(CASE WHEN event_type = 'task_completed' THEN 1 ELSE 0 END) as completed
            FROM agent_episodes
            WHERE agent_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND session_id IS NOT NULL
            GROUP BY session_id
        ", [$agentId]);

        $totalCalls = (int) ($total->cnt ?? 0);
        $errorCount = (int) ($errors->cnt ?? 0);

        // Calculate avg iterations per session
        $iterationCounts = array_map(fn($s) => (int) $s->tool_calls, $sessions);
        $completedSessions = array_filter($sessions, fn($s) => (int) $s->completed === 1);

        $skill = $this->getSkillLoader()->getSkillConfig($agentId);
        $maxIter = (int) ($skill['max_iterations'] ?? 15);
        $avgIter = count($iterationCounts) > 0 ? array_sum($iterationCounts) / count($iterationCounts) : 0;

        return [
            'total_tool_calls_30d' => $totalCalls,
            'error_count_30d' => $errorCount,
            'error_rate' => $totalCalls > 0 ? round($errorCount / $totalCalls, 4) : 0,
            'avg_iterations_per_session' => round($avgIter, 1),
            'max_iterations_configured' => $maxIter,
            'iteration_utilization' => $maxIter > 0 ? round($avgIter / $maxIter, 2) : 0,
            'sessions_analyzed' => count($sessions),
            'sessions_completed' => count($completedSessions),
        ];
    }

    /**
     * Session success/failure rates over time windows.
     */
    public function getEpisodeFailureRate(string $agentId): array
    {
        $windows = [
            '24h' => 'INTERVAL 1 DAY',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY',
        ];

        $rates = [];
        foreach ($windows as $label => $interval) {
            $data = DB::selectOne("
                SELECT
                    COUNT(DISTINCT session_id) as total_sessions,
                    COUNT(DISTINCT CASE WHEN event_type = 'task_completed' THEN session_id END) as completed,
                    COUNT(DISTINCT CASE WHEN event_type = 'error' THEN session_id END) as with_errors
                FROM agent_episodes
                WHERE agent_id = ?
                  AND created_at > DATE_SUB(NOW(), {$interval})
                  AND session_id IS NOT NULL
            ", [$agentId]);

            $total = (int) ($data->total_sessions ?? 0);
            $completed = (int) ($data->completed ?? 0);

            $rates[$label] = [
                'total_sessions' => $total,
                'completed' => $completed,
                'success_rate' => $total > 0 ? round($completed / $total, 2) : null,
                'sessions_with_errors' => (int) ($data->with_errors ?? 0),
            ];
        }

        return $rates;
    }

    // =========================================================================
    // PHASE 2: Mode Recommendation
    // =========================================================================

    /**
     * Data-driven mode recommendation per task type based on benchmark results.
     */
    public function recommendMode(string $agentId): array
    {
        $benchmarks = DB::select("
            SELECT task_key, workflow_mode,
                   AVG(accuracy_score) as avg_accuracy,
                   AVG(completeness_score) as avg_completeness,
                   AVG(relevance_score) as avg_relevance,
                   AVG(duration_ms) as avg_duration,
                   COUNT(*) as sample_count
            FROM agent_benchmarks
            WHERE agent_id = ? AND accuracy_score IS NOT NULL
            GROUP BY task_key, workflow_mode
        ", [$agentId]);

        if (empty($benchmarks)) {
            return ['sufficient_data' => false, 'message' => 'No benchmark data available. Run agent:benchmark first.'];
        }

        // Group by task
        $byTask = [];
        foreach ($benchmarks as $b) {
            $byTask[$b->task_key][$b->workflow_mode] = [
                'avg_accuracy' => round((float) $b->avg_accuracy, 2),
                'avg_completeness' => round((float) $b->avg_completeness, 2),
                'avg_relevance' => round((float) $b->avg_relevance, 2),
                'avg_duration' => (int) $b->avg_duration,
                'sample_count' => (int) $b->sample_count,
                'composite_score' => round((float) $b->avg_accuracy + (float) $b->avg_completeness + (float) $b->avg_relevance, 2),
            ];
        }

        $recommendations = [];
        foreach ($byTask as $task => $modes) {
            // Need minimum samples per mode to recommend
            $validModes = array_filter($modes, fn($m) => $m['sample_count'] >= self::MIN_BENCHMARK_SAMPLES);

            if (empty($validModes)) {
                $recommendations[$task] = [
                    'recommended_mode' => null,
                    'confidence' => 0,
                    'reasoning' => 'Insufficient benchmark data (need ' . self::MIN_BENCHMARK_SAMPLES . '+ runs per mode)',
                    'data' => $modes,
                ];
                continue;
            }

            // Find best mode: highest composite score, break ties by speed
            $bestMode = null;
            $bestScore = -1;
            $bestDuration = PHP_INT_MAX;

            foreach ($validModes as $mode => $data) {
                if ($data['composite_score'] > $bestScore ||
                    ($data['composite_score'] == $bestScore && $data['avg_duration'] < $bestDuration)) {
                    $bestMode = $mode;
                    $bestScore = $data['composite_score'];
                    $bestDuration = $data['avg_duration'];
                }
            }

            // Calculate confidence based on sample size and score margin
            $scores = array_column($validModes, 'composite_score');
            $margin = count($scores) > 1 ? $bestScore - max(array_diff($scores, [$bestScore]) ?: [0]) : 0;
            $sampleBoost = min(1.0, array_sum(array_column($validModes, 'sample_count')) / 30);
            $confidence = min(1.0, ($margin / 15) * 0.6 + $sampleBoost * 0.4);

            $reasoning = sprintf(
                '%s scores %.1f/15 (accuracy %.1f, completeness %.1f, relevance %.1f) in %dms',
                $bestMode,
                $bestScore,
                $validModes[$bestMode]['avg_accuracy'],
                $validModes[$bestMode]['avg_completeness'],
                $validModes[$bestMode]['avg_relevance'],
                $bestDuration
            );

            if ($margin > 0 && count($validModes) > 1) {
                $reasoning .= sprintf('. Margin: +%.1f over next best mode', $margin);
            }

            $recommendations[$task] = [
                'recommended_mode' => $bestMode,
                'confidence' => round($confidence, 2),
                'reasoning' => $reasoning,
                'data' => $modes,
            ];
        }

        // Overall recommendation: most frequent best mode
        $modeCounts = array_count_values(
            array_filter(array_column($recommendations, 'recommended_mode'))
        );
        arsort($modeCounts);
        $overallMode = !empty($modeCounts) ? array_key_first($modeCounts) : null;

        return [
            'sufficient_data' => true,
            'per_task' => $recommendations,
            'overall_recommendation' => $overallMode,
            'overall_confidence' => $overallMode
                ? round(array_sum(array_column(
                    array_filter($recommendations, fn($r) => $r['recommended_mode'] === $overallMode),
                    'confidence'
                )) / max(1, $modeCounts[$overallMode]), 2)
                : 0,
        ];
    }

    // =========================================================================
    // PHASE 3: SKILL.md Amendment Proposals
    // =========================================================================

    /**
     * Generate specific SKILL.md change proposals based on analysis data.
     */
    public function proposeSkillAmendments(string $agentId): array
    {
        $skill = $this->getSkillLoader()->loadSkill($agentId);
        if (!$skill) {
            return ['success' => false, 'error' => "Skill not found: {$agentId}"];
        }

        $config = $skill['frontmatter'] ?? [];
        $amendments = [];

        // 1. Tool removal: unused tools
        $heatmap = $this->getToolUsageHeatmap($agentId);
        foreach ($heatmap['unused_tools'] as $unusedTool) {
            $amendments[] = [
                'type' => 'tool_removal',
                'agent_id' => $agentId,
                'current_value' => $unusedTool,
                'proposed_value' => null,
                'reasoning' => "Tool '{$unusedTool}' has 0 calls in last 30 days. Removing reduces prompt size and tool selection noise.",
                'metrics' => ['days_unused' => self::UNUSED_TOOL_DAYS, 'total_tools' => count($config['tools'] ?? [])],
            ];
        }

        // 2. Phase rebalancing
        $phaseAmendments = $this->analyzePhaseBalance($agentId, $config, $heatmap);
        $amendments = array_merge($amendments, $phaseAmendments);

        // 3. Iteration limit optimization
        $waste = $this->getIterationWaste($agentId);
        if ($waste['iteration_utilization'] > 0 && $waste['iteration_utilization'] < self::ITERATION_WASTE_THRESHOLD
            && $waste['sessions_analyzed'] >= 5) {
            $currentMax = $waste['max_iterations_configured'];
            $proposedMax = max(5, (int) ceil($waste['avg_iterations_per_session'] * 1.5));
            if ($proposedMax < $currentMax) {
                $amendments[] = [
                    'type' => 'iteration_limit',
                    'agent_id' => $agentId,
                    'current_value' => $currentMax,
                    'proposed_value' => $proposedMax,
                    'reasoning' => sprintf(
                        'Agent averages %.1f iterations but max is %d (%.0f%% utilization). Reducing to %d saves tokens while providing headroom.',
                        $waste['avg_iterations_per_session'],
                        $currentMax,
                        $waste['iteration_utilization'] * 100,
                        $proposedMax
                    ),
                    'metrics' => $waste,
                ];
            }
        }

        // 4. Temperature tuning
        $failures = $this->getEpisodeFailureRate($agentId);
        $rate7d = $failures['7d']['success_rate'] ?? null;
        $currentTemp = (float) ($config['temperature'] ?? 0.7);
        if ($rate7d !== null && $rate7d < 0.70 && $currentTemp > 0.3
            && ($config['workflow_mode'] ?? 'agentic') !== 'deterministic') {
            $proposedTemp = max(0.1, round($currentTemp - 0.2, 1));
            $amendments[] = [
                'type' => 'temperature',
                'agent_id' => $agentId,
                'current_value' => $currentTemp,
                'proposed_value' => $proposedTemp,
                'reasoning' => sprintf(
                    'Success rate is %.0f%% over 7 days with temperature %.1f. Lowering to %.1f may improve reliability.',
                    $rate7d * 100,
                    $currentTemp,
                    $proposedTemp
                ),
                'metrics' => $failures['7d'],
            ];
        }

        // 5. Mode switch recommendation
        $modeRec = $this->recommendMode($agentId);
        $currentMode = $config['workflow_mode'] ?? 'agentic';
        if (($modeRec['sufficient_data'] ?? false)
            && ($modeRec['overall_recommendation'] ?? null)
            && $modeRec['overall_recommendation'] !== $currentMode
            && $modeRec['overall_confidence'] >= 0.6) {
            $amendments[] = [
                'type' => 'mode_switch',
                'agent_id' => $agentId,
                'current_value' => $currentMode,
                'proposed_value' => $modeRec['overall_recommendation'],
                'reasoning' => sprintf(
                    'Benchmark data recommends %s mode (confidence %.0f%%). Current mode: %s.',
                    $modeRec['overall_recommendation'],
                    $modeRec['overall_confidence'] * 100,
                    $currentMode
                ),
                'metrics' => ['per_task' => $modeRec['per_task']],
            ];
        }

        return [
            'success' => true,
            'agent_id' => $agentId,
            'amendments' => $amendments,
            'count' => count($amendments),
        ];
    }

    /**
     * Analyze phase balance — detect overloaded/underused phases.
     */
    private function analyzePhaseBalance(string $agentId, array $config, array $heatmap): array
    {
        $phases = $config['tool_phases'] ?? [];
        if (count($phases) < 2) {
            return [];
        }

        $activeTools = $heatmap['active_tools'] ?? [];
        $totalCalls = $heatmap['total_tool_calls_30d'] ?? 0;
        if ($totalCalls < 10) {
            return [];
        }

        // Count calls per phase
        $phaseCalls = [];
        foreach ($phases as $phaseName => $phaseTools) {
            $phaseCalls[$phaseName] = 0;
            foreach ($phaseTools as $tool) {
                if (isset($activeTools[$tool]['phases'][$phaseName])) {
                    $phaseCalls[$phaseName] += $activeTools[$tool]['phases'][$phaseName];
                }
            }
        }

        $amendments = [];
        foreach ($phaseCalls as $phase => $calls) {
            $ratio = $calls / $totalCalls;
            if ($ratio > self::PHASE_IMBALANCE_HIGH) {
                $amendments[] = [
                    'type' => 'phase_rebalance',
                    'agent_id' => $agentId,
                    'current_value' => "{$phase}: {$calls}/{$totalCalls} calls (" . round($ratio * 100) . '%)',
                    'proposed_value' => 'Consider splitting heavy phase or moving tools to distribute load',
                    'reasoning' => sprintf(
                        'Phase "%s" accounts for %.0f%% of all tool calls. This may cause phase starvation for other phases.',
                        $phase,
                        $ratio * 100
                    ),
                    'metrics' => ['phase_distribution' => $phaseCalls],
                ];
            }
        }

        return $amendments;
    }

    /**
     * Submit a specific amendment for human review.
     */
    public function submitAmendmentForReview(string $agentId, array $amendment): array
    {
        // AG-23: Significance gate — skip low-impact amendments
        if (!$this->isSignificantAmendment($amendment)) {
            Log::info('SkillOptimization: Amendment below significance threshold, skipping', [
                'agent_id' => $agentId,
                'type' => $amendment['type'],
            ]);
            return ['success' => false, 'skipped' => true, 'reason' => 'below significance threshold'];
        }

        // AG-23: Dedup — skip if identical pending amendment exists
        $existing = DB::selectOne("
            SELECT id FROM agent_review_queue
            WHERE agent_id = ? AND review_type = 'skill_optimization' AND status = 'pending'
              AND title LIKE ?
            LIMIT 1
        ", [$agentId, '%' . $amendment['type'] . '%']);

        if ($existing) {
            return ['success' => false, 'skipped' => true, 'reason' => 'duplicate pending amendment'];
        }

        $token = bin2hex(random_bytes(16));
        $title = sprintf('Skill Optimization: %s for %s', $amendment['type'], $agentId);
        $summary = $amendment['reasoning'];

        $details = [
            'amendment_type' => $amendment['type'],
            'agent_id' => $agentId,
            'current_value' => $amendment['current_value'],
            'proposed_value' => $amendment['proposed_value'],
            'metrics' => $amendment['metrics'] ?? [],
        ];

        try {
            DB::insert("
                INSERT INTO agent_review_queue
                (agent_id, review_type, title, summary, details, confidence, priority, status, token, expires_at, created_at, updated_at)
                VALUES (?, 'skill_optimization', ?, ?, ?, ?, 1, 'pending', ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW())
            ", [
                $agentId,
                substr($title, 0, 500),
                $summary,
                json_encode($details),
                0.8,
                $token,
            ]);

            Log::info('SkillOptimization: Amendment submitted for review', [
                'agent_id' => $agentId,
                'type' => $amendment['type'],
                'token' => $token,
            ]);

            return ['success' => true, 'token' => $token, 'title' => $title];
        } catch (\Throwable $e) {
            Log::error('SkillOptimization: Failed to submit amendment', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * AG-23: Determine if an amendment is significant enough to warrant a review item.
     * Tool removals and mode switches always qualify. Numeric changes need >= 15% relative change.
     */
    private function isSignificantAmendment(array $amendment): bool
    {
        $type = $amendment['type'] ?? '';

        // Tool removal and mode switch are always significant
        if (in_array($type, ['tool_removal', 'mode_switch'])) {
            return true;
        }

        $current = $amendment['current_value'];
        $proposed = $amendment['proposed_value'];

        // Numeric comparison: require >= 15% relative change
        if (is_numeric($current) && is_numeric($proposed) && $current != 0) {
            $relativeChange = abs($proposed - $current) / abs($current);
            return $relativeChange >= self::SIGNIFICANCE_THRESHOLD;
        }

        return true; // Non-numeric changes are significant by default
    }

    /**
     * AG-23: Clean up rejected and expired review items older than TTL.
     * Called from OpsMaintenanceJob.
     */
    public function cleanupStaleReviews(): int
    {
        $deleted = DB::delete("
            DELETE FROM agent_review_queue
            WHERE review_type = 'skill_optimization'
              AND status IN ('rejected', 'expired')
              AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ", [self::REJECTED_REVIEW_TTL_DAYS]);

        if ($deleted > 0) {
            Log::info('SkillOptimization: Cleaned up stale review items', ['deleted' => $deleted]);
        }

        return $deleted;
    }

    /**
     * ReviewApprovalHandler: Apply SKILL.md changes on approval.
     */
    public function onApprove(int $itemId, array $details): array
    {
        $agentId = $details['agent_id'] ?? null;
        $type = $details['amendment_type'] ?? null;
        $proposedValue = $details['proposed_value'] ?? null;

        if (!$agentId || !$type) {
            return ['success' => false, 'error' => 'Missing agent_id or amendment_type in details'];
        }

        $skill = $this->getSkillLoader()->loadSkill($agentId);
        if (!$skill) {
            return ['success' => false, 'error' => "Skill not found: {$agentId}"];
        }

        $config = $skill['frontmatter'] ?? [];
        $body = $skill['body'] ?? '';
        $modified = false;

        switch ($type) {
            case 'tool_removal':
                $toolName = $details['current_value'] ?? null;
                if ($toolName && isset($config['tools']) && is_array($config['tools'])) {
                    $config['tools'] = array_values(array_filter($config['tools'], fn($t) => $t !== $toolName));
                    // Also remove from tool_phases
                    if (isset($config['tool_phases'])) {
                        foreach ($config['tool_phases'] as $phase => $tools) {
                            $config['tool_phases'][$phase] = array_values(array_filter($tools, fn($t) => $t !== $toolName));
                        }
                    }
                    $modified = true;
                }
                break;

            case 'iteration_limit':
                if ($proposedValue !== null) {
                    $config['max_iterations'] = (int) $proposedValue;
                    $modified = true;
                }
                break;

            case 'temperature':
                if ($proposedValue !== null) {
                    $config['temperature'] = (float) $proposedValue;
                    $modified = true;
                }
                break;

            case 'mode_switch':
                if ($proposedValue !== null) {
                    $config['workflow_mode'] = $proposedValue;
                    $modified = true;
                }
                break;

            case 'phase_rebalance':
                // Phase rebalance requires manual intervention — just log approval
                Log::info('SkillOptimization: Phase rebalance approved — requires manual SKILL.md edit', [
                    'agent_id' => $agentId,
                ]);
                return ['success' => true, 'message' => 'Phase rebalance approved. Manual SKILL.md edit required.'];

            default:
                return ['success' => false, 'error' => "Unknown amendment type: {$type}"];
        }

        if (!$modified) {
            return ['success' => false, 'error' => 'No changes to apply'];
        }

        // Rebuild SKILL.md content
        $newContent = $this->buildSkillContent($config, $body);

        // Write to disk
        $skillFile = SkillLoaderService::configuredSkillsBasePath().'/'.$agentId.'/SKILL.md';
        $written = file_put_contents($skillFile, $newContent);

        if ($written === false) {
            return ['success' => false, 'error' => 'Failed to write SKILL.md'];
        }

        // Track version (will be picked up by SkillLoaderService on next load)
        $this->getSkillVersion()->trackVersion($agentId, $config, $body, $newContent);

        Log::info('SkillOptimization: Amendment applied', [
            'agent_id' => $agentId,
            'type' => $type,
            'proposed_value' => $proposedValue,
        ]);

        return ['success' => true, 'message' => "Applied {$type} change to {$agentId}"];
    }

    /**
     * Handle rejection — log for learning.
     */
    public function onReject(int $itemId, array $details): array
    {
        $agentId = $details['agent_id'] ?? 'unknown';
        $type = $details['amendment_type'] ?? 'unknown';

        Log::info('SkillOptimization: Amendment rejected', [
            'agent_id' => $agentId,
            'type' => $type,
            'item_id' => $itemId,
        ]);

        return ['success' => true, 'message' => "Rejection logged for {$agentId} ({$type})"];
    }

    /**
     * Rebuild SKILL.md content from frontmatter config and body text.
     */
    private function buildSkillContent(array $config, string $body): string
    {
        $lines = ['---'];

        // Scalar fields
        $scalarFields = ['name', 'version', 'description', 'model', 'fallback_model', 'temperature', 'schedule', 'notifications', 'workflow_mode', 'max_iterations', 'max_tokens'];
        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $config)) {
                $val = $config[$field];
                if ($val === null) {
                    $lines[] = "{$field}: null";
                } elseif (is_numeric($val) && !is_string($val)) {
                    $lines[] = "{$field}: {$val}";
                } elseif (is_bool($val)) {
                    $lines[] = "{$field}: " . ($val ? 'true' : 'false');
                } else {
                    // Quote strings that contain special chars
                    if (preg_match('/[:#{}[\],&*?|>!%@`]/', (string) $val)) {
                        $lines[] = "{$field}: \"{$val}\"";
                    } else {
                        $lines[] = "{$field}: {$val}";
                    }
                }
            }
        }

        // Permissions (list)
        if (!empty($config['permissions'])) {
            $lines[] = 'permissions:';
            foreach ($config['permissions'] as $perm) {
                $lines[] = "  - {$perm}";
            }
        }

        // Tool phases (nested map)
        if (!empty($config['tool_phases'])) {
            $lines[] = 'tool_phases:';
            foreach ($config['tool_phases'] as $phase => $tools) {
                $lines[] = "  {$phase}:";
                foreach ($tools as $tool) {
                    $lines[] = "    - {$tool}";
                }
            }
        }

        // Tools (list)
        if (!empty($config['tools'])) {
            $lines[] = 'tools:';
            foreach ($config['tools'] as $tool) {
                $lines[] = "  - {$tool}";
            }
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = $body;

        return implode("\n", $lines);
    }

    // =========================================================================
    // PHASE 4: Tool Gap Analysis
    // =========================================================================

    /**
     * Identify recurring failure patterns and cross-reference with available tools.
     */
    public function analyzeToolGaps(string $agentId): array
    {
        // Find error episodes in last 30 days
        $errors = DB::select("
            SELECT summary, details, created_at
            FROM agent_episodes
            WHERE agent_id = ? AND event_type = 'error'
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at DESC
            LIMIT 100
        ", [$agentId]);

        if (empty($errors)) {
            return ['gaps' => [], 'message' => 'No errors in last 30 days'];
        }

        // Extract error keywords
        $patterns = [];
        foreach ($errors as $error) {
            $keywords = $this->extractKeywords($error->summary);
            $key = implode(',', array_slice($keywords, 0, 3));
            if (!$key) continue;

            if (!isset($patterns[$key])) {
                $patterns[$key] = [
                    'keywords' => array_slice($keywords, 0, 3),
                    'count' => 0,
                    'examples' => [],
                ];
            }
            $patterns[$key]['count']++;
            if (count($patterns[$key]['examples']) < 3) {
                $patterns[$key]['examples'][] = substr($error->summary, 0, 200);
            }
        }

        // Filter to recurring patterns (3+ occurrences)
        $gaps = array_filter($patterns, fn($p) => $p['count'] >= self::TOOL_GAP_MIN_OCCURRENCES);

        // Cross-reference: find tools this agent doesn't have but others do
        $skill = $this->getSkillLoader()->getSkillConfig($agentId);
        $agentTools = $skill['tools'] ?? [];

        $otherTools = DB::select("
            SELECT DISTINCT name, description, category
            FROM agent_tool_registry
            WHERE enabled = 1 AND name NOT IN (" . implode(',', array_fill(0, max(1, count($agentTools)), '?')) . ")
            ORDER BY category, name
        ", $agentTools ?: ['__none__']);

        return [
            'gaps' => array_values($gaps),
            'recurring_error_patterns' => count($gaps),
            'total_errors_30d' => count($errors),
            'available_tools_not_assigned' => array_map(fn($t) => [
                'name' => $t->name,
                'description' => $t->description,
                'category' => $t->category,
            ], $otherTools),
        ];
    }

    /**
     * Propose new tools when gap patterns are detected.
     * Uses ToolProposalService for the actual proposal submission.
     */
    public function proposeToolsFromGaps(string $agentId): array
    {
        $gaps = $this->analyzeToolGaps($agentId);
        $gapPatterns = $gaps['gaps'] ?? [];
        $availableTools = $gaps['available_tools_not_assigned'] ?? [];

        if (empty($gapPatterns)) {
            return ['success' => true, 'proposals' => [], 'message' => 'No tool gaps detected'];
        }

        $proposals = [];

        // Check if any available tools match gap keywords
        foreach ($gapPatterns as $gap) {
            foreach ($availableTools as $tool) {
                $toolWords = $this->extractKeywords($tool['name'] . ' ' . $tool['description']);
                $overlap = array_intersect($gap['keywords'], $toolWords);

                if (count($overlap) >= 1) {
                    $proposals[] = [
                        'tool' => $tool['name'],
                        'gap_pattern' => $gap['keywords'],
                        'occurrences' => $gap['count'],
                        'reason' => sprintf(
                            'Tool "%s" may address recurring error pattern (%dx in 30d) matching keywords: %s',
                            $tool['name'],
                            $gap['count'],
                            implode(', ', $gap['keywords'])
                        ),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'proposals' => $proposals,
            'count' => count($proposals),
        ];
    }

    /**
     * Extract meaningful keywords from text (same pattern as AgentProceduralMemoryService).
     */
    private function extractKeywords(string $text): array
    {
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'dare',
            'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'as',
            'into', 'through', 'during', 'before', 'after', 'above', 'below',
            'and', 'but', 'or', 'nor', 'not', 'so', 'yet', 'both', 'either',
            'neither', 'each', 'every', 'all', 'any', 'few', 'more', 'most',
            'other', 'some', 'such', 'no', 'only', 'same', 'than', 'too',
            'very', 'just', 'because', 'this', 'that', 'these', 'those',
            'it', 'its', 'null', 'true', 'false', 'error', 'failed', 'calling'];

        $words = preg_split('/[\s_\-:,.\/()]+/', strtolower($text));
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords));
        return array_values(array_unique($words));
    }

    // =========================================================================
    // PHASE 5: Agent-Facing Tool Methods
    // =========================================================================

    /**
     * Agent tool: analyze skill performance for an agent.
     */
    public function analyzeSkillPerformance(array $params): array
    {
        $agentId = $params['agent_id'] ?? $params['target_agent'] ?? null;
        if (!$agentId) {
            return ['success' => false, 'error' => 'agent_id or target_agent is required'];
        }

        return $this->analyzeAgent($agentId);
    }

    /**
     * Agent tool: run analysis, generate amendments, submit for review.
     */
    public function proposeSkillChanges(array $params): array
    {
        $agentId = $params['agent_id'] ?? $params['target_agent'] ?? null;
        $dryRun = (bool) ($params['dry_run'] ?? false);

        if (!$agentId) {
            return ['success' => false, 'error' => 'agent_id or target_agent is required'];
        }

        $result = $this->proposeSkillAmendments($agentId);
        if (!($result['success'] ?? false)) {
            return $result;
        }

        $amendments = $result['amendments'] ?? [];
        if (empty($amendments)) {
            return ['success' => true, 'message' => "No optimization amendments needed for {$agentId}", 'count' => 0];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'amendments' => $amendments,
                'count' => count($amendments),
                'message' => sprintf('Would submit %d amendments for %s (dry run)', count($amendments), $agentId),
            ];
        }

        // Submit each amendment for review
        $submitted = [];
        foreach ($amendments as $amendment) {
            $submitResult = $this->submitAmendmentForReview($agentId, $amendment);
            $submitted[] = [
                'type' => $amendment['type'],
                'submitted' => $submitResult['success'] ?? false,
                'token' => $submitResult['token'] ?? null,
            ];
        }

        return [
            'success' => true,
            'agent_id' => $agentId,
            'submitted' => $submitted,
            'count' => count($submitted),
            'message' => sprintf('Submitted %d skill optimization proposals for %s', count($submitted), $agentId),
        ];
    }

    /**
     * Agent tool: get optimization stats dashboard.
     */
    public function getOptimizationStats(array $params): array
    {
        $pending = DB::select("
            SELECT agent_id, COUNT(*) as cnt
            FROM agent_review_queue
            WHERE review_type = 'skill_optimization' AND status = 'pending'
              AND (expires_at IS NULL OR expires_at > NOW())
            GROUP BY agent_id
        ");

        $approved = DB::selectOne("
            SELECT COUNT(*) as cnt FROM agent_review_queue
            WHERE review_type = 'skill_optimization' AND status = 'approved'
        ");

        $rejected = DB::selectOne("
            SELECT COUNT(*) as cnt FROM agent_review_queue
            WHERE review_type = 'skill_optimization' AND status = 'rejected'
        ");

        $recent = DB::select("
            SELECT agent_id, title, status, created_at, reviewed_at
            FROM agent_review_queue
            WHERE review_type = 'skill_optimization'
            ORDER BY created_at DESC
            LIMIT 10
        ");

        return [
            'success' => true,
            'pending_by_agent' => array_map(fn($p) => ['agent_id' => $p->agent_id, 'count' => (int) $p->cnt], $pending),
            'total_pending' => array_sum(array_column($pending, 'cnt')),
            'total_approved' => (int) ($approved->cnt ?? 0),
            'total_rejected' => (int) ($rejected->cnt ?? 0),
            'recent_proposals' => array_map(fn($r) => [
                'agent_id' => $r->agent_id,
                'title' => $r->title,
                'status' => $r->status,
                'created_at' => $r->created_at,
                'reviewed_at' => $r->reviewed_at,
            ], $recent),
        ];
    }

    /**
     * Agent tool: list pending skill optimization proposals.
     */
    public function getPendingProposals(array $params): array
    {
        $agentFilter = $params['agent_id'] ?? $params['target_agent'] ?? null;

        $sql = "
            SELECT id, agent_id, title, summary, details, token, created_at, expires_at
            FROM agent_review_queue
            WHERE review_type = 'skill_optimization' AND status = 'pending'
              AND (expires_at IS NULL OR expires_at > NOW())
        ";
        $bindings = [];

        if ($agentFilter) {
            $sql .= " AND agent_id = ?";
            $bindings[] = $agentFilter;
        }

        $sql .= " ORDER BY created_at DESC LIMIT 50";

        $proposals = DB::select($sql, $bindings);

        return [
            'success' => true,
            'count' => count($proposals),
            'proposals' => array_map(fn($p) => [
                'id' => $p->id,
                'agent_id' => $p->agent_id,
                'title' => $p->title,
                'summary' => $p->summary,
                'details' => json_decode($p->details, true),
                'token' => $p->token,
                'created_at' => $p->created_at,
                'expires_at' => $p->expires_at,
            ], $proposals),
        ];
    }
}
