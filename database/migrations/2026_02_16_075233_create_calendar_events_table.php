<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE calendar_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                external_id VARCHAR(255) NOT NULL COMMENT 'Nextcloud UID',
                calendar_name VARCHAR(255),
                title VARCHAR(500) NOT NULL,
                description TEXT,
                location VARCHAR(500),
                start_at DATETIME NOT NULL,
                end_at DATETIME,
                all_day BOOLEAN DEFAULT FALSE,
                recurrence_rule TEXT,
                attendees JSON,
                raw_ical TEXT,
                rag_indexed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_external_id (external_id),
                KEY idx_start_at (start_at),
                KEY idx_calendar (calendar_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS calendar_events");
    }
};
