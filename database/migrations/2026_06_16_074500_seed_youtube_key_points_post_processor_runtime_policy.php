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
            'execute_default_limit' => 50,
            'direct_process_default_limit' => 20,
            'dry_run_default' => false,
            'file_batch_size' => 50,
            'max_seconds' => 900,
            'title_preview_chars' => 50,
            'transcript_min_chars' => 50,
            'transcript_max_chars' => 12000,
            'ai_max_tokens' => 1000,
            'ai_temperature' => 0.0,
            'policy_notes' => [
                'execute_default_limit' => 'Default limit used by the workflow node execute() path.',
                'direct_process_default_limit' => 'Default limit used when processPlaceholderNotes() is called directly without a positive limit.',
                'file_batch_size' => 'Batch size for reading Joplin files before scanning placeholders.',
                'max_seconds' => 'Wall-clock processing budget for placeholder note backfill.',
                'transcript_limits' => 'Minimum accepted transcript length and maximum trusted-envelope transcript characters.',
                'ai_generation' => 'Default max tokens and temperature for key-point generation.',
                'overrides' => 'Workflow node config remains the per-run override for matching keys.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'youtube')
            ->where('config_key', 'key_points_post_processor_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'youtube', 'config_key' => 'key_points_post_processor_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for YouTube key-points post-processing limits, batching, and AI generation defaults.',
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
            ->where('section', 'youtube')
            ->where('config_key', 'key_points_post_processor_runtime_policy')
            ->delete();
    }
};
