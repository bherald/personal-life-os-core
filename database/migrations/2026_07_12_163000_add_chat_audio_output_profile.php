<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }

        $now = now();
        $values = [
            'model_name' => 'sidecar:piper',
            'description' => 'Local text-to-speech generation with a stored WAV attachment',
            'use_cases' => json_encode(['speech_generation', 'accessibility', 'read_aloud'], JSON_UNESCAPED_SLASHES),
            'enabled' => 1,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('llm_model_profiles', 'status')) {
            $values['status'] = 'active';
        }
        if (Schema::hasColumn('llm_model_profiles', 'capabilities')) {
            $values['capabilities'] = json_encode([
                'text_input',
                'text_output',
                'audio_output',
                'generated_file_output',
                'text_to_speech',
                'local_tool',
            ], JSON_UNESCAPED_SLASHES);
        }
        if (Schema::hasColumn('llm_model_profiles', 'requires_override')) {
            $values['requires_override'] = 0;
        }
        if (Schema::hasColumn('llm_model_profiles', 'vetted_at')) {
            $values['vetted_at'] = $now;
        }
        if (Schema::hasColumn('llm_model_profiles', 'metadata')) {
            $values['metadata'] = json_encode([
                'runtime_source' => 'OHF-Voice/piper1-gpl@v1.4.2',
                'runtime_license' => 'GPL-3.0',
                'voice_source' => 'rhasspy/piper-voices@2525f22960f62209936f0ae3cce3ffe7b2c18f1f',
                'voice' => 'en_US-lessac-medium',
                'voice_license' => 'MIT repository signal; retain MODEL_CARD dataset notice',
                'max_input_chars' => 5000,
                'output_format' => 'wav',
            ], JSON_UNESCAPED_SLASHES);
        }

        $existing = DB::table('llm_model_profiles')->where('profile_name', 'media_output_audio')->first();
        if ($existing) {
            DB::table('llm_model_profiles')->where('profile_name', 'media_output_audio')->update($values);
        } else {
            DB::table('llm_model_profiles')->insert(['profile_name' => 'media_output_audio', 'created_at' => $now] + $values);
        }

        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        if (Schema::hasTable('llm_model_profiles')) {
            DB::table('llm_model_profiles')->where('profile_name', 'media_output_audio')->delete();
        }

        Cache::forget('llm_model_profiles');
    }
};
