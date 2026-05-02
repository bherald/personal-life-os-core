<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Content-Dependent Chunking support for file_registry
     *
     * Adds chunk_hashes JSON column to store FastCDC chunk hashes
     * for partial duplicate detection in large files.
     */
    public function up(): void
    {
        // Add chunk_hashes JSON column to file_registry
        DB::statement("
            ALTER TABLE file_registry
            ADD COLUMN chunk_hashes JSON NULL COMMENT 'FastCDC chunk hashes: [{offset, size, hash}, ...]',
            ADD COLUMN chunk_algorithm VARCHAR(20) NULL DEFAULT 'fastcdc' COMMENT 'Chunking algorithm used',
            ADD COLUMN chunk_count INT UNSIGNED NULL COMMENT 'Number of chunks',
            ADD COLUMN chunked_at TIMESTAMP NULL COMMENT 'When chunking was performed'
        ");

        // Create index for finding files that need chunking
        DB::statement("
            CREATE INDEX idx_file_registry_chunked_at ON file_registry(chunked_at)
        ");

        // Create table for chunk-based similarity detection
        DB::statement("
            CREATE TABLE file_registry_chunk_matches (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                file_registry_id_1 BIGINT UNSIGNED NOT NULL,
                file_registry_id_2 BIGINT UNSIGNED NOT NULL,
                matching_chunks INT UNSIGNED NOT NULL COMMENT 'Number of matching chunk hashes',
                total_chunks_1 INT UNSIGNED NOT NULL,
                total_chunks_2 INT UNSIGNED NOT NULL,
                similarity_ratio DECIMAL(5, 4) NOT NULL COMMENT 'Jaccard similarity of chunk sets',
                matching_bytes BIGINT UNSIGNED NULL COMMENT 'Approximate bytes in common',
                status ENUM('pending_review', 'confirmed_partial', 'false_positive', 'different_versions') DEFAULT 'pending_review',
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_file_1 (file_registry_id_1),
                INDEX idx_file_2 (file_registry_id_2),
                INDEX idx_similarity (similarity_ratio),
                INDEX idx_status (status),
                CONSTRAINT fk_chunk_match_file_1 FOREIGN KEY (file_registry_id_1) REFERENCES file_registry(id) ON DELETE CASCADE,
                CONSTRAINT fk_chunk_match_file_2 FOREIGN KEY (file_registry_id_2) REFERENCES file_registry(id) ON DELETE CASCADE,
                CONSTRAINT chk_chunk_ordered_pair CHECK (file_registry_id_1 < file_registry_id_2),
                UNIQUE KEY unique_chunk_pair (file_registry_id_1, file_registry_id_2)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create hash lookup table for faster matching (inverted index)
        DB::statement("
            CREATE TABLE file_registry_chunk_index (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chunk_hash CHAR(16) NOT NULL COMMENT 'First 16 chars of SHA256 chunk hash',
                file_registry_id BIGINT UNSIGNED NOT NULL,
                chunk_offset BIGINT UNSIGNED NOT NULL,
                chunk_size INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_chunk_hash (chunk_hash),
                INDEX idx_file (file_registry_id),
                CONSTRAINT fk_chunk_index_file FOREIGN KEY (file_registry_id) REFERENCES file_registry(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS file_registry_chunk_index");
        DB::statement("DROP TABLE IF EXISTS file_registry_chunk_matches");

        DB::statement("
            ALTER TABLE file_registry
            DROP COLUMN IF EXISTS chunk_hashes,
            DROP COLUMN IF EXISTS chunk_algorithm,
            DROP COLUMN IF EXISTS chunk_count,
            DROP COLUMN IF EXISTS chunked_at
        ");
    }
};
