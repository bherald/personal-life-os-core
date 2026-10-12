<?php

namespace App\Services;

class LlmPoolProbePolicyService
{
    public const CONFIG_KEY = 'llm.pool_probe_runtime_policy';

    private const DEFAULT_POLICY = [
        'process_lookup_timeout_seconds' => 5,
        'cli_status_timeout_seconds' => 10,
        'http_connect_timeout_seconds' => 5,
        'local_sidecar_connect_timeout_seconds' => 3,
        'health_request_timeout_seconds' => 10,
        'external_health_request_timeout_seconds' => 15,
    ];

    private ?array $policy = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function processLookupTimeoutSeconds(): int
    {
        return $this->bounded('process_lookup_timeout_seconds', 1, 30);
    }

    public function cliStatusTimeoutSeconds(): int
    {
        return $this->bounded('cli_status_timeout_seconds', 1, 120);
    }

    public function httpConnectTimeoutSeconds(): int
    {
        return $this->bounded('http_connect_timeout_seconds', 1, 30);
    }

    public function localSidecarConnectTimeoutSeconds(): int
    {
        return $this->bounded('local_sidecar_connect_timeout_seconds', 1, 30);
    }

    public function healthRequestTimeoutSeconds(): int
    {
        return max(
            $this->httpConnectTimeoutSeconds(),
            $this->bounded('health_request_timeout_seconds', 1, 120)
        );
    }

    public function externalHealthRequestTimeoutSeconds(): int
    {
        return max(
            $this->httpConnectTimeoutSeconds(),
            $this->bounded('external_health_request_timeout_seconds', 1, 180)
        );
    }

    /** @return array<string, int> */
    public function resolvedPolicy(): array
    {
        return [
            'process_lookup_timeout_seconds' => $this->processLookupTimeoutSeconds(),
            'cli_status_timeout_seconds' => $this->cliStatusTimeoutSeconds(),
            'http_connect_timeout_seconds' => $this->httpConnectTimeoutSeconds(),
            'local_sidecar_connect_timeout_seconds' => $this->localSidecarConnectTimeoutSeconds(),
            'health_request_timeout_seconds' => $this->healthRequestTimeoutSeconds(),
            'external_health_request_timeout_seconds' => $this->externalHealthRequestTimeoutSeconds(),
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
