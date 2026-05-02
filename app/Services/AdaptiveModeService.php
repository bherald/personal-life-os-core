<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adaptive Mode Selection Service (S20)
 *
 * Automatically selects the optimal workflow_mode (agentic/hybrid/deterministic)
 * per agent+task based on accumulated benchmark data, speculative execution results,
 * and historical outcomes.
 *
 * When an agent's SKILL.md declares `workflow_mode: auto`, AgentLoopService calls
 * selectMode() which classifies the task, scores each mode, and picks the best one.
 * After execution, recordOutcome() stores actual results for continuous learning.
 *
 * Scoring: quality (70%) + speed (25%) + speculative wins (5%)
 * Fallback: agent's declared default mode when insufficient data.
 *
 * Data sources:
 * - agent_benchmarks: scored benchmark runs (primary)
 * - speculative_executions: mode head-to-head winners (supplementary)
 * - adaptive_mode_selections: prior selections + outcomes (learning loop)
 */
class AdaptiveModeService
{
    // Minimum benchmark samples per mode to consider it for selection
    private const MIN_SAMPLES = 2;

    // Scoring weights (must sum to 1.0)
    private const QUALITY_WEIGHT = 0.70;
    private const SPEED_WEIGHT = 0.25;
    private const SPECULATIVE_WEIGHT = 0.05;

    // Override cache key prefix
    private const OVERRIDE_PREFIX = 'adaptive_mode_override:';

    // Default task classification keywords → task_key (fallback when agent has no benchmark data)
    private const DEFAULT_TASK_KEYWORDS = [
        'health_assessment' => ['health', 'status', 'check', 'health check', 'system health', 'assessment', 'monitoring'],
        'resource_analysis' => ['resource', 'capacity', 'utilization', 'throughput', 'bottleneck', 'performance', 'load', 'memory', 'gpu', 'queue'],
        'issue_detection'   => ['issue', 'problem', 'error', 'warning', 'diagnose', 'detect', 'alert', 'degraded', 'failure', 'fix'],
        'data_processing'   => ['process', 'sync', 'index', 'enrich', 'extract', 'ingest', 'import', 'scan', 'catalog', 'pipeline'],
        'research'          => ['research', 'search', 'find', 'investigate', 'analyze', 'discover', 'explore', 'query', 'lookup'],
        'content_creation'  => ['create', 'generate', 'write', 'draft', 'compose', 'summarize', 'report', 'digest'],
        'cleanup'           => ['clean', 'remove', 'delete', 'archive', 'expire', 'prune', 'deduplicate', 'consolidate'],
        'review'            => ['review', 'approve', 'verify', 'validate', 'audit', 'check', 'evaluate', 'assess'],
    ];

    // =========================================================================
    // CORE: Mode Selection
    // =========================================================================

    /**
     * Select the optimal workflow mode for an agent+task.
     *
     * @param string $agentId Agent identifier
     * @param string $task Task description
     * @param array $options Extra options (default_mode for fallback)
     * @return array ['mode', 'confidence', 'reasoning', 'fallback', 'task_key', 'selection_id']
     */
    public function selectMode(string $agentId, string $task, array $options = []): array
    {
        $defaultMode = $options['default_mode'] ?? 'agentic';

        // 1. Check for active override
        $override = $this->getActiveOverride($agentId);
        if ($override) {
            $selectionId = $this->recordSelection(
                $agentId, $options['session_id'] ?? null, $task,
                $override['task_key'] ?? null,
                $override['mode'], 1.0,
                "Manual override active ({$override['remaining']} runs remaining)",
                false
            );
            $this->decrementOverride($agentId);

            return [
                'mode' => $override['mode'],
                'confidence' => 1.0,
                'reasoning' => "Manual override: {$override['mode']} ({$override['remaining']} runs remaining)",
                'fallback' => false,
                'task_key' => $override['task_key'] ?? null,
                'selection_id' => $selectionId,
            ];
        }

        // 2. Classify task
        $taskKey = $this->classifyTask($agentId, $task);

        // 3. Score modes
        $scores = $this->scoreModes($agentId, $taskKey);

        // 4. Pick best or fallback
        if (empty($scores)) {
            $selectionId = $this->recordSelection(
                $agentId, $options['session_id'] ?? null, $task, $taskKey,
                $defaultMode, 0.0, 'No benchmark data available', true, 'no_data'
            );

            Log::info("AdaptiveMode: Fallback (no data)", [
                'agent_id' => $agentId,
                'task_key' => $taskKey,
                'mode' => $defaultMode,
            ]);

            return [
                'mode' => $defaultMode,
                'confidence' => 0.0,
                'reasoning' => "No benchmark data available for {$agentId}. Using default: {$defaultMode}",
                'fallback' => true,
                'task_key' => $taskKey,
                'selection_id' => $selectionId,
            ];
        }

        // Sort by composite score descending
        uasort($scores, fn($a, $b) => $b['composite'] <=> $a['composite']);

        $bestMode = array_key_first($scores);
        $bestData = $scores[$bestMode];

        // Calculate confidence
        $modeScores = array_column($scores, 'composite');
        $runnerUp = count($modeScores) > 1 ? array_values($modeScores)[1] : 0;
        $margin = $bestData['composite'] - $runnerUp;
        $sampleFactor = min(1.0, $bestData['samples'] / 15);
        $marginFactor = $runnerUp > 0 ? $margin / max($bestData['composite'], 0.01) : 0.5;
        $confidence = min(1.0, $sampleFactor * 0.5 + $marginFactor * 0.5);

        $reasoning = sprintf(
            '%s: composite %.3f (quality %.2f, speed %.2f, spec %.2f) | %d samples | confidence %.2f',
            $bestMode,
            $bestData['composite'],
            $bestData['quality'],
            $bestData['speed'],
            $bestData['speculative_boost'],
            $bestData['samples'],
            $confidence
        );

        if (count($scores) > 1) {
            $modes = array_keys($scores);
            $reasoning .= sprintf(' | runner-up: %s (%.3f)', $modes[1], $scores[$modes[1]]['composite']);
        }

        $selectionId = $this->recordSelection(
            $agentId, $options['session_id'] ?? null, $task, $taskKey,
            $bestMode, $confidence, $reasoning, false
        );

        Log::info("AdaptiveMode: Selected", [
            'agent_id' => $agentId,
            'task_key' => $taskKey,
            'mode' => $bestMode,
            'confidence' => round($confidence, 3),
            'scores' => array_map(fn($s) => round($s['composite'], 3), $scores),
        ]);

        return [
            'mode' => $bestMode,
            'confidence' => round($confidence, 3),
            'reasoning' => $reasoning,
            'fallback' => false,
            'task_key' => $taskKey,
            'selection_id' => $selectionId,
        ];
    }

    // =========================================================================
    // TASK CLASSIFICATION
    // =========================================================================

    /**
     * AG-14: Classify a task description into a task_key.
     * Generalized: checks agent benchmarks first, then keyword matching
     * against all known patterns (not just agent-specific ones), then
     * derives a key from the task description as last resort.
     *
     * @return string|null Matched task_key or null
     */
    public function classifyTask(string $agentId, string $taskDescription): ?string
    {
        $taskLower = strtolower($taskDescription);

        // 1. Check if task description matches a known benchmark task_key for this agent
        $knownKeys = DB::select("
            SELECT DISTINCT task_key FROM agent_benchmarks WHERE agent_id = ?
        ", [$agentId]);

        $knownTaskKeys = array_map(fn($r) => $r->task_key, $knownKeys);

        foreach ($knownTaskKeys as $key) {
            if (str_contains($taskLower, str_replace('_', ' ', $key))) {
                return $key;
            }
        }

        // 2. Keyword matching — prefer agent's known keys, but match against all patterns
        $bestMatch = null;
        $bestScore = 0;
        $bestMatchIsKnown = false;

        foreach (self::DEFAULT_TASK_KEYWORDS as $taskKey => $keywords) {
            $matchCount = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($taskLower, $keyword)) {
                    $matchCount++;
                }
            }

            if ($matchCount <= 0) {
                continue;
            }

            $isKnown = in_array($taskKey, $knownTaskKeys);

            // Prefer keys the agent has benchmark data for
            if ($isKnown && !$bestMatchIsKnown) {
                $bestMatch = $taskKey;
                $bestScore = $matchCount;
                $bestMatchIsKnown = true;
            } elseif ($isKnown === $bestMatchIsKnown && $matchCount > $bestScore) {
                $bestMatch = $taskKey;
                $bestScore = $matchCount;
                $bestMatchIsKnown = $isKnown;
            }
        }

        if ($bestMatch) {
            return $bestMatch;
        }

        // 3. Derive task key from first meaningful words in description
        // This ensures new task types get tracked even without predefined keywords
        $words = preg_split('/[\s\-_:]+/', $taskLower);
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'for', 'to', 'in', 'on', 'at', 'of', 'is', 'it', 'run', 'do', 'all', 'with'];
        $meaningful = array_values(array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords)));

        if (count($meaningful) >= 2) {
            return $meaningful[0] . '_' . $meaningful[1];
        } elseif (count($meaningful) === 1) {
            return $meaningful[0] . '_task';
        }

        return null;
    }

    // =========================================================================
    // MODE SCORING
    // =========================================================================

    /**
     * Score each workflow mode based on benchmark data and speculative results.
     *
     * @return array Mode => ['composite', 'quality', 'speed', 'speculative_boost', 'samples']
     */
    public function scoreModes(string $agentId, ?string $taskKey = null): array
    {
        // 1. Get benchmark data
        $where = 'WHERE agent_id = ? AND accuracy_score IS NOT NULL';
        $params = [$agentId];

        if ($taskKey) {
            $where .= ' AND task_key = ?';
            $params[] = $taskKey;
        }

        $benchmarks = DB::select("
            SELECT workflow_mode,
                   AVG(accuracy_score) as avg_accuracy,
                   AVG(completeness_score) as avg_completeness,
                   AVG(relevance_score) as avg_relevance,
                   AVG(duration_ms) as avg_duration,
                   COUNT(*) as sample_count
            FROM agent_benchmarks
            {$where}
            GROUP BY workflow_mode
            HAVING sample_count >= ?
        ", array_merge($params, [self::MIN_SAMPLES]));

        if (empty($benchmarks)) {
            // If task-specific query found nothing but we had a task key, try agent-level
            if ($taskKey) {
                return $this->scoreModes($agentId, null);
            }
            return [];
        }

        // 2. Get speculative wins
        $specWins = $this->getSpeculativeWinRates($agentId);

        // 3. Find max duration for normalization
        $maxDuration = max(array_map(fn($b) => (float) $b->avg_duration, $benchmarks));
        if ($maxDuration <= 0) {
            $maxDuration = 1;
        }

        // 4. Score each mode
        $scores = [];
        foreach ($benchmarks as $b) {
            $mode = $b->workflow_mode;

            // Quality: average of 3 scores normalized to [0,1]
            $quality = ((float) $b->avg_accuracy + (float) $b->avg_completeness + (float) $b->avg_relevance) / 15.0;

            // Speed: faster = better, normalized to [0,1]
            $speed = 1.0 - ((float) $b->avg_duration / $maxDuration);

            // Speculative boost: win rate from head-to-head comparisons
            $specBoost = $specWins[$mode] ?? 0.0;

            $composite = ($quality * self::QUALITY_WEIGHT) + ($speed * self::SPEED_WEIGHT) + ($specBoost * self::SPECULATIVE_WEIGHT);

            $scores[$mode] = [
                'composite' => round($composite, 4),
                'quality' => round($quality, 3),
                'speed' => round($speed, 3),
                'speculative_boost' => round($specBoost, 3),
                'samples' => (int) $b->sample_count,
                'avg_accuracy' => round((float) $b->avg_accuracy, 2),
                'avg_completeness' => round((float) $b->avg_completeness, 2),
                'avg_relevance' => round((float) $b->avg_relevance, 2),
                'avg_duration_ms' => (int) $b->avg_duration,
            ];
        }

        return $scores;
    }

    /**
     * Get speculative execution win rates per mode for an agent.
     *
     * @return array Mode => win_rate [0.0-1.0]
     */
    private function getSpeculativeWinRates(string $agentId): array
    {
        try {
            $wins = DB::select("
                SELECT winning_mode, COUNT(*) as wins
                FROM speculative_executions
                WHERE agent_id = ? AND status = 'completed' AND winning_mode IS NOT NULL
                GROUP BY winning_mode
            ", [$agentId]);

            if (empty($wins)) {
                return [];
            }

            $total = array_sum(array_map(fn($w) => (int) $w->wins, $wins));
            if ($total <= 0) {
                return [];
            }

            $rates = [];
            foreach ($wins as $w) {
                $rates[$w->winning_mode] = (int) $w->wins / $total;
            }

            return $rates;
        } catch (\Throwable $e) {
            Log::debug('AdaptiveModeService: historical win rates query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // =========================================================================
    // OUTCOME TRACKING (Continuous Learning)
    // =========================================================================

    /**
     * Record a mode selection decision.
     *
     * @return int Selection ID
     */
    public function recordSelection(
        string $agentId,
        ?string $sessionId,
        ?string $task,
        ?string $taskKey,
        string $selectedMode,
        float $confidence,
        string $reasoning,
        bool $wasFallback,
        ?string $fallbackReason = null
    ): int {
        try {
            DB::insert("
                INSERT INTO adaptive_mode_selections
                (agent_id, session_id, task_description, task_key, selected_mode, confidence, reasoning, was_fallback, fallback_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $agentId,
                $sessionId,
                $task ? substr($task, 0, 65535) : null,
                $taskKey,
                $selectedMode,
                round($confidence, 3),
                $reasoning,
                $wasFallback ? 1 : 0,
                $fallbackReason,
            ]);

            return (int) DB::selectOne("SELECT LAST_INSERT_ID() as id")->id;
        } catch (\Throwable $e) {
            Log::warning("AdaptiveMode: Failed to record selection", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Record the actual outcome after execution completes.
     */
    public function recordOutcome(
        int $selectionId,
        bool $success,
        int $durationMs,
        int $tokensUsed,
        ?int $accuracy = null,
        ?int $completeness = null,
        ?int $relevance = null
    ): void {
        if ($selectionId <= 0) {
            return;
        }

        try {
            DB::update("
                UPDATE adaptive_mode_selections
                SET outcome_success = ?,
                    outcome_duration_ms = ?,
                    outcome_tokens = ?,
                    outcome_accuracy = ?,
                    outcome_completeness = ?,
                    outcome_relevance = ?
                WHERE id = ?
            ", [
                $success ? 1 : 0,
                $durationMs,
                $tokensUsed,
                $accuracy,
                $completeness,
                $relevance,
                $selectionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("AdaptiveMode: Failed to record outcome", [
                'selection_id' => $selectionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // OVERRIDE SYSTEM
    // =========================================================================

    /**
     * Set a mode override for an agent (expires after N runs).
     */
    public function setOverride(string $agentId, string $mode, int $runs = 5, ?string $reason = null): array
    {
        $validModes = ['agentic', 'hybrid', 'deterministic'];
        if (!in_array($mode, $validModes)) {
            return ['success' => false, 'error' => "Invalid mode. Must be one of: " . implode(', ', $validModes)];
        }

        $runs = max(1, min(20, $runs));

        Cache::put(self::OVERRIDE_PREFIX . $agentId, [
            'mode' => $mode,
            'remaining' => $runs,
            'reason' => $reason,
            'set_at' => now()->toISOString(),
        ], 86400); // 24hr max TTL

        Log::info("AdaptiveMode: Override set", [
            'agent_id' => $agentId,
            'mode' => $mode,
            'runs' => $runs,
            'reason' => $reason,
        ]);

        return ['success' => true, 'mode' => $mode, 'runs' => $runs];
    }

    /**
     * Get active override for an agent.
     */
    private function getActiveOverride(string $agentId): ?array
    {
        $override = Cache::get(self::OVERRIDE_PREFIX . $agentId);
        if (!$override || ($override['remaining'] ?? 0) <= 0) {
            Cache::forget(self::OVERRIDE_PREFIX . $agentId);
            return null;
        }

        return $override;
    }

    /**
     * Decrement override run counter.
     */
    private function decrementOverride(string $agentId): void
    {
        $override = Cache::get(self::OVERRIDE_PREFIX . $agentId);
        if (!$override) {
            return;
        }

        $override['remaining'] = max(0, ($override['remaining'] ?? 0) - 1);
        if ($override['remaining'] <= 0) {
            Cache::forget(self::OVERRIDE_PREFIX . $agentId);
            Log::info("AdaptiveMode: Override expired", ['agent_id' => $agentId]);
        } else {
            Cache::put(self::OVERRIDE_PREFIX . $agentId, $override, 86400);
        }
    }

    // =========================================================================
    // ANALYTICS
    // =========================================================================

    /**
     * Get adaptive mode statistics for an agent.
     */
    public function getStats(?string $agentId = null): array
    {
        $where = $agentId ? 'WHERE agent_id = ?' : '';
        $params = $agentId ? [$agentId] : [];

        // Selection distribution
        $distribution = DB::select("
            SELECT selected_mode, COUNT(*) as selections,
                   SUM(was_fallback) as fallbacks,
                   AVG(confidence) as avg_confidence,
                   SUM(CASE WHEN outcome_success = 1 THEN 1 ELSE 0 END) as successes,
                   SUM(CASE WHEN outcome_success = 0 THEN 1 ELSE 0 END) as failures,
                   SUM(CASE WHEN outcome_success IS NULL THEN 1 ELSE 0 END) as pending
            FROM adaptive_mode_selections
            {$where}
            GROUP BY selected_mode
        ", $params);

        // Outcome quality by mode
        $quality = DB::select("
            SELECT selected_mode,
                   AVG(outcome_accuracy) as avg_accuracy,
                   AVG(outcome_completeness) as avg_completeness,
                   AVG(outcome_relevance) as avg_relevance,
                   AVG(outcome_duration_ms) as avg_duration,
                   AVG(outcome_tokens) as avg_tokens
            FROM adaptive_mode_selections
            {$where}
            " . ($where ? 'AND' : 'WHERE') . " outcome_success IS NOT NULL
            GROUP BY selected_mode
        ", $params);

        // Total count
        $total = DB::selectOne("
            SELECT COUNT(*) as total FROM adaptive_mode_selections {$where}
        ", $params);

        // Prediction accuracy: did adaptive pick match the best-performing mode?
        $accuracy = $this->calculatePredictionAccuracy($agentId);

        return [
            'total_selections' => (int) ($total->total ?? 0),
            'distribution' => $distribution,
            'outcome_quality' => $quality,
            'prediction_accuracy' => $accuracy,
        ];
    }

    /**
     * Calculate how often the adaptive selection was the best mode.
     */
    private function calculatePredictionAccuracy(?string $agentId): array
    {
        $where = $agentId ? 'WHERE agent_id = ?' : '';
        $params = $agentId ? [$agentId] : [];

        $selections = DB::select("
            SELECT id, agent_id, task_key, selected_mode,
                   outcome_accuracy, outcome_completeness, outcome_relevance
            FROM adaptive_mode_selections
            {$where}
            " . ($where ? 'AND' : 'WHERE') . " outcome_accuracy IS NOT NULL
              AND was_fallback = 0
        ", $params);

        if (empty($selections)) {
            return ['total_evaluated' => 0, 'optimal_picks' => 0, 'accuracy_pct' => null];
        }

        $optimal = 0;
        foreach ($selections as $sel) {
            $selectedScore = (int) $sel->outcome_accuracy + (int) $sel->outcome_completeness + (int) $sel->outcome_relevance;

            // Check if any other mode scored higher for this agent+task
            $bestOther = DB::selectOne("
                SELECT MAX(accuracy_score + completeness_score + relevance_score) as best_score
                FROM agent_benchmarks
                WHERE agent_id = ?
                  AND (task_key = ? OR ? IS NULL)
                  AND workflow_mode != ?
                  AND accuracy_score IS NOT NULL
            ", [$sel->agent_id, $sel->task_key, $sel->task_key, $sel->selected_mode]);

            $bestOtherScore = (int) ($bestOther->best_score ?? 0);

            // Adaptive was optimal if its outcome >= best other mode's benchmark
            if ($selectedScore >= $bestOtherScore) {
                $optimal++;
            }
        }

        return [
            'total_evaluated' => count($selections),
            'optimal_picks' => $optimal,
            'accuracy_pct' => count($selections) > 0 ? round($optimal / count($selections) * 100, 1) : null,
        ];
    }

    /**
     * Get recent selection history for an agent.
     */
    public function getSelectionHistory(string $agentId, int $limit = 20): array
    {
        return DB::select("
            SELECT id, agent_id, task_key, selected_mode, confidence,
                   reasoning, was_fallback, fallback_reason,
                   outcome_success, outcome_duration_ms, outcome_tokens,
                   outcome_accuracy, outcome_completeness, outcome_relevance,
                   created_at
            FROM adaptive_mode_selections
            WHERE agent_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ", [$agentId, min(100, max(1, $limit))]);
    }

    // =========================================================================
    // AGENT TOOL HANDLERS
    // =========================================================================

    /**
     * Handle the adaptive_mode_stats agent tool.
     */
    public function adaptiveModeStats(array $params): array
    {
        $agentId = $params['agent_id'] ?? null;
        return ['success' => true, 'stats' => $this->getStats($agentId)];
    }

    /**
     * Handle the adaptive_mode_recommend agent tool.
     */
    public function adaptiveModeRecommend(array $params): array
    {
        $agentId = $params['agent_id'] ?? '';
        if (empty($agentId)) {
            return ['success' => false, 'error' => 'agent_id required'];
        }

        $task = $params['task'] ?? null;
        $taskKey = $task ? $this->classifyTask($agentId, $task) : null;
        $scores = $this->scoreModes($agentId, $taskKey);

        if (empty($scores)) {
            return [
                'success' => true,
                'recommendation' => null,
                'message' => "No benchmark data for {$agentId}. Run agent:benchmark first.",
            ];
        }

        // Sort by composite descending
        uasort($scores, fn($a, $b) => $b['composite'] <=> $a['composite']);
        $bestMode = array_key_first($scores);

        return [
            'success' => true,
            'recommendation' => $bestMode,
            'task_key' => $taskKey,
            'scores' => $scores,
        ];
    }

    /**
     * Handle the adaptive_mode_override agent tool.
     */
    public function adaptiveModeOverride(array $params): array
    {
        $agentId = $params['agent_id'] ?? '';
        $mode = $params['mode'] ?? '';
        $runs = (int) ($params['runs'] ?? 5);
        $reason = $params['reason'] ?? null;

        if (empty($agentId) || empty($mode)) {
            return ['success' => false, 'error' => 'agent_id and mode required'];
        }

        return $this->setOverride($agentId, $mode, $runs, $reason);
    }
}
