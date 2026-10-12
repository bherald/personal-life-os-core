<?php

namespace App\Services;

use Illuminate\Support\Collection;

class OllamaEvalAssessmentService
{
    public const SCHEMA = 'plos.ollama_eval_assessment.v1';

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, string>  $expectedCaseIds
     * @return array<string, mixed>
     */
    public function assess(array $results, array $expectedCaseIds): array
    {
        $resultsByCase = collect($results)->keyBy('case_id');
        $evaluatedCaseIds = $resultsByCase->keys()->map(static fn ($id): string => (string) $id)->all();
        $missingCaseIds = array_values(array_diff($expectedCaseIds, $evaluatedCaseIds));
        $unexpectedCaseIds = array_values(array_diff($evaluatedCaseIds, $expectedCaseIds));
        $caseFailures = $resultsByCase
            ->filter(static fn (array $result): bool => ! (bool) ($result['passed'] ?? false))
            ->keys()
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $scoreRatios = $resultsByCase
            ->map(static fn (array $result): float => self::scoreRatio($result))
            ->values();
        $averageScore = $scoreRatios->isNotEmpty()
            ? round((float) $scoreRatios->average(), 4)
            : 0.0;
        $thresholdVerdict = $this->thresholdVerdict($averageScore);
        $regressions = $this->assessRegressions($resultsByCase);
        $criticalFailures = collect($regressions)
            ->filter(static fn (array $row): bool => (bool) $row['critical'] && ! (bool) $row['passed'])
            ->keys()
            ->values()
            ->all();
        $actualProviders = $resultsByCase
            ->pluck('provider')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
        $actualModels = $resultsByCase
            ->pluck('model')
            ->filter(static fn ($value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
        $instanceMatches = $resultsByCase
            ->every(static fn (array $result): bool => (bool) ($result['instance_match'] ?? true));
        $requestedTargetsMatch = $resultsByCase
            ->every(static fn (array $result): bool => (bool) ($result['target_match'] ?? true));
        $targetConsistent = count($actualProviders) === 1
            && count($actualModels) === 1
            && $instanceMatches
            && $requestedTargetsMatch;
        $coverageComplete = $missingCaseIds === [] && $unexpectedCaseIds === [];
        $structuredResults = $resultsByCase->filter(static fn (array $result): bool => array_key_exists(
            'schema_compliant',
            is_array($result['checks'] ?? null) ? $result['checks'] : []
        ));
        $schemaCompliantCount = $structuredResults->filter(static fn (array $result): bool => (bool) data_get(
            $result,
            'checks.schema_compliant',
            false
        ))->count();
        $structuredResultCount = $structuredResults->count();
        $promotionEligible = $coverageComplete
            && $targetConsistent
            && $caseFailures === []
            && $criticalFailures === []
            && $thresholdVerdict === 'promote';

        $decision = match (true) {
            ! $coverageComplete => 'hold_incomplete',
            ! $targetConsistent => 'hold_mixed_target',
            $criticalFailures !== [] => 'hold_critical_regression',
            $caseFailures !== [] => 'hold_case_failure',
            default => $thresholdVerdict,
        };

        return [
            'schema' => self::SCHEMA,
            'score_scale' => 'ratio_0_1',
            'average_score_ratio' => $averageScore,
            'threshold_verdict' => $thresholdVerdict,
            'decision' => $decision,
            'promotion_eligible' => $promotionEligible,
            'target_evidence' => [
                'consistent' => $targetConsistent,
                'actual_providers' => $actualProviders,
                'actual_models' => $actualModels,
                'requested_instance_matched' => $instanceMatches,
                'requested_target_matched' => $requestedTargetsMatch,
            ],
            'coverage' => [
                'complete' => $coverageComplete,
                'expected_case_count' => count($expectedCaseIds),
                'evaluated_case_count' => count($evaluatedCaseIds),
                'missing_case_ids' => $missingCaseIds,
                'unexpected_case_ids' => $unexpectedCaseIds,
            ],
            'structured_output_evidence' => [
                'schema_version' => (string) config('ollama_output_schemas.schema_version', 'unknown'),
                'evaluated_case_count' => $structuredResultCount,
                'compliant_case_count' => $schemaCompliantCount,
                'compliance_ratio' => $structuredResultCount > 0
                    ? round($schemaCompliantCount / $structuredResultCount, 4)
                    : null,
                'all_compliant' => $structuredResultCount > 0
                    && $schemaCompliantCount === $structuredResultCount,
            ],
            'case_failures' => $caseFailures,
            'critical_regressions' => $regressions,
            'critical_failures' => $criticalFailures,
            'task_readiness' => $this->taskReadiness($resultsByCase, $expectedCaseIds),
            'authority' => [
                'read_only' => true,
                'routes_changed' => false,
                'model_promotion_applied' => false,
                'genealogy_writes_applied' => false,
                'operator_review_bypassed' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $result */
    private static function scoreRatio(array $result): float
    {
        if (is_numeric($result['score_ratio'] ?? null)) {
            return max(0.0, min(1.0, (float) $result['score_ratio']));
        }

        if (is_numeric($result['score_pct'] ?? null)) {
            return max(0.0, min(1.0, (float) $result['score_pct'] / 100));
        }

        return is_numeric($result['score'] ?? null)
            ? max(0.0, min(1.0, (float) $result['score'] / 100))
            : 0.0;
    }

    private function thresholdVerdict(float $score): string
    {
        $thresholds = (array) config('ollama_eval.thresholds', []);

        if ($score >= (float) ($thresholds['promotion_score_min'] ?? 0.75)) {
            return 'promote';
        }
        if ($score >= (float) ($thresholds['fallback_score_min'] ?? 0.55)) {
            return 'hold';
        }
        if ($score > (float) ($thresholds['rejection_score_max'] ?? 0.40)) {
            return 'fallback';
        }

        return 'reject';
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $resultsByCase
     * @return array<string, array<string, mixed>>
     */
    private function assessRegressions($resultsByCase): array
    {
        $assessments = [];

        foreach ((array) config('ollama_eval.regression_cases', []) as $name => $policy) {
            if (! is_array($policy)) {
                continue;
            }

            $caseIds = array_values(array_filter((array) ($policy['case_ids'] ?? []), 'is_string'));
            $requiredChecks = array_values(array_filter((array) ($policy['required_checks'] ?? []), 'is_string'));
            $passedSignals = 0;
            $totalSignals = 0;
            $missing = [];

            foreach ($caseIds as $caseId) {
                $result = $resultsByCase->get($caseId);
                if (! is_array($result)) {
                    $missing[] = $caseId;

                    continue;
                }

                if ($requiredChecks === []) {
                    $totalSignals++;
                    $passedSignals += (bool) ($result['passed'] ?? false) ? 1 : 0;

                    continue;
                }

                $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
                foreach ($requiredChecks as $check) {
                    $totalSignals++;
                    $passedSignals += (bool) ($checks[$check] ?? false) ? 1 : 0;
                }
            }

            $score = $totalSignals > 0 ? round($passedSignals / $totalSignals, 4) : 0.0;
            $minimum = (float) ($policy['minimum_score'] ?? 1.0);
            $assessments[(string) $name] = [
                'score_ratio' => $score,
                'minimum_score_ratio' => $minimum,
                'passed' => $missing === [] && $score >= $minimum,
                'critical' => (bool) ($policy['failure_is_critical'] ?? false),
                'case_ids' => $caseIds,
                'required_checks' => $requiredChecks,
                'missing_case_ids' => $missing,
            ];
        }

        return $assessments;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $resultsByCase
     * @param  array<int, string>  $expectedCaseIds
     * @return array<string, array<string, mixed>>
     */
    private function taskReadiness($resultsByCase, array $expectedCaseIds): array
    {
        $cases = collect(config('ollama_eval.eval_cases', []))->keyBy('id');
        $expectedByTask = collect($expectedCaseIds)
            ->map(fn (string $caseId): ?array => is_array($cases->get($caseId)) ? $cases->get($caseId) : null)
            ->filter()
            ->groupBy('task_class');
        $routes = [];
        foreach ((array) config('ollama_eval.routing', []) as $route => $tasks) {
            foreach ((array) $tasks as $task) {
                $routes[(string) $task] = (string) $route;
            }
        }

        $readiness = [];
        foreach ($expectedByTask as $taskClass => $taskCases) {
            $caseIds = $taskCases->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            $evaluated = array_values(array_filter($caseIds, fn (string $id): bool => $resultsByCase->has($id)));
            $failed = array_values(array_filter($evaluated, fn (string $id): bool => ! (bool) ($resultsByCase->get($id)['passed'] ?? false)));
            $route = $routes[(string) $taskClass] ?? 'unclassified';
            $status = match (true) {
                count($evaluated) !== count($caseIds) => 'incomplete',
                $failed !== [] => 'failed',
                $route === 'local_first' => 'ready_local_first',
                $route === 'guarded_local' => 'ready_with_human_review',
                $route === 'never_local_alone' => 'never_local_alone',
                default => 'review_route',
            };

            $readiness[(string) $taskClass] = [
                'route' => $route,
                'status' => $status,
                'case_ids' => $caseIds,
                'failed_case_ids' => $failed,
                'human_review_required' => $route !== 'local_first',
            ];
        }

        ksort($readiness);

        return $readiness;
    }
}
