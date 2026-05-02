<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create youtube_chapters table for storing video chapter/timestamp data.
     *
     * Supports multiple extraction sources:
     * - description: Parsed from video description timestamps
     * - yt_dlp: Extracted via yt-dlp --dump-json
     * - invidious: From Invidious API
     * - twelvelabs: TwelveLabs API (future)
     * - ai_generated: Generated from transcript via AIService
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS youtube_chapters (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                video_id VARCHAR(11) NOT NULL,
                chapter_index TINYINT UNSIGNED NOT NULL,
                title VARCHAR(500) NOT NULL,
                start_time DECIMAL(10,3) NOT NULL,
                end_time DECIMAL(10,3) NULL,
                duration DECIMAL(10,3) GENERATED ALWAYS AS (COALESCE(end_time, 0) - start_time) STORED,
                source ENUM('description', 'yt_dlp', 'invidious', 'twelvelabs', 'ai_generated') NOT NULL,
                transcript_segment TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY unique_video_chapter (video_id, chapter_index),
                INDEX idx_video_id (video_id),
                INDEX idx_source (source),
                INDEX idx_start_time (video_id, start_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS youtube_chapters");
    }
};
