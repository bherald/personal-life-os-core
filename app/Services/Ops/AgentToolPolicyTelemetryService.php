<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentToolPolicyTelemetryService
{
    private const KNOWN_AVAILABILITY = ['available', 'degraded', 'unavailable', 'disabled', 'unknown'];

    /**
     * @return array<string, mixed>
     */
    public function collect(int $staleHours = 168): array
    {
        $staleHours = max(1, min($staleHours, 2160));
        $now = CarbonImmutable::now();

        if (! Schema::hasTable('agent_tool_registry')) {
            return [
                'version' => 1,
                'mode' => 'observe',
                'generated_at' => $now->utc()->toIso8601String(),
                'status' => 'blocked',
                'stale_hours' => $staleHours,
                'summary' => $this->emptySummary(),
                'warnings' => ['agent_tool_registry_missing'],
            ];
        }

        $columns = array_flip(Schema::getColumnListing('agent_tool_registry'));
        foreach (['name', 'enabled'] as $requiredColumn) {
            if (! isset($columns[$requiredColumn])) {
                return [
                    'version' => 1,
                    'mode' => 'observe',
                    'generated_at' => $now->utc()->toIso8601String(),
                    'status' => 'blocked',
                    'stale_hours' => $staleHours,
                    'summary' => $this->emptySummary(),
                    'warnings' => ["agent_tool_registry_{$requiredColumn}_missing"],
                ];
            }
        }

        $optionalColumns = array_values(array_filter([
            'risk_level',
            'source',
            'availability_status',
            'last_checked_at',
            'last_error',
            'schema_generation',
            'privacy_class',
            'allows_private_data',
            'max_result_bytes',
        ], static fn (string $column): bool => isset($columns[$column])));

        $rows = DB::table('agent_tool_registry')
            ->select(array_merge(['name', 'enabled'], $optionalColumns))
            ->get();

        $summary = $this->emptySummary();
        $summary['registry_columns_present'] = count($columns);
        $summary['freshness_stale_hours'] = $staleHours;

        $staleCutoff = $now->subHours($staleHours);
        $oldestCheckedAt = null;
        $warnings = [];

        foreach ($rows as $row) {
            $enabled = (bool) ($row->enabled ?? false);
            $summary['tools_total']++;
            $enabled ? $summary['enabled_total']++ : $summary['disabled_total']++;

            $riskLevel = $this->bucket((string) ($row->risk_level ?? 'unspecified'));
            $source = $this->bucket((string) ($row->source ?? 'unspecified'));
            $availability = $this->availability((string) ($row->availability_status ?? 'unknown'));
            $privacyClass = $this->bucket((string) ($row->privacy_class ?? 'unspecified'));

            $summary['risk_level_counts'][$riskLevel] = ($summary['risk_level_counts'][$riskLevel] ?? 0) + 1;
            $summary['source_counts'][$source] = ($summary['source_counts'][$source] ?? 0) + 1;
            $summary['availability_counts'][$availability] = ($summary['availability_counts'][$availability] ?? 0) + 1;
            $summary['privacy_class_counts'][$privacyClass] = ($summary['privacy_class_counts'][$privacyClass] ?? 0) + 1;

            if ($enabled && in_array($availability, ['disabled', 'unavailable'], true)) {
                $summary['enabled_unavailable_total']++;
            }
            if ($enabled && $availability === 'degraded') {
                $summary['enabled_degraded_total']++;
            }
            if ($enabled && $availability === 'unknown') {
                $summary['enabled_unknown_availability_total']++;
            }

            if (isset($columns['last_checked_at'])) {
                $checkedAt = $this->parseTime($row->last_checked_at ?? null);
                if ($checkedAt === null) {
                    $summary['freshness_unchecked_total']++;
                    if ($enabled) {
                        $summary['freshness_unchecked_enabled_total']++;
                    }
                } elseif ($checkedAt->lessThan($staleCutoff)) {
                    $summary['freshness_stale_total']++;
                    if ($enabled) {
                        $summary['freshness_stale_enabled_total']++;
                    }
                    $oldestCheckedAt = $this->olderTime($oldestCheckedAt, $checkedAt);
                } else {
                    $summary['freshness_recent_total']++;
                    $oldestCheckedAt = $this->olderTime($oldestCheckedAt, $checkedAt);
                }
            } else {
                $summary['freshness_unchecked_total']++;
                if ($enabled) {
                    $summary['freshness_unchecked_enabled_total']++;
                }
            }

            if (isset($columns['last_error']) && trim((string) ($row->last_error ?? '')) !== '') {
                $summary['last_error_total']++;
                if ($enabled) {
                    $summary['last_error_enabled_total']++;
                }
            }

            if (isset($columns['allows_private_data'])) {
                ((bool) ($row->allows_private_data ?? false))
                    ? $summary['allows_private_data_total']++
                    : $summary['denies_private_data_total']++;
            } else {
                $summary['denies_private_data_total'] = $summary['tools_total'];
            }

            if ($enabled && $privacyClass === 'unspecified') {
                $summary['privacy_unspecified_enabled_total']++;
            }

            if (isset($columns['max_result_bytes']) && $enabled && empty($row->max_result_bytes)) {
                $summary['max_result_bytes_missing_enabled_total']++;
            }

            if (isset($columns['schema_generation']) && (int) ($row->schema_generation ?? 0) <= 0) {
                $summary['schema_generation_missing_total']++;
            }
        }

        $summary['oldest_checked_age_hours'] = $oldestCheckedAt === null
            ? null
            : round($oldestCheckedAt->diffInMinutes($now) / 60, 1);
        $summary['policy_gap_enabled_total'] = $summary['enabled_unknown_availability_total']
            + $summary['freshness_stale_enabled_total']
            + $summary['freshness_unchecked_enabled_total']
            + $summary['privacy_unspecified_enabled_total'];

        if ($summary['enabled_unavailable_total'] > 0) {
            $warnings[] = 'enabled_unavailable_tools';
        }
        if ($summary['enabled_degraded_total'] > 0) {
            $warnings[] = 'enabled_degraded_tools';
        }
        if ($summary['enabled_unknown_availability_total'] > 0) {
            $warnings[] = 'enabled_unknown_availability';
        }
        if ($summary['freshness_stale_enabled_total'] > 0) {
            $warnings[] = 'enabled_stale_freshness';
        }
        if ($summary['freshness_unchecked_enabled_total'] > 0) {
            $warnings[] = 'enabled_unchecked_freshness';
        }
        if ($summary['privacy_unspecified_enabled_total'] > 0) {
            $warnings[] = 'enabled_unspecified_privacy';
        }

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => $now->utc()->toIso8601String(),
            'status' => $this->status($summary),
            'stale_hours' => $staleHours,
            'summary' => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'tools_total' => 0,
            'enabled_total' => 0,
            'disabled_total' => 0,
            'enabled_unavailable_total' => 0,
            'enabled_degraded_total' => 0,
            'enabled_unknown_availability_total' => 0,
            'freshness_recent_total' => 0,
            'freshness_stale_total' => 0,
            'freshness_stale_enabled_total' => 0,
            'freshness_unchecked_total' => 0,
            'freshness_unchecked_enabled_total' => 0,
            'freshness_stale_hours' => 0,
            'oldest_checked_age_hours' => null,
            'last_error_total' => 0,
            'last_error_enabled_total' => 0,
            'allows_private_data_total' => 0,
            'denies_private_data_total' => 0,
            'privacy_unspecified_enabled_total' => 0,
            'max_result_bytes_missing_enabled_total' => 0,
            'schema_generation_missing_total' => 0,
            'policy_gap_enabled_total' => 0,
            'registry_columns_present' => 0,
            'availability_counts' => [],
            'privacy_class_counts' => [],
            'risk_level_counts' => [],
            'source_counts' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function status(array $summary): string
    {
        if ((int) ($summary['enabled_unavailable_total'] ?? 0) > 0) {
            return 'degraded';
        }

        foreach ([
            'enabled_degraded_total',
            'enabled_unknown_availability_total',
            'freshness_stale_enabled_total',
            'freshness_unchecked_enabled_total',
            'privacy_unspecified_enabled_total',
        ] as $field) {
            if ((int) ($summary[$field] ?? 0) > 0) {
                return 'watch';
            }
        }

        return 'healthy';
    }

    private function availability(string $value): string
    {
        $value = $this->bucket($value);

        return in_array($value, self::KNOWN_AVAILABILITY, true) ? $value : 'unknown';
    }

    private function bucket(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_:-]+/', '_', $value) ?: 'unspecified';

        return trim($value, '_') ?: 'unspecified';
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function olderTime(?CarbonImmutable $left, CarbonImmutable $right): CarbonImmutable
    {
        return $left === null || $right->lessThan($left) ? $right : $left;
    }
}
