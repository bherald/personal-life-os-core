<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Service for downloading missing genealogy media from external sources
 *
 * Supports:
 * - FamilySearch manual review queue (via ARK URLs)
 * - FindAGrave (via memorial IDs)
 * - Newspapers.com (direct image URLs)
 * - LOC Chronicling America (free API - historical newspapers)
 * - Generic URLs (PDFs, images, etc.)
 *
 * E20: Family Tree App - Media Recovery
 */
class GenealogyMediaDownloadService
{
    protected string $storageBasePath;

    protected array $stats = [];

    public function __construct()
    {
        $this->storageBasePath = storage_path('app/public/genealogy/downloaded');
        if (! is_dir($this->storageBasePath)) {
            mkdir($this->storageBasePath, 0755, true);
        }
    }

    /**
     * Analyze GEDCOM citations for downloadable media
     */
    public function analyzeDownloadableSources(int $treeId): array
    {
        // Get the tree's source file
        $tree = DB::selectOne('SELECT * FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree || ! $tree->source_file || ! file_exists($tree->source_file)) {
            return ['error' => 'Tree source file not found'];
        }

        // Parse GEDCOM to get citations with URLs
        $parser = new GedcomParserService($tree->source_file);
        $data = $parser->parse();

        $citations = $data['source_citations'] ?? [];
        $citationsWithUrls = array_filter($citations, fn ($c) => ! empty($c['urls']));

        // Categorize by source type
        $byType = [
            'familysearch' => [],
            'findagrave' => [],
            'newspapers' => [],
            'ancestry' => [],
            'other_urls' => [],
        ];

        foreach ($citationsWithUrls as $citation) {
            foreach ($citation['urls'] as $urlType => $url) {
                if ($urlType === 'familysearch' || strpos($url, 'familysearch.org') !== false) {
                    $byType['familysearch'][] = $citation;
                } elseif ($urlType === 'findagrave_id' || strpos($url, 'findagrave') !== false) {
                    $byType['findagrave'][] = $citation;
                } elseif (strpos($url, 'newspapers.com') !== false) {
                    $byType['newspapers'][] = $citation;
                } elseif ($urlType === 'ancestry_image' || strpos($url, 'ancestry.com') !== false) {
                    $byType['ancestry'][] = $citation;
                } elseif ($urlType === 'page_url' || $urlType === 'direct_link') {
                    $byType['other_urls'][] = $citation;
                }
            }
        }

        // Map GEDCOM IDs to DB IDs
        $sourceIdMap = [];
        $sources = DB::select('SELECT id, gedcom_id, title FROM genealogy_sources WHERE tree_id = ?', [$treeId]);
        foreach ($sources as $s) {
            $sourceIdMap[$s->gedcom_id] = ['id' => $s->id, 'title' => $s->title];
        }

        return [
            'tree_id' => $treeId,
            'tree_name' => $tree->name,
            'total_citations' => count($citations),
            'citations_with_urls' => count($citationsWithUrls),
            'by_source_type' => [
                'familysearch' => count(array_unique(array_column($byType['familysearch'], 'source_gedcom_id'))),
                'findagrave' => count(array_unique(array_column($byType['findagrave'], 'source_gedcom_id'))),
                'newspapers' => count(array_unique(array_column($byType['newspapers'], 'source_gedcom_id'))),
                'ancestry' => count(array_unique(array_column($byType['ancestry'], 'source_gedcom_id'))),
                'other_urls' => count(array_unique(array_column($byType['other_urls'], 'source_gedcom_id'))),
            ],
            'downloadable' => [
                'familysearch_arks' => $this->getUniqueUrls($byType['familysearch'], 'familysearch'),
                'findagrave_ids' => $this->getUniqueUrls($byType['findagrave'], 'findagrave_id'),
                'newspapers_urls' => $this->getUniqueUrls($byType['newspapers'], 'page_url'),
                'other_urls' => $this->getUniqueUrls($byType['other_urls'], ['page_url', 'direct_link']),
            ],
            'notes' => [
                'ancestry' => 'Ancestry.com requires manual browser review; no supported PLOS API integration',
                'familysearch' => 'FamilySearch images require manual browser review; no supported PLOS API integration',
                'findagrave' => 'FindAGrave memorial images are public when available; use respectful rate limits and manual review when needed',
                'newspapers' => 'Newspapers.com may require subscription for full images and is private opt-in only',
            ],
        ];
    }

    /**
     * Get unique URLs from citations
     */
    protected function getUniqueUrls(array $citations, $urlTypes): array
    {
        $urls = [];
        $urlTypes = (array) $urlTypes;

        foreach ($citations as $citation) {
            foreach ($urlTypes as $type) {
                if (isset($citation['urls'][$type])) {
                    $url = $citation['urls'][$type];
                    if (! isset($urls[$url])) {
                        $urls[$url] = [
                            'url' => $url,
                            'source_gedcom_id' => $citation['source_gedcom_id'],
                            'person_gedcom_id' => $citation['person_gedcom_id'],
                            'page' => $citation['page'] ?? null,
                        ];
                    }
                }
            }
        }

        return array_values($urls);
    }

    /**
     * Download media from FindAGrave memorial
     *
     * @param  string  $memorialId  FindAGrave memorial ID
     * @param  int  $treeId  Tree ID for storage
     * @return array Result with success status and file info
     */
    public function downloadFindAGraveMedia(string $memorialId, int $treeId): array
    {
        $result = [
            'success' => false,
            'memorial_id' => $memorialId,
            'files' => [],
            'errors' => [],
        ];

        try {
            // Fetch memorial page with realistic browser headers
            $url = "https://www.findagrave.com/memorial/{$memorialId}";
            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Cache-Control' => 'no-cache',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->get($url);

            if (! $response->successful()) {
                $result['errors'][] = "Failed to fetch memorial page: HTTP {$response->status()}";

                return $result;
            }

            $html = $response->body();

            // Extract memorial photo URLs
            // Pattern: data-src="https://images.findagrave.com/photos/..."
            preg_match_all('/data-src="(https:\/\/images\.findagrave\.com\/photos[^"]+)"/', $html, $matches);

            if (empty($matches[1])) {
                // Try alternate pattern for full-size images
                preg_match_all('/(https:\/\/images\.findagrave\.com\/photos\/\d+\/\d+\/\d+\.(?:jpg|jpeg|png|gif))/i', $html, $matches);
            }

            if (empty($matches[1])) {
                $result['errors'][] = 'No memorial photos found';

                return $result;
            }

            $imageUrls = array_unique($matches[1]);

            // Download each image
            foreach ($imageUrls as $imageUrl) {
                $downloadResult = $this->downloadFile($imageUrl, $treeId, "findagrave_{$memorialId}");
                if ($downloadResult['success']) {
                    $result['files'][] = $downloadResult;
                } else {
                    $result['errors'][] = $downloadResult['error'];
                }
            }

            $result['success'] = ! empty($result['files']);

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('FindAGrave download error', [
                'memorial_id' => $memorialId,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Download media from FamilySearch ARK URL
     *
     * Note: FamilySearch requires browser authentication for image downloads.
     * This method does not call FamilySearch APIs; it queues the URL for manual review.
     */
    public function downloadFamilySearchMedia(string $arkUrl, int $treeId): array
    {
        $result = [
            'success' => false,
            'ark_url' => $arkUrl,
            'files' => [],
            'errors' => [],
            'requires_auth' => false,
        ];

        try {
            if (preg_match('/ark:\/61903\/([^\s\?]+)/', $arkUrl, $match)) {
                $arkId = $match[1];
                $result['ark_id'] = $arkId;
                $result['requires_auth'] = true;
                $result['errors'][] = 'FamilySearch media requires manual browser review';
                $result['manual_url'] = $arkUrl;

                return $result;
            }

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Download media from Newspapers.com URL using Puppeteer browser automation
     *
     * Newspapers.com Library Edition requires authenticated access.
     * User has authorized access via library barcode (see phase-9.5-newspaper-research.md)
     *
     * Format: https://www.newspapers.com/image/481894657/?article=b8bc3449-5162-4e0b-8780-a7d5caeca517
     *
     * @param  string  $url  Newspapers.com URL
     * @param  int  $treeId  Tree ID for storage
     * @return array Result with success status and file info
     */
    public function downloadNewspapersMedia(string $url, int $treeId): array
    {
        $result = [
            'success' => false,
            'url' => $url,
            'title' => null,
        ];

        if (! (bool) config('services.newspapers.personal_automation_enabled', false)) {
            $result['error'] = 'Newspapers.com media download is private/personal-gated and disabled for this install';
            $result['manual_only'] = true;
            $result['manual_required'] = true;
            $result['policy'] = 'private_personal_adapter_disabled';
            $result['requires_browser'] = true;

            return $result;
        }

        try {
            // Extract image ID from URL
            // Pattern: /image/(\d+)
            if (! preg_match('/\/image\/(\d+)/', $url, $match)) {
                $result['error'] = 'Could not extract image ID from URL';

                return $result;
            }

            $imageId = $match[1];

            // Use Puppeteer MCP to navigate and screenshot
            // This bypasses Cloudflare protection that blocks HTTP requests
            $screenshotPath = "{$this->storageBasePath}/newspapers_{$imageId}_".uniqid().'.png';

            // Try using the Puppeteer MCP server if available
            if ($this->canUsePuppeteer()) {
                $puppeteerResult = $this->downloadViaPuppeteer($url, $screenshotPath, $imageId);
                if ($puppeteerResult['success']) {
                    $result = array_merge($result, $puppeteerResult);

                    return $result;
                }
            }

            // Fallback: Try direct HTTP with enhanced headers (may fail due to Cloudflare)
            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->get($url);

            if (! $response->successful()) {
                $result['error'] = "HTTP request blocked (likely Cloudflare). Status: {$response->status()}. Use Puppeteer automation or manual download.";
                $result['requires_browser'] = true;

                return $result;
            }

            $html = $response->body();

            // Check for Cloudflare challenge
            if (strpos($html, 'cf-browser-verification') !== false || strpos($html, 'challenge-platform') !== false) {
                $result['error'] = 'Cloudflare browser verification required. Use Puppeteer automation or manual download.';
                $result['requires_browser'] = true;

                return $result;
            }

            // Extract title from page
            if (preg_match('/<title>([^<]+)<\/title>/i', $html, $titleMatch)) {
                $result['title'] = html_entity_decode(trim($titleMatch[1]));
            }

            // Look for image URLs in the page
            $imageUrl = null;

            // Pattern 1: og:image meta tag
            if (preg_match('/property="og:image"\s+content="([^"]+)"/', $html, $ogMatch)) {
                $imageUrl = $ogMatch[1];
            }

            // Pattern 2: Direct image link in data attributes
            if (! $imageUrl && preg_match('/data-image-url="([^"]+)"/', $html, $dataMatch)) {
                $imageUrl = $dataMatch[1];
            }

            // Pattern 3: Image src in clipping viewer
            if (! $imageUrl && preg_match('/src="(https:\/\/[^"]*newspapers\.com[^"]*\.(?:jpg|jpeg|png|gif)[^"]*)"/', $html, $srcMatch)) {
                $imageUrl = html_entity_decode($srcMatch[1]);
            }

            if (! $imageUrl) {
                $result['error'] = 'Could not find downloadable image. Use Library Edition login via Puppeteer.';
                $result['requires_browser'] = true;
                $result['hint'] = 'Configure Puppeteer with library barcode authentication for full access.';

                return $result;
            }

            // Download the image
            $downloadResult = $this->downloadFile($imageUrl, $treeId, "newspapers_{$imageId}");

            if ($downloadResult['success']) {
                $result['success'] = true;
                $result['filename'] = $downloadResult['filename'];
                $result['filepath'] = $downloadResult['filepath'];
                $result['size'] = $downloadResult['size'];
                $result['content_type'] = $downloadResult['content_type'];
            } else {
                $result['error'] = $downloadResult['error'] ?? 'Download failed';
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::error('Newspapers.com download error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Search LOC Chronicling America for newspaper pages
     *
     * Free API - no authentication required.
     * Coverage: 1736-1963 historical newspapers from Library of Congress.
     *
     * @param  string  $query  Search query (person name, event, location)
     * @param  array  $options  Optional filters: state, dateStart, dateEnd, lccn
     * @return array Search results with page URLs
     */
    public function searchChroniclingAmerica(string $query, array $options = []): array
    {
        $result = [
            'success' => false,
            'query' => $query,
            'total' => 0,
            'pages' => [],
            'errors' => [],
        ];

        try {
            // Build search URL
            // API: https://www.loc.gov/collections/chronicling-america/?fo=json
            $params = [
                'q' => $query,
                'fo' => 'json',
                'c' => $options['limit'] ?? 25,
            ];

            // Add state filter if specified
            if (! empty($options['state'])) {
                $params['fa'] = 'location_state:'.strtolower($options['state']);
            }

            // Add date range filter
            if (! empty($options['dateStart']) || ! empty($options['dateEnd'])) {
                $start = $options['dateStart'] ?? '1736';
                $end = $options['dateEnd'] ?? '1963';
                $params['dates'] = "{$start}/{$end}";
            }

            $url = 'https://www.loc.gov/collections/chronicling-america/?'.http_build_query($params);

            $response = Http::connectTimeout(5)->timeout(60)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'GenealogyApp/1.0 (genealogy research)',
                ])
                ->get($url);

            if (! $response->successful()) {
                $result['errors'][] = "LOC API returned HTTP {$response->status()}";

                return $result;
            }

            $data = $response->json();

            // Extract results
            $results = $data['results'] ?? $data['content']['results'] ?? [];
            $result['total'] = $data['pagination']['of'] ?? count($results);

            foreach ($results as $item) {
                // Only include actual newspaper pages (segments)
                if (isset($item['image_url']) && ! empty($item['image_url'])) {
                    $page = [
                        'id' => $item['id'] ?? '',
                        'title' => $item['title'] ?? $item['partof_title']['value'] ?? 'Unknown',
                        'date' => $item['date'] ?? '',
                        'state' => $item['location_state']['value'] ?? '',
                        'city' => $item['location_city'][0] ?? '',
                        'description' => is_array($item['description'] ?? null) ? ($item['description'][0] ?? '') : ($item['description'] ?? ''),
                        'url' => $item['url'] ?? $item['id'] ?? '',
                        'image_urls' => [],
                    ];

                    // Extract image URLs (IIIF format)
                    foreach ($item['image_url'] as $imgUrl) {
                        // Skip word coordinates URLs
                        if (strpos($imgUrl, 'word-coordinates') !== false) {
                            continue;
                        }
                        // Upgrade to higher resolution (pct:50 or full)
                        $highResUrl = preg_replace('/pct:\d+\.?\d*/', 'pct:50', $imgUrl);
                        // Remove size hints from URL
                        $highResUrl = preg_replace('/#.*$/', '', $highResUrl);
                        $page['image_urls'][] = $highResUrl;
                    }

                    if (! empty($page['image_urls'])) {
                        $result['pages'][] = $page;
                    }
                }
            }

            $result['success'] = true;

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('LOC Chronicling America search error', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Download newspaper page from LOC Chronicling America
     *
     * Uses IIIF Image API for high-quality downloads.
     * No authentication required.
     *
     * @param  string  $pageUrl  LOC resource URL or IIIF image URL
     * @param  int  $treeId  Tree ID for storage
     * @param  string  $resolution  Resolution: 'thumbnail' (6.25%), 'medium' (25%), 'high' (50%), 'full' (100%)
     * @return array Download result with file info
     */
    public function downloadChroniclingAmericaPage(string $pageUrl, int $treeId, string $resolution = 'high'): array
    {
        $result = [
            'success' => false,
            'url' => $pageUrl,
            'files' => [],
            'errors' => [],
        ];

        try {
            // Determine the image URL
            $imageUrl = $pageUrl;

            // If it's a resource URL, convert to IIIF image URL
            if (strpos($pageUrl, '/resource/') !== false || strpos($pageUrl, '/item/') !== false) {
                // Fetch the resource JSON to get image URLs
                $jsonUrl = rtrim($pageUrl, '/').'?fo=json';

                $response = Http::connectTimeout(5)->timeout(30)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get($jsonUrl);

                if ($response->successful()) {
                    $data = $response->json();
                    $imageUrls = $data['image_url'] ?? $data['resources'][0]['image'] ?? [];

                    foreach ((array) $imageUrls as $url) {
                        if (strpos($url, 'image-services/iiif') !== false && strpos($url, 'word-coordinates') === false) {
                            $imageUrl = $url;
                            break;
                        }
                    }
                }
            }

            // Apply resolution setting to IIIF URL
            // IIIF format: .../full/pct:XX/0/default.jpg
            // - 'full' is the region (keep as-is)
            // - 'pct:XX' is the size (this is what we modify)
            $resolutionMap = [
                'thumbnail' => 'pct:6.25',
                'medium' => 'pct:25',
                'high' => 'pct:50',
                'full' => 'full',
            ];
            $pct = $resolutionMap[$resolution] ?? 'pct:50';

            // Update IIIF URL with desired resolution (only modify the size portion)
            if (strpos($imageUrl, 'iiif') !== false) {
                // Replace existing pct:X.XX with new resolution
                $imageUrl = preg_replace('/\/pct:\d+\.?\d*\//', "/{$pct}/", $imageUrl);
            }

            // Remove any URL fragments
            $imageUrl = preg_replace('/#.*$/', '', $imageUrl);

            // Generate unique filename
            $hash = substr(md5($pageUrl), 0, 8);
            $filename = "loc_chronicling_{$hash}_".uniqid().'.jpg';

            // Download the image
            $downloadResult = $this->downloadFile($imageUrl, $treeId, '');

            if ($downloadResult['success']) {
                // Rename with our preferred filename
                $newPath = "{$this->storageBasePath}/{$filename}";
                if (file_exists($downloadResult['filepath'])) {
                    rename($downloadResult['filepath'], $newPath);
                    $downloadResult['filepath'] = $newPath;
                    $downloadResult['filename'] = $filename;
                }

                $result['files'][] = $downloadResult;
                $result['success'] = true;
            } else {
                $result['errors'][] = $downloadResult['error'] ?? 'Download failed';
            }

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            Log::error('LOC Chronicling America download error', [
                'url' => $pageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Search LOC Chronicling America for obituaries or death notices
     *
     * Specialized search for genealogy research.
     *
     * @param  string  $surname  Person's surname
     * @param  string|null  $givenName  Person's given name
     * @param  string|null  $state  State to search (e.g., 'Pennsylvania')
     * @param  string|null  $deathYear  Approximate year of death
     * @return array Search results
     */
    public function searchChroniclingAmericaObituaries(
        string $surname,
        ?string $givenName = null,
        ?string $state = null,
        ?string $deathYear = null
    ): array {
        // Build obituary-focused query
        // Keep it simple for faster API response
        $queryParts = [];

        // Add name (quoted for exact match)
        if ($givenName) {
            $queryParts[] = "\"{$givenName} {$surname}\"";
        } else {
            $queryParts[] = $surname;
        }

        // Add single obituary keyword (simpler = faster)
        $queryParts[] = 'died';

        $query = implode(' ', $queryParts);

        $options = [
            'limit' => 25, // Reduce limit for faster response
        ];

        if ($state) {
            $options['state'] = $state;
        }

        if ($deathYear) {
            // Search 1 year before and after death year
            $options['dateStart'] = (int) $deathYear - 1;
            $options['dateEnd'] = (int) $deathYear + 1;
        }

        return $this->searchChroniclingAmerica($query, $options);
    }

    /**
     * Check if Puppeteer script is available
     *
     * Note: Newspapers.com Library Edition requires browser automation with
     * library barcode authentication. The script uses puppeteer-extra-plugin-stealth
     * to bypass Cloudflare protection.
     */
    protected function canUsePuppeteer(): bool
    {
        if (! (bool) config('services.newspapers.personal_automation_enabled', false)) {
            return false;
        }

        // Check if puppeteer script exists (.cjs for CommonJS in ES module project)
        return file_exists(base_path('scripts/newspapers-scraper.cjs'));
    }

    /**
     * Download via Puppeteer browser automation
     * Uses Node.js script with puppeteer-extra-plugin-stealth
     *
     * Note: This requires manual browser session establishment first.
     * The Cloudflare + Barcode login combination is difficult to automate.
     * Consider using the MCP Puppeteer server for interactive sessions.
     */
    protected function downloadViaPuppeteer(string $url, string $outputPath, string $imageId): array
    {
        $result = ['success' => false];

        $script = base_path('scripts/newspapers-scraper.cjs');
        if (! file_exists($script)) {
            $result['error'] = 'Puppeteer scraper script not found. Create scripts/newspapers-scraper.cjs';

            return $result;
        }

        // Get library credentials from env
        $barcode = config('services.newspapers.barcode');
        if (empty($barcode)) {
            $result['error'] = 'NEWSPAPERS_BARCODE not configured in .env';

            return $result;
        }

        $params = json_encode([
            'url' => $url,
            'outputPath' => $outputPath,
            'barcode' => $barcode,
        ]);

        $output = Process::timeout(180)
            ->path(base_path())
            ->run(['node', $script, $params])
            ->output();
        $response = json_decode($output, true);

        if ($response && isset($response['success']) && $response['success']) {
            $result['success'] = true;
            $result['filename'] = basename($outputPath);
            $result['filepath'] = $outputPath;
            $result['size'] = filesize($outputPath);
            $result['content_type'] = 'image/png';
            $result['title'] = $response['title'] ?? "Newspapers.com Image {$imageId}";
        } else {
            $result['error'] = $response['error'] ?? 'Puppeteer automation failed. Try MCP Puppeteer for manual authentication.';
            $result['manual_url'] = $url;
            $result['hint'] = 'Use MCP Puppeteer to manually log in with barcode, then screenshot the clipping.';
        }

        return $result;
    }

    /**
     * Download a generic file from URL
     */
    public function downloadFile(string $url, int $treeId, string $prefix = ''): array
    {
        $result = [
            'success' => false,
            'url' => $url,
        ];

        if ($manualOnlyDomain = $this->manualOnlyDomainForUrl($url)) {
            $result['error'] = "Manual-only domain media download is disabled for {$manualOnlyDomain}";
            $result['manual_only'] = true;
            $result['manual_required'] = true;
            $result['policy'] = 'manual_only_domain';
            $result['domain'] = $manualOnlyDomain;

            return $result;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(60)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; GenealogyApp/1.0)',
                ])
                ->get($url);

            if (! $response->successful()) {
                $result['error'] = "HTTP {$response->status()}";

                return $result;
            }

            // Determine filename and extension
            $contentType = $response->header('Content-Type');
            $ext = $this->getExtensionFromContentType($contentType) ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?? 'jpg';

            $filename = ($prefix ? $prefix.'_' : '').uniqid().'.'.$ext;
            $filepath = "{$this->storageBasePath}/{$filename}";

            // Save file
            file_put_contents($filepath, $response->body());

            $result['success'] = true;
            $result['filename'] = $filename;
            $result['filepath'] = $filepath;
            $result['size'] = filesize($filepath);
            $result['content_type'] = $contentType;

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    protected function manualOnlyDomainForUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '') {
            return null;
        }

        foreach ((array) config('scraping.manual_only_domains', []) as $domain) {
            $domain = ltrim(strtolower(trim((string) $domain)), '*.');
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Get file extension from content type
     */
    protected function getExtensionFromContentType(?string $contentType): ?string
    {
        if (! $contentType) {
            return null;
        }

        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'text/html' => 'html',
        ];

        foreach ($map as $mime => $ext) {
            if (stripos($contentType, $mime) !== false) {
                return $ext;
            }
        }

        return null;
    }

    /**
     * Create a media record from downloaded file
     */
    public function createMediaRecord(int $treeId, string $filepath, array $metadata = []): int
    {
        $filename = basename($filepath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Determine media type
        $mediaType = 'document';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
            $mediaType = 'photo';
        } elseif ($ext === 'pdf') {
            $mediaType = 'document';
        }

        $sql = 'INSERT INTO genealogy_media
                (tree_id, title, media_type, original_path, local_filename, nextcloud_path, description, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $metadata['title'] ?? $filename,
            $mediaType,
            $metadata['source_url'] ?? $filepath,
            $filename,
            '/101.Genealogy/downloaded/'.$filename,
            $metadata['description'] ?? 'Downloaded from '.($metadata['source'] ?? 'external source'),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Link downloaded media to source via citation
     */
    public function linkMediaToSource(int $sourceId, int $mediaId, ?int $personId = null, ?string $page = null): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_citations WHERE source_id = ? AND media_id = ?',
            [$sourceId, $mediaId]
        );

        if (! $existing) {
            DB::insert(
                "INSERT INTO genealogy_citations (source_id, media_id, person_id, page, fact_type, created_at)
                 VALUES (?, ?, ?, ?, 'downloaded_media', NOW())",
                [$sourceId, $mediaId, $personId, $page]
            );
        }
    }

    /**
     * Get download status/queue for a tree
     */
    public function getDownloadQueue(int $treeId, int $limit = 100): array
    {
        // Find citations that have URLs but no media
        $sql = "
            SELECT DISTINCT
                ps.source_id,
                s.title as source_title,
                s.gedcom_id as source_gedcom_id,
                ps.page,
                p.id as person_id,
                p.given_name,
                p.surname
            FROM genealogy_person_sources ps
            JOIN genealogy_sources s ON s.id = ps.source_id
            JOIN genealogy_persons p ON p.id = ps.person_id
            LEFT JOIN genealogy_citations c ON c.source_id = ps.source_id AND c.media_id IS NOT NULL
            WHERE s.tree_id = ?
            AND c.id IS NULL
            AND (
                s.title LIKE '%Find A Grave%'
                OR s.title LIKE '%FamilySearch%'
                OR s.title LIKE '%Newspapers.com%'
            )
            ORDER BY s.title
            LIMIT ?
        ";

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * N144: Download NARA digital objects and attach to a genealogy person.
     *
     * Fetches the NARA catalog item, extracts digital object URLs,
     * downloads images/PDFs, creates genealogy_media records, and
     * links them to the person via genealogy_person_media.
     *
     * @param  string  $externalId  NARA catalog URL or naId
     * @param  int  $treeId  Genealogy tree ID
     * @param  int  $personId  Person to attach media to
     * @return array{success: bool, downloaded: int, skipped: int, media_ids: int[]}
     */
    public function downloadNaraRecord(string $externalId, int $treeId, int $personId): array
    {
        $result = ['success' => false, 'downloaded' => 0, 'skipped' => 0, 'media_ids' => []];

        // Extract naId from URL or use as-is
        $naId = $externalId;
        if (preg_match('/catalog\.archives\.gov\/id\/(\d+)/', $externalId, $m)) {
            $naId = $m[1];
        } elseif (preg_match('/memories\/memory\/(\d+)/', $externalId, $m)) {
            $naId = $m[1];
        }
        // Strip any non-numeric prefix for plain IDs
        $naId = preg_replace('/[^\d]/', '', $naId);
        if (empty($naId)) {
            $result['error'] = 'Could not extract NARA ID from: '.$externalId;

            return $result;
        }

        Log::info('N144: Fetching NARA catalog item', ['naId' => $naId, 'person_id' => $personId]);

        // Fetch the catalog item to get digital object URLs
        $apiKey = config('services.nara.api_key');
        $request = Http::connectTimeout(5)->timeout(30)->withHeaders([
            'User-Agent' => 'PLOS-Genealogy/1.0',
        ]);
        if ($apiKey) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        $response = $request->get("https://catalog.archives.gov/api/v2/?naIds={$naId}&resultTypes=object");

        if (! $response->successful()) {
            $result['error'] = 'NARA API returned HTTP '.$response->status();

            return $result;
        }

        $data = $response->json();
        $resultItem = $data['opaResponse']['results']['result'][0] ?? null;

        if (! $resultItem) {
            $result['error'] = 'No results from NARA for naId: '.$naId;

            return $result;
        }

        // Extract digital objects
        $objects = $resultItem['objects']['object'] ?? [];
        if (! is_array($objects)) {
            $objects = [$objects];
        }
        // Handle single object (not wrapped in array)
        if (isset($objects['file'])) {
            $objects = [$objects];
        }

        $title = $resultItem['description']['item']['title'] ?? "NARA Record {$naId}";
        $maxDownloads = 5;
        $downloaded = 0;

        foreach ($objects as $obj) {
            if ($downloaded >= $maxDownloads) {
                break;
            }

            $fileUrl = $obj['file']['@url'] ?? $obj['file']['url'] ?? null;
            $mime = $obj['file']['@mime'] ?? $obj['file']['mime'] ?? '';

            if (! $fileUrl) {
                $result['skipped']++;

                continue;
            }

            // Only download images and PDFs
            $allowedMimes = ['image/jpeg', 'image/png', 'image/tiff', 'image/gif', 'application/pdf'];
            $mimeMatch = false;
            foreach ($allowedMimes as $allowed) {
                if (stripos($mime, $allowed) !== false) {
                    $mimeMatch = true;
                    break;
                }
            }
            // Also accept if no mime but URL looks like an image/PDF
            if (! $mimeMatch && ! preg_match('/\.(jpg|jpeg|png|tiff?|gif|pdf)$/i', $fileUrl)) {
                $result['skipped']++;

                continue;
            }

            $dlResult = $this->downloadFile($fileUrl, $treeId, "nara_{$naId}");
            if (! $dlResult['success']) {
                Log::warning('N144: Failed to download NARA object', [
                    'url' => $fileUrl,
                    'error' => $dlResult['error'] ?? 'unknown',
                ]);
                $result['skipped']++;

                continue;
            }

            // Create media record
            $mediaId = $this->createMediaRecord($treeId, $dlResult['filepath'], [
                'title' => $title.($downloaded > 0 ? ' ('.($downloaded + 1).')' : ''),
                'source_url' => $fileUrl,
                'description' => "Downloaded from National Archives Catalog (naId: {$naId})",
                'source' => 'NARA',
            ]);

            // Link to person
            $existingLink = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ? LIMIT 1',
                [$personId, $mediaId]
            );
            if (! $existingLink) {
                DB::insert(
                    'INSERT INTO genealogy_person_media (person_id, media_id, is_primary, created_at) VALUES (?, ?, 0, NOW())',
                    [$personId, $mediaId]
                );
            }

            $result['media_ids'][] = $mediaId;
            $downloaded++;
        }

        $result['downloaded'] = $downloaded;
        $result['success'] = $downloaded > 0;

        // Update external record status to 'imported' if we downloaded anything
        if ($downloaded > 0) {
            DB::update(
                "UPDATE genealogy_external_records SET status = 'imported', imported_at = NOW(), updated_at = NOW()
                 WHERE service_type = 'nara' AND external_id = ?",
                [$externalId]
            );
        }

        Log::info('N144: NARA download complete', [
            'naId' => $naId,
            'person_id' => $personId,
            'downloaded' => $downloaded,
            'skipped' => $result['skipped'],
        ]);

        return $result;
    }
}
