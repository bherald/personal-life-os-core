<?php

namespace App\Services\UnifiedCli;

use App\Services\NativeCodexToolService;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Explicit operator controls; the shared dispatcher remains the authority. */
final class CliNativeOperator
{
    private array $pending = [];

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $workspace,
        private readonly Closure $channel,
    ) {}

    public function run(string $sessionId, string $command, array $ownedIds = []): array
    {
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($sessionId);
        $scope = hash('sha256', json_encode($configuration->effective($session), JSON_THROW_ON_ERROR));
        if ($command === '' || $command === 'help') {
            return ['message' => '/process list | output ID [OFFSET] | send ID "JSON text" | input ID {"input_base64":"...","close_stdin":false} | eof ID | cancel ID | resize ID ROWS COLS | confirm TOKEN | deny TOKEN. Controls use current PLOS table rules. Input is sent exactly; send does not append a newline.'];
        }
        if ($command === 'list') {
            $results = [];
            foreach ($ownedIds as $id) {
                $results[] = $this->run($sessionId, 'output '.$id, $ownedIds);
            }

            return ['processes' => $results, 'message' => $results === [] ? 'No processes are owned by this connection and session.' : 'Processes owned by this connection and session; saved processes cannot be controlled.'];
        }
        $confirmed = false;
        if (preg_match('/^(confirm|deny) ([a-f0-9-]{36})$/D', $command, $match)) {
            $pending = $this->pending[$match[2]] ?? null;
            if ($pending === null || $pending['session'] !== $sessionId) {
                throw new RuntimeException('This session has no pending operator control with that token.');
            }
            unset($this->pending[$match[2]]);
            if ($match[1] === 'deny') {
                $this->store->recordEvent($sessionId, 'native_operator_denied', ['action' => $pending['action']]);

                return ['message' => 'Operator control declined; no input or cancellation was sent.'];
            }
            if ($pending['scope'] !== $scope) {
                throw new RuntimeException('Session settings changed after the control was proposed. Submit the control again for review.');
            }
            $action = $pending['action'];
            $confirmed = true;
        } else {
            [$operation, $arguments] = $this->parse($command);
            $definitions = (new CliToolCatalog($configuration))->definitions($session);
            $matches = array_filter($definitions, fn ($definition) => $definition['service'] === NativeCodexToolService::class && $definition['method'] === $operation);
            if (count($matches) !== 1) {
                throw new RuntimeException('The synchronized registry must provide one enabled native '.$operation.' tool.');
            }
            $action = ['id' => (string) Str::uuid(), 'name' => array_key_first($matches), 'arguments' => $arguments];
        }
        if (! in_array($action['arguments']['process_id'], $ownedIds, true)) {
            throw new RuntimeException('Process does not belong to this connection and session. Saved actions cannot be resumed.');
        }
        $native = new CliNativeExecution($this->store, $sessionId, $this->channel, $this->workspace);
        $runtime = new CliToolRuntime($this->store, $sessionId,
            // Operator process controls have no model request or model-host lease.
            // Native ownership/policy and the shared tool/skill/guardrail checks
            // still run; only inference admission is inapplicable here.
            fn () => ['allowed' => true],
            function ($session) use ($configuration) {
                $selection = $configuration->operationSelection($session);

                return ['workspace' => $this->workspace, 'execution_host' => gethostname(), '_cli_actor' => 'operator',
                    'operation_target' => $selection['target'], 'tree_id' => $selection['tree_id']];
            },
            fn () => $confirmed && hash_equals($scope, hash('sha256', json_encode(
                $configuration->effective($this->store->session($sessionId)), JSON_THROW_ON_ERROR))),
            serviceFactory: function ($class, $context, $definition) use ($native) {
                if ($class !== NativeCodexToolService::class || ! in_array($definition['method'], ['input', 'output', 'cancel', 'resize'], true)) {
                    throw new RuntimeException('This channel supports existing native process controls only.');
                }

                return new NativeCodexToolService($native->dispatch(...));
            }, workspaceRoot: $this->workspace);
        $decision = $runtime->inspectTool($action, []);
        if (($decision['confirmation_pending'] ?? false) === true) {
            $token = (string) Str::uuid();
            $this->pending[$token] = ['session' => $sessionId, 'scope' => $scope, 'action' => $action];
            $this->store->recordEvent($sessionId, 'native_operator_proposed', ['token' => $token, 'action' => $action, 'scope' => $scope]);

            return ['status' => 'approval_required', 'token' => $token, 'execution_host' => gethostname(), 'action' => $action,
                'input_text' => isset($action['arguments']['input_base64'])
                    && mb_check_encoding(base64_decode($action['arguments']['input_base64'], true), 'UTF-8')
                    ? base64_decode($action['arguments']['input_base64'], true) : null,
                'message' => 'Review the exact action below, then /process confirm '.$token.' or /process deny '.$token.'. Approval is scoped to these bytes and current session settings; disconnect discards it.'];
        }
        if (($decision['authorized'] ?? false) !== true) {
            throw new RuntimeException($decision['result_text'] ?? $decision['error'] ?? 'Current PLOS policy denied this operator control.');
        }
        $this->store->recordEvent($sessionId, 'native_operator_started', ['action' => $action, 'scope' => $scope, 'execution_host' => gethostname()]);
        try {
            $result = $runtime->execute($action, []);
            $this->store->recordEvent($sessionId, 'native_operator_completed', ['action_id' => $action['id'], 'result' => $result]);

            return $result;
        } catch (Throwable $error) {
            $this->store->recordEvent($sessionId, 'native_operator_unknown', ['action_id' => $action['id'], 'error' => $error->getMessage()]);
            throw $error;
        }
    }

    /** A fixed registered read, without inventing a /tool command or approval. */
    public function diff(string $sessionId, bool $staged, CliArtifactStore $artifacts): array
    {
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($sessionId);
        $turnId = $session['turns'][array_key_last($session['turns'] ?? [])]['id'] ?? '';
        $this->store->assertTurnNotCancelled($sessionId, $turnId);
        $native = new CliNativeExecution($this->store, $sessionId, $this->channel, $this->workspace);
        $runtime = new CliToolRuntime($this->store, $sessionId,
            function () use ($sessionId, $turnId): array {
                $this->store->assertTurnNotCancelled($sessionId, $turnId);

                return ['allowed' => true];
            }, function ($current) use ($configuration): array {
                $selection = $configuration->operationSelection($current);

                return ['workspace' => $this->workspace, 'execution_host' => gethostname(), '_cli_actor' => 'operator',
                    'operation_target' => $selection['target'], 'tree_id' => $selection['tree_id']];
            }, serviceFactory: function ($class, $context, $definition) use ($native) {
                if ($class !== NativeCodexToolService::class || $definition['method'] !== 'workspaceDiff') {
                    throw new RuntimeException('Workspace review requires the registered fixed diff tool.');
                }

                return new NativeCodexToolService($native->dispatch(...));
            }, workspaceRoot: $this->workspace);
        $action = ['id' => (string) Str::uuid(), 'name' => 'native_workspace_diff',
            'arguments' => ['path' => $this->workspace, 'staged' => $staged]];
        $decision = $runtime->inspectTool($action, []);
        if (($decision['authorized'] ?? false) !== true) {
            throw new RuntimeException('The selected skill or current policy does not allow workspace diff. Inspect /permissions and /skills; no command ran.');
        }
        $this->store->recordEvent($sessionId, 'workspace_diff_started', ['action' => $action]);
        $result = $runtime->execute($action, []);
        if (($result['success'] ?? false) !== true) {
            throw new RuntimeException($result['output'] ?? 'Workspace diff failed; inspect retained process output.');
        }
        $decoded = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_string($decoded['diff'] ?? null)) {
            throw new RuntimeException('Workspace diff did not return complete typed evidence.');
        }
        $artifact = $artifacts->captureBytes($decoded['diff']);
        $this->store->recordEvent($sessionId, 'workspace_diff_completed', ['artifact' => $artifact,
            'paths' => $decoded['paths'], 'excluded_paths' => $decoded['excluded_paths'], 'staged' => $staged]);
        $answer = $decoded['scope']."\nArtifact SHA-256: ".$artifact['sha256']."\n";
        if ($decoded['excluded_paths'] !== []) {
            $answer .= 'Excluded protected paths: '.implode(', ', $decoded['excluded_paths'])."\n";
        }
        $answer .= $decoded['diff'] === '' ? 'No readable tracked changes in this selection.' : $decoded['diff'];

        return [$this->store->session($sessionId), $answer];
    }

    private function parse(string $command): array
    {
        if (! preg_match('/^(send|input|eof|output|cancel|resize) ([a-zA-Z0-9_-]{1,128})(?: (.*))?$/sD', $command, $match)) {
            throw new RuntimeException('Use /process help for exact command syntax.');
        }
        $operation = $match[1];
        $arguments = ['process_id' => $match[2]];
        $tail = $match[3] ?? '';
        if ($operation === 'send') {
            $text = json_decode($tail, true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($text)) {
                throw new RuntimeException('Send requires one JSON string; use explicit \\n for a newline.');
            }
            $arguments['input_base64'] = base64_encode($text);
            $operation = 'input';
        } elseif ($operation === 'input') {
            $input = json_decode($tail, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($input) || array_diff(array_keys($input), ['input_base64', 'close_stdin'])) {
                throw new RuntimeException('Input accepts only input_base64 and close_stdin.');
            }
            $arguments += $input;
        } elseif ($operation === 'eof' && $tail === '') {
            $operation = 'input';
            $arguments['close_stdin'] = true;
        } elseif ($operation === 'output' && ($tail === '' || preg_match('/^\d+$/D', $tail))) {
            $arguments['offset'] = $tail === '' ? 0 : filter_var($tail, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        } elseif ($operation === 'resize' && preg_match('/^(\d+) (\d+)$/D', $tail, $size)) {
            $arguments += ['rows' => (int) $size[1], 'columns' => (int) $size[2]];
        } elseif ($operation !== 'cancel' || $tail !== '') {
            throw new RuntimeException('Invalid process control arguments. Use /process help.');
        }

        return [$operation, $arguments];
    }
}
