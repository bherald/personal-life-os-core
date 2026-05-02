<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Historical Maps Overlay Service (N05)
 *
 * Provides historical map overlays for genealogy visualization including:
 * - Ancestor location plotting on historical maps
 * - Migration path visualization
 * - Historical census boundary overlays
 * - Place name changes over time
 */
class HistoricalMapsService
{
    /**
     * Historical map tile sources
     */
    private const MAP_SOURCES = [
        'david_rumsey' => [
            'name' => 'David Rumsey Historical Map Collection',
            'base_url' => 'https://www.davidrumsey.com/luna/servlet/detail/',
            'api_available' => false,
        ],
        'old_maps_online' => [
            'name' => 'Old Maps Online',
            'base_url' => 'https://www.oldmapsonline.org/',
            'api_available' => true,
        ],
        'loc_maps' => [
            'name' => 'Library of Congress Maps',
            'base_url' => 'https://www.loc.gov/maps/',
            'api_available' => true,
        ],
        'nypl_maps' => [
            'name' => 'NYPL Map Warper',
            'base_url' => 'https://maps.nypl.org/warper/',
            'api_available' => true,
        ],
    ];

    /**
     * US Census boundary years
     */
    private const CENSUS_YEARS = [1790, 1800, 1810, 1820, 1830, 1840, 1850, 1860, 1870, 1880, 1890, 1900, 1910, 1920, 1930, 1940, 1950];

    /**
     * Get ancestor locations for map plotting
     *
     * @param int $treeId Tree ID
     * @param array $options Options: event_types, date_range, person_ids
     * @return array Location data for map plotting
     */
    public function getAncestorLocations(int $treeId, array $options = []): array
    {
        $eventTypes = $options['event_types'] ?? ['birth', 'death', 'marriage', 'residence', 'census'];
        $dateFrom = $options['date_from'] ?? null;
        $dateTo = $options['date_to'] ?? null;
        $personIds = $options['person_ids'] ?? null;

        // genealogy_places columns: id, name, place_type, latitude, longitude, parent_id
        // No country/state_province/county columns — place hierarchy is via parent_id chain
        $sql = "SELECT
                    gp.id as person_id,
                    gp.given_name,
                    gp.surname,
                    ge.event_type,
                    ge.event_date,
                    gpl.latitude,
                    gpl.longitude,
                    gpl.name,
                    gpl.place_type,
                    NULL AS country,
                    NULL AS state_province
                FROM genealogy_persons gp
                JOIN genealogy_events ge ON ge.person_id = gp.id
                LEFT JOIN genealogy_places gpl ON gpl.id = ge.place_id
                WHERE gp.tree_id = ?
                AND ge.event_type IN (" . implode(',', array_fill(0, count($eventTypes), '?')) . ")
                AND gpl.latitude IS NOT NULL";

        $params = [$treeId, ...$eventTypes];

        if ($dateFrom) {
            $sql .= " AND ge.event_date >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $sql .= " AND ge.event_date <= ?";
            $params[] = $dateTo;
        }

        if ($personIds) {
            $sql .= " AND gp.id IN (" . implode(',', array_fill(0, count($personIds), '?')) . ")";
            $params = array_merge($params, $personIds);
        }

        $sql .= " ORDER BY ge.event_date ASC";

        $events = DB::select($sql, $params);

        // Group by location for clustering
        $locations = [];
        foreach ($events as $event) {
            $key = $event->latitude && $event->longitude
                ? round($event->latitude, 4) . ',' . round($event->longitude, 4)
                : md5($event->name ?? 'unknown');

            if (!isset($locations[$key])) {
                $locations[$key] = [
                    'latitude' => $event->latitude,
                    'longitude' => $event->longitude,
                    'place_name' => $event->name,
                    'place_type' => $event->place_type ?? null,
                    'events' => [],
                    'persons' => [],
                ];
            }

            $locations[$key]['events'][] = [
                'person_id' => $event->person_id,
                'person_name' => trim($event->given_name . ' ' . $event->surname),
                'event_type' => $event->event_type,
                'event_date' => $event->event_date,
            ];

            $locations[$key]['persons'][$event->person_id] = trim($event->given_name . ' ' . $event->surname);
        }

        // Convert to indexed array
        return array_values($locations);
    }

    /**
     * Generate migration paths for a person or family line
     *
     * @param int $personId Starting person ID
     * @param string $direction 'ancestors' or 'descendants'
     * @param int $generations Number of generations to trace
     * @return array Migration path data
     */
    public function generateMigrationPath(int $personId, string $direction = 'ancestors', int $generations = 5): array
    {
        $paths = [];
        $visited = [];

        $this->traceMigration($personId, $direction, $generations, 0, $paths, $visited);

        // Sort paths by date
        usort($paths, function ($a, $b) {
            return strcmp($a['from_date'] ?? '9999', $b['from_date'] ?? '9999');
        });

        // Build connected path segments
        $segments = [];
        foreach ($paths as $path) {
            if ($path['from_lat'] && $path['from_lng'] && $path['to_lat'] && $path['to_lng']) {
                $segments[] = [
                    'from' => [
                        'lat' => $path['from_lat'],
                        'lng' => $path['from_lng'],
                        'place' => $path['from_place'],
                        'date' => $path['from_date'],
                    ],
                    'to' => [
                        'lat' => $path['to_lat'],
                        'lng' => $path['to_lng'],
                        'place' => $path['to_place'],
                        'date' => $path['to_date'],
                    ],
                    'person_id' => $path['person_id'],
                    'person_name' => $path['person_name'],
                    'generation' => $path['generation'],
                    'migration_type' => $this->classifyMigration($path),
                ];
            }
        }

        return [
            'person_id' => $personId,
            'direction' => $direction,
            'generations' => $generations,
            'segments' => $segments,
            'summary' => $this->summarizeMigration($segments),
        ];
    }

    /**
     * Recursive migration tracing helper
     */
    private function traceMigration(int $personId, string $direction, int $maxGen, int $currentGen, array &$paths, array &$visited): void
    {
        if ($currentGen > $maxGen || isset($visited[$personId])) {
            return;
        }
        $visited[$personId] = true;

        // Get person's life events with locations
        $events = DB::select(
            "SELECT ge.*, gpl.latitude, gpl.longitude, gpl.name,
                    gp.given_name, gp.surname
             FROM genealogy_events ge
             JOIN genealogy_persons gp ON gp.id = ge.person_id
             LEFT JOIN genealogy_places gpl ON gpl.id = ge.place_id
             WHERE ge.person_id = ?
             AND ge.event_type IN ('birth', 'death', 'marriage', 'residence', 'census', 'immigration', 'emigration')
             ORDER BY ge.event_date ASC",
            [$personId]
        );

        // Track location changes for this person
        $prevEvent = null;
        foreach ($events as $event) {
            if ($prevEvent && $event->latitude && $prevEvent->latitude) {
                // Calculate distance to determine if this is a significant move
                $distance = $this->calculateDistance(
                    $prevEvent->latitude, $prevEvent->longitude,
                    $event->latitude, $event->longitude
                );

                if ($distance > 50) { // More than 50 km
                    $paths[] = [
                        'person_id' => $personId,
                        'person_name' => trim($event->given_name . ' ' . $event->surname),
                        'generation' => $currentGen,
                        'from_lat' => $prevEvent->latitude,
                        'from_lng' => $prevEvent->longitude,
                        'from_place' => $prevEvent->name ?? null,
                        'from_date' => $prevEvent->event_date,
                        'from_event' => $prevEvent->event_type,
                        'to_lat' => $event->latitude,
                        'to_lng' => $event->longitude,
                        'to_place' => $event->name ?? null,
                        'to_date' => $event->event_date,
                        'to_event' => $event->event_type,
                        'distance_km' => round($distance, 1),
                    ];
                }
            }
            $prevEvent = $event;
        }

        // Recurse to ancestors or descendants
        if ($direction === 'ancestors') {
            $parents = DB::select(
                "SELECT gf.husband_id, gf.wife_id
                 FROM genealogy_families gf
                 JOIN genealogy_children gfc ON gfc.family_id = gf.id
                 WHERE gfc.person_id = ?",
                [$personId]
            );

            foreach ($parents as $parent) {
                if ($parent->husband_id) {
                    $this->traceMigration($parent->husband_id, $direction, $maxGen, $currentGen + 1, $paths, $visited);
                }
                if ($parent->wife_id) {
                    $this->traceMigration($parent->wife_id, $direction, $maxGen, $currentGen + 1, $paths, $visited);
                }
            }
        } else {
            // Descendants
            $children = DB::select(
                "SELECT gfc.person_id
                 FROM genealogy_children gfc
                 JOIN genealogy_families gf ON gf.id = gfc.family_id
                 WHERE gf.husband_id = ? OR gf.wife_id = ?",
                [$personId, $personId]
            );

            foreach ($children as $child) {
                $this->traceMigration($child->person_id, $direction, $maxGen, $currentGen + 1, $paths, $visited);
            }
        }
    }

    /**
     * Calculate distance between two coordinates (Haversine formula)
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Classify migration type based on path data
     */
    private function classifyMigration(array $path): string
    {
        $fromEvent = $path['from_event'] ?? '';
        $toEvent = $path['to_event'] ?? '';
        $distance = $path['distance_km'] ?? 0;

        if ($toEvent === 'immigration' || $fromEvent === 'emigration') {
            return 'international';
        }

        if ($distance > 500) {
            return 'long_distance';
        }

        if ($distance > 100) {
            return 'regional';
        }

        return 'local';
    }

    /**
     * Summarize migration patterns
     */
    private function summarizeMigration(array $segments): array
    {
        if (empty($segments)) {
            return ['total_moves' => 0];
        }

        $totalDistance = array_sum(array_column($segments, 'distance_km') ?? []);
        $countries = [];
        $migrationTypes = [];

        foreach ($segments as $segment) {
            $migrationTypes[$segment['migration_type']] = ($migrationTypes[$segment['migration_type']] ?? 0) + 1;
        }

        return [
            'total_moves' => count($segments),
            'total_distance_km' => round($totalDistance, 1),
            'migration_types' => $migrationTypes,
            'earliest_date' => $segments[0]['from']['date'] ?? null,
            'latest_date' => end($segments)['to']['date'] ?? null,
        ];
    }

    /**
     * Get historical census boundaries for a specific year
     *
     * @param int $year Census year
     * @param string $country Country code (US, UK, etc.)
     * @param string|null $state State/province to filter
     * @return array GeoJSON boundary data
     */
    public function getCensusBoundaries(int $year, string $country = 'US', ?string $state = null): array
    {
        // Find nearest census year
        $censusYear = $this->findNearestCensusYear($year, $country);

        $cacheKey = "census_boundaries_{$country}_{$censusYear}" . ($state ? "_{$state}" : '');

        return Cache::remember($cacheKey, 86400, function () use ($censusYear, $country, $state) {
            // Check for stored boundaries
            $sql = "SELECT boundary_name, boundary_type, boundary_level, geojson_data
                    FROM genealogy_historical_boundaries
                    WHERE census_year = ? AND country = ?";
            $params = [$censusYear, $country];

            if ($state) {
                $sql .= " AND (state_code = ? OR boundary_name LIKE ?)";
                $params[] = $state;
                $params[] = "%{$state}%";
            }

            $boundaries = DB::select($sql, $params);

            if (empty($boundaries)) {
                // Return metadata for external boundary lookup
                return [
                    'census_year' => $censusYear,
                    'country' => $country,
                    'state' => $state,
                    'source' => 'nhgis',
                    'external_url' => $this->getBoundarySourceUrl($censusYear, $country),
                    'boundaries' => [],
                ];
            }

            return [
                'census_year' => $censusYear,
                'country' => $country,
                'state' => $state,
                'boundary_count' => count($boundaries),
                'boundaries' => array_map(fn($b) => [
                    'name' => $b->boundary_name,
                    'type' => $b->boundary_type,
                    'level' => $b->boundary_level,
                    'geojson' => json_decode($b->geojson_data, true),
                ], $boundaries),
            ];
        });
    }

    /**
     * Find nearest available census year
     */
    private function findNearestCensusYear(int $year, string $country): int
    {
        $censusYears = self::CENSUS_YEARS;

        $closest = $censusYears[0];
        $minDiff = abs($year - $closest);

        foreach ($censusYears as $censusYear) {
            $diff = abs($year - $censusYear);
            if ($diff < $minDiff && $censusYear <= $year) {
                $minDiff = $diff;
                $closest = $censusYear;
            }
        }

        return $closest;
    }

    /**
     * Get URL for external boundary data source
     */
    private function getBoundarySourceUrl(int $year, string $country): string
    {
        if ($country === 'US') {
            return "https://data2.nhgis.org/main#datasets-filter=years:{$year}";
        }
        return "https://www.visionofbritain.org.uk/data/" . ($country === 'UK' ? 'boundaries' : '');
    }

    /**
     * Get place name history showing how names changed over time
     *
     * @param string $currentName Current place name
     * @param string|null $country Country to search in
     * @return array Historical name variants with dates
     */
    public function getPlaceNameHistory(string $currentName, ?string $country = null): array
    {
        $sql = "SELECT
                    gph.place_id,
                    gph.historical_name,
                    gph.name_type,
                    gph.valid_from,
                    gph.valid_to,
                    gph.source,
                    gpl.name as current_name,
                    NULL AS country
                FROM genealogy_place_history gph
                JOIN genealogy_places gpl ON gpl.id = gph.place_id
                WHERE (gpl.name LIKE ? OR gph.historical_name LIKE ?)";

        $params = ["%{$currentName}%", "%{$currentName}%"];

        if ($country) {
            $sql .= " AND gpl.name LIKE ?";
            $params[] = "%{$country}%";
        }

        $sql .= " ORDER BY gph.valid_from ASC";

        $history = DB::select($sql, $params);

        // Group by place
        $places = [];
        foreach ($history as $record) {
            $placeId = $record->place_id;
            if (!isset($places[$placeId])) {
                $places[$placeId] = [
                    'current_name' => $record->current_name,
                    'country' => $record->country,
                    'name_history' => [],
                ];
            }

            $places[$placeId]['name_history'][] = [
                'name' => $record->historical_name,
                'type' => $record->name_type,
                'from' => $record->valid_from,
                'to' => $record->valid_to,
                'source' => $record->source,
            ];
        }

        return array_values($places);
    }

    /**
     * Find historical map overlays for a specific region and time period
     *
     * @param float $latitude Center latitude
     * @param float $longitude Center longitude
     * @param int $year Year to find maps for
     * @param int $radiusKm Search radius in km
     * @return array Available historical maps
     */
    public function findHistoricalMaps(float $latitude, float $longitude, int $year, int $radiusKm = 50): array
    {
        $maps = [];

        // Check local map registry
        $localMaps = DB::select(
            "SELECT * FROM genealogy_historical_maps
             WHERE map_year BETWEEN ? AND ?
             AND ST_Distance_Sphere(
                 POINT(center_longitude, center_latitude),
                 POINT(?, ?)
             ) <= ?
             ORDER BY ABS(map_year - ?) ASC
             LIMIT 20",
            [$year - 20, $year + 20, $longitude, $latitude, $radiusKm * 1000, $year]
        );

        foreach ($localMaps as $map) {
            $maps[] = [
                'id' => $map->id,
                'title' => $map->title,
                'year' => $map->map_year,
                'source' => $map->source,
                'tile_url' => $map->tile_url,
                'bounds' => json_decode($map->bounds, true),
                'attribution' => $map->attribution,
                'type' => 'local',
            ];
        }

        // Add suggestions from external sources
        foreach (self::MAP_SOURCES as $sourceKey => $source) {
            if ($source['api_available']) {
                $maps[] = [
                    'source_key' => $sourceKey,
                    'source_name' => $source['name'],
                    'search_url' => $source['base_url'] . "?lat={$latitude}&lng={$longitude}&year={$year}",
                    'type' => 'external_suggestion',
                ];
            }
        }

        return $maps;
    }

    /**
     * Store a historical map reference
     *
     * @param array $mapData Map data
     * @return int Map ID
     */
    public function storeHistoricalMap(array $mapData): int
    {
        DB::insert(
            "INSERT INTO genealogy_historical_maps
             (title, map_year, source, source_url, tile_url, bounds, center_latitude, center_longitude, attribution, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $mapData['title'],
                $mapData['year'],
                $mapData['source'],
                $mapData['source_url'] ?? null,
                $mapData['tile_url'] ?? null,
                isset($mapData['bounds']) ? json_encode($mapData['bounds']) : null,
                $mapData['center_latitude'] ?? null,
                $mapData['center_longitude'] ?? null,
                $mapData['attribution'] ?? null,
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get map data formatted for Leaflet/MapLibre visualization
     *
     * @param int $treeId Tree ID
     * @param array $options Visualization options
     * @return array Map visualization data
     */
    public function getMapVisualizationData(int $treeId, array $options = []): array
    {
        $locations = $this->getAncestorLocations($treeId, $options);

        // Convert to GeoJSON FeatureCollection
        $features = [];

        foreach ($locations as $location) {
            if ($location['latitude'] && $location['longitude']) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $location['longitude'], (float) $location['latitude']],
                    ],
                    'properties' => [
                        'place_name' => $location['place_name'],
                        'country' => $location['country'],
                        'state_province' => $location['state_province'],
                        'event_count' => count($location['events']),
                        'person_count' => count($location['persons']),
                        'events' => $location['events'],
                        'persons' => array_values($location['persons']),
                    ],
                ];
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'tree_id' => $treeId,
                'location_count' => count($features),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Get statistics about geographic distribution of ancestors
     *
     * @param int $treeId Tree ID
     * @return array Geographic statistics
     */
    public function getGeographicStatistics(int $treeId): array
    {
        $byCountry = DB::select(
            "SELECT gpl.name AS country, COUNT(DISTINCT gp.id) as person_count
             FROM genealogy_persons gp
             JOIN genealogy_events ge ON ge.person_id = gp.id
             JOIN genealogy_places gpl ON gpl.id = ge.place_id
             WHERE gp.tree_id = ? AND gpl.place_type = 'country'
             GROUP BY gpl.name
             ORDER BY person_count DESC",
            [$treeId]
        );

        $byState = DB::select(
            "SELECT parent_pl.name AS country, gpl.name AS state_province, COUNT(DISTINCT gp.id) as person_count
             FROM genealogy_persons gp
             JOIN genealogy_events ge ON ge.person_id = gp.id
             JOIN genealogy_places gpl ON gpl.id = ge.place_id
             LEFT JOIN genealogy_places parent_pl ON parent_pl.id = gpl.parent_id
             WHERE gp.tree_id = ? AND gpl.place_type IN ('state', 'province')
             GROUP BY parent_pl.name, gpl.name
             ORDER BY person_count DESC
             LIMIT 20",
            [$treeId]
        );

        $withCoordinates = DB::selectOne(
            "SELECT
                COUNT(DISTINCT gp.id) as with_coords,
                (SELECT COUNT(*) FROM genealogy_persons WHERE tree_id = ?) as total
             FROM genealogy_persons gp
             JOIN genealogy_events ge ON ge.person_id = gp.id
             JOIN genealogy_places gpl ON gpl.id = ge.place_id
             WHERE gp.tree_id = ? AND gpl.latitude IS NOT NULL",
            [$treeId, $treeId]
        );

        return [
            'by_country' => array_map(fn($r) => ['country' => $r->country, 'count' => $r->person_count], $byCountry),
            'by_state' => array_map(fn($r) => [
                'country' => $r->country,
                'state' => $r->state_province,
                'count' => $r->person_count,
            ], $byState),
            'geocoding_coverage' => [
                'with_coordinates' => $withCoordinates->with_coords ?? 0,
                'total_persons' => $withCoordinates->total ?? 0,
                'percent' => $withCoordinates->total > 0
                    ? round(($withCoordinates->with_coords / $withCoordinates->total) * 100, 1)
                    : 0,
            ],
        ];
    }
}
