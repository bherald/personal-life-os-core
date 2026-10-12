<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ai', 'config_key' => 'offline_coding_runtime_policy'],
            [
                'config_value' => json_encode([
                    'version' => 1,
                    'instance_id' => 'ollama_secondary',
                    'profile' => 'plos-offline-coding',
                    'model_role' => 'coding',
                    'reasoning_effort' => 'low',
                    'allow_model_override' => true,
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Fail-closed profile, instance, role, and effort binding for offline Codex development and protected coding evaluation.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        Cache::forget('syscfg:ai.offline_coding_runtime_policy');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ai')
                ->where('config_key', 'offline_coding_runtime_policy')
                ->delete();
        }
        Cache::forget('syscfg:ai.offline_coding_runtime_policy');
    }
};
