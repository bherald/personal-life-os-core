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
            'default_language' => 'en',
            'include_timestamps_default' => true,
            'default_batch_size' => 5,
            'max_retries' => (int) config('youtube.transcript.max_retries', 3),
            'base_delay_seconds' => (int) config('youtube.transcript.base_delay', 10),
            'max_delay_seconds' => (int) config('youtube.transcript.max_delay', 300),
            'min_request_gap_seconds' => (int) config('youtube.transcript.min_request_gap', 15),
            'batch_delay_seconds' => (int) config('youtube.transcript.batch_delay', 60),
            'policy_notes' => [
                'default_batch_size' => 'Default YouTubeTranscript node batch size when workflow config does not provide one.',
                'retry_policy' => 'Retry count and backoff timings used by YouTubeTranscript node before marking a transcript fetch failed.',
                'request_gap' => 'Minimum wall-clock gap between transcript requests to reduce provider rate-limit pressure.',
                'batch_delay' => 'Pause between transcript batches.',
                'overrides' => 'Workflow node config values still override default_language, include_timestamps_default, and default_batch_size for a specific run.',
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

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'youtube', 'config_key' => 'transcript_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for YouTube transcript node batching, retry, backoff, and rate-limit timing.',
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
            ->where('config_key', 'transcript_runtime_policy')
            ->delete();
    }
};
