<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Genealogy Research Service
 *
 * Searches external genealogy data sources (LOC, NARA, Europeana)
 * Uses RAW SQL queries - NO Eloquent models
 */
class GenealogyResearchService
{
    private string $pgsqlConnection = 'pgsql_rag';

    /**
     * Get all active genealogy research sources
     */
    public function getSources(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM research_sources WHERE research_category = ?";
        $params = ['genealogy'];

        if ($activeOnly) {
            $sql .= " AND is_active = TRUE";
        }

        $sql .= " ORDER BY trust_score DESC, estimated_records DESC";

        return DB::connection($this->pgsqlConnection)->select($sql, $params);
    }

    /**
     * Get a single source by code/name
     */
    public function getSource(string $identifier): ?object
    {
        return DB::connection($this->pgsqlConnection)->selectOne("
            SELECT * FROM research_sources
            WHERE (name ILIKE ? OR base_url ILIKE ?) AND research_category = ?
            LIMIT 1
        ", ["%{$identifier}%", "%{$identifier}%", 'genealogy']);
    }

    /**
     * Search for a person across all genealogy sources
     */
    public function searchForPerson(int $personId, array $options = []): array
    {
        // Get person data
        $person = DB::selectOne("SELECT * FROM genealogy_persons WHERE id = ?", [$personId]);

        if (!$person) {
            return ['error' => 'Person not found', 'results' => []];
        }

        // Build search query from person data
        $query = $this->buildSearchQuery($person, $options);

        // Get relevant sources
        $sources = $this->getRelevantSources($person, $options);

        $results = [];
        foreach ($sources as $source) {
            try {
                $sourceResults = $this->searchSource($source, $query, $options);
                $results[$source->name] = $sourceResults;

                // Update AI metrics
                $this->updateSourceMetrics($source->id, !empty($sourceResults['results']));
            } catch (\Exception $e) {
                Log::warning('GenealogyResearchService: Source search failed', [
                    'source' => $source->name,
                    'error' => $e->getMessage()
                ]);
                $results[$source->name] = ['error' => $e->getMessage(), 'results' => []];
                $this->updateSourceMetrics($source->id, false);
            }
        }

        return [
            'person_id' => $personId,
            'query' => $query,
            'sources_searched' => count($sources),
            'results' => $results,
        ];
    }

    /**
     * Search a specific genealogy source
     */
    public function searchSource(object $source, string $query, array $options = []): array
    {
        $cacheKey = "genealogy_research:{$source->id}:" . md5($query . json_encode($options));
        $cacheTtl = $options['cache_ttl'] ?? 3600; // 1 hour default

        return Cache::remember($cacheKey, $cacheTtl, function () use ($source, $query, $options) {
            switch ($source->name) {
                case 'Library of Congress - Chronicling America':
                    return $this->searchChroniclingAmerica($query, $options);

                case 'National Archives Catalog (NARA)':
                    return $this->searchNARA($query, $options);

                case 'Europeana Newspapers':
                    return $this->searchEuropeana($query, $options);

                default:
                    Log::warning('GenealogyResearchService: Unknown source', ['source' => $source->name]);
                    return ['error' => 'Unknown source', 'results' => []];
            }
        });
    }

    /**
     * Search Library of Congress Chronicling America
     * FREE - No API key required
     */
    public function searchChroniclingAmerica(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;

        $url = "https://www.loc.gov/collections/chronicling-america/";
        $params = [
            'fo' => 'json',
            'q' => $query,
            'c' => $limit,
        ];

        // Add date range if provided
        if (!empty($options['date_start'])) {
            $params['dates'] = $options['date_start'];
            if (!empty($options['date_end'])) {
                $params['dates'] .= '/' . $options['date_end'];
            }
        }

        Log::info('GenealogyResearchService: Searching Chronicling America', [
            'query' => $query,
            'limit' => $limit
        ]);

        try {
            $response = Http::connectTimeout(5)->timeout(30)->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $results = $data['results'] ?? [];

                return [
                    'success' => true,
                    'source' => 'Library of Congress',
                    'total_count' => $data['pagination']['total'] ?? count($results),
                    'results' => array_map(function ($item) {
                        return [
                            'title' => $item['title'] ?? $item['item']['title'] ?? 'Untitled',
                            'date' => $item['date'] ?? null,
                            'url' => $item['url'] ?? $item['id'] ?? null,
                            'description' => $item['description'] ?? null,
                            'type' => $item['original_format'] ?? ['newspaper'],
                        ];
                    }, array_slice($results, 0, $limit)),
                ];
            }

            return [
                'success' => false,
                'error' => 'LOC API request failed: ' . $response->status(),
                'results' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => []
            ];
        }
    }

    /**
     * Search National Archives Catalog (NARA)
     * FREE - API key optional (higher limits with key)
     */
    public function searchNARA(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $apiKey = config('services.nara.api_key');

        $url = "https://catalog.archives.gov/api/v2/";
        $params = [
            'q' => $query,
            'rows' => $limit,
        ];

        // Add record types filter
        if (!empty($options['record_types'])) {
            $params['resultTypes'] = implode(',', $options['record_types']);
        }

        Log::info('GenealogyResearchService: Searching NARA', [
            'query' => $query,
            'limit' => $limit,
            'has_api_key' => !empty($apiKey)
        ]);

        try {
            $request = Http::connectTimeout(5)->timeout(30);

            if ($apiKey) {
                $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
            }

            $response = $request->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $results = $data['opaResponse']['results']['result'] ?? [];

                return [
                    'success' => true,
                    'source' => 'National Archives',
                    'total_count' => $data['opaResponse']['results']['total'] ?? count($results),
                    'results' => array_map(function ($item) {
                        return [
                            'title' => $item['description']['item']['title'] ?? 'Untitled',
                            'naId' => $item['naId'] ?? null,
                            'url' => "https://catalog.archives.gov/id/" . ($item['naId'] ?? ''),
                            'description' => $item['description']['item']['scopeAndContentNote'] ?? null,
                            'type' => $item['description']['item']['generalRecordsTypeArray'] ?? [],
                            'date' => $item['description']['item']['inclusiveDates'] ?? null,
                        ];
                    }, is_array($results) ? array_slice($results, 0, $limit) : []),
                ];
            }

            return [
                'success' => false,
                'error' => 'NARA API request failed: ' . $response->status(),
                'results' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => []
            ];
        }
    }

    /**
     * Search Europeana Newspapers
     * FREE - API key required
     */
    public function searchEuropeana(string $query, array $options = []): array
    {
        $apiKey = config('services.europeana.api_key');
        $limit = $options['limit'] ?? 20;

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Europeana API key not configured. Get free key at https://pro.europeana.eu/page/apis',
                'results' => []
            ];
        }

        $url = "https://api.europeana.eu/record/v2/search.json";
        $params = [
            'wskey' => $apiKey,
            'query' => $query,
            'rows' => $limit,
            'qf' => 'TYPE:TEXT', // Filter to newspapers/text
        ];

        Log::info('GenealogyResearchService: Searching Europeana', [
            'query' => $query,
            'limit' => $limit
        ]);

        try {
            $response = Http::connectTimeout(5)->timeout(30)->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $results = $data['items'] ?? [];

                return [
                    'success' => true,
                    'source' => 'Europeana',
                    'total_count' => $data['totalResults'] ?? count($results),
                    'results' => array_map(function ($item) {
                        return [
                            'title' => is_array($item['title'] ?? null) ? ($item['title'][0] ?? 'Untitled') : ($item['title'] ?? 'Untitled'),
                            'id' => $item['id'] ?? null,
                            'url' => $item['guid'] ?? null,
                            'description' => is_array($item['dcDescription'] ?? null) ? ($item['dcDescription'][0] ?? null) : ($item['dcDescription'] ?? null),
                            'type' => $item['type'] ?? 'TEXT',
                            'date' => is_array($item['year'] ?? null) ? ($item['year'][0] ?? null) : ($item['year'] ?? null),
                            'country' => $item['country'] ?? null,
                        ];
                    }, array_slice($results, 0, $limit)),
                ];
            }

            return [
                'success' => false,
                'error' => 'Europeana API request failed: ' . $response->status(),
                'results' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => []
            ];
        }
    }

    /**
     * Build search query from person data
     */
    private function buildSearchQuery(object $person, array $options = []): string
    {
        $parts = [];

        // Name
        if (!empty($person->given_name)) {
            $parts[] = $person->given_name;
        }
        if (!empty($person->surname)) {
            $parts[] = $person->surname;
        }

        // Add location if searching for local records
        if (!empty($options['include_location']) && !empty($person->birth_place)) {
            $parts[] = $person->birth_place;
        }

        return implode(' ', $parts);
    }

    /**
     * Filter sources relevant to the person
     */
    private function getRelevantSources(object $person, array $options = []): array
    {
        $sources = $this->getSources(true);

        // If specific sources requested, filter
        if (!empty($options['sources'])) {
            $requestedSources = array_map('strtolower', $options['sources']);
            return array_filter($sources, function ($source) use ($requestedSources) {
                return in_array(strtolower($source->name), $requestedSources);
            });
        }

        // TODO: Smart filtering based on person's geographic/temporal coverage
        // For now, return all active sources
        return $sources;
    }

    /**
     * Update source AI metrics
     */
    private function updateSourceMetrics(int $sourceId, bool $success): void
    {
        $column = $success ? 'ai_success_count' : 'ai_failure_count';

        DB::connection($this->pgsqlConnection)->update("
            UPDATE research_sources
            SET {$column} = COALESCE({$column}, 0) + 1,
                ai_last_used = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ", [$sourceId]);
    }

    /**
     * Register a new research source discovered by AI
     */
    public function registerSource(array $config): int
    {
        DB::connection($this->pgsqlConnection)->insert("
            INSERT INTO research_sources (
                name, base_url, source_type, categories, trust_score,
                requires_scraping, rate_limit_per_hour, is_active,
                search_url_template, notes, discovered_by, research_category,
                auth_type, api_endpoints, geographic_coverage, temporal_coverage,
                record_types, is_free, documentation_url, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?::jsonb, ?,
                ?, ?, ?,
                ?, ?, 'ai_discovered', 'genealogy',
                ?, ?::jsonb, ?::jsonb, ?::jsonb,
                ?::jsonb, ?, ?, NOW(), NOW()
            )
        ", [
            $config['name'],
            $config['base_url'],
            $config['source_type'] ?? 'api',
            json_encode($config['categories'] ?? ['genealogy']),
            $config['trust_score'] ?? 5,
            $config['requires_scraping'] ?? false,
            $config['rate_limit_per_hour'] ?? 100,
            $config['is_active'] ?? true,
            $config['search_url_template'] ?? null,
            $config['notes'] ?? 'Auto-discovered by AI on ' . date('Y-m-d'),
            $config['auth_type'] ?? 'none',
            json_encode($config['api_endpoints'] ?? []),
            json_encode($config['geographic_coverage'] ?? []),
            json_encode($config['temporal_coverage'] ?? []),
            json_encode($config['record_types'] ?? []),
            $config['is_free'] ?? true,
            $config['documentation_url'] ?? null,
        ]);

        return (int) DB::connection($this->pgsqlConnection)->getPdo()->lastInsertId('research_sources_id_seq');
    }
}
