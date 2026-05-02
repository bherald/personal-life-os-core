<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\Log;

/**
 * AG-20: Multi-Agent Debate — run same task through 2-3 agents with
 * different system prompts/approaches, then synthesize the best answer.
 *
 * Unlike AG-13 (multi-persona critique which evaluates one output),
 * this spawns actual parallel reasoning chains and synthesizes.
 *
 * Debate modes:
 * - adversarial: One argues for, one against, judge synthesizes
 * - diverse: Each uses different approach (conservative/creative/analytical)
 * - consensus: All reason independently, vote on best answer
 */
class MultiAgentDebateService
{
    use RecursionAware;

    private AIService $ai;

    /** Default debater perspectives */
    private const PERSPECTIVES = [
        'conservative' => [
            'name' => 'Conservative Analyst',
            'system' => 'You favor established facts, proven methods, and cautious conclusions. Avoid speculation. Cite evidence for every claim. When uncertain, say so explicitly.',
        ],
        'creative' => [
            'name' => 'Creative Explorer',
            'system' => 'You explore unconventional angles, make connections across domains, and consider unlikely possibilities. Think laterally. Propose hypotheses even with limited evidence.',
        ],
        'analytical' => [
            'name' => 'Systematic Analyst',
            'system' => 'You break problems into components, evaluate each systematically, and build structured arguments. Use frameworks, checklists, and decision matrices.',
        ],
    ];

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Run a multi-agent debate on a task.
     *
     * @param string $task Task/question to debate
     * @param array $options Options:
     *   - mode: 'diverse' (default), 'adversarial', 'consensus'
     *   - perspectives: array of perspective keys (default: all three)
     *   - context: additional context for all debaters
     *   - max_tokens: per-debater max tokens (default: 500)
     * @return array Synthesized result with per-perspective outputs
     */
    public function debate(string $task, array $options = []): array
    {
        // RLM: Try recursive multi-agent debate
        $rlm = $this->tryRecursive('multi_agent_debate', 'quality_gate_retry', ['task' => $task, 'options' => $options], function ($ctx) {
            return $this->debate($ctx['task'], $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $mode = $options['mode'] ?? 'diverse';
        $perspectiveKeys = $options['perspectives'] ?? array_keys(self::PERSPECTIVES);
        $context = $options['context'] ?? '';
        $maxTokens = $options['max_tokens'] ?? 500;

        $startTime = microtime(true);
        $debaterOutputs = [];

        // Phase 1: Each perspective reasons independently
        foreach ($perspectiveKeys as $key) {
            $perspective = self::PERSPECTIVES[$key] ?? null;
            if (!$perspective) continue;

            $systemPrompt = $perspective['system'];
            if ($context) {
                $systemPrompt .= "\n\nContext: {$context}";
            }

            $prompt = $mode === 'adversarial' && $key === 'creative'
                ? "Challenge the conventional view on this: {$task}"
                : $task;

            try {
                $result = $this->ai->process($prompt, [
                    'system_prompt' => $systemPrompt,
                    'max_tokens' => $maxTokens,
                    'temperature' => $key === 'creative' ? 0.8 : 0.3,
                    'skip_if_busy' => true,
                ]);

                $debaterOutputs[$key] = [
                    'name' => $perspective['name'],
                    'response' => $result['response'] ?? '',
                    'provider' => $result['provider'] ?? 'unknown',
                    'success' => $result['success'] ?? false,
                ];
            } catch (\Throwable $e) {
                $debaterOutputs[$key] = [
                    'name' => $perspective['name'],
                    'response' => '',
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        $successfulOutputs = array_filter($debaterOutputs, fn($o) => $o['success'] && !empty($o['response']));

        if (count($successfulOutputs) < 2) {
            // Not enough perspectives for meaningful synthesis — return best single output
            $best = reset($successfulOutputs) ?: reset($debaterOutputs);
            return [
                'success' => !empty($best['response']),
                'synthesis' => $best['response'] ?? 'Debate failed — insufficient perspectives.',
                'perspectives' => $debaterOutputs,
                'mode' => $mode,
                'debaters_succeeded' => count($successfulOutputs),
                'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
            ];
        }

        // Phase 2: Synthesize — judge picks best elements from each perspective
        $synthesisPrompt = $this->buildSynthesisPrompt($task, $successfulOutputs, $mode);

        try {
            $synthesisResult = $this->ai->process($synthesisPrompt, [
                'max_tokens' => $maxTokens * 2,
                'temperature' => 0.3,
                'model_role' => 'quality', // Use best available model for synthesis
            ]);

            $synthesis = $synthesisResult['response'] ?? '';
        } catch (\Throwable $e) {
            // Fallback: concatenate perspectives
            $synthesis = "Multiple perspectives analyzed:\n\n";
            foreach ($successfulOutputs as $key => $output) {
                $synthesis .= "**{$output['name']}**: {$output['response']}\n\n";
            }
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        Log::info('MultiAgentDebate: completed', [
            'mode' => $mode,
            'perspectives' => count($successfulOutputs),
            'synthesis_length' => strlen($synthesis),
            'duration_ms' => $durationMs,
        ]);

        return [
            'success' => true,
            'synthesis' => $synthesis,
            'perspectives' => $debaterOutputs,
            'mode' => $mode,
            'debaters_succeeded' => count($successfulOutputs),
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Build the synthesis/judge prompt.
     */
    private function buildSynthesisPrompt(string $task, array $outputs, string $mode): string
    {
        $perspectiveTexts = '';
        foreach ($outputs as $key => $output) {
            $perspectiveTexts .= "### {$output['name']}\n{$output['response']}\n\n";
        }

        $instruction = match ($mode) {
            'adversarial' => "Evaluate the arguments for and against. Determine which position has stronger evidence. Produce a balanced conclusion.",
            'consensus' => "Identify points of agreement across all perspectives. Where they disagree, explain why and pick the best-supported position.",
            default => "Synthesize the best elements from each perspective into a comprehensive, well-reasoned answer.",
        };

        return <<<PROMPT
You are a judge synthesizing multiple expert perspectives on a task.

## Original Task
{$task}

## Expert Perspectives
{$perspectiveTexts}

## Your Task
{$instruction}

Produce a single, coherent answer that is stronger than any individual perspective. Cite which expert's reasoning you drew from when relevant.
PROMPT;
    }

    /**
     * Agent tool wrapper.
     */
    public function runDebate(array $params): array
    {
        $task = $params['task'] ?? $params['question'] ?? null;
        if (!$task) {
            return ['success' => false, 'result_text' => 'Error: task parameter required'];
        }

        $result = $this->debate($task, [
            'mode' => $params['mode'] ?? 'diverse',
            'context' => $params['context'] ?? '',
        ]);

        if ($result['success']) {
            $count = $result['debaters_succeeded'];
            $ms = $result['duration_ms'];
            return [
                'success' => true,
                'result_text' => "Multi-agent debate ({$count} perspectives, {$ms}ms):\n\n{$result['synthesis']}",
                'result' => $result,
            ];
        }

        return ['success' => false, 'result_text' => 'Debate failed: ' . ($result['synthesis'] ?? 'unknown error')];
    }
}
