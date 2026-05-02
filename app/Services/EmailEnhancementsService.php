<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Email Enhancements Service
 *
 * Provides draft version history, scheduled sending,
 * attachment management, and analytics tracking.
 */
class EmailEnhancementsService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    // =========================================================================
    // DRAFT VERSION HISTORY
    // =========================================================================

    // email_draft_versions, email_scheduled, email_attachments, email_analytics tables
    // dropped (D1 decision). All methods below are stubbed until rebuilt.

    public function saveDraftVersion(int $draftId, string $content, string $changedBy = 'human', string $changeType = 'edited'): int
    {
        // email_draft_versions table dropped (D1 decision). Stub.
        return 0;
    }

    public function getDraftHistory(int $draftId): array
    {
        // email_draft_versions table dropped (D1 decision). Stub.
        return [];
    }

    public function getDraftDiff(int $draftId, int $v1, int $v2): array
    {
        // email_draft_versions table dropped (D1 decision). Stub.
        return ['error' => 'D1 decision: email_draft_versions table dropped'];
    }

    private function computeDiffSummary(string $old, string $new): string
    {
        $oldLen = strlen($old);
        $newLen = strlen($new);
        $diff = $newLen - $oldLen;
        $oldWords = str_word_count($old);
        $newWords = str_word_count($new);

        $parts = [];
        if ($diff > 0) {
            $parts[] = "+{$diff} chars";
        } elseif ($diff < 0) {
            $parts[] = "{$diff} chars";
        }
        $wordDiff = $newWords - $oldWords;
        if ($wordDiff !== 0) {
            $sign = $wordDiff > 0 ? '+' : '';
            $parts[] = "{$sign}{$wordDiff} words";
        }

        return implode(', ', $parts) ?: 'no change';
    }

    // =========================================================================
    // SCHEDULED SENDING
    // =========================================================================

    public function scheduleSend(int $draftId, string $sendAt, ?string $timezone = null, ?string $recurringPattern = null): bool
    {
        // email_scheduled table dropped (D1 decision). Stub.
        return false;
    }

    public function processScheduledEmails(): array
    {
        // email_scheduled table dropped (D1 decision). Stub.
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
    }

    public function cancelScheduled(int $id): bool
    {
        // email_scheduled table dropped (D1 decision). Stub.
        return false;
    }

    public function getScheduledEmails(): array
    {
        // email_scheduled table dropped (D1 decision). Stub.
        return [];
    }

    private function calculateNextSend(string $pattern, string $timezone): string
    {
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);

        switch ($pattern) {
            case 'daily':
                $now->modify('+1 day');
                break;
            case 'weekly':
                $now->modify('+1 week');
                break;
            case 'monthly':
                $now->modify('+1 month');
                break;
            case 'weekdays':
                do {
                    $now->modify('+1 day');
                } while (in_array($now->format('N'), ['6', '7']));
                break;
            default:
                // Cron-like patterns could be added later
                $now->modify('+1 day');
        }

        return $now->format('Y-m-d H:i:s');
    }

    // =========================================================================
    // ATTACHMENT MANAGEMENT
    // =========================================================================

    public function handleAttachment(int $draftId, array $file): array
    {
        // email_attachments table dropped (D1 decision). Stub.
        return ['success' => false, 'error' => 'D1 decision: email_attachments table dropped'];
    }

    public function getAttachments(int $draftId): array
    {
        // email_attachments table dropped (D1 decision). Stub.
        return [];
    }

    public function deleteAttachment(int $attachmentId): bool
    {
        // email_attachments table dropped (D1 decision). Stub.
        return false;
    }

    // =========================================================================
    // ANALYTICS
    // =========================================================================

    public function recordMetric(string $type, int $value = 1, ?array $metadata = null): void
    {
        // email_analytics table dropped (D1 decision). Stub — no-op.
    }

    public function getAnalytics(string $startDate, string $endDate, ?array $metricTypes = null): array
    {
        // email_analytics table dropped (D1 decision). Stub.
        return [];
    }

    public function getAIAccuracyStats(): array
    {
        // Compare AI-generated drafts vs final sent content
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_ai_drafts,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_as_is,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'sent' AND updated_at > created_at THEN 1 ELSE 0 END) as sent_with_edits
            FROM email_reply_drafts
            WHERE source = 'ai_reply'
        ");

        $total = $stats->total_ai_drafts ?? 0;

        return [
            'total_ai_drafts' => $total,
            'sent_without_edits' => $stats->sent_as_is ?? 0,
            'sent_with_edits' => $stats->sent_with_edits ?? 0,
            'rejected' => $stats->rejected ?? 0,
            'approval_rate' => $total > 0 ? round((($stats->sent_as_is + $stats->sent_with_edits) / $total) * 100, 1) : 0,
        ];
    }

    public function getDailyStats(int $days = 30): array
    {
        // email_analytics table dropped (D1 decision). Stub.
        return [];
    }

    public function getOverviewStats(): array
    {
        $draftStats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM email_reply_drafts
        ");

        // email_scheduled and email_attachments tables dropped (D1 decision).
        return [
            'drafts' => $draftStats,
            'scheduled_pending' => 0,
            'attachments' => ['count' => 0, 'total_size_bytes' => 0],
            'ai_accuracy' => $this->getAIAccuracyStats(),
        ];
    }
}
