<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'joplin';

    private const KEY = 'sync_runtime_policy';

    private const DESCRIPTION = 'Table-owned WebDAV, chunking, quarantine, vision, and OCR runtime policy for Joplin note sync.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.joplin_sync_runtime_policy.v1',
            'webdav' => [
                'connect_timeout_seconds' => 5,
                'request_timeout_seconds' => 120,
                'health_probe_timeout_seconds' => 10,
                'directory_listing_timeout_seconds' => 30,
                'note_fetch_timeout_seconds' => 15,
                'retry_attempts' => 3,
                'retry_base_delay_seconds' => 1,
                'attachment_timeout_seconds' => 30,
            ],
            'sync' => [
                'default_chunk_size' => 50,
                'last_sync_cache_days' => 7,
            ],
            'quarantine' => [
                'failure_threshold' => 3,
                'duration_hours' => 72,
                'cache_buffer_hours' => 24,
                'retained_errors' => 5,
            ],
            'vision' => [
                'timeout_seconds' => 120,
                'max_tokens' => 1500,
            ],
            'ocr' => [
                'probe_timeout_seconds' => 5,
                'process_timeout_seconds' => 60,
            ],
            'policy_notes' => [
                'scheduler_authority' => 'scheduled_jobs owns cadence, command limit, and outer timeout; this policy owns only in-service runtime behavior.',
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops the affected sync path before WebDAV, AI, OCR, or RAG mutation.',
                'prompt_integrity' => 'Lossy attachment summarization and prompt compression remain source-enforced off; extracted text is retained faithfully.',
                'operator_overrides' => 'Migration replay and rollback preserve operator-owned policy rows.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => self::SECTION,
                'config_key' => self::KEY,
                'config_value' => $this->encodedPolicy(),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', $this->encodedPolicy())
            ->where('description', self::DESCRIPTION)
            ->delete();
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    private function encodedPolicy(): string
    {
        return json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
