<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_automatic_selection_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($policy) || ! is_array($policy['targets'] ?? null)) {
                throw new RuntimeException('Automatic selection target registration is invalid.');
            }
            $adapters = ['qwen38_87' => ['llama.cpp', 'openai_compatible'],
                'ollama_qwen3_8b_87' => ['ollama', 'ollama'], 'bitnet_87' => ['bitnet.cpp', 'privacy_gate']];
            foreach ($adapters as $id => [$engine, $adapter]) {
                if (! isset($policy['targets'][$id]) || ($policy['targets'][$id]['engine'] ?? null) !== $engine) {
                    continue;
                }
                $target = &$policy['targets'][$id];
                if (! array_key_exists('inference_adapter', $target)) {
                    $target['inference_adapter'] = $adapter;
                }
                if (! array_key_exists('inference_limits', $target)) {
                    // Preserve the former runtime limits as explicit editable table data.
                    $target['inference_limits'] = ['default_max_tokens' => 128, 'max_tokens' => 700, 'privacy_timeout_seconds' => 20];
                }
                unset($target);
            }
            $next = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($next !== $row->config_value) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => $next, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Keep explicit operator parameters; rollback never changes routing or reactivates a target.
    }
};
