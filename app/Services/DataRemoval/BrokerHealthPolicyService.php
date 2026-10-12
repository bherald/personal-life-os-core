<?php

namespace App\Services\DataRemoval;

use App\Services\SystemConfigService;

class BrokerHealthPolicyService
{
    public const CONFIG_KEY = 'data_removal.broker_health_policy';

    private const DEFAULT_POLICY = [
        'batch_size' => 80,
        'run_interval_hours' => 24,
        'inter_check_delay_ms' => 500,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 15,
        'stale_after_hours' => 168,
        'failure_confirmation_checks' => 2,
    ];

    private ?array $policy = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function batchSize(): int
    {
        return $this->bounded('batch_size', 1, 200);
    }

    public function runIntervalHours(): int
    {
        return $this->bounded('run_interval_hours', 1, 168);
    }

    public function interCheckDelayMs(): int
    {
        return $this->bounded('inter_check_delay_ms', 0, 5000);
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->bounded('connect_timeout_seconds', 1, 30);
    }

    public function requestTimeoutSeconds(): int
    {
        return max($this->connectTimeoutSeconds(), $this->bounded('request_timeout_seconds', 1, 120));
    }

    public function staleAfterHours(): int
    {
        return $this->bounded('stale_after_hours', 24, 720);
    }

    public function failureConfirmationChecks(): int
    {
        return $this->bounded('failure_confirmation_checks', 1, 5);
    }

    /** @return array<string, int> */
    public function resolvedPolicy(): array
    {
        return [
            'batch_size' => $this->batchSize(),
            'run_interval_hours' => $this->runIntervalHours(),
            'inter_check_delay_ms' => $this->interCheckDelayMs(),
            'connect_timeout_seconds' => $this->connectTimeoutSeconds(),
            'request_timeout_seconds' => $this->requestTimeoutSeconds(),
            'stale_after_hours' => $this->staleAfterHours(),
            'failure_confirmation_checks' => $this->failureConfirmationChecks(),
        ];
    }

    private function bounded(string $key, int $minimum, int $maximum): int
    {
        $value = $this->configuredPolicy()[$key] ?? self::DEFAULT_POLICY[$key];
        $value = is_numeric($value) ? (int) $value : self::DEFAULT_POLICY[$key];

        return max($minimum, min($maximum, $value));
    }

    /** @return array<string, mixed> */
    private function configuredPolicy(): array
    {
        if ($this->policy !== null) {
            return $this->policy;
        }

        $configured = $this->systemConfig->get(self::CONFIG_KEY, self::DEFAULT_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        return $this->policy = is_array($configured)
            ? array_replace(self::DEFAULT_POLICY, $configured)
            : self::DEFAULT_POLICY;
    }
}
