<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            // When the image was digitized/scanned — preserves the scan-era EXIF date
            // that was wrongly stored in DateTimeOriginal by the scanner software
            $table->timestamp('scan_date')->nullable()->after('date_taken_reasoning');

            // Whether this file is a scanned physical photograph (slide, print, negative)
            $table->tinyInteger('is_scan')->default(0)->after('scan_date');

            // What triggered scan detection: "path:slides", "grayscale_recent_exif", etc.
            $table->string('scan_context', 500)->nullable()->after('is_scan');

            // AI-estimated dates that need human confirmation before authoritative use
            $table->tinyInteger('date_needs_review')->default(0)->after('scan_context');

            $table->index('is_scan');
            $table->index('date_needs_review');
        });

        // Extend date_taken_source enum — must list ALL existing values plus new ones.
        // New values:
        //   scan_exif       = EXIF date moved to scan_date (digitization date, not photo date)
        //   ai_visual_high  = AI visual estimate, confidence >= 0.65
        //   ai_visual_medium= AI visual estimate, confidence 0.45-0.64
        //   ai_visual_low   = AI visual estimate, confidence < 0.45
        DB::statement("
            ALTER TABLE file_registry
            MODIFY COLUMN date_taken_source ENUM(
                'exif_original',
                'exif_digitized',
                'exif_modified',
                'path_extracted',
                'filename_extracted',
                'ai_estimated',
                'user_manual',
                'file_modified',
                'scan_exif',
                'ai_visual_high',
                'ai_visual_medium',
                'ai_visual_low'
            )
        ");
    }

    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->dropIndex(['is_scan']);
            $table->dropIndex(['date_needs_review']);
            $table->dropColumn(['scan_date', 'is_scan', 'scan_context', 'date_needs_review']);
        });

        DB::statement("
            ALTER TABLE file_registry
            MODIFY COLUMN date_taken_source ENUM(
                'exif_original',
                'exif_digitized',
                'exif_modified',
                'path_extracted',
                'filename_extracted',
                'ai_estimated',
                'user_manual',
                'file_modified'
            )
        ");
    }
};
