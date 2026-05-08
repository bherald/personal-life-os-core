<?php

namespace App\Services\Genealogy\Providers;

/**
 * BillionGraves Provider
 *
 * FREE access to grave/headstone records with GPS coordinates.
 * Uses public search-link/manual lookup only; no undocumented API calls.
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
     * Search BillionGraves records as a public search-link helper.
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        $params = [];

        if (! empty($criteria['given_name'])) {
            $params['given_names'] = $criteria['given_name'];
        }
        if (! empty($criteria['surname'])) {
            $params['family_names'] = $criteria['surname'];
        }
        if (! empty($criteria['birth_year'])) {
            $params['birth_year'] = $criteria['birth_year'];
        }
        if (! empty($criteria['death_year'])) {
            $params['death_year'] = $criteria['death_year'];
        }
        if (! empty($criteria['country'])) {
            $params['country'] = $criteria['country'];
        }
        if (! empty($criteria['state'])) {
            $params['admin1'] = $criteria['state'];
        }
        if (! empty($criteria['city'])) {
            $params['place'] = $criteria['city'];
        }

        $params['page'] = $options['page'] ?? 1;
        $params['size'] = $options['limit'] ?? 20;

        $searchUrl = self::SEARCH_URL.'?'.http_build_query($params);

        return [
            'success' => true,
            'source' => 'BillionGraves',
            'search_url' => $searchUrl,
            'access_mode' => 'manual_public_search',
            'automation_supported' => false,
            'total_count' => 0,
            'message' => 'BillionGraves is available as a public search-link helper. Direct or undocumented API access is disabled; visit search_url manually or use approved public web-search tooling for citation discovery.',
            'results' => [],
        ];
    }

    /**
     * Get record by ID — returns metadata and URL for manual lookup.
     */
    public function getRecord(string $recordId): ?array
    {
        return [
            'id' => $recordId,
            'url' => self::BASE_URL.'/grave/'.$recordId,
            'source' => 'BillionGraves',
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

        if (! empty($options['country'])) {
            $params['country'] = $options['country'];
        }
        if (! empty($options['state'])) {
            $params['admin1'] = $options['state'];
        }

        return [
            'success' => true,
            'source' => 'BillionGraves',
            'search_url' => self::BASE_URL.'/search?'.http_build_query($params),
            'results' => [],
        ];
    }

    /**
     * Get nearby cemeteries by GPS coordinates
     */
    public function getNearbyCemeteries(float $lat, float $lng, int $radius = 10): array
    {
        return [
            'success' => true,
            'source' => 'BillionGraves',
            'coordinates' => ['lat' => $lat, 'lng' => $lng],
            'radius_km' => $radius,
            'access_mode' => 'manual_public_search',
            'automation_supported' => false,
            'message' => 'GPS-based cemetery search requires manual BillionGraves site or mobile-app lookup; direct mobile API access is disabled.',
            'results' => [],
        ];
    }
}
