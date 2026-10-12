<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JETSON_INSTANCE_ID = 'ollama_jetson';

    private const CHAT_ROLES = ['fast', 'standard', 'coding'];

    private const REVIEW_PRESSURE_POLICY = [
        'enabled' => true,
        'window_days' => 7,
        'reserve_slots' => 6,
        'max_inflow_to_clearance_ratio' => 1.25,
        'minimum_created_for_ratio_gate' => 4,
        'policy_source' => 'system_configs.genealogy.supervisor_manager.review_pressure',
        'reason' => 'Keep operator review capacity ahead of preview-only packet production; face identity remains operator-owned.',
    ];

    public function up(): void
    {
        $this->protectJetsonEmbeddingCapacity();
        $this->addGeneaReviewPressurePolicy();
        $this->clearCaches();
    }

    public function down(): void
    {
        $this->restoreJetsonChatRoles();
        $this->removeGeneaReviewPressurePolicy();
        $this->clearCaches();
    }

    private function protectJetsonEmbeddingCapacity(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')
            ->where('instance_id', self::JETSON_INSTANCE_ID)
            ->first(['config', 'notes']);
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config ?? '{}');
        $models = $this->decodeMap($config['models'] ?? []);
        $rolePolicy = $this->decodeMap($config['role_policy'] ?? []);
        $routePolicy = $this->decodeMap($config['route_policy'] ?? []);
        $jetson = $this->decodeMap($config['jetson'] ?? []);

        if (! is_array($jetson['explicit_offline_fallback'] ?? null)) {
            $jetson['explicit_offline_fallback'] = [
                'profile' => 'plos-offline-jetson',
                'model' => (string) ($models['coding'] ?? $config['default_model'] ?? 'qwen3-coder:30b'),
                'normal_pool_routing' => false,
                'preserved_roles' => array_intersect_key($models, array_flip(self::CHAT_ROLES)),
                'preserved_role_policy' => array_intersect_key($rolePolicy, array_flip(self::CHAT_ROLES)),
                'preserved_route_policy' => array_intersect_key($routePolicy, array_flip([
                    'allowed_model_roles',
                    'require_model_role',
                    'allow_generic_fallback',
                ])),
                'reason' => 'Explicit read-only Codex fallback is preserved outside normal PLOS role routing.',
            ];
        }

        foreach (self::CHAT_ROLES as $role) {
            unset($models[$role], $rolePolicy[$role]);
        }

        $allowedRoles = array_values(array_filter(
            is_array($routePolicy['allowed_model_roles'] ?? null) ? $routePolicy['allowed_model_roles'] : [],
            static fn (mixed $role): bool => is_string($role) && ! in_array($role, self::CHAT_ROLES, true)
        ));
        foreach (['embedding', 'health'] as $role) {
            if (! in_array($role, $allowedRoles, true)) {
                $allowedRoles[] = $role;
            }
        }

        $routePolicy['allowed_model_roles'] = $allowedRoles;
        $routePolicy['require_model_role'] = true;
        $routePolicy['allow_generic_fallback'] = false;
        $routePolicy['normal_chat_routing'] = 'disabled_by_runtime_evidence';
        $routePolicy['normal_chat_disabled_reason'] = '7d telemetry: Jetson chat had 48% failures and about 224s average latency while embeddings remained fast and reliable.';
        $routePolicy['normal_chat_recovery_gate'] = 'task-specific eval plus live canary evidence required before a future migration restores chat roles.';

        $config['models'] = $models;
        $config['role_policy'] = $rolePolicy;
        $config['route_policy'] = $routePolicy;
        $config['jetson'] = $jetson;

        $note = '2026-07-09 RTC: removed Jetson fast/standard/coding from normal pool routing after 7d request-kind telemetry showed 48% chat failures and ~224s average latency. Embedding/health routing and the explicit plos-offline-jetson Codex fallback remain available.';
        DB::table('llm_instances')
            ->where('instance_id', self::JETSON_INSTANCE_ID)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'notes' => $this->appendNote($row->notes ?? null, $note),
                'updated_at' => now(),
            ]);
    }

    private function restoreJetsonChatRoles(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')
            ->where('instance_id', self::JETSON_INSTANCE_ID)
            ->first(['config']);
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config ?? '{}');
        $jetson = $this->decodeMap($config['jetson'] ?? []);
        $fallback = $this->decodeMap($jetson['explicit_offline_fallback'] ?? []);
        $models = $this->decodeMap($config['models'] ?? []);
        $rolePolicy = $this->decodeMap($config['role_policy'] ?? []);
        $routePolicy = $this->decodeMap($config['route_policy'] ?? []);

        foreach ($this->decodeMap($fallback['preserved_roles'] ?? []) as $role => $model) {
            if (is_string($role) && is_string($model) && in_array($role, self::CHAT_ROLES, true)) {
                $models[$role] = $model;
            }
        }
        foreach ($this->decodeMap($fallback['preserved_role_policy'] ?? []) as $role => $policy) {
            if (is_string($role) && is_string($policy) && in_array($role, self::CHAT_ROLES, true)) {
                $rolePolicy[$role] = $policy;
            }
        }

        $preservedRoutePolicy = $this->decodeMap($fallback['preserved_route_policy'] ?? []);
        foreach (['allowed_model_roles', 'require_model_role', 'allow_generic_fallback'] as $key) {
            if (array_key_exists($key, $preservedRoutePolicy)) {
                $routePolicy[$key] = $preservedRoutePolicy[$key];
            } else {
                unset($routePolicy[$key]);
            }
        }
        unset(
            $routePolicy['normal_chat_routing'],
            $routePolicy['normal_chat_disabled_reason'],
            $routePolicy['normal_chat_recovery_gate'],
            $jetson['explicit_offline_fallback']
        );

        $config['models'] = $models;
        $config['role_policy'] = $rolePolicy;
        $config['route_policy'] = $routePolicy;
        $config['jetson'] = $jetson;

        DB::table('llm_instances')
            ->where('instance_id', self::JETSON_INSTANCE_ID)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function addGeneaReviewPressurePolicy(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['config_value']);
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config_value ?? '{}');
        $config['review_pressure'] = self::REVIEW_PRESSURE_POLICY;

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function removeGeneaReviewPressurePolicy(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['config_value']);
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config_value ?? '{}');
        if (($config['review_pressure']['policy_source'] ?? null) === self::REVIEW_PRESSURE_POLICY['policy_source']) {
            unset($config['review_pressure']);
        }

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function appendNote(?string $existing, string $note): string
    {
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $note;
        }

        return str_contains($existing, $note) ? $existing : $existing."\n".$note;
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'llm_model_profiles',
            'llm_provider_role_bias_policy',
            'llm_role_fit_policy',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
