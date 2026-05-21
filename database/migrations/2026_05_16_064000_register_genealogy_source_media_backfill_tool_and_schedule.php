<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $parameters = [
            'type' => 'object',
            'properties' => [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID whose URL-only sources should be backfilled'],
                'since' => ['type' => 'string', 'description' => 'Window to scan, e.g. 24h, 14d, all', 'default' => '14d'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum source rows per batch', 'default' => 25],
                'order' => ['type' => 'string', 'description' => 'oldest or newest', 'default' => 'oldest'],
                'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'confirm_download' => ['type' => 'boolean', 'default' => false],
                'confirm_storage_write' => ['type' => 'boolean', 'default' => false],
                'nara_metadata_snapshot' => ['type' => 'boolean', 'default' => true],
                'link_sources' => ['type' => 'boolean', 'default' => true],
                'max_bytes' => ['type' => 'integer'],
            ],
            'required' => ['tree_id'],
        ];

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
            'source_media_backfill',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Dry-run-first bounded backfill of URL-only genealogy sources into tree-scoped FT storage, using NARA API digital objects when available.',
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'Returns source capture batch counts, saved/reused media IDs, NARA API activity, and blockers.',
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            20,
            'genealogy',
            'source_media_backfill',
            'MCP bridge registration for source URL media backfill; command enforces dry-run and explicit download/storage confirmation flags.',
        ]);

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_backfill_source_media'],
            [
                'description' => 'Frequent bounded genealogy source URL media backfill across all trees.',
                'job_type' => 'command',
                'command' => 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json',
                'cron_expression' => '*/10 * * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 25,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'batch',
                'workload_family' => 'genealogy',
                'resource_profile' => 'network',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_source_media_backfill',
                'notification_mode' => 'digest',
                'notes' => 'Captures URL-only genealogy_sources into tree-local FT storage in small frequent batches. NARA catalog URLs use API digitalObjects first, with metadata snapshot fallback when requested.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'source_media_backfill')
            ->where('mcp_server', 'genealogy')
            ->delete();

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_backfill_source_media')
            ->update([
                'command' => 'genealogy:backfill-source-media --since=3d --tree=4 --limit=100',
                'cron_expression' => '25 4 * * *',
                'timeout_minutes' => 30,
                'runtime_mode' => 'maintenance',
                'resource_profile' => 'network',
                'notes' => 'Rollback restored the prior daily source media backfill posture.',
                'updated_at' => now(),
            ]);
    }
};
