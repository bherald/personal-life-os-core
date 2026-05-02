<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Genealogy Place Authority Database
     *
     * Normalized place hierarchy for genealogy events.
     * Supports place name variants, historical boundaries, and geocoding.
     */
    public function up(): void
    {
        // Create genealogy_places table
        DB::statement("
            CREATE TABLE genealogy_places (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL COMMENT 'Display name (e.g., Philadelphia, Pennsylvania, USA)',
                normalized_name VARCHAR(255) NOT NULL COMMENT 'Lowercase, no punctuation for matching',
                short_name VARCHAR(100) NULL COMMENT 'Short form (e.g., Philadelphia)',
                parent_id INT UNSIGNED NULL COMMENT 'Hierarchy: city -> county -> state -> country',
                place_type ENUM('country', 'state', 'county', 'city', 'township', 'district', 'address', 'other') NULL,
                latitude DECIMAL(10, 6) NULL,
                longitude DECIMAL(10, 6) NULL,
                historical_boundaries JSON NULL COMMENT 'Array of {start_year, end_year, boundary_geojson}',
                aliases JSON NULL COMMENT 'Array of alternate names/spellings',
                external_ids JSON NULL COMMENT 'FamilySearch, Wikidata, GeoNames IDs',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_normalized_name (normalized_name),
                INDEX idx_parent (parent_id),
                INDEX idx_place_type (place_type),
                INDEX idx_coords (latitude, longitude),
                FULLTEXT INDEX ft_name (name, short_name),
                CONSTRAINT fk_place_parent FOREIGN KEY (parent_id) REFERENCES genealogy_places(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add place_id column to genealogy_events
        DB::statement("
            ALTER TABLE genealogy_events
            ADD COLUMN place_id INT UNSIGNED NULL AFTER event_place,
            ADD INDEX idx_place_id (place_id),
            ADD CONSTRAINT fk_event_place FOREIGN KEY (place_id) REFERENCES genealogy_places(id) ON DELETE SET NULL
        ");

        // Add place_id column to genealogy_family_events
        DB::statement("
            ALTER TABLE genealogy_family_events
            ADD COLUMN place_id INT UNSIGNED NULL AFTER event_place,
            ADD INDEX idx_family_event_place_id (place_id),
            ADD CONSTRAINT fk_family_event_place FOREIGN KEY (place_id) REFERENCES genealogy_places(id) ON DELETE SET NULL
        ");

        // Create place aliases lookup table for faster matching
        DB::statement("
            CREATE TABLE genealogy_place_aliases (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                place_id INT UNSIGNED NOT NULL,
                alias VARCHAR(255) NOT NULL,
                normalized_alias VARCHAR(255) NOT NULL,
                alias_type ENUM('spelling', 'historical', 'abbreviation', 'translation', 'common') DEFAULT 'spelling',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_normalized_alias (normalized_alias),
                INDEX idx_place (place_id),
                CONSTRAINT fk_alias_place FOREIGN KEY (place_id) REFERENCES genealogy_places(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        // Remove foreign keys first
        DB::statement("ALTER TABLE genealogy_events DROP FOREIGN KEY fk_event_place");
        DB::statement("ALTER TABLE genealogy_events DROP COLUMN place_id");

        DB::statement("ALTER TABLE genealogy_family_events DROP FOREIGN KEY fk_family_event_place");
        DB::statement("ALTER TABLE genealogy_family_events DROP COLUMN place_id");

        DB::statement("DROP TABLE IF EXISTS genealogy_place_aliases");
        DB::statement("DROP TABLE IF EXISTS genealogy_places");
    }
};
