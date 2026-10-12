<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('llm_instances')->where('instance_id', 'ollama_primary')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $config = json_decode((string) $row->config, true, 512, JSON_THROW_ON_ERROR);
            $route = $config['route_policy'] ?? [];
            if ((int) $row->is_active !== 1 || (int) $row->is_healthy !== 1
                || $row->routability !== 'blocked' || (int) $row->max_concurrent !== 1
                || ($route['operator_route_block'] ?? null) !== true
                || ($route['operator_route_block_phase'] ?? null) !== 'pipeline_act_2026_09_08'
                || ($route['allowed_model_roles'] ?? null) !== ['health']
                || ($config['models']['fast'] ?? null) !== 'qwen3:4b'
                || ($config['models']['embedding'] ?? null) !== 'embeddinggemma:latest'
                || ($config['models']['standard'] ?? null) !== 'dolphin-llama3:8b') {
                throw new RuntimeException('ollama_primary restoration requires the exact blocked preimage.');
            }

            $config['explicit_fit_restore_preimage'] = [
                'route_policy' => $route,
                'standard_model' => $config['models']['standard'],
                'standard_runtime' => $config['role_runtime']['standard'] ?? null,
                'fast_runtime' => $config['role_runtime']['fast'] ?? null,
                'embedding_runtime' => $config['role_runtime']['embedding'] ?? null,
                'routability' => $row->routability,
            ];
            $config['models']['standard'] = 'qwen3:4b';
            foreach (['standard', 'fast'] as $role) {
                $config['role_runtime'][$role] = [
                    'think' => false,
                    'keep_alive' => 0,
                    'max_tokens' => 128,
                    'options' => ['num_ctx' => 2048, 'num_predict' => 128],
                ];
            }
            $config['role_runtime']['embedding'] = ['keep_alive' => 0, 'options' => ['num_ctx' => 1024]];
            $config['route_policy'] = array_replace($route, [
                'allowed_model_roles' => ['standard', 'fast', 'embedding'],
                'require_model_role' => true,
                'require_explicit_instance' => true,
                'allow_generic_fallback' => false,
                'tools_enabled' => false,
                'operator_route_block' => false,
                'operator_route_block_phase' => '2026-09-30-explicit-fit',
                'normal_agent_routing' => 'explicit_only',
                'max_num_ctx' => 2048,
            ]);
            DB::table('llm_instances')->where('id', $row->id)->update([
                'routability' => 'allowed',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('llm_instances')->where('instance_id', 'ollama_primary')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $config = json_decode((string) $row->config, true, 512, JSON_THROW_ON_ERROR);
            $preimage = $config['explicit_fit_restore_preimage'] ?? null;
            if (! is_array($preimage)
                || ($config['route_policy']['operator_route_block_phase'] ?? null) !== '2026-09-30-explicit-fit'
                || ($config['models']['standard'] ?? null) !== 'qwen3:4b'
                || $row->routability !== 'allowed') {
                throw new RuntimeException('ollama_primary rollback preimage changed.');
            }
            $config['route_policy'] = $preimage['route_policy'];
            $config['models']['standard'] = $preimage['standard_model'];
            foreach (['standard', 'fast', 'embedding'] as $role) {
                $old = $preimage[$role.'_runtime'] ?? null;
                if ($old === null) {
                    unset($config['role_runtime'][$role]);
                } else {
                    $config['role_runtime'][$role] = $old;
                }
            }
            unset($config['explicit_fit_restore_preimage']);
            DB::table('llm_instances')->where('id', $row->id)->update([
                'routability' => $preimage['routability'],
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }
};
