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
        if (! Schema::hasTable('file_date_review_decisions')) {
            Schema::create('file_date_review_decisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('file_registry_id');
                $table->string('decision', 20);
                $table->timestamp('proposed_date')->nullable();
                $table->decimal('ai_confidence', 4, 3)->nullable();
                $table->string('ai_source', 50)->nullable();
                $table->text('ai_reasoning')->nullable();
                $table->smallInteger('previous_exif_written')->nullable();
                $table->string('reason_code', 80)->nullable();
                $table->text('reviewer_notes')->nullable();
                $table->boolean('metadata_file_written')->default(false);
                $table->timestamps();
                $table->index(['file_registry_id', 'created_at'], 'file_date_review_file_created_idx');
                $table->index(['decision', 'created_at'], 'file_date_review_decision_created_idx');
            });
        }

        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $predicate = "fr.status = 'active' AND fr.is_scan = 1 AND fr.date_needs_review = 1 AND fr.date_taken IS NOT NULL AND fr.asset_uuid IS NOT NULL AND fr.date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low') AND COALESCE(fr.exif_written, 0) <> 1";
        $definition = [
            'label' => 'Scan Dates',
            'icon' => 'calendar-days',
            'category' => 'files',
            'source_table' => 'file_registry',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) AS total FROM file_registry fr WHERE {$predicate}",
            'fetch_sql' => "SELECT fr.id,
                CONCAT('Review scan date: ', fr.filename) AS title,
                CONCAT('Proposed original date ', DATE_FORMAT(fr.date_taken, '%Y-%m-%d'), '; digitized ', COALESCE(DATE_FORMAT(fr.scan_date, '%Y-%m-%d'), 'unknown')) AS summary,
                NULL AS confidence,
                COALESCE(fr.date_extracted_at, fr.updated_at, fr.created_at) AS created_at,
                JSON_OBJECT(
                    'asset_uuid', fr.asset_uuid,
                    'filename', fr.filename,
                    'proposed_date', DATE_FORMAT(fr.date_taken, '%Y-%m-%d %H:%i:%s'),
                    'proposed_year', YEAR(fr.date_taken),
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
            'approve_sql' => null,
            'reject_sql' => null,
            'field_mapping' => json_encode([
                'unified_id_template' => 'scan_date_estimate:{{id}}',
                'id' => 'id',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'created_at' => 'created_at',
                'details_json' => 'details',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ui_schema' => json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'source', 'class' => 'bg-ops-sky'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'vue_renderer' => 'ScanDateEstimateRenderer',
            'vue_detail_component' => 'ScanDateEstimateReviewDetail',
            'actions' => json_encode(['approve', 'reject'], JSON_THROW_ON_ERROR),
            'requires_image' => false,
            'image_field' => null,
            'batch_enabled' => false,
            'service_class' => 'App\\Services\\Files\\ScanDateEstimateReviewDecisionService',
            'approve_method' => 'approve',
            'reject_method' => 'reject',
            'display_order' => 35,
            'color' => 'ops-sky',
            'enabled' => true,
            'updated_at' => now(),
        ];

        $existing = DB::table('review_type_registry')->where('name', 'scan_date_estimate')->first(['id']);
        if ($existing !== null) {
            DB::table('review_type_registry')->where('id', $existing->id)->update($definition);
        } else {
            DB::table('review_type_registry')->insert(array_merge($definition, [
                'name' => 'scan_date_estimate',
                'created_at' => now(),
            ]));
        }
        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')->where('name', 'scan_date_estimate')->delete();
        }
        Cache::forget('review_type_registry');
        Schema::dropIfExists('file_date_review_decisions');
    }
};
