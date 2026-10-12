<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use App\Services\ModelHostControllerWaitService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen-trials.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.ollama_qwen_trials.v1' || count($definition['candidates'] ?? []) !== 2) {
            throw new RuntimeException('Exact Qwen trial definition is unavailable.');
        }
        DB::transaction(function () use ($definition): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle'
                || DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                    ->where('status', 'held')->exists()) {
                throw new RuntimeException('Controller journal and FIFO must be idle before supervised trial admission.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing exact provider/controller policy is required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($config['controller_qwen_manual_trials_20260929']['manual_only'] ?? null) !== true
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || ($policy['execution_mode'] ?? null) !== 'serial'
                || ($policy['concurrent_inference_enabled'] ?? null) !== false
                || ($resources['host_reserves']['.87']['free_physical_bytes']['minimum'] ?? 0) < 8589934592) {
                throw new RuntimeException('Qwen trial scope or controller reserve drifted.');
            }
            $changed = false;
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $role = $candidate['role'].'_trial';
                $target = $policy['targets'][$id] ?? null;
                $capacity = $resources['profiles'][$candidate['resource_profile']] ?? null;
                if (! is_array($target) || ! is_array($capacity)
                    || ($config['models'][$role] ?? null) !== $candidate['model']
                    || ($config['controller_catalog']['targets'][$id]['activation_allowed'] ?? null) !== true
                    || ($target['model'] ?? null) !== $candidate['model']
                    || ($target['roles'] ?? null) !== [$role]
                    || ($target['state'] ?? null) !== 'staged'
                    || ($target['activation_allowed'] ?? null) !== true
                    || ($capacity['qualified'] ?? null) !== true
                    || ($capacity['model_identity'] ?? null) !== 'digest:'.$candidate['digest']) {
                    throw new RuntimeException('Qwen supervised-trial target differs from exact preimage.');
                }
                if (($target['requires_operator_override'] ?? null) === false) {
                    continue;
                }
                if ($target['requires_operator_override'] !== true) {
                    throw new RuntimeException('Qwen supervised-trial admission flag is invalid.');
                }
                $policy['targets'][$id]['requires_operator_override'] = false;
                $changed = true;
            }
            if (! $changed) {
                return;
            }
            (new ModelHostControllerPolicyService)->normalize($policy);
            $updated = DB::table('system_configs')->where('id', $policyRow->id)
                ->where('config_value', $policyRow->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('Controller policy changed during Qwen trial admission.');
            }
        });
        Cache::forget('syscfg:ai.model_host_controller_policy');
    }

    public function down(): void
    {
        // Preserve the operator's later trial decision.
    }
};
