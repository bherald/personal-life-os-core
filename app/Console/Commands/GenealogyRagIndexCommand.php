<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DI-3: Index genealogy person records into RAG for cross-domain search.
 *
 * Builds a rich text profile per person (name, dates, places, family, events, sources)
 * and indexes it via RAGService. Uses rag_indexed_at to track which persons need indexing.
 *
 * Usage:
 *   php artisan genealogy:rag-index --limit=50           # Index unindexed persons
 *   php artisan genealogy:rag-index --reindex --limit=0   # Re-index all persons
 *   php artisan genealogy:rag-index --stats               # Show indexing stats
 *   php artisan genealogy:rag-index --dry-run --limit=5   # Preview without indexing
 *   php artisan genealogy:rag-index --exclude-living      # Optional privacy filter
 */
class GenealogyRagIndexCommand extends Command
{
    protected $signature = 'genealogy:rag-index
                            {--limit=50 : Max items to index per run; 0 means all}
                            {--type=persons : Type: persons, places, sources, all}
                            {--reindex : Re-index already indexed items}
                            {--stats : Show indexing statistics}
                            {--dry-run : Preview without indexing}
                            {--exclude-living : Exclude persons explicitly marked living}
                            {--tree= : Limit to specific tree_id}';

    protected $description = 'DI-3/DI-9: Index genealogy data into RAG (persons, places, sources)';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats($this->option('tree'));
        }

        $type = $this->option('type');
        $limit = (int) $this->option('limit');
        $reindex = $this->option('reindex');
        $dryRun = $this->option('dry-run');
        $treeId = $this->option('tree');
        $excludeLiving = (bool) $this->option('exclude-living');

        $totalIndexed = 0;

        if ($type === 'all' || $type === 'places') {
            $totalIndexed += $this->indexPlaces($limit, $reindex, $dryRun, $treeId);
        }

        if ($type === 'all' || $type === 'sources') {
            $totalIndexed += $this->indexSources($limit, $reindex, $dryRun, $treeId);
        }

        if ($type !== 'places' && $type !== 'sources') {
            // Default: persons (original behavior)
        } else {
            $this->info("Total indexed: {$totalIndexed}. [ITEMS_PROCESSED:{$totalIndexed}]");

            return 0;
        }

        $limitLabel = $limit <= 0 ? 'all' : (string) $limit;
        $this->info("Genealogy RAG indexing persons (limit: {$limitLabel}, reindex: ".($reindex ? 'yes' : 'no').', exclude living: '.($excludeLiving ? 'yes' : 'no').')');

        $persons = $this->getPersonsToIndex($limit, $reindex, $treeId, $excludeLiving);

        if (empty($persons)) {
            $this->info('No persons to index.');
            if (! $dryRun) {
                $this->syncPersonRagMarkersFromPg($treeId);
            }

            return 0;
        }

        if (! $dryRun) {
            $selectedPersonIds = array_map(
                static fn (object $person): int => (int) $person->id,
                $persons
            );
            $existingSelectedPersonIds = $reindex
                ? $selectedPersonIds
                : array_values(array_intersect($selectedPersonIds, $this->getExistingRagPersonIds($treeId)));

            if ($existingSelectedPersonIds !== []) {
                $deleted = $this->removeExistingPersonDocuments($existingSelectedPersonIds);
                $this->info("Removed {$deleted} existing person RAG document(s) for selected batch.");
            }
        }

        $this->info('Found '.count($persons).' persons to index.');

        $ragService = app(RAGService::class);
        $indexed = 0;
        $failed = 0;
        $startTime = microtime(true);

        foreach ($persons as $person) {
            $name = trim(($person->given_name ?? '').' '.($person->surname ?? ''));

            try {
                $content = $this->buildPersonContent($person);

                if (strlen(trim($content)) < 50) {
                    $this->line("  Skip (too little data): {$name}");

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY] Would index: {$name} (".strlen($content).' chars)');
                    $indexed++;

                    continue;
                }

                $doc = $ragService->indexDocument(
                    'genealogy_person',
                    $content,
                    $name,
                    $this->buildPersonMetadata($person),
                    $person->id,
                    'genealogy_person',
                    'genealogy',
                    null,
                    ['skip_dedup' => true]
                );

                if ($doc) {
                    DB::update('UPDATE genealogy_persons SET rag_indexed_at = NOW(), updated_at = updated_at WHERE id = ?', [$person->id]);
                    $indexed++;
                    $this->line("  OK: {$name} (doc #{$doc->id})");
                } else {
                    $failed++;
                    $this->line("  <error>Dedup/skip:</error> {$name}");
                }

            } catch (\Throwable $e) {
                $failed++;
                $this->line("  <error>Error:</error> {$name} — {$e->getMessage()}");
                Log::warning('GenealogyRagIndex: Failed to index person', [
                    'person_id' => $person->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Bounded incremental runs self-stop; unlimited full rebuilds rely on the scheduled job timeout.
            if ($limit > 0 && (microtime(true) - $startTime) > 600) {
                $this->warn('Wall-clock limit reached (10 min).');
                break;
            }
        }

        if (! $dryRun) {
            $this->syncPersonRagMarkersFromPg($treeId);
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Indexed', $dryRun ? "{$indexed} (dry run)" : $indexed],
            ['Failed', $failed],
            ['Duration', round(microtime(true) - $startTime, 1).'s'],
        ]);

        return $failed > 0 && $indexed === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getPersonsToIndex(int $limit, bool $reindex, ?string $treeId, bool $excludeLiving): array
    {
        $sql = 'SELECT p.*
                FROM genealogy_persons p
                WHERE 1=1';

        $params = [];

        if ($excludeLiving) {
            $sql .= ' AND (p.living IS NULL OR p.living = 0)';
        }

        if ($treeId) {
            $sql .= ' AND p.tree_id = ?';
            $params[] = $treeId;
        }

        if (! $reindex) {
            $existingIds = $this->getExistingRagPersonIds($treeId);

            if (! empty($existingIds)) {
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $sql .= " AND (
                    p.id NOT IN ({$placeholders})
                    OR p.rag_indexed_at IS NULL
                    OR (
                        p.rag_indexed_at IS NOT NULL
                        AND p.updated_at IS NOT NULL
                        AND p.updated_at > p.rag_indexed_at
                    )
                )";
                array_push($params, ...$existingIds);
            }
        }

        $sql .= ' ORDER BY p.updated_at DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return DB::select($sql, $params);
    }

    /**
     * The MySQL rag_indexed_at marker can drift from PostgreSQL after old imports
     * or dedup behavior. Use the actual RAG source rows as the coverage source of truth.
     */
    private function getExistingRagPersonIds(?string $treeId): array
    {
        return array_map(
            static fn ($row) => (int) $row->source_id,
            $this->getExistingRagPersonRows($treeId)
        );
    }

    private function getExistingRagPersonRows(?string $treeId): array
    {
        $params = [];
        $treeClause = '';

        if ($treeId) {
            $treeClause = " AND metadata->>'tree_id' = ?";
            $params[] = (string) $treeId;
        }

        $sql = "SELECT source_id
                , MAX(updated_at) AS indexed_at
                FROM rag_documents
                WHERE document_type = 'genealogy_person'
                  AND source_type = 'genealogy_person'
                  AND source_id IS NOT NULL
                  {$treeClause}
                GROUP BY source_id";

        return DB::connection('pgsql_rag')->select($sql, $params);
    }

    private function removeExistingPersonDocuments(array $personIds): int
    {
        return $this->removeExistingRagDocuments('genealogy_person', 'genealogy_person', $personIds);
    }

    private function removeExistingRagDocuments(string $documentType, string $sourceType, array $sourceIds): int
    {
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn ($sourceId): string => (string) (int) $sourceId,
            $sourceIds
        ))));

        if ($sourceIds === []) {
            return 0;
        }

        $db = DB::connection('pgsql_rag');
        $sourcePlaceholders = implode(',', array_fill(0, count($sourceIds), '?'));

        $sql = "SELECT id
                FROM rag_documents
                WHERE document_type = ?
                  AND source_type = ?
                  AND source_id::text IN ({$sourcePlaceholders})";
        $params = array_merge([$documentType, $sourceType], $sourceIds);

        $documentIds = array_map(
            static fn ($row) => (int) $row->id,
            $db->select($sql, $params)
        );

        if (empty($documentIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $db->delete("DELETE FROM rag_sentence_embeddings WHERE document_id IN ({$placeholders})", $documentIds);
        $db->delete("DELETE FROM rag_chunk_hypotheticals WHERE document_id IN ({$placeholders})", $documentIds);

        return $db->delete("DELETE FROM rag_documents WHERE id IN ({$placeholders})", $documentIds);
    }

    /**
     * MySQL markers are an operational cache; PostgreSQL RAG docs are the source of truth.
     * Reconcile after each run so failed or partial indexing does not leave false coverage.
     */
    private function syncPersonRagMarkersFromPg(?string $treeId): void
    {
        $indexedAtByPersonId = [];
        $existingRows = $this->getExistingRagPersonRows($treeId);

        foreach ($existingRows as $row) {
            $personId = (int) ($row->source_id ?? 0);
            if ($personId <= 0) {
                continue;
            }

            $indexedAtByPersonId[$personId] = (string) ($row->indexed_at ?? now()->toDateTimeString());
        }

        $personSql = 'SELECT id, updated_at, rag_indexed_at
                      FROM genealogy_persons
                      WHERE 1=1';
        $personParams = [];

        if ($treeId) {
            $personSql .= ' AND tree_id = ?';
            $personParams[] = $treeId;
        }

        $markersToClear = [];
        $markersToSet = [];

        foreach (DB::select($personSql, $personParams) as $person) {
            $personId = (int) $person->id;
            $indexedAt = $indexedAtByPersonId[$personId] ?? null;

            if ($indexedAt === null) {
                if ($person->rag_indexed_at !== null) {
                    $markersToClear[] = $personId;
                }

                continue;
            }

            if (! $this->personRagDocumentIsCurrent($person->updated_at ?? null, $indexedAt)) {
                if ($person->rag_indexed_at !== null) {
                    $markersToClear[] = $personId;
                }

                continue;
            }

            if ((string) ($person->rag_indexed_at ?? '') !== $indexedAt) {
                $markersToSet[$personId] = $indexedAt;
            }
        }

        $this->clearPersonRagMarkers($markersToClear, $treeId);
        $this->setPersonRagMarkers($markersToSet, $treeId);
    }

    private function personRagDocumentIsCurrent(mixed $personUpdatedAt, string $indexedAt): bool
    {
        if ($personUpdatedAt === null || $personUpdatedAt === '') {
            return true;
        }

        $updatedTimestamp = strtotime((string) $personUpdatedAt);
        $indexedTimestamp = strtotime($indexedAt);

        if ($updatedTimestamp === false || $indexedTimestamp === false) {
            return true;
        }

        return $updatedTimestamp <= $indexedTimestamp;
    }

    private function clearPersonRagMarkers(array $personIds, ?string $treeId): void
    {
        foreach (array_chunk(array_values(array_unique($personIds)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "UPDATE genealogy_persons
                    SET rag_indexed_at = NULL, updated_at = updated_at
                    WHERE rag_indexed_at IS NOT NULL
                      AND id IN ({$placeholders})";
            $params = $chunk;

            if ($treeId) {
                $sql .= ' AND tree_id = ?';
                $params[] = $treeId;
            }

            DB::update($sql, $params);
        }
    }

    private function setPersonRagMarkers(array $markersByPersonId, ?string $treeId): void
    {
        foreach ($markersByPersonId as $personId => $indexedAt) {
            $sql = 'UPDATE genealogy_persons
                    SET rag_indexed_at = ?, updated_at = updated_at
                    WHERE id = ?
                      AND (updated_at IS NULL OR updated_at <= ?)';
            $params = [$indexedAt, $personId, $indexedAt];

            if ($treeId) {
                $sql .= ' AND tree_id = ?';
                $params[] = $treeId;
            }

            DB::update($sql, $params);
        }
    }

    private function buildPersonMetadata(object $person): array
    {
        return [
            'person_id' => $person->id,
            'tree_id' => $person->tree_id,
            'gedcom_id' => $person->gedcom_id ?? null,
            'uid' => $person->uid ?? null,
            'birth_year' => $this->extractYear($person->birth_date ?? null),
            'death_year' => $this->extractYear($person->death_date ?? null),
            'birth_place' => $person->birth_place ?? null,
            'death_place' => $person->death_place ?? null,
            'living' => isset($person->living) ? (bool) $person->living : null,
            'all_person_fields_included' => true,
            'person_record' => $this->personRecord($person),
        ];
    }

    private function personRecord(object $person): array
    {
        $record = [];

        foreach ((array) $person as $key => $value) {
            $record[$key] = $this->normalizePersonValue($value);
        }

        return $record;
    }

    private function buildPersonContent(object $person): string
    {
        $parts = [];
        $name = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
        if ($person->suffix) {
            $name .= " {$person->suffix}";
        }

        $parts[] = "Person: {$name}";

        $parts[] = "\nPerson record fields:";
        foreach ($this->personFieldLabels() as $field => $label) {
            $value = $this->normalizePersonValue($person->{$field} ?? null);
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = "- {$label}: {$value}";
        }

        // Family relationships
        $families = $this->getPersonFamilies($person->id);
        if (! empty($families)) {
            $parts[] = "\nFamily:";
            foreach ($families as $fam) {
                $parts[] = "- {$fam}";
            }
        }

        // Events
        $events = $this->getPersonEvents($person->id);
        if (! empty($events)) {
            $parts[] = "\nLife Events:";
            foreach ($events as $evt) {
                $parts[] = "- {$evt}";
            }
        }

        // Sources
        $sources = $this->getPersonSources($person->id);
        if (! empty($sources)) {
            $parts[] = "\nSources:";
            foreach (array_slice($sources, 0, 10) as $src) {
                $parts[] = "- {$src}";
            }
        }

        return implode("\n", $parts);
    }

    private function personFieldLabels(): array
    {
        return [
            'id' => 'Person database ID',
            'tree_id' => 'Tree ID',
            'gedcom_id' => 'GEDCOM ID',
            'uid' => 'UID',
            'title' => 'Title',
            'given_name' => 'Given name',
            'surname' => 'Surname',
            'suffix' => 'Suffix',
            'nickname' => 'Nickname or also known as',
            'sex' => 'Sex',
            'birth_date' => 'Birth date',
            'birth_place' => 'Birth place',
            'birth_lat' => 'Birth latitude',
            'birth_lon' => 'Birth longitude',
            'birth_place_id' => 'Birth place ID',
            'death_date' => 'Death date',
            'death_place' => 'Death place',
            'death_lat' => 'Death latitude',
            'death_lon' => 'Death longitude',
            'death_place_id' => 'Death place ID',
            'burial_date' => 'Burial date',
            'burial_place' => 'Burial place',
            'burial_lat' => 'Burial latitude',
            'burial_lon' => 'Burial longitude',
            'burial_place_id' => 'Burial place ID',
            'occupation' => 'Occupation',
            'education' => 'Education',
            'religion' => 'Religion',
            'primary_photo_id' => 'Primary photo media ID',
            'notes' => 'Notes',
            'primary_language' => 'Primary language',
            'living' => 'Living status',
            'privacy_override' => 'Privacy override',
            'physical_description' => 'Physical description',
            'nationality' => 'Nationality',
            'ssn' => 'SSN',
            'id_number' => 'ID number',
            'property' => 'Property',
            'cause_of_death' => 'Cause of death',
            'created_at' => 'Record created at',
            'updated_at' => 'Record updated at',
            'rag_indexed_at' => 'RAG indexed at before this run',
        ];
    }

    private function normalizePersonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function getPersonFamilies(int $personId): array
    {
        $families = [];

        try {
            // Spouse relationships
            $spouses = DB::select('
                SELECT p2.given_name, p2.surname, f.marriage_date, f.marriage_place
                FROM genealogy_families f
                JOIN genealogy_persons p2 ON (
                    (f.husband_id = ? AND f.wife_id = p2.id) OR
                    (f.wife_id = ? AND f.husband_id = p2.id)
                )
                WHERE f.husband_id = ? OR f.wife_id = ?
            ', [$personId, $personId, $personId, $personId]);

            foreach ($spouses as $s) {
                $spouseName = trim(($s->given_name ?? '').' '.($s->surname ?? ''));
                $detail = "Spouse: {$spouseName}";
                if ($s->marriage_date) {
                    $detail .= " (married {$s->marriage_date}".($s->marriage_place ? " in {$s->marriage_place}" : '').')';
                }
                $families[] = $detail;
            }

            // Children
            $children = DB::select('
                SELECT p.given_name, p.surname, p.birth_date
                FROM genealogy_persons p
                JOIN genealogy_children gc ON gc.person_id = p.id
                JOIN genealogy_families f ON f.id = gc.family_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY p.birth_date
            ', [$personId, $personId]);

            foreach ($children as $c) {
                $childName = trim(($c->given_name ?? '').' '.($c->surname ?? ''));
                $families[] = "Child: {$childName}".($c->birth_date ? " (b. {$c->birth_date})" : '');
            }

            // Parents
            $parents = DB::select("
                SELECT p.given_name, p.surname,
                       CASE WHEN f.husband_id = p.id THEN 'Father' ELSE 'Mother' END as relationship
                FROM genealogy_families f
                JOIN genealogy_children gc ON gc.family_id = f.id
                JOIN genealogy_persons p ON p.id IN (f.husband_id, f.wife_id)
                WHERE gc.person_id = ?
            ", [$personId]);

            foreach ($parents as $p) {
                $parentName = trim(($p->given_name ?? '').' '.($p->surname ?? ''));
                $families[] = "{$p->relationship}: {$parentName}";
            }

        } catch (\Throwable $e) {
            // Non-critical
        }

        return $families;
    }

    private function getPersonEvents(int $personId): array
    {
        try {
            $events = DB::select('
                SELECT event_type, event_date, event_place, description
                FROM genealogy_events
                WHERE person_id = ?
                ORDER BY event_date
            ', [$personId]);

            return array_map(function ($e) {
                $detail = ucfirst($e->event_type);
                if ($e->event_date) {
                    $detail .= ": {$e->event_date}";
                }
                if ($e->event_place) {
                    $detail .= " in {$e->event_place}";
                }
                if ($e->description) {
                    $detail .= " — {$e->description}";
                }

                return $detail;
            }, $events);

        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getPersonSources(int $personId): array
    {
        try {
            $sources = DB::select('
                SELECT gs.title, gs.author, gs.repository
                FROM genealogy_citations gc
                JOIN genealogy_sources gs ON gs.id = gc.source_id
                WHERE gc.person_id = ?
                LIMIT 10
            ', [$personId]);

            return array_map(function ($s) {
                $detail = $s->title ?? 'Untitled';
                if ($s->author) {
                    $detail .= " by {$s->author}";
                }
                if ($s->repository) {
                    $detail .= " ({$s->repository})";
                }

                return $detail;
            }, $sources);

        } catch (\Throwable $e) {
            return [];
        }
    }

    private function showStats(?string $treeId): int
    {
        $where = $treeId ? ' WHERE tree_id = ?' : '';
        $params = $treeId ? [$treeId] : [];

        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END), 0) as indexed,
                COALESCE(SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END), 0) as living
            FROM genealogy_persons
            {$where}
        ", $params);

        $ragDocumentCount = count($this->getExistingRagPersonIds($treeId));
        $missingRagDocuments = max(0, (int) $stats->total - $ragDocumentCount);

        $this->table(['Metric', 'Value'], [
            ['Total persons', $stats->total],
            ['MySQL rag_indexed_at set', $stats->indexed],
            ['MySQL rag_indexed_at pending', $stats->pending],
            ['PostgreSQL person RAG docs', $ragDocumentCount],
            ['Missing PostgreSQL person RAG docs', $missingRagDocuments],
            ['Living persons', $stats->living],
        ]);

        return 0;
    }

    private function extractYear(?string $date): ?int
    {
        if (! $date) {
            return null;
        }
        if (preg_match('/(\d{4})/', $date, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * DI-9: Index genealogy places into RAG
     */
    private function indexPlaces(int $limit, bool $reindex, bool $dryRun, ?string $treeId): int
    {
        $sql = 'SELECT id, name, normalized_name, place_type, latitude, longitude, rag_indexed_at, updated_at
                FROM genealogy_places WHERE 1=1';
        $params = [];
        if (! $reindex) {
            $sql .= ' AND (rag_indexed_at IS NULL OR (updated_at IS NOT NULL AND updated_at > rag_indexed_at))';
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        try {
            $places = DB::select($sql, $params);
        } catch (\Throwable $e) {
            // rag_indexed_at column may not exist yet
            $this->warn("Places indexing skipped: {$e->getMessage()}");

            return 0;
        }

        if (empty($places)) {
            $this->info('No places to index.');

            return 0;
        }

        $this->info('Found '.count($places).' places to index.');
        $ragService = app(RAGService::class);
        $indexed = 0;
        $selectedPlaceIds = array_map(static fn (object $place): int => (int) $place->id, $places);

        if (! $dryRun) {
            $deleted = $this->removeExistingRagDocuments('genealogy_place', 'genealogy_place', $selectedPlaceIds);
            if ($deleted > 0) {
                $this->info("Removed {$deleted} existing place RAG document(s) for selected batch.");
            }
        }

        foreach ($places as $place) {
            $content = "Place: {$place->name}";
            if ($place->normalized_name && $place->normalized_name !== $place->name) {
                $content .= " (standardized: {$place->normalized_name})";
            }
            if ($place->place_type) {
                $content .= "\nType: {$place->place_type}";
            }
            if ($place->latitude && $place->longitude) {
                $content .= "\nCoordinates: {$place->latitude}, {$place->longitude}";
            }

            if (strlen($content) < 20) {
                continue;
            }

            if ($dryRun) {
                $this->line("  Would index place: {$place->name}");
                $indexed++;

                continue;
            }

            try {
                $doc = $ragService->indexDocument(
                    'genealogy_place',
                    $content,
                    "Place: {$place->name}",
                    [
                        'place_id' => (int) $place->id,
                        'type' => $place->place_type,
                    ],
                    $place->id,
                    'genealogy_place',
                    'genealogy',
                    null,
                    ['skip_dedup' => true]
                );

                if ($doc) {
                    DB::update('UPDATE genealogy_places SET rag_indexed_at = NOW(), updated_at = updated_at WHERE id = ?', [$place->id]);
                    $indexed++;
                }
            } catch (\Throwable $e) {
                Log::warning('GenealogyRagIndex: Place indexing failed', ['id' => $place->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Places: {$indexed} indexed.");

        return $indexed;
    }

    /**
     * DI-9: Index genealogy sources into RAG
     */
    private function indexSources(int $limit, bool $reindex, bool $dryRun, ?string $treeId): int
    {
        $sql = 'SELECT gs.id, gs.tree_id, gs.title, gs.author, gs.publication,
                       gs.source_category, gs.repository, gs.url, gs.notes,
                       gs.rag_indexed_at, gs.updated_at
                FROM genealogy_sources gs WHERE 1=1';
        $params = [];

        if ($treeId) {
            $sql .= ' AND gs.tree_id = ?';
            $params[] = $treeId;
        }
        if (! $reindex) {
            $sql .= ' AND (gs.rag_indexed_at IS NULL OR (gs.updated_at IS NOT NULL AND gs.updated_at > gs.rag_indexed_at))';
        }
        $sql .= ' ORDER BY gs.id ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        try {
            $sources = DB::select($sql, $params);
        } catch (\Throwable $e) {
            $this->warn("Sources indexing skipped: {$e->getMessage()}");

            return 0;
        }

        if (empty($sources)) {
            $this->info('No sources to index.');

            return 0;
        }

        $this->info('Found '.count($sources).' sources to index.');
        $ragService = app(RAGService::class);
        $indexed = 0;
        $selectedSourceIds = array_map(static fn (object $source): int => (int) $source->id, $sources);

        if (! $dryRun) {
            $deleted = $this->removeExistingRagDocuments('genealogy_source', 'genealogy_source', $selectedSourceIds);
            if ($deleted > 0) {
                $this->info("Removed {$deleted} existing source RAG document(s) for selected batch.");
            }
        }

        foreach ($sources as $src) {
            $parts = ["Source: {$src->title}"];
            if ($src->author) {
                $parts[] = "Author: {$src->author}";
            }
            if ($src->publication) {
                $parts[] = "Publication: {$src->publication}";
            }
            if ($src->source_category) {
                $parts[] = "Category: {$src->source_category}";
            }
            if ($src->repository) {
                $parts[] = "Repository: {$src->repository}";
            }
            if ($src->url) {
                $parts[] = "URL: {$src->url}";
            }
            if ($src->notes) {
                $parts[] = "\nNotes: ".mb_substr($src->notes, 0, 500);
            }

            $content = implode("\n", $parts);
            if (strlen($content) < 20) {
                continue;
            }

            if ($dryRun) {
                $this->line("  Would index source: {$src->title}");
                $indexed++;

                continue;
            }

            try {
                $doc = $ragService->indexDocument(
                    'genealogy_source',
                    $content,
                    "Source: {$src->title}",
                    [
                        'source_id' => (int) $src->id,
                        'tree_id' => (int) $src->tree_id,
                        'category' => $src->source_category,
                    ],
                    $src->id,
                    'genealogy_source',
                    'genealogy',
                    null,
                    ['skip_dedup' => true]
                );

                if ($doc) {
                    DB::update('UPDATE genealogy_sources SET rag_indexed_at = NOW(), updated_at = updated_at WHERE id = ?', [$src->id]);
                    $indexed++;
                }
            } catch (\Throwable $e) {
                Log::warning('GenealogyRagIndex: Source indexing failed', ['id' => $src->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Sources: {$indexed} indexed.");

        return $indexed;
    }
}
