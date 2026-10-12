<?php

namespace App\Services\Ops;

final class PromptCandidateEvalService
{
    public const ARTIFACT_SCHEMA = 'plos.prompt_candidate.v1';

    public const RESULT_SCHEMA = 'plos.prompt_candidate_eval.v1';

    /**
     * @param  array<string,mixed>  $artifact
     * @return array<string,mixed>
     */
    public function evaluate(array $artifact): array
    {
        $violations = [];
        $this->validateEnvelope($artifact, $violations);

        $metrics = $this->normalizeMetrics($artifact['metrics'] ?? null, $violations);
        $primaryMetric = is_string($artifact['primary_metric'] ?? null)
            ? (string) $artifact['primary_metric']
            : '';
        if ($primaryMetric === '' || ! in_array($primaryMetric, $metrics, true)) {
            $violations[] = 'primary_metric_missing_or_unknown';
        }

        $hypotheses = $this->normalizeHypotheses($artifact['hypotheses'] ?? null, $violations);
        $training = $this->normalizeCases(
            'training',
            $artifact['training_cases'] ?? null,
            $metrics,
            array_keys($hypotheses),
            $violations,
        );
        $holdout = $this->normalizeCases(
            'holdout',
            $artifact['holdout_cases'] ?? null,
            $metrics,
            array_keys($hypotheses),
            $violations,
        );

        $trainingIds = array_column($training, 'id');
        $holdoutIds = array_column($holdout, 'id');
        $trainingHoldoutDisjoint = array_intersect($trainingIds, $holdoutIds) === [];
        if (! $trainingHoldoutDisjoint) {
            $violations[] = 'training_holdout_case_overlap';
        }

        $minTraining = max(1, (int) config('agent_prompt_optimization.min_training_cases', 2));
        $minHoldout = max(1, (int) config('agent_prompt_optimization.min_holdout_cases', 3));
        if (count($training) < $minTraining) {
            $violations[] = 'insufficient_training_cases';
        }
        if (count($holdout) < $minHoldout) {
            $violations[] = 'insufficient_holdout_cases';
        }

        $trainingSummary = $this->splitSummary($training, $metrics, $primaryMetric);
        $holdoutSummary = $this->splitSummary($holdout, $metrics, $primaryMetric);
        $regressions = $this->regressions($holdout, $metrics);
        $minImprovement = (float) config('agent_prompt_optimization.min_holdout_mean_improvement', 0.02);
        $minImprovedCases = max(1, (int) config('agent_prompt_optimization.min_improved_holdout_cases', 1));
        $primaryDelta = (float) data_get($holdoutSummary, "metrics.{$primaryMetric}.delta", 0.0);
        $improvedCases = (int) ($holdoutSummary['primary_metric_improved_cases'] ?? 0);
        $operatorReviewRequired = ($artifact['operator_review_required'] ?? null) === true;

        if (! $operatorReviewRequired) {
            $violations[] = 'operator_review_not_required_by_artifact';
        }

        $violations = array_values(array_unique($violations));
        $eligible = $violations === []
            && $regressions === []
            && $primaryDelta >= $minImprovement
            && $improvedCases >= $minImprovedCases;

        return [
            'schema' => self::RESULT_SCHEMA,
            'status' => $eligible ? 'eligible_for_review' : ($violations === [] ? 'not_eligible' : 'invalid'),
            'read_only' => true,
            'generated_at' => now('UTC')->toIso8601String(),
            'candidate' => [
                'id' => $this->safeString($artifact['candidate_id'] ?? null),
                'target' => $this->safeString($artifact['target'] ?? null),
                'baseline_sha256' => $this->safeString(data_get($artifact, 'baseline.sha256')),
                'candidate_sha256' => $this->safeString(data_get($artifact, 'candidate.sha256')),
                'primary_metric' => $primaryMetric,
                'metrics' => $metrics,
                'hypothesis_count' => count($hypotheses),
            ],
            'training' => $trainingSummary,
            'holdout' => $holdoutSummary,
            'regressions' => $regressions,
            'violations' => $violations,
            'promotion' => [
                'eligible_for_operator_review' => $eligible,
                'autonomous_apply_allowed' => false,
                'operator_review_required' => true,
                'criteria' => [
                    'training_holdout_disjoint' => $trainingHoldoutDisjoint,
                    'minimum_training_cases' => $minTraining,
                    'minimum_holdout_cases' => $minHoldout,
                    'minimum_primary_metric_mean_improvement' => $minImprovement,
                    'minimum_improved_holdout_cases' => $minImprovedCases,
                    'maximum_case_regression' => (float) config('agent_prompt_optimization.max_case_regression', 0.0),
                ],
            ],
            'posture' => [
                'deterministic' => true,
                'normalized_scores_higher_is_better' => true,
                'hashes_only_prompt_contract' => true,
                'raw_prompt_or_trace_content_included' => false,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'lossy_prompt_transforms' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function exampleArtifact(): array
    {
        $metrics = ['task_quality', 'policy_compliance'];

        return [
            'schema' => self::ARTIFACT_SCHEMA,
            'candidate_id' => 'offline-dev-tool-policy-v2',
            'target' => 'skill:offline-dev:instructions',
            'baseline' => ['sha256' => hash('sha256', 'offline-dev-tool-policy-v1')],
            'candidate' => ['sha256' => hash('sha256', 'offline-dev-tool-policy-v2')],
            'metrics' => $metrics,
            'primary_metric' => 'task_quality',
            'operator_review_required' => true,
            'hypotheses' => [[
                'id' => 'require_allowlist_check',
                'label' => 'Require an allowlist check before proposing a new tool',
                'failure_codes' => ['mcp_allowlist_missed'],
                'evidence_sha256' => hash('sha256', 'synthetic-training-failure'),
            ]],
            'training_cases' => [
                $this->exampleCase('train_allowlist', 'tool_selection', 0.55, 0.90, true),
                $this->exampleCase('train_safe_command', 'tool_selection', 0.70, 0.90, true),
            ],
            'holdout_cases' => [
                $this->exampleCase('holdout_unknown_tool', 'tool_selection', 0.60, 0.85),
                $this->exampleCase('holdout_protected_path', 'policy_compliance', 0.80, 0.90),
                $this->exampleCase('holdout_read_only', 'task_quality', 0.75, 0.85),
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? self::RESULT_SCHEMA,
            'status' => $payload['status'] ?? 'invalid',
            'compact' => true,
            'read_only' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'candidate' => $payload['candidate'] ?? [],
            'training' => $payload['training'] ?? [],
            'holdout' => $payload['holdout'] ?? [],
            'regressions' => $payload['regressions'] ?? [],
            'violations' => $payload['violations'] ?? [],
            'promotion' => $payload['promotion'] ?? [],
            'posture' => $payload['posture'] ?? [],
            'source' => $payload['source'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $violations
     *
     * @param-out list<string> $violations
     */
    private function validateEnvelope(array $artifact, array &$violations): void
    {
        if ($this->unknownKeys($artifact, [
            'schema',
            'candidate_id',
            'target',
            'baseline',
            'candidate',
            'metrics',
            'primary_metric',
            'operator_review_required',
            'hypotheses',
            'training_cases',
            'holdout_cases',
        ]) !== []) {
            $violations[] = 'unknown_artifact_field';
        }
        if (($artifact['schema'] ?? null) !== self::ARTIFACT_SCHEMA) {
            $violations[] = 'invalid_artifact_schema';
        }

        foreach (['candidate_id', 'target'] as $key) {
            $value = $artifact[$key] ?? null;
            if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{1,199}$/', $value) !== 1) {
                $violations[] = "invalid_{$key}";
            }
        }

        $baselineHash = data_get($artifact, 'baseline.sha256');
        $candidateHash = data_get($artifact, 'candidate.sha256');
        foreach (['baseline', 'candidate'] as $variant) {
            $identity = $artifact[$variant] ?? null;
            if (! is_array($identity) || array_is_list($identity) || $this->unknownKeys($identity, ['sha256']) !== []) {
                $violations[] = "invalid_{$variant}_identity";
            }
        }
        if (! $this->isSha256($baselineHash)) {
            $violations[] = 'invalid_baseline_sha256';
        }
        if (! $this->isSha256($candidateHash)) {
            $violations[] = 'invalid_candidate_sha256';
        }
        if (is_string($baselineHash) && $baselineHash === $candidateHash) {
            $violations[] = 'candidate_matches_baseline';
        }

        foreach (['prompt', 'baseline_prompt', 'candidate_prompt', 'raw_trace', 'trace'] as $forbidden) {
            if (array_key_exists($forbidden, $artifact)) {
                $violations[] = 'raw_content_field_forbidden:'.$forbidden;
            }
        }
    }

    /**
     * @param  list<string>  $violations
     *
     * @param-out list<string> $violations
     *
     * @return list<string>
     */
    private function normalizeMetrics(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'metrics_must_be_a_list';

            return [];
        }

        $metrics = [];
        foreach ($raw as $metric) {
            if (! is_string($metric) || preg_match('/^[a-z][a-z0-9_]{1,60}$/', $metric) !== 1) {
                $violations[] = 'invalid_metric_name';

                continue;
            }
            $metrics[] = $metric;
        }
        if ($metrics === [] || count($metrics) !== count(array_unique($metrics))) {
            $violations[] = 'metrics_empty_or_duplicated';
        }
        if (count($metrics) > max(1, (int) config('agent_prompt_optimization.max_metrics', 8))) {
            $violations[] = 'too_many_metrics';
        }

        return array_values(array_unique($metrics));
    }

    /**
     * @param  list<string>  $violations
     *
     * @param-out list<string> $violations
     *
     * @return array<string,array<string,mixed>>
     */
    private function normalizeHypotheses(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            $violations[] = 'hypotheses_required';

            return [];
        }

        $result = [];
        foreach ($raw as $hypothesis) {
            if (! is_array($hypothesis)) {
                $violations[] = 'invalid_hypothesis';

                continue;
            }
            $id = $hypothesis['id'] ?? null;
            $label = $hypothesis['label'] ?? null;
            $failureCodes = $hypothesis['failure_codes'] ?? null;
            if ($this->unknownKeys($hypothesis, ['id', 'label', 'failure_codes', 'evidence_sha256']) !== []) {
                $violations[] = 'unknown_hypothesis_field';
            }
            if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]{1,80}$/', $id) !== 1 || isset($result[$id])) {
                $violations[] = 'invalid_or_duplicate_hypothesis_id';

                continue;
            }
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 200) {
                $violations[] = 'invalid_hypothesis_label:'.$id;
            }
            if (! is_array($failureCodes) || ! array_is_list($failureCodes) || $failureCodes === []) {
                $violations[] = 'hypothesis_failure_codes_required:'.$id;
            } elseif (count($failureCodes) !== count(array_unique($failureCodes, SORT_REGULAR))) {
                $violations[] = 'duplicate_hypothesis_failure_code:'.$id;
            } else {
                foreach ($failureCodes as $failureCode) {
                    if (! is_string($failureCode) || preg_match('/^[a-z][a-z0-9_.:-]{1,100}$/', $failureCode) !== 1) {
                        $violations[] = 'invalid_hypothesis_failure_code:'.$id;
                    }
                }
            }
            if (! $this->isSha256($hypothesis['evidence_sha256'] ?? null)) {
                $violations[] = 'hypothesis_evidence_sha256_required:'.$id;
            }
            $result[$id] = $hypothesis;
        }

        return $result;
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $knownHypotheses
     * @param  list<string>  $violations
     *
     * @param-out list<string> $violations
     *
     * @return list<array<string,mixed>>
     */
    private function normalizeCases(
        string $split,
        mixed $raw,
        array $metrics,
        array $knownHypotheses,
        array &$violations,
    ): array {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = "{$split}_cases_must_be_a_list";

            return [];
        }

        $result = [];
        $seen = [];
        foreach ($raw as $case) {
            if (! is_array($case)) {
                $violations[] = "invalid_{$split}_case";

                continue;
            }

            $id = $case['id'] ?? null;
            $competency = $case['competency'] ?? null;
            $allowedCaseKeys = ['id', 'competency', 'evidence_sha256', 'baseline', 'candidate'];
            if ($split === 'training') {
                $allowedCaseKeys[] = 'hypothesis_ids';
            }
            if ($this->unknownKeys($case, $allowedCaseKeys) !== []) {
                $violations[] = "unknown_{$split}_case_field";
            }
            if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]{1,100}$/', $id) !== 1 || isset($seen[$id])) {
                $violations[] = "invalid_or_duplicate_{$split}_case_id";

                continue;
            }
            $seen[$id] = true;
            if (! is_string($competency) || preg_match('/^[a-z][a-z0-9_]{1,80}$/', $competency) !== 1) {
                $violations[] = "invalid_{$split}_competency:{$id}";
            }
            if (! $this->isSha256($case['evidence_sha256'] ?? null)) {
                $violations[] = "invalid_{$split}_evidence_sha256:{$id}";
            }

            if ($split === 'training') {
                $bindings = $case['hypothesis_ids'] ?? null;
                if (! is_array($bindings) || ! array_is_list($bindings) || $bindings === []) {
                    $violations[] = "training_hypothesis_binding_required:{$id}";
                } elseif (count($bindings) !== count(array_unique($bindings, SORT_REGULAR))) {
                    $violations[] = "duplicate_training_hypothesis:{$id}";
                } else {
                    foreach ($bindings as $binding) {
                        if (! is_string($binding) || ! in_array($binding, $knownHypotheses, true)) {
                            $violations[] = "unknown_training_hypothesis:{$id}";
                        }
                    }
                }
            } elseif (array_key_exists('hypothesis_ids', $case)) {
                $violations[] = "holdout_hypothesis_binding_forbidden:{$id}";
            }

            $baseline = $this->normalizeScores($split, $id, 'baseline', $case['baseline'] ?? null, $metrics, $violations);
            $candidate = $this->normalizeScores($split, $id, 'candidate', $case['candidate'] ?? null, $metrics, $violations);
            $result[] = [
                'id' => $id,
                'competency' => is_string($competency) ? $competency : 'invalid',
                'baseline' => $baseline,
                'candidate' => $candidate,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $violations
     *
     * @param-out list<string> $violations
     *
     * @return array<string,float>
     */
    private function normalizeScores(
        string $split,
        string $caseId,
        string $variant,
        mixed $raw,
        array $metrics,
        array &$violations,
    ): array {
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$split}_{$variant}_scores:{$caseId}";

            return [];
        }

        $scores = [];
        foreach ($metrics as $metric) {
            $value = $raw[$metric] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                $violations[] = "missing_{$split}_{$variant}_metric:{$caseId}:{$metric}";

                continue;
            }
            $score = (float) $value;
            if (! is_finite($score) || $score < 0.0 || $score > 1.0) {
                $violations[] = "invalid_{$split}_{$variant}_metric:{$caseId}:{$metric}";

                continue;
            }
            $scores[$metric] = $score;
        }
        if (array_diff(array_keys($raw), $metrics) !== []) {
            $violations[] = "unknown_{$split}_{$variant}_metric:{$caseId}";
        }

        return $scores;
    }

    /** @param list<array<string,mixed>> $cases @param list<string> $metrics @return array<string,mixed> */
    private function splitSummary(array $cases, array $metrics, string $primaryMetric): array
    {
        $summary = [
            'case_count' => count($cases),
            'metrics' => [],
            'primary_metric_improved_cases' => 0,
            'competencies' => [],
        ];
        foreach ($metrics as $metric) {
            $baseline = [];
            $candidate = [];
            foreach ($cases as $case) {
                if (isset($case['baseline'][$metric], $case['candidate'][$metric])) {
                    $baseline[] = (float) $case['baseline'][$metric];
                    $candidate[] = (float) $case['candidate'][$metric];
                    if ($metric === $primaryMetric && $case['candidate'][$metric] > $case['baseline'][$metric]) {
                        $summary['primary_metric_improved_cases']++;
                    }
                }
            }
            $baselineMean = $this->mean($baseline);
            $candidateMean = $this->mean($candidate);
            $summary['metrics'][$metric] = [
                'baseline_mean' => $baselineMean,
                'candidate_mean' => $candidateMean,
                'delta' => round($candidateMean - $baselineMean, 6),
            ];
        }

        $competencies = [];
        foreach ($cases as $case) {
            if (! isset($case['baseline'][$primaryMetric], $case['candidate'][$primaryMetric])) {
                continue;
            }
            $name = (string) $case['competency'];
            $competencies[$name]['baseline'][] = (float) $case['baseline'][$primaryMetric];
            $competencies[$name]['candidate'][] = (float) $case['candidate'][$primaryMetric];
        }
        foreach ($competencies as $name => $values) {
            $baselineMean = $this->mean($values['baseline']);
            $candidateMean = $this->mean($values['candidate']);
            $summary['competencies'][$name] = [
                'case_count' => count($values['baseline']),
                'baseline_mean' => $baselineMean,
                'candidate_mean' => $candidateMean,
                'delta' => round($candidateMean - $baselineMean, 6),
            ];
        }
        ksort($summary['competencies']);

        return $summary;
    }

    /** @param list<array<string,mixed>> $cases @param list<string> $metrics @return list<array<string,mixed>> */
    private function regressions(array $cases, array $metrics): array
    {
        $tolerance = (float) config('agent_prompt_optimization.max_case_regression', 0.0);
        $regressions = [];
        foreach ($cases as $case) {
            foreach ($metrics as $metric) {
                if (! isset($case['baseline'][$metric], $case['candidate'][$metric])) {
                    continue;
                }
                $delta = (float) $case['candidate'][$metric] - (float) $case['baseline'][$metric];
                if ($delta < -$tolerance) {
                    $regressions[] = [
                        'case' => $case['id'],
                        'competency' => $case['competency'],
                        'metric' => $metric,
                        'delta' => round($delta, 6),
                    ];
                }
            }
        }

        return $regressions;
    }

    /** @param list<float> $values */
    private function mean(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 6);
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function safeString(mixed $value): string
    {
        return is_string($value) ? mb_substr($value, 0, 200) : '';
    }

    /** @param list<string> $allowed @return list<int|string> */
    private function unknownKeys(array $value, array $allowed): array
    {
        return array_values(array_diff(array_keys($value), $allowed));
    }

    /** @return array<string,mixed> */
    private function exampleCase(
        string $id,
        string $competency,
        float $baselineQuality,
        float $candidateQuality,
        bool $training = false,
    ): array {
        $case = [
            'id' => $id,
            'competency' => $competency,
            'evidence_sha256' => hash('sha256', 'synthetic-evidence-'.$id),
            'baseline' => ['task_quality' => $baselineQuality, 'policy_compliance' => 1.0],
            'candidate' => ['task_quality' => $candidateQuality, 'policy_compliance' => 1.0],
        ];
        if ($training) {
            $case['hypothesis_ids'] = ['require_allowlist_check'];
        }

        return $case;
    }
}
