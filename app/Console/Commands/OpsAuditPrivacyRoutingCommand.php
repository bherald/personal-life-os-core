<?php

namespace App\Console\Commands;

use App\Services\OfflinePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpsAuditPrivacyRoutingCommand extends Command
{
    private const SENSITIVE_PERMISSION_NAMESPACES = [
        'contacts',
        'email',
        'finance',
        'genealogy',
        'health',
        'rag',
    ];

    protected $signature = 'ops:audit-privacy-routing
                            {--strict : Exit non-zero when reachable unsafe providers overlap sensitive tool permissions}
                            {--details : Include full sensitive tool and provider lists in JSON output}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Read-only audit of sensitive tool permissions against reachable LLM provider privacy posture';

    public function handle(): int
    {
        foreach (['llm_instances', 'agent_tool_registry'] as $table) {
            if (! Schema::hasTable($table)) {
                return $this->finish([
                    'generated_at' => now()->toIso8601String(),
                    'status' => 'fail',
                    'message' => "{$table} table is missing.",
                ], self::FAILURE);
            }
        }

        $strict = (bool) $this->option('strict');
        $offlineModeActive = $this->offlineModeActive();
        $activeProfile = $this->activeProfile();
        $allowedProviderClasses = $this->allowedProviderClasses($activeProfile, $offlineModeActive);
        $sensitiveTools = $this->sensitiveTools();
        $providers = $this->providerPosture($allowedProviderClasses, $offlineModeActive);

        $issues = [];
        if (! $this->profileExists($activeProfile)) {
            $issues[] = [
                'code' => 'unknown_routing_profile',
                'severity' => 'error',
                'message' => "Active routing profile '{$activeProfile}' is not defined; audit failed closed to local_llm only.",
            ];
        }

        if ($sensitiveTools['total_tools'] > 0 && $providers['reachable_cloud_external_count'] > 0) {
            $issues[] = [
                'code' => 'sensitive_tools_can_reach_cloud_external',
                'severity' => 'warning',
                'message' => 'Enabled sensitive tool permissions coexist with reachable sensitive_safe=false cloud providers under the current routing profile.',
            ];
        }

        $hasError = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? null) === 'error');
        $status = empty($issues) ? 'pass' : (($strict || $hasError) ? 'fail' : 'warn');
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'strict' => $strict,
            'active_profile' => $activeProfile,
            'offline_mode_active' => $offlineModeActive,
            'allowed_provider_classes' => $allowedProviderClasses,
            'sensitive_permission_namespaces' => self::SENSITIVE_PERMISSION_NAMESPACES,
            'sensitive_tools' => $sensitiveTools,
            'providers' => $providers,
            'issues' => $issues,
        ];

        if (! $this->option('details')) {
            $payload['sensitive_tools']['sample_tools'] = array_slice($payload['sensitive_tools']['tools'], 0, 20);
            unset($payload['sensitive_tools']['tools']);

            $payload['providers']['sample_providers'] = array_slice($payload['providers']['providers'], 0, 20);
            unset($payload['providers']['providers']);
        }

        return $this->finish($payload, $status === 'fail' ? self::FAILURE : self::SUCCESS);
    }

    private function sensitiveTools(): array
    {
        $rows = DB::select(
            'SELECT name, permissions
             FROM agent_tool_registry
             WHERE enabled = 1
             ORDER BY name ASC'
        );

        $tools = [];
        $namespaceCounts = array_fill_keys(self::SENSITIVE_PERMISSION_NAMESPACES, 0);

        foreach ($rows as $row) {
            $permissions = $this->decodeJson((string) ($row->permissions ?? '[]'));
            $sensitivePermissions = [];

            foreach ($permissions as $permission) {
                if (! is_string($permission)) {
                    continue;
                }

                $namespace = strtolower(strtok($permission, ':') ?: $permission);
                if (! in_array($namespace, self::SENSITIVE_PERMISSION_NAMESPACES, true)) {
                    continue;
                }

                $sensitivePermissions[] = $permission;
                $namespaceCounts[$namespace]++;
            }

            if ($sensitivePermissions === []) {
                continue;
            }

            $tools[] = [
                'name' => (string) $row->name,
                'permissions' => array_values(array_unique($sensitivePermissions)),
            ];
        }

        return [
            'total_tools' => count($tools),
            'namespace_counts' => $namespaceCounts,
            'tools' => $tools,
        ];
    }

    private function providerPosture(array $allowedProviderClasses, bool $offlineModeActive): array
    {
        $rows = DB::select(
            'SELECT instance_id, instance_name, instance_type, base_url, host_affinity, is_active, is_healthy,
                    routability, circuit_state, capabilities, config
             FROM llm_instances
             ORDER BY priority ASC, instance_id ASC'
        );

        $providers = [];
        $counts = [
            'total' => 0,
            'configured_active' => 0,
            'reachable' => 0,
            'reachable_local_llm' => 0,
            'reachable_cloud_sensitive_safe' => 0,
            'reachable_cloud_external' => 0,
        ];
        $reachableCloudExternal = [];

        foreach ($rows as $row) {
            $providerClass = $this->providerClass($row);
            $isActive = (int) ($row->is_active ?? 0) === 1;
            $isHealthy = (int) ($row->is_healthy ?? 0) === 1;
            $routability = (string) ($row->routability ?? 'allowed');
            $circuitState = (string) ($row->circuit_state ?? 'closed');
            $reachable = $isActive
                && $isHealthy
                && $routability === 'allowed'
                && $circuitState === 'closed'
                && in_array($providerClass, $allowedProviderClasses, true)
                && (! $offlineModeActive || $providerClass === 'local_llm');

            $counts['total']++;
            if ($isActive) {
                $counts['configured_active']++;
            }

            if ($reachable) {
                $counts['reachable']++;
                $counts['reachable_'.$providerClass]++;
            }

            $provider = [
                'instance_id' => (string) $row->instance_id,
                'instance_name' => (string) $row->instance_name,
                'instance_type' => (string) $row->instance_type,
                'provider_class' => $providerClass,
                'base_url_host' => $this->baseUrlHost((string) ($row->base_url ?? '')),
                'host_affinity' => (string) ($row->host_affinity ?? ''),
                'active' => $isActive,
                'healthy' => $isHealthy,
                'routability' => $routability,
                'circuit_state' => $circuitState,
                'reachable' => $reachable,
            ];

            $providers[] = $provider;

            if ($reachable && $providerClass === 'cloud_external') {
                $reachableCloudExternal[] = $provider;
            }
        }

        return [
            'counts' => $counts,
            'reachable_cloud_external_count' => count($reachableCloudExternal),
            'reachable_cloud_external' => $reachableCloudExternal,
            'providers' => $providers,
        ];
    }

    private function providerClass(object $provider): string
    {
        return app(OfflinePolicyService::class)->classifyProvider($provider);
    }

    private function baseUrlHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function allowedProviderClasses(string $profile, bool $offlineModeActive): array
    {
        if ($offlineModeActive) {
            return ['local_llm'];
        }

        if (! $this->profileExists($profile)) {
            return ['local_llm'];
        }

        $profiles = (array) config('offline_policy.profiles', []);
        $allowed = $profiles[$profile]['allowed_provider_classes'] ?? null;

        if (! is_array($allowed) || $allowed === []) {
            return (array) ($profiles['default']['allowed_provider_classes'] ?? ['local_llm']);
        }

        return array_values($allowed);
    }

    private function profileExists(string $profile): bool
    {
        return array_key_exists($profile, (array) config('offline_policy.profiles', []));
    }

    private function activeProfile(): string
    {
        $profile = $this->systemConfig('routing', 'active_profile');

        return $profile ?: (string) config('offline_policy.active_profile_default', 'default');
    }

    private function offlineModeActive(): bool
    {
        $value = $this->systemConfig('routing', 'offline_mode');

        return ! is_string($value) || strtolower(trim($value)) !== 'disabled';
    }

    private function systemConfig(string $section, string $key): ?string
    {
        if (! Schema::hasTable('system_configs')) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT config_value
             FROM system_configs
             WHERE section = ? AND config_key = ?
             LIMIT 1',
            [$section, $key]
        );

        return is_string($row->config_value ?? null) ? trim($row->config_value) : null;
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function finish(array $payload, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $status = strtoupper((string) ($payload['status'] ?? 'unknown'));
        $this->line("Privacy routing audit: {$status}");
        $this->line('Profile: '.($payload['active_profile'] ?? 'unknown').' | offline_mode='.(($payload['offline_mode_active'] ?? false) ? 'enabled' : 'disabled'));

        if (isset($payload['sensitive_tools'])) {
            $this->line('Sensitive tools: '.$payload['sensitive_tools']['total_tools']);
        }

        if (isset($payload['providers'])) {
            $counts = $payload['providers']['counts'];
            $this->line('Reachable providers: '.$counts['reachable'].' (cloud_external='.$payload['providers']['reachable_cloud_external_count'].')');
        }

        foreach (($payload['issues'] ?? []) as $issue) {
            $this->warn(($issue['code'] ?? 'issue').': '.($issue['message'] ?? ''));
        }

        return $exitCode;
    }
}
