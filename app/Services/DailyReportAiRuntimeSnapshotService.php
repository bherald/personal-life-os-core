<?php

namespace App\Services;

use App\Services\Ops\AIObservabilityMetricsService;
use Illuminate\Support\Facades\DB;

/**
 * Content-free, table-owned AI runtime inventory for the daily report.
 *
 * llm_instances owns endpoints, engines, and model catalogs. Table-owned
 * controller policies link controller target IDs to provider instance IDs;
 * controller status contributes only live custody and runtime state.
 */
class DailyReportAiRuntimeSnapshotService
{
    public function __construct(
        private readonly ModelHostControllerClient $controller,
        private readonly ?AIObservabilityMetricsService $metrics = null,
    ) {}

    public function controllerHealthObservations(array $instances): array
    {
        $bindings = [];
        foreach ($instances as $instance) {
            $config = json_decode((string) ($instance->config ?? '{}'), true);
            if (is_array($config) && array_key_exists('controller_catalog', $config)) {
                $bindings[$instance->instance_id] = $config['controller_catalog'];
            }
        }
        if ($bindings === []) {
            return [];
        }

        $observations = [];
        foreach ($bindings as $instanceId => $catalog) {
            $state = 'UNKNOWN';
            $bindingTargets = is_array($catalog) ? ($catalog['targets'] ?? []) : [];
            if (is_array($catalog) && ($catalog['schema'] ?? null) === 'plos.model_host_controller.catalog_binding.v1'
                && is_array($bindingTargets) && count($bindingTargets) === 1) {
                $targetId = array_key_first($bindingTargets);
                $binding = $bindingTargets[$targetId];
                $status = is_string($targetId) && is_array($binding)
                    && ($binding['target_id'] ?? null) === $targetId
                    && ($binding['provider_instance_id'] ?? null) === $instanceId
                    ? $this->selectedHealthStatus($targetId, $binding['engine'] ?? null) : [];
                $controller = $this->controllerSummary($status);
                $target = $status['target_descriptor'] ?? null;
                $runtime = $this->runtimeTargets($status)[$targetId] ?? [];
                if (is_array($binding) && is_array($target)
                    && ($binding['target_id'] ?? null) === $targetId
                    && ($binding['provider_instance_id'] ?? null) === $instanceId
                    && ($binding['controller_host_id'] ?? null) === ($status['host_id'] ?? null)
                    && is_string($binding['model'] ?? null) && $binding['model'] !== ''
                    && is_string($binding['engine'] ?? null) && $binding['engine'] !== ''
                    && ($binding['model'] ?? null) === ($target['model'] ?? null)
                    && ($binding['engine'] ?? null) === ($target['engine'] ?? null)
                    && is_int($runtime['owned_process_count'] ?? null)
                    && is_int($runtime['loopback_listener_count'] ?? null)
                    && is_string($runtime['task_state'] ?? null)
                    && is_bool($runtime['healthy'] ?? null)
                    && is_bool($runtime['model_exact'] ?? null)
                    && is_bool($runtime['running_exact'] ?? null)) {
                    $entry = $this->controlledEntry(['instance_id' => $instanceId], $target, $runtime, [], $controller, $targetId);
                    $resources = $targetId === ($status['mhc08_k3']['target'] ?? null)
                        ? ($status['mhc08_k3']['resources'] ?? [])
                        : ($status['lifecycle']['resources'] ?? $status['resources'] ?? []);
                    $state = $controller['state'] === 'BLOCKED' || ($resources['gate_passed'] ?? null) !== true
                        ? 'BLOCKED' : $entry['state'];
                }
            }
            $observations[$instanceId] = [
                'state' => $state,
                'healthy' => match ($state) {
                    'ACTIVE-LEASE', 'MANAGED-IDLE', 'RESEARCH-IDLE' => true,
                    'UNKNOWN' => null,
                    default => false,
                },
                'health_source' => 'model_host_controller',
                'health_persisted' => false,
                'observed_at' => now()->toIso8601String(),
            ];
        }

        return $observations;
    }

    /** Exact read-only observation; missing descriptors remain unknown on older hosts. */
    private function selectedHealthStatus(string $target, mixed $engine): array
    {
        // K3 has a separate ownership protocol and is not probed through this lane.
        if ($engine === 'colibri-k3') {
            return [];
        }
        try {
            $status = $this->controller->targetStatus($target);
            $descriptor = $status['target_descriptor'] ?? null;
            $runtimes = $status['lifecycle']['targets'] ?? null;
            if (($status['schema'] ?? null) !== 'plos.model_host_controller.target_status.v1'
                || ($status['target'] ?? null) !== $target
                || ($status['result'] ?? null) !== 'guarded_target'
                || ($status['mutation_available'] ?? null) !== false
                || ($status['contains_prompt_or_response_content'] ?? null) !== false
                || ($status['k3_lease_active'] ?? null) !== false
                || ! is_array($descriptor) || ($descriptor['target'] ?? null) !== $target
                || ! is_string($descriptor['model'] ?? null) || ! is_string($descriptor['engine'] ?? null)
                || ! is_string($descriptor['policy_state'] ?? null) || ! is_bool($descriptor['activation_allowed'] ?? null)
                || ! is_array($runtimes) || count($runtimes) !== 1
                || (array_values($runtimes)[0]['target'] ?? null) !== $target) {
                return [];
            }

            return $status;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *   controller:array<string,mixed>,
     *   entries:list<array<string,mixed>>,
     *   totals:array{local:int,external:int},
     *   issues:list<string>,
     *   watches:list<string>
     * }
     */
    public function snapshot(int $hours): array
    {
        $instances = $this->instances();
        $metricSnapshot = $this->usage(max(1, $hours));
        $usage = $metricSnapshot['instances'];
        $status = $this->controllerStatus();
        $controller = $this->controllerSummary($status);
        $targets = $this->targets($status);
        $runtimeTargets = $this->runtimeTargets($status);
        $issues = [];
        $watches = [];
        try {
            $providerMap = $this->controllerProviderMap($status, $instances);
        } catch (\Throwable) {
            $providerMap = [];
            $issues[] = 'Model-host controller policy catalog is malformed';
        }
        $targetsByProvider = [];
        foreach ($providerMap as $targetId => $providerId) {
            $targetsByProvider[$providerId] ??= [];
            $targetsByProvider[$providerId][] = $targetId;
        }
        $seenTargets = [];
        if (($metricSnapshot['summary']['available'] ?? false) !== true) {
            $watches[] = 'AI request metrics unavailable';
        }

        if (! $controller['reachable']) {
            $issues[] = 'Model-host controller unavailable';
        } elseif ($controller['state'] === 'BLOCKED') {
            $issues[] = 'Model-host controller is blocked';
        } elseif (! $controller['resource_gate_passed']) {
            $issues[] = 'Model-host controller resource guard is red';
        }

        $entries = [];
        foreach ($instances as $instanceId => $instance) {
            $providerTargets = $targetsByProvider[$instanceId] ?? [];
            if (count($providerTargets) > 1) {
                foreach ($providerTargets as $targetId) {
                    $seenTargets[$targetId] = true;
                }
                $entries[] = $this->entry($instance, $usage[$instanceId] ?? [], 'DRIFT');
                $watches[] = ($instance['instance_name'] ?? $instanceId).' has multiple controller target bindings';

                continue;
            }

            $targetId = $providerTargets[0] ?? null;
            if (is_string($targetId) && isset($targets[$targetId])) {
                $entry = $this->controlledEntry(
                    $instance,
                    $targets[$targetId],
                    $runtimeTargets[$targetId] ?? [],
                    $usage[$instanceId] ?? [],
                    $controller,
                    $targetId
                );
                $seenTargets[$targetId] = true;
            } else {
                $entry = $this->ordinaryEntry($instance, $usage[$instanceId] ?? []);
            }

            $entries[] = $entry;
            if ($entry['state'] === 'DRIFT') {
                $watches[] = $entry['host'].' '.$entry['engine'].' controller identity drift';
            } elseif (in_array($entry['state'], ['BLOCKED', 'UNAVAILABLE'], true)
                && ($entry['policy_state'] ?? null) !== 'storage_absent') {
                $message = $entry['host'].' '.$entry['engine'].' is '.$entry['state'];
                if ($entry['scope'] === 'local') {
                    $issues[] = $message;
                } else {
                    $watches[] = $message;
                }
            }
        }

        foreach ($targets as $targetId => $target) {
            if (isset($seenTargets[$targetId])) {
                continue;
            }
            $entry = $this->unregisteredControllerEntry(
                $target,
                $runtimeTargets[$targetId] ?? [],
                $controller,
                $targetId
            );
            $entries[] = $entry;
            if (($target['state'] ?? null) !== 'storage_absent') {
                $watches[] = 'Controller target '.$targetId.' has no table-owned provider row';
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$a['priority'], $a['host'], $a['port']] <=> [$b['priority'], $b['host'], $b['port']]);

        $totals = ['local' => 0, 'external' => 0];
        foreach ($usage as $instanceId => $metrics) {
            $scope = $this->scope((string) ($instances[$instanceId]['instance_type'] ?? ''));
            $totals[$scope] += (int) ($metrics['requests'] ?? 0);
        }

        return [
            'controller' => $controller,
            'entries' => $entries,
            'totals' => $totals,
            'metrics' => $metricSnapshot['summary'],
            'issues' => array_values(array_unique($issues)),
            'watches' => array_values(array_unique($watches)),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    protected function instances(): array
    {
        try {
            $rows = DB::table('llm_instances')
                ->select([
                    'instance_id', 'instance_name', 'instance_type', 'base_url',
                    'port', 'priority', 'host_affinity', 'compat_backend',
                    'is_active', 'is_healthy', 'health_score', 'routability',
                    'supported_models', 'total_requests', 'total_failures',
                    'success_rate', 'avg_response_ms', 'p95_response_ms',
                    'last_health_check', 'last_success_at', 'last_failure_at',
                ])
                ->orderBy('priority')
                ->orderBy('instance_id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $instances = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $instanceId = trim((string) ($values['instance_id'] ?? ''));
            if ($instanceId === '') {
                continue;
            }
            $values['is_active'] = (bool) ($values['is_active'] ?? false);
            $values['is_healthy'] = (bool) ($values['is_healthy'] ?? false);
            $instances[$instanceId] = $values;
        }

        return $instances;
    }

    /**
     * @return array{
     *   instances:array<string,array<string,mixed>>,
     *   summary:array<string,mixed>
     * }
     */
    protected function usage(int $hours): array
    {
        try {
            $snapshot = ($this->metrics ?? app(AIObservabilityMetricsService::class))->snapshot($hours);
        } catch (\Throwable) {
            return ['instances' => [], 'summary' => ['available' => false, 'calls' => 0]];
        }

        $usage = [];
        foreach ((array) ($snapshot['instances'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $instanceId = trim((string) ($row['instance_id'] ?? ''));
            if ($instanceId === '') {
                continue;
            }
            $usage[$instanceId] = [
                'requests' => (int) ($row['calls'] ?? 0),
                'failures' => (int) ($row['failures'] ?? 0),
                'success_rate' => $row['success_rate'] ?? null,
                'avg_latency_ms' => $row['avg_latency_ms'] ?? null,
                'p95_latency_ms' => $row['p95_latency_ms'] ?? null,
                'avg_tokens_per_second' => $row['avg_tokens_per_second'] ?? null,
                'usage_coverage_rate' => $row['usage_coverage_rate'] ?? null,
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                'last_used_at' => $row['last_used_at'] ?? null,
            ];
        }

        $summary = (array) ($snapshot['summary'] ?? []);
        $summary['available'] = ! isset($snapshot['unavailable_reason']);
        $summary['sampled'] = (bool) ($snapshot['sampled'] ?? false);
        $summary['sample_rows'] = (int) ($snapshot['sample_rows'] ?? 0);
        $summary['total_rows'] = (int) ($snapshot['total_rows'] ?? 0);

        return ['instances' => $usage, 'summary' => $summary];
    }

    /** @return array<string,mixed> */
    protected function controllerStatus(): array
    {
        try {
            $status = $this->controller->status();

            return ($status['schema'] ?? null) === 'plos.model_host_controller.status.v1' ? $status : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $status
     * @param  array<string,array<string,mixed>>  $instances
     * @return array<string,string>
     */
    protected function controllerProviderMap(array $status, array $instances): array
    {
        $map = [];
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
                throw new \RuntimeException('Malformed model-host controller policy.');
            }
            foreach ((array) ($policy['targets'] ?? []) as $targetId => $target) {
                $providerId = is_array($target) ? trim((string) ($target['provider_instance_id'] ?? '')) : '';
                if (is_string($targetId) && $providerId !== '') {
                    $map[$targetId] = $providerId;
                }
            }
            $target = (array) ($policy['target'] ?? []);
            $targetId = trim((string) ($target['target_id'] ?? ''));
            $providerId = trim((string) ($target['provider_instance_id'] ?? ''));
            if ($targetId !== '' && $providerId !== '') {
                $map[$targetId] = $providerId;
            }
        }

        foreach (array_keys($this->targets($status)) as $targetId) {
            if (! isset($map[$targetId]) && isset($instances[$targetId])) {
                $map[$targetId] = $targetId;
            }
        }

        return $map;
    }

    /** @param array<string,mixed> $status @return array<string,mixed> */
    private function controllerSummary(array $status): array
    {
        $lifecycle = (array) ($status['lifecycle'] ?? []);
        $k3 = (array) ($status['mhc08_k3'] ?? []);
        $k3Lease = data_get($k3, 'state.lease');
        $resources = (array) ($lifecycle['resources'] ?? $status['resources'] ?? []);
        $reachable = $status !== [] && ($status['result'] ?? null) !== 'disabled';
        $leasePresent = ($lifecycle['lease_present'] ?? false) === true || is_array($k3Lease);
        $activeTarget = ($lifecycle['lease_present'] ?? false) === true
            ? ($lifecycle['active_target'] ?? null)
            : (is_array($k3Lease) ? ($k3['target'] ?? null) : null);
        $result = (string) ($lifecycle['result'] ?? $status['result'] ?? 'unknown');
        $blocked = in_array($result, ['blocked', 'cleanup_failed', 'rollback_failed'], true);
        $resourceGatePassed = $reachable && ($resources['gate_passed'] ?? null) === true;
        if ($k3 !== []) {
            $resourceGatePassed = $resourceGatePassed && data_get($k3, 'resources.gate_passed') === true;
        }

        return [
            'reachable' => $reachable,
            'host_id' => trim((string) ($status['host_id'] ?? 'controller')),
            'state' => ! $reachable ? 'UNAVAILABLE' : ($blocked ? 'BLOCKED' : ($leasePresent ? 'ACTIVE-LEASE' : 'IDLE')),
            'result' => $result,
            'execution_mode' => strtoupper((string) ($lifecycle['execution_mode'] ?? $status['execution_mode'] ?? 'unknown')),
            'active_target' => $activeTarget,
            'lease_present' => $leasePresent,
            'tools_enabled' => $lifecycle['tools_enabled'] ?? $status['tools_enabled'] ?? null,
            'fallback_enabled' => $lifecycle['fallback_enabled'] ?? $status['fallback_enabled'] ?? null,
            'concurrent_inference_enabled' => $lifecycle['concurrent_inference_enabled'] ?? $status['concurrent_inference_enabled'] ?? null,
            'resource_gate_passed' => $resourceGatePassed,
            'free_ram_gb' => $this->bytesToGb($resources['free_physical_bytes'] ?? null),
        ];
    }

    /** @param array<string,mixed> $status @return array<string,array<string,mixed>> */
    private function targets(array $status): array
    {
        $targets = [];
        foreach ((array) ($status['targets'] ?? []) as $targetId => $target) {
            if (! is_array($target)) {
                continue;
            }
            $id = trim((string) ($target['target'] ?? (is_string($targetId) ? $targetId : '')));
            if ($id !== '') {
                $targets[$id] = $target;
            }
        }

        return $targets;
    }

    /** @param array<string,mixed> $status @return array<string,array<string,mixed>> */
    private function runtimeTargets(array $status): array
    {
        $targets = [];
        foreach ((array) data_get($status, 'lifecycle.targets', []) as $targetId => $target) {
            if (! is_array($target)) {
                continue;
            }
            $id = trim((string) ($target['target'] ?? (is_string($targetId) ? $targetId : '')));
            if ($id !== '') {
                $targets[$id] = $target;
            }
        }

        $k3 = (array) ($status['mhc08_k3'] ?? []);
        $k3Target = trim((string) ($k3['target'] ?? ''));
        if ($k3Target !== '') {
            $processCount = (int) ($k3['process_count'] ?? 0);
            $listenerCount = (int) ($k3['listener_count'] ?? 0);
            $healthy = data_get($k3, 'health.healthy') === true;
            $engineSha = trim((string) ($k3['engine_sha256'] ?? ''));
            $runtimeRevision = trim((string) ($k3['runtime_revision'] ?? ''));
            $identityExact = ($k3['task_exact'] ?? null) === true
                && preg_match('/\A[a-f0-9]{64}\z/', $engineSha) === 1
                && $runtimeRevision !== '';
            $targets[$k3Target] = [
                'target' => $k3Target,
                'task_exact' => ($k3['task_exact'] ?? null) === true,
                'control_exact' => $identityExact,
                'executable_exact' => $identityExact,
                'owned_process_count' => $processCount,
                'loopback_listener_count' => ($k3['loopback_only'] ?? null) === true ? $listenerCount : 0,
                'task_state' => $k3['task_state'] ?? 'Unknown',
                'healthy' => $healthy,
                'model_exact' => $healthy,
                'running_exact' => $healthy && $processCount === 1 && $listenerCount === 1
                    && ($k3['loopback_only'] ?? null) === true,
            ];
        }

        return $targets;
    }

    /** @param array<string,mixed> $instance @param array<string,mixed> $usage @return array<string,mixed> */
    private function ordinaryEntry(array $instance, array $usage): array
    {
        $state = match (true) {
            ($instance['is_active'] ?? false) !== true => 'INACTIVE',
            ($instance['routability'] ?? null) !== 'allowed' => 'BLOCKED',
            ($instance['is_healthy'] ?? false) !== true => 'UNAVAILABLE',
            default => 'ONLINE',
        };

        return $this->entry($instance, $usage, $state);
    }

    /**
     * @param  array<string,mixed>  $instance
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $usage
     * @param  array<string,mixed>  $controller
     * @return array<string,mixed>
     */
    private function controlledEntry(array $instance, array $target, array $runtime, array $usage, array $controller, string $targetId): array
    {
        $policyState = $target['state'] ?? $target['policy_state'] ?? null;
        $identityExact = ($runtime['task_exact'] ?? null) === true
            && ($runtime['control_exact'] ?? null) === true
            && ($runtime['executable_exact'] ?? null) === true;
        $runtimeActive = (int) ($runtime['owned_process_count'] ?? 0) > 0
            || (int) ($runtime['loopback_listener_count'] ?? 0) > 0
            || ($runtime['task_state'] ?? null) === 'Running';
        $runtimeHealthy = ($runtime['healthy'] ?? null) === true
            && ($runtime['model_exact'] ?? null) === true
            && ($runtime['running_exact'] ?? null) === true;
        $state = match (true) {
            ($controller['lease_present'] ?? false) === true
                && ($controller['active_target'] ?? null) === $targetId
                && $identityExact && $runtimeHealthy => 'ACTIVE-LEASE',
            ($controller['lease_present'] ?? false) === true
                && ($controller['active_target'] ?? null) === $targetId => 'DRIFT',
            $policyState === 'storage_absent' => 'UNAVAILABLE',
            $policyState === 'research_retained' => 'RESEARCH-IDLE',
            $runtime !== [] && (! $identityExact || ($runtimeActive && ! $runtimeHealthy)) => 'DRIFT',
            ($target['activation_allowed'] ?? false) === true
                && $runtime !== []
                && $identityExact => 'MANAGED-IDLE',
            default => 'BLOCKED',
        };

        $entry = $this->entry(
            $instance,
            $usage,
            $state,
            isset($target['model']) ? (string) $target['model'] : null,
            isset($target['engine']) ? (string) $target['engine'] : null
        );
        $entry['policy_state'] = $policyState;

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $runtime
     * @param  array<string,mixed>  $controller
     * @return array<string,mixed>
     */
    private function unregisteredControllerEntry(array $target, array $runtime, array $controller, string $targetId): array
    {
        $instance = [
            'instance_name' => ($controller['host_id'] ?? 'controller').' '.$targetId,
            'instance_type' => 'local_llm',
            'base_url' => $target['base_url'] ?? null,
            'port' => $target['port'] ?? null,
            'priority' => 999,
            'compat_backend' => $target['engine'] ?? 'controller',
            'supported_models' => json_encode(array_values(array_filter([$target['model'] ?? null]))),
        ];

        return $this->controlledEntry($instance, $target, $runtime, [], $controller, $targetId);
    }

    /**
     * @param  array<string,mixed>  $instance
     * @param  array<string,mixed>  $usage
     * @return array<string,mixed>
     */
    private function entry(array $instance, array $usage, string $state, ?string $requiredModel = null, ?string $engine = null): array
    {
        return [
            'host' => trim((string) ($instance['instance_name'] ?? $instance['host_affinity'] ?? 'Unnamed runtime')),
            'ip' => $this->endpointHost($instance),
            'port' => $this->endpointPort($instance),
            'engine' => trim((string) ($engine ?: ($instance['compat_backend'] ?? $instance['instance_type'] ?? 'unknown'))),
            'model' => $this->modelSummary($instance, $usage, $requiredModel),
            'state' => $state,
            'requests' => (int) ($usage['requests'] ?? 0),
            'failures' => (int) ($usage['failures'] ?? 0),
            'success_rate' => $usage['success_rate'] ?? null,
            'avg_latency_ms' => $usage['avg_latency_ms'] ?? null,
            'p95_latency_ms' => $usage['p95_latency_ms'] ?? null,
            'avg_tokens_per_second' => $usage['avg_tokens_per_second'] ?? null,
            'usage_coverage_rate' => $usage['usage_coverage_rate'] ?? null,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'last_used_at' => $usage['last_used_at'] ?? null,
            'health_score' => isset($instance['health_score']) ? (int) $instance['health_score'] : null,
            'last_health_check' => $instance['last_health_check'] ?? null,
            'scope' => $this->scope((string) ($instance['instance_type'] ?? '')),
            'priority' => (int) ($instance['priority'] ?? 999),
        ];
    }

    /** @param array<string,mixed> $instance @param array<string,mixed> $usage */
    private function modelSummary(array $instance, array $usage, ?string $requiredModel = null): string
    {
        $models = json_decode((string) ($instance['supported_models'] ?? '[]'), true);
        $available = [];
        if (is_array($models)) {
            foreach ($models as $model) {
                $name = trim((string) $model);
                if ($name !== '' && ! in_array($name, $available, true)) {
                    $available[] = $name;
                }
            }
        }
        foreach ([$requiredModel, $usage['model'] ?? null] as $model) {
            $name = trim((string) $model);
            if ($name !== '' && ! in_array($name, $available, true)) {
                array_unshift($available, $name);
            }
        }

        return $available === [] ? 'unknown' : implode(', ', $available);
    }

    /** @param array<string,mixed> $instance */
    private function endpointHost(array $instance): string
    {
        $host = parse_url((string) ($instance['base_url'] ?? ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'unregistered';
    }

    /** @param array<string,mixed> $instance */
    private function endpointPort(array $instance): int
    {
        $urlPort = parse_url((string) ($instance['base_url'] ?? ''), PHP_URL_PORT);

        return (int) ($urlPort ?: ($instance['port'] ?? 0));
    }

    private function scope(string $instanceType): string
    {
        return in_array($instanceType, ['ollama', 'local_llm'], true) ? 'local' : 'external';
    }

    private function bytesToGb(mixed $bytes): ?int
    {
        if (! is_numeric($bytes) || (float) $bytes < 0) {
            return null;
        }

        return (int) round((float) $bytes / 1073741824);
    }
}
