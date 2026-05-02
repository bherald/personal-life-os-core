<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'agent_pushover_receipt_poll'");
        if (!$existing) {
            DB::insert(
                "INSERT INTO scheduled_jobs (name, description, command, cron_expression, job_type, category, enabled, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    'agent_pushover_receipt_poll',
                    'Poll Pushover receipts for emergency-priority review acks',
                    'php artisan tinker --execute="echo json_encode(app(App\\Services\\AgentLoopService::class)->pollPushoverReceipts());"',
                    '*/16 * * * *',
                    'command',
                    'Agent',
                    1,
                    'Runs every 16 min. Only hits Pushover API when pending urgent reviews exist (typically rare). Most runs = 1 DB query + exit.',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'agent_pushover_receipt_poll'");
    }
};
