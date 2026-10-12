<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentSkillReferenceService;
use App\Services\NativeCodexToolService;
use App\Services\OfflinePolicyService;
use Closure;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** Compose one session's real model, policy, skill and tool adapters. */
final class CliAgentBackend
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $workspace,
        private readonly string $stateDirectory,
        private readonly string $plosRoot,
        private readonly string $nodeExecutable,
        private readonly CliLocalBindings $bindings,
        private readonly ClientInterface $client,
        private readonly Closure $runtimeAdmission,
        private readonly ?Closure $counterFactory,
        private readonly Closure $operationContext,
        private readonly ?Closure $serviceFactory = null,
        private readonly ?Closure $internalMcpAdmission = null,
        private readonly ?Closure $internalMcpFactory = null,
        private readonly ?Closure $leaseKeeperFactory = null,
        private readonly ?Closure $runtimePreparation = null,
        private readonly ?Closure $nativeChannel = null,
    ) {
        if ($workspace === '' || realpath($workspace) !== $workspace || ! is_dir($workspace) || $workspace === '/') {
            throw new RuntimeException('Primary-agent composition requires an explicit canonical workspace.');
        }
    }

    public function run(array $session, array $input): array
    {
        return $this->agent($session['id'])->run($session, $input);
    }

    public function resume(array $session): array
    {
        return $this->agent($session['id'], prepareRuntime: false)->resume($session);
    }

    public function compact(array $session, string $confirmation): array
    {
        return $this->agent($session['id'])->compact($session, $confirmation);
    }

    private function agent(string $sessionId, bool $prepareRuntime = true): CliPrimaryAgent
    {
        $configuration = new CliConfiguration($this->store);
        $resolver = new CliRouteResolver($configuration);
        $admit = function (array $session, array $candidate, array $action, ?array $reviewRoute = null) use ($configuration): array {
            $agent = $configuration->agentForSession($session);
            $toolsEnabled = ($candidate['native_tools_configured'] ?? false) === true;
            $supportsChat = ! CliAgentRuntime::isColibriEngine($candidate['engine'] ?? null)
                && (($candidate['instance']['instance_type'] ?? null) === 'ollama'
                    || CliProviderRuntimePolicy::supports($candidate['instance']))
                || CliAgentRuntime::isManualTextCandidate($candidate);
            if (! $toolsEnabled && (! $supportsChat || ($action['kind'] ?? null) === 'tool')) {
                return ['allowed' => false, 'reason' => 'Selected provider policy disables native tools for the primary agent.'];
            }
            if (! CliAgentRuntime::isColibriEngine($candidate['engine'] ?? null)
                && CliProviderRuntimePolicy::supports($candidate['instance'])) {
                $policy = (new OfflinePolicyService(systemConfig: new CliSystemConfigService($this->store, $session['id'])))
                    ->evaluateProvider($candidate['instance'], context: ['_audit' => false]);
                $this->store->recordEvent($session['id'], 'provider_policy', [
                    'instance_id' => $candidate['instance_id'], 'allowed' => $policy->allowed,
                    'profile' => $policy->profile, 'provider_class' => $policy->providerClass,
                ]);
                if (! $policy->allowed) {
                    return ['allowed' => false, 'reason' => $policy->reason];
                }
            }

            // A review can establish local policy eligibility, not current host
            // readiness. The execution path never supplies a review route.
            $decision = $reviewRoute === null
                ? ($this->runtimeAdmission)($session, $candidate, $action)
                : ['allowed' => true, 'route' => $reviewRoute, 'runtime_admission_checked' => false];
            if (($decision['allowed'] ?? false) === true && ! $toolsEnabled) {
                // Chat admission cannot grant tools disabled by the selected table row.
                $decision['route']['native_tools_enabled'] = false;
            }
            if (($decision['allowed'] ?? false) === true) {
                $operationContext = ($this->operationContext)($session, $decision['route']);
                if (! is_array($operationContext)) {
                    throw new RuntimeException('The current operation context is unavailable.');
                }
                $configuration->requireOperationContext($session, $operationContext);
            }
            if (($decision['allowed'] ?? false) === true && $agent !== null) {
                $decision['route']['agent_binding_sha256'] = hash('sha256', json_encode($agent,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return $decision;
        };
        $artifacts = new CliArtifactStore($this->stateDirectory.'/artifacts');
        $workflowServices = new CliWorkflowServices($this->store, $sessionId, $artifacts, $this->bindings, $this->client);
        $frameworkServices = new CliFrameworkServices($this->store, $sessionId, $this->plosRoot, $this->nodeExecutable, $artifacts);
        $localServices = new CliLocalMcpServices($this->store, $sessionId, $this->workspace, $artifacts,
            new CliDataBindings($this->store, $sessionId, $this->stateDirectory.'/data-bindings.json', $this->bindings),
            $this->nodeExecutable);
        $useLocalServices = $this->internalMcpAdmission === null && $this->internalMcpFactory === null;
        $internalWorker = new CliInternalMcpWorker($this->store, $sessionId, $this->workspace,
            $this->stateDirectory, $this->plosRoot, $this->nodeExecutable, $this->bindings, $artifacts);
        $mcp = new CliMcpToolRuntime($this->store, $sessionId, $this->plosRoot, $this->nodeExecutable,
            $artifacts, $this->bindings->resolveMcp(...),
            $useLocalServices ? $localServices->allows(...) : $this->internalMcpAdmission,
            $this->internalMcpFactory, $useLocalServices ? function ($server, $tool, $arguments, $definition, $context, $keeper) use ($localServices, $internalWorker): array {
                return $keeper === null ? $localServices->dispatch($server, $tool, $arguments, $definition, $context)
                    : $internalWorker->dispatch($server, $tool, $arguments, $definition, $context, $keeper);
            } : null, workflowServices: $workflowServices);
        $current = function (array $session, array $route, array $action, bool $reviewOnly = false) use ($resolver, $admit, $configuration): array {
            $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? [];
            if (($turn['cancel_requested'] ?? false) === true) {
                return ['allowed' => false, 'reason' => 'Operator cancelled this turn; no further action was started.'];
            }
            // Revalidate this exact route; never resolve another automatic
            // candidate after a turn has selected its model.
            foreach ($resolver->inspect($session)['candidates'] as $candidate) {
                if ($candidate['instance_id'] !== ($route['instance_id'] ?? null) || $candidate['model'] !== ($route['model'] ?? null)) {
                    continue;
                }
                if (! $candidate['configuration_eligible']) {
                    return ['allowed' => false, 'reason' => 'Selected route is no longer eligible in current session definitions.'];
                }
                if ($reviewOnly) {
                    $values = $configuration->effective($session)['values'];
                    if (($candidate['instance']['base_url'] ?? null) !== ($route['base_url'] ?? null)
                        || ($values['cli.network'] ?? null) !== ($route['network_mode'] ?? null)) {
                        return ['allowed' => false, 'reason' => 'Selected route binding changed before authorization review.'];
                    }
                }
                $decision = $admit($session, $candidate, $action, $reviewOnly ? $route : null);
                if (($decision['allowed'] ?? false) !== true || ($decision['route'] ?? null) !== $route) {
                    return ['allowed' => false, 'reason' => 'Current runtime admission denied or changed the selected route.'];
                }

                return ['allowed' => true];
            }

            return ['allowed' => false, 'reason' => 'Selected route is absent from current session selection.'];
        };
        $context = function ($session, $route, $definition, $name) use ($workflowServices, $frameworkServices): array {
            $context = $frameworkServices->context($name, $definition)
                ?? $workflowServices->context((string) ($definition['mcp_server'] ?? ''), (string) ($definition['mcp_tool'] ?? ''))
                ?? ($this->operationContext)($session, $route, $definition);
            if (! is_array($context) || (isset($context['workspace']) && $context['workspace'] !== $this->workspace)) {
                throw new RuntimeException('Operation context cannot change the terminal workspace.');
            }

            return array_replace($context, ['workspace' => $this->workspace]);
        };
        $toolKeeper = null;
        $tools = new CliToolRuntime($this->store, $sessionId, $current, $context,
            mcpCaller: function ($server, $tool, $arguments, $context, $definition) use ($mcp, &$toolKeeper): array {
                return $mcp->call($server, $tool, $arguments, $context, $definition, $toolKeeper);
            }, serviceFactory: $this->serviceFactory ?? function ($class, $context, $definition) use ($sessionId, &$toolKeeper) {
                if ($class === AgentSkillReferenceService::class && ($definition['method'] ?? null) === 'read') {
                    return new CliSkillReferenceService($this->store, $sessionId, $toolKeeper);
                }
                if ($class === NativeCodexToolService::class && $this->nativeChannel !== null) {
                    return new NativeCodexToolService((new CliNativeExecution($this->store, $sessionId, $this->nativeChannel, $this->workspace))->dispatch(...));
                }
                throw new RuntimeException('An explicit session service binding is required.');
            }, workspaceRoot: $this->workspace,
            serviceCaller: function ($name, $arguments, $context, $definition) use ($frameworkServices, &$toolKeeper) {
                return $frameworkServices->call($name, $arguments, $context, $definition, $toolKeeper);
            }, reviewAdmission: fn ($session, $route, $action) => $current($session, $route, $action, reviewOnly: true));
        $request = new CliModelRequest($this->store, $sessionId, $artifacts, $this->bindings, $this->client);
        $counter = $this->counterFactory === null
            ? fn ($route, $messages, $nativeTools): int => CliPromptCounter::fromBindingsFile(
                $this->store, $sessionId, $artifacts, $this->stateDirectory.'/tokenizer-bindings.json')->count($route, $messages, $nativeTools)
            : ($this->counterFactory)($sessionId, $artifacts);
        if (! $counter instanceof Closure) {
            throw new RuntimeException('A session-bound exact prompt counter is required.');
        }

        return new CliPrimaryAgent($this->store,
            fn ($session) => $resolver->resolve($session, function ($state, $candidate) use ($admit, $prepareRuntime): array {
                $prepared = null;
                if ($prepareRuntime && $this->runtimePreparation !== null) {
                    $prepared = ($this->runtimePreparation)($state, $candidate);
                    if (($prepared['allowed'] ?? false) !== true) {
                        return $prepared;
                    }
                    $state = $this->store->session($state['id']);
                }
                $decision = $admit($state, $candidate, ['kind' => 'select']);
                if (($prepared['selection_committed'] ?? false) === true) {
                    $decision['selection_committed'] = true;
                }

                return $decision;
            }),
            fn ($session, $route) => ($route['native_tools_enabled'] ?? true) ? $tools->tools() : [],
            function ($route, $messages, $nativeTools) use ($current, $sessionId, $request): array {
                // Counting can take time. Check again immediately before I/O.
                $decision = $current($this->store->session($sessionId), $route, ['kind' => 'model']);
                if (! $decision['allowed']) {
                    throw new RuntimeException($decision['reason']);
                }

                $managed = ($route['runtime_management'] ?? null) !== 'provider';
                $keeper = $this->leaseKeeperFactory === null || ! $managed ? null
                    : ($this->leaseKeeperFactory)($this->store->session($sessionId), $route);
                if ($managed && $this->leaseKeeperFactory !== null && ! $keeper instanceof Closure) {
                    throw new RuntimeException('The selected runtime lease keeper is unavailable.');
                }

                $result = $request->complete($route, $messages, $nativeTools, $keeper);
                $keeper?->__invoke();

                return $result;
            }, $counter, $tools->authorize(...),
            function (array $action, array $route) use ($tools, $sessionId, &$toolKeeper): array {
                $managed = ($route['runtime_management'] ?? null) !== 'provider';
                $toolKeeper = $this->leaseKeeperFactory === null || ! $managed ? null
                    : ($this->leaseKeeperFactory)($this->store->session($sessionId), $route);
                if ($managed && $this->leaseKeeperFactory !== null && ! $toolKeeper instanceof Closure) {
                    throw new RuntimeException('The selected runtime lease keeper is unavailable.');
                }
                try {
                    return $tools->execute($action, $route);
                } finally {
                    $toolKeeper = null;
                }
            }, function ($session, $route) use ($tools): array {
                $instructions = $tools->instructions();
                $instructions[0]['content'] .= ($route['native_tools_enabled'] ?? true)
                    ? ' Current model tools: enabled, subject to the selected skill and PLOS permissions.'
                    : ' Current model tools: disabled by the selected table configuration. Chat and supplied attachments remain available within declared capabilities. Do not claim to execute tools or OS commands.';

                return $instructions;
            },
            (new CliInputAdapter($artifacts, $this->workspace,
                (new CliNativeCatalog($configuration, $this->stateDirectory))->validateSelection(...)))->parts(...));
    }
}
