<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add graph parameters to rag_deep_search tool so agents can opt into graph fusion
        $tool = DB::selectOne("SELECT id, parameters FROM agent_tool_registry WHERE name = 'rag_deep_search'");
        if (!$tool) {
            return;
        }

        $params = json_decode($tool->parameters, true) ?: [];

        // Add graph parameters
        $params['use_graph'] = [
            'type' => 'boolean',
            'description' => 'Enable knowledge graph fusion for enhanced retrieval',
            'default' => false,
        ];
        $params['graph_mode'] = [
            'type' => 'string',
            'description' => 'Graph search mode: local (entity neighborhood), global (community reports), drift (iterative refinement)',
            'default' => 'local',
            'enum' => ['local', 'global', 'drift'],
        ];
        $params['graph_alpha'] = [
            'type' => 'float',
            'description' => 'Blend weight: 0.0 = pure vector, 1.0 = pure graph. Default 0.6 favors vector slightly.',
            'default' => 0.6,
        ];

        DB::update(
            "UPDATE agent_tool_registry SET parameters = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($params), $tool->id]
        );
    }

    public function down(): void
    {
        $tool = DB::selectOne("SELECT id, parameters FROM agent_tool_registry WHERE name = 'rag_deep_search'");
        if (!$tool) {
            return;
        }

        $params = json_decode($tool->parameters, true) ?: [];
        unset($params['use_graph'], $params['graph_mode'], $params['graph_alpha']);

        DB::update(
            "UPDATE agent_tool_registry SET parameters = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($params), $tool->id]
        );
    }
};
