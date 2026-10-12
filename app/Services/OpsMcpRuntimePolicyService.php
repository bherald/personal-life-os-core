<?php

namespace App\Services;

class OpsMcpRuntimePolicyService
{
    public const CONFIG_KEY = 'ops.mcp_runtime_policy';

    private const DEFAULT_POLICY = [
        'horizon_master_retry_attempts' => 3,
        'horizon_master_retry_delay_seconds' => 2,
        'process_probe_timeout_seconds' => 5,
        'transient_issue_stale_hours' => 6,
    ];

    private ?array $policy = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function horizonMasterRetryAttempts(): int
    {
        return $this->bounded('horizon_master_retry_attempts', 1, 10);
    }

    public function horizonMasterRetryDelaySeconds(): int
    {
        return $this->bounded('horizon_master_retry_delay_seconds', 0, 30);
    }

    public function processProbeTimeoutSeconds(): int
    {
        return $this->bounded('process_probe_timeout_seconds', 1, 30);
    }

    public function transientIssueStaleHours(): int
    {
        return $this->bounded('transient_issue_stale_hours', 1, 720);
    }

    /** @return array<string, int> */
    public function resolvedPolicy(): array
    {
        return [
            'horizon_master_retry_attempts' => $this->horizonMasterRetryAttempts(),
            'horizon_master_retry_delay_seconds' => $this->horizonMasterRetryDelaySeconds(),
            'process_probe_timeout_seconds' => $this->processProbeTimeoutSeconds(),
            'transient_issue_stale_hours' => $this->transientIssueStaleHours(),
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
