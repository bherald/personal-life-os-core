<?php

namespace App\Services\Genealogy\Providers;

/**
 * BillionGraves Provider
 *
 * FREE access to grave/headstone records with GPS coordinates
 * Uses web interface (no official API)
 *
 * Website: https://billiongraves.com
 *
 * Features:
 * - Cemetery search
 * - Headstone photos with GPS
 * - Transcribed inscriptions
 * - Mobile app integration for contributors
 *
 * Records: 60+ million headstone records
 */
class BillionGravesProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'billiongraves';
    protected const PROVIDER_NAME = 'BillionGraves';
    protected const BASE_URL = 'https://billiongraves.com';
    protected const SEARCH_URL = 'https://billiongraves.com/search/results';

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
        return true;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    /**
     * Search for headstone records
     */
    public function searchPersons(array $criteria, array $options = []): array
    {
        return $this->searchRecords($criteria, $options);
    }

    /**
     * Search BillionGraves records.
     *
     * Attempts their internal JSON API first. BillionGraves embeds record data
     * as JSON in search results pages (Next.js / React hydration). Falls back
     * to returning the search URL with metadata when scraping is blocked.
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        $params = [];

        if (!empty($criteria['given_name'])) {
            $params['given_names'] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $params['family_names'] = $criteria['surname'];
        }
        if (!empty($criteria['birth_year'])) {
            $params['birth_year'] = $criteria['birth_year'];
        }
        if (!empty($criteria['death_year'])) {
            $params['death_year'] = $criteria['death_year'];
        }
        if (!empty($criteria['country'])) {
            $params['country'] = $criteria['country'];
        }
        if (!empty($criteria['state'])) {
            $params['admin1'] = $criteria['state'];
        }
        if (!empty($criteria['city'])) {
            $params['place'] = $criteria['city'];
        }

        $params['page'] = $options['page'] ?? 1;
        $params['size'] = $options['limit'] ?? 20;

        $searchUrl = self::SEARCH_URL . '?' . http_build_query($params);

        // Attempt their internal XHR search endpoint (returns JSON without JS rendering)
        $apiParams = [
            'given_names'  => $params['given_names']  ?? '',
            'family_names' => $params['family_names'] ?? '',
            'birth_year'   => $params['birth_year']   ?? '',
            'death_year'   => $params['death_year']   ?? '',
            'country'      => $params['country']      ?? '',
            'admin1'       => $params['admin1']        ?? '',
            'place'        => $params['place']         ?? '',
            'page'         => $params['page'],
            'size'         => $params['size'],
        ];

        $results = $this->fetchApiResults($apiParams);

        if (!empty($results)) {
            return [
                'success'     => true,
                'source'      => 'BillionGraves',
                'search_url'  => $searchUrl,
                'total_count' => count($results),
                'results'     => $results,
            ];
        }

        // API blocked — return search URL so agent can use mcp_searxng_search
        return [
            'success'    => true,
            'source'     => 'BillionGraves',
            'search_url' => $searchUrl,
            'message'    => 'BillionGraves direct API requires authentication. Use search_url with mcp_searxng_search or visit manually.',
            'results'    => [],
        ];
    }

    /**
     * Attempt to fetch results from BillionGraves internal JSON endpoint.
     * Returns empty array when blocked (auth required or rate limited).
     */
    protected function fetchApiResults(array $params): array
    {
        try {
            // BillionGraves API endpoint (undocumented, returns JSON)
            $apiUrl = self::BASE_URL . '/api/v1/search/records';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $apiUrl . '?' . http_build_query(array_filter($params, fn($v) => $v !== '')),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                    'Referer: https://billiongraves.com/search/results',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::debug('BillionGravesProvider: transport error', ['error' => $error]);
                return [];
            }

            if ($httpCode !== 200 || !$body) {
                return [];
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return [];
            }

            // Map BillionGraves records to standard format
            $records = $data['records'] ?? $data['results'] ?? $data['data'] ?? [];
            $results = [];

            foreach ($records as $r) {
                $id = $r['id'] ?? $r['record_id'] ?? null;
                if (!$id) {
                    continue;
                }

                $results[] = [
                    'id'          => (string)$id,
                    'name'        => trim(($r['given_names'] ?? '') . ' ' . ($r['family_names'] ?? '')),
                    'given_name'  => $r['given_names']  ?? null,
                    'surname'     => $r['family_names'] ?? null,
                    'birth_year'  => $r['birth_year']   ?? null,
                    'death_year'  => $r['death_year']   ?? null,
                    'cemetery'    => $r['cemetery_name'] ?? null,
                    'location'    => ($r['place'] ?? '') . (isset($r['admin1']) ? ', ' . $r['admin1'] : '') . (isset($r['country']) ? ', ' . $r['country'] : ''),
                    'has_photo'   => !empty($r['image_url']) || !empty($r['has_photo']),
                    'gps_lat'     => $r['latitude']  ?? null,
                    'gps_lng'     => $r['longitude'] ?? null,
                    'url'         => self::BASE_URL . '/grave/' . $id,
                    'source'      => 'BillionGraves',
                ];
            }

            return $results;

        } catch (\Exception $e) {
            Log::debug('BillionGravesProvider: API fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get record by ID — returns metadata and URL for manual lookup.
     */
    public function getRecord(string $recordId): ?array
    {
        return [
            'id'      => $recordId,
            'url'     => self::BASE_URL . '/grave/' . $recordId,
            'source'  => 'BillionGraves',
            'message' => 'Visit URL to view headstone photo, GPS coordinates, and transcription.',
        ];
    }

    public function getPerson(string $personId): ?array
    {
        return $this->getRecord($personId);
    }

    /**
     * Search cemeteries
     */
    public function searchCemeteries(string $query, array $options = []): array
    {
        $params = [
            'q' => $query,
            'type' => 'cemetery',
        ];

        if (!empty($options['country'])) {
            $params['country'] = $options['country'];
        }
        if (!empty($options['state'])) {
            $params['admin1'] = $options['state'];
        }

        return [
            'success' => true,
            'source' => 'BillionGraves',
            'search_url' => self::BASE_URL . '/search?' . http_build_query($params),
            'results' => [],
        ];
    }

    /**
     * Get nearby cemeteries by GPS coordinates
     */
    public function getNearbyCemeteries(float $lat, float $lng, int $radius = 10): array
    {
        // BillionGraves has GPS data for all records
        // This would need their mobile app API or web scraping

        return [
            'success' => true,
            'source' => 'BillionGraves',
            'coordinates' => ['lat' => $lat, 'lng' => $lng],
            'radius_km' => $radius,
            'message' => 'GPS-based cemetery search requires BillionGraves mobile API integration',
            'results' => [],
        ];
    }
}
