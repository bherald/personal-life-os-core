<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add hidden column
        try {
            DB::statement("ALTER TABLE file_registry_faces ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Add favorite column
        try {
            DB::statement("ALTER TABLE file_registry_faces ADD COLUMN favorite TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Composite index for faces page queries
        try {
            DB::statement("CREATE INDEX idx_frf_name_hidden ON file_registry_faces (person_name, hidden)");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("DROP INDEX idx_frf_name_hidden ON file_registry_faces");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE file_registry_faces DROP COLUMN hidden");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE file_registry_faces DROP COLUMN favorite");
        } catch (\Exception $e) {}
    }
};
