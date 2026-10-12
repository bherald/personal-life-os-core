<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ChatResponseFeedbackService
{
    public const VALUES = ['helpful', 'unhelpful'];

    public const REASONS = [
        'incorrect',
        'incomplete',
        'ignored_instruction',
        'retry_after_failure',
        'unrequested_action',
        'expressed_frustration',
        'other',
    ];

    /** @return array<string, mixed> */
    public function record(int $conversationId, int $messageId, string $value, ?string $reason = null): array
    {
        $value = strtolower(trim($value));
        $reason = $reason === null || trim($reason) === '' ? null : strtolower(trim($reason));

        if (! in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException('Unsupported feedback value.');
        }
        if ($value === 'helpful') {
            $reason = null;
        } elseif ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unsupported feedback reason.');
        }

        $message = DB::selectOne(
            'SELECT id FROM chat_messages WHERE id = ? AND conversation_id = ? AND role = ? LIMIT 1',
            [$messageId, $conversationId, 'assistant']
        );
        if ($message === null) {
            throw new InvalidArgumentException('Assistant message not found in this conversation.');
        }

        $now = now();
        DB::table('chat_response_feedback')->upsert(
            [[
                'conversation_id' => $conversationId,
                'chat_message_id' => $messageId,
                'value' => $value,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['chat_message_id'],
            ['conversation_id', 'value', 'reason', 'updated_at']
        );

        return [
            'value' => $value,
            'reason' => $reason,
            'recorded_at' => $now->toIso8601String(),
        ];
    }

    /** @param array<int, object> $messages
     * @return array<int, object>
     */
    public function appendToMessages(array $messages): array
    {
        if (! Schema::hasTable('chat_response_feedback')) {
            return $messages;
        }

        $messageIds = array_values(array_filter(array_map(
            static fn (object $message): ?int => isset($message->id) ? (int) $message->id : null,
            $messages
        )));
        if ($messageIds === []) {
            return $messages;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $rows = DB::select(
            "SELECT chat_message_id, value, reason, updated_at FROM chat_response_feedback WHERE chat_message_id IN ({$placeholders})",
            $messageIds
        );
        $byMessage = [];
        foreach ($rows as $row) {
            $byMessage[(int) $row->chat_message_id] = [
                'value' => (string) $row->value,
                'reason' => $row->reason === null ? null : (string) $row->reason,
                'recorded_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        }

        foreach ($messages as $message) {
            $message->response_feedback = $byMessage[(int) ($message->id ?? 0)] ?? null;
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    public function aggregate(int $windowDays = 30): array
    {
        if (! Schema::hasTable('chat_response_feedback')) {
            return [
                'available' => false,
                'window_days' => $windowDays,
                'feedback_total' => 0,
                'helpful_total' => 0,
                'unhelpful_total' => 0,
                'helpful_rate' => null,
                'reason_counts' => [],
                'latest_feedback_at' => null,
                'raw_content_included' => false,
            ];
        }

        $cutoff = now()->subDays($windowDays);
        $summary = DB::selectOne(
            'SELECT COUNT(*) AS feedback_total,
                    SUM(CASE WHEN value = ? THEN 1 ELSE 0 END) AS helpful_total,
                    SUM(CASE WHEN value = ? THEN 1 ELSE 0 END) AS unhelpful_total,
                    MAX(updated_at) AS latest_feedback_at
             FROM chat_response_feedback WHERE updated_at >= ?',
            ['helpful', 'unhelpful', $cutoff]
        );
        $reasons = DB::select(
            'SELECT reason, COUNT(*) AS aggregate_count
             FROM chat_response_feedback
             WHERE updated_at >= ? AND value = ? AND reason IS NOT NULL
             GROUP BY reason ORDER BY aggregate_count DESC, reason ASC',
            [$cutoff, 'unhelpful']
        );

        $total = (int) ($summary->feedback_total ?? 0);
        $helpful = (int) ($summary->helpful_total ?? 0);

        return [
            'available' => true,
            'window_days' => $windowDays,
            'feedback_total' => $total,
            'helpful_total' => $helpful,
            'unhelpful_total' => (int) ($summary->unhelpful_total ?? 0),
            'helpful_rate' => $total > 0 ? round($helpful / $total, 4) : null,
            'reason_counts' => collect($reasons)->mapWithKeys(
                static fn (object $row): array => [(string) $row->reason => (int) $row->aggregate_count]
            )->all(),
            'latest_feedback_at' => $summary->latest_feedback_at ?? null,
            'raw_content_included' => false,
        ];
    }
}
