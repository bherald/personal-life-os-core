<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')
            || ! Schema::hasColumn('llm_model_profiles', 'metadata')) {
            return;
        }

        $profile = DB::table('llm_model_profiles')
            ->where('profile_name', 'media_output_audio')
            ->first(['metadata']);
        if ($profile === null) {
            return;
        }

        $metadata = json_decode((string) ($profile->metadata ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $existingRuntime = is_array($metadata['runtime'] ?? null) ? $metadata['runtime'] : [];
        $runtime = array_replace([
            'schema' => 'plos.tts_runtime.v1',
            'enabled' => true,
            'provider' => trim((string) config('services.tts.provider', 'auto')),
            'command' => trim((string) config('services.tts.command', '')),
            'voice' => trim((string) config('services.tts.voice', '')),
            'model' => trim((string) config('services.tts.model', '')),
            'timeout_seconds' => max(1, min(600, (int) config('services.tts.timeout', 120))),
            'output_format' => 'wav',
            'release_contract' => 'OHF-Voice/piper1-gpl@v1.4.2',
        ], $existingRuntime);

        DB::table('llm_model_profiles')
            ->where('profile_name', 'media_output_audio')
            ->update([
                'metadata' => json_encode(
                    array_replace($metadata, ['runtime' => $runtime]),
                    JSON_UNESCAPED_SLASHES
                ),
                'updated_at' => now(),
            ]);

        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        // Runtime policy is operator control data and remains intact on code rollback.
        Cache::forget('llm_model_profiles');
    }
};
