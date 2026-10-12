<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class OllamaEvalLedgerService
{
    public const SCHEMA = 'plos.ollama_eval_ledger.v1';

    /** @param array<string, mixed> $run */
    public function record(array $run): array
    {
        if (! Schema::hasTable('ollama_eval_runs')) {
            throw new RuntimeException('ollama_eval_runs table is unavailable; run migrations before recording.');
        }

        $assessment = is_array($run['promotion_assessment'] ?? null) ? $run['promotion_assessment'] : [];
        $target = is_array($assessment['target_evidence'] ?? null) ? $assessment['target_evidence'] : [];
        $results = is_array($run['results'] ?? null) ? $run['results'] : [];
        $runUuid = (string) Str::uuid();
        $suiteFingerprint = $this->suiteFingerprint();
        $actualProviders = array_values((array) ($target['actual_providers'] ?? []));
        $actualModels = array_values((array) ($target['actual_models'] ?? []));

        DB::table('ollama_eval_runs')->insert([
            'run_uuid' => $runUuid,
            'requested_provider' => (string) ($run['provider'] ?? ''),
            'requested_model' => (string) ($run['model'] ?? ''),
            'requested_instance_id' => $run['requested_instance_id'] ?? null,
            'actual_provider' => count($actualProviders) === 1 ? (string) $actualProviders[0] : null,
            'actual_model' => count($actualModels) === 1 ? (string) $actualModels[0] : null,
            'suite_id' => (string) config('ollama_eval.suite_id', 'plos.local_model.v1'),
            'suite_fingerprint' => $suiteFingerprint,
            'expected_case_count' => (int) data_get($assessment, 'coverage.expected_case_count', 0),
            'evaluated_case_count' => (int) ($run['total_cases'] ?? 0),
            'pass_rate' => (float) ($run['pass_rate'] ?? 0.0),
            'average_score_ratio' => (float) ($run['average_score_ratio'] ?? 0.0),
            'decision' => (string) ($assessment['decision'] ?? 'hold_incomplete'),
            'promotion_eligible' => (bool) ($assessment['promotion_eligible'] ?? false),
            'target_consistent' => (bool) ($target['consistent'] ?? false),
            'duration_ms' => array_sum(array_map(static fn (array $result): int => (int) ($result['duration_ms'] ?? 0), $results)),
            'case_summary' => json_encode($this->contentFreeCaseSummary($results), JSON_THROW_ON_ERROR),
            'assessment' => json_encode($assessment, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return [
            'schema' => self::SCHEMA,
            'run_uuid' => $runUuid,
            'suite_id' => (string) config('ollama_eval.suite_id', 'plos.local_model.v1'),
            'suite_fingerprint' => $suiteFingerprint,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'promotion_applied' => false,
        ];
    }

    public function suiteFingerprint(): string
    {
        $contract = [
            'suite_id' => config('ollama_eval.suite_id', 'plos.local_model.v1'),
            'eval_cases' => config('ollama_eval.eval_cases', []),
            'regression_cases' => config('ollama_eval.regression_cases', []),
            'thresholds' => config('ollama_eval.thresholds', []),
            'fixtures' => config('ollama_eval_fixtures.cases', []),
            'profiles' => config('ollama_pipeline.tasks', []),
            'output_schemas' => config('ollama_output_schemas.schemas', []),
        ];

        return hash('sha256', json_encode($this->canonicalize($contract), JSON_THROW_ON_ERROR));
    }

    /** @param array<int, array<string, mixed>> $results */
    private function contentFreeCaseSummary(array $results): array
    {
        return array_map(static fn (array $result): array => [
            'case_id' => (string) ($result['case_id'] ?? ''),
            'task_class' => (string) ($result['task_class'] ?? ''),
            'prompt_shape' => (string) ($result['prompt_shape'] ?? ''),
            'structured_output_enforced' => (bool) ($result['structured_output_enforced'] ?? false),
            'structured_output_schema_id' => $result['structured_output_schema_id'] ?? null,
            'passed' => (bool) ($result['passed'] ?? false),
            'score_pct' => (float) ($result['score_pct'] ?? 0.0),
            'score_ratio' => (float) ($result['score_ratio'] ?? 0.0),
            'checks' => array_map('boolval', is_array($result['checks'] ?? null) ? $result['checks'] : []),
            'provider' => (string) ($result['provider'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'requested_max_tokens' => (int) ($result['requested_max_tokens'] ?? 0),
            'output_chars' => (int) ($result['output_chars'] ?? 0),
            'completion_tokens' => isset($result['completion_tokens']) ? (int) $result['completion_tokens'] : null,
            'finish_reason' => isset($result['finish_reason']) ? (string) $result['finish_reason'] : null,
            'fallback_suppressed' => isset($result['fallback_suppressed'])
                ? (bool) $result['fallback_suppressed']
                : null,
            'instance_match' => (bool) ($result['instance_match'] ?? true),
            'target_match' => (bool) ($result['target_match'] ?? true),
        ], $results);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
