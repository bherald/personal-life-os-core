<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Newspaper Search Service
 *
 * Specialized service for searching newspaper archives.
 * Primary source: Library of Congress Chronicling America (FREE)
 *
 * RAW SQL ONLY - No Eloquent/Query Builder per project standards.
 *
 * Features:
 * - LOC Chronicling America API integration
 * - OCR text extraction
 * - AI-powered name/date extraction
 * - Clipping storage and linking to persons
 */
class NewspaperSearchService
{
    protected string $locBaseUrl = 'https://www.loc.gov';
    protected string $chroniclingBaseUrl = 'https://chroniclingamerica.loc.gov';
    protected int $rateLimitMs = 1000; // 1 request per second

    /**
     * Search Chronicling America for newspaper articles
     */
    public function search(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $page = $options['page'] ?? 1;

        $url = "{$this->locBaseUrl}/collections/chronicling-america/";
        $params = [
            'fo' => 'json',
            'q' => $query,
            'c' => $limit,
            'sp' => $page,
        ];

        // Add date range filter
        if (!empty($options['date_start']) || !empty($options['date_end'])) {
            $dateFilter = '';
            if (!empty($options['date_start'])) {
                $dateFilter = $options['date_start'];
            }
            if (!empty($options['date_end'])) {
                $dateFilter .= '/' . $options['date_end'];
            }
            if ($dateFilter) {
                $params['dates'] = $dateFilter;
            }
        }

        // Add state filter
        if (!empty($options['state'])) {
            $params['fa'] = 'location:' . $options['state'];
        }

        Log::info('NewspaperSearchService: Searching LOC', [
            'query' => $query,
            'options' => $options
        ]);

        // Rate limiting
        $this->rateLimit();

        try {
            $response = Http::connectTimeout(5)->timeout(30)->get($url, $params);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "LOC API error: HTTP {$response->status()}",
                    'results' => [],
                ];
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            return [
                'success' => true,
                'source' => 'Library of Congress - Chronicling America',
                'query' => $query,
                'total_count' => $data['pagination']['total'] ?? count($results),
                'page' => $page,
                'per_page' => $limit,
                'results' => array_map(function ($item) {
                    return $this->normalizeResult($item);
                }, $results),
            ];

        } catch (\Exception $e) {
            Log::error('NewspaperSearchService: Search failed', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Search for obituaries specifically
     */
    public function searchObituaries(string $name, array $options = []): array
    {
        // Add obituary-related terms to query
        $query = "{$name} (obituary OR died OR death OR funeral OR burial)";

        return $this->search($query, $options);
    }

    /**
     * Search for birth announcements
     */
    public function searchBirthAnnouncements(string $name, array $options = []): array
    {
        $query = "{$name} (born OR birth OR baby OR infant OR arrival)";

        return $this->search($query, $options);
    }

    /**
     * Search for marriage announcements
     */
    public function searchMarriages(string $name, array $options = []): array
    {
        $query = "{$name} (married OR wedding OR marriage OR bride OR groom OR nuptials)";

        return $this->search($query, $options);
    }

    /**
     * Get OCR text for a specific newspaper page
     *
     * @param string $lccn Library of Congress Control Number
     * @param string $date Date in YYYY-MM-DD format
     * @param int $edition Edition number (usually 1)
     * @param int $page Page/sequence number
     */
    public function getPageOCR(string $lccn, string $date, int $edition = 1, int $page = 1): array
    {
        // Format date for LOC URL (YYYY-MM-DD to just date path)
        $datePath = str_replace('-', '', $date);

        $url = "{$this->chroniclingBaseUrl}/lccn/{$lccn}/{$datePath}/ed-{$edition}/seq-{$page}/ocr.txt";

        Log::info('NewspaperSearchService: Getting OCR', [
            'lccn' => $lccn,
            'date' => $date,
            'page' => $page,
        ]);

        $this->rateLimit();

        try {
            $response = Http::connectTimeout(5)->timeout(30)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "Failed to get OCR: HTTP {$response->status()}",
                    'text' => null,
                ];
            }

            return [
                'success' => true,
                'lccn' => $lccn,
                'date' => $date,
                'page' => $page,
                'text' => $response->body(),
                'url' => $url,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'text' => null,
            ];
        }
    }

    /**
     * Get newspaper metadata by LCCN
     */
    public function getNewspaperInfo(string $lccn): array
    {
        $url = "{$this->chroniclingBaseUrl}/lccn/{$lccn}.json";

        $this->rateLimit();

        try {
            $response = Http::connectTimeout(5)->timeout(30)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "Failed to get newspaper info: HTTP {$response->status()}",
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'lccn' => $lccn,
                'title' => $data['name'] ?? 'Unknown',
                'place_of_publication' => $data['place_of_publication'] ?? null,
                'publisher' => $data['publisher'] ?? null,
                'start_year' => $data['start_year'] ?? null,
                'end_year' => $data['end_year'] ?? null,
                'subject' => $data['subject'] ?? [],
                'url' => $data['url'] ?? null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search for a person and save matching clippings
     */
    public function searchAndSaveForPerson(int $personId, int $treeId, array $options = []): array
    {
        // Get person data
        $person = DB::selectOne("
            SELECT id, given_name, surname, birth_date, birth_place, death_date, death_place
            FROM genealogy_persons
            WHERE id = ?
        ", [$personId]);

        if (!$person) {
            return [
                'success' => false,
                'error' => 'Person not found',
                'clippings_saved' => 0,
            ];
        }

        // Build search query
        $name = trim("{$person->given_name} {$person->surname}");
        if (empty($name)) {
            return [
                'success' => false,
                'error' => 'Person has no name',
                'clippings_saved' => 0,
            ];
        }

        // Determine date range from person's life
        $searchOptions = $options;
        if (!empty($person->birth_date)) {
            $birthYear = date('Y', strtotime($person->birth_date));
            $searchOptions['date_start'] = $birthYear;
        }
        if (!empty($person->death_date)) {
            $deathYear = date('Y', strtotime($person->death_date));
            $searchOptions['date_end'] = $deathYear + 1; // Include year after death for obituaries
        }

        // Search
        $searchResults = $this->search($name, $searchOptions);

        if (!$searchResults['success']) {
            return [
                'success' => false,
                'error' => $searchResults['error'],
                'clippings_saved' => 0,
            ];
        }

        // Save clippings
        $savedCount = 0;
        foreach ($searchResults['results'] as $result) {
            $clippingId = $this->saveClipping($treeId, $result);

            if ($clippingId) {
                // Link to person
                $this->linkClippingToPerson($clippingId, $personId);
                $savedCount++;
            }
        }

        // Log the search
        DB::insert("
            INSERT INTO genealogy_research_searches
            (tree_id, person_id, search_query, search_params, sources_searched, total_results, clippings_created)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            $treeId,
            $personId,
            $name,
            json_encode($searchOptions),
            json_encode(['Library of Congress']),
            $searchResults['total_count'] ?? 0,
            $savedCount,
        ]);

        return [
            'success' => true,
            'person_id' => $personId,
            'query' => $name,
            'total_found' => $searchResults['total_count'] ?? count($searchResults['results']),
            'clippings_saved' => $savedCount,
        ];
    }

    /**
     * Save a clipping to the database
     */
    public function saveClipping(int $treeId, array $result): ?int
    {
        // Check if already exists
        $existing = DB::selectOne("
            SELECT id FROM genealogy_newspaper_clippings
            WHERE tree_id = ? AND api_source = 'loc' AND external_id = ?
        ", [$treeId, $result['id'] ?? $result['url'] ?? null]);

        if ($existing) {
            return $existing->id;
        }

        try {
            DB::insert("
                INSERT INTO genealogy_newspaper_clippings
                (tree_id, newspaper_name, publication_date, headline, clipping_type,
                 api_source, external_id, original_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'loc', ?, ?, NOW(), NOW())
            ", [
                $treeId,
                $result['newspaper'] ?? $result['title'] ?? 'Unknown Newspaper',
                $result['date'] ?? null,
                $result['title'] ?? $result['headline'] ?? 'Untitled',
                $this->detectClippingType($result),
                $result['id'] ?? $result['url'] ?? null,
                $result['url'] ?? null,
            ]);

            return (int) DB::getPdo()->lastInsertId();

        } catch (\Exception $e) {
            Log::error('NewspaperSearchService: Failed to save clipping', [
                'error' => $e->getMessage(),
                'result' => $result,
            ]);
            return null;
        }
    }

    /**
     * Link a clipping to a person
     */
    public function linkClippingToPerson(int $clippingId, int $personId, string $relevanceType = 'mentioned', float $confidence = 0.5): bool
    {
        try {
            DB::insert("
                INSERT INTO genealogy_person_clippings
                (person_id, clipping_id, relevance_type, confidence, match_method, created_at)
                VALUES (?, ?, ?, ?, 'ai_auto', NOW())
                ON DUPLICATE KEY UPDATE confidence = VALUES(confidence)
            ", [$personId, $clippingId, $relevanceType, $confidence]);

            return true;
        } catch (\Exception $e) {
            Log::error('NewspaperSearchService: Failed to link clipping to person', [
                'error' => $e->getMessage(),
                'clipping_id' => $clippingId,
                'person_id' => $personId,
            ]);
            return false;
        }
    }

    /**
     * Get clippings for a person
     */
    public function getClippingsForPerson(int $personId): array
    {
        return DB::select("
            SELECT c.*, pc.relevance_type, pc.confidence, pc.is_verified
            FROM genealogy_newspaper_clippings c
            INNER JOIN genealogy_person_clippings pc ON c.id = pc.clipping_id
            WHERE pc.person_id = ?
            ORDER BY c.publication_date DESC
        ", [$personId]);
    }

    /**
     * Get clippings for a tree
     */
    public function getClippingsForTree(int $treeId, array $options = []): array
    {
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $type = $options['type'] ?? null;

        $sql = "SELECT * FROM genealogy_newspaper_clippings WHERE tree_id = ?";
        $params = [$treeId];

        if ($type) {
            $sql .= " AND clipping_type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY publication_date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return DB::select($sql, $params);
    }

    /**
     * Detect clipping type from content
     */
    protected function detectClippingType(array $result): string
    {
        $text = strtolower(($result['title'] ?? '') . ' ' . ($result['description'] ?? ''));

        if (preg_match('/obituar|died|death|funeral|burial|passed away|in loving memory/i', $text)) {
            return 'obituary';
        }

        if (preg_match('/born|birth|baby|infant|welcome|arrival|announce.*birth/i', $text)) {
            return 'birth';
        }

        if (preg_match('/married|marriage|wedding|bride|groom|nuptials|wed/i', $text)) {
            return 'marriage';
        }

        if (preg_match('/military|army|navy|marine|soldier|veteran|war|draft|enlisted/i', $text)) {
            return 'military';
        }

        if (preg_match('/court|judge|trial|lawsuit|sued|petition|estate|will|probate/i', $text)) {
            return 'legal';
        }

        if (preg_match('/social|party|club|meeting|visit|guest|society/i', $text)) {
            return 'social';
        }

        return 'other';
    }

    /**
     * Normalize LOC result to standard format
     */
    protected function normalizeResult(array $item): array
    {
        // LOC can return nested structures
        $title = $item['title'] ?? $item['item']['title'] ?? 'Untitled';
        if (is_array($title)) {
            $title = $title[0] ?? 'Untitled';
        }

        return [
            'id' => $item['id'] ?? null,
            'title' => $title,
            'newspaper' => $item['partof']['title'] ?? $item['newspaper'] ?? null,
            'date' => $item['date'] ?? null,
            'url' => $item['url'] ?? $item['id'] ?? null,
            'description' => $item['description'] ?? null,
            'type' => $item['original_format'] ?? ['newspaper'],
            'location' => $item['location'] ?? null,
            'lccn' => $item['partof']['lccn'] ?? null,
        ];
    }

    /**
     * Simple rate limiting
     */
    protected function rateLimit(): void
    {
        static $lastRequest = null;

        if ($lastRequest !== null) {
            $elapsed = (microtime(true) - $lastRequest) * 1000;
            if ($elapsed < $this->rateLimitMs) {
                usleep(($this->rateLimitMs - $elapsed) * 1000);
            }
        }

        $lastRequest = microtime(true);
    }
}
