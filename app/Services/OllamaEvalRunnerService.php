<?php

namespace App\Services;

use App\Support\PromptIntegrityPolicy;
use InvalidArgumentException;

class OllamaEvalRunnerService
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly OllamaPipelineProfileService $profiles,
        private readonly ?OllamaEvalAssessmentService $assessment = null,
        private readonly ?OllamaStructuredOutputSchemaService $schemaValidator = null
    ) {}

    public function runModel(
        string $model,
        ?array $caseIds = null,
        ?string $provider = null,
        ?string $instanceId = null,
        ?int $maxTokens = null
    ): array {
        if ($maxTokens !== null && ($maxTokens < 1 || $maxTokens > 700)) {
            throw new InvalidArgumentException('Eval max tokens must be between 1 and 700.');
        }

        [$targetProvider, $targetModel, $targetRef, $requiredInstanceId] = $this->resolveTarget($model, $provider);

        $cases = collect(config('ollama_eval.eval_cases', []))->keyBy('id');

        $selectedIds = $caseIds ?? $cases
            ->filter(fn (array $case): bool => $this->evalCaseEnabled($case))
            ->keys()
            ->all();
        $expectedCaseIds = $cases
            ->filter(fn (array $case): bool => $this->evalCaseEnabled($case))
            ->keys()
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $results = [];

        foreach ($selectedIds as $caseId) {
            $case = $cases->get($caseId);
            if (! is_array($case)) {
                throw new InvalidArgumentException("Unknown eval case: {$caseId}");
            }

            if (! $this->evalCaseEnabled($case)) {
                throw new InvalidArgumentException("Eval case disabled by compression gate: {$caseId}");
            }

            $fixture = config("ollama_eval_fixtures.cases.{$caseId}");
            if (! is_array($fixture)) {
                throw new InvalidArgumentException("Missing eval fixture for case: {$caseId}");
            }

            $profile = $this->profiles->getTaskProfile($case['task_class']);
            if (! ($profile['enabled'] ?? true)) {
                $reason = (string) ($profile['disabled_reason'] ?? 'disabled');
                throw new InvalidArgumentException("Ollama pipeline task disabled: {$case['task_class']} ({$reason})");
            }

            $prompt = $this->buildPrompt($case, $fixture, $profile, $maxTokens);
            $startedAt = microtime(true);
            $schemaId = ($profile['structured_output_enforced'] ?? false)
                ? (string) ($profile['output_schema'] ?? '')
                : null;

            $requestConfig = [
                // Force the explicit eval target. Supplying model_role here
                // lets AIService route by role and can silently test a
                // different local model than the operator requested.
                'model' => $targetProvider === 'ollama' ? $targetModel : $targetRef,
                'model_override' => $targetModel,
                'max_tokens' => $maxTokens ?? ($targetProvider === 'provider' ? 256 : 700),
                'temperature' => 0.0,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'ollama_eval',
                'eval_task_class' => (string) $case['task_class'],
            ];

            if ($targetProvider === 'provider') {
                $requestConfig['required_instance_id'] = $requiredInstanceId;
                $requestConfig['model_role'] = 'deep_offline';
            }

            if ($instanceId !== null) {
                if ($targetProvider !== 'ollama') {
                    throw new InvalidArgumentException('An Ollama instance can only be pinned for the ollama provider.');
                }

                $requestConfig['allowed_ollama_instance_ids'] = [$instanceId];
                $requestConfig['preferred_ollama_instance_ids'] = [$instanceId];
                $requestConfig['ollama_required_no_fallback'] = true;
                $requestConfig['disable_cascade'] = true;
            }

            if ($schemaId !== null) {
                // AIService maps this registered contract to each admitted
                // provider transport and validates the returned document.
                $requestConfig['ollama_output_schema'] = $schemaId;

                // A pinned evaluation may probe an unpromoted structured-output
                // capability only when every exact-target/no-fallback guard is
                // present. AIService refuses this flag for ordinary routing.
                if ($instanceId !== null || $requiredInstanceId !== null) {
                    $requestConfig['structured_output_eval_probe'] = true;
                    $requestConfig['disable_cascade'] = true;
                }
            }

            $response = $this->aiService->process($prompt, $requestConfig);

            $transportSuccess = (bool) ($response['success'] ?? true);
            $rawOutput = (string) ($response['response'] ?? $response['content'] ?? '');
            $evaluation = $this->evaluateOutput($case, $fixture, $rawOutput, $transportSuccess, $schemaId);
            $actualProvider = (string) ($response['provider'] ?? $targetProvider);
            $actualModel = (string) ($response['model'] ?? $targetModel);
            $providerMatchesTarget = $targetProvider === 'ollama'
                ? ($actualProvider === 'ollama' || str_starts_with($actualProvider, 'ollama_'))
                : ($targetProvider === 'provider'
                    ? $actualProvider === $requiredInstanceId
                    : $actualProvider === $targetProvider);
            $transportTelemetry = is_array($response['transport_telemetry'] ?? null)
                ? $response['transport_telemetry']
                : [];

            $results[] = [
                'case_id' => $caseId,
                'task_class' => $case['task_class'],
                'prompt_shape' => $case['prompt_shape'],
                'structured_output_enforced' => isset($requestConfig['ollama_output_schema']),
                'structured_output_schema_id' => $schemaId,
                'passed' => $evaluation['passed'],
                // score remains the legacy percentage field. New consumers
                // must use the explicitly scaled fields below.
                'score' => $evaluation['score_pct'],
                'score_pct' => $evaluation['score_pct'],
                'score_ratio' => $evaluation['score_ratio'],
                'checks' => $evaluation['checks'],
                'output' => $rawOutput,
                'provider' => $actualProvider,
                'model' => $actualModel,
                'target_match' => $providerMatchesTarget && $actualModel === $targetModel,
                'requested_instance_id' => $requiredInstanceId ?? $instanceId,
                'instance_match' => ($requiredInstanceId ?? $instanceId) === null
                    || $actualProvider === ($requiredInstanceId ?? $instanceId),
                'requested_max_tokens' => $requestConfig['max_tokens'],
                'output_chars' => mb_strlen($rawOutput),
                'completion_tokens' => isset($transportTelemetry['completion_tokens'])
                    ? (int) $transportTelemetry['completion_tokens']
                    : null,
                'finish_reason' => isset($transportTelemetry['finish_reason'])
                    ? (string) $transportTelemetry['finish_reason']
                    : null,
                'fallback_suppressed' => $targetProvider === 'provider'
                    ? (bool) ($response['fallback_suppressed'] ?? false)
                    : ($instanceId !== null ? true : null),
                'duration_ms' => $response['duration_ms'] ?? (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $response['error'] ?? null,
            ];
        }

        $passCount = count(array_filter($results, fn (array $result): bool => $result['passed']));

        $assessment = ($this->assessment ?? new OllamaEvalAssessmentService)
            ->assess($results, $expectedCaseIds);

        return [
            'provider' => $targetProvider,
            'model' => $targetModel,
            'target' => $targetRef,
            'requested_instance_id' => $requiredInstanceId ?? $instanceId,
            'requested_max_tokens' => $maxTokens ?? ($targetProvider === 'provider' ? 256 : 700),
            'total_cases' => count($results),
            'passed_cases' => $passCount,
            'failed_cases' => count($results) - $passCount,
            'pass_rate' => count($results) > 0 ? round(($passCount / count($results)) * 100, 1) : 0.0,
            'average_score_ratio' => $assessment['average_score_ratio'],
            'promotion_assessment' => $assessment,
            'results' => $results,
        ];
    }

    private function resolveTarget(string $model, ?string $provider = null): array
    {
        $knownProviders = config('ollama_eval.provider_policy.known_providers', ['ollama']);
        $allowedProviders = config('ollama_eval.provider_policy.allowed_providers', ['ollama']);

        $requiredInstanceId = null;
        if (preg_match('#^provider:([a-zA-Z0-9_.-]+)/(.+)$#', $model, $matches) === 1) {
            $targetProvider = 'provider';
            $requiredInstanceId = $matches[1];
            $targetModel = $matches[2];
        } elseif (preg_match('/^([a-z_]+):(.*)$/', $model, $matches) === 1 && in_array($matches[1], $knownProviders, true)) {
            $targetProvider = $matches[1];
            $targetModel = $matches[2];
        } else {
            $targetProvider = $provider ?: 'ollama';
            $targetModel = $model;
        }

        if (! in_array($targetProvider, $allowedProviders, true)) {
            throw new InvalidArgumentException("Eval provider not allowed by policy: {$targetProvider}");
        }

        $targetRef = $targetProvider === 'provider'
            ? "provider:{$requiredInstanceId}/{$targetModel}"
            : "{$targetProvider}:{$targetModel}";

        return [$targetProvider, $targetModel, $targetRef, $requiredInstanceId];
    }

    private function evalCaseEnabled(array $case): bool
    {
        if (! filter_var($case['requires_lossy_transform'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return PromptIntegrityPolicy::allowsLossyTransforms()
            && filter_var(config('ollama_eval.compression_eval_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function buildPrompt(array $case, array $fixture, array $profile, ?int $maxTokens = null): string
    {
        $instruction = match ($case['prompt_shape']) {
            'strict_json' => 'Return valid JSON only. No prose, no markdown, no code fences.',
            'classification_json' => 'Return valid JSON only with the requested decision fields.',
            'label_confidence_json' => 'Return valid JSON only with label, confidence, and rationale.',
            'compact_text' => 'Return compact plain text only. No JSON, no markdown, no raw payload echo.',
            default => 'Return a concise answer matching the requested format only.',
        };
        $boundedStructuredInstruction = $maxTokens !== null
            && $maxTokens <= 128
            && in_array($case['prompt_shape'], ['strict_json', 'classification_json', 'label_confidence_json'], true)
                ? 'Use the smallest schema-valid JSON object. Keep arrays and rationale fields minimal while preserving every required key and success-rule fact.'
                : null;
        $boundedFactInstruction = $maxTokens !== null
            && $maxTokens <= 128
            && ($case['task_class'] ?? null) === 'fact_extraction'
                ? 'For this bounded fact extraction, use one primary persons item; put every required name, relationship, date, and place compactly in one facts item with field "other"; use an empty relationships array and notes_remainder.'
                : null;

        return implode("\n\n", array_filter([
            "Task class: {$case['task_class']}",
            "Output schema: {$profile['output_schema']}",
            "Success rule: {$case['success_rule']}",
            $instruction,
            $boundedStructuredInstruction,
            $boundedFactInstruction,
            $fixture['input'] ?? null,
        ]));
    }

    private function evaluateOutput(
        array $case,
        array $fixture,
        string $output,
        bool $transportSuccess = true,
        ?string $schemaId = null
    ): array {
        $expectations = $fixture['expectations'] ?? [];
        $checks = ['transport_success' => $transportSuccess];
        $parsed = null;

        if ($transportSuccess && in_array($case['prompt_shape'], ['strict_json', 'classification_json', 'label_confidence_json'], true)) {
            $parsed = $this->extractExactJson($output);
            $checks['json_parseable'] = is_array($parsed);
            $checks['json_exact'] = is_array($parsed);
        }

        if ($transportSuccess && $schemaId !== null) {
            $checks['schema_compliant'] = ($this->schemaValidator ?? new OllamaStructuredOutputSchemaService)
                ->validateResponse($schemaId, $output);
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

        if ($transportSuccess && isset($expectations['grounded_year_fields'])) {
            $input = (string) ($fixture['input'] ?? '');
            $checks['grounded_year_fields'] = is_array($parsed)
                && collect($expectations['grounded_year_fields'])->every(function (string $field) use ($parsed, $input): bool {
                    $value = $parsed[$field] ?? null;

                    return $value === null
                        || (is_int($value) && preg_match('/(?<!\d)'.preg_quote((string) $value, '/').'(?!\d)/', $input) === 1);
                });
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
            'score_pct' => $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 1) : 0.0,
            'score_ratio' => $totalChecks > 0 ? round($passedChecks / $totalChecks, 4) : 0.0,
            'checks' => $checks,
        ];
    }

    private function extractExactJson(string $output): ?array
    {
        $trimmed = trim($output);
        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
