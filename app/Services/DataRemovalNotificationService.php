<?php

namespace App\Services;

use App\Controllers\NotificationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data Removal Notification Service
 *
 * Sends notifications for data removal events.
 * Uses Pushover for urgent alerts and supports email digests.
 *
 * E06: Personal Data Removal System
 */
class DataRemovalNotificationService
{
    private NotificationController $pushover;

    /**
     * Notification priority levels
     */
    private const PRIORITY_SILENT = -2;

    private const PRIORITY_QUIET = -1;

    private const PRIORITY_NORMAL = 0;

    private const PRIORITY_HIGH = 1;

    private const PRIORITY_EMERGENCY = 2;

    public function __construct()
    {
        $this->pushover = new NotificationController;
    }

    // ========================================
    // ALERT NOTIFICATIONS
    // ========================================

    /**
     * Send alert when new data is discovered on a broker
     */
    public function alertDataDiscovered(object $request, object $broker, object $subject): bool
    {
        $title = "🔍 Data Found: {$broker->name}";
        $message = "Personal data for {$subject->name} was found on {$broker->domain}.\n\n";
        $message .= 'Automation Tier: '.$this->getTierLabel($broker->automation_tier)."\n";
        $message .= 'Removal Method: '.ucfirst($broker->removal_method ?? 'Unknown');

        if ($broker->automation_tier >= 3) {
            $message .= "\n\n⚠️ Manual action required for this broker.";
        }

        return $this->sendPushover($title, $message, self::PRIORITY_NORMAL);
    }

    /**
     * Send alert when removal is verified successful
     */
    public function alertRemovalVerified(object $request, object $broker, object $subject): bool
    {
        $title = "✅ Removal Verified: {$broker->name}";
        $message = "Personal data for {$subject->name} has been successfully removed from {$broker->domain}.\n\n";

        $daysToRemove = $this->calculateDaysToRemove($request);
        if ($daysToRemove > 0) {
            $message .= "Time to removal: {$daysToRemove} days";
        }

        return $this->sendPushover($title, $message, self::PRIORITY_QUIET);
    }

    /**
     * Send alert when removal fails
     */
    public function alertRemovalFailed(object $request, object $broker, object $subject, string $reason): bool
    {
        $title = "❌ Removal Failed: {$broker->name}";
        $message = "Failed to remove data for {$subject->name} from {$broker->domain}.\n\n";
        $message .= "Reason: {$reason}\n\n";
        $message .= 'This request requires manual review.';

        return $this->sendPushover($title, $message, self::PRIORITY_HIGH);
    }

    /**
     * Send alert when data reappears after removal
     */
    public function alertDataReappeared(object $request, object $broker, object $subject): bool
    {
        $title = "⚠️ Data Reappeared: {$broker->name}";
        $message = "Personal data for {$subject->name} has reappeared on {$broker->domain} after previous removal.\n\n";
        $message .= 'A new removal request will be initiated.';

        return $this->sendPushover($title, $message, self::PRIORITY_HIGH);
    }

    /**
     * Send alert when CAPTCHA requires manual solving
     */
    public function alertCaptchaQueued(object $request, object $broker, string $captchaType): bool
    {
        $title = "🔐 CAPTCHA Required: {$broker->name}";
        $message = "A {$captchaType} CAPTCHA needs to be solved for {$broker->domain}.\n\n";
        $message .= 'Please access the CAPTCHA queue to resolve this manually.';

        return $this->sendPushover($title, $message, self::PRIORITY_NORMAL);
    }

    /**
     * Send alert when request is escalated after max follow-ups
     */
    public function alertRequestEscalated(object $request, object $broker, object $subject, int $followupCount): bool
    {
        $title = "🚨 Escalated: {$broker->name}";
        $message = "Removal request for {$subject->name} on {$broker->domain} has been escalated.\n\n";
        $message .= "After {$followupCount} follow-ups, no response has been received.\n\n";
        $message .= 'Consider filing a formal complaint with regulatory authorities.';

        return $this->sendPushover($title, $message, self::PRIORITY_HIGH);
    }

    // ========================================
    // DIGEST NOTIFICATIONS
    // ========================================

    /**
     * Send daily digest summary
     */
    public function sendDailyDigest(bool $force = false): bool
    {
        $stats = $this->getDailyStats();

        if (! $force && $this->shouldSkipDigest($stats)) {
            return true; // Nothing notable to report
        }

        $title = '📊 Data Removal Daily Digest';
        $message = $this->formatDigestMessage($stats);

        return $this->sendPushover($title, $message, self::PRIORITY_QUIET);
    }

    /**
     * Get stats for daily digest
     */
    private function getDailyStats(): array
    {
        $since = now()->subDay()->toDateTimeString();

        // New discoveries
        $newDiscoveries = DB::select('
            SELECT COUNT(*) as count FROM removal_requests
            WHERE first_discovered_at >= ?
        ', [$since])[0]->count ?? 0;

        // Successful removals
        $verified = DB::select('
            SELECT COUNT(*) as count FROM removal_requests
            WHERE verified_removed_at >= ?
        ', [$since])[0]->count ?? 0;

        // Failed requests
        $failed = DB::select("
            SELECT COUNT(*) as count FROM removal_requests
            WHERE status = 'failed'
            AND updated_at >= ?
        ", [$since])[0]->count ?? 0;

        // Pending requiring action
        $pending = DB::select("
            SELECT COUNT(*) as count FROM removal_requests
            WHERE status IN ('pending', 'submitted')
            AND requires_review = 1
        ")[0]->count ?? 0;

        // CAPTCHA queue — data_removal_captcha_queue dropped (D2 decision). Stub.
        $captchaQueue = 0;

        // Overall stats
        $totalActive = DB::select("
            SELECT COUNT(*) as count FROM removal_requests
            WHERE status NOT IN ('verified_removed', 'failed', 'cancelled')
        ")[0]->count ?? 0;

        $totalRemoved = DB::select("
            SELECT COUNT(*) as count FROM removal_requests
            WHERE status = 'verified_removed'
        ")[0]->count ?? 0;

        return [
            'new_discoveries' => $newDiscoveries,
            'verified' => $verified,
            'failed' => $failed,
            'pending_review' => $pending,
            'captcha_queue' => $captchaQueue,
            'total_active' => $totalActive,
            'total_removed' => $totalRemoved,
        ];
    }

    /**
     * Check if digest should be skipped (no notable activity)
     */
    private function shouldSkipDigest(array $stats): bool
    {
        return $stats['new_discoveries'] === 0
            && $stats['verified'] === 0
            && $stats['failed'] === 0
            && $stats['pending_review'] === 0
            && $stats['captcha_queue'] === 0;
    }

    /**
     * Format digest message
     */
    private function formatDigestMessage(array $stats): string
    {
        $message = "Today's Activity:\n";
        $message .= "• New discoveries: {$stats['new_discoveries']}\n";
        $message .= "• Verified removed: {$stats['verified']}\n";
        $message .= "• Failed: {$stats['failed']}\n\n";

        $message .= "Requires Attention:\n";
        $message .= "• Pending review: {$stats['pending_review']}\n";
        $message .= "• CAPTCHA queue: {$stats['captcha_queue']}\n\n";

        $message .= "Overall:\n";
        $message .= "• Active requests: {$stats['total_active']}\n";
        $message .= "• Total removed: {$stats['total_removed']}";

        return $message;
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Send notification via Pushover
     */
    private function sendPushover(string $title, string $message, int $priority = 0): bool
    {
        try {
            $result = $this->pushover->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'sound' => $priority >= 1 ? 'siren' : 'pushover',
            ]);

            return ! empty($result);

        } catch (\Exception $e) {
            Log::error('DataRemovalNotificationService: Failed to send Pushover', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get human-readable tier label
     */
    private function getTierLabel(int $tier): string
    {
        return match ($tier) {
            1 => 'Tier 1 (Fully Automated)',
            2 => 'Tier 2 (AI Review)',
            3 => 'Tier 3 (Manual)',
            default => "Tier {$tier}",
        };
    }

    /**
     * Calculate days from discovery to removal
     */
    private function calculateDaysToRemove(object $request): int
    {
        if (empty($request->first_discovered_at) || empty($request->verified_removed_at)) {
            return 0;
        }

        return now()->parse($request->first_discovered_at)
            ->diffInDays(now()->parse($request->verified_removed_at));
    }
}
