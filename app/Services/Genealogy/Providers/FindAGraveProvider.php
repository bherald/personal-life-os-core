<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\Log;

/**
 * Find A Grave Provider
 *
 * FREE - No API key required (uses web scraping approach)
 * 250+ million memorial records
 *
 * Website: https://www.findagrave.com
 *
 * Note: Find A Grave doesn't have an official API.
 * This uses their search interface and publicly available data.
 *
 * Features:
 * - Search memorials by name, location, dates
 * - Get memorial details (burial location, photos, etc.)
 * - Link to full memorial pages
 */
class FindAGraveProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'findagrave';
    protected const PROVIDER_NAME = 'Find A Grave';
    protected const BASE_URL = 'https://www.findagrave.com';
    protected const SEARCH_URL = 'https://www.findagrave.com/memorial/search';

    protected array $defaultCapabilities = [
        'search_persons' => true,
        'search_records' => true,
        'get_record' => true,
        'get_person' => false,
        'get_family' => false,
        'get_collections' => false,
        'hints' => false,
        'attach_records' => false,
        'dna_matches' => false,
    ];

    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getAuthType(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return true; // No configuration required
    }

    public function isAuthenticated(): bool
    {
        return true; // No authentication required
    }

    /**
     * Search for memorials/grave records
     */
    public function searchPersons(array $criteria, array $options = []): array
    {
        return $this->searchRecords($criteria, $options);
    }

    /**
     * Search Find A Grave memorials
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        $params = [];

        // Name search
        if (!empty($criteria['given_name'])) {
            $params['firstname'] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $params['lastname'] = $criteria['surname'];
        }

        // Date filters
        if (!empty($criteria['birth_year'])) {
            $params['birthyear'] = $criteria['birth_year'];
            $params['birthyearfilter'] = $options['year_range'] ?? 'on'; // on, before, after, +/- years
        }
        if (!empty($criteria['death_year'])) {
            $params['deathyear'] = $criteria['death_year'];
            $params['deathyearfilter'] = $options['year_range'] ?? 'on';
        }

        // Location filters
        if (!empty($criteria['cemetery'])) {
            $params['cemeteryname'] = $criteria['cemetery'];
        }
        if (!empty($criteria['city'])) {
            $params['city'] = $criteria['city'];
        }
        if (!empty($criteria['state'])) {
            $params['state'] = $criteria['state'];
        }
        if (!empty($criteria['country'])) {
            $params['country'] = $criteria['country'];
        }

        // Pagination
        $params['page'] = $options['page'] ?? 1;
        $params['orderby'] = $options['order_by'] ?? 'r'; // r = relevance

        // Build search URL
        $url = self::SEARCH_URL . '?' . http_build_query($params);

        Log::info('FindAGraveProvider: Searching', ['params' => $params]);

        // Since Find A Grave doesn't have a JSON API, we'd need to:
        // 1. Make HTTP request
        // 2. Parse HTML response
        // 3. Extract memorial data

        // For now, return search URL for manual use
        // Full implementation would use DOM parsing

        try {
            // Attempt to get search results (simplified)
            $html = $this->fetchHtml(self::SEARCH_URL, $params);

            if (!$html) {
                return [
                    'success' => false,
                    'error' => $this->lastError ?? 'Failed to fetch search results',
                    'search_url' => $url,
                    'results' => [],
                ];
            }

            $results = $this->parseSearchResults($html);

            return [
                'success' => true,
                'source' => 'Find A Grave',
                'search_url' => $url,
                'total_count' => count($results),
                'results' => $results,
            ];

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'search_url' => $url,
                'results' => [],
            ];
        }
    }

    /**
     * Get memorial details by ID
     */
    public function getRecord(string $recordId): ?array
    {
        $url = self::BASE_URL . '/memorial/' . $recordId;

        try {
            $html = $this->fetchHtml($url, []);

            if (!$html) {
                return null;
            }

            return $this->parseMemorialDetails($html, $recordId);

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Fetch HTML from URL with browser-like headers.
     */
    protected function fetchHtml(string $url, array $params = []): ?string
    {
        try {
            $fullUrl = $params ? $url . '?' . http_build_query($params) : $url;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => $this->config['timeout'],
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Accept-Encoding: identity',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => '',
            ]);

            $html     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->lastError = "cURL error: {$error}";
                return null;
            }

            if ($httpCode === 403 || $httpCode === 429) {
                $this->lastError = "FindAGrave rate-limited (HTTP {$httpCode}). Try again later.";
                return null;
            }

            if ($httpCode !== 200) {
                $this->lastError = "HTTP {$httpCode}";
                return null;
            }

            return $html ?: null;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Extract embedded Next.js JSON (__NEXT_DATA__) from HTML.
     * FindAGrave runs on Next.js and embeds all page data as structured JSON.
     */
    protected function extractNextData(string $html): ?array
    {
        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/s', $html, $m)) {
            $data = json_decode($m[1], true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /**
     * Extract JSON-LD schema data from HTML (schema.org/Person).
     */
    protected function extractJsonLd(string $html): ?array
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $html, $matches);
        foreach ($matches[1] ?? [] as $json) {
            $data = json_decode($json, true);
            if (is_array($data) && in_array($data['@type'] ?? '', ['Person', 'BurialPlace', 'Cemetery'])) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Parse search results HTML — tries __NEXT_DATA__ JSON first, DOM as fallback.
     */
    protected function parseSearchResults(string $html): array
    {
        // PRIMARY: Extract from Next.js embedded JSON (most reliable)
        $nextData = $this->extractNextData($html);
        if ($nextData) {
            $results = $this->parseNextDataSearch($nextData);
            if (!empty($results)) {
                return $results;
            }
        }

        // FALLBACK: DOM parsing — updated for current FAG layout
        return $this->parseDomSearchResults($html);
    }

    /**
     * Parse search results from __NEXT_DATA__ JSON.
     */
    protected function parseNextDataSearch(array $nextData): array
    {
        $results   = [];
        $pageProps = $nextData['props']['pageProps'] ?? [];

        // FAG embeds results under several possible keys
        $memorials = $pageProps['memorials']
            ?? $pageProps['searchResults']
            ?? $pageProps['results']
            ?? [];

        foreach ($memorials as $m) {
            if (empty($m['memorialId']) && empty($m['id'])) {
                continue;
            }

            $id = $m['memorialId'] ?? $m['id'] ?? null;

            // Build full name
            $name = trim(
                ($m['firstName'] ?? $m['givenName'] ?? '') . ' '
                . ($m['middleName'] ?? '') . ' '
                . ($m['lastName'] ?? $m['familyName'] ?? $m['maidenName'] ?? '')
            );

            $results[] = [
                'id'         => (string)$id,
                'name'       => $name ?: ($m['name'] ?? 'Unknown'),
                'birth_year' => $m['birthYear'] ?? null,
                'death_year' => $m['deathYear'] ?? null,
                'birth_date' => $m['birthDate'] ?? null,
                'death_date' => $m['deathDate'] ?? null,
                'cemetery'   => $m['cemeteryName'] ?? null,
                'location'   => $m['locationName'] ?? $m['location'] ?? null,
                'has_photos' => !empty($m['photoUrl']) || !empty($m['hasPhoto']),
                'url'        => self::BASE_URL . '/memorial/' . $id,
                'source'     => 'Find A Grave',
            ];
        }

        return $results;
    }

    /**
     * DOM-based search result parser — updated selectors for current FAG layout.
     */
    protected function parseDomSearchResults(string $html): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // Current FAG layout uses data-memorial-id attributes and various wrapper classes
        $selectors = [
            '//div[@data-memorial-id]',
            '//li[@data-memorial-id]',
            '//div[contains(@class, "memorial-item")]',
            '//div[contains(@class, "search-result")]',
        ];

        $entries = null;
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                $entries = $nodes;
                break;
            }
        }

        if ($entries) {
            foreach ($entries as $entry) {
                $memorialId = $entry->getAttribute('data-memorial-id');

                if (!$memorialId) {
                    $linkNode = $xpath->query('.//a[contains(@href, "/memorial/")]', $entry)->item(0);
                    if ($linkNode) {
                        preg_match('/\/memorial\/(\d+)/', $linkNode->getAttribute('href'), $m);
                        $memorialId = $m[1] ?? null;
                    }
                }

                if (!$memorialId) {
                    continue;
                }

                $nameNode = $xpath->query('.//h2|.//h3|.//span[contains(@class,"name")]', $entry)->item(0);
                $text     = $entry->textContent;

                preg_match('/(\d{4})\s*[-–—]\s*(\d{4})/', $text, $dateMatches);
                preg_match('/(?:born|b\.?)\s*(\d{4})/i', $text, $birthMatches);
                preg_match('/(?:died|d\.?)\s*(\d{4})/i', $text, $deathMatches);

                $birthYear = $dateMatches[1] ?? $birthMatches[1] ?? null;
                $deathYear = $dateMatches[2] ?? $deathMatches[1] ?? null;

                $results[] = [
                    'id'         => $memorialId,
                    'name'       => $nameNode ? trim($nameNode->textContent) : 'Unknown',
                    'birth_year' => $birthYear,
                    'death_year' => $deathYear,
                    'url'        => self::BASE_URL . '/memorial/' . $memorialId,
                    'source'     => 'Find A Grave',
                ];
            }
        }

        // If DOM also found nothing, try parsing all /memorial/ links
        if (empty($results)) {
            preg_match_all('/href="\/memorial\/(\d+)[^"]*"[^>]*>([^<]+)</i', $html, $linkMatches);
            foreach ($linkMatches[1] as $i => $mid) {
                $results[] = [
                    'id'     => $mid,
                    'name'   => trim($linkMatches[2][$i] ?? 'Unknown'),
                    'url'    => self::BASE_URL . '/memorial/' . $mid,
                    'source' => 'Find A Grave',
                ];
            }
        }

        libxml_clear_errors();
        return array_unique($results, SORT_REGULAR);
    }

    /**
     * Parse memorial details page — tries __NEXT_DATA__ and JSON-LD first.
     */
    protected function parseMemorialDetails(string $html, string $memorialId): array
    {
        $details = [
            'id'     => $memorialId,
            'url'    => self::BASE_URL . '/memorial/' . $memorialId,
            'source' => 'Find A Grave',
        ];

        // PRIMARY: __NEXT_DATA__ JSON
        $nextData = $this->extractNextData($html);
        if ($nextData) {
            $memorial = $nextData['props']['pageProps']['memorial']
                ?? $nextData['props']['pageProps']['person']
                ?? null;

            if (is_array($memorial)) {
                $details['name']       = trim(($memorial['firstName'] ?? '') . ' ' . ($memorial['lastName'] ?? '')) ?: null;
                $details['birth_date'] = $memorial['birthDate'] ?? null;
                $details['death_date'] = $memorial['deathDate'] ?? null;
                $details['birth_year'] = $memorial['birthYear'] ?? null;
                $details['death_year'] = $memorial['deathYear'] ?? null;
                $details['cemetery']   = $memorial['cemeteryName'] ?? null;
                $details['location']   = $memorial['locationName'] ?? $memorial['location'] ?? null;
                $details['has_photos'] = !empty($memorial['photos']) || !empty($memorial['photoUrl']);
                $details['biography']  = $memorial['bio'] ?? $memorial['biography'] ?? null;

                if (!empty($details['name'])) {
                    return $details;
                }
            }
        }

        // SECONDARY: JSON-LD schema.org/Person
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd) {
            $details['name']       = $jsonLd['name'] ?? null;
            $details['birth_date'] = $jsonLd['birthDate'] ?? null;
            $details['death_date'] = $jsonLd['deathDate'] ?? null;
            $details['location']   = $jsonLd['deathPlace']['name'] ?? null;

            if (!empty($details['name'])) {
                return $details;
            }
        }

        // FALLBACK: DOM with schema.org microdata (itemprop attributes)
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        $nameNode = $xpath->query('//h1[@id="bio-name"]|//h1[contains(@class,"name")]|//h1[@itemprop="name"]')->item(0);
        if ($nameNode) {
            $details['name'] = trim($nameNode->textContent);
        }

        $birthNode = $xpath->query('//*[@itemprop="birthDate"]')->item(0);
        $deathNode = $xpath->query('//*[@itemprop="deathDate"]')->item(0);
        if ($birthNode) $details['birth_date'] = trim($birthNode->getAttribute('content') ?: $birthNode->textContent);
        if ($deathNode) $details['death_date'] = trim($deathNode->getAttribute('content') ?: $deathNode->textContent);

        $cemeteryNode = $xpath->query('//a[contains(@href, "/cemetery/")]')->item(0);
        if ($cemeteryNode) {
            $details['cemetery']     = trim($cemeteryNode->textContent);
            $details['cemetery_url'] = self::BASE_URL . $cemeteryNode->getAttribute('href');
        }

        $photoCount = $xpath->query('//img[contains(@class,"memorial-photo")]|//img[contains(@src,"photos.findagrave")]')->length;
        $details['has_photos']  = $photoCount > 0;
        $details['photo_count'] = $photoCount;

        libxml_clear_errors();
        return $details;
    }

    public function getPerson(string $personId): ?array
    {
        return $this->getRecord($personId);
    }
}
