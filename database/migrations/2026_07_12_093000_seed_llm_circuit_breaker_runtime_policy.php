<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Shared table-owned LLM circuit-breaker thresholds for DB pool and cache fallback routing.';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', 'llm')
            ->where('config_key', 'circuit_breaker_runtime_policy')
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => 'llm',
                'config_key' => 'circuit_breaker_runtime_policy',
                'config_value' => json_encode([
                    'failure_threshold' => max(1, min(100, (int) config('circuit_breaker.failure_threshold', 5))),
                    'cooldown_seconds' => max(1, min(86400, (int) config('circuit_breaker.cooldown_seconds', 30))),
                    'retry_after_max_seconds' => 7200,
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('syscfg:llm.circuit_breaker_runtime_policy');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'llm')
            ->where('config_key', 'circuit_breaker_runtime_policy')
            ->where('description', self::DESCRIPTION)
            ->delete();
        Cache::forget('syscfg:llm.circuit_breaker_runtime_policy');
    }
};
