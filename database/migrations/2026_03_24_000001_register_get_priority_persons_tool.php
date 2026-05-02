<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register get_priority_persons tool for genealogy-researcher agent.
 *
 * Exposes the priority-ranked person coverage data to the LLM during
 * the assess phase so it can make informed rotation/selection decisions
 * instead of picking from an unranked list.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_tool_registry')->updateOrInsert(
            ['name' => 'get_priority_persons'],
            [
                'name' => 'get_priority_persons',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyService',
                'method' => 'getPriorityPersons',
                'description' => 'Get priority-ranked persons for research selection. Returns persons sorted by computed priority score (bloodline tier × data gaps × staleness ÷ exhaustion). Use this in the assess phase to pick research targets — it replaces manual selection from list_persons. Exhausted brick-wall persons are filtered out by default.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to get priority persons for'],
                        'limit' => ['type' => 'integer', 'description' => 'Max persons to return (default 20)', 'default' => 20],
                        'tier' => ['type' => 'integer', 'description' => 'Filter to bloodline tier: 1=direct ancestor, 2=sibling/child of ancestor, 3=collateral, 4=married-in. Omit for all tiers.'],
                        'include_exhausted' => ['type' => 'boolean', 'description' => 'Include persons with >=90% negative searches in last 30 days (default false)', 'default' => false],
                    ],
                    'required' => ['tree_id'],
                ]),
                'returns_description' => 'Array with tier_distribution, persons array (each with bloodline_tier, tier_label, priority_score, priority_rank, data_gap_score, research_exhaustion, pending_hints, hint_statuses, all_hints_deferred, last_searched_at, searches_30d), and filters_applied',
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'max_calls_per_run' => 5,
                'source' => 'manual',
                'notes' => 'Priority person rotation — fixes agent selecting same brick-wall persons repeatedly. Coverage data from nightly genealogy_coverage_rebuild job.',
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'get_priority_persons')
            ->delete();
    }
};
