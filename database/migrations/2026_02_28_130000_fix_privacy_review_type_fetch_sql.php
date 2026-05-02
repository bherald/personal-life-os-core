<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix privacy review type fetch_sql — references data_removal_brokers and
        // data_removal_subjects tables that don't exist on prod
        DB::table('review_type_registry')
            ->where('name', 'privacy')
            ->update([
                'fetch_sql' => "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes, r.review_notes, r.created_at, r.broker_id, r.subject_id FROM removal_requests r WHERE r.requires_review = 1 AND r.status = 'pending' ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100",
            ]);
    }

    public function down(): void
    {
        DB::table('review_type_registry')
            ->where('name', 'privacy')
            ->update([
                'fetch_sql' => "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes, r.review_notes, r.created_at, b.name as broker_name, b.url as broker_url, s.name as subject_name FROM removal_requests r LEFT JOIN data_removal_brokers b ON b.id = r.broker_id LEFT JOIN data_removal_subjects s ON s.id = r.subject_id WHERE r.requires_review = 1 AND r.status = 'pending' ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100",
            ]);
    }
};
