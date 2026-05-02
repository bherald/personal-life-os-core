<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix privacy review type fetch_sql to include JOINs for broker_name and subject_name.
 *
 * Previously the fetch_sql only selected raw IDs without joining the broker/subject tables,
 * so the "Subject:" and broker fields in the review card were always blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixedFetchSql = "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes as summary, r.review_notes, r.created_at,
                b.name as broker_name, b.url as broker_url,
                s.name as subject_name
         FROM removal_requests r
         LEFT JOIN data_removal_brokers b ON b.id = r.broker_id
         LEFT JOIN data_removal_subjects s ON s.id = r.subject_id
         WHERE r.requires_review = 1 AND r.status = 'pending'
         ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100";

        DB::table('review_type_registry')
            ->where('name', 'privacy')
            ->update(['fetch_sql' => $fixedFetchSql]);
    }

    public function down(): void
    {
        $originalFetchSql = "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes, r.review_notes, r.created_at, r.broker_id, r.subject_id
         FROM removal_requests r
         WHERE r.requires_review = 1 AND r.status = 'pending'
         ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100";

        DB::table('review_type_registry')
            ->where('name', 'privacy')
            ->update(['fetch_sql' => $originalFetchSql]);
    }
};
