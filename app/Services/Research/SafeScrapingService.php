<?php

namespace App\Services\Research;

use App\Engine\MCPRouter;
use App\Services\AgentGuardrailService;
use App\Services\AIService;
use App\Services\SystemConfigService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * SafeScrapingService - Multi-layer safe web scraping
 *
 * Implements three safety layers:
 * 1. Whitelist Fast-Track - Trusted domains (.gov, .edu, archives) scraped directly
 * 2. AI Safety Check - Unknown domains evaluated before scraping
 * 3. Sandbox Execution - Risky domains scraped in isolated environment
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 * Tuning constants in system_configs table (SC-3), fallback to hardcoded defaults.
 */
class SafeScrapingService
{
    private AIService $aiService;

    private ?AgentGuardrailService $guardrail = null;

    private DynamicSourceDiscoveryService $discoveryService;

    private ?MCPRouter $mcpRouter = null;

    private ?SystemConfigService $config = null;

    private string $connection = 'pgsql_rag';

    // Sandbox config (not promoted — static, rarely changed)
    private const SANDBOX_MEMORY_LIMIT = '512M';

    private const SANDBOX_JS_DISABLED_DEFAULT = true;

    // JS-heavy domains that require Puppeteer rendering
    private const JS_HEAVY_DOMAINS = [
        'webmd.com', 'mayoclinic.org', 'healthline.com', 'medicalnewstoday.com',
        'cleveland clinic.org', 'drugs.com', 'verywellhealth.com',
    ];

    public function __construct(AIService $aiService, DynamicSourceDiscoveryService $discoveryService)
    {
        $this->aiService = $aiService;
        $this->discoveryService = $discoveryService;

        try {
            $this->config = app(SystemConfigService::class);
        } catch (\Throwable $e) {
            // Fallback to hardcoded defaults
        }

        // Initialize MCP Router if available
        try {
            $this->mcpRouter = app(MCPRouter::class);
        } catch (Exception $e) {
            Log::debug('MCPRouter not available for SafeScrapingService');
        }

        try {
            $this->guardrail = app(AgentGuardrailService::class);
        } catch (\Throwable $e) {
            Log::debug('AgentGuardrailService not available for SafeScrapingService');
        }
    }

    private function getDefaultTimeout(): int
    {
        return $this->config?->getInt('scraping.default_timeout', 30) ?? 30;
    }

    private function getSandboxTimeout(): int
    {
        return $this->config?->getInt('scraping.sandbox_timeout', 45) ?? 45;
    }

    private function getMaxContentSize(): int
    {
        return $this->config?->getInt('scraping.max_content_size', 5242880) ?? 5242880;
    }

    private function getMaxResponseTime(): int
    {
        return $this->config?->getInt('scraping.max_response_time_ms', 30000) ?? 30000;
    }

    private function getGlobalRateLimit(): int
    {
        return $this->config?->getInt('scraping.global_rate_limit_per_min', 100) ?? 100;
    }

    private function getPerDomainRateLimit(): int
    {
        return $this->config?->getInt('scraping.per_domain_rate_limit', 30) ?? 30;
    }

    /**
     * Scrape a URL with appropriate safety measures
     *
     * @param  string  $url  The URL to scrape
     * @param  array  $config  Scraping configuration
     * @return array Scraping result with content and metadata
     */
    public function scrape(string $url, array $config = []): array
    {
        $startTime = microtime(true);
        $domain = $this->extractDomain($url);

        if (! $domain) {
            return ['success' => false, 'error' => 'Invalid URL'];
        }

        if ($this->isManualOnlyDomain($domain)) {
            return $this->manualOnlyDomainResponse($domain, $url);
        }

        // Check global rate limit
        if (! $this->checkGlobalRateLimit()) {
            return ['success' => false, 'error' => 'Global rate limit exceeded'];
        }

        // Check per-domain rate limit
        if (! $this->checkDomainRateLimit($domain)) {
            return ['success' => false, 'error' => "Rate limit exceeded for domain: {$domain}"];
        }

        // Get or create source record
        $source = $this->discoveryService->getSourceByDomain($domain);

        if (! $source) {
            // New domain - evaluate it
            $evaluation = $this->discoveryService->evaluateSourceSafety($domain, $url);

            if ($evaluation['is_blacklisted']) {
                return [
                    'success' => false,
                    'error' => 'Domain is blacklisted',
                    'domain' => $domain,
                ];
            }

            // Register the new source
            $sourceId = $this->discoveryService->registerSource([
                'domain' => $domain,
                'full_url' => $url,
                'safety_score' => $evaluation['safety_score'],
                'trust_score' => $evaluation['trust_score'],
                'domain_category' => $evaluation['domain_category'],
                'is_whitelisted' => $evaluation['is_whitelisted'],
                'requires_sandbox' => $evaluation['requires_sandbox'],
                'safety_evaluation' => $evaluation,
                'discovery_context' => 'Discovered during scrape request',
            ]);

            $source = [
                'id' => $sourceId,
                'domain' => $domain,
                'is_whitelisted' => $evaluation['is_whitelisted'],
                'requires_sandbox' => $evaluation['requires_sandbox'],
                'safety_score' => $evaluation['safety_score'],
            ];
        }

        // Determine scraping method
        $method = $this->determineScrapingMethod($source, $config);

        // Execute scrape
        try {
            switch ($method) {
                case 'api':
                    $result = $this->scrapeWithApi($url, $config);
                    break;

                case 'direct':
                    $result = $this->scrapeDirect($url, $config);
                    break;

                case 'sandbox':
                    $result = $this->scrapeWithSandbox($url, $config);
                    break;

                case 'puppeteer':
                    $result = $this->scrapeWithPuppeteer($url, $config);
                    break;

                default:
                    $result = $this->scrapeDirect($url, $config);
            }

            $result = $this->applyScrapePolicy($url, $result, $config);

            // Update source health
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $this->discoveryService->updateSourceHealth(
                $source['id'],
                $result['success'],
                $responseTime,
                $result['error'] ?? null
            );

            // Add metadata to result
            $result['domain'] = $domain;
            $result['source_id'] = $source['id'];
            $result['method'] = $method;
            $result['response_time_ms'] = $responseTime;

            return $result;

        } catch (Exception $e) {
            Log::error('Scraping failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            $this->discoveryService->updateSourceHealth(
                $source['id'],
                false,
                0,
                $e->getMessage()
            );

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'domain' => $domain,
            ];
        }
    }

    /**
     * Determine the appropriate scraping method
     */
    public function determineScrapingMethod(array $source, array $config = []): string
    {
        // Force method if specified
        if (! empty($config['method'])) {
            return $config['method'];
        }

        // API sources use the API method
        if (($source['source_type'] ?? '') === 'api' || ! empty($config['is_api'])) {
            return 'api';
        }

        // JavaScript-heavy sites need Puppeteer
        $domain = $source['domain'] ?? '';
        foreach (self::JS_HEAVY_DOMAINS as $jsDomain) {
            if (str_contains($domain, $jsDomain)) {
                return 'puppeteer';
            }
        }

        if (! empty($config['requires_js']) || ! empty($source['requires_js'])) {
            return 'puppeteer';
        }

        // Whitelisted sources - direct scraping
        if (! empty($source['is_whitelisted']) && ($source['safety_score'] ?? 0) >= 0.85) {
            return 'direct';
        }

        // High safety score - direct is fine
        if (($source['safety_score'] ?? 0) >= 0.80 && ! ($source['requires_sandbox'] ?? true)) {
            return 'direct';
        }

        // Unknown or risky domains - sandbox
        if (($source['requires_sandbox'] ?? true) || ($source['safety_score'] ?? 0) < 0.5) {
            return 'sandbox';
        }

        return 'direct';
    }

    /**
     * API-based data retrieval (for structured sources like PubMed)
     */
    private function scrapeWithApi(string $url, array $config = []): array
    {
        $timeout = $config['timeout'] ?? $this->getDefaultTimeout();
        $domain = $this->extractDomain($url);

        try {
            // Check if this is a known API that needs special handling
            if (str_contains($url, 'eutils.ncbi.nlm.nih.gov') || str_contains($domain, 'pubmed')) {
                return $this->fetchPubMedData($url, $config);
            }

            // Generic JSON API fetch
            $response = Http::connectTimeout(5)->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'ResearchBot/1.0 (Academic Research)',
                ])
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "API returned HTTP {$response->status()}",
                    'status_code' => $response->status(),
                    'api' => true,
                ];
            }

            $data = $response->json();

            if (! $data) {
                // Maybe it's not JSON, fall back to direct scraping
                return $this->scrapeDirect($url, $config);
            }

            // Convert JSON data to readable text content
            $content = $this->jsonToReadableContent($data, $config['content_path'] ?? null);

            return [
                'success' => true,
                'content' => $content,
                'title' => $data['title'] ?? $data['name'] ?? 'API Response',
                'raw_data' => $data,
                'api' => true,
            ];

        } catch (Exception $e) {
            Log::warning('API scraping failed, falling back to direct', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Fall back to direct scraping
            return $this->scrapeDirect($url, $config);
        }
    }

    /**
     * Fetch data from PubMed using E-utilities API
     * Two-step process: esearch (get IDs) -> efetch (get abstracts)
     */
    private function fetchPubMedData(string $url, array $config = []): array
    {
        $timeout = $config['timeout'] ?? $this->getDefaultTimeout();

        try {
            // Step 1: Search for article IDs
            $searchResponse = Http::connectTimeout(5)->timeout($timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($url);

            if (! $searchResponse->successful()) {
                return [
                    'success' => false,
                    'error' => "PubMed search failed: HTTP {$searchResponse->status()}",
                    'api' => true,
                ];
            }

            $searchData = $searchResponse->json();
            $ids = $searchData['esearchresult']['idlist'] ?? [];

            if (empty($ids)) {
                return [
                    'success' => true,
                    'content' => 'No PubMed articles found for this query.',
                    'title' => 'PubMed Search Results',
                    'api' => true,
                    'article_count' => 0,
                ];
            }

            // Step 2: Fetch abstracts for the found IDs
            $idList = implode(',', array_slice($ids, 0, 10)); // Limit to 10 articles
            $efetchUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&id={$idList}&retmode=xml&rettype=abstract";

            $fetchResponse = Http::connectTimeout(5)->timeout($timeout)->get($efetchUrl);

            if (! $fetchResponse->successful()) {
                // Return just the IDs if fetch fails
                return [
                    'success' => true,
                    'content' => 'Found '.count($ids).' PubMed articles. IDs: '.$idList,
                    'title' => 'PubMed Search Results',
                    'api' => true,
                    'article_count' => count($ids),
                ];
            }

            // Parse XML response and extract article data
            $content = $this->parsePubMedXml($fetchResponse->body());

            return [
                'success' => true,
                'content' => $content,
                'title' => 'PubMed Research Articles',
                'api' => true,
                'article_count' => count($ids),
                'article_ids' => $ids,
            ];

        } catch (Exception $e) {
            Log::warning('PubMed API fetch failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'PubMed API error: '.$e->getMessage(),
                'api' => true,
            ];
        }
    }

    /**
     * Parse PubMed XML response to extract article content
     */
    private function parsePubMedXml(string $xml): string
    {
        $content = [];

        try {
            // Suppress XML errors and parse
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);

            if (! $doc) {
                return 'Unable to parse PubMed response.';
            }

            foreach ($doc->PubmedArticle as $article) {
                $medlineCitation = $article->MedlineCitation;
                $articleData = $medlineCitation->Article;

                $pmid = (string) $medlineCitation->PMID;
                $title = (string) $articleData->ArticleTitle;
                $abstract = '';

                // Extract abstract text
                if (isset($articleData->Abstract->AbstractText)) {
                    $abstractParts = [];
                    foreach ($articleData->Abstract->AbstractText as $text) {
                        $label = (string) $text['Label'];
                        $textContent = (string) $text;
                        if ($label) {
                            $abstractParts[] = "{$label}: {$textContent}";
                        } else {
                            $abstractParts[] = $textContent;
                        }
                    }
                    $abstract = implode(' ', $abstractParts);
                }

                // Extract authors
                $authors = [];
                if (isset($articleData->AuthorList->Author)) {
                    foreach ($articleData->AuthorList->Author as $author) {
                        $lastName = (string) $author->LastName;
                        $foreName = (string) $author->ForeName;
                        if ($lastName) {
                            $authors[] = "{$foreName} {$lastName}";
                        }
                    }
                }
                $authorStr = ! empty($authors) ? implode(', ', array_slice($authors, 0, 3)) : 'Unknown';
                if (count($authors) > 3) {
                    $authorStr .= ' et al.';
                }

                // Extract journal info
                $journal = (string) $articleData->Journal->Title;
                $year = (string) $articleData->Journal->JournalIssue->PubDate->Year;

                // Build article entry
                $entry = "## {$title}\n";
                $entry .= "**PMID:** {$pmid} | **Authors:** {$authorStr}\n";
                $entry .= "**Journal:** {$journal} ({$year})\n\n";
                if ($abstract) {
                    $entry .= "{$abstract}\n";
                }
                $entry .= "\n---\n";

                $content[] = $entry;
            }

        } catch (Exception $e) {
            Log::warning('PubMed XML parsing error', ['error' => $e->getMessage()]);

            return 'Error parsing PubMed response: '.$e->getMessage();
        }

        if (empty($content)) {
            return 'No article abstracts found in PubMed response.';
        }

        return "# PubMed Research Articles\n\n".implode("\n", $content);
    }

    /**
     * Convert JSON API data to readable text content
     */
    private function jsonToReadableContent($data, ?string $contentPath = null): string
    {
        // If a specific content path is provided, extract from there
        if ($contentPath) {
            $parts = explode('.', $contentPath);
            $current = $data;
            foreach ($parts as $part) {
                if (is_array($current) && isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    break;
                }
            }
            if (is_string($current)) {
                return $current;
            }
            if (is_array($current)) {
                $data = $current;
            }
        }

        // Convert array/object to readable text
        return $this->arrayToText($data, 0);
    }

    /**
     * Recursively convert array to readable text
     */
    private function arrayToText($data, int $depth = 0, int $maxDepth = 3): string
    {
        if ($depth > $maxDepth) {
            return '[...]';
        }

        if (is_string($data)) {
            return $data;
        }

        if (is_numeric($data) || is_bool($data)) {
            return (string) $data;
        }

        if (! is_array($data)) {
            return '';
        }

        $lines = [];
        $indent = str_repeat('  ', $depth);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $lines[] = "{$indent}{$key}:";
                $lines[] = $this->arrayToText($value, $depth + 1, $maxDepth);
            } else {
                $strValue = is_string($value) ? $value : json_encode($value);
                // Skip empty values and very long values
                if ($strValue && strlen($strValue) < 1000) {
                    $lines[] = "{$indent}{$key}: {$strValue}";
                }
            }
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * Direct scraping (for trusted sources)
     */
    private function scrapeDirect(string $url, array $config = []): array
    {
        $timeout = $config['timeout'] ?? 20;

        try {
            $response = Http::connectTimeout(5)->timeout($timeout)
                ->withHeaders([
                    'User-Agent' => $config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => true,
                        'track_redirects' => true,
                    ],
                ])
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}",
                    'status_code' => $response->status(),
                ];
            }

            $content = $response->body();

            // Check content size
            if (strlen($content) > $this->getMaxContentSize()) {
                $content = substr($content, 0, $this->getMaxContentSize());
            }

            // Extract text content
            $extractedContent = $this->extractContent($content, $config['selectors'] ?? []);

            return [
                'success' => true,
                'content' => $extractedContent['text'],
                'html' => $content,
                'title' => $extractedContent['title'],
                'links' => $extractedContent['links'] ?? [],
                'status_code' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'content_length' => strlen($content),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sandbox scraping (for untrusted sources)
     */
    public function scrapeWithSandbox(string $url, array $config = []): array
    {
        $timeout = $config['timeout'] ?? $this->getSandboxTimeout();

        // Build sandbox command
        // Uses PHP's built-in timeout and memory limits
        $sandboxScript = $this->buildSandboxScript($url, $config);

        try {
            // Execute in isolated process with resource limits
            $result = Process::timeout($timeout)
                ->env([
                    'URL' => $url,
                    'TIMEOUT' => (string) $timeout,
                    'MAX_SIZE' => (string) $this->getMaxContentSize(),
                ])
                ->run([
                    'php',
                    '-d',
                    'memory_limit='.self::SANDBOX_MEMORY_LIMIT,
                    '-r',
                    $sandboxScript,
                ]);

            if ($result->failed()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput() ?: 'Sandbox execution failed',
                    'sandboxed' => true,
                ];
            }

            $output = $result->output();
            $parsed = json_decode($output, true);

            if (! $parsed) {
                return [
                    'success' => false,
                    'error' => 'Failed to parse sandbox output',
                    'raw_output' => substr($output, 0, 1000),
                    'sandboxed' => true,
                ];
            }

            $parsed['sandboxed'] = true;

            return $parsed;

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Sandbox execution error: '.$e->getMessage(),
                'sandboxed' => true,
            ];
        }
    }

    /**
     * Build sandboxed PHP script for scraping
     */
    private function buildSandboxScript(string $url, array $config): string
    {
        $timeout = $config['timeout'] ?? $this->getSandboxTimeout();

        return <<<'PHP'
$url = getenv('URL');
$timeout = (int)getenv('TIMEOUT') ?: 30;
$maxSize = (int)getenv('MAX_SIZE') ?: 5242880;

// Disable risky functions
if (function_exists('pcntl_exec')) { /* can't disable at runtime */ }

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept: text/html,application/xhtml+xml',
    ],
    CURLOPT_MAXFILESIZE => $maxSize,
]);

$content = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

if (!$content) {
    echo json_encode([
        'success' => false,
        'error' => 'Empty response from target',
        'status_code' => $info['http_code'] ?? 0,
    ]);
    exit;
}

if (($info['http_code'] ?? 0) < 200 || ($info['http_code'] ?? 0) >= 400) {
    echo json_encode([
        'success' => false,
        'error' => 'Unexpected HTTP status',
        'status_code' => $info['http_code'] ?? 0,
        'content_type' => $info['content_type'] ?? null,
    ]);
    exit;
}

// Extract text content
$text = strip_tags($content);
$text = preg_replace('/\s+/', ' ', $text);
$text = substr(trim($text), 0, 50000);

// Extract title
preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $titleMatch);
$title = $titleMatch[1] ?? '';

echo json_encode([
    'success' => true,
    'content' => $text,
    'title' => $title,
    'status_code' => $info['http_code'],
    'content_type' => $info['content_type'],
    'content_length' => strlen($content),
]);
PHP;
    }

    /**
     * Puppeteer-based scraping for JS-heavy sites
     * Uses MCPRouter to call Puppeteer MCP tools
     */
    private function scrapeWithPuppeteer(string $url, array $config = []): array
    {
        // Check if MCPRouter is available
        if (! $this->mcpRouter) {
            Log::warning('Puppeteer requested but MCPRouter not available, falling back to direct', [
                'url' => $url,
            ]);

            return $this->scrapeDirect($url, $config);
        }

        // Check if Puppeteer MCP server is enabled and reachable
        // Skip if we've had recent failures (cached for 5 minutes)
        $cacheKey = 'puppeteer_mcp_available';
        if (Cache::has($cacheKey) && ! Cache::get($cacheKey)) {
            Log::debug('Puppeteer MCP marked as unavailable, falling back to direct', ['url' => $url]);

            return $this->scrapeDirect($url, $config);
        }

        try {
            // Navigate to URL using Puppeteer MCP
            Log::debug('Puppeteer navigating', ['url' => $url]);

            // Use extended timeout (60s) for page navigation - complex pages can take time
            $navResult = $this->mcpRouter->callTool('puppeteer', 'puppeteer_navigate', [
                'url' => $url,
                'allowDangerous' => true,
                'launchOptions' => [
                    'headless' => true,
                    'args' => ['--no-sandbox', '--disable-setuid-sandbox'],
                ],
            ], 60);

            if (! $navResult || (isset($navResult['error']) && $navResult['error'])) {
                Log::warning('Puppeteer navigation failed', [
                    'url' => $url,
                    'result' => $navResult,
                ]);

                return $this->scrapeDirect($url, $config);
            }

            // Wait for content to load (JS rendering)
            usleep(3000000); // 3 seconds

            // Extract page content using JavaScript
            $extractScript = <<<'JS'
(function() {
    // Remove script and style elements
    const scripts = document.querySelectorAll('script, style, noscript');
    scripts.forEach(el => el.remove());

    // Get main content - try common content containers first
    const selectors = [
        'main', 'article', '[role="main"]',
        '.content', '#content', '.article-body',
        '.post-content', '.entry-content'
    ];

    let content = '';
    for (const selector of selectors) {
        const el = document.querySelector(selector);
        if (el && el.innerText.length > 500) {
            content = el.innerText;
            break;
        }
    }

    // Fallback to body if no main content found
    if (!content || content.length < 500) {
        content = document.body.innerText;
    }

    return JSON.stringify({
        title: document.title,
        content: content.substring(0, 50000),
        url: window.location.href
    });
})()
JS;

            // Use extended timeout (45s) for script evaluation - extraction can be slow
            $evalResult = $this->mcpRouter->callTool('puppeteer', 'puppeteer_evaluate', [
                'script' => $extractScript,
            ], 45);

            // Parse the result
            $pageData = null;
            if (isset($evalResult['content']) && is_array($evalResult['content'])) {
                foreach ($evalResult['content'] as $item) {
                    if (isset($item['text'])) {
                        $pageData = json_decode($item['text'], true);
                        break;
                    }
                }
            }

            if (! $pageData || empty($pageData['content'])) {
                Log::warning('Puppeteer returned empty content', ['url' => $url]);

                return $this->scrapeDirect($url, $config);
            }

            Log::debug('Puppeteer scrape successful', [
                'url' => $url,
                'content_length' => strlen($pageData['content']),
            ]);

            return [
                'success' => true,
                'content' => $pageData['content'],
                'title' => $pageData['title'] ?? '',
                'puppeteer' => true,
            ];

        } catch (Exception $e) {
            // Fall back to direct scraping if Puppeteer fails
            Log::warning('Puppeteer scraping failed, falling back to direct', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Mark Puppeteer as unavailable for 5 minutes if it's a timeout/connection issue
            if (str_contains($e->getMessage(), 'Timeout') || str_contains($e->getMessage(), 'connection')) {
                Cache::put('puppeteer_mcp_available', false, 300);
            }

            return $this->scrapeDirect($url, $config);
        }
    }

    /**
     * Extract content from HTML
     */
    public function extractContent(string $html, array $selectors = []): array
    {
        $result = [
            'text' => '',
            'title' => '',
            'links' => [],
            'meta' => [],
        ];

        // Extract title
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $titleMatch)) {
            $result['title'] = html_entity_decode(trim($titleMatch[1]));
        }

        // Extract meta description
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $metaMatch)) {
            $result['meta']['description'] = html_entity_decode(trim($metaMatch[1]));
        }

        // Remove script and style tags
        $cleanHtml = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $cleanHtml = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $cleanHtml);
        $cleanHtml = preg_replace('/<!--[\s\S]*?-->/', '', $cleanHtml);

        // Use specific selectors if provided
        if (! empty($selectors)) {
            $extractedParts = [];
            foreach ($selectors as $selector) {
                // Simple CSS selector support (just element names and classes)
                if (preg_match('/^(\w+)(?:\.(\w+))?$/', $selector, $selectorParts)) {
                    $tag = $selectorParts[1];
                    $class = $selectorParts[2] ?? null;

                    if ($class) {
                        preg_match_all("/<{$tag}[^>]*class=['\"][^'\"]*{$class}[^'\"]*['\"][^>]*>([\s\S]*?)<\/{$tag}>/i", $cleanHtml, $matches);
                    } else {
                        preg_match_all("/<{$tag}[^>]*>([\s\S]*?)<\/{$tag}>/i", $cleanHtml, $matches);
                    }

                    if (! empty($matches[1])) {
                        $extractedParts = array_merge($extractedParts, $matches[1]);
                    }
                }
            }

            if (! empty($extractedParts)) {
                $cleanHtml = implode("\n", $extractedParts);
            }
        }

        // Extract links
        preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>([^<]*)<\/a>/i', $html, $linkMatches, PREG_SET_ORDER);
        foreach ($linkMatches as $match) {
            if (! empty($match[1]) && ! str_starts_with($match[1], '#') && ! str_starts_with($match[1], 'javascript:')) {
                $result['links'][] = [
                    'url' => $match[1],
                    'text' => trim(strip_tags($match[2])),
                ];
            }
        }
        $result['links'] = array_slice($result['links'], 0, 50); // Limit links

        // Convert to plain text
        $text = strip_tags($cleanHtml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Limit content length
        $result['text'] = substr($text, 0, 100000);

        return $result;
    }

    /**
     * Check global rate limit
     */
    private function checkGlobalRateLimit(): bool
    {
        $cacheKey = 'scrape_global_limit:'.date('Y-m-d-H-i');
        $count = Cache::get($cacheKey, 0);

        if ($count >= $this->getGlobalRateLimit()) {
            return false;
        }

        Cache::put($cacheKey, $count + 1, 120);

        return true;
    }

    /**
     * Check per-domain rate limit
     */
    private function checkDomainRateLimit(string $domain): bool
    {
        $cacheKey = "scrape_domain_limit:{$domain}:".date('Y-m-d-H-i');
        $count = Cache::get($cacheKey, 0);

        if ($count >= $this->getPerDomainRateLimit()) {
            return false;
        }

        Cache::put($cacheKey, $count + 1, 120);

        return true;
    }

    private function isManualOnlyDomain(string $domain): bool
    {
        foreach ((array) config('scraping.manual_only_domains', []) as $manualOnlyDomain) {
            $manualOnlyDomain = preg_replace('/^www\./', '', strtolower(trim((string) $manualOnlyDomain)));

            if ($manualOnlyDomain === '') {
                continue;
            }

            if ($domain === $manualOnlyDomain || str_ends_with($domain, ".{$manualOnlyDomain}")) {
                return true;
            }
        }

        return false;
    }

    private function manualOnlyDomainResponse(string $domain, string $url): array
    {
        return [
            'success' => false,
            'error' => 'Domain is manual-only; automated scraping is disabled',
            'domain' => $domain,
            'url' => $url,
            'manual_only' => true,
            'manual_required' => true,
            'policy' => 'manual_only_domain',
            'external_content_policy' => [
                'treat_as_data' => true,
                'robots_bypassed' => false,
            ],
        ];
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): ?string
    {
        if (! preg_match('/^https?:\/\//', $url)) {
            $url = "https://{$url}";
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if (! $host) {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Check robots.txt for URL
     */
    public function checkRobotsTxt(string $url): array
    {
        if ($this->shouldBypassRobotsTxt()) {
            return ['allowed' => true, 'reason' => 'Robots.txt bypass enabled by runtime policy', 'bypassed' => true];
        }

        $parsed = parse_url($url);
        $robotsUrl = "{$parsed['scheme']}://{$parsed['host']}/robots.txt";

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($robotsUrl);

            if (! $response->successful()) {
                return ['allowed' => true, 'reason' => 'No robots.txt found'];
            }

            $robotsTxt = $response->body();
            $path = $parsed['path'] ?? '/';

            // Simple robots.txt parsing
            $userAgentMatches = false;
            $disallowed = false;

            $lines = explode("\n", $robotsTxt);
            foreach ($lines as $line) {
                $line = trim(strtolower($line));

                if (str_starts_with($line, 'user-agent:')) {
                    $agent = trim(substr($line, 11));
                    $userAgentMatches = ($agent === '*' || str_contains($agent, 'bot'));
                }

                if ($userAgentMatches && str_starts_with($line, 'disallow:')) {
                    $disallowPath = trim(substr($line, 9));
                    if ($disallowPath && str_starts_with($path, $disallowPath)) {
                        $disallowed = true;
                        break;
                    }
                }
            }

            return [
                'allowed' => ! $disallowed,
                'reason' => $disallowed ? 'Disallowed by robots.txt' : 'Allowed',
            ];

        } catch (Exception $e) {
            return ['allowed' => true, 'reason' => 'Could not check robots.txt: '.$e->getMessage()];
        }
    }

    private function shouldBypassRobotsTxt(): bool
    {
        return (bool) config('scraping.bypass_robots_txt', true);
    }

    private function shouldSanitizeExternalContent(): bool
    {
        return (bool) config('scraping.sanitize_untrusted_content', true);
    }

    private function applyScrapePolicy(string $url, array $result, array $config = []): array
    {
        $result['robots_txt'] = $this->checkRobotsTxt($url);
        $result['external_content_policy'] = [
            'treat_as_data' => true,
            'robots_bypassed' => (bool) ($result['robots_txt']['bypassed'] ?? false),
        ];

        if (! ($result['success'] ?? false) || ! $this->shouldSanitizeExternalContent()) {
            return $result;
        }

        $content = (string) ($result['content'] ?? '');
        if ($content === '' || ! $this->guardrail) {
            return $result;
        }

        $contamination = $this->guardrail->detectContentContamination($content);
        $result['guardrail'] = $contamination;

        if ($contamination['clean']) {
            return $result;
        }

        $result = $this->sanitizeScrapedPayload($result);
        $result['content_was_sanitized'] = true;

        return $result;
    }

    private function sanitizeScrapedPayload(array $result): array
    {
        foreach (['content', 'title', 'html', 'description', 'snippet', 'excerpt'] as $field) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $result[$field] = $this->neutralizeExternalInstructions($result[$field]);
            }
        }

        foreach (['raw_data', 'metadata'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = $this->sanitizeExternalValue($result[$field]);
            }
        }

        return $result;
    }

    private function sanitizeExternalValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->neutralizeExternalInstructions($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeExternalValue($item);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->sanitizeExternalValue($item);
            }
        }

        return $value;
    }

    private function neutralizeExternalInstructions(string $text): string
    {
        $patterns = [
            '/\b(ignore|disregard|forget)\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?|prompts?)/i',
            '/\b(you\s+are\s+now|act\s+as|pretend\s+to\s+be|your\s+new\s+(role|instructions?))\b/i',
            '/\bsystem\s*prompt\s*[:=]/i',
            '/\b(call|execute|run|invoke)\s+(tool|function|command)\s*[:=\(]/i',
            '/\[\s*INST\s*\]|\<\|\s*im_start\s*\|/i',
            '/\b(output|return|respond\s+with)\s+(only|exactly|just)\s+["\']/i',
        ];

        $sanitized = $text;
        foreach ($patterns as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED_UNTRUSTED_INSTRUCTION]', $sanitized) ?? $sanitized;
        }

        return $sanitized;
    }

    /**
     * Get scraping statistics
     */
    public function getScrapingStats(): array
    {
        $stats = DB::connection($this->connection)->select('
            SELECT
                COUNT(*) as total_sources,
                SUM(success_count) as total_successes,
                SUM(failure_count) as total_failures,
                AVG(avg_response_ms)::integer as avg_response_ms,
                COUNT(*) FILTER (WHERE consecutive_failures >= 3) as failing_sources,
                COUNT(*) FILTER (WHERE requires_sandbox = true) as sandbox_required,
                COUNT(*) FILTER (WHERE is_whitelisted = true) as whitelisted
            FROM discovered_sources
            WHERE is_active = true
        ');

        return [
            'summary' => (array) ($stats[0] ?? []),
            'rate_limits' => [
                'global_per_minute' => $this->getGlobalRateLimit(),
                'per_domain_per_minute' => $this->getPerDomainRateLimit(),
            ],
            'timeouts' => [
                'default' => $this->getDefaultTimeout(),
                'sandbox' => $this->getSandboxTimeout(),
                'whitelist' => 20,
            ],
        ];
    }
}
