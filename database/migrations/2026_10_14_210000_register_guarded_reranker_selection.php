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
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'rag', 'config_key' => 'reranker_controller_policy', 'data_type' => 'json',
            'config_value' => json_encode(['schema' => 'plos.reranker_controller_policy.v1',
                'enabled' => false, 'instance_id' => '', 'model' => ''], JSON_THROW_ON_ERROR),
            'description' => 'Explicit cross-encoder selection; requires operator-registered native model, role, receipt and resource admission.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::forget('syscfg:rag.reranker_controller_policy');
    }

    public function down(): void
    {
        // Retain later operator registration, original indexes and native UNKNOWN receipts.
    }
};
