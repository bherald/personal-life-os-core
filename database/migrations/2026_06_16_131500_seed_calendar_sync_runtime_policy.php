<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 30,
            'default_sync_window_days' => 30,
            'google_max_results' => 500,
            'google_token_expiry_fallback_seconds' => 3600,
            'conflict_window_minutes' => 5,
            'policy_notes' => [
                'connect_timeout_seconds' => 'Default HTTP connection timeout for external calendar calls.',
                'request_timeout_seconds' => 'Default HTTP request timeout for external calendar calls.',
                'default_sync_window_days' => 'Default forward sync window when workflow node config does not override sync_window_days.',
                'google_max_results' => 'Google Calendar event page size for one calendar fetch.',
                'google_token_expiry_fallback_seconds' => 'Expiry fallback when Google token refresh omits expires_in.',
                'conflict_window_minutes' => 'Bidirectional-sync updated-at proximity treated as conflict.',
                'override_rule' => 'Explicit workflow node config wins for matching per-run options.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'calendar')
            ->where('config_key', 'sync_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'calendar', 'config_key' => 'sync_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for CalendarSyncNode HTTP and sync tuning defaults.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'calendar')
            ->where('config_key', 'sync_runtime_policy')
            ->delete();
    }
};
