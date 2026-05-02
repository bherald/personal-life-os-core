<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D1 + D2: Drop empty email operational tables and data removal operational tables.
 *
 * Decision context:
 * - D1: Thunderbird MCP is the email solution. AI assists via MCP hooks.
 *        These 18 tables replicate email state that Thunderbird already manages.
 * - D2: Data removal uses MCP email approach (AI drafts → human approves → Thunderbird sends).
 *        These 20 operational tables were never populated. Reference tables (data_brokers,
 *        data_subjects, removal_requests) are KEPT.
 *
 * All 38 tables have 0 rows on production. FK dependencies are only between
 * tables in the drop set (no external tables reference these).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks for clean drop order
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        // === D1: Email operational tables (18) ===
        $emailTables = [
            'email_analytics',
            'email_attachments',
            'email_bounces',
            'email_draft_versions',
            'email_follow_ups',
            'email_messages',
            'email_queue',
            'email_rate_limits',
            'email_recurring_schedules',
            'email_retry_queue',
            'email_rules',
            'email_scheduled',
            'email_shipments',
            'email_suggestions',
            'email_suppression_list',
            'email_threads',
            'email_unsubscribe_links',
            'attachment_virus_scans',
        ];

        // === D2: Data removal operational tables (20) ===
        $dataRemovalTables = [
            'broker_discovery_queue',
            'broker_verification_requirements',
            'data_removal_captcha_queue',
            'data_removal_state_requests',
            'data_removal_suppression_archive',
            'data_removal_suppression_list',
            'removal_activity_log',
            'removal_effectiveness',
            'removal_proof_archive',
            'removal_proof_audit',
            'removal_verification_schedule',
            'subject_name_variants',
            'identity_verification_audit',
            'identity_verification_documents',
            'identity_verification_redactions',
            'identity_verification_tokens',
            'breach_records',
            'stealth_fingerprints',
            'compensation_log',
            'compensation_log_nodes',
        ];

        $allTables = array_merge($emailTables, $dataRemovalTables);

        foreach ($allTables as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(): void
    {
        // These tables were empty and their schemas exist in prior migrations.
        // To restore, re-run the original creation migrations.
        // This is intentionally a no-op — the tables had 0 rows.
    }
};
