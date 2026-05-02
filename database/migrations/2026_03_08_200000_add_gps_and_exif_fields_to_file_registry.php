<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // GPS coordinates + reverse-geocoded location name
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN gps_latitude DECIMAL(10,8) NULL AFTER exif_checked"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN gps_longitude DECIMAL(11,8) NULL AFTER gps_latitude"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN gps_location VARCHAR(500) NULL AFTER gps_longitude"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }

        // Camera hardware
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN camera_make VARCHAR(100) NULL AFTER gps_location"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN camera_model VARCHAR(150) NULL AFTER camera_make"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }

        // User-set metadata from photo editing software (Lightroom, digiKam, Apple Photos, etc.)
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN exif_rating TINYINT UNSIGNED NULL AFTER camera_model"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN exif_keywords TEXT NULL AFTER exif_rating"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }
        try { DB::statement("ALTER TABLE file_registry ADD COLUMN exif_caption TEXT NULL AFTER exif_keywords"); } catch (\Exception $e) { if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; }

        // Index for GPS backfill queries
        try { DB::statement("ALTER TABLE file_registry ADD INDEX idx_gps_location (gps_latitude, gps_location(20))"); } catch (\Exception $e) { /* index may exist */ }
    }

    public function down(): void
    {
        foreach (['gps_latitude','gps_longitude','gps_location','camera_make','camera_model','exif_rating','exif_keywords','exif_caption'] as $col) {
            try { DB::statement("ALTER TABLE file_registry DROP COLUMN {$col}"); } catch (\Exception $e) {}
        }
    }
};
