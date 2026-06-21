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
            ['section' => 'ai', 'config_key' => 'claude_slot_scaling_policy'],
            [
                'config_value' => json_encode([
                    'load_scale_up' => 1.5,
                    'load_scale_down' => 3.0,
                    'load_critical' => 5.0,
                    'memory_min_free_mb' => 512,
                    'memory_critical_mb' => 256,
                    'slots_per_core' => 2.0,
                    'absolute_max' => 20,
                    'min_concurrent' => 3,
                    'default_max' => 10,
                    'ollama_fallback_min' => 8,
                    'constrained_resource_divisor' => 3.0,
                    'ollama_down_resource_divisor' => 2.0,
                    'middle_ground_resource_divisor' => 2.0,
                    'good_resource_fraction' => 0.8,
                    'memory_scale_up_multiplier' => 3.0,
                    'memory_good_multiplier' => 2.0,
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned Claude/Codex slot scaling policy. Framework code must not impose hidden concurrency caps.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        Cache::forget('ai_claude_slot_scaling_policy');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ai')
                ->where('config_key', 'claude_slot_scaling_policy')
                ->delete();
        }

        Cache::forget('ai_claude_slot_scaling_policy');
    }
};
