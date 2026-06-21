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
            'timeout_seconds' => 7200,
            'tries' => 1,
            'overlap_release_after_seconds' => 300,
            'overlap_expire_after_seconds' => 7500,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one queued research mission execution.',
                'tries' => 'Retry attempts for one queued research mission execution.',
                'overlap' => 'WithoutOverlapping release and expiry values for same-mission concurrency.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                'mission_owner' => 'Research mission time limits, source caps, and mission status remain owned by research_missions and orchestrator policy.',
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
            ->where('config_key', 'execute_research_mission_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (isset($existing['policy_notes']) && is_array($existing['policy_notes'])) {
            $policy['policy_notes'] = array_replace(
                $defaultPolicy['policy_notes'],
                $existing['policy_notes']
            );
        } else {
            $policy['policy_notes'] = $defaultPolicy['policy_notes'];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'research', 'config_key' => 'execute_research_mission_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued research mission execution.',
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
            ->where('section', 'research')
            ->where('config_key', 'execute_research_mission_job_runtime_policy')
            ->delete();
    }
};
