<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N128d: Switch agent review type filter from blacklist to whitelist.
 *
 * The NOT IN blacklist missed 'suggestion' (and would miss any future ops types).
 * Only genealogy_finding, tool_proposal, and skill_optimization need human review —
 * everything else is ops informational. Use review_type IN whitelist on the
 * inverse: the 'agent' type should only show items that aren't handled by
 * dedicated review types.
 *
 * Since only genealogy_finding/tool_proposal/skill_optimization have service
 * handlers, and they have their own review_type_registry entries, the generic
 * 'agent' type should show nothing (all ops items are auto-approved now).
 * Disable it to avoid an empty tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Disable the generic 'agent' review type — all actionable subtypes
        // (genealogy_finding, tool_proposal, skill_optimization) have dedicated
        // registry entries. Remaining subtypes are ops informational and auto-approved.
        DB::update("UPDATE review_type_registry SET enabled = 0 WHERE name = 'agent'");

        // Dismiss any remaining pending ops items
        DB::update("
            UPDATE agent_review_queue
            SET status = 'ignored', reviewed_at = NOW(), reviewer_notes = 'N128d: ops informational — auto-dismissed', updated_at = NOW()
            WHERE status = 'pending' AND review_type NOT IN ('genealogy_finding', 'tool_proposal', 'skill_optimization')
        ");
    }

    public function down(): void
    {
        DB::update("UPDATE review_type_registry SET enabled = 1 WHERE name = 'agent'");
    }
};
