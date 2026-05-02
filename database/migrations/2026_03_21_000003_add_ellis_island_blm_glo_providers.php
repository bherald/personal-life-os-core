<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GEN-2 + GEN-3: Register Ellis Island and BLM GLO genealogy providers
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT IGNORE INTO genealogy_research_providers
                (provider_id, provider_name, provider_class, provider_type, base_url,
                 auth_type, capabilities, rate_limit_rpm, is_active, priority, notes)
             VALUES (?, ?, ?, 'scrape', ?, 'none', ?, 60, 1, 60, ?)",
            [
                'ellis_island',
                'Ellis Island',
                'App\\Services\\Genealogy\\Providers\\EllisIslandProvider',
                'https://heritage.statueofliberty.org',
                json_encode(['search_persons' => true, 'search_records' => true, 'get_record' => true]),
                'Statue of Liberty - Ellis Island Foundation. ~65M passenger records 1892-1957.',
            ]
        );

        DB::insert(
            "INSERT IGNORE INTO genealogy_research_providers
                (provider_id, provider_name, provider_class, provider_type, base_url,
                 auth_type, capabilities, rate_limit_rpm, is_active, priority, notes)
             VALUES (?, ?, ?, 'scrape', ?, 'none', ?, 60, 1, 65, ?)",
            [
                'blm_glo',
                'BLM GLO Land Records',
                'App\\Services\\Genealogy\\Providers\\BLMGLOProvider',
                'https://glorecords.blm.gov',
                json_encode(['search_persons' => true, 'search_records' => true, 'get_record' => true]),
                'Bureau of Land Management General Land Office. ~5M land patents 1788-present.',
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM genealogy_research_providers WHERE provider_id IN ('ellis_island', 'blm_glo')");
    }
};
