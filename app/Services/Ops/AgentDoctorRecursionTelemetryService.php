<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;

class AgentDoctorRecursionTelemetryService
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $payload = $this->emptyPayload();

        try {
            $calls = DB::selectOne(
                'SELECT
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS calls_24h,
                    COUNT(*) AS calls_7d,
                    COALESCE(SUM(COALESCE(tokens_used, 0)), 0) AS tokens_7d,
                    COALESCE(SUM(CASE WHEN move_on_triggered = 1 THEN 1 ELSE 0 END), 0) AS move_ons_7d
                 FROM agent_recursion_calls
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                []
            );
        } catch (\Throwable $e) {
            $payload['warnings'][] = 'agent_recursion_calls telemetry unavailable: '.$this->shortError($e);

            return $this->withStatus($payload);
        }

        $calls7d = (int) ($calls->calls_7d ?? 0);
        $moveOns7d = (int) ($calls->move_ons_7d ?? 0);
        $payload['calls_24h'] = (int) ($calls->calls_24h ?? 0);
        $payload['calls_7d'] = $calls7d;
        $payload['tokens_7d'] = (int) ($calls->tokens_7d ?? 0);
        $payload['move_ons_7d'] = $moveOns7d;
        $payload['move_on_rate_7d'] = $calls7d > 0 ? round($moveOns7d / $calls7d, 4) : null;

        $this->collectDepthSignal($payload, $calls7d);
        $this->collectServiceConfig($payload);
        $this->collectMasterSwitch($payload);

        return $this->withStatus($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function collectDepthSignal(array &$payload, int $calls7d): void
    {
        if ($calls7d === 0) {
            return;
        }

        $limit = max(0, (int) config('health_thresholds.agents.recursion.depth_scan_row_limit', 100_000));
        $payload['depth_scan_limit'] = $limit;

        if ($limit > 0 && $calls7d > $limit) {
            $payload['depth_scan_skipped'] = true;

            return;
        }

        try {
            $depth = DB::selectOne(
                'SELECT MAX(depth) AS max_depth_7d
                   FROM agent_recursion_calls
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                []
            );
            $payload['max_depth_7d'] = $depth->max_depth_7d !== null ? (int) $depth->max_depth_7d : null;
        } catch (\Throwable $e) {
            $payload['depth_scan_skipped'] = true;
            $payload['warnings'][] = 'agent_recursion_calls depth telemetry unavailable: '.$this->shortError($e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function collectServiceConfig(array &$payload): void
    {
        try {
            $config = DB::selectOne(
                'SELECT COUNT(*) AS services_total,
                        COALESCE(SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END), 0) AS services_enabled
                   FROM recursion_config',
                []
            );
            $payload['services_total'] = (int) ($config->services_total ?? 0);
            $payload['services_enabled'] = (int) ($config->services_enabled ?? 0);
        } catch (\Throwable $e) {
            $payload['warnings'][] = 'recursion_config telemetry unavailable: '.$this->shortError($e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function collectMasterSwitch(array &$payload): void
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value
                   FROM system_configs
                  WHERE section = ? AND config_key = ?
                  LIMIT 1',
                ['recursion', 'master_enabled']
            );
            $payload['master_enabled'] = $row === null ? null : $this->parseBool($row->config_value ?? null);
        } catch (\Throwable $e) {
            $payload['warnings'][] = 'recursion master switch telemetry unavailable: '.$this->shortError($e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withStatus(array $payload): array
    {
        $calls7d = (int) ($payload['calls_7d'] ?? 0);
        $moveOnRate = $payload['move_on_rate_7d'] ?? null;
        $minCalls = max(1, (int) config('health_thresholds.agents.recursion.min_calls_for_rate_status', 100));
        $warningRate = (float) config('health_thresholds.agents.recursion.move_on_rate_warning', 0.40);
        $criticalRate = (float) config('health_thresholds.agents.recursion.move_on_rate_critical', 0.70);

        if (($payload['master_enabled'] ?? null) === false) {
            $payload['warnings'][] = 'recursion master switch is disabled';
        }

        if ((int) ($payload['services_total'] ?? 0) > 0 && (int) ($payload['services_enabled'] ?? 0) === 0) {
            $payload['warnings'][] = 'all per-service recursion configs are disabled';
        }

        if ($moveOnRate !== null && $calls7d >= $minCalls) {
            if ($moveOnRate >= $criticalRate) {
                $payload['critical'][] = "7-day recursion move-on rate is {$moveOnRate}";
            } elseif ($moveOnRate >= $warningRate) {
                $payload['warnings'][] = "7-day recursion move-on rate is {$moveOnRate}";
            }
        }

        $payload['warnings'] = array_values(array_unique($payload['warnings']));
        $payload['critical'] = array_values(array_unique($payload['critical']));
        $payload['status'] = $payload['critical'] !== []
            ? 'critical'
            : ($payload['warnings'] !== [] ? 'warning' : 'healthy');

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        return [
            'mode' => 'observe',
            'source' => 'agent_recursion_calls+recursion_config+system_configs',
            'window_hours' => 168,
            'master_enabled' => null,
            'calls_24h' => 0,
            'calls_7d' => 0,
            'tokens_7d' => 0,
            'move_ons_7d' => 0,
            'move_on_rate_7d' => null,
            'max_depth_7d' => null,
            'depth_scan_skipped' => false,
            'depth_scan_limit' => (int) config('health_thresholds.agents.recursion.depth_scan_row_limit', 100_000),
            'services_total' => 0,
            'services_enabled' => 0,
            'status' => 'healthy',
            'warnings' => [],
            'critical' => [],
        ];
    }

    private function parseBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function shortError(\Throwable $e): string
    {
        return substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage(), 0, 180);
    }
}
