<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GPS (Genealogical Proof Standard) Research Task Management
 *
 * Creates tables for structured research methodology:
 * - gps_research_tasks: Research questions with hypothesis and status
 * - gps_research_logs: Search activity logging including negative results
 * - gps_assessments: 5-element GPS compliance scoring
 *
 * @see docs/future-enhancements.md - Priority 4: Research Task Management
 */
return new class extends Migration
{
    public function up(): void
    {
        // GPS Research Tasks - structured research questions per person
        DB::statement("
            CREATE TABLE IF NOT EXISTS gps_research_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                tree_id INT UNSIGNED NOT NULL,
                task_type ENUM('birth', 'death', 'marriage', 'parentage', 'identity', 'location', 'occupation', 'migration', 'military', 'other') NOT NULL,
                question TEXT NOT NULL COMMENT 'The specific research question being investigated',
                hypothesis TEXT NULL COMMENT 'Proposed answer or working theory',
                status ENUM('open', 'in_progress', 'resolved', 'inconclusive', 'abandoned') NOT NULL DEFAULT 'open',
                priority ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
                assigned_to INT UNSIGNED NULL COMMENT 'User ID if assigned',
                conclusion TEXT NULL COMMENT 'Final written conclusion (GPS element 5)',
                evidence_summary JSON NULL COMMENT 'Summary of direct/indirect/negative evidence',
                due_date DATE NULL,
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_person (person_id),
                INDEX idx_tree (tree_id),
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_type_status (task_type, status),

                CONSTRAINT fk_gps_task_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE,
                CONSTRAINT fk_gps_task_tree FOREIGN KEY (tree_id)
                    REFERENCES genealogy_trees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // GPS Research Logs - detailed search activity tracking
        DB::statement("
            CREATE TABLE IF NOT EXISTS gps_research_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL,
                log_type ENUM('search', 'analysis', 'conclusion', 'note') NOT NULL DEFAULT 'search',
                repository_searched VARCHAR(255) NULL COMMENT 'Name of repository/archive searched',
                repository_url VARCHAR(500) NULL,
                search_terms TEXT NULL COMMENT 'Exact search terms used',
                date_range_searched VARCHAR(100) NULL COMMENT 'Date range covered (e.g., 1850-1870)',
                location_searched VARCHAR(255) NULL COMMENT 'Geographic area searched',
                record_types_searched JSON NULL COMMENT 'Types of records searched (census, vital, church, etc.)',
                results_summary TEXT NULL COMMENT 'What was found or not found',
                negative_result BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE if search yielded no relevant results',
                source_ids_found JSON NULL COMMENT 'Array of source IDs found/created',
                media_ids_found JSON NULL COMMENT 'Array of media IDs found/created',
                search_duration_minutes INT UNSIGNED NULL,
                notes TEXT NULL,
                searched_at TIMESTAMP NULL COMMENT 'When the search was performed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_task (task_id),
                INDEX idx_person (person_id),
                INDEX idx_negative (negative_result),
                INDEX idx_repository (repository_searched),
                INDEX idx_log_type (log_type),

                CONSTRAINT fk_gps_log_task FOREIGN KEY (task_id)
                    REFERENCES gps_research_tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_gps_log_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // GPS Assessments - 5-element GPS compliance scoring
        DB::statement("
            CREATE TABLE IF NOT EXISTS gps_assessments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL,

                -- GPS Element 1: Reasonably exhaustive search
                exhaustive_search_score TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 score',
                exhaustive_search_notes TEXT NULL,
                repositories_checked JSON NULL COMMENT 'List of repositories searched',
                repositories_remaining JSON NULL COMMENT 'Repositories still to check',

                -- GPS Element 2: Complete and accurate source citations
                source_citations_complete BOOLEAN NOT NULL DEFAULT FALSE,
                citation_issues JSON NULL COMMENT 'List of incomplete/inaccurate citations',

                -- GPS Element 3: Evidence analysis and correlation
                evidence_analysis_complete BOOLEAN NOT NULL DEFAULT FALSE,
                direct_evidence_count INT UNSIGNED DEFAULT 0,
                indirect_evidence_count INT UNSIGNED DEFAULT 0,
                negative_evidence_count INT UNSIGNED DEFAULT 0,
                evidence_correlation_notes TEXT NULL,

                -- GPS Element 4: Conflicting evidence resolution
                conflicting_evidence_exists BOOLEAN NOT NULL DEFAULT FALSE,
                conflicting_evidence_resolved BOOLEAN NOT NULL DEFAULT FALSE,
                conflict_resolution_notes TEXT NULL,

                -- GPS Element 5: Sound written conclusion
                sound_conclusion BOOLEAN NOT NULL DEFAULT FALSE,
                conclusion_reasoning TEXT NULL,

                -- Overall assessment
                overall_score TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 composite GPS score',
                gps_compliant BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE if all 5 elements satisfied',
                assessor_notes TEXT NULL,
                assessed_by INT UNSIGNED NULL COMMENT 'User who performed assessment',
                assessed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_task (task_id),
                INDEX idx_person (person_id),
                INDEX idx_compliant (gps_compliant),
                INDEX idx_score (overall_score),

                CONSTRAINT fk_gps_assess_task FOREIGN KEY (task_id)
                    REFERENCES gps_research_tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_gps_assess_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Standard repositories for genealogical research
        DB::statement("
            CREATE TABLE IF NOT EXISTS gps_standard_repositories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(500) NULL,
                category ENUM('vital_records', 'census', 'church', 'military', 'immigration', 'land', 'probate', 'newspaper', 'cemetery', 'dna', 'other') NOT NULL,
                geographic_coverage JSON NULL COMMENT 'Countries/states covered',
                temporal_coverage VARCHAR(100) NULL COMMENT 'Date range covered',
                is_free BOOLEAN NOT NULL DEFAULT FALSE,
                requires_subscription VARCHAR(255) NULL COMMENT 'Subscription type needed',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                UNIQUE INDEX idx_name (name),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed standard repositories
        $this->seedStandardRepositories();
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::statement('DROP TABLE IF EXISTS gps_standard_repositories');
        DB::statement('DROP TABLE IF EXISTS gps_assessments');
        DB::statement('DROP TABLE IF EXISTS gps_research_logs');
        DB::statement('DROP TABLE IF EXISTS gps_research_tasks');
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedStandardRepositories(): void
    {
        $repositories = [
            // Vital Records
            ['FamilySearch', 'https://www.familysearch.org', 'vital_records', '["USA", "UK", "Europe", "Global"]', '1500-present', true, null],
            ['Ancestry.com', 'https://www.ancestry.com', 'vital_records', '["USA", "UK", "Canada", "Australia"]', '1500-present', false, 'Ancestry subscription'],
            ['FindMyPast', 'https://www.findmypast.com', 'vital_records', '["UK", "Ireland", "USA"]', '1500-present', false, 'FindMyPast subscription'],
            ['MyHeritage', 'https://www.myheritage.com', 'vital_records', '["Global"]', '1500-present', false, 'MyHeritage subscription'],

            // Census
            ['National Archives (NARA)', 'https://www.archives.gov', 'census', '["USA"]', '1790-1950', true, null],
            ['Ancestry Census', 'https://www.ancestry.com', 'census', '["USA", "UK"]', '1790-present', false, 'Ancestry subscription'],

            // Church Records
            ['FamilySearch Church Records', 'https://www.familysearch.org', 'church', '["Global"]', '1500-present', true, null],
            ['Catholic Heritage Archive', 'https://www.catholicheritage.net', 'church', '["USA Catholic"]', '1600-present', false, 'Subscription'],

            // Military
            ['Fold3', 'https://www.fold3.com', 'military', '["USA"]', '1700-present', false, 'Fold3 subscription'],
            ['NARA Military Records', 'https://www.archives.gov/veterans', 'military', '["USA"]', '1775-present', true, null],

            // Immigration
            ['Ellis Island', 'https://www.libertyellisfoundation.org', 'immigration', '["USA"]', '1892-1957', true, null],
            ['Castle Garden', 'https://www.castlegarden.org', 'immigration', '["USA"]', '1820-1892', true, null],
            ['Ancestry Immigration', 'https://www.ancestry.com', 'immigration', '["USA", "UK", "Canada"]', '1600-present', false, 'Ancestry subscription'],

            // Land Records
            ['BLM Land Records', 'https://glorecords.blm.gov', 'land', '["USA"]', '1788-present', true, null],

            // Probate
            ['FamilySearch Probate', 'https://www.familysearch.org', 'probate', '["USA", "UK"]', '1600-present', true, null],

            // Newspapers
            ['Chronicling America (LOC)', 'https://chroniclingamerica.loc.gov', 'newspaper', '["USA"]', '1777-1963', true, null],
            ['Newspapers.com', 'https://www.newspapers.com', 'newspaper', '["USA", "Global"]', '1700-present', false, 'Newspapers.com subscription'],
            ['GenealogyBank', 'https://www.genealogybank.com', 'newspaper', '["USA"]', '1690-present', false, 'GenealogyBank subscription'],

            // Cemetery
            ['FindAGrave', 'https://www.findagrave.com', 'cemetery', '["Global"]', 'All periods', true, null],
            ['BillionGraves', 'https://billiongraves.com', 'cemetery', '["Global"]', 'All periods', true, null],

            // DNA
            ['AncestryDNA', 'https://www.ancestry.com/dna', 'dna', '["Global"]', 'Modern', false, 'AncestryDNA test'],
            ['23andMe', 'https://www.23andme.com', 'dna', '["Global"]', 'Modern', false, '23andMe test'],
            ['FamilyTreeDNA', 'https://www.familytreedna.com', 'dna', '["Global"]', 'Modern', false, 'FTDNA test'],
            ['GEDmatch', 'https://www.gedmatch.com', 'dna', '["Global"]', 'Modern', true, null],
        ];

        foreach ($repositories as $repo) {
            DB::insert("
                INSERT INTO gps_standard_repositories
                (name, url, category, geographic_coverage, temporal_coverage, is_free, requires_subscription)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", $repo);
        }
    }
};
