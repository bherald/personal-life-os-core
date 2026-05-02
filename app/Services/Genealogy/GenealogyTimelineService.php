<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Genealogy Timeline Service
 *
 * Aggregates events (birth, death, marriage, census, etc.) for a person and family
 * into a timeline-friendly format. Includes overlapping family member events
 * for comparative visualization.
 */
class GenealogyTimelineService
{
    /**
     * Event type display configuration
     */
    private const EVENT_CONFIG = [
        'birth' => ['icon' => 'baby', 'color' => '#4CAF50', 'priority' => 1],
        'baptism' => ['icon' => 'water', 'color' => '#2196F3', 'priority' => 2],
        'death' => ['icon' => 'cross', 'color' => '#9E9E9E', 'priority' => 3],
        'burial' => ['icon' => 'monument', 'color' => '#795548', 'priority' => 4],
        'marriage' => ['icon' => 'rings', 'color' => '#E91E63', 'priority' => 5],
        'divorce' => ['icon' => 'split', 'color' => '#FF5722', 'priority' => 6],
        'census' => ['icon' => 'list', 'color' => '#607D8B', 'priority' => 7],
        'residence' => ['icon' => 'home', 'color' => '#00BCD4', 'priority' => 8],
        'immigration' => ['icon' => 'ship', 'color' => '#3F51B5', 'priority' => 9],
        'emigration' => ['icon' => 'plane', 'color' => '#673AB7', 'priority' => 10],
        'naturalization' => ['icon' => 'flag', 'color' => '#F44336', 'priority' => 11],
        'military' => ['icon' => 'medal', 'color' => '#8BC34A', 'priority' => 12],
        'occupation' => ['icon' => 'briefcase', 'color' => '#FFC107', 'priority' => 13],
        'education' => ['icon' => 'school', 'color' => '#03A9F4', 'priority' => 14],
        'religion' => ['icon' => 'church', 'color' => '#9C27B0', 'priority' => 15],
        'other' => ['icon' => 'star', 'color' => '#757575', 'priority' => 99],
    ];

    /**
     * Get timeline for a person including family context
     *
     * @param int $personId Person ID
     * @param array $options Options:
     *   - include_family: bool (default true) - Include spouse/children events
     *   - include_parents: bool (default true) - Include parent events
     *   - include_siblings: bool (default false) - Include sibling events
     *   - event_types: array|null - Filter to specific event types
     *   - start_year: int|null - Filter events after this year
     *   - end_year: int|null - Filter events before this year
     * @return array Timeline data with events grouped and sorted
     */
    public function getPersonTimeline(int $personId, array $options = []): array
    {
        $includeFamily = $options['include_family'] ?? true;
        $includeParents = $options['include_parents'] ?? true;
        $includeSiblings = $options['include_siblings'] ?? false;
        $eventTypes = $options['event_types'] ?? null;
        $startYear = $options['start_year'] ?? null;
        $endYear = $options['end_year'] ?? null;

        $events = [];

        // Get person's own events
        $personEvents = $this->getEventsForPerson($personId, $eventTypes, $startYear, $endYear);
        foreach ($personEvents as $event) {
            $event->relationship = 'self';
            $events[] = $event;
        }

        // Get person info for context
        $person = $this->getPerson($personId);
        if (!$person) {
            return ['success' => false, 'error' => 'Person not found'];
        }

        // Get family events (marriages)
        $familyEvents = $this->getFamilyEventsForPerson($personId, $eventTypes, $startYear, $endYear);
        foreach ($familyEvents as $event) {
            $event->relationship = 'family';
            $events[] = $event;
        }

        if ($includeFamily) {
            // Get spouse events
            $spouseIds = $this->getSpouseIds($personId);
            foreach ($spouseIds as $spouseId) {
                $spouseEvents = $this->getEventsForPerson($spouseId, ['birth', 'death'], $startYear, $endYear);
                foreach ($spouseEvents as $event) {
                    $event->relationship = 'spouse';
                    $events[] = $event;
                }
            }

            // Get children events
            $childIds = $this->getChildIds($personId);
            foreach ($childIds as $childId) {
                $childEvents = $this->getEventsForPerson($childId, ['birth', 'death'], $startYear, $endYear);
                foreach ($childEvents as $event) {
                    $event->relationship = 'child';
                    $events[] = $event;
                }
            }
        }

        if ($includeParents) {
            $parentIds = $this->getParentIds($personId);
            foreach ($parentIds as $parentId) {
                $parentEvents = $this->getEventsForPerson($parentId, ['birth', 'death'], $startYear, $endYear);
                foreach ($parentEvents as $event) {
                    $event->relationship = 'parent';
                    $events[] = $event;
                }
            }
        }

        if ($includeSiblings) {
            $siblingIds = $this->getSiblingIds($personId);
            foreach ($siblingIds as $siblingId) {
                $siblingEvents = $this->getEventsForPerson($siblingId, ['birth', 'death'], $startYear, $endYear);
                foreach ($siblingEvents as $event) {
                    $event->relationship = 'sibling';
                    $events[] = $event;
                }
            }
        }

        // Sort events by date
        $sortedEvents = $this->sortEventsByDate($events);

        // Group events by year for timeline visualization
        $groupedByYear = $this->groupEventsByYear($sortedEvents);

        return [
            'success' => true,
            'person' => $person,
            'events' => $sortedEvents,
            'events_by_year' => $groupedByYear,
            'event_count' => count($sortedEvents),
            'year_range' => $this->getYearRange($sortedEvents),
            'event_config' => self::EVENT_CONFIG,
        ];
    }

    /**
     * Get timeline for a family (both spouses and children)
     *
     * @param int $familyId Family ID
     * @param array $options Same options as getPersonTimeline
     * @return array Timeline data
     */
    public function getFamilyTimeline(int $familyId, array $options = []): array
    {
        $eventTypes = $options['event_types'] ?? null;
        $startYear = $options['start_year'] ?? null;
        $endYear = $options['end_year'] ?? null;

        $events = [];

        // Get family record
        $family = $this->getFamily($familyId);
        if (!$family) {
            return ['success' => false, 'error' => 'Family not found'];
        }

        // Get family events (marriage, divorce, etc.)
        $familyEvents = $this->getFamilyEvents($familyId, $eventTypes, $startYear, $endYear);
        foreach ($familyEvents as $event) {
            $event->relationship = 'family';
            $events[] = $event;
        }

        // Get husband events
        if ($family->husband_id) {
            $husbandEvents = $this->getEventsForPerson($family->husband_id, $eventTypes, $startYear, $endYear);
            foreach ($husbandEvents as $event) {
                $event->relationship = 'husband';
                $events[] = $event;
            }
        }

        // Get wife events
        if ($family->wife_id) {
            $wifeEvents = $this->getEventsForPerson($family->wife_id, $eventTypes, $startYear, $endYear);
            foreach ($wifeEvents as $event) {
                $event->relationship = 'wife';
                $events[] = $event;
            }
        }

        // Get children events
        $childIds = $this->getChildIdsForFamily($familyId);
        foreach ($childIds as $childId) {
            $childEvents = $this->getEventsForPerson($childId, $eventTypes, $startYear, $endYear);
            foreach ($childEvents as $event) {
                $event->relationship = 'child';
                $events[] = $event;
            }
        }

        // Sort and group
        $sortedEvents = $this->sortEventsByDate($events);
        $groupedByYear = $this->groupEventsByYear($sortedEvents);

        return [
            'success' => true,
            'family' => $family,
            'events' => $sortedEvents,
            'events_by_year' => $groupedByYear,
            'event_count' => count($sortedEvents),
            'year_range' => $this->getYearRange($sortedEvents),
            'event_config' => self::EVENT_CONFIG,
        ];
    }

    /**
     * Get overlapping events for multiple persons (for comparison view)
     *
     * @param array $personIds Array of person IDs to compare
     * @param array $options Filter options
     * @return array Combined timeline with person labels
     */
    public function getComparisonTimeline(array $personIds, array $options = []): array
    {
        $events = [];

        foreach ($personIds as $personId) {
            $person = $this->getPerson($personId);
            if (!$person) continue;

            $personEvents = $this->getEventsForPerson(
                $personId,
                $options['event_types'] ?? null,
                $options['start_year'] ?? null,
                $options['end_year'] ?? null
            );

            foreach ($personEvents as $event) {
                $event->relationship = 'self';
                $event->track_id = $personId; // For multi-track visualization
                $event->track_label = trim(($person->given_name ?? '') . ' ' . ($person->surname ?? ''));
                $events[] = $event;
            }
        }

        $sortedEvents = $this->sortEventsByDate($events);

        return [
            'success' => true,
            'events' => $sortedEvents,
            'tracks' => array_map(fn($id) => $this->getPerson($id), $personIds),
            'event_count' => count($sortedEvents),
            'year_range' => $this->getYearRange($sortedEvents),
            'event_config' => self::EVENT_CONFIG,
        ];
    }

    /**
     * Get events for a person
     */
    private function getEventsForPerson(int $personId, ?array $eventTypes, ?int $startYear, ?int $endYear): array
    {
        $sql = "
            SELECT
                e.id,
                e.person_id,
                p.given_name,
                p.surname,
                e.event_type,
                e.event_date,
                e.event_place,
                e.latitude,
                e.longitude,
                e.description,
                e.source_id,
                'person' as event_source
            FROM genealogy_events e
            JOIN genealogy_persons p ON p.id = e.person_id
            WHERE e.person_id = ?
        ";
        $params = [$personId];

        if ($eventTypes) {
            $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
            $sql .= " AND e.event_type IN ({$placeholders})";
            $params = array_merge($params, $eventTypes);
        }

        $results = DB::select($sql, $params);

        // Filter by year if specified
        if ($startYear || $endYear) {
            $results = array_filter($results, function ($event) use ($startYear, $endYear) {
                $year = $this->extractYear($event->event_date);
                if ($year === null) return true; // Include undated events
                if ($startYear && $year < $startYear) return false;
                if ($endYear && $year > $endYear) return false;
                return true;
            });
        }

        return array_values($results);
    }

    /**
     * Get family events (marriage, divorce, etc.)
     */
    private function getFamilyEvents(int $familyId, ?array $eventTypes, ?int $startYear, ?int $endYear): array
    {
        $sql = "
            SELECT
                fe.id,
                fe.family_id,
                f.husband_id,
                f.wife_id,
                CONCAT(COALESCE(h.given_name, ''), ' ', COALESCE(h.surname, ''), ' & ', COALESCE(w.given_name, ''), ' ', COALESCE(w.surname, '')) as family_name,
                fe.event_type,
                fe.event_date,
                fe.event_place,
                fe.latitude,
                fe.longitude,
                fe.description,
                fe.source_id,
                'family' as event_source
            FROM genealogy_family_events fe
            JOIN genealogy_families f ON f.id = fe.family_id
            LEFT JOIN genealogy_persons h ON h.id = f.husband_id
            LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE fe.family_id = ?
        ";
        $params = [$familyId];

        if ($eventTypes) {
            $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
            $sql .= " AND fe.event_type IN ({$placeholders})";
            $params = array_merge($params, $eventTypes);
        }

        $results = DB::select($sql, $params);

        if ($startYear || $endYear) {
            $results = array_filter($results, function ($event) use ($startYear, $endYear) {
                $year = $this->extractYear($event->event_date);
                if ($year === null) return true;
                if ($startYear && $year < $startYear) return false;
                if ($endYear && $year > $endYear) return false;
                return true;
            });
        }

        return array_values($results);
    }

    /**
     * Get family events for a person (as spouse)
     */
    private function getFamilyEventsForPerson(int $personId, ?array $eventTypes, ?int $startYear, ?int $endYear): array
    {
        $sql = "
            SELECT
                fe.id,
                fe.family_id,
                f.husband_id,
                f.wife_id,
                CONCAT(COALESCE(h.given_name, ''), ' ', COALESCE(h.surname, ''), ' & ', COALESCE(w.given_name, ''), ' ', COALESCE(w.surname, '')) as family_name,
                fe.event_type,
                fe.event_date,
                fe.event_place,
                fe.latitude,
                fe.longitude,
                fe.description,
                fe.source_id,
                'family' as event_source
            FROM genealogy_family_events fe
            JOIN genealogy_families f ON f.id = fe.family_id
            LEFT JOIN genealogy_persons h ON h.id = f.husband_id
            LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE f.husband_id = ? OR f.wife_id = ?
        ";
        $params = [$personId, $personId];

        if ($eventTypes) {
            $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
            $sql .= " AND fe.event_type IN ({$placeholders})";
            $params = array_merge($params, $eventTypes);
        }

        $results = DB::select($sql, $params);

        if ($startYear || $endYear) {
            $results = array_filter($results, function ($event) use ($startYear, $endYear) {
                $year = $this->extractYear($event->event_date);
                if ($year === null) return true;
                if ($startYear && $year < $startYear) return false;
                if ($endYear && $year > $endYear) return false;
                return true;
            });
        }

        return array_values($results);
    }

    /**
     * Get person record
     */
    private function getPerson(int $personId): ?object
    {
        return DB::selectOne("
            SELECT id, tree_id, gedcom_id, given_name, surname, sex, birth_date, death_date
            FROM genealogy_persons
            WHERE id = ?
        ", [$personId]);
    }

    /**
     * Get family record
     */
    private function getFamily(int $familyId): ?object
    {
        return DB::selectOne("
            SELECT f.id, f.tree_id, f.husband_id, f.wife_id,
                   CONCAT(COALESCE(h.given_name, ''), ' ', COALESCE(h.surname, '')) as husband_name,
                   CONCAT(COALESCE(w.given_name, ''), ' ', COALESCE(w.surname, '')) as wife_name
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON h.id = f.husband_id
            LEFT JOIN genealogy_persons w ON w.id = f.wife_id
            WHERE f.id = ?
        ", [$familyId]);
    }

    /**
     * Get spouse IDs for a person
     */
    private function getSpouseIds(int $personId): array
    {
        $results = DB::select("
            SELECT
                CASE
                    WHEN husband_id = ? THEN wife_id
                    ELSE husband_id
                END as spouse_id
            FROM genealogy_families
            WHERE husband_id = ? OR wife_id = ?
        ", [$personId, $personId, $personId]);

        return array_filter(array_column($results, 'spouse_id'));
    }

    /**
     * Get child IDs for a person
     */
    private function getChildIds(int $personId): array
    {
        $results = DB::select("
            SELECT c.person_id
            FROM genealogy_children c
            JOIN genealogy_families f ON f.id = c.family_id
            WHERE f.husband_id = ? OR f.wife_id = ?
        ", [$personId, $personId]);

        return array_column($results, 'person_id');
    }

    /**
     * Get child IDs for a family
     */
    private function getChildIdsForFamily(int $familyId): array
    {
        $results = DB::select("
            SELECT person_id FROM genealogy_children WHERE family_id = ?
        ", [$familyId]);

        return array_column($results, 'person_id');
    }

    /**
     * Get parent IDs for a person
     */
    private function getParentIds(int $personId): array
    {
        $results = DB::select("
            SELECT f.husband_id, f.wife_id
            FROM genealogy_families f
            JOIN genealogy_children c ON c.family_id = f.id
            WHERE c.person_id = ?
        ", [$personId]);

        $parentIds = [];
        foreach ($results as $row) {
            if ($row->husband_id) $parentIds[] = $row->husband_id;
            if ($row->wife_id) $parentIds[] = $row->wife_id;
        }

        return array_unique($parentIds);
    }

    /**
     * Get sibling IDs for a person
     */
    private function getSiblingIds(int $personId): array
    {
        $results = DB::select("
            SELECT c2.person_id
            FROM genealogy_children c1
            JOIN genealogy_children c2 ON c2.family_id = c1.family_id
            WHERE c1.person_id = ? AND c2.person_id != ?
        ", [$personId, $personId]);

        return array_unique(array_column($results, 'person_id'));
    }

    /**
     * Extract year from GEDCOM date string
     */
    private function extractYear(?string $dateStr): ?int
    {
        if (!$dateStr) return null;

        // Handle various GEDCOM date formats
        // "1850", "ABT 1850", "BEF 1850", "AFT 1850", "1 JAN 1850", "JAN 1850"
        if (preg_match('/\b(\d{4})\b/', $dateStr, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Sort events by date
     */
    private function sortEventsByDate(array $events): array
    {
        usort($events, function ($a, $b) {
            $yearA = $this->extractYear($a->event_date);
            $yearB = $this->extractYear($b->event_date);

            // Null dates go to end
            if ($yearA === null && $yearB === null) return 0;
            if ($yearA === null) return 1;
            if ($yearB === null) return -1;

            if ($yearA !== $yearB) {
                return $yearA - $yearB;
            }

            // Same year - sort by event priority
            $configA = self::EVENT_CONFIG[$a->event_type] ?? self::EVENT_CONFIG['other'];
            $configB = self::EVENT_CONFIG[$b->event_type] ?? self::EVENT_CONFIG['other'];

            return $configA['priority'] - $configB['priority'];
        });

        return $events;
    }

    /**
     * Group events by year for timeline visualization
     */
    private function groupEventsByYear(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $year = $this->extractYear($event->event_date) ?? 'Unknown';
            if (!isset($grouped[$year])) {
                $grouped[$year] = [];
            }
            $grouped[$year][] = $event;
        }

        // Sort by year
        uksort($grouped, function ($a, $b) {
            if ($a === 'Unknown') return 1;
            if ($b === 'Unknown') return -1;
            return $a - $b;
        });

        return $grouped;
    }

    /**
     * Get year range from events
     */
    private function getYearRange(array $events): array
    {
        $years = array_filter(array_map(
            fn($e) => $this->extractYear($e->event_date),
            $events
        ));

        if (empty($years)) {
            return ['min' => null, 'max' => null];
        }

        return [
            'min' => min($years),
            'max' => max($years),
        ];
    }
}
