<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-5: Memory Retrieval Gating — Memory-R1 pattern
 *
 * Gates memory retrieval on predicted usefulness to avoid injecting
 * irrelevant context that wastes tokens and dilutes agent focus.
 *
 * Decides whether to inject procedural memory, episodic memory,
 * and cross-agent insights based on task type, agent history,
 * and memory freshness.
 */
class AgentMemoryGatingService
{
    /** Minimum episodes needed before gating can make informed decisions */
    private const MIN_HISTORY_FOR_GATING = 5;

    /** Cache TTL for gating decisions (seconds) */
    private const CACHE_TTL = 1800;

    /**
     * Decide which memory types to inject for this agent+task.
     *
     * @param string $agentId Agent ID
     * @param string $task Task description
     * @return array Gating decisions: ['procedural' => bool, 'episodic' => bool, 'cross_agent' => bool]
     */
    public function gate(string $agentId, string $task): array
    {
        $defaults = ['procedural' => true, 'episodic' => true, 'cross_agent' => true];

        if (empty($task)) {
            return $defaults;
        }

        $cacheKey = "memory_gate:{$agentId}:" . md5($task);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $decisions = $this->evaluateGating($agentId, $task);
            Cache::put($cacheKey, $decisions, self::CACHE_TTL);
            return $decisions;
        } catch (\Throwable $e) {
            Log::debug('MemoryGating: evaluation failed, using defaults', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
            return $defaults;
        }
    }

    /**
     * Evaluate gating decisions based on agent history and task characteristics.
     */
    private function evaluateGating(string $agentId, string $task): array
    {
        // Check if agent has enough history for informed gating
        $historyCount = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_episode_summaries WHERE agent_id = ?",
            [$agentId]
        )?->c ?? 0);

        if ($historyCount < self::MIN_HISTORY_FOR_GATING) {
            // Not enough history — inject everything (learning phase)
            return ['procedural' => true, 'episodic' => true, 'cross_agent' => true];
        }

        // Check if procedural memory has been useful (high-success procedures exist)
        $usefulProcedures = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_procedures
             WHERE agent_id = ? AND success_rate >= 0.6 AND times_used >= 2",
            [$agentId]
        )?->c ?? 0);

        // Check if episodic memory has relevant recent entries
        $relevantEpisodes = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_episode_summaries
             WHERE agent_id = ? AND outcome IN ('success', 'partial')
               AND importance >= 0.5
               AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)",
            [$agentId]
        )?->c ?? 0);

        // Check if this agent runs frequently (high-frequency agents benefit less from episodic)
        $recentRuns = (int) (DB::selectOne(
            "SELECT COUNT(*) as c FROM agent_episode_summaries
             WHERE agent_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$agentId]
        )?->c ?? 0);

        // Task keyword heuristics — certain task types benefit more from memory
        $taskLower = strtolower($task);
        $isResearch = str_contains($taskLower, 'research') || str_contains($taskLower, 'investigate');
        $isRoutine = str_contains($taskLower, 'monitor') || str_contains($taskLower, 'check') || str_contains($taskLower, 'scan');

        return [
            // Procedural: useful if agent has successful procedures
            'procedural' => $usefulProcedures > 0,

            // Episodic: useful if relevant recent episodes exist, less useful for high-frequency routine tasks
            'episodic' => $relevantEpisodes > 0 && !($isRoutine && $recentRuns > 10),

            // Cross-agent: useful for research/investigation tasks, skip for routine monitoring
            'cross_agent' => $isResearch || (!$isRoutine && $relevantEpisodes > 0),
        ];
    }

    /**
     * Get gating stats for observability.
     */
    public function getStats(string $agentId): array
    {
        try {
            $procedureCount = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM agent_procedures WHERE agent_id = ? AND success_rate >= 0.6",
                [$agentId]
            )?->c ?? 0);

            $episodeCount = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM agent_episode_summaries
                 WHERE agent_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)",
                [$agentId]
            )?->c ?? 0);

            return [
                'agent_id' => $agentId,
                'useful_procedures' => $procedureCount,
                'recent_episodes' => $episodeCount,
                'min_history_threshold' => self::MIN_HISTORY_FOR_GATING,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
