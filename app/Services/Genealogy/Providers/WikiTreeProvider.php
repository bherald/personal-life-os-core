<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * WikiTree Provider
 *
 * FREE open genealogy platform — 30+ million profiles, no API key required.
 * Especially strong for US colonial-era and European ancestry.
 *
 * API Documentation: https://www.wikitree.com/wiki/WikiTree_API
 * Endpoint: https://api.wikitree.com/api.php
 *
 * - No OAuth or registration needed for public (non-living) profiles
 * - Living persons are privacy-protected and return minimal data
 * - Rate limit: ~1 request/second; 200/hour enforced via cache
 */
class WikiTreeProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'wikitree';
    protected const PROVIDER_NAME = 'WikiTree';
    protected const API_URL = 'https://api.wikitree.com/api.php';

    // Standard profile fields to retrieve
    protected const PERSON_FIELDS = 'Id,Name,FirstName,LastNameAtBirth,LastNameCurrent,'
        . 'BirthDate,BirthDateDecade,BirthLocation,DeathDate,DeathDateDecade,DeathLocation,'
        . 'Father,Mother,Gender,Privacy,IsLiving,DataStatus,Bio';

    protected array $defaultCapabilities = [
        'search_persons'  => true,
        'search_records'  => false,
        'get_record'      => false,
        'get_person'      => true,
        'get_family'      => true,
        'get_collections' => false,
        'hints'           => true,
        'attach_records'  => false,
        'dna_matches'     => false,
    ];

    public function getProviderId(): string  { return self::PROVIDER_ID; }
    public function getProviderName(): string { return self::PROVIDER_NAME; }
    public function getAuthType(): string    { return 'none'; }
    public function isConfigured(): bool     { return true; }
    public function isAuthenticated(): bool  { return true; }

    protected function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'timeout'             => 20,
            'rate_limit_per_hour' => 200,
            'cache_ttl'           => 86400, // 24h — genealogy data is stable
            'client_id'           => config('services.wikitree.client_id', 'plos-genealogy'),
        ]);
    }

    /**
     * Search WikiTree profiles by name and optional dates/places.
     *
     * @param array $criteria Keys: given_name, surname, birth_year, birth_place, death_year
     * @param array $options  Keys: limit (max 100)
     */
    public function searchPersons(array $criteria, array $options = []): array
    {
        $parts = [];
        if (!empty($criteria['given_name'])) {
            $parts[] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $parts[] = $criteria['surname'];
        }
        if (empty($parts)) {
            return ['success' => false, 'error' => 'Name is required', 'results' => []];
        }

        $query = implode(' ', $parts);
        $limit = min((int)($options['limit'] ?? 20), 100);

        $cacheKey = 'wikitree:search:' . md5($query . $limit);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->callApi([
            'action'           => 'searchPerson',
            'q'                => $query,
            'fields'           => self::PERSON_FIELDS,
            'resolveRedirects' => 1,
            'limit'            => $limit,
        ]);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError, 'results' => []];
        }

        // WikiTree returns [{status info}, {matches array}]
        $matchData = $response[1] ?? [];
        $matches   = $matchData['matches'] ?? (is_array($matchData) ? $matchData : []);

        $results = [];
        foreach ($matches as $match) {
            $personData = $match['person'] ?? $match;
            if (!is_array($personData) || empty($personData['Id'])) {
                continue;
            }

            $person = $this->mapPerson($personData);

            // Post-filter by surname if provided (fuzzy)
            if (!empty($criteria['surname'])) {
                $needle = strtolower($criteria['surname']);
                if (
                    stripos($person['surname'] ?? '', $needle) === false &&
                    stripos($person['full_name'] ?? '', $needle) === false
                ) {
                    continue;
                }
            }

            // Post-filter by birth year ±15 if provided
            if (!empty($criteria['birth_year']) && !empty($person['birth_date'])) {
                preg_match('/(\d{4})/', $person['birth_date'], $m);
                if (!empty($m[1]) && abs((int)$m[1] - (int)$criteria['birth_year']) > 15) {
                    continue;
                }
            }

            $results[] = $person;
        }

        $result = [
            'success'     => true,
            'source'      => 'WikiTree',
            'total_count' => count($results),
            'results'     => $results,
        ];

        Cache::put($cacheKey, $result, $this->config['cache_ttl']);
        return $result;
    }

    /**
     * Aliases searchPersons for interface compatibility.
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        return $this->searchPersons($criteria, $options);
    }

    /**
     * WikiTree does not support direct record retrieval by ID — use getPerson() instead.
     */
    public function getRecord(string $recordId): ?array
    {
        return null;
    }

    /**
     * Get a WikiTree profile by ID (e.g. "Smith-1" or numeric ID).
     */
    public function getPerson(string $personId): ?array
    {
        $cacheKey = 'wikitree:person:' . $personId;
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->callApi([
            'action' => 'getPerson',
            'key'    => $personId,
            'fields' => self::PERSON_FIELDS,
        ]);

        if ($response === null) {
            return null;
        }

        // Locate person data in response (structure varies slightly by API version)
        $personData = $response[1]['person']    // most common
            ?? $response[1][$personId]['person'] // keyed by id
            ?? $response['person']
            ?? null;

        if (!is_array($personData) || empty($personData['Id'])) {
            return null;
        }

        $result = $this->mapPerson($personData);
        Cache::put($cacheKey, $result, $this->config['cache_ttl']);
        return $result;
    }

    /**
     * Get a person's immediate family — parents, spouses, children, siblings.
     */
    public function getPersonFamily(string $personId): ?array
    {
        $cacheKey = 'wikitree:family:' . $personId;
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->callApi([
            'action'      => 'getRelatives',
            'keys'        => $personId,
            'getParents'  => 1,
            'getChildren' => 1,
            'getSpouses'  => 1,
            'getSiblings' => 1,
            'fields'      => self::PERSON_FIELDS,
        ]);

        if ($response === null) {
            return null;
        }

        $item = $response[1]['items'][0] ?? null;
        if (!$item) {
            return null;
        }

        $mapFn = fn($p) => is_array($p) && !empty($p['Id']) ? $this->mapPerson($p) : null;
        $filter = fn($arr) => array_values(array_filter(array_map($mapFn, $arr ?? [])));

        $result = [
            'person_id'  => $personId,
            'source'     => 'WikiTree',
            'parents'    => $filter($item['Parents']  ?? []),
            'children'   => $filter($item['Children'] ?? []),
            'spouses'    => $filter($item['Spouses']  ?? []),
            'siblings'   => $filter($item['Siblings'] ?? []),
        ];

        Cache::put($cacheKey, $result, $this->config['cache_ttl']);
        return $result;
    }

    /**
     * Traverse ancestors up to $depth generations (2=grandparents, 3=great-grandparents).
     *
     * @param string $personId WikiTree ID (e.g. "Smith-1")
     * @param int    $depth    Generations (max 5 per API limits)
     */
    public function getAncestors(string $personId, int $depth = 3): array
    {
        $depth    = min(max((int)$depth, 1), 5);
        $cacheKey = 'wikitree:ancestors:' . $personId . ':' . $depth;
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->callApi([
            'action' => 'getAncestors',
            'key'    => $personId,
            'depth'  => $depth,
            'fields' => self::PERSON_FIELDS,
        ]);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError, 'ancestors' => []];
        }

        $rawAncestors = $response[1]['ancestors'] ?? $response[1] ?? [];
        $ancestors    = [];

        foreach ($rawAncestors as $key => $person) {
            if (!is_array($person) || empty($person['Id'])) {
                continue;
            }
            $mapped             = $this->mapPerson($person);
            $mapped['ahnentafel'] = is_numeric($key) ? (int)$key : null;
            $ancestors[]        = $mapped;
        }

        $result = [
            'success'     => true,
            'source'      => 'WikiTree',
            'person_id'   => $personId,
            'depth'       => $depth,
            'total_count' => count($ancestors),
            'ancestors'   => $ancestors,
        ];

        Cache::put($cacheKey, $result, $this->config['cache_ttl']);
        return $result;
    }

    /**
     * Generate record hints — matches a genealogy person to WikiTree profiles.
     */
    public function getPersonRecordHints(array $personData, array $options = []): array
    {
        $criteria = [];
        if (!empty($personData['given_name'])) {
            $criteria['given_name'] = $personData['given_name'];
        }
        if (!empty($personData['surname'])) {
            $criteria['surname'] = $personData['surname'];
        }
        if (!empty($personData['birth_year'])) {
            $criteria['birth_year'] = $personData['birth_year'];
        } elseif (!empty($personData['birth_date'])) {
            preg_match('/(\d{4})/', $personData['birth_date'], $m);
            if (!empty($m[1])) {
                $criteria['birth_year'] = $m[1];
            }
        }

        return $this->searchPersons($criteria, ['limit' => $options['limit'] ?? 10]);
    }

    /**
     * Map a WikiTree API person object to the standard provider format.
     */
    protected function mapPerson(array $p): array
    {
        // WikiTree ID: the human-readable "Name" (e.g. "Smith-1") or numeric Id
        $wikitreeId = $p['Name'] ?? null;
        $numericId  = $p['Id']   ?? null;
        $displayId  = $wikitreeId ?? $numericId;

        $firstName = $p['FirstName']         ?? null;
        $lastName  = $p['LastNameAtBirth']   ?? $p['LastNameCurrent'] ?? null;

        return [
            'id'          => (string)$displayId,
            'external_id' => (string)$displayId,
            'given_name'  => $firstName,
            'surname'     => $lastName,
            'full_name'   => trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
            'gender'      => $this->mapGender($p['Gender'] ?? null),
            'birth_date'  => $p['BirthDate']      ?? $p['BirthDateDecade'] ?? null,
            'birth_place' => $p['BirthLocation']  ?? null,
            'death_date'  => $p['DeathDate']      ?? $p['DeathDateDecade'] ?? null,
            'death_place' => $p['DeathLocation']  ?? null,
            'living'      => (bool)($p['IsLiving']  ?? false),
            'privacy'     => $p['Privacy']          ?? null,
            'bio_snippet' => isset($p['Bio']) ? mb_substr(strip_tags((string)$p['Bio']), 0, 300) : null,
            'url'         => $wikitreeId ? 'https://www.wikitree.com/wiki/' . $wikitreeId : null,
            'source'      => 'WikiTree',
        ];
    }

    protected function mapGender(?string $gender): ?string
    {
        return match ($gender) {
            'Male'   => 'M',
            'Female' => 'F',
            default  => null,
        };
    }

    /**
     * POST to the WikiTree API and return decoded JSON, or null on failure.
     */
    protected function callApi(array $params): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout($this->config['timeout'])
                ->withHeaders([
                    'User-Agent' => 'PLOS-Genealogy/1.0 (' . $this->config['client_id'] . ')',
                    'Accept'     => 'application/json',
                ])
                ->post(self::API_URL, array_merge($params, ['format' => 'json']));

            if (!$response->successful()) {
                $this->lastError = 'WikiTree API HTTP ' . $response->status();
                Log::warning('WikiTreeProvider: HTTP error', [
                    'status' => $response->status(),
                    'action' => $params['action'] ?? '',
                ]);
                return null;
            }

            $data = $response->json();
            if (!is_array($data)) {
                $this->lastError = 'WikiTree returned non-JSON response';
                return null;
            }

            // Index [0] contains status; non-zero or non-'OK' is an error
            $status = $data[0]['status'] ?? null;
            if ($status !== null && $status !== 0 && $status !== '0' && $status !== 'OK') {
                $this->lastError = 'WikiTree API error: ' . ($data[0]['message'] ?? (string)$status);
                Log::warning('WikiTreeProvider: API error', [
                    'status'  => $status,
                    'message' => $data[0]['message'] ?? '',
                    'action'  => $params['action'] ?? '',
                ]);
                return null;
            }

            $this->logActivity('api_call', ['action' => $params['action'] ?? ''], true);
            return $data;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('WikiTreeProvider: Exception', [
                'error'  => $e->getMessage(),
                'action' => $params['action'] ?? '',
            ]);
            return null;
        }
    }
}
