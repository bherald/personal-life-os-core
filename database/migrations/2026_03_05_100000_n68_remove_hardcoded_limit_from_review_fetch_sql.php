<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N68: Remove hardcoded LIMIT 100 from review_type_registry fetch_sql
 *
 * Face (242 pending), proposal, and change_proposal all had LIMIT 100 hardcoded,
 * capping infinite scroll at 100 items even when the DB has more. ReviewTypeRegistryService
 * already handles pagination via PHP array_slice, so SQL LIMIT is redundant and harmful.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Remove LIMIT 100 from face fetch_sql (242 pending items, was capped at 100)
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = REGEXP_REPLACE(fetch_sql, ' LIMIT [0-9]+$', '')
            WHERE name = 'face'
        ");

        // Remove LIMIT 100 from proposal fetch_sql
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = REGEXP_REPLACE(fetch_sql, ' LIMIT [0-9]+$', '')
            WHERE name = 'proposal'
        ");

        // Remove LIMIT 100 from change_proposal fetch_sql
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = REGEXP_REPLACE(fetch_sql, ' LIMIT [0-9]+$', '')
            WHERE name = 'change_proposal'
        ");
    }

    public function down(): void
    {
        // Restore LIMIT 100 to face
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = CONCAT(fetch_sql, ' LIMIT 100')
            WHERE name = 'face'
        ");

        // Restore LIMIT 100 to proposal
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = CONCAT(fetch_sql, ' LIMIT 100')
            WHERE name = 'proposal'
        ");

        // Restore LIMIT 100 to change_proposal
        DB::statement("
            UPDATE review_type_registry
            SET fetch_sql = CONCAT(fetch_sql, ' LIMIT 100')
            WHERE name = 'change_proposal'
        ");
    }
};
