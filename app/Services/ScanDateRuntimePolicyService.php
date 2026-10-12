<?php

namespace App\Services;

use RuntimeException;

class ScanDateRuntimePolicyService
{
    public const CONFIG_KEY = 'files.scan_date_runtime_policy';

    public const SCHEMA = 'plos.files_scan_date_runtime_policy.v1';

    private const HARD_MAX_LIMIT = 1000000;

    private const HARD_MAX_SECONDS = 86400;

    private const HARD_MAX_REVIEW_BACKLOG = 1000000;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *     preview_default_limit:int,
     *     max_limit:int,
     *     runtime_budget_seconds:int,
     *     per_item_reserve_seconds:int,
     *     pending_review_soft_cap:int
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Scan-date runtime policy is unavailable.');
        }

        $resolved = [
            'preview_default_limit' => $this->boundedInt($policy, 'preview_default_limit', self::HARD_MAX_LIMIT),
            'max_limit' => $this->boundedInt($policy, 'max_limit', self::HARD_MAX_LIMIT),
            'runtime_budget_seconds' => $this->boundedInt($policy, 'runtime_budget_seconds', self::HARD_MAX_SECONDS),
            'per_item_reserve_seconds' => $this->boundedInt($policy, 'per_item_reserve_seconds', self::HARD_MAX_SECONDS),
            'pending_review_soft_cap' => $this->boundedInt($policy, 'pending_review_soft_cap', self::HARD_MAX_REVIEW_BACKLOG),
        ];

        if ($resolved['preview_default_limit'] > $resolved['max_limit']) {
            throw new RuntimeException('Scan-date runtime policy preview limit exceeds max_limit.');
        }
        if ($resolved['per_item_reserve_seconds'] >= $resolved['runtime_budget_seconds']) {
            throw new RuntimeException('Scan-date runtime policy reserve must be smaller than its runtime budget.');
        }

        return $resolved;
    }

    /** @param array{preview_default_limit:int,max_limit:int} $policy */
    public function resolveLimit(mixed $requested, array $policy, bool $execute): int
    {
        if ($requested === null || $requested === '') {
            if ($execute) {
                throw new RuntimeException('Scan-date execution requires an explicit positive --limit.');
            }

            return $policy['preview_default_limit'];
        }

        if (! is_string($requested) || preg_match('/^[1-9][0-9]*$/', $requested) !== 1) {
            throw new RuntimeException('Scan-date --limit must be a positive integer.');
        }

        $limit = (int) $requested;
        if ($limit > $policy['max_limit']) {
            throw new RuntimeException('Scan-date limit exceeds the runtime policy maximum.');
        }

        return $limit;
    }

    /** @param array{runtime_budget_seconds:int,per_item_reserve_seconds:int} $policy */
    public function resolveTimeBudget(mixed $requested, array $policy): int
    {
        if ($requested === null || $requested === '') {
            return $policy['runtime_budget_seconds'];
        }

        if (! is_string($requested) || preg_match('/^[1-9][0-9]*$/', $requested) !== 1) {
            throw new RuntimeException('Scan-date --time-budget must be a positive integer.');
        }

        $seconds = (int) $requested;
        if ($seconds > $policy['runtime_budget_seconds']) {
            throw new RuntimeException('Scan-date time budget cannot exceed the table-owned runtime budget.');
        }
        if ($seconds <= $policy['per_item_reserve_seconds']) {
            throw new RuntimeException('Scan-date time budget must leave the table-owned next-item reserve.');
        }

        return $seconds;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("Scan-date runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
