<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICIES = [
        'hybrid_default_timeout_minutes' => [
            'config_key' => 'agents.hybrid_default_timeout_minutes',
            'default' => 90,
            'min' => 5,
            'max' => 150,
            'description' => 'Table-owned default hybrid agent workflow timeout in minutes; explicit scheduled-job/run timeout remains higher priority and runtime clamps this fallback to 5-150.',
        ],
        'ai_timeout_seconds' => [
            'config_key' => 'agents.ai_timeout_seconds',
            'default' => 45,
            'min' => 5,
            'max' => 3600,
            'description' => 'Table-owned default timeout in seconds for one agent LLM call; explicit run and skill values remain higher priority and runtime clamps this fallback to 5-3600.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        foreach (self::POLICIES as $key => $policy) {
            $exists = DB::table('system_configs')
                ->where('section', 'agents')
                ->where('config_key', $key)
                ->exists();
            if (! $exists) {
                $value = max($policy['min'], min($policy['max'], (int) config($policy['config_key'], $policy['default'])));
                DB::table('system_configs')->insert([
                    'section' => 'agents',
                    'config_key' => $key,
                    'config_value' => (string) $value,
                    'data_type' => 'int',
                    'description' => $policy['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Cache::forget('syscfg:agents.'.$key);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        foreach (self::POLICIES as $key => $policy) {
            DB::table('system_configs')
                ->where('section', 'agents')
                ->where('config_key', $key)
                ->where('description', $policy['description'])
                ->delete();
            Cache::forget('syscfg:agents.'.$key);
        }
    }
};
