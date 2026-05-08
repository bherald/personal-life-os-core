<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GEN-1: Source Registry for Intelligent Tool Selection
 *
 * DB-driven registry mapping record types → archives → tools with era/geographic
 * coverage. Replaces hardcoded RepositoryRoutingService matrix.
 *
 * Query patterns:
 *   - getSourcesForPerson(personId) — full routing for a person
 *   - getSourcesForCriteria(recordTypes, era, region, ethnicity) — direct query
 *   - recordSearchResult(registryId, hit) — track success metrics
 */
class SourceRegistryService
{
    private const CACHE_KEY = 'genealogy_source_registry';

    private const CACHE_TTL = 3600; // 1 hour

    private const APPROVED_PUBLIC_TOOLS = [
        'dar_search' => true,
        'ellis_island_search' => true,
        'freedmens_bureau_search' => true,
        'german_church_records_search' => true,
        'nara_search' => true,
        'newspaper_search' => true,
        'newspaper_search_obituaries' => true,
        'wikitree_search' => true,
    ];

    /**
     * Get prioritized sources for a person based on their attributes.
     */
    public function getSourcesForPerson(int $personId): array
    {
        try {
            $person = DB::selectOne(
                'SELECT id, birth_date, birth_place, death_date, death_place,
                        nationality, religion, primary_language
                 FROM genealogy_persons WHERE id = ?',
                [$personId]
            );

            if (! $person) {
                return ['error' => 'Person not found', 'sources' => []];
            }

            $routing = app(RepositoryRoutingService::class);
            $era = $this->callPrivateMethod($routing, 'inferEra', [$person->birth_date, $person->death_date]);
            $region = $this->callPrivateMethod($routing, 'inferRegion', [$person->birth_place ?? $person->death_place ?? '']);
            $ethnicity = $this->callPrivateMethod($routing, 'inferEthnicity', [
                $person->nationality ?? '', $person->religion ?? '',
                $person->primary_language ?? '', $era, $region,
            ]);

            $sources = $this->getSourcesForCriteria([], $era, $region, $ethnicity);

            return [
                'person_id' => $personId,
                'era' => $era,
                'region' => $region,
                'ethnicity' => $ethnicity,
                'sources' => $sources,
                'total' => count($sources),
            ];
        } catch (\Exception $e) {
            Log::error('SourceRegistryService: Failed to get sources for person', [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage(), 'sources' => []];
        }
    }

    /**
     * Get sources matching specific criteria.
     *
     * @param  array  $recordTypes  Filter by record types (empty = all)
     * @param  string  $era  Era filter (empty = all)
     * @param  string  $region  Region filter (empty = all)
     * @param  string  $ethnicity  Ethnicity filter (default = 'default')
     * @return array Prioritized list of matching sources
     */
    public function getSourcesForCriteria(
        array $recordTypes = [],
        string $era = '',
        string $region = '',
        string $ethnicity = 'default'
    ): array {
        try {
            $all = $this->getAllSources();

            return array_values(array_filter($all, function ($source) use ($recordTypes, $era, $region, $ethnicity) {
                // Record type filter
                if (! empty($recordTypes)) {
                    $sourceTypes = json_decode($source->record_types, true) ?? [];
                    if (empty(array_intersect($recordTypes, $sourceTypes))) {
                        return false;
                    }
                }

                // Era filter
                if (! empty($era) && $era !== 'unknown') {
                    $sourceEras = json_decode($source->eras, true) ?? [];
                    if (! empty($sourceEras) && ! in_array('all', $sourceEras) && ! in_array($era, $sourceEras)) {
                        return false;
                    }
                }

                // Region filter
                if (! empty($region) && $region !== 'unknown') {
                    $sourceRegions = json_decode($source->regions, true) ?? [];
                    if (! empty($sourceRegions) && ! in_array('all', $sourceRegions) && ! in_array($region, $sourceRegions)) {
                        return false;
                    }
                }

                // Ethnicity filter
                if ($ethnicity !== 'default') {
                    $sourceEthnicities = json_decode($source->ethnicities, true) ?? [];
                    if (! empty($sourceEthnicities) && ! in_array('all', $sourceEthnicities)
                        && ! in_array($ethnicity, $sourceEthnicities) && ! in_array('default', $sourceEthnicities)) {
                        return false;
                    }
                }

                return true;
            }));
        } catch (\Exception $e) {
            Log::error('SourceRegistryService: Criteria query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get all active sources, cached.
     */
    public function getAllSources(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return DB::select('
                    SELECT id, archive_name, archive_url, record_types, eras, regions,
                           ethnicities, tool_name, priority, coverage_start_year,
                           coverage_end_year, access_type, notes, search_count, hit_count,
                           last_searched_at
                    FROM genealogy_source_registry
                    WHERE is_active = 1
                    ORDER BY priority ASC, archive_name ASC
                ');
            });
        } catch (\Exception $e) {
            Log::warning('SourceRegistryService: Failed to load sources', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Record a search result for tracking success metrics.
     */
    public function recordSearchResult(int $registryId, bool $hit): void
    {
        try {
            $hitIncrement = $hit ? 1 : 0;
            DB::update('
                UPDATE genealogy_source_registry
                SET search_count = search_count + 1,
                    hit_count = hit_count + ?,
                    last_searched_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ', [$hitIncrement, $registryId]);

            Cache::forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            Log::warning('SourceRegistryService: Failed to record search result', [
                'registry_id' => $registryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get sources with the highest hit rate for a given record type.
     */
    public function getTopSourcesForRecordType(string $recordType, int $limit = 5): array
    {
        try {
            return DB::select('
                SELECT id, archive_name, tool_name, priority, search_count, hit_count,
                       CASE WHEN search_count > 0 THEN ROUND(hit_count / search_count * 100, 1) ELSE 0 END as hit_rate_pct
                FROM genealogy_source_registry
                WHERE is_active = 1
                  AND JSON_CONTAINS(record_types, ?)
                  AND search_count >= 3
                ORDER BY (hit_count / GREATEST(search_count, 1)) DESC, priority ASC
                LIMIT ?
            ', [json_encode($recordType), $limit]);
        } catch (\Exception $e) {
            Log::warning('SourceRegistryService: Top sources query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get registry statistics.
     */
    public function getStatistics(): array
    {
        try {
            $total = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_source_registry WHERE is_active = 1');
            $withTools = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_source_registry WHERE is_active = 1 AND tool_name IS NOT NULL');
            $searched = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_source_registry WHERE search_count > 0');

            return [
                'total_sources' => $total->count ?? 0,
                'with_tools' => $withTools->count ?? 0,
                'searched' => $searched->count ?? 0,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Validate active registry rows against the current public/manual source posture.
     *
     * This is intentionally read-only: manual-only sources remain visible as
     * operator research targets, but they must not advertise an automated tool.
     *
     * @return array{valid: bool, summary: array{checked: int, errors: int}, errors: array<int, array<string, mixed>>}
     */
    public function validatePublicSourcePosture(): array
    {
        $rows = DB::select('
            SELECT id, archive_name, archive_url, tool_name
            FROM genealogy_source_registry
            WHERE is_active = 1
            ORDER BY archive_name ASC, id ASC
        ');

        $errors = [];
        foreach ($rows as $row) {
            $toolName = $this->nullableString($row->tool_name ?? null);
            $url = $this->nullableString($row->archive_url ?? null);
            $domain = $this->locatorHost($url ?? '');

            $urlIssue = $this->archiveUrlIssue($url);
            if ($urlIssue !== null) {
                $errors[] = $this->postureError($row, $domain, $toolName, $urlIssue);

                continue;
            }

            if ($url === null || $domain === null) {
                $errors[] = $this->postureError($row, $domain, $toolName, 'archive_url_missing_or_invalid');

                continue;
            }

            if ($this->isNonPublicArchiveHost($domain)) {
                $errors[] = $this->postureError($row, $domain, $toolName, 'non_public_archive_url');

                continue;
            }

            if ($toolName !== null && $this->isManualOnlyHost($domain)) {
                $errors[] = $this->postureError($row, $domain, $toolName, 'manual_only_domain_has_tool');

                continue;
            }

            if ($toolName !== null && ! isset(self::APPROVED_PUBLIC_TOOLS[$toolName])) {
                $errors[] = $this->postureError($row, $domain, $toolName, 'unapproved_public_tool');
            }
        }

        return [
            'valid' => $errors === [],
            'summary' => [
                'checked' => count($rows),
                'errors' => count($errors),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Call a private method via reflection (for reusing RepositoryRoutingService inference).
     */
    private function callPrivateMethod(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invoke($obj, ...$args);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function locatorHost(string $locator): ?string
    {
        if ($locator === '') {
            return null;
        }

        $host = parse_url($locator, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:[\/?#]|$)/i', $locator) !== 1) {
                return null;
            }

            $host = parse_url('https://'.$locator, PHP_URL_HOST);
        }

        return is_string($host) && trim($host) !== '' ? strtolower(trim($host)) : null;
    }

    private function isManualOnlyHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        foreach ((array) config('scraping.manual_only_domains', []) as $domain) {
            $domain = $this->nullableString($domain);
            if ($domain === null) {
                continue;
            }

            $domain = strtolower($domain);
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function isNonPublicArchiveHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, "[] \t\n\r\0\x0B."));
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        foreach (['localhost', 'test', 'example', 'invalid'] as $reservedTld) {
            if ($host === $reservedTld || str_ends_with($host, '.'.$reservedTld)) {
                return true;
            }
        }

        foreach (['example.com', 'example.net', 'example.org'] as $reservedDomain) {
            if ($host === $reservedDomain || str_ends_with($host, '.'.$reservedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function archiveUrlIssue(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return 'archive_url_missing_or_invalid';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && trim($scheme) !== '') {
            $scheme = strtolower(trim($scheme));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return 'archive_url_unsupported_scheme';
            }
        }

        if ($this->archiveUrlHasUserinfo($url)) {
            return 'archive_url_contains_credentials';
        }

        return null;
    }

    private function archiveUrlHasUserinfo(string $url): bool
    {
        foreach ([PHP_URL_USER, PHP_URL_PASS] as $component) {
            $value = parse_url($url, $component);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function postureError(object $row, ?string $domain, ?string $toolName, string $code): array
    {
        $archiveUrl = $this->nullableString($row->archive_url ?? null);
        if (is_string($archiveUrl) && $this->archiveUrlHasUserinfo($archiveUrl)) {
            $archiveUrl = null;
        }

        return [
            'id' => (int) $row->id,
            'archive_name' => (string) ($row->archive_name ?? ''),
            'archive_url' => $archiveUrl,
            'domain' => $domain,
            'tool_name' => $toolName,
            'code' => $code,
        ];
    }
}
