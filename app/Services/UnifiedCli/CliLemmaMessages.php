<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Str;
use RuntimeException;

/** Session-addressed private messages in the existing Lemma pod. */
final class CliLemmaMessages
{
    public function __construct(
        private readonly string $sessionId,
        private readonly array $definition,
        private readonly string $root,
        private readonly string $node,
        private readonly int $timeoutMs,
    ) {}

    public function send(array $arguments, string $messageKey): array
    {
        $to = $arguments['to_session'] ?? null;
        $subject = $arguments['subject'] ?? null;
        $body = $arguments['body'] ?? null;
        $task = $arguments['task_key'] ?? null;
        if (! self::uuid($to) || ! is_string($subject) || trim($subject) === '' || mb_strlen($subject) > 160
            || ! is_string($body) || trim($body) === '' || mb_strlen($body) > 4000
            || ($task !== null && (! is_string($task) || mb_strlen($task) > 160))
            || ! preg_match('/^[a-zA-Z0-9:._-]{1,96}$/D', $messageKey)) {
            throw new RuntimeException('Message needs a recipient session UUID, subject and body within pod limits.');
        }
        $data = ['message_key' => $messageKey, 'from_session' => $this->sessionId,
            'to_session' => $to, 'subject' => trim($subject), 'body' => $body];
        if ($task !== null && $task !== '') {
            $data['task_key'] = $task;
        }
        $existing = $this->byKey($messageKey);
        if ($existing !== null) {
            return $this->matched($existing, $data, true);
        }
        try {
            $this->mcp('lemma_pod_write_record', ['action' => 'create', 'table_name' => 'agent_messages', 'data' => $data]);
        } catch (\Throwable) {
            $existing = $this->byKey($messageKey);
            if ($existing === null) {
                throw new CliToolOutcomeUnknown('Lemma message outcome is unknown; inspect its message key before retrying.');
            }
        }
        $existing = $this->byKey($messageKey);
        if ($existing === null) {
            throw new CliToolOutcomeUnknown('Lemma message has no verified read-back; do not resend automatically.');
        }

        return $this->matched($existing, $data, false);
    }

    public function inbox(bool $unreadOnly = true, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Inbox limit must be 1 to 50.');
        }
        $records = [];
        $pageSize = 50;
        for ($page = 0; $page < 20; $page++) {
            $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_messages',
                'filters' => [['column' => 'to_session', 'op' => 'eq', 'value' => $this->sessionId]],
                'sorts' => [['column' => 'created_at', 'direction' => 'desc']],
                'limit' => $pageSize, 'offset' => $page * $pageSize]);
            $batch = $result['records'] ?? null;
            if (! is_array($batch)) {
                throw new RuntimeException('Lemma inbox result is incomplete.');
            }
            foreach ($batch as $record) {
                if (! $unreadOnly || empty($record['read_at'])) {
                    $records[] = $record;
                }
                if (count($records) >= $limit) {
                    break 2;
                }
            }
            if (count($batch) < $pageSize) {
                break;
            }
        }

        return ['session_id' => $this->sessionId, 'messages' => $records,
            'count' => count($records), 'truncated' => $page === 20];
    }

    public function read(string $messageId, bool $markRead = true): array
    {
        if (! self::uuid($messageId)) {
            throw new RuntimeException('Message ID must be a UUID.');
        }
        $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_messages', 'record_id' => $messageId]);
        $record = $result['record'] ?? ($result['records'][0] ?? null);
        if (! is_array($record) || ($record['to_session'] ?? null) !== $this->sessionId) {
            throw new RuntimeException('Message is unavailable to this session.');
        }
        if ($markRead && empty($record['read_at'])) {
            $this->mcp('lemma_pod_write_record', ['action' => 'update', 'table_name' => 'agent_messages',
                'record_id' => $messageId, 'data' => ['read_at' => gmdate('Y-m-d\TH:i:s\Z')]]);
            $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_messages', 'record_id' => $messageId]);
            $record = $result['record'] ?? ($result['records'][0] ?? null);
            if (! is_array($record) || empty($record['read_at'])) {
                throw new CliToolOutcomeUnknown('Message read receipt has no verified read-back.');
            }
        }

        return $record;
    }

    private function byKey(string $key): ?array
    {
        $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_messages',
            'filters' => [['column' => 'message_key', 'op' => 'eq', 'value' => $key]], 'limit' => 2]);
        $records = $result['records'] ?? [];
        if (! is_array($records) || count($records) > 1) {
            throw new RuntimeException('Lemma message key is not unique.');
        }

        return $records[0] ?? null;
    }

    private function matched(array $record, array $data, bool $existing): array
    {
        foreach ($data as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                throw new RuntimeException('Existing Lemma message key has different content.');
            }
        }

        return ['message_id' => $record['id'], 'message_key' => $data['message_key'],
            'from_session' => $this->sessionId, 'to_session' => $data['to_session'],
            'created_at' => $record['created_at'] ?? null, 'already_sent' => $existing];
    }

    private function mcp(string $tool, array $arguments): array
    {
        return CliLemmaPodMcpService::result($tool, $arguments, $this->definition,
            $this->root, $this->node, $this->timeoutMs);
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }
}
