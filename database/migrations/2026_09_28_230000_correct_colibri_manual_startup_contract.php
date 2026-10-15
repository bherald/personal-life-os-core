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
            'config/local-engine-candidates/colibri-glm-startup.87.json',
        ])) {
            return;
        }
        $correction = json_decode(file_get_contents(base_path('config/local-engine-candidates/colibri-glm-startup.87.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($correction): void {
            $provider = DB::table('llm_instances')->where('instance_id', 'colibri_87')->lockForUpdate()->first();
            $profile = DB::table('llm_model_profiles')->where('profile_name', 'deep_offline_colibri')->lockForUpdate()->first();
            $resource = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $profile || ! $resource) {
                throw new RuntimeException('Exact Colibri registration is missing.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            if (($config['manual_controller_restore']['startup_correction']['schema'] ?? null) === $correction['schema']) {
                return; // Preserve subsequent qualification and operator decisions.
            }
            $archive = $config['manual_controller_restore'] ?? [];
            $prior = $archive['managed_runtime'] ?? [];
            $managed = $correction['managed_runtime'];
            $expected = $prior;
            $expected['environment']['MTP'] = '0';
            $oldHash = $correction['prior_managed_runtime_sha256'];
            $newHash = $correction['managed_runtime_sha256'];
            $spec = $metadata['lifecycle_projection'] ?? [];
            $resources = json_decode($resource->config_value, true, flags: JSON_THROW_ON_ERROR);
            $binding = $resources['bindings']['.87']['glm52_87'] ?? null;
            $model = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', 'glm-5.2-colibri')->lockForUpdate()->first();
            if ($correction['schema'] !== 'plos.colibri.startup_correction.20260928.v1'
                || ($archive['schema'] ?? null) !== 'plos.colibri.manual_text.20260928.v1'
                || ($archive['qualification'] ?? null) !== 'pending'
                || (bool) $provider->is_active || $provider->routability !== 'blocked'
                || (bool) $profile->enabled || ! $model || (bool) $model->is_available
                || ($archive['managed_runtime_sha256'] ?? null) !== $oldHash
                || ($metadata['manual_controller_restore']['managed_runtime_sha256'] ?? null) !== $oldHash
                || array_key_exists('MTP', $prior['environment'] ?? [])
                || Resources::requestContractIdentity($managed) !== Resources::requestContractIdentity($expected)
                || preg_match('/\A[a-f0-9]{64}\z/', $newHash) !== 1
                || hash('sha256', $correction['managed_runtime_document']) !== $newHash
                || Resources::requestContractIdentity(json_decode($correction['managed_runtime_document'], true, flags: JSON_THROW_ON_ERROR)) !== Resources::requestContractIdentity($managed)
                || substr_count($spec['task_arguments'] ?? '', $oldHash) !== 1
                || $binding !== 'glm52_manual_2048_20260928'
                || ($resources['profiles'][$binding]['runtime_identity'] ?? null) !== Resources::runtimeIdentity($spec)
                || ($resources['profiles'][$binding]['settings']['mtp_enabled'] ?? null) !== false) {
                throw new RuntimeException('Pending Colibri startup predecessor changed.');
            }
            $proof = ['schema' => $correction['schema'], 'prior_managed_runtime_sha256' => $oldHash,
                'prior_task_arguments' => $spec['task_arguments'], 'prior_runtime_identity' => $resources['profiles'][$binding]['runtime_identity']];
            $spec['task_arguments'] = str_replace($oldHash, $newHash, $spec['task_arguments']);
            $config['manual_controller_restore']['managed_runtime'] = $managed;
            $config['manual_controller_restore']['managed_runtime_sha256'] = $newHash;
            $config['manual_controller_restore']['startup_correction'] = $proof;
            $metadata['manual_controller_restore']['managed_runtime'] = $managed;
            $metadata['manual_controller_restore']['managed_runtime_sha256'] = $newHash;
            $metadata['manual_controller_restore']['startup_correction'] = $proof;
            $metadata['lifecycle_projection'] = $spec;
            $resources['profiles'][$binding]['runtime_identity'] = Resources::runtimeIdentity($spec);
            $resources['revision'] .= '-colibri-startup-mtp-off';
            $resources = (new Resources)->normalize($resources);
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config), 'updated_at' => now()]);
            DB::table('llm_model_profiles')->where('id', $profile->id)->update(['metadata' => $this->json($metadata), 'updated_at' => now()]);
            DB::table('system_configs')->where('id', $resource->id)->update(['config_value' => $this->json($resources), 'updated_at' => now()]);
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function down(): void
    {
        // Restore only through matched table/host reconciliation; keep attempt evidence.
    }
};
