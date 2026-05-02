<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GEN-1: Source Registry for Intelligent Tool Selection.
 *
 * Maps record types → archives → tools with era/geographic coverage.
 * Replaces hardcoded RepositoryRoutingService matrix with queryable data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('genealogy_source_registry')) {
            return;
        }

        DB::statement("
            CREATE TABLE genealogy_source_registry (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                archive_name VARCHAR(200) NOT NULL,
                archive_url VARCHAR(500),
                record_types JSON NOT NULL COMMENT 'Array of record types: vital, census, church, military, immigration, land, probate, newspaper, cemetery, death, family_tree, obituary, labor',
                eras JSON COMMENT 'Array of applicable eras: colonial, revolutionary, antebellum, civil_war, gilded_age, progressive, interwar, modern, all',
                regions JSON COMMENT 'Array of applicable regions: new_england, mid_atlantic, south, midwest, great_plains, southwest, west, uk_ireland, scandinavia, france, eastern_europe, italy, canada, german_origin, all',
                ethnicities JSON COMMENT 'Array: african_american, jewish, default, all',
                tool_name VARCHAR(100) COMMENT 'FK to agent_tool_registry.name — null if no automated tool',
                priority TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=highest, 8=lowest',
                coverage_start_year SMALLINT UNSIGNED COMMENT 'Earliest year of records',
                coverage_end_year SMALLINT UNSIGNED COMMENT 'Latest year of records',
                access_type ENUM('free', 'subscription', 'library', 'foia', 'mixed') NOT NULL DEFAULT 'free',
                notes TEXT,
                search_count INT UNSIGNED NOT NULL DEFAULT 0,
                hit_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_searched_at TIMESTAMP NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_source_registry_tool (tool_name),
                INDEX idx_source_registry_active_priority (is_active, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('genealogy_source_registry');
    }
};
