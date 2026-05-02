<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create domain_credibility table in MySQL (framework-shared resource)
        DB::statement("
            CREATE TABLE IF NOT EXISTS domain_credibility (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                domain VARCHAR(255) NOT NULL,
                credibility_score DECIMAL(4,3) NOT NULL DEFAULT 0.500,
                tier TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1=authoritative, 2=major news, 3=reference, 4=general, 5=low credibility',
                category VARCHAR(50) DEFAULT NULL COMMENT 'government, academic, wire_service, scientific, health, news, reference, factcheck, tabloid',
                is_tld_pattern TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if this is a TLD pattern like gov, edu, ac.uk',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_domain (domain),
                KEY idx_tier (tier),
                KEY idx_active_score (is_active, credibility_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed all domain credibility scores consolidated from:
        // - EvidenceRetrieverService::DOMAIN_CREDIBILITY
        // - ClaimVerificationService::DOMAIN_CREDIBILITY
        // - SourceCredibilityService::TIER1-5_DOMAINS
        // Using highest score when duplicates exist across services

        $domains = [
            // ══════════════════════════════════════════════════════════════
            // TIER 1: Authoritative sources (0.88+)
            // ══════════════════════════════════════════════════════════════

            // TLD patterns
            ['gov', 0.950, 1, 'government', 1, 'Generic .gov TLD pattern'],
            ['gov.uk', 0.950, 1, 'government', 1, 'UK government TLD'],
            ['gov.au', 0.950, 1, 'government', 1, 'Australian government TLD'],
            ['gc.ca', 0.950, 1, 'government', 1, 'Canadian government TLD'],
            ['edu', 0.920, 1, 'academic', 1, 'Generic .edu TLD pattern'],
            ['ac.uk', 0.920, 1, 'academic', 1, 'UK academic TLD'],

            // Government agencies
            ['archives.gov', 0.980, 1, 'government', 0, 'National Archives (NARA) — primary source government records'],
            ['loc.gov', 0.980, 1, 'government', 0, 'Library of Congress'],
            ['census.gov', 0.980, 1, 'government', 0, 'US Census Bureau'],
            ['usa.gov', 0.980, 1, 'government', 0, 'USA.gov federal portal'],
            ['congress.gov', 0.980, 1, 'government', 0, 'US Congress records'],
            ['nih.gov', 0.940, 1, 'health', 0, 'National Institutes of Health'],
            ['cdc.gov', 0.940, 1, 'health', 0, 'Centers for Disease Control'],
            ['fda.gov', 0.930, 1, 'health', 0, 'Food and Drug Administration'],
            ['who.int', 0.940, 1, 'health', 0, 'World Health Organization'],

            // Wire services
            ['reuters.com', 0.950, 1, 'wire_service', 0, 'Reuters wire service'],
            ['apnews.com', 0.950, 1, 'wire_service', 0, 'Associated Press'],
            ['afp.com', 0.940, 1, 'wire_service', 0, 'Agence France-Presse'],

            // Scientific publishers
            ['nature.com', 0.960, 1, 'scientific', 0, 'Nature Publishing Group'],
            ['science.org', 0.960, 1, 'scientific', 0, 'AAAS Science journal'],
            ['cell.com', 0.950, 1, 'scientific', 0, 'Cell Press journals'],
            ['thelancet.com', 0.950, 1, 'scientific', 0, 'The Lancet medical journal'],
            ['nejm.org', 0.960, 1, 'scientific', 0, 'New England Journal of Medicine'],
            ['bmj.com', 0.940, 1, 'scientific', 0, 'British Medical Journal'],
            ['pubmed.ncbi.nlm.nih.gov', 0.950, 1, 'scientific', 0, 'PubMed medical database'],
            ['ncbi.nlm.nih.gov', 0.940, 1, 'scientific', 0, 'NCBI biomedical databases'],
            ['arxiv.org', 0.880, 1, 'scientific', 0, 'arXiv preprint server'],

            // Academic
            ['scholar.google.com', 0.850, 1, 'academic', 0, 'Google Scholar'],
            ['jstor.org', 0.950, 1, 'academic', 0, 'JSTOR digital library'],
            ['semanticscholar.org', 0.880, 1, 'academic', 0, 'Semantic Scholar AI research'],
            ['sciencedirect.com', 0.920, 1, 'academic', 0, 'Elsevier ScienceDirect'],

            // Public broadcasters
            ['bbc.com', 0.920, 1, 'news', 0, 'BBC News'],
            ['bbc.co.uk', 0.920, 1, 'news', 0, 'BBC UK'],
            ['npr.org', 0.900, 1, 'news', 0, 'National Public Radio'],
            ['pbs.org', 0.900, 1, 'news', 0, 'Public Broadcasting Service'],

            // ══════════════════════════════════════════════════════════════
            // TIER 2: Major news organizations (0.75-0.87)
            // ══════════════════════════════════════════════════════════════
            ['economist.com', 0.870, 2, 'news', 0, 'The Economist'],
            ['nytimes.com', 0.860, 2, 'news', 0, 'The New York Times'],
            ['ft.com', 0.860, 2, 'news', 0, 'Financial Times'],
            ['wsj.com', 0.850, 2, 'news', 0, 'Wall Street Journal'],
            ['bloomberg.com', 0.850, 2, 'news', 0, 'Bloomberg'],
            ['washingtonpost.com', 0.840, 2, 'news', 0, 'The Washington Post'],
            ['newyorker.com', 0.840, 2, 'news', 0, 'The New Yorker'],
            ['theguardian.com', 0.820, 2, 'news', 0, 'The Guardian'],
            ['theatlantic.com', 0.820, 2, 'news', 0, 'The Atlantic'],
            ['time.com', 0.800, 2, 'news', 0, 'TIME Magazine'],
            ['latimes.com', 0.800, 2, 'news', 0, 'Los Angeles Times'],
            ['researchgate.net', 0.800, 2, 'academic', 0, 'ResearchGate'],
            ['cnbc.com', 0.780, 2, 'news', 0, 'CNBC'],
            ['chicagotribune.com', 0.780, 2, 'news', 0, 'Chicago Tribune'],
            ['usatoday.com', 0.750, 2, 'news', 0, 'USA Today'],
            ['newsweek.com', 0.750, 2, 'news', 0, 'Newsweek'],

            // ══════════════════════════════════════════════════════════════
            // TIER 3: Fact-checkers and reference (0.70-0.85)
            // ══════════════════════════════════════════════════════════════
            ['factcheck.org', 0.850, 3, 'factcheck', 0, 'FactCheck.org — Annenberg Public Policy Center'],
            ['britannica.com', 0.850, 3, 'reference', 0, 'Encyclopaedia Britannica'],
            ['politifact.com', 0.830, 3, 'factcheck', 0, 'PolitiFact — Poynter Institute'],
            ['snopes.com', 0.820, 3, 'factcheck', 0, 'Snopes fact-checking'],
            ['fullfact.org', 0.820, 3, 'factcheck', 0, 'Full Fact UK fact-checker'],
            ['merriam-webster.com', 0.800, 3, 'reference', 0, 'Merriam-Webster dictionary'],
            ['wikipedia.org', 0.720, 3, 'reference', 0, 'Wikipedia — community-edited, verify citations'],

            // ══════════════════════════════════════════════════════════════
            // TIER 4: General news (0.50-0.70)
            // ══════════════════════════════════════════════════════════════
            ['abcnews.go.com', 0.700, 4, 'news', 0, 'ABC News'],
            ['cbsnews.com', 0.700, 4, 'news', 0, 'CBS News'],
            ['nbcnews.com', 0.700, 4, 'news', 0, 'NBC News'],
            ['cnn.com', 0.680, 4, 'news', 0, 'CNN'],
            ['vox.com', 0.600, 4, 'news', 0, 'Vox Media'],
            ['msnbc.com', 0.580, 4, 'news', 0, 'MSNBC'],
            ['foxnews.com', 0.550, 4, 'news', 0, 'Fox News'],
            ['huffpost.com', 0.550, 4, 'news', 0, 'HuffPost'],
            ['vice.com', 0.550, 4, 'news', 0, 'Vice Media'],
            ['buzzfeed.com', 0.500, 4, 'news', 0, 'BuzzFeed'],

            // ══════════════════════════════════════════════════════════════
            // TIER 5: Low credibility (below 0.50)
            // ══════════════════════════════════════════════════════════════
            ['nypost.com', 0.480, 5, 'tabloid', 0, 'New York Post — tabloid'],
            ['dailymail.co.uk', 0.420, 5, 'tabloid', 0, 'Daily Mail — UK tabloid, banned by Wikipedia as source'],
            ['mirror.co.uk', 0.400, 5, 'tabloid', 0, 'Daily Mirror — UK tabloid'],
            ['thesun.co.uk', 0.380, 5, 'tabloid', 0, 'The Sun — UK tabloid'],
            ['express.co.uk', 0.380, 5, 'tabloid', 0, 'Daily Express — UK tabloid'],
            ['breitbart.com', 0.350, 5, 'tabloid', 0, 'Breitbart — far-right news'],
            ['infowars.com', 0.150, 5, 'tabloid', 0, 'InfoWars — conspiracy content, multiple defamation judgments'],
            ['naturalnews.com', 0.100, 5, 'tabloid', 0, 'NaturalNews — health misinformation, banned from multiple platforms'],

            // ══════════════════════════════════════════════════════════════
            // Additional sources from AuthoritativeSourceDiscoveryService
            // ══════════════════════════════════════════════════════════════
            ['mayoclinic.org', 0.950, 1, 'health', 0, 'Mayo Clinic — top medical institution'],
            ['clevelandclinic.org', 0.950, 1, 'health', 0, 'Cleveland Clinic'],
            ['medlineplus.gov', 0.950, 1, 'health', 0, 'MedlinePlus — NLM consumer health'],
            ['webmd.com', 0.850, 2, 'health', 0, 'WebMD health reference'],
            ['healthline.com', 0.850, 2, 'health', 0, 'Healthline health reference'],
            ['examine.com', 0.900, 1, 'health', 0, 'Examine.com — evidence-based supplements/nutrition'],
            ['drugs.com', 0.850, 2, 'health', 0, 'Drugs.com — medication reference'],

            ['familysearch.org', 0.950, 1, 'genealogy', 0, 'FamilySearch — LDS church, largest free genealogy'],
            ['ancestry.com', 0.900, 1, 'genealogy', 0, 'Ancestry.com — largest genealogy database'],
            ['findagrave.com', 0.850, 2, 'genealogy', 0, 'Find a Grave — cemetery records'],
            ['billiongraves.com', 0.800, 2, 'genealogy', 0, 'BillionGraves — GPS-tagged headstones'],
            ['wikitree.com', 0.750, 3, 'genealogy', 0, 'WikiTree — collaborative genealogy'],
            ['fold3.com', 0.850, 2, 'genealogy', 0, 'Fold3 — military records'],
            ['newspapers.com', 0.850, 2, 'genealogy', 0, 'Newspapers.com — historical newspaper archive'],
            ['myheritage.com', 0.850, 2, 'genealogy', 0, 'MyHeritage — genealogy and DNA'],

            ['developer.mozilla.org', 0.950, 1, 'technology', 0, 'MDN Web Docs'],
            ['stackoverflow.com', 0.850, 2, 'technology', 0, 'Stack Overflow'],
            ['github.com', 0.800, 2, 'technology', 0, 'GitHub'],
            ['docs.microsoft.com', 0.900, 1, 'technology', 0, 'Microsoft Docs'],
            ['laravel.com', 0.950, 1, 'technology', 0, 'Laravel official docs'],
            ['php.net', 0.950, 1, 'technology', 0, 'PHP official manual'],
            ['docs.python.org', 0.950, 1, 'technology', 0, 'Python official docs'],
            ['nodejs.org', 0.900, 1, 'technology', 0, 'Node.js official docs'],

            ['investopedia.com', 0.850, 2, 'finance', 0, 'Investopedia financial education'],
            ['federalreserve.gov', 0.980, 1, 'finance', 0, 'Federal Reserve'],
            ['morningstar.com', 0.880, 2, 'finance', 0, 'Morningstar investment research'],
            ['sec.gov', 0.950, 1, 'finance', 0, 'SEC — Securities and Exchange Commission'],
            ['irs.gov', 0.950, 1, 'government', 0, 'IRS — Internal Revenue Service'],
            ['nutrition.gov', 0.950, 1, 'health', 0, 'USDA nutrition resource'],

            ['seriouseats.com', 0.880, 2, 'food', 0, 'Serious Eats'],
            ['bonappetit.com', 0.850, 2, 'food', 0, 'Bon Appetit'],
            ['foodnetwork.com', 0.800, 3, 'food', 0, 'Food Network'],
            ['allrecipes.com', 0.780, 3, 'food', 0, 'Allrecipes'],
            ['epicurious.com', 0.850, 2, 'food', 0, 'Epicurious'],

            ['archive.org', 0.850, 2, 'reference', 0, 'Internet Archive — digital library'],
        ];

        foreach ($domains as [$domain, $score, $tier, $category, $isTld, $notes]) {
            try {
                DB::insert("
                    INSERT INTO domain_credibility (domain, credibility_score, tier, category, is_tld_pattern, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        credibility_score = VALUES(credibility_score),
                        tier = VALUES(tier),
                        category = VALUES(category),
                        notes = VALUES(notes)
                ", [$domain, $score, $tier, $category, $isTld, $notes]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::info("Skipping domain_credibility seed for {$domain}: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS domain_credibility");
    }
};
