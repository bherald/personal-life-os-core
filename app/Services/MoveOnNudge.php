<?php

namespace App\Services;

use App\DTOs\NudgeDecision;
use App\DTOs\RecursionState;
use Illuminate\Support\Facades\Log;

/**
 * RLM: Overthinking prevention — evaluates whether recursion should continue.
 *
 * 7 triggers (any one fires = unwind):
 *   1. Novelty decay — sub-call returns < threshold new info
 *   2. Time budget — wall-clock > configured cap
 *   3. Token budget — total tokens exceed cap
 *   4. Cost budget — total USD exceed cap
 *   5. Depth limit — current depth >= max_depth
 *   6. Repetition — cosine similarity > 0.90 between consecutive outputs
 *   7. Stall — 2+ consecutive empty/error sub-calls
 */
class MoveOnNudge
{
    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Main evaluation. Returns decision with reason.
     */
    public function evaluate(RecursionState $state, array $config = []): NudgeDecision
    {
        $isHard = ($config['move_on_mode'] ?? config('recursion.default_move_on_mode', 'graceful')) === 'hard';

        // Trigger 5: Depth limit
        $maxDepth = $state->maxDepth;
        if ($state->currentDepth >= $maxDepth) {
            return NudgeDecision::unwind(
                'depth_limit',
                "Depth {$state->currentDepth} >= max {$maxDepth}",
                $isHard
            );
        }

        // Trigger 2: Time budget
        $elapsed = microtime(true) - $state->startTime;
        $maxTime = $state->budget->maxTimeSeconds;
        $reservePct = config('recursion.time_budget_reserve_pct', 0.30);
        if ($elapsed >= $maxTime * (1 - $reservePct)) {
            return NudgeDecision::unwind(
                'time_budget',
                sprintf("Elapsed %.1fs >= %.0f%% of %.1fs budget", $elapsed, (1 - $reservePct) * 100, $maxTime),
                $isHard
            );
        }

        // Trigger 3: Token budget
        if ($state->budget->tokensUsed >= $state->budget->maxTokens) {
            return NudgeDecision::unwind(
                'token_budget',
                "Used {$state->budget->tokensUsed} >= max {$state->budget->maxTokens}",
                $isHard
            );
        }

        // Trigger 4: Cost budget
        if ($state->budget->costUsed >= $state->budget->maxCostUsd) {
            return NudgeDecision::unwind(
                'cost_budget',
                sprintf("Cost \$%.4f >= max \$%.4f", $state->budget->costUsed, $state->budget->maxCostUsd),
                $isHard
            );
        }

        // Trigger 7: Stall detection (before novelty — stalls have no output to score)
        $maxStalls = (int) ($config['max_consecutive_stalls'] ?? config('recursion.default_max_consecutive_stalls', 2));
        if ($this->isStalled($state->subCallOutputs, $maxStalls)) {
            return NudgeDecision::unwind(
                'stall',
                "{$maxStalls} consecutive empty/error sub-calls",
                $isHard
            );
        }

        // Trigger 1: Novelty decay (requires at least 1 output)
        if (count($state->noveltyScores) > 0) {
            $noveltyThreshold = (float) ($config['novelty_threshold'] ?? config('recursion.default_novelty_threshold', 0.15));
            $decayWindow = (int) ($config['decay_window'] ?? config('recursion.default_decay_window', 3));
            $recentScores = array_slice($state->noveltyScores, -$decayWindow);

            if (count($recentScores) > 0) {
                $avgNovelty = array_sum($recentScores) / count($recentScores);
                if ($avgNovelty < $noveltyThreshold) {
                    return NudgeDecision::unwind(
                        'novelty_decay',
                        sprintf("Avg novelty %.4f < threshold %.4f (window %d)", $avgNovelty, $noveltyThreshold, $decayWindow),
                        $isHard
                    );
                }
            }
        }

        // Trigger 6: Repetition detection (requires at least 2 outputs)
        if (count($state->subCallOutputs) >= 2) {
            $repThreshold = (float) ($config['repetition_threshold'] ?? config('recursion.default_repetition_threshold', 0.90));
            $lastOutput = end($state->subCallOutputs);
            $prevOutput = $state->subCallOutputs[count($state->subCallOutputs) - 2];

            if (is_string($lastOutput) && is_string($prevOutput) && $lastOutput !== '' && $prevOutput !== '') {
                if ($this->isRepeating($lastOutput, $prevOutput, $repThreshold)) {
                    return NudgeDecision::unwind(
                        'repetition',
                        "Consecutive outputs similarity > {$repThreshold}",
                        $isHard
                    );
                }
            }
        }

        return NudgeDecision::continue();
    }

    /**
     * Compute novelty score: how much new info does this output add?
     * Uses AIService embedding (768d, local Ollama) — negligible overhead.
     * Returns 0.0 (pure repetition) to 1.0 (completely new).
     */
    public function computeNovelty(string $output, array $priorOutputs): float
    {
        if (empty($priorOutputs) || trim($output) === '') {
            return 1.0; // First sub-call or empty = max novelty
        }

        // Mean-pool prior outputs into single comparison string
        $accumulated = implode("\n", array_filter($priorOutputs, 'is_string'));
        if (trim($accumulated) === '') {
            return 1.0;
        }

        try {
            $outputEmbed = $this->getEmbedding($output);
            $accumulatedEmbed = $this->getEmbedding($accumulated);

            if ($outputEmbed === null || $accumulatedEmbed === null) {
                return 0.5; // Can't compute — assume moderate novelty
            }

            $similarity = $this->cosineSimilarity($outputEmbed, $accumulatedEmbed);

            return max(0.0, min(1.0, 1.0 - $similarity));
        } catch (\Throwable $e) {
            Log::debug('MoveOnNudge: novelty computation failed', ['error' => $e->getMessage()]);
            return 0.5; // Fail-open — don't trigger unwind on embedding failure
        }
    }

    /**
     * Detect repetition: cosine similarity between consecutive outputs.
     */
    public function isRepeating(string $currentOutput, string $previousOutput, float $threshold = 0.90): bool
    {
        if (trim($currentOutput) === '' || trim($previousOutput) === '') {
            return false;
        }

        try {
            $currentEmbed = $this->getEmbedding($currentOutput);
            $previousEmbed = $this->getEmbedding($previousOutput);

            if ($currentEmbed === null || $previousEmbed === null) {
                return false; // Can't compute — assume not repeating
            }

            return $this->cosineSimilarity($currentEmbed, $previousEmbed) > $threshold;
        } catch (\Throwable $e) {
            Log::debug('MoveOnNudge: repetition check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Detect stall: sub-call returned empty or error.
     */
    public function isStalled(array $recentResults, int $maxConsecutiveStalls = 2): bool
    {
        if (count($recentResults) < $maxConsecutiveStalls) {
            return false;
        }

        $tail = array_slice($recentResults, -$maxConsecutiveStalls);

        foreach ($tail as $result) {
            if (is_string($result) && trim($result) !== '') {
                return false; // At least one non-empty result in the window
            }
            if (is_array($result) && !empty($result)) {
                return false;
            }
        }

        return true; // All results in window were empty/null
    }

    /**
     * Get embedding via AIService (768d, local Ollama preferred).
     */
    private function getEmbedding(string $text): ?array
    {
        // Truncate to avoid oversized embedding calls
        $text = mb_substr($text, 0, 2000);

        $result = $this->ai->generateEmbedding($text);

        if (!($result['success'] ?? false) || empty($result['embedding'])) {
            return null;
        }

        return $result['embedding'];
    }

    /**
     * Cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0 ? $dotProduct / $denominator : 0.0;
    }
}
