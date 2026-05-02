<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            DB::table('agent_tool_registry')
                ->whereIn('name', ['fold3_search', 'nehgs_search'])
                ->delete();
        }

        if (Schema::hasTable('genealogy_source_registry')) {
            DB::table('genealogy_source_registry')
                ->whereIn('tool_name', ['fold3_search', 'nehgs_search'])
                ->update([
                    'tool_name' => null,
                    'notes' => DB::raw("CONCAT(COALESCE(notes, ''), ' Manual/browser-only; automated source tool retired.')"),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('genealogy_research_providers')) {
            DB::table('genealogy_research_providers')
                ->where('provider_id', 'myheritage')
                ->update([
                    'is_active' => 0,
                    'notes' => 'Private/personal-gated only. Current implementation uses screenshot/vision automation; keep disabled unless MYHERITAGE_PERSONAL_AUTOMATION_ENABLED is intentionally set.',
                    'updated_at' => now(),
                ]);
        }

        $this->retireDiscoveredManualOnlyDomains();

        foreach ([
            'genealogy_source_registry',
            'genealogy_provider_classes',
            'genealogy_provider_classes:myheritage:on',
            'genealogy_provider_classes:myheritage:off',
        ] as $key) {
            cache()->forget($key);
        }
    }

    public function down(): void
    {
        // No-op: this migration removes unsupported automation surfaces while
        // preserving manual source/citation references.
    }

    private function retireDiscoveredManualOnlyDomains(): void
    {
        try {
            $connection = DB::connection('pgsql_rag');
            $domains = [
                'ancestry.com',
                'familysearch.org',
                'fold3.com',
                'americanancestors.org',
                'nehgs.org',
                'findmypast.com',
            ];

            if (Schema::connection('pgsql_rag')->hasTable('discovered_sources')) {
                $connection->table('discovered_sources')
                    ->where(function ($query) use ($domains) {
                        foreach ($domains as $domain) {
                            $query->orWhere('domain', $domain)
                                ->orWhere('domain', 'like', '%.'.$domain);
                        }
                    })
                    ->update([
                        'is_active' => false,
                        'is_whitelisted' => false,
                        'is_blacklisted' => true,
                        'blacklist_reason' => 'Manual-only genealogy source; automated scraping disabled.',
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::connection('pgsql_rag')->hasTable('discovery_rules')) {
                $connection->table('discovery_rules')
                    ->where(function ($query) use ($domains) {
                        foreach ($domains as $domain) {
                            $query->orWhere('match_pattern', $domain)
                                ->orWhere('match_pattern', 'like', '%.'.$domain);
                        }
                    })
                    ->delete();
            }
        } catch (Throwable) {
            // pgsql_rag is optional in partial/dev installs.
        }
    }
};
