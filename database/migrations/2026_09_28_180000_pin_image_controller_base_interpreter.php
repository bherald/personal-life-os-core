<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
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
        if (! (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/image-modalities.87.json',
        ])) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/image-modalities.87.json')), true, flags: JSON_THROW_ON_ERROR);
        $workloads = json_decode(file_get_contents(resource_path('model-host-controller/image-workloads.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($definition, $workloads): void {
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing image controller registration is required.');
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $changed = false;
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $profileName = $workloads['models'][$candidate['model']]['profile_name'];
                $row = DB::table('llm_model_profiles')->where('profile_name', $profileName)->lockForUpdate()->first();
                if (! $row || ! isset($policy['targets'][$id], $resources['bindings']['.87'][$id])) {
                    throw new RuntimeException('Image controller binding is incomplete.');
                }
                $metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
                $spec = $metadata['controller_candidate']['lifecycle_projection'] ?? null;
                $profileId = $resources['bindings']['.87'][$id];
                $profile = $resources['profiles'][$profileId] ?? null;
                if (! is_array($spec) || ! is_array($profile)
                    || ($spec['adapter_type'] ?? null) !== 'authenticated_image'
                    || ($metadata['controller_candidate']['target'] ?? null) !== $id
                    || $spec['model_api_id'] !== $candidate['model']
                    || $profile['target'] !== $id || $profile['model_identity'] !== $spec['model_identity']) {
                    throw new RuntimeException('Image interpreter predecessor identity differs.');
                }
                foreach (['interpreter_path', 'interpreter_sha256'] as $field) {
                    $value = $candidate['lifecycle_target'][$field];
                    foreach ([$spec, $policy['targets'][$id]['lifecycle_adapter']] as $existing) {
                        if (isset($existing[$field]) && $existing[$field] !== $value) {
                            throw new RuntimeException('Operator image interpreter binding differs.');
                        }
                    }
                    if (! isset($policy['targets'][$id]['lifecycle_adapter'][$field])) {
                        $changed = true;
                    }
                    $spec[$field] = $value;
                    $policy['targets'][$id]['lifecycle_adapter'][$field] = $value;
                }
                $identity = Resources::runtimeIdentity($spec);
                if (! in_array($profile['runtime_identity'], [$candidate['interpreter_predecessor_runtime_identity'], $identity], true)) {
                    throw new RuntimeException('Image capacity runtime predecessor differs.');
                }
                $updated = $metadata;
                $updated['controller_candidate']['lifecycle_projection'] = $spec;
                if ($updated !== $metadata || $profile['runtime_identity'] !== $identity) {
                    $changed = true;
                    $resources['profiles'][$profileId]['runtime_identity'] = $identity;
                    DB::table('llm_model_profiles')->where('id', $row->id)->update([
                        'metadata' => json_encode($updated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                        'updated_at' => now(),
                    ]);
                }
            }
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            if ($changed) {
                $resources['revision'] .= '-image-interpreter-20260928';
                foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                    DB::table('system_configs')->where('id', $row->id)->update([
                        'config_value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
        foreach (['llm_model_profiles', 'llm_instances', 'llm_instances_all', 'llm_instances_healthy'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Retain exact interpreter custody; rollback must not restore incomplete process ownership.
    }
};
