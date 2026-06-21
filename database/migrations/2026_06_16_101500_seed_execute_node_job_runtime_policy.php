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
            'timeout_seconds' => 600,
            'tries' => 3,
            'backoff_seconds' => [30, 60, 120],
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one queued fan-out branch node job.',
                'tries' => 'Retry attempts for one queued fan-out branch node job.',
                'backoff_seconds' => 'Retry backoff sequence for queued fan-out branch execution.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as workflow.',
                'fanout_node_policy' => 'FanOutNode branch cap and HTTP pool timeout remain owned by workflow.fanout_runtime_policy; this row owns only the queued branch envelope.',
                'fanin_retry_caution' => 'Defaults preserve current retry timing because FanIn can see failed branch state before Laravel retry succeeds.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'execute_node_job_runtime_policy')
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
            ['section' => 'workflow', 'config_key' => 'execute_node_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued fan-out branch node execution.',
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
            ->where('section', 'workflow')
            ->where('config_key', 'execute_node_job_runtime_policy')
            ->delete();
    }
};
