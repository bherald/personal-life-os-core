<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     *
     * Seeds discovery_rules with the patterns that were previously
     * hardcoded in DynamicSourceDiscoveryService.php
     */
    public function up(): void
    {
        // TLD Trust Score Rules (from TLD_TRUST_SCORES constant)
        $tldRules = [
            ['tld' => 'gov', 'score' => 0.95, 'notes' => 'US Government domains - highest trust'],
            ['tld' => 'edu', 'score' => 0.90, 'notes' => 'Educational institutions'],
            ['tld' => 'mil', 'score' => 0.95, 'notes' => 'US Military domains'],
            ['tld' => 'org', 'score' => 0.70, 'notes' => 'Non-profit organizations - varies in quality'],
            ['tld' => 'int', 'score' => 0.85, 'notes' => 'International treaty organizations'],
            ['tld' => 'museum', 'score' => 0.80, 'notes' => 'Museum domains'],
            ['tld' => 'ac.uk', 'score' => 0.90, 'notes' => 'UK Academic institutions'],
            ['tld' => 'gov.uk', 'score' => 0.95, 'notes' => 'UK Government'],
            ['tld' => 'gc.ca', 'score' => 0.95, 'notes' => 'Canadian Government'],
            ['tld' => 'gov.au', 'score' => 0.95, 'notes' => 'Australian Government'],
            ['tld' => 'edu.au', 'score' => 0.90, 'notes' => 'Australian Education'],
        ];

        foreach ($tldRules as $rule) {
            DB::connection($this->connection)->insert("
                INSERT INTO discovery_rules
                (rule_name, rule_type, match_pattern, pattern_type,
                 trust_score_value, auto_whitelist, requires_verification,
                 priority, notes, created_by)
                VALUES (?, 'tld_trust', ?, 'suffix', ?, ?, ?, ?, ?, 'migration')
            ", [
                "TLD Trust: .{$rule['tld']}",
                ".{$rule['tld']}",
                $rule['score'],
                $rule['score'] >= 0.90,  // auto_whitelist for high-trust TLDs
                $rule['score'] < 0.90,   // requires_verification for lower-trust
                $rule['score'] >= 0.90 ? 10 : 50,  // higher priority for gov/edu
                $rule['notes']
            ]);
        }

        // Whitelist Pattern Rules (from WHITELIST_PATTERNS constant)
        $whitelistDomains = [
            ['pattern' => 'archive.org', 'name' => 'Internet Archive', 'category' => null, 'specializations' => ['historical_records', 'web_archive']],
            ['pattern' => 'loc.gov', 'name' => 'Library of Congress', 'category' => null, 'specializations' => ['primary_sources', 'historical_records']],
            ['pattern' => 'archives.gov', 'name' => 'National Archives', 'category' => 'genealogy', 'specializations' => ['vital_records', 'census', 'military']],
            ['pattern' => 'wikipedia.org', 'name' => 'Wikipedia', 'category' => null, 'specializations' => ['encyclopedia', 'reference']],
            ['pattern' => 'wikimedia.org', 'name' => 'Wikimedia', 'category' => null, 'specializations' => ['media', 'reference']],
            ['pattern' => 'britannica.com', 'name' => 'Encyclopaedia Britannica', 'category' => null, 'specializations' => ['encyclopedia', 'reference']],
            ['pattern' => 'jstor.org', 'name' => 'JSTOR', 'category' => 'science', 'specializations' => ['academic', 'journals']],
            ['pattern' => 'pubmed.gov', 'name' => 'PubMed', 'category' => 'medical', 'specializations' => ['medical', 'research']],
            ['pattern' => 'pubmed.ncbi.nlm.nih.gov', 'name' => 'PubMed NCBI', 'category' => 'medical', 'specializations' => ['medical', 'research']],
            ['pattern' => 'arxiv.org', 'name' => 'arXiv', 'category' => 'science', 'specializations' => ['preprints', 'research']],
            ['pattern' => 'ncbi.nlm.nih.gov', 'name' => 'NCBI', 'category' => 'medical', 'specializations' => ['medical', 'genetics', 'research']],
            ['pattern' => 'scholar.google.com', 'name' => 'Google Scholar', 'category' => 'science', 'specializations' => ['academic', 'search']],
            ['pattern' => 'doi.org', 'name' => 'DOI Resolution', 'category' => 'science', 'specializations' => ['academic', 'reference']],
        ];

        foreach ($whitelistDomains as $domain) {
            DB::connection($this->connection)->insert("
                INSERT INTO discovery_rules
                (rule_name, rule_type, match_pattern, pattern_type,
                 trust_score_value, domain_category, suggested_specializations,
                 auto_whitelist, requires_verification, priority, created_by)
                VALUES (?, 'whitelist_pattern', ?, 'exact', 0.90, ?, ?::jsonb, true, false, 5, 'migration')
            ", [
                "Whitelist: {$domain['name']}",
                $domain['pattern'],
                $domain['category'],
                json_encode($domain['specializations'])
            ]);
        }

        // Blacklist Pattern Rules (from BLACKLIST_PATTERNS constant)
        $blacklistPatterns = [
            ['pattern' => 'bit.ly', 'name' => 'bit.ly URL shortener'],
            ['pattern' => 'tinyurl.com', 'name' => 'TinyURL shortener'],
            ['pattern' => 't.co', 'name' => 'Twitter shortener'],
            ['pattern' => 'goo.gl', 'name' => 'Google shortener'],
            ['pattern' => 'ow.ly', 'name' => 'Hootsuite shortener'],
            ['pattern' => 'is.gd', 'name' => 'is.gd shortener'],
            ['pattern' => 'buff.ly', 'name' => 'Buffer shortener'],
            ['pattern' => 'adf.ly', 'name' => 'adf.ly (ad-heavy)'],
            ['pattern' => 'shorte.st', 'name' => 'shorte.st (ad-heavy)'],
            ['pattern' => 'linktr.ee', 'name' => 'Linktree (aggregator)'],
            ['pattern' => 'rebrand.ly', 'name' => 'Rebrandly shortener'],
            ['pattern' => 'cutt.ly', 'name' => 'Cutt.ly shortener'],
            ['pattern' => 'short.io', 'name' => 'Short.io shortener'],
            // Content farms / low quality
            ['pattern' => 'pinterest.com', 'name' => 'Pinterest (image aggregator)'],
            ['pattern' => 'quora.com', 'name' => 'Quora (user-generated, unverified)'],
            // SEO spam patterns
            ['pattern' => 'about.me', 'name' => 'about.me profiles'],
        ];

        foreach ($blacklistPatterns as $pattern) {
            DB::connection($this->connection)->insert("
                INSERT INTO discovery_rules
                (rule_name, rule_type, match_pattern, pattern_type,
                 trust_score_value, safety_score_adjustment,
                 auto_blacklist, priority, notes, created_by)
                VALUES (?, 'blacklist_pattern', ?, 'exact', 0.0, -1.0, true, 1, ?, 'migration')
            ", [
                "Blacklist: {$pattern['name']}",
                $pattern['pattern'],
                $pattern['name']
            ]);
        }

        // Category-Specific Domain Rules (from CATEGORY_DOMAINS constant)
        $categoryDomains = [
            // Genealogy
            ['domain' => 'findagrave.com', 'category' => 'genealogy', 'trust' => 0.85, 'specs' => ['cemetery', 'obituaries', 'memorials']],
            ['domain' => 'billiongraves.com', 'category' => 'genealogy', 'trust' => 0.85, 'specs' => ['cemetery', 'headstones', 'gps_located']],
            ['domain' => 'chroniclingamerica.loc.gov', 'category' => 'genealogy', 'trust' => 0.95, 'specs' => ['newspapers', 'historical', 'primary_source']],
            ['domain' => 'wikitree.com', 'category' => 'genealogy', 'trust' => 0.80, 'specs' => ['family_trees', 'collaborative', 'free']],
            ['domain' => 'geni.com', 'category' => 'genealogy', 'trust' => 0.80, 'specs' => ['family_trees', 'world_tree', 'collaborative']],
            ['domain' => 'rootsweb.com', 'category' => 'genealogy', 'trust' => 0.75, 'specs' => ['message_boards', 'mailing_lists', 'community']],
            ['domain' => 'usgenweb.org', 'category' => 'genealogy', 'trust' => 0.80, 'specs' => ['county_records', 'volunteer', 'transcriptions']],
            ['domain' => 'accessgenealogy.com', 'category' => 'genealogy', 'trust' => 0.75, 'specs' => ['native_american', 'census', 'free']],
            ['domain' => 'stevemorse.org', 'category' => 'genealogy', 'trust' => 0.90, 'specs' => ['search_tools', 'immigration', 'one_step']],
            ['domain' => 'ellisisland.org', 'category' => 'genealogy', 'trust' => 0.95, 'specs' => ['immigration', 'passenger_lists', 'ellis_island']],
            ['domain' => 'castlegarden.org', 'category' => 'genealogy', 'trust' => 0.95, 'specs' => ['immigration', 'pre_ellis', 'passenger_lists']],
            ['domain' => 'ngsgenealogy.org', 'category' => 'genealogy', 'trust' => 0.90, 'specs' => ['society', 'education', 'standards']],
            ['domain' => 'cdnc.ucr.edu', 'category' => 'genealogy', 'trust' => 0.90, 'specs' => ['california', 'newspapers', 'historical']],
            ['domain' => 'oac.cdlib.org', 'category' => 'genealogy', 'trust' => 0.90, 'specs' => ['california', 'archives', 'finding_aids']],

            // Science
            ['domain' => 'nature.com', 'category' => 'science', 'trust' => 0.95, 'specs' => ['journals', 'peer_reviewed', 'research']],
            ['domain' => 'sciencedirect.com', 'category' => 'science', 'trust' => 0.90, 'specs' => ['journals', 'elsevier', 'research']],
            ['domain' => 'springer.com', 'category' => 'science', 'trust' => 0.90, 'specs' => ['journals', 'books', 'research']],
            ['domain' => 'wiley.com', 'category' => 'science', 'trust' => 0.90, 'specs' => ['journals', 'peer_reviewed']],
            ['domain' => 'pnas.org', 'category' => 'science', 'trust' => 0.95, 'specs' => ['journals', 'multidisciplinary', 'peer_reviewed']],
            ['domain' => 'science.org', 'category' => 'science', 'trust' => 0.95, 'specs' => ['journals', 'aaas', 'peer_reviewed']],
            ['domain' => 'ieee.org', 'category' => 'science', 'trust' => 0.90, 'specs' => ['engineering', 'technology', 'standards']],
            ['domain' => 'acm.org', 'category' => 'science', 'trust' => 0.90, 'specs' => ['computing', 'research', 'peer_reviewed']],

            // News
            ['domain' => 'reuters.com', 'category' => 'news', 'trust' => 0.90, 'specs' => ['wire_service', 'international', 'factual']],
            ['domain' => 'apnews.com', 'category' => 'news', 'trust' => 0.90, 'specs' => ['wire_service', 'us', 'factual']],
            ['domain' => 'bbc.com', 'category' => 'news', 'trust' => 0.85, 'specs' => ['international', 'uk', 'broadcast']],
            ['domain' => 'npr.org', 'category' => 'news', 'trust' => 0.85, 'specs' => ['us', 'public_radio', 'analysis']],
            ['domain' => 'pbs.org', 'category' => 'news', 'trust' => 0.85, 'specs' => ['us', 'public_tv', 'documentary']],

            // Medical
            ['domain' => 'nih.gov', 'category' => 'medical', 'trust' => 0.95, 'specs' => ['research', 'government', 'authoritative']],
            ['domain' => 'cdc.gov', 'category' => 'medical', 'trust' => 0.95, 'specs' => ['public_health', 'government', 'guidelines']],
            ['domain' => 'who.int', 'category' => 'medical', 'trust' => 0.95, 'specs' => ['international', 'public_health', 'guidelines']],
            ['domain' => 'mayoclinic.org', 'category' => 'medical', 'trust' => 0.90, 'specs' => ['patient_info', 'conditions', 'treatments']],
            ['domain' => 'clevelandclinic.org', 'category' => 'medical', 'trust' => 0.90, 'specs' => ['patient_info', 'conditions', 'treatments']],
            ['domain' => 'webmd.com', 'category' => 'medical', 'trust' => 0.75, 'specs' => ['patient_info', 'symptoms', 'general']],
            ['domain' => 'medlineplus.gov', 'category' => 'medical', 'trust' => 0.95, 'specs' => ['patient_info', 'government', 'authoritative']],

            // Legal
            ['domain' => 'law.cornell.edu', 'category' => 'legal', 'trust' => 0.95, 'specs' => ['statutes', 'case_law', 'reference']],
            ['domain' => 'supremecourt.gov', 'category' => 'legal', 'trust' => 0.95, 'specs' => ['case_law', 'opinions', 'government']],
            ['domain' => 'uscourts.gov', 'category' => 'legal', 'trust' => 0.95, 'specs' => ['federal_courts', 'rules', 'government']],
            ['domain' => 'justia.com', 'category' => 'legal', 'trust' => 0.85, 'specs' => ['case_law', 'statutes', 'free_access']],
            ['domain' => 'oyez.org', 'category' => 'legal', 'trust' => 0.90, 'specs' => ['supreme_court', 'audio', 'educational']],
        ];

        foreach ($categoryDomains as $domain) {
            DB::connection($this->connection)->insert("
                INSERT INTO discovery_rules
                (rule_name, rule_type, match_pattern, pattern_type,
                 trust_score_value, domain_category, suggested_specializations,
                 auto_whitelist, requires_verification, priority, created_by)
                VALUES (?, 'category_domain', ?, 'exact', ?, ?, ?::jsonb, ?, false, 20, 'migration')
            ", [
                "Category Domain: {$domain['domain']}",
                $domain['domain'],
                $domain['trust'],
                $domain['category'],
                json_encode($domain['specs']),
                $domain['trust'] >= 0.85
            ]);
        }

        // Safety modifier rules for common patterns
        $safetyModifiers = [
            ['pattern' => 'https://', 'type' => 'prefix', 'adjustment' => 0.05, 'name' => 'HTTPS bonus'],
            ['pattern' => 'http://', 'type' => 'prefix', 'adjustment' => -0.10, 'name' => 'HTTP penalty'],
            ['pattern' => '/wp-content/', 'type' => 'contains', 'adjustment' => -0.05, 'name' => 'WordPress content path'],
            ['pattern' => '/user/', 'type' => 'contains', 'adjustment' => -0.10, 'name' => 'User-generated content indicator'],
            ['pattern' => '/forum/', 'type' => 'contains', 'adjustment' => -0.15, 'name' => 'Forum content (unmoderated)'],
            ['pattern' => '/blog/', 'type' => 'contains', 'adjustment' => -0.05, 'name' => 'Blog content'],
        ];

        foreach ($safetyModifiers as $mod) {
            DB::connection($this->connection)->insert("
                INSERT INTO discovery_rules
                (rule_name, rule_type, match_pattern, pattern_type,
                 safety_score_adjustment, priority, notes, created_by)
                VALUES (?, 'safety_modifier', ?, ?, ?, 100, ?, 'migration')
            ", [
                "Safety: {$mod['name']}",
                $mod['pattern'],
                $mod['type'],
                $mod['adjustment'],
                $mod['name']
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->connection)->statement("
            DELETE FROM discovery_rules WHERE created_by = 'migration'
        ");
    }
};
