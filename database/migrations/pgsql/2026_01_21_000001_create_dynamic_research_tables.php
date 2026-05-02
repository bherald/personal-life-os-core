<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create tables for Dynamic Universal Research Framework
 *
 * Tables:
 * - research_missions: Research task definitions with progress tracking
 * - discovered_sources: Dynamically discovered web sources with safety/trust scores
 * - research_facts: Extracted facts with verification status
 * - verification_attempts: Audit log of fact verification attempts
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Create research_missions table
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_missions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                title VARCHAR(255) NOT NULL,
                description TEXT,
                mission_type VARCHAR(50) NOT NULL DEFAULT 'knowledge_capture',
                domain_category VARCHAR(100) DEFAULT 'general',
                query_template TEXT,
                constraints JSONB DEFAULT '{}'::jsonb,

                -- Progress tracking
                status VARCHAR(30) DEFAULT 'pending',
                progress_pct DECIMAL(5,2) DEFAULT 0,
                current_phase VARCHAR(50),
                phase_details JSONB DEFAULT '{}'::jsonb,

                -- Research parameters
                depth_level INTEGER DEFAULT 3 CHECK (depth_level >= 1 AND depth_level <= 10),
                verification_level VARCHAR(30) DEFAULT 'strict',
                max_sources INTEGER DEFAULT 20,
                time_limit_minutes INTEGER DEFAULT 30,

                -- Results tracking
                facts_discovered INTEGER DEFAULT 0,
                facts_verified INTEGER DEFAULT 0,
                facts_rejected INTEGER DEFAULT 0,
                sources_discovered INTEGER DEFAULT 0,
                sources_used INTEGER DEFAULT 0,

                -- Error tracking
                last_error TEXT,
                error_count INTEGER DEFAULT 0,

                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP,
                completed_at TIMESTAMP,

                -- Creator tracking
                created_by VARCHAR(50) DEFAULT 'system',
                workflow_run_id INTEGER
            )
        ");

        // Indexes for research_missions
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_missions_status ON research_missions(status)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_missions_domain ON research_missions(domain_category)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_missions_type ON research_missions(mission_type)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_missions_created ON research_missions(created_at DESC)
        ");

        // Create discovered_sources table (more comprehensive than research_sources)
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS discovered_sources (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                domain VARCHAR(255) NOT NULL,
                full_url TEXT,
                display_name VARCHAR(255),

                -- Classification
                source_type VARCHAR(50) DEFAULT 'webpage',
                domain_category VARCHAR(100) DEFAULT 'unknown',
                content_types JSONB DEFAULT '[]'::jsonb,
                specializations JSONB DEFAULT '[]'::jsonb,

                -- Safety & Trust (0.00-1.00 scale)
                safety_score DECIMAL(4,3) DEFAULT 0.500,
                trust_score DECIMAL(4,3) DEFAULT 0.500,
                safety_evaluation JSONB,
                is_whitelisted BOOLEAN DEFAULT FALSE,
                is_blacklisted BOOLEAN DEFAULT FALSE,
                blacklist_reason TEXT,
                requires_sandbox BOOLEAN DEFAULT TRUE,

                -- Access method
                access_method VARCHAR(30) DEFAULT 'scrape',
                api_endpoint TEXT,
                api_auth_type VARCHAR(30),
                api_key_env_var VARCHAR(100),
                rate_limit_rpm INTEGER DEFAULT 30,
                scrape_selectors JSONB,
                robots_txt_checked BOOLEAN DEFAULT FALSE,
                robots_txt_allows BOOLEAN DEFAULT TRUE,

                -- Health tracking
                success_count INTEGER DEFAULT 0,
                failure_count INTEGER DEFAULT 0,
                consecutive_failures INTEGER DEFAULT 0,
                last_success_at TIMESTAMP,
                last_failure_at TIMESTAMP,
                last_error_message TEXT,
                avg_response_ms INTEGER,
                is_active BOOLEAN DEFAULT TRUE,

                -- Discovery metadata
                discovered_by VARCHAR(50) DEFAULT 'ai',
                discovered_from_mission UUID,
                discovery_context TEXT,
                discovery_query TEXT,

                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT uq_discovered_sources_domain UNIQUE(domain)
            )
        ");

        // Indexes for discovered_sources
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_domain ON discovered_sources(domain)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_category ON discovered_sources(domain_category)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_active ON discovered_sources(is_active, trust_score DESC)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_safety ON discovered_sources(safety_score DESC)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_specializations ON discovered_sources USING GIN(specializations)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_dsources_content_types ON discovered_sources USING GIN(content_types)
        ");

        // Create research_facts table
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS research_facts (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                mission_id UUID REFERENCES research_missions(id) ON DELETE SET NULL,

                -- The fact itself
                fact_statement TEXT NOT NULL,
                fact_hash VARCHAR(64) NOT NULL,
                fact_type VARCHAR(50),
                domain_category VARCHAR(100),
                context_snippet TEXT,

                -- Verification status
                verification_status VARCHAR(30) DEFAULT 'unverified',
                confidence_score DECIMAL(5,4) DEFAULT 0,

                -- Verification details
                llm_stated BOOLEAN DEFAULT FALSE,
                llm_confidence DECIMAL(5,4),
                llm_model VARCHAR(100),
                external_sources_checked INTEGER DEFAULT 0,
                external_sources_confirmed INTEGER DEFAULT 0,
                external_sources_denied INTEGER DEFAULT 0,
                rag_cross_referenced BOOLEAN DEFAULT FALSE,
                rag_match_score DECIMAL(5,4),
                rag_match_document_ids JSONB DEFAULT '[]'::jsonb,

                -- Source tracking
                primary_source_id UUID REFERENCES discovered_sources(id) ON DELETE SET NULL,
                source_urls JSONB DEFAULT '[]'::jsonb,
                source_citations JSONB DEFAULT '[]'::jsonb,

                -- RAG integration
                indexed_to_rag BOOLEAN DEFAULT FALSE,
                rag_document_id BIGINT,

                -- Metadata
                related_entities JSONB DEFAULT '[]'::jsonb,
                tags JSONB DEFAULT '[]'::jsonb,
                notes TEXT,

                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_at TIMESTAMP,
                indexed_at TIMESTAMP
            )
        ");

        // Indexes for research_facts
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_facts_hash ON research_facts(fact_hash)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_mission ON research_facts(mission_id)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_status ON research_facts(verification_status)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_confidence ON research_facts(confidence_score DESC)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_domain ON research_facts(domain_category)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_tags ON research_facts USING GIN(tags)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_entities ON research_facts USING GIN(related_entities)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_facts_indexed ON research_facts(indexed_to_rag, verification_status)
        ");

        // Create verification_attempts table
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS verification_attempts (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                fact_id UUID NOT NULL REFERENCES research_facts(id) ON DELETE CASCADE,

                -- Verification method
                method VARCHAR(30) NOT NULL,
                source_id UUID REFERENCES discovered_sources(id) ON DELETE SET NULL,
                source_url TEXT,
                search_query TEXT,

                -- Result
                result VARCHAR(20) NOT NULL,
                confidence DECIMAL(5,4),
                evidence_snippet TEXT,
                evidence_url TEXT,

                -- Execution details
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                response_time_ms INTEGER,
                error_message TEXT,

                -- Additional metadata
                raw_response JSONB
            )
        ");

        // Indexes for verification_attempts
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verification_fact ON verification_attempts(fact_id)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verification_result ON verification_attempts(result)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verification_method ON verification_attempts(method)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_verification_executed ON verification_attempts(executed_at DESC)
        ");

        // Insert pre-approved whitelist sources
        $this->insertWhitelistSources();

        // Insert known blacklist patterns
        $this->insertBlacklistSources();
    }

    private function insertWhitelistSources(): void
    {
        $whitelistSources = [
            // Government sources
            ['domain' => 'loc.gov', 'display_name' => 'Library of Congress', 'domain_category' => 'government', 'specializations' => '["history", "genealogy", "newspapers", "archives"]', 'trust_score' => 0.95, 'safety_score' => 0.99],
            ['domain' => 'archives.gov', 'display_name' => 'National Archives', 'domain_category' => 'government', 'specializations' => '["history", "genealogy", "government_records"]', 'trust_score' => 0.95, 'safety_score' => 0.99],
            ['domain' => 'census.gov', 'display_name' => 'US Census Bureau', 'domain_category' => 'government', 'specializations' => '["demographics", "statistics", "genealogy"]', 'trust_score' => 0.95, 'safety_score' => 0.99],
            ['domain' => 'nih.gov', 'display_name' => 'NIH', 'domain_category' => 'government', 'specializations' => '["health", "medical", "research"]', 'trust_score' => 0.95, 'safety_score' => 0.99],
            ['domain' => 'nasa.gov', 'display_name' => 'NASA', 'domain_category' => 'government', 'specializations' => '["science", "space", "technology"]', 'trust_score' => 0.95, 'safety_score' => 0.99],

            // Educational sources
            ['domain' => 'edu', 'display_name' => 'Educational Institutions', 'domain_category' => 'academic', 'specializations' => '["research", "education", "science"]', 'trust_score' => 0.85, 'safety_score' => 0.95],

            // Archives and libraries
            ['domain' => 'archive.org', 'display_name' => 'Internet Archive', 'domain_category' => 'archive', 'specializations' => '["history", "archives", "web_history", "books"]', 'trust_score' => 0.90, 'safety_score' => 0.95],
            ['domain' => 'europeana.eu', 'display_name' => 'Europeana', 'domain_category' => 'archive', 'specializations' => '["history", "culture", "european_heritage"]', 'trust_score' => 0.90, 'safety_score' => 0.95],

            // Reference sources
            ['domain' => 'wikipedia.org', 'display_name' => 'Wikipedia', 'domain_category' => 'reference', 'specializations' => '["general", "encyclopedia"]', 'trust_score' => 0.70, 'safety_score' => 0.95],
            ['domain' => 'britannica.com', 'display_name' => 'Encyclopaedia Britannica', 'domain_category' => 'reference', 'specializations' => '["general", "encyclopedia"]', 'trust_score' => 0.85, 'safety_score' => 0.95],

            // Academic databases
            ['domain' => 'jstor.org', 'display_name' => 'JSTOR', 'domain_category' => 'academic', 'specializations' => '["research", "academic_journals"]', 'trust_score' => 0.90, 'safety_score' => 0.95],
            ['domain' => 'pubmed.ncbi.nlm.nih.gov', 'display_name' => 'PubMed', 'domain_category' => 'academic', 'specializations' => '["medical", "research", "health"]', 'trust_score' => 0.95, 'safety_score' => 0.99],
            ['domain' => 'arxiv.org', 'display_name' => 'arXiv', 'domain_category' => 'academic', 'specializations' => '["research", "preprints", "science"]', 'trust_score' => 0.85, 'safety_score' => 0.95],

            // Genealogy specific
            ['domain' => 'findagrave.com', 'display_name' => 'Find a Grave', 'domain_category' => 'archive', 'specializations' => '["genealogy", "cemeteries", "memorials"]', 'trust_score' => 0.80, 'safety_score' => 0.90],
            ['domain' => 'billiongraves.com', 'display_name' => 'BillionGraves', 'domain_category' => 'archive', 'specializations' => '["genealogy", "cemeteries", "memorials"]', 'trust_score' => 0.80, 'safety_score' => 0.90],
            ['domain' => 'wikitree.com', 'display_name' => 'WikiTree', 'domain_category' => 'community', 'specializations' => '["genealogy", "family_trees"]', 'trust_score' => 0.75, 'safety_score' => 0.90],
        ];

        foreach ($whitelistSources as $source) {
            DB::connection($this->connection)->statement("
                INSERT INTO discovered_sources
                    (domain, display_name, domain_category, specializations, trust_score, safety_score,
                     is_whitelisted, requires_sandbox, discovered_by, source_type)
                VALUES (?, ?, ?, ?::jsonb, ?, ?, TRUE, FALSE, 'system', 'archive')
                ON CONFLICT (domain) DO NOTHING
            ", [
                $source['domain'],
                $source['display_name'],
                $source['domain_category'],
                $source['specializations'],
                $source['trust_score'],
                $source['safety_score'],
            ]);
        }
    }

    private function insertBlacklistSources(): void
    {
        $blacklistDomains = [
            ['domain' => 'bit.ly', 'reason' => 'URL shortener - potential redirect attack vector'],
            ['domain' => 'tinyurl.com', 'reason' => 'URL shortener - potential redirect attack vector'],
            ['domain' => 't.co', 'reason' => 'URL shortener - potential redirect attack vector'],
            ['domain' => 'goo.gl', 'reason' => 'URL shortener - potential redirect attack vector'],
            ['domain' => 'ow.ly', 'reason' => 'URL shortener - potential redirect attack vector'],
        ];

        foreach ($blacklistDomains as $source) {
            DB::connection($this->connection)->statement("
                INSERT INTO discovered_sources
                    (domain, is_blacklisted, blacklist_reason, is_active, requires_sandbox, discovered_by, safety_score, trust_score)
                VALUES (?, TRUE, ?, FALSE, TRUE, 'system', 0.0, 0.0)
                ON CONFLICT (domain) DO NOTHING
            ", [
                $source['domain'],
                $source['reason'],
            ]);
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS verification_attempts");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS research_facts");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS discovered_sources");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS research_missions");
    }
};
