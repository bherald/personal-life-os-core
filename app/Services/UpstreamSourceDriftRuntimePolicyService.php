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
     *     response_max_bytes:int,
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
            'response_max_bytes' => $this->boundedInt($policy, 'response_max_bytes', 1048576),
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

    /** @return array<string,mixed> */
    public function preparation(): array
    {
        $runtime = $this->required();
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        $preparation = $policy['preparation'] ?? null;
        if (! is_array($preparation) || ! is_bool($preparation['enabled'] ?? null)) {
            throw new RuntimeException('Upstream preparation policy is unavailable.');
        }
        foreach (['limit' => $runtime['max_limit'], 'budget_seconds' => 240, 'retry_after_seconds' => 604800, 'max_sources' => self::HARD_MAX_LIMIT] as $key => $maximum) {
            $preparation[$key] = $this->boundedInt($preparation, $key, $maximum);
        }
        if ($preparation['budget_seconds'] < $preparation['limit'] * $runtime['request_timeout_seconds']) {
            throw new RuntimeException('Upstream preparation request budget exceeds its deadline.');
        }
        foreach (['owner', 'next_action', 'integration_owner', 'deployment_owner', 'owning_record'] as $key) {
            if (! is_string($preparation[$key] ?? null) || trim($preparation[$key]) === '') {
                throw new RuntimeException('Upstream preparation owner or next action is unavailable.');
            }
        }
        if (! is_array($preparation['priority_project_keys'] ?? null)
            || array_filter($preparation['priority_project_keys'], fn ($key) => ! is_string($key) || $key === '') !== []) {
            throw new RuntimeException('Upstream preparation priority keys are invalid.');
        }

        if (array_key_exists('metadata_network_profile', $preparation)
            && (! is_string($preparation['metadata_network_profile'])
                || config('offline_policy.profiles.'.$preparation['metadata_network_profile'].'.task_metadata_get.task') !== 'whiteroom_monitor_due_report')) {
            throw new RuntimeException('Upstream preparation metadata network profile is unqualified.');
        }

        return $preparation + $runtime;
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
