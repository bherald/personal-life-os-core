<?php

use App\Services\Custody\TaskCustodyService;
use App\Services\LLMPoolManagerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('task_custody_records')) {
            throw new RuntimeException('Existing configuration and task custody tables are required.');
        }
        DB::transaction(function (): void {
            $query = DB::table('system_configs')->where('section', 'ai')->where('config_key', LLMPoolManagerService::CUSTODY_POLICY_KEY);
            $existing = $query->lockForUpdate()->first();
            if ($existing !== null) {
                $policy = json_decode($existing->config_value, true, flags: JSON_THROW_ON_ERROR);
                if (($policy['schema'] ?? null) !== 'plos.pool_execution_policy.v1'
                    || ($policy['surface'] ?? null) !== TaskCustodyService::SURFACE_LLM_POOL_EXECUTION) {
                    throw new RuntimeException('Existing pool custody registration is incompatible.');
                }

                return;
            }
            DB::table('system_configs')->insert([
                'section' => 'ai', 'config_key' => LLMPoolManagerService::CUSTODY_POLICY_KEY,
                'data_type' => 'json', 'description' => 'Durable pool concurrency custody; enable only after independent old-writer qualification and exact catalog binding.',
                'config_value' => json_encode(['schema' => 'plos.pool_execution_policy.v1',
                    'surface' => TaskCustodyService::SURFACE_LLM_POOL_EXECUTION,
                    'enabled' => false, 'old_writers_qualified' => false, 'catalog_sha256' => null], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Cache::forget('syscfg:ai.'.LLMPoolManagerService::CUSTODY_POLICY_KEY);
    }

    public function down(): void
    {
        // Registration and every unresolved hold survive rollback. Old code is not safe to resume.
    }
};
