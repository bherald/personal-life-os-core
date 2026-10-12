<?php

use App\Services\Genealogy\FaceMatchReviewDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $policy = DB::table('review_desk_policy')->where('review_type', 'face')->where('action', 'refresh_region')->lockForUpdate()->first();
            if (! $policy || $policy->service_class !== FaceMatchReviewDecisionService::class || $policy->method !== 'refreshRegion') {
                return;
            }
            $conditions = json_decode($policy->conditions ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($conditions) || ($conditions['operator_only'] ?? false) !== true
                || ($conditions['current_source_required'] ?? false) !== true || array_key_exists('materialize_current_source', $conditions)) {
                return;
            }
            $conditions['materialize_current_source'] = true;
            DB::table('review_desk_policy')->where('review_type', 'face')->where('action', 'refresh_region')->update([
                'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $policy = DB::table('review_desk_policy')->where('review_type', 'face')->where('action', 'refresh_region')->lockForUpdate()->first();
            if (! $policy || $policy->service_class !== FaceMatchReviewDecisionService::class || $policy->method !== 'refreshRegion') {
                return;
            }
            $conditions = json_decode($policy->conditions ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($conditions) || ($conditions['materialize_current_source'] ?? false) !== true) {
                return;
            }
            unset($conditions['materialize_current_source']);
            DB::table('review_desk_policy')->where('review_type', 'face')->where('action', 'refresh_region')->update([
                'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]);
        });
    }
};
