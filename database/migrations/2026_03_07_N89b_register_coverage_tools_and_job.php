<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N89b — Register coverage tools + scheduled job for nightly rebuild
 *
 * - rebuild_ancestor_paths: agent can trigger a BFS recompute when tree changes
 * - refresh_person_coverage: agent can trigger priority score refresh mid-run
 * - Scheduled job: genealogy_coverage_rebuild runs nightly at 3:30 AM
 *   (after face-sync jobs, before genealogy agent at 8 AM)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Register agent tools
        $tools = [
            [
                'name' => 'rebuild_ancestor_paths',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyService',
                'method' => 'rebuildAncestorPaths',
                'description' => 'Recompute bloodline tier and generation distance for all persons in a tree via BFS from the root person. Run when tree structure changes significantly. Results feed into priority scoring.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                        'root_person_id' => ['type' => 'integer', 'description' => 'Person ID of tree owner / root person'],
                    ],
                    'required' => ['tree_id', 'root_person_id'],
                ]),
                'returns_description' => 'Number of ancestor path rows written',
                'permissions' => json_encode(['genealogy:write']),
                'risk_level' => 'write',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'source' => 'manual',
                'notes' => 'N89: Phase 2 — bloodline tier computation for priority scoring',
            ],
            [
                'name' => 'refresh_person_coverage',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyService',
                'method' => 'refreshPersonCoverage',
                'description' => 'Recalculate research priority scores for all persons in a tree. Uses bloodline tier, data gaps, search staleness, and exhaustion score. Results used by get_missing_data_report for tier-aware ordering.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                    ],
                    'required' => ['tree_id'],
                ]),
                'returns_description' => 'Number of coverage rows upserted',
                'permissions' => json_encode(['genealogy:write']),
                'risk_level' => 'write',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'source' => 'manual',
                'notes' => 'N89: Phase 2 — priority score refresh, called by nightly job and optionally mid-run',
            ],
        ];

        foreach ($tools as $tool) {
            DB::table('agent_tool_registry')->updateOrInsert(
                ['name' => $tool['name']],
                array_merge($tool, ['updated_at' => now()])
            );
        }

        // Scheduled job: nightly coverage rebuild at 3:30 AM
        // Runs after face-sync (#32 at 3 AM), before genealogy agent (#82 at 8 AM)
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_coverage_rebuild'],
            [
                'name' => 'genealogy_coverage_rebuild',
                'command' => 'genealogy:rebuild-coverage',
                'job_type' => 'command',
                'cron_expression' => '30 3 * * *',
                'enabled' => 1,
                'timeout_minutes' => 15,
                'category' => 'Genealogy',
                'description' => 'Nightly rebuild of genealogy_ancestor_paths and genealogy_person_coverage. Computes bloodline tier and research priority scores for all persons in all trees.',
                'notes' => 'N89: Phase 2 — feeds tier-aware priority into get_missing_data_report',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['rebuild_ancestor_paths', 'refresh_person_coverage'])
            ->delete();
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_coverage_rebuild')
            ->delete();
    }
};
