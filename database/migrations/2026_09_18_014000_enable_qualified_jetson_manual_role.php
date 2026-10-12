<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODEL = 'qwen3.5:4b';

    private const ROLE = 'offline_manual';

    private const PROFILE = 'hybrid_jetson_offline_manual';

    private const RUNTIME = ['think' => false, 'temperature' => 0.1,
        'options' => ['num_ctx' => 2048, 'num_predict' => 128, 'temperature' => 0.1],
        'max_tokens' => 128, 'ai_timeout' => 120];

    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }
        DB::transaction(function (): void {
            if (DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->exists()) {
                return;
            }
            [$instance, $config, $row, $policy, $evidence] = $this->readLocked();
            if (isset($config['models'][self::ROLE]) || isset($config['role_runtime'][self::ROLE])
                || ($config['models']['embedding'] ?? null) !== 'embeddinggemma:latest'
                || ($config['models']['health'] ?? null) !== 'smollm2:135m'
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || ! is_array($config['route_policy']['allowed_model_roles'] ?? null)
                || ($evidence['deployment_state'] ?? null) !== 'candidate'
                || ($evidence['hardware_fit'] ?? null) !== 'feasible'
                || (int) $instance->max_concurrent !== 1) {
                throw new RuntimeException('Jetson manual role requires the qualified serial candidate preimage.');
            }
            $before = ['deployment_state' => $evidence['deployment_state'], 'roles' => $evidence['roles'] ?? [],
                'tools_enabled_present' => array_key_exists('tools_enabled', $config['route_policy']),
                'tools_enabled' => $config['route_policy']['tools_enabled'] ?? null];
            $config['models'][self::ROLE] = self::MODEL;
            $config['role_runtime'][self::ROLE] = self::RUNTIME;
            $config['route_policy']['allowed_model_roles'][] = self::ROLE;
            $config['route_policy']['tools_enabled'] = false;
            $evidence['deployment_state'] = 'production';
            $evidence['roles'] = [self::ROLE];
            $evidence['manual_qualification'] = ['context_tokens' => 2048, 'max_output_tokens' => 128,
                'runtime' => '0.30.8', 'artifact_digest' => 'sha256:2a654d98e6fba55d452b7043684e9b57a947e393bbffa62485a7aac05ee4eefd',
                'full_context_observed_tokens' => 2048, 'minimum_available_ram_bytes' => 2413658112,
                'cli_manual_cases_passed' => 3, 'cli_manual_cases_total' => 3,
                'tools_enabled' => false, 'automatic_task_promotion' => false,
                'limitations' => ['Genealogy and injection task fixtures failed; no standard, coding, vision or genealogy admission.', 'Home and alternate LAN isolation was simulated; physical alternate-router acceptance remains an operator check.']];
            $policy['hardware_classes']['jetson_orin_8gb']['models'][self::MODEL] = $evidence;
            $this->write($instance->id, $config, $row->id, $policy);
            DB::table('llm_model_profiles')->insert([
                'profile_name' => self::PROFILE, 'model_name' => self::MODEL,
                'description' => 'Explicit, tool-free Jetson manual chat; 2K context and 128-token output.',
                'enabled' => 1, 'status' => 'active', 'requires_override' => 1,
                'use_cases' => json_encode(['offline_manual_chat'], JSON_THROW_ON_ERROR),
                'capabilities' => json_encode(['text_input', 'text_output'], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['registration' => self::PROFILE, 'instance_id' => 'ollama_jetson',
                    'model_role' => self::ROLE, 'temperature_mode' => 'observation_only',
                    'qualification' => $evidence['manual_qualification'], 'preimage' => $before], JSON_THROW_ON_ERROR),
                'notes' => 'Manual chat only. Existing embedding and health models/defaults are preserved. No tools, generic fallback or automatic task promotion.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->clearCaches();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->lockForUpdate()->first();
            if ($profile === null || ! $profile->enabled) {
                return;
            }
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            [$instance, $config, $row, $policy, $evidence] = $this->readLocked();
            if (($metadata['registration'] ?? null) !== self::PROFILE
                || ($config['models'][self::ROLE] ?? null) !== self::MODEL
                || ($config['role_runtime'][self::ROLE] ?? null) != self::RUNTIME
                || ($config['route_policy']['tools_enabled'] ?? null) !== false
                || ($evidence['deployment_state'] ?? null) !== 'production'
                || ($evidence['roles'] ?? null) !== [self::ROLE]) {
                throw new RuntimeException('Jetson manual-role rollback refused operator drift.');
            }
            unset($config['models'][self::ROLE], $config['role_runtime'][self::ROLE]);
            $config['route_policy']['allowed_model_roles'] = array_values(array_filter(
                $config['route_policy']['allowed_model_roles'], fn ($role) => $role !== self::ROLE
            ));
            $before = $metadata['preimage'];
            if ($before['tools_enabled_present']) {
                $config['route_policy']['tools_enabled'] = $before['tools_enabled'];
            } else {
                unset($config['route_policy']['tools_enabled']);
            }
            $evidence['deployment_state'] = $before['deployment_state'];
            $evidence['roles'] = $before['roles'];
            $policy['hardware_classes']['jetson_orin_8gb']['models'][self::MODEL] = $evidence;
            $this->write($instance->id, $config, $row->id, $policy);
            DB::table('llm_model_profiles')->where('id', $profile->id)->update(['enabled' => 0, 'status' => 'staged', 'updated_at' => now()]);
        });
        $this->clearCaches();
    }

    private function readLocked(): array
    {
        $instance = DB::table('llm_instances')->where('instance_id', 'ollama_jetson')->lockForUpdate()->first();
        if ($instance === null) {
            throw new RuntimeException('Jetson provider is missing.');
        }
        $config = json_decode($instance->config, true, flags: JSON_THROW_ON_ERROR);
        $row = DB::table('system_configs')->where('section', 'llm')->where('config_key', 'model_hardware_evidence_policy')->lockForUpdate()->first();
        $policy = json_decode((string) ($row->config_value ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
        $evidence = $policy['hardware_classes']['jetson_orin_8gb']['models'][self::MODEL] ?? [];
        if (($config['model_admission']['enforce'] ?? null) !== true
            || ($config['model_admission']['hardware_class'] ?? null) !== 'jetson_orin_8gb' || $evidence === []) {
            throw new RuntimeException('Jetson hardware admission must remain enforced.');
        }

        return [$instance, $config, $row, $policy, $evidence];
    }

    private function write(int $instanceId, array $config, int $policyId, array $policy): void
    {
        DB::table('llm_instances')->where('id', $instanceId)->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        DB::table('system_configs')->where('id', $policyId)->update([
            'config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    private function clearCaches(): void
    {
        foreach (['llm_model_profiles', 'llm_model_hardware_evidence_policy', 'llm_instances_all', 'llm_instances_healthy'] as $key) {
            Cache::forget($key);
        }
    }
};
