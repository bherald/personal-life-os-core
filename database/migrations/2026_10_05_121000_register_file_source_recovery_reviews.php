<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $predicate = "review_type = 'file_source_recovery' AND status = 'pending' AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
        $createdAt = DB::table('review_type_registry')->where('name', 'file_source_recovery')->value('created_at') ?? now();
        DB::table('review_type_registry')->updateOrInsert(['name' => 'file_source_recovery'], [
            'label' => 'Source recovery holds',
            'icon' => 'file-search',
            'category' => 'files',
            'source_table' => 'agent_review_queue',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) AS total FROM agent_review_queue WHERE {$predicate}",
            'fetch_sql' => "SELECT id, agent_id, review_type, finding_type, title, summary, details, NULL AS confidence, priority, status, token, created_at FROM agent_review_queue WHERE {$predicate} ORDER BY priority DESC, created_at ASC, id ASC LIMIT 500",
            'approve_sql' => null,
            'reject_sql' => null,
            'ignore_sql' => null,
            'field_mapping' => json_encode([
                'unified_id_template' => 'file_source_recovery:{{id}}',
                'id' => 'id', 'agent_id' => 'agent_id', 'review_type' => 'review_type',
                'finding_type' => 'finding_type', 'title' => 'title', 'summary' => 'summary',
                'details_json' => 'details', 'confidence' => 'confidence',
                'priority' => 'priority', 'status' => 'status', 'created_at' => 'created_at',
            ], JSON_THROW_ON_ERROR),
            'ui_schema' => json_encode([
                'inspection_only' => true,
                'card' => [
                    'header' => [['type' => 'text', 'source' => 'title']],
                    'body' => [['type' => 'text', 'source' => 'summary']],
                ],
            ], JSON_THROW_ON_ERROR),
            'vue_renderer' => null,
            'vue_detail_component' => 'GenericReviewDetail',
            'actions' => '[]',
            'requires_image' => false,
            'image_field' => null,
            'batch_enabled' => false,
            'service_class' => null,
            'approve_method' => null,
            'reject_method' => null,
            'display_order' => 37,
            'color' => 'ops-teal',
            'enabled' => true,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ]);
        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')->where('name', 'file_source_recovery')->delete();
        }
        Cache::forget('review_type_registry');
    }
};
