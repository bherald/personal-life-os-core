<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentDSGovernanceService;
use App\Services\AgentGuardrailService;
use App\Services\AgentToolRegistryService;
use App\Services\MCPToolResultValidatorService;
use App\Services\OfflinePolicyService;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

/** Session-owned adapter around the existing PLOS dispatcher and policy rules. */
final class CliToolRuntime
{
    private CliConfiguration $configuration;

    private CliToolCatalog $catalog;

    private AgentToolRegistryService $registry;

    private bool $tracingAdmission = false;

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly Closure $currentAdmission,
        private readonly Closure $runtimeContext,
        ?Closure $confirmationVerifier = null,
        ?Closure $mcpCaller = null,
        ?Closure $serviceFactory = null,
        private readonly ?string $workspaceRoot = null,
        ?Closure $serviceCaller = null,
        private readonly ?Closure $reviewAdmission = null,
    ) {
        $this->configuration = new CliConfiguration($store);
        $this->catalog = new CliToolCatalog($this->configuration);
        $settings = new CliSystemConfigService($store, $sessionId);
        $guardrails = new AgentGuardrailService(
            fn () => array_values(array_filter($this->configuration->definitions($this->session(), 'guardrail_rules'),
                fn ($rule) => in_array($rule['is_active'] ?? false, [true, 1, '1'], true))),
            function ($event, $operation, $context, $reason, $agent) use ($store, $sessionId) {
                // Complete tool inputs/results live in the action ledger. Audit
                // metadata must not copy arbitrary arguments or credentials.
                if ($this->tracingAdmission) {
                    return;
                }
                $store->recordEvent($sessionId, 'guardrail', ['event' => $event, 'operation' => $operation,
                    'reason' => $reason, 'agent' => $agent, 'policy_decision' => $context['policy_decision'] ?? null]);
            }, new OfflinePolicyService(systemConfig: $settings,
                mcpServers: fn () => (new CliMcpServerDefinitions)->forSession($this->configuration, $this->session()),
                workspaceRoot: $workspaceRoot ?? ''));
        $this->registry = new AgentToolRegistryService(
            fn () => $this->catalog->definitions($this->session()),
            fn ($event) => $this->tracingAdmission ? null : $store->recordEvent($sessionId, 'tool_call', $event),
            true, $confirmationVerifier ?? function ($name, $params, $context) use ($store, $sessionId): bool {
                if (! isset($context['_cli_action_id'], $context['_cli_action_turn'], $context['_cli_approval_scope'])) {
                    return false;
                }

                $action = ['id' => $context['_cli_action_id'], 'name' => $name, 'arguments' => $params];
                if (($context['_cli_operator_override'] ?? false) === true) {
                    $action['operator_override'] = true;
                }

                return $store->hasApproval($sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope']);
            }, $guardrails, new AgentDSGovernanceService($settings), $mcpCaller,
            $serviceFactory ?? fn () => throw new RuntimeException('An explicit session service binding is required.'), $serviceCaller);
    }

    public function tools(): array
    {
        $session = $this->session();
        if ($this->configuration->effective($session)['values']['cli.operator_mode'] === 'chat') {
            return [];
        }
        $skill = $this->selectedSkill($session);

        return $skill === null ? [] : $this->catalog->forSkill($session, $this->registry, $skill);
    }

    public function instructions(): array
    {
        $session = $this->session();
        $skill = $this->selectedSkill($session);
        // Explicit selection metadata is separate from the unchanged SKILL.md.
        // It makes clearing/changing a skill visible while retaining history.
        $agent = $this->configuration->agentForSession($session);
        $messages = [['role' => 'system', 'content' => 'PLOS current skill selection: '.($skill['name'] ?? 'none').'. Current agent selection: '.($agent['agent_id'] ?? 'none').'. Current tool permissions are enforced by PLOS.']];
        if ($agent !== null) {
            $messages[] = ['role' => 'system', 'content' => 'PLOS current agent definition: '.json_encode($agent,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        if ($skill !== null) {
            $messages[] = ['role' => 'system', 'content' => $skill['instructions']];
        }
        $values = $this->configuration->effective($session)['values'];
        $messages[0]['content'] .= ' Current operator mode: '.$values['cli.operator_mode'].'. Chat mode permits no tools; read-only mode permits registered read tools only. Other modes retain the selected skill permissions and required approvals.';
        if (array_key_exists('cli.target', $values) || array_key_exists('cli.tree_id', $values)) {
            $messages[] = ['role' => 'system', 'content' => 'PLOS current operation selection: '.json_encode(
                $this->configuration->operationSelection($session), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }

        return $messages;
    }

    public function execute(array $action, array $route): array
    {
        $context = $this->prepare($action, $route);
        $result = $this->registry->executeTool($action['name'], $action['arguments'], $context);
        $output = $result['result_text'] ?? json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($output) || ! is_bool($result['success'] ?? null)) {
            throw new RuntimeException('Shared dispatcher did not return a complete typed result.');
        }

        $outcome = $result['outcome'] ?? (new MCPToolResultValidatorService)->outcome($result['result'] ?? null);

        return ['success' => $result['success'], 'output' => $output,
            'warnings' => $outcome['warnings'],
            'confirmation_pending' => $result['confirmation_pending'] ?? false];
    }

    /** Shared policy check for an explicit operator action, without claiming a model turn. */
    public function inspectTool(array $action, array $route): array
    {
        return $this->registry->executeTool($action['name'], $action['arguments'], $this->prepare($action, $route), authorizeOnly: true);
    }

    /** Inspect existing exact scope; never request approval, execute or claim host readiness. */
    public function admissionTrace(array $action, array $route): array
    {
        $this->tracingAdmission = true;
        $trace = ['executed_now' => false, 'approval_requested' => false,
            'execution_gates' => 'unchecked', 'host_reviewer' => 'independent_unchecked'];
        try {
            $context = $this->prepare($action, $route, reviewOnly: true);
            $approved = isset($context['_cli_action_turn'], $context['_cli_approval_scope'])
                && $this->store->hasApproval($this->sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope']);
            if (($action['operator_override'] ?? false) === true && $approved) {
                $context['_cli_operator_override'] = true;
            }
            $result = $this->registry->executeTool($action['name'], $action['arguments'], $context, authorizeOnly: true);
            $confirmation = ($result['confirmation_pending'] ?? false) === true
                || (($action['operator_override'] ?? false) === true && ! $approved && ($result['authorized'] ?? false) === true);

            return $trace + ['status' => $confirmation ? 'approval_required' : (($result['authorized'] ?? false) === true ? 'policy_passed' : 'blocked'),
                'scope' => $context['_cli_approval_scope'] ?? null, 'exact_approval_valid' => $approved,
                'reason' => $result['result_text'] ?? $result['error'] ?? 'Current policy denied the action.'];
        } catch (RuntimeException $exception) {
            return $trace + ['status' => 'blocked', 'reason' => $exception->getMessage()];
        } finally {
            $this->tracingAdmission = false;
        }
    }

    /** Check/request approval before the loop claims any action for execution. */
    public function authorize(string $kind, array $route, array $action): array
    {
        if ($kind === 'model') {
            return ($this->currentAdmission)($this->session(), $route, ['kind' => 'model']);
        }
        if ($kind !== 'tool') {
            throw new RuntimeException('Unknown admission operation.');
        }
        $context = $this->prepare($action, $route, reviewOnly: true);
        $override = ($action['operator_override'] ?? false) === true;
        // The verifier must bind the same exact action during review and execution.
        // Derive this marker only from a persisted, current scoped approval.
        if ($override && isset($context['_cli_action_turn'], $context['_cli_approval_scope'])
            && $this->store->hasApproval($this->sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope'])) {
            $context['_cli_operator_override'] = true;
        }
        $result = $this->registry->executeTool($action['name'], $action['arguments'], $context, authorizeOnly: true);
        $overrideReady = $override && ($result['authorized'] ?? false) === true
            && isset($context['_cli_action_turn'], $context['_cli_approval_scope'])
            && ! $this->store->hasApproval($this->sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope']);
        if (($result['confirmation_pending'] ?? false) === true || $overrideReady) {
            if (! isset($context['_cli_action_turn'], $context['_cli_approval_scope'])) {
                throw new RuntimeException('Approval requires a persisted pending action.');
            }
            $approval = $this->store->requestApproval($this->sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope']);

            return ['allowed' => false, 'approval_id' => $approval['id'],
                'reason' => 'Operator approval '.$approval['state'].'. Review /approvals, then /approve '.$approval['id'].' or /deny '.$approval['id'].'.'];
        }

        return ['allowed' => ($result['authorized'] ?? false) === true, 'reason' => $result['result_text'] ?? 'Current policy denied the action.'];
    }

    private function prepare(array $action, array $route, bool $reviewOnly = false): array
    {
        $schema = null;
        $session = $this->session();
        $skill = $this->selectedSkill($session);
        $override = ($action['operator_override'] ?? false) === true;
        $pending = (new LosslessConversation($session['messages'] ?? []))->pendingToolCalls();
        if ($override && ($this->configuration->effective($session)['values']['cli.operator_mode'] !== 'operator'
            || $skill === null || isset($pending[$action['id'] ?? ''])
            || $this->store->operatorActionOrigin($this->sessionId, $action) === null)) {
            throw new RuntimeException('Tool override requires an exact operator command, an active skill and operator mode.');
        }
        if ($skill !== null) {
            $contract = $this->registry->resolveToolContractForAgent($skill['name'], $skill['configuration']);
            $definition = $contract['tools'][$action['name'] ?? ''] ?? null;
            if ($definition !== null) {
                CliOperatorModes::requireTool($this->configuration->effective($session)['values']['cli.operator_mode'], $definition);
                $schema = $this->catalog->schema($definition['parameters']);
            }
        }
        if ($override) {
            $definition = $this->catalog->definitions($session)[$action['name'] ?? ''] ?? null;
            $schema = $definition === null ? null : $this->catalog->schema($definition['parameters']);
        }
        if ($schema === null || ! is_array($action['arguments'] ?? null)) {
            throw new RuntimeException('Action is not available to the currently selected skill.');
        }
        $arguments = $action['arguments'];
        $object = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        if (! (new MCPToolResultValidatorService)->validate(['structuredContent' => $object], $schema, $object)['valid']) {
            throw new RuntimeException('Action arguments differ from the current tool schema.');
        }
        $context = ($this->runtimeContext)($session, $route, $definition, $action['name']);
        if (! is_array($context)) {
            throw new RuntimeException('Current operation target/context is unavailable.');
        }
        $this->configuration->requireOperationContext($session, $context);
        $agent = $this->configuration->agentForSession($session);
        if ($agent !== null) {
            if (isset($context['agent_id']) && $context['agent_id'] !== $agent['agent_id']) {
                throw new RuntimeException('Operation context differs from the selected agent identity.');
            }
            $context['agent_id'] = $agent['agent_id'];
        } elseif (! isset($context['agent_id'])) {
            // A selected skill is also the trusted default runtime identity
            // when the operator has not selected a separate agent definition.
            $context['agent_id'] = $this->selectedSkill($session)['name'];
        }
        if ($this->workspaceRoot !== null && isset($context['workspace'])
            && realpath($this->workspaceRoot) !== realpath($context['workspace'])) {
            throw new RuntimeException('Operation workspace differs from the bound CLI file policy.');
        }
        // No model-provided approval, simulation or authority switches. Target
        // fields selected by the operator must also match exactly if supplied.
        foreach ($arguments as $key => $value) {
            $canonical = Str::snake($key);
            $alias = $schema['properties'][$key]['x_context_alias'] ?? $canonical;
            $authority = ['confirmed', 'operator_confirmed', 'ds_confirmed', 'approval_ref', 'simulate', 'sidecar', 'ds_profile', 'session_id', 'agent_id', 'risk_level', 'mcp_server', 'mcp_tool'];
            if (str_starts_with($key, '_') || in_array($canonical, $authority, true) || in_array($alias, $authority, true)
                || (array_key_exists($key, $context) && $context[$key] !== $value)
                || (array_key_exists($canonical, $context) && $context[$canonical] !== $value)
                || (is_string($alias) && array_key_exists($alias, $context) && $context[$alias] !== $value)) {
                throw new RuntimeException('Model arguments cannot override runtime authority or the selected target.');
            }
        }
        // Review checks policy and scope; execution still obtains fresh runtime
        // admission. Callers without this explicit local review adapter retain
        // the previous full admission behavior in both phases.
        $admission = $reviewOnly && $this->reviewAdmission !== null ? $this->reviewAdmission : $this->currentAdmission;
        $decision = $admission($session, $route, $action);
        if (! is_array($decision) || ($decision['allowed'] ?? false) !== true) {
            throw new RuntimeException('Current tool execution admission was denied.');
        }
        $scope = ['configuration_sha256' => $this->hash($this->configuration->effective($session)),
            'context_sha256' => $this->hash($context), 'route_sha256' => $this->hash($route),
            'instance_id' => $route['instance_id'] ?? '', 'model' => $route['model'] ?? '',
            'target' => array_intersect_key($context, array_flip(['workspace', 'tree_id', 'operation_target', 'execution_host']))];
        $entry = $pending[$action['id'] ?? ''] ?? null;
        $origin = $entry === null ? $this->store->operatorActionOrigin($this->sessionId, $action)
            : ($session['message_turn_ids'][$entry['message_index']] ?? null);
        $context = array_replace($arguments, $context, ['session_id' => $this->sessionId, '_audit' => false]);
        if (($definition['mcp_server'] ?? null) === 'lemma-pod') {
            // Lemma pod paths name remote pod files, not host filesystem paths.
            unset($context['path']);
        }
        if ($origin !== null) {
            $context['_cli_action_id'] = $action['id'];
            $context['_cli_action_name'] = $action['name'];
            $context['_cli_action_turn'] = $origin;
            $context['_cli_approval_scope'] = $scope;
        }
        if ($override && ! $reviewOnly && ($origin === null
            || ! $this->store->hasApproval($this->sessionId, $origin, $action, $scope))) {
            throw new RuntimeException('Exact operator override approval is required before execution.');
        }
        if ($override && ! $reviewOnly) {
            // This marker is derived only after the persisted human command and
            // its exact approval have both been rechecked.
            $context['_cli_operator_override'] = true;
        }

        return $context;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function selectedSkill(array $session): ?array
    {
        // AgentHandoffService uses the stable agent ID as the default skill.
        // An explicit operator skill selection remains the local override.
        $name = $this->configuration->selectedSkillName($session);
        if ($name === null) {
            return null;
        }
        $available = array_filter($this->configuration->skills($session), fn ($skill) => $skill['name'] === $name && $skill['version_matches']);
        if (count($available) !== 1) {
            throw new RuntimeException('Current selected skill is inactive, ambiguous or unavailable. Selected agents require their matching skill or an explicit /skill override.');
        }

        return (new CliSkillReader($this->configuration))->read($session, $name);
    }

    private function session(): array
    {
        return $this->store->session($this->sessionId);
    }
}
