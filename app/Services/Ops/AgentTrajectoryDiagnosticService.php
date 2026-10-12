<?php

namespace App\Services\Ops;

class AgentTrajectoryDiagnosticService
{
    public const SCHEMA = 'plos.agent_trajectory_diagnostics.v1';

    private const ACTIONS = ['search', 'file_read', 'file_write', 'validation', 'plan', 'other'];

    public function canonicalAction(string $tool, mixed $params = null): string
    {
        return $this->classify($tool, $params);
    }

    /** @return array<string, mixed> */
    public function analyze(mixed $rawCalls): array
    {
        $calls = $this->calls($rawCalls);
        $searchLoopThreshold = $this->boundedConfig('search_loop_threshold', 10, 3, 50);
        $redundantSearchWindow = $this->boundedConfig('redundant_search_window', 10, 2, 50);
        $tailValidationWindow = $this->boundedConfig('tail_validation_window', 5, 1, 20);
        $planWriteThreshold = $this->boundedConfig('structured_plan_write_threshold', 5, 2, 20);

        $counts = array_fill_keys(self::ACTIONS, 0);
        $failedWorkActions = 0;
        $maxSearchReadStreak = 0;
        $currentSearchReadStreak = 0;
        $searchLoopFirstStep = null;
        $redundantSearchFirstStep = null;
        $redundantSearchCount = 0;
        $recentSearchTargets = [];
        $reReadChurnFirstStep = null;
        $reReadChurnCount = 0;
        $recentReadTargets = [];
        $firstWriteStep = null;
        $lastWriteStep = null;
        $lastSuccessfulValidationStep = null;
        $firstPlanStep = null;
        $pendingWriteCheckpointStep = null;
        $writeCheckpointCount = 0;
        $validatedWriteCheckpointCount = 0;
        $unvalidatedWriteCheckpointCount = 0;
        $firstUnvalidatedWriteStep = null;

        foreach ($calls as $index => $call) {
            $step = $index + 1;
            $action = $call['action'];
            $counts[$action]++;

            if (! $call['success']) {
                $failedWorkActions++;
            }

            if (in_array($action, ['search', 'file_read'], true)) {
                $currentSearchReadStreak++;
                $maxSearchReadStreak = max($maxSearchReadStreak, $currentSearchReadStreak);
                if ($searchLoopFirstStep === null && $currentSearchReadStreak >= $searchLoopThreshold) {
                    $searchLoopFirstStep = $step - $searchLoopThreshold + 1;
                }
            } else {
                $currentSearchReadStreak = 0;
            }

            if ($action === 'search' && $call['target_fingerprint'] !== null) {
                $fingerprint = $call['target_fingerprint'];
                $priorSteps = $recentSearchTargets[$fingerprint] ?? [];
                $priorSteps = array_values(array_filter(
                    $priorSteps,
                    static fn (int $prior): bool => $step - $prior < $redundantSearchWindow
                ));
                if ($priorSteps !== []) {
                    $redundantSearchCount++;
                    $redundantSearchFirstStep ??= $step;
                }
                $priorSteps[] = $step;
                $recentSearchTargets[$fingerprint] = $priorSteps;
            }

            if ($action === 'file_read' && $call['target_fingerprint'] !== null) {
                $fingerprint = $call['target_fingerprint'];
                $priorSteps = $recentReadTargets[$fingerprint] ?? [];
                $priorSteps = array_values(array_filter(
                    $priorSteps,
                    static fn (int $prior): bool => $step - $prior < $redundantSearchWindow
                        && ($lastWriteStep === null || $prior > $lastWriteStep)
                ));
                if (count($priorSteps) >= 2) {
                    $reReadChurnCount++;
                    $reReadChurnFirstStep ??= $step;
                }
                $priorSteps[] = $step;
                $recentReadTargets[$fingerprint] = $priorSteps;
            }

            if ($action === 'file_write') {
                $firstWriteStep ??= $step;
                $lastWriteStep = $step;
                if ($call['success']) {
                    if ($pendingWriteCheckpointStep !== null) {
                        $unvalidatedWriteCheckpointCount++;
                        $firstUnvalidatedWriteStep ??= $pendingWriteCheckpointStep;
                    }
                    $pendingWriteCheckpointStep = $step;
                    $writeCheckpointCount++;
                }
            }
            if ($action === 'validation' && $call['success']) {
                $lastSuccessfulValidationStep = $step;
                if ($pendingWriteCheckpointStep !== null) {
                    $validatedWriteCheckpointCount++;
                    $pendingWriteCheckpointStep = null;
                }
            }
            if ($action === 'plan') {
                $firstPlanStep ??= $step;
            }
        }

        $tailValidationPassed = null;
        if ($lastWriteStep !== null) {
            $tailStart = max($lastWriteStep + 1, count($calls) - $tailValidationWindow + 1);
            $tailValidationPassed = $lastSuccessfulValidationStep !== null
                && $lastSuccessfulValidationStep >= $tailStart;
        }

        $structuredPlanPresent = null;
        if ($counts['file_write'] >= $planWriteThreshold) {
            $structuredPlanPresent = $firstPlanStep !== null
                && $firstWriteStep !== null
                && $firstPlanStep < $firstWriteStep;
        }

        if ($pendingWriteCheckpointStep !== null) {
            $unvalidatedWriteCheckpointCount++;
            $firstUnvalidatedWriteStep ??= $pendingWriteCheckpointStep;
        }
        $writeCheckpointValidationCoverage = $writeCheckpointCount > 0
            ? round($validatedWriteCheckpointCount / $writeCheckpointCount, 4)
            : null;

        $antiPatterns = [];
        if ($searchLoopFirstStep !== null) {
            $antiPatterns[] = 'search_loop';
        }
        if ($redundantSearchCount > 0) {
            $antiPatterns[] = 'redundant_search';
        }
        if ($reReadChurnCount > 0) {
            $antiPatterns[] = 're_read_churn';
        }
        if ($tailValidationPassed === false) {
            $antiPatterns[] = 'no_formal_tail_validation';
        }
        if ($structuredPlanPresent === false) {
            $antiPatterns[] = 'structured_plan_absence';
        }
        if ($unvalidatedWriteCheckpointCount > 0) {
            $antiPatterns[] = 'unvalidated_write_checkpoint';
        }

        return [
            'schema' => self::SCHEMA,
            'canonical_action_sequence' => array_column($calls, 'action'),
            'anti_patterns' => $antiPatterns,
            'metrics' => [
                'tool_calls' => count($calls),
                'search_actions' => $counts['search'],
                'read_actions' => $counts['file_read'],
                'write_actions' => $counts['file_write'],
                'validation_actions' => $counts['validation'],
                'plan_actions' => $counts['plan'],
                'other_actions' => $counts['other'],
                'failed_work_actions' => $failedWorkActions,
                'max_search_read_streak' => $maxSearchReadStreak,
                'redundant_search_count' => $redundantSearchCount,
                're_read_churn_count' => $reReadChurnCount,
                'tail_validation_passed' => $tailValidationPassed,
                'structured_plan_present' => $structuredPlanPresent,
                'write_checkpoint_count' => $writeCheckpointCount,
                'validated_write_checkpoint_count' => $validatedWriteCheckpointCount,
                'unvalidated_write_checkpoint_count' => $unvalidatedWriteCheckpointCount,
                'write_checkpoint_validation_coverage' => $writeCheckpointValidationCoverage,
            ],
            'localization' => [
                'search_loop_first_step' => $searchLoopFirstStep,
                'redundant_search_first_step' => $redundantSearchFirstStep,
                're_read_churn_first_step' => $reReadChurnFirstStep,
                'first_write_step' => $firstWriteStep,
                'last_write_step' => $lastWriteStep,
                'last_successful_validation_step' => $lastSuccessfulValidationStep,
                'first_unvalidated_write_step' => $firstUnvalidatedWriteStep,
            ],
            'posture' => [
                'deterministic' => true,
                'diagnostic_only' => true,
                'promotion_authority' => false,
                'semantic_labeler_used' => false,
                'oracle_used' => false,
                'content_included' => false,
                'raw_tool_names_included' => false,
                'raw_parameters_included' => false,
                'build_health_claimed' => false,
                'test_suite_stability_claimed' => false,
            ],
        ];
    }

    /** @return list<array{action:string,success:bool,target_fingerprint:?string}> */
    private function calls(mixed $rawCalls): array
    {
        if (! is_array($rawCalls)) {
            return [];
        }

        $calls = [];
        foreach (array_slice($rawCalls, 0, 500) as $call) {
            if (! is_array($call)) {
                continue;
            }
            $tool = $this->safeToken($call['tool'] ?? null);
            if ($tool === null) {
                continue;
            }

            $explicit = $this->safeToken($call['canonical_action'] ?? null);
            $action = in_array($explicit, self::ACTIONS, true)
                ? $explicit
                : $this->classify($tool, $call['params'] ?? null);

            $calls[] = [
                'action' => $action,
                'success' => ($call['success'] ?? false) === true,
                'target_fingerprint' => match ($action) {
                    'search' => $this->targetFingerprint(
                        $call['params'] ?? null,
                        (array) config('agent_trajectory.diagnostics.search_target_keys', [])
                    ),
                    'file_read' => $this->targetFingerprint(
                        $call['params'] ?? null,
                        (array) config('agent_trajectory.diagnostics.read_target_keys', [])
                    ),
                    default => null,
                },
            ];
        }

        return $calls;
    }

    private function classify(string $tool, mixed $params): string
    {
        $normalized = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $tool));
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', $normalized), '_');
        $tokens = array_values(array_filter(explode('_', $normalized)));
        $terms = config('agent_trajectory.diagnostics.canonical_action_terms', []);

        foreach (['validation', 'plan', 'file_write', 'search', 'file_read'] as $action) {
            foreach ((array) ($terms[$action] ?? []) as $term) {
                $term = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '_', (string) $term), '_'));
                if ($term !== '' && str_contains("_{$normalized}_", "_{$term}_")) {
                    return $action;
                }
            }
        }

        if (array_intersect($tokens, ['command', 'exec', 'shell', 'terminal']) !== []) {
            $command = $this->commandText($params);
            if ($command !== null) {
                if (preg_match('/\b(test|phpunit|pest|lint|build|audit|check|smoke|validate|verify)\b/', $command) === 1) {
                    return 'validation';
                }
                if ($this->isShellWriteCommand($command)) {
                    return 'file_write';
                }
                if (preg_match('/\b(rg|grep|ag|find)\b/', $command) === 1) {
                    return 'search';
                }
                if (preg_match('/\b(cat|sed|head|tail|less|more)\b/', $command) === 1) {
                    return 'file_read';
                }
            }
        }

        return 'other';
    }

    private function isShellWriteCommand(string $command): bool
    {
        return preg_match('/\b(?:touch|mkdir|install|cp|mv|rm|truncate)\b/', $command) === 1
            || preg_match('/\b(?:sed|perl)\s+-(?:[^\s]*i|[^\s]*p[^\s]*i)\b/', $command) === 1
            || preg_match('/\b(?:cat|printf|echo)\b[^\n;]*(?:>>?|\|\s*tee\b)/', $command) === 1;
    }

    private function commandText(mixed $params): ?string
    {
        if (! is_array($params)) {
            return null;
        }
        foreach ((array) config('agent_trajectory.diagnostics.command_param_keys', []) as $key) {
            $value = $params[(string) $key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtolower(mb_substr(trim($value), 0, 500));
            }
        }

        return null;
    }

    /** @param list<mixed> $keys */
    private function targetFingerprint(mixed $params, array $keys): ?string
    {
        if (! is_array($params)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $params[(string) $key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
            if ($normalized !== '') {
                return hash('sha256', mb_substr($normalized, 0, 500));
            }
        }

        return null;
    }

    private function boundedConfig(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) config("agent_trajectory.diagnostics.{$key}", $default)));
    }

    private function safeToken(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && preg_match('/\A[a-zA-Z0-9_.:-]{1,120}\z/D', $value) === 1
            ? $value
            : null;
    }
}
