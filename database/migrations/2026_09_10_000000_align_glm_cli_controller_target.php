<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $target = $policy['targets']['glm52_87'] ?? [];
            // Repair only the obsolete pre-installation registration. Preserve
            // operator edits, other engines, and general pipeline routing policy.
            if (($policy['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
                || ($policy['host_id'] ?? null) !== '.87'
                || ($target['model'] ?? null) !== 'glm-5.2-colibri'
                || ($target['engine'] ?? null) !== 'colibri'
                || ($target['state'] ?? null) !== 'storage_absent'
                || ($target['activation_allowed'] ?? null) !== false
                || ($target['requires_operator_override'] ?? null) !== true) {
                return;
            }
            $instance = DB::table('llm_instances')->where('instance_id', 'colibri_87')->first();
            $config = json_decode($instance->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $runtime = $config['cli_agent_runtime'] ?? [];
            if (($runtime['schema'] ?? null) !== 'plos.cli.agent-runtime.v1'
                || ($runtime['controller_target'] ?? null) !== 'glm52_87'
                || ($runtime['model'] ?? null) !== $target['model']) {
                return;
            }
            $policy['targets']['glm52_87'] = array_replace($target, [
                'state' => 'production', 'activation_allowed' => true, 'requires_operator_override' => false,
            ]);
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        });
    }

    public function down(): void
    {
        // Reverting application code must not reclassify the installed model
        // as missing or reinstate an operator-retired activation hold.
    }
};
