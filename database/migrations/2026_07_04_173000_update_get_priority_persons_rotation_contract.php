<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        DB::table('agent_tool_registry')
            ->where('name', 'get_priority_persons')
            ->update([
                'description' => 'Get priority-ranked persons for research selection. When the coverage schema supports it, returns never-worked persons first, then oldest agent-worked persons, with computed priority score as the tie breaker (bloodline tier x data gaps x staleness / exhaustion). Use this in the assess phase to spread work across the tree instead of repeatedly selecting the same high-score people. Exhausted brick-wall persons are filtered out by default.',
                'returns_description' => 'Array with tier_distribution, persons array (each with bloodline_tier, tier_label, priority_score, priority_rank, data_gap_score, research_exhaustion, pending_hints, hint_statuses, all_hints_deferred, last_searched_at, agent_last_worked_at, searches_30d), and filters_applied including whether agent_work_rotation was active',
                'notes' => 'Priority person rotation - fixes agent selecting same brick-wall persons repeatedly by using genealogy_person_coverage.agent_last_worked_at when present. Coverage data from nightly genealogy_coverage_rebuild job.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        DB::table('agent_tool_registry')
            ->where('name', 'get_priority_persons')
            ->update([
                'description' => 'Get priority-ranked persons for research selection. Returns persons sorted by computed priority score (bloodline tier x data gaps x staleness / exhaustion). Use this in the assess phase to pick research targets - it replaces manual selection from list_persons. Exhausted brick-wall persons are filtered out by default.',
                'returns_description' => 'Array with tier_distribution, persons array (each with bloodline_tier, tier_label, priority_score, priority_rank, data_gap_score, research_exhaustion, pending_hints, hint_statuses, all_hints_deferred, last_searched_at, searches_30d), and filters_applied',
                'notes' => 'Priority person rotation - fixes agent selecting same brick-wall persons repeatedly. Coverage data from nightly genealogy_coverage_rebuild job.',
                'updated_at' => now(),
            ]);
    }
};
