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

        $definition = [
            'label' => 'AI Model Portfolio',
            'icon' => 'cpu-chip',
            'category' => 'agent',
            'source_table' => 'agent_review_queue',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) as total, SUM(CASE WHEN priority >= 1 THEN 1 ELSE 0 END) as `high_priority` FROM agent_review_queue WHERE status = 'pending' AND review_type = 'ai_model_update' AND (expires_at IS NULL OR expires_at > NOW())",
            'fetch_sql' => "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type = 'ai_model_update' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            'approve_sql' => null,
            'reject_sql' => null,
            'field_mapping' => json_encode([
                'unified_id_template' => 'ai_model_update:{{id}}',
                'id' => 'id',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'priority' => 'priority',
                'created_at' => 'created_at',
                'expires_at' => 'expires_at',
                'details_json' => 'details',
                'review_type' => 'review_type',
                'agent_id' => 'agent_id',
            ], JSON_UNESCAPED_SLASHES),
            'ui_schema' => json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-sky'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                    ],
                    'footer' => [
                        ['type' => 'text', 'source' => 'agent_id', 'label' => 'Reviewer'],
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Created'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
            'vue_renderer' => 'AiModelUpdateRenderer',
            'vue_detail_component' => 'AiModelUpdateReviewDetail',
            'actions' => json_encode(['approve', 'reject']),
            'requires_image' => false,
            'image_field' => null,
            'batch_enabled' => false,
            'service_class' => 'App\\Services\\Ops\\AiModelUpdateReviewDecisionService',
            'approve_method' => 'approve',
            'reject_method' => 'reject',
            'display_order' => 14,
            'color' => 'ops-sky',
            'enabled' => true,
            'updated_at' => now(),
        ];

        $existing = DB::table('review_type_registry')->where('name', 'ai_model_update')->first(['id']);
        if ($existing !== null) {
            DB::table('review_type_registry')->where('id', $existing->id)->update($definition);
        } else {
            DB::table('review_type_registry')->insert(array_merge($definition, [
                'name' => 'ai_model_update',
                'created_at' => now(),
            ]));
        }

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')->where('name', 'ai_model_update')->delete();
        }
        Cache::forget('review_type_registry');
    }
};
