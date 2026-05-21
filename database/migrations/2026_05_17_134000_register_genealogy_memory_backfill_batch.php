<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, requires_confirmation, max_calls_per_run,
                 mcp_server, mcp_tool, enabled, source, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                returns_description = VALUES(returns_description),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                category = VALUES(category),
                requires_confirmation = VALUES(requires_confirmation),
                max_calls_per_run = VALUES(max_calls_per_run),
                mcp_server = VALUES(mcp_server),
                mcp_tool = VALUES(mcp_tool),
                enabled = VALUES(enabled),
                source = VALUES(source),
                notes = VALUES(notes),
                updated_at = NOW()
        ", [
            'memory_backfill_batch',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Run compact bounded Genea learning-memory backfills across canonical lessons, health-audit findings, media-intake outcomes, source-media capture outcomes, and review decisions.',
            json_encode([
                'type' => 'object',
                'properties' => [
                    'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID. Omit only in trusted scheduled contexts.'],
                    'lanes' => ['type' => 'string', 'description' => 'all or comma-separated lanes', 'default' => 'all'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum candidates per lane', 'default' => 25],
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                    'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
                ],
            ], JSON_UNESCAPED_SLASHES),
            'Returns per-tree lane summaries, candidate counts, recorded memory IDs, and errors without exposing raw memory tables.',
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            20,
            'genealogy',
            'memory_backfill_batch',
            'MCP bridge registration for scheduled/local Genea learning backfills so agents can grow memory without raw SQL or multi-tool orchestration.',
        ]);

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_memory_backfill'],
            [
                'description' => 'Frequent bounded local Genea learning-memory backfill across all family trees.',
                'job_type' => 'command',
                'command' => 'genealogy:memory-backfill --tree=all --lanes=all --limit=25 --confirm --json',
                'cron_expression' => '37 */6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 20,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'batch',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_learning_memory',
                'notification_mode' => 'digest',
                'notes' => 'Runs dry-run-first Genea memory backfill in confirmed scheduled mode. Captures canonical lessons and memorizes accepted/rejected review, media-intake, source-media capture, and health-audit signals without raw table work.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'memory_backfill_batch')
            ->where('mcp_server', 'genealogy')
            ->delete();

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_memory_backfill')
            ->delete();
    }
};
