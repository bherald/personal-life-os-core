<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            DB::table('agent_tool_registry')
                ->whereIn('name', ['familysearch_public_search', 'sync_familysearch_hints'])
                ->delete();
        }

        if (Schema::hasTable('genealogy_research_providers')) {
            DB::table('genealogy_research_providers')
                ->whereIn('provider_id', ['familysearch', 'ancestry_dna'])
                ->delete();
        }

        if (Schema::hasTable('genealogy_provider_tokens')) {
            DB::table('genealogy_provider_tokens')
                ->whereIn('provider_id', ['familysearch', 'ancestry_dna'])
                ->delete();
        }

        if (Schema::hasTable('genealogy_external_connections')) {
            DB::table('genealogy_external_connections')
                ->whereIn('service_type', ['familysearch', 'ancestry'])
                ->update([
                    'status' => 'revoked',
                    'access_token' => null,
                    'refresh_token' => null,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('genealogy_source_registry')) {
            DB::table('genealogy_source_registry')
                ->whereIn('tool_name', ['familysearch_public_search', 'sync_familysearch_hints'])
                ->update([
                    'tool_name' => null,
                    'notes' => DB::raw("CONCAT(COALESCE(notes, ''), ' Manual/browser-only; automated API integration retired.')"),
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('genealogy_provider_classes');
        Cache::forget('genealogy_source_registry');
    }

    public function down(): void
    {
        // No-op: retired external integrations are intentionally not restored.
    }
};
