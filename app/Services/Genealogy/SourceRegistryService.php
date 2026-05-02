<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Get prioritized sources for a person based on their attributes.
     */
    public function getSourcesForPerson(int $personId): array
    {
        try {
            $person = DB::selectOne(
                "SELECT id, birth_date, birth_place, death_date, death_place,
                        nationality, religion, primary_language
                 FROM genealogy_persons WHERE id = ?",
                [$personId]
            );

            if (!$person) {
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
     * @param array $recordTypes Filter by record types (empty = all)
     * @param string $era Era filter (empty = all)
     * @param string $region Region filter (empty = all)
     * @param string $ethnicity Ethnicity filter (default = 'default')
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
                if (!empty($recordTypes)) {
                    $sourceTypes = json_decode($source->record_types, true) ?? [];
                    if (empty(array_intersect($recordTypes, $sourceTypes))) {
                        return false;
                    }
                }

                // Era filter
                if (!empty($era) && $era !== 'unknown') {
                    $sourceEras = json_decode($source->eras, true) ?? [];
                    if (!empty($sourceEras) && !in_array('all', $sourceEras) && !in_array($era, $sourceEras)) {
                        return false;
                    }
                }

                // Region filter
                if (!empty($region) && $region !== 'unknown') {
                    $sourceRegions = json_decode($source->regions, true) ?? [];
                    if (!empty($sourceRegions) && !in_array('all', $sourceRegions) && !in_array($region, $sourceRegions)) {
                        return false;
                    }
                }

                // Ethnicity filter
                if ($ethnicity !== 'default') {
                    $sourceEthnicities = json_decode($source->ethnicities, true) ?? [];
                    if (!empty($sourceEthnicities) && !in_array('all', $sourceEthnicities)
                        && !in_array($ethnicity, $sourceEthnicities) && !in_array('default', $sourceEthnicities)) {
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
                return DB::select("
                    SELECT id, archive_name, archive_url, record_types, eras, regions,
                           ethnicities, tool_name, priority, coverage_start_year,
                           coverage_end_year, access_type, notes, search_count, hit_count,
                           last_searched_at
                    FROM genealogy_source_registry
                    WHERE is_active = 1
                    ORDER BY priority ASC, archive_name ASC
                ");
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
            DB::update("
                UPDATE genealogy_source_registry
                SET search_count = search_count + 1,
                    hit_count = hit_count + ?,
                    last_searched_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ", [$hitIncrement, $registryId]);

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
            return DB::select("
                SELECT id, archive_name, tool_name, priority, search_count, hit_count,
                       CASE WHEN search_count > 0 THEN ROUND(hit_count / search_count * 100, 1) ELSE 0 END as hit_rate_pct
                FROM genealogy_source_registry
                WHERE is_active = 1
                  AND JSON_CONTAINS(record_types, ?)
                  AND search_count >= 3
                ORDER BY (hit_count / GREATEST(search_count, 1)) DESC, priority ASC
                LIMIT ?
            ", [json_encode($recordType), $limit]);
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
            $total = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_source_registry WHERE is_active = 1");
            $withTools = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_source_registry WHERE is_active = 1 AND tool_name IS NOT NULL");
            $searched = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_source_registry WHERE search_count > 0");

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
     * Call a private method via reflection (for reusing RepositoryRoutingService inference).
     */
    private function callPrivateMethod(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }
}
