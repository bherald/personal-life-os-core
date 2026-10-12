<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Restore only already recorded results. Never invoke or infer an action. */
final class CliActionRecovery
{
    public function __construct(private readonly CliStateStore $store) {}

    public function cancel(array $session, ?array $binding = null): array
    {
        $session = $this->restore($session, allowUnclaimed: true);
        $conversation = new LosslessConversation($session['messages'] ?? []);
        $pending = $conversation->pendingToolCalls();
        $operatorTurn = $session['turns'][array_key_last($session['turns'])]['id'] ?? null;
        $cancel = [];
        foreach ($pending as $id => $entry) {
            if ($binding !== null && ($binding['action']['id'] ?? null) !== $id) {
                continue;
            }
            $origin = $session['message_turn_ids'][$entry['message_index']] ?? null;
            $call = $entry['call'];
            $arguments = json_decode($call['function']['arguments'] ?? '', true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($origin) || ! is_string($operatorTurn) || ! is_array($arguments) || ! is_string($call['function']['name'] ?? null)) {
                throw new RuntimeException('Pending action lacks a valid cancellation binding; review its original record.');
            }
            $action = ['id' => $id, 'name' => $call['function']['name'], 'arguments' => $arguments];
            if ($binding !== null && (($binding['turn_id'] ?? null) !== $origin || ($binding['action'] ?? null) !== $action)) {
                throw new RuntimeException('Approval denial does not match the currently pending action.');
            }
            $cancel[] = ['turn' => $origin, 'action' => $action];
        }
        if ($cancel === []) {
            throw new RuntimeException('No unexecuted pending action matches this cancellation.');
        }
        foreach ($cancel as $item) {
            $result = $this->store->cancelAction($session['id'], $item['turn'], $item['action'], $operatorTurn);
            $conversation->append(['role' => 'tool', 'tool_call_id' => $item['action']['id'], 'content' => $result['output']]);
            $session['message_turn_ids'][] = $item['turn'];
        }
        $session['messages'] = $conversation->messages();

        return $this->store->saveSession($session);
    }

    public function restore(array $session, bool $allowUnclaimed = false): array
    {
        $conversation = new LosslessConversation($session['messages'] ?? []);
        $pending = $conversation->pendingToolCalls();
        if ($pending === []) {
            return $session;
        }
        $restored = [];
        foreach ($pending as $id => $entry) {
            $turnId = $session['message_turn_ids'][$entry['message_index']] ?? null;
            if (! is_string($turnId)) {
                throw new RuntimeException('Pending tool call has no verified turn binding. Review the original action before continuing.');
            }
            $receipt = $this->store->actionReceipts($session['id'], $turnId)[$id] ?? null;
            $call = $entry['call'];
            $arguments = json_decode($call['function']['arguments'] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $expected = ['id' => $id, 'name' => $call['function']['name'] ?? null, 'arguments' => $arguments];
            if ($receipt === null) {
                if ($allowUnclaimed) {
                    continue;
                }
                throw new RuntimeException('Pending tool action needs explicit approval or cancellation before another model request. No action was replayed.');
            }
            if (($receipt['document']['action'] ?? null) !== $expected || $receipt['state'] !== 'completed') {
                throw new RuntimeException('Pending action outcome is unknown or its identity differs. Review is required; automatic replay is prohibited.');
            }
            $result = $receipt['document']['result'] ?? null;
            if (! is_array($result) || ! is_bool($result['success'] ?? null) || ! is_string($result['output'] ?? null)) {
                throw new RuntimeException('Pending action receipt does not contain a complete result.');
            }
            $restored[] = ['turn_id' => $turnId, 'message' => ['role' => 'tool', 'tool_call_id' => $id, 'content' => $result['output']]];
        }
        if ($restored === []) {
            return $session;
        }
        foreach ($restored as $result) {
            $conversation->append($result['message']);
            $session['message_turn_ids'][] = $result['turn_id'];
        }
        $session['messages'] = $conversation->messages();

        return $this->store->saveSession($session);
    }
}
