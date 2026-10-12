<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $policy = json_decode((string) ($row->config_value ?? ''), true);
            if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
                || ! is_array($policy['targets'] ?? null)) {
                return;
            }
            $changed = false;
            // Registration preserves the installed contract, including Ollama's exemption.
            // Only known existing bindings are seeded; unknown targets need explicit policy.
            foreach (['qwen38_87' => false, 'ollama_qwen3_8b_87' => true, 'bitnet_87' => false, 'glm52_87' => false] as $id => $exempt) {
                if (is_array($policy['targets'][$id] ?? null) && ! array_key_exists('switch_budget_exempt', $policy['targets'][$id])) {
                    $policy['targets'][$id]['switch_budget_exempt'] = $exempt;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Additive policy is retained: rollback cannot distinguish an operator's
        // explicit choice from the seed. Old readers ignore this target field.
    }
};
