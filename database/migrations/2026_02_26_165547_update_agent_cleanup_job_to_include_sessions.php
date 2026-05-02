<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Update agent cleanup scheduled job to run all cleanup operations (messages + reviews + sessions).
     * Also one-time fix: mark orphaned active sessions as completed.
     */
    public function up(): void
    {
        // Update existing cleanup job to use --all (messages + reviews + sessions)
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'agent:cleanup --all',
                name = 'agent_cleanup_all',
                description = 'Clean up expired agent messages, reviews, and sessions'
            WHERE name = 'agent_cleanup_messages'
        ");

        // One-time fix: mark all orphaned active sessions as completed
        $cleaned = DB::update("
            UPDATE agent_sessions
            SET status = 'completed', updated_at = NOW()
            WHERE status = 'active'
              AND last_activity_at < NOW() - INTERVAL 2 HOUR
        ");

        if ($cleaned > 0) {
            Log::info("Migration: Cleaned up {$cleaned} orphaned active agent sessions");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'agent:cleanup --messages',
                name = 'agent_cleanup_messages',
                description = NULL
            WHERE name = 'agent_cleanup_all'
        ");
    }
};
