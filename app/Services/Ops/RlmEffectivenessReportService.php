<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RlmEffectivenessReportService
{
    /**
     * @return array<string, mixed>
     */
    public function collect(int $windowHours = 24, ?string $serviceName = null): array
    {
        $windowHours = max(1, min($windowHours, 2160));
        $serviceName = $this->normalizeServiceName($serviceName);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'source' => 'agent_recursion_calls+recursion_effectiveness+recursion_config',
            'window_hours' => $windowHours,
            'service_filter' => $serviceName,
            'captured_at' => now()->toIso8601String(),
            'status' => 'healthy',
            'summary' => [
                'services_configured' => 0,
                'services_enabled' => 0,
                'services_seen' => 0,
                'calls' => 0,
                'effectiveness_runs' => 0,
                'total_sub_calls' => 0,
                'tokens' => 0,
                'cost_usd' => 0.0,
                'move_ons' => 0,
                'move_on_rate' => null,
                'review_services' => 0,
                'positive_services' => 0,
                'incomplete_call_services' => 0,
                'missing_effectiveness_services' => 0,
            ],
            'services' => [],
            'warnings' => [],
            'recommendations' => [],
        ];

        foreach (['agent_recursion_calls', 'recursion_effectiveness', 'recursion_config'] as $table) {
            if (! Schema::hasTable($table)) {
                $payload['warnings'][] = "{$table} table is unavailable";
            }
        }

        if ($payload['warnings'] !== []) {
            return $this->finalize($payload);
        }

        try {
            $configs = $this->loadConfigs($serviceName);
            $calls = $this->loadCallStats($windowHours, $serviceName);
            $effectiveness = $this->loadEffectivenessStats($windowHours, $serviceName);
            $reasons = $this->loadMoveOnReasons($windowHours, $serviceName);
        } catch (\Throwable $e) {
            $payload['warnings'][] = 'RLM effectiveness telemetry unavailable: '.$this->shortError($e);

            return $this->finalize($payload);
        }

        $serviceNames = array_values(array_unique(array_filter(array_merge(
            array_keys($configs),
            array_keys($calls),
            array_keys($effectiveness)
        ))));
        sort($serviceNames);

        $payload['summary']['services_configured'] = count($configs);
        $payload['summary']['services_enabled'] = count(array_filter(
            $configs,
            static fn (array $config): bool => ! empty($config['enabled'])
        ));
        $payload['summary']['services_seen'] = count(array_filter(
            $serviceNames,
            static fn (string $name): bool => isset($calls[$name]) || isset($effectiveness[$name])
        ));

        foreach ($serviceNames as $name) {
            $service = $this->buildServiceRow(
                $name,
                $configs[$name] ?? null,
                $calls[$name] ?? null,
                $effectiveness[$name] ?? null,
                $reasons[$name] ?? [],
                $serviceName !== null
            );

            $payload['services'][] = $service;
            $payload['summary']['calls'] += (int) ($service['calls']['total'] ?? 0);
            $payload['summary']['effectiveness_runs'] += (int) ($service['effectiveness']['runs'] ?? 0);
            $payload['summary']['total_sub_calls'] += (int) ($service['effectiveness']['total_sub_calls'] ?? 0);
            $payload['summary']['tokens'] += (int) ($service['calls']['tokens'] ?? 0);
            $payload['summary']['cost_usd'] = round(
                (float) $payload['summary']['cost_usd'] + (float) ($service['calls']['cost_usd'] ?? 0),
                4
            );
            $payload['summary']['move_ons'] += (int) ($service['move_on']['count'] ?? 0);

            if ($service['status'] === 'review') {
                $payload['summary']['review_services']++;
                $payload['warnings'][] = "{$name} has high RLM move-on churn";
            } elseif ($service['status'] === 'incomplete_calls') {
                $payload['summary']['incomplete_call_services']++;
                $payload['warnings'][] = "{$name} has incomplete RLM call rows";
            } elseif ($service['status'] === 'missing_effectiveness') {
                $payload['summary']['missing_effectiveness_services']++;
                $payload['warnings'][] = "{$name} has recent RLM calls but no effectiveness rows";
            } elseif ($service['status'] === 'positive') {
                $payload['summary']['positive_services']++;
            }

            if (($service['recommendation'] ?? '') !== '') {
                $payload['recommendations'][] = $service['recommendation'];
            }
        }

        $payload['summary']['move_on_rate'] = $payload['summary']['calls'] > 0
            ? round($payload['summary']['move_ons'] / $payload['summary']['calls'], 4)
            : null;

        if ($payload['services'] === [] && $serviceName !== null) {
            $payload['warnings'][] = "No RLM telemetry or config found for {$serviceName}";
        }

        return $this->finalize($payload);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadConfigs(?string $serviceName): array
    {
        $where = '';
        $bindings = [];
        if ($serviceName !== null) {
            $where = ' WHERE service_name = ?';
            $bindings[] = $serviceName;
        }

        $rows = DB::select(
            'SELECT service_name, enabled, max_depth, max_tokens, max_time_seconds, max_cost_usd,
                    strategies, sub_call_model_role, synthesis_model_role, disabled_reason
               FROM recursion_config'.$where.'
              ORDER BY service_name',
            $bindings
        );

        $configs = [];
        foreach ($rows as $row) {
            $name = (string) ($row->service_name ?? '');
            if ($name === '') {
                continue;
            }

            $configs[$name] = [
                'configured' => true,
                'enabled' => (bool) ($row->enabled ?? false),
                'max_depth' => (int) ($row->max_depth ?? 0),
                'max_tokens' => (int) ($row->max_tokens ?? 0),
                'max_time_seconds' => (int) ($row->max_time_seconds ?? 0),
                'max_cost_usd' => (float) ($row->max_cost_usd ?? 0),
                'strategies' => $this->decodeJsonArray($row->strategies ?? null),
                'sub_call_model_role' => (string) ($row->sub_call_model_role ?? ''),
                'synthesis_model_role' => (string) ($row->synthesis_model_role ?? ''),
                'disabled_reason' => $row->disabled_reason ?? null,
            ];
        }

        return $configs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCallStats(int $windowHours, ?string $serviceName): array
    {
        [$filter, $bindings] = $this->windowBindings($windowHours, $serviceName);

        $rows = DB::select(
            "SELECT COALESCE(service_name, 'unknown') AS service_name,
                    COUNT(*) AS total_calls,
                    COALESCE(SUM(COALESCE(tokens_used, 0)), 0) AS tokens,
                    COALESCE(SUM(COALESCE(cost_usd, 0)), 0) AS cost_usd,
                    COALESCE(SUM(CASE WHEN move_on_triggered = 1 THEN 1 ELSE 0 END), 0) AS move_ons,
                    COALESCE(SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END), 0) AS incomplete_calls,
                    COALESCE(MAX(depth), 0) AS max_depth,
                    AVG(NULLIF(novelty_score, 0)) AS avg_novelty_score,
                    AVG(NULLIF(context_window_size, 0)) AS avg_context_window,
                    AVG(NULLIF(time_seconds, 0)) AS avg_time_seconds,
                    COALESCE(SUM(CASE
                        WHEN LOWER(COALESCE(provider_used, '')) LIKE '%ollama%'
                          OR LOWER(COALESCE(provider_used, '')) LIKE '%local%'
                        THEN 1 ELSE 0 END), 0) AS local_calls
               FROM agent_recursion_calls
              WHERE {$filter}
              GROUP BY COALESCE(service_name, 'unknown')",
            $bindings
        );

        $stats = [];
        foreach ($rows as $row) {
            $name = (string) ($row->service_name ?? 'unknown');
            $total = (int) ($row->total_calls ?? 0);
            $moveOns = (int) ($row->move_ons ?? 0);
            $incompleteCalls = (int) ($row->incomplete_calls ?? 0);
            $localCalls = (int) ($row->local_calls ?? 0);

            $stats[$name] = [
                'total' => $total,
                'completed' => max(0, $total - $incompleteCalls),
                'incomplete' => $incompleteCalls,
                'incomplete_rate' => $total > 0 ? round($incompleteCalls / $total, 4) : null,
                'tokens' => (int) ($row->tokens ?? 0),
                'cost_usd' => round((float) ($row->cost_usd ?? 0), 4),
                'move_ons' => $moveOns,
                'move_on_rate' => $total > 0 ? round($moveOns / $total, 4) : null,
                'max_depth' => (int) ($row->max_depth ?? 0),
                'avg_novelty_score' => $this->nullableFloat($row->avg_novelty_score ?? null, 4),
                'avg_context_window' => $row->avg_context_window !== null ? (int) round((float) $row->avg_context_window) : null,
                'avg_time_seconds' => $this->nullableFloat($row->avg_time_seconds ?? null, 2),
                'local_call_pct' => $total > 0 ? round(($localCalls / $total) * 100, 2) : null,
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadEffectivenessStats(int $windowHours, ?string $serviceName): array
    {
        [$filter, $bindings] = $this->windowBindings($windowHours, $serviceName);

        $rows = DB::select(
            "SELECT COALESCE(service_name, 'unknown') AS service_name,
                    COUNT(*) AS runs,
                    COALESCE(SUM(total_sub_calls), 0) AS total_sub_calls,
                    COALESCE(SUM(total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(total_cost_usd), 0) AS total_cost_usd,
                    COALESCE(SUM(move_on_count), 0) AS move_on_count,
                    COALESCE(MAX(max_depth_reached), 0) AS max_depth_reached,
                    AVG(NULLIF(avg_novelty_score, 0)) AS avg_novelty_score,
                    AVG(NULLIF(avg_context_window, 0)) AS avg_context_window,
                    AVG(quality_improvement_estimate) AS avg_quality_improvement,
                    AVG(local_provider_pct) AS avg_local_provider_pct,
                    AVG(NULLIF(total_time_seconds, 0)) AS avg_time_seconds
               FROM recursion_effectiveness
              WHERE {$filter}
              GROUP BY COALESCE(service_name, 'unknown')",
            $bindings
        );

        $stats = [];
        foreach ($rows as $row) {
            $name = (string) ($row->service_name ?? 'unknown');
            $stats[$name] = [
                'runs' => (int) ($row->runs ?? 0),
                'total_sub_calls' => (int) ($row->total_sub_calls ?? 0),
                'total_tokens' => (int) ($row->total_tokens ?? 0),
                'total_cost_usd' => round((float) ($row->total_cost_usd ?? 0), 4),
                'move_on_count' => (int) ($row->move_on_count ?? 0),
                'max_depth_reached' => (int) ($row->max_depth_reached ?? 0),
                'avg_novelty_score' => $this->nullableFloat($row->avg_novelty_score ?? null, 4),
                'avg_context_window' => $row->avg_context_window !== null ? (int) round((float) $row->avg_context_window) : null,
                'avg_quality_improvement' => $this->nullableFloat($row->avg_quality_improvement ?? null, 4),
                'avg_local_provider_pct' => $this->nullableFloat($row->avg_local_provider_pct ?? null, 2),
                'avg_time_seconds' => $this->nullableFloat($row->avg_time_seconds ?? null, 2),
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, array<int, array{reason: string, count: int}>>
     */
    private function loadMoveOnReasons(int $windowHours, ?string $serviceName): array
    {
        [$filter, $bindings] = $this->windowBindings($windowHours, $serviceName);

        $rows = DB::select(
            "SELECT COALESCE(service_name, 'unknown') AS service_name,
                    COALESCE(NULLIF(move_on_reason, ''), 'unspecified') AS reason,
                    COUNT(*) AS reason_count
               FROM agent_recursion_calls
              WHERE {$filter}
                AND move_on_triggered = 1
              GROUP BY COALESCE(service_name, 'unknown'), COALESCE(NULLIF(move_on_reason, ''), 'unspecified')
              ORDER BY COALESCE(service_name, 'unknown'), reason_count DESC, reason",
            $bindings
        );

        $reasons = [];
        foreach ($rows as $row) {
            $name = (string) ($row->service_name ?? 'unknown');
            $reasons[$name][] = [
                'reason' => (string) ($row->reason ?? 'unspecified'),
                'count' => (int) ($row->reason_count ?? 0),
            ];
        }

        foreach ($reasons as $name => $items) {
            $reasons[$name] = array_slice($items, 0, 5);
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @param  array<string, mixed>|null  $calls
     * @param  array<string, mixed>|null  $effectiveness
     * @param  array<int, array{reason: string, count: int}>  $reasons
     * @return array<string, mixed>
     */
    private function buildServiceRow(
        string $name,
        ?array $config,
        ?array $calls,
        ?array $effectiveness,
        array $reasons,
        bool $focused
    ): array {
        $calls ??= [
            'total' => 0,
            'completed' => 0,
            'incomplete' => 0,
            'incomplete_rate' => null,
            'tokens' => 0,
            'cost_usd' => 0.0,
            'move_ons' => 0,
            'move_on_rate' => null,
            'max_depth' => 0,
            'avg_novelty_score' => null,
            'avg_context_window' => null,
            'avg_time_seconds' => null,
            'local_call_pct' => null,
        ];
        $effectiveness ??= [
            'runs' => 0,
            'total_sub_calls' => 0,
            'total_tokens' => 0,
            'total_cost_usd' => 0.0,
            'move_on_count' => 0,
            'max_depth_reached' => 0,
            'avg_novelty_score' => null,
            'avg_context_window' => null,
            'avg_quality_improvement' => null,
            'avg_local_provider_pct' => null,
            'avg_time_seconds' => null,
        ];

        $moveOnRate = $calls['move_on_rate'];
        $incompleteRate = $calls['incomplete_rate'];
        $qualityImprovement = $effectiveness['avg_quality_improvement'];
        $status = 'neutral';
        $recommendation = '';
        $minCalls = 3;

        if ((int) $calls['total'] === 0 && (int) $effectiveness['runs'] === 0) {
            $status = ! empty($config['enabled']) ? 'no_recent_evidence' : 'disabled';
            $recommendation = $focused && ! empty($config['enabled'])
                ? "No recent RLM evidence for {$name}; leave enabled only if this service is intentionally quiet."
                : '';
        } elseif ($incompleteRate !== null && (int) $calls['total'] >= $minCalls && $incompleteRate >= 0.2) {
            $status = 'incomplete_calls';
            $recommendation = "Inspect {$name} recursion completion path; {$calls['incomplete']} of {$calls['total']} recent call rows are incomplete.";
        } elseif ((int) $calls['total'] > 0 && (int) $effectiveness['runs'] === 0) {
            $status = 'missing_effectiveness';
            $recommendation = "Inspect {$name} RLM recording path; {$calls['total']} recent calls have no recursion_effectiveness rows.";
        } elseif ($moveOnRate !== null && (int) $calls['total'] >= $minCalls && $moveOnRate >= 0.5) {
            $status = 'review';
            $recommendation = "Review {$name} recursion settings; move-on rate is ".round($moveOnRate * 100, 1)."% over {$calls['total']} calls.";
        } elseif ($qualityImprovement !== null && $qualityImprovement >= 0.15 && ($moveOnRate === null || $moveOnRate <= 0.25)) {
            $status = 'positive';
            $recommendation = "Keep {$name} enabled and keep sampling; quality improvement is {$qualityImprovement} with low move-on churn.";
        }

        return [
            'service_name' => $name,
            'status' => $status,
            'config' => $config ?? [
                'configured' => false,
                'enabled' => null,
                'max_depth' => null,
                'max_tokens' => null,
                'max_time_seconds' => null,
                'max_cost_usd' => null,
                'strategies' => [],
                'sub_call_model_role' => null,
                'synthesis_model_role' => null,
                'disabled_reason' => null,
            ],
            'calls' => $calls,
            'effectiveness' => $effectiveness,
            'move_on' => [
                'count' => (int) ($calls['move_ons'] ?? 0),
                'rate' => $moveOnRate,
                'primary_reason' => $reasons[0]['reason'] ?? null,
                'reasons' => $reasons,
            ],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function windowBindings(int $windowHours, ?string $serviceName): array
    {
        $filter = 'created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)';
        $bindings = [$windowHours];

        if ($serviceName !== null) {
            $filter .= ' AND service_name = ?';
            $bindings[] = $serviceName;
        }

        return [$filter, $bindings];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['warnings'] = array_values(array_unique(array_filter($payload['warnings'])));
        $payload['recommendations'] = array_values(array_unique(array_filter($payload['recommendations'])));

        if ($payload['warnings'] !== [] || (int) ($payload['summary']['review_services'] ?? 0) > 0) {
            $payload['status'] = 'warning';
        }

        return $payload;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function normalizeServiceName(?string $serviceName): ?string
    {
        $serviceName = trim((string) $serviceName);

        return $serviceName !== '' ? $serviceName : null;
    }

    private function nullableFloat(mixed $value, int $precision): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, $precision);
    }

    private function shortError(\Throwable $e): string
    {
        return substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage(), 0, 180);
    }
}
