<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Index on current_path for JOIN with genealogy_media.nextcloud_path
        // Using prefix index (255) since current_path is TEXT type
        try {
            DB::statement("CREATE INDEX idx_file_registry_current_path ON file_registry (current_path(255))");
        } catch (\Exception $e) {
            // Index may already exist
        }

        // Index on filename for reverse lookup (SUBSTRING_INDEX matching)
        try {
            DB::statement("CREATE INDEX idx_file_registry_filename ON file_registry (filename)");
        } catch (\Exception $e) {
            // Index may already exist
        }
    }

    public function down(): void
    {
        try {
            DB::statement("DROP INDEX idx_file_registry_current_path ON file_registry");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }
        try {
            DB::statement("DROP INDEX idx_file_registry_filename ON file_registry");
        } catch (\Exception $e) {
            // Ignore if doesn't exist
        }
    }
};
