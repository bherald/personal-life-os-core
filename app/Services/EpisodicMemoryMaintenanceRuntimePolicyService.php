<?php

namespace App\Services;

use RuntimeException;

class EpisodicMemoryMaintenanceRuntimePolicyService
{
    public const CONFIG_KEY = 'agents.episodic_memory_maintenance_runtime_policy';

    public const SCHEMA = 'plos.episodic_memory_maintenance_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   archive:array{retention_days:int,importance_below:float,preview_default_limit:int,max_limit:int},
     *   backfill:array{default_days:int,max_days:int,preview_default_limit:int,max_limit:int},
     *   prune:array{preview_default_limit:int,max_limit:int,scan_batch:int,scan_cap:int,delete_batch:int}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Episodic memory maintenance runtime policy is unavailable.');
        }

        $archive = $this->requiredSection($policy, 'archive');
        $backfill = $this->requiredSection($policy, 'backfill');
        $prune = $this->requiredSection($policy, 'prune');

        $normalized = [
            'archive' => [
                'retention_days' => $this->boundedInt($archive, 'retention_days', 1, 3650),
                'importance_below' => $this->boundedFloat($archive, 'importance_below', 0.01, 0.99),
                'preview_default_limit' => $this->boundedInt($archive, 'preview_default_limit', 1, 100000),
                'max_limit' => $this->boundedInt($archive, 'max_limit', 1, 100000),
            ],
            'backfill' => [
                'default_days' => $this->boundedInt($backfill, 'default_days', 1, 3650),
                'max_days' => $this->boundedInt($backfill, 'max_days', 1, 3650),
                'preview_default_limit' => $this->boundedInt($backfill, 'preview_default_limit', 1, 10000),
                'max_limit' => $this->boundedInt($backfill, 'max_limit', 1, 10000),
            ],
            'prune' => [
                'preview_default_limit' => $this->boundedInt($prune, 'preview_default_limit', 1, 100000),
                'max_limit' => $this->boundedInt($prune, 'max_limit', 1, 100000),
                'scan_batch' => $this->boundedInt($prune, 'scan_batch', 1, 100000),
                'scan_cap' => $this->boundedInt($prune, 'scan_cap', 1, 1000000),
                'delete_batch' => $this->boundedInt($prune, 'delete_batch', 1, 10000),
            ],
        ];

        if (
            $normalized['archive']['preview_default_limit'] > $normalized['archive']['max_limit']
            || $normalized['backfill']['default_days'] > $normalized['backfill']['max_days']
            || $normalized['backfill']['preview_default_limit'] > $normalized['backfill']['max_limit']
            || $normalized['prune']['preview_default_limit'] > $normalized['prune']['max_limit']
            || $normalized['prune']['scan_batch'] > $normalized['prune']['scan_cap']
            || $normalized['prune']['delete_batch'] > $normalized['prune']['max_limit']
        ) {
            throw new RuntimeException('Episodic memory maintenance runtime policy is inconsistent.');
        }

        return $normalized;
    }

    /** @param array{preview_default_limit:int,max_limit:int} $modePolicy */
    public function resolveLimit(mixed $requested, array $modePolicy, bool $write): int
    {
        if ($requested === null || $requested === '') {
            if ($write) {
                throw new RuntimeException('Confirmed episodic memory maintenance requires an explicit --limit.');
            }

            return $modePolicy['preview_default_limit'];
        }

        $limit = $this->positiveOption($requested, '--limit');
        if ($limit > $modePolicy['max_limit']) {
            throw new RuntimeException('Episodic memory maintenance limit exceeds the runtime policy maximum.');
        }

        return $limit;
    }

    /** @param array{default_days:int,max_days:int} $policy */
    public function resolveBackfillDays(mixed $requested, array $policy): int
    {
        if ($requested === null || $requested === '') {
            return $policy['default_days'];
        }

        $days = $this->positiveOption($requested, '--days');
        if ($days > $policy['max_days']) {
            throw new RuntimeException('Episodic memory backfill days exceed the runtime policy maximum.');
        }

        return $days;
    }

    /** @param array<string,mixed> $policy */
    private function requiredSection(array $policy, string $key): array
    {
        $section = $policy[$key] ?? null;
        if (! is_array($section)) {
            throw new RuntimeException("Episodic memory maintenance runtime policy is missing {$key}.");
        }

        return $section;
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Episodic memory maintenance runtime policy has invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function boundedFloat(array $values, string $key, float $minimum, float $maximum): float
    {
        $value = $values[$key] ?? null;
        if (! is_float($value) && ! is_int($value)) {
            throw new RuntimeException("Episodic memory maintenance runtime policy has invalid {$key}.");
        }

        $value = (float) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException("Episodic memory maintenance runtime policy has invalid {$key}.");
        }

        return $value;
    }

    private function positiveOption(mixed $value, string $option): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("{$option} must be a positive integer.");
    }
}
