<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AI-1: Cascade Quality Evaluator
 *
 * Scores a local-model response on heuristic signals. If the aggregate score
 * falls below the configured threshold, AIService re-runs the request on the
 * next provider in its fallback chain.
 *
 * Signals (all 0.0–1.0, higher = better quality):
 *   length       — response is long enough to be meaningful
 *   json_validity — response is valid JSON when JSON was expected
 *   refusal      — response does not contain a refusal/capability disclaimer
 *   repetition   — response does not repeat phrases excessively
 *   self_assess  — local model rates its own response (optional, disabled by default)
 */
class CascadeQualityEvaluator
{
    // Refusal phrases that indicate the model declined or couldn't answer
    private const REFUSAL_PATTERNS = [
        "i cannot", "i can't", "i'm unable", "i am unable",
        "as an ai", "as a language model", "i don't have access",
        "i don't have the ability", "i'm not able to", "i am not able to",
        "i apologize, but i", "i'm sorry, but i cannot",
        "that's not something i can", "this is beyond my",
    ];

    /**
     * Evaluate response quality and decide whether to escalate.
     *
     * @param  string $prompt    Original prompt sent to the local model
     * @param  string $response  Response text returned by the local model
     * @param  array  $config    Caller config (cascade key + provider AIService $ai for self-assess)
     * @return array{escalate: bool, score: float, reason: string, signals: array<string,float>}
     */
    public function evaluate(string $prompt, string $response, array $config = []): array
    {
        $cascadeConfig = array_merge(config('cascade', []), $config['cascade'] ?? []);
        $threshold     = (float) ($cascadeConfig['threshold'] ?? $cascadeConfig['default_threshold'] ?? 0.50);
        $weights       = $cascadeConfig['weights'] ?? [];

        $expectsJson = $this->promptExpectsJson($prompt, $config);
        $signals     = $this->collectSignals($prompt, $response, $expectsJson, $cascadeConfig, $config);

        // Redistribute json_validity weight when JSON is not expected
        $activeWeights = $weights;
        if (!$expectsJson && isset($activeWeights['json_validity'])) {
            $freed = $activeWeights['json_validity'];
            unset($activeWeights['json_validity']);
            $remaining = array_filter(array_keys($activeWeights), fn($k) => isset($signals[$k]));
            if (!empty($remaining)) {
                $share = $freed / count($remaining);
                foreach ($remaining as $key) {
                    $activeWeights[$key] = ($activeWeights[$key] ?? 0) + $share;
                }
            }
        }

        // Weighted average over active signals
        $score       = 0.0;
        $totalWeight = 0.0;
        foreach ($signals as $key => $value) {
            $w            = $activeWeights[$key] ?? 0.0;
            $score       += $value * $w;
            $totalWeight += $w;
        }

        $score = $totalWeight > 0 ? round($score / $totalWeight, 4) : 1.0;

        $escalate = $score < $threshold;
        $reason   = $escalate
            ? $this->buildReason($signals, $activeWeights, $threshold)
            : 'quality acceptable';

        Log::debug('CascadeQualityEvaluator: evaluation', [
            'score'     => $score,
            'threshold' => $threshold,
            'escalate'  => $escalate,
            'signals'   => $signals,
            'reason'    => $reason,
        ]);

        return [
            'escalate' => $escalate,
            'score'    => $score,
            'reason'   => $reason,
            'signals'  => $signals,
        ];
    }

    // -------------------------------------------------------------------------
    // Signal collectors
    // -------------------------------------------------------------------------

    private function collectSignals(
        string $prompt,
        string $response,
        bool   $expectsJson,
        array  $cascadeConfig,
        array  $callerConfig
    ): array {
        $signals = [];

        $signals['length']    = $this->scoreLength($response, $cascadeConfig);
        $signals['refusal']   = $this->scoreRefusal($response);
        $signals['repetition'] = $this->scoreRepetition($response);

        if ($expectsJson) {
            $signals['json_validity'] = $this->scoreJsonValidity($response);
        }

        $selfAssessEnabled = $callerConfig['cascade']['self_assess'] ?? $cascadeConfig['self_assess_enabled'] ?? false;
        if ($selfAssessEnabled && isset($callerConfig['_ai_service'])) {
            $sa = $this->scoreSelfAssess($prompt, $response, $callerConfig['_ai_service'], $cascadeConfig);
            if ($sa !== null) {
                $signals['self_assess'] = $sa;
            }
        }

        return $signals;
    }

    /** Score: is the response long enough to be meaningful? */
    private function scoreLength(string $response, array $config): float
    {
        $min = (int) ($config['min_response_length'] ?? 80);
        $len = mb_strlen(trim($response));

        if ($len === 0) {
            return 0.0;
        }

        if ($len >= $min) {
            return 1.0;
        }

        // Partial credit for short-but-non-empty responses
        return round($len / $min, 4);
    }

    /** Score: if JSON was expected, is it valid? */
    private function scoreJsonValidity(string $response): float
    {
        $trimmed = trim($response);

        // Strip markdown fences if present
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-z]*\n?/i', '', $trimmed);
            $trimmed = preg_replace('/\n?```$/', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        json_decode($trimmed);
        return json_last_error() === JSON_ERROR_NONE ? 1.0 : 0.0;
    }

    /** Score: does the response NOT contain a refusal? */
    private function scoreRefusal(string $response): float
    {
        $lower = mb_strtolower($response);
        foreach (self::REFUSAL_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 0.0;
            }
        }
        return 1.0;
    }

    /** Score: is the response free of excessive repetition? */
    private function scoreRepetition(string $response): float
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($response)));
        if (count($words) < 20) {
            return 1.0; // Too short to judge
        }

        // Build trigrams and count duplicates
        $trigrams = [];
        for ($i = 0; $i < count($words) - 2; $i++) {
            $trigram = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            $trigrams[$trigram] = ($trigrams[$trigram] ?? 0) + 1;
        }

        $total     = count($trigrams);
        $repeated  = count(array_filter($trigrams, fn($c) => $c > 2));
        $ratio     = $total > 0 ? $repeated / $total : 0.0;

        // >20% repeated trigrams = likely stuck in a loop
        return $ratio > 0.20 ? max(0.0, round(1.0 - ($ratio * 2), 4)) : 1.0;
    }

    /**
     * Optional: ask the local model to rate its own response.
     * Returns null if the self-assessment call fails or times out.
     */
    private function scoreSelfAssess(
        string     $prompt,
        string     $response,
        AIService  $ai,
        array      $config
    ): ?float {
        $selfPrompt = "Rate the quality of the following AI response to the given task on a scale of 1 to 10. "
            . "Respond with ONLY a single integer from 1 to 10, nothing else.\n\n"
            . "TASK: " . mb_substr($prompt, 0, 300) . "\n\n"
            . "RESPONSE: " . mb_substr($response, 0, 500);

        try {
            $result = $ai->process($selfPrompt, [
                'timeout'        => $config['self_assess_timeout'] ?? 10,
                'max_tokens'     => 5,
                'temperature'    => 0,
                'suppress_alert' => true,
                'use_cache'      => false,
                '_cascade_attempt' => true, // Prevent recursive cascading
            ]);

            if (!($result['success'] ?? false)) {
                return null;
            }

            $rating = (int) trim($result['response'] ?? '');
            if ($rating < 1 || $rating > 10) {
                return null;
            }

            return round(($rating - 1) / 9, 4); // Normalise 1–10 → 0.0–1.0
        } catch (\Throwable $e) {
            Log::warning('CascadeQualityEvaluator: self-assess failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Does the prompt (or caller config) indicate JSON output is expected? */
    private function promptExpectsJson(string $prompt, array $config): bool
    {
        if (!empty($config['json_mode']) || !empty($config['expect_json'])) {
            return true;
        }

        $lower = mb_strtolower($prompt);
        return str_contains($lower, 'respond with json')
            || str_contains($lower, 'return json')
            || str_contains($lower, 'output json')
            || str_contains($lower, 'json format')
            || str_contains($lower, 'as json')
            || str_contains($lower, '```json');
    }

    /** Build a human-readable escalation reason from the worst-scoring signals. */
    private function buildReason(array $signals, array $weights, float $threshold): string
    {
        arsort($weights); // Heaviest signals first
        $parts = [];
        foreach (array_keys($weights) as $key) {
            if (!isset($signals[$key])) {
                continue;
            }
            $score = $signals[$key];
            if ($score < 0.5) {
                $label = match ($key) {
                    'length'       => "response too short ({$score})",
                    'json_validity' => 'invalid JSON output',
                    'refusal'      => 'model refused or disclaimed capability',
                    'repetition'   => "excessive repetition ({$score})",
                    'self_assess'  => "self-assessment low ({$score})",
                    default        => "{$key} low ({$score})",
                };
                $parts[] = $label;
            }
        }

        return empty($parts)
            ? "aggregate score below threshold ({$threshold})"
            : implode('; ', $parts);
    }
}
