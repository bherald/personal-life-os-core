<?php

use App\Services\ModelHostControllerMhc08K3PolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = ['glm52_87', 'kimi_k3_87'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('system_configs')) {
                $this->updatePolicy('model_host_controller_policy', function (array $policy): array {
                    foreach (self::TARGETS as $id) {
                        if (isset($policy['targets'][$id])) {
                            $target = &$policy['targets'][$id];
                            $target['activation_allowed'] = false;
                            $target['requires_operator_override'] = true;
                            if (($target['state'] ?? null) !== 'storage_absent') {
                                $target['state'] = 'research_retained';
                            }
                            unset($target);
                        }
                    }

                    return $policy;
                });
                $this->updatePolicy('model_host_controller_automatic_selection_policy', function (array $policy): array {
                    foreach (self::TARGETS as $id) {
                        unset($policy['targets'][$id]);
                    }
                    foreach ($policy['role_targets'] ?? [] as $role => $id) {
                        if (in_array($id, self::TARGETS, true)) {
                            unset($policy['role_targets'][$role]);
                        }
                    }

                    return $policy;
                });
                $this->updatePolicy(ModelHostControllerMhc08K3PolicyService::CONFIG_KEY, function (array $policy): array {
                    $policy['enabled'] = false;
                    $policy['revision'] = ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION;

                    return (new ModelHostControllerMhc08K3PolicyService)->normalize($policy);
                });
            }
            if (Schema::hasTable('llm_instances')) {
                foreach (DB::table('llm_instances')->whereIn('instance_id', self::TARGETS)->lockForUpdate()->get() as $instance) {
                    $config = json_decode($instance->config, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($config)) {
                        throw new RuntimeException('Retired controller provider configuration is invalid.');
                    }
                    foreach (self::TARGETS as $id) {
                        if (isset($config['controller_catalog']['targets'][$id])) {
                            $target = &$config['controller_catalog']['targets'][$id];
                            $target['activation_allowed'] = false;
                            if (($target['policy_state'] ?? null) !== 'storage_absent') {
                                $target['policy_state'] = 'research_retained';
                            }
                            unset($target);
                        }
                    }
                    DB::table('llm_instances')->where('id', $instance->id)->update([
                        'is_active' => 0, 'routability' => 'blocked', 'config' => $this->json($config), 'updated_at' => now(),
                    ]);
                    if (Schema::hasTable('ollama_models')) {
                        DB::table('ollama_models')->where('instance_id', $instance->id)
                            ->update(['is_available' => 0, 'updated_at' => now()]);
                    }
                }
            }
            if (Schema::hasTable('llm_model_profiles')) {
                DB::table('llm_model_profiles')->whereIn('profile_name', ['deep_offline_colibri', 'deep_offline_kimi_k3'])
                    ->update(['enabled' => 0, 'updated_at' => now()]);
            }
            if (Schema::hasTable('scheduled_jobs')) {
                DB::table('scheduled_jobs')->where('name', 'model_host_controller_mhc08_k3_worker')
                    ->update(['enabled' => 0, 'updated_at' => now()]);
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    private function updatePolicy(string $key, callable $change): void
    {
        $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', $key)->lockForUpdate()->first();
        if ($row === null) {
            return;
        }
        $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new RuntimeException('Retired controller policy is invalid.');
        }
        $next = $this->json($change($policy));
        if ($next !== $row->config_value) {
            DB::table('system_configs')->where('id', $row->id)->update(['config_value' => $next, 'updated_at' => now()]);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function down(): void
    {
        // Retirement retains evidence and never reactivates routes on rollback.
    }
};
