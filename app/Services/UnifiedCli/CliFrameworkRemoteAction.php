<?php

namespace App\Services\UnifiedCli;

use Closure;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/** Worker-local durable custody. Readback never invokes the registry or releases parent custody. */
final class CliFrameworkRemoteAction
{
    private bool $supervised = false;

    public function __construct(private readonly CliStateStore $store, private readonly array $binding) {}

    public function dispatch(array $request, Closure $execute): array
    {
        $this->supervised = ($request['supervised_process'] ?? false) === true;
        if ($this->supervised && ! isset($this->binding['supervisor'])) {
            throw new RuntimeException('Original supervised process binding is unavailable.');
        }
        $operation = $request['remote_operation'] ?? null;
        $caller = $request['caller_binding'] ?? null;
        $existingControl = ($request['control_existing'] ?? false) === true;
        if (($existingControl && ! in_array($operation, ['status', 'cancel'], true))
            || ! in_array($operation, ['execute', 'status', 'cancel'], true) || ! is_array($caller)
            || (! $existingControl && $operation !== 'status' && ($request['approved'] ?? false) !== true)) {
            throw new RuntimeException('Durable framework control requires the exact approved caller.');
        }
        // Execution validates live rules and definitions. Existing-only control
        // validates the installation, original caller and exact durable request;
        // it cannot create a claim or invoke the registry.
        $id = hash('sha256', json_encode([$caller['session_id'], $caller['turn_id'], $caller['action_id']], JSON_THROW_ON_ERROR));
        $requestHash = hash('sha256', json_encode(array_diff_key($request, ['remote_operation' => true, 'approved' => true, 'control_existing' => true]), JSON_THROW_ON_ERROR));
        $sessionId = $this->binding['session_id'];
        $turnId = $this->binding['turn_id'];
        $session = $this->store->session($sessionId);
        if (($session['framework_worker'] ?? null) !== ['worker_id' => $this->binding['worker_id'], 'root' => $request['root']]) {
            throw new RuntimeException('Worker-local journal identity differs from this installation.');
        }
        if (! $existingControl) {
            $this->store->assertTurnNotCancelled($sessionId, $turnId);
        }
        $old = $this->store->actionReceipts($sessionId, $turnId)[$id] ?? null;
        if ($old !== null && (($old['document']['action']['arguments']['request_sha256'] ?? null) !== $requestHash
            || (($old['document']['action_executed'] ?? null) !== false && ($old['document']['instance_id'] ?? null) !== $this->binding['worker_id']))) {
            throw new RuntimeException('Original remote action identity is bound to a different request.');
        }
        if ($operation !== 'execute') {
            if ($old === null && $operation === 'cancel' && ! $existingControl) {
                $this->store->cancelAction($sessionId, $turnId,
                    ['id' => $id, 'name' => $request['tool'], 'arguments' => ['request_sha256' => $requestHash]], $turnId);
                $old = $this->store->actionReceipts($sessionId, $turnId)[$id];
            }
            if ($operation === 'cancel' && ($old === null || $old['state'] === 'started')) {
                // Intent only. No local PID, SSH exit or request can certify a stopped tree.
                $this->store->recordEvent($sessionId, 'framework_remote_cancel_requested',
                    ['action_id' => $id, 'request_sha256' => $requestHash]);
                if ($this->supervised) {
                    CliWindowsActionProcess::requestCancel($this->binding, ['action_id' => $id, 'request_sha256' => $requestHash]);
                }
            }

            return $this->readback($id, $requestHash, $old, $this->cancelRequested($id, $requestHash));
        }
        if ($old !== null) {
            throw new CliToolOutcomeUnknown('Remote action already has a durable claim. Read the original result; never replay.');
        }
        if ($this->cancelRequested($id, $requestHash)) {
            // A control request may arrive after parent dispatch intent but before
            // the worker claim. Only the still-approved execute path creates this
            // no-effect tombstone; control itself never creates execution authority.
            $this->store->cancelAction($sessionId, $turnId,
                ['id' => $id, 'name' => $request['tool'], 'arguments' => ['request_sha256' => $requestHash]], $turnId);

            $saved = $this->store->actionReceipts($sessionId, $turnId)[$id];

            return ['success' => false, 'cancelled_before_dispatch' => true,
                'framework_remote_receipt' => $this->readback($id, $requestHash, $saved, true)];
        }
        try {
            $this->store->startAction($sessionId, $turnId,
                ['id' => $id, 'name' => $request['tool'], 'arguments' => ['request_sha256' => $requestHash]],
                ['instance_id' => $this->binding['worker_id'], 'model' => 'framework-no-model']);
            $result = $this->supervised ? Context::scope($execute, hidden: [CliWindowsActionProcess::CONTEXT => [
                'journal' => $this->binding, 'identity' => ['action_id' => $id, 'request_sha256' => $requestHash],
            ]]) : $execute();
            if ($this->supervised && CliWindowsActionProcess::terminal($this->binding,
                ['action_id' => $id, 'request_sha256' => $requestHash]) === null) {
                throw new RuntimeException('Supervised action did not publish native whole-job termination.');
            }
            if (! is_array($result) || ! is_bool($result['success'] ?? null) || ($result['outcome_unknown'] ?? false) === true
                || array_key_exists('framework_remote_receipt', $result)) {
                throw new RuntimeException('Worker result is incomplete or its execution outcome is unknown.');
            }
            $bytes = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->store->completeAction($sessionId, $turnId, $id, ['success' => $result['success'], 'output' => $bytes]);
            $saved = $this->store->actionReceipts($sessionId, $turnId)[$id];
            if (($saved['document']['result']['output'] ?? null) !== $bytes) {
                throw new RuntimeException('Worker terminal result did not reopen exactly.');
            }

            return $result + ['framework_remote_receipt' => $this->readback($id, $requestHash, $saved, $this->cancelRequested($id, $requestHash))];
        } catch (\Throwable $error) {
            throw new CliToolOutcomeUnknown('Worker action has no delivered verified result. Reconnect to its exact durable identity; no automatic replay.', previous: $error);
        }
    }

    private function cancelRequested(string $id, string $hash): bool
    {
        foreach ($this->store->events($this->binding['session_id']) as $event) {
            if ($event['type'] === 'framework_remote_cancel_requested'
                && ($event['document']['event']['action_id'] ?? null) === $id && ($event['document']['event']['request_sha256'] ?? null) === $hash) {
                return true;
            }
        }

        return false;
    }

    private function readback(string $id, string $hash, ?array $receipt, bool $cancel): array
    {
        $result = $receipt['state'] ?? null;
        $bytes = $result === 'completed' && ($receipt['document']['action_executed'] ?? null) !== false ? $receipt['document']['result']['output'] : null;
        $terminal = $receipt === null || ! $this->supervised ? null : CliWindowsActionProcess::terminal($this->binding,
            ['action_id' => $id, 'request_sha256' => $hash]);

        return ['schema' => 'plos.cli.framework-remote-receipt.v1', 'success' => true,
            'worker_id' => $this->binding['worker_id'], 'action_id' => $id, 'request_sha256' => $hash,
            'state' => ($receipt['document']['action_executed'] ?? null) === false ? 'cancelled_before_dispatch'
                : ($result === null ? 'not_found' : ($result === 'completed' ? 'result_recorded' : 'running_or_outcome_unknown')),
            'cancel_requested' => $cancel || ($receipt['document']['action_executed'] ?? null) === false, 'process_tree_termination_verified' => $terminal !== null,
            'automatic_replay_allowed' => false, 'result_json' => $bytes,
            ...($terminal === null ? [] : ['native_terminal' => $terminal, 'native_terminal_sha256' => hash('sha256', json_encode($terminal, JSON_THROW_ON_ERROR))]),
            'result_sha256' => $bytes === null ? null : hash('sha256', $bytes)];
    }
}
