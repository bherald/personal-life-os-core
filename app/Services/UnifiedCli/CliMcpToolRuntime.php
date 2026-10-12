<?php

namespace App\Services\UnifiedCli;

use App\Engine\MCPRouter;
use App\Services\OfflinePolicyService;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Session definitions and PLOS policy precede the actual external MCP process. */
final class CliMcpToolRuntime
{
    private CliConfiguration $configuration;

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $plosRoot,
        private readonly string $nodeExecutable,
        private readonly CliArtifactStore $artifacts,
        private readonly Closure $environmentValue,
        private readonly ?Closure $internalAdmission = null,
        private readonly ?Closure $internalServiceFactory = null,
        private readonly ?Closure $internalToolDispatcher = null,
        private readonly bool $internalOnly = false,
        private readonly ?CliWorkflowServices $workflowServices = null,
    ) {
        $this->configuration = new CliConfiguration($store);
    }

    public function discover(string $server, array $context): array
    {
        if ($this->internalOnly) {
            throw new RuntimeException('External MCP discovery is unavailable in an internal worker.');
        }
        $session = $this->store->session($this->sessionId);
        $definitions = (new CliMcpServerDefinitions)->forSession($this->configuration, $session);
        $definition = $definitions[$server] ?? throw new RuntimeException('Selected MCP definition is unavailable.');
        $settings = new CliSystemConfigService($this->store, $this->sessionId);
        $profile = $settings->get(config('offline_policy.active_profile_config_key', 'routing.active_profile'), 'offline_review');
        $policy = new OfflinePolicyService(systemConfig: $settings, mcpServers: fn () => $definitions, workspaceRoot: $context['workspace'] ?? '');
        $decision = $policy->evaluateMcpServer($server, $profile, false);
        if (! $decision->allowed) {
            throw new RuntimeException('Current PLOS MCP policy denied discovery: '.$decision->reason);
        }
        $bound = (new CliMcpRuntimeBinding($this->plosRoot, $this->nodeExecutable))->resolve($server, $definition, $this->environmentValue);
        if ($bound['type'] !== 'external') {
            throw new RuntimeException('Live discovery is currently available for external MCP servers; internal contracts remain in the synced registry.');
        }
        [$receipt, $evidence] = $this->externalOperation($server, null, $bound, $session, $context);
        if (($receipt['protocol_valid'] ?? false) !== true || ! is_array($receipt['tools'] ?? null)
            || ! array_is_list($receipt['tools']) || ($receipt['calls_sent'] ?? null) !== 0) {
            throw new RuntimeException('MCP discovery did not return a complete catalog. Private protocol evidence is retained.');
        }

        return ['server_name' => $server, 'server' => $receipt['server'] ?? null,
            'capabilities' => $receipt['capabilities'] ?? [], 'instructions' => $receipt['instructions'] ?? null,
            'tools' => $receipt['tools'], 'catalog_sha256' => hash('sha256', json_encode($receipt['tools'], JSON_THROW_ON_ERROR)),
            'tool_calls' => 0, 'tools_admitted_for_agent' => false, 'private_evidence' => $evidence];
    }

    public function call(string $server, string $tool, array $arguments, array $context, array $toolDefinition, ?Closure $keeper = null): array
    {
        $session = $this->store->session($this->sessionId);
        $definitions = (new CliMcpServerDefinitions)->forSession($this->configuration, $session);
        $definition = $definitions[$server] ?? throw new RuntimeException('Selected MCP definition is unavailable.');
        if ($this->internalOnly && ($definition['type'] ?? null) !== 'internal') {
            throw new RuntimeException('External MCP execution is unavailable in an internal worker.');
        }
        $settings = new CliSystemConfigService($this->store, $this->sessionId);
        $policy = new OfflinePolicyService(systemConfig: $settings, mcpServers: fn () => $definitions, workspaceRoot: $context['workspace'] ?? '');
        $profile = $settings->get(config('offline_policy.active_profile_config_key', 'routing.active_profile'), 'offline_review');
        $policyContext = array_replace($context, $arguments, ['_audit' => false]);
        unset($policyContext['path']);
        $decision = $policy->evaluateMcpTool($server, $tool, $policyContext, $profile);
        if (! $decision->allowed) {
            throw new RuntimeException('Current PLOS MCP policy denied execution.');
        }
        foreach ($this->localPaths($server, $tool, $arguments) as $path) {
            // A permissive profile cannot change the session's selected workspace.
            // Inspect every actual argument, never a caller-supplied context alias.
            if (in_array($policy->classifyPath($path), ['protected_read', 'protected_write'], true)
                || ! $policy->evaluateMcpTool($server, $tool, array_replace($policyContext, ['path' => $path]), $profile)->allowed) {
                throw new RuntimeException('Current PLOS MCP policy denied a filesystem path outside the permitted workspace.');
            }
        }
        if (($decision->requiresConfirmation || ($toolDefinition['requires_confirmation'] ?? false))
            && ! $this->hasActionApproval($session, $server, $tool, $arguments, $context)) {
            throw new RuntimeException('This MCP operation requires an approved, claimed action for the exact current session and arguments.');
        }
        // Only table-declared context-only fields can cross the MCP boundary.
        // Approval/session authority and arbitrary server-requested fields cannot.
        foreach ($toolDefinition['parameters'] ?? [] as $key => $parameter) {
            if (! is_array($parameter) || ($parameter['x_context_only'] ?? false) !== true) {
                continue;
            }
            $name = is_string($key) ? $key : ($parameter['name'] ?? null);
            if (! is_string($name) || str_starts_with($name, '_') || ! in_array($name,
                ['tree_id', 'workspace', 'operation_target', 'execution_host'], true) || ! array_key_exists($name, $context)) {
                throw new RuntimeException('MCP context binding is unavailable or carries runtime authority.');
            }
            if (array_key_exists($name, $arguments) && $arguments[$name] !== $context[$name]) {
                throw new RuntimeException('MCP arguments differ from the selected operation target.');
            }
            $arguments[$name] = $context[$name];
        }
        if ($this->workflowServices?->context($server, $tool) !== null) {
            return $this->workflowServices->call($server, $tool, $arguments, $context, $keeper);
        }
        $bound = (new CliMcpRuntimeBinding($this->plosRoot, $this->nodeExecutable))->resolve($server, $definition, $this->environmentValue);
        if ($bound['type'] === 'internal') {
            if ($this->internalAdmission === null || ($this->internalServiceFactory === null && $this->internalToolDispatcher === null)) {
                throw new RuntimeException('This internal MCP service still requires session-bound dependencies and current operation-target admission.');
            }
            $router = new MCPRouter(new CliMcpRouterContext($settings, $policy,
                fn () => (new CliMcpServerDefinitions)->forSession($this->configuration, $this->store->session($this->sessionId)),
                fn ($name, $method) => ($this->internalAdmission)($session, $name, $method, $arguments, $context) === true,
                fn ($name, $class) => ($this->internalServiceFactory)($name, $class, $bound, $context),
                function ($name, $method, $error): void {
                    $this->recordInternalEvidence($name, $method, ['exception_type' => get_class($error), 'message' => $error->getMessage()]);
                }, $this->internalToolDispatcher === null ? null
                    : fn ($name, $method, $params) => ($this->internalToolDispatcher)($name, $method, $params, $bound, $context, $keeper)));
            $result = $router->callTool($server, $tool, $arguments);
            $this->recordInternalEvidence($server, $tool, $result);

            return $result;
        }
        [$receipt, $evidence] = $this->externalOperation($server, ['name' => $tool, 'arguments' => (object) $arguments], $bound, $session, $context, $keeper);
        if (($receipt['calls_sent'] ?? 0) > 0 && ($receipt['response_received'] ?? false) !== true) {
            throw new CliToolOutcomeUnknown('MCP action has no verified response; automatic replay is blocked.');
        }
        if (($receipt['protocol_valid'] ?? false) !== true || ! is_array($receipt['result'] ?? null)) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => $receipt['failure'] ?? 'MCP protocol rejected the response.']],
                'original_result' => $receipt['result'] ?? null, 'original_rpc_error' => $receipt['rpc_error'] ?? null,
                'private_evidence' => $evidence];
        }

        return $receipt['result'];
    }

    private function localPaths(string $server, string $tool, array $arguments): array
    {
        $map = (array) config('offline_policy.local_fs_path_mcp_tools', []);
        $names = $map[$server.'.'.$tool] ?? $map[$server.'.*'] ?? [];
        if ($server === 'filesystem') {
            // The installed filesystem server also accepts batches and moves.
            $names = array_unique(array_merge($names, ['path', 'paths', 'source', 'destination']));
        }
        $paths = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $arguments)) {
                continue;
            }
            $values = is_array($arguments[$name]) ? $arguments[$name] : [$arguments[$name]];
            if (! array_is_list($values) || $values === []) {
                throw new RuntimeException('MCP local filesystem paths require a nonempty string or list of strings.');
            }
            foreach ($values as $path) {
                if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
                    throw new RuntimeException('MCP local filesystem paths require nonempty strings.');
                }
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function externalOperation(string $server, ?array $tool, array $bound, array $session, array $context, ?Closure $keeper = null): array
    {
        $values = $this->configuration->effective($session)['values'];
        // A minimal PATH is execution plumbing, not a copied production variable.
        $runtimePath = PHP_OS_FAMILY === 'Windows' ? dirname($this->nodeExecutable) : dirname($this->nodeExecutable).':/usr/bin:/bin';
        $bound['env'] = array_replace(['PATH' => $runtimePath], $bound['env'] ?? []);
        $timeout = $values['cli.mcp_timeout_ms'] ?? 30000;
        if (! is_int($timeout) || $timeout < 50 || $timeout > 3600000) {
            throw new RuntimeException('MCP timeout requires a valid explicit millisecond value.');
        }
        $launch = (new CliMcpProcessPlan(windows: PHP_OS_FAMILY === 'Windows'
            ? CliWindowsProcessPlan::fromEnvironment($this->plosRoot) : null, applicationRoot: $this->plosRoot))->build(
                $bound, CliConnectivity::toolNetwork($values), $context['workspace'] ?? '', $timeout,
                $values['cli.native_permissions'] ?? null, $values['cli.operator_mode']);
        $request = ['launch' => $launch, 'tool' => $tool, 'timeout_ms' => $timeout];
        // Suppress inherited application credentials in the worker environment.
        $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);
        $environment['PATH'] = $runtimePath;
        if (PHP_OS_FAMILY === 'Windows') {
            $environment['SystemRoot'] = $launch['env']['SystemRoot'];
        }
        $command = [$this->nodeExecutable, $this->plosRoot.'/scripts/plos-cli/mcp-operation.mjs'];
        if ($keeper !== null) {
            $command[] = '--managed-stdin';
        }
        $process = new Process($command, $this->plosRoot, $environment, timeout: ($timeout / 1000) + 5);
        try {
            (new CliMcpWorkerProcess)->run($process, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $keeper);
        } catch (\Throwable) {
            $this->saveEvidence($server, $tool['name'] ?? 'tools/list', $process->getOutput(), $process->getErrorOutput());
            throw new CliToolOutcomeUnknown('MCP worker interrupted; inspect private evidence before resuming this action.');
        }
        $evidence = $this->saveEvidence($server, $tool['name'] ?? 'tools/list', $process->getOutput(), $process->getErrorOutput());
        try {
            $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('MCP worker returned no valid receipt; inspect private evidence before resuming.');
        }
        if (! $process->isSuccessful() || ($receipt['schema'] ?? null) !== 'plos.cli.mcp-operation.v1') {
            throw new CliToolOutcomeUnknown('MCP worker completion is unverified; inspect private evidence before resuming.');
        }

        return [$receipt, $evidence];
    }

    private function hasActionApproval(array $session, string $server, string $tool, array $arguments, array $context): bool
    {
        foreach (['_cli_action_id', '_cli_action_name', '_cli_action_turn'] as $key) {
            if (! is_string($context[$key] ?? null)) {
                return false;
            }
        }
        $scope = $context['_cli_approval_scope'] ?? null;
        if (! is_array($scope) || ($scope['configuration_sha256'] ?? null) !== hash('sha256', json_encode(
            $this->configuration->effective($session), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
            return false;
        }
        if (($scope['target'] ?? null) !== array_intersect_key($context, array_flip(['workspace', 'tree_id', 'operation_target', 'execution_host']))) {
            return false;
        }
        $action = ['id' => $context['_cli_action_id'], 'name' => $context['_cli_action_name'], 'arguments' => $arguments];
        if (($context['_cli_operator_override'] ?? false) === true) {
            $action['operator_override'] = true;
        }
        $registered = (new CliToolCatalog($this->configuration))->definitions($session)[$action['name']] ?? [];
        if (($registered['mcp_server'] ?? null) !== $server || ($registered['mcp_tool'] ?? null) !== $tool) {
            return false;
        }
        $receipt = $this->store->actionReceipts($this->sessionId, $context['_cli_action_turn'])[$action['id']] ?? null;

        return ($receipt['state'] ?? null) === 'started' && ($receipt['document']['action'] ?? null) === $action
            && $this->store->hasApproval($this->sessionId, $context['_cli_action_turn'], $action, $scope);
    }

    private function saveEvidence(string $server, string $tool, string $stdout, string $stderr): array
    {
        try {
            $evidence = ['stdout' => $this->artifacts->captureBytes($stdout), 'stderr' => $this->artifacts->captureBytes($stderr)];
            $this->store->recordEvent($this->sessionId, 'mcp_transport', ['server' => $server, 'tool' => $tool, 'evidence' => $evidence]);
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('MCP execution evidence could not be saved; automatic replay is blocked.');
        }

        return $evidence;
    }

    private function recordInternalEvidence(string $server, string $tool, mixed $result): void
    {
        try {
            $artifact = $this->artifacts->captureBytes(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->store->recordEvent($this->sessionId, 'mcp_internal_result', ['server' => $server, 'tool' => $tool, 'artifact' => $artifact]);
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('Internal MCP outcome evidence could not be saved; automatic replay is blocked.');
        }
    }
}
