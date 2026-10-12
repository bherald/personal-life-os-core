<?php

namespace App\Services;

use RuntimeException;

class ScheduledJobApiRuntimePolicyService
{
    public const CONFIG_KEY = 'ops.scheduler_api_runtime_policy';

    public const SCHEMA = 'plos.scheduler_api_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   history:array{embedded_limit:int,default_limit:int,max_limit:int},
     *   cleanup:array{default_retention_days:int,min_retention_days:int,max_retention_days:int},
     *   job_timeout:array{default_minutes:int,min_minutes:int,max_minutes:int}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Scheduler API runtime policy is unavailable.');
        }

        $history = $policy['history'] ?? null;
        $cleanup = $policy['cleanup'] ?? null;
        $jobTimeout = $policy['job_timeout'] ?? null;
        if (! is_array($history) || ! is_array($cleanup) || ! is_array($jobTimeout)) {
            throw new RuntimeException('Scheduler API runtime policy is incomplete.');
        }

        $embeddedLimit = $this->boundedInt($history, 'embedded_limit', 1, 500, 'history.');
        $defaultLimit = $this->boundedInt($history, 'default_limit', 1, 500, 'history.');
        $maxLimit = $this->boundedInt($history, 'max_limit', 1, 1000, 'history.');
        if ($embeddedLimit > $maxLimit || $defaultLimit > $maxLimit) {
            throw new RuntimeException('Scheduler API runtime policy has invalid history limits.');
        }

        $defaultRetentionDays = $this->boundedInt(
            $cleanup,
            'default_retention_days',
            1,
            36500,
            'cleanup.',
        );
        $minRetentionDays = $this->boundedInt($cleanup, 'min_retention_days', 1, 36500, 'cleanup.');
        $maxRetentionDays = $this->boundedInt($cleanup, 'max_retention_days', 1, 36500, 'cleanup.');
        if ($minRetentionDays > $defaultRetentionDays || $defaultRetentionDays > $maxRetentionDays) {
            throw new RuntimeException('Scheduler API runtime policy has invalid cleanup retention bounds.');
        }

        $defaultTimeoutMinutes = $this->boundedInt($jobTimeout, 'default_minutes', 1, 10080, 'job_timeout.');
        $minTimeoutMinutes = $this->boundedInt($jobTimeout, 'min_minutes', 1, 10080, 'job_timeout.');
        $maxTimeoutMinutes = $this->boundedInt($jobTimeout, 'max_minutes', 1, 10080, 'job_timeout.');
        if ($minTimeoutMinutes > $defaultTimeoutMinutes || $defaultTimeoutMinutes > $maxTimeoutMinutes) {
            throw new RuntimeException('Scheduler API runtime policy has invalid job timeout bounds.');
        }

        return [
            'history' => [
                'embedded_limit' => $embeddedLimit,
                'default_limit' => $defaultLimit,
                'max_limit' => $maxLimit,
            ],
            'cleanup' => [
                'default_retention_days' => $defaultRetentionDays,
                'min_retention_days' => $minRetentionDays,
                'max_retention_days' => $maxRetentionDays,
            ],
            'job_timeout' => [
                'default_minutes' => $defaultTimeoutMinutes,
                'min_minutes' => $minTimeoutMinutes,
                'max_minutes' => $maxTimeoutMinutes,
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum, string $prefix): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Scheduler API runtime policy has invalid {$prefix}{$key}.");
        }

        return $value;
    }
}
