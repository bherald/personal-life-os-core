<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N103 — Retired FamilySearch Collaborative Hint Sync
 *
 * FamilySearch automated sync is no longer registered. Keep FamilySearch as
 * a manual/browser-only source and citation target.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_tool_registry')->where('name', 'sync_familysearch_hints')->delete();
    }

    public function down(): void
    {
        // No-op: retired integration is intentionally not restored.
    }
};
