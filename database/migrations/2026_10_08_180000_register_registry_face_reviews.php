<?php

use App\Services\Review\RegistryFaceReviewService;
use App\Services\Review\ReviewDeskService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('review_type_registry')->updateOrInsert(['name' => RegistryFaceReviewService::TYPE], [
            'label' => 'Photo identification', 'icon' => 'user', 'category' => 'photos',
            'source_table' => 'file_registry_faces', 'source_connection' => 'mysql',
            // Main Review discovers current anonymous faces through its guarded photo projection.
            'count_sql' => 'SELECT 0 AS total', 'fetch_sql' => 'SELECT * FROM file_registry_faces WHERE 1 = 0',
            'field_mapping' => json_encode(['unified_id_template' => 'registry_face:{{id}}', 'id' => 'id', 'created_at' => 'created_at']),
            'actions' => json_encode(['skip', 'followup']), 'requires_image' => true, 'batch_enabled' => false,
            'service_class' => RegistryFaceReviewService::class, 'approve_method' => null, 'reject_method' => null,
            'display_order' => 46, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('review_desk_sources')->updateOrInsert(['name' => RegistryFaceReviewService::TYPE], [
            'source_table' => 'file_registry_faces', 'connection_name' => null, 'id_column' => 'id', 'status_column' => 'source_current',
            'pending_states' => json_encode(['1']), 'selection' => json_encode([]), 'discovery_mode' => 'on_demand',
            'source_url' => '/reviews?mode=photos', 'effect' => 'Stage a separate current-source person confirmation. Family tree membership is optional.',
            'blocker' => 'Person naming requires the explicit current-source proposal workflow.',
        ]);
        foreach (['skip', 'followup', 'reopen'] as $action) {
            DB::table('review_desk_policy')->updateOrInsert(['review_type' => RegistryFaceReviewService::TYPE, 'action' => $action], [
                'enabled' => true, 'bulk_enabled' => false, 'sensitive' => true,
                'service_class' => ReviewDeskService::class, 'method' => 'decide', 'conditions' => json_encode(['presentation_only' => true]),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('review_desk_policy')->where('review_type', RegistryFaceReviewService::TYPE)->delete();
        DB::table('review_desk_sources')->where('name', RegistryFaceReviewService::TYPE)->delete();
        DB::table('review_type_registry')->where('name', RegistryFaceReviewService::TYPE)->delete();
    }
};
