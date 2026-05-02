<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\Log;

/**
 * AG-13: Multi-Persona Critique
 *
 * Replaces single LLM-as-judge with 2-3 critic personas that evaluate
 * agent output from different perspectives. Each scores independently,
 * then results are aggregated via weighted average.
 *
 * Personas:
 *   - Evidence Skeptic: challenges factual claims, checks source quality
 *   - Completeness Auditor: checks for missing angles, gaps in coverage
 *   - Domain Expert: evaluates domain-specific quality (genealogy, research, etc.)
 *
 * Reference: MAR (arXiv:2512.20845) — Multi-Agent Review
 */
class MultiPersonaCritiqueService
{
    use RecursionAware;

    /** @var array Default persona definitions */
    private const PERSONAS = [
        'evidence_skeptic' => [
            'name' => 'Evidence Skeptic',
            'weight' => 0.40,
            'system' => 'You are a rigorous fact-checker. You challenge every claim, check for unsupported assertions, look for logical fallacies, and flag any statement lacking a verifiable source. You are suspicious of vague language and generalizations.',
            'criteria' => ['factual_accuracy', 'source_quality', 'logical_coherence'],
        ],
        'completeness_auditor' => [
            'name' => 'Completeness Auditor',
            'weight' => 0.35,
            'system' => 'You evaluate whether all aspects of the task have been addressed. You look for missing angles, unexplored leads, unanswered questions, and gaps in coverage. You value thoroughness over brevity.',
            'criteria' => ['task_coverage', 'gap_identification', 'thoroughness'],
        ],
        'domain_expert' => [
            'name' => 'Domain Expert',
            'weight' => 0.25,
            'system' => 'You are a domain specialist who evaluates the quality and relevance of the output within its specific field. You assess whether best practices were followed, terminology is correct, and the work meets professional standards.',
            'criteria' => ['domain_accuracy', 'best_practices', 'professional_quality'],
        ],
    ];

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Critique an agent output using multiple personas.
     *
     * @param string $task The original task description
     * @param string $output The agent's output to evaluate
     * @param array $options Options:
     *   - personas: array of persona keys (default: all three)
     *   - domain: string domain context (e.g., 'genealogy', 'research')
     *   - max_tokens: int per-persona max tokens (default: 300)
     * @return array Aggregated critique with per-persona scores
     */
    public function critique(string $task, string $output, array $options = []): array
    {
        // RLM: Try recursive multi-persona critique
        $rlm = $this->tryRecursive('multi_persona_critique', 'quality_gate_retry', ['task' => $task, 'output' => $output, 'options' => $options], function ($ctx) {
            return $this->critique($ctx['task'], $ctx['output'], $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $personaKeys = $options['personas'] ?? array_keys(self::PERSONAS);
        $domain = $options['domain'] ?? 'general';
        $maxTokens = $options['max_tokens'] ?? 300;

        $critiques = [];
        $totalWeight = 0;
        $weightedScore = 0;

        foreach ($personaKeys as $key) {
            $persona = self::PERSONAS[$key] ?? null;
            if (!$persona) {
                continue;
            }

            try {
                $result = $this->runPersonaCritique($persona, $task, $output, $domain, $maxTokens);
                $critiques[$key] = $result;
                $totalWeight += $persona['weight'];
                $weightedScore += $result['overall_score'] * $persona['weight'];
            } catch (\Throwable $e) {
                Log::warning('MultiPersonaCritique: Persona failed', [
                    'persona' => $key,
                    'error' => $e->getMessage(),
                ]);
                $critiques[$key] = [
                    'persona' => $persona['name'],
                    'overall_score' => 0.5,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $aggregateScore = $totalWeight > 0 ? round($weightedScore / $totalWeight, 3) : 0.5;

        // Consensus: do personas agree?
        $scores = array_column(
            array_filter($critiques, fn($c) => !isset($c['error'])),
            'overall_score'
        );
        $consensus = $this->calculateConsensus($scores);

        return [
            'aggregate_score' => $aggregateScore,
            'consensus' => $consensus,
            'persona_count' => count($critiques),
            'critiques' => $critiques,
            'recommendation' => $this->getRecommendation($aggregateScore, $consensus),
        ];
    }

    // =========================================================================
    // Per-persona evaluation
    // =========================================================================

    /**
     * Run a single persona's critique.
     */
    private function runPersonaCritique(
        array $persona,
        string $task,
        string $output,
        string $domain,
        int $maxTokens
    ): array {
        $truncatedOutput = mb_substr($output, 0, 4000);
        $criteriaList = implode(', ', $persona['criteria']);

        $prompt = "{$persona['system']}\n\n"
            . "## Task\n{$task}\n\n"
            . "## Domain\n{$domain}\n\n"
            . "## Agent Output to Evaluate\n{$truncatedOutput}\n\n"
            . "Evaluate this output on these criteria: {$criteriaList}\n"
            . "Score each criterion 1-5 (1=poor, 5=excellent).\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . '{"scores": {"' . implode('": N, "', $persona['criteria']) . '": N}, '
            . '"overall": N, "strengths": "brief", "weaknesses": "brief"}';

        $result = $this->ai->process($prompt, [
            'max_tokens' => $maxTokens,
            'temperature' => 0.1,
            'expect_json' => true,
            'task_type' => 'multi_persona_critique',
            'model_role' => 'fast',
            'suppress_alert' => true,
            'dedup' => false,
        ]);

        if (!($result['success'] ?? false)) {
            throw new \Exception($result['error'] ?? 'AI call failed');
        }

        $parsed = $this->parsePersonaResponse($result['response'] ?? '', $persona['criteria']);

        return [
            'persona' => $persona['name'],
            'criteria_scores' => $parsed['scores'],
            'overall_score' => $parsed['overall'] / 5.0, // Normalize to 0-1
            'strengths' => $parsed['strengths'],
            'weaknesses' => $parsed['weaknesses'],
        ];
    }

    // =========================================================================
    // Response parsing (pure — unit-testable)
    // =========================================================================

    /**
     * Parse a persona's JSON response into structured scores.
     */
    public function parsePersonaResponse(string $response, array $expectedCriteria): array
    {
        $default = [
            'scores' => array_fill_keys($expectedCriteria, 3),
            'overall' => 3.0,
            'strengths' => '',
            'weaknesses' => '',
        ];

        $raw = trim($response);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return $default;
        }

        $scores = $parsed['scores'] ?? [];
        $overall = (float) ($parsed['overall'] ?? 3.0);

        // Clamp scores to 1-5
        foreach ($expectedCriteria as $criterion) {
            $scores[$criterion] = max(1, min(5, (int) ($scores[$criterion] ?? 3)));
        }
        $overall = max(1.0, min(5.0, $overall));

        return [
            'scores' => $scores,
            'overall' => $overall,
            'strengths' => (string) ($parsed['strengths'] ?? ''),
            'weaknesses' => (string) ($parsed['weaknesses'] ?? ''),
        ];
    }

    // =========================================================================
    // Consensus + recommendation (pure — unit-testable)
    // =========================================================================

    /**
     * Calculate consensus level from persona scores.
     *
     * @param float[] $scores Normalized scores (0-1)
     * @return array{level: string, variance: float}
     */
    public function calculateConsensus(array $scores): array
    {
        if (count($scores) < 2) {
            return ['level' => 'single_reviewer', 'variance' => 0.0];
        }

        $mean = array_sum($scores) / count($scores);
        $squaredDiffs = array_map(fn($s) => pow($s - $mean, 2), $scores);
        $variance = round(array_sum($squaredDiffs) / count($scores), 4);

        $level = match (true) {
            $variance <= 0.01 => 'strong_agreement',
            $variance <= 0.04 => 'moderate_agreement',
            $variance <= 0.10 => 'mild_disagreement',
            default => 'strong_disagreement',
        };

        return ['level' => $level, 'variance' => $variance];
    }

    /**
     * Generate a recommendation based on aggregate score and consensus.
     */
    public function getRecommendation(float $score, array $consensus): string
    {
        $level = $consensus['level'] ?? 'unknown';

        if ($score >= 0.8 && $level !== 'strong_disagreement') {
            return 'accept';
        }

        if ($score <= 0.4) {
            return 'reject';
        }

        if ($level === 'strong_disagreement') {
            return 'human_review';
        }

        return 'revise';
    }

    // =========================================================================
    // Persona access (for testing/extension)
    // =========================================================================

    /**
     * Get available persona definitions.
     */
    public function getPersonas(): array
    {
        return self::PERSONAS;
    }
}
