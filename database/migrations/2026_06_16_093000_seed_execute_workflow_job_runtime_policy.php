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
            'timeout_seconds' => 900,
            'tries' => 2,
            'unique_for_seconds' => 1800,
            'backoff_seconds' => [60, 120],
            'overlap_release_after_seconds' => 300,
            'overlap_expire_after_seconds' => 1800,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one generic queued workflow execution.',
                'tries' => 'Retry attempts for the generic ExecuteWorkflow queue envelope.',
                'unique_for_seconds' => 'ShouldBeUnique admission window for workflow name plus normalized input.',
                'backoff_seconds' => 'Retry backoff sequence used by queued workflow execution.',
                'overlap' => 'WithoutOverlapping release and expiry values for same-workflow concurrency.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as workflow.',
                'scheduled_workflow_rows' => 'Scheduled workflow cadence, row timeout, max_parallel, and overlap settings remain owned by scheduled_jobs.',
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
            ->where('config_key', 'execute_workflow_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'execute_workflow_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for the generic queued workflow execution job.',
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
            ->where('config_key', 'execute_workflow_job_runtime_policy')
            ->delete();
    }
};
