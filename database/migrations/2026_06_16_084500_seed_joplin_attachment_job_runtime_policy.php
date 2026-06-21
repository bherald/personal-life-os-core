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
            'timeout_seconds' => 1800,
            'tries' => 1,
            'unique_for_seconds' => 3600,
            'backoff_seconds' => [60, 180],
            'overlap_release_after_seconds' => 60,
            'overlap_expire_after_seconds' => 300,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one Joplin attachment extraction/OCR job.',
                'tries' => 'Retry attempts for attachment jobs; kept low to avoid repeated timeout storms.',
                'unique_for_seconds' => 'ShouldBeUnique admission window for one note/resource pair.',
                'backoff_seconds' => 'Retry backoff sequence used by queued attachment processing.',
                'overlap' => 'WithoutOverlapping release and expiry values for duplicate note/resource work.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'jobs')
            ->where('config_key', 'joplin_attachment_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'jobs', 'config_key' => 'joplin_attachment_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued Joplin attachment extraction jobs.',
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
            ->where('section', 'jobs')
            ->where('config_key', 'joplin_attachment_runtime_policy')
            ->delete();
    }
};
