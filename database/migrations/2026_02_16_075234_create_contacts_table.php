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
            CREATE TABLE contacts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                external_id VARCHAR(255) NOT NULL COMMENT 'Nextcloud UID',
                full_name VARCHAR(255),
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                nickname VARCHAR(100),
                emails JSON COMMENT '[{type: work, email: ...}, ...]',
                phones JSON COMMENT '[{type: mobile, number: ...}, ...]',
                addresses JSON,
                organization VARCHAR(255),
                title VARCHAR(255),
                birthday DATE,
                notes TEXT,
                photo_url TEXT,
                categories JSON COMMENT 'tags/groups',
                raw_vcard TEXT,
                rag_indexed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_external_id (external_id),
                KEY idx_full_name (full_name),
                KEY idx_organization (organization)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS contacts");
    }
};
