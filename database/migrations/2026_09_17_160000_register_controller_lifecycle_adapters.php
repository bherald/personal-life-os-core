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
        $seeds = json_decode(<<<'JSON'
{
 "qwen38_87": {"adapter_type":"pinned_llama","control_roots":{"KimiRoot":"C:\\PLOS\\KimiK3","CandidateRoot":"D:\\PLOS-Models\\k3-alt"},"required_process_arguments":{"--n-gpu-layers":"0","--parallel":"1"},"resource_mode":"full"},
 "ollama_qwen3_8b_87": {"adapter_type":"ollama_coex","control_roots":{"KimiRoot":"C:\\PLOS\\KimiK3"},"required_process_arguments":{},"resource_mode":"runtime_managed"},
 "bitnet_87": {"adapter_type":"scheduled_llama","control_roots":{},"required_process_arguments":{},"resource_mode":"full"},
 "glm52_87": {"adapter_type":"colibri_gateway","control_roots":{},"required_process_arguments":{},"resource_mode":"ram_commit_c"}
}
JSON, true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($seeds): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $policy = json_decode((string) ($row->config_value ?? ''), true);
            if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
                || ! is_array($policy['targets'] ?? null)) {
                return;
            }
            $changed = false;
            foreach ($seeds as $id => $adapter) {
                if (is_array($policy['targets'][$id] ?? null) && ! array_key_exists('lifecycle_adapter', $policy['targets'][$id])) {
                    $policy['targets'][$id]['lifecycle_adapter'] = $adapter;
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
        // Additive registration remains: never erase operator choices or invent a
        // reverse mapping from an adapter to a model-specific implementation.
    }
};
