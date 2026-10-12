<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('llm_instances')->where('instance_id', 'colibri_87')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
            // Colibri is an engine, not a retired model. Preserve a repurposed alias.
            if (($config['default_model'] ?? null) !== 'glm-5.2-colibri') {
                return;
            }
            $config['route_policy']['operator_route_block'] = true;
            $config['route_policy']['operator_route_block_phase'] = 'glm52_retirement_20260926';
            foreach ($config['controller_catalog']['targets'] ?? [] as $id => $target) {
                if (($target['model'] ?? null) === 'glm-5.2-colibri') {
                    $config['controller_catalog']['targets'][$id]['activation_allowed'] = false;
                }
            }
            DB::table('llm_instances')->where('id', $row->id)->update([
                'is_active' => 0, 'routability' => 'blocked',
                'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            if (Schema::hasTable('ollama_models')) {
                DB::table('ollama_models')->where('instance_id', $row->id)
                    ->update(['is_available' => 0, 'updated_at' => now()]);
            }
        });
        foreach (['external_api_providers', 'llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Operator retirement is retained; application rollback cannot reactivate GLM.
    }
};
