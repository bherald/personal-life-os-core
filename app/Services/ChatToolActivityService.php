<?php

namespace App\Services;

use App\Exceptions\ChatToolLifecycleException;

class ChatToolActivityService
{
    private const TOOL_CALL_ID_PATTERN = '/\Atool_[1-9]\d*_[1-9]\d*\z/D';

    /**
     * Project one stream event into the content-bounded tool activity ledger.
     *
     * @param  list<array<string, mixed>>  $activities
     * @param  array<string, mixed>  $event
     * @return list<array<string, mixed>>
     */
    public function apply(array $activities, array $event): array
    {
        $type = $event['type'] ?? null;
        $toolCallId = $event['tool_call_id'] ?? null;
        if (! is_string($type) || ! is_string($toolCallId) || ! preg_match(self::TOOL_CALL_ID_PATTERN, $toolCallId)) {
            throw new ChatToolLifecycleException('invalid_identity');
        }

        if ($type === 'tool_call') {
            $server = $this->boundedName($event['server'] ?? null);
            $tool = $this->boundedName($event['tool'] ?? null);
            if ($server === null || $tool === null) {
                throw new ChatToolLifecycleException('invalid_call');
            }
            if ($this->find($activities, $toolCallId) !== null) {
                throw new ChatToolLifecycleException('duplicate_call');
            }

            $activities[] = [
                'tool_call_id' => $toolCallId,
                'server' => $server,
                'tool' => $tool,
                'arguments' => is_array($event['arguments'] ?? null) ? $event['arguments'] : [],
                'status' => 'running',
            ];

            return array_values($activities);
        }

        if (! in_array($type, ['tool_result', 'tool_error'], true)) {
            throw new ChatToolLifecycleException('unsupported_event');
        }

        $index = $this->find($activities, $toolCallId);
        if ($index === null) {
            throw new ChatToolLifecycleException('orphan_terminal');
        }
        if (in_array($activities[$index]['status'] ?? null, ['succeeded', 'failed'], true)) {
            return array_values($activities);
        }

        $activities[$index]['status'] = $type === 'tool_result' ? 'succeeded' : 'failed';

        return array_values($activities);
    }

    /** @param list<array<string, mixed>> $activities */
    private function find(array $activities, string $toolCallId): ?int
    {
        foreach ($activities as $index => $activity) {
            if (($activity['tool_call_id'] ?? null) === $toolCallId) {
                return $index;
            }
        }

        return null;
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
