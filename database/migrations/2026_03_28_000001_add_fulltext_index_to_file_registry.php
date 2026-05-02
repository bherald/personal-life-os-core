<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add FULLTEXT index on ai_description + ai_detected_text for fast keyword search.
 *
 * Enables MATCH...AGAINST queries in UnifiedSearchService.searchMedia()
 * so users can find files by OCR text content and AI descriptions.
 *
 * InnoDB online DDL — table stays readable during index build (~30-60s on 73K rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Check if index already exists (idempotent)
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'file_registry'
              AND INDEX_NAME = 'ft_ai_search'
            LIMIT 1
        ");

        if (!$exists) {
            DB::statement("ALTER TABLE file_registry ADD FULLTEXT INDEX ft_ai_search (ai_description, ai_detected_text)");
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'file_registry'
              AND INDEX_NAME = 'ft_ai_search'
            LIMIT 1
        ");

        if ($exists) {
            DB::statement("ALTER TABLE file_registry DROP INDEX ft_ai_search");
        }
    }
};
