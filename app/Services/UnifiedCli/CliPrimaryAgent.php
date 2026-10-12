<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Session orchestration shared by the terminal and a future web frontend. */
final class CliPrimaryAgent
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly Closure $resolveRoute,
        private readonly Closure $resolveTools,
        private readonly Closure $transport,
        private readonly Closure $tokenCounter,
        private readonly Closure $authorize,
        private readonly Closure $executeTool,
        private readonly ?Closure $resolveInstructions = null,
        private readonly ?Closure $resolveInput = null,
    ) {}

    public function run(array $session, array $input): array
    {
        $turnId = $session['turns'][array_key_last($session['turns'])]['id'] ?? null;
        if (! is_string($turnId)) {
            throw new RuntimeException('A persisted terminal turn is required.');
        }
        $session = (new CliActionRecovery($this->store))->restore($session);
        $route = $this->measure($session, 'route_admission', fn () => ($this->resolveRoute)($session));
        $this->refreshLeaseState($session, 'route selection');
        $tools = $this->measure($session, 'skill_tools', fn () => ($this->resolveTools)($session, $route));
        $parts = $this->resolveInput === null ? [] : ($this->resolveInput)($session, $input, $route);
        if ($this->resolveInput === null) {
            if (($session['turn_attachments'][$turnId]['queued'] ?? []) !== []) {
                throw new RuntimeException('Queued attachments require a connected input adapter. No attachment was omitted.');
            }
            foreach ($input as $item) {
                if (($item['type'] ?? null) !== 'text' || ! is_string($item['text'] ?? null)) {
                    throw new RuntimeException('This input requires an admitted media adapter. Original input is saved.');
                }
                $parts[] = ['type' => 'text', 'text' => $item['text']];
            }
        }
        $conversation = new LosslessConversation($session['messages'] ?? [], $session['active_context_compaction'] ?? null);
        if ($this->resolveInstructions !== null) {
            $instructions = ($this->resolveInstructions)($session, $route);
            if (! is_array($instructions) || ! array_is_list($instructions)) {
                throw new RuntimeException('Instruction provider must return complete ordered messages.');
            }
            foreach ($instructions as $instruction) {
                if (! in_array($instruction['role'] ?? null, ['system', 'developer'], true)
                    || ! is_string($instruction['content'] ?? null) || isset($instruction['tool_calls'])) {
                    throw new RuntimeException('Instruction provider returned an invalid instruction message.');
                }
            }
            $hash = hash('sha256', json_encode($instructions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (($session['instruction_binding'] ?? null) !== $hash) {
                if ($instructions === [] && isset($session['instruction_binding'])) {
                    throw new RuntimeException('Clearing active instructions requires an explicit replacement instruction or a new session. Original instructions cannot be silently removed.');
                }
                foreach ($instructions as $instruction) {
                    $conversation->append($instruction);
                }
                $session['instruction_binding'] = $hash;
            }
        }
        if (! is_array($parts) || ! array_is_list($parts) || $parts === []) {
            throw new RuntimeException('Input adapter must return complete ordered content parts.');
        }
        $conversation->append(['role' => 'user', 'content' => count($parts) === 1 && ($parts[0]['type'] ?? null) === 'text' ? $parts[0]['text'] : $parts]);
        $queued = $session['turn_attachments'][$turnId]['queued'] ?? [];
        if ($queued !== []) {
            if (array_slice($session['pending_attachments'] ?? [], 0, count($queued)) !== $queued) {
                throw new RuntimeException('Attachment queue changed before input could be saved.');
            }
            $session['pending_attachments'] = array_slice($session['pending_attachments'], count($queued));
        }
        $session['active_agent_turn'] = $turnId;
        $session['agent_route_hashes'][$turnId] = $this->routeHash($route);

        return $this->executeConversation($session, $turnId, $route, $tools, $conversation);
    }

    /** Explicit operator continuation; no new user/model message is invented. */
    public function resume(array $session): array
    {
        $commandTurn = $session['turns'][array_key_last($session['turns'])]['id'] ?? null;
        $turnId = $session['active_agent_turn'] ?? null;
        if (! is_string($commandTurn) || ! is_string($turnId) || $commandTurn === $turnId) {
            throw new RuntimeException('No interrupted agent turn is available for explicit continuation.');
        }
        $session = (new CliActionRecovery($this->store))->restore($session, allowUnclaimed: true);
        $route = ($this->resolveRoute)($session);
        if (($session['agent_route_hashes'][$turnId] ?? null) !== $this->routeHash($route)) {
            throw new RuntimeException('The selected route changed. Continuation cannot silently switch model or execution limits.');
        }
        if ($this->resolveInstructions !== null) {
            $instructions = ($this->resolveInstructions)($session, $route);
            $hash = hash('sha256', json_encode($instructions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (($session['instruction_binding'] ?? null) !== $hash) {
                throw new RuntimeException('Selected agent or skill instructions changed. Restore the original selection before continuing its pending actions.');
            }
        }
        $conversation = new LosslessConversation($session['messages'] ?? [], $session['active_context_compaction'] ?? null);
        $last = $session['messages'][array_key_last($session['messages'])] ?? null;
        if ($conversation->pendingToolCalls() === [] && ($last['role'] ?? null) !== 'tool') {
            throw new RuntimeException('No pending tool batch or recorded tool result requires continuation.');
        }
        $completedResponses = 0;
        foreach ($session['messages'] as $index => $message) {
            if (($session['message_turn_ids'][$index] ?? null) === $turnId && $message['role'] === 'assistant') {
                $completedResponses++;
            }
        }
        $iterations = $session['agent_model_attempts'][$turnId] ?? null;
        if (! is_int($iterations) || $iterations < $completedResponses) {
            throw new RuntimeException('Original model request budget is unavailable; continuation requires review.');
        }
        $receipts = $this->store->actionReceipts($session['id'], $turnId);
        foreach ($receipts as $receipt) {
            if ($receipt['state'] !== 'completed') {
                throw new RuntimeException('Unknown action outcome blocks continuation; review its receipt.');
            }
        }
        // Controller renewals have their own exact-operation journal, but are
        // not model-selected tool calls and cannot consume that action budget.
        $receipts = array_filter($receipts, fn ($receipt) => ($receipt['document']['action']['origin'] ?? null) !== 'controller_lifecycle');
        $attempts = count(array_filter($receipts, fn ($receipt) => ($receipt['document']['outcome'] ?? null) !== 'cancelled'));
        $resume = ['iterations' => $iterations, 'tool_calls' => $attempts, 'executed_ids' => array_fill_keys(array_keys($receipts), true)];

        return $this->executeConversation($session, $turnId, $route, ($this->resolveTools)($session, $route), $conversation, $resume, $commandTurn);
    }

    /** Explicitly confirmed summary work; model-selected actions are never executed. */
    public function compact(array $session, string $confirmation): array
    {
        $compaction = new CliCompaction($this->store);
        $session = $compaction->begin($session, $confirmation);
        try {
            return $this->summarize($session, $compaction);
        } catch (\Throwable $error) {
            $current = $this->store->session($session['id']);
            if (($current['compaction_request']['id'] ?? null) === $confirmation
                && ($current['compaction_request']['state'] ?? null) === 'running') {
                $current['compaction_request']['state'] = 'failed';
                $this->store->saveSession($current);
            }
            throw $error;
        }
    }

    private function summarize(array $session, CliCompaction $compaction): array
    {
        $route = ($this->resolveRoute)($session);
        $this->refreshLeaseState($session, 'compaction route selection');
        $conversation = new LosslessConversation($session['messages'] ?? [], $session['active_context_compaction'] ?? null);
        $messages = $conversation->requestMessages();
        // Current table-controlled instructions remain complete and authoritative.
        if ($this->resolveInstructions !== null) {
            foreach (($this->resolveInstructions)($session, $route) as $instruction) {
                if (! in_array($instruction['role'] ?? null, ['system', 'developer'], true)
                    || ! is_string($instruction['content'] ?? null) || isset($instruction['tool_calls'])) {
                    throw new RuntimeException('Compaction requires valid complete PLOS instructions.');
                }
                if (! in_array($instruction, $messages, true)) {
                    $messages[] = $instruction;
                }
            }
        }
        $request = function ($selected, $messages, $tools) use (&$session): array {
            $decision = ($this->authorize)('model', $selected, []);
            if (($decision['allowed'] ?? false) !== true) {
                throw new RuntimeException($decision['reason'] ?? 'Current PLOS policy denied the summary request.');
            }
            $session['compaction_request']['model_request_started'] = true;
            $session['compaction_request']['model_requests_started'] = ($session['compaction_request']['model_requests_started'] ?? 0) + 1;
            $session = $this->store->saveSession($session);
            try {
                return ($this->transport)($selected, $messages, $tools);
            } finally {
                $this->refreshLeaseState($session, 'compaction model I/O');
            }
        };
        $summary = (new CliSummaryReducer($request, $this->tokenCounter))->summarize($messages, $route, $compaction->policy($session));
        $session = $compaction->finish($session, $summary, $route);

        return [$session, "Compaction completed. Full original history, instructions and results remain saved; earlier actions were not replayed.\n\n".$summary];
    }

    private function executeConversation(array $session, string $turnId, array $route, array $tools, LosslessConversation $conversation, array $resume = [], ?string $continuationTurn = null): array
    {
        $checkpoint = function (array $messages) use (&$session, $turnId): void {
            $session['message_turn_ids'] ??= array_fill(0, count($session['messages'] ?? []), null);
            while (count($session['message_turn_ids']) < count($messages)) {
                $session['message_turn_ids'][] = $turnId;
            }
            $session['messages'] = $messages;
            $session = $this->store->saveSession($session);
        };
        $checkpoint($conversation->messages());
        $journal = function (string $event, array $action, array $selected, ?array $result) use (&$session, $turnId, $continuationTurn): bool {
            if ($event === 'start') {
                $this->store->startAction($session['id'], $turnId, $action, $selected, $continuationTurn);
            } elseif ($event === 'complete' && $result !== null) {
                $this->store->completeAction($session['id'], $turnId, $action['id'], $result);
            } else {
                throw new RuntimeException('Invalid action journal event.');
            }

            return true;
        };
        $transport = function (array $selected, array $messages, array $nativeTools) use (&$session, $turnId, $continuationTurn): array {
            // Claim the request budget before I/O; failed or interrupted model
            // requests must not disappear when the operator continues a turn.
            $session['agent_model_attempts'][$turnId] = ($session['agent_model_attempts'][$turnId] ?? 0) + 1;
            $session = $this->store->saveSession($session);

            try {
                $reply = $this->measure($session, 'model_request', fn () => ($this->transport)($selected, $messages, $nativeTools));
            } finally {
                // The lease keeper may checkpoint a renewal while HTTP waits.
                // Only that separate state may advance; concurrent input or
                // selection changes still fail instead of overwriting history.
                $this->refreshLeaseState($session, 'model I/O');
            }
            if (($reply['completed'] ?? false) === true && ($reply['model'] ?? null) === $selected['model']
                && ($reply['instance_id'] ?? null) === $selected['instance_id']) {
                $usage = CliContextUsage::provider($selected, $continuationTurn ?? $turnId, $reply['usage'] ?? null);
                if ($usage !== null) {
                    $session['context_usage'] = $usage;
                    $session = $this->store->saveSession($session);
                }
            }

            return $reply;
        };
        $execute = function (array $action, array $selected) use (&$session): array {
            try {
                return $this->measure($session, 'tool_execution', fn () => ($this->executeTool)($action, $selected));
            } finally {
                $this->refreshLeaseState($session, 'tool execution');
            }
        };
        $counter = function ($selected, $messages, $nativeTools) use (&$session, $turnId, $continuationTurn) {
            try {
                $tokens = $this->measure($session, 'context_count', fn () => ($this->tokenCounter)($selected, $messages, $nativeTools));
            } finally {
                $this->refreshLeaseState($session, 'context counting');
            }
            if (is_int($tokens)) {
                $usage = CliContextUsage::measured($selected, $continuationTurn ?? $turnId, $tokens, $selected['max_output_tokens'], 'exact_input_with_reserve');
                if ($usage !== null) {
                    $session['context_usage'] = $usage;
                    $session = $this->store->saveSession($session);
                }
            }

            return $tokens;
        };
        $authorize = fn ($kind, $selected, $action) => $this->measure($session, $kind.'_admission',
            fn () => ($this->authorize)($kind, $selected, $action));
        $loop = new PrimaryAgentLoop($transport, $counter, $authorize, $execute, $journal, argumentCorrectionScope: $turnId);
        $result = $loop->run($conversation, $route, $tools, $checkpoint, $resume);

        return [$session, $result['response'].$this->toolNotices($session['id'], $turnId)];
    }

    /** Observe existing stages without changing admission, inputs, or execution order. */
    private function measure(array $session, string $phase, Closure $operation): mixed
    {
        $start = hrtime(true);
        $completed = false;
        $step = ['turn_id' => $session['turns'][array_key_last($session['turns'])]['id'],
            'phase' => $phase, 'step_id' => bin2hex(random_bytes(8))];
        $this->store->recordEvent($session['id'], 'agent_step', $step + ['state' => 'started', 'completed' => false, 'elapsed_ms' => 0]);
        try {
            $result = $operation();
            $completed = true;

            return $result;
        } finally {
            $this->store->recordEvent($session['id'], 'agent_step', $step + [
                'state' => ! $completed ? 'failed' : ((is_array($result) && ($result['allowed'] ?? null) === false) ? 'rejected' : 'finished'),
                'completed' => $completed,
                'allowed' => is_array($result ?? null) && is_bool($result['allowed'] ?? null) ? $result['allowed'] : null,
                'elapsed_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
            ]);
        }
    }

    /** Operator presentation only; original model/tool messages remain lossless. */
    private function toolNotices(string $sessionId, string $turnId): string
    {
        $messages = [
            'reduced_coverage' => 'reported reduced coverage; the available evidence may be incomplete.',
            'source_warnings' => 'returned source warnings; inspect its retained result for details.',
            'source_freshness' => 'reported stale or future-dated source data; check its source timestamps.',
        ];
        $notices = [];
        foreach ($this->store->actionReceipts($sessionId, $turnId) as $receipt) {
            if ($receipt['state'] !== 'completed') {
                continue;
            }
            $document = $receipt['document'];
            foreach ($document['result']['warnings'] ?? [] as $code) {
                if (is_string($code) && isset($messages[$code])) {
                    $notices[] = ($document['action']['name'] ?? 'Tool').' '.$messages[$code];
                }
            }
        }

        return $notices === [] ? '' : "\n\nPLOS tool notices:\n- ".implode("\n- ", array_unique($notices));
    }

    private function refreshLeaseState(array &$session, string $phase): void
    {
        $current = $this->store->session($session['id']);
        $before = $session;
        $after = $current;
        unset($before['revision'], $before['host_lease'], $after['revision'], $after['host_lease']);
        if ($before !== $after) {
            throw new RuntimeException('Session changed during '.$phase.'; reload before continuing.');
        }
        $session = $current;
    }

    private function routeHash(array $route): string
    {
        return hash('sha256', json_encode($route, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
