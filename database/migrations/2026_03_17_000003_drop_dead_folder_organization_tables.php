<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop 5 empty tables from the defunct folder organization/exclusion system.
 *
 * These were created Jan-Feb 2026 as part of the File Organizer feature,
 * which was downsized to File Catalog (read-only). All tables confirmed
 * empty on prod. No folder exclusions — the full configured library is now scanned.
 */
return new class extends Migration
{
    // Order matters: child tables (FK references) before parents
    private const TABLES = [
        'file_organization_rule_log',
        'file_organization_rules',
        'folder_semantics',
        'folder_research_queue',
        'file_registry_folder_status',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                // Safety: only drop if empty
                $count = DB::selectOne("SELECT COUNT(*) as cnt FROM {$table}");
                if ((int) $count->cnt === 0) {
                    Schema::drop($table);
                }
            }
        }
    }

    public function down(): void
    {
        // Tables can be recreated from original migrations if needed
        // 2026_01_19_000002, 2026_01_23_165646, 2026_02_07_000003
    }
};
