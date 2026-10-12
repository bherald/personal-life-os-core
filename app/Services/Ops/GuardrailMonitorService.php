<?php

namespace App\Services\Ops;

use App\Services\OfflinePolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GuardrailMonitorService
{
    public const SCHEMA = 'plos.guardrail_monitor.v1';

    public function __construct(
        private readonly OfflinePolicyService $offlinePolicy,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $databaseRules = $this->databaseRules();
        $configRules = $this->configRules();
        $rules = [...$databaseRules, ...$configRules];

        usort($rules, static function (array $left, array $right): int {
            $priority = ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0);
            if ($priority !== 0) {
                return $priority;
            }

            $source = ($left['source'] ?? '') <=> ($right['source'] ?? '');

            return $source !== 0 ? $source : ($left['id'] <=> $right['id']);
        });

        $profiles = (array) config('offline_policy.profiles', []);
        $activeProfile = $this->offlinePolicy->activeProfile();

        return [
            'schema' => self::SCHEMA,
            'window_hours' => $hours,
            'active_profile' => $activeProfile,
            'active_profile_configured' => array_key_exists($activeProfile, $profiles),
            'offline_mode_active' => $this->offlinePolicy->isOfflineModeActive(),
            'source_counts' => [
                'database' => count($databaseRules),
                'config' => count($configRules),
                'offline_policy_profiles' => count($profiles),
            ],
            'rules' => $rules,
            'activity' => $this->activity($hours),
            'database_rules_are_authoritative' => true,
            'config_rules_visible_without_events' => true,
            'raw_conditions_reasons_or_context_included' => false,
            'changes_enforcement_or_operator_authority' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function databaseRules(): array
    {
        if (! config('services.guardrails.use_database', true) || ! $this->hasTable('guardrail_rules')) {
            return [];
        }

        try {
            return DB::table('guardrail_rules')
                ->select(['id', 'name', 'operation_pattern', 'action', 'severity', 'priority'])
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (object $rule): array => $this->projectRule((array) $rule, 'database', 'database:'.$rule->id))
                ->all();
        } catch (\Throwable $e) {
            Log::warning('GuardrailMonitorService: database rule inventory unavailable', [
                'error_type' => get_debug_type($e),
            ]);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function configRules(): array
    {
        $rules = [];
        $seen = [];

        foreach ((array) config('services.guardrails.rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $identity = implode('|', [
                (string) ($rule['name'] ?? ''),
                (string) ($rule['operation_pattern'] ?? $rule['operation'] ?? ''),
                (string) ($rule['action'] ?? ''),
            ]);
            $id = 'config:'.substr(hash('sha256', $identity), 0, 16);
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rules[] = $this->projectRule($rule, 'config', $id);
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function projectRule(array $rule, string $source, string $id): array
    {
        return [
            'id' => $id,
            'source' => $source,
            'name' => (string) ($rule['name'] ?? $id),
            'operation_pattern' => (string) ($rule['operation_pattern'] ?? $rule['operation'] ?? ''),
            'action' => (string) ($rule['action'] ?? 'log'),
            'severity' => (string) ($rule['severity'] ?? 'medium'),
            'priority' => (int) ($rule['priority'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function activity(int $hours): array
    {
        if (! $this->hasTable('guardrail_events')) {
            return ['total' => 0, 'by_outcome' => []];
        }

        try {
            $rows = DB::table('guardrail_events')
                ->selectRaw('event_type, COUNT(*) as event_count')
                ->where('created_at', '>=', now()->subHours($hours))
                ->groupBy('event_type')
                ->orderBy('event_type')
                ->get();
            $byOutcome = [];
            $total = 0;

            foreach ($rows as $row) {
                $count = (int) $row->event_count;
                $byOutcome[(string) $row->event_type] = $count;
                $total += $count;
            }

            return ['total' => $total, 'by_outcome' => $byOutcome];
        } catch (\Throwable $e) {
            Log::warning('GuardrailMonitorService: activity aggregate unavailable', [
                'error_type' => get_debug_type($e),
            ]);

            return ['total' => 0, 'by_outcome' => []];
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
