<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Thumbnail/Preview generation support for file registry
     */
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN thumbnail_generated_at TIMESTAMP NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN thumbnail_sizes JSON NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN thumbnail_error VARCHAR(255) NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Index for finding files needing thumbnails
        try {
            DB::statement("CREATE INDEX idx_file_registry_thumb_gen ON file_registry(thumbnail_generated_at)");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("DROP INDEX idx_file_registry_thumb_gen ON file_registry");
        } catch (\Exception $e) {
            // ignore
        }

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN thumbnail_generated_at");
        } catch (\Exception $e) {
            // ignore
        }

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN thumbnail_sizes");
        } catch (\Exception $e) {
            // ignore
        }

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN thumbnail_error");
        } catch (\Exception $e) {
            // ignore
        }
    }
};
