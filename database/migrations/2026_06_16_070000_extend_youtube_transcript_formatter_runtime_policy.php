<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policyExtension(): array
    {
        return [
            'formatter_enabled_default' => true,
            'formatter_batch_size' => 5,
            'formatter_skip_if_no_transcript_default' => true,
            'formatter_missing_key_points_retries' => 1,
            'formatter_batch_delay_seconds' => 1,
            'formatter_min_formatted_chars' => 100,
            'formatter_max_seconds' => 240,
            'policy_notes' => [
                'formatter' => 'YouTubeTranscriptFormatter runtime defaults; explicit workflow node config still overrides formatter keys for a specific run.',
                'formatter_missing_key_points_retries' => 'Retries formatting when the LLM output omits the required key-points section.',
                'formatter_batch_delay_seconds' => 'Pause between formatted transcript batches; 0 disables the pause.',
                'formatter_min_formatted_chars' => 'Minimum non-empty AI formatted output length before accepting the result.',
                'formatter_max_seconds' => 'Wall-clock processing budget for formatter loop before yielding.',
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
            ->where('config_key', 'transcript_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policyExtension(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'youtube', 'config_key' => 'transcript_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for YouTube transcript fetch and formatter batching, retry, backoff, validation, and rate-limit timing.',
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
            ->where('section', 'youtube')
            ->where('config_key', 'transcript_runtime_policy')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($policy)) {
            return;
        }

        foreach (array_keys($this->policyExtension()) as $key) {
            if ($key === 'policy_notes') {
                continue;
            }

            unset($policy[$key]);
        }

        if (is_array($policy['policy_notes'] ?? null)) {
            unset(
                $policy['policy_notes']['formatter'],
                $policy['policy_notes']['formatter_missing_key_points_retries'],
                $policy['policy_notes']['formatter_batch_delay_seconds'],
                $policy['policy_notes']['formatter_min_formatted_chars'],
                $policy['policy_notes']['formatter_max_seconds']
            );
        }

        DB::table('system_configs')
            ->where('section', 'youtube')
            ->where('config_key', 'transcript_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
