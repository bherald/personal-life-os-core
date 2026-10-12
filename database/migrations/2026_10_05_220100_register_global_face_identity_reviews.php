<?php

use App\Services\Review\GlobalFaceIdentityReviewService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('review_type_registry')->updateOrInsert(['name' => GlobalFaceIdentityReviewService::REVIEW_TYPE], [
            'label' => 'Global person registry', 'icon' => 'user', 'category' => 'photos',
            'source_table' => 'agent_review_queue', 'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) AS total FROM agent_review_queue WHERE review_type = 'global_face_identity' AND status = 'pending' AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)",
            'fetch_sql' => "SELECT * FROM agent_review_queue WHERE review_type = 'global_face_identity' AND status = 'pending' AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP) ORDER BY created_at ASC LIMIT 100",
            'field_mapping' => json_encode(['unified_id_template' => 'global_face_identity:{{id}}', 'id' => 'id',
                'title' => 'title', 'summary' => 'summary', 'details_json' => 'details', 'created_at' => 'created_at'], JSON_THROW_ON_ERROR),
            'actions' => json_encode(['approve', 'reject']), 'requires_image' => false, 'batch_enabled' => false,
            'service_class' => GlobalFaceIdentityReviewService::class, 'approve_method' => 'approve', 'reject_method' => 'reject',
            'display_order' => 45, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['approve', 'reject'] as $action) {
            DB::table('review_desk_policy')->updateOrInsert(['review_type' => GlobalFaceIdentityReviewService::REVIEW_TYPE, 'action' => $action], [
                'enabled' => true, 'bulk_enabled' => false, 'sensitive' => true,
                'service_class' => GlobalFaceIdentityReviewService::class, 'method' => $action,
                'conditions' => json_encode(['operator_only' => true, 'registry_only' => true, 'face_association' => false]),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('review_desk_policy')->where('review_type', GlobalFaceIdentityReviewService::REVIEW_TYPE)->delete();
        DB::table('review_type_registry')->where('name', GlobalFaceIdentityReviewService::REVIEW_TYPE)->delete();
    }
};
