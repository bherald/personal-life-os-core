<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ModelHostControllerBitNetCanaryAdmissionService
{
    /** @template T @param callable():T $callback @return T */
    public function run(string $requestId, callable $callback): mixed
    {
        return $this->runScoped($requestId, $callback, 'MHC-05', 'model_host_controller_canary');
    }

    /** @template T @param callable():T $callback @return T */
    public function runAutomatic(string $requestId, callable $callback): mixed
    {
        return $this->runScoped($requestId, $callback, 'MHC-06', 'model_host_controller_automatic');
    }

    /** @template T @param callable():T $callback @return T */
    private function runScoped(string $requestId, callable $callback, string $phase, string $markerKey): mixed
    {
        $requestId = trim($requestId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,110}\z/', $requestId) !== 1) {
            throw new RuntimeException('Model-host BitNet admission request identity is invalid.');
        }

        $this->admit($requestId, $phase, $markerKey);
        try {
            $this->clearCaches();

            return $callback();
        } finally {
            $this->restore($requestId, $phase, $markerKey);
        }
    }

    private function admit(string $requestId, string $phase, string $markerKey): void
    {
        if (! Schema::hasTable('llm_instances')) {
            throw new RuntimeException('MHC-05 BitNet provider table is unavailable.');
        }

        DB::transaction(function () use ($requestId, $phase, $markerKey): void {
            $row = DB::table('llm_instances')
                ->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id'))
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw new RuntimeException('MHC-05 BitNet admission requires the exact blocked narrow provider state.');
            }
            $config = $this->config($row->config);
            $route = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
            $permanent = ($config['manual_text_admission']['schema'] ?? null) === 'plos.bitnet.manual_text.v1'
                && ($row->routability ?? null) === 'allowed'
                && ($row->quarantine_status ?? null) === 'none'
                && ($route['require_cached_health'] ?? null) === false
                && ($route['require_explicit_instance'] ?? null) === true
                && ($route['tools_enabled'] ?? null) === false
                && ($route['allowed_model_roles'] ?? null) === ['privacy_deny_prefilter', 'offline_manual'];
            if ($permanent && ! AIService::hasManagedInferenceContract()) {
                throw new RuntimeException('Permanent BitNet privacy admission requires managed inference custody.');
            }
            if ((int) ($row->is_active ?? 0) !== 1
                || (! $permanent && (int) ($row->is_healthy ?? 1) !== 0)
                || (! $permanent && (string) ($row->routability ?? '') !== 'blocked')
                || (string) ($row->circuit_state ?? '') !== 'closed'
                || (! $permanent && (string) ($row->quarantine_status ?? '') !== 'auto_blocked_unhealthy')
                || (string) ($row->instance_type ?? '') !== 'local_llm'
                || (string) ($row->compat_status ?? '') !== 'authoritative'
                || (int) ($row->allows_private_data ?? 0) !== 1
                || (string) ($row->data_privacy_scope ?? '') !== 'local_private'
                || (int) ($row->port ?? 0) !== (int) app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'port')
                || (string) parse_url((string) ($row->base_url ?? ''), PHP_URL_HOST) !== app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'host')
                || ($config['default_model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'model')
                || (! $permanent && ($route['allowed_model_roles'] ?? null) !== ['privacy_deny_prefilter'])
                || ($route['allow_generic_fallback'] ?? null) !== false
                || ($route['require_model_role'] ?? null) !== true
                || ($route['require_redacted_input'] ?? null) !== true
                || isset($config['model_host_controller_canary'])
                || isset($config['model_host_controller_automatic'])) {
                throw new RuntimeException('Model-host BitNet admission requires the exact blocked narrow provider state.');
            }

            $config[$markerKey] = [
                'phase' => $phase,
                'request_id' => $requestId,
                'normal_routing' => false,
                'tools_enabled' => false,
                'fallback_enabled' => false,
                'admitted_config_identity' => ModelHostControllerResourcePolicyService::requestContractIdentity(array_replace_recursive($config, ['route_policy' => ['require_explicit_instance' => true]])),
                'prior' => [
                    'is_healthy' => (int) $row->is_healthy,
                    'health_score' => (int) ($row->health_score ?? 0),
                    'routability' => (string) $row->routability,
                    'quarantine_status' => $row->quarantine_status,
                    'quarantined_at' => $row->quarantined_at,
                    'quarantine_reason' => $row->quarantine_reason,
                    'quarantine_source' => $row->quarantine_source,
                    'last_health_check' => $row->last_health_check,
                    'route_policy_had_require_explicit_instance' => array_key_exists('require_explicit_instance', $route),
                    'route_policy_require_explicit_instance' => $route['require_explicit_instance'] ?? null,
                ],
            ];
            $config['route_policy']['require_explicit_instance'] = true;

            DB::table('llm_instances')->where('id', $row->id)->update($this->filterColumns([
                'is_healthy' => 1,
                'health_score' => max(60, (int) ($row->health_score ?? 0)),
                'routability' => 'allowed',
                'quarantine_status' => 'none',
                'quarantined_at' => null,
                'quarantine_reason' => null,
                'quarantine_source' => null,
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_health_check' => now(),
                'updated_at' => now(),
            ]));
        });
    }

    private function restore(string $requestId, string $phase, string $markerKey): void
    {
        DB::transaction(function () use ($requestId, $phase, $markerKey): void {
            $row = DB::table('llm_instances')
                ->where('instance_id', app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id'))
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw new RuntimeException('MHC-05 BitNet admission cleanup state is missing or ambiguous.');
            }
            $config = $this->config($row->config);
            $marker = is_array($config[$markerKey] ?? null)
                ? $config[$markerKey]
                : [];
            $prior = is_array($marker['prior'] ?? null) ? $marker['prior'] : [];
            if (($marker['phase'] ?? null) !== $phase
                || ($marker['request_id'] ?? null) !== $requestId
                || ($marker['normal_routing'] ?? null) !== false
                || ($marker['tools_enabled'] ?? null) !== false
                || ($marker['fallback_enabled'] ?? null) !== false
                || $prior === []) {
                throw new RuntimeException('MHC-05 BitNet admission cleanup state is missing or ambiguous.');
            }

            unset($config[$markerKey]);
            if (($marker['admitted_config_identity'] ?? null) !== ModelHostControllerResourcePolicyService::requestContractIdentity($config)
                || (int) $row->is_healthy !== 1 || $row->routability !== 'allowed'
                || $row->quarantine_status !== 'none' || $row->circuit_state !== 'closed') {
                throw new RuntimeException('BitNet provider changed during admission; preserve operator changes and reconcile.');
            }
            if (($prior['route_policy_had_require_explicit_instance'] ?? false) === true) {
                $config['route_policy']['require_explicit_instance'] = $prior['route_policy_require_explicit_instance'] ?? false;
            } else {
                unset($config['route_policy']['require_explicit_instance']);
            }
            DB::table('llm_instances')->where('id', $row->id)->update($this->filterColumns([
                'is_healthy' => (int) ($prior['is_healthy'] ?? 0),
                'health_score' => (int) ($prior['health_score'] ?? 0),
                'routability' => (string) ($prior['routability'] ?? 'blocked'),
                'quarantine_status' => $prior['quarantine_status'] ?? null,
                'quarantined_at' => $prior['quarantined_at'] ?? null,
                'quarantine_reason' => $prior['quarantine_reason'] ?? null,
                'quarantine_source' => $prior['quarantine_source'] ?? null,
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_health_check' => $prior['last_health_check'] ?? null,
                'updated_at' => now(),
            ]));
        });
        $this->clearCaches();
    }

    /** @return array<string,mixed> */
    private function config(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function filterColumns(array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn('llm_instances', $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }
}
