<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Place Authority Service
 *
 * Manages normalized place names with hierarchy resolution.
 * Supports place name variants, historical boundaries, and geocoding.
 * Links to genealogy_events.place for consistent place references.
 */
class PlaceAuthorityService
{
    /**
     * Common US state abbreviations
     */
    private const STATE_ABBREVIATIONS = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
    ];

    /**
     * Common country name variants
     */
    private const COUNTRY_VARIANTS = [
        'usa' => 'United States',
        'us' => 'United States',
        'u.s.' => 'United States',
        'u.s.a.' => 'United States',
        'america' => 'United States',
        'uk' => 'United Kingdom',
        'england' => 'England',
        'great britain' => 'United Kingdom',
        'deutschland' => 'Germany',
    ];

    /**
     * Find or create a place in the authority database
     *
     * @param string $placeString Original place string (e.g., "Philadelphia, PA, USA")
     * @param array $options Additional options:
     *   - latitude: float
     *   - longitude: float
     *   - create_hierarchy: bool (default true) - Auto-create parent places
     * @return int|null Place ID or null if parsing fails
     */
    public function findOrCreatePlace(string $placeString, array $options = []): ?int
    {
        $placeString = trim($placeString);
        if (empty($placeString)) {
            return null;
        }

        $normalized = $this->normalizePlaceName($placeString);

        // Try exact match first
        $existing = DB::selectOne("
            SELECT id FROM genealogy_places WHERE normalized_name = ?
        ", [$normalized]);

        if ($existing) {
            return $existing->id;
        }

        // Try alias match
        $aliasMatch = DB::selectOne("
            SELECT place_id FROM genealogy_place_aliases WHERE normalized_alias = ?
        ", [$normalized]);

        if ($aliasMatch) {
            return $aliasMatch->place_id;
        }

        // Parse the place string into hierarchy
        $hierarchy = $this->parsePlaceHierarchy($placeString);
        if (empty($hierarchy)) {
            return null;
        }

        // Create the place with hierarchy if requested
        $createHierarchy = $options['create_hierarchy'] ?? true;

        return $this->createPlaceWithHierarchy($hierarchy, $options, $createHierarchy);
    }

    /**
     * Get place with full hierarchy path
     *
     * @param int $placeId Place ID
     * @return array|null Place data with ancestors
     */
    public function getPlaceWithHierarchy(int $placeId): ?array
    {
        $place = DB::selectOne("
            SELECT * FROM genealogy_places WHERE id = ?
        ", [$placeId]);

        if (!$place) {
            return null;
        }

        // Build hierarchy path
        $ancestors = [];
        $currentId = $place->parent_id;
        $depth = 0;
        $maxDepth = 10; // Prevent infinite loops

        while ($currentId && $depth < $maxDepth) {
            $ancestor = DB::selectOne("
                SELECT * FROM genealogy_places WHERE id = ?
            ", [$currentId]);

            if (!$ancestor) break;

            $ancestors[] = $ancestor;
            $currentId = $ancestor->parent_id;
            $depth++;
        }

        // Get aliases
        $aliases = DB::select("
            SELECT alias, alias_type FROM genealogy_place_aliases WHERE place_id = ?
        ", [$placeId]);

        return [
            'place' => $place,
            'ancestors' => array_reverse($ancestors),
            'aliases' => $aliases,
            'full_path' => $this->buildFullPath($place, $ancestors),
        ];
    }

    /**
     * Search places by name
     *
     * @param string $query Search query
     * @param array $options Search options:
     *   - place_type: string - Filter by type
     *   - limit: int (default 20)
     * @return array Matching places
     */
    public function searchPlaces(string $query, array $options = []): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        $limit = min($options['limit'] ?? 20, 100);
        $normalized = $this->normalizePlaceName($query);

        $params = [];

        $sql = "
            SELECT DISTINCT p.*,
                   (SELECT COUNT(*) FROM genealogy_events WHERE place_id = p.id) +
                   (SELECT COUNT(*) FROM genealogy_family_events WHERE place_id = p.id) as usage_count
            FROM genealogy_places p
            LEFT JOIN genealogy_place_aliases a ON a.place_id = p.id
            WHERE p.normalized_name LIKE ?
               OR a.normalized_alias LIKE ?
               OR MATCH(p.name, p.short_name) AGAINST(? IN NATURAL LANGUAGE MODE)
        ";
        $params[] = "%{$normalized}%";
        $params[] = "%{$normalized}%";
        $params[] = $query;

        if (!empty($options['place_type'])) {
            $sql .= " AND p.place_type = ?";
            $params[] = $options['place_type'];
        }

        $sql .= " ORDER BY usage_count DESC, p.name ASC LIMIT ?";
        $params[] = $limit;

        return DB::select($sql, $params);
    }

    /**
     * Add an alias for a place
     *
     * @param int $placeId Place ID
     * @param string $alias Alias name
     * @param string $aliasType Type: spelling, historical, abbreviation, translation, common
     * @return bool Success
     */
    public function addAlias(int $placeId, string $alias, string $aliasType = 'spelling'): bool
    {
        $alias = trim($alias);
        if (empty($alias)) {
            return false;
        }

        $normalized = $this->normalizePlaceName($alias);

        // Check if alias already exists for this place
        $existing = DB::selectOne("
            SELECT id FROM genealogy_place_aliases
            WHERE place_id = ? AND normalized_alias = ?
        ", [$placeId, $normalized]);

        if ($existing) {
            return true; // Already exists
        }

        DB::insert("
            INSERT INTO genealogy_place_aliases (place_id, alias, normalized_alias, alias_type)
            VALUES (?, ?, ?, ?)
        ", [$placeId, $alias, $normalized, $aliasType]);

        return true;
    }

    /**
     * Link an event to a place
     *
     * @param int $eventId Event ID
     * @param int $placeId Place ID
     * @param bool $isFamily True for family events, false for person events
     * @return bool Success
     */
    public function linkEventToPlace(int $eventId, int $placeId, bool $isFamily = false): bool
    {
        $table = $isFamily ? 'genealogy_family_events' : 'genealogy_events';

        return DB::update("
            UPDATE {$table} SET place_id = ? WHERE id = ?
        ", [$placeId, $eventId]) > 0;
    }

    /**
     * Backfill place_id for existing events
     *
     * @param int $limit Max events to process
     * @return array Results summary
     */
    public function backfillEventPlaces(int $limit = 1000): array
    {
        $results = [
            'processed' => 0,
            'linked' => 0,
            'failed' => 0,
        ];

        // Process person events
        $personEvents = DB::select("
            SELECT id, event_place
            FROM genealogy_events
            WHERE event_place IS NOT NULL
              AND event_place != ''
              AND place_id IS NULL
            LIMIT ?
        ", [$limit]);

        foreach ($personEvents as $event) {
            $results['processed']++;

            $placeId = $this->findOrCreatePlace($event->event_place);
            if ($placeId) {
                $this->linkEventToPlace($event->id, $placeId, false);
                $results['linked']++;
            } else {
                $results['failed']++;
            }
        }

        // Process family events
        $remaining = $limit - $results['processed'];
        if ($remaining > 0) {
            $familyEvents = DB::select("
                SELECT id, event_place
                FROM genealogy_family_events
                WHERE event_place IS NOT NULL
                  AND event_place != ''
                  AND place_id IS NULL
                LIMIT ?
            ", [$remaining]);

            foreach ($familyEvents as $event) {
                $results['processed']++;

                $placeId = $this->findOrCreatePlace($event->event_place);
                if ($placeId) {
                    $this->linkEventToPlace($event->id, $placeId, true);
                    $results['linked']++;
                } else {
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * Get statistics about place usage
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        $totalPlaces = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_places")->cnt;
        $linkedPersonEvents = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_events WHERE place_id IS NOT NULL")->cnt;
        $unlinkedPersonEvents = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_events WHERE place_id IS NULL AND event_place IS NOT NULL")->cnt;
        $linkedFamilyEvents = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_family_events WHERE place_id IS NOT NULL")->cnt;
        $unlinkedFamilyEvents = DB::selectOne("SELECT COUNT(*) as cnt FROM genealogy_family_events WHERE place_id IS NULL AND event_place IS NOT NULL")->cnt;

        $byType = DB::select("
            SELECT place_type, COUNT(*) as cnt
            FROM genealogy_places
            GROUP BY place_type
            ORDER BY cnt DESC
        ");

        $topPlaces = DB::select("
            SELECT p.id, p.name, p.place_type,
                   (SELECT COUNT(*) FROM genealogy_events WHERE place_id = p.id) +
                   (SELECT COUNT(*) FROM genealogy_family_events WHERE place_id = p.id) as usage_count
            FROM genealogy_places p
            ORDER BY usage_count DESC
            LIMIT 20
        ");

        return [
            'total_places' => $totalPlaces,
            'linked_person_events' => $linkedPersonEvents,
            'unlinked_person_events' => $unlinkedPersonEvents,
            'linked_family_events' => $linkedFamilyEvents,
            'unlinked_family_events' => $unlinkedFamilyEvents,
            'by_type' => $byType,
            'top_places' => $topPlaces,
        ];
    }

    /**
     * Normalize a place name for matching
     */
    public function normalizePlaceName(string $name): string
    {
        // Lowercase
        $normalized = strtolower($name);

        // Remove punctuation except commas
        $normalized = preg_replace('/[^\w\s,]/', '', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Trim
        $normalized = trim($normalized);

        return $normalized;
    }

    /**
     * Parse a place string into hierarchy components
     *
     * @param string $placeString E.g., "Philadelphia, Philadelphia County, Pennsylvania, USA"
     * @return array Parsed components with types
     */
    public function parsePlaceHierarchy(string $placeString): array
    {
        $parts = array_map('trim', explode(',', $placeString));
        $parts = array_filter($parts);

        if (empty($parts)) {
            return [];
        }

        $hierarchy = [];

        // Work backwards - last part is typically country
        $parts = array_reverse($parts);

        foreach ($parts as $index => $part) {
            $type = $this->inferPlaceType($part, $index, count($parts));
            $normalized = $this->normalizePlaceName($part);

            // Expand abbreviations
            $expandedName = $this->expandAbbreviation($part, $type);

            $hierarchy[] = [
                'name' => $expandedName,
                'short_name' => $part !== $expandedName ? $part : null,
                'normalized_name' => $this->normalizePlaceName($expandedName),
                'place_type' => $type,
            ];
        }

        return array_reverse($hierarchy);
    }

    /**
     * Infer place type from position and content
     */
    private function inferPlaceType(string $part, int $reverseIndex, int $totalParts): string
    {
        $lower = strtolower($part);
        $normalized = $this->normalizePlaceName($part);

        // Check country variants
        if (isset(self::COUNTRY_VARIANTS[$normalized]) || $reverseIndex === 0) {
            if ($reverseIndex === 0 && $totalParts > 1) {
                return 'country';
            }
        }

        // Check US states
        $upper = strtoupper($part);
        if (isset(self::STATE_ABBREVIATIONS[$upper]) || in_array($part, self::STATE_ABBREVIATIONS)) {
            return 'state';
        }

        // Check for "county" in name
        if (preg_match('/\bcounty\b/i', $part)) {
            return 'county';
        }

        // Check for "township" in name
        if (preg_match('/\btownship\b/i', $part)) {
            return 'township';
        }

        // Position-based inference
        if ($reverseIndex === 0 && $totalParts > 2) {
            return 'country';
        }
        if ($reverseIndex === 1 && $totalParts > 2) {
            return 'state';
        }
        if ($reverseIndex === 2 && $totalParts > 3) {
            return 'county';
        }

        // Default to city for the first (most specific) part
        if ($reverseIndex === $totalParts - 1) {
            return 'city';
        }

        return 'other';
    }

    /**
     * Expand abbreviations to full names
     */
    private function expandAbbreviation(string $part, string $type): string
    {
        $upper = strtoupper($part);
        $normalized = $this->normalizePlaceName($part);

        // US state abbreviations
        if ($type === 'state' && isset(self::STATE_ABBREVIATIONS[$upper])) {
            return self::STATE_ABBREVIATIONS[$upper];
        }

        // Country variants
        if ($type === 'country' && isset(self::COUNTRY_VARIANTS[$normalized])) {
            return self::COUNTRY_VARIANTS[$normalized];
        }

        return $part;
    }

    /**
     * Create a place with its hierarchy
     */
    private function createPlaceWithHierarchy(array $hierarchy, array $options, bool $createParents): ?int
    {
        $parentId = null;

        // Create from most general (country) to most specific (city)
        // Hierarchy is already in specific->general order, so reverse it
        $hierarchyReversed = array_reverse($hierarchy);

        foreach ($hierarchyReversed as $index => $level) {
            // Check if this level already exists with the same parent
            $existing = DB::selectOne("
                SELECT id FROM genealogy_places
                WHERE normalized_name = ?
                  AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))
            ", [$level['normalized_name'], $parentId, $parentId]);

            if ($existing) {
                $parentId = $existing->id;
                continue;
            }

            if (!$createParents && $index < count($hierarchyReversed) - 1) {
                // Only create the final (most specific) level
                continue;
            }

            // Create this level
            $lat = ($index === count($hierarchyReversed) - 1) ? ($options['latitude'] ?? null) : null;
            $lng = ($index === count($hierarchyReversed) - 1) ? ($options['longitude'] ?? null) : null;

            DB::insert("
                INSERT INTO genealogy_places (name, normalized_name, short_name, parent_id, place_type, latitude, longitude)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [
                $level['name'],
                $level['normalized_name'],
                $level['short_name'],
                $parentId,
                $level['place_type'],
                $lat,
                $lng,
            ]);

            $parentId = (int) DB::getPdo()->lastInsertId();

            // Add short name as alias if different
            if ($level['short_name']) {
                $this->addAlias($parentId, $level['short_name'], 'abbreviation');
            }
        }

        return $parentId;
    }

    /**
     * Build full path string from place and ancestors
     */
    private function buildFullPath(object $place, array $ancestors): string
    {
        $parts = [$place->name];

        foreach ($ancestors as $ancestor) {
            $parts[] = $ancestor->name;
        }

        return implode(', ', $parts);
    }

    /**
     * Update place coordinates
     *
     * @param int $placeId Place ID
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @return bool Success
     */
    public function updateCoordinates(int $placeId, float $latitude, float $longitude): bool
    {
        return DB::update("
            UPDATE genealogy_places
            SET latitude = ?, longitude = ?, updated_at = NOW()
            WHERE id = ?
        ", [$latitude, $longitude, $placeId]) > 0;
    }

    /**
     * Set historical boundaries for a place
     *
     * @param int $placeId Place ID
     * @param array $boundaries Array of {start_year, end_year, boundary_geojson}
     * @return bool Success
     */
    public function setHistoricalBoundaries(int $placeId, array $boundaries): bool
    {
        return DB::update("
            UPDATE genealogy_places
            SET historical_boundaries = ?, updated_at = NOW()
            WHERE id = ?
        ", [json_encode($boundaries), $placeId]) > 0;
    }

    /**
     * Set external IDs for a place
     *
     * @param int $placeId Place ID
     * @param array $externalIds Array of {service: id} pairs
     * @return bool Success
     */
    public function setExternalIds(int $placeId, array $externalIds): bool
    {
        return DB::update("
            UPDATE genealogy_places
            SET external_ids = ?, updated_at = NOW()
            WHERE id = ?
        ", [json_encode($externalIds), $placeId]) > 0;
    }
}
