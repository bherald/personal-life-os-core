<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/** Full provider messages; presentation shortening never mutates this ledger. */
final class LosslessConversation
{
    /** @param list<array<string, mixed>> $messages */
    public function __construct(private array $messages = [], private readonly ?array $compaction = null)
    {
        foreach ($messages as $message) {
            $this->validate($message);
        }
    }

    /** @param array<string, mixed> $message */
    public function append(array $message): void
    {
        $this->validate($message);
        $this->messages[] = $message;
    }

    /** @return list<array<string, mixed>> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** The only shortened context is an explicitly approved, source-bound summary. */
    public function requestMessages(): array
    {
        if ($this->compaction === null) {
            return self::providerMessages($this->messages);
        }
        $count = $this->compaction['source_count'] ?? null;
        if (! is_int($count) || $count < 1 || $count > count($this->messages)
            || ! is_string($this->compaction['operator_turn'] ?? null)
            || ! is_string($this->compaction['summary'] ?? null) || trim($this->compaction['summary']) === ''
            || CliCompaction::hash(array_slice($this->messages, 0, $count)) !== ($this->compaction['source_sha256'] ?? null)) {
            throw new RuntimeException('Approved context summary does not match the original history. No shortened context was sent.');
        }
        $original = array_slice($this->messages, 0, $count);
        if ((new self($original))->pendingToolCalls() !== []) {
            throw new RuntimeException('A context summary cannot hide unresolved tool actions.');
        }
        $instructions = array_values(array_filter($original, fn ($message) => in_array($message['role'], ['system', 'developer'], true)));

        return self::providerMessages([...$instructions, ['role' => 'user', 'content' => "Operator-approved summary of earlier history (original records remain saved; earlier actions must not be replayed):\n".$this->compaction['summary']],
            ...array_slice($this->messages, $count)]);
    }

    /** Strip only PLOS-owned bookkeeping, preserving all provider content. */
    private static function providerMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            unset($message['_plos_argument_rejection']);

            return $message;
        }, $messages);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Inspect protocol order without editing any original message. */
    public function pendingToolCalls(): array
    {
        $pending = [];
        foreach ($this->messages as $index => $message) {
            if ($message['role'] === 'tool') {
                $id = $message['tool_call_id'] ?? null;
                if (! is_string($id) || ! isset($pending[$id])) {
                    throw new RuntimeException('Conversation has an unmatched or repeated tool result. Review the original history.');
                }
                unset($pending[$id]);

                continue;
            }
            if ($pending !== []) {
                throw new RuntimeException('Conversation continues before its tool results are resolved. Original history requires review.');
            }
            $calls = $message['tool_calls'] ?? [];
            if (! is_array($calls) || ! array_is_list($calls) || ($calls !== [] && $message['role'] !== 'assistant')) {
                throw new RuntimeException('Conversation has malformed tool-call history.');
            }
            foreach ($calls as $call) {
                $id = $call['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($pending[$id])) {
                    throw new RuntimeException('Conversation has malformed or duplicate pending tool identities.');
                }
                $pending[$id] = ['message_index' => $index, 'call' => $call];
            }
        }

        return $pending;
    }

    /**
     * Token counts must come from the selected model's admitted tokenizer.
     * No automatic trimming, summarization or retry with less input is allowed.
     *
     * @return list<array<string, mixed>>
     */
    public function forRequest(int $inputTokens, int $contextWindow, int $reservedOutputTokens): array
    {
        if ($inputTokens < 0 || $contextWindow < 1 || $reservedOutputTokens < 1) {
            throw new InvalidArgumentException('Invalid model context accounting.');
        }
        if ($reservedOutputTokens > $contextWindow || $inputTokens > $contextWindow - $reservedOutputTokens) {
            throw new OverflowException('Context limit reached. Original input is retained. Choose /compact for explicit confirmation, /model to select a larger model, or /clear to start a new session.');
        }

        return $this->requestMessages();
    }

    private function validate(array $message): void
    {
        if (! in_array($message['role'] ?? null, ['system', 'developer', 'user', 'assistant', 'tool'], true)) {
            throw new InvalidArgumentException('Unsupported conversation role.');
        }
        if (! array_key_exists('content', $message) || (! is_string($message['content']) && ! is_array($message['content']) && $message['content'] !== null)) {
            throw new InvalidArgumentException('Content must be exact text, structured media, or null for a tool call.');
        }
        // Reject malformed UTF-8 instead of silently replacing bytes at transport.
        json_encode($message, JSON_THROW_ON_ERROR);
    }
}
