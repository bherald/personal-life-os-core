<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Find youtube_watch_later workflow
        $workflow = DB::selectOne("SELECT id FROM workflows WHERE name = ?", ['youtube_watch_later']);

        if (!$workflow) {
            // Workflow doesn't exist on this environment, skip
            return;
        }

        $workflowId = $workflow->id;

        // Get current max node_order
        $maxOrder = DB::selectOne(
            "SELECT COALESCE(MAX(node_order), 0) as max_order FROM workflow_nodes WHERE workflow_id = ?",
            [$workflowId]
        );
        $nextOrder = $maxOrder->max_order + 1;

        // Node: YouTubeKeyPointsPostProcessor
        DB::insert(
            "INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)",
            [$workflowId, 'App\\Nodes\\YouTube\\YouTubeKeyPointsPostProcessor', $nextOrder, now()]
        );
        $keyPointsNodeId = (int) DB::getPdo()->lastInsertId();

        DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
            $keyPointsNodeId, 'limit', '20',
        ]);
        DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
            $keyPointsNodeId, 'dry_run', 'false',
        ]);

        // Node: YouTubeWatchLaterOrganize
        DB::insert(
            "INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at) VALUES (?, ?, ?, ?)",
            [$workflowId, 'App\\Nodes\\YouTube\\YouTubeWatchLaterOrganize', $nextOrder + 1, now()]
        );
        $organizeNodeId = (int) DB::getPdo()->lastInsertId();

        DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
            $organizeNodeId, 'use_ai', 'true',
        ]);
        DB::insert("INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value) VALUES (?, ?, ?)", [
            $organizeNodeId, 'dry_run', 'false',
        ]);
    }

    public function down(): void
    {
        $workflow = DB::selectOne("SELECT id FROM workflows WHERE name = ?", ['youtube_watch_later']);

        if (!$workflow) {
            return;
        }

        $nodes = DB::select(
            "SELECT id FROM workflow_nodes WHERE workflow_id = ? AND node_type IN (?, ?)",
            [
                $workflow->id,
                'App\\Nodes\\YouTube\\YouTubeKeyPointsPostProcessor',
                'App\\Nodes\\YouTube\\YouTubeWatchLaterOrganize',
            ]
        );

        if (!empty($nodes)) {
            $nodeIds = array_column($nodes, 'id');
            $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
            DB::delete("DELETE FROM workflow_node_configs WHERE workflow_node_id IN ({$placeholders})", $nodeIds);
            DB::delete("DELETE FROM workflow_nodes WHERE id IN ({$placeholders})", $nodeIds);
        }
    }
};
