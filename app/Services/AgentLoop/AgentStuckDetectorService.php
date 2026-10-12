<?php

namespace App\Services\AgentLoop;

final class AgentStuckDetectorService
{
    /** @var array{history_limit:int,canonical_json_max_bytes:int,action_observation_threshold:int,action_error_threshold:int,alternating_pattern_threshold:int,periodic_pattern_max_period:int,periodic_pattern_repetitions:int} */
    private array $policy;

    public function __construct(?array $policy = null)
    {
        $configured = $policy ?? (array) config('agents.stuck_detection', []);
        $actionObservationThreshold = max(2, min(20, (int) ($configured['action_observation_threshold'] ?? 4)));
        $actionErrorThreshold = max(2, min(20, (int) ($configured['action_error_threshold'] ?? 3)));
        $alternatingPatternThreshold = max(4, min(20, (int) ($configured['alternating_pattern_threshold'] ?? 6)));
        if ($alternatingPatternThreshold % 2 !== 0) {
            $alternatingPatternThreshold = min(20, $alternatingPatternThreshold + 1);
        }
        $periodicPatternMaxPeriod = max(3, min(10, (int) ($configured['periodic_pattern_max_period'] ?? 5)));
        $periodicPatternRepetitions = max(2, min(10, (int) ($configured['periodic_pattern_repetitions'] ?? 3)));

        $this->policy = [
            'history_limit' => max(
                $actionObservationThreshold,
                $actionErrorThreshold,
                $alternatingPatternThreshold,
                $periodicPatternMaxPeriod * $periodicPatternRepetitions,
                min(100, (int) ($configured['history_limit'] ?? 20)),
            ),
            'canonical_json_max_bytes' => max(1024, min(1048576, (int) ($configured['canonical_json_max_bytes'] ?? 65536))),
            'action_observation_threshold' => $actionObservationThreshold,
            'action_error_threshold' => $actionErrorThreshold,
            'alternating_pattern_threshold' => $alternatingPatternThreshold,
            'periodic_pattern_max_period' => $periodicPatternMaxPeriod,
            'periodic_pattern_repetitions' => $periodicPatternRepetitions,
        ];
    }

    /**
     * Build a content-free semantic event for one completed tool action.
     *
     * @return array{tool:string,action_fingerprint:string,observation_fingerprint:string,pair_fingerprint:string,success:bool}
     */
    public function event(string $tool, array $params, bool $success, string $result): array
    {
        $actionFingerprint = hash('sha256', $tool."\n".$this->canonicalValue($params));
        $observationFingerprint = hash('sha256', ($success ? 'success' : 'failure')."\n".$this->canonicalResult($result));

        return [
            'tool' => $tool,
            'action_fingerprint' => $actionFingerprint,
            'observation_fingerprint' => $observationFingerprint,
            'pair_fingerprint' => hash('sha256', $actionFingerprint."\n".$observationFingerprint),
            'success' => $success,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $history
     * @return array{stuck:bool,pattern:?string,reason:?string,occurrences:int,history_scanned:int,pattern_length:?int,repetitions:?int}
     */
    public function detect(array $history): array
    {
        $history = array_values(array_slice($history, -$this->policy['history_limit']));

        $result = $this->identicalPairLoop($history)
            ?? $this->repeatedActionErrorLoop($history)
            ?? $this->alternatingPairLoop($history)
            ?? $this->periodicPairLoop($history);

        return $result ?? [
            'stuck' => false,
            'pattern' => null,
            'reason' => null,
            'occurrences' => 0,
            'history_scanned' => count($history),
            'pattern_length' => null,
            'repetitions' => null,
        ];
    }

    /** @param list<array<string,mixed>> $history */
    private function identicalPairLoop(array $history): ?array
    {
        $threshold = $this->policy['action_observation_threshold'];
        if (count($history) < $threshold) {
            return null;
        }

        $window = array_slice($history, -$threshold);
        if (count(array_unique(array_column($window, 'pair_fingerprint'))) !== 1) {
            return null;
        }

        return $this->stuckResult(
            'identical_action_observation',
            'The same tool action produced the same observation repeatedly.',
            $threshold,
            count($history),
        );
    }

    /** @param list<array<string,mixed>> $history */
    private function repeatedActionErrorLoop(array $history): ?array
    {
        $threshold = $this->policy['action_error_threshold'];
        if (count($history) < $threshold) {
            return null;
        }

        $window = array_slice($history, -$threshold);
        if (count(array_unique(array_column($window, 'action_fingerprint'))) !== 1) {
            return null;
        }
        if (count(array_filter($window, static fn (array $event): bool => ($event['success'] ?? true) === false)) !== $threshold) {
            return null;
        }

        return $this->stuckResult(
            'repeated_action_error',
            'The same tool action failed repeatedly.',
            $threshold,
            count($history),
        );
    }

    /** @param list<array<string,mixed>> $history */
    private function alternatingPairLoop(array $history): ?array
    {
        $threshold = $this->policy['alternating_pattern_threshold'];
        if ($threshold % 2 !== 0 || count($history) < $threshold) {
            return null;
        }

        $pairs = array_column(array_slice($history, -$threshold), 'pair_fingerprint');
        if (($pairs[0] ?? null) === ($pairs[1] ?? null)) {
            return null;
        }

        for ($index = 2; $index < $threshold; $index++) {
            if ($pairs[$index] !== $pairs[$index % 2]) {
                return null;
            }
        }

        return $this->stuckResult(
            'alternating_action_observation',
            'Two tool action-observation pairs alternated without progress.',
            $threshold,
            count($history),
            2,
            intdiv($threshold, 2),
        );
    }

    /** @param list<array<string,mixed>> $history */
    private function periodicPairLoop(array $history): ?array
    {
        // Adapted to PLOS fingerprints from OmniCoreAgent's MIT pattern-loop scan.
        $repetitions = $this->policy['periodic_pattern_repetitions'];
        $maxPeriod = min($this->policy['periodic_pattern_max_period'], intdiv(count($history), $repetitions));

        for ($period = 3; $period <= $maxPeriod; $period++) {
            $required = $period * $repetitions;
            $pairs = array_column(array_slice($history, -$required), 'pair_fingerprint');
            $pattern = array_slice($pairs, -$period);
            $matches = true;

            for ($offset = 0; $offset < $required; $offset++) {
                if (($pairs[$offset] ?? null) !== ($pattern[$offset % $period] ?? null)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $this->stuckResult(
                    'periodic_action_observation',
                    "A {$period}-step tool action-observation cycle repeated {$repetitions} times without progress.",
                    $required,
                    count($history),
                    $period,
                    $repetitions,
                );
            }
        }

        return null;
    }

    /** @return array{stuck:true,pattern:string,reason:string,occurrences:int,history_scanned:int,pattern_length:?int,repetitions:?int} */
    private function stuckResult(
        string $pattern,
        string $reason,
        int $occurrences,
        int $historyScanned,
        ?int $patternLength = null,
        ?int $repetitions = null,
    ): array {
        return [
            'stuck' => true,
            'pattern' => $pattern,
            'reason' => $reason,
            'occurrences' => $occurrences,
            'history_scanned' => $historyScanned,
            'pattern_length' => $patternLength,
            'repetitions' => $repetitions,
        ];
    }

    private function canonicalResult(string $result): string
    {
        $trimmed = trim(str_replace("\r\n", "\n", $result));
        if ($trimmed === '') {
            return '';
        }

        if (strlen($trimmed) > $this->policy['canonical_json_max_bytes']) {
            return $trimmed;
        }

        $decoded = json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $this->canonicalValue($decoded)
            : $trimmed;
    }

    private function canonicalValue(mixed $value): string
    {
        $normalized = $this->normalizeValue($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? get_debug_type($value) : $json;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }
}
