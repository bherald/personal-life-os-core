<?php

use App\Services\Genealogy\FaceMatchReviewDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['refresh_region' => 'refreshRegion', 'link' => 'link', 'false_detection' => 'dismissFalseDetection'] as $action => $method) {
            DB::table('review_desk_policy')->updateOrInsert(['review_type' => 'face', 'action' => $action], [
                'enabled' => true, 'bulk_enabled' => false, 'sensitive' => true,
                'service_class' => FaceMatchReviewDecisionService::class, 'method' => $method,
                'conditions' => json_encode(['operator_only' => true, 'current_source_required' => true]),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('review_desk_policy')->where('review_type', 'face')->whereIn('action', ['refresh_region', 'link', 'false_detection'])->delete();
    }
};
