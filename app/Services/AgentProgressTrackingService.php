<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AG-12: Progress Tracking + Mid-Task Replanning — Magentic-One pattern (MS Research)
 *
 * Tracks completion % across iterations and phases, maintains checkpoint
 * state, and detects when replanning is needed (stalled progress,
 * repeated failures, phase regression).
 */
class AgentProgressTrackingService
{
    /** Minimum iterations before stall detection kicks in */
    private const STALL_CHECK_AFTER = 5;

    /** If no new tools used in N iterations, consider stalled */
    private const STALL_THRESHOLD = 3;

    /**
     * Calculate progress metrics for current agent run.
     *
     * @param string $agentId Agent ID
     * @param int $iteration Current iteration
     * @param int $maxIterations Max allowed iterations
     * @param array $toolCalls All tool calls made so far
     * @param array $phaseNames Phase names (if hybrid workflow)
     * @param int $currentPhaseIdx Current phase index
     * @return array Progress metrics
     */
    public function calculateProgress(
        string $agentId,
        int $iteration,
        int $maxIterations,
        array $toolCalls,
        array $phaseNames = [],
        int $currentPhaseIdx = 0
    ): array {
        $iterationPct = $maxIterations > 0
            ? round(($iteration / $maxIterations) * 100, 1)
            : 0;

        $phasePct = !empty($phaseNames)
            ? round((($currentPhaseIdx + 1) / count($phaseNames)) * 100, 1)
            : 100;

        // Weighted: 40% iteration progress + 60% phase progress (phases matter more)
        $overallPct = !empty($phaseNames)
            ? round($iterationPct * 0.4 + $phasePct * 0.6, 1)
            : $iterationPct;

        $uniqueTools = count(array_unique(array_column($toolCalls, 'tool')));
        $successRate = count($toolCalls) > 0
            ? round(count(array_filter($toolCalls, fn($c) => $c['success'] ?? false)) / count($toolCalls) * 100, 1)
            : 0;

        return [
            'iteration' => $iteration,
            'max_iterations' => $maxIterations,
            'iteration_pct' => $iterationPct,
            'phase_pct' => $phasePct,
            'overall_pct' => min(100, $overallPct),
            'unique_tools_used' => $uniqueTools,
            'total_tool_calls' => count($toolCalls),
            'tool_success_rate' => $successRate,
            'current_phase' => $phaseNames[$currentPhaseIdx] ?? null,
            'total_phases' => count($phaseNames),
        ];
    }

    /**
     * Detect if the agent is stalled (not making progress).
     *
     * @param int $iteration Current iteration
     * @param array $toolCalls All tool calls
     * @return array Stall detection result with 'stalled' bool and 'reason'
     */
    public function detectStall(int $iteration, array $toolCalls): array
    {
        if ($iteration < self::STALL_CHECK_AFTER) {
            return ['stalled' => false, 'reason' => null];
        }

        // Check if any new tools used in last N iterations
        $recentCalls = array_filter($toolCalls, fn($c) => ($c['iteration'] ?? 0) > $iteration - self::STALL_THRESHOLD);
        $allCalls = array_filter($toolCalls, fn($c) => ($c['iteration'] ?? 0) <= $iteration - self::STALL_THRESHOLD);

        $recentTools = array_unique(array_column($recentCalls, 'tool'));
        $priorTools = array_unique(array_column($allCalls, 'tool'));

        $newTools = array_diff($recentTools, $priorTools);

        if (empty($recentCalls)) {
            return [
                'stalled' => true,
                'reason' => "No tool calls in last {self::STALL_THRESHOLD} iterations",
            ];
        }

        // Check for repeated failures
        $recentFailures = array_filter($recentCalls, fn($c) => !($c['success'] ?? false));
        if (count($recentFailures) >= self::STALL_THRESHOLD) {
            return [
                'stalled' => true,
                'reason' => 'Consecutive tool failures (' . count($recentFailures) . " in last " . self::STALL_THRESHOLD . " iterations)",
            ];
        }

        // Check for tool thrashing (same tool called 3+ times in a row)
        if (count($recentCalls) >= 3) {
            $lastThree = array_slice(array_column(array_values($recentCalls), 'tool'), -3);
            if (count(array_unique($lastThree)) === 1) {
                return [
                    'stalled' => true,
                    'reason' => "Tool thrashing: '{$lastThree[0]}' called 3+ times consecutively",
                ];
            }
        }

        return ['stalled' => false, 'reason' => null];
    }

    /**
     * Build a replanning prompt when stall is detected.
     *
     * @param array $progress Current progress metrics
     * @param array $stallInfo Stall detection result
     * @param array $availableTools Tools the agent can use
     * @return string Replanning instruction
     */
    public function buildReplanPrompt(array $progress, array $stallInfo, array $availableTools): string
    {
        $usedTools = $progress['unique_tools_used'] ?? 0;
        $totalTools = count($availableTools);
        $unusedTools = array_diff(array_keys($availableTools), []);

        return "PROGRESS CHECK: You are at {$progress['overall_pct']}% completion " .
            "(iteration {$progress['iteration']}/{$progress['max_iterations']}). " .
            "STALL DETECTED: {$stallInfo['reason']}. " .
            "You have used {$usedTools}/{$totalTools} available tools. " .
            "REPLAN: Consider a different approach. Try an unused tool or change your parameters. " .
            "Available tools: " . implode(', ', array_keys($availableTools));
    }

    /**
     * Save checkpoint for potential resume.
     *
     * @param string $sessionId Agent session ID
     * @param array $state Checkpoint state
     */
    public function saveCheckpoint(string $sessionId, array $state): void
    {
        Cache::put(
            "agent_checkpoint:{$sessionId}",
            $state,
            7200 // 2 hours
        );
    }

    /**
     * Load checkpoint if available.
     *
     * @param string $sessionId Agent session ID
     * @return array|null Checkpoint state or null
     */
    public function loadCheckpoint(string $sessionId): ?array
    {
        return Cache::get("agent_checkpoint:{$sessionId}");
    }
}
