<?php

namespace App\Services\Ops;

class AgentProcedureIntegrityService
{
    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  list<mixed>  $milestones
     * @param  list<mixed>  $minefields
     * @return array<string, mixed>
     */
    public function evaluate(
        array $observations,
        array $milestones,
        array $minefields,
        bool $taskSuccess
    ): array {
        $observations = array_values(array_filter($observations, 'is_array'));
        $milestoneDefinitions = [];
        $milestoneOrder = [];
        $invalidDefinitions = [];

        foreach ($milestones as $index => $milestone) {
            if (! is_array($milestone)) {
                $invalidDefinitions[] = 'milestone_'.(int) $index;

                continue;
            }

            $id = $this->safeId($milestone['id'] ?? null, 'milestone_'.(int) $index);
            if (array_key_exists($id, $milestoneDefinitions)) {
                $invalidDefinitions[] = $id;

                continue;
            }

            $dependencies = array_values(array_filter(array_map(
                fn (mixed $dependency): string => $this->safeId($dependency, ''),
                (array) ($milestone['after'] ?? [])
            )));
            $milestoneDefinitions[$id] = [
                'dependencies' => array_values(array_unique($dependencies)),
                'match' => is_array($milestone['match'] ?? null) ? $milestone['match'] : [],
            ];
            $milestoneOrder[] = $id;
        }

        [$evaluationOrder, $graphInvalidDefinitions] = $this->milestoneEvaluationOrder(
            $milestoneDefinitions,
            $milestoneOrder
        );
        $invalidDefinitions = [...$invalidDefinitions, ...$graphInvalidDefinitions];
        $matchedMilestones = [];
        $missingMilestones = [];
        $usedObservationIndexes = [];

        foreach ($evaluationOrder as $id) {
            $definition = $milestoneDefinitions[$id];
            $dependencyIndexes = [];
            $dependenciesMet = true;
            foreach ($definition['dependencies'] as $dependency) {
                if (! array_key_exists($dependency, $matchedMilestones)) {
                    $dependenciesMet = false;
                    break;
                }
                $dependencyIndexes[] = $matchedMilestones[$dependency];
            }

            if (! $dependenciesMet) {
                $missingMilestones[] = $id;

                continue;
            }

            $afterIndex = $dependencyIndexes === [] ? -1 : max($dependencyIndexes);
            $observationIndex = $this->findObservation(
                $observations,
                $definition['match'],
                $afterIndex,
                $usedObservationIndexes
            );
            if ($observationIndex === null) {
                $missingMilestones[] = $id;

                continue;
            }

            $matchedMilestones[$id] = $observationIndex;
            $usedObservationIndexes[] = $observationIndex;
        }

        $triggeredMinefields = [];
        foreach ($minefields as $index => $minefield) {
            if (! is_array($minefield)) {
                $invalidDefinitions[] = 'minefield_'.(int) $index;

                continue;
            }

            $id = $this->safeId($minefield['id'] ?? null, 'minefield_'.(int) $index);
            $match = is_array($minefield['match'] ?? null) ? $minefield['match'] : [];
            if ($this->findObservation($observations, $match) !== null) {
                $triggeredMinefields[] = $id;
            }
        }

        $policyViolations = count(array_filter(
            $observations,
            static fn (array $observation): bool => ($observation['allowed'] ?? null) === false
        ));
        $procedurePassed = $missingMilestones === []
            && $triggeredMinefields === []
            && $policyViolations === 0
            && $invalidDefinitions === [];

        return [
            'schema' => 'plos.agent_procedure_integrity.v1',
            'procedure_passed' => $procedurePassed,
            'task_success' => $taskSuccess,
            'corrupt_success' => $taskSuccess && ! $procedurePassed,
            'observation_count' => count($observations),
            'milestone_count' => count($milestones),
            'milestones_met' => array_keys($matchedMilestones),
            'missing_milestones' => $missingMilestones,
            'minefields_triggered' => array_values(array_unique($triggeredMinefields)),
            'policy_violation_count' => $policyViolations,
            'invalid_definition_count' => count(array_unique($invalidDefinitions)),
        ];
    }

    /**
     * Resolve a stable topological order so declaration order is not mistaken
     * for an execution dependency. Unknown and cyclic definitions fail closed.
     *
     * @param  array<string,array{dependencies:list<string>,match:array<string,mixed>}>  $definitions
     * @param  list<string>  $definitionOrder
     * @return array{list<string>,list<string>}
     */
    private function milestoneEvaluationOrder(array $definitions, array $definitionOrder): array
    {
        $invalid = [];
        $eligible = [];
        foreach ($definitionOrder as $id) {
            $unknownDependency = count(array_filter(
                $definitions[$id]['dependencies'],
                static fn (string $dependency): bool => ! array_key_exists($dependency, $definitions)
            )) > 0;
            if ($unknownDependency) {
                $invalid[] = $id;
            } else {
                $eligible[$id] = true;
            }
        }

        $ordered = [];
        while ($eligible !== []) {
            $progress = false;
            foreach ($definitionOrder as $id) {
                if (! isset($eligible[$id])) {
                    continue;
                }

                $unresolved = array_filter(
                    $definitions[$id]['dependencies'],
                    static fn (string $dependency): bool => ! in_array($dependency, $ordered, true)
                );
                if ($unresolved !== []) {
                    continue;
                }

                $ordered[] = $id;
                unset($eligible[$id]);
                $progress = true;
            }

            if (! $progress) {
                $invalid = [...$invalid, ...array_keys($eligible)];
                break;
            }
        }

        return [$ordered, array_values(array_unique($invalid))];
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, mixed>  $match
     * @param  list<int>  $excludedIndexes
     */
    private function findObservation(
        array $observations,
        array $match,
        int $afterIndex = -1,
        array $excludedIndexes = []
    ): ?int {
        if ($match === []) {
            return null;
        }

        foreach ($observations as $index => $observation) {
            if ($index <= $afterIndex || in_array($index, $excludedIndexes, true)) {
                continue;
            }
            if ($this->observationMatches($observation, $match)) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $observation @param array<string, mixed> $match */
    private function observationMatches(array $observation, array $match): bool
    {
        foreach ($match as $key => $expected) {
            if ($key === 'param_keys') {
                $actualKeys = array_values(array_filter((array) ($observation['param_keys'] ?? []), 'is_string'));
                foreach ((array) $expected as $requiredKey) {
                    if (! is_string($requiredKey) || ! in_array($requiredKey, $actualKeys, true)) {
                        return false;
                    }
                }

                continue;
            }

            if ($key === 'params') {
                if (! is_array($expected)
                    || ! is_array($observation['params'] ?? null)
                    || $this->normalizeParameters($observation['params']) !== $this->normalizeParameters($expected)
                ) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists((string) $key, $observation)
                || $observation[(string) $key] !== $expected
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $params @return array<mixed> */
    private function normalizeParameters(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->normalizeParameters($value);
            }
        }
        if (! array_is_list($params)) {
            ksort($params, SORT_STRING);
        }

        return $params;
    }

    private function safeId(mixed $value, string $fallback): string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        $safe = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $value), '_');

        return $safe !== '' ? substr($safe, 0, 80) : $fallback;
    }
}
