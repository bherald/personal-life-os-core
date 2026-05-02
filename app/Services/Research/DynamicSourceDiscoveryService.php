<?php

namespace App\Services\Research;

use App\Services\AIService;
use App\Traits\RecursionAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * DynamicSourceDiscoveryService - AI-driven source discovery for universal research
 *
 * v2.0 - Now fully database-driven with self-learning capabilities
 *
 * Features:
 * - Discovers new sources on-the-fly based on research needs
 * - Evaluates safety and trustworthiness using database-driven rules
 * - Maintains whitelist/blacklist with automatic categorization
 * - Tracks source health and reliability over time
 * - Self-learning: Updates rules based on source performance
 * - Self-healing: Attempts to recover failing sources
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class DynamicSourceDiscoveryService
{
    use RecursionAware;

    private AIService $aiService;
    private string $connection = 'pgsql_rag';

    // Cached rules (loaded from database)
    private ?array $tldRules = null;
    private ?array $whitelistRules = null;
    private ?array $blacklistRules = null;
    private ?array $categoryDomainRules = null;
    private ?array $safetyModifierRules = null;

    // Rule cache TTL (reload every 15 minutes)
    private const RULE_CACHE_TTL = 900;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    // =========================================================================
    // RULE LOADING - Loads patterns from discovery_rules table
    // =========================================================================

    /**
     * Load all rules from database (with caching)
     */
    private function loadRules(): void
    {
        if ($this->tldRules !== null) {
            return; // Already loaded this request
        }

        $cacheKey = 'discovery_rules:all';
        $cached = Cache::get($cacheKey);

        if ($cached) {
            $this->tldRules = $cached['tld'] ?? [];
            $this->whitelistRules = $cached['whitelist'] ?? [];
            $this->blacklistRules = $cached['blacklist'] ?? [];
            $this->categoryDomainRules = $cached['category_domain'] ?? [];
            $this->safetyModifierRules = $cached['safety_modifier'] ?? [];
            return;
        }

        // Load from database
        $this->tldRules = $this->loadRulesByType('tld_trust');
        $this->whitelistRules = $this->loadRulesByType('whitelist_pattern');
        $this->blacklistRules = $this->loadRulesByType('blacklist_pattern');
        $this->categoryDomainRules = $this->loadRulesByType('category_domain');
        $this->safetyModifierRules = $this->loadRulesByType('safety_modifier');

        // Cache all rules together
        Cache::put($cacheKey, [
            'tld' => $this->tldRules,
            'whitelist' => $this->whitelistRules,
            'blacklist' => $this->blacklistRules,
            'category_domain' => $this->categoryDomainRules,
            'safety_modifier' => $this->safetyModifierRules,
        ], self::RULE_CACHE_TTL);
    }

    /**
     * Load rules by type from database
     */
    private function loadRulesByType(string $ruleType): array
    {
        try {
            $rules = DB::connection($this->connection)->select("
                SELECT
                    id, rule_name, match_pattern, pattern_type,
                    trust_score_value, trust_score_multiplier, safety_score_adjustment,
                    domain_category, suggested_specializations, suggested_content_types,
                    auto_whitelist, auto_blacklist, requires_verification, priority
                FROM discovery_rules
                WHERE rule_type = ? AND is_active = true
                ORDER BY priority ASC, created_at ASC
            ", [$ruleType]);

            return array_map(function ($rule) {
                $r = (array)$rule;
                $r['suggested_specializations'] = json_decode($r['suggested_specializations'] ?? '[]', true);
                $r['suggested_content_types'] = json_decode($r['suggested_content_types'] ?? '[]', true);
                return $r;
            }, $rules);

        } catch (Exception $e) {
            Log::warning("Failed to load {$ruleType} rules", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Clear rules cache (call after rule updates)
     */
    public function clearRulesCache(): void
    {
        Cache::forget('discovery_rules:all');
        $this->tldRules = null;
        $this->whitelistRules = null;
        $this->blacklistRules = null;
        $this->categoryDomainRules = null;
        $this->safetyModifierRules = null;
    }

    /**
     * Get all rules (for admin/debug)
     */
    public function getAllRules(): array
    {
        $this->loadRules();
        return [
            'tld' => $this->tldRules,
            'whitelist' => $this->whitelistRules,
            'blacklist' => $this->blacklistRules,
            'category_domain' => $this->categoryDomainRules,
            'safety_modifier' => $this->safetyModifierRules,
        ];
    }

    // =========================================================================
    // DISCOVERY - Main source discovery functionality
    // =========================================================================

    /**
     * Discover sources for a given topic/query
     *
     * @param string $topic The research topic or query
     * @param string $category Domain category (genealogy, science, news, etc.)
     * @param int $limit Maximum sources to discover
     * @return array Discovered sources with metadata
     */
    public function discoverSourcesForTopic(string $topic, string $category = 'general', int $limit = 10): array
    {
        // RLM: Try recursive source discovery
        $rlm = $this->tryRecursive('dynamic_source_discovery', 'partition_map', ['topic' => $topic, 'category' => $category, 'options' => ['limit' => $limit]], function ($ctx) {
            return $this->discoverSourcesForTopic($ctx['topic'], $ctx['category'] ?? 'general', $ctx['options']['limit'] ?? 10);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $this->loadRules();
        $startTime = microtime(true);
        $discovered = [];

        try {
            // Track this discovery pattern
            $patternHash = $this->recordDiscoveryPattern($topic, $category);

            // Step 1: Ask AI for authoritative source suggestions
            $aiSuggestions = $this->getAISuggestions($topic, $category);

            // Step 2: Search for sources using existing research infrastructure
            $searchSources = $this->searchForSources($topic, $category);

            // Merge and deduplicate
            $allCandidates = array_merge($aiSuggestions, $searchSources);
            $uniqueDomains = [];

            foreach ($allCandidates as $candidate) {
                $domain = $this->extractDomain($candidate['url'] ?? $candidate['domain'] ?? '');
                if ($domain && !isset($uniqueDomains[$domain])) {
                    $uniqueDomains[$domain] = $candidate;
                }
            }

            // Step 3: Evaluate and register each unique source
            $count = 0;
            foreach ($uniqueDomains as $domain => $candidate) {
                if ($count >= $limit) break;

                // Check if already known
                $existing = $this->getSourceByDomain($domain);
                if ($existing) {
                    $discovered[] = array_merge($existing, ['status' => 'existing']);
                    $count++;
                    continue;
                }

                // Evaluate safety
                $evaluation = $this->evaluateSourceSafety($domain, $candidate['url'] ?? "https://{$domain}");

                // Register if safe enough
                if ($evaluation['safety_score'] >= 0.3 && !$evaluation['is_blacklisted']) {
                    $sourceId = $this->registerSource([
                        'domain' => $domain,
                        'full_url' => $candidate['url'] ?? "https://{$domain}",
                        'display_name' => $candidate['title'] ?? $domain,
                        'source_type' => $candidate['type'] ?? 'webpage',
                        'domain_category' => $evaluation['domain_category'],
                        'specializations' => $candidate['specializations'] ?? [$category],
                        'safety_score' => $evaluation['safety_score'],
                        'trust_score' => $evaluation['trust_score'],
                        'safety_evaluation' => $evaluation,
                        'is_whitelisted' => $evaluation['is_whitelisted'],
                        'requires_sandbox' => $evaluation['requires_sandbox'],
                        'discovery_context' => "Discovered for topic: {$topic}",
                        'discovery_query' => $topic,
                    ]);

                    if ($sourceId) {
                        $discovered[] = array_merge($evaluation, [
                            'id' => $sourceId,
                            'domain' => $domain,
                            'status' => 'new',
                        ]);
                        $count++;
                    }
                }
            }

            // Update discovery pattern with results
            $this->updateDiscoveryPattern($patternHash, count($discovered), count($allCandidates));

            Log::info('DynamicSourceDiscovery completed', [
                'topic' => $topic,
                'category' => $category,
                'discovered_count' => count($discovered),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => true,
                'sources' => $discovered,
                'count' => count($discovered),
                'topic' => $topic,
                'category' => $category,
            ];

        } catch (Exception $e) {
            Log::error('DynamicSourceDiscovery failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'sources' => $discovered,
                'count' => count($discovered),
            ];
        }
    }

    /**
     * Ask AI for authoritative source suggestions
     * Now includes learned patterns from successful discoveries
     */
    private function getAISuggestions(string $topic, string $category): array
    {
        $cacheKey = "source_suggestions:" . md5("{$topic}:{$category}");
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // Get top-performing sources in this category for context
        $topSources = $this->getTopPerformingSources($category, 5);
        $exampleDomains = array_map(fn($s) => $s['domain'], $topSources);
        $exampleList = !empty($exampleDomains)
            ? "\n\nExamples of successful sources in this category: " . implode(', ', $exampleDomains)
            : '';

        // Get successful discovery patterns
        $patterns = $this->getSuccessfulPatterns($category, 3);
        $patternHint = !empty($patterns)
            ? "\n\nPreviously successful search patterns: " . implode('; ', array_column($patterns, 'pattern_used'))
            : '';

        $prompt = <<<PROMPT
You are a research librarian helping find authoritative sources.

Topic: {$topic}
Category: {$category}{$exampleList}{$patternHint}

List 5-10 authoritative websites or databases that would be excellent sources for researching this topic.

For each source, provide:
1. The domain name (e.g., "archives.gov" not "https://archives.gov")
2. A brief description of why it's authoritative
3. What type of content it provides (api, database, archive, wiki, news, academic)
4. Specializations (e.g., ["genealogy", "vital_records"] or ["science", "research"])

Return ONLY valid JSON array, no other text:
[
  {
    "domain": "example.gov",
    "title": "Example Government Archives",
    "description": "Official government records",
    "type": "archive",
    "specializations": ["history", "government_records"]
  }
]

Focus on:
- Government sources (.gov, .edu) when applicable
- Established archives and libraries
- Academic databases
- Known authoritative sources in the {$category} field
- Sources with good APIs or scrapable content
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 1500,
                'factual_mode' => true,
            ]);

            if (!empty($result['response'])) {
                // Extract JSON from response
                $content = $result['response'];
                if (preg_match('/\[[\s\S]*\]/m', $content, $matches)) {
                    $suggestions = json_decode($matches[0], true);
                    if (is_array($suggestions)) {
                        Cache::put($cacheKey, $suggestions, now()->addHours(24));
                        return $suggestions;
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('AI source suggestions failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Search using existing research infrastructure for potential sources
     */
    private function searchForSources(string $topic, string $category): array
    {
        $sources = [];
        $searchQuery = "authoritative sources for {$topic} research .edu .gov .org database";

        // Use existing research_sources search engines
        $engines = DB::connection($this->connection)->select("
            SELECT id, name, search_url_template, result_selector
            FROM research_sources
            WHERE is_search_engine = true AND is_active = true
            ORDER BY trust_score DESC
            LIMIT 2
        ");

        foreach ($engines as $engine) {
            try {
                $searchUrl = str_replace('{query}', urlencode($searchQuery), $engine->search_url_template);

                $response = Http::connectTimeout(5)->timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->get($searchUrl);

                if ($response->successful()) {
                    // Extract URLs from response (simple regex approach)
                    $html = $response->body();
                    preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $html, $matches);

                    foreach ($matches[0] as $url) {
                        $domain = $this->extractDomain($url);
                        if ($domain && $this->isRelevantDomain($domain, $category)) {
                            $sources[] = [
                                'url' => $url,
                                'domain' => $domain,
                                'found_via' => $engine->name,
                            ];
                        }
                    }
                }

                // Rate limiting
                usleep(500000); // 500ms between requests

            } catch (Exception $e) {
                Log::debug("Search engine {$engine->name} failed", ['error' => $e->getMessage()]);
            }
        }

        return array_slice($sources, 0, 20); // Limit candidates
    }

    // =========================================================================
    // EVALUATION - Source safety and trust evaluation (now DB-driven)
    // =========================================================================

    /**
     * Evaluate the safety and trustworthiness of a source
     *
     * @param string $domain The domain to evaluate
     * @param string|null $url Optional full URL for additional context
     * @return array Safety evaluation results
     */
    public function evaluateSourceSafety(string $domain, ?string $url = null): array
    {
        $this->loadRules();

        $evaluation = [
            'domain' => $domain,
            'safety_score' => 0.5,
            'trust_score' => 0.5,
            'domain_category' => 'unknown',
            'is_whitelisted' => false,
            'is_blacklisted' => false,
            'requires_sandbox' => true,
            'factors' => [],
            'matched_rules' => [],
        ];

        // Step 1: Check blacklist rules first
        foreach ($this->blacklistRules as $rule) {
            if ($this->matchesPattern($domain, $rule)) {
                $evaluation['is_blacklisted'] = true;
                $evaluation['safety_score'] = 0.0;
                $evaluation['trust_score'] = 0.0;
                $evaluation['factors'][] = "Blacklist rule: {$rule['rule_name']}";
                $evaluation['matched_rules'][] = $rule['id'];
                $this->incrementRuleUsage($rule['id']);
                return $evaluation;
            }
        }

        // Step 2: Check database blacklist
        $dbBlacklist = DB::connection($this->connection)->select("
            SELECT blacklist_reason FROM discovered_sources
            WHERE domain = ? AND is_blacklisted = true
            LIMIT 1
        ", [$domain]);

        if (!empty($dbBlacklist)) {
            $evaluation['is_blacklisted'] = true;
            $evaluation['safety_score'] = 0.0;
            $evaluation['trust_score'] = 0.0;
            $evaluation['factors'][] = 'Database blacklisted: ' . $dbBlacklist[0]->blacklist_reason;
            return $evaluation;
        }

        // Step 3: Check whitelist rules
        foreach ($this->whitelistRules as $rule) {
            if ($this->matchesPattern($domain, $rule)) {
                $evaluation['is_whitelisted'] = true;
                $evaluation['safety_score'] = $rule['trust_score_value'] ?? 0.90;
                $evaluation['trust_score'] = $rule['trust_score_value'] ?? 0.90;
                $evaluation['requires_sandbox'] = false;
                $evaluation['factors'][] = "Whitelist rule: {$rule['rule_name']}";
                $evaluation['matched_rules'][] = $rule['id'];

                if (!empty($rule['domain_category'])) {
                    $evaluation['domain_category'] = $rule['domain_category'];
                }
                if (!empty($rule['suggested_specializations'])) {
                    $evaluation['suggested_specializations'] = $rule['suggested_specializations'];
                }

                $this->incrementRuleUsage($rule['id']);
                break;
            }
        }

        // Step 4: Check database whitelist
        if (!$evaluation['is_whitelisted']) {
            $dbWhitelist = DB::connection($this->connection)->select("
                SELECT trust_score, safety_score, domain_category FROM discovered_sources
                WHERE domain = ? AND is_whitelisted = true
                LIMIT 1
            ", [$domain]);

            if (!empty($dbWhitelist)) {
                $evaluation['is_whitelisted'] = true;
                $evaluation['safety_score'] = (float)$dbWhitelist[0]->safety_score;
                $evaluation['trust_score'] = (float)$dbWhitelist[0]->trust_score;
                $evaluation['domain_category'] = $dbWhitelist[0]->domain_category;
                $evaluation['requires_sandbox'] = false;
                $evaluation['factors'][] = 'Database whitelisted';
            }
        }

        // Step 5: Check category domain rules
        foreach ($this->categoryDomainRules as $rule) {
            if ($this->matchesPattern($domain, $rule)) {
                $ruleScore = $rule['trust_score_value'] ?? 0.80;
                $evaluation['trust_score'] = max($evaluation['trust_score'], $ruleScore);
                $evaluation['safety_score'] = max($evaluation['safety_score'], $ruleScore * 0.95);
                $evaluation['factors'][] = "Category domain: {$rule['rule_name']}";
                $evaluation['matched_rules'][] = $rule['id'];

                if (!empty($rule['domain_category'])) {
                    $evaluation['domain_category'] = $rule['domain_category'];
                }
                if (!empty($rule['suggested_specializations'])) {
                    $evaluation['suggested_specializations'] = $rule['suggested_specializations'];
                }
                if ($rule['auto_whitelist'] ?? false) {
                    $evaluation['is_whitelisted'] = true;
                    $evaluation['requires_sandbox'] = false;
                }

                $this->incrementRuleUsage($rule['id']);
                break;
            }
        }

        // Step 6: TLD analysis using rules
        $tld = $this->extractTLD($domain);
        foreach ($this->tldRules as $rule) {
            if ($this->matchesTLD($domain, $rule)) {
                $tldScore = $rule['trust_score_value'] ?? 0.70;
                $evaluation['trust_score'] = max($evaluation['trust_score'], $tldScore);
                $evaluation['safety_score'] = max($evaluation['safety_score'], $tldScore * 0.9);
                $evaluation['factors'][] = "TLD rule: {$rule['rule_name']} = {$tldScore}";
                $evaluation['matched_rules'][] = $rule['id'];

                // Set category based on TLD
                if (in_array($tld, ['gov', 'gov.uk', 'mil', 'gc.ca', 'gov.au'])) {
                    $evaluation['domain_category'] = 'government';
                } elseif (in_array($tld, ['edu', 'ac.uk', 'edu.au'])) {
                    $evaluation['domain_category'] = 'academic';
                }

                if ($rule['auto_whitelist'] ?? false) {
                    $evaluation['is_whitelisted'] = true;
                    $evaluation['requires_sandbox'] = false;
                }

                $this->incrementRuleUsage($rule['id']);
                break;
            }
        }

        // Step 7: Apply safety modifiers
        if ($url) {
            foreach ($this->safetyModifierRules as $rule) {
                if ($this->matchesPattern($url, $rule)) {
                    $adjustment = $rule['safety_score_adjustment'] ?? 0.0;
                    $evaluation['safety_score'] = max(0.0, min(1.0, $evaluation['safety_score'] + $adjustment));
                    $evaluation['factors'][] = "Safety modifier: {$rule['rule_name']} ({$adjustment})";
                    $evaluation['matched_rules'][] = $rule['id'];
                    $this->incrementRuleUsage($rule['id']);
                }
            }
        }

        // Step 8: AI-based evaluation for unknown domains (only if not already trusted)
        if ($evaluation['trust_score'] < 0.7 && $evaluation['domain_category'] === 'unknown') {
            $aiEvaluation = $this->getAIEvaluation($domain, $url);
            if ($aiEvaluation) {
                $evaluation['trust_score'] = ($evaluation['trust_score'] + $aiEvaluation['trust_score']) / 2;
                $evaluation['safety_score'] = ($evaluation['safety_score'] + $aiEvaluation['safety_score']) / 2;
                $evaluation['domain_category'] = $aiEvaluation['domain_category'] ?? $evaluation['domain_category'];
                $evaluation['factors'][] = 'AI evaluation applied';
                $evaluation['ai_evaluation'] = $aiEvaluation;
            }
        }

        // Step 9: Determine sandbox requirement
        if ($evaluation['safety_score'] >= 0.85 && $evaluation['is_whitelisted']) {
            $evaluation['requires_sandbox'] = false;
        }

        return $evaluation;
    }

    /**
     * Check if domain matches a rule pattern
     */
    private function matchesPattern(string $value, array $rule): bool
    {
        $pattern = $rule['match_pattern'];
        $type = $rule['pattern_type'] ?? 'exact';

        return match ($type) {
            'exact' => strtolower($value) === strtolower($pattern),
            'suffix' => str_ends_with(strtolower($value), strtolower($pattern)),
            'prefix' => str_starts_with(strtolower($value), strtolower($pattern)),
            'contains' => str_contains(strtolower($value), strtolower($pattern)),
            'regex' => (bool)preg_match($pattern, $value),
            default => false,
        };
    }

    /**
     * Check if domain matches a TLD rule
     */
    private function matchesTLD(string $domain, array $rule): bool
    {
        $pattern = $rule['match_pattern'];
        // TLD rules use suffix pattern type
        return str_ends_with(strtolower($domain), strtolower($pattern));
    }

    /**
     * Increment rule usage counter (for self-learning)
     */
    private function incrementRuleUsage(string $ruleId): void
    {
        try {
            DB::connection($this->connection)->update("
                UPDATE discovery_rules SET
                    times_applied = times_applied + 1,
                    last_applied_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$ruleId]);
        } catch (Exception $e) {
            // Non-critical, just log
            Log::debug("Failed to increment rule usage", ['rule_id' => $ruleId]);
        }
    }

    /**
     * Get AI evaluation for a domain
     */
    private function getAIEvaluation(string $domain, ?string $url): ?array
    {
        $cacheKey = "domain_eval:" . md5($domain);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $prompt = <<<PROMPT
Evaluate the website domain: {$domain}

Analyze and return ONLY valid JSON with these fields:
{
  "trust_score": 0.0-1.0 (how trustworthy for research purposes),
  "safety_score": 0.0-1.0 (how safe to scrape/access),
  "domain_category": "government|academic|archive|commercial|community|news|unknown",
  "content_type": "primary_source|secondary_source|aggregator|news|wiki|unknown",
  "reasoning": "brief explanation",
  "known_issues": ["list any known issues or concerns"]
}

Consider:
- Is this a primary source or aggregator?
- Is it an established organization?
- Any history of malware, phishing, or misinformation?
- Is the content typically well-sourced?
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 500,
                'factual_mode' => true,
            ]);

            if (!empty($result['response'])) {
                if (preg_match('/\{[\s\S]*\}/m', $result['response'], $matches)) {
                    $evaluation = json_decode($matches[0], true);
                    if (is_array($evaluation)) {
                        Cache::put($cacheKey, $evaluation, now()->addDays(7));
                        return $evaluation;
                    }
                }
            }
        } catch (Exception $e) {
            Log::debug('AI domain evaluation failed', ['domain' => $domain, 'error' => $e->getMessage()]);
        }

        return null;
    }

    // =========================================================================
    // REGISTRATION & RETRIEVAL
    // =========================================================================

    /**
     * Register a new discovered source
     *
     * @param array $sourceData Source data to register
     * @return string|null The source UUID if successful
     */
    public function registerSource(array $sourceData): ?string
    {
        try {
            $domain = $sourceData['domain'] ?? null;
            if (!$domain) {
                return null;
            }

            // Check if already exists
            $existing = DB::connection($this->connection)->select("
                SELECT id FROM discovered_sources WHERE domain = ?
            ", [$domain]);

            if (!empty($existing)) {
                return $existing[0]->id;
            }

            $result = DB::connection($this->connection)->select("
                INSERT INTO discovered_sources (
                    domain, full_url, display_name, source_type, domain_category,
                    specializations, content_types, safety_score, trust_score,
                    safety_evaluation, is_whitelisted, requires_sandbox,
                    discovered_by, discovery_context, discovery_query
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?::jsonb, ?::jsonb, ?, ?,
                    ?::jsonb, ?, ?,
                    'ai', ?, ?
                )
                ON CONFLICT (domain) DO UPDATE SET
                    updated_at = CURRENT_TIMESTAMP
                RETURNING id
            ", [
                $domain,
                $sourceData['full_url'] ?? "https://{$domain}",
                $sourceData['display_name'] ?? $domain,
                $sourceData['source_type'] ?? 'webpage',
                $sourceData['domain_category'] ?? 'unknown',
                json_encode($sourceData['specializations'] ?? []),
                json_encode($sourceData['content_types'] ?? ['text']),
                $sourceData['safety_score'] ?? 0.5,
                $sourceData['trust_score'] ?? 0.5,
                json_encode($sourceData['safety_evaluation'] ?? []),
                $sourceData['is_whitelisted'] ?? false,
                $sourceData['requires_sandbox'] ?? true,
                $sourceData['discovery_context'] ?? null,
                $sourceData['discovery_query'] ?? null,
            ]);

            return $result[0]->id ?? null;

        } catch (Exception $e) {
            Log::error('Failed to register source', [
                'domain' => $sourceData['domain'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get a source by domain
     */
    public function getSourceByDomain(string $domain): ?array
    {
        $result = DB::connection($this->connection)->select("
            SELECT * FROM discovered_sources WHERE domain = ? LIMIT 1
        ", [$domain]);

        if (empty($result)) {
            return null;
        }

        $source = (array)$result[0];
        $source['specializations'] = json_decode($source['specializations'] ?? '[]', true);
        $source['content_types'] = json_decode($source['content_types'] ?? '[]', true);
        $source['safety_evaluation'] = json_decode($source['safety_evaluation'] ?? '{}', true);

        return $source;
    }

    /**
     * Find sources specialized for a given domain/category
     * Queries both research_sources (curated) and discovered_sources (dynamic)
     */
    public function findSpecializedSources(string $specialization, int $limit = 20): array
    {
        $sources = [];

        // First get curated sources from research_sources table (preferred)
        $curated = DB::connection($this->connection)->select("
            SELECT
                id, name, base_url as full_url, research_category,
                search_url_template, trust_score, source_type,
                is_search_engine, is_active
            FROM research_sources
            WHERE is_active = true
            AND research_category = ?
            ORDER BY trust_score DESC
            LIMIT ?
        ", [$specialization, $limit]);

        foreach ($curated as $row) {
            $source = (array)$row;
            $source['domain'] = parse_url($source['full_url'] ?? '', PHP_URL_HOST) ?: $source['name'];
            $source['specializations'] = [$specialization];
            $source['source'] = 'curated';
            $sources[] = $source;
        }

        // If we need more, get from discovered_sources
        $remaining = $limit - count($sources);
        if ($remaining > 0) {
            $discovered = DB::connection($this->connection)->select("
                SELECT * FROM discovered_sources
                WHERE is_active = true
                AND is_blacklisted = false
                AND specializations @> ?::jsonb
                ORDER BY trust_score DESC, success_count DESC
                LIMIT ?
            ", [json_encode([$specialization]), $remaining]);

            foreach ($discovered as $row) {
                $source = (array)$row;
                $source['specializations'] = json_decode($source['specializations'] ?? '[]', true);
                $source['content_types'] = json_decode($source['content_types'] ?? '[]', true);
                $source['source'] = 'discovered';
                $sources[] = $source;
            }
        }

        return $sources;
    }

    // =========================================================================
    // HEALTH TRACKING & FEEDBACK
    // =========================================================================

    /**
     * Update source health after a scraping attempt
     */
    public function updateSourceHealth(string $sourceId, bool $success, int $responseMs = 0, ?string $errorMessage = null): void
    {
        if ($success) {
            DB::connection($this->connection)->update("
                UPDATE discovered_sources SET
                    success_count = success_count + 1,
                    consecutive_failures = 0,
                    last_success_at = CURRENT_TIMESTAMP,
                    avg_response_ms = COALESCE(
                        (avg_response_ms * success_count + ?) / (success_count + 1),
                        ?
                    ),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$responseMs, $responseMs, $sourceId]);
        } else {
            DB::connection($this->connection)->update("
                UPDATE discovered_sources SET
                    failure_count = failure_count + 1,
                    consecutive_failures = consecutive_failures + 1,
                    last_failure_at = CURRENT_TIMESTAMP,
                    last_error_message = ?,
                    is_active = (consecutive_failures < 5),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$errorMessage, $sourceId]);
        }
    }

    /**
     * Record performance feedback for a source
     *
     * @param string $sourceId Source identifier
     * @param array $feedback Feedback data [accuracy_rating, relevance_rating, etc.]
     * @param string|null $missionId Research mission that generated this feedback
     * @return bool Success
     */
    public function recordPerformanceFeedback(
        string $sourceId,
        array $feedback,
        ?string $missionId = null,
        ?string $topic = null,
        ?string $category = null
    ): bool {
        try {
            // Get current source info
            $source = DB::connection($this->connection)->select("
                SELECT domain, trust_score, safety_score FROM discovered_sources WHERE id = ?
            ", [$sourceId]);

            if (empty($source)) {
                return false;
            }

            $currentTrust = (float)$source[0]->trust_score;
            $currentSafety = (float)$source[0]->safety_score;

            // Calculate overall score from ratings
            $ratings = array_filter([
                $feedback['accuracy_rating'] ?? null,
                $feedback['relevance_rating'] ?? null,
                $feedback['reliability_rating'] ?? null,
                $feedback['timeliness_rating'] ?? null,
            ], fn($v) => $v !== null);

            $overallScore = !empty($ratings) ? array_sum($ratings) / (count($ratings) * 5) : null;

            // Determine feedback type
            $feedbackType = $feedback['feedback_type'] ?? $this->determineFeedbackType($overallScore);

            // Calculate new scores based on feedback
            $newTrust = $currentTrust;
            $newSafety = $currentSafety;

            if ($overallScore !== null) {
                // Gradual adjustment: move 10% toward the feedback
                $targetTrust = $overallScore;
                $newTrust = $currentTrust + (($targetTrust - $currentTrust) * 0.1);
                $newTrust = max(0.0, min(1.0, $newTrust));

                // Safety follows trust but more conservatively
                $newSafety = $currentSafety + (($targetTrust - $currentSafety) * 0.05);
                $newSafety = max(0.0, min(1.0, $newSafety));
            }

            // Record feedback
            DB::connection($this->connection)->insert("
                INSERT INTO source_performance_feedback (
                    source_id, source_domain, mission_id,
                    research_topic, research_category,
                    accuracy_rating, relevance_rating, reliability_rating, timeliness_rating,
                    overall_score, feedback_type, notes, error_message,
                    response_time_ms, content_length,
                    facts_extracted, facts_verified, facts_rejected,
                    trust_score_before, trust_score_after,
                    safety_score_before, safety_score_after,
                    rated_by
                ) VALUES (
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?
                )
            ", [
                $sourceId, $source[0]->domain, $missionId,
                $topic, $category,
                $feedback['accuracy_rating'] ?? null,
                $feedback['relevance_rating'] ?? null,
                $feedback['reliability_rating'] ?? null,
                $feedback['timeliness_rating'] ?? null,
                $overallScore, $feedbackType,
                $feedback['notes'] ?? null,
                $feedback['error_message'] ?? null,
                $feedback['response_time_ms'] ?? null,
                $feedback['content_length'] ?? null,
                $feedback['facts_extracted'] ?? 0,
                $feedback['facts_verified'] ?? 0,
                $feedback['facts_rejected'] ?? 0,
                $currentTrust, $newTrust,
                $currentSafety, $newSafety,
                $feedback['rated_by'] ?? 'system',
            ]);

            // Update source scores if they changed
            if ($newTrust !== $currentTrust || $newSafety !== $currentSafety) {
                DB::connection($this->connection)->update("
                    UPDATE discovered_sources SET
                        trust_score = ?,
                        safety_score = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ", [$newTrust, $newSafety, $sourceId]);
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to record performance feedback', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Determine feedback type from overall score
     */
    private function determineFeedbackType(?float $score): string
    {
        if ($score === null) return 'neutral';
        if ($score >= 0.9) return 'excellent';
        if ($score >= 0.7) return 'good';
        if ($score >= 0.5) return 'neutral';
        if ($score >= 0.3) return 'poor';
        return 'unusable';
    }

    // =========================================================================
    // STATISTICS & PATTERNS
    // =========================================================================

    /**
     * Get health statistics for all sources
     */
    public function getSourceHealthStats(): array
    {
        $stats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total_sources,
                COUNT(*) FILTER (WHERE is_active = true) as active_sources,
                COUNT(*) FILTER (WHERE is_whitelisted = true) as whitelisted,
                COUNT(*) FILTER (WHERE is_blacklisted = true) as blacklisted,
                COUNT(*) FILTER (WHERE consecutive_failures >= 3) as failing,
                AVG(trust_score)::numeric(4,3) as avg_trust_score,
                AVG(safety_score)::numeric(4,3) as avg_safety_score,
                SUM(success_count) as total_successes,
                SUM(failure_count) as total_failures
            FROM discovered_sources
        ");

        $byCategory = DB::connection($this->connection)->select("
            SELECT domain_category, COUNT(*) as count
            FROM discovered_sources
            WHERE is_active = true
            GROUP BY domain_category
            ORDER BY count DESC
        ");

        $ruleStats = DB::connection($this->connection)->select("
            SELECT rule_type, COUNT(*) as count, SUM(times_applied) as total_applications
            FROM discovery_rules
            WHERE is_active = true
            GROUP BY rule_type
        ");

        return [
            'summary' => (array)($stats[0] ?? []),
            'by_category' => array_map(fn($r) => (array)$r, $byCategory),
            'rule_stats' => array_map(fn($r) => (array)$r, $ruleStats),
        ];
    }

    /**
     * Get top-performing sources for a category
     */
    public function getTopPerformingSources(string $category, int $limit = 10): array
    {
        $results = DB::connection($this->connection)->select("
            SELECT domain, display_name, trust_score, safety_score, success_count, failure_count
            FROM discovered_sources
            WHERE is_active = true
            AND is_blacklisted = false
            AND (domain_category = ? OR specializations @> ?::jsonb)
            AND success_count > 0
            ORDER BY
                (success_count::float / GREATEST(success_count + failure_count, 1)) DESC,
                trust_score DESC
            LIMIT ?
        ", [$category, json_encode([$category]), $limit]);

        return array_map(fn($r) => (array)$r, $results);
    }

    /**
     * Get successful discovery patterns for a category
     */
    public function getSuccessfulPatterns(string $category, int $limit = 5): array
    {
        try {
            $results = DB::connection($this->connection)->select("
                SELECT pattern_used, success_rate_pct, sources_discovered
                FROM source_discovery_patterns
                WHERE domain_category = ?
                AND is_active = true
                AND success_rate_pct >= 50
                ORDER BY success_rate_pct DESC, sources_discovered DESC
                LIMIT ?
            ", [$category, $limit]);

            return array_map(fn($r) => (array)$r, $results);
        } catch (Exception $e) {
            // Table might not exist yet
            return [];
        }
    }

    /**
     * Record a discovery pattern
     */
    private function recordDiscoveryPattern(string $topic, string $category): string
    {
        $patternHash = md5("{$category}:{$topic}");

        try {
            DB::connection($this->connection)->statement("
                INSERT INTO source_discovery_patterns (
                    pattern_hash, domain_category, pattern_used,
                    discovery_method, pattern_keywords
                ) VALUES (?, ?, ?, 'hybrid', ?::jsonb)
                ON CONFLICT (pattern_hash) DO UPDATE SET
                    times_used = source_discovery_patterns.times_used + 1,
                    last_used_at = CURRENT_TIMESTAMP
            ", [
                $patternHash,
                $category,
                $topic,
                json_encode(explode(' ', strtolower($topic))),
            ]);
        } catch (Exception $e) {
            // Non-critical
            Log::debug("Failed to record discovery pattern", ['error' => $e->getMessage()]);
        }

        return $patternHash;
    }

    /**
     * Update discovery pattern with results
     */
    private function updateDiscoveryPattern(string $patternHash, int $discovered, int $candidates): void
    {
        try {
            $successRate = $candidates > 0 ? ($discovered / $candidates) * 100 : 0;

            DB::connection($this->connection)->update("
                UPDATE source_discovery_patterns SET
                    sources_discovered = sources_discovered + ?,
                    success_rate_pct = COALESCE(
                        (success_rate_pct * (times_used - 1) + ?) / times_used,
                        ?
                    ),
                    updated_at = CURRENT_TIMESTAMP
                WHERE pattern_hash = ?
            ", [$discovered, $successRate, $successRate, $patternHash]);
        } catch (Exception $e) {
            // Non-critical
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): ?string
    {
        if (empty($url)) return null;

        // Add scheme if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = "https://{$url}";
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if (!$host) return null;

        // Remove www prefix
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Extract TLD from domain
     */
    private function extractTLD(string $domain): string
    {
        // Check for multi-part TLDs first (loaded from rules)
        $this->loadRules();
        foreach ($this->tldRules as $rule) {
            $tld = ltrim($rule['match_pattern'], '.');
            if (str_ends_with($domain, ".{$tld}")) {
                return $tld;
            }
        }

        // Simple single TLD
        $parts = explode('.', $domain);
        return end($parts);
    }

    /**
     * Check if domain is relevant for a category
     */
    private function isRelevantDomain(string $domain, string $category): bool
    {
        $this->loadRules();

        // Filter out common non-research domains (could also be a rule)
        $excluded = ['google.com', 'facebook.com', 'twitter.com', 'instagram.com',
                     'youtube.com', 'linkedin.com', 'amazon.com', 'ebay.com'];

        if (in_array($domain, $excluded)) {
            return false;
        }

        // Check if matches any TLD rule
        foreach ($this->tldRules as $rule) {
            if ($this->matchesTLD($domain, $rule)) {
                return true;
            }
        }

        // Check category domains
        foreach ($this->categoryDomainRules as $rule) {
            if (
                $this->matchesPattern($domain, $rule) &&
                ($rule['domain_category'] === $category || $rule['domain_category'] === null)
            ) {
                return true;
            }
        }

        return true; // Default to allowing evaluation
    }

    // =========================================================================
    // RULE MANAGEMENT (for admin/API)
    // =========================================================================

    /**
     * Add a new discovery rule
     */
    public function addRule(array $ruleData): ?string
    {
        try {
            $result = DB::connection($this->connection)->select("
                INSERT INTO discovery_rules (
                    rule_name, rule_type, match_pattern, pattern_type,
                    trust_score_value, trust_score_multiplier, safety_score_adjustment,
                    domain_category, suggested_specializations, suggested_content_types,
                    auto_whitelist, auto_blacklist, requires_verification,
                    priority, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ", [
                $ruleData['rule_name'],
                $ruleData['rule_type'],
                $ruleData['match_pattern'],
                $ruleData['pattern_type'] ?? 'exact',
                $ruleData['trust_score_value'] ?? null,
                $ruleData['trust_score_multiplier'] ?? 1.0,
                $ruleData['safety_score_adjustment'] ?? 0.0,
                $ruleData['domain_category'] ?? null,
                json_encode($ruleData['suggested_specializations'] ?? []),
                json_encode($ruleData['suggested_content_types'] ?? []),
                $ruleData['auto_whitelist'] ?? false,
                $ruleData['auto_blacklist'] ?? false,
                $ruleData['requires_verification'] ?? true,
                $ruleData['priority'] ?? 100,
                $ruleData['notes'] ?? null,
                $ruleData['created_by'] ?? 'api',
            ]);

            $this->clearRulesCache();
            return $result[0]->id ?? null;

        } catch (Exception $e) {
            Log::error('Failed to add discovery rule', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Update an existing rule
     */
    public function updateRule(string $ruleId, array $updates): bool
    {
        try {
            $setClauses = [];
            $params = [];

            $allowedFields = [
                'rule_name', 'match_pattern', 'pattern_type',
                'trust_score_value', 'trust_score_multiplier', 'safety_score_adjustment',
                'domain_category', 'auto_whitelist', 'auto_blacklist',
                'requires_verification', 'priority', 'notes', 'is_active',
            ];

            foreach ($updates as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $setClauses[] = "{$field} = ?";
                    $params[] = $value;
                }
            }

            // Handle JSON fields separately
            if (isset($updates['suggested_specializations'])) {
                $setClauses[] = "suggested_specializations = ?::jsonb";
                $params[] = json_encode($updates['suggested_specializations']);
            }
            if (isset($updates['suggested_content_types'])) {
                $setClauses[] = "suggested_content_types = ?::jsonb";
                $params[] = json_encode($updates['suggested_content_types']);
            }

            if (empty($setClauses)) {
                return false;
            }

            $setClauses[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = $ruleId;

            $sql = "UPDATE discovery_rules SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $affected = DB::connection($this->connection)->update($sql, $params);

            $this->clearRulesCache();
            return $affected > 0;

        } catch (Exception $e) {
            Log::error('Failed to update discovery rule', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete a rule (soft delete by setting is_active = false)
     */
    public function deleteRule(string $ruleId): bool
    {
        try {
            $affected = DB::connection($this->connection)->update("
                UPDATE discovery_rules SET is_active = false, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$ruleId]);

            $this->clearRulesCache();
            return $affected > 0;

        } catch (Exception $e) {
            Log::error('Failed to delete discovery rule', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get all rules with optional filtering
     */
    public function getRules(?string $ruleType = null, bool $includeInactive = false): array
    {
        $sql = "SELECT * FROM discovery_rules WHERE 1=1";
        $params = [];

        if ($ruleType) {
            $sql .= " AND rule_type = ?";
            $params[] = $ruleType;
        }

        if (!$includeInactive) {
            $sql .= " AND is_active = true";
        }

        $sql .= " ORDER BY priority ASC, rule_type, created_at";

        $results = DB::connection($this->connection)->select($sql, $params);

        return array_map(function ($rule) {
            $r = (array)$rule;
            $r['suggested_specializations'] = json_decode($r['suggested_specializations'] ?? '[]', true);
            $r['suggested_content_types'] = json_decode($r['suggested_content_types'] ?? '[]', true);
            return $r;
        }, $results);
    }
}
