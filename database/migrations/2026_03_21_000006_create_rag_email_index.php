<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-6: Dedup tracking table for email RAG indexing.
 * Prevents re-indexing the same message from mbox archives.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE rag_email_index (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                message_hash VARCHAR(64) NOT NULL,
                subject VARCHAR(500) NULL,
                sender VARCHAR(255) NULL,
                message_date TIMESTAMP NULL,
                folder VARCHAR(255) NULL,
                indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_message_hash (message_hash),
                INDEX idx_folder (folder),
                INDEX idx_date (message_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS rag_email_index");
    }
};
