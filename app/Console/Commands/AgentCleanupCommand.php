<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class AgentCleanupCommand extends Command
{
    protected $signature = 'agent:cleanup
        {--messages : Clean up expired agent messages}
        {--reviews : Expire pending reviews past TTL}
        {--sessions : Clean up stale/expired agent sessions}
        {--all : Run all cleanup operations (default if no flags)}';

    protected $description = 'Clean up expired agent messages, reviews, and sessions';

    public function handle(AgentLoopService $service): int
    {
        $noFlags = !$this->option('messages') && !$this->option('reviews') && !$this->option('sessions');
        $runAll = $this->option('all') || $noFlags;

        if ($this->option('messages') || $runAll) {
            $deleted = $service->cleanupExpiredMessages();
            $this->info("Cleaned up {$deleted} expired agent messages.");
        }

        if ($this->option('reviews') || $runAll) {
            $expired = $service->expirePendingReviews();
            $this->info("Expired {$expired} pending review items.");

            // AG-23: Clean up stale skill optimization reviews (rejected/expired > 14 days)
            $stale = app(\App\Services\SkillOptimizationService::class)->cleanupStaleReviews();
            $this->info("Cleaned up {$stale} stale skill optimization reviews.");
        }

        if ($this->option('sessions') || $runAll) {
            $sessionService = app(\App\Services\AgentSessionService::class);
            $cleaned = $sessionService->cleanupExpiredSessions();
            $this->info("Cleaned up {$cleaned} expired/stale agent sessions.");
        }

        return 0;
    }
}
