<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Reconcile table-owned provider rows with the live controller catalog. */
class ModelHostControllerCatalogSyncService
{
    private const BINDING_SCHEMA = 'plos.model_host_controller.catalog_binding.v1';

    public function __construct(private readonly ModelHostControllerClient $controller) {}

    /** @return array<string,mixed> */
    public function run(bool $execute = false): array
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('system_configs')) {
            throw new RuntimeException('Model-host catalog tables are unavailable.');
        }

        $status = $this->controller->status();
        if (($status['schema'] ?? null) !== 'plos.model_host_controller.status.v1') {
            throw new RuntimeException('Model-host controller returned an unsafe catalog status.');
        }

        $transport = $this->transport();
        $policies = $this->policyCatalog();
        $targets = $this->statusTargets($status, $policies);
        if ($targets === []) {
            throw new RuntimeException('Model-host controller returned no catalog targets.');
        }

        $hostId = trim((string) ($status['host_id'] ?? ''));
        if ($hostId === '' || mb_strlen($hostId) > 50) {
            throw new RuntimeException('Model-host controller returned an invalid host identity.');
        }
        $entries = $execute
            ? DB::transaction(function () use ($targets, $transport, $hostId): array {
                $receipts = [];
                foreach ($targets as $target) {
                    $receipts[] = $this->persist($target, $transport, $hostId);
                }

                return $receipts;
            })
            : array_map(fn (array $target): array => $this->plan($target, $transport, $hostId), $targets);

        if ($execute) {
            foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers', 'llm_model_profiles'] as $key) {
                try {
                    Cache::forget($key);
                } catch (\Throwable $exception) {
                    Log::warning('Model-host catalog cache invalidation failed', [
                        'cache_key' => $key,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return [
            'schema' => 'plos.model_host_controller.catalog_sync.v1',
            'result' => 'green',
            'execute' => $execute,
            'catalog_source' => ['llm_instances', 'system_configs', 'model_host_controller_status'],
            'host_id' => $hostId,
            'targets' => $entries,
            'summary' => [
                'total' => count($entries),
                'inserted' => count(array_filter($entries, static fn (array $entry): bool => $entry['action'] === 'inserted')),
                'updated' => count(array_filter($entries, static fn (array $entry): bool => $entry['action'] === 'updated')),
                'unchanged' => count(array_filter($entries, static fn (array $entry): bool => $entry['action'] === 'unchanged')),
                'planned_insert' => count(array_filter($entries, static fn (array $entry): bool => $entry['action'] === 'insert')),
                'planned_update' => count(array_filter($entries, static fn (array $entry): bool => $entry['action'] === 'update')),
            ],
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @return array{scheme:string,host:string} */
    private function transport(): array
    {
        $parts = parse_url((string) app(SystemConfigService::class)->llmRoutingValue('controller_transport', 'base_url'));
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new RuntimeException('Model-host controller transport is unavailable for catalog reconciliation.');
        }

        return ['scheme' => (string) $parts['scheme'], 'host' => (string) $parts['host']];
    }

    /** @return array<string,array<string,mixed>> */
    private function policyCatalog(): array
    {
        $catalog = [];
        $rows = DB::table('system_configs')
            ->where('section', 'ai')
            ->orderBy('config_key')
            ->get(['config_key', 'config_value']);

        foreach ($rows as $row) {
            if (preg_match('/\Amodel_host_controller(?:_[a-z0-9]+)*_policy\z/', (string) ($row->config_key ?? '')) !== 1) {
                continue;
            }
            $policy = json_decode((string) ($row->config_value ?? ''), true);
            if (! is_array($policy)) {
                throw new RuntimeException('Model-host controller policy is malformed.');
            }
            foreach ((array) ($policy['targets'] ?? []) as $targetId => $target) {
                if (! is_string($targetId) || ! is_array($target)) {
                    continue;
                }
                $catalog[$targetId] = $this->mergePolicyTarget(
                    $catalog[$targetId] ?? [],
                    $target,
                    'system_configs.ai.'.(string) $row->config_key,
                    $targetId,
                );
            }

            $target = (array) ($policy['target'] ?? []);
            $targetId = trim((string) ($target['target_id'] ?? ''));
            if ($targetId !== '') {
                $catalog[$targetId] = $this->mergePolicyTarget($catalog[$targetId] ?? [], [
                    ...$target,
                    'roles' => array_values((array) ($policy['roles'] ?? [])),
                ], 'system_configs.ai.'.(string) $row->config_key, $targetId);
            }
        }

        return $catalog;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming @return array<string,mixed> */
    private function mergePolicyTarget(array $existing, array $incoming, string $source, string $targetId): array
    {
        foreach (['provider_instance_id', 'model', 'engine'] as $field) {
            $current = trim((string) ($existing[$field] ?? ''));
            $candidate = trim((string) ($incoming[$field] ?? ''));
            if ($current !== '' && $candidate !== '' && $current !== $candidate) {
                throw new RuntimeException("Model-host policies disagree on {$targetId} {$field}.");
            }
        }

        $merged = array_replace($existing, $incoming);
        $merged['roles'] = array_values(array_unique([
            ...(array) ($existing['roles'] ?? []),
            ...(array) ($incoming['roles'] ?? []),
        ]));
        $merged['policy_sources'] = array_values(array_unique([
            ...(array) ($existing['policy_sources'] ?? []),
            $source,
        ]));

        return $merged;
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,array<string,mixed>>  $policies
     * @return list<array<string,mixed>>
     */
    private function statusTargets(array $status, array $policies): array
    {
        $targets = [];
        $providerOwners = [];
        $k3 = (array) ($status['mhc08_k3'] ?? []);
        foreach ((array) ($status['targets'] ?? []) as $key => $statusTarget) {
            if (! is_array($statusTarget)) {
                continue;
            }
            $targetId = trim((string) ($statusTarget['target'] ?? (is_string($key) ? $key : '')));
            if (preg_match('/\A[a-z0-9][a-z0-9_.:-]{2,63}\z/', $targetId) !== 1) {
                throw new RuntimeException('Model-host controller catalog contains an invalid target identity.');
            }
            $policy = $policies[$targetId] ?? [];
            $providerInstanceId = trim((string) ($policy['provider_instance_id'] ?? $targetId));
            if (preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,49}\z/', $providerInstanceId) !== 1) {
                throw new RuntimeException("Model-host target {$targetId} has an invalid provider identity.");
            }
            $model = trim((string) ($statusTarget['model'] ?? $policy['model'] ?? ''));
            $engine = trim((string) ($statusTarget['engine'] ?? $policy['engine'] ?? ''));
            foreach (['model' => $model, 'engine' => $engine] as $field => $liveValue) {
                $policyValue = trim((string) ($policy[$field] ?? ''));
                if ($policyValue !== '' && $liveValue !== $policyValue) {
                    throw new RuntimeException("Model-host status disagrees with policy for {$targetId} {$field}.");
                }
            }
            $port = filter_var($statusTarget['port'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($model === '' || $engine === '' || $port === false) {
                throw new RuntimeException("Model-host target {$targetId} has incomplete catalog identity.");
            }

            foreach ($providerOwners[$providerInstanceId] ?? [] as $owner) {
                if (! in_array($engine, ['ollama', 'diffusers'], true)
                    || $owner['engine'] !== $engine || $owner['port'] !== (int) $port
                    || $owner['model'] === $model || $owner['target_id'] === $targetId) {
                    throw new RuntimeException("Model-host provider {$providerInstanceId} maps to multiple controller targets with incompatible identities.");
                }
            }
            $providerOwners[$providerInstanceId][] = ['engine' => $engine, 'port' => (int) $port, 'model' => $model, 'target_id' => $targetId];

            $runtime = ($k3['target'] ?? null) === $targetId ? array_filter([
                'runtime_profile' => $k3['runtime_profile'] ?? null,
                'runtime_revision' => $k3['runtime_revision'] ?? null,
                'engine_sha256' => $k3['engine_sha256'] ?? null,
                'patch_sha256' => $k3['patch_sha256'] ?? null,
                'patched_tree' => $k3['patched_tree'] ?? null,
                'restart_state_present' => $k3['restart_state_present'] ?? null,
                'restart_state_sha256' => $k3['restart_state_sha256'] ?? null,
            ], static fn (mixed $value): bool => $value !== null) : [];

            $targets[] = [
                'target_id' => $targetId,
                'provider_instance_id' => $providerInstanceId,
                'model' => $model,
                'engine' => $engine,
                'port' => (int) $port,
                'policy_state' => (string) ($statusTarget['policy_state'] ?? $statusTarget['state'] ?? $policy['state'] ?? 'unknown'),
                'activation_allowed' => ($statusTarget['activation_allowed'] ?? $policy['activation_allowed'] ?? false) === true,
                'roles' => $this->roles($policy),
                'task_present' => ($statusTarget['task_present'] ?? false) === true,
                'task_state' => (string) ($statusTarget['task_state'] ?? 'Unknown'),
                'policy_sources' => $policy['policy_sources'] ?? ['model_host_controller_status'],
                'runtime' => $runtime,
            ];
        }

        return $targets;
    }

    /** @param array<string,mixed> $policy @return list<string> */
    private function roles(array $policy): array
    {
        $roles = array_values(array_unique(array_filter(
            (array) ($policy['roles'] ?? []),
            static fn (mixed $role): bool => is_string($role) && preg_match('/\A[a-z][a-z0-9_]{1,47}\z/', $role) === 1,
        )));
        $providerRole = trim((string) ($policy['provider_model_role'] ?? ''));
        if ($providerRole !== '' && ! in_array($providerRole, $roles, true)) {
            $roles[] = $providerRole;
        }

        return $roles;
    }

    /** @param array<string,mixed> $target @param array{scheme:string,host:string} $transport @return array<string,mixed> */
    private function plan(array $target, array $transport, string $hostId): array
    {
        $existing = DB::table('llm_instances')->where('instance_id', $target['provider_instance_id'])->first();
        $payload = $existing
            ? $this->existingPayload((array) $existing, $target, $hostId)
            : $this->newPayload($target, $transport, $hostId);

        return $this->receipt($target, $existing ? ($payload === [] ? 'unchanged' : 'update') : 'insert');
    }

    /** @param array<string,mixed> $target @param array{scheme:string,host:string} $transport @return array<string,mixed> */
    private function persist(array $target, array $transport, string $hostId): array
    {
        $query = DB::table('llm_instances')->where('instance_id', $target['provider_instance_id']);
        $existing = $query->lockForUpdate()->first();
        if ($existing) {
            $payload = $this->existingPayload((array) $existing, $target, $hostId);
            if ($payload === []) {
                return $this->receipt($target, 'unchanged');
            }
            $payload['updated_at'] = now();
            $query->update($this->filterColumns($payload));

            return $this->receipt($target, 'updated');
        }

        DB::table('llm_instances')->insert($this->filterColumns($this->newPayload($target, $transport, $hostId)));

        return $this->receipt($target, 'inserted');
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $target @return array<string,mixed> */
    private function existingPayload(array $existing, array $target, string $hostId): array
    {
        $config = $this->jsonMap($existing['config'] ?? null);
        $binding = $this->binding($target, $hostId);
        $controllerCatalog = is_array($config['controller_catalog'] ?? null) ? $config['controller_catalog'] : [];
        $currentTargets = is_array($controllerCatalog['targets'] ?? null) ? $controllerCatalog['targets'] : [];
        foreach ($currentTargets as $id => $registered) {
            if ($id === $target['target_id']) {
                continue;
            }
            if (! is_array($registered) || ! in_array($target['engine'], ['ollama', 'diffusers'], true)
                || ($registered['engine'] ?? null) !== $target['engine']
                || ($registered['port'] ?? null) !== $target['port']
                || ($registered['model'] ?? null) === $target['model']) {
                throw new RuntimeException('Existing provider catalog has incompatible shared engine identities.');
            }
        }
        $currentBinding = is_array($currentTargets[$target['target_id']] ?? null)
            ? $currentTargets[$target['target_id']]
            : [];
        $models = $this->jsonList($existing['supported_models'] ?? null);
        if (! in_array($target['model'], $models, true)) {
            $models[] = $target['model'];
        }

        $payload = [];
        if (! $this->jsonEquivalent($currentBinding, $binding)) {
            $config['controller_catalog'] = [
                'schema' => self::BINDING_SCHEMA,
                'targets' => [
                    ...$currentTargets,
                    $target['target_id'] => $binding,
                ],
            ];
            $payload['config'] = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($models !== $this->jsonList($existing['supported_models'] ?? null)) {
            $payload['supported_models'] = json_encode($models, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($target['provider_instance_id'] === $target['target_id'] && empty($existing['port'])) {
            $payload['port'] = $target['port'];
        }

        return $payload;
    }

    /** @param array<string,mixed> $target @param array{scheme:string,host:string} $transport @return array<string,mixed> */
    private function newPayload(array $target, array $transport, string $hostId): array
    {
        $roles = $target['roles'];
        $models = [];
        foreach ($roles as $role) {
            $models[$role] = $target['model'];
        }
        $priority = min(100, max(1, (int) DB::table('llm_instances')->max('priority') + 1));
        $baseUrl = sprintf('%s://%s:%d', $transport['scheme'], $transport['host'], $target['port']);
        $config = [
            'models' => $models,
            'default_model' => $target['model'],
            'controller_catalog' => [
                'schema' => self::BINDING_SCHEMA,
                'targets' => [$target['target_id'] => $this->binding($target, $hostId)],
            ],
            'route_policy' => [
                'require_model_role' => true,
                'allowed_model_roles' => $roles,
                'require_explicit_instance' => true,
                'allow_generic_fallback' => false,
            ],
        ];
        $now = now();

        return [
            'instance_id' => $target['provider_instance_id'],
            'instance_name' => mb_substr(trim($hostId.' '.$target['engine'].' '.$target['model']), 0, 100),
            'instance_type' => 'local_llm',
            'base_url' => $baseUrl,
            'port' => $target['port'],
            'priority' => $priority,
            'is_active' => 0,
            'routability' => 'blocked',
            'is_healthy' => 0,
            'health_score' => 0,
            'gpu_target' => 'none',
            'host_affinity' => $hostId,
            'compat_runtime_family' => mb_substr($target['engine'], 0, 30),
            'compat_backend' => mb_substr($target['engine'], 0, 30),
            'compat_status' => 'provisional',
            'capabilities' => json_encode([], JSON_THROW_ON_ERROR),
            'supported_models' => json_encode([$target['model']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_censored' => 1,
            'allows_private_data' => 0,
            'data_privacy_scope' => 'unreviewed',
            'privacy_reviewed_at' => null,
            'privacy_notes' => 'Controller-discovered local target; privacy and routing remain unreviewed.',
            'max_concurrent' => 1,
            'cost_tier' => 'free',
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'notes' => 'Controller catalog registration; runtime routing remains table-gated.',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function binding(array $target, string $hostId): array
    {
        return [
            'controller_host_id' => $hostId,
            'target_id' => $target['target_id'],
            'provider_instance_id' => $target['provider_instance_id'],
            'model' => $target['model'],
            'engine' => $target['engine'],
            'port' => $target['port'],
            'policy_state' => $target['policy_state'],
            'activation_allowed' => $target['activation_allowed'],
            'roles' => $target['roles'],
            'task_present' => $target['task_present'],
            'task_state' => $target['task_state'],
            'policy_sources' => $target['policy_sources'],
            'runtime' => $target['runtime'],
        ];
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function receipt(array $target, string $action): array
    {
        return [
            'target_id' => $target['target_id'],
            'provider_instance_id' => $target['provider_instance_id'],
            'model' => $target['model'],
            'engine' => $target['engine'],
            'port' => $target['port'],
            'policy_state' => $target['policy_state'],
            'action' => $action,
        ];
    }

    /** @return array<string,mixed> */
    private function filterColumns(array $payload): array
    {
        $columns = array_flip(Schema::getColumnListing('llm_instances'));

        return array_intersect_key($payload, $columns);
    }

    /** @return array<string,mixed> */
    private function jsonMap(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            $this->jsonMap($value),
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        )));
    }

    private function jsonEquivalent(mixed $left, mixed $right): bool
    {
        return $this->canonicalJsonValue($left) === $this->canonicalJsonValue($right);
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalJsonValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalJsonValue($item);
        }

        return $value;
    }
}
