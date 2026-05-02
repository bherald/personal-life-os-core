<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN exif_location_written TINYINT NULL AFTER exif_tags_written_at");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e;
        }
    }

    public function down(): void
    {
        try { DB::statement("ALTER TABLE file_registry DROP COLUMN exif_location_written"); } catch (\Exception $e) {}
    }
};
