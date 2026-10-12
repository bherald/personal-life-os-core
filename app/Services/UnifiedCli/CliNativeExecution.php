<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Called only by a registered service after the shared PLOS dispatcher checks it. */
final class CliNativeExecution
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly Closure $channel,
        private readonly ?string $workspaceRoot = null,
    ) {}

    public function dispatch(string $operation, array $arguments): array
    {
        $session = $this->store->session($this->sessionId);
        $values = (new CliConfiguration($this->store))->effective($session)['values'];
        $mode = $values['cli.operator_mode'];
        CliOperatorModes::validate($mode);
        if ($mode === 'chat' || ($mode === 'read-only' && ! in_array($operation, ['output', 'workspace_diff'], true))) {
            throw new RuntimeException('Native execution is blocked in '.$mode.' mode. Inspect /permissions and select an appropriate mode explicitly.');
        }
        if ($operation === 'workspace_diff') {
            if ($this->workspaceRoot === null || ! is_string($arguments['path'] ?? null)
                || realpath($arguments['path']) !== realpath($this->workspaceRoot)) {
                throw new RuntimeException("Workspace diff must use this CLI session's bound workspace.");
            }

            return (new CliWorkspaceDiff(fn ($command) => $this->readOnlyCommand($command, $arguments['path']), $arguments['path']))->read($arguments['staged'] ?? false);
        }
        $policy = $values['cli.native_permissions'] ?? null;
        if (in_array($operation, ['start', 'input'], true)) {
            self::validatePolicy($policy, CliConnectivity::toolNetwork($values));
        }

        return ($this->channel)($this->sessionId, $operation, $arguments, $policy);
    }

    private function readOnlyCommand(string $command, string $path): string
    {
        $policy = ['type' => 'readOnly', 'networkAccess' => false];
        $result = ($this->channel)($this->sessionId, 'start', ['command' => $command, 'path' => $path,
            'tty' => false, 'timeout_ms' => 30000], $policy);
        $deadline = microtime(true) + 35;
        while (in_array($result['status'] ?? null, ['starting', 'running'], true) && microtime(true) < $deadline) {
            usleep(100000);
            $result = ($this->channel)($this->sessionId, 'output', ['process_id' => $result['process_id'], 'offset' => 0], $policy);
        }
        if (in_array($result['status'] ?? null, ['starting', 'running'], true)) {
            // Cancel only the process returned by this exact session's launch.
            // A failed acknowledgement remains unknown, never a replay signal.
            $result = ($this->channel)($this->sessionId, 'cancel', ['process_id' => $result['process_id']], $policy);
            $stopDeadline = microtime(true) + 5;
            while (in_array($result['status'] ?? null, ['starting', 'running'], true) && microtime(true) < $stopDeadline) {
                usleep(100000);
                $result = ($this->channel)($this->sessionId, 'output', ['process_id' => $result['process_id'], 'offset' => 0], $policy);
            }
            throw new RuntimeException('The fixed diff command exceeded its wait. Cancellation was requested for its owned process; reported status: '.($result['status'] ?? 'unknown').'. Inspect /process list before another attempt.');
        }
        if (($result['status'] ?? null) !== 'completed' || ($result['exit_code'] ?? null) !== 0
            || ! empty($result['integrity_error'])) {
            throw new RuntimeException('The read-only Git command did not complete successfully. Inspect /process list and retained output; no command was replayed. A deployed release without Git metadata cannot provide a repository diff.');
        }
        $bytes = '';
        foreach ($result['events'] ?? [] as $event) {
            if (($event['type'] ?? null) === 'output' && ($event['stream'] ?? null) === 'stdout') {
                $part = base64_decode($event['deltaBase64'] ?? '', true);
                if ($part === false) {
                    throw new RuntimeException('Native diff output encoding is invalid; inspect the retained journal.');
                }
                $bytes .= $part;
            }
        }

        return $bytes;
    }

    public static function validatePolicy(mixed $policy, string $network): void
    {
        if (! is_array($policy) || ! in_array($policy['type'] ?? null, ['readOnly', 'workspaceWrite', 'dangerFullAccess'], true)) {
            throw new RuntimeException('Select cli.native_permissions from the synced settings before native execution.');
        }
        $keys = match ($policy['type']) {
            'readOnly' => ['type', 'networkAccess'],
            'workspaceWrite' => ['type', 'networkAccess', 'writableRoots', 'excludeTmpdirEnvVar', 'excludeSlashTmp'],
            'dangerFullAccess' => ['type'],
        };
        if (array_diff(array_keys($policy), $keys)) {
            throw new RuntimeException('Unsupported native permission fields.');
        }
        foreach (['networkAccess', 'excludeTmpdirEnvVar', 'excludeSlashTmp'] as $key) {
            if (array_key_exists($key, $policy) && ! is_bool($policy[$key])) {
                throw new RuntimeException('Native permission switches must be boolean.');
            }
        }
        if ($policy['type'] === 'workspaceWrite') {
            if (! is_array($policy['writableRoots'] ?? null) || ! array_is_list($policy['writableRoots'])) {
                throw new RuntimeException('Native writable roots must be an explicit list.');
            }
            foreach ($policy['writableRoots'] as $root) {
                if (! is_string($root) || ! str_starts_with($root, '/') || str_contains($root, "\0")) {
                    throw new RuntimeException('Native writable roots must be absolute host paths.');
                }
            }
        }
        if (($policy['type'] === 'dangerFullAccess' || ($policy['networkAccess'] ?? false)) && $network !== 'online') {
            throw new RuntimeException('Native unrestricted networking requires online mode. This native policy cannot enforce LAN-only networking.');
        }
    }
}
