<?php

namespace App\Console\Commands;

use App\Services\Genealogy\PlaceAuthorityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Genealogy Places Index Command
 *
 * Extracts and normalizes places from genealogy events into the genealogy_places table.
 * Processes place hierarchy (city -> county -> state -> country) and links events to normalized place_id.
 */
class GenealogyPlacesIndexCommand extends Command
{
    protected $signature = 'genealogy:index-places
                            {--tree= : Only process events from specific tree ID}
                            {--force : Re-process events that already have place_id}';

    protected $description = 'Extract and normalize places from genealogy events into the places authority table';

    private PlaceAuthorityService $placeService;

    public function __construct(PlaceAuthorityService $placeService)
    {
        parent::__construct();
        $this->placeService = $placeService;
    }

    public function handle(): int
    {
        $treeId = $this->option('tree') ? (int) $this->option('tree') : null;
        $force = $this->option('force');

        $this->info('Genealogy Places Index');
        $this->info('======================');
        $this->newLine();

        // Get unique places from person events table
        $personEventPlaces = $this->getUniquePlaces('genealogy_events', 'person_id', $treeId, $force);
        $familyPlaces = $this->getUniquePlaces('genealogy_family_events', 'family_id', $treeId, $force);

        // Get unique places directly from persons table (birth_place, death_place, burial_place)
        $personPlaces = $this->getUniquePersonPlaces($treeId, $force);

        // Combine and deduplicate
        $allPlaces = array_unique(array_merge($personEventPlaces, $familyPlaces, $personPlaces));
        sort($allPlaces);

        $this->info(sprintf('Found %d unique place strings to process', count($allPlaces)));
        $this->info(sprintf('  - From person events table: %d', count($personEventPlaces)));
        $this->info(sprintf('  - From family events table: %d', count($familyPlaces)));
        $this->info(sprintf('  - From persons table (birth/death/burial): %d', count($personPlaces)));
        $this->newLine();

        if (empty($allPlaces)) {
            $this->info('No places to process.');
            return Command::SUCCESS;
        }

        // Phase 1: Create normalized place records
        $this->info('Phase 1: Creating normalized place records...');
        $bar = $this->output->createProgressBar(count($allPlaces));
        $bar->start();

        $placeMap = []; // event_place => place_id
        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($allPlaces as $placeString) {
            try {
                $placeId = $this->placeService->findOrCreatePlace($placeString, [
                    'create_hierarchy' => true,
                ]);

                if ($placeId) {
                    $placeMap[$placeString] = $placeId;

                    // Check if this was a new creation (simplified check)
                    $created++;
                } else {
                    $failed++;
                    Log::warning('Failed to normalize place', ['place' => $placeString]);
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error('Error creating place', [
                    'place' => $placeString,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Place records: %d mapped, %d failed', count($placeMap), $failed));
        $this->newLine();

        // Phase 2: Link person events to places
        $this->info('Phase 2: Linking person events to places...');
        $personLinked = $this->linkEventsToPlaces('genealogy_events', 'person_id', $placeMap, $treeId, $force);

        // Phase 3: Link family events to places
        $this->info('Phase 3: Linking family events to places...');
        $familyLinked = $this->linkEventsToPlaces('genealogy_family_events', 'family_id', $placeMap, $treeId, $force);

        // Phase 4: Link persons table place columns
        $this->info('Phase 4: Linking person place columns (birth/death/burial)...');
        $personPlacesLinked = $this->linkPersonPlaces($placeMap, $treeId, $force);

        // Summary
        $this->newLine();
        $this->info('Summary');
        $this->info('-------');

        $stats = $this->placeService->getStatistics();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total places in database', $stats['total_places']],
                ['Person events linked', $personLinked],
                ['Family events linked', $familyLinked],
                ['Person place columns linked', $personPlacesLinked],
                ['Total events linked', $personLinked + $familyLinked + $personPlacesLinked],
                ['Unlinked person events', $stats['unlinked_person_events']],
                ['Unlinked family events', $stats['unlinked_family_events']],
            ]
        );

        // Show place type breakdown
        if (!empty($stats['by_type'])) {
            $this->newLine();
            $this->info('Places by type:');
            foreach ($stats['by_type'] as $type) {
                $this->line(sprintf('  %s: %d', $type->place_type ?? 'other', $type->cnt));
            }
        }

        Log::info('Genealogy places index completed', [
            'tree_id' => $treeId,
            'places_mapped' => count($placeMap),
            'person_events_linked' => $personLinked,
            'family_events_linked' => $familyLinked,
            'person_places_linked' => $personPlacesLinked,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Get unique place strings from an events table
     */
    private function getUniquePlaces(string $table, string $entityIdColumn, ?int $treeId, bool $force): array
    {
        $params = [];

        $sql = "
            SELECT DISTINCT e.event_place
            FROM {$table} e
        ";

        // Join to get tree filtering for person events
        if ($treeId && $entityIdColumn === 'person_id') {
            $sql .= " JOIN genealogy_persons p ON p.id = e.person_id ";
        } elseif ($treeId && $entityIdColumn === 'family_id') {
            $sql .= " JOIN genealogy_families f ON f.id = e.family_id ";
        }

        $sql .= " WHERE e.event_place IS NOT NULL AND e.event_place != '' ";

        if (!$force) {
            $sql .= " AND e.place_id IS NULL ";
        }

        if ($treeId) {
            if ($entityIdColumn === 'person_id') {
                $sql .= " AND p.tree_id = ? ";
            } else {
                $sql .= " AND f.tree_id = ? ";
            }
            $params[] = $treeId;
        }

        $results = DB::select($sql, $params);

        return array_map(fn($row) => $row->event_place, $results);
    }

    /**
     * Link events to their normalized place records
     */
    private function linkEventsToPlaces(string $table, string $entityIdColumn, array $placeMap, ?int $treeId, bool $force): int
    {
        $params = [];

        // Build WHERE clause
        $whereClause = "e.event_place IS NOT NULL AND e.event_place != ''";

        if (!$force) {
            $whereClause .= " AND e.place_id IS NULL";
        }

        // Get events to update
        $sql = "SELECT e.id, e.event_place FROM {$table} e ";

        if ($treeId && $entityIdColumn === 'person_id') {
            $sql .= " JOIN genealogy_persons p ON p.id = e.person_id ";
            $whereClause .= " AND p.tree_id = ?";
            $params[] = $treeId;
        } elseif ($treeId && $entityIdColumn === 'family_id') {
            $sql .= " JOIN genealogy_families f ON f.id = e.family_id ";
            $whereClause .= " AND f.tree_id = ?";
            $params[] = $treeId;
        }

        $sql .= " WHERE {$whereClause}";

        $events = DB::select($sql, $params);

        if (empty($events)) {
            $this->line('  No events to link.');
            return 0;
        }

        $bar = $this->output->createProgressBar(count($events));
        $bar->start();

        $linked = 0;

        foreach ($events as $event) {
            if (isset($placeMap[$event->event_place])) {
                DB::update(
                    "UPDATE {$table} SET place_id = ? WHERE id = ?",
                    [$placeMap[$event->event_place], $event->id]
                );
                $linked++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $linked;
    }

    /**
     * Get unique place strings from genealogy_persons table (birth_place, death_place, burial_place)
     */
    private function getUniquePersonPlaces(?int $treeId, bool $force): array
    {
        $places = [];
        $placeColumns = ['birth_place', 'death_place', 'burial_place'];
        $placeIdColumns = ['birth_place_id', 'death_place_id', 'burial_place_id'];

        foreach ($placeColumns as $idx => $column) {
            $placeIdColumn = $placeIdColumns[$idx];
            $params = [];

            $sql = "SELECT DISTINCT {$column} FROM genealogy_persons WHERE {$column} IS NOT NULL AND {$column} != ''";

            if (!$force) {
                $sql .= " AND ({$placeIdColumn} IS NULL)";
            }

            if ($treeId) {
                $sql .= " AND tree_id = ?";
                $params[] = $treeId;
            }

            $results = DB::select($sql, $params);

            foreach ($results as $row) {
                $places[] = $row->{$column};
            }
        }

        return array_unique($places);
    }

    /**
     * Link persons table place columns to normalized place records
     */
    private function linkPersonPlaces(array $placeMap, ?int $treeId, bool $force): int
    {
        $linked = 0;
        $placeColumns = [
            'birth_place' => 'birth_place_id',
            'death_place' => 'death_place_id',
            'burial_place' => 'burial_place_id',
        ];

        foreach ($placeColumns as $placeColumn => $placeIdColumn) {
            $params = [];

            $sql = "SELECT id, {$placeColumn} FROM genealogy_persons WHERE {$placeColumn} IS NOT NULL AND {$placeColumn} != ''";

            if (!$force) {
                $sql .= " AND ({$placeIdColumn} IS NULL)";
            }

            if ($treeId) {
                $sql .= " AND tree_id = ?";
                $params[] = $treeId;
            }

            $persons = DB::select($sql, $params);

            foreach ($persons as $person) {
                $placeString = $person->{$placeColumn};
                if (isset($placeMap[$placeString])) {
                    DB::update(
                        "UPDATE genealogy_persons SET {$placeIdColumn} = ? WHERE id = ?",
                        [$placeMap[$placeString], $person->id]
                    );
                    $linked++;
                }
            }
        }

        $this->line(sprintf('  Linked %d person place columns.', $linked));

        return $linked;
    }
}
