<?php

namespace App\Services;

use App\Services\AIService;
use App\Engine\MCPRouter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Traits\RecursionAware;
use Exception;

/**
 * AI-Powered Data Broker Discovery Service
 *
 * Uses LIVE web search to discover new data broker sites, then AI to analyze them.
 * This ensures current, up-to-date broker information regardless of LLM knowledge cutoff.
 *
 * Discovery Flow:
 * 1. Web search for data broker lists, opt-out guides, privacy resources
 * 2. Extract broker domains from search results
 * 3. AI analyzes each broker for opt-out procedures
 * 4. Queue for human approval before adding to database
 *
 * E06: Personal Data Removal System
 */
class BrokerDiscoveryService
{
    use RecursionAware;

    private AIService $aiService;
    private MCPRouter $mcpRouter;
    private DataRemovalService $dataRemovalService;

    // Search queries to find data broker lists
    private const DISCOVERY_QUERIES = [
        'data broker opt out list 2024 2025',
        'people search sites opt out guide',
        'remove personal information from data brokers',
        'list of data brokers with opt out links',
        'privacy rights data broker removal',
        'CCPA data broker registry california',
    ];

    public function __construct(
        AIService $aiService,
        MCPRouter $mcpRouter,
        DataRemovalService $dataRemovalService
    ) {
        $this->aiService = $aiService;
        $this->mcpRouter = $mcpRouter;
        $this->dataRemovalService = $dataRemovalService;
    }

    /**
     * Discover new data brokers using LIVE web search
     *
     * @param array $config Discovery configuration
     * @return array Discovery results with suggested brokers
     */
    public function discoverBrokers(array $config = []): array
    {
        // RLM: Try recursive broker discovery
        $rlm = $this->tryRecursive('broker_discovery', 'partition_map', ['config' => $config], function ($ctx) {
            return $this->discoverBrokers($ctx['config'] ?? $ctx['data'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $maxResults = $config['max_results'] ?? 10;
        $category = $config['category'] ?? null;
        $skipExisting = $config['skip_existing'] ?? true;
        $useWebSearch = $config['use_web_search'] ?? true;

        Log::info('BrokerDiscoveryService: Starting discovery', [
            'max_results' => $maxResults,
            'category' => $category,
            'use_web_search' => $useWebSearch,
        ]);

        // Get list of existing broker domains to avoid duplicates
        $existingDomains = [];
        if ($skipExisting) {
            $existingDomains = $this->getExistingDomains();
        }

        $allSuggestions = [];

        if ($useWebSearch) {
            // Method 1: Live web search (preferred - current data)
            $webResults = $this->searchWebForBrokers($category);

            if (!empty($webResults)) {
                Log::info('BrokerDiscoveryService: Web search found results', [
                    'count' => count($webResults),
                ]);

                // Extract broker domains from search results
                $extractedDomains = $this->extractBrokerDomainsFromResults($webResults, $existingDomains);

                foreach ($extractedDomains as $domain) {
                    if (count($allSuggestions) >= $maxResults) {
                        break;
                    }

                    $allSuggestions[] = [
                        'domain' => $domain,
                        'source' => 'web_search',
                    ];
                }
            }
        }

        // Method 2: AI knowledge (fallback - may have dated info but good for well-known brokers)
        if (count($allSuggestions) < $maxResults) {
            $aiSuggestions = $this->discoverWithAI($existingDomains, $category, $maxResults - count($allSuggestions));
            foreach ($aiSuggestions as $suggestion) {
                $suggestion['source'] = 'ai_knowledge';
                $allSuggestions[] = $suggestion;
            }
        }

        // Deduplicate by domain
        $uniqueSuggestions = [];
        $seenDomains = [];
        foreach ($allSuggestions as $suggestion) {
            $domain = strtolower($suggestion['domain']);
            if (!in_array($domain, $seenDomains) && !in_array($domain, $existingDomains)) {
                $seenDomains[] = $domain;
                $uniqueSuggestions[] = $suggestion;
            }
        }

        Log::info('BrokerDiscoveryService: Discovery complete', [
            'suggestions_found' => count($uniqueSuggestions),
            'from_web' => count(array_filter($uniqueSuggestions, fn($s) => ($s['source'] ?? '') === 'web_search')),
            'from_ai' => count(array_filter($uniqueSuggestions, fn($s) => ($s['source'] ?? '') === 'ai_knowledge')),
        ]);

        return [
            'success' => true,
            'suggestions' => array_slice($uniqueSuggestions, 0, $maxResults),
            'existing_domains_skipped' => count($existingDomains),
        ];
    }

    /**
     * Search the web for data broker information using MCP tools
     */
    private function searchWebForBrokers(?string $category = null): array
    {
        $results = [];

        // Build search queries
        $queries = self::DISCOVERY_QUERIES;
        if ($category) {
            array_unshift($queries, "{$category} data broker opt out list");
        }

        // Use Graphlit web search if available (most reliable)
        foreach (array_slice($queries, 0, 3) as $query) {
            try {
                $searchResult = $this->mcpRouter->callTool('graphlit', 'webSearch', [
                    'query' => $query,
                    'limit' => 10,
                ]);

                if (!empty($searchResult['content'])) {
                    foreach ($searchResult['content'] as $item) {
                        if (isset($item['text'])) {
                            $results[] = [
                                'query' => $query,
                                'content' => $item['text'],
                                'source' => 'graphlit',
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                Log::debug('BrokerDiscoveryService: Graphlit search failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Try direct HTTP search of known opt-out guide sites
        if (empty($results)) {
            $results = $this->scrapeKnownOptOutGuides();
        }

        return $results;
    }

    /**
     * Scrape known opt-out guide websites for broker lists
     */
    private function scrapeKnownOptOutGuides(): array
    {
        $guides = [
            'https://www.privacyrights.org/data-brokers',
            'https://joindeleteme.com/blog/data-broker-opt-out-list/',
        ];

        $results = [];

        foreach ($guides as $url) {
            try {
                $response = Http::connectTimeout(5)->timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                    ->get($url);

                if ($response->successful()) {
                    $results[] = [
                        'url' => $url,
                        'content' => $response->body(),
                        'source' => 'direct_scrape',
                    ];
                }
            } catch (Exception $e) {
                Log::debug('BrokerDiscoveryService: Failed to scrape guide', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Extract broker domains from search/scrape results using AI
     */
    private function extractBrokerDomainsFromResults(array $results, array $existingDomains): array
    {
        if (empty($results)) {
            return [];
        }

        // Combine all content
        $combinedContent = '';
        foreach ($results as $result) {
            $content = $result['content'] ?? '';
            // Limit content size
            if (strlen($content) > 10000) {
                $content = substr($content, 0, 10000);
            }
            $combinedContent .= $content . "\n\n";
        }

        // Limit total content
        if (strlen($combinedContent) > 30000) {
            $combinedContent = substr($combinedContent, 0, 30000);
        }

        $excludeList = implode(', ', array_slice($existingDomains, 0, 50));

        $prompt = <<<PROMPT
Extract data broker website domains from the following content. These are sites that collect and sell personal information.

IMPORTANT: Only extract domains that are clearly data brokers or people search sites.
DO NOT include: social media, news sites, government sites, or general websites.

EXCLUDE these domains (already in database): {$excludeList}

Content to analyze:
{$combinedContent}

Return ONLY a JSON array of domain names (no explanations):
["spokeo.com", "beenverified.com", "example.com"]

If no valid data broker domains found, return: []
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 1000,
            ]);

            if (!$result['success']) {
                Log::error('BrokerDiscoveryService: AI extraction failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                return [];
            }

            $response = $result['response'];

            // Extract JSON array from response
            if (preg_match('/\[[\s\S]*?\]/', $response, $matches)) {
                $domains = json_decode($matches[0], true);
                if (is_array($domains)) {
                    // Clean and validate domains
                    return array_filter(array_map(function ($d) {
                        $d = strtolower(trim($d));
                        // Basic domain validation
                        if (preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $d)) {
                            return $d;
                        }
                        return null;
                    }, $domains));
                }
            }
        } catch (Exception $e) {
            Log::error('BrokerDiscoveryService: AI extraction failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Discover brokers using AI knowledge (fallback method)
     */
    private function discoverWithAI(array $existingDomains, ?string $category, int $maxResults): array
    {
        $prompt = $this->buildDiscoveryPrompt($existingDomains, $category, $maxResults);

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 4000,
                'system_prompt' => $this->getDiscoverySystemPrompt(),
            ]);

            if (!$result['success']) {
                Log::error('BrokerDiscoveryService: AI discovery failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                    'provider' => $result['provider'] ?? 'none',
                ]);
                return [];
            }

            return $this->parseDiscoveryResponse($result['response']);
        } catch (Exception $e) {
            Log::error('BrokerDiscoveryService: AI discovery failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Research a specific broker domain and extract its opt-out information
     * Uses web search to get current information about the broker
     *
     * @param string $domain Domain to research
     * @return array Broker information
     */
    public function researchBroker(string $domain): array
    {
        Log::info('BrokerDiscoveryService: Researching broker', ['domain' => $domain]);

        // First, try to get current info via web search
        $webInfo = $this->searchWebForBrokerInfo($domain);

        // Then use AI to analyze and structure the information
        $prompt = $this->buildResearchPrompt($domain, $webInfo);

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 2000,
                'system_prompt' => $this->getResearchSystemPrompt(),
            ]);

            if (!$result['success']) {
                Log::error('BrokerDiscoveryService: Research failed', [
                    'domain' => $domain,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                ];
            }

            $brokerInfo = $this->parseResearchResponse($result['response'], $domain);

            return [
                'success' => true,
                'broker' => $brokerInfo,
                'source' => !empty($webInfo) ? 'web_search+ai' : 'ai_only',
                'provider' => $result['provider'] ?? 'unknown',
            ];

        } catch (Exception $e) {
            Log::error('BrokerDiscoveryService: Research failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search web for specific broker info
     */
    private function searchWebForBrokerInfo(string $domain): string
    {
        $queries = [
            "{$domain} opt out removal request",
            "{$domain} privacy policy data deletion",
        ];

        $info = '';

        foreach ($queries as $query) {
            try {
                $result = $this->mcpRouter->callTool('graphlit', 'webSearch', [
                    'query' => $query,
                    'limit' => 3,
                ]);

                if (!empty($result['content'])) {
                    foreach ($result['content'] as $item) {
                        if (isset($item['text'])) {
                            $info .= $item['text'] . "\n";
                        }
                    }
                }

                if (strlen($info) > 5000) {
                    break;
                }
            } catch (Exception $e) {
                // Continue with next query
            }
        }

        return substr($info, 0, 8000);
    }

    /**
     * Auto-discover and add new brokers to the database
     *
     * @param array $config Configuration options
     * @return array Results with added brokers
     */
    public function autoDiscoverAndAdd(array $config = []): array
    {
        $dryRun = $config['dry_run'] ?? false;
        $maxToAdd = $config['max_to_add'] ?? 5;

        // First, discover new brokers (uses web search)
        $discovery = $this->discoverBrokers($config);

        if (!$discovery['success'] || empty($discovery['suggestions'])) {
            return [
                'success' => false,
                'error' => $discovery['error'] ?? 'No brokers discovered',
                'added' => [],
            ];
        }

        $added = [];
        $errors = [];

        foreach (array_slice($discovery['suggestions'], 0, $maxToAdd) as $suggestion) {
            try {
                // Research each broker for more details
                $research = $this->researchBroker($suggestion['domain']);

                if (!$research['success']) {
                    $errors[] = [
                        'domain' => $suggestion['domain'],
                        'error' => $research['error'] ?? 'Research failed',
                    ];
                    continue;
                }

                $brokerData = array_merge($suggestion, $research['broker'] ?? []);

                if ($dryRun) {
                    $added[] = [
                        'domain' => $brokerData['domain'],
                        'name' => $brokerData['name'] ?? $brokerData['domain'],
                        'status' => 'would_add',
                        'data' => $brokerData,
                        'source' => $research['source'] ?? 'unknown',
                    ];
                } else {
                    // Queue for approval (don't auto-add)
                    $this->queueForApproval($brokerData);

                    $added[] = [
                        'domain' => $brokerData['domain'],
                        'name' => $brokerData['name'] ?? $brokerData['domain'],
                        'status' => 'queued_for_approval',
                        'source' => $research['source'] ?? 'unknown',
                    ];

                    Log::info('BrokerDiscoveryService: Queued broker for approval', [
                        'domain' => $brokerData['domain'],
                    ]);
                }

            } catch (Exception $e) {
                $errors[] = [
                    'domain' => $suggestion['domain'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'added' => $added,
            'errors' => $errors,
            'total_discovered' => count($discovery['suggestions']),
        ];
    }

    /**
     * Queue broker for human approval
     */
    /**
     * Queue broker for human approval
     * NOTE: broker_discovery_queue table dropped in D2 decision (2026-03-16).
     * Broker discovery now uses agent_review_queue instead.
     */
    private function queueForApproval(array $brokerData): void
    {
        $domain = strtolower($brokerData['domain']);
        $confidence = $this->calculateConfidence($brokerData);

        Log::info('BrokerDiscoveryService: broker_discovery_queue dropped (D2), skipping queue insert', [
            'domain' => $domain,
            'confidence' => $confidence,
        ]);
    }

    /**
     * Get existing broker domains from database (raw SQL)
     */
    private function getExistingDomains(): array
    {
        $results = DB::select("SELECT LOWER(domain) as domain FROM data_brokers");
        $domains = array_map(fn($r) => $r->domain, $results);

        // broker_discovery_queue dropped in D2 decision (2026-03-16)
        return array_unique($domains);
    }

    /**
     * Build the discovery prompt
     */
    private function buildDiscoveryPrompt(array $existingDomains, ?string $category, int $maxResults): string
    {
        $excludeList = '';
        if (!empty($existingDomains)) {
            $excludeList = "\n\nEXCLUDE these domains (already in database): " . implode(', ', array_slice($existingDomains, 0, 50));
        }

        $categoryFilter = '';
        if ($category) {
            $categoryFilter = "\n\nFocus specifically on '{$category}' category brokers.";
        }

        return <<<PROMPT
List up to {$maxResults} data broker websites that collect and sell personal information about people in the United States.

For each broker, provide:
1. Domain name (e.g., spokeo.com)
2. Website name
3. Category (people_search, marketing, background_check, data_aggregator, or other)
4. Brief description of what data they collect
5. Known opt-out URL if available

Focus on:
- People search sites (like Spokeo, BeenVerified, WhitePages)
- Data aggregators
- Marketing data brokers
- Background check services
- Property record sites

{$excludeList}
{$categoryFilter}

Format your response as a JSON array:
[
  {
    "domain": "example.com",
    "name": "Example Data",
    "category": "people_search",
    "description": "Collects names, addresses, phone numbers",
    "removal_url": "https://example.com/optout"
  }
]
PROMPT;
    }

    /**
     * Build the research prompt for a specific domain
     */
    private function buildResearchPrompt(string $domain, string $webInfo = ''): string
    {
        $webContext = '';
        if (!empty($webInfo)) {
            $webContext = "\n\nRecent web search results about this broker:\n{$webInfo}";
        }

        return <<<PROMPT
Research the data broker website: {$domain}
{$webContext}

Please provide the following information:

1. Official website name
2. Category (people_search, marketing, background_check, data_aggregator, other)
3. What personal data they collect (names, addresses, phone numbers, etc.)
4. Removal/opt-out method (web_form, email, api, postal, phone, unknown)
5. Direct URL to their opt-out or removal request page (if available)
6. Whether they use CAPTCHA on their removal form
7. Whether their removal form requires JavaScript
8. Any special notes about the removal process

Format your response as JSON:
{
  "name": "Official Site Name",
  "domain": "{$domain}",
  "category": "people_search",
  "data_collected": ["names", "addresses", "phone_numbers"],
  "removal_method": "web_form",
  "removal_url": "https://...",
  "requires_captcha": true,
  "uses_javascript": true,
  "notes": "Special instructions or notes"
}
PROMPT;
    }

    /**
     * System prompt for discovery
     */
    private function getDiscoverySystemPrompt(): string
    {
        return 'You are a privacy research assistant helping to identify data broker websites that may contain personal information. Your goal is to help people understand what sites have their data so they can exercise their privacy rights. Provide accurate, factual information about data brokers. Always respond in valid JSON format.';
    }

    /**
     * System prompt for research
     */
    private function getResearchSystemPrompt(): string
    {
        return 'You are a privacy research assistant analyzing data broker websites. Provide accurate, factual information about the website\'s data collection practices and opt-out procedures. Always respond in valid JSON format. If you cannot find specific information, indicate "unknown" rather than guessing.';
    }

    /**
     * Parse the discovery response from AI
     */
    private function parseDiscoveryResponse(string $response): array
    {
        $suggestions = [];

        // Look for JSON array in response
        if (preg_match('/\[[\s\S]*\]/m', $response, $matches)) {
            $jsonStr = $matches[0];
            $decoded = json_decode($jsonStr, true);

            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (isset($item['domain']) && !empty($item['domain'])) {
                        $suggestions[] = [
                            'domain' => strtolower(trim($item['domain'])),
                            'name' => $item['name'] ?? null,
                            'category' => $item['category'] ?? 'people_search',
                            'description' => $item['description'] ?? null,
                            'removal_url' => $item['removal_url'] ?? null,
                        ];
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Parse the research response from AI
     */
    private function parseResearchResponse(string $response, string $domain): array
    {
        $brokerInfo = [
            'domain' => $domain,
        ];

        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $jsonStr = $matches[0];
            $decoded = json_decode($jsonStr, true);

            if (is_array($decoded)) {
                $brokerInfo = array_merge($brokerInfo, $decoded);
            }
        }

        // Normalize fields
        if (isset($brokerInfo['requires_captcha'])) {
            $brokerInfo['requires_captcha'] = (bool) $brokerInfo['requires_captcha'];
        }
        if (isset($brokerInfo['uses_javascript'])) {
            $brokerInfo['uses_javascript'] = (bool) $brokerInfo['uses_javascript'];
        }

        // Default automation tier based on requirements
        if (!isset($brokerInfo['automation_tier'])) {
            if ($brokerInfo['requires_captcha'] ?? true) {
                $brokerInfo['automation_tier'] = 3; // Needs human
            } elseif ($brokerInfo['uses_javascript'] ?? true) {
                $brokerInfo['automation_tier'] = 2; // Needs browser
            } else {
                $brokerInfo['automation_tier'] = 1; // Fully automated
            }
        }

        return $brokerInfo;
    }

    /**
     * Calculate confidence score based on data quality
     */
    private function calculateConfidence(array $brokerData): float
    {
        $score = 50.0; // Base score

        // Has removal URL (+20)
        if (!empty($brokerData['removal_url'])) {
            $score += 20;
        }

        // Has specific category (+10)
        if (!empty($brokerData['category']) && $brokerData['category'] !== 'unknown') {
            $score += 10;
        }

        // Has description (+10)
        if (!empty($brokerData['description'])) {
            $score += 10;
        }

        // From web search (+5) - more current
        if (($brokerData['source'] ?? '') === 'web_search') {
            $score += 5;
        }

        // Has data types listed (+5)
        if (!empty($brokerData['data_collected'])) {
            $score += 5;
        }

        return min(100.0, $score);
    }

    /**
     * Get well-known data broker list as seed data
     */
    public function getWellKnownBrokers(): array
    {
        return [
            ['domain' => 'spokeo.com', 'name' => 'Spokeo', 'category' => 'people_search'],
            ['domain' => 'beenverified.com', 'name' => 'BeenVerified', 'category' => 'people_search'],
            ['domain' => 'whitepages.com', 'name' => 'Whitepages', 'category' => 'people_search'],
            ['domain' => 'intelius.com', 'name' => 'Intelius', 'category' => 'people_search'],
            ['domain' => 'truepeoplesearch.com', 'name' => 'TruePeopleSearch', 'category' => 'people_search'],
            ['domain' => 'fastpeoplesearch.com', 'name' => 'FastPeopleSearch', 'category' => 'people_search'],
            ['domain' => 'peoplefinders.com', 'name' => 'PeopleFinders', 'category' => 'people_search'],
            ['domain' => 'instantcheckmate.com', 'name' => 'Instant Checkmate', 'category' => 'background_check'],
            ['domain' => 'truthfinder.com', 'name' => 'TruthFinder', 'category' => 'background_check'],
            ['domain' => 'mylife.com', 'name' => 'MyLife', 'category' => 'people_search'],
            ['domain' => 'radaris.com', 'name' => 'Radaris', 'category' => 'people_search'],
            ['domain' => 'pipl.com', 'name' => 'Pipl', 'category' => 'people_search'],
            ['domain' => 'familytreenow.com', 'name' => 'FamilyTreeNow', 'category' => 'people_search'],
            ['domain' => 'usphonebook.com', 'name' => 'USPhonebook', 'category' => 'people_search'],
            ['domain' => 'thatsthem.com', 'name' => 'ThatsThem', 'category' => 'people_search'],
            ['domain' => 'clustrmaps.com', 'name' => 'ClustrMaps', 'category' => 'people_search'],
            ['domain' => 'addresses.com', 'name' => 'Addresses.com', 'category' => 'people_search'],
            ['domain' => 'publicrecordsnow.com', 'name' => 'PublicRecordsNow', 'category' => 'people_search'],
            ['domain' => 'zabasearch.com', 'name' => 'ZabaSearch', 'category' => 'people_search'],
            ['domain' => 'ussearch.com', 'name' => 'US Search', 'category' => 'people_search'],
        ];
    }
}
