<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('llm_instances')->where('instance_id', 'ollama_secondary')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $config = json_decode($row->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            // Operator-approved removal of the known qualification hold. Do not
            // replace a later operator policy when replaying this migration.
            if (($config['route_policy']['operator_route_block_phase'] ?? null) !== 'pipeline_act_2026_09_08') {
                return;
            }
            $config['route_policy']['operator_route_block'] = false;
            $config['route_policy']['operator_route_block_phase'] = 'operator_cli_review_2026_09_09';
            $config['route_policy']['normal_agent_routing'] = 'enabled';
            $config['route_policy']['require_cached_health'] = false;
            $config['route_policy']['tools_enabled'] = true;
            unset($config['route_policy']['allowed_model_roles']);
            // Existing role/model definitions, runtime budgets and tool rules
            // remain operator-controlled. Never manufacture health telemetry.
            DB::table('llm_instances')->where('id', $row->id)->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'routability' => 'allowed',
                'quarantine_status' => 'none',
            ]);
        });
    }

    public function down(): void
    {
        // An explicit operator removal must not silently reinstall an obsolete
        // quarantine or fabricate its previous operational health state.
    }
};
