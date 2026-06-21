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
            'ttl_seconds' => 120,
            'tries' => 1,
            'timeout_seconds' => 10,
            'policy_notes' => [
                'ttl_seconds' => 'Redis marker TTL for one synthetic probe completion key.',
                'tries' => 'Retry attempts for the synthetic queue heartbeat job.',
                'timeout_seconds' => 'Horizon timeout for one synthetic queue heartbeat job.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as default.',
                'scheduled_probe_row' => 'Scheduled probe cadence and command timeout remain owned by scheduled_jobs.scheduler_synthetic_probe.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'watchdog_synthetic_probe_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'watchdog_synthetic_probe_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for the watchdog synthetic queue heartbeat job.',
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
            ->where('section', 'ops')
            ->where('config_key', 'watchdog_synthetic_probe_job_runtime_policy')
            ->delete();
    }
};
