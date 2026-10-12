<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentSkillReferenceService;
use App\Services\NativeCodexToolService;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Reuse local PHP or existing SSH, with table-owned targets and no new API. */
final class CliFrameworkServices
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $plosRoot,
        private readonly string $nodeExecutable,
        private readonly CliArtifactStore $artifacts,
        private readonly ?Closure $processFactory = null,
    ) {}

    public function context(string $tool, array $definition): ?array
    {
        if (! empty($definition['mcp_server']) || ! empty($definition['mcp_tool'])
            || in_array($definition['service'] ?? null, [NativeCodexToolService::class, AgentSkillReferenceService::class], true)) {
            return null;
        }
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($this->sessionId);
        $values = $configuration->effective($session)['values'];
        $policy = $values['cli.framework_services'] ?? null;
        if ($policy === null) {
            return null;
        }
        CliFrameworkServicePolicy::validate($policy);
        $rule = $policy['tools'][$tool] ?? null;
        if ($rule === null) {
            return null;
        }
        $selection = $configuration->operationSelection($session, workflow: true);
        $target = $rule['target'] === 'local' ? 'local' : $selection['target'];
        $binding = $policy['targets'][$target] ?? throw new RuntimeException('Selected framework service target is unconfigured. Inspect /target; no alternate host was tried.');
        if ($rule['tree_scoped'] && $selection['tree_id'] === null) {
            throw new RuntimeException('Select /tree before this framework service.');
        }
        $rows = $configuration->definitions($session, 'agent_tool_registry');
        $current = (new CliToolCatalog($configuration))->fromRows($rows)[$tool] ?? null;
        if ($current === null && $rows !== []) {
            throw new RuntimeException('The selected framework tool is no longer available in the current CLI configuration.');
        }
        $current ??= $definition;

        return ['operation_target' => $target, 'tree_id' => $selection['tree_id'],
            'execution_host' => $binding['host'] ?? gethostname(),
            'framework_tool_sha256' => CliFrameworkServicePolicy::fingerprint($current),
            'framework_service_sha256' => hash('sha256', json_encode([$target, $binding, $rule,
                $values['cli.network'] ?? 'offline', $values['cli.host_addresses'] ?? [],
                $values['cli.workflow_model_role'] ?? 'auto', $values['cli.workflow_model'] ?? 'auto',
                $values['cli.workflow_vision_model'] ?? 'auto',
                $values['cli.workflow_embedding_model'] ?? 'auto',
                $values['cli.compute_instance'] ?? 'auto',
                $values['routing.internet_access'] ?? 'enabled', $values['routing.offline_mode'] ?? 'disabled',
                ...(($rule['durable_receipts'] ?? false) === true ? [$policy['worker_receipts'] ?? null] : [])], JSON_THROW_ON_ERROR))];
    }

    public function call(string $tool, array $arguments, array $context, array $definition, ?Closure $keeper = null, string $remoteOperation = 'execute'): ?array
    {
        if (! in_array($remoteOperation, ['execute', 'status', 'cancel'], true)) {
            throw new RuntimeException('Unsupported bounded framework control operation.');
        }
        $expected = $this->context($tool, $definition);
        if ($expected === null) {
            return null;
        }
        foreach ($expected as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                throw new RuntimeException('Framework service differs from its prepared execution target.');
            }
        }
        if (CliFrameworkServicePolicy::fingerprint($definition) !== $expected['framework_tool_sha256']) {
            throw new RuntimeException('Framework tool differs from its prepared definition.');
        }
        $session = $this->store->session($this->sessionId);
        $configuration = new CliConfiguration($this->store);
        $values = $configuration->effective($session)['values'];
        $policy = $values['cli.framework_services'];
        $binding = $policy['targets'][$expected['operation_target']];
        $root = $binding['transport'] === 'local' ? $this->plosRoot : $binding['root'];
        $skill = $configuration->selectedSkillName($session);
        if (! is_string($skill) || $skill === '') {
            throw new RuntimeException('Select a skill before executing a framework service.');
        }
        $action = ['id' => $context['_cli_action_id'] ?? null, 'name' => $tool, 'arguments' => $arguments];
        $override = ($context['_cli_operator_override'] ?? false) === true;
        if ($override) {
            $action['operator_override'] = true;
        }
        $approved = isset($context['_cli_action_id'], $context['_cli_action_turn'], $context['_cli_approval_scope'])
            && $this->store->hasApproval($this->sessionId, $context['_cli_action_turn'], $action, $context['_cli_approval_scope']);
        if ($override && (! $approved || $this->store->operatorActionOrigin($this->sessionId, $action) !== $context['_cli_action_turn'])) {
            throw new RuntimeException('Framework override requires the exact approved operator command.');
        }
        $request = ['schema' => 'plos.cli.framework-request.v1', 'tool' => $tool, 'arguments' => $arguments,
            'skill' => $skill, 'definition_sha256' => CliFrameworkServicePolicy::fingerprint($definition),
            'rule_sha256' => hash('sha256', json_encode($policy['tools'][$tool], JSON_THROW_ON_ERROR)),
            'root' => $root, 'production' => $binding['production'] ?? null, 'target' => $expected['operation_target'],
            'tree_id' => $expected['tree_id'], 'approved' => $approved,
            'model_role' => $values['cli.workflow_model_role'] ?? 'auto',
            'model_selection' => $values['cli.workflow_model'] ?? 'auto',
            'vision_model_selection' => $values['cli.workflow_vision_model'] ?? 'auto',
            'embedding_model_selection' => $values['cli.workflow_embedding_model'] ?? 'auto',
            'compute_selection' => $values['cli.compute_instance'] ?? 'auto',
            'internet_access' => $values['routing.internet_access'] ?? 'enabled',
            'offline_mode' => $values['routing.offline_mode'] ?? 'disabled',
            'network' => ['mode' => $values['cli.network'] ?? 'online', 'host_addresses' => $values['cli.host_addresses'] ?? []]];
        if ($override) {
            $request['operator_override'] = true;
        }
        if (isset($context['_cli_action_id'], $context['_cli_action_turn'], $context['_cli_approval_scope'])) {
            // Derived from parent-owned state/approval context, never service/model arguments.
            $request['caller_binding'] = ['session_id' => $this->sessionId, 'turn_id' => $context['_cli_action_turn'],
                'action_id' => $context['_cli_action_id'],
                'arguments_sha256' => hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR)),
                'approval_scope_sha256' => hash('sha256', json_encode($context['_cli_approval_scope'], JSON_THROW_ON_ERROR))];
        }
        if (($policy['tools'][$tool]['durable_receipts'] ?? false) === true) {
            if (($policy['tools'][$tool]['supervised_process'] ?? false) === true) {
                $request['supervised_process'] = true;
            }
            $request['remote_operation'] = $remoteOperation;
            $request['worker_journal_sha256'] = hash('sha256', json_encode($policy['worker_receipts']
                ?? throw new RuntimeException('Durable framework target requires its reviewed worker journal binding.'), JSON_THROW_ON_ERROR));
        }
        if ($remoteOperation !== 'execute' && ! isset($request['remote_operation'])) {
            throw new RuntimeException('Framework control requires a durable execution rule.');
        }
        $catalog = new CliToolCatalog($configuration);
        $baselineRows = $this->store->snapshot($session['generation'])['snapshot']['tables']['agent_tool_registry'] ?? [];
        $baseline = $catalog->fromRows($baselineRows)[$tool] ?? null;
        if ($baseline !== null) {
            $effective = $catalog->definitions($session)[$tool] ?? null;
            if ($effective === null || CliFrameworkServicePolicy::fingerprint($effective) !== CliFrameworkServicePolicy::fingerprint($definition)) {
                throw new RuntimeException('Framework tool changed after preparation. Review the current CLI definition.');
            }
            if ($baseline['parameters'] !== $effective['parameters']) {
                // Only the operator-owned configuration overlay supplies this
                // envelope. Model arguments and approval flags cannot supply it.
                $request['parameter_override'] = ['base_sha256' => CliFrameworkServicePolicy::fingerprint($baseline),
                    'parameters' => $effective['parameters']];
            }
        }

        return $this->dispatch($request, $binding, $values, $keeper, function () use ($tool, $definition, $expected, $request, $binding): void {
            if ($this->context($tool, $definition) !== $expected) {
                throw new RuntimeException('Framework target changed before dispatch.');
            }
            if (($request['remote_operation'] ?? null) === 'execute' && $request['approved'] === true && isset($request['caller_binding'])) {
                // Retain the original private envelope before any remote dispatch.
                // This is readback authority only, never a new execution grant.
                $this->store->recordEvent($this->sessionId, 'framework_remote_dispatch', ['request' => $request, 'binding' => $binding]);
            }
        });
    }

    /** Reuse one persisted action over the same authenticated target; no new approval or execution. */
    public function control(string $turnId, string $actionId, string $operation): array
    {
        if (! in_array($operation, ['status', 'cancel'], true)) {
            throw new RuntimeException('Only original-action status or cancellation is permitted.');
        }
        $original = null;
        foreach ($this->store->events($this->sessionId) as $event) {
            $saved = $event['document']['event'] ?? [];
            $caller = $saved['request']['caller_binding'] ?? [];
            if ($event['type'] !== 'framework_remote_dispatch' || ($caller['session_id'] ?? null) !== $this->sessionId
                || ($caller['turn_id'] ?? null) !== $turnId || ($caller['action_id'] ?? null) !== $actionId) {
                continue;
            }
            if ($original !== null && $original !== $saved) {
                throw new RuntimeException('Original action has conflicting retained envelopes; reconcile without replay.');
            }
            $original = $saved;
        }
        if ($original === null) {
            throw new RuntimeException('No original dispatched action was retained in this private session.');
        }
        $request = $original['request'];
        $receipt = $this->store->actionReceipts($this->sessionId, $turnId)[$actionId] ?? null;
        if ($receipt === null || ($receipt['document']['action_executed'] ?? null) === false
            || ($receipt['document']['action']['name'] ?? null) !== $request['tool']
            || ($receipt['document']['action']['arguments'] ?? null) !== $request['arguments']) {
            throw new RuntimeException('Original local action receipt is absent or differs; no control was sent.');
        }
        $session = $this->store->session($this->sessionId);
        $values = (new CliConfiguration($this->store))->effective($session)['values'];
        $validate = function () use ($original, $request): void {
            $current = $this->store->session($this->sessionId);
            $values = (new CliConfiguration($this->store))->effective($current)['values'];
            $policy = $values['cli.framework_services'] ?? [];
            CliFrameworkServicePolicy::validate($policy);
            if (($policy['targets'][$request['target']] ?? null) !== $original['binding']
                || ($request['worker_journal_sha256'] ?? null) !== hash('sha256', json_encode($policy['worker_receipts'] ?? null, JSON_THROW_ON_ERROR))
                || $request['network'] !== ['mode' => $values['cli.network'] ?? 'online', 'host_addresses' => $values['cli.host_addresses'] ?? []]) {
                throw new RuntimeException('Original host, journal or network binding changed; no alternate target was contacted.');
            }
        };
        $validate();
        $request['remote_operation'] = $operation;
        $request['approved'] = false;
        $request['control_existing'] = true;

        $readback = $this->dispatch($request, $original['binding'], $values, null, $validate);
        $workerId = $values['cli.framework_services']['worker_receipts']['worker_id'];
        if (($readback['process_tree_termination_verified'] ?? false) === true
            && (($request['supervised_process'] ?? false) !== true
                || ! isset($values['cli.framework_services']['worker_receipts']['supervisor']))) {
            throw new RuntimeException('This original worker binding did not admit a native supervisor.');
        }
        self::validateReadback($readback, $request, $workerId);
        $this->store->recordEvent($this->sessionId, 'framework_remote_readback', ['turn_id' => $turnId,
            'action_id' => $actionId, 'operation' => $operation, 'receipt' => $readback]);

        return $readback;
    }

    /** Validate a reply only after the normal authenticated/pinned transport returned it. */
    public static function validateReadback(array $readback, array $request, string $workerId): void
    {
        $caller = $request['caller_binding'];
        $id = hash('sha256', json_encode([$caller['session_id'], $caller['turn_id'], $caller['action_id']], JSON_THROW_ON_ERROR));
        $hash = hash('sha256', json_encode(array_diff_key($request, ['remote_operation' => true, 'approved' => true, 'control_existing' => true]), JSON_THROW_ON_ERROR));
        if (($readback['schema'] ?? null) !== 'plos.cli.framework-remote-receipt.v1' || ($readback['success'] ?? null) !== true
            || ($readback['worker_id'] ?? null) !== $workerId || ($readback['action_id'] ?? null) !== $id
            || ($readback['request_sha256'] ?? null) !== $hash || ($readback['automatic_replay_allowed'] ?? null) !== false
            || ! is_bool($readback['cancel_requested'] ?? null) || ! is_bool($readback['process_tree_termination_verified'] ?? null)
            || ! in_array($readback['state'] ?? null, ['not_found', 'cancelled_before_dispatch', 'result_recorded', 'running_or_outcome_unknown'], true)) {
            throw new RuntimeException('Remote control reply differs from the original owned action.');
        }
        if ($readback['state'] === 'result_recorded') {
            if (! is_string($readback['result_json'] ?? null) || ! is_string($readback['result_sha256'] ?? null)
                || ! hash_equals($readback['result_sha256'], hash('sha256', $readback['result_json']))) {
                throw new RuntimeException('Remote result bytes do not match their durable receipt.');
            }
            $result = json_decode($readback['result_json'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($result) || ! is_bool($result['success'] ?? null) || ($result['outcome_unknown'] ?? false) === true) {
                throw new RuntimeException('Remote result is not a complete typed outcome.');
            }
        } elseif (($readback['result_json'] ?? null) !== null || ($readback['result_sha256'] ?? null) !== null) {
            throw new RuntimeException('Nonterminal remote state cannot supply a completed result.');
        }
        if ($readback['process_tree_termination_verified']) {
            $terminal = CliWindowsActionProcess::validateTerminal($readback['native_terminal'] ?? null,
                ['action_id' => $id, 'request_sha256' => $hash]);
            if (($readback['native_terminal_sha256'] ?? null) !== hash('sha256', json_encode($terminal, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Native terminal fingerprint differs from readback.');
            }
        } elseif (isset($readback['native_terminal']) || isset($readback['native_terminal_sha256'])) {
            throw new RuntimeException('Unverified process tree cannot supply terminal evidence.');
        }
    }

    private function dispatch(array $request, array $binding, array $values, ?Closure $keeper, Closure $beforeLaunch): array
    {
        $root = $request['root'];
        $tool = $request['tool'];
        $expected = ['operation_target' => $request['target']];
        $script = rtrim($root, '/').'/scripts/plos-cli/framework-service.php';
        if ($binding['transport'] === 'local') {
            if (realpath($root) !== $this->plosRoot || ! is_file($script)) {
                throw new RuntimeException('The local framework worker is unavailable in this PLOS installation.');
            }
            $command = PHP_BINARY;
            $args = ['-d', 'display_errors=stderr', $script];
        } else {
            $host = str_contains($binding['host'], '@') ? explode('@', $binding['host'], 2)[1] : $binding['host'];
            $address = $values['cli.host_addresses'][strtolower($host)] ?? $host;
            (new CliNetworkBoundary)->requestOptions('http://'.$address.':22', $values['cli.network'] ?? 'offline');
            $remote = 'php -d display_errors=stderr '.escapeshellarg($script);
            if (($binding['platform'] ?? null) === 'windows') {
                // This is an invocation envelope, not operator/model command text.
                // Payload remains stdin. Explicit executable and script paths are
                // encoded for PowerShell instead of passing POSIX quotes to cmd.exe.
                $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";
                $windowsScript = str_replace('/', '\\', rtrim($root, '/\\')).'\\scripts\\plos-cli\\framework-service.php';
                $preflight = '';
                $ini = '';
                if (isset($binding['php_ini'])) {
                    // The exact private file set is part of the existing target
                    // fingerprint. Verify remote bytes before PHP or app code starts.
                    $pinJson = json_encode($binding['runtime_files'], JSON_THROW_ON_ERROR);
                    if (strlen($pinJson) > 32768 || ! function_exists('gzencode')) {
                        throw new RuntimeException('Windows runtime pins exceed the bounded launch format or local zlib is unavailable.');
                    }
                    $compressed = gzencode($pinJson, 9);
                    if (! is_string($compressed)) {
                        throw new RuntimeException('Windows runtime pin compression failed before dispatch.');
                    }
                    $pins = base64_encode($compressed);
                    $failure = json_encode(['schema' => 'plos.cli.framework-result.v1',
                        'result' => ['success' => false, 'status' => 'review_required',
                            'error' => 'Windows PHP runtime changed or is unavailable; re-vet before use.',
                            'runtime_started' => false]], JSON_THROW_ON_ERROR);
                    $preflight = 'try { $m=[IO.MemoryStream]::new([Convert]::FromBase64String('.$quote($pins).')); '
                        .'$z=[IO.Compression.GZipStream]::new($m,[IO.Compression.CompressionMode]::Decompress); '
                        .'try { $b=[byte[]]::new(32769); $n=0; while($n -lt 32769) { $r=$z.Read($b,$n,32769-$n); if($r -eq 0){break}; $n+=$r }; '
                        .'if($n -gt 32768){throw "pins"}; '
                        .'$pins=([Text.UTF8Encoding]::new(0,1)).GetString($b,0,$n)|ConvertFrom-Json } finally { $z.Dispose(); $m.Dispose() }; '
                        .'if($pins -isnot [PSCustomObject] -or @($pins.PSObject.Properties).Count -ne '.count($binding['runtime_files']).'){throw "pins"}; '
                        .'foreach($pin in $pins.PSObject.Properties) { '
                        .'if($pin.Name -notmatch "^[A-Za-z]:[\\\\/]" -or $pin.Value -isnot [string] -or $pin.Value -cnotmatch "^[a-f0-9]{64}$"){throw "pins"}; '
                        .'if((Get-FileHash -LiteralPath $pin.Name -Algorithm SHA256).Hash.ToLowerInvariant() -cne $pin.Value) { throw "pins" } } } '
                        .'catch { [Console]::Out.Write('.$quote($failure).'); exit 0 }; '
                        .'$env:PHP_INI_SCAN_DIR='.$quote($binding['php_ini']).'; ';
                    $ini = ' -c '.$quote($binding['php_ini']);
                }
                $program = '$ErrorActionPreference="Stop"; '.$preflight.'& '.$quote($binding['php'])
                    .$ini.' -d display_errors=stderr '.$quote($windowsScript).'; exit $LASTEXITCODE';
                $remote = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand '.base64_encode(mb_convert_encoding($program, 'UTF-16LE', 'UTF-8'));
                // Windows OpenSSH commonly invokes cmd.exe (8191 characters).
                // Keep headroom and refuse before SSH instead of losing an action.
                if (strlen($remote) > 7500) {
                    throw new RuntimeException('Selected Windows runtime exceeds the bounded command length ('.strlen($remote).' > 7500); no remote action started.');
                }
            }
            $command = '/usr/bin/ssh';
            $args = ['-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5',
                '-o', 'Hostname='.$address, '-o', 'Port=22', '-o', 'ProxyCommand=none', '-o', 'ProxyJump=none',
                '-o', 'CanonicalizeHostname=no', '-o', 'ControlMaster=no', '-o', 'ControlPath=none',
                '--', $binding['host'], $remote];
        }
        // Reviewed per-tool policy owns long service work; action arguments cannot extend it.
        $timeout = $values['cli.framework_services']['tools'][$tool]['timeout_ms'] ?? $values['cli.mcp_timeout_ms'] ?? 30000;
        if (! is_int($timeout) || $timeout < 50 || $timeout > 3600000) {
            throw new RuntimeException('Framework service timeout must be a valid millisecond value.');
        }
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];
        // SSH uses the operator's existing keys/agent. No credential is copied
        // into the payload, registry, command arguments or result evidence.
        foreach (['HOME', 'SSH_AUTH_SOCK', 'PHP_INI_SCAN_DIR'] as $name) {
            if (is_string(getenv($name))) {
                $env[$name] = getenv($name);
            }
        }
        // Loopback forwards belong to this machine only, never a remote worker.
        if (($binding['transport'] ?? null) === 'local' && is_string(getenv('PLOS_CLI_REMOTE_FORWARDS'))) {
            $env['PLOS_CLI_REMOTE_FORWARDS'] = getenv('PLOS_CLI_REMOTE_FORWARDS');
        }
        $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);
        $environment = array_replace($environment, $env);
        $launch = ['command' => $command, 'args' => $args, 'cwd' => $this->plosRoot, 'env' => $env,
            'input' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'timeout_ms' => $timeout];
        $process = $this->processFactory === null
            ? new Process([$this->nodeExecutable, $this->plosRoot.'/scripts/plos-cli/internal-operation.mjs'],
                $this->plosRoot, $environment, timeout: ($timeout / 1000) + 5)
            : ($this->processFactory)($launch);
        // Refuse before process launch when current custody or selection changed.
        $keeper?->__invoke();
        $beforeLaunch();
        try {
            // The managed-input protocol treats parent EOF as cancellation.
            // Keep its control pipe open even when no model lease is owned.
            (new CliMcpWorkerProcess)->run($process, json_encode($launch, JSON_THROW_ON_ERROR), $keeper ?? static function (): void {});
            $keeper?->__invoke();
            $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $stdout = base64_decode($receipt['stdout_base64'] ?? '', true);
            $result = is_string($stdout) ? json_decode($stdout, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! $process->isSuccessful() || ($receipt['schema'] ?? null) !== 'plos.cli.internal-operation.v1'
                || ($receipt['completed'] ?? false) !== true || ($result['schema'] ?? null) !== 'plos.cli.framework-result.v1'
                || ! is_array($result['result'] ?? null)) {
                throw new RuntimeException('Unverified framework result.');
            }

            $outcome = $result['result'];
            foreach (['compute' => ['stdin', 'stdout', 'stderr'], 'codex' => ['stdin', 'stdout', 'stderr', 'last_message']] as $kind => $streams) {
                $executionKey = $kind.'_executions';
                $artifactKey = $kind.'_artifacts';
                if (! array_key_exists($executionKey, $outcome)) {
                    continue;
                }
                if (! is_array($outcome[$executionKey]) || ! array_is_list($outcome[$executionKey])) {
                    throw new RuntimeException('Invalid process execution evidence.');
                }
                $outcome[$artifactKey] = [];
                foreach ($outcome[$executionKey] as $execution) {
                    if (! is_array($execution) || ! is_string($execution['instance_id'] ?? null)
                        || ! is_bool($execution['success'] ?? null)) {
                        throw new RuntimeException('Invalid process execution evidence.');
                    }
                    $evidence = ['tool' => $tool, 'target' => $expected['operation_target'],
                        'instance_id' => $execution['instance_id'], 'success' => $execution['success'],
                        'tool_success' => ($outcome['success'] ?? false) === true,
                        'exit_code' => $execution['exit_code'] ?? null, 'error' => $execution['error'] ?? null];
                    if ($kind === 'codex') {
                        $evidence['timed_out'] = ($execution['timed_out'] ?? false) === true;
                        $evidence['last_message_available'] = ($execution['last_message_available'] ?? false) === true;
                    }
                    foreach ($streams as $stream) {
                        $encoded = $execution[$stream.'_base64'] ?? null;
                        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                        if ($bytes === false) {
                            throw new RuntimeException('Invalid process output encoding.');
                        }
                        $evidence[$stream] = $this->artifacts->captureBytes($bytes);
                    }
                    $this->store->recordEvent($this->sessionId, 'framework_'.$kind.'_io', $evidence);
                    $outcome[$artifactKey][] = $evidence;
                }
                unset($outcome[$executionKey]);
            }

            if (($outcome['outcome_unknown'] ?? false) === true) {
                throw new CliToolOutcomeUnknown('Framework execution outcome is unknown; available process output has been retained.');
            }

            return $outcome;
        } catch (Throwable) {
            throw new CliToolOutcomeUnknown('Selected PLOS service did not complete with a verified result. Inspect its installation, target and retained output before retrying; no alternate target or replay was attempted.');
        } finally {
            $this->store->recordEvent($this->sessionId, 'framework_service_io', ['tool' => $tool, 'target' => $expected['operation_target'], 'action_id' => $request['caller_binding']['action_id'] ?? null,
                'stdout' => $this->artifacts->captureBytes($process->isStarted() ? $process->getOutput() : ''),
                'stderr' => $this->artifacts->captureBytes($process->isStarted() ? $process->getErrorOutput() : '')]);
        }
    }
}
