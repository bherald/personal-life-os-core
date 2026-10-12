<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('file_registry') && ! Schema::hasColumn('file_registry', 'date_taken_precision')) {
            Schema::table('file_registry', function (Blueprint $table): void {
                $table->string('date_taken_precision', 16)->nullable()->after('date_taken');
                $table->index('date_taken_precision', 'file_registry_date_precision_idx');
            });
        }

        if (Schema::hasTable('file_date_review_decisions') && ! Schema::hasColumn('file_date_review_decisions', 'proposed_precision')) {
            Schema::table('file_date_review_decisions', function (Blueprint $table): void {
                $table->string('proposed_precision', 16)->nullable()->after('proposed_date');
            });
        }

        if (Schema::hasTable('file_registry') && Schema::hasColumn('file_registry', 'date_taken_precision')) {
            DB::table('file_registry')
                ->whereIn('date_taken_source', ['ai_estimated', 'ai_visual_high', 'ai_visual_medium', 'ai_visual_low'])
                ->whereNotNull('date_taken')
                ->whereNull('date_taken_precision')
                ->update(['date_taken_precision' => 'year']);
        }

        $this->updateReviewRegistration();
    }

    public function down(): void
    {
        $this->updateReviewRegistration(false);

        if (Schema::hasTable('file_date_review_decisions') && Schema::hasColumn('file_date_review_decisions', 'proposed_precision')) {
            Schema::table('file_date_review_decisions', function (Blueprint $table): void {
                $table->dropColumn('proposed_precision');
            });
        }

        if (Schema::hasTable('file_registry') && Schema::hasColumn('file_registry', 'date_taken_precision')) {
            Schema::table('file_registry', function (Blueprint $table): void {
                $table->dropIndex('file_registry_date_precision_idx');
                $table->dropColumn('date_taken_precision');
            });
        }

        Cache::forget('review_type_registry');
    }

    private function updateReviewRegistration(bool $withPrecision = true): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $predicate = "fr.status = 'active' AND fr.is_scan = 1 AND fr.date_needs_review = 1 AND fr.date_taken IS NOT NULL AND fr.asset_uuid IS NOT NULL AND fr.date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low') AND COALESCE(fr.exif_written, 0) <> 1";
        DB::table('review_type_registry')
            ->where('name', 'scan_date_estimate')
            ->update([
                'count_sql' => "SELECT COUNT(*) AS total FROM file_registry fr WHERE {$predicate}",
                'fetch_sql' => "SELECT fr.id,
                    CONCAT('Review approximate year: ', fr.filename) AS title,
                    CONCAT('Proposed year ', YEAR(fr.date_taken), '; digitized ', COALESCE(DATE_FORMAT(fr.scan_date, '%Y-%m-%d'), 'unknown')) AS summary,
                    NULL AS confidence,
                    COALESCE(fr.date_extracted_at, fr.updated_at, fr.created_at) AS created_at,
                    JSON_OBJECT(
                        'asset_uuid', fr.asset_uuid,
                        'filename', fr.filename,
                        'proposed_date', DATE_FORMAT(fr.date_taken, '%Y-%m-%d %H:%i:%s'),
                        'proposed_year', YEAR(fr.date_taken),
                        'proposed_precision', ".($withPrecision ? "COALESCE(fr.date_taken_precision, 'year')" : "'year'").",
                        'scan_date', DATE_FORMAT(fr.scan_date, '%Y-%m-%d %H:%i:%s'),
                        'ai_confidence', fr.date_taken_confidence,
                        'ai_source', fr.date_taken_source,
                        'ai_reasoning', fr.date_taken_reasoning,
                        'previous_exif_written', fr.exif_written,
                        'metadata_file_written', FALSE
                    ) AS details
                 FROM file_registry fr
                 WHERE {$predicate}
                 ORDER BY COALESCE(fr.date_extracted_at, fr.updated_at, fr.created_at) ASC
                 LIMIT 500",
                'updated_at' => now(),
            ]);

        Cache::forget('review_type_registry');
    }
};
