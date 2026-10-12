<?php

namespace App\Services;

use RuntimeException;

class UpstreamSourceDriftRuntimePolicyService
{
    public const CONFIG_KEY = 'ops.upstream_source_drift_runtime_policy';

    public const SCHEMA = 'plos.ops_upstream_source_drift_runtime_policy.v1';

    private const HARD_MAX_LIMIT = 10000;

    private const HARD_MAX_TIMEOUT_SECONDS = 300;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *     default_limit:int,
     *     max_limit:int,
     *     connect_timeout_seconds:int,
     *     request_timeout_seconds:int,
     *     max_request_timeout_seconds:int
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Upstream source-drift runtime policy is unavailable.');
        }

        $resolved = [
            'default_limit' => $this->boundedInt($policy, 'default_limit', self::HARD_MAX_LIMIT),
            'max_limit' => $this->boundedInt($policy, 'max_limit', self::HARD_MAX_LIMIT),
            'connect_timeout_seconds' => $this->boundedInt(
                $policy,
                'connect_timeout_seconds',
                self::HARD_MAX_TIMEOUT_SECONDS,
            ),
            'request_timeout_seconds' => $this->boundedInt(
                $policy,
                'request_timeout_seconds',
                self::HARD_MAX_TIMEOUT_SECONDS,
            ),
            'max_request_timeout_seconds' => $this->boundedInt(
                $policy,
                'max_request_timeout_seconds',
                self::HARD_MAX_TIMEOUT_SECONDS,
            ),
        ];

        if ($resolved['default_limit'] > $resolved['max_limit']) {
            throw new RuntimeException('Upstream source-drift default_limit exceeds max_limit.');
        }
        if ($resolved['request_timeout_seconds'] > $resolved['max_request_timeout_seconds']) {
            throw new RuntimeException('Upstream source-drift request timeout exceeds its maximum.');
        }
        if ($resolved['connect_timeout_seconds'] > $resolved['max_request_timeout_seconds']) {
            throw new RuntimeException('Upstream source-drift connect timeout exceeds the request maximum.');
        }

        return $resolved;
    }

    public function resolveOption(mixed $requested, string $name, int $default, int $maximum): int
    {
        if ($requested === null || $requested === '') {
            return $default;
        }

        $value = is_scalar($requested) ? trim((string) $requested) : '';
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1 || (int) $value > $maximum) {
            throw new RuntimeException("Invalid --{$name}. Use an integer from 1 to {$maximum}.");
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("Upstream source-drift runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
