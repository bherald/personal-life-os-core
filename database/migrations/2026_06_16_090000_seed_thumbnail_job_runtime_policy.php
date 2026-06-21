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
            'timeout_seconds' => 120,
            'tries' => 2,
            'backoff_seconds' => [30, 60],
            'overlap_release_after_seconds' => 30,
            'overlap_expire_after_seconds' => 120,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one thumbnail generation job.',
                'tries' => 'Retry attempts for per-file thumbnail generation.',
                'backoff_seconds' => 'Retry backoff sequence used by queued thumbnail generation.',
                'overlap' => 'WithoutOverlapping release and expiry values for duplicate thumbnail work.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                'scheduled_throughput' => 'Batch command limits and runtime budget remain owned by scheduled_jobs or command options.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'files')
            ->where('config_key', 'thumbnail_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'files', 'config_key' => 'thumbnail_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued thumbnail generation jobs.',
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
            ->where('section', 'files')
            ->where('config_key', 'thumbnail_job_runtime_policy')
            ->delete();
    }
};
