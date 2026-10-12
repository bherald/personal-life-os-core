<?php

namespace App\Support\AI;

final class StreamFallbackGuard
{
    private const TOOL_CALL_ID_PATTERN = '/\Atool_[1-9]\d*_[1-9]\d*\z/D';

    private bool $answerCommitted = false;

    private bool $toolExecutionCommitted = false;

    private bool $completed = false;

    private bool $terminalError = false;

    /** @var array<string,array{tool_call_id:string,server:string,tool:string}> */
    private array $pendingTools = [];

    /**
     * @return array<string, mixed>|null
     */
    public function observe(string $chunk): ?array
    {
        $event = json_decode(trim($chunk), true);
        if (! is_array($event)) {
            return null;
        }

        $type = (string) ($event['type'] ?? '');
        $content = $event['content'] ?? null;

        if (in_array($type, ['content', 'content_replace'], true) && is_string($content) && $content !== '') {
            $this->answerCommitted = true;
        }

        if (in_array($type, ['tool_call', 'tool_result', 'tool_error'], true)) {
            $this->toolExecutionCommitted = true;
        }

        if ($type === 'tool_call') {
            $toolCallId = $event['tool_call_id'] ?? null;
            $server = $this->boundedName($event['server'] ?? null);
            $tool = $this->boundedName($event['tool'] ?? null);
            if (is_string($toolCallId) && preg_match(self::TOOL_CALL_ID_PATTERN, $toolCallId) && $server !== null && $tool !== null) {
                $this->pendingTools[$toolCallId] = [
                    'tool_call_id' => $toolCallId,
                    'server' => $server,
                    'tool' => $tool,
                ];
            }
        } elseif (in_array($type, ['tool_result', 'tool_error'], true)) {
            $toolCallId = $event['tool_call_id'] ?? null;
            if (is_string($toolCallId)) {
                unset($this->pendingTools[$toolCallId]);
            }
        }

        if ($type === 'done') {
            $this->completed = true;
            if (is_string($content) && $content !== '') {
                $this->answerCommitted = true;
            }
        }

        if ($type === 'error') {
            $this->terminalError = true;
        }

        return $event;
    }

    public function canFallback(): bool
    {
        return ! $this->answerCommitted && ! $this->toolExecutionCommitted;
    }

    public function completedSuccessfully(): bool
    {
        return $this->completed && ! $this->terminalError;
    }

    public function commitKind(): ?string
    {
        if ($this->answerCommitted) {
            return 'answer_content';
        }

        return $this->toolExecutionCommitted ? 'tool_execution' : null;
    }

    /** @return list<array<string,mixed>> */
    public function drainPendingToolFailures(): array
    {
        $failures = array_map(static fn (array $tool): array => [
            'type' => 'tool_error',
            'tool_call_id' => $tool['tool_call_id'],
            'server' => $tool['server'],
            'tool' => $tool['tool'],
            'error_code' => 'tool_stream_interrupted',
            'raw_error_included' => false,
        ], array_values($this->pendingTools));
        $this->pendingTools = [];

        return $failures;
    }

    private function boundedName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 191 && ! preg_match('/[\x00-\x1F\x7F]/', $value)
            ? $value
            : null;
    }
}
