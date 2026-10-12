<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPE = 'scan_date_legacy_metadata';

    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $predicate = "fr.status = 'active' AND fr.is_scan = 1 AND fr.exif_written = 1 AND fr.date_needs_review = 1 AND fr.date_taken IS NOT NULL AND fr.asset_uuid IS NOT NULL AND fr.date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low')";
        $definition = [
            'label' => 'Legacy Scan Metadata',
            'icon' => 'file-search',
            'category' => 'files',
            'source_table' => 'file_registry',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) AS total FROM file_registry fr WHERE {$predicate}",
            'fetch_sql' => "SELECT fr.id,
                CONCAT('Inspect legacy metadata: ', fr.filename) AS title,
                CONCAT('Catalog write flag requires live file inspection; proposed original date ', DATE_FORMAT(fr.date_taken, '%Y-%m-%d')) AS summary,
                NULL AS confidence,
                COALESCE(fr.date_extracted_at, fr.updated_at, fr.created_at) AS created_at,
                JSON_OBJECT(
                    'file_registry_id', fr.id,
                    'asset_uuid', fr.asset_uuid,
                    'filename', fr.filename,
                    'extension', fr.extension,
                    'catalog_estimate_date', DATE_FORMAT(fr.date_taken, '%Y-%m-%d %H:%i:%s'),
                    'catalog_scan_date', DATE_FORMAT(fr.scan_date, '%Y-%m-%d %H:%i:%s'),
                    'catalog_source', fr.date_taken_source,
                    'catalog_confidence', fr.date_taken_confidence,
                    'catalog_exif_written', fr.exif_written,
                    'content_hash_verified_at', DATE_FORMAT(fr.content_hash_verified_at, '%Y-%m-%d %H:%i:%s'),
                    'nextcloud_modified_at', DATE_FORMAT(fr.nextcloud_modified_at, '%Y-%m-%d %H:%i:%s'),
                    'inspection_only', TRUE
                ) AS details
             FROM file_registry fr
             WHERE {$predicate}
             ORDER BY COALESCE(fr.date_extracted_at, fr.updated_at, fr.created_at) ASC
             LIMIT 500",
            'approve_sql' => null,
            'reject_sql' => null,
            'field_mapping' => json_encode([
                'unified_id_template' => self::TYPE.':{{id}}',
                'id' => 'id',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'created_at' => 'created_at',
                'details_json' => 'details',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ui_schema' => json_encode([
                'inspection_only' => true,
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'source', 'class' => 'bg-ops-orange'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'vue_renderer' => 'ScanDateLegacyMetadataRenderer',
            'vue_detail_component' => 'ScanDateLegacyMetadataDetail',
            'actions' => json_encode([], JSON_THROW_ON_ERROR),
            'requires_image' => false,
            'image_field' => null,
            'batch_enabled' => false,
            'service_class' => null,
            'approve_method' => null,
            'reject_method' => null,
            'display_order' => 36,
            'color' => 'ops-orange',
            'enabled' => true,
            'updated_at' => now(),
        ];

        $existing = DB::table('review_type_registry')->where('name', self::TYPE)->first(['id']);
        if ($existing !== null) {
            DB::table('review_type_registry')->where('id', $existing->id)->update($definition);
        } else {
            DB::table('review_type_registry')->insert(array_merge($definition, [
                'name' => self::TYPE,
                'created_at' => now(),
            ]));
        }
        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')->where('name', self::TYPE)->delete();
        }
        Cache::forget('review_type_registry');
    }
};
