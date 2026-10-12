<?php

use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $id = 'native_qwen3_coder_87';
            $row = DB::table('llm_model_profiles')->where('profile_name', 'hybrid_native_qwen3_coder_30b')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
            if (isset($metadata['context_extension_registration'])) {
                return; // Never overwrite later qualification or operator changes.
            }
            $plan = $metadata['context_extension_plan'] ?? null;
            $result = $metadata['context_extension_result'] ?? null;
            if ($plan === null && $result === null) {
                return; // Hosts without this measured candidate keep their current registration.
            }
            if (! is_array($plan) || ! is_array($result) || ($result['passed'] ?? null) !== true
                || ($result['candidate_stopped'] ?? null) !== true || ($result['custody_free'] ?? null) !== true
                || ($result['actual_slot_context'] ?? null) !== ($plan['context_tokens'] ?? null)
                || ($result['input_tokens'] ?? null) !== ($plan['input_tokens'] ?? null)
                || ($result['output_tokens'] ?? null) !== ($plan['max_output_tokens'] ?? null)
                || $result['input_tokens'] + $result['output_tokens'] !== $plan['context_tokens'] - $plan['context_guard_tokens']
                || $result['minimum_available_bytes'] < $plan['ready_available_bytes']
                || $result['elapsed_seconds'] > $plan['request_seconds']) {
                throw new RuntimeException('Context extension requires an exact passing capacity and cleanup receipt.');
            }
            $instance = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_resource_policy')->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->first();
            if ($instance === null || $resourceRow === null || $policyRow === null) {
                throw new RuntimeException('Existing native runtime registration is required.');
            }
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $config = json_decode($instance->config, true, flags: JSON_THROW_ON_ERROR);
            $registration = $metadata['controller_registration'];
            if (($result['model_sha256'] ?? null) !== $registration['launch']['model_sha256']
                || ($result['executable_sha256'] ?? null) !== $registration['launch']['executable_sha256']) {
                throw new RuntimeException('Capacity evidence belongs to a different model or runtime.');
            }
            $host = $registration['host_id'];
            $oldProfile = $resources['bindings'][$host][$id];
            $candidate = $resources['profiles'][$oldProfile];
            $newProfile = 'native87.qwen3-coder-30b.b10964.'.intdiv($plan['context_tokens'], 1024).'k.serial';
            if (isset($resources['profiles'][$newProfile]) || $plan['context_tokens'] <= $registration['context_slot_tokens']
                || $plan['max_output_tokens'] !== $config['max_tokens']
                || $plan['ready_available_bytes'] !== $candidate['requirements']['loaded']['free_physical_bytes']['minimum']
                || $plan['cold_available_bytes'] !== $candidate['requirements']['cold']['free_physical_bytes']['minimum']
                || $plan['gpu_available_bytes'] !== $candidate['requirements']['cold']['gpu_free_bytes']['minimum']
                || $plan['request_seconds'] !== $config['absolute_timeout_seconds']) {
                throw new RuntimeException('Context extension would change an unrelated guard or overwrite a profile.');
            }
            $launch = $registration['launch'];
            $settings = $result['observed_launch_settings'] ?? [];
            foreach (['--parallel' => 'parallel_requests', '--n-gpu-layers' => 'gpu_layers', '--fit' => 'fit'] as $argument => $setting) {
                $index = array_search($argument, $launch['arguments'], true);
                if ($index === false || $launch['arguments'][$index + 1] !== (string) ($settings[$setting] ?? '')) {
                    throw new RuntimeException('Capacity launch settings differ from the registered runtime.');
                }
            }
            if (($settings['cpu_moe'] ?? null) !== in_array('--cpu-moe', $launch['arguments'], true)
                || ($settings['context_shift'] ?? null) !== ! in_array('--no-context-shift', $launch['arguments'], true)) {
                throw new RuntimeException('Capacity placement or context shifting differs.');
            }
            $position = array_search('--ctx-size', $launch['arguments'], true);
            if ($position === false || $launch['arguments'][$position + 1] !== (string) $registration['context_slot_tokens']) {
                throw new RuntimeException('Existing context launch argument differs.');
            }
            $launch['arguments'][$position + 1] = (string) $plan['context_tokens'];
            // Reconstruct the declared wire order after MySQL JSON key normalization.
            $ordered = [];
            foreach (['schema', 'executable_path', 'executable_sha256', 'model_path', 'model_sha256', 'working_directory', 'stdout_path', 'stderr_path', 'arguments'] as $field) {
                $ordered[$field] = $launch[$field];
            }
            $launchBytes = json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
            $oldLaunchHash = $registration['launch_sha256'];
            if (substr_count($registration['task_arguments'], $oldLaunchHash) !== 1) {
                throw new RuntimeException('Existing scheduled task launch binding differs.');
            }
            $registration['launch'] = $ordered;
            $registration['launch_sha256'] = hash('sha256', $launchBytes);
            $registration['task_arguments'] = str_replace($oldLaunchHash, $registration['launch_sha256'], $registration['task_arguments']);
            $registration['context_slot_tokens'] = $plan['context_tokens'];
            $registration['usable_context_tokens'] = $plan['context_tokens'] - $plan['context_guard_tokens'];
            $registration['enabled'] = false;
            $config['context_window'] = $registration['usable_context_tokens'];
            $target = $policy['targets'][$id];
            $target['lifecycle_adapter']['required_process_arguments']['--ctx-size'] = (string) $plan['context_tokens'];
            $spec = $target['lifecycle_adapter'] + ['control_sha256' => $registration['control_sha256'],
                'executable_sha256' => $launch['executable_sha256'], 'task_arguments' => $registration['task_arguments']];
            $values = [];
            foreach (DB::table('system_configs')->where('section', 'cli')->get() as $value) {
                $decoded = json_decode($value->config_value, true);
                if (is_array($decoded)) {
                    $values[$value->section.'.'.$value->config_key] = $decoded;
                }
            }
            $contract = (new CliProviderRuntimePolicy)->resolve([
                'configuration_eligible' => true, 'instance_id' => $id, 'engine' => 'llama.cpp',
                'model' => $config['default_model'], 'role' => $registration['role'], 'native_tools_configured' => false,
                'model_definition' => ['capabilities' => ['text', 'streaming', 'structured_output']],
                'instance' => array_replace((array) $instance, ['config' => $config]),
            ], $values);
            $candidate['qualified'] = false;
            $candidate['settings']['context_tokens'] = $registration['usable_context_tokens'];
            $candidate['runtime_identity'] = Resources::runtimeIdentity($spec);
            $candidate['request_contracts'] = ['cli.offline_manual.native' => Resources::requestContractIdentity($contract)];
            $resources['profiles'][$newProfile] = $candidate;
            (new Resources)->normalize($resources);
            $metadata['context_extension_registration'] = [
                'schema' => 'plos.native_context_extension.v1', 'state' => 'staged', 'enabled' => false,
                'previous_profile' => $oldProfile, 'profile' => $newProfile, 'registration' => $registration,
                'provider_config' => $config, 'controller_target' => $target, 'request_contract' => $contract,
                'next_gate' => 'Exact projected host, managed request/heartbeat/release and persisted-route verification before promotion',
            ];
            DB::table('system_configs')->where('id', $resourceRow->id)->update([
                'config_value' => json_encode($resources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            DB::table('llm_model_profiles')->where('id', $row->id)->update([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        Cache::forget('llm_model_profiles');
        Cache::forget('syscfg:ai.model_host_controller_resource_policy');
    }

    public function down(): void
    {
        // This additive, unbound candidate does not change active routes. Retain
        // evidence and later operator qualification during application rollback.
    }
};
