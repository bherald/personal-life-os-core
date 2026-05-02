<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D1+D2: Remove agent tool registry entries for deleted email/data-removal services.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deadTools = [
            'email_analytics_dashboard',
            'email_bounce_stats',
            'email_followup_stats',
            'email_urgent_emails',
            'email_sentiment_stats',
            'email_overdue_followups',
            'email_process_reminders',
            'email_pending_retries',
            'email_unsubscribeable_senders',
            'email_scheduled_upcoming',
        ];

        DB::delete(
            "DELETE FROM agent_tool_registry WHERE name IN ('" . implode("','", $deadTools) . "')"
        );
    }

    public function down(): void
    {
        // Tools can be re-inserted from the original migration if needed
    }
};
