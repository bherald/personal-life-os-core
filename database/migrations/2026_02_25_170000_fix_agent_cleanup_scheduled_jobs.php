<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix agent cleanup scheduled jobs to use proper artisan commands
     * instead of tinker hacks that break on quote parsing.
     */
    public function up(): void
    {
        // Fix agent_cleanup_messages: use artisan command instead of tinker
        DB::update(
            "UPDATE scheduled_jobs SET command = ?, updated_at = NOW() WHERE name = ?",
            ['php artisan agent:cleanup --messages', 'agent_cleanup_messages']
        );

        // Fix agent_expire_reviews: use artisan command instead of tinker
        DB::update(
            "UPDATE scheduled_jobs SET command = ?, updated_at = NOW() WHERE name = ?",
            ['php artisan agent:cleanup --reviews', 'agent_expire_reviews']
        );
    }

    public function down(): void
    {
        // Restore original tinker commands
        DB::update(
            "UPDATE scheduled_jobs SET command = ?, updated_at = NOW() WHERE name = ?",
            [
                'php artisan tinker --execute="echo app(App\\Services\\AgentLoopService::class)->cleanupExpiredMessages() . \' deleted\';"',
                'agent_cleanup_messages',
            ]
        );

        DB::update(
            "UPDATE scheduled_jobs SET command = ?, updated_at = NOW() WHERE name = ?",
            [
                'php artisan tinker --execute="echo app(App\\Services\\AgentLoopService::class)->expirePendingReviews() . \' expired\';"',
                'agent_expire_reviews',
            ]
        );
    }
};
