<?php

use App\Services\Files\ScanDateEstimateReviewDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_desk_policy') || ! Schema::hasTable('review_desk_sources')) {
            return;
        }

        DB::table('review_desk_policy')->insertOrIgnore([
            'review_type' => 'scan_date_estimate',
            'action' => 'retry_scan_year_write',
            'enabled' => true,
            'bulk_enabled' => false,
            'sensitive' => true,
            'service_class' => ScanDateEstimateReviewDecisionService::class,
            'method' => 'retryApprovedWriteback',
            'conditions' => json_encode([
                'operator_only' => true,
                'original_authorization_required' => true,
                'current_source_required' => true,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(): void
    {
        // Preserve operator overrides and all retry receipts during code rollback.
    }
};
