<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Exception;

/**
 * AuthoritativeSourceDiscoveryService - Self-Growing Source Discovery
 *
 * Automatically discovers and vets authoritative sources for research topics.
 * Uses industry-standard topic-to-source mappings and AI vetting.
 *
 * Features:
 * - Industry-standard source mappings per topic category
 * - AI-powered source quality vetting
 * - Automatic health checking and trust score updates
 * - Self-healing: demotes failing sources, promotes reliable ones
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class AuthoritativeSourceDiscoveryService
{
    private string $dbConnection = 'pgsql_rag';
    private AIService $aiService;

    /**
     * Industry-standard authoritative sources by category
     * These are well-known, trusted sources that should be seeded automatically
     */
    private const INDUSTRY_SOURCES = [
        'health' => [
            ['domain' => 'mayoclinic.org', 'name' => 'Mayo Clinic', 'trust' => 0.95, 'search' => 'https://www.mayoclinic.org/search/search-results?q={query}'],
            ['domain' => 'webmd.com', 'name' => 'WebMD', 'trust' => 0.85, 'search' => 'https://www.webmd.com/search/search_results/default.aspx?query={query}'],
            ['domain' => 'healthline.com', 'name' => 'Healthline', 'trust' => 0.85, 'search' => 'https://www.healthline.com/search?q1={query}'],
            ['domain' => 'nih.gov', 'name' => 'National Institutes of Health', 'trust' => 0.98, 'search' => 'https://search.nih.gov/search?utf8=%E2%9C%93&query={query}'],
            ['domain' => 'medlineplus.gov', 'name' => 'MedlinePlus', 'trust' => 0.95, 'search' => 'https://medlineplus.gov/search.html?query={query}'],
            ['domain' => 'cdc.gov', 'name' => 'CDC', 'trust' => 0.95, 'search' => 'https://search.cdc.gov/search/?query={query}'],
            ['domain' => 'who.int', 'name' => 'World Health Organization', 'trust' => 0.95, 'search' => 'https://www.who.int/home/search-results?query={query}'],
            ['domain' => 'examine.com', 'name' => 'Examine.com', 'trust' => 0.90, 'search' => 'https://examine.com/search/?q={query}'],
            ['domain' => 'drugs.com', 'name' => 'Drugs.com', 'trust' => 0.85, 'search' => 'https://www.drugs.com/search.php?searchterm={query}'],
            ['domain' => 'clevelandclinic.org', 'name' => 'Cleveland Clinic', 'trust' => 0.95, 'search' => 'https://my.clevelandclinic.org/search-results#q={query}'],
        ],
        'academic' => [
            ['domain' => 'pubmed.ncbi.nlm.nih.gov', 'name' => 'PubMed', 'trust' => 0.98, 'search' => 'https://pubmed.ncbi.nlm.nih.gov/?term={query}'],
            ['domain' => 'scholar.google.com', 'name' => 'Google Scholar', 'trust' => 0.90, 'search' => 'https://scholar.google.com/scholar?q={query}'],
            ['domain' => 'arxiv.org', 'name' => 'arXiv', 'trust' => 0.90, 'search' => 'https://arxiv.org/search/?query={query}&searchtype=all'],
            ['domain' => 'jstor.org', 'name' => 'JSTOR', 'trust' => 0.95, 'search' => 'https://www.jstor.org/action/doBasicSearch?Query={query}'],
            ['domain' => 'semanticscholar.org', 'name' => 'Semantic Scholar', 'trust' => 0.88, 'search' => 'https://www.semanticscholar.org/search?q={query}'],
            ['domain' => 'researchgate.net', 'name' => 'ResearchGate', 'trust' => 0.80, 'search' => 'https://www.researchgate.net/search/publication?q={query}'],
            ['domain' => 'sciencedirect.com', 'name' => 'ScienceDirect', 'trust' => 0.92, 'search' => 'https://www.sciencedirect.com/search?qs={query}'],
        ],
        'genealogy' => [
            ['domain' => 'findagrave.com', 'name' => 'Find a Grave', 'trust' => 0.85, 'search' => 'https://www.findagrave.com/memorial/search?firstname=&lastname={query}'],
            ['domain' => 'billiongraves.com', 'name' => 'BillionGraves', 'trust' => 0.80, 'search' => 'https://billiongraves.com/search?firstName=&lastName={query}'],
            ['domain' => 'wikitree.com', 'name' => 'WikiTree', 'trust' => 0.75, 'search' => 'https://www.wikitree.com/wiki/Special:SearchPerson?Name={query}'],
        ],
        'technology' => [
            ['domain' => 'developer.mozilla.org', 'name' => 'MDN Web Docs', 'trust' => 0.95, 'search' => 'https://developer.mozilla.org/en-US/search?q={query}'],
            ['domain' => 'stackoverflow.com', 'name' => 'Stack Overflow', 'trust' => 0.85, 'search' => 'https://stackoverflow.com/search?q={query}'],
            ['domain' => 'github.com', 'name' => 'GitHub', 'trust' => 0.80, 'search' => 'https://github.com/search?q={query}'],
            ['domain' => 'docs.microsoft.com', 'name' => 'Microsoft Docs', 'trust' => 0.90, 'search' => 'https://docs.microsoft.com/en-us/search/?terms={query}'],
            ['domain' => 'laravel.com', 'name' => 'Laravel Docs', 'trust' => 0.95, 'search' => 'https://laravel.com/docs'],
            ['domain' => 'php.net', 'name' => 'PHP Manual', 'trust' => 0.95, 'search' => 'https://www.php.net/manual-lookup.php?pattern={query}'],
            ['domain' => 'docs.python.org', 'name' => 'Python Docs', 'trust' => 0.95, 'search' => 'https://docs.python.org/3/search.html?q={query}'],
            ['domain' => 'nodejs.org', 'name' => 'Node.js Docs', 'trust' => 0.90, 'search' => 'https://nodejs.org/en/search?q={query}'],
        ],
        'government' => [
            ['domain' => 'usa.gov', 'name' => 'USA.gov', 'trust' => 0.98, 'search' => 'https://www.usa.gov/search?query={query}'],
            ['domain' => 'loc.gov', 'name' => 'Library of Congress', 'trust' => 0.98, 'search' => 'https://www.loc.gov/search/?q={query}'],
            ['domain' => 'archives.gov', 'name' => 'National Archives', 'trust' => 0.98, 'search' => 'https://www.archives.gov/search?query={query}'],
            ['domain' => 'census.gov', 'name' => 'US Census Bureau', 'trust' => 0.98, 'search' => 'https://www.census.gov/search-results.html?q={query}'],
            ['domain' => 'congress.gov', 'name' => 'Congress.gov', 'trust' => 0.98, 'search' => 'https://www.congress.gov/search?q={query}'],
            ['domain' => 'irs.gov', 'name' => 'IRS', 'trust' => 0.95, 'search' => 'https://www.irs.gov/search#q={query}'],
            ['domain' => 'sec.gov', 'name' => 'SEC', 'trust' => 0.95, 'search' => 'https://www.sec.gov/cgi-bin/srch-ia?text={query}'],
        ],
        'finance' => [
            ['domain' => 'investopedia.com', 'name' => 'Investopedia', 'trust' => 0.85, 'search' => 'https://www.investopedia.com/search?q={query}'],
            ['domain' => 'sec.gov', 'name' => 'SEC EDGAR', 'trust' => 0.95, 'search' => 'https://www.sec.gov/cgi-bin/srch-ia?text={query}'],
            ['domain' => 'federalreserve.gov', 'name' => 'Federal Reserve', 'trust' => 0.98, 'search' => 'https://www.federalreserve.gov/search.htm?text={query}'],
            ['domain' => 'yahoo.com/finance', 'name' => 'Yahoo Finance', 'trust' => 0.80, 'search' => 'https://finance.yahoo.com/quote/{query}'],
            ['domain' => 'bloomberg.com', 'name' => 'Bloomberg', 'trust' => 0.90, 'search' => 'https://www.bloomberg.com/search?query={query}'],
            ['domain' => 'morningstar.com', 'name' => 'Morningstar', 'trust' => 0.88, 'search' => 'https://www.morningstar.com/search?query={query}'],
        ],
        'news' => [
            ['domain' => 'reuters.com', 'name' => 'Reuters', 'trust' => 0.92, 'search' => 'https://www.reuters.com/search/news?blob={query}'],
            ['domain' => 'apnews.com', 'name' => 'Associated Press', 'trust' => 0.95, 'search' => 'https://apnews.com/search?q={query}'],
            ['domain' => 'bbc.com', 'name' => 'BBC News', 'trust' => 0.90, 'search' => 'https://www.bbc.co.uk/search?q={query}'],
            ['domain' => 'npr.org', 'name' => 'NPR', 'trust' => 0.88, 'search' => 'https://www.npr.org/search?query={query}'],
            ['domain' => 'pbs.org', 'name' => 'PBS', 'trust' => 0.88, 'search' => 'https://www.pbs.org/search/?q={query}'],
        ],
        'food' => [
            ['domain' => 'seriouseats.com', 'name' => 'Serious Eats', 'trust' => 0.88, 'search' => 'https://www.seriouseats.com/search?q={query}'],
            ['domain' => 'bonappetit.com', 'name' => 'Bon Appetit', 'trust' => 0.85, 'search' => 'https://www.bonappetit.com/search?q={query}'],
            ['domain' => 'foodnetwork.com', 'name' => 'Food Network', 'trust' => 0.80, 'search' => 'https://www.foodnetwork.com/search/{query}-'],
            ['domain' => 'allrecipes.com', 'name' => 'Allrecipes', 'trust' => 0.78, 'search' => 'https://www.allrecipes.com/search?q={query}'],
            ['domain' => 'epicurious.com', 'name' => 'Epicurious', 'trust' => 0.85, 'search' => 'https://www.epicurious.com/search/{query}'],
            ['domain' => 'fda.gov', 'name' => 'FDA', 'trust' => 0.95, 'search' => 'https://search.fda.gov/search?query={query}'],
            ['domain' => 'nutrition.gov', 'name' => 'Nutrition.gov', 'trust' => 0.95, 'search' => 'https://www.nutrition.gov/search?keywords={query}'],
        ],
        'general' => [
            ['domain' => 'wikipedia.org', 'name' => 'Wikipedia', 'trust' => 0.80, 'search' => 'https://en.wikipedia.org/w/index.php?search={query}'],
            ['domain' => 'britannica.com', 'name' => 'Britannica', 'trust' => 0.90, 'search' => 'https://www.britannica.com/search?query={query}'],
            ['domain' => 'archive.org', 'name' => 'Internet Archive', 'trust' => 0.85, 'search' => 'https://archive.org/search?query={query}'],
        ],
    ];

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Seed industry-standard sources into the database
     * Called by maintenance job to ensure all standard sources exist
     */
    public function seedIndustrySources(): array
    {
        $added = 0;
        $updated = 0;
        $errors = [];

        foreach (self::INDUSTRY_SOURCES as $category => $sources) {
            foreach ($sources as $source) {
                try {
                    $result = $this->upsertSource($source, $category);
                    if ($result === 'added') {
                        $added++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                } catch (Exception $e) {
                    $errors[] = "{$source['domain']}: {$e->getMessage()}";
                }
            }
        }

        Log::info('AuthoritativeSourceDiscovery: Seeded industry sources', [
            'added' => $added,
            'updated' => $updated,
            'errors' => count($errors),
        ]);

        return [
            'added' => $added,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Upsert a source into discovered_sources table
     */
    private function upsertSource(array $source, string $category): string
    {
        $existing = DB::connection($this->dbConnection)->selectOne(
            "SELECT id, trust_score FROM discovered_sources WHERE domain = ?",
            [$source['domain']]
        );

        if ($existing) {
            // Update if trust score is higher or source is industry standard
            DB::connection($this->dbConnection)->statement(
                "UPDATE discovered_sources
                 SET display_name = ?, trust_score = GREATEST(trust_score, ?),
                     api_endpoint = COALESCE(api_endpoint, ?),
                     is_active = true, is_whitelisted = true,
                     discovered_by = COALESCE(discovered_by, 'industry_standard'),
                     updated_at = NOW()
                 WHERE id = ?",
                [$source['name'], $source['trust'], $source['search'], $existing->id]
            );
            return 'updated';
        }

        // Insert new source
        $id = Str::uuid()->toString();
        DB::connection($this->dbConnection)->statement(
            "INSERT INTO discovered_sources
             (id, domain, full_url, display_name, domain_category, trust_score, safety_score,
              is_active, is_whitelisted, discovered_by, api_endpoint, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0.8, true, true, 'industry_standard', ?, NOW(), NOW())",
            [
                $id,
                $source['domain'],
                "https://{$source['domain']}",
                $source['name'],
                $category,
                $source['trust'],
                $source['search'],
            ]
        );

        return 'added';
    }

    /**
     * Health check all active sources and update trust scores
     */
    public function healthCheckSources(): array
    {
        $checked = 0;
        $demoted = 0;
        $promoted = 0;

        $sources = DB::connection($this->dbConnection)->select(
            "SELECT id, domain, full_url, trust_score, consecutive_failures
             FROM discovered_sources
             WHERE is_active = true AND is_blacklisted = false
             ORDER BY last_success_at ASC NULLS FIRST
             LIMIT 20"
        );

        foreach ($sources as $source) {
            try {
                $isHealthy = $this->checkSourceHealth($source->domain, $source->full_url);
                $checked++;

                if ($isHealthy) {
                    // Reset failures and maybe promote
                    $newTrust = min(1.0, (float)$source->trust_score + 0.01);
                    DB::connection($this->dbConnection)->statement(
                        "UPDATE discovered_sources
                         SET consecutive_failures = 0, last_success_at = NOW(),
                             success_count = success_count + 1,
                             trust_score = ?,
                             updated_at = NOW()
                         WHERE id = ?",
                        [$newTrust, $source->id]
                    );
                    if ($newTrust > $source->trust_score) {
                        $promoted++;
                    }
                } else {
                    // Increment failures and maybe demote
                    $newFailures = (int)$source->consecutive_failures + 1;
                    $newTrust = max(0.1, (float)$source->trust_score - 0.05);

                    DB::connection($this->dbConnection)->statement(
                        "UPDATE discovered_sources
                         SET consecutive_failures = ?, last_failure_at = NOW(),
                             failure_count = failure_count + 1,
                             trust_score = ?,
                             is_active = CASE WHEN ? >= 5 THEN false ELSE is_active END,
                             updated_at = NOW()
                         WHERE id = ?",
                        [$newFailures, $newTrust, $newFailures, $source->id]
                    );
                    $demoted++;
                }

                // Rate limit health checks
                usleep(500000); // 500ms between checks

            } catch (Exception $e) {
                Log::warning('AuthoritativeSourceDiscovery: Health check failed', [
                    'domain' => $source->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clear source caches
        $this->clearSourceCaches();

        Log::info('AuthoritativeSourceDiscovery: Health check complete', [
            'checked' => $checked,
            'promoted' => $promoted,
            'demoted' => $demoted,
        ]);

        return [
            'checked' => $checked,
            'promoted' => $promoted,
            'demoted' => $demoted,
        ];
    }

    /**
     * Check if a source is healthy (responds within timeout)
     */
    private function checkSourceHealth(string $domain, ?string $url): bool
    {
        try {
            $checkUrl = $url ?? "https://{$domain}";
            $response = Http::connectTimeout(5)->timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PLOS-Bot/1.0; +https://github.com/plos)',
                ])
                ->get($checkUrl);

            return $response->successful() || $response->status() === 403; // 403 often means rate limiting, not down

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Discover new sources for a category using AI
     */
    public function discoverNewSources(string $category, int $limit = 5): array
    {
        $discovered = [];

        try {
            $prompt = "List {$limit} authoritative, well-known websites for researching {$category} topics.
                       For each, provide:
                       1. Domain name (e.g., mayoclinic.org)
                       2. Display name (e.g., Mayo Clinic)
                       3. Trust score 0.0-1.0 (how authoritative is this source?)
                       4. Search URL pattern with {query} placeholder

                       Format as JSON array:
                       [{\"domain\":\"...\",\"name\":\"...\",\"trust\":0.9,\"search\":\"https://...\"}]

                       Only include well-established, reputable sources. No social media or forums.";

            $response = $this->aiService->process($prompt, [
                'max_tokens' => 500,
                'factual_mode' => true,
            ]);

            if (!empty($response['response'])) {
                // Extract JSON from response
                preg_match('/\[.*\]/s', $response['response'], $matches);
                if (!empty($matches[0])) {
                    $sources = json_decode($matches[0], true);
                    if (is_array($sources)) {
                        foreach ($sources as $source) {
                            if ($this->vetSource($source)) {
                                $result = $this->upsertSource($source, $category);
                                $discovered[] = [
                                    'domain' => $source['domain'],
                                    'result' => $result,
                                ];
                            }
                        }
                    }
                }
            }

        } catch (Exception $e) {
            Log::warning('AuthoritativeSourceDiscovery: AI discovery failed', [
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }

        return $discovered;
    }

    /**
     * Vet a source before adding it
     */
    private function vetSource(array $source): bool
    {
        // Basic validation
        if (empty($source['domain']) || empty($source['name'])) {
            return false;
        }

        // Check if domain is blacklisted
        $blacklisted = DB::connection($this->dbConnection)->selectOne(
            "SELECT 1 FROM discovered_sources WHERE domain = ? AND is_blacklisted = true",
            [$source['domain']]
        );
        if ($blacklisted) {
            return false;
        }

        // Block social media and forums
        $blocked = ['facebook.com', 'twitter.com', 'reddit.com', 'quora.com', 'instagram.com', 'tiktok.com'];
        foreach ($blocked as $domain) {
            if (stripos($source['domain'], $domain) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear all source-related caches
     */
    private function clearSourceCaches(): void
    {
        $categories = ['health', 'academic', 'genealogy', 'technology', 'government', 'finance', 'news', 'food', 'general'];
        foreach ($categories as $category) {
            Cache::forget("authoritative_sources:{$category}");
        }
    }

    /**
     * Run full maintenance cycle
     */
    public function runMaintenance(): array
    {
        $results = [
            'seed' => $this->seedIndustrySources(),
            'health' => $this->healthCheckSources(),
        ];

        Log::info('AuthoritativeSourceDiscovery: Maintenance complete', $results);

        return $results;
    }

    /**
     * Get stats about discovered sources
     */
    public function getStats(): array
    {
        $stats = DB::connection($this->dbConnection)->selectOne("
            SELECT
                COUNT(*) as total_sources,
                COUNT(*) FILTER (WHERE is_active = true) as active_sources,
                COUNT(*) FILTER (WHERE is_blacklisted = true) as blacklisted_sources,
                COUNT(*) FILTER (WHERE discovered_by = 'industry_standard') as industry_sources,
                AVG(trust_score) as avg_trust_score,
                SUM(success_count) as total_successes,
                SUM(failure_count) as total_failures
            FROM discovered_sources
        ");

        $byCategory = DB::connection($this->dbConnection)->select("
            SELECT domain_category, COUNT(*) as count, AVG(trust_score) as avg_trust
            FROM discovered_sources
            WHERE is_active = true AND is_blacklisted = false
            GROUP BY domain_category
            ORDER BY count DESC
        ");

        return [
            'totals' => [
                'total' => (int)($stats->total_sources ?? 0),
                'active' => (int)($stats->active_sources ?? 0),
                'blacklisted' => (int)($stats->blacklisted_sources ?? 0),
                'industry' => (int)($stats->industry_sources ?? 0),
                'avg_trust' => round((float)($stats->avg_trust_score ?? 0), 2),
                'total_successes' => (int)($stats->total_successes ?? 0),
                'total_failures' => (int)($stats->total_failures ?? 0),
            ],
            'by_category' => array_map(fn($c) => [
                'category' => $c->domain_category,
                'count' => (int)$c->count,
                'avg_trust' => round((float)$c->avg_trust, 2),
            ], $byCategory),
        ];
    }
}
