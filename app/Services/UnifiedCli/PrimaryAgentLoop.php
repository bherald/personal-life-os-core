<?php

namespace App\Services\UnifiedCli;

use App\Services\MCPToolResultValidatorService;
use Closure;
use RuntimeException;

/** One selected model owns a turn; all actions cross the supplied PLOS gate. */
final class PrimaryAgentLoop
{
    public function __construct(
        private readonly Closure $transport,
        private readonly Closure $tokenCounter,
        private readonly Closure $authorize,
        private readonly Closure $executeTool,
        private readonly Closure $journal,
        private readonly ?string $argumentCorrectionScope = null,
    ) {}

    /**
     * The route is resolved by PLOS, not supplied by model output. The admission
     * callback must check current policy/custody before each request and action.
     * The checkpoint persists complete messages before a subsequent action.
     */
    public function run(LosslessConversation $conversation, array $route, array $tools, Closure $checkpoint, array $resume = []): array
    {
        $correctionLimit = array_key_exists('max_argument_corrections', $route) ? $route['max_argument_corrections'] : 0;
        if (! is_int($correctionLimit) || $correctionLimit < 0 || $correctionLimit > 2
            || ($correctionLimit > 0 && ($this->argumentCorrectionScope === null || $this->argumentCorrectionScope === ''))) {
            throw new RuntimeException('Argument correction requires a table-owned integer limit from zero to two and the original agent turn.');
        }
        [$correctionCount, $rejectedIds] = $this->rejectionLedger($conversation);
        if ($correctionCount > $correctionLimit) {
            throw new RuntimeException('Persisted argument correction budget exceeds the current route.');
        }
        $pending = $conversation->pendingToolCalls();
        if ($pending !== [] && $resume === []) {
            throw new RuntimeException('Unresolved tool actions must be reviewed before another model request. No automatic replay was attempted.');
        }
        foreach (['context_window', 'max_output_tokens', 'max_iterations', 'max_tool_calls'] as $limit) {
            if (! is_int($route[$limit] ?? null) || $route[$limit] < 1) {
                throw new RuntimeException('A positive table-backed '.$limit.' is required.');
            }
        }
        if (($route['agent_admitted'] ?? false) !== true || ! is_string($route['instance_id'] ?? null) || ! is_string($route['model'] ?? null)) {
            throw new RuntimeException('Exact primary-agent instance/model contract is not admitted.');
        }
        $toolNames = [];
        $schemaValidator = new MCPToolResultValidatorService;
        foreach ($tools as $tool) {
            $name = $tool['function']['name'] ?? null;
            $schema = $tool['function']['parameters'] ?? null;
            if (($tool['type'] ?? null) !== 'function' || ! is_string($name) || $name === '' || isset($toolNames[$name])
                || ! is_array($schema) || ! $schemaValidator->validateSchema($schema)['valid']) {
                throw new RuntimeException('Invalid or duplicate tool schema.');
            }
            $toolNames[$name] = $schema;
        }
        $executedIds = $resume['executed_ids'] ?? [];
        $toolCount = $resume['tool_calls'] ?? 0;
        $iterations = $resume['iterations'] ?? 0;
        if (! is_int($toolCount) || $toolCount < 0 || $toolCount > $route['max_tool_calls']
            || ! is_int($iterations) || $iterations < 0 || $iterations > $route['max_iterations'] || ! is_array($executedIds)) {
            throw new RuntimeException('Invalid persisted continuation budget.');
        }
        $executedIds += $rejectedIds;
        if ($pending !== []) {
            $this->executeBatch(array_column($pending, 'call'), $toolNames, $route, $conversation, $checkpoint, $executedIds, $toolCount, $correctionCount, $correctionLimit);
        }
        for ($iteration = $iterations; $iteration < $route['max_iterations']; $iteration++) {
            $this->requireAdmission('model', $route, []);
            if (($route['context_handling'] ?? null) === 'provider_reject' && in_array($route['wire_api'] ?? null, ['ollama_chat', 'chat_completions'], true)) {
                // Admitted transports reject overflow; send the original history
                // without an approximate local count or shortening it.
                if ($route['max_output_tokens'] >= $route['context_window']) {
                    throw new RuntimeException('Output budget leaves no input context. Adjust the selected runtime settings.');
                }
                $messages = $conversation->requestMessages();
            } else {
                $tokens = ($this->tokenCounter)($route, $conversation->requestMessages(), $tools);
                if (! is_int($tokens)) {
                    throw new RuntimeException('Selected-model tokenizer did not supply an integer count.');
                }
                $messages = $conversation->forRequest($tokens, $route['context_window'], $route['max_output_tokens']);
            }
            $checkpoint($conversation->messages());
            $reply = ($this->transport)($route, $messages, $tools);
            if (! is_array($reply)) {
                throw new RuntimeException('Invalid model response; original transport evidence is retained.');
            }
            if (($reply['instance_id'] ?? null) !== $route['instance_id'] || ($reply['model'] ?? null) !== $route['model']) {
                throw new RuntimeException('Selected instance/model mismatch; no fallback was attempted.');
            }
            if (($reply['completed'] ?? false) !== true) {
                if (($reply['finish_reason'] ?? null) === 'length') {
                    throw new RuntimeException('Selected model reached its '.$route['max_output_tokens'].'-token output limit. Full received output is retained; incomplete tool calls were not executed. No automatic retry or fallback was attempted.');
                }
                throw new RuntimeException('Selected model response did not complete. Full received output is retained; no automatic retry or fallback was attempted.');
            }
            $content = $reply['content'] ?? null;
            $calls = $reply['tool_calls'] ?? [];
            if ((! is_string($content) && $content !== null) || ! is_array($calls) || ! array_is_list($calls)) {
                throw new RuntimeException('Malformed primary-agent response.');
            }
            $message = ['role' => 'assistant', 'content' => $content];
            if (isset($reply['reasoning_content'])) {
                if (! is_string($reply['reasoning_content'])) {
                    throw new RuntimeException('Malformed reasoning content; original response requires review.');
                }
                $message['reasoning_content'] = $reply['reasoning_content'];
            }
            if ($calls !== []) {
                $message['tool_calls'] = $calls;
            }
            $conversation->append($message);
            $checkpoint($conversation->messages());
            if ($calls === []) {
                if (! is_string($content) || $content === '') {
                    throw new RuntimeException('Primary agent returned neither a result nor an action.');
                }

                return ['status' => 'completed', 'response' => $content, 'messages' => $conversation->messages(), 'tool_calls' => $toolCount, 'instance_id' => $route['instance_id'], 'model' => $route['model']];
            }
            $this->executeBatch($calls, $toolNames, $route, $conversation, $checkpoint, $executedIds, $toolCount, $correctionCount, $correctionLimit);
        }
        throw new RuntimeException('Agent iteration limit reached. Full conversation is retained; no automatic continuation or fallback.');
    }

    private function executeBatch(array $calls, array $toolNames, array $route, LosslessConversation $conversation, Closure $checkpoint, array &$executedIds, int &$toolCount, int &$correctionCount, int $correctionLimit): void
    {
        $schemaValidator = new MCPToolResultValidatorService;
        if (count($calls) > $route['max_tool_calls'] - $toolCount) {
            throw new RuntimeException('Tool-call limit reached; remaining actions were not executed.');
        }
        // Validate the whole response before executing any member of it.
        $actions = [];
        $batchIds = [];
        $invalid = [];
        foreach ($calls as $call) {
            $id = $call['id'] ?? null;
            $name = $call['function']['name'] ?? null;
            $arguments = $call['function']['arguments'] ?? null;
            if (! is_string($id) || $id === '' || isset($executedIds[$id]) || isset($batchIds[$id]) || ! is_string($name) || ! isset($toolNames[$name]) || ($call['type'] ?? null) !== 'function' || ! is_string($arguments)) {
                throw new RuntimeException('Unknown, malformed or duplicate tool action; no batch action executed.');
            }
            $decoded = json_decode($arguments, flags: JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new RuntimeException('Tool arguments must be a JSON object.');
            }
            $validation = $schemaValidator->validate(['structuredContent' => $decoded], $toolNames[$name], $decoded);
            if (! $validation['valid']) {
                if ($correctionLimit === 0) {
                    throw new RuntimeException('Tool arguments violate the declared schema; no batch action executed.');
                }
                $invalid[$id] = ['codes' => $validation['codes'], 'fields' => $validation['fields']];
            }
            $actions[] = ['id' => $id, 'name' => $name, 'arguments' => json_decode($arguments, true, flags: JSON_THROW_ON_ERROR)];
            $batchIds[$id] = true;
        }
        if ($invalid !== []) {
            if ($correctionCount >= $correctionLimit) {
                throw new RuntimeException('Argument correction limit reached; no batch action executed.');
            }
            // A resumed partial batch is not evidence that the whole batch had no
            // effects. Only the exact, still-unanswered original batch qualifies.
            $messages = $conversation->messages();
            $original = end($messages);
            if (($original['role'] ?? null) !== 'assistant' || ($original['tool_calls'] ?? null) !== $calls) {
                throw new RuntimeException('Argument correction requires the complete unexecuted batch.');
            }
            $sequence = $correctionCount + 1;
            $batchHash = self::rejectionHash($calls);
            $feedback = [];
            foreach ($actions as $action) {
                $id = $action['id'];
                $content = json_encode([
                    'isError' => true, 'executed' => false,
                    'error' => isset($invalid[$id]) ? 'tool_argument_schema_invalid' : 'tool_batch_not_executed',
                    'validation' => $invalid[$id] ?? null,
                    'instruction' => 'No action in this batch was executed. Correct arguments using the declared tool schema, use new call IDs, and resubmit the needed actions. Diagnostic labels are not operations.',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $feedback[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => $content,
                    '_plos_argument_rejection' => ['scope' => $this->argumentCorrectionScope, 'sequence' => $sequence,
                        'batch_sha256' => $batchHash, 'content_sha256' => hash('sha256', $content)]];
            }
            // Save the entire feedback batch first. A failed checkpoint cannot
            // authorize another request, even on this same in-memory object.
            $checkpoint([...$messages, ...$feedback]);
            foreach ($feedback as $message) {
                $conversation->append($message);
            }
            $correctionCount = $sequence;
            $executedIds += $batchIds;

            return;
        }
        foreach ($actions as $action) {
            $this->requireAdmission('tool', $route, $action);
            if (($this->journal)('start', $action, $route, null) !== true) {
                throw new RuntimeException('Action could not be durably claimed; no execution attempted.');
            }
            $executedIds[$action['id']] = true;
            $toolCount++;
            $result = ($this->executeTool)($action, $route);
            if (! is_array($result) || ! is_bool($result['success'] ?? null) || ! is_string($result['output'] ?? null)) {
                throw new RuntimeException('Tool did not return a complete typed result; inspect the action receipt before resuming.');
            }
            if (($this->journal)('complete', $action, $route, $result) !== true) {
                throw new RuntimeException('Action completion could not be saved. Review its outcome before continuing.');
            }
            $conversation->append(['role' => 'tool', 'tool_call_id' => $action['id'], 'content' => $result['output']]);
            $checkpoint($conversation->messages());
            if (! $result['success']) {
                throw new RuntimeException('Tool reported failure. Full output is retained; the turn stopped before another action.');
            }
        }
    }

    /** Local saved-message metadata is never accepted from a model reply. */
    private function rejectionLedger(LosslessConversation $conversation): array
    {
        $messages = $conversation->messages();
        $counts = [];
        $retired = [];
        for ($index = 0; $index < count($messages); $index++) {
            $message = $messages[$index];
            if (! array_key_exists('_plos_argument_rejection', $message)) {
                continue;
            }
            $previous = $messages[$index - 1] ?? [];
            $calls = $previous['tool_calls'] ?? null;
            $marker = $message['_plos_argument_rejection'];
            if (($previous['role'] ?? null) !== 'assistant' || ! is_array($calls) || $calls === []
                || ! is_array($marker) || ! is_string($marker['scope'] ?? null) || $marker['scope'] === ''
                || ! is_int($marker['sequence'] ?? null)
                || $marker['sequence'] !== ($counts[$marker['scope']] ?? 0) + 1 || $marker['sequence'] > 2
                || ($marker['batch_sha256'] ?? null) !== self::rejectionHash($calls)) {
                throw new RuntimeException('Invalid persisted argument rejection ledger; no continuation attempted.');
            }
            $sawInvalid = false;
            $ids = [];
            foreach ($calls as $offset => $call) {
                $entry = $messages[$index + $offset] ?? [];
                $entryMarker = $entry['_plos_argument_rejection'] ?? [];
                $id = $call['id'] ?? null;
                $content = $entry['content'] ?? null;
                if (! is_string($id) || $id === '' || isset($ids[$id]) || ($entry['role'] ?? null) !== 'tool'
                    || ($entry['tool_call_id'] ?? null) !== $id || ! is_string($content)
                    || $entryMarker !== array_replace($marker, ['content_sha256' => hash('sha256', $content)])) {
                    throw new RuntimeException('Incomplete or altered argument rejection checkpoint; no continuation attempted.');
                }
                $typed = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                if (($typed['isError'] ?? null) !== true || ($typed['executed'] ?? null) !== false
                    || ! in_array($typed['error'] ?? null, ['tool_argument_schema_invalid', 'tool_batch_not_executed'], true)) {
                    throw new RuntimeException('Invalid typed argument rejection feedback.');
                }
                $sawInvalid = $sawInvalid || $typed['error'] === 'tool_argument_schema_invalid';
                $ids[$id] = true;
            }
            if (! $sawInvalid) {
                throw new RuntimeException('Argument rejection checkpoint has no schema failure.');
            }
            $counts[$marker['scope']] = $marker['sequence'];
            if ($marker['scope'] === $this->argumentCorrectionScope) {
                $retired += $ids;
            }
            $index += count($calls) - 1;
        }

        return [$counts[$this->argumentCorrectionScope ?? ''] ?? 0, $retired];
    }

    private static function rejectionHash(array $calls): string
    {
        return hash('sha256', json_encode($calls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function requireAdmission(string $kind, array $route, array $action): void
    {
        $decision = ($this->authorize)($kind, $route, $action);
        if (! is_array($decision) || ($decision['allowed'] ?? false) !== true) {
            throw new RuntimeException('PLOS execution gate blocked '.$kind.': '.($decision['reason'] ?? 'current admission unavailable'));
        }
    }
}
