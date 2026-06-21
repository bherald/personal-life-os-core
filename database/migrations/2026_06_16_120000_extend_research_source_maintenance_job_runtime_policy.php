<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'maintenance_job_timeout_seconds' => 3600,
            'maintenance_job_tries' => 1,
            'category_refresh_job_timeout_seconds' => 1800,
            'category_refresh_job_tries' => 1,
            'policy_notes' => [
                'queued_jobs' => 'ExecuteResearchMaintenance and ExecuteResearchCategoryRefresh queue timing are table-owned here.',
                'maintenance_job_timeout_seconds' => 'Horizon timeout for one queued research maintenance job.',
                'maintenance_job_tries' => 'Retry attempts for one queued research maintenance job.',
                'category_refresh_job_timeout_seconds' => 'Horizon timeout for one queued research category refresh job.',
                'category_refresh_job_tries' => 'Retry attempts for one queued research category refresh job.',
                'queue_owner' => 'Queue placement remains explicit in each job constructor as long-running.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'research')
            ->where('config_key', 'source_maintenance_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaults = $this->defaults();
        $policy = array_replace($defaults, $existing);
        $existingNotes = $existing['policy_notes'] ?? [];
        if (! is_array($existingNotes)) {
            $existingNotes = [];
        }

        $policy['policy_notes'] = array_replace(
            $defaults['policy_notes'],
            $existingNotes
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'research', 'config_key' => 'source_maintenance_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for research source maintenance, healing, refresh, stale windows, deactivation thresholds, and queued job timing.',
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

        $row = DB::table('system_configs')
            ->where('section', 'research')
            ->where('config_key', 'source_maintenance_runtime_policy')
            ->first(['config_value']);

        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy)) {
            return;
        }

        foreach ([
            'maintenance_job_timeout_seconds',
            'maintenance_job_tries',
            'category_refresh_job_timeout_seconds',
            'category_refresh_job_tries',
        ] as $key) {
            unset($policy[$key]);
        }

        if (isset($policy['policy_notes']) && is_array($policy['policy_notes'])) {
            foreach ([
                'queued_jobs',
                'maintenance_job_timeout_seconds',
                'maintenance_job_tries',
                'category_refresh_job_timeout_seconds',
                'category_refresh_job_tries',
                'queue_owner',
            ] as $key) {
                unset($policy['policy_notes'][$key]);
            }
        }

        DB::table('system_configs')
            ->where('section', 'research')
            ->where('config_key', 'source_maintenance_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
