<?php

namespace App\Services\Ops;

final class ProspectiveIntentEvalService
{
    /**
     * @param  array<string,array<string,mixed>>|null  $scenarios
     * @return array<string,mixed>
     */
    public function evaluate(?array $scenarios = null): array
    {
        $scenarios ??= (array) config('prospective_intent_eval.scenarios', []);
        $results = [];

        foreach ($scenarios as $name => $scenario) {
            $results[] = is_array($scenario)
                ? $this->evaluateScenario((string) $name, $scenario)
                : $this->invalidScenario((string) $name, ['invalid_scenario_definition']);
        }

        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ! (bool) ($result['passed'] ?? false)
        ));

        return [
            'schema' => (string) config('prospective_intent_eval.schema', 'plos.prospective_intent_eval.v1'),
            'status' => $scenarios !== [] && $failed === [] ? 'pass' : 'fail',
            'generated_at' => now('UTC')->toIso8601String(),
            'summary' => [
                'scenarios' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
                'checks' => array_sum(array_column($results, 'check_count')),
                'violations' => array_sum(array_map(
                    static fn (array $result): int => count((array) ($result['violation_codes'] ?? [])),
                    $results
                )),
            ],
            'scenarios' => $results,
            'posture' => [
                'deterministic_fixtures' => true,
                'set_precision_recall_f1' => true,
                'exact_set_matches' => true,
                'on_time_late_miss_classification' => true,
                'wrong_content_checks' => true,
                'stale_update_checks' => true,
                'dependency_violation_checks' => true,
                'event_and_time_cues' => true,
                'monitoring_cost_counts' => true,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'runtime_policy_changes' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function compactPayload(array $payload): array
    {
        $scenarios = array_values(array_filter((array) ($payload['scenarios'] ?? []), 'is_array'));

        return [
            'schema' => $payload['schema'] ?? 'plos.prospective_intent_eval.v1',
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'failed_scenarios' => array_values(array_map(
                static fn (array $scenario): array => [
                    'scenario' => $scenario['scenario'] ?? 'unknown',
                    'violation_codes' => $scenario['violation_codes'] ?? [],
                ],
                array_filter($scenarios, static fn (array $scenario): bool => ! (bool) ($scenario['passed'] ?? false))
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @param array<string,mixed> $scenario @return array<string,mixed> */
    private function evaluateScenario(string $name, array $scenario): array
    {
        $intents = array_values(array_filter((array) ($scenario['intents'] ?? []), 'is_array'));
        $steps = array_values(array_filter((array) ($scenario['steps'] ?? []), 'is_array'));
        $updates = array_values(array_filter((array) ($scenario['updates'] ?? []), 'is_array'));
        $expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
        $definitionViolations = $this->validateDefinition($intents, $steps, $updates, $expect);

        if ($definitionViolations !== []) {
            return $this->invalidScenario($name, $definitionViolations);
        }

        $states = [];
        foreach ($intents as $intent) {
            $states[(string) $intent['id']] = [
                'due_step' => (int) $intent['due_step'],
                'revision' => (int) ($intent['revision'] ?? 1),
                'content_key' => isset($intent['content_key']) ? (string) $intent['content_key'] : null,
                'cue' => isset($intent['cue']) ? (string) $intent['cue'] : null,
                'depends_on' => array_values((array) ($intent['depends_on'] ?? [])),
                'proactive_required' => (bool) ($intent['proactive_required'] ?? false),
                'cancelled' => false,
                'completed_at' => null,
            ];
        }

        $metrics = $this->emptyMetrics();
        foreach ($steps as $step) {
            $stepIndex = (int) $step['step'];
            $this->applyUpdates($states, $updates, $stepIndex);
            $observedCues = array_values((array) ($step['observed_cues'] ?? []));
            $due = $this->dueSet($states, $stepIndex, $observedCues);
            $selections = $this->normalizeSelections((array) ($step['selected'] ?? []));
            $onTime = [];

            $metrics['monitoring_queries'] += (int) ($step['monitoring_queries'] ?? 0);
            foreach ($selections as $selection) {
                $id = $selection['id'];
                $state = $states[$id] ?? null;
                $revisionMatches = $state !== null && $selection['revision'] === $state['revision'];
                $contentMatches = $state !== null
                    && ($state['content_key'] === null || $selection['content_key'] === $state['content_key']);

                if (isset($due[$id]) && $revisionMatches && $contentMatches) {
                    $onTime[$id] = true;
                    $metrics['true_positives']++;
                    $metrics['on_time']++;
                    if ($state['proactive_required']) {
                        $metrics['proactive_required_hits']++;
                        if ($state['cue'] !== 'clock') {
                            $metrics['non_clock_proactive_hits']++;
                        }
                    }

                    continue;
                }

                $metrics['false_positives']++;
                if ($state !== null && ($state['cancelled'] || $state['completed_at'] !== null || ! $revisionMatches)) {
                    $metrics['update_violations']++;
                } elseif ($state !== null && ! $contentMatches) {
                    $metrics['wrong_content']++;
                } elseif ($state !== null && ! $this->dependenciesComplete($states, $state, $stepIndex)) {
                    $metrics['dependency_violations']++;
                } elseif ($state !== null && $state['cue'] === null && $state['due_step'] < $stepIndex) {
                    $metrics['late']++;
                } else {
                    $metrics['false_alarms']++;
                }
            }

            foreach ($due as $id => $_state) {
                if (! isset($onTime[$id])) {
                    $metrics['false_negatives']++;
                    $metrics['misses']++;
                }
            }

            $selectedIds = array_keys($onTime);
            sort($selectedIds, SORT_STRING);
            $dueIds = array_keys($due);
            sort($dueIds, SORT_STRING);
            $metrics['steps']++;
            if ($selectedIds === $dueIds && count($selections) === count($selectedIds)) {
                $metrics['exact_set_matches']++;
            }

            foreach ($selections as $selection) {
                $id = $selection['id'];
                $state = $states[$id] ?? null;
                if ($state === null || $state['cancelled'] || $state['completed_at'] !== null) {
                    continue;
                }
                if ($selection['revision'] !== $state['revision']
                    || ($state['content_key'] !== null && $selection['content_key'] !== $state['content_key'])
                    || ! $this->dependenciesComplete($states, $state, $stepIndex)
                ) {
                    continue;
                }
                if (isset($onTime[$id]) || ($state['cue'] === null && $state['due_step'] < $stepIndex)) {
                    $states[$id]['completed_at'] = $stepIndex;
                }
            }
        }

        $metrics = array_merge($metrics, $this->setMetrics($metrics));
        $checks = [];
        foreach ($expect as $key => $expected) {
            $checks[] = [
                'code' => 'metric_'.$this->safeCode((string) $key),
                'passed' => array_key_exists((string) $key, $metrics) && $metrics[(string) $key] === $expected,
            ];
        }
        $violations = array_values(array_map(
            static fn (array $check): string => $check['code'],
            array_filter($checks, static fn (array $check): bool => ! $check['passed'])
        ));

        return [
            'scenario' => $this->safeName($name),
            'passed' => $violations === [],
            'check_count' => count($checks),
            'violation_codes' => $violations,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $intents
     * @param  list<array<string,mixed>>  $steps
     * @param  list<array<string,mixed>>  $updates
     * @param  array<string,mixed>  $expect
     * @return list<string>
     */
    private function validateDefinition(array $intents, array $steps, array $updates, array $expect): array
    {
        if ($intents === [] || $steps === [] || $expect === []) {
            return ['missing_intents_steps_or_expectation'];
        }

        $violations = [];
        $ids = [];
        foreach ($intents as $intent) {
            $id = isset($intent['id']) ? (string) $intent['id'] : '';
            if (! $this->validId($id)
                || isset($ids[$id])
                || ! $this->positiveInt($intent['due_step'] ?? null)
                || (isset($intent['revision']) && ! $this->positiveInt($intent['revision']))
            ) {
                $violations[] = 'invalid_intent_definition';

                continue;
            }
            $ids[$id] = true;
        }
        foreach ($intents as $intent) {
            foreach ((array) ($intent['depends_on'] ?? []) as $dependency) {
                if (! isset($ids[(string) $dependency]) || (string) $dependency === (string) ($intent['id'] ?? '')) {
                    $violations[] = 'invalid_dependency_definition';
                }
            }
        }
        if ($this->hasDependencyCycle($intents)) {
            $violations[] = 'cyclic_dependency_definition';
        }

        $previousStep = 0;
        foreach ($steps as $step) {
            $currentStep = $step['step'] ?? null;
            if (! $this->positiveInt($currentStep) || (int) $currentStep <= $previousStep) {
                $violations[] = 'invalid_step_sequence';
                break;
            }
            $previousStep = (int) $currentStep;
        }
        foreach ($updates as $update) {
            if (! isset($ids[(string) ($update['intent_id'] ?? '')])
                || ! $this->positiveInt($update['at_step'] ?? null)
                || ! in_array((string) ($update['type'] ?? ''), ['cancel', 'reschedule', 'override'], true)
                || (isset($update['due_step']) && ! $this->positiveInt($update['due_step']))
                || (isset($update['revision']) && ! $this->positiveInt($update['revision']))
            ) {
                $violations[] = 'invalid_update_definition';
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param array<string,array<string,mixed>> $states @param list<array<string,mixed>> $updates */
    private function applyUpdates(array &$states, array $updates, int $step): void
    {
        foreach ($updates as $update) {
            if ((int) $update['at_step'] !== $step) {
                continue;
            }
            $id = (string) $update['intent_id'];
            if ($update['type'] === 'cancel') {
                $states[$id]['cancelled'] = true;

                continue;
            }
            $states[$id]['cancelled'] = false;
            $states[$id]['revision'] = (int) ($update['revision'] ?? ($states[$id]['revision'] + 1));
            if (isset($update['due_step'])) {
                $states[$id]['due_step'] = (int) $update['due_step'];
            }
            if (array_key_exists('cue', $update)) {
                $states[$id]['cue'] = $update['cue'] === null ? null : (string) $update['cue'];
            }
        }
    }

    /** @param array<string,array<string,mixed>> $states @param list<mixed> $observedCues @return array<string,array<string,mixed>> */
    private function dueSet(array $states, int $step, array $observedCues): array
    {
        $due = [];
        foreach ($states as $id => $state) {
            if ($state['cancelled'] || $state['completed_at'] !== null || ! $this->dependenciesComplete($states, $state, $step)) {
                continue;
            }
            $cueSatisfied = $state['cue'] !== null && in_array($state['cue'], $observedCues, true);
            if (($state['cue'] === null && $state['due_step'] === $step) || $cueSatisfied) {
                $due[$id] = $state;
            }
        }

        return $due;
    }

    /** @param array<string,array<string,mixed>> $states @param array<string,mixed> $state */
    private function dependenciesComplete(array $states, array $state, int $step): bool
    {
        foreach ($state['depends_on'] as $dependency) {
            $completedAt = $states[(string) $dependency]['completed_at'] ?? null;
            if ($completedAt === null || $completedAt >= $step) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $raw @return list<array{id:string,revision:int,content_key:?string}> */
    private function normalizeSelections(array $raw): array
    {
        $selections = [];
        foreach ($raw as $selection) {
            if (is_string($selection)) {
                $selections[] = ['id' => $selection, 'revision' => 1, 'content_key' => null];
            } elseif (is_array($selection)) {
                $selections[] = [
                    'id' => (string) ($selection['id'] ?? ''),
                    'revision' => (int) ($selection['revision'] ?? 1),
                    'content_key' => isset($selection['content_key']) ? (string) $selection['content_key'] : null,
                ];
            }
        }

        return $selections;
    }

    /** @param list<array<string,mixed>> $intents */
    private function hasDependencyCycle(array $intents): bool
    {
        $graph = [];
        foreach ($intents as $intent) {
            $graph[(string) ($intent['id'] ?? '')] = array_values((array) ($intent['depends_on'] ?? []));
        }
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$id])) {
                return true;
            }
            if (isset($visited[$id])) {
                return false;
            }
            $visiting[$id] = true;
            foreach ($graph[$id] ?? [] as $dependency) {
                if ($visit((string) $dependency)) {
                    return true;
                }
            }
            unset($visiting[$id]);
            $visited[$id] = true;

            return false;
        };
        foreach (array_keys($graph) as $id) {
            if ($visit($id)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,int> */
    private function emptyMetrics(): array
    {
        return array_fill_keys([
            'steps', 'true_positives', 'false_positives', 'false_negatives',
            'exact_set_matches', 'on_time', 'late', 'misses', 'false_alarms', 'wrong_content',
            'update_violations', 'dependency_violations', 'monitoring_queries',
            'proactive_required_hits', 'non_clock_proactive_hits',
        ], 0);
    }

    /** @param array<string,int> $metrics @return array{precision:float,recall:float,set_f1:float,exact_set_rate:float} */
    private function setMetrics(array $metrics): array
    {
        $precisionDenominator = $metrics['true_positives'] + $metrics['false_positives'];
        $recallDenominator = $metrics['true_positives'] + $metrics['false_negatives'];
        $precision = $precisionDenominator > 0 ? $metrics['true_positives'] / $precisionDenominator : 1.0;
        $recall = $recallDenominator > 0 ? $metrics['true_positives'] / $recallDenominator : 1.0;
        $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;

        return [
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'set_f1' => round($f1, 4),
            'exact_set_rate' => round($metrics['exact_set_matches'] / max(1, $metrics['steps']), 4),
        ];
    }

    /** @param list<string> $violations @return array<string,mixed> */
    private function invalidScenario(string $name, array $violations): array
    {
        return [
            'scenario' => $this->safeName($name),
            'passed' => false,
            'check_count' => max(1, count($violations)),
            'violation_codes' => array_values(array_unique($violations)),
        ];
    }

    private function positiveInt(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }

    private function validId(string $id): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) === 1;
    }

    private function safeName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,80}$/', $name) === 1 ? $name : 'invalid_scenario';
    }

    private function safeCode(string $code): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $code));

        return trim(substr($safe, 0, 80), '_') ?: 'unknown';
    }
}
