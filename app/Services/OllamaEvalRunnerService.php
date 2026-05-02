<?php

namespace App\Services;

use InvalidArgumentException;

class OllamaEvalRunnerService
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly OllamaPipelineProfileService $profiles
    ) {}

    public function runModel(string $model, ?array $caseIds = null, ?string $provider = null): array
    {
        [$targetProvider, $targetModel, $targetRef] = $this->resolveTarget($model, $provider);

        $cases = collect(config('ollama_eval.eval_cases', []))
            ->keyBy('id');

        $selectedIds = $caseIds ?? $cases->keys()->all();
        $results = [];

        foreach ($selectedIds as $caseId) {
            $case = $cases->get($caseId);
            if (! is_array($case)) {
                throw new InvalidArgumentException("Unknown eval case: {$caseId}");
            }

            $fixture = config("ollama_eval_fixtures.cases.{$caseId}");
            if (! is_array($fixture)) {
                throw new InvalidArgumentException("Missing eval fixture for case: {$caseId}");
            }

            $profile = $this->profiles->getTaskProfile($case['task_class']);
            $prompt = $this->buildPrompt($case, $fixture, $profile);
            $startedAt = microtime(true);

            $response = $this->aiService->process($prompt, [
                'model' => $targetRef,
                'model_role' => $profile['model_role'],
                'max_tokens' => 700,
                'temperature' => 0.0,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'ollama_eval',
            ]);

            $transportSuccess = (bool) ($response['success'] ?? true);
            $rawOutput = (string) ($response['response'] ?? $response['content'] ?? '');
            $evaluation = $this->evaluateOutput($case, $fixture, $rawOutput, $transportSuccess);

            $results[] = [
                'case_id' => $caseId,
                'task_class' => $case['task_class'],
                'prompt_shape' => $case['prompt_shape'],
                'passed' => $evaluation['passed'],
                'score' => $evaluation['score'],
                'checks' => $evaluation['checks'],
                'output' => $rawOutput,
                'provider' => $response['provider'] ?? $targetProvider,
                'model' => $response['model'] ?? $targetModel,
                'duration_ms' => $response['duration_ms'] ?? (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $response['error'] ?? null,
            ];
        }

        $passCount = count(array_filter($results, fn (array $result): bool => $result['passed']));

        return [
            'provider' => $targetProvider,
            'model' => $targetModel,
            'target' => $targetRef,
            'total_cases' => count($results),
            'passed_cases' => $passCount,
            'failed_cases' => count($results) - $passCount,
            'pass_rate' => count($results) > 0 ? round(($passCount / count($results)) * 100, 1) : 0.0,
            'results' => $results,
        ];
    }

    private function resolveTarget(string $model, ?string $provider = null): array
    {
        $knownProviders = config('ollama_eval.provider_policy.known_providers', ['ollama']);
        $allowedProviders = config('ollama_eval.provider_policy.allowed_providers', ['ollama']);

        if (preg_match('/^([a-z_]+):(.*)$/', $model, $matches) === 1 && in_array($matches[1], $knownProviders, true)) {
            $targetProvider = $matches[1];
            $targetModel = $matches[2];
        } else {
            $targetProvider = $provider ?: 'ollama';
            $targetModel = $model;
        }

        if (! in_array($targetProvider, $allowedProviders, true)) {
            throw new InvalidArgumentException("Eval provider not allowed by policy: {$targetProvider}");
        }

        return [$targetProvider, $targetModel, "{$targetProvider}:{$targetModel}"];
    }

    private function buildPrompt(array $case, array $fixture, array $profile): string
    {
        $instruction = match ($case['prompt_shape']) {
            'strict_json' => 'Return valid JSON only. No prose, no markdown, no code fences.',
            'classification_json' => 'Return valid JSON only with the requested decision fields.',
            'label_confidence_json' => 'Return valid JSON only with label, confidence, and rationale.',
            'compact_text' => 'Return compact plain text only. No JSON, no markdown, no raw payload echo.',
            default => 'Return a concise answer matching the requested format only.',
        };

        return implode("\n\n", array_filter([
            "Task class: {$case['task_class']}",
            "Output schema: {$profile['output_schema']}",
            "Success rule: {$case['success_rule']}",
            $instruction,
            $fixture['input'] ?? null,
        ]));
    }

    private function evaluateOutput(array $case, array $fixture, string $output, bool $transportSuccess = true): array
    {
        $expectations = $fixture['expectations'] ?? [];
        $checks = ['transport_success' => $transportSuccess];
        $parsed = null;

        if ($transportSuccess && in_array($case['prompt_shape'], ['strict_json', 'classification_json', 'label_confidence_json'], true)) {
            $parsed = $this->extractJson($output);
            $checks['json_parseable'] = is_array($parsed);
        }

        if ($transportSuccess && isset($expectations['json_required_keys'])) {
            $checks['required_keys'] = is_array($parsed)
                && collect($expectations['json_required_keys'])->every(fn (string $key): bool => array_key_exists($key, $parsed));
        }

        if ($transportSuccess && isset($expectations['allowed_decisions'])) {
            $decision = is_array($parsed) ? strtolower((string) ($parsed['decision'] ?? '')) : '';
            $checks['allowed_decision'] = in_array($decision, $expectations['allowed_decisions'], true);
        }

        if ($transportSuccess && isset($expectations['allowed_labels'])) {
            $label = is_array($parsed) ? strtolower((string) ($parsed['label'] ?? '')) : '';
            $checks['allowed_label'] = in_array($label, $expectations['allowed_labels'], true);
        }

        if ($transportSuccess && isset($expectations['must_contain'])) {
            $haystack = is_array($parsed) ? json_encode($parsed, JSON_UNESCAPED_SLASHES) ?: $output : $output;
            $checks['must_contain'] = collect($expectations['must_contain'])
                ->every(fn (string $needle): bool => str_contains(mb_strtolower($haystack), mb_strtolower($needle)));
        }

        if ($transportSuccess && isset($expectations['must_not_contain'])) {
            $checks['must_not_contain'] = collect($expectations['must_not_contain'])
                ->every(fn (string $needle): bool => ! str_contains(mb_strtolower($output), mb_strtolower($needle)));
        }

        if ($transportSuccess && isset($expectations['max_length'])) {
            $checks['max_length'] = mb_strlen(trim($output)) <= (int) $expectations['max_length'];
        }

        $totalChecks = count($checks);
        $passedChecks = count(array_filter($checks));

        return [
            'passed' => $totalChecks > 0 && $passedChecks === $totalChecks,
            'score' => $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 1) : 0.0,
            'checks' => $checks,
        ];
    }

    private function extractJson(string $output): ?array
    {
        $trimmed = trim($output);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $output, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
