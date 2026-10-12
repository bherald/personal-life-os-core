<?php

namespace App\Services;

final class AgentMemoryRecallPolicyService
{
    /**
     * Rank already-retrieved episodic candidates without letting recency erase
     * an exact objective match or a high-value failure lesson.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    public function rank(string $query, array $candidates, int $limit): array
    {
        $candidates = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->recallEligible($candidate)
        ));
        $weights = $this->weights();

        foreach ($candidates as &$candidate) {
            $objectiveMatch = $this->objectiveMatch($query, (string) ($candidate['task'] ?? ''));
            $experienceValue = $this->experienceValue((string) ($candidate['outcome'] ?? ''));
            $score = (
                $this->unit($candidate['similarity'] ?? 0) * $weights['semantic']
                + $objectiveMatch * $weights['objective']
                + $this->unit($candidate['importance'] ?? 0) * $weights['importance']
                + $this->unit($candidate['recency'] ?? 0) * $weights['recency']
                + $experienceValue * $weights['gotcha']
            ) / array_sum($weights);

            $candidate['objective_match'] = round($objectiveMatch, 3);
            $candidate['experience_value'] = round($experienceValue, 3);
            $candidate['score'] = round($score, 4);
        }
        unset($candidate);

        usort($candidates, static function (array $left, array $right): int {
            $scoreOrder = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $similarityOrder = ((float) ($right['similarity'] ?? 0)) <=> ((float) ($left['similarity'] ?? 0));
            if ($similarityOrder !== 0) {
                return $similarityOrder;
            }

            return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
        });

        return $this->selectForContext($candidates, max(1, $limit));
    }

    /**
     * Preserve one near-scoring adverse lesson for the same objective when a
     * small context limit would otherwise be filled by duplicate successes.
     *
     * @param  list<array<string,mixed>>  $ranked
     * @return list<array<string,mixed>>
     */
    private function selectForContext(array $ranked, int $limit): array
    {
        if ($ranked === [] || $limit === 1 || count($ranked) <= $limit) {
            return array_slice($ranked, 0, $limit);
        }

        $top = $ranked[0];
        $topClass = $this->outcomeClass((string) ($top['outcome'] ?? ''));
        $scoreMargin = max(0.0, min(
            1.0,
            (float) config('agent_memory.recall.outcome_diversity_score_margin', 0.12)
        ));
        $minimumObjectiveMatch = max(0.0, min(
            1.0,
            (float) config('agent_memory.recall.outcome_diversity_min_objective_match', 0.60)
        ));
        $minimumScore = (float) ($top['score'] ?? 0.0) - $scoreMargin;
        $selectedIndexes = [0 => true];

        foreach ($ranked as $index => $candidate) {
            if ($index === 0) {
                continue;
            }
            if (! $this->contrastsWith($topClass, $this->outcomeClass((string) ($candidate['outcome'] ?? '')))) {
                continue;
            }
            if ((float) ($candidate['objective_match'] ?? 0.0) < $minimumObjectiveMatch
                || (float) ($candidate['score'] ?? 0.0) < $minimumScore) {
                continue;
            }

            $selectedIndexes[$index] = true;
            break;
        }

        foreach ($ranked as $index => $candidate) {
            if (count($selectedIndexes) >= $limit) {
                break;
            }
            $selectedIndexes[$index] = true;
        }

        return array_values(array_intersect_key($ranked, $selectedIndexes));
    }

    private function outcomeClass(string $outcome): string
    {
        return match (strtolower(trim($outcome))) {
            'success' => 'success',
            'failure', 'error', 'partial' => 'adverse',
            default => 'other',
        };
    }

    private function contrastsWith(string $left, string $right): bool
    {
        return in_array($left, ['success', 'adverse'], true)
            && in_array($right, ['success', 'adverse'], true)
            && $left !== $right;
    }

    /** @param array<string,mixed> $candidate */
    private function recallEligible(array $candidate): bool
    {
        if (array_key_exists('eligible', $candidate) && ! (bool) $candidate['eligible']) {
            return false;
        }

        foreach (['is_archived', 'forgotten', 'is_forgotten'] as $flag) {
            if ((bool) ($candidate[$flag] ?? false)) {
                return false;
            }
        }

        foreach (['forgotten_at', 'deleted_at'] as $timestamp) {
            if (($candidate[$timestamp] ?? null) !== null && trim((string) $candidate[$timestamp]) !== '') {
                return false;
            }
        }

        $supersededBy = $candidate['superseded_by'] ?? null;
        if ($supersededBy !== null && trim((string) $supersededBy) !== '' && (int) $supersededBy > 0) {
            return false;
        }

        $status = strtolower(trim((string) ($candidate['status'] ?? '')));

        return ! in_array($status, [
            'archived',
            'deleted',
            'forgotten',
            'invalidated',
            'superseded',
            'withdrawn',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{procedural:bool,episodic:bool,cross_agent:bool}
     */
    public function decideGate(array $signals): array
    {
        $usefulProcedures = max(0, (int) ($signals['useful_procedures'] ?? 0));
        $recentExperience = max(0, (int) ($signals['recent_experience'] ?? 0));
        $activeGotchas = max(0, (int) ($signals['active_gotchas'] ?? 0));
        $recentRuns = max(0, (int) ($signals['recent_runs'] ?? 0));
        $isResearch = (bool) ($signals['is_research'] ?? false);
        $isRoutine = (bool) ($signals['is_routine'] ?? false);
        $highFrequencyThreshold = max(
            1,
            (int) config('agent_memory.gating.high_frequency_run_threshold', 10)
        );

        $hasEpisodicValue = $recentExperience > 0 || $activeGotchas > 0;
        $routineSuppression = $isRoutine
            && $recentRuns > $highFrequencyThreshold
            && $activeGotchas === 0;

        return [
            'procedural' => $usefulProcedures > 0,
            'episodic' => $hasEpisodicValue && ! $routineSuppression,
            'cross_agent' => $isResearch || (! $isRoutine && $recentExperience > 0),
        ];
    }

    public function objectiveMatch(string $query, string $candidateTask): float
    {
        $queryTokens = $this->tokens($query);
        if ($queryTokens === []) {
            return 0.0;
        }

        $candidateTokens = $this->tokens($candidateTask);
        if ($candidateTokens === []) {
            return 0.0;
        }

        return count(array_intersect($queryTokens, $candidateTokens)) / count($queryTokens);
    }

    /** @return array{semantic:float,objective:float,importance:float,recency:float,gotcha:float} */
    private function weights(): array
    {
        $configured = (array) config('agent_memory.recall.weights', []);
        $defaults = [
            'semantic' => 0.55,
            'objective' => 0.20,
            'importance' => 0.10,
            'recency' => 0.05,
            'gotcha' => 0.10,
        ];

        $weights = [];
        foreach ($defaults as $name => $fallback) {
            $value = $configured[$name] ?? $fallback;
            $weights[$name] = is_numeric($value) && (float) $value >= 0
                ? (float) $value
                : $fallback;
        }

        return array_sum($weights) > 0 ? $weights : $defaults;
    }

    private function experienceValue(string $outcome): float
    {
        return match (strtolower($outcome)) {
            'failure', 'error' => 1.0,
            'partial' => 0.35,
            default => 0.0,
        };
    }

    private function unit(mixed $value): float
    {
        return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.0));
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $stopWords = [
            'and', 'are', 'for', 'from', 'into', 'that', 'the', 'then', 'this',
            'use', 'using', 'with', 'work', 'task', 'run', 'check',
        ];
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $token): bool => strlen($token) >= 3
                && ! in_array($token, $stopWords, true)
        )));
    }
}
