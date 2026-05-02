<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mcpTools = [
            [
                'name' => 'mcp_searxng_search',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Search the web using SearXNG privacy-respecting meta search engine. Returns web results with titles, URLs, and snippets.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
                    'max_results' => ['type' => 'integer', 'description' => 'Maximum results (default: 10)', 'default' => 10],
                ]),
                'permissions' => json_encode(['system:read']),
                'risk_level' => 'read',
                'category' => 'search',
                'max_calls_per_run' => 10,
                'mcp_server' => 'searxng',
                'mcp_tool' => 'searxng_search',
            ],
            [
                'name' => 'mcp_searxng_news',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Search for news articles using SearXNG. Returns recent news with publication dates.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
                    'max_results' => ['type' => 'integer', 'description' => 'Maximum results (default: 10)', 'default' => 10],
                ]),
                'permissions' => json_encode(['system:read']),
                'risk_level' => 'read',
                'category' => 'search',
                'max_calls_per_run' => 5,
                'mcp_server' => 'searxng',
                'mcp_tool' => 'searxng_news',
            ],
            [
                'name' => 'mcp_web_search',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Search the web using multiple sources in parallel (SearXNG + Wikipedia + NewsAPI). Comprehensive results.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
                    'max_results' => ['type' => 'integer', 'description' => 'Maximum results (default: 15)', 'default' => 15],
                ]),
                'permissions' => json_encode(['system:read']),
                'risk_level' => 'read',
                'category' => 'search',
                'max_calls_per_run' => 5,
                'mcp_server' => 'web-research',
                'mcp_tool' => 'web_search_parallel',
            ],
            [
                'name' => 'mcp_code_review',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Review a code snippet for issues (security, performance, bugs, best practices, style).',
                'parameters' => json_encode([
                    'code' => ['type' => 'string', 'description' => 'Code to review', 'required' => true],
                    'language' => ['type' => 'string', 'description' => 'Programming language', 'required' => true],
                ]),
                'permissions' => json_encode(['system:read']),
                'risk_level' => 'read',
                'category' => 'code',
                'max_calls_per_run' => 5,
                'mcp_server' => 'code-review',
                'mcp_tool' => 'code_review',
            ],
            [
                'name' => 'mcp_genealogy_search',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Search the genealogy database for persons, families, or sources by name/title.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'description' => 'Search query (name, surname, title)', 'required' => true],
                    'type' => ['type' => 'string', 'description' => 'Type: person, family, source, all', 'default' => 'all'],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 10,
                'mcp_server' => 'genealogy',
                'mcp_tool' => 'tree_search',
            ],
            [
                'name' => 'mcp_genealogy_stats',
                'service_class' => 'MCP_BRIDGE',
                'method' => 'callTool',
                'description' => 'Get comprehensive statistics about a family tree.',
                'parameters' => json_encode([
                    'tree_id' => ['type' => 'integer', 'description' => 'Tree ID', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 3,
                'mcp_server' => 'genealogy',
                'mcp_tool' => 'tree_stats',
            ],
        ];

        foreach ($mcpTools as $tool) {
            try {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, permissions,
                     risk_level, category, max_calls_per_run, mcp_server, mcp_tool, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual')
                    ON DUPLICATE KEY UPDATE
                        mcp_server = VALUES(mcp_server),
                        mcp_tool = VALUES(mcp_tool),
                        risk_level = VALUES(risk_level),
                        category = VALUES(category),
                        max_calls_per_run = VALUES(max_calls_per_run),
                        updated_at = NOW()
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                    $tool['max_calls_per_run'],
                    $tool['mcp_server'],
                    $tool['mcp_tool'],
                ]);
            } catch (\Exception $e) {
                // Skip on error (idempotent)
            }
        }
    }

    public function down(): void
    {
        $names = [
            'mcp_searxng_search', 'mcp_searxng_news', 'mcp_web_search',
            'mcp_code_review', 'mcp_genealogy_search', 'mcp_genealogy_stats',
        ];

        foreach ($names as $name) {
            DB::delete("DELETE FROM agent_tool_registry WHERE name = ?", [$name]);
        }
    }
};
