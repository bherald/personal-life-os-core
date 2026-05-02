<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add catalog-related columns to folder_semantics table
 *
 * These columns support the File Catalog feature:
 * - folder_path: Direct path reference for exclusion/inclusion patterns
 * - exclude_from_catalog: Flag to exclude folder from catalog scanning
 * - force_include_in_catalog: Flag to override parent exclusion
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return 'mysql';
    }

    public function up(): void
    {
        $columns = DB::select("SHOW COLUMNS FROM folder_semantics");
        $columnNames = array_map(fn($c) => $c->Field, $columns);

        if (!in_array('folder_path', $columnNames)) {
            DB::statement("
                ALTER TABLE folder_semantics
                ADD COLUMN folder_path VARCHAR(500) NULL
                    COMMENT 'Full folder path for catalog exclusion patterns'
                    AFTER path_pattern
            ");
            DB::statement("CREATE INDEX idx_folder_path ON folder_semantics(folder_path(200))");
        }

        if (!in_array('exclude_from_catalog', $columnNames)) {
            DB::statement("
                ALTER TABLE folder_semantics
                ADD COLUMN exclude_from_catalog BOOLEAN DEFAULT FALSE
                    COMMENT 'Exclude this folder from file catalog scanning'
                    AFTER folder_path
            ");
            DB::statement("CREATE INDEX idx_exclude_catalog ON folder_semantics(exclude_from_catalog)");
        }

        if (!in_array('force_include_in_catalog', $columnNames)) {
            DB::statement("
                ALTER TABLE folder_semantics
                ADD COLUMN force_include_in_catalog BOOLEAN DEFAULT FALSE
                    COMMENT 'Override parent exclusion and include in catalog'
                    AFTER exclude_from_catalog
            ");
            DB::statement("CREATE INDEX idx_force_include ON folder_semantics(force_include_in_catalog)");
        }
    }

    public function down(): void
    {
        $columns = DB::select("SHOW COLUMNS FROM folder_semantics");
        $columnNames = array_map(fn($c) => $c->Field, $columns);

        if (in_array('force_include_in_catalog', $columnNames)) {
            DB::statement("ALTER TABLE folder_semantics DROP INDEX idx_force_include");
            DB::statement("ALTER TABLE folder_semantics DROP COLUMN force_include_in_catalog");
        }

        if (in_array('exclude_from_catalog', $columnNames)) {
            DB::statement("ALTER TABLE folder_semantics DROP INDEX idx_exclude_catalog");
            DB::statement("ALTER TABLE folder_semantics DROP COLUMN exclude_from_catalog");
        }

        if (in_array('folder_path', $columnNames)) {
            DB::statement("ALTER TABLE folder_semantics DROP INDEX idx_folder_path");
            DB::statement("ALTER TABLE folder_semantics DROP COLUMN folder_path");
        }
    }
};
