<?php

use App\Services\Files\ScanDateEstimateReviewDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_desk_sources') || ! Schema::hasTable('review_desk_policy')) {
            return;
        }
        DB::table('review_desk_sources')->insertOrIgnore([
            'name' => 'scan_date_estimate', 'source_table' => 'file_registry', 'connection_name' => null,
            'id_column' => 'id', 'status_column' => 'status', 'pending_states' => '["active"]',
            'source_url' => '/reviews/source', 'effect' => 'Accept the catalog estimate or explicitly approve a source-pinned approximate year write.',
            'blocker' => 'Choose one individual date outcome; physical writes require current source proof.',
            'discovery_mode' => 'queue', 'selection' => json_encode([
                'is_scan' => [1], 'date_needs_review' => [1],
                'date_taken_source' => ['ai_visual_high', 'ai_visual_medium', 'ai_visual_low'],
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach (['approve', 'reject'] as $action) {
            DB::table('review_desk_policy')->insertOrIgnore([
                'review_type' => 'scan_date_estimate', 'action' => $action, 'enabled' => true,
                'bulk_enabled' => false, 'sensitive' => true, 'service_class' => ScanDateEstimateReviewDecisionService::class,
                'method' => $action, 'conditions' => json_encode(['operator_only' => true, 'current_source_required' => true], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve registrations, receipt history and operator-owned overrides.
        // Code rollback removes admission; do not erase historical authorizations.
    }
};
