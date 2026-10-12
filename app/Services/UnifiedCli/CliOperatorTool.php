<?php

namespace App\Services\UnifiedCli;

use Closure;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit operator requests use the shared registered dispatcher, never inference. */
final class CliOperatorTool
{
    private const ROUTE = ['instance_id' => 'operator', 'model' => 'none'];

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $workspace,
        private readonly string $stateDirectory,
        private readonly string $plosRoot,
        private readonly string $nodeExecutable,
        private readonly ?Closure $runtimeFactory = null,
    ) {}

    public function run(string $sessionId, string $command): array
    {
        $session = $this->store->session($sessionId);
        $turnId = $session['turns'][array_key_last($session['turns'] ?? [])]['id'] ?? '';
        $this->store->assertTurnNotCancelled($sessionId, $turnId);
        if ($command === '' || $command === 'help') {
            return ['message' => '/tool NAME {"argument":"value"} runs a skill-permitted tool. /tool override NAME JSON_OBJECT requests one exact operator-approved tool outside that skill in operator mode. Review /approvals, /approve ID, then /tool run ACTION_UUID. /tool inspect ACTION_UUID reads current admission without execution or requesting approval. Registry, guardrail, target and host sandbox checks still apply; no model runs.'];
        }
        if ($command === 'list') {
            return ['actions' => array_values(array_map(function ($entry) use ($sessionId) {
                $receipt = $this->store->actionReceipts($sessionId, $entry['turn_id'])[$entry['action']['id']] ?? null;

                return $entry + ['receipt' => $receipt];
            }, $session['operator_actions'] ?? [])), 'automatic_replay_allowed' => false];
        }
        if (preg_match('/^inspect ([a-f0-9-]{36})$/D', $command, $match)) {
            $action = $session['operator_actions'][$match[1]]['action'] ?? null;
            if (! is_array($action) || $this->store->operatorActionOrigin($sessionId, $action) === null) {
                throw new RuntimeException('Inspect requires an existing exact operator action in this session. Use /tool list.');
            }
            $runtime = $this->runtimeFactory === null ? $this->runtime($sessionId) : ($this->runtimeFactory)($sessionId);

            return $runtime->admissionTrace($action, self::ROUTE);
        }
        if (preg_match('/^run ([a-f0-9-]{36})$/D', $command, $match)) {
            $action = $session['operator_actions'][$match[1]]['action'] ?? null;
            if (! is_array($action)) {
                throw new RuntimeException('Operator action does not belong to this session. Use /tool list.');
            }
        } else {
            $override = str_starts_with($command, 'override ');
            $pattern = $override
                ? '/^override ([a-z][a-z0-9_]{0,127}) (.+)$/sD'
                : '/^([a-z][a-z0-9_]{0,127}) (.+)$/sD';
            if (strlen($command) > 32768 || ! preg_match($pattern, $command, $match)
                || in_array($match[1], ['run', 'list', 'help', 'override'], true)) {
                throw new RuntimeException('Use /tool NAME JSON_OBJECT, /tool override NAME JSON_OBJECT, /tool run ACTION_UUID or /tool list.');
            }
            try {
                $object = json_decode($match[2], flags: JSON_THROW_ON_ERROR);
                $arguments = json_decode($match[2], true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Tool arguments must be valid JSON. Use a JSON object with double-quoted property names.');
            }
            if (! $object instanceof \stdClass) {
                throw new RuntimeException('Tool arguments must be a JSON object, not an array or scalar.');
            }
            $action = ['id' => (string) Str::uuid(), 'name' => $match[1], 'arguments' => $arguments];
            if ($override) {
                $action['operator_override'] = true;
            }
            $session = $this->store->registerOperatorAction($sessionId, $action);
            if ($override) {
                $this->store->recordEvent($sessionId, 'operator_override', [
                    'action_id' => $action['id'], 'tool' => $action['name'], 'state' => 'requested',
                ]);
            }
        }
        $origin = $this->store->operatorActionOrigin($sessionId, $action);
        if ($origin === null) {
            throw new RuntimeException('The original operator action is cancelled or no longer matches its command.');
        }
        $receipt = $this->store->actionReceipts($sessionId, $origin)[$action['id']] ?? null;
        if ($receipt !== null) {
            if ($receipt['state'] === 'completed') {
                return ['status' => 'recorded', 'action' => $action, 'receipt' => $receipt, 'executed_now' => false];
            }
            throw new CliToolOutcomeUnknown('This operator action already started or was cancelled. Inspect its original job handle; no action was replayed.');
        }
        $runtime = $this->runtimeFactory === null ? $this->runtime($sessionId) : ($this->runtimeFactory)($sessionId);
        $decision = $runtime->authorize('tool', self::ROUTE, $action);
        if (($decision['allowed'] ?? false) !== true) {
            $approval = isset($decision['approval_id']) ? $this->store->approval($sessionId, $decision['approval_id']) : null;
            $canApprove = $approval !== null && $approval['state'] === 'pending' && ! $approval['expired'];

            return ['status' => $canApprove ? 'approval_required' : 'denied', 'action' => $action,
                'decision' => $decision, 'executed_now' => false,
                'next' => $canApprove ? '/approve '.$decision['approval_id'].' then /tool run '.$action['id'] : null];
        }
        $this->store->assertTurnNotCancelled($sessionId, $turnId);
        $this->store->startAction($sessionId, $origin, $action, self::ROUTE, $origin === $turnId ? null : $turnId);
        // Any exception leaves a started/unknown receipt. Neither reconnect nor
        // a second run command replays it. API status is a separate read action.
        $result = $runtime->execute($action, self::ROUTE);
        $this->store->completeAction($sessionId, $origin, $action['id'], $result);

        return ['status' => 'completed', 'action' => $action, 'result' => $result, 'executed_now' => true];
    }

    private function runtime(string $sessionId): CliToolRuntime
    {
        $artifacts = new CliArtifactStore($this->stateDirectory.'/artifacts');
        $framework = new CliFrameworkServices($this->store, $sessionId, $this->plosRoot, $this->nodeExecutable, $artifacts);
        $bindingPath = $this->stateDirectory.'/bindings.json';
        $bindings = is_file($bindingPath) ? CliLocalBindings::fromFile($bindingPath) : new CliLocalBindings;
        $localServices = new CliLocalMcpServices($this->store, $sessionId, $this->workspace,
            $artifacts, null, $this->nodeExecutable);
        $mcp = new CliMcpToolRuntime($this->store, $sessionId, $this->plosRoot, $this->nodeExecutable,
            $artifacts, $bindings->resolveMcp(...), $localServices->allows(...),
            internalToolDispatcher: $localServices->dispatch(...));

        return new CliToolRuntime($this->store, $sessionId,
            function ($session): array {
                $turnId = $session['turns'][array_key_last($session['turns'] ?? [])]['id'] ?? '';
                $this->store->assertTurnNotCancelled($session['id'], $turnId);

                return ['allowed' => true]; // No inference or model-host lease exists for an operator command.
            },
            function ($session, $route, $definition, $name) use ($framework): array {
                $context = $framework->context($name, $definition);
                if ($context === null) {
                    if (! is_string($definition['mcp_server'] ?? null) || $definition['mcp_server'] === ''
                        || ! is_string($definition['mcp_tool'] ?? null) || $definition['mcp_tool'] === '') {
                        throw new RuntimeException('Explicit /tool commands require an enabled framework service binding or external MCP binding.');
                    }
                    $configuration = new CliConfiguration($this->store);
                    $servers = (new CliMcpServerDefinitions)->forSession($configuration, $session);
                    $server = $servers[$definition['mcp_server']] ?? null;
                    $lemmaPod = ($definition['mcp_server'] ?? null) === 'lemma-pod'
                        && ($server['type'] ?? null) === 'internal'
                        && ($server['service'] ?? null) === CliLemmaPodMcpService::class;
                    $codexSkills = ($definition['mcp_server'] ?? null) === 'codex-skills'
                        && ($server['type'] ?? null) === 'internal'
                        && ($server['service'] ?? null) === CliCodexSkillReader::class;
                    if ($server === null || ! $server['enabled'] || (($server['type'] ?? null) !== 'external' && ! $lemmaPod && ! $codexSkills)) {
                        throw new RuntimeException('Explicit /tool MCP commands require an enabled MCP binding.');
                    }

                    return ['workspace' => $this->workspace, '_cli_actor' => 'operator'];
                }

                return array_replace($context, ['workspace' => $this->workspace, '_cli_actor' => 'operator']);
            }, workspaceRoot: $this->workspace,
            mcpCaller: fn ($server, $tool, $arguments, $context, $definition) => $mcp->call($server, $tool, $arguments, $context, $definition),
            serviceCaller: fn ($name, $arguments, $context, $definition) => $framework->call($name, $arguments, $context, $definition));
    }
}
