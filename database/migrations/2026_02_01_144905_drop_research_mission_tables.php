<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drops research mission tables from PostgreSQL (pgsql_rag connection)
     * These tables are being deprecated as the missions feature was merged into topics.
     */
    public function up(): void
    {
        $connection = DB::connection('pgsql_rag');

        // Drop tables in order (respecting foreign key dependencies)
        $connection->statement('DROP TABLE IF EXISTS verification_attempts CASCADE');
        $connection->statement('DROP TABLE IF EXISTS research_facts CASCADE');
        $connection->statement('DROP TABLE IF EXISTS discovered_sources CASCADE');
        $connection->statement('DROP TABLE IF EXISTS research_missions CASCADE');
    }

    /**
     * Reverse the migrations.
     * Note: This does not restore the tables - they would need to be recreated from scratch.
     */
    public function down(): void
    {
        // Tables are not recreated - this is a permanent removal
        // Git history preserves the original table definitions if ever needed
    }
};
