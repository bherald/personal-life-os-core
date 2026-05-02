<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FAN (Friends-Associates-Neighbors) Cluster Research Tables
 *
 * Implements professional genealogy FAN methodology for identifying research clusters.
 * FAN clusters help researchers identify potential family connections by analyzing
 * social networks around target ancestors.
 *
 * @see App\Services\Genealogy\FANClusterService
 * @see Elizabeth Shown Mills, "Evidence Explained"
 * @see Board for Certification of Genealogists - Genealogical Proof Standard
 */
return new class extends Migration
{
    public function up(): void
    {
        // FAN Clusters - main cluster definitions
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_fan_clusters (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL COMMENT 'FK to genealogy_persons - the research subject',
                cluster_name VARCHAR(255) NOT NULL COMMENT 'Descriptive name for this cluster',
                research_period VARCHAR(50) NULL COMMENT 'Time period e.g. 1850-1880',
                location VARCHAR(255) NULL COMMENT 'Geographic focus of cluster',
                notes TEXT NULL COMMENT 'Research notes and methodology',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_person_id (person_id),
                INDEX idx_research_period (research_period),
                INDEX idx_location (location(100)),

                CONSTRAINT fk_fan_cluster_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // FAN Cluster Members - individuals in the cluster
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_fan_members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cluster_id INT UNSIGNED NOT NULL COMMENT 'FK to genealogy_fan_clusters',
                member_person_id INT UNSIGNED NULL COMMENT 'FK to genealogy_persons if linked',
                member_name VARCHAR(255) NOT NULL COMMENT 'Name as appears in source (for unlinked persons)',
                relationship_type ENUM('friend', 'associate', 'neighbor', 'witness', 'business', 'church', 'other') NOT NULL DEFAULT 'other',
                source_record_type VARCHAR(50) NOT NULL COMMENT 'census, marriage, deed, probate, church, etc.',
                source_citation TEXT NULL COMMENT 'Full citation for this connection',
                interaction_date DATE NULL COMMENT 'Date of documented interaction',
                interaction_description TEXT NULL COMMENT 'Description of the interaction/connection',
                confidence ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_cluster_id (cluster_id),
                INDEX idx_member_person_id (member_person_id),
                INDEX idx_relationship_type (relationship_type),
                INDEX idx_source_record_type (source_record_type),
                INDEX idx_interaction_date (interaction_date),
                INDEX idx_confidence (confidence),
                INDEX idx_cluster_relationship (cluster_id, relationship_type),
                INDEX idx_member_name (member_name(100)),

                CONSTRAINT fk_fan_member_cluster FOREIGN KEY (cluster_id)
                    REFERENCES genealogy_fan_clusters(id) ON DELETE CASCADE,
                CONSTRAINT fk_fan_member_person FOREIGN KEY (member_person_id)
                    REFERENCES genealogy_persons(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS genealogy_fan_members');
        DB::statement('DROP TABLE IF EXISTS genealogy_fan_clusters');
    }
};
