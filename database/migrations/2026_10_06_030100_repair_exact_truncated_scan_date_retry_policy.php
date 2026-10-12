<?php

use App\Services\Files\ScanDateEstimateReviewDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION = 'retry_scan_year_write';

    private const TRUNCATED_ACTION = 'retry_scan_estimated_year_writ';

    private function conditions(): string
    {
        return json_encode([
            'operator_only' => true,
            'original_authorization_required' => true,
            'current_source_required' => true,
        ], JSON_THROW_ON_ERROR);
    }

    public function up(): void
    {
        if (! Schema::hasTable('review_desk_policy') || ! Schema::hasTable('review_desk_sources')) {
            return;
        }

        DB::transaction(function (): void {
            $query = DB::table('review_desk_policy')->where('review_type', 'scan_date_estimate')
                ->where('action', self::TRUNCATED_ACTION);
            $truncated = (clone $query)->lockForUpdate()->first();
            if ($truncated && $truncated->review_type === 'scan_date_estimate'
                && $truncated->action === self::TRUNCATED_ACTION
                && (bool) $truncated->enabled && ! (bool) $truncated->bulk_enabled
                && (bool) $truncated->sensitive
                && $truncated->service_class === ScanDateEstimateReviewDecisionService::class
                && $truncated->method === 'retryApprovedWriteback'
                && $truncated->conditions === $this->conditions()) {
                $query->update(['enabled' => false]);
            }

            DB::table('review_desk_policy')->insertOrIgnore([
                'review_type' => 'scan_date_estimate',
                'action' => self::ACTION,
                'enabled' => true,
                'bulk_enabled' => false,
                'sensitive' => true,
                'service_class' => ScanDateEstimateReviewDecisionService::class,
                'method' => 'retryApprovedWriteback',
                'conditions' => $this->conditions(),
            ]);
        });
    }

    public function down(): void
    {
        // Keep the corrected policy, any operator changes, and all receipts during rollback.
    }
};
