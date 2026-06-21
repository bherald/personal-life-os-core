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
            'timeout_seconds' => 300,
            'tries' => 3,
            'backoff_seconds' => [30, 60, 180],
            'overlap_release_after_seconds' => 60,
            'overlap_expire_after_seconds' => 300,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one AI auto-tag analysis job.',
                'tries' => 'Retry attempts for per-file AI analysis.',
                'backoff_seconds' => 'Retry backoff sequence used by queued AI auto-tagging.',
                'overlap' => 'WithoutOverlapping release and expiry values for duplicate per-file AI analysis.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                'scheduled_throughput' => 'Batch command limits and worker parallelism remain owned by scheduled_jobs or command options.',
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
            ->where('config_key', 'ai_auto_tag_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'files', 'config_key' => 'ai_auto_tag_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued AI auto-tag analysis jobs.',
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
            ->where('config_key', 'ai_auto_tag_job_runtime_policy')
            ->delete();
    }
};
