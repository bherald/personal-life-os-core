<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N48b: Register 'genealogy_finding' in review_type_registry so the UI can
 * categorize genealogy agent research findings under the genealogy category.
 *
 * Also update the existing 'agent' type to exclude genealogy_finding items
 * (they now have their own dedicated type with genealogy category).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM review_type_registry WHERE name = 'genealogy_finding'");
        if ($exists) {
            return;
        }

        DB::insert("INSERT INTO review_type_registry (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, ui_schema, actions, requires_image, batch_enabled, service_class, display_order, color, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
            'genealogy_finding',
            'Research Findings',
            'mdi-magnify',
            'genealogy',
            'agent_review_queue',
            'mysql',
            // count_sql
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND review_type = 'genealogy_finding' AND (expires_at IS NULL OR expires_at > NOW())",
            // fetch_sql
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type = 'genealogy_finding' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            // approve_sql
            "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            // reject_sql
            "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            json_encode([
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
                'unified_id_template' => 'genealogy_finding:{{token}}',
            ]),
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'class' => 'bg-ops-sky', 'source' => 'agent_id'],
                        ['type' => 'badge', 'class' => 'bg-ops-blue', 'source' => 'review_type'],
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
                    ['type' => 'text', 'label' => 'Person', 'source' => 'review_type'],
                    ['type' => 'json', 'label' => 'Details', 'source' => 'details', 'collapsible' => true],
                ],
                'actions' => [
                    ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ]),
            json_encode(['approve', 'reject', 'defer']),
            false,
            true,
            null,
            35,
            'blue',
            true,
        ]);

        // Exclude genealogy_finding from the generic 'agent' type to avoid duplicates
        DB::update("UPDATE review_type_registry SET fetch_sql = ?, count_sql = ? WHERE name = 'agent'", [
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type != 'genealogy_finding' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND review_type != 'genealogy_finding' AND (expires_at IS NULL OR expires_at > NOW())",
        ]);
    }

    public function down(): void
    {
        DB::delete("DELETE FROM review_type_registry WHERE name = 'genealogy_finding'");

        // Restore agent type to include all review_types
        DB::update("UPDATE review_type_registry SET fetch_sql = ?, count_sql = ? WHERE name = 'agent'", [
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())",
        ]);
    }
};
