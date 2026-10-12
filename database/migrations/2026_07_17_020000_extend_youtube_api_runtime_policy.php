<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'youtube';

    private const KEY = 'transcript_runtime_policy';

    private const MARKER = '2026_07_17_youtube_api_runtime_policy';

    /** @return array<string,int> */
    private function extension(): array
    {
        return [
            'phplib_request_timeout_seconds' => 15,
            'phplib_connect_timeout_seconds' => 10,
            'whisper_youtube_download_timeout_seconds' => 120,
        ];
    }

    /** @return array<string,string> */
    private function policyNotes(): array
    {
        return [
            'phplib_request_timeout_seconds' => 'Total request timeout for the PHP transcript-library fallback.',
            'phplib_connect_timeout_seconds' => 'Connection timeout for the PHP transcript-library fallback.',
            'whisper_youtube_download_timeout_seconds' => 'Process timeout for the yt-dlp audio download used by the local Whisper fallback.',
            'youtube_api_failure_behavior' => 'Missing or invalid values stop the affected fallback before HTTP or process dispatch.',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $insertedKeys = [];
        foreach ($this->extension() as $key => $value) {
            if (! array_key_exists($key, $policy)) {
                $policy[$key] = $value;
                $insertedKeys[] = $key;
            }
        }

        $policy['policy_notes'] = is_array($policy['policy_notes'] ?? null)
            ? $policy['policy_notes']
            : [];
        $insertedNoteKeys = [];
        foreach ($this->policyNotes() as $key => $value) {
            if (! array_key_exists($key, $policy['policy_notes'])) {
                $policy['policy_notes'][$key] = $value;
                $insertedNoteKeys[] = $key;
            }
        }

        $policy['_migration_extensions'] = is_array($policy['_migration_extensions'] ?? null)
            ? $policy['_migration_extensions']
            : [];
        if (! is_array($policy['_migration_extensions'][self::MARKER] ?? null)) {
            $policy['_migration_extensions'][self::MARKER] = [
                'keys' => $insertedKeys,
                'policy_note_keys' => $insertedNoteKeys,
            ];
        }

        $this->updatePolicy($policy);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }
        $marker = is_array($policy['_migration_extensions'][self::MARKER] ?? null)
            ? $policy['_migration_extensions'][self::MARKER]
            : null;
        if ($marker === null) {
            return;
        }

        foreach ($marker['keys'] ?? [] as $key) {
            if (array_key_exists($key, $this->extension()) && ($policy[$key] ?? null) === $this->extension()[$key]) {
                unset($policy[$key]);
            }
        }
        foreach ($marker['policy_note_keys'] ?? [] as $key) {
            if (array_key_exists($key, $this->policyNotes())
                && ($policy['policy_notes'][$key] ?? null) === $this->policyNotes()[$key]) {
                unset($policy['policy_notes'][$key]);
            }
        }

        unset($policy['_migration_extensions'][self::MARKER]);
        if (($policy['_migration_extensions'] ?? []) === []) {
            unset($policy['_migration_extensions']);
        }

        $this->updatePolicy($policy);
    }

    /** @param array<string,mixed> $policy */
    private function updatePolicy(array $policy): void
    {
        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }
};
