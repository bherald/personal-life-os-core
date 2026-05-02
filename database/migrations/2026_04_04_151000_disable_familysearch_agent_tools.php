<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['familysearch_public_search', 'sync_familysearch_hints'])
            ->delete();
    }

    public function down(): void
    {
        // No-op: retired integrations are intentionally not restored.
    }
};
