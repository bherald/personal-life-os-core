<?php

namespace App\Services\UnifiedCli;

use App\Services\AIService;
use App\Services\CodexExecConnectorService;
use App\Services\ComputeRouterService;
use App\Services\SystemConfigService;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The same table-owned execution choices for direct, API and queued workflows. */
final class CliWorkflowExecutionScope
{
    public const SCHEMA = 'plos.cli.workflow-scope.v1';

    public const QUEUE_CONTEXT = 'plos.cli.workflow_scope';

    private const SELECTIONS = [
        'model_role' => 'cli.workflow_model_role',
        'model_selection' => 'cli.workflow_model',
        'vision_model_selection' => 'cli.workflow_vision_model',
        'embedding_model_selection' => 'cli.workflow_embedding_model',
        'compute_selection' => 'cli.compute_instance',
    ];

    public static function fromValues(array $values): array
    {
        $scope = ['schema' => self::SCHEMA, 'network' => ['mode' => $values['cli.network'] ?? 'online',
            'host_addresses' => $values['cli.host_addresses'] ?? []]];
        if (isset($values['routing.active_profile'])) {
            $scope['policy_profile'] = $values['routing.active_profile'];
        }
        foreach (self::SELECTIONS as $field => $key) {
            $scope[$field] = $values[$key] ?? 'auto';
        }
        foreach (['internet_access' => 'enabled', 'offline_mode' => 'disabled'] as $key => $default) {
            $scope[$key] = $values['routing.'.$key] ?? $default;
        }
        self::validate($scope);

        return $scope;
    }

    public static function fromFrameworkRequest(array $request): array
    {
        $settings = app(SystemConfigService::class);
        $scope = ['schema' => self::SCHEMA, 'network' => $request['network'] ?? [
            'mode' => $settings->get('cli.network', 'online'), 'host_addresses' => $settings->get('cli.host_addresses', [])]];
        if (isset($request['policy_profile'])) {
            $scope['policy_profile'] = $request['policy_profile'];
        }
        foreach (self::SELECTIONS as $field => $key) {
            $scope[$field] = $request[$field] ?? $settings->get($key, 'auto');
        }
        foreach (['internet_access' => 'enabled', 'offline_mode' => 'disabled'] as $key => $default) {
            $scope[$key] = $request[$key] ?? $settings->get('routing.'.$key, $default);
        }
        self::validate($scope);

        return $scope;
    }

    public static function validate(array $scope): void
    {
        if (($scope['schema'] ?? null) !== self::SCHEMA
            || array_diff(array_keys($scope), ['schema', 'network', 'policy_profile', 'internet_access', 'offline_mode', ...array_keys(self::SELECTIONS)]) !== []
            || ! is_array($scope['network'] ?? null)) {
            throw new RuntimeException('Workflow execution settings require the supported scope schema.');
        }
        if (array_key_exists('policy_profile', $scope)
            && (! is_string($scope['policy_profile']) || ! array_key_exists($scope['policy_profile'], (array) config('offline_policy.profiles', [])))) {
            throw new RuntimeException('Workflow policy profile must be a registered PLOS profile.');
        }
        foreach (['internet_access', 'offline_mode'] as $key) {
            if (array_key_exists($key, $scope) && ! in_array($scope[$key], ['enabled', 'disabled'], true)) {
                throw new RuntimeException('Workflow routing switches require enabled or disabled.');
            }
        }
        CliFrameworkHttpScope::validate($scope['network']);
        $role = $scope['model_role'] ?? null;
        if (! is_string($role) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $role)) {
            throw new RuntimeException('Workflow model role must be auto or a registered PLOS role.');
        }
        foreach (['model_selection', 'vision_model_selection', 'embedding_model_selection'] as $field) {
            CliWorkflowModelSelection::validate($scope[$field] ?? null);
        }
        $compute = $scope['compute_selection'] ?? null;
        if (! is_string($compute) || $compute === '' || trim($compute) !== $compute || strlen($compute) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $compute)) {
            throw new RuntimeException('Choose auto or an exact registered compute instance.');
        }
    }

    public function run(array $scope, Closure $operation, ?Closure $computeObserver = null, ?Closure $codexObserver = null): mixed
    {
        self::validate($scope);
        $settings = app(SystemConfigService::class);
        $targetNetwork = ['mode' => $settings->get('cli.network', 'online'),
            'host_addresses' => $settings->get('cli.host_addresses', [])];
        CliFrameworkHttpScope::validate($targetNetwork);
        $rank = ['offline' => 0, 'lan' => 1, 'online' => 2];
        $parentNetwork = self::current()['settings']['network']['mode'] ?? null;
        if ($parentNetwork !== null && $rank[$parentNetwork] < $rank[$targetNetwork['mode']]) {
            $targetNetwork['mode'] = $parentNetwork;
        }
        $scope['network'] = ['mode' => $rank[$scope['network']['mode']] < $rank[$targetNetwork['mode']]
            ? $scope['network']['mode'] : $targetNetwork['mode'],
            'host_addresses' => array_replace($targetNetwork['host_addresses'], $scope['network']['host_addresses'])];
        // A child or queued job cannot relax a parent or target-installation restriction.
        foreach (['internet_access' => ['enabled', 'disabled'], 'offline_mode' => ['disabled', 'enabled']] as $key => [$default, $restricted]) {
            $parent = self::current()['settings'][$key] ?? $default;
            $target = $settings->get('routing.'.$key, $default);
            $scope[$key] = ($scope[$key] ?? $default) === $restricted || $parent === $restricted || $target === $restricted
                ? $restricted : $default;
        }
        $role = $scope['model_role'];
        $validateRole = function () use ($role): void {
            foreach (DB::table('llm_instances')->where('is_active', true)->pluck('config') as $config) {
                $models = json_decode($config ?? '{}', true, flags: JSON_THROW_ON_ERROR)['models'] ?? [];
                if (is_string($models[$role] ?? null) && $models[$role] !== '') {
                    return;
                }
            }
            throw new RuntimeException('The selected workflow role is absent from active target tables. Sync or select another role.');
        };
        // Laravel already serializes hidden context into queue payloads. No
        // credentials, confirmations or closures become persisted job authority.
        $execute = fn () => CodexExecConnectorService::withManualCompactionOnly(
            fn () => (new CliFrameworkHttpScope)->run($scope['network'],
                fn () => AIService::withWorkflowModelRole($role === 'auto' ? null : $role,
                    fn () => ComputeRouterService::withRequiredInstance($scope['compute_selection'], $operation, $computeObserver),
                    $validateRole,
                    $scope['model_selection'] === 'auto' ? null : fn ($config) => (new CliWorkflowModelSelection)->resolve($scope['model_selection'], $role, $config),
                    $scope['vision_model_selection'] === 'auto' ? null : fn ($config) => (new CliWorkflowModelSelection)->resolve($scope['vision_model_selection'], 'vision', $config),
                    fn ($config) => (new CliWorkflowModelSelection)->resolve($scope['embedding_model_selection'], 'embedding', $config)
                        + ['preserve_embedding_input' => true, 'allow_cpu_model_download' => $scope['network']['mode'] === 'online'])));

        return Context::scope(fn () => $codexObserver === null ? $execute()
            : CodexExecConnectorService::withOutputObserver($execute, $codexObserver),
            hidden: [self::QUEUE_CONTEXT => ['installation' => self::installationIdentity(), 'settings' => $scope]]);
    }

    /** Laravel Bus middleware reapplies serialized settings before a queued handler. */
    public function handle(mixed $job, Closure $next): mixed
    {
        $context = self::current();
        if ($context === null) {
            return $next($job);
        }

        return $this->runCaptured($context, fn () => $next($job));
    }

    public static function current(): ?array
    {
        $context = Context::getHidden(self::QUEUE_CONTEXT);
        if ($context !== null) {
            self::validateCaptured($context);
        }

        return $context;
    }

    public static function validateCaptured(mixed $context): void
    {
        if (! is_array($context) || array_diff(array_keys($context), ['installation', 'settings']) !== []
            || ($context['installation'] ?? null) !== self::installationIdentity() || ! is_array($context['settings'] ?? null)) {
            throw new RuntimeException('Queued CLI work belongs to a different PLOS installation or has invalid settings. No alternate target was selected.');
        }
        self::validate($context['settings']);
    }

    public function runCaptured(array $context, Closure $operation, ?Closure $computeObserver = null, ?Closure $codexObserver = null): mixed
    {
        self::validateCaptured($context);

        return $this->run($context['settings'], $operation, $computeObserver, $codexObserver);
    }

    private static function installationIdentity(): string
    {
        $connection = config('database.default');
        $database = config('database.connections.'.$connection, []);

        return hash('sha256', json_encode([gethostname(), realpath(base_path()), $connection,
            array_intersect_key($database, array_flip(['driver', 'host', 'port', 'unix_socket', 'database']))], JSON_THROW_ON_ERROR));
    }
}
