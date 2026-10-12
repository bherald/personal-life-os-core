<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;

class ComputeRouterRuntimePolicyService
{
    public const CONFIG_KEY = 'compute.router_runtime_policy';

    private const DEFAULT_POLICY = [
        'cache_ttl_seconds' => 300,
        'execution_timeout_seconds' => 30,
        'cleanup_grace_seconds' => 5,
        'transport_margin_seconds' => 10,
        'custody_accounting_margin_seconds' => 10,
        'output_max_bytes' => 67108864,
        'capture_raw_ceiling_bytes' => 8388608,
        'capture_transport_overhead_bytes' => 8192,
        'capture_memory_reserve_bytes' => 33554432,
        'capture_memory_multiplier' => 16,
        'scope_boundary_enabled' => false,
        'ssh_connect_timeout_seconds' => 5,
        'local_gpu_probe_timeout_seconds' => 10,
        'local_jetson_probe_timeout_seconds' => 5,
        'remote_health_timeout_seconds' => 15,
        'health_freshness_seconds' => 93600,
        'circuit_failure_threshold' => 5,
        'circuit_cooldown_seconds' => 60,
        'health_success_delta' => 5,
        'health_failure_delta' => 10,
        'unhealthy_threshold' => 30,
        'routing_weight_priority' => 0.32,
        'routing_weight_health' => 0.24,
        'routing_weight_speed' => 0.24,
        'routing_weight_load' => 0.20,
        'priority_reference' => 50,
        'unknown_latency_ms' => 5000,
        'speed_reference_ms' => 20000,
        'latency_ema_alpha' => 0.20,
    ];

    private ?array $policy = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function cacheTtlSeconds(): int
    {
        return $this->int('cache_ttl_seconds', 1, 3600);
    }

    public function executionTimeoutSeconds(): int
    {
        return $this->int('execution_timeout_seconds', 1, 86400);
    }

    public function outputMaxBytes(): int
    {
        return $this->int('output_max_bytes', 1024, 268435456);
    }

    /** Existing instance config owns limits; requested but unqualified scope never falls back. */
    public function executionScopeLimits(object $instance): ?array
    {
        $config = $instance->config ?? [];
        if (is_string($config)) {
            try {
                $config = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new InferenceAdmissionException('Compute instance scope configuration is unreadable; dispatch held.', 0, $error);
            }
        }
        if (! is_array($config)) {
            throw new InferenceAdmissionException('Compute instance scope configuration is invalid; dispatch held.');
        }
        if (! array_key_exists('execution_scope', $config)) {
            return null;
        }
        $scope = $config['execution_scope'];
        if (! is_array($scope) || ! is_bool($scope['enabled'] ?? null)) {
            throw new InferenceAdmissionException('Compute scope policy is invalid; dispatch held.');
        }
        if (! $scope['enabled']) {
            return null;
        }
        if (($this->configuredPolicy()['scope_boundary_enabled'] ?? false) !== true
            || ($scope['qualified'] ?? false) !== true || ($scope['backend'] ?? null) !== 'systemd_user_scope') {
            throw new InferenceAdmissionException('Compute scope boundary is unqualified; dispatch held.');
        }
        $limits = ['backend' => 'systemd_user_scope'];
        foreach (['tasks_max', 'memory_max_bytes', 'memory_swap_max_bytes', 'handshake_timeout_seconds'] as $key) {
            $value = $scope[$key] ?? null;
            if (! is_int($value) || $value < ($key === 'memory_swap_max_bytes' ? 0 : 1)
                || ($key === 'handshake_timeout_seconds' && $value > 30)) {
                throw new InferenceAdmissionException('Compute scope limit is invalid; dispatch held.');
            }
            $limits[$key] = $value;
        }
        foreach (['proof_max_directories' => [1, 1024], 'proof_max_depth' => [0, 32],
            'proof_timeout_seconds' => [1, 30]] as $key => [$minimum, $maximum]) {
            $value = $scope[$key] ?? config('compute.execution_scope_'.$key);
            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                throw new InferenceAdmissionException('Compute scope proof budget is invalid; dispatch held.');
            }
            $limits[$key] = $value;
        }
        foreach (['systemd_run', 'systemctl', 'cgroup_root'] as $key) {
            $path = config('compute.execution_scope_'.$key);
            if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
                throw new InferenceAdmissionException('Compute scope executable or cgroup path is unconfigured; dispatch held.');
            }
            $limits[$key] = $path;
        }

        return $limits;
    }

    /** Reserve for JSON/base64 copies, observer output and unrelated application accounting. */
    public function captureLimits(int $inputBytes = 0, bool $observer = false): array
    {
        $raw = min($this->outputMaxBytes(), $this->int('capture_raw_ceiling_bytes', 1024, 33554432));
        $available = $this->captureMemoryAvailable();
        if ($available !== null) {
            $observerBytes = $observer ? (int) ceil($inputBytes / 3) * 4 : 0;
            $raw = min($raw, (int) floor(($available - $observerBytes) / $this->int('capture_memory_multiplier', 8, 64)));
        }
        if ($raw < 1024) {
            throw new InferenceAdmissionException('Compute capture memory headroom is insufficient; dispatch held.');
        }

        return ['raw_bytes' => $raw, 'transport_bytes' => (int) ceil($raw / 3) * 4
            + $this->int('capture_transport_overhead_bytes', 512, 65536)];
    }

    public function assertDecodeMemory(int $bytes): void
    {
        $available = $this->captureMemoryAvailable();
        if ($available !== null && $bytes * (int) ceil($this->int('capture_memory_multiplier', 8, 64) / 2) > $available) {
            throw new InferenceAdmissionException('Compute receipt decoding memory headroom is insufficient; hold retained.');
        }
    }

    private function captureMemoryAvailable(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return null;
        }
        if (! preg_match('/^([0-9]+)([KMG]?)$/i', $limit, $match)) {
            throw new InferenceAdmissionException('Compute PHP memory limit is unproven; dispatch held.');
        }
        $scale = match (strtoupper($match[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1
        };

        return (int) $match[1] * $scale - memory_get_usage(true)
            - $this->int('capture_memory_reserve_bytes', 16384, 1073741824);
    }

    public function cleanupGraceSeconds(): int
    {
        return $this->int('cleanup_grace_seconds', 1, 120);
    }

    public function relayTimeoutSeconds(int $executionTimeout): int
    {
        return $executionTimeout + $this->cleanupGraceSeconds() + $this->sshConnectTimeoutSeconds()
            + $this->int('transport_margin_seconds', 1, 300);
    }

    public function transportTimeoutSeconds(int $executionTimeout): int
    {
        return $this->relayTimeoutSeconds($executionTimeout) + $this->cleanupGraceSeconds()
            + $this->int('transport_margin_seconds', 1, 300);
    }

    public function custodyTimeoutSeconds(int $executionTimeout): int
    {
        return $this->transportTimeoutSeconds($executionTimeout) + $this->int('custody_accounting_margin_seconds', 1, 300);
    }

    public function sshConnectTimeoutSeconds(): int
    {
        return $this->int('ssh_connect_timeout_seconds', 1, 30);
    }

    public function localGpuProbeTimeoutSeconds(): int
    {
        return $this->int('local_gpu_probe_timeout_seconds', 1, 120);
    }

    public function localJetsonProbeTimeoutSeconds(): int
    {
        return $this->int('local_jetson_probe_timeout_seconds', 1, 60);
    }

    public function remoteHealthTimeoutSeconds(): int
    {
        return max(
            $this->sshConnectTimeoutSeconds(),
            $this->int('remote_health_timeout_seconds', 1, 300)
        );
    }

    public function healthFreshnessSeconds(): int
    {
        return $this->int('health_freshness_seconds', 300, 604800);
    }

    public function circuitFailureThreshold(): int
    {
        return $this->int('circuit_failure_threshold', 1, 100);
    }

    public function circuitCooldownSeconds(): int
    {
        return $this->int('circuit_cooldown_seconds', 1, 86400);
    }

    public function healthSuccessDelta(): int
    {
        return $this->int('health_success_delta', 1, 100);
    }

    public function healthFailureDelta(): int
    {
        return $this->int('health_failure_delta', 1, 100);
    }

    public function unhealthyThreshold(): int
    {
        return $this->int('unhealthy_threshold', 1, 100);
    }

    /** @return array{priority: float, health: float, speed: float, load: float} */
    public function routingWeights(): array
    {
        $weights = [
            'priority' => $this->float('routing_weight_priority', 0.0, 1.0),
            'health' => $this->float('routing_weight_health', 0.0, 1.0),
            'speed' => $this->float('routing_weight_speed', 0.0, 1.0),
            'load' => $this->float('routing_weight_load', 0.0, 1.0),
        ];
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return [
                'priority' => 0.32,
                'health' => 0.24,
                'speed' => 0.24,
                'load' => 0.20,
            ];
        }

        return array_map(static fn (float $weight): float => $weight / $total, $weights);
    }

    public function priorityReference(): int
    {
        return $this->int('priority_reference', 1, 255);
    }

    public function unknownLatencyMs(): int
    {
        return min(
            $this->speedReferenceMs(),
            $this->int('unknown_latency_ms', 0, 600000)
        );
    }

    public function speedReferenceMs(): int
    {
        return $this->int('speed_reference_ms', 100, 600000);
    }

    public function latencyEmaAlpha(): float
    {
        return $this->float('latency_ema_alpha', 0.01, 1.0);
    }

    /** @return array<string, int|float|array<string, float>> */
    public function resolvedPolicy(): array
    {
        return [
            'cache_ttl_seconds' => $this->cacheTtlSeconds(),
            'execution_timeout_seconds' => $this->executionTimeoutSeconds(),
            'cleanup_grace_seconds' => $this->cleanupGraceSeconds(),
            'output_max_bytes' => $this->outputMaxBytes(),
            'capture_raw_ceiling_bytes' => $this->int('capture_raw_ceiling_bytes', 1024, 33554432),
            'capture_transport_overhead_bytes' => $this->int('capture_transport_overhead_bytes', 512, 65536),
            'capture_memory_reserve_bytes' => $this->int('capture_memory_reserve_bytes', 16384, 1073741824),
            'capture_memory_multiplier' => $this->int('capture_memory_multiplier', 8, 64),
            'transport_margin_seconds' => $this->int('transport_margin_seconds', 1, 300),
            'custody_accounting_margin_seconds' => $this->int('custody_accounting_margin_seconds', 1, 300),
            'ssh_connect_timeout_seconds' => $this->sshConnectTimeoutSeconds(),
            'local_gpu_probe_timeout_seconds' => $this->localGpuProbeTimeoutSeconds(),
            'local_jetson_probe_timeout_seconds' => $this->localJetsonProbeTimeoutSeconds(),
            'remote_health_timeout_seconds' => $this->remoteHealthTimeoutSeconds(),
            'health_freshness_seconds' => $this->healthFreshnessSeconds(),
            'circuit_failure_threshold' => $this->circuitFailureThreshold(),
            'circuit_cooldown_seconds' => $this->circuitCooldownSeconds(),
            'health_success_delta' => $this->healthSuccessDelta(),
            'health_failure_delta' => $this->healthFailureDelta(),
            'unhealthy_threshold' => $this->unhealthyThreshold(),
            'routing_weights' => $this->routingWeights(),
            'priority_reference' => $this->priorityReference(),
            'unknown_latency_ms' => $this->unknownLatencyMs(),
            'speed_reference_ms' => $this->speedReferenceMs(),
            'latency_ema_alpha' => $this->latencyEmaAlpha(),
        ];
    }

    private function int(string $key, int $minimum, int $maximum): int
    {
        $value = $this->configuredPolicy()[$key] ?? self::DEFAULT_POLICY[$key];
        $value = is_numeric($value) ? (int) $value : self::DEFAULT_POLICY[$key];

        return max($minimum, min($maximum, $value));
    }

    private function float(string $key, float $minimum, float $maximum): float
    {
        $value = $this->configuredPolicy()[$key] ?? self::DEFAULT_POLICY[$key];
        $value = is_numeric($value) ? (float) $value : self::DEFAULT_POLICY[$key];

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
