<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create youtube_transcripts table for persistent transcript storage.
     *
     * Stores full transcripts and timed segments from YouTube videos.
     * Supports multiple extraction sources for reliability tracking.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS youtube_transcripts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                video_id VARCHAR(20) NOT NULL COMMENT 'YouTube video ID (11 chars typical)',
                language VARCHAR(10) NOT NULL DEFAULT 'en' COMMENT 'Language code (en, es, fr, etc.)',
                content LONGTEXT NULL COMMENT 'Full transcript text (concatenated)',
                timed_content JSON NULL COMMENT 'Timestamped segments [{start, duration, text}]',
                source_method VARCHAR(50) NOT NULL COMMENT 'Extraction method: direct_api, invidious, piped, library, yt-dlp',
                duration_seconds INT UNSIGNED NULL COMMENT 'Video duration in seconds',
                word_count INT UNSIGNED NULL COMMENT 'Total word count',
                fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When transcript was fetched',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY unique_video_language (video_id, language),
                INDEX idx_video_id (video_id),
                INDEX idx_language (language),
                INDEX idx_fetched_at (fetched_at),
                INDEX idx_source_method (source_method)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS youtube_transcripts");
    }
};
