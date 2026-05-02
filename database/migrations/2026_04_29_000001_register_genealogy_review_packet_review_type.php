<?php

use Illuminate\Database\Migrations\Migration;
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
            'label' => 'Genealogy Review Packets',
            'icon' => 'mdi-file-document-check',
            'category' => 'genealogy',
            'source_table' => 'agent_review_queue',
            'source_connection' => 'mysql',
            'count_sql' => "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND review_type = 'genealogy_review_packet' AND (expires_at IS NULL OR expires_at > NOW())",
            'fetch_sql' => "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type = 'genealogy_review_packet' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            'approve_sql' => null,
            'reject_sql' => null,
            'field_mapping' => json_encode([
                'id' => 'id',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'token' => 'token',
                'agent_id' => 'agent_id',
                'review_type' => 'review_type',
                'priority' => 'priority',
                'details_json' => 'details',
                'created_at' => 'created_at',
                'expires_at' => 'expires_at',
                'unified_id_template' => 'genealogy_review_packet:{{token}}',
            ]),
            'requires_image' => false,
            'batch_enabled' => true,
            'service_class' => 'App\\Services\\Genealogy\\GenealogyReviewPacketDecisionService',
            'approve_method' => 'markReviewed',
            'reject_method' => 'reject',
            'display_order' => 37,
            'color' => 'ops-green',
            'enabled' => true,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('review_type_registry', 'ui_schema')) {
            $definition['ui_schema'] = json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'class' => 'bg-ops-green', 'source' => 'review_type'],
                        ['type' => 'text', 'class' => 'font-semibold text-ops-peach flex-1', 'source' => 'title'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'class' => 'text-sm text-ops-text-muted', 'source' => 'summary'],
                    ],
                    'footer' => [
                        ['type' => 'timestamp', 'label' => 'Created', 'source' => 'created_at'],
                        ['type' => 'timestamp', 'label' => 'Expires', 'source' => 'expires_at', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'label' => 'Agent', 'source' => 'agent_id'],
                    ['type' => 'json', 'label' => 'Packet Details', 'source' => 'details', 'expanded' => true],
                ],
            ]);
        }

        if (Schema::hasColumn('review_type_registry', 'actions')) {
            $definition['actions'] = json_encode(['approve', 'reject', 'ignore']);
        }

        if (Schema::hasColumn('review_type_registry', 'ignore_sql')) {
            $definition['ignore_sql'] = "UPDATE agent_review_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?";
        }

        $exists = DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->exists();

        if ($exists) {
            DB::table('review_type_registry')
                ->where('name', 'genealogy_review_packet')
                ->update($definition);

            return;
        }

        DB::table('review_type_registry')->insert(array_merge($definition, [
            'name' => 'genealogy_review_packet',
            'created_at' => now(),
        ]));
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'genealogy_review_packet')
            ->delete();
    }
};
