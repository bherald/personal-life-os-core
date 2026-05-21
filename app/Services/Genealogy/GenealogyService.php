<?php

namespace App\Services\Genealogy;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E20: Genealogy Service
 *
 * Main CRUD service for genealogy data management.
 * Uses RAW parameterized SQL only - no Eloquent/Query Builder.
 *
 * @see docs/future-enhancements.md E20
 */
class GenealogyService
{
    private ?GedcomParserService $parser = null;

    private ?GenealogyMediaService $mediaService = null;

    public function __construct()
    {
        // Parser is created when needed
    }

    /**
     * Set the media service for auto-importing media during GEDCOM import
     */
    public function setMediaService(GenealogyMediaService $mediaService): void
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Get parser instance
     */
    private function getParser(): GedcomParserService
    {
        if ($this->parser === null) {
            $this->parser = new GedcomParserService('');
        }

        return $this->parser;
    }

    // ========================================================================
    // TREE MANAGEMENT
    // ========================================================================

    /**
     * Create a new family tree
     */
    public function createTree(string $name, ?string $description = null): int
    {
        $sql = 'INSERT INTO genealogy_trees (name, description, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())';

        DB::insert($sql, [$name, $description]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get all family trees
     */
    public function listTrees(): array
    {
        $sql = "SELECT t.id, t.name, t.description, t.source_file, t.import_date,
                       t.person_count, t.family_count, t.media_count, t.source_count,
                       t.root_person_id,
                       CONCAT(p.given_name, ' ', p.surname) AS root_person_name,
                       p.birth_date AS root_person_birth_date,
                       t.created_at, t.updated_at
                FROM genealogy_trees t
                LEFT JOIN genealogy_persons p ON p.id = t.root_person_id
                ORDER BY t.name ASC";

        return DB::select($sql);
    }

    /**
     * Get a single tree by ID
     */
    public function getTree(int $treeId): ?object
    {
        $sql = 'SELECT id, name, description, source_file, import_date,
                       person_count, family_count, media_count, source_count,
                       created_at, updated_at
                FROM genealogy_trees
                WHERE id = ?';

        return DB::selectOne($sql, [$treeId]);
    }

    /**
     * Update tree details
     */
    public function updateTree(int $treeId, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['name', 'description'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $treeId;

        $sql = 'UPDATE genealogy_trees SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a tree and all its data
     */
    public function deleteTree(int $treeId): bool
    {
        // Foreign keys with CASCADE will handle related records
        $sql = 'DELETE FROM genealogy_trees WHERE id = ?';

        return DB::delete($sql, [$treeId]) > 0;
    }

    /**
     * Update tree statistics
     */
    public function updateTreeStats(int $treeId): void
    {
        $sql = 'UPDATE genealogy_trees SET
                    person_count = (SELECT COUNT(*) FROM genealogy_persons WHERE tree_id = ?),
                    family_count = (SELECT COUNT(*) FROM genealogy_families WHERE tree_id = ?),
                    media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?),
                    source_count = (SELECT COUNT(*) FROM genealogy_sources WHERE tree_id = ?),
                    updated_at = NOW()
                WHERE id = ?';

        DB::update($sql, [$treeId, $treeId, $treeId, $treeId, $treeId]);
    }

    // ========================================================================
    // GEDCOM IMPORT
    // ========================================================================

    /**
     * Import a GEDCOM file into a tree
     *
     * @param  string  $filePath  Path to GEDCOM file
     * @param  int|null  $treeId  Existing tree ID to import into
     * @param  string|null  $treeName  Name for new tree
     * @param  bool  $importMedia  Whether to auto-import media files from Windows
     * @param  string|null  $mediaBasePath  Base path on Windows for media files
     * @return array Import results
     */
    public function importGedcom(
        string $filePath,
        ?int $treeId = null,
        ?string $treeName = null,
        bool $importMedia = false,
        ?string $mediaBasePath = null
    ): array {
        $startTime = microtime(true);
        $results = [
            'success' => false,
            'tree_id' => null,
            'persons_imported' => 0,
            'families_imported' => 0,
            'media_imported' => 0,
            'sources_imported' => 0,
            'repositories_imported' => 0,
            'children_linked' => 0,
            'media_files_imported' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        try {
            // Parse the GEDCOM file
            $parser = new GedcomParserService($filePath);
            $data = $parser->parse();

            // Create or use existing tree
            if ($treeId) {
                $tree = $this->getTree($treeId);
                if (! $tree) {
                    throw new Exception("Tree ID {$treeId} not found");
                }
            } else {
                $name = $treeName ?? basename($filePath, '.ged');
                $treeId = $this->createTree($name, "Imported from {$filePath}");
            }
            $results['tree_id'] = $treeId;

            // Update tree source info
            $sql = 'UPDATE genealogy_trees SET source_file = ?, import_date = NOW(), updated_at = NOW() WHERE id = ?';
            DB::update($sql, [$filePath, $treeId]);

            // Build GEDCOM ID to database ID mappings
            $personIdMap = [];
            $familyIdMap = [];
            $mediaIdMap = [];
            $sourceIdMap = [];
            $repositoryIdMap = [];

            // Import repositories first (sources reference them)
            if (! empty($data['repositories'])) {
                foreach ($data['repositories'] as $repo) {
                    try {
                        $repoDbId = $this->importRepository($treeId, $repo);
                        $repositoryIdMap[$repo['gedcom_id']] = $repoDbId;
                        $results['repositories_imported']++;
                    } catch (Exception $e) {
                        $results['errors'][] = "Repository {$repo['gedcom_id']}: ".$e->getMessage();
                    }
                }
            }

            // Import sources (they're referenced by other records)
            foreach ($data['sources'] as $source) {
                try {
                    $sourceDbId = $this->importSource($treeId, $source);
                    $sourceIdMap[$source['gedcom_id']] = $sourceDbId;
                    $results['sources_imported']++;
                } catch (Exception $e) {
                    $results['errors'][] = "Source {$source['gedcom_id']}: ".$e->getMessage();
                }
            }

            // Import media records (metadata only, files imported later)
            foreach ($data['media'] as $media) {
                try {
                    $mediaDbId = $this->importMedia($treeId, $media);
                    $mediaIdMap[$media['gedcom_id']] = $mediaDbId;
                    $results['media_imported']++;
                } catch (Exception $e) {
                    $results['errors'][] = "Media {$media['gedcom_id']}: ".$e->getMessage();
                }
            }

            // Import persons with all their data
            foreach ($data['persons'] as $gedcomId => $person) {
                try {
                    $personDbId = $this->importPerson($treeId, $person);
                    $personIdMap[$person['gedcom_id']] = $personDbId;
                    $results['persons_imported']++;

                    // Link person to media references
                    if (! empty($person['media_refs'])) {
                        foreach ($person['media_refs'] as $mediaRef) {
                            if (isset($mediaIdMap[$mediaRef])) {
                                $this->linkPersonMedia($personDbId, $mediaIdMap[$mediaRef]);
                            }
                        }
                    }

                    // Import person sources
                    if (! empty($person['sources'])) {
                        foreach ($person['sources'] as $sourceRef) {
                            if (isset($sourceIdMap[$sourceRef])) {
                                $this->linkPersonSource($personDbId, $sourceIdMap[$sourceRef]);
                            }
                        }
                    }

                    // Import residences with full detail
                    if (! empty($person['residences'])) {
                        foreach ($person['residences'] as $residence) {
                            $this->importResidence($personDbId, $residence, $sourceIdMap);
                        }
                    }

                    // Import events with full detail
                    if (! empty($person['events'])) {
                        foreach ($person['events'] as $event) {
                            $this->importEvent($personDbId, $event, $sourceIdMap);
                        }
                    }
                } catch (Exception $e) {
                    $results['errors'][] = "Person {$person['gedcom_id']}: ".$e->getMessage();
                }
            }

            // Import families with all relationships
            foreach ($data['families'] as $gedcomId => $family) {
                try {
                    // Map spouse references using both husband_id/wife_id and husband/wife
                    $husbandGedcom = $family['husband_id'] ?? $family['husband'] ?? null;
                    $wifeGedcom = $family['wife_id'] ?? $family['wife'] ?? null;

                    $familyDbId = $this->importFamily($treeId, $family, $personIdMap);
                    $familyIdMap[$family['gedcom_id']] = $familyDbId;
                    $results['families_imported']++;

                    // Import children links with relationship types
                    if (! empty($family['children'])) {
                        foreach ($family['children'] as $childData) {
                            // Handle both simple ID and structured child data
                            $childGedcomId = is_array($childData) ? ($childData['id'] ?? null) : $childData;
                            if ($childGedcomId && isset($personIdMap[$childGedcomId])) {
                                $birthOrder = is_array($childData) ? ($childData['birth_order'] ?? null) : null;
                                $frel = is_array($childData) ? ($childData['frel'] ?? 'Natural') : 'Natural';
                                $mrel = is_array($childData) ? ($childData['mrel'] ?? 'Natural') : 'Natural';

                                $this->linkChildToFamily($familyDbId, $personIdMap[$childGedcomId], $birthOrder, $frel, $mrel);
                                $results['children_linked']++;
                            }
                        }
                    }

                    // Link family to media
                    if (! empty($family['media_refs'])) {
                        foreach ($family['media_refs'] as $mediaRef) {
                            if (isset($mediaIdMap[$mediaRef])) {
                                $this->linkFamilyMedia($familyDbId, $mediaIdMap[$mediaRef]);
                            }
                        }
                    }

                    // Link family to sources
                    if (! empty($family['sources'])) {
                        foreach ($family['sources'] as $sourceRef) {
                            if (isset($sourceIdMap[$sourceRef])) {
                                $this->linkFamilySource($familyDbId, $sourceIdMap[$sourceRef]);
                            }
                        }
                    }
                } catch (Exception $e) {
                    $results['errors'][] = "Family {$family['gedcom_id']}: ".$e->getMessage();
                }
            }

            // Import source-citation-media links (OBJE nested under SOUR in INDI records)
            if (! empty($data['source_citation_media'])) {
                $citationMediaImported = 0;
                foreach ($data['source_citation_media'] as $link) {
                    try {
                        $sourceDbId = $sourceIdMap[$link['source_gedcom_id']] ?? null;
                        $mediaDbId = $mediaIdMap[$link['media_gedcom_id']] ?? null;
                        $personDbId = isset($link['person_gedcom_id']) ? ($personIdMap[$link['person_gedcom_id']] ?? null) : null;

                        if ($sourceDbId && $mediaDbId) {
                            $this->linkSourceCitationMedia(
                                $sourceDbId,
                                $mediaDbId,
                                $personDbId,
                                $link['citation_page'] ?? null
                            );
                            $citationMediaImported++;
                        }
                    } catch (Exception $e) {
                        // Skip duplicates silently
                        if (strpos($e->getMessage(), 'Duplicate') === false) {
                            $results['warnings'][] = 'Citation media link: '.$e->getMessage();
                        }
                    }
                }
                $results['citation_media_imported'] = $citationMediaImported;
                Log::info("Imported {$citationMediaImported} source-citation-media links");
            }

            // Update tree statistics
            $this->updateTreeStats($treeId);

            // Auto-import media files if requested and media service is available
            if ($importMedia && $this->mediaService) {
                $tree = $this->getTree($treeId);
                if ($tree) {
                    Log::info("Starting auto-import of media files for tree {$treeId}");
                    $mediaResult = $this->mediaService->importTreeMedia(
                        $treeId,
                        $tree->name,
                        $mediaBasePath
                    );
                    $results['media_files_imported'] = $mediaResult['imported'] ?? 0;
                    if (! empty($mediaResult['errors'])) {
                        foreach ($mediaResult['errors'] as $error) {
                            $results['warnings'][] = 'Media import: '.(is_array($error) ? json_encode($error) : $error);
                        }
                    }
                }
            }

            $results['success'] = true;
            $results['duration_seconds'] = round(microtime(true) - $startTime, 2);

            Log::info('GEDCOM import completed', $results);

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            Log::error('GEDCOM import failed: '.$e->getMessage());
        }

        return $results;
    }

    /**
     * Import a repository record
     */
    private function importRepository(int $treeId, array $repo): int
    {
        $sql = 'INSERT INTO genealogy_repositories (
                    tree_id, gedcom_id, name, address, phone, email, url, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $repo['gedcom_id'],
            $repo['name'] ?? null,
            $repo['address'] ?? null,
            $repo['phone'] ?? null,
            $repo['email'] ?? null,
            $repo['url'] ?? null,
            $repo['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Link a person to a source
     */
    private function linkPersonSource(int $personId, int $sourceId, ?string $page = null, ?string $quality = null): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_person_sources WHERE person_id = ? AND source_id = ?',
            [$personId, $sourceId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_person_sources (person_id, source_id, page, quality, created_at)
                    VALUES (?, ?, ?, ?, NOW())';
            DB::insert($sql, [$personId, $sourceId, $page, $quality]);
        }
    }

    /**
     * Link source to media via citation
     * This handles OBJE nested under SOUR in GEDCOM citations
     */
    private function linkSourceCitationMedia(int $sourceId, int $mediaId, ?int $personId, ?string $page): void
    {
        // Check for existing link
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_citations
             WHERE source_id = ? AND media_id = ? AND (person_id = ? OR (person_id IS NULL AND ? IS NULL))',
            [$sourceId, $mediaId, $personId, $personId]
        );

        if (! $existing) {
            $sql = "INSERT INTO genealogy_citations
                    (source_id, media_id, person_id, page, fact_type, created_at)
                    VALUES (?, ?, ?, ?, 'source_media', NOW())";
            DB::insert($sql, [$sourceId, $mediaId, $personId, $page]);
        }
    }

    /**
     * Get sources linked to a person, including related media
     */
    public function getPersonSources(int $personId): array
    {
        $sql = 'SELECT s.*, ps.page as citation_page, ps.quality as citation_quality, ps.created_at as linked_at
                FROM genealogy_sources s
                INNER JOIN genealogy_person_sources ps ON s.id = ps.source_id
                WHERE ps.person_id = ?
                ORDER BY s.title';

        $sources = DB::select($sql, [$personId]);

        // For each source, find related media linked to this person that matches the source title
        foreach ($sources as $source) {
            $source->related_media = $this->getSourceRelatedMediaForPerson($source->id, $personId, $source->title);
        }

        return $sources;
    }

    /**
     * Get media related to a source for a specific person
     *
     * GEDCOM structure:
     * - Citations link sources to media (source_id + media_id, person_id may be null)
     * - person_media links persons to media directly
     *
     * For a person+source combo, show media that:
     * 1. Is directly cited for this person+source (citations with both person_id and media_id)
     * 2. OR is linked to source AND to person (via person_media)
     * 3. OR is linked to the source via any citation (for shared docs like census pages)
     */
    public function getSourceRelatedMediaForPerson(int $sourceId, int $personId, ?string $sourceTitle = null): array
    {
        // Method 1: Media directly cited for this person+source combination
        $directCitations = DB::select("
            SELECT DISTINCT m.id, m.title, m.media_type, m.nextcloud_path, m.local_filename, 'direct_citation' as link_type
            FROM genealogy_citations c
            JOIN genealogy_media m ON m.id = c.media_id
            WHERE c.source_id = ? AND c.person_id = ? AND c.media_id IS NOT NULL
        ", [$sourceId, $personId]);

        // Method 2: Media linked to source (via any citation) AND linked to this person (via person_media)
        $sharedMedia = DB::select("
            SELECT DISTINCT m.id, m.title, m.media_type, m.nextcloud_path, m.local_filename, 'source_person_shared' as link_type
            FROM genealogy_media m
            INNER JOIN genealogy_citations c ON c.media_id = m.id AND c.source_id = ?
            INNER JOIN genealogy_person_media pm ON pm.media_id = m.id AND pm.person_id = ?
        ", [$sourceId, $personId]);

        // Method 3: All media linked to this source via citations (for shared docs like census pages)
        // This shows the actual source document even if not in person_media
        $sourceMedia = DB::select("
            SELECT DISTINCT m.id, m.title, m.media_type, m.nextcloud_path, m.local_filename, 'source_document' as link_type
            FROM genealogy_citations c
            JOIN genealogy_media m ON m.id = c.media_id
            WHERE c.source_id = ? AND c.media_id IS NOT NULL
        ", [$sourceId]);

        // Merge results, avoiding duplicates (priority: direct > shared > source)
        $mediaById = [];
        foreach ($directCitations as $media) {
            $mediaById[$media->id] = $media;
        }
        foreach ($sharedMedia as $media) {
            if (! isset($mediaById[$media->id])) {
                $mediaById[$media->id] = $media;
            }
        }
        foreach ($sourceMedia as $media) {
            if (! isset($mediaById[$media->id])) {
                $mediaById[$media->id] = $media;
            }
        }

        // Add thumbnail URLs using API route
        foreach ($mediaById as $media) {
            $media->thumbnail_url = '/api/genealogy/media/'.$media->id.'/thumbnail';
        }

        return array_values($mediaById);
    }

    /**
     * Link a source to a person (public wrapper)
     */
    public function linkPersonSourcePublic(int $personId, int $sourceId, ?string $page = null, ?string $quality = null): void
    {
        $this->linkPersonSource($personId, $sourceId, $page, $quality);
    }

    /**
     * Unlink a source from a person
     */
    public function unlinkPersonSource(int $personId, int $sourceId): bool
    {
        $deleted = DB::delete(
            'DELETE FROM genealogy_person_sources WHERE person_id = ? AND source_id = ?',
            [$personId, $sourceId]
        );

        return $deleted > 0;
    }

    /**
     * Link a family to media
     */
    private function linkFamilyMedia(int $familyId, int $mediaId): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?',
            [$familyId, $mediaId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())';
            DB::insert($sql, [$familyId, $mediaId]);
        }
    }

    /**
     * Link a family to a source
     */
    private function linkFamilySource(int $familyId, int $sourceId, ?string $page = null): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_family_sources WHERE family_id = ? AND source_id = ?',
            [$familyId, $sourceId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_family_sources (family_id, source_id, page, created_at)
                    VALUES (?, ?, ?, NOW())';
            DB::insert($sql, [$familyId, $sourceId, $page]);
        }
    }

    /**
     * Import a single person record
     */
    private function importPerson(int $treeId, array $person): int
    {
        $sql = 'INSERT INTO genealogy_persons (
                    tree_id, gedcom_id, given_name, surname, suffix, nickname, sex,
                    birth_date, birth_place, birth_lat, birth_lon,
                    death_date, death_place, death_lat, death_lon,
                    burial_date, burial_place, burial_lat, burial_lon,
                    occupation, education, religion, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $person['gedcom_id'],
            $person['given_name'] ?? null,
            $person['surname'] ?? null,
            $person['suffix'] ?? null,
            $person['nickname'] ?? null,
            $person['sex'] ?? null,
            $person['birth_date'] ?? null,
            $person['birth_place'] ?? null,
            $person['birth_lat'] ?? null,
            $person['birth_lon'] ?? null,
            $person['death_date'] ?? null,
            $person['death_place'] ?? null,
            $person['death_lat'] ?? null,
            $person['death_lon'] ?? null,
            $person['burial_date'] ?? null,
            $person['burial_place'] ?? null,
            $person['burial_lat'] ?? null,
            $person['burial_lon'] ?? null,
            $person['occupation'] ?? null,
            $person['education'] ?? null,
            $person['religion'] ?? null,
            $person['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Import a single family record
     */
    private function importFamily(int $treeId, array $family, array $personIdMap): int
    {
        // Parser stores as husband_id/wife_id (GEDCOM IDs), map to database IDs
        $husbandGedcom = $family['husband_id'] ?? $family['husband'] ?? null;
        $wifeGedcom = $family['wife_id'] ?? $family['wife'] ?? null;

        $husbandId = $husbandGedcom && isset($personIdMap[$husbandGedcom])
            ? $personIdMap[$husbandGedcom]
            : null;

        $wifeId = $wifeGedcom && isset($personIdMap[$wifeGedcom])
            ? $personIdMap[$wifeGedcom]
            : null;

        $sql = 'INSERT INTO genealogy_families (
                    tree_id, gedcom_id, husband_id, wife_id,
                    marriage_date, marriage_place, marriage_lat, marriage_lon,
                    divorce_date, divorce_place, annulment_date, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $family['gedcom_id'],
            $husbandId,
            $wifeId,
            $family['marriage_date'] ?? null,
            $family['marriage_place'] ?? null,
            $family['marriage_lat'] ?? null,
            $family['marriage_lon'] ?? null,
            $family['divorce_date'] ?? null,
            $family['divorce_place'] ?? null,
            $family['annulment_date'] ?? null,
            $family['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Import a single media record
     */
    private function importMedia(int $treeId, array $media): int
    {
        $sql = 'INSERT INTO genealogy_media (
                    tree_id, gedcom_id, original_path, file_format, title,
                    media_date, description, media_type,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        // Get file path - parser uses 'file_path', support both for backwards compatibility
        $filePath = $media['file_path'] ?? $media['file'] ?? null;

        // Detect media type from file extension
        $mediaType = 'photo';
        if (! empty($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx', 'txt'])) {
                $mediaType = 'document';
            }
        }

        // Get file format - parser uses 'file_format', support both for backwards compatibility
        $fileFormat = $media['file_format'] ?? $media['format'] ?? null;

        // Get media date - parser uses 'media_date', support both for backwards compatibility
        $mediaDate = $media['media_date'] ?? $media['date'] ?? null;

        // Get description - parser uses 'description', support both for backwards compatibility
        $description = $media['description'] ?? $media['note'] ?? null;

        DB::insert($sql, [
            $treeId,
            $media['gedcom_id'],
            $filePath,
            $fileFormat,
            $media['title'] ?? null,
            $mediaDate,
            $description,
            $mediaType,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Import a single source record
     */
    private function importSource(int $treeId, array $source): int
    {
        $sql = 'INSERT INTO genealogy_sources (
                    tree_id, gedcom_id, author, title, publication,
                    repository, url, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $source['gedcom_id'],
            $source['author'] ?? null,
            $source['title'] ?? null,
            $source['publication'] ?? null,
            $source['repository'] ?? null,
            $source['url'] ?? null,
            $source['text'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Link a child to a family
     */
    private function linkChildToFamily(int $familyId, int $personId, ?int $birthOrder = null): void
    {
        // Check if link already exists
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_children WHERE family_id = ? AND person_id = ?',
            [$familyId, $personId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_children (family_id, person_id, birth_order, created_at)
                    VALUES (?, ?, ?, NOW())';
            DB::insert($sql, [$familyId, $personId, $birthOrder]);
        }
    }

    /**
     * Link a person to media
     */
    private function linkPersonMedia(int $personId, int $mediaId, bool $isPrimary = false): void
    {
        // Check if link already exists
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $mediaId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_person_media (person_id, media_id, is_primary, created_at)
                    VALUES (?, ?, ?, NOW())';
            DB::insert($sql, [$personId, $mediaId, $isPrimary ? 1 : 0]);
        }
    }

    /**
     * Import a residence record
     */
    private function importResidence(int $personId, array $residence, array $sourceIdMap): void
    {
        $sourceId = isset($residence['source']) && isset($sourceIdMap[$residence['source']])
            ? $sourceIdMap[$residence['source']]
            : null;

        $sql = 'INSERT INTO genealogy_residences (
                    person_id, residence_date, place, latitude, longitude, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $personId,
            $residence['date'] ?? null,
            $residence['place'] ?? null,
            $residence['lat'] ?? null,
            $residence['lon'] ?? null,
            $sourceId,
        ]);
    }

    /**
     * Import an event record
     */
    private function importEvent(int $personId, array $event, array $sourceIdMap): void
    {
        $sourceId = isset($event['source']) && isset($sourceIdMap[$event['source']])
            ? $sourceIdMap[$event['source']]
            : null;

        $sql = 'INSERT INTO genealogy_events (
                    person_id, event_type, event_date, event_place,
                    latitude, longitude, description, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $personId,
            $event['type'] ?? 'unknown',
            $event['date'] ?? null,
            $event['place'] ?? null,
            $event['lat'] ?? null,
            $event['lon'] ?? null,
            $event['description'] ?? null,
            $sourceId,
        ]);
    }

    // ========================================================================
    // PERSON CRUD
    // ========================================================================

    /**
     * Get a person by ID with full details
     */
    public function getPerson(int $personId): ?array
    {
        $sql = 'SELECT p.*, t.name as tree_name
                FROM genealogy_persons p
                JOIN genealogy_trees t ON t.id = p.tree_id
                WHERE p.id = ?';

        $person = DB::selectOne($sql, [$personId]);
        if (! $person) {
            return null;
        }

        $result = (array) $person;

        // Get families where person is spouse
        $result['families_as_spouse'] = $this->getPersonFamiliesAsSpouse($personId);

        // Get family where person is child
        $result['family_as_child'] = $this->getPersonFamilyAsChild($personId);

        // Get siblings (other children in same family)
        $result['siblings'] = $this->getPersonSiblings($personId);

        // Get media
        $result['media'] = $this->getPersonMedia($personId);

        // Get residences
        $result['residences'] = $this->getPersonResidences($personId);

        // Get events
        $result['events'] = $this->getPersonEvents($personId);

        // Add primary photo URL
        $result['primary_photo_url'] = $this->getPrimaryPhotoUrl($result['primary_photo_id'] ?? null);

        // Name variants
        $result['name_variants'] = DB::select('
            SELECT id, name_type, given_names, surname, full_name, notes, created_at
            FROM genealogy_name_variants
            WHERE person_id = ?
            ORDER BY created_at ASC
        ', [$personId]);

        // External IDs (FamilySearch, Ancestry, FindAGrave, etc.)
        $result['external_ids'] = DB::select("
            SELECT id, id_type, external_id, created_at
            FROM genealogy_external_ids
            WHERE record_type = 'person' AND record_id = ?
            ORDER BY id_type
        ", [$personId]);

        // External service links
        $result['external_links'] = $this->getPersonExternalLinks($personId);

        // Sources linked to this person
        $result['sources'] = $this->getPersonSources($personId);

        return $result;
    }

    /**
     * Get the thumbnail URL for a primary photo
     *
     * @param  int|null  $mediaId  The media ID
     * @return string|null The thumbnail URL or null
     */
    public function getPrimaryPhotoUrl(?int $mediaId): ?string
    {
        if (! $mediaId) {
            return null;
        }

        // Return the API endpoint for thumbnail - this works whether media is on Nextcloud or local
        return "/api/genealogy/media/{$mediaId}/thumbnail";
    }

    /**
     * N101: Get extended person data including name variants, external IDs, research coverage.
     * Used by the get_person_full agent tool.
     */
    public function getPersonFull(int $personId): ?array
    {
        $base = $this->getPerson($personId);
        if (! $base) {
            return null;
        }

        // Name variants
        $base['name_variants'] = DB::select('
            SELECT id, name_type, given_names, surname, full_name, notes, created_at
            FROM genealogy_name_variants
            WHERE person_id = ?
            ORDER BY created_at ASC
        ', [$personId]);

        // External IDs (FamilySearch, Ancestry, FindAGrave, etc.)
        $base['external_ids'] = DB::select("
            SELECT id, id_type, external_id, created_at
            FROM genealogy_external_ids
            WHERE record_type = 'person' AND record_id = ?
            ORDER BY id_type
        ", [$personId]);

        // GPS research tasks
        $base['research_tasks'] = DB::select('
            SELECT id, task_type, question, status, priority, conclusion, created_at, updated_at
            FROM gps_research_tasks
            WHERE person_id = ?
            ORDER BY priority DESC, created_at DESC
            LIMIT 10
        ', [$personId]);

        // Research coverage per repository (negative evidence map — GPS Element 1)
        $base['research_coverage'] = DB::select('
            SELECT repository_searched,
                   COUNT(*) AS total_searches,
                   SUM(negative_result) AS negative_count,
                   SUM(CASE WHEN negative_result = 0 THEN 1 ELSE 0 END) AS positive_count,
                   MAX(searched_at) AS last_searched
            FROM gps_research_logs
            WHERE person_id = ?
            GROUP BY repository_searched
        ', [$personId]);

        return $base;
    }

    /**
     * Set or unset the primary photo for a person
     *
     * @param  int  $personId  The person ID
     * @param  int|null  $mediaId  The media ID to set as primary, or null to unset
     * @return array Result with success status and updated person data
     */
    public function setPersonPrimaryPhoto(int $personId, ?int $mediaId): array
    {
        // Verify person exists
        $person = DB::selectOne('SELECT id, given_name, surname FROM genealogy_persons WHERE id = ?', [$personId]);
        if (! $person) {
            return ['success' => false, 'error' => 'Person not found'];
        }

        // If setting a photo, verify media exists and is linked to this person
        if ($mediaId !== null) {
            $mediaLink = DB::selectOne(
                "SELECT pm.id FROM genealogy_person_media pm
                 JOIN genealogy_media m ON m.id = pm.media_id
                 WHERE pm.person_id = ? AND pm.media_id = ? AND m.media_type = 'photo'",
                [$personId, $mediaId]
            );

            if (! $mediaLink) {
                return ['success' => false, 'error' => 'Media not found or not linked to this person'];
            }
        }

        DB::beginTransaction();
        try {
            // Clear existing is_primary flags for this person
            DB::update(
                'UPDATE genealogy_person_media SET is_primary = 0 WHERE person_id = ?',
                [$personId]
            );

            // Update person's primary_photo_id
            DB::update(
                'UPDATE genealogy_persons SET primary_photo_id = ? WHERE id = ?',
                [$mediaId, $personId]
            );

            // If setting a new primary, mark it in the junction table
            if ($mediaId !== null) {
                DB::update(
                    'UPDATE genealogy_person_media SET is_primary = 1 WHERE person_id = ? AND media_id = ?',
                    [$personId, $mediaId]
                );
            }

            DB::commit();

            // Return updated person data
            $updatedPerson = $this->getPerson($personId);

            return [
                'success' => true,
                'person' => $updatedPerson,
                'message' => $mediaId !== null
                    ? 'Primary photo set successfully'
                    : 'Primary photo removed successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return ['success' => false, 'error' => 'Failed to update primary photo: '.$e->getMessage()];
        }
    }

    /**
     * Get families where person is a spouse
     */
    public function getPersonFamiliesAsSpouse(int $personId): array
    {
        $sql = 'SELECT f.*,
                       h.id as husband_db_id, h.given_name as husband_given, h.surname as husband_surname,
                       w.id as wife_db_id, w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY f.marriage_date';

        $families = DB::select($sql, [$personId, $personId]);

        // Get children for each family
        foreach ($families as &$family) {
            $family->children = $this->getFamilyChildren($family->id);
        }

        return $families;
    }

    /**
     * Get family where person is a child
     */
    public function getPersonFamilyAsChild(int $personId): ?object
    {
        $sql = 'SELECT f.*, c.father_relationship, c.mother_relationship, c.birth_order,
                       h.id as father_id, h.given_name as father_given, h.surname as father_surname,
                       w.id as mother_id, w.given_name as mother_given, w.surname as mother_surname
                FROM genealogy_children c
                JOIN genealogy_families f ON f.id = c.family_id
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE c.person_id = ?';

        return DB::selectOne($sql, [$personId]);
    }

    /**
     * Get siblings of a person (other children in the same family)
     */
    public function getPersonSiblings(int $personId): array
    {
        // First get the family where this person is a child
        $childLink = DB::selectOne(
            'SELECT family_id FROM genealogy_children WHERE person_id = ?',
            [$personId]
        );

        if (! $childLink) {
            return [];
        }

        // Get other children in the same family (excluding the person themselves)
        $sql = 'SELECT p.id, p.given_name, p.surname, p.sex, p.birth_date, p.death_date,
                       c.birth_order
                FROM genealogy_children c
                JOIN genealogy_persons p ON p.id = c.person_id
                WHERE c.family_id = ? AND p.id != ?
                ORDER BY c.birth_order, p.birth_date, p.id';

        return DB::select($sql, [$childLink->family_id, $personId]);
    }

    /**
     * Get children of a family
     */
    public function getFamilyChildren(int $familyId): array
    {
        $sql = 'SELECT p.id, p.given_name, p.surname, p.sex, p.birth_date, p.death_date,
                       c.birth_order, c.father_relationship, c.mother_relationship
                FROM genealogy_children c
                JOIN genealogy_persons p ON p.id = c.person_id
                WHERE c.family_id = ?
                ORDER BY c.birth_order, p.birth_date';

        return DB::select($sql, [$familyId]);
    }

    /**
     * Get media for a person
     */
    public function getPersonMedia(int $personId): array
    {
        $sql = 'SELECT m.*, pm.is_primary, pm.face_region_x, pm.face_region_y,
                       pm.face_region_w, pm.face_region_h, pm.face_confirmed, pm.notes as link_notes
                FROM genealogy_person_media pm
                JOIN genealogy_media m ON m.id = pm.media_id
                WHERE pm.person_id = ? AND m.file_exists = 1
                ORDER BY pm.is_primary DESC, m.media_date';

        return DB::select($sql, [$personId]);
    }

    /**
     * Get residences for a person
     */
    public function getPersonResidences(int $personId): array
    {
        $sql = 'SELECT r.*, s.title as source_title
                FROM genealogy_residences r
                LEFT JOIN genealogy_sources s ON s.id = r.source_id
                WHERE r.person_id = ?
                ORDER BY r.residence_date';

        return DB::select($sql, [$personId]);
    }

    /**
     * Get a single residence
     */
    public function getResidence(int $id): ?object
    {
        $sql = 'SELECT r.*, s.title as source_title
                FROM genealogy_residences r
                LEFT JOIN genealogy_sources s ON s.id = r.source_id
                WHERE r.id = ?';
        $result = DB::select($sql, [$id]);

        return $result[0] ?? null;
    }

    /**
     * Create a new residence
     */
    public function createResidence(int $personId, array $data): object
    {
        $sql = 'INSERT INTO genealogy_residences (person_id, residence_date, place, latitude, longitude, source_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())';
        DB::insert($sql, [
            $personId,
            $data['residence_date'] ?? null,
            $data['place'],
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['source_id'] ?? null,
        ]);

        $id = DB::getPdo()->lastInsertId();

        return $this->getResidence($id);
    }

    /**
     * Update a residence
     */
    public function updateResidence(int $id, array $data): ?object
    {
        $residence = $this->getResidence($id);
        if (! $residence) {
            return null;
        }

        $sql = 'UPDATE genealogy_residences SET
                residence_date = ?, place = ?, latitude = ?, longitude = ?, source_id = ?
                WHERE id = ?';
        DB::update($sql, [
            $data['residence_date'] ?? null,
            $data['place'],
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['source_id'] ?? null,
            $id,
        ]);

        return $this->getResidence($id);
    }

    /**
     * Delete a residence
     */
    public function deleteResidence(int $id): bool
    {
        $sql = 'DELETE FROM genealogy_residences WHERE id = ?';

        return DB::delete($sql, [$id]) > 0;
    }

    /**
     * Get events for a person
     */
    public function getPersonEvents(int $personId): array
    {
        $sql = 'SELECT e.*, s.title as source_title
                FROM genealogy_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                WHERE e.person_id = ?
                ORDER BY e.event_date';

        return DB::select($sql, [$personId]);
    }

    // ========================================================================
    // EVENT CRUD (Phase 2.1)
    // ========================================================================

    /**
     * GEDCOM 5.5.1 Event Types Reference:
     * Individual Events: CHR (Christening), BAPM (Baptism), CONF (Confirmation),
     *   GRAD (Graduation), EMIG (Emigration), IMMI (Immigration), NATU (Naturalization),
     *   RETI (Retirement), CENS (Census), PROB (Probate), WILL, EVEN (Custom)
     *   BARM (Bar Mitzvah), BASM (Bas Mitzvah), BLES (Blessing), CHRA (Adult Christening),
     *   FCOM (First Communion), ORDN (Ordination), CREM (Cremation), ADOP (Adoption)
     */
    public const EVENT_TYPES = [
        'CHR' => 'Christening',
        'BAPM' => 'Baptism',
        'CONF' => 'Confirmation',
        'BARM' => 'Bar Mitzvah',
        'BASM' => 'Bas Mitzvah',
        'BLES' => 'Blessing',
        'CHRA' => 'Adult Christening',
        'FCOM' => 'First Communion',
        'ORDN' => 'Ordination',
        'GRAD' => 'Graduation',
        'EMIG' => 'Emigration',
        'IMMI' => 'Immigration',
        'NATU' => 'Naturalization',
        'RETI' => 'Retirement',
        'CENS' => 'Census',
        'PROB' => 'Probate',
        'WILL' => 'Will',
        'CREM' => 'Cremation',
        'ADOP' => 'Adoption',
        'EVEN' => 'Custom Event',
        'MIL' => 'Military Service',
        'EDUC' => 'Education',
        'OCCU' => 'Occupation',
    ];

    /**
     * Get a single event by ID
     */
    public function getEvent(int $eventId): ?object
    {
        $sql = 'SELECT e.*, s.title as source_title, p.given_name, p.surname
                FROM genealogy_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                LEFT JOIN genealogy_persons p ON p.id = e.person_id
                WHERE e.id = ?';

        return DB::selectOne($sql, [$eventId]);
    }

    /**
     * Create a new event for a person
     */
    public function createEvent(int $personId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_events (
                    person_id, event_type, event_date, event_place,
                    latitude, longitude, description, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $personId,
            $data['event_type'] ?? 'EVEN',
            $data['event_date'] ?? null,
            $data['event_place'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['description'] ?? null,
            $data['source_id'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update an event
     */
    public function updateEvent(int $eventId, array $data): bool
    {
        $allowedFields = [
            'event_type', 'event_date', 'event_place',
            'latitude', 'longitude', 'description', 'source_id',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $eventId;

        $sql = 'UPDATE genealogy_events SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete an event
     */
    public function deleteEvent(int $eventId): bool
    {
        $sql = 'DELETE FROM genealogy_events WHERE id = ?';

        return DB::delete($sql, [$eventId]) > 0;
    }

    /**
     * Get all event types (for dropdown)
     */
    public function getEventTypes(): array
    {
        return self::EVENT_TYPES;
    }

    // ========================================================================
    // FAMILY EVENT CRUD (Phase 2.3)
    // ========================================================================

    /**
     * GEDCOM 5.5.1 Family Event Types Reference:
     * MARB - Marriage Bann (public announcement of upcoming marriage)
     * MARC - Marriage Contract (formal agreement before marriage)
     * MARL - Marriage License (legal document authorizing marriage)
     * MARS - Marriage Settlement (property agreement)
     * ENGA - Engagement (formal betrothal)
     * ANUL - Annulment (legal invalidation of marriage)
     * CENS - Census (family census record)
     * EVEN - Custom Event
     */
    public const FAMILY_EVENT_TYPES = [
        'MARB' => 'Marriage Bann',
        'MARC' => 'Marriage Contract',
        'MARL' => 'Marriage License',
        'MARS' => 'Marriage Settlement',
        'ENGA' => 'Engagement',
        'ANUL' => 'Annulment',
        'CENS' => 'Census',
        'EVEN' => 'Custom Event',
    ];

    /**
     * Get all family events for a family
     */
    public function getFamilyEvents(int $familyId): array
    {
        $sql = 'SELECT e.*, s.title as source_title
                FROM genealogy_family_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                WHERE e.family_id = ?
                ORDER BY e.event_date';

        return DB::select($sql, [$familyId]);
    }

    /**
     * Get a single family event by ID
     */
    public function getFamilyEvent(int $eventId): ?object
    {
        $sql = 'SELECT e.*, s.title as source_title,
                       f.id as family_id,
                       h.given_name as husband_given, h.surname as husband_surname,
                       w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_family_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                LEFT JOIN genealogy_families f ON f.id = e.family_id
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE e.id = ?';

        return DB::selectOne($sql, [$eventId]);
    }

    /**
     * Create a new family event
     */
    public function createFamilyEvent(int $familyId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_family_events (
                    family_id, event_type, event_date, event_place,
                    latitude, longitude, description, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $familyId,
            $data['event_type'] ?? 'EVEN',
            $data['event_date'] ?? null,
            $data['event_place'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['description'] ?? null,
            $data['source_id'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a family event
     */
    public function updateFamilyEvent(int $eventId, array $data): bool
    {
        $allowedFields = [
            'event_type', 'event_date', 'event_place',
            'latitude', 'longitude', 'description', 'source_id',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $eventId;

        $sql = 'UPDATE genealogy_family_events SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a family event
     */
    public function deleteFamilyEvent(int $eventId): bool
    {
        $sql = 'DELETE FROM genealogy_family_events WHERE id = ?';

        return DB::delete($sql, [$eventId]) > 0;
    }

    /**
     * Get all family event types (for dropdown)
     */
    public function getFamilyEventTypes(): array
    {
        return self::FAMILY_EVENT_TYPES;
    }

    // ========================================================================
    // SOURCE CRUD (Phase 2.4)
    // ========================================================================

    /**
     * Get all sources for a tree
     */
    public function getSources(int $treeId, int $limit = 100): array
    {
        $sql = 'SELECT s.*,
                       (SELECT COUNT(*) FROM genealogy_citations WHERE source_id = s.id) as citation_count,
                       (SELECT COUNT(*) FROM genealogy_person_sources WHERE source_id = s.id) as person_link_count,
                       (SELECT COUNT(*) FROM genealogy_family_sources WHERE source_id = s.id) as family_link_count
                FROM genealogy_sources s
                WHERE s.tree_id = ?
                ORDER BY s.title
                LIMIT ?';

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * Search sources in a tree
     */
    public function searchSources(int $treeId, string $query, int $limit = 50): array
    {
        $searchTerm = '%'.$query.'%';

        $sql = 'SELECT id, gedcom_id, author, title, publication, repository, url
                FROM genealogy_sources
                WHERE tree_id = ?
                  AND (
                      title LIKE ?
                      OR author LIKE ?
                      OR publication LIKE ?
                      OR repository LIKE ?
                  )
                ORDER BY title
                LIMIT ?';

        return DB::select($sql, [$treeId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    }

    /**
     * Get a single source by ID
     */
    public function getSource(int $sourceId): ?array
    {
        $sql = 'SELECT s.*
                FROM genealogy_sources s
                WHERE s.id = ?';

        $source = DB::selectOne($sql, [$sourceId]);
        if (! $source) {
            return null;
        }

        $result = (array) $source;

        // Get citation count
        $result['citation_count'] = (int) DB::selectOne(
            'SELECT COUNT(*) as cnt FROM genealogy_citations WHERE source_id = ?',
            [$sourceId]
        )->cnt;

        // Get linked persons
        $result['linked_persons'] = DB::select(
            'SELECT p.id, p.given_name, p.surname, ps.page, ps.quality
             FROM genealogy_person_sources ps
             JOIN genealogy_persons p ON p.id = ps.person_id
             WHERE ps.source_id = ?
             ORDER BY p.surname, p.given_name',
            [$sourceId]
        );

        // Get linked families
        $result['linked_families'] = DB::select(
            'SELECT f.id, f.gedcom_id,
                    h.given_name as husband_given, h.surname as husband_surname,
                    w.given_name as wife_given, w.surname as wife_surname,
                    fs.page
             FROM genealogy_family_sources fs
             JOIN genealogy_families f ON f.id = fs.family_id
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
             WHERE fs.source_id = ?',
            [$sourceId]
        );

        return $result;
    }

    /**
     * Create a new source
     */
    public function createSource(int $treeId, array $data): int
    {
        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'S');

        $sql = 'INSERT INTO genealogy_sources (
                    tree_id, gedcom_id, author, title, publication,
                    repository, repository_address, call_number, url, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['author'] ?? null,
            $data['title'] ?? null,
            $data['publication'] ?? null,
            $data['repository'] ?? null,
            $data['repository_address'] ?? null,
            $data['call_number'] ?? null,
            $data['url'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a source
     */
    public function updateSource(int $sourceId, array $data): bool
    {
        $allowedFields = [
            'author', 'title', 'publication', 'repository',
            'repository_address', 'call_number', 'url', 'notes',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $sourceId;

        $sql = 'UPDATE genealogy_sources SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a source
     */
    public function deleteSource(int $sourceId): bool
    {
        // The foreign keys should handle cascading deletes for citations and links
        $sql = 'DELETE FROM genealogy_sources WHERE id = ?';

        return DB::delete($sql, [$sourceId]) > 0;
    }

    // ==================== CITATION METHODS (Phase 2.5) ====================

    /**
     * Citation fact types - events and attributes that can be cited
     */
    public const CITATION_FACT_TYPES = [
        // Vital Events
        'BIRT' => 'Birth',
        'DEAT' => 'Death',
        'BURI' => 'Burial',
        'CREM' => 'Cremation',
        // Religious Events
        'CHR' => 'Christening',
        'BAPM' => 'Baptism',
        'CONF' => 'Confirmation',
        'FCOM' => 'First Communion',
        'ORDN' => 'Ordination',
        'BARM' => 'Bar Mitzvah',
        'BASM' => 'Bas Mitzvah',
        // Life Events
        'GRAD' => 'Graduation',
        'RETI' => 'Retirement',
        'ADOP' => 'Adoption',
        'NATU' => 'Naturalization',
        'EMIG' => 'Emigration',
        'IMMI' => 'Immigration',
        // Records
        'CENS' => 'Census',
        'PROB' => 'Probate',
        'WILL' => 'Will',
        'MIL' => 'Military Service',
        // Attributes
        'OCCU' => 'Occupation',
        'EDUC' => 'Education',
        'RESI' => 'Residence',
        'RELI' => 'Religion',
        'TITL' => 'Title',
        // Family Events
        'MARR' => 'Marriage',
        'DIV' => 'Divorce',
        'ENGA' => 'Engagement',
        'MARB' => 'Marriage Bann',
        'MARC' => 'Marriage Contract',
        'MARL' => 'Marriage License',
        'MARS' => 'Marriage Settlement',
        'ANUL' => 'Annulment',
        // General
        'EVEN' => 'Custom Event',
        'NOTE' => 'Note',
        'PHOT' => 'Photograph',
    ];

    /**
     * Citation quality levels (per GEDCOM standard)
     */
    public const CITATION_QUALITY_LEVELS = [
        0 => 'Unreliable evidence or estimated data',
        1 => 'Questionable reliability of evidence',
        2 => 'Secondary evidence, data officially recorded sometime after event',
        3 => 'Direct and primary evidence, or by dominance of evidence',
    ];

    /**
     * Get citations for a person (all facts)
     */
    public function getPersonCitations(int $personId): array
    {
        $sql = 'SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON c.source_id = s.id
                WHERE c.person_id = ?
                ORDER BY c.fact_type, c.created_at';

        return DB::select($sql, [$personId]);
    }

    /**
     * Get citations for a family (all facts)
     */
    public function getFamilyCitations(int $familyId): array
    {
        $sql = 'SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON c.source_id = s.id
                WHERE c.family_id = ?
                ORDER BY c.fact_type, c.created_at';

        return DB::select($sql, [$familyId]);
    }

    /**
     * Get citations for a specific source
     */
    public function getSourceCitations(int $sourceId): array
    {
        // First get the source to get its title for matching
        $source = DB::selectOne('SELECT title FROM genealogy_sources WHERE id = ?', [$sourceId]);
        $sourceTitle = $source ? $source->title : null;

        $sql = "SELECT c.*,
                       p.given_name, p.surname,
                       m.id as m_id, m.title as media_title, m.media_type, m.nextcloud_path, m.local_filename,
                       CASE
                           WHEN c.person_id IS NOT NULL THEN 'person'
                           WHEN c.family_id IS NOT NULL THEN 'family'
                           WHEN c.media_id IS NOT NULL THEN 'media'
                           ELSE 'unknown'
                       END as entity_type
                FROM genealogy_citations c
                LEFT JOIN genealogy_persons p ON c.person_id = p.id
                LEFT JOIN genealogy_media m ON c.media_id = m.id
                WHERE c.source_id = ?
                ORDER BY c.fact_type, c.created_at";

        $citations = DB::select($sql, [$sourceId]);

        // Structure media as nested object for citations with media
        foreach ($citations as $citation) {
            if ($citation->media_id) {
                // Build thumbnail URL using API route
                $citation->media = (object) [
                    'id' => $citation->media_id,
                    'title' => $citation->media_title,
                    'media_type' => $citation->media_type,
                    'nextcloud_path' => $citation->nextcloud_path,
                    'thumbnail_url' => '/api/genealogy/media/'.$citation->media_id.'/thumbnail',
                ];
            } else {
                $citation->media = null;
            }

            // For person citations without direct media, find related media from person_media
            if ($citation->person_id && ! $citation->media_id) {
                $citation->related_media = $this->getSourceRelatedMediaForPerson($sourceId, $citation->person_id, $sourceTitle);
            } else {
                $citation->related_media = [];
            }
        }

        return $citations;
    }

    /**
     * Get a single citation
     */
    public function getCitation(int $citationId): ?object
    {
        $sql = 'SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON c.source_id = s.id
                WHERE c.id = ?';

        $result = DB::select($sql, [$citationId]);

        return $result[0] ?? null;
    }

    /**
     * Create a citation linking a source to a fact
     */
    public function createCitation(array $data): int
    {
        $sql = 'INSERT INTO genealogy_citations (source_id, person_id, family_id, media_id, fact_type, page, quality, text)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        DB::insert($sql, [
            $data['source_id'],
            $data['person_id'] ?? null,
            $data['family_id'] ?? null,
            $data['media_id'] ?? null,
            $data['fact_type'] ?? null,
            $data['page'] ?? null,
            $data['quality'] ?? null,
            $data['text'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a citation
     */
    public function updateCitation(int $citationId, array $data): bool
    {
        $sql = 'UPDATE genealogy_citations
                SET source_id = ?,
                    person_id = ?,
                    family_id = ?,
                    media_id = ?,
                    fact_type = ?,
                    page = ?,
                    quality = ?,
                    text = ?
                WHERE id = ?';

        return DB::update($sql, [
            $data['source_id'],
            $data['person_id'] ?? null,
            $data['family_id'] ?? null,
            $data['media_id'] ?? null,
            $data['fact_type'] ?? null,
            $data['page'] ?? null,
            $data['quality'] ?? null,
            $data['text'] ?? null,
            $citationId,
        ]) >= 0;
    }

    /**
     * Delete a citation
     */
    public function deleteCitation(int $citationId): bool
    {
        $sql = 'DELETE FROM genealogy_citations WHERE id = ?';

        return DB::delete($sql, [$citationId]) > 0;
    }

    /**
     * Get citation fact types
     */
    public function getCitationFactTypes(): array
    {
        return self::CITATION_FACT_TYPES;
    }

    /**
     * Get citation quality levels
     */
    public function getCitationQualityLevels(): array
    {
        return self::CITATION_QUALITY_LEVELS;
    }

    // ==================== REPOSITORY METHODS (Phase 2.6) ====================

    /**
     * Get all repositories for a tree
     */
    public function getRepositories(int $treeId): array
    {
        $sql = 'SELECT r.*,
                       (SELECT COUNT(*) FROM genealogy_sources s WHERE s.repository = r.name AND s.tree_id = r.tree_id) as source_count
                FROM genealogy_repositories r
                WHERE r.tree_id = ?
                ORDER BY r.name';

        return DB::select($sql, [$treeId]);
    }

    /**
     * Get a single repository by ID
     */
    public function getRepository(int $repositoryId): ?object
    {
        $sql = 'SELECT r.*,
                       (SELECT COUNT(*) FROM genealogy_sources s WHERE s.repository = r.name AND s.tree_id = r.tree_id) as source_count
                FROM genealogy_repositories r
                WHERE r.id = ?';

        $result = DB::select($sql, [$repositoryId]);
        $repository = $result[0] ?? null;

        if ($repository) {
            // Get linked sources
            $sourcesSql = 'SELECT s.id, s.title, s.author
                          FROM genealogy_sources s
                          WHERE s.repository = ? AND s.tree_id = ?
                          ORDER BY s.title';
            $repository->linked_sources = DB::select($sourcesSql, [$repository->name, $repository->tree_id]);
        }

        return $repository;
    }

    /**
     * Search repositories
     */
    public function searchRepositories(int $treeId, string $query): array
    {
        $searchTerm = '%'.$query.'%';

        $sql = 'SELECT r.*,
                       (SELECT COUNT(*) FROM genealogy_sources s WHERE s.repository = r.name AND s.tree_id = r.tree_id) as source_count
                FROM genealogy_repositories r
                WHERE r.tree_id = ?
                  AND (r.name LIKE ? OR r.address LIKE ?)
                ORDER BY r.name
                LIMIT 50';

        return DB::select($sql, [$treeId, $searchTerm, $searchTerm]);
    }

    /**
     * Create a repository
     */
    public function createRepository(int $treeId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_repositories (tree_id, gedcom_id, name, address, phone, email, url, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        DB::insert($sql, [
            $treeId,
            $data['gedcom_id'] ?? null,
            $data['name'],
            $data['address'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['url'] ?? null,
            $data['notes'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a repository
     */
    public function updateRepository(int $repositoryId, array $data): bool
    {
        $fields = [];
        $params = [];

        $updatableFields = ['name', 'address', 'phone', 'email', 'url', 'notes'];

        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $params[] = $repositoryId;
        $sql = 'UPDATE genealogy_repositories SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a repository
     */
    public function deleteRepository(int $repositoryId): bool
    {
        // Note: Sources that reference this repository by name will keep that reference
        // but it won't be linked to this repository record anymore
        $sql = 'DELETE FROM genealogy_repositories WHERE id = ?';

        return DB::delete($sql, [$repositoryId]) > 0;
    }

    // ==================== MISSING DATA REPORT METHODS (Phase 2.7) ====================

    /**
     * Report types for missing data analysis
     */
    public const MISSING_DATA_TYPES = [
        'birth_date' => 'Missing Birth Date',
        'birth_place' => 'Missing Birth Place',
        'death_date' => 'Missing Death Date (Deceased)',
        'death_place' => 'Missing Death Place (Deceased)',
        'burial_info' => 'Missing Burial Information (Deceased)',
        'parents' => 'Missing Parents',
        'no_spouse' => 'No Spouse Recorded',
        'no_children' => 'No Children Recorded',
        'no_sources' => 'No Source Citations',
        'unknown_sex' => 'Unknown Sex/Gender',
    ];

    /**
     * Get missing data report for a tree
     * Returns persons grouped by type of missing data
     */
    public function getMissingDataReport(int $treeId, ?array $reportTypes = null): array
    {
        // Default to all report types if none specified
        if ($reportTypes === null) {
            $reportTypes = array_keys(self::MISSING_DATA_TYPES);
        }

        $report = [];

        foreach ($reportTypes as $type) {
            if (! isset(self::MISSING_DATA_TYPES[$type])) {
                continue;
            }

            $persons = $this->getPersonsWithMissingData($treeId, $type);
            $report[$type] = [
                'label' => self::MISSING_DATA_TYPES[$type],
                'count' => count($persons),
                'persons' => $persons,
            ];
        }

        return $report;
    }

    /**
     * Get persons with specific type of missing data
     */
    private function getPersonsWithMissingData(int $treeId, string $type): array
    {
        // Coverage join — sorts by priority_score when coverage table is populated
        $coverageJoin = 'LEFT JOIN genealogy_person_coverage cov
                            ON cov.person_id = p.id AND cov.tree_id = p.tree_id';
        $coverageSelect = ', COALESCE(cov.bloodline_tier, 3) AS bloodline_tier,
                             COALESCE(cov.priority_score, 0) AS priority_score,
                             COALESCE(cov.priority_rank, 9999) AS priority_rank,
                             cov.last_searched_at';
        $coverageOrder = 'ORDER BY COALESCE(cov.priority_score, 0) DESC, p.surname, p.given_name';

        $sql = match ($type) {
            'birth_date' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND (p.birth_date IS NULL OR p.birth_date = '')
                {$coverageOrder}
            ",
            'birth_place' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND (p.birth_place IS NULL OR p.birth_place = '')
                {$coverageOrder}
            ",
            'death_date' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND (
                      (p.death_date IS NULL OR p.death_date = '' OR p.death_date = 'Y' OR p.death_date = 'y')
                      AND p.birth_date IS NOT NULL AND p.birth_date != ''
                      AND p.birth_date < DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 110 YEAR), '%Y')
                  )
                {$coverageOrder}
            ",
            'death_place' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND p.death_date IS NOT NULL AND p.death_date != ''
                  AND (p.death_place IS NULL OR p.death_place = '')
                {$coverageOrder}
            ",
            'burial_info' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND p.death_date IS NOT NULL AND p.death_date != ''
                  AND (p.burial_date IS NULL OR p.burial_date = '')
                  AND (p.burial_place IS NULL OR p.burial_place = '')
                {$coverageOrder}
            ",
            'parents' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                LEFT JOIN genealogy_children fc ON fc.person_id = p.id
                WHERE p.tree_id = ?
                  AND fc.id IS NULL
                {$coverageOrder}
            ",
            'no_spouse' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                LEFT JOIN genealogy_families f1 ON f1.husband_id = p.id
                LEFT JOIN genealogy_families f2 ON f2.wife_id = p.id
                WHERE p.tree_id = ?
                  AND f1.id IS NULL AND f2.id IS NULL
                {$coverageOrder}
            ",
            'no_children' => "
                SELECT DISTINCT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                INNER JOIN genealogy_families f ON (f.husband_id = p.id OR f.wife_id = p.id)
                LEFT JOIN genealogy_children fc ON fc.family_id = f.id
                WHERE p.tree_id = ?
                  AND fc.id IS NULL
                {$coverageOrder}
            ",
            'no_sources' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                LEFT JOIN genealogy_citations c ON c.person_id = p.id
                WHERE p.tree_id = ?
                  AND c.id IS NULL
                {$coverageOrder}
            ",
            'unknown_sex' => "
                SELECT p.id, p.gedcom_id, p.given_name, p.surname, p.sex,
                       p.birth_date, p.birth_place, p.death_date, p.death_place
                       {$coverageSelect}
                FROM genealogy_persons p {$coverageJoin}
                WHERE p.tree_id = ?
                  AND (p.sex IS NULL OR p.sex = '' OR p.sex = 'U')
                {$coverageOrder}
            ",
            default => null,
        };

        if ($sql === null) {
            return [];
        }

        return DB::select($sql, [$treeId]);
    }

    /**
     * Get summary statistics for missing data
     */
    public function getMissingDataSummary(int $treeId): array
    {
        $totalPersons = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?',
            [$treeId]
        )->count;

        $summary = [
            'total_persons' => $totalPersons,
            'categories' => [],
        ];

        foreach (array_keys(self::MISSING_DATA_TYPES) as $type) {
            $persons = $this->getPersonsWithMissingData($treeId, $type);
            $count = count($persons);
            $summary['categories'][$type] = [
                'label' => self::MISSING_DATA_TYPES[$type],
                'count' => $count,
                'percentage' => $totalPersons > 0 ? round(($count / $totalPersons) * 100, 1) : 0,
            ];
        }

        return $summary;
    }

    /**
     * Search persons in a tree
     */
    public function searchPersons(int $treeId, string $query, int $limit = 50): array
    {
        $searchTerm = '%'.$query.'%';

        $sql = "SELECT id, gedcom_id, given_name, surname, suffix, nickname, sex,
                       birth_date, birth_place, death_date, death_place, primary_photo_id
                FROM genealogy_persons
                WHERE tree_id = ?
                  AND (
                      given_name LIKE ?
                      OR surname LIKE ?
                      OR nickname LIKE ?
                      OR CONCAT(given_name, ' ', surname) LIKE ?
                  )
                ORDER BY surname, given_name
                LIMIT ?";

        $results = DB::select($sql, [$treeId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);

        // Add primary_photo_url to each result
        foreach ($results as &$person) {
            $person->primary_photo_url = $this->getPrimaryPhotoUrl($person->primary_photo_id);
        }

        return $results;
    }

    /**
     * List persons by surname
     */
    public function listPersonsBySurname(int $treeId, string $surname): array
    {
        $sql = 'SELECT id, gedcom_id, given_name, surname, suffix, nickname, sex,
                       birth_date, birth_place, death_date, death_place, primary_photo_id
                FROM genealogy_persons
                WHERE tree_id = ? AND surname = ?
                ORDER BY given_name, birth_date';

        $results = DB::select($sql, [$treeId, $surname]);

        // Add primary_photo_url to each result
        foreach ($results as &$person) {
            $person->primary_photo_url = $this->getPrimaryPhotoUrl($person->primary_photo_id);
        }

        return $results;
    }

    /**
     * Get list of unique surnames in a tree
     */
    public function getSurnameList(int $treeId): array
    {
        $sql = "SELECT surname, COUNT(*) as person_count
                FROM genealogy_persons
                WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                GROUP BY surname
                ORDER BY surname";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Create a new person
     */
    public function createPerson(int $treeId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_persons (
                    tree_id, gedcom_id, given_name, surname, suffix, nickname, sex,
                    birth_date, birth_place, birth_lat, birth_lon,
                    death_date, death_place, death_lat, death_lon,
                    burial_date, burial_place, burial_lat, burial_lon,
                    occupation, education, religion, notes,
                    title, physical_description, nationality, ssn, id_number, property, cause_of_death,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        // Generate GEDCOM ID if not provided
        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'I');

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['given_name'] ?? null,
            $data['surname'] ?? null,
            $data['suffix'] ?? null,
            $data['nickname'] ?? null,
            $data['sex'] ?? null,
            $data['birth_date'] ?? null,
            $data['birth_place'] ?? null,
            $data['birth_lat'] ?? null,
            $data['birth_lon'] ?? null,
            $data['death_date'] ?? null,
            $data['death_place'] ?? null,
            $data['death_lat'] ?? null,
            $data['death_lon'] ?? null,
            $data['burial_date'] ?? null,
            $data['burial_place'] ?? null,
            $data['burial_lat'] ?? null,
            $data['burial_lon'] ?? null,
            $data['occupation'] ?? null,
            $data['education'] ?? null,
            $data['religion'] ?? null,
            $data['notes'] ?? null,
            // Phase 2.2 GEDCOM Attributes
            $data['title'] ?? null,
            $data['physical_description'] ?? null,
            $data['nationality'] ?? null,
            $data['ssn'] ?? null,
            $data['id_number'] ?? null,
            $data['property'] ?? null,
            $data['cause_of_death'] ?? null,
        ]);

        $personId = (int) DB::getPdo()->lastInsertId();
        $this->updateTreeStats($treeId);

        return $personId;
    }

    /**
     * Update a person
     */
    public function updatePerson(int $personId, array $data): bool
    {
        // Validate business rules before updating
        $this->validatePersonData($data, $personId);

        // Capture old values for change history
        $oldPerson = DB::selectOne('SELECT * FROM genealogy_persons WHERE id = ?', [$personId]);

        // Handle append_notes flag - append to existing notes instead of replacing
        if (! empty($data['append_notes']) && isset($data['notes'])) {
            if ($oldPerson) {
                $data['notes'] = ($oldPerson->notes ?? '').$data['notes'];
            }
            unset($data['append_notes']); // Don't try to save this flag as a field
        }

        $allowedFields = [
            'given_name', 'surname', 'suffix', 'nickname', 'sex',
            'birth_date', 'birth_place', 'birth_lat', 'birth_lon',
            'death_date', 'death_place', 'death_lat', 'death_lon',
            'burial_date', 'burial_place', 'burial_lat', 'burial_lon',
            'occupation', 'education', 'religion', 'notes', 'primary_photo_id',
            // Phase 2.2 GEDCOM Attributes
            'title', 'physical_description', 'nationality', 'ssn', 'id_number', 'property', 'cause_of_death',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $personId;

        $sql = 'UPDATE genealogy_persons SET '.implode(', ', $fields).' WHERE id = ?';
        $updated = DB::update($sql, $params) > 0;

        // Record change history using GenealogyChangeHistoryService (canonical service)
        if ($updated && $oldPerson) {
            try {
                $changeService = app(GenealogyChangeHistoryService::class);
                $changeService->recordUpdate(
                    'person',
                    $personId,
                    $oldPerson->tree_id,
                    (array) $oldPerson,
                    array_intersect_key($data, array_flip($allowedFields))
                );
            } catch (\Exception $e) {
                Log::warning('GenealogyService: Failed to record change history', ['error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    /**
     * Delete a person
     */
    public function deletePerson(int $personId): bool
    {
        // Get tree ID for stats update
        $person = DB::selectOne('SELECT tree_id FROM genealogy_persons WHERE id = ?', [$personId]);
        if (! $person) {
            return false;
        }

        // Foreign keys with CASCADE will handle related records
        $sql = 'DELETE FROM genealogy_persons WHERE id = ?';
        $result = DB::delete($sql, [$personId]) > 0;

        if ($result) {
            $this->updateTreeStats($person->tree_id);
        }

        return $result;
    }

    /**
     * Delete a family
     */
    public function deleteFamily(int $familyId): bool
    {
        // Get tree ID for stats update
        $family = DB::selectOne('SELECT tree_id FROM genealogy_families WHERE id = ?', [$familyId]);
        if (! $family) {
            return false;
        }

        // Foreign keys with CASCADE will handle related records (children links, media, sources)
        $sql = 'DELETE FROM genealogy_families WHERE id = ?';
        $result = DB::delete($sql, [$familyId]) > 0;

        if ($result) {
            $this->updateTreeStats($family->tree_id);
        }

        return $result;
    }

    /**
     * Generate a unique GEDCOM ID for a record type
     */
    private function generateGedcomId(int $treeId, string $prefix): string
    {
        // Get the highest existing ID for this prefix
        $table = match ($prefix) {
            'I' => 'genealogy_persons',
            'F' => 'genealogy_families',
            'M', 'O' => 'genealogy_media',
            'S' => 'genealogy_sources',
            default => 'genealogy_persons',
        };

        $sql = "SELECT gedcom_id FROM {$table}
                WHERE tree_id = ? AND gedcom_id LIKE ?
                ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC
                LIMIT 1";

        $result = DB::selectOne($sql, [$treeId, $prefix.'%']);

        if ($result) {
            $num = (int) substr($result->gedcom_id, 1);

            return $prefix.($num + 1);
        }

        return $prefix.'1';
    }

    // ========================================================================
    // FAMILY CRUD
    // ========================================================================

    /**
     * Get all families for a tree
     */
    public function getFamilies(int $treeId): array
    {
        $sql = "SELECT f.*,
                       h.id as husband_id, h.given_name as husband_given, h.surname as husband_surname,
                       w.id as wife_id, w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.tree_id = ?
                ORDER BY COALESCE(f.marriage_date, '9999')";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Get a family by ID
     */
    public function getFamily(int $familyId): ?array
    {
        $sql = 'SELECT f.*,
                       h.id as husband_id, h.given_name as husband_given, h.surname as husband_surname,
                       h.birth_date as husband_birth, h.death_date as husband_death,
                       w.id as wife_id, w.given_name as wife_given, w.surname as wife_surname,
                       w.birth_date as wife_birth, w.death_date as wife_death
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.id = ?';

        $family = DB::selectOne($sql, [$familyId]);
        if (! $family) {
            return null;
        }

        $result = (array) $family;
        $result['children'] = $this->getFamilyChildren($familyId);
        $result['events'] = $this->getFamilyEvents($familyId);

        return $result;
    }

    /**
     * Create a new family
     */
    /**
     * Validate family data for business rule compliance
     *
     * @throws \InvalidArgumentException if validation fails
     */
    private function validateFamilyData(array $data, ?int $familyId = null): void
    {
        $errors = [];

        // Rule 1: Person cannot marry themselves
        $husbandId = $data['husband_id'] ?? null;
        $wifeId = $data['wife_id'] ?? null;

        if ($husbandId && $wifeId && $husbandId === $wifeId) {
            $errors[] = 'A person cannot be married to themselves';
        }

        // Rule 2: Validate marriage date is after both spouses' birth dates
        if (isset($data['marriage_date']) && $data['marriage_date']) {
            $marriageYear = $this->extractYearFromGedcomDate($data['marriage_date']);

            if ($marriageYear && $husbandId) {
                $husband = $this->getPerson($husbandId);
                if ($husband && ! empty($husband['birth_date'])) {
                    $husbandBirthYear = $this->extractYearFromGedcomDate($husband['birth_date']);
                    if ($husbandBirthYear && $marriageYear < $husbandBirthYear) {
                        $errors[] = 'Marriage date cannot be before husband\'s birth date';
                    }
                }
            }

            if ($marriageYear && $wifeId) {
                $wife = $this->getPerson($wifeId);
                if ($wife && ! empty($wife['birth_date'])) {
                    $wifeBirthYear = $this->extractYearFromGedcomDate($wife['birth_date']);
                    if ($wifeBirthYear && $marriageYear < $wifeBirthYear) {
                        $errors[] = 'Marriage date cannot be before wife\'s birth date';
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Validate person data for business rule compliance
     *
     * @throws \InvalidArgumentException if validation fails
     */
    private function validatePersonData(array $data, ?int $personId = null): void
    {
        $errors = [];

        // Rule: Death date must be after birth date
        if (isset($data['birth_date']) && isset($data['death_date']) && $data['birth_date'] && $data['death_date']) {
            $birthYear = $this->extractYearFromGedcomDate($data['birth_date']);
            $deathYear = $this->extractYearFromGedcomDate($data['death_date']);

            if ($birthYear && $deathYear && $deathYear < $birthYear) {
                $errors[] = 'Death date cannot be before birth date';
            }
        }

        // Rule: Burial date must be after death date
        if (isset($data['death_date']) && isset($data['burial_date']) && $data['death_date'] && $data['burial_date']) {
            $deathYear = $this->extractYearFromGedcomDate($data['death_date']);
            $burialYear = $this->extractYearFromGedcomDate($data['burial_date']);

            if ($deathYear && $burialYear && $burialYear < $deathYear) {
                $errors[] = 'Burial date cannot be before death date';
            }
        }

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Validate child-family relationship for business rule compliance
     *
     * @throws \InvalidArgumentException if validation fails
     */
    private function validateChildFamilyRelationship(int $familyId, int $personId): void
    {
        $errors = [];

        // Get family information
        $family = $this->getFamily($familyId);
        if (! $family) {
            throw new \InvalidArgumentException('Family not found');
        }

        // Rule: Child cannot be their own parent
        $husbandId = isset($family['husband_id']) ? (int) $family['husband_id'] : null;
        $wifeId = isset($family['wife_id']) ? (int) $family['wife_id'] : null;
        if ($personId === $husbandId || $personId === $wifeId) {
            $errors[] = 'A person cannot be their own parent';
        }

        // Rule: Child should be born after parents (if birth dates are known)
        $child = $this->getPerson($personId);
        if ($child && ! empty($child['birth_date'])) {
            $childBirthYear = $this->extractYearFromGedcomDate($child['birth_date']);

            if ($childBirthYear && $husbandId) {
                $father = $this->getPerson($husbandId);
                if ($father && ! empty($father['birth_date'])) {
                    $fatherBirthYear = $this->extractYearFromGedcomDate($father['birth_date']);
                    if ($fatherBirthYear && $childBirthYear <= $fatherBirthYear) {
                        $errors[] = 'Child\'s birth date must be after father\'s birth date';
                    }
                }
            }

            if ($childBirthYear && $wifeId) {
                $mother = $this->getPerson($wifeId);
                if ($mother && ! empty($mother['birth_date'])) {
                    $motherBirthYear = $this->extractYearFromGedcomDate($mother['birth_date']);
                    if ($motherBirthYear && $childBirthYear <= $motherBirthYear) {
                        $errors[] = 'Child\'s birth date must be after mother\'s birth date';
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Extract year from GEDCOM date format (e.g., "15 MAR 1850" -> 1850)
     */
    private function extractYearFromGedcomDate(?string $date): ?int
    {
        if (! $date) {
            return null;
        }
        // Match 4-digit year anywhere in the string
        if (preg_match('/\b(\d{4})\b/', $date, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function createFamily(int $treeId, array $data): int
    {
        // Validate business rules
        $this->validateFamilyData($data);

        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'F');

        $sql = 'INSERT INTO genealogy_families (
                    tree_id, gedcom_id, husband_id, wife_id,
                    marriage_date, marriage_place, marriage_lat, marriage_lon,
                    divorce_date, divorce_place, annulment_date, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['husband_id'] ?? null,
            $data['wife_id'] ?? null,
            $data['marriage_date'] ?? null,
            $data['marriage_place'] ?? null,
            $data['marriage_lat'] ?? null,
            $data['marriage_lon'] ?? null,
            $data['divorce_date'] ?? null,
            $data['divorce_place'] ?? null,
            $data['annulment_date'] ?? null,
            $data['notes'] ?? null,
        ]);

        $familyId = (int) DB::getPdo()->lastInsertId();
        $this->updateTreeStats($treeId);

        return $familyId;
    }

    /**
     * Update a family
     */
    public function updateFamily(int $familyId, array $data): bool
    {
        // Merge with existing family data for validation
        $existingFamily = $this->getFamily($familyId);
        if ($existingFamily) {
            $validationData = array_merge([
                'husband_id' => $existingFamily['husband_id'] ?? null,
                'wife_id' => $existingFamily['wife_id'] ?? null,
                'marriage_date' => $existingFamily['marriage_date'] ?? null,
            ], $data);
            $this->validateFamilyData($validationData, $familyId);
        }

        $allowedFields = [
            'husband_id', 'wife_id',
            'marriage_date', 'marriage_place', 'marriage_lat', 'marriage_lon',
            'divorce_date', 'divorce_place', 'annulment_date', 'notes',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $familyId;

        $sql = 'UPDATE genealogy_families SET '.implode(', ', $fields).' WHERE id = ?';
        $updated = DB::update($sql, $params) > 0;

        // Record change history using GenealogyChangeHistoryService (canonical service)
        if ($updated && $existingFamily) {
            try {
                $changeService = app(GenealogyChangeHistoryService::class);
                $changeService->recordUpdate(
                    'family',
                    $familyId,
                    (int) ($existingFamily['tree_id'] ?? 0),
                    $existingFamily,
                    array_intersect_key($data, array_flip($allowedFields))
                );
            } catch (\Exception $e) {
                Log::warning('GenealogyService: Failed to record change history', ['error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    /**
     * Add a child to a family
     */
    public function addChildToFamily(int $familyId, int $personId, array $options = []): bool
    {
        // Validate child-family relationship business rules
        $this->validateChildFamilyRelationship($familyId, $personId);

        $this->linkChildToFamily(
            $familyId,
            $personId,
            $options['birth_order'] ?? null
        );

        // Update relationship types if specified
        if (isset($options['father_relationship']) || isset($options['mother_relationship'])) {
            $sql = 'UPDATE genealogy_children SET
                        father_relationship = COALESCE(?, father_relationship),
                        mother_relationship = COALESCE(?, mother_relationship)
                    WHERE family_id = ? AND person_id = ?';

            DB::update($sql, [
                $options['father_relationship'] ?? null,
                $options['mother_relationship'] ?? null,
                $familyId,
                $personId,
            ]);
        }

        return true;
    }

    /**
     * Remove a child from a family
     */
    public function removeChildFromFamily(int $familyId, int $personId): bool
    {
        $sql = 'DELETE FROM genealogy_children WHERE family_id = ? AND person_id = ?';

        return DB::delete($sql, [$familyId, $personId]) > 0;
    }

    /**
     * Sync children for a family - replace all children with the given list
     */
    public function syncFamilyChildren(int $familyId, array $childIds): void
    {
        // Get current children
        $sql = 'SELECT person_id FROM genealogy_children WHERE family_id = ?';
        $currentChildren = DB::select($sql, [$familyId]);
        $currentIds = array_column($currentChildren, 'person_id');

        // Remove children no longer in the list
        $toRemove = array_diff($currentIds, $childIds);
        foreach ($toRemove as $personId) {
            $this->removeChildFromFamily($familyId, $personId);
        }

        // Add new children
        $toAdd = array_diff($childIds, $currentIds);
        foreach ($toAdd as $personId) {
            $this->addChildToFamily($familyId, $personId);
        }
    }

    // ========================================================================
    // MEDIA CRUD
    // ========================================================================

    /**
     * Get all media for a tree
     */
    public function getTreeMedia(int $treeId, int $limit = 100, int $offset = 0, ?string $mediaType = null): array
    {
        $params = [$treeId];
        $whereClause = 'WHERE m.tree_id = ?';

        if ($mediaType && $mediaType !== 'all') {
            $whereClause .= ' AND m.media_type = ?';
            $params[] = $mediaType;
        }

        $sql = "SELECT m.*,
                       (SELECT COUNT(*) FROM genealogy_person_media pm WHERE pm.media_id = m.id) as person_count
                FROM genealogy_media m
                {$whereClause}
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;

        return DB::select($sql, $params);
    }

    /**
     * Get media category counts for a tree (Phase 3.6)
     */
    public function getMediaCategoryCounts(int $treeId): array
    {
        $sql = 'SELECT media_type, COUNT(*) as count
                FROM genealogy_media
                WHERE tree_id = ?
                GROUP BY media_type';

        $results = DB::select($sql, [$treeId]);

        $counts = [];
        foreach ($results as $row) {
            $counts[$row->media_type] = $row->count;
        }

        return $counts;
    }

    /**
     * Update media type/category (Phase 3.6)
     */
    public function updateMediaType(int $mediaId, string $mediaType): bool
    {
        $validTypes = ['photo', 'document', 'certificate', 'census', 'military', 'obituary', 'headstone', 'other'];
        if (! in_array($mediaType, $validTypes)) {
            return false;
        }

        $sql = 'UPDATE genealogy_media SET media_type = ? WHERE id = ?';

        return DB::update($sql, [$mediaType, $mediaId]) > 0;
    }

    /**
     * Update media transcription (Phase 3.7)
     */
    public function updateMediaTranscription(int $mediaId, string $transcription, string $source = 'manual'): bool
    {
        $validSources = ['manual', 'ocr', 'ai'];
        if (! in_array($source, $validSources)) {
            $source = 'manual';
        }

        $sql = 'UPDATE genealogy_media SET transcription = ?, transcription_source = ?, transcription_date = NOW() WHERE id = ?';

        return DB::update($sql, [$transcription, $source, $mediaId]) > 0;
    }

    /**
     * Get media items that need transcription (Phase 3.7)
     */
    public function getMediaNeedingTranscription(int $treeId, int $limit = 20): array
    {
        $sql = "SELECT id, title, media_type, file_format, original_path, nextcloud_path, local_filename
                FROM genealogy_media
                WHERE tree_id = ?
                  AND media_type IN ('document', 'certificate', 'census', 'military', 'obituary', 'headstone')
                  AND (transcription IS NULL OR transcription = '')
                  AND file_exists = 1
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * Get media with Windows paths that need import (Phase 3.8)
     * Returns media items that have original Windows paths but haven't been imported yet
     */
    public function getWindowsMediaPaths(int $treeId): array
    {
        $sql = "SELECT id, title, original_path, file_format, media_type
                FROM genealogy_media
                WHERE tree_id = ?
                  AND original_path IS NOT NULL
                  AND (original_path LIKE '%\\\\%' OR original_path LIKE '_:%')
                  AND (file_exists = 0 OR file_exists IS NULL)
                ORDER BY original_path";

        $results = DB::select($sql, [$treeId]);

        // Group by base directory for easier batch import
        $grouped = [];
        foreach ($results as $item) {
            $path = $item->original_path;
            // Extract directory from Windows path
            $lastBackslash = strrpos($path, '\\');
            $directory = $lastBackslash !== false ? substr($path, 0, $lastBackslash) : dirname($path);

            if (! isset($grouped[$directory])) {
                $grouped[$directory] = [];
            }
            $grouped[$directory][] = $item;
        }

        return [
            'total_files' => count($results),
            'directories' => array_map(function ($dir, $files) {
                return [
                    'path' => $dir,
                    'file_count' => count($files),
                    'files' => $files,
                ];
            }, array_keys($grouped), array_values($grouped)),
        ];
    }

    /**
     * Generate SCP commands for Windows media import (Phase 3.8)
     */
    public function generateScpCommands(int $treeId, string $remoteHost, string $remotePath): array
    {
        $windowsPaths = $this->getWindowsMediaPaths($treeId);
        $commands = [];

        foreach ($windowsPaths['directories'] as $dir) {
            // Convert Windows path to what would be expected on a mapped drive or share
            $windowsPath = str_replace('\\', '/', $dir['path']);
            // Add SCP command
            $commands[] = [
                'source' => $dir['path'],
                'files_count' => $dir['file_count'],
                'command' => "scp -r \"{$windowsPath}/*\" {$remoteHost}:{$remotePath}/",
            ];
        }

        return $commands;
    }

    /**
     * Get a single media item
     */
    public function getMedia(int $mediaId): ?array
    {
        $sql = 'SELECT * FROM genealogy_media WHERE id = ?';
        $media = DB::selectOne($sql, [$mediaId]);

        if (! $media) {
            return null;
        }

        $result = (array) $media;

        // Get linked persons
        $sql = 'SELECT p.id, p.given_name, p.surname, pm.is_primary,
                       pm.face_region_x, pm.face_region_y, pm.face_region_w, pm.face_region_h,
                       pm.face_confirmed
                FROM genealogy_person_media pm
                JOIN genealogy_persons p ON p.id = pm.person_id
                WHERE pm.media_id = ?';

        $result['persons'] = DB::select($sql, [$mediaId]);

        // Get linked families (Phase 3.3)
        $sql = 'SELECT f.id, f.gedcom_id,
                       h.given_name as husband_given_name, h.surname as husband_surname,
                       w.given_name as wife_given_name, w.surname as wife_surname,
                       f.marriage_date
                FROM genealogy_family_media fm
                JOIN genealogy_families f ON f.id = fm.family_id
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE fm.media_id = ?';

        $result['families'] = DB::select($sql, [$mediaId]);

        return $result;
    }

    /**
     * Create a media record
     */
    public function createMedia(int $treeId, array $data): int
    {
        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'M');

        $sql = 'INSERT INTO genealogy_media (
                    tree_id, gedcom_id, original_path, nextcloud_path, local_filename,
                    file_format, mime_type, file_size, title, media_date, description,
                    media_type, file_exists, width, height, has_faces, face_count,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['original_path'] ?? null,
            $data['nextcloud_path'] ?? null,
            $data['local_filename'] ?? null,
            $data['file_format'] ?? null,
            $data['mime_type'] ?? null,
            $data['file_size'] ?? null,
            $data['title'] ?? null,
            $data['media_date'] ?? null,
            $data['description'] ?? null,
            $data['media_type'] ?? 'photo',
            $data['file_exists'] ?? 0,
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['has_faces'] ?? 0,
            $data['face_count'] ?? 0,
        ]);

        $mediaId = (int) DB::getPdo()->lastInsertId();
        $this->updateTreeStats($treeId);

        return $mediaId;
    }

    /**
     * Update media record
     */
    public function updateMedia(int $mediaId, array $data): bool
    {
        $allowedFields = [
            'nextcloud_path', 'local_filename', 'file_format', 'mime_type',
            'file_size', 'title', 'media_date', 'description', 'media_type',
            'file_exists', 'imported_at', 'width', 'height', 'has_faces', 'face_count',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $mediaId;

        $sql = 'UPDATE genealogy_media SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Update existing media records with file paths from parsed GEDCOM data
     * This is useful when media was imported without file paths
     */
    public function updateMediaPathsFromGedcom(int $treeId, array $mediaData): array
    {
        $results = [
            'total' => count($mediaData),
            'updated' => 0,
            'skipped' => 0,
            'not_found' => 0,
            'errors' => [],
        ];

        foreach ($mediaData as $gedcomId => $media) {
            $filePath = $media['file_path'] ?? null;
            if (empty($filePath)) {
                $results['skipped']++;

                continue;
            }

            // Find the existing media record by GEDCOM ID
            $existing = DB::selectOne(
                'SELECT id, original_path FROM genealogy_media WHERE tree_id = ? AND gedcom_id = ?',
                [$treeId, $gedcomId]
            );

            if (! $existing) {
                $results['not_found']++;
                $results['errors'][] = "Media {$gedcomId} not found in database";

                continue;
            }

            // Skip if already has an original path
            if (! empty($existing->original_path)) {
                $results['skipped']++;

                continue;
            }

            try {
                // Detect media type from file extension
                $mediaType = 'photo';
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'doc', 'docx', 'txt'])) {
                    $mediaType = 'document';
                }

                $sql = 'UPDATE genealogy_media SET
                            original_path = ?,
                            file_format = COALESCE(file_format, ?),
                            media_type = COALESCE(media_type, ?),
                            updated_at = NOW()
                        WHERE id = ?';

                DB::update($sql, [
                    $filePath,
                    $media['file_format'] ?? $ext,
                    $mediaType,
                    $existing->id,
                ]);

                $results['updated']++;
            } catch (Exception $e) {
                $results['errors'][] = "Failed to update {$gedcomId}: ".$e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Link a person to media with optional face region
     */
    public function linkPersonToMedia(int $personId, int $mediaId, array $options = []): bool
    {
        // Check if link exists
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $mediaId]
        );

        if ($existing) {
            // Update existing link
            $sql = 'UPDATE genealogy_person_media SET
                        is_primary = ?,
                        face_region_x = ?,
                        face_region_y = ?,
                        face_region_w = ?,
                        face_region_h = ?,
                        face_confirmed = ?,
                        notes = ?
                    WHERE person_id = ? AND media_id = ?';

            DB::update($sql, [
                $options['is_primary'] ?? 0,
                $options['face_region_x'] ?? null,
                $options['face_region_y'] ?? null,
                $options['face_region_w'] ?? null,
                $options['face_region_h'] ?? null,
                $options['face_confirmed'] ?? 0,
                $options['notes'] ?? null,
                $personId,
                $mediaId,
            ]);
        } else {
            $sql = 'INSERT INTO genealogy_person_media (
                        person_id, media_id, is_primary,
                        face_region_x, face_region_y, face_region_w, face_region_h,
                        face_confirmed, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

            DB::insert($sql, [
                $personId,
                $mediaId,
                $options['is_primary'] ?? 0,
                $options['face_region_x'] ?? null,
                $options['face_region_y'] ?? null,
                $options['face_region_w'] ?? null,
                $options['face_region_h'] ?? null,
                $options['face_confirmed'] ?? 0,
                $options['notes'] ?? null,
            ]);
        }

        // If this is primary, unset other primaries for this person
        if (! empty($options['is_primary'])) {
            DB::update(
                'UPDATE genealogy_person_media SET is_primary = 0
                 WHERE person_id = ? AND media_id != ?',
                [$personId, $mediaId]
            );

            // Update person's primary_photo_id
            DB::update(
                'UPDATE genealogy_persons SET primary_photo_id = ? WHERE id = ?',
                [$mediaId, $personId]
            );
        }

        return true;
    }

    /**
     * Unlink a person from media
     */
    public function unlinkPersonFromMedia(int $personId, int $mediaId): bool
    {
        // Check if this was the primary photo
        $link = DB::selectOne(
            'SELECT is_primary FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $mediaId]
        );

        if ($link && $link->is_primary) {
            // Clear primary_photo_id
            DB::update('UPDATE genealogy_persons SET primary_photo_id = NULL WHERE id = ?', [$personId]);
        }

        $sql = 'DELETE FROM genealogy_person_media WHERE person_id = ? AND media_id = ?';

        return DB::delete($sql, [$personId, $mediaId]) > 0;
    }

    /**
     * Link a family to media (Phase 3.3)
     */
    public function linkFamilyToMedia(int $familyId, int $mediaId): bool
    {
        // Check if link exists
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?',
            [$familyId, $mediaId]
        );

        if ($existing) {
            return true; // Already linked
        }

        $sql = 'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())';
        DB::insert($sql, [$familyId, $mediaId]);

        return true;
    }

    /**
     * Unlink a family from media (Phase 3.3)
     */
    public function unlinkFamilyFromMedia(int $familyId, int $mediaId): bool
    {
        $sql = 'DELETE FROM genealogy_family_media WHERE family_id = ? AND media_id = ?';

        return DB::delete($sql, [$familyId, $mediaId]) > 0;
    }

    /**
     * Confirm or reject a face tag (Phase 3.5)
     */
    public function confirmFaceTag(int $personId, int $mediaId, bool $confirmed): bool
    {
        $sql = 'UPDATE genealogy_person_media SET face_confirmed = ? WHERE person_id = ? AND media_id = ?';

        return DB::update($sql, [$confirmed ? 1 : 0, $personId, $mediaId]) > 0;
    }

    /**
     * Get count of unconfirmed faces for a tree (Phase 3.5)
     */
    public function getUnconfirmedFaceCount(int $treeId): int
    {
        $sql = 'SELECT COUNT(*) as count
                FROM genealogy_person_media pm
                JOIN genealogy_media m ON m.id = pm.media_id
                WHERE m.tree_id = ?
                AND pm.face_region_x IS NOT NULL
                AND pm.face_confirmed = 0';
        $result = DB::selectOne($sql, [$treeId]);

        return $result->count ?? 0;
    }

    /**
     * Get unconfirmed faces for review (Phase 3.5)
     */
    public function getUnconfirmedFaces(int $treeId, int $limit = 20): array
    {
        $sql = 'SELECT pm.*, p.given_name, p.surname, m.title, m.original_path,
                       m.id as media_id, m.nextcloud_path, m.local_filename, m.file_format
                FROM genealogy_person_media pm
                JOIN genealogy_persons p ON p.id = pm.person_id
                JOIN genealogy_media m ON m.id = pm.media_id
                WHERE m.tree_id = ?
                AND pm.face_region_x IS NOT NULL
                AND pm.face_confirmed = 0
                ORDER BY pm.created_at DESC
                LIMIT ?';

        return DB::select($sql, [$treeId, $limit]);
    }

    // ========================================================================
    // STATISTICS & REPORTS
    // ========================================================================

    /**
     * Get tree statistics
     */
    public function getTreeStatistics(int $treeId): array
    {
        $stats = [];

        // Basic counts
        $tree = $this->getTree($treeId);
        $stats['person_count'] = $tree->person_count ?? 0;
        $stats['family_count'] = $tree->family_count ?? 0;
        $stats['media_count'] = $tree->media_count ?? 0;
        $stats['source_count'] = $tree->source_count ?? 0;

        // Gender breakdown
        $sql = 'SELECT sex, COUNT(*) as count
                FROM genealogy_persons
                WHERE tree_id = ?
                GROUP BY sex';
        $genders = DB::select($sql, [$treeId]);
        $stats['gender_breakdown'] = [];
        foreach ($genders as $g) {
            $stats['gender_breakdown'][$g->sex ?? 'Unknown'] = $g->count;
        }

        // Birth year range
        $sql = "SELECT
                    MIN(CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED)) as earliest_birth,
                    MAX(CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED)) as latest_birth
                FROM genealogy_persons
                WHERE tree_id = ? AND birth_date IS NOT NULL AND birth_date REGEXP '^[0-9]{4}'";
        $dates = DB::selectOne($sql, [$treeId]);
        $stats['earliest_birth'] = $dates->earliest_birth;
        $stats['latest_birth'] = $dates->latest_birth;

        // Living vs deceased
        $sql = 'SELECT
                    SUM(CASE WHEN death_date IS NULL THEN 1 ELSE 0 END) as living,
                    SUM(CASE WHEN death_date IS NOT NULL THEN 1 ELSE 0 END) as deceased
                FROM genealogy_persons
                WHERE tree_id = ?';
        $living = DB::selectOne($sql, [$treeId]);
        $stats['living_count'] = $living->living;
        $stats['deceased_count'] = $living->deceased;

        // Persons with photos
        $sql = 'SELECT COUNT(DISTINCT person_id) as count
                FROM genealogy_person_media pm
                JOIN genealogy_persons p ON p.id = pm.person_id
                WHERE p.tree_id = ?';
        $withPhotos = DB::selectOne($sql, [$treeId]);
        $stats['persons_with_photos'] = $withPhotos->count;

        // Media with identified faces
        $sql = 'SELECT
                    SUM(CASE WHEN has_faces = 1 THEN 1 ELSE 0 END) as with_faces,
                    SUM(face_count) as total_faces
                FROM genealogy_media
                WHERE tree_id = ?';
        $faces = DB::selectOne($sql, [$treeId]);
        $stats['media_with_faces'] = $faces->with_faces ?? 0;
        $stats['total_faces'] = $faces->total_faces ?? 0;

        return $stats;
    }

    /**
     * Get recent additions to a tree
     */
    public function getRecentAdditions(int $treeId, int $limit = 10): array
    {
        $sql = "SELECT id, 'person' as type, CONCAT(given_name, ' ', surname) as name, created_at
                FROM genealogy_persons
                WHERE tree_id = ?
                UNION ALL
                SELECT id, 'media' as type, title as name, created_at
                FROM genealogy_media
                WHERE tree_id = ?
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$treeId, $treeId, $limit]);
    }

    /**
     * List all persons in a tree (for dropdowns/autocomplete)
     */
    public function listPersons(int $treeId, int $limit = 5000): array
    {
        $sql = 'SELECT id, gedcom_id, given_name, surname, suffix, sex, birth_date, death_date
                FROM genealogy_persons
                WHERE tree_id = ?
                ORDER BY surname, given_name
                LIMIT ?';

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * Get tree visualization data for Topola
     * Returns persons and families as key-value maps for efficient lookup
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $personId  Starting person ID
     * @param  string  $mode  Mode: 'hourglass', 'ancestors', or 'descendants'
     * @param  int  $generations  Number of generations to fetch (default 5 for performance)
     * @return array Contains persons, families, and metadata for lazy loading
     */
    public function getTreeVisualizationData(int $treeId, int $personId, string $mode = 'hourglass', int $generations = 5): array
    {
        $persons = [];
        $families = [];
        $visited = [];
        $hasMoreAncestors = [];
        $hasMoreDescendants = [];

        // Limit generations to reasonable range (1-20)
        $generations = max(1, min(20, $generations));

        // Get the starting person
        $startPerson = $this->getPersonForTreeVisualization($personId);
        if (! $startPerson) {
            return ['persons' => [], 'families' => [], 'metadata' => ['has_more_ancestors' => [], 'has_more_descendants' => []]];
        }

        // Kinship and relatives need both ancestors and descendants (like hourglass)
        $needsAncestors = in_array($mode, ['hourglass', 'ancestors', 'kinship', 'relatives']);
        $needsDescendants = in_array($mode, ['hourglass', 'descendants', 'kinship', 'relatives', 'fancy']);

        if ($needsAncestors) {
            $this->collectAncestors($personId, $generations, $persons, $families, $visited, $treeId, $hasMoreAncestors);
        }

        if ($needsDescendants) {
            $this->collectDescendants($personId, $generations, $persons, $families, $visited, $treeId, $hasMoreDescendants);
        }

        // N147: Kinship/relatives need siblings, spouse parents, and descendants of ancestors
        if (in_array($mode, ['kinship', 'relatives'])) {
            $this->collectSiblingsAndInLaws($personId, $persons, $families, $visited, $treeId);
        }

        // Always include the starting person
        if (! isset($persons[$personId])) {
            $persons[$personId] = $this->formatPersonForTree($startPerson);
        }

        return [
            'persons' => $persons,
            'families' => $families,
            'metadata' => [
                'generations_loaded' => $generations,
                'mode' => $mode,
                'root_person_id' => $personId,
                'person_count' => count($persons),
                'family_count' => count($families),
                'has_more_ancestors' => $hasMoreAncestors,
                'has_more_descendants' => $hasMoreDescendants,
            ],
        ];
    }

    /**
     * Collect ancestors recursively
     *
     * @param  int  $personId  Person ID to collect ancestors for
     * @param  int  $generations  Remaining generations to collect
     * @param  array  $persons  Reference to persons array
     * @param  array  $families  Reference to families array
     * @param  array  $visited  Reference to visited tracking array
     * @param  int  $treeId  Tree ID
     * @param  array  $hasMore  Reference to array tracking persons with more ancestors
     */
    private function collectAncestors(int $personId, int $generations, array &$persons, array &$families, array &$visited, int $treeId, array &$hasMore = []): void
    {
        if (isset($visited['person_'.$personId])) {
            return;
        }

        // If we've hit generation limit, check if there are more ancestors and mark them
        if ($generations <= 0) {
            $familyAsChildId = $this->getParentFamilyIdForTreeVisualization($personId);
            if ($familyAsChildId !== null) {
                $hasMore[] = $personId;
            }

            return;
        }

        $visited['person_'.$personId] = true;

        // Get person data
        $person = $this->getPersonForTreeVisualization($personId);
        if (! $person) {
            return;
        }

        $persons[$personId] = $this->formatPersonForTree($person);

        // Get family as child (parents)
        $familyId = $this->getParentFamilyIdForTreeVisualization($personId);
        if ($familyId !== null) {

            if (! isset($families[$familyId])) {
                $family = $this->getFamilyForTreeVisualization($familyId);
                if ($family) {
                    $families[$familyId] = $this->formatFamilyForTree($family);

                    // Collect father's ancestors (husband_id from getFamily result)
                    $fatherId = $family['husband_id'] ?? null;
                    if ($fatherId) {
                        $this->collectAncestors($fatherId, $generations - 1, $persons, $families, $visited, $treeId, $hasMore);
                    }

                    // Collect mother's ancestors (wife_id from getFamily result)
                    $motherId = $family['wife_id'] ?? null;
                    if ($motherId) {
                        $this->collectAncestors($motherId, $generations - 1, $persons, $families, $visited, $treeId, $hasMore);
                    }
                }
            }

            // N147: Link parents to this family as spouses (Topola needs this for KinshipChart)
            $famData = $families[$familyId] ?? null;
            if ($famData) {
                foreach (['husband_id', 'wife_id'] as $role) {
                    $parentId = $famData[$role] ?? null;
                    if ($parentId && isset($persons[$parentId])) {
                        if (! in_array($familyId, $persons[$parentId]['families_as_spouse'])) {
                            $persons[$parentId]['families_as_spouse'][] = $familyId;
                        }
                    }
                }
            }

            // Link person to their parent family
            $persons[$personId]['family_as_child'] = $familyId;
        }
    }

    /**
     * Collect descendants recursively
     *
     * @param  int  $personId  Person ID to collect descendants for
     * @param  int  $generations  Remaining generations to collect
     * @param  array  $persons  Reference to persons array
     * @param  array  $families  Reference to families array
     * @param  array  $visited  Reference to visited tracking array
     * @param  int  $treeId  Tree ID
     * @param  array  $hasMore  Reference to array tracking persons with more descendants
     */
    private function collectDescendants(int $personId, int $generations, array &$persons, array &$families, array &$visited, int $treeId, array &$hasMore = []): void
    {
        if (isset($visited['desc_'.$personId])) {
            return;
        }

        // If we've hit generation limit, check if there are more descendants and mark them
        if ($generations <= 0) {
            $familyIds = $this->getSpouseFamilyIdsForTreeVisualization($personId);
            foreach ($familyIds as $familyId) {
                $family = $this->getFamilyForTreeVisualization($familyId);
                if ($family && ! empty($family['children'])) {
                    $hasMore[] = $personId;
                    break;
                }
            }

            return;
        }

        $visited['desc_'.$personId] = true;

        // Get person data
        $person = $this->getPersonForTreeVisualization($personId);
        if (! $person) {
            return;
        }

        if (! isset($persons[$personId])) {
            $persons[$personId] = $this->formatPersonForTree($person);
        }

        // Get families as spouse
        $familyIds = $this->getSpouseFamilyIdsForTreeVisualization($personId);

        foreach ($familyIds as $familyId) {

            if (! isset($families[$familyId])) {
                $family = $this->getFamilyForTreeVisualization($familyId);
                if ($family) {
                    $families[$familyId] = $this->formatFamilyForTree($family);

                    // Add spouse to persons (use husband_id/wife_id keys from getFamily)
                    $husbandId = $family['husband_id'] ?? null;
                    $wifeId = $family['wife_id'] ?? null;
                    $spouseId = $husbandId == $personId ? $wifeId : $husbandId;
                    if ($spouseId && ! isset($persons[$spouseId])) {
                        $spouse = $this->getPersonForTreeVisualization($spouseId);
                        if ($spouse) {
                            $persons[$spouseId] = $this->formatPersonForTree($spouse);
                        }
                    }

                    // N147: Link spouse to this family (Topola needs families_as_spouse)
                    if ($spouseId && isset($persons[$spouseId])) {
                        if (! in_array($familyId, $persons[$spouseId]['families_as_spouse'])) {
                            $persons[$spouseId]['families_as_spouse'][] = $familyId;
                        }
                    }

                    // Collect children's descendants
                    if (! empty($family['children'])) {
                        foreach ($family['children'] as $child) {
                            // Handle both array and stdClass for children
                            $childId = is_object($child) ? $child->id : $child['id'];
                            $this->collectDescendants($childId, $generations - 1, $persons, $families, $visited, $treeId, $hasMore);
                            // N147: Link child to parent family (Topola needs family_as_child)
                            if (isset($persons[$childId])) {
                                $persons[$childId]['family_as_child'] = $familyId;
                            }
                        }
                    }
                }
            }
        }

        // Update person's families as spouse
        if (! empty($familyIds)) {
            $persons[$personId]['families_as_spouse'] = $familyIds;
        }
    }

    /**
     * N147: Collect siblings (other children of parent families) and in-law parents
     * (spouse's parents) for kinship/relatives chart modes.
     */
    private function collectSiblingsAndInLaws(int $personId, array &$persons, array &$families, array &$visited, int $treeId): void
    {
        // 1. Siblings: find person's parent family, add all children as persons
        $familyAsChildId = $this->getParentFamilyIdForTreeVisualization($personId);
        if ($familyAsChildId !== null) {
            $family = $this->getFamilyForTreeVisualization($familyAsChildId);
            if ($family && ! empty($family['children'])) {
                foreach ($family['children'] as $child) {
                    $childId = is_object($child) ? $child->id : $child['id'];
                    if (! isset($persons[$childId])) {
                        $sibling = $this->getPersonForTreeVisualization($childId);
                        if ($sibling) {
                            $persons[$childId] = $this->formatPersonForTree($sibling);
                            $persons[$childId]['family_as_child'] = $familyAsChildId;

                            // Also get sibling's spouse families so Topola can draw them
                            $sibFamIds = [];
                            foreach ($this->getSpouseFamilyIdsForTreeVisualization($childId) as $sibFamId) {
                                $sibFamIds[] = $sibFamId;
                                if (! isset($families[$sibFamId])) {
                                    $sibFam = $this->getFamilyForTreeVisualization($sibFamId);
                                    if ($sibFam) {
                                        $families[$sibFamId] = $this->formatFamilyForTree($sibFam);
                                        // Add sibling's spouse as person
                                        $hId = $sibFam['husband_id'] ?? null;
                                        $wId = $sibFam['wife_id'] ?? null;
                                        $sibSpouseId = $hId == $childId ? $wId : $hId;
                                        if ($sibSpouseId && ! isset($persons[$sibSpouseId])) {
                                            $sp = $this->getPersonForTreeVisualization($sibSpouseId);
                                            if ($sp) {
                                                $persons[$sibSpouseId] = $this->formatPersonForTree($sp);
                                            }
                                        }
                                    }
                                }
                            }
                            if (! empty($sibFamIds)) {
                                $persons[$childId]['families_as_spouse'] = $sibFamIds;
                            }
                        }
                    }
                }
            }
        }

        // 2. In-law parents: for each spouse, add their parent family
        foreach ($this->getSpouseFamilyIdsForTreeVisualization($personId) as $familyId) {
            $family = $this->getFamilyForTreeVisualization($familyId);
            if (! $family) {
                continue;
            }

            $hId = $family['husband_id'] ?? null;
            $wId = $family['wife_id'] ?? null;
            $spouseId = $hId == $personId ? $wId : $hId;
            if (! $spouseId) {
                continue;
            }

            // Get spouse's parent family
            $spFamId = $this->getParentFamilyIdForTreeVisualization($spouseId);
            if ($spFamId !== null) {
                if (! isset($families[$spFamId])) {
                    $spFam = $this->getFamilyForTreeVisualization($spFamId);
                    if ($spFam) {
                        $families[$spFamId] = $this->formatFamilyForTree($spFam);
                        // Add in-law parents as persons
                        foreach (['husband_id', 'wife_id'] as $key) {
                            $inLawId = $spFam[$key] ?? null;
                            if ($inLawId && ! isset($persons[$inLawId])) {
                                $inLaw = $this->getPersonForTreeVisualization($inLawId);
                                if ($inLaw) {
                                    $persons[$inLawId] = $this->formatPersonForTree($inLaw);
                                }
                            }
                        }
                    }
                }
                // Link spouse to their parent family
                if (isset($persons[$spouseId])) {
                    $persons[$spouseId]['family_as_child'] = $spFamId;
                }
            }
        }
    }

    /**
     * Format person data for tree visualization
     */
    private function formatPersonForTree(array $person): array
    {
        return [
            'id' => $person['id'],
            'gedcom_id' => $person['gedcom_id'] ?? null,
            'given_name' => $person['given_name'] ?? '',
            'surname' => $person['surname'] ?? '',
            'sex' => $person['sex'] ?? 'U',
            'birth_date' => $person['birth_date'] ?? null,
            'birth_place' => $person['birth_place'] ?? null,
            'death_date' => $person['death_date'] ?? null,
            'death_place' => $person['death_place'] ?? null,
            'photo' => $person['primary_photo_url'] ?? null,
            'family_as_child' => null,
            'families_as_spouse' => [],
        ];
    }

    private function getPersonForTreeVisualization(int $personId): ?array
    {
        $person = DB::selectOne('
            SELECT id, gedcom_id, given_name, surname, sex, birth_date, birth_place, death_date, death_place, primary_photo_id
            FROM genealogy_persons
            WHERE id = ?
        ', [$personId]);

        if (! $person) {
            return null;
        }

        $result = (array) $person;
        $result['primary_photo_url'] = $this->getPrimaryPhotoUrl($result['primary_photo_id'] ?? null);

        return $result;
    }

    private function getFamilyForTreeVisualization(int $familyId): ?array
    {
        $family = DB::selectOne('
            SELECT id, gedcom_id, husband_id, wife_id, marriage_date, marriage_place
            FROM genealogy_families
            WHERE id = ?
        ', [$familyId]);

        if (! $family) {
            return null;
        }

        $result = (array) $family;
        $result['children'] = $this->getFamilyChildren($familyId);

        return $result;
    }

    private function getParentFamilyIdForTreeVisualization(int $personId): ?int
    {
        $row = DB::selectOne('SELECT family_id FROM genealogy_children WHERE person_id = ? LIMIT 1', [$personId]);

        return $row ? (int) $row->family_id : null;
    }

    private function getSpouseFamilyIdsForTreeVisualization(int $personId): array
    {
        return array_map(
            static fn ($row) => (int) $row->id,
            DB::select(
                'SELECT id FROM genealogy_families WHERE husband_id = ? OR wife_id = ? ORDER BY COALESCE(marriage_date, "9999")',
                [$personId, $personId]
            )
        );
    }

    /**
     * Format family data for tree visualization
     */
    private function formatFamilyForTree(array $family): array
    {
        $childIds = [];
        if (! empty($family['children'])) {
            foreach ($family['children'] as $child) {
                // Handle both array and stdClass
                $childIds[] = is_object($child) ? $child->id : $child['id'];
            }
        }

        // The family array may have different key names depending on source
        $husbandId = $family['husband_db_id'] ?? $family['husband_id'] ?? null;
        $wifeId = $family['wife_db_id'] ?? $family['wife_id'] ?? null;

        return [
            'id' => $family['id'],
            'gedcom_id' => $family['gedcom_id'] ?? null,
            'husband_id' => $husbandId,
            'wife_id' => $wifeId,
            'marriage_date' => $family['marriage_date'] ?? null,
            'marriage_place' => $family['marriage_place'] ?? null,
            'children' => $childIds,
        ];
    }

    // ========================================================================
    // PHASE 4: EXPORT, BACKUP & DATA INTEGRITY
    // ========================================================================

    /**
     * Export tree to GEDCOM 5.5.1 format
     */
    public function exportToGedcom(int $treeId): string
    {
        $tree = $this->getTree($treeId);
        if (! $tree) {
            throw new Exception("Tree not found: {$treeId}");
        }

        $lines = [];

        // GEDCOM Header
        $lines[] = '0 HEAD';
        $lines[] = '1 SOUR PLOS';
        $lines[] = '2 NAME PLOS Genealogy';
        $lines[] = '2 VERS 1.0';
        $lines[] = '1 DEST GEDCOM';
        $lines[] = '1 DATE '.date('d M Y');
        $lines[] = '2 TIME '.date('H:i:s');
        $lines[] = '1 SUBM @SUBM1@';
        $lines[] = '1 GEDC';
        $lines[] = '2 VERS 5.5.1';
        $lines[] = '2 FORM LINEAGE-LINKED';
        $lines[] = '1 CHAR UTF-8';

        // Submitter
        $lines[] = '0 @SUBM1@ SUBM';
        $lines[] = '1 NAME PLOS Export';

        // Export Individuals
        $personsSql = 'SELECT * FROM genealogy_persons WHERE tree_id = ? ORDER BY id';
        $persons = DB::select($personsSql, [$treeId]);

        foreach ($persons as $person) {
            $gedcomId = $person->gedcom_id ?: "@I{$person->id}@";
            if (strpos($gedcomId, '@') !== 0) {
                $gedcomId = "@{$gedcomId}@";
            }

            $lines[] = "0 {$gedcomId} INDI";

            // Name
            $name = trim(($person->given_name ?? '').' /'.($person->surname ?? '').'/');
            $lines[] = "1 NAME {$name}";
            if ($person->given_name) {
                $lines[] = "2 GIVN {$person->given_name}";
            }
            if ($person->surname) {
                $lines[] = "2 SURN {$person->surname}";
            }
            if ($person->name_prefix) {
                $lines[] = "2 NPFX {$person->name_prefix}";
            }
            if ($person->name_suffix) {
                $lines[] = "2 NSFX {$person->name_suffix}";
            }

            // Sex
            if ($person->sex) {
                $lines[] = "1 SEX {$person->sex}";
            }

            // Birth
            if ($person->birth_date || $person->birth_place) {
                $lines[] = '1 BIRT';
                if ($person->birth_date) {
                    $lines[] = "2 DATE {$person->birth_date}";
                }
                if ($person->birth_place) {
                    $lines[] = "2 PLAC {$person->birth_place}";
                }
            }

            // Death
            if ($person->death_date || $person->death_place) {
                $lines[] = '1 DEAT';
                if ($person->death_date) {
                    $lines[] = "2 DATE {$person->death_date}";
                }
                if ($person->death_place) {
                    $lines[] = "2 PLAC {$person->death_place}";
                }
            }

            // Occupation
            if ($person->occupation) {
                $lines[] = "1 OCCU {$person->occupation}";
            }

            // Note
            if ($person->notes) {
                $lines[] = "1 NOTE {$person->notes}";
            }

            // Family links as child
            $famcSql = 'SELECT f.gedcom_id, f.id FROM genealogy_families f
                        JOIN genealogy_children fc ON f.id = fc.family_id
                        WHERE fc.person_id = ?';
            $famcs = DB::select($famcSql, [$person->id]);
            foreach ($famcs as $famc) {
                $famGedcomId = $famc->gedcom_id ?: "@F{$famc->id}@";
                if (strpos($famGedcomId, '@') !== 0) {
                    $famGedcomId = "@{$famGedcomId}@";
                }
                $lines[] = "1 FAMC {$famGedcomId}";
            }

            // Family links as spouse
            $famsSql = 'SELECT gedcom_id, id FROM genealogy_families
                        WHERE (husband_id = ? OR wife_id = ?) AND tree_id = ?';
            $famss = DB::select($famsSql, [$person->id, $person->id, $treeId]);
            foreach ($famss as $fams) {
                $famGedcomId = $fams->gedcom_id ?: "@F{$fams->id}@";
                if (strpos($famGedcomId, '@') !== 0) {
                    $famGedcomId = "@{$famGedcomId}@";
                }
                $lines[] = "1 FAMS {$famGedcomId}";
            }
        }

        // Export Families
        $familiesSql = 'SELECT * FROM genealogy_families WHERE tree_id = ? ORDER BY id';
        $families = DB::select($familiesSql, [$treeId]);

        foreach ($families as $family) {
            $gedcomId = $family->gedcom_id ?: "@F{$family->id}@";
            if (strpos($gedcomId, '@') !== 0) {
                $gedcomId = "@{$gedcomId}@";
            }

            $lines[] = "0 {$gedcomId} FAM";

            // Husband
            if ($family->husband_id) {
                $husbandSql = 'SELECT gedcom_id, id FROM genealogy_persons WHERE id = ?';
                $husband = DB::selectOne($husbandSql, [$family->husband_id]);
                if ($husband) {
                    $husbGedcomId = $husband->gedcom_id ?: "@I{$husband->id}@";
                    if (strpos($husbGedcomId, '@') !== 0) {
                        $husbGedcomId = "@{$husbGedcomId}@";
                    }
                    $lines[] = "1 HUSB {$husbGedcomId}";
                }
            }

            // Wife
            if ($family->wife_id) {
                $wifeSql = 'SELECT gedcom_id, id FROM genealogy_persons WHERE id = ?';
                $wife = DB::selectOne($wifeSql, [$family->wife_id]);
                if ($wife) {
                    $wifeGedcomId = $wife->gedcom_id ?: "@I{$wife->id}@";
                    if (strpos($wifeGedcomId, '@') !== 0) {
                        $wifeGedcomId = "@{$wifeGedcomId}@";
                    }
                    $lines[] = "1 WIFE {$wifeGedcomId}";
                }
            }

            // Children
            $childrenSql = 'SELECT p.gedcom_id, p.id FROM genealogy_persons p
                           JOIN genealogy_children fc ON p.id = fc.person_id
                           WHERE fc.family_id = ? ORDER BY fc.birth_order';
            $children = DB::select($childrenSql, [$family->id]);
            foreach ($children as $child) {
                $childGedcomId = $child->gedcom_id ?: "@I{$child->id}@";
                if (strpos($childGedcomId, '@') !== 0) {
                    $childGedcomId = "@{$childGedcomId}@";
                }
                $lines[] = "1 CHIL {$childGedcomId}";
            }

            // Marriage
            if ($family->marriage_date || $family->marriage_place) {
                $lines[] = '1 MARR';
                if ($family->marriage_date) {
                    $lines[] = "2 DATE {$family->marriage_date}";
                }
                if ($family->marriage_place) {
                    $lines[] = "2 PLAC {$family->marriage_place}";
                }
            }

            // Divorce
            if ($family->divorce_date) {
                $lines[] = '1 DIV';
                $lines[] = "2 DATE {$family->divorce_date}";
            }
        }

        // Footer
        $lines[] = '0 TRLR';

        return implode("\r\n", $lines);
    }

    /**
     * Validate data integrity for a tree
     */
    public function validateTreeIntegrity(int $treeId): array
    {
        $issues = [];
        $warnings = [];

        // Check for orphaned family children references
        $orphanedChildrenSql = '
            SELECT fc.id, fc.family_id, fc.person_id
            FROM genealogy_children fc
            LEFT JOIN genealogy_persons p ON fc.person_id = p.id
            LEFT JOIN genealogy_families f ON fc.family_id = f.id
            WHERE p.id IS NULL OR f.id IS NULL
        ';
        $orphanedChildren = DB::select($orphanedChildrenSql);
        if (! empty($orphanedChildren)) {
            $issues[] = [
                'type' => 'orphaned_child_link',
                'severity' => 'error',
                'message' => count($orphanedChildren).' orphaned family-child relationships found',
                'details' => $orphanedChildren,
            ];
        }

        // Check for families with missing spouses (both null)
        $noSpouseFamiliesSql = '
            SELECT id, gedcom_id FROM genealogy_families
            WHERE tree_id = ? AND husband_id IS NULL AND wife_id IS NULL
        ';
        $noSpouseFamilies = DB::select($noSpouseFamiliesSql, [$treeId]);
        if (! empty($noSpouseFamilies)) {
            $warnings[] = [
                'type' => 'no_spouse_family',
                'severity' => 'warning',
                'message' => count($noSpouseFamilies).' families have no spouses defined',
                'details' => $noSpouseFamilies,
            ];
        }

        // Check for persons with invalid family references
        $invalidHusbandSql = '
            SELECT f.id, f.gedcom_id, f.husband_id
            FROM genealogy_families f
            LEFT JOIN genealogy_persons p ON f.husband_id = p.id
            WHERE f.tree_id = ? AND f.husband_id IS NOT NULL AND p.id IS NULL
        ';
        $invalidHusbands = DB::select($invalidHusbandSql, [$treeId]);
        if (! empty($invalidHusbands)) {
            $issues[] = [
                'type' => 'invalid_husband_ref',
                'severity' => 'error',
                'message' => count($invalidHusbands).' families reference non-existent husbands',
                'details' => $invalidHusbands,
            ];
        }

        $invalidWifeSql = '
            SELECT f.id, f.gedcom_id, f.wife_id
            FROM genealogy_families f
            LEFT JOIN genealogy_persons p ON f.wife_id = p.id
            WHERE f.tree_id = ? AND f.wife_id IS NOT NULL AND p.id IS NULL
        ';
        $invalidWives = DB::select($invalidWifeSql, [$treeId]);
        if (! empty($invalidWives)) {
            $issues[] = [
                'type' => 'invalid_wife_ref',
                'severity' => 'error',
                'message' => count($invalidWives).' families reference non-existent wives',
                'details' => $invalidWives,
            ];
        }

        // Check for persons with no birth info and no family connection
        $isolatedPersonsSql = '
            SELECT p.id, p.gedcom_id, p.given_name, p.surname
            FROM genealogy_persons p
            LEFT JOIN genealogy_families f1 ON p.id = f1.husband_id OR p.id = f1.wife_id
            LEFT JOIN genealogy_children fc ON p.id = fc.person_id
            WHERE p.tree_id = ? AND f1.id IS NULL AND fc.id IS NULL
        ';
        $isolatedPersons = DB::select($isolatedPersonsSql, [$treeId]);
        if (! empty($isolatedPersons)) {
            $warnings[] = [
                'type' => 'isolated_person',
                'severity' => 'info',
                'message' => count($isolatedPersons).' persons have no family connections',
                'details' => array_slice($isolatedPersons, 0, 10), // Limit details
            ];
        }

        // Check for duplicate GEDCOM IDs
        $duplicatePersonGedcomSql = '
            SELECT gedcom_id, COUNT(*) as count
            FROM genealogy_persons
            WHERE tree_id = ? AND gedcom_id IS NOT NULL
            GROUP BY gedcom_id
            HAVING COUNT(*) > 1
        ';
        $duplicatePersonGedcom = DB::select($duplicatePersonGedcomSql, [$treeId]);
        if (! empty($duplicatePersonGedcom)) {
            $issues[] = [
                'type' => 'duplicate_person_gedcom_id',
                'severity' => 'error',
                'message' => count($duplicatePersonGedcom).' duplicate person GEDCOM IDs found',
                'details' => $duplicatePersonGedcom,
            ];
        }

        $duplicateFamilyGedcomSql = '
            SELECT gedcom_id, COUNT(*) as count
            FROM genealogy_families
            WHERE tree_id = ? AND gedcom_id IS NOT NULL
            GROUP BY gedcom_id
            HAVING COUNT(*) > 1
        ';
        $duplicateFamilyGedcom = DB::select($duplicateFamilyGedcomSql, [$treeId]);
        if (! empty($duplicateFamilyGedcom)) {
            $issues[] = [
                'type' => 'duplicate_family_gedcom_id',
                'severity' => 'error',
                'message' => count($duplicateFamilyGedcom).' duplicate family GEDCOM IDs found',
                'details' => $duplicateFamilyGedcom,
            ];
        }

        // Check for circular relationships (person is their own ancestor)
        // This is a simplified check - just looks for immediate issues
        $selfParentSql = '
            SELECT f.id as family_id, fc.person_id
            FROM genealogy_families f
            JOIN genealogy_children fc ON f.id = fc.family_id
            WHERE f.tree_id = ? AND (f.husband_id = fc.person_id OR f.wife_id = fc.person_id)
        ';
        $selfParents = DB::select($selfParentSql, [$treeId]);
        if (! empty($selfParents)) {
            $issues[] = [
                'type' => 'self_parent',
                'severity' => 'error',
                'message' => count($selfParents).' persons are listed as their own parent',
                'details' => $selfParents,
            ];
        }

        // Check media references (person-media links pointing to non-existent media)
        $orphanedMediaLinksSql = '
            SELECT pm.id, pm.media_id, pm.person_id
            FROM genealogy_person_media pm
            LEFT JOIN genealogy_media m ON pm.media_id = m.id
            WHERE m.id IS NULL
        ';
        $orphanedMediaLinks = DB::select($orphanedMediaLinksSql);
        if (! empty($orphanedMediaLinks)) {
            $issues[] = [
                'type' => 'orphaned_media_link',
                'severity' => 'error',
                'message' => count($orphanedMediaLinks).' person-media links reference non-existent media',
                'details' => $orphanedMediaLinks,
            ];
        }

        return [
            'tree_id' => $treeId,
            'validated_at' => date('Y-m-d H:i:s'),
            'is_valid' => empty($issues),
            'error_count' => count($issues),
            'warning_count' => count($warnings),
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get detailed statistics for a tree (Phase 4: comprehensive version)
     */
    public function getDetailedTreeStatistics(int $treeId): array
    {
        // Basic counts
        $personCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?',
            [$treeId]
        )->count;

        $familyCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_families WHERE tree_id = ?',
            [$treeId]
        )->count;

        // Gender breakdown
        $genderStats = DB::select(
            'SELECT sex, COUNT(*) as count FROM genealogy_persons WHERE tree_id = ? GROUP BY sex',
            [$treeId]
        );
        $genders = [];
        foreach ($genderStats as $g) {
            $genders[$g->sex ?: 'U'] = $g->count;
        }

        // Living vs deceased
        $livingCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ? AND death_date IS NULL',
            [$treeId]
        )->count;

        // Birth/death date ranges - extract years using regex (GEDCOM dates like "15 MAR 1850")
        $dateRanges = DB::selectOne("
            SELECT
                MIN(CAST(REGEXP_SUBSTR(birth_date, '[0-9]{4}') AS UNSIGNED)) as earliest_birth,
                MAX(CAST(REGEXP_SUBSTR(birth_date, '[0-9]{4}') AS UNSIGNED)) as latest_birth,
                MIN(CAST(REGEXP_SUBSTR(death_date, '[0-9]{4}') AS UNSIGNED)) as earliest_death,
                MAX(CAST(REGEXP_SUBSTR(death_date, '[0-9]{4}') AS UNSIGNED)) as latest_death
            FROM genealogy_persons
            WHERE tree_id = ?
              AND (
                  (birth_date IS NOT NULL AND birth_date != '' AND birth_date REGEXP '[0-9]{4}')
                  OR (death_date IS NOT NULL AND death_date != '' AND death_date REGEXP '[0-9]{4}')
              )
        ", [$treeId]);

        // Surname distribution
        $surnameStats = DB::select("
            SELECT surname, COUNT(*) as count
            FROM genealogy_persons
            WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
            GROUP BY surname
            ORDER BY count DESC
            LIMIT 20
        ", [$treeId]);

        // Place distribution (birth places)
        $placeStats = DB::select("
            SELECT birth_place as place, COUNT(*) as count
            FROM genealogy_persons
            WHERE tree_id = ? AND birth_place IS NOT NULL AND birth_place != ''
            GROUP BY birth_place
            ORDER BY count DESC
            LIMIT 20
        ", [$treeId]);

        // Media statistics
        $mediaStats = DB::selectOne("
            SELECT
                COUNT(*) as total_media,
                SUM(CASE WHEN media_type = 'photo' THEN 1 ELSE 0 END) as photos,
                SUM(CASE WHEN media_type = 'document' THEN 1 ELSE 0 END) as documents,
                SUM(CASE WHEN media_type = 'headstone' THEN 1 ELSE 0 END) as headstones,
                SUM(CASE WHEN media_type = 'audio' THEN 1 ELSE 0 END) as audio,
                SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) as video
            FROM genealogy_media
            WHERE tree_id = ?
        ", [$treeId]);

        // Average children per family
        $avgChildren = DB::selectOne('
            SELECT AVG(child_count) as avg_children
            FROM (
                SELECT f.id, COUNT(fc.id) as child_count
                FROM genealogy_families f
                LEFT JOIN genealogy_children fc ON f.id = fc.family_id
                WHERE f.tree_id = ?
                GROUP BY f.id
            ) as family_children
        ', [$treeId]);

        // Generations estimate (longest ancestor chain)
        $generations = $this->estimateGenerations($treeId);

        return [
            'tree_id' => $treeId,
            'generated_at' => date('Y-m-d H:i:s'),
            'persons' => [
                'total' => $personCount,
                'by_gender' => $genders,
                'living' => $livingCount,
                'deceased' => $personCount - $livingCount,
            ],
            'families' => [
                'total' => $familyCount,
                'avg_children' => round((float) ($avgChildren->avg_children ?? 0), 2),
            ],
            'dates' => [
                'earliest_birth' => $dateRanges->earliest_birth,
                'latest_birth' => $dateRanges->latest_birth,
                'earliest_death' => $dateRanges->earliest_death,
                'latest_death' => $dateRanges->latest_death,
            ],
            'surnames' => $surnameStats,
            'places' => $placeStats,
            'media' => [
                'total' => (int) ($mediaStats->total_media ?? 0),
                'photos' => (int) ($mediaStats->photos ?? 0),
                'documents' => (int) ($mediaStats->documents ?? 0),
                'headstones' => (int) ($mediaStats->headstones ?? 0),
                'audio' => (int) ($mediaStats->audio ?? 0),
                'video' => (int) ($mediaStats->video ?? 0),
            ],
            'generations' => $generations,
        ];
    }

    /**
     * Estimate number of generations in tree
     */
    private function estimateGenerations(int $treeId): int
    {
        // Find a person with valid birth date who has no parents (earliest ancestor)
        $rootPersonSql = "
            SELECT p.id
            FROM genealogy_persons p
            LEFT JOIN genealogy_children fc ON p.id = fc.person_id
            WHERE p.tree_id = ?
              AND fc.id IS NULL
              AND p.birth_date IS NOT NULL
              AND p.birth_date != ''
            ORDER BY p.birth_date ASC
            LIMIT 1
        ";
        $rootPerson = DB::selectOne($rootPersonSql, [$treeId]);

        if (! $rootPerson) {
            // Fallback: find any root person without parents
            $fallbackSql = '
                SELECT p.id
                FROM genealogy_persons p
                LEFT JOIN genealogy_children fc ON p.id = fc.person_id
                WHERE p.tree_id = ? AND fc.id IS NULL
                LIMIT 1
            ';
            $rootPerson = DB::selectOne($fallbackSql, [$treeId]);

            if (! $rootPerson) {
                return 0;
            }
        }

        return $this->countDescendantGenerations($rootPerson->id, 0, $treeId);
    }

    /**
     * Recursively count descendant generations
     */
    private function countDescendantGenerations(int $personId, int $currentDepth, int $treeId, array &$visited = []): int
    {
        if (in_array($personId, $visited) || $currentDepth > 50) {
            return $currentDepth;
        }
        $visited[] = $personId;

        $maxDepth = $currentDepth;

        // Get families where this person is a spouse
        $familiesSql = 'SELECT id FROM genealogy_families WHERE (husband_id = ? OR wife_id = ?) AND tree_id = ?';
        $families = DB::select($familiesSql, [$personId, $personId, $treeId]);

        foreach ($families as $family) {
            // Get children
            $childrenSql = 'SELECT person_id FROM genealogy_children WHERE family_id = ?';
            $children = DB::select($childrenSql, [$family->id]);

            foreach ($children as $child) {
                $childDepth = $this->countDescendantGenerations($child->person_id, $currentDepth + 1, $treeId, $visited);
                $maxDepth = max($maxDepth, $childDepth);
            }
        }

        return $maxDepth;
    }

    /**
     * Get backup status for genealogy data
     */
    public function getBackupStatus(int $treeId): array
    {
        $tree = $this->getTree($treeId);

        // Count records
        $personCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?',
            [$treeId]
        )->count;

        $familyCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_families WHERE tree_id = ?',
            [$treeId]
        )->count;

        $mediaCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_media WHERE tree_id = ?',
            [$treeId]
        )->count;

        $sourceCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_sources WHERE tree_id = ?',
            [$treeId]
        )->count;

        // Get latest modifications
        $latestPersonUpdate = DB::selectOne(
            'SELECT MAX(updated_at) as latest FROM genealogy_persons WHERE tree_id = ?',
            [$treeId]
        );

        $latestFamilyUpdate = DB::selectOne(
            'SELECT MAX(updated_at) as latest FROM genealogy_families WHERE tree_id = ?',
            [$treeId]
        );

        $latestMediaUpdate = DB::selectOne(
            'SELECT MAX(updated_at) as latest FROM genealogy_media WHERE tree_id = ?',
            [$treeId]
        );

        // Calculate estimated GEDCOM file size
        $estimatedGedcomSize = ($personCount * 500) + ($familyCount * 300) + 1000; // rough estimate

        return [
            'tree_id' => $treeId,
            'tree_name' => $tree->name ?? 'Unknown',
            'checked_at' => date('Y-m-d H:i:s'),
            'record_counts' => [
                'persons' => $personCount,
                'families' => $familyCount,
                'media' => $mediaCount,
                'sources' => $sourceCount,
            ],
            'latest_updates' => [
                'persons' => $latestPersonUpdate->latest ?? null,
                'families' => $latestFamilyUpdate->latest ?? null,
                'media' => $latestMediaUpdate->latest ?? null,
            ],
            'estimated_gedcom_size' => $this->formatBytes($estimatedGedcomSize),
            'recommendations' => $this->getBackupRecommendations($personCount, $mediaCount),
        ];
    }

    /**
     * Format bytes to human readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2).' '.$units[$index];
    }

    /**
     * Get backup recommendations based on data size
     */
    private function getBackupRecommendations(int $personCount, int $mediaCount): array
    {
        $recommendations = [];

        if ($personCount > 1000) {
            $recommendations[] = 'Consider weekly GEDCOM exports for large trees';
        }

        if ($mediaCount > 100) {
            $recommendations[] = 'Media files should be backed up separately from database';
        }

        if ($personCount > 0) {
            $recommendations[] = 'Export GEDCOM before making bulk changes';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Regular backups recommended';
        }

        return $recommendations;
    }

    // ========================================================================
    // PHASE 5: ADVANCED VISUALIZATION & ANALYSIS
    // ========================================================================

    /**
     * Get timeline for a person (birth, events, marriage, death)
     */
    public function getPersonTimeline(int $personId): array
    {
        $timeline = [];

        // Get person basic info
        $personSql = 'SELECT p.*, t.name as tree_name FROM genealogy_persons p
                      JOIN genealogy_trees t ON p.tree_id = t.id
                      WHERE p.id = ?';
        $person = DB::selectOne($personSql, [$personId]);

        if (! $person) {
            return [];
        }

        // Birth event
        if ($person->birth_date || $person->birth_place) {
            $timeline[] = [
                'type' => 'birth',
                'date' => $person->birth_date,
                'sortable_year' => $this->extractYear($person->birth_date) ?? 0,
                'place' => $person->birth_place,
                'title' => 'Birth',
                'description' => $this->formatEventDescription('Born', $person->birth_date, $person->birth_place),
                'icon' => 'baby',
            ];
        }

        // Individual events (from genealogy_events table)
        $eventsSql = 'SELECT * FROM genealogy_events
                      WHERE person_id = ?
                      ORDER BY COALESCE(
                        CAST(SUBSTRING(event_date, 1, 4) AS UNSIGNED),
                        9999
                      )';
        $events = DB::select($eventsSql, [$personId]);

        foreach ($events as $event) {
            $timeline[] = [
                'type' => 'event',
                'event_type' => $event->event_type,
                'date' => $event->event_date,
                'sortable_year' => $this->extractYear($event->event_date) ?? 9999,
                'place' => $event->event_place,
                'title' => $this->getEventTypeLabel($event->event_type),
                'description' => $this->formatEventDescription(
                    $this->getEventTypeLabel($event->event_type),
                    $event->event_date,
                    $event->event_place
                ),
                'notes' => $event->description ?? null,
                'icon' => $this->getEventIcon($event->event_type),
            ];
        }

        // Marriages
        $marriagesSql = '
            SELECT f.*,
                   CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END as spouse_id,
                   sp.given_name as spouse_given_name, sp.surname as spouse_surname
            FROM genealogy_families f
            LEFT JOIN genealogy_persons sp ON sp.id = CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END
            WHERE (f.husband_id = ? OR f.wife_id = ?)
            ORDER BY COALESCE(
                CAST(SUBSTRING(f.marriage_date, 1, 4) AS UNSIGNED),
                9999
            )
        ';
        $marriages = DB::select($marriagesSql, [$personId, $personId, $personId, $personId]);

        foreach ($marriages as $marriage) {
            $spouseName = trim(($marriage->spouse_given_name ?? '').' '.($marriage->spouse_surname ?? ''));

            if ($marriage->marriage_date || $marriage->marriage_place) {
                $timeline[] = [
                    'type' => 'marriage',
                    'date' => $marriage->marriage_date,
                    'sortable_year' => $this->extractYear($marriage->marriage_date) ?? 9999,
                    'place' => $marriage->marriage_place,
                    'title' => 'Marriage',
                    'description' => 'Married '.($spouseName ?: 'unknown spouse').
                        ($marriage->marriage_date ? ' on '.$marriage->marriage_date : '').
                        ($marriage->marriage_place ? ' at '.$marriage->marriage_place : ''),
                    'spouse_id' => $marriage->spouse_id,
                    'spouse_name' => $spouseName,
                    'family_id' => $marriage->id,
                    'icon' => 'rings',
                ];
            }

            if ($marriage->divorce_date) {
                $timeline[] = [
                    'type' => 'divorce',
                    'date' => $marriage->divorce_date,
                    'sortable_year' => $this->extractYear($marriage->divorce_date) ?? 9999,
                    'place' => null,
                    'title' => 'Divorce',
                    'description' => 'Divorced from '.($spouseName ?: 'spouse'),
                    'spouse_id' => $marriage->spouse_id,
                    'family_id' => $marriage->id,
                    'icon' => 'divide',
                ];
            }
        }

        // Death event
        if ($person->death_date || $person->death_place) {
            $timeline[] = [
                'type' => 'death',
                'date' => $person->death_date,
                'sortable_year' => $this->extractYear($person->death_date) ?? 9999,
                'place' => $person->death_place,
                'title' => 'Death',
                'description' => $this->formatEventDescription('Died', $person->death_date, $person->death_place),
                'icon' => 'cross',
            ];
        }

        // Burial if in notes
        if ($person->burial_place) {
            $timeline[] = [
                'type' => 'burial',
                'date' => $person->burial_date ?? null,
                'sortable_year' => $this->extractYear($person->burial_date ?? $person->death_date) ?? 9999,
                'place' => $person->burial_place,
                'title' => 'Burial',
                'description' => 'Buried at '.$person->burial_place,
                'icon' => 'cemetery',
            ];
        }

        // Sort by year
        usort($timeline, function ($a, $b) {
            return $a['sortable_year'] <=> $b['sortable_year'];
        });

        return [
            'person' => [
                'id' => $person->id,
                'name' => trim(($person->given_name ?? '').' '.($person->surname ?? '')),
                'sex' => $person->sex,
                'birth_year' => $this->extractYear($person->birth_date),
                'death_year' => $this->extractYear($person->death_date),
                'birth_date' => $person->birth_date,
                'death_date' => $person->death_date,
                'living' => $person->living ?? false,
            ],
            'events' => $timeline,
        ];
    }

    /**
     * Extract year from GEDCOM date
     */
    private function extractYear(?string $date): ?int
    {
        if (! $date) {
            return null;
        }

        // Try to find a 4-digit year
        if (preg_match('/\b(\d{4})\b/', $date, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Format event description
     */
    private function formatEventDescription(string $verb, ?string $date, ?string $place): string
    {
        $parts = [$verb];
        if ($date) {
            $parts[] = 'on '.$date;
        }
        if ($place) {
            $parts[] = 'at '.$place;
        }

        return implode(' ', $parts);
    }

    /**
     * Get human readable event type label
     */
    private function getEventTypeLabel(string $type): string
    {
        $labels = [
            'CHR' => 'Christening',
            'BAPM' => 'Baptism',
            'CONF' => 'Confirmation',
            'BARM' => 'Bar Mitzvah',
            'BASM' => 'Bas Mitzvah',
            'BLES' => 'Blessing',
            'GRAD' => 'Graduation',
            'ORDN' => 'Ordination',
            'OCCU' => 'Occupation',
            'RESI' => 'Residence',
            'CENS' => 'Census',
            'EMIG' => 'Emigration',
            'IMMI' => 'Immigration',
            'NATU' => 'Naturalization',
            'WILL' => 'Will',
            'PROB' => 'Probate',
            'RETI' => 'Retirement',
            'MILI' => 'Military Service',
        ];

        return $labels[$type] ?? ucfirst(strtolower($type));
    }

    /**
     * Get icon for event type
     */
    private function getEventIcon(string $type): string
    {
        $icons = [
            'CHR' => 'church',
            'BAPM' => 'church',
            'CONF' => 'church',
            'GRAD' => 'graduation',
            'OCCU' => 'briefcase',
            'RESI' => 'home',
            'CENS' => 'document',
            'EMIG' => 'plane',
            'IMMI' => 'plane',
            'MILI' => 'shield',
        ];

        return $icons[$type] ?? 'star';
    }

    /**
     * Calculate relationship between two persons
     */
    public function calculateRelationship(int $personId1, int $personId2, int $treeId): array
    {
        if ($personId1 === $personId2) {
            return [
                'relationship' => 'Self',
                'path' => [],
                'degree' => 0,
            ];
        }

        // Build ancestor maps for both persons
        $ancestors1 = $this->buildAncestorMap($personId1, $treeId);
        $ancestors2 = $this->buildAncestorMap($personId2, $treeId);

        // Find common ancestors
        $commonAncestors = array_intersect(array_keys($ancestors1), array_keys($ancestors2));

        if (empty($commonAncestors)) {
            // Check if person2 is descendant of person1 or vice versa
            $descendants1 = $this->findDescendantPath($personId1, $personId2, $treeId);
            if ($descendants1) {
                return [
                    'relationship' => $this->describeDescendantRelationship($descendants1),
                    'path' => $descendants1,
                    'degree' => count($descendants1) - 1,
                    'direction' => 'descendant',
                ];
            }

            $descendants2 = $this->findDescendantPath($personId2, $personId1, $treeId);
            if ($descendants2) {
                return [
                    'relationship' => $this->describeAncestorRelationship($descendants2),
                    'path' => array_reverse($descendants2),
                    'degree' => count($descendants2) - 1,
                    'direction' => 'ancestor',
                ];
            }

            return [
                'relationship' => 'Not Related (within tree)',
                'path' => [],
                'degree' => null,
            ];
        }

        // Find the closest common ancestor
        $closestAncestor = null;
        $minTotalDistance = PHP_INT_MAX;
        $gen1 = 0;
        $gen2 = 0;

        foreach ($commonAncestors as $ancestorId) {
            $dist1 = $ancestors1[$ancestorId];
            $dist2 = $ancestors2[$ancestorId];
            $totalDist = $dist1 + $dist2;

            if ($totalDist < $minTotalDistance) {
                $minTotalDistance = $totalDist;
                $closestAncestor = $ancestorId;
                $gen1 = $dist1;
                $gen2 = $dist2;
            }
        }

        // Get ancestor name
        $ancestorSql = 'SELECT given_name, surname FROM genealogy_persons WHERE id = ?';
        $ancestor = DB::selectOne($ancestorSql, [$closestAncestor]);
        $ancestorName = trim(($ancestor->given_name ?? '').' '.($ancestor->surname ?? ''));

        $relationship = $this->describeRelationship($gen1, $gen2);

        return [
            'relationship' => $relationship,
            'common_ancestor' => [
                'id' => $closestAncestor,
                'name' => $ancestorName,
            ],
            'generations_to_person1' => $gen1,
            'generations_to_person2' => $gen2,
            'degree' => max($gen1, $gen2),
        ];
    }

    /**
     * Build map of ancestors with their generation distance
     */
    private function buildAncestorMap(int $personId, int $treeId, int $maxGenerations = 15): array
    {
        $ancestors = [];
        $queue = [[$personId, 0]];
        $visited = [];

        while (! empty($queue)) {
            [$currentId, $generation] = array_shift($queue);

            if (in_array($currentId, $visited) || $generation > $maxGenerations) {
                continue;
            }
            $visited[] = $currentId;

            if ($generation > 0) {
                $ancestors[$currentId] = $generation;
            }

            // Find parents
            $parentsSql = '
                SELECT f.husband_id, f.wife_id
                FROM genealogy_families f
                JOIN genealogy_children fc ON f.id = fc.family_id
                WHERE fc.person_id = ? AND f.tree_id = ?
            ';
            $families = DB::select($parentsSql, [$currentId, $treeId]);

            foreach ($families as $family) {
                if ($family->husband_id) {
                    $queue[] = [$family->husband_id, $generation + 1];
                }
                if ($family->wife_id) {
                    $queue[] = [$family->wife_id, $generation + 1];
                }
            }
        }

        return $ancestors;
    }

    /**
     * Find path from ancestor to descendant
     */
    private function findDescendantPath(int $ancestorId, int $descendantId, int $treeId, int $maxDepth = 15): ?array
    {
        $queue = [[$ancestorId, [$ancestorId]]];
        $visited = [];

        while (! empty($queue)) {
            [$currentId, $path] = array_shift($queue);

            if ($currentId === $descendantId) {
                return $path;
            }

            if (in_array($currentId, $visited) || count($path) > $maxDepth) {
                continue;
            }
            $visited[] = $currentId;

            // Find children
            $childrenSql = '
                SELECT fc.person_id
                FROM genealogy_children fc
                JOIN genealogy_families f ON fc.family_id = f.id
                WHERE (f.husband_id = ? OR f.wife_id = ?) AND f.tree_id = ?
            ';
            $children = DB::select($childrenSql, [$currentId, $currentId, $treeId]);

            foreach ($children as $child) {
                $newPath = array_merge($path, [$child->person_id]);
                $queue[] = [$child->person_id, $newPath];
            }
        }

        return null;
    }

    /**
     * Describe relationship based on generations
     */
    private function describeRelationship(int $gen1, int $gen2): string
    {
        if ($gen1 === 1 && $gen2 === 1) {
            return 'Sibling';
        }

        $min = min($gen1, $gen2);
        $diff = abs($gen1 - $gen2);

        if ($min === 1) {
            // Direct line (uncle/aunt, nephew/niece, etc.)
            if ($gen1 === 1) {
                // Person1 is the aunt/uncle
                $nephewTerm = $diff === 1 ? 'Nephew/Niece' : 'Grand-nephew/niece';

                return $nephewTerm;
            } else {
                // Person1 is the nephew/niece
                $auntTerm = $diff === 1 ? 'Uncle/Aunt' : 'Grand-uncle/aunt';

                return $auntTerm;
            }
        }

        // Cousins
        $cousinDegree = $min - 1;
        $removed = $diff;

        $ordinal = $this->getOrdinal($cousinDegree);

        if ($removed === 0) {
            return "{$ordinal} Cousin";
        } else {
            $removedText = $removed === 1 ? 'once removed' : ($removed.'x removed');

            return "{$ordinal} Cousin, {$removedText}";
        }
    }

    /**
     * Get ordinal suffix
     */
    private function getOrdinal(int $n): string
    {
        $ordinals = ['1st', '2nd', '3rd'];
        if ($n >= 1 && $n <= 3) {
            return $ordinals[$n - 1];
        }

        return "{$n}th";
    }

    /**
     * Describe descendant relationship
     */
    private function describeDescendantRelationship(array $path): string
    {
        $generations = count($path) - 1;
        switch ($generations) {
            case 1: return 'Child';
            case 2: return 'Grandchild';
            case 3: return 'Great-grandchild';
            default:
                $greats = $generations - 2;
                $prefix = str_repeat('Great-', $greats);

                return "{$prefix}grandchild";
        }
    }

    /**
     * Describe ancestor relationship
     */
    private function describeAncestorRelationship(array $path): string
    {
        $generations = count($path) - 1;
        switch ($generations) {
            case 1: return 'Parent';
            case 2: return 'Grandparent';
            case 3: return 'Great-grandparent';
            default:
                $greats = $generations - 2;
                $prefix = str_repeat('Great-', $greats);

                return "{$prefix}grandparent";
        }
    }

    /**
     * Get geographic distribution of places in tree
     */
    public function getGeographicDistribution(int $treeId): array
    {
        // Birth places
        $birthPlacesSql = "
            SELECT birth_place as place, COUNT(*) as count, 'birth' as event_type
            FROM genealogy_persons
            WHERE tree_id = ? AND birth_place IS NOT NULL AND birth_place != ''
            GROUP BY birth_place
            ORDER BY count DESC
        ";
        $birthPlaces = DB::select($birthPlacesSql, [$treeId]);

        // Death places
        $deathPlacesSql = "
            SELECT death_place as place, COUNT(*) as count, 'death' as event_type
            FROM genealogy_persons
            WHERE tree_id = ? AND death_place IS NOT NULL AND death_place != ''
            GROUP BY death_place
            ORDER BY count DESC
        ";
        $deathPlaces = DB::select($deathPlacesSql, [$treeId]);

        // Marriage places
        $marriagePlacesSql = "
            SELECT marriage_place as place, COUNT(*) as count, 'marriage' as event_type
            FROM genealogy_families
            WHERE tree_id = ? AND marriage_place IS NOT NULL AND marriage_place != ''
            GROUP BY marriage_place
            ORDER BY count DESC
        ";
        $marriagePlaces = DB::select($marriagePlacesSql, [$treeId]);

        // Aggregate all places
        $allPlaces = [];
        foreach (array_merge($birthPlaces, $deathPlaces, $marriagePlaces) as $place) {
            $key = $place->place;
            if (! isset($allPlaces[$key])) {
                $allPlaces[$key] = [
                    'place' => $key,
                    'total' => 0,
                    'births' => 0,
                    'deaths' => 0,
                    'marriages' => 0,
                ];
            }
            $allPlaces[$key]['total'] += $place->count;
            if ($place->event_type === 'birth') {
                $allPlaces[$key]['births'] = $place->count;
            } elseif ($place->event_type === 'death') {
                $allPlaces[$key]['deaths'] = $place->count;
            } elseif ($place->event_type === 'marriage') {
                $allPlaces[$key]['marriages'] = $place->count;
            }
        }

        // Sort by total
        usort($allPlaces, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        // Group by country/region (simple heuristic)
        $byRegion = [];
        foreach ($allPlaces as $place) {
            $parts = array_map('trim', explode(',', $place['place']));
            $region = count($parts) > 1 ? end($parts) : $parts[0];

            if (! isset($byRegion[$region])) {
                $byRegion[$region] = [
                    'region' => $region,
                    'total' => 0,
                    'places' => [],
                ];
            }
            $byRegion[$region]['total'] += $place['total'];
            $byRegion[$region]['places'][] = $place;
        }

        usort($byRegion, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return [
            'tree_id' => $treeId,
            'places' => array_values($allPlaces),
            'by_region' => array_values($byRegion),
            'summary' => [
                'total_birth_places' => count($birthPlaces),
                'total_death_places' => count($deathPlaces),
                'total_marriage_places' => count($marriagePlaces),
                'total_unique_places' => count($allPlaces),
            ],
        ];
    }

    // ========================================
    // Phase 6: Reports & Printing
    // ========================================

    /**
     * Generate a Family Group Sheet report for a family
     */
    public function getFamilyGroupSheet(int $familyId): array
    {
        // Get family details
        $family = DB::selectOne('
            SELECT f.*,
                   h.given_name as husband_given, h.surname as husband_surname,
                   h.birth_date as husband_birth_date, h.birth_place as husband_birth_place,
                   h.death_date as husband_death_date, h.death_place as husband_death_place,
                   h.occupation as husband_occupation,
                   w.given_name as wife_given, w.surname as wife_surname,
                   w.birth_date as wife_birth_date, w.birth_place as wife_birth_place,
                   w.death_date as wife_death_date, w.death_place as wife_death_place,
                   w.occupation as wife_occupation
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE f.id = ?
        ', [$familyId]);

        if (! $family) {
            return ['error' => 'Family not found'];
        }

        // Get children via genealogy_children junction table
        $children = DB::select('
            SELECT p.id, p.given_name, p.surname, p.sex, p.birth_date, p.birth_place,
                   p.death_date, p.death_place, p.occupation
            FROM genealogy_persons p
            INNER JOIN genealogy_children c ON p.id = c.person_id
            WHERE c.family_id = ?
            ORDER BY c.birth_order, p.birth_date, p.id
        ', [$familyId]);

        // Get husband's parents via genealogy_children junction table
        $husbandParents = null;
        if ($family->husband_id) {
            $husbandParents = DB::selectOne('
                SELECT f.id as family_id,
                       father.given_name as father_given, father.surname as father_surname,
                       mother.given_name as mother_given, mother.surname as mother_surname
                FROM genealogy_children c
                INNER JOIN genealogy_families f ON c.family_id = f.id
                LEFT JOIN genealogy_persons father ON f.husband_id = father.id
                LEFT JOIN genealogy_persons mother ON f.wife_id = mother.id
                WHERE c.person_id = ?
            ', [$family->husband_id]);
        }

        // Get wife's parents via genealogy_children junction table
        $wifeParents = null;
        if ($family->wife_id) {
            $wifeParents = DB::selectOne('
                SELECT f.id as family_id,
                       father.given_name as father_given, father.surname as father_surname,
                       mother.given_name as mother_given, mother.surname as mother_surname
                FROM genealogy_children c
                INNER JOIN genealogy_families f ON c.family_id = f.id
                LEFT JOIN genealogy_persons father ON f.husband_id = father.id
                LEFT JOIN genealogy_persons mother ON f.wife_id = mother.id
                WHERE c.person_id = ?
            ', [$family->wife_id]);
        }

        // Get marriage events from genealogy_family_events table
        $marriageEvents = DB::select('
            SELECT event_type, event_date, event_place, description
            FROM genealogy_family_events
            WHERE family_id = ?
            ORDER BY event_date
        ', [$familyId]);

        return [
            'family' => [
                'id' => $family->id,
                'marriage_date' => $family->marriage_date,
                'marriage_place' => $family->marriage_place,
                'events' => $marriageEvents,
            ],
            'husband' => $family->husband_id ? [
                'id' => $family->husband_id,
                'name' => trim($family->husband_given.' '.$family->husband_surname),
                'birth_date' => $family->husband_birth_date,
                'birth_place' => $family->husband_birth_place,
                'death_date' => $family->husband_death_date,
                'death_place' => $family->husband_death_place,
                'occupation' => $family->husband_occupation,
                'parents' => $husbandParents ? [
                    'father' => trim(($husbandParents->father_given ?? '').' '.($husbandParents->father_surname ?? '')),
                    'mother' => trim(($husbandParents->mother_given ?? '').' '.($husbandParents->mother_surname ?? '')),
                ] : null,
            ] : null,
            'wife' => $family->wife_id ? [
                'id' => $family->wife_id,
                'name' => trim($family->wife_given.' '.$family->wife_surname),
                'birth_date' => $family->wife_birth_date,
                'birth_place' => $family->wife_birth_place,
                'death_date' => $family->wife_death_date,
                'death_place' => $family->wife_death_place,
                'occupation' => $family->wife_occupation,
                'parents' => $wifeParents ? [
                    'father' => trim(($wifeParents->father_given ?? '').' '.($wifeParents->father_surname ?? '')),
                    'mother' => trim(($wifeParents->mother_given ?? '').' '.($wifeParents->mother_surname ?? '')),
                ] : null,
            ] : null,
            'children' => array_map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => trim($child->given_name.' '.$child->surname),
                    'sex' => $child->sex,
                    'birth_date' => $child->birth_date,
                    'birth_place' => $child->birth_place,
                    'death_date' => $child->death_date,
                    'death_place' => $child->death_place,
                    'occupation' => $child->occupation,
                ];
            }, $children),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate a Pedigree Chart (ancestors) for a person
     */
    public function getPedigreeChart(int $personId, int $generations = 4): array
    {
        $person = DB::selectOne('
            SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
            FROM genealogy_persons
            WHERE id = ?
        ', [$personId]);

        if (! $person) {
            return ['error' => 'Person not found'];
        }

        $pedigree = $this->buildPedigreeData($personId, $generations);

        return [
            'root_person' => [
                'id' => $person->id,
                'name' => trim($person->given_name.' '.$person->surname),
                'sex' => $person->sex,
                'birth_date' => $person->birth_date,
                'birth_place' => $person->birth_place,
                'death_date' => $person->death_date,
                'death_place' => $person->death_place,
            ],
            'generations' => $generations,
            'ancestors' => $pedigree,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recursively build pedigree data (ancestors)
     */
    private function buildPedigreeData(int $personId, int $maxGen, int $currentGen = 1): array
    {
        if ($currentGen > $maxGen) {
            return [];
        }

        // Get person's parents via genealogy_children junction table
        $childLink = DB::selectOne('
            SELECT c.family_id
            FROM genealogy_children c
            WHERE c.person_id = ?
        ', [$personId]);

        if (! $childLink || ! $childLink->family_id) {
            return [];
        }

        $family = DB::selectOne('
            SELECT husband_id, wife_id
            FROM genealogy_families
            WHERE id = ?
        ', [$childLink->family_id]);

        if (! $family) {
            return [];
        }

        $result = [];

        // Father
        if ($family->husband_id) {
            $father = DB::selectOne('
                SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
                FROM genealogy_persons
                WHERE id = ?
            ', [$family->husband_id]);

            if ($father) {
                $fatherData = [
                    'id' => $father->id,
                    'name' => trim($father->given_name.' '.$father->surname),
                    'sex' => 'M',
                    'birth_date' => $father->birth_date,
                    'birth_place' => $father->birth_place,
                    'death_date' => $father->death_date,
                    'death_place' => $father->death_place,
                    'generation' => $currentGen,
                    'position' => 'father',
                ];

                if ($currentGen < $maxGen) {
                    $fatherData['parents'] = $this->buildPedigreeData($father->id, $maxGen, $currentGen + 1);
                }

                $result['father'] = $fatherData;
            }
        }

        // Mother
        if ($family->wife_id) {
            $mother = DB::selectOne('
                SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
                FROM genealogy_persons
                WHERE id = ?
            ', [$family->wife_id]);

            if ($mother) {
                $motherData = [
                    'id' => $mother->id,
                    'name' => trim($mother->given_name.' '.$mother->surname),
                    'sex' => 'F',
                    'birth_date' => $mother->birth_date,
                    'birth_place' => $mother->birth_place,
                    'death_date' => $mother->death_date,
                    'death_place' => $mother->death_place,
                    'generation' => $currentGen,
                    'position' => 'mother',
                ];

                if ($currentGen < $maxGen) {
                    $motherData['parents'] = $this->buildPedigreeData($mother->id, $maxGen, $currentGen + 1);
                }

                $result['mother'] = $motherData;
            }
        }

        return $result;
    }

    /**
     * Generate a Descendant Report for a person
     */
    public function getDescendantReport(int $personId, int $maxGenerations = 10): array
    {
        $person = DB::selectOne('
            SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
            FROM genealogy_persons
            WHERE id = ?
        ', [$personId]);

        if (! $person) {
            return ['error' => 'Person not found'];
        }

        $descendants = $this->buildDescendantData($personId, $maxGenerations);
        $totalDescendants = $this->countTotalDescendants($descendants);

        return [
            'root_person' => [
                'id' => $person->id,
                'name' => trim($person->given_name.' '.$person->surname),
                'sex' => $person->sex,
                'birth_date' => $person->birth_date,
                'birth_place' => $person->birth_place,
                'death_date' => $person->death_date,
                'death_place' => $person->death_place,
            ],
            'max_generations' => $maxGenerations,
            'total_descendants' => $totalDescendants,
            'descendants' => $descendants,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recursively build descendant data
     */
    private function buildDescendantData(int $personId, int $maxGen, int $currentGen = 1, array &$visited = []): array
    {
        if ($currentGen > $maxGen || in_array($personId, $visited)) {
            return [];
        }

        $visited[] = $personId;

        // Get families where this person is a spouse
        $families = DB::select('
            SELECT f.id, f.marriage_date, f.marriage_place,
                   CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END as spouse_id,
                   s.given_name as spouse_given, s.surname as spouse_surname
            FROM genealogy_families f
            LEFT JOIN genealogy_persons s ON s.id = CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END
            WHERE f.husband_id = ? OR f.wife_id = ?
            ORDER BY f.marriage_date
        ', [$personId, $personId, $personId, $personId]);

        $result = [];

        foreach ($families as $family) {
            $familyData = [
                'family_id' => $family->id,
                'spouse' => $family->spouse_id ? [
                    'id' => $family->spouse_id,
                    'name' => trim($family->spouse_given.' '.$family->spouse_surname),
                ] : null,
                'marriage_date' => $family->marriage_date,
                'marriage_place' => $family->marriage_place,
                'children' => [],
            ];

            // Get children via genealogy_children junction table
            $children = DB::select('
                SELECT p.id, p.given_name, p.surname, p.sex, p.birth_date, p.birth_place, p.death_date, p.death_place
                FROM genealogy_persons p
                INNER JOIN genealogy_children c ON p.id = c.person_id
                WHERE c.family_id = ?
                ORDER BY c.birth_order, p.birth_date, p.id
            ', [$family->id]);

            foreach ($children as $child) {
                $childData = [
                    'id' => $child->id,
                    'name' => trim($child->given_name.' '.$child->surname),
                    'sex' => $child->sex,
                    'birth_date' => $child->birth_date,
                    'birth_place' => $child->birth_place,
                    'death_date' => $child->death_date,
                    'death_place' => $child->death_place,
                    'generation' => $currentGen,
                ];

                if ($currentGen < $maxGen) {
                    $childDescendants = $this->buildDescendantData($child->id, $maxGen, $currentGen + 1, $visited);
                    if (! empty($childDescendants)) {
                        $childData['families'] = $childDescendants;
                    }
                }

                $familyData['children'][] = $childData;
            }

            if (! empty($familyData['children']) || $familyData['spouse']) {
                $result[] = $familyData;
            }
        }

        return $result;
    }

    /**
     * Count total descendants from descendant tree
     */
    private function countTotalDescendants(array $families): int
    {
        $count = 0;
        foreach ($families as $family) {
            foreach ($family['children'] ?? [] as $child) {
                $count++;
                if (isset($child['families'])) {
                    $count += $this->countTotalDescendants($child['families']);
                }
            }
        }

        return $count;
    }

    /**
     * Generate an Ahnentafel Report (ancestor numbering system)
     *
     * The Ahnentafel (German for "ancestor table") uses a standard numbering system:
     * - Subject is #1
     * - Father is 2n (where n is the person's number)
     * - Mother is 2n+1
     *
     * For example:
     * 1. Subject
     * 2. Father
     * 3. Mother
     * 4. Paternal Grandfather
     * 5. Paternal Grandmother
     * 6. Maternal Grandfather
     * 7. Maternal Grandmother
     * etc.
     *
     * @param  int  $personId  The starting person
     * @param  int  $maxGenerations  Maximum number of generations (default 10)
     * @return array Ahnentafel report data
     */
    public function getAhnentafelReport(int $personId, int $maxGenerations = 10): array
    {
        $person = DB::selectOne('
            SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
            FROM genealogy_persons
            WHERE id = ?
        ', [$personId]);

        if (! $person) {
            return ['error' => 'Person not found'];
        }

        // Build the ahnentafel list
        $ancestors = [];
        $this->buildAhnentafelList($personId, 1, $maxGenerations, $ancestors);

        // Sort by ahnentafel number
        ksort($ancestors);

        // Calculate statistics
        $totalPossible = (int) pow(2, $maxGenerations + 1) - 1;
        $totalFound = count($ancestors);
        $completenessPercent = $totalPossible > 0 ? round(($totalFound / $totalPossible) * 100, 1) : 0;

        // Group by generation for display
        $byGeneration = [];
        foreach ($ancestors as $ahnNum => $ancestor) {
            $gen = (int) floor(log($ahnNum, 2));
            if (! isset($byGeneration[$gen])) {
                $byGeneration[$gen] = [
                    'generation' => $gen,
                    'label' => $gen === 0 ? 'Subject' : 'Generation '.$gen,
                    'ancestors' => [],
                ];
            }
            $byGeneration[$gen]['ancestors'][] = $ancestor;
        }

        return [
            'root_person' => [
                'id' => $person->id,
                'name' => trim($person->given_name.' '.$person->surname),
                'sex' => $person->sex,
                'birth_date' => $person->birth_date,
                'birth_place' => $person->birth_place,
                'death_date' => $person->death_date,
                'death_place' => $person->death_place,
            ],
            'max_generations' => $maxGenerations,
            'statistics' => [
                'total_found' => $totalFound,
                'total_possible' => $totalPossible,
                'completeness_percent' => $completenessPercent,
            ],
            'ancestors' => $ancestors,
            'by_generation' => array_values($byGeneration),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recursively build the Ahnentafel list
     *
     * @param  int  $personId  Current person ID
     * @param  int  $ahnNumber  Current Ahnentafel number
     * @param  int  $maxGenerations  Maximum generations to trace
     * @param  array  &$ancestors  Reference to ancestors array
     */
    private function buildAhnentafelList(int $personId, int $ahnNumber, int $maxGenerations, array &$ancestors): void
    {
        // Check if we've exceeded max generations
        $currentGeneration = (int) floor(log($ahnNumber, 2));
        if ($currentGeneration > $maxGenerations) {
            return;
        }

        // Get person data
        $person = DB::selectOne('
            SELECT id, given_name, surname, sex, birth_date, birth_place, death_date, death_place, occupation
            FROM genealogy_persons
            WHERE id = ?
        ', [$personId]);

        if (! $person) {
            return;
        }

        // Add to ancestors list with Ahnentafel number
        $ancestors[$ahnNumber] = [
            'ahnentafel_number' => $ahnNumber,
            'generation' => $currentGeneration,
            'position' => $this->getAhnentafelPosition($ahnNumber),
            'id' => $person->id,
            'name' => trim($person->given_name.' '.$person->surname),
            'given_name' => $person->given_name,
            'surname' => $person->surname,
            'sex' => $person->sex,
            'birth_date' => $person->birth_date,
            'birth_place' => $person->birth_place,
            'death_date' => $person->death_date,
            'death_place' => $person->death_place,
            'occupation' => $person->occupation,
        ];

        // Get parents via genealogy_children junction table
        $childLink = DB::selectOne('
            SELECT c.family_id
            FROM genealogy_children c
            WHERE c.person_id = ?
        ', [$personId]);

        if (! $childLink || ! $childLink->family_id) {
            return;
        }

        $family = DB::selectOne('
            SELECT husband_id, wife_id
            FROM genealogy_families
            WHERE id = ?
        ', [$childLink->family_id]);

        if (! $family) {
            return;
        }

        // Father is 2n, Mother is 2n+1
        if ($family->husband_id) {
            $this->buildAhnentafelList($family->husband_id, $ahnNumber * 2, $maxGenerations, $ancestors);
        }
        if ($family->wife_id) {
            $this->buildAhnentafelList($family->wife_id, $ahnNumber * 2 + 1, $maxGenerations, $ancestors);
        }
    }

    /**
     * Get the position label for an Ahnentafel number
     *
     * @param  int  $ahnNumber  The Ahnentafel number
     * @return string Position label (e.g., "Paternal Grandfather", "Maternal Great-Grandmother")
     */
    private function getAhnentafelPosition(int $ahnNumber): string
    {
        if ($ahnNumber === 1) {
            return 'Subject';
        }
        if ($ahnNumber === 2) {
            return 'Father';
        }
        if ($ahnNumber === 3) {
            return 'Mother';
        }

        // Determine the generation
        $generation = (int) floor(log($ahnNumber, 2));

        // Build the path from subject to this ancestor
        $path = [];
        $n = $ahnNumber;
        while ($n > 1) {
            $path[] = ($n % 2 === 0) ? 'P' : 'M'; // P = paternal, M = maternal
            $n = (int) ($n / 2);
        }
        $path = array_reverse($path);

        // First in path determines paternal/maternal line
        $lineage = ($path[0] === 'P') ? 'Paternal' : 'Maternal';

        // Last in path determines sex
        $sex = (end($path) === 'P') ? 'Grandfather' : 'Grandmother';

        // Add "Great-" prefixes based on generation
        $greats = $generation - 2;
        if ($greats > 0) {
            $greatPrefix = str_repeat('Great-', $greats);

            return "{$lineage} {$greatPrefix}{$sex}";
        }

        return "{$lineage} {$sex}";
    }

    /**
     * Get an Individual Summary Report for a person
     */
    public function getIndividualSummary(int $personId): array
    {
        $person = DB::selectOne('
            SELECT p.*, t.name as tree_name
            FROM genealogy_persons p
            LEFT JOIN genealogy_trees t ON p.tree_id = t.id
            WHERE p.id = ?
        ', [$personId]);

        if (! $person) {
            return ['error' => 'Person not found'];
        }

        // Get all events for this person
        $events = DB::select('
            SELECT event_type, event_date, event_place, description
            FROM genealogy_events
            WHERE person_id = ?
            ORDER BY event_date
        ', [$personId]);

        // Get parents via genealogy_children junction table
        $parents = null;
        $childLink = DB::selectOne('
            SELECT family_id FROM genealogy_children WHERE person_id = ?
        ', [$personId]);
        if ($childLink) {
            $parents = DB::selectOne('
                SELECT f.id as family_id,
                       h.id as father_id, h.given_name as father_given, h.surname as father_surname,
                       w.id as mother_id, w.given_name as mother_given, w.surname as mother_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON f.husband_id = h.id
                LEFT JOIN genealogy_persons w ON f.wife_id = w.id
                WHERE f.id = ?
            ', [$childLink->family_id]);
        }

        // Get spouses and marriages
        $families = DB::select('
            SELECT f.id, f.marriage_date, f.marriage_place,
                   CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END as spouse_id,
                   s.given_name as spouse_given, s.surname as spouse_surname
            FROM genealogy_families f
            LEFT JOIN genealogy_persons s ON s.id = CASE WHEN f.husband_id = ? THEN f.wife_id ELSE f.husband_id END
            WHERE f.husband_id = ? OR f.wife_id = ?
            ORDER BY f.marriage_date
        ', [$personId, $personId, $personId, $personId]);

        // Get children via genealogy_children junction table
        $children = [];
        foreach ($families as $family) {
            $familyChildren = DB::select('
                SELECT p.id, p.given_name, p.surname, p.birth_date
                FROM genealogy_persons p
                INNER JOIN genealogy_children c ON p.id = c.person_id
                WHERE c.family_id = ?
                ORDER BY c.birth_order, p.birth_date, p.id
            ', [$family->id]);

            foreach ($familyChildren as $child) {
                $children[] = [
                    'id' => $child->id,
                    'name' => trim($child->given_name.' '.$child->surname),
                    'birth_date' => $child->birth_date,
                    'other_parent' => $family->spouse_id ? trim($family->spouse_given.' '.$family->spouse_surname) : null,
                ];
            }
        }

        // Get siblings via genealogy_children junction table
        $siblings = [];
        if ($childLink) {
            $siblings = DB::select('
                SELECT p.id, p.given_name, p.surname, p.birth_date
                FROM genealogy_persons p
                INNER JOIN genealogy_children c ON p.id = c.person_id
                WHERE c.family_id = ? AND p.id != ?
                ORDER BY c.birth_order, p.birth_date, p.id
            ', [$childLink->family_id, $personId]);
        }

        // Get media via genealogy_person_media junction table
        $media = DB::select('
            SELECT m.id, m.local_filename, m.title, m.media_type, m.nextcloud_path, pm.is_primary
            FROM genealogy_media m
            JOIN genealogy_person_media pm ON m.id = pm.media_id
            WHERE pm.person_id = ?
            ORDER BY pm.is_primary DESC, m.created_at
            LIMIT 10
        ', [$personId]);

        // Get sources via genealogy_citations junction table
        $sources = DB::select('
            SELECT s.id, s.title, c.page, c.text
            FROM genealogy_sources s
            JOIN genealogy_citations c ON s.id = c.source_id
            WHERE c.person_id = ?
        ', [$personId]);

        return [
            'person' => [
                'id' => $person->id,
                'name' => trim($person->given_name.' '.$person->surname),
                'given_name' => $person->given_name,
                'surname' => $person->surname,
                'sex' => $person->sex,
                'birth_date' => $person->birth_date,
                'birth_place' => $person->birth_place,
                'death_date' => $person->death_date,
                'death_place' => $person->death_place,
                'burial_place' => $person->burial_place,
                'occupation' => $person->occupation,
                'notes' => $person->notes,
                'living' => (bool) $person->living,
                'tree_name' => $person->tree_name,
            ],
            'events' => array_map(fn ($e) => [
                'type' => $e->event_type,
                'date' => $e->event_date,
                'place' => $e->event_place,
                'description' => $e->description,
            ], $events),
            'parents' => $parents ? [
                'father' => $parents->father_id ? [
                    'id' => $parents->father_id,
                    'name' => trim($parents->father_given.' '.$parents->father_surname),
                ] : null,
                'mother' => $parents->mother_id ? [
                    'id' => $parents->mother_id,
                    'name' => trim($parents->mother_given.' '.$parents->mother_surname),
                ] : null,
            ] : null,
            'spouses' => array_map(fn ($f) => [
                'id' => $f->spouse_id,
                'name' => $f->spouse_id ? trim($f->spouse_given.' '.$f->spouse_surname) : null,
                'marriage_date' => $f->marriage_date,
                'marriage_place' => $f->marriage_place,
                'family_id' => $f->id,
            ], $families),
            'children' => $children,
            'siblings' => array_map(fn ($s) => [
                'id' => $s->id,
                'name' => trim($s->given_name.' '.$s->surname),
                'birth_date' => $s->birth_date,
            ], $siblings),
            'media' => array_map(fn ($m) => [
                'id' => $m->id,
                'file_name' => $m->local_filename,
                'title' => $m->title,
                'category' => $m->media_type,
                'nextcloud_path' => $m->nextcloud_path,
                'is_primary' => (bool) $m->is_primary,
            ], $media),
            'sources' => array_map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'page' => $s->page,
                'text' => $s->text,
            ], $sources),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ========================================
    // Phase 7: Privacy & Collaboration Methods
    // ========================================

    /**
     * Get tree privacy settings
     */
    public function getTreePrivacySettings(int $treeId): ?array
    {
        $tree = DB::selectOne('
            SELECT id, name, owner_id, privacy, living_privacy,
                   living_years_threshold, default_media_privacy, allow_public_search
            FROM genealogy_trees
            WHERE id = ?
        ', [$treeId]);

        if (! $tree) {
            return null;
        }

        return [
            'id' => $tree->id,
            'name' => $tree->name,
            'owner_id' => $tree->owner_id,
            'privacy' => $tree->privacy ?? 'private',
            'living_privacy' => $tree->living_privacy ?? 'hide_details',
            'living_years_threshold' => $tree->living_years_threshold ?? 100,
            'default_media_privacy' => $tree->default_media_privacy ?? 'shared',
            'allow_public_search' => (bool) ($tree->allow_public_search ?? false),
        ];
    }

    /**
     * Update tree privacy settings
     */
    public function updateTreePrivacySettings(int $treeId, array $settings): bool
    {
        $updates = [];
        $params = [];

        if (isset($settings['privacy'])) {
            $updates[] = 'privacy = ?';
            $params[] = $settings['privacy'];
        }
        if (isset($settings['living_privacy'])) {
            $updates[] = 'living_privacy = ?';
            $params[] = $settings['living_privacy'];
        }
        if (isset($settings['living_years_threshold'])) {
            $updates[] = 'living_years_threshold = ?';
            $params[] = (int) $settings['living_years_threshold'];
        }
        if (isset($settings['default_media_privacy'])) {
            $updates[] = 'default_media_privacy = ?';
            $params[] = $settings['default_media_privacy'];
        }
        if (isset($settings['allow_public_search'])) {
            $updates[] = 'allow_public_search = ?';
            $params[] = $settings['allow_public_search'] ? 1 : 0;
        }
        if (isset($settings['owner_id'])) {
            $updates[] = 'owner_id = ?';
            $params[] = $settings['owner_id'];
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $treeId;
        $sql = 'UPDATE genealogy_trees SET '.implode(', ', $updates).' WHERE id = ?';

        DB::update($sql, $params);

        // Log the activity
        $this->logActivity($treeId, null, 'update_privacy_settings', 'tree', $treeId, null, $settings);

        return true;
    }

    /**
     * Check if a person is considered living (for privacy filtering)
     */
    public function isPersonLiving(int $personId): bool
    {
        $person = DB::selectOne('
            SELECT p.living, p.birth_date, p.death_date, t.living_years_threshold
            FROM genealogy_persons p
            LEFT JOIN genealogy_trees t ON p.tree_id = t.id
            WHERE p.id = ?
        ', [$personId]);

        if (! $person) {
            return false;
        }

        // If living status is explicitly set, use it
        if ($person->living !== null) {
            return (bool) $person->living;
        }

        // If death date exists, person is deceased
        if ($person->death_date && $person->death_date !== '') {
            return false;
        }

        // If birth date exists, check against threshold
        if ($person->birth_date) {
            $threshold = $person->living_years_threshold ?? 100;
            $birthYear = $this->extractYear($person->birth_date);
            if ($birthYear && (date('Y') - $birthYear > $threshold)) {
                return false; // Assumed deceased
            }
        }

        // Default to living if no death date and within threshold
        return true;
    }

    /**
     * Auto-detect and update living status for all persons in a tree
     */
    public function autoDetectLivingPersons(int $treeId): array
    {
        $tree = DB::selectOne('
            SELECT living_years_threshold FROM genealogy_trees WHERE id = ?
        ', [$treeId]);

        $threshold = $tree->living_years_threshold ?? 100;
        $thresholdYear = date('Y') - $threshold;

        // Mark as living: no death date and born within threshold
        $markedLiving = DB::update("
            UPDATE genealogy_persons
            SET living = 1
            WHERE tree_id = ?
              AND living IS NULL
              AND (death_date IS NULL OR death_date = '')
              AND (
                  birth_date IS NULL OR birth_date = ''
                  OR CAST(SUBSTRING(birth_date, -4) AS UNSIGNED) >= ?
              )
        ", [$treeId, $thresholdYear]);

        // Mark as deceased: has death date OR born before threshold
        $markedDeceased = DB::update("
            UPDATE genealogy_persons
            SET living = 0
            WHERE tree_id = ?
              AND living IS NULL
              AND (
                  (death_date IS NOT NULL AND death_date != '')
                  OR (
                      birth_date IS NOT NULL AND birth_date != ''
                      AND CAST(SUBSTRING(birth_date, -4) AS UNSIGNED) < ?
                  )
              )
        ", [$treeId, $thresholdYear]);

        $this->logActivity($treeId, null, 'auto_detect_living', 'tree', $treeId, null, [
            'marked_living' => $markedLiving,
            'marked_deceased' => $markedDeceased,
        ]);

        return [
            'marked_living' => $markedLiving,
            'marked_deceased' => $markedDeceased,
        ];
    }

    /**
     * Update privacy override for a person
     */
    public function updatePersonPrivacy(int $personId, string $privacyOverride): bool
    {
        if (! in_array($privacyOverride, ['default', 'public', 'private'])) {
            return false;
        }

        $person = DB::selectOne('SELECT tree_id FROM genealogy_persons WHERE id = ?', [$personId]);
        if (! $person) {
            return false;
        }

        DB::update('
            UPDATE genealogy_persons
            SET privacy_override = ?
            WHERE id = ?
        ', [$privacyOverride, $personId]);

        $this->logActivity($person->tree_id, null, 'update_person_privacy', 'person', $personId, null, [
            'privacy_override' => $privacyOverride,
        ]);

        return true;
    }

    /**
     * Update media privacy settings
     */
    public function updateMediaPrivacy(int $mediaId, ?string $privacy, bool $isSensitive = false): bool
    {
        $media = DB::selectOne('SELECT tree_id FROM genealogy_media WHERE id = ?', [$mediaId]);
        if (! $media) {
            return false;
        }

        if ($privacy !== null && ! in_array($privacy, ['private', 'shared', 'public'])) {
            return false;
        }

        DB::update('
            UPDATE genealogy_media
            SET privacy = ?, is_sensitive = ?
            WHERE id = ?
        ', [$privacy, $isSensitive ? 1 : 0, $mediaId]);

        $this->logActivity($media->tree_id, null, 'update_media_privacy', 'media', $mediaId, null, [
            'privacy' => $privacy,
            'is_sensitive' => $isSensitive,
        ]);

        return true;
    }

    /**
     * Get tree collaborators
     */
    public function getTreeCollaborators(int $treeId): array
    {
        $collaborators = DB::select('
            SELECT c.*, u.name as user_name, u.email as user_email,
                   i.name as invited_by_name
            FROM genealogy_tree_collaborators c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN users i ON c.invited_by = i.id
            WHERE c.tree_id = ?
            ORDER BY c.role, c.created_at
        ', [$treeId]);

        return array_map(fn ($c) => [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'user_name' => $c->user_name,
            'user_email' => $c->user_email,
            'role' => $c->role,
            'can_export' => (bool) $c->can_export,
            'can_delete' => (bool) $c->can_delete,
            'can_manage_media' => (bool) $c->can_manage_media,
            'invited_by' => $c->invited_by,
            'invited_by_name' => $c->invited_by_name,
            'invited_at' => $c->invited_at,
            'accepted_at' => $c->accepted_at,
        ], $collaborators);
    }

    /**
     * Add a collaborator to a tree
     */
    public function addCollaborator(int $treeId, int $userId, string $role, ?int $invitedBy = null, array $permissions = []): bool
    {
        if (! in_array($role, ['viewer', 'contributor', 'editor', 'admin'])) {
            return false;
        }

        // Check if already a collaborator
        $existing = DB::selectOne('
            SELECT id FROM genealogy_tree_collaborators WHERE tree_id = ? AND user_id = ?
        ', [$treeId, $userId]);

        if ($existing) {
            return false; // Already exists
        }

        DB::insert('
            INSERT INTO genealogy_tree_collaborators
            (tree_id, user_id, role, can_export, can_delete, can_manage_media, invited_by, accepted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ', [
            $treeId,
            $userId,
            $role,
            $permissions['can_export'] ?? ($role === 'admin' || $role === 'editor'),
            $permissions['can_delete'] ?? ($role === 'admin'),
            $permissions['can_manage_media'] ?? true,
            $invitedBy,
        ]);

        $this->logActivity($treeId, $invitedBy, 'add_collaborator', 'collaborator', $userId, null, [
            'role' => $role,
        ]);

        return true;
    }

    /**
     * Update collaborator role/permissions
     */
    public function updateCollaborator(int $collaboratorId, array $updates): bool
    {
        $collab = DB::selectOne('
            SELECT tree_id, user_id FROM genealogy_tree_collaborators WHERE id = ?
        ', [$collaboratorId]);

        if (! $collab) {
            return false;
        }

        $sets = [];
        $params = [];

        if (isset($updates['role']) && in_array($updates['role'], ['viewer', 'contributor', 'editor', 'admin'])) {
            $sets[] = 'role = ?';
            $params[] = $updates['role'];
        }
        if (isset($updates['can_export'])) {
            $sets[] = 'can_export = ?';
            $params[] = $updates['can_export'] ? 1 : 0;
        }
        if (isset($updates['can_delete'])) {
            $sets[] = 'can_delete = ?';
            $params[] = $updates['can_delete'] ? 1 : 0;
        }
        if (isset($updates['can_manage_media'])) {
            $sets[] = 'can_manage_media = ?';
            $params[] = $updates['can_manage_media'] ? 1 : 0;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $collaboratorId;
        DB::update('UPDATE genealogy_tree_collaborators SET '.implode(', ', $sets).' WHERE id = ?', $params);

        $this->logActivity($collab->tree_id, null, 'update_collaborator', 'collaborator', $collab->user_id, null, $updates);

        return true;
    }

    /**
     * Remove a collaborator from a tree
     */
    public function removeCollaborator(int $collaboratorId): bool
    {
        $collab = DB::selectOne('
            SELECT tree_id, user_id FROM genealogy_tree_collaborators WHERE id = ?
        ', [$collaboratorId]);

        if (! $collab) {
            return false;
        }

        DB::delete('DELETE FROM genealogy_tree_collaborators WHERE id = ?', [$collaboratorId]);

        $this->logActivity($collab->tree_id, null, 'remove_collaborator', 'collaborator', $collab->user_id);

        return true;
    }

    /**
     * Create an invitation to collaborate on a tree
     */
    public function createInvitation(int $treeId, string $email, string $role, int $invitedBy): ?array
    {
        if (! in_array($role, ['viewer', 'contributor', 'editor', 'admin'])) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        DB::insert('
            INSERT INTO genealogy_tree_invitations (tree_id, email, role, token, invited_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [$treeId, $email, $role, $token, $invitedBy, $expiresAt]);

        $id = DB::getPdo()->lastInsertId();

        $this->logActivity($treeId, $invitedBy, 'create_invitation', 'invitation', $id, null, [
            'email' => $email,
            'role' => $role,
        ]);

        return [
            'id' => $id,
            'token' => $token,
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get pending invitations for a tree
     */
    public function getPendingInvitations(int $treeId): array
    {
        $invitations = DB::select('
            SELECT i.*, u.name as invited_by_name
            FROM genealogy_tree_invitations i
            LEFT JOIN users u ON i.invited_by = u.id
            WHERE i.tree_id = ? AND i.expires_at > NOW()
            ORDER BY i.created_at DESC
        ', [$treeId]);

        return array_map(fn ($i) => [
            'id' => $i->id,
            'email' => $i->email,
            'role' => $i->role,
            'invited_by' => $i->invited_by,
            'invited_by_name' => $i->invited_by_name,
            'expires_at' => $i->expires_at,
            'created_at' => $i->created_at,
        ], $invitations);
    }

    /**
     * Accept an invitation
     */
    public function acceptInvitation(string $token, int $userId): ?array
    {
        $invitation = DB::selectOne('
            SELECT * FROM genealogy_tree_invitations
            WHERE token = ? AND expires_at > NOW()
        ', [$token]);

        if (! $invitation) {
            return null;
        }

        // Add as collaborator
        $this->addCollaborator($invitation->tree_id, $userId, $invitation->role, $invitation->invited_by);

        // Delete invitation
        DB::delete('DELETE FROM genealogy_tree_invitations WHERE id = ?', [$invitation->id]);

        return [
            'tree_id' => $invitation->tree_id,
            'role' => $invitation->role,
        ];
    }

    /**
     * Cancel/delete an invitation
     */
    public function cancelInvitation(int $invitationId): bool
    {
        $invitation = DB::selectOne('
            SELECT tree_id FROM genealogy_tree_invitations WHERE id = ?
        ', [$invitationId]);

        if (! $invitation) {
            return false;
        }

        DB::delete('DELETE FROM genealogy_tree_invitations WHERE id = ?', [$invitationId]);

        $this->logActivity($invitation->tree_id, null, 'cancel_invitation', 'invitation', $invitationId);

        return true;
    }

    /**
     * Check user's role/permissions on a tree
     */
    public function getUserTreePermissions(int $treeId, int $userId): ?array
    {
        // Check if owner
        $tree = DB::selectOne('
            SELECT owner_id FROM genealogy_trees WHERE id = ?
        ', [$treeId]);

        if ($tree && $tree->owner_id === $userId) {
            return [
                'role' => 'owner',
                'can_view' => true,
                'can_edit' => true,
                'can_export' => true,
                'can_delete' => true,
                'can_manage_media' => true,
                'can_manage_collaborators' => true,
            ];
        }

        // Check collaborator role
        $collab = DB::selectOne('
            SELECT * FROM genealogy_tree_collaborators
            WHERE tree_id = ? AND user_id = ?
        ', [$treeId, $userId]);

        if (! $collab) {
            // Check if tree is public
            if ($tree && $tree->privacy === 'public') {
                return [
                    'role' => 'public',
                    'can_view' => true,
                    'can_edit' => false,
                    'can_export' => false,
                    'can_delete' => false,
                    'can_manage_media' => false,
                    'can_manage_collaborators' => false,
                ];
            }

            return null; // No access
        }

        return [
            'role' => $collab->role,
            'can_view' => true,
            'can_edit' => in_array($collab->role, ['contributor', 'editor', 'admin']),
            'can_export' => (bool) $collab->can_export,
            'can_delete' => (bool) $collab->can_delete,
            'can_manage_media' => (bool) $collab->can_manage_media,
            'can_manage_collaborators' => $collab->role === 'admin',
        ];
    }

    /**
     * Log activity for audit trail
     */
    public function logActivity(
        int $treeId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $ipAddress = request()->ip() ?? null;
        $userAgent = substr(request()->userAgent() ?? '', 0, 500);

        DB::insert('
            INSERT INTO genealogy_activity_log
            (tree_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            $treeId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ipAddress,
            $userAgent,
        ]);
    }

    /**
     * Get activity log for a tree
     */
    public function getActivityLog(int $treeId, int $limit = 50, int $offset = 0, ?int $personId = null): array
    {
        $sql = 'SELECT a.*, u.name as user_name
            FROM genealogy_activity_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tree_id = ?';
        $params = [$treeId];

        if ($personId !== null) {
            $sql .= " AND a.entity_type = 'person' AND a.entity_id = ?";
            $params[] = $personId;
        }

        $sql .= ' ORDER BY a.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $activities = DB::select($sql, $params);

        return array_map(fn ($a) => [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'user_name' => $a->user_name,
            'action' => $a->action,
            'entity_type' => $a->entity_type,
            'entity_id' => $a->entity_id,
            'old_values' => $a->old_values ? json_decode($a->old_values, true) : null,
            'new_values' => $a->new_values ? json_decode($a->new_values, true) : null,
            'ip_address' => $a->ip_address,
            'created_at' => $a->created_at,
        ], $activities);
    }

    /**
     * Apply privacy filtering to person data based on tree settings
     */
    public function applyPrivacyFilter(array $personData, int $treeId, ?int $viewingUserId = null): array
    {
        $tree = $this->getTreePrivacySettings($treeId);
        if (! $tree) {
            return $personData;
        }

        // Check if viewer has full access
        if ($viewingUserId) {
            $permissions = $this->getUserTreePermissions($treeId, $viewingUserId);
            if ($permissions && in_array($permissions['role'], ['owner', 'admin', 'editor'])) {
                return $personData; // Full access
            }
        }

        // Check if person is living and needs privacy protection
        $isLiving = $personData['living'] ?? false;
        $privacyOverride = $personData['privacy_override'] ?? 'default';

        // Handle privacy override
        if ($privacyOverride === 'public') {
            return $personData;
        }
        if ($privacyOverride === 'private') {
            return $this->redactPersonData($personData, 'hide_all');
        }

        // Apply tree's living privacy settings
        if ($isLiving) {
            return $this->redactPersonData($personData, $tree['living_privacy']);
        }

        return $personData;
    }

    /**
     * Redact person data based on privacy level
     */
    private function redactPersonData(array $data, string $level): array
    {
        if ($level === 'show_all') {
            return $data;
        }

        if ($level === 'hide_all') {
            return [
                'id' => $data['id'] ?? null,
                'tree_id' => $data['tree_id'] ?? null,
                'given_name' => 'Living',
                'surname' => $data['surname'] ?? null,
                'sex' => $data['sex'] ?? null,
                'living' => true,
                'privacy_restricted' => true,
            ];
        }

        // hide_details - show name but hide dates/places
        $filtered = $data;
        $filtered['birth_date'] = null;
        $filtered['birth_place'] = null;
        $filtered['death_date'] = null;
        $filtered['death_place'] = null;
        $filtered['burial_date'] = null;
        $filtered['burial_place'] = null;
        $filtered['ssn'] = null;
        $filtered['notes'] = null;
        $filtered['privacy_restricted'] = true;

        return $filtered;
    }

    /**
     * Get statistics about living/deceased persons in a tree
     */
    public function getLivingStatistics(int $treeId): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END) as living_explicit,
                SUM(CASE WHEN living = 0 THEN 1 ELSE 0 END) as deceased_explicit,
                SUM(CASE WHEN living IS NULL THEN 1 ELSE 0 END) as unknown,
                SUM(CASE WHEN death_date IS NOT NULL AND death_date != '' THEN 1 ELSE 0 END) as has_death_date,
                SUM(CASE WHEN privacy_override = 'public' THEN 1 ELSE 0 END) as privacy_public,
                SUM(CASE WHEN privacy_override = 'private' THEN 1 ELSE 0 END) as privacy_private
            FROM genealogy_persons
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'total' => (int) $stats->total,
            'living_explicit' => (int) $stats->living_explicit,
            'deceased_explicit' => (int) $stats->deceased_explicit,
            'unknown_status' => (int) $stats->unknown,
            'has_death_date' => (int) $stats->has_death_date,
            'privacy_public' => (int) $stats->privacy_public,
            'privacy_private' => (int) $stats->privacy_private,
        ];
    }

    // ========================================================================
    // Phase 8: AI-Assisted Research
    // ========================================================================

    /**
     * Get research hints for a tree or person
     */
    public function getResearchHints(int $treeId, ?int $personId = null, string $status = 'pending', int $limit = 50): array
    {
        $sql = '
            SELECT h.*, p.given_name, p.surname
            FROM genealogy_research_hints h
            LEFT JOIN genealogy_persons p ON h.person_id = p.id
            WHERE h.tree_id = ?
        ';
        $params = [$treeId];

        if ($personId) {
            $sql .= ' AND h.person_id = ?';
            $params[] = $personId;
        }

        if ($status !== 'all') {
            $sql .= ' AND h.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY h.confidence DESC, h.created_at DESC LIMIT ?';
        $params[] = $limit;

        $hints = DB::select($sql, $params);

        return array_map(function ($hint) {
            $hint->source_info = $hint->source_info ? json_decode($hint->source_info, true) : null;
            $hint->matching_criteria = ! empty($hint->matching_criteria) ? json_decode($hint->matching_criteria, true) : null;
            $hint->person_name = trim(($hint->given_name ?? '').' '.($hint->surname ?? ''));

            return $hint;
        }, $hints);
    }

    /**
     * Create a research hint
     */
    public function createResearchHint(array $data): ?int
    {
        DB::insert("
            INSERT INTO genealogy_research_hints
            (tree_id, person_id, hint_type, title, description, confidence, source_info, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ", [
            $data['tree_id'],
            $data['person_id'] ?? null,
            $data['hint_type'],
            $data['title'],
            $data['description'] ?? null,
            $data['confidence'] ?? 0.50,
            isset($data['source_info']) ? json_encode($data['source_info']) : null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update research hint status
     */
    public function updateResearchHintStatus(int $hintId, string $status, ?int $userId = null): bool
    {
        return DB::update('
            UPDATE genealogy_research_hints
            SET status = ?, reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
            WHERE id = ?
        ', [$status, $userId, $hintId]) > 0;
    }

    /**
     * Get name variations for a tree
     */
    public function getNameVariations(int $treeId, ?string $originalName = null, ?string $nameType = null): array
    {
        $sql = 'SELECT * FROM genealogy_name_variations WHERE tree_id = ?';
        $params = [$treeId];

        if ($originalName) {
            $sql .= ' AND original_name = ?';
            $params[] = $originalName;
        }

        if ($nameType) {
            $sql .= ' AND name_type = ?';
            $params[] = $nameType;
        }

        $sql .= ' ORDER BY original_name, variation';

        return DB::select($sql, $params);
    }

    /**
     * Add a name variation
     */
    public function addNameVariation(array $data): ?int
    {
        // Check for duplicate
        $exists = DB::selectOne('
            SELECT id FROM genealogy_name_variations
            WHERE tree_id = ? AND original_name = ? AND name_type = ? AND variation = ?
        ', [$data['tree_id'], $data['original_name'], $data['name_type'], $data['variation']]);

        if ($exists) {
            return null;
        }

        DB::insert('
            INSERT INTO genealogy_name_variations
            (tree_id, original_name, name_type, variation, language_origin, notes, is_ai_generated, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ', [
            $data['tree_id'],
            $data['original_name'],
            $data['name_type'],
            $data['variation'],
            $data['language_origin'] ?? null,
            $data['notes'] ?? null,
            $data['is_ai_generated'] ?? false,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Delete a name variation
     */
    public function deleteNameVariation(int $variationId): bool
    {
        return DB::delete('DELETE FROM genealogy_name_variations WHERE id = ?', [$variationId]) > 0;
    }

    /**
     * Generate AI name suggestions using common patterns
     */
    public function generateNameVariations(int $treeId, string $name, string $nameType): array
    {
        $variations = [];

        // Common surname variations
        $surnamePatterns = [
            // German/Yiddish
            'berg' => ['burg', 'berger'],
            'stein' => ['stone', 'stine'],
            'mann' => ['man'],
            'baum' => ['boom'],
            // Slavic
            'ski' => ['sky', 'skiy'],
            'wicz' => ['vich', 'vitz', 'witch'],
            'ov' => ['off', 'ow'],
            // Irish/Scottish
            'mc' => ['mac', "m'"],
            "o'" => ['o', 'oh'],
            // General Anglicization
            'schmidt' => ['smith', 'smit'],
            'mueller' => ['miller', 'muller'],
            'meyer' => ['mayer', 'meier', 'maier'],
            'schwartz' => ['schwarz', 'swartz', 'black'],
            'weiss' => ['weis', 'white'],
            'klein' => ['kline', 'cline'],
            'braun' => ['brown'],
            'jung' => ['young'],
            'gross' => ['large', 'big'],
        ];

        // Given name variations
        $givenNamePatterns = [
            'william' => ['will', 'bill', 'billy', 'willy', 'liam'],
            'robert' => ['rob', 'bob', 'bobby', 'robbie', 'bert'],
            'richard' => ['rick', 'dick', 'richie', 'ricky'],
            'james' => ['jim', 'jimmy', 'jamie', 'jem'],
            'john' => ['jack', 'johnny', 'jon', 'ian', 'sean', 'johann', 'hans'],
            'margaret' => ['maggie', 'meg', 'peggy', 'margie', 'marge', 'greta'],
            'elizabeth' => ['liz', 'lizzie', 'beth', 'betty', 'eliza', 'lisa', 'elise'],
            'catherine' => ['kate', 'katie', 'cathy', 'kitty', 'katherine', 'kathryn'],
            'michael' => ['mike', 'mikey', 'mick', 'mickey'],
            'thomas' => ['tom', 'tommy', 'thom'],
            'joseph' => ['joe', 'joey', 'josef', 'giuseppe'],
            'charles' => ['charlie', 'chuck', 'carl', 'karl'],
            'henry' => ['harry', 'hank', 'heinrich', 'henri'],
            'george' => ['georgie', 'jorge', 'jurgen'],
            'edward' => ['ed', 'eddie', 'ted', 'teddy', 'ned'],
            'mary' => ['marie', 'maria', 'molly', 'polly', 'mamie', 'may'],
            'sarah' => ['sara', 'sally', 'sadie'],
            'anna' => ['anne', 'ann', 'annie', 'nan', 'nancy', 'hannah'],
            'benjamin' => ['ben', 'benny', 'benji'],
            'samuel' => ['sam', 'sammy'],
            'daniel' => ['dan', 'danny'],
            'david' => ['dave', 'davey', 'davie'],
            'alexander' => ['alex', 'sandy', 'alec', 'xander'],
            'frederick' => ['fred', 'freddy', 'fritz', 'friedrich'],
        ];

        $nameLower = strtolower($name);
        $patterns = $nameType === 'surname' ? $surnamePatterns : $givenNamePatterns;

        // Check exact matches
        if (isset($patterns[$nameLower])) {
            foreach ($patterns[$nameLower] as $var) {
                $variations[] = [
                    'variation' => ucfirst($var),
                    'confidence' => 0.85,
                    'is_ai_generated' => true,
                ];
            }
        }

        // Check suffix patterns for surnames
        if ($nameType === 'surname') {
            foreach ($surnamePatterns as $pattern => $replacements) {
                if (str_ends_with($nameLower, $pattern)) {
                    $base = substr($name, 0, -strlen($pattern));
                    foreach ($replacements as $replacement) {
                        $variations[] = [
                            'variation' => $base.$replacement,
                            'confidence' => 0.70,
                            'is_ai_generated' => true,
                        ];
                    }
                }
            }

            // Check prefix patterns
            if (str_starts_with($nameLower, 'mc')) {
                $variations[] = ['variation' => 'Mac'.substr($name, 2), 'confidence' => 0.80, 'is_ai_generated' => true];
            } elseif (str_starts_with($nameLower, 'mac')) {
                $variations[] = ['variation' => 'Mc'.substr($name, 3), 'confidence' => 0.80, 'is_ai_generated' => true];
            }
        }

        // Add phonetic variations (simple sound-alike substitutions)
        $phoneticSubs = [
            'c' => 'k', 'k' => 'c',
            'sch' => 'sh', 'sh' => 'sch',
            'ph' => 'f', 'f' => 'ph',
            'ck' => 'k', 'k' => 'ck',
            'ie' => 'y', 'y' => 'ie',
            'ow' => 'au', 'au' => 'ow',
        ];

        foreach ($phoneticSubs as $from => $to) {
            if (stripos($name, $from) !== false) {
                $newName = str_ireplace($from, $to, $name);
                if ($newName !== $name) {
                    $variations[] = [
                        'variation' => $newName,
                        'confidence' => 0.60,
                        'is_ai_generated' => true,
                    ];
                }
            }
        }

        // Remove duplicates
        $unique = [];
        foreach ($variations as $var) {
            $key = strtolower($var['variation']);
            if (! isset($unique[$key]) && strtolower($var['variation']) !== $nameLower) {
                $unique[$key] = $var;
            }
        }

        return array_values($unique);
    }

    /**
     * Create a research task
     */
    public function createResearchTask(array $data): ?int
    {
        // Derive tree_id from person if not supplied (agent tool registry doesn't pass tree_id)
        if (empty($data['tree_id']) && ! empty($data['person_id'])) {
            $p = DB::selectOne('SELECT tree_id FROM genealogy_persons WHERE id = ?', [(int) $data['person_id']]);
            $data['tree_id'] = $p ? $p->tree_id : null;
        }

        // Validate task_type against enum — LLM may send arbitrary values
        $validTypes = ['find_records', 'verify_facts', 'find_relatives', 'analyze_dna', 'suggest_sources', 'transcribe_document'];
        $taskType = $data['task_type'] ?? 'find_records';
        if (! in_array($taskType, $validTypes)) {
            $taskType = 'find_records'; // safe default
        }
        $createdBy = $data['created_by'] ?? null;
        $createdBy = is_numeric($createdBy) ? (int) $createdBy : null;

        DB::insert("
            INSERT INTO genealogy_research_tasks
            (tree_id, person_id, queue_item_id, task_type, priority, status,
             research_question, selection_reason, scope_reason,
             related_people_used, sources_checked, evidence_summary,
             conflicts_found, outcome_state, outcome_reason,
             parameters, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'queued', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $data['tree_id'],
            $data['person_id'] ?? null,
            $data['queue_item_id'] ?? null,
            $taskType,
            $data['priority'] ?? 'medium',
            $data['research_question'] ?? null,
            $data['selection_reason'] ?? null,
            $data['scope_reason'] ?? null,
            isset($data['related_people_used']) ? json_encode($data['related_people_used']) : null,
            isset($data['sources_checked']) ? json_encode($data['sources_checked']) : null,
            $data['evidence_summary'] ?? null,
            $data['conflicts_found'] ?? null,
            $data['outcome_state'] ?? null,
            $data['outcome_reason'] ?? null,
            isset($data['parameters']) ? json_encode($data['parameters']) : null,
            $createdBy,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Get research tasks for a tree
     */
    public function getResearchTasks(int $treeId, ?string $status = null, int $limit = 50): array
    {
        $sql = '
            SELECT t.*, p.given_name, p.surname
            FROM genealogy_research_tasks t
            LEFT JOIN genealogy_persons p ON t.person_id = p.id
            WHERE t.tree_id = ?
        ';
        $params = [$treeId];

        if ($status) {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }

        $sql .= " ORDER BY
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                ELSE 4
            END,
            t.created_at DESC
            LIMIT ?";
        $params[] = $limit;

        $tasks = DB::select($sql, $params);

        return array_map(function ($task) {
            $task->parameters = $task->parameters ? json_decode($task->parameters, true) : null;
            $task->results = $task->results ? json_decode($task->results, true) : null;
            $task->related_people_used = $task->related_people_used ? json_decode($task->related_people_used, true) : null;
            $task->sources_checked = $task->sources_checked ? json_decode($task->sources_checked, true) : null;
            $task->person_name = trim(($task->given_name ?? '').' '.($task->surname ?? ''));

            return $task;
        }, $tasks);
    }

    /**
     * Get stale processing research tasks from the live genealogy queue contract.
     */
    public function getStaleProcessingResearchTasks(?int $treeId = null, int $staleMinutes = 180): array
    {
        $sql = '
            SELECT t.*, p.given_name, p.surname
            FROM genealogy_research_tasks t
            LEFT JOIN genealogy_persons p ON t.person_id = p.id
            WHERE t.status = \'processing\'
              AND COALESCE(t.started_at, t.updated_at, t.created_at) < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ';
        $params = [max(1, $staleMinutes)];

        if ($treeId !== null) {
            $sql .= ' AND t.tree_id = ?';
            $params[] = $treeId;
        }

        $sql .= ' ORDER BY COALESCE(t.started_at, t.updated_at, t.created_at) ASC, t.id ASC';

        $tasks = DB::select($sql, $params);

        return array_map(fn ($task) => $this->hydrateResearchTaskRow($task), $tasks);
    }

    /**
     * Cancel superseded stale processing tasks and requeue unsuperseded ones.
     */
    public function cleanupStaleProcessingResearchTasks(?int $treeId = null, int $staleMinutes = 180): array
    {
        $staleTasks = $this->getStaleProcessingResearchTasks($treeId, $staleMinutes);
        $cancelled = [];
        $requeued = [];
        $queueResets = [];

        foreach ($staleTasks as $task) {
            $newerTask = $this->findNewerResearchTaskForCleanup($task);

            if ($newerTask) {
                $reason = "Superseded by newer research task #{$newerTask->id} during stale-task cleanup.";
                $affected = DB::update('
                    UPDATE genealogy_research_tasks
                    SET status = \'cancelled\',
                        completed_at = NOW(),
                        outcome_state = ?,
                        outcome_reason = ?,
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ? AND status = \'processing\'
                ', ['cancelled', $reason, $reason, $task->id]);

                if ($affected > 0) {
                    $cancelled[] = [
                        'id' => (int) $task->id,
                        'superseded_by' => (int) $newerTask->id,
                    ];
                }

                continue;
            }

            $affected = DB::update('
                UPDATE genealogy_research_tasks
                SET status = \'queued\',
                    started_at = NULL,
                    completed_at = NULL,
                    results = NULL,
                    error_message = NULL,
                    outcome_state = NULL,
                    outcome_reason = NULL,
                    updated_at = NOW()
                WHERE id = ? AND status = \'processing\'
            ', [$task->id]);

            if ($affected <= 0) {
                continue;
            }

            $requeued[] = (int) $task->id;

            if ($this->resetResearchQueueAfterStaleTask($task)) {
                $queueResets[] = (int) $task->queue_item_id;
            }
        }

        Log::info('GenealogyService: stale live research task cleanup complete', [
            'tree_id' => $treeId,
            'stale_minutes' => $staleMinutes,
            'stale_count' => count($staleTasks),
            'cancelled_count' => count($cancelled),
            'requeued_count' => count($requeued),
            'queue_reset_count' => count($queueResets),
        ]);

        return [
            'stale_count' => count($staleTasks),
            'cancelled_count' => count($cancelled),
            'requeued_count' => count($requeued),
            'queue_reset_count' => count($queueResets),
            'cancelled_tasks' => $cancelled,
            'requeued_task_ids' => $requeued,
            'queue_reset_ids' => $queueResets,
        ];
    }

    /**
     * Update a research task
     */
    public function updateResearchTask(int $taskId, array $updates): bool
    {
        $sets = ['updated_at = NOW()'];
        $params = [];

        if (isset($updates['status'])) {
            $sets[] = 'status = ?';
            $params[] = $updates['status'];

            if ($updates['status'] === 'processing') {
                $sets[] = 'started_at = NOW()';
            } elseif (in_array($updates['status'], ['completed', 'failed'])) {
                $sets[] = 'completed_at = NOW()';
            }
        }

        if (isset($updates['results'])) {
            $sets[] = 'results = ?';
            $params[] = json_encode($updates['results']);
        }

        if (isset($updates['error_message'])) {
            $sets[] = 'error_message = ?';
            $params[] = $updates['error_message'];
        }

        if (array_key_exists('research_question', $updates)) {
            $sets[] = 'research_question = ?';
            $params[] = $updates['research_question'];
        }

        if (array_key_exists('selection_reason', $updates)) {
            $sets[] = 'selection_reason = ?';
            $params[] = $updates['selection_reason'];
        }

        if (array_key_exists('scope_reason', $updates)) {
            $sets[] = 'scope_reason = ?';
            $params[] = $updates['scope_reason'];
        }

        if (array_key_exists('related_people_used', $updates)) {
            $sets[] = 'related_people_used = ?';
            $params[] = $updates['related_people_used'] !== null ? json_encode($updates['related_people_used']) : null;
        }

        if (array_key_exists('sources_checked', $updates)) {
            $sets[] = 'sources_checked = ?';
            $params[] = $updates['sources_checked'] !== null ? json_encode($updates['sources_checked']) : null;
        }

        if (array_key_exists('evidence_summary', $updates)) {
            $sets[] = 'evidence_summary = ?';
            $params[] = $updates['evidence_summary'];
        }

        if (array_key_exists('conflicts_found', $updates)) {
            $sets[] = 'conflicts_found = ?';
            $params[] = $updates['conflicts_found'];
        }

        if (array_key_exists('outcome_state', $updates)) {
            $sets[] = 'outcome_state = ?';
            $params[] = $updates['outcome_state'];
        }

        if (array_key_exists('outcome_reason', $updates)) {
            $sets[] = 'outcome_reason = ?';
            $params[] = $updates['outcome_reason'];
        }

        $params[] = $taskId;

        return DB::update('
            UPDATE genealogy_research_tasks
            SET '.implode(', ', $sets).'
            WHERE id = ?
        ', $params) > 0;
    }

    private function hydrateResearchTaskRow(object $task): object
    {
        $task->parameters = $task->parameters ? json_decode($task->parameters, true) : null;
        $task->results = $task->results ? json_decode($task->results, true) : null;
        $task->related_people_used = $task->related_people_used ? json_decode($task->related_people_used, true) : null;
        $task->sources_checked = $task->sources_checked ? json_decode($task->sources_checked, true) : null;
        $task->person_name = trim(($task->given_name ?? '').' '.($task->surname ?? ''));

        return $task;
    }

    private function findNewerResearchTaskForCleanup(object $task): ?object
    {
        $queueItemId = $task->queue_item_id ?? null;
        $personId = $task->person_id ?? null;

        if ($queueItemId === null && $personId === null) {
            return null;
        }

        return DB::selectOne('
            SELECT id, queue_item_id, person_id, status
            FROM genealogy_research_tasks
            WHERE id > ?
              AND id <> ?
              AND tree_id = ?
              AND status <> \'cancelled\'
              AND (
                    (? IS NOT NULL AND queue_item_id = ?)
                 OR (? IS NULL AND ? IS NOT NULL AND person_id = ?)
              )
            ORDER BY id DESC
            LIMIT 1
        ', [
            $task->id,
            $task->id,
            $task->tree_id,
            $queueItemId,
            $queueItemId,
            $queueItemId,
            $personId,
            $personId,
        ]);
    }

    private function resetResearchQueueAfterStaleTask(object $task): bool
    {
        if (empty($task->queue_item_id)) {
            return false;
        }

        $noteSuffix = " [auto-reset after stale task #{$task->id} requeue]";

        return DB::update('
            UPDATE genealogy_research_queue
            SET status = \'pending\',
                started_at = NULL,
                completed_at = NULL,
                session_id = NULL,
                findings_count = 0,
                review_items_count = 0,
                last_task_id = NULL,
                last_outcome_state = NULL,
                last_outcome_reason = NULL,
                notes = CONCAT(COALESCE(notes, \'\'), ?),
                updated_at = NOW()
            WHERE id = ?
              AND status = \'in_progress\'
              AND (last_task_id IS NULL OR last_task_id = ?)
        ', [$noteSuffix, $task->queue_item_id, $task->id]) > 0;
    }

    /**
     * Get smart matches for a person
     */
    public function getSmartMatches(int $personId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM genealogy_smart_matches WHERE person_id = ?';
        $params = [$personId];

        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY confidence DESC, created_at DESC';

        $matches = DB::select($sql, $params);

        return array_map(function ($match) {
            $match->match_data = json_decode($match->match_data, true);

            return $match;
        }, $matches);
    }

    /**
     * Create a smart match
     */
    public function createSmartMatch(array $data): ?int
    {
        DB::insert("
            INSERT INTO genealogy_smart_matches
            (tree_id, person_id, match_source, external_id, match_data, confidence, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ", [
            $data['tree_id'],
            $data['person_id'],
            $data['match_source'],
            $data['external_id'] ?? null,
            json_encode($data['match_data']),
            $data['confidence'] ?? 0.50,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update smart match status
     */
    public function updateSmartMatchStatus(int $matchId, string $status, ?int $userId = null): bool
    {
        return DB::update('
            UPDATE genealogy_smart_matches
            SET status = ?, reviewed_at = NOW(), reviewed_by = ?
            WHERE id = ?
        ', [$status, $userId, $matchId]) > 0;
    }

    /**
     * Analyze a person for potential research suggestions
     */
    public function analyzePersonForHints(int $personId): array
    {
        $person = $this->getPerson($personId);
        if (! $person) {
            return [];
        }

        $hints = [];

        // Check for missing birth info
        if (empty($person['birth_date']) && empty($person['birth_place'])) {
            $hints[] = [
                'tree_id' => $person['tree_id'],
                'person_id' => $personId,
                'hint_type' => 'missing_info',
                'title' => 'Missing birth information',
                'description' => 'No birth date or birth place recorded. Consider searching vital records.',
                'confidence' => 0.90,
            ];
        }

        // Check for estimated dates that could be refined
        if ($person['birth_date'] && preg_match('/^(ABT|EST|BEF|AFT|CAL)\s/i', $person['birth_date'])) {
            $hints[] = [
                'tree_id' => $person['tree_id'],
                'person_id' => $personId,
                'hint_type' => 'date_correction',
                'title' => 'Birth date could be refined',
                'description' => 'Birth date is approximate. Search census, baptism, or vital records for exact date.',
                'confidence' => 0.75,
            ];
        }

        // Check for missing death info on likely deceased
        $birthYear = $this->extractYear($person['birth_date'] ?? '');
        if ($birthYear && $birthYear < (date('Y') - 100) && empty($person['death_date'])) {
            $hints[] = [
                'tree_id' => $person['tree_id'],
                'person_id' => $personId,
                'hint_type' => 'missing_info',
                'title' => 'Missing death information',
                'description' => 'Person born over 100 years ago with no death date. Search death records or obituaries.',
                'confidence' => 0.85,
            ];
        }

        // Check for missing parents
        $familiesAsChild = DB::select('
            SELECT f.id FROM genealogy_families f
            JOIN genealogy_children fc ON f.id = fc.family_id
            WHERE fc.person_id = ?
        ', [$personId]);

        if (empty($familiesAsChild)) {
            $hints[] = [
                'tree_id' => $person['tree_id'],
                'person_id' => $personId,
                'hint_type' => 'relationship_suggestion',
                'title' => 'No parents recorded',
                'description' => 'Consider searching for birth records, baptism records, or census records showing parents.',
                'confidence' => 0.80,
            ];
        }

        // Check for name variations to search
        if ($person['surname']) {
            $existingVariations = DB::selectOne("
                SELECT COUNT(*) as cnt FROM genealogy_name_variations
                WHERE tree_id = ? AND original_name = ? AND name_type = 'surname'
            ", [$person['tree_id'], $person['surname']]);

            if ($existingVariations->cnt == 0) {
                $suggestedVariations = $this->generateNameVariations($person['tree_id'], $person['surname'], 'surname');
                if (count($suggestedVariations) > 0) {
                    $hints[] = [
                        'tree_id' => $person['tree_id'],
                        'person_id' => $personId,
                        'hint_type' => 'name_variation',
                        'title' => 'Surname variations available',
                        'description' => 'Consider searching for alternate spellings: '.
                            implode(', ', array_column(array_slice($suggestedVariations, 0, 5), 'variation')),
                        'confidence' => 0.70,
                        'source_info' => ['variations' => $suggestedVariations],
                    ];
                }
            }
        }

        return $hints;
    }

    /**
     * Generate research hints for all persons in a tree
     */
    public function generateTreeHints(int $treeId, int $limit = 100): array
    {
        $persons = DB::select('
            SELECT id FROM genealogy_persons
            WHERE tree_id = ?
            ORDER BY updated_at DESC
            LIMIT ?
        ', [$treeId, $limit]);

        $allHints = [];
        foreach ($persons as $person) {
            $hints = $this->analyzePersonForHints($person->id);
            foreach ($hints as $hint) {
                // Don't create duplicates
                $existing = DB::selectOne("
                    SELECT id FROM genealogy_research_hints
                    WHERE tree_id = ? AND person_id = ? AND hint_type = ? AND status = 'pending'
                ", [$hint['tree_id'], $hint['person_id'], $hint['hint_type']]);

                if (! $existing) {
                    $hintId = $this->createResearchHint($hint);
                    if ($hintId) {
                        $hint['id'] = $hintId;
                        $allHints[] = $hint;
                    }
                }
            }
        }

        return $allHints;
    }

    /**
     * Get research statistics for a tree
     */
    public function getResearchStatistics(int $treeId): array
    {
        $hints = DB::selectOne("
            SELECT
                COUNT(*) as total_hints,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_hints,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_hints,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_hints
            FROM genealogy_research_hints
            WHERE tree_id = ?
        ", [$treeId]);

        $variations = DB::selectOne('
            SELECT
                COUNT(*) as total_variations,
                COUNT(DISTINCT original_name) as unique_names,
                SUM(CASE WHEN is_ai_generated = 1 THEN 1 ELSE 0 END) as ai_generated
            FROM genealogy_name_variations
            WHERE tree_id = ?
        ', [$treeId]);

        $tasks = DB::selectOne("
            SELECT
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_tasks,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
            FROM genealogy_research_tasks
            WHERE tree_id = ?
        ", [$treeId]);

        $matches = DB::selectOne("
            SELECT
                COUNT(*) as total_matches,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_matches
            FROM genealogy_smart_matches
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'hints' => [
                'total' => (int) ($hints->total_hints ?? 0),
                'pending' => (int) ($hints->pending_hints ?? 0),
                'accepted' => (int) ($hints->accepted_hints ?? 0),
                'rejected' => (int) ($hints->rejected_hints ?? 0),
            ],
            'name_variations' => [
                'total' => (int) ($variations->total_variations ?? 0),
                'unique_names' => (int) ($variations->unique_names ?? 0),
                'ai_generated' => (int) ($variations->ai_generated ?? 0),
            ],
            'tasks' => [
                'total' => (int) ($tasks->total_tasks ?? 0),
                'queued' => (int) ($tasks->queued_tasks ?? 0),
                'completed' => (int) ($tasks->completed_tasks ?? 0),
            ],
            'matches' => [
                'total' => (int) ($matches->total_matches ?? 0),
                'pending' => (int) ($matches->pending_matches ?? 0),
            ],
        ];
    }

    // ========================================================================
    // Phase 9: External Integrations
    // ========================================================================

    /**
     * Get external connections for a tree
     */
    public function getExternalConnections(int $treeId): array
    {
        return DB::select('
            SELECT id, tree_id, user_id, service_type, service_user_id, status,
                   last_sync_at, sync_errors, settings, created_at, updated_at
            FROM genealogy_external_connections
            WHERE tree_id = ?
            ORDER BY service_type
        ', [$treeId]);
    }

    /**
     * Get a specific external connection
     */
    public function getExternalConnection(int $connectionId): ?object
    {
        return DB::selectOne('
            SELECT * FROM genealogy_external_connections WHERE id = ?
        ', [$connectionId]);
    }

    /**
     * Create or update an external connection
     */
    public function saveExternalConnection(array $data): ?int
    {
        $existing = DB::selectOne('
            SELECT id FROM genealogy_external_connections
            WHERE tree_id = ? AND service_type = ?
        ', [$data['tree_id'], $data['service_type']]);

        if ($existing) {
            DB::update('
                UPDATE genealogy_external_connections
                SET access_token = ?, refresh_token = ?, token_expires_at = ?,
                    service_user_id = ?, status = ?, settings = ?, updated_at = NOW()
                WHERE id = ?
            ', [
                $data['access_token'] ?? null,
                $data['refresh_token'] ?? null,
                $data['token_expires_at'] ?? null,
                $data['service_user_id'] ?? null,
                $data['status'] ?? 'active',
                isset($data['settings']) ? json_encode($data['settings']) : null,
                $existing->id,
            ]);

            return $existing->id;
        }

        DB::insert('
            INSERT INTO genealogy_external_connections
            (tree_id, user_id, service_type, access_token, refresh_token, token_expires_at,
             service_user_id, status, settings, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ', [
            $data['tree_id'],
            $data['user_id'],
            $data['service_type'],
            $data['access_token'] ?? null,
            $data['refresh_token'] ?? null,
            $data['token_expires_at'] ?? null,
            $data['service_user_id'] ?? null,
            $data['status'] ?? 'active',
            isset($data['settings']) ? json_encode($data['settings']) : null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Delete an external connection
     */
    public function deleteExternalConnection(int $connectionId): bool
    {
        return DB::delete('DELETE FROM genealogy_external_connections WHERE id = ?', [$connectionId]) > 0;
    }

    /**
     * Update connection status
     */
    public function updateConnectionStatus(int $connectionId, string $status, ?string $errorMessage = null): bool
    {
        if ($status === 'error') {
            DB::update('
                UPDATE genealogy_external_connections
                SET sync_errors = sync_errors + 1, status = ?, updated_at = NOW()
                WHERE id = ?
            ', [$status, $connectionId]);

            return true;
        }

        return DB::update('
            UPDATE genealogy_external_connections
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ', [$status, $connectionId]) > 0;
    }

    /**
     * Get external records for a tree or person
     */
    public function getExternalRecords(int $treeId, ?int $personId = null, ?string $status = null, int $limit = 50): array
    {
        $sql = '
            SELECT r.*, p.given_name, p.surname
            FROM genealogy_external_records r
            LEFT JOIN genealogy_persons p ON r.person_id = p.id
            WHERE r.tree_id = ?
        ';
        $params = [$treeId];

        if ($personId) {
            $sql .= ' AND r.person_id = ?';
            $params[] = $personId;
        }

        if ($status) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY r.match_confidence DESC, r.created_at DESC LIMIT ?';
        $params[] = $limit;

        $records = DB::select($sql, $params);

        return array_map(function ($record) {
            $record->record_data = json_decode($record->record_data, true);
            $record->person_name = trim(($record->given_name ?? '').' '.($record->surname ?? ''));

            return $record;
        }, $records);
    }

    /**
     * Save an external record
     */
    public function saveExternalRecord(array $data): ?int
    {
        // Check if already exists
        $existing = DB::selectOne('
            SELECT id FROM genealogy_external_records
            WHERE service_type = ? AND external_id = ?
        ', [$data['service_type'], $data['external_id']]);

        if ($existing) {
            DB::update('
                UPDATE genealogy_external_records
                SET record_data = ?, match_confidence = ?, updated_at = NOW()
                WHERE id = ?
            ', [
                json_encode($data['record_data']),
                $data['match_confidence'] ?? 0.50,
                $existing->id,
            ]);

            return $existing->id;
        }

        DB::insert("
            INSERT INTO genealogy_external_records
            (tree_id, person_id, service_type, external_id, record_type, title, record_data, match_confidence, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ", [
            $data['tree_id'],
            $data['person_id'] ?? null,
            $data['service_type'],
            $data['external_id'],
            $data['record_type'] ?? null,
            $data['title'] ?? null,
            json_encode($data['record_data']),
            $data['match_confidence'] ?? 0.50,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update external record status
     */
    public function updateExternalRecordStatus(int $recordId, string $status, ?int $userId = null): bool
    {
        $sets = ['status = ?', 'updated_at = NOW()'];
        $params = [$status];

        if ($status === 'matched') {
            $sets[] = 'matched_at = NOW()';
            $sets[] = 'reviewed_by = ?';
            $params[] = $userId;
        } elseif ($status === 'imported') {
            $sets[] = 'imported_at = NOW()';
            $sets[] = 'reviewed_by = ?';
            $params[] = $userId;
        } elseif ($status === 'rejected') {
            $sets[] = 'reviewed_by = ?';
            $params[] = $userId;
        }

        $params[] = $recordId;

        return DB::update('
            UPDATE genealogy_external_records
            SET '.implode(', ', $sets).'
            WHERE id = ?
        ', $params) > 0;
    }

    /**
     * Get person external links
     */
    public function getPersonExternalLinks(int $personId): array
    {
        return DB::select('
            SELECT * FROM genealogy_person_external_links
            WHERE person_id = ?
            ORDER BY service_type
        ', [$personId]);
    }

    /**
     * Link a person to an external service
     */
    public function linkPersonToExternalService(array $data): ?int
    {
        // Check if already exists
        $existing = DB::selectOne('
            SELECT id FROM genealogy_person_external_links
            WHERE person_id = ? AND service_type = ?
        ', [$data['person_id'], $data['service_type']]);

        if ($existing) {
            DB::update('
                UPDATE genealogy_person_external_links
                SET external_person_id = ?, link_type = ?, sync_enabled = ?, updated_at = NOW()
                WHERE id = ?
            ', [
                $data['external_person_id'],
                $data['link_type'] ?? 'confirmed',
                $data['sync_enabled'] ?? true,
                $existing->id,
            ]);

            return $existing->id;
        }

        DB::insert('
            INSERT INTO genealogy_person_external_links
            (person_id, service_type, external_person_id, link_type, sync_enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ', [
            $data['person_id'],
            $data['service_type'],
            $data['external_person_id'],
            $data['link_type'] ?? 'confirmed',
            $data['sync_enabled'] ?? true,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Unlink a person from an external service
     */
    public function unlinkPersonFromExternalService(int $personId, string $serviceType): bool
    {
        return DB::delete('
            DELETE FROM genealogy_person_external_links
            WHERE person_id = ? AND service_type = ?
        ', [$personId, $serviceType]) > 0;
    }

    /**
     * Create a sync record
     */
    public function createExternalSync(int $connectionId, string $syncType, string $direction = 'import'): ?int
    {
        DB::insert("
            INSERT INTO genealogy_external_syncs
            (connection_id, sync_type, direction, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ", [$connectionId, $syncType, $direction]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update sync status
     */
    public function updateExternalSync(int $syncId, array $updates): bool
    {
        $sets = [];
        $params = [];

        if (isset($updates['status'])) {
            $sets[] = 'status = ?';
            $params[] = $updates['status'];

            if ($updates['status'] === 'running') {
                $sets[] = 'started_at = NOW()';
            } elseif (in_array($updates['status'], ['completed', 'failed', 'cancelled'])) {
                $sets[] = 'completed_at = NOW()';
            }
        }

        if (isset($updates['records_found'])) {
            $sets[] = 'records_found = ?';
            $params[] = $updates['records_found'];
        }
        if (isset($updates['records_imported'])) {
            $sets[] = 'records_imported = ?';
            $params[] = $updates['records_imported'];
        }
        if (isset($updates['records_updated'])) {
            $sets[] = 'records_updated = ?';
            $params[] = $updates['records_updated'];
        }
        if (isset($updates['records_skipped'])) {
            $sets[] = 'records_skipped = ?';
            $params[] = $updates['records_skipped'];
        }
        if (isset($updates['records_failed'])) {
            $sets[] = 'records_failed = ?';
            $params[] = $updates['records_failed'];
        }
        if (isset($updates['error_log'])) {
            $sets[] = 'error_log = ?';
            $params[] = json_encode($updates['error_log']);
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $syncId;

        return DB::update('
            UPDATE genealogy_external_syncs
            SET '.implode(', ', $sets).'
            WHERE id = ?
        ', $params) > 0;
    }

    /**
     * Get sync history for a connection
     */
    public function getSyncHistory(int $connectionId, int $limit = 20): array
    {
        $syncs = DB::select('
            SELECT * FROM genealogy_external_syncs
            WHERE connection_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ', [$connectionId, $limit]);

        return array_map(function ($sync) {
            $sync->error_log = $sync->error_log ? json_decode($sync->error_log, true) : null;

            return $sync;
        }, $syncs);
    }

    /**
     * Get external integration statistics for a tree
     */
    public function getExternalIntegrationStats(int $treeId): array
    {
        $connections = DB::selectOne("
            SELECT
                COUNT(*) as total_connections,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_connections
            FROM genealogy_external_connections
            WHERE tree_id = ?
        ", [$treeId]);

        $records = DB::selectOne("
            SELECT
                COUNT(*) as total_records,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_records,
                SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported_records,
                SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END) as matched_records,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_records
            FROM genealogy_external_records
            WHERE tree_id = ?
        ", [$treeId]);

        $links = DB::selectOne('
            SELECT
                COUNT(*) as total_links,
                SUM(CASE WHEN sync_enabled = 1 THEN 1 ELSE 0 END) as sync_enabled_count
            FROM genealogy_person_external_links pel
            JOIN genealogy_persons p ON pel.person_id = p.id
            WHERE p.tree_id = ?
        ', [$treeId]);

        $syncs = DB::selectOne("
            SELECT
                COUNT(*) as recent_count,
                SUM(CASE WHEN es.status = 'completed' THEN 1 ELSE 0 END) as successful_count,
                SUM(CASE WHEN es.status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM genealogy_external_syncs es
            JOIN genealogy_external_connections ec ON es.connection_id = ec.id
            WHERE ec.tree_id = ? AND es.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ", [$treeId]);

        return [
            'connections' => [
                'total' => (int) ($connections->total_connections ?? 0),
                'active' => (int) ($connections->active_connections ?? 0),
            ],
            'records' => [
                'total' => (int) ($records->total_records ?? 0),
                'pending' => (int) ($records->pending_records ?? 0),
                'imported' => (int) ($records->imported_records ?? 0),
                'matched' => (int) ($records->matched_records ?? 0),
                'rejected' => (int) ($records->rejected_records ?? 0),
            ],
            'person_links' => [
                'total' => (int) ($links->total_links ?? 0),
                'sync_enabled' => (int) ($links->sync_enabled_count ?? 0),
            ],
            'syncs' => [
                'recent_count' => (int) ($syncs->recent_count ?? 0),
                'successful' => (int) ($syncs->successful_count ?? 0),
                'failed' => (int) ($syncs->failed_count ?? 0),
            ],
        ];
    }

    /**
     * Get supported external services
     */
    public function getSupportedExternalServices(): array
    {
        return [
            'findmypast' => [
                'name' => 'FindMyPast',
                'description' => 'UK and Irish genealogy records',
                'url' => 'https://www.findmypast.com',
                'auth_type' => 'oauth2',
                'features' => ['records', 'trees'],
            ],
            'myheritage' => [
                'name' => 'MyHeritage',
                'description' => 'Online genealogy platform with DNA testing',
                'url' => 'https://www.myheritage.com',
                'auth_type' => 'oauth2',
                'features' => ['records', 'trees', 'dna', 'photos'],
            ],
            'geneanet' => [
                'name' => 'Geneanet',
                'description' => 'European genealogy portal',
                'url' => 'https://www.geneanet.org',
                'auth_type' => 'oauth2',
                'features' => ['trees'],
            ],
            'wikitree' => [
                'name' => 'WikiTree',
                'description' => 'Free collaborative family tree',
                'url' => 'https://www.wikitree.com',
                'auth_type' => 'api_key',
                'features' => ['trees'],
            ],
            'findagrave' => [
                'name' => 'Find A Grave',
                'description' => 'Cemetery and burial records',
                'url' => 'https://www.findagrave.com',
                'auth_type' => 'api_key',
                'features' => ['records', 'photos'],
            ],
        ];
    }

    // ==========================================
    // PHASE 4: DUPLICATE DETECTION & MERGE
    // ==========================================

    /**
     * Find potential duplicate persons in a tree
     *
     * Uses multiple matching strategies:
     * - Exact name match
     * - Soundex/phonetic matching for name variations
     * - Birth date matching
     * - Death date matching
     * - Combined scoring
     *
     * @param  int  $treeId  Tree ID
     * @param  array  $options  Filter options (minScore, limit, includeResolved)
     * @return array List of potential duplicate pairs with confidence scores
     */
    public function findDuplicatePersons(int $treeId, array $options = []): array
    {
        $minScore = $options['minScore'] ?? 0.6;
        $limit = $options['limit'] ?? 100;
        $includeResolved = $options['includeResolved'] ?? false;

        // Get all persons with relevant data
        $sql = '
            SELECT
                p.id,
                p.gedcom_id,
                p.given_name,
                p.surname,
                p.birth_date,
                p.birth_place,
                p.death_date,
                p.death_place,
                p.sex as gender,
                SOUNDEX(p.surname) as surname_soundex,
                SOUNDEX(p.given_name) as given_soundex
            FROM genealogy_persons p
            WHERE p.tree_id = ?
            ORDER BY p.surname, p.given_name
        ';

        $persons = DB::select($sql, [$treeId]);

        // Get already-resolved duplicate pairs to exclude
        $resolvedPairs = [];
        if (! $includeResolved) {
            $resolvedSql = "
                SELECT person1_id, person2_id
                FROM genealogy_duplicate_pairs
                WHERE tree_id = ? AND status IN ('resolved', 'rejected', 'merged')
            ";
            $resolved = DB::select($resolvedSql, [$treeId]);
            foreach ($resolved as $r) {
                $key = min($r->person1_id, $r->person2_id).'-'.max($r->person1_id, $r->person2_id);
                $resolvedPairs[$key] = true;
            }
        }

        $duplicates = [];
        $count = count($persons);

        // Compare each person with every other person (O(n²) but necessary)
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $p1 = $persons[$i];
                $p2 = $persons[$j];

                // Skip if already resolved
                $pairKey = min($p1->id, $p2->id).'-'.max($p1->id, $p2->id);
                if (isset($resolvedPairs[$pairKey])) {
                    continue;
                }

                // Calculate similarity score
                $score = $this->calculateDuplicateScore($p1, $p2);

                if ($score >= $minScore) {
                    $duplicates[] = [
                        'person1' => [
                            'id' => $p1->id,
                            'gedcom_id' => $p1->gedcom_id,
                            'name' => trim(($p1->given_name ?? '').' '.($p1->surname ?? '')),
                            'birth_date' => $p1->birth_date,
                            'birth_place' => $p1->birth_place,
                            'death_date' => $p1->death_date,
                            'death_place' => $p1->death_place,
                            'gender' => $p1->gender,
                        ],
                        'person2' => [
                            'id' => $p2->id,
                            'gedcom_id' => $p2->gedcom_id,
                            'name' => trim(($p2->given_name ?? '').' '.($p2->surname ?? '')),
                            'birth_date' => $p2->birth_date,
                            'birth_place' => $p2->birth_place,
                            'death_date' => $p2->death_date,
                            'death_place' => $p2->death_place,
                            'gender' => $p2->gender,
                        ],
                        'score' => round($score, 3),
                        'reasons' => $this->getDuplicateReasons($p1, $p2),
                    ];
                }
            }
        }

        // Sort by score descending
        usort($duplicates, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Apply limit
        return array_slice($duplicates, 0, $limit);
    }

    /**
     * Calculate similarity score between two persons
     */
    private function calculateDuplicateScore(object $p1, object $p2): float
    {
        $score = 0.0;
        $weights = [
            'surname_exact' => 0.25,
            'surname_soundex' => 0.15,
            'given_exact' => 0.20,
            'given_soundex' => 0.10,
            'birth_date' => 0.15,
            'death_date' => 0.10,
            'gender' => 0.05,
        ];

        // Surname comparison
        if (! empty($p1->surname) && ! empty($p2->surname)) {
            if (strtolower($p1->surname) === strtolower($p2->surname)) {
                $score += $weights['surname_exact'];
            } elseif ($p1->surname_soundex === $p2->surname_soundex) {
                $score += $weights['surname_soundex'];
            }
        }

        // Given name comparison
        if (! empty($p1->given_name) && ! empty($p2->given_name)) {
            $given1 = strtolower($p1->given_name);
            $given2 = strtolower($p2->given_name);

            if ($given1 === $given2) {
                $score += $weights['given_exact'];
            } elseif ($p1->given_soundex === $p2->given_soundex) {
                $score += $weights['given_soundex'];
            } else {
                // Check for partial match (first name)
                $first1 = explode(' ', $given1)[0];
                $first2 = explode(' ', $given2)[0];
                if ($first1 === $first2) {
                    $score += $weights['given_exact'] * 0.7;
                }
            }
        }

        // Birth date comparison
        if (! empty($p1->birth_date) && ! empty($p2->birth_date)) {
            if ($p1->birth_date === $p2->birth_date) {
                $score += $weights['birth_date'];
            } else {
                // Check year match only
                $year1 = substr($p1->birth_date, 0, 4);
                $year2 = substr($p2->birth_date, 0, 4);
                if ($year1 === $year2 && is_numeric($year1)) {
                    $score += $weights['birth_date'] * 0.5;
                }
            }
        }

        // Death date comparison
        if (! empty($p1->death_date) && ! empty($p2->death_date)) {
            if ($p1->death_date === $p2->death_date) {
                $score += $weights['death_date'];
            } else {
                $year1 = substr($p1->death_date, 0, 4);
                $year2 = substr($p2->death_date, 0, 4);
                if ($year1 === $year2 && is_numeric($year1)) {
                    $score += $weights['death_date'] * 0.5;
                }
            }
        }

        // Gender match
        if (! empty($p1->gender) && ! empty($p2->gender)) {
            if ($p1->gender === $p2->gender) {
                $score += $weights['gender'];
            }
        }

        return $score;
    }

    /**
     * Get human-readable reasons for duplicate match
     */
    private function getDuplicateReasons(object $p1, object $p2): array
    {
        $reasons = [];

        if (! empty($p1->surname) && ! empty($p2->surname)) {
            if (strtolower($p1->surname) === strtolower($p2->surname)) {
                $reasons[] = 'Exact surname match';
            } elseif (soundex($p1->surname) === soundex($p2->surname)) {
                $reasons[] = 'Similar surname (phonetic)';
            }
        }

        if (! empty($p1->given_name) && ! empty($p2->given_name)) {
            if (strtolower($p1->given_name) === strtolower($p2->given_name)) {
                $reasons[] = 'Exact given name match';
            } else {
                $first1 = explode(' ', strtolower($p1->given_name))[0];
                $first2 = explode(' ', strtolower($p2->given_name))[0];
                if ($first1 === $first2) {
                    $reasons[] = 'First name match';
                }
            }
        }

        if (! empty($p1->birth_date) && $p1->birth_date === $p2->birth_date) {
            $reasons[] = 'Same birth date';
        } elseif (! empty($p1->birth_date) && ! empty($p2->birth_date)) {
            $year1 = substr($p1->birth_date, 0, 4);
            $year2 = substr($p2->birth_date, 0, 4);
            if ($year1 === $year2) {
                $reasons[] = 'Same birth year';
            }
        }

        if (! empty($p1->death_date) && $p1->death_date === $p2->death_date) {
            $reasons[] = 'Same death date';
        }

        if (! empty($p1->gender) && $p1->gender === $p2->gender) {
            $reasons[] = 'Same gender';
        }

        return $reasons;
    }

    /**
     * Mark a duplicate pair as resolved (not duplicates) or to be merged
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $person1Id  First person ID
     * @param  int  $person2Id  Second person ID
     * @param  string  $status  Status: 'rejected' (not duplicates), 'pending_merge', 'merged'
     * @return bool Success
     */
    public function resolveDuplicatePair(int $treeId, int $person1Id, int $person2Id, string $status): bool
    {
        // Normalize order
        $minId = min($person1Id, $person2Id);
        $maxId = max($person1Id, $person2Id);

        // Check if pair exists
        $existing = DB::selectOne('
            SELECT id FROM genealogy_duplicate_pairs
            WHERE tree_id = ? AND person1_id = ? AND person2_id = ?
        ', [$treeId, $minId, $maxId]);

        if ($existing) {
            DB::update('
                UPDATE genealogy_duplicate_pairs
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ', [$status, $existing->id]);
        } else {
            DB::insert('
                INSERT INTO genealogy_duplicate_pairs
                (tree_id, person1_id, person2_id, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ', [$treeId, $minId, $maxId, $status]);
        }

        return true;
    }

    /**
     * Merge two persons, keeping primary and transferring data from secondary
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $primaryId  Person to keep (primary)
     * @param  int  $secondaryId  Person to merge into primary (will be deleted)
     * @param  array  $options  Merge options (keepSecondaryNames, keepSecondaryDates, etc.)
     * @return array Merge result with transferred data counts
     */
    public function mergePersons(int $treeId, int $primaryId, int $secondaryId, array $options = []): array
    {
        $result = [
            'success' => true,
            'primary_id' => $primaryId,
            'secondary_id' => $secondaryId,
            'transferred' => [
                'events' => 0,
                'facts' => 0,
                'names' => 0,
                'media' => 0,
                'citations' => 0,
                'notes' => 0,
                'families_as_child' => 0,
                'families_as_spouse' => 0,
            ],
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            // Verify both persons exist in tree
            $primary = DB::selectOne('SELECT * FROM genealogy_persons WHERE id = ? AND tree_id = ?', [$primaryId, $treeId]);
            $secondary = DB::selectOne('SELECT * FROM genealogy_persons WHERE id = ? AND tree_id = ?', [$secondaryId, $treeId]);

            if (! $primary || ! $secondary) {
                throw new Exception('One or both persons not found in tree');
            }

            // Transfer events from secondary to primary
            $eventsUpdated = DB::update('
                UPDATE genealogy_events SET person_id = ? WHERE person_id = ?
            ', [$primaryId, $secondaryId]);
            $result['transferred']['events'] = $eventsUpdated;

            // genealogy_person_facts table does not exist — skip
            $result['transferred']['facts'] = 0;

            // Transfer alternate names if option enabled
            // Note: genealogy_person_names table not implemented - skip name transfer
            // If secondary has a different name, log it in notes or as nickname
            if ($options['keepSecondaryNames'] ?? true) {
                $secondaryName = trim(($secondary->given_name ?? '').' '.($secondary->surname ?? ''));
                $primaryName = trim(($primary->given_name ?? '').' '.($primary->surname ?? ''));
                if ($secondaryName && $secondaryName !== $primaryName) {
                    // Store secondary name as nickname if primary doesn't have one
                    if (empty($primary->nickname) && ! empty($secondaryName)) {
                        DB::update('UPDATE genealogy_persons SET nickname = ? WHERE id = ?', [$secondaryName, $primaryId]);
                        $result['transferred']['names']++;
                    }
                }
            }

            // Transfer media links via genealogy_person_media junction table
            $mediaUpdated = DB::update('
                UPDATE genealogy_person_media SET person_id = ?
                WHERE person_id = ? AND media_id NOT IN (
                    SELECT media_id FROM genealogy_person_media WHERE person_id = ?
                )
            ', [$primaryId, $secondaryId, $primaryId]);
            $result['transferred']['media'] = $mediaUpdated;

            // Transfer citations
            $citationsUpdated = DB::update('
                UPDATE genealogy_citations SET person_id = ? WHERE person_id = ?
            ', [$primaryId, $secondaryId]);
            $result['transferred']['citations'] = $citationsUpdated;

            // Transfer shared note references from secondary to primary
            $notesUpdated = DB::update("
                UPDATE genealogy_shared_note_refs SET record_id = ?
                WHERE record_type = 'person' AND record_id = ?
            ", [$primaryId, $secondaryId]);
            $result['transferred']['notes'] = $notesUpdated;

            // Update family relationships - child
            $childFamilies = DB::update('
                UPDATE genealogy_children SET person_id = ?
                WHERE person_id = ?
            ', [$primaryId, $secondaryId]);
            $result['transferred']['families_as_child'] = $childFamilies;

            // Update family relationships - spouse (husband)
            $husbandFamilies = DB::update('
                UPDATE genealogy_families SET husband_id = ?
                WHERE husband_id = ? AND tree_id = ?
            ', [$primaryId, $secondaryId, $treeId]);

            // Update family relationships - spouse (wife)
            $wifeFamilies = DB::update('
                UPDATE genealogy_families SET wife_id = ?
                WHERE wife_id = ? AND tree_id = ?
            ', [$primaryId, $secondaryId, $treeId]);
            $result['transferred']['families_as_spouse'] = $husbandFamilies + $wifeFamilies;

            // Update research hints
            DB::update('
                UPDATE genealogy_research_hints SET person_id = ? WHERE person_id = ?
            ', [$primaryId, $secondaryId]);

            // Update external links
            DB::update('
                UPDATE genealogy_person_external_links SET person_id = ?
                WHERE person_id = ? AND service_type NOT IN (
                    SELECT service_type FROM genealogy_person_external_links WHERE person_id = ?
                )
            ', [$primaryId, $secondaryId, $primaryId]);

            // Optionally merge dates if primary is missing them
            if ($options['fillMissingDates'] ?? true) {
                $updates = [];
                $params = [];

                if (empty($primary->birth_date) && ! empty($secondary->birth_date)) {
                    $updates[] = 'birth_date = ?';
                    $params[] = $secondary->birth_date;
                }
                if (empty($primary->birth_place) && ! empty($secondary->birth_place)) {
                    $updates[] = 'birth_place = ?';
                    $params[] = $secondary->birth_place;
                }
                if (empty($primary->death_date) && ! empty($secondary->death_date)) {
                    $updates[] = 'death_date = ?';
                    $params[] = $secondary->death_date;
                }
                if (empty($primary->death_place) && ! empty($secondary->death_place)) {
                    $updates[] = 'death_place = ?';
                    $params[] = $secondary->death_place;
                }

                if (! empty($updates)) {
                    $params[] = $primaryId;
                    DB::update('UPDATE genealogy_persons SET '.implode(', ', $updates).' WHERE id = ?', $params);
                }
            }

            // Delete secondary person
            DB::delete('DELETE FROM genealogy_persons WHERE id = ?', [$secondaryId]);

            // Mark duplicate pair as merged
            $this->resolveDuplicatePair($treeId, $primaryId, $secondaryId, 'merged');

            DB::commit();

            Log::info('Merged persons', ['primary' => $primaryId, 'secondary' => $secondaryId, 'result' => $result]);

        } catch (Exception $e) {
            DB::rollBack();
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            Log::error('Person merge failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Get duplicate detection statistics for a tree
     */
    public function getDuplicateStats(int $treeId): array
    {
        // Count total pairs by status
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_pairs,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'pending_merge' THEN 1 ELSE 0 END) as pending_merge,
                SUM(CASE WHEN status = 'merged' THEN 1 ELSE 0 END) as merged,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM genealogy_duplicate_pairs
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'total_pairs' => (int) ($stats->total_pairs ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'pending_merge' => (int) ($stats->pending_merge ?? 0),
            'merged' => (int) ($stats->merged ?? 0),
            'rejected' => (int) ($stats->rejected ?? 0),
        ];
    }

    /**
     * Get tree-wide timeline of all family events (Priority 4.2)
     * Returns chronological view of births, deaths, marriages, and other events across the entire tree
     *
     * @param  int  $treeId  Tree ID
     * @param  array  $options  Filter options: start_year, end_year, event_types, surname, limit
     * @return array Timeline events sorted by date
     */
    public function getTreeTimeline(int $treeId, array $options = []): array
    {
        $timeline = [];
        $startYear = $options['start_year'] ?? null;
        $endYear = $options['end_year'] ?? null;
        $eventTypes = $options['event_types'] ?? null; // ['birth', 'death', 'marriage', 'event']
        $surnameFilter = $options['surname'] ?? null;
        $limit = $options['limit'] ?? 500;

        // Build WHERE clause for date filtering
        $yearFilter = '';
        $yearParams = [];
        if ($startYear) {
            $yearFilter .= ' AND CAST(SUBSTRING(date_field, 1, 4) AS UNSIGNED) >= ?';
            $yearParams[] = $startYear;
        }
        if ($endYear) {
            $yearFilter .= ' AND CAST(SUBSTRING(date_field, 1, 4) AS UNSIGNED) <= ?';
            $yearParams[] = $endYear;
        }

        // Birth events
        if (! $eventTypes || in_array('birth', $eventTypes)) {
            $birthFilter = str_replace('date_field', 'p.birth_date', $yearFilter);
            $surnameClause = $surnameFilter ? ' AND p.surname = ?' : '';

            $birthSql = "
                SELECT
                    p.id as person_id,
                    p.given_name,
                    p.surname,
                    p.sex,
                    'birth' as event_type,
                    p.birth_date as event_date,
                    p.birth_place as event_place,
                    CAST(SUBSTRING(p.birth_date, 1, 4) AS UNSIGNED) as sort_year,
                    p.birth_date as sort_date
                FROM genealogy_persons p
                WHERE p.tree_id = ?
                  AND p.birth_date IS NOT NULL
                  AND p.birth_date != ''
                  {$birthFilter}
                  {$surnameClause}
            ";

            $params = [$treeId, ...$yearParams];
            if ($surnameFilter) {
                $params[] = $surnameFilter;
            }

            $births = DB::select($birthSql, $params);
            foreach ($births as $event) {
                $timeline[] = [
                    'id' => 'birth_'.$event->person_id,
                    'type' => 'birth',
                    'date' => $event->event_date,
                    'year' => $event->sort_year,
                    'place' => $event->event_place,
                    'person_id' => $event->person_id,
                    'person_name' => trim($event->given_name.' '.$event->surname),
                    'surname' => $event->surname,
                    'sex' => $event->sex,
                    'title' => 'Birth',
                    'description' => trim($event->given_name.' '.$event->surname).' was born',
                    'icon' => 'baby',
                    'color' => '#4CAF50', // Green for births
                ];
            }
        }

        // Death events
        if (! $eventTypes || in_array('death', $eventTypes)) {
            $deathFilter = str_replace('date_field', 'p.death_date', $yearFilter);
            $surnameClause = $surnameFilter ? ' AND p.surname = ?' : '';

            $deathSql = "
                SELECT
                    p.id as person_id,
                    p.given_name,
                    p.surname,
                    p.sex,
                    'death' as event_type,
                    p.death_date as event_date,
                    p.death_place as event_place,
                    CAST(SUBSTRING(p.death_date, 1, 4) AS UNSIGNED) as sort_year,
                    p.death_date as sort_date
                FROM genealogy_persons p
                WHERE p.tree_id = ?
                  AND p.death_date IS NOT NULL
                  AND p.death_date != ''
                  {$deathFilter}
                  {$surnameClause}
            ";

            $params = [$treeId, ...$yearParams];
            if ($surnameFilter) {
                $params[] = $surnameFilter;
            }

            $deaths = DB::select($deathSql, $params);
            foreach ($deaths as $event) {
                $timeline[] = [
                    'id' => 'death_'.$event->person_id,
                    'type' => 'death',
                    'date' => $event->event_date,
                    'year' => $event->sort_year,
                    'place' => $event->event_place,
                    'person_id' => $event->person_id,
                    'person_name' => trim($event->given_name.' '.$event->surname),
                    'surname' => $event->surname,
                    'sex' => $event->sex,
                    'title' => 'Death',
                    'description' => trim($event->given_name.' '.$event->surname).' died',
                    'icon' => 'cross',
                    'color' => '#9E9E9E', // Gray for deaths
                ];
            }
        }

        // Marriage events
        if (! $eventTypes || in_array('marriage', $eventTypes)) {
            $marriageFilter = str_replace('date_field', 'f.marriage_date', $yearFilter);

            $marriageSql = "
                SELECT
                    f.id as family_id,
                    f.marriage_date as event_date,
                    f.marriage_place as event_place,
                    CAST(SUBSTRING(f.marriage_date, 1, 4) AS UNSIGNED) as sort_year,
                    h.id as husband_id,
                    h.given_name as husband_given_name,
                    h.surname as husband_surname,
                    w.id as wife_id,
                    w.given_name as wife_given_name,
                    w.surname as wife_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON f.husband_id = h.id
                LEFT JOIN genealogy_persons w ON f.wife_id = w.id
                WHERE f.tree_id = ?
                  AND f.marriage_date IS NOT NULL
                  AND f.marriage_date != ''
                  {$marriageFilter}
            ";

            $params = [$treeId, ...$yearParams];
            $marriages = DB::select($marriageSql, $params);

            foreach ($marriages as $event) {
                // Skip if surname filter is set and neither spouse matches
                if ($surnameFilter && $event->husband_surname !== $surnameFilter && $event->wife_surname !== $surnameFilter) {
                    continue;
                }

                $husbandName = trim($event->husband_given_name.' '.$event->husband_surname);
                $wifeName = trim($event->wife_given_name.' '.$event->wife_surname);

                $timeline[] = [
                    'id' => 'marriage_'.$event->family_id,
                    'type' => 'marriage',
                    'date' => $event->event_date,
                    'year' => $event->sort_year,
                    'place' => $event->event_place,
                    'family_id' => $event->family_id,
                    'husband_id' => $event->husband_id,
                    'wife_id' => $event->wife_id,
                    'person_name' => $husbandName.' & '.$wifeName,
                    'surname' => $event->husband_surname,
                    'title' => 'Marriage',
                    'description' => $husbandName.' married '.$wifeName,
                    'icon' => 'rings',
                    'color' => '#E91E63', // Pink for marriages
                ];
            }
        }

        // Other life events from genealogy_events table
        if (! $eventTypes || in_array('event', $eventTypes)) {
            $eventFilter = str_replace('date_field', 'e.event_date', $yearFilter);
            $surnameClause = $surnameFilter ? ' AND p.surname = ?' : '';

            $eventsSql = "
                SELECT
                    e.id as event_id,
                    e.event_type,
                    e.event_date,
                    e.event_place,
                    e.description as event_description,
                    CAST(SUBSTRING(e.event_date, 1, 4) AS UNSIGNED) as sort_year,
                    p.id as person_id,
                    p.given_name,
                    p.surname,
                    p.sex
                FROM genealogy_events e
                JOIN genealogy_persons p ON e.person_id = p.id
                WHERE p.tree_id = ?
                  AND e.event_date IS NOT NULL
                  AND e.event_date != ''
                  {$eventFilter}
                  {$surnameClause}
            ";

            $params = [$treeId, ...$yearParams];
            if ($surnameFilter) {
                $params[] = $surnameFilter;
            }

            $events = DB::select($eventsSql, $params);
            foreach ($events as $event) {
                $timeline[] = [
                    'id' => 'event_'.$event->event_id,
                    'type' => 'event',
                    'event_subtype' => $event->event_type,
                    'date' => $event->event_date,
                    'year' => $event->sort_year,
                    'place' => $event->event_place,
                    'person_id' => $event->person_id,
                    'person_name' => trim($event->given_name.' '.$event->surname),
                    'surname' => $event->surname,
                    'sex' => $event->sex,
                    'title' => $this->getEventTypeLabel($event->event_type),
                    'description' => $event->event_description ?? $this->getEventTypeLabel($event->event_type),
                    'icon' => $this->getEventIcon($event->event_type),
                    'color' => '#2196F3', // Blue for other events
                ];
            }
        }

        // Sort by year and date
        usort($timeline, function ($a, $b) {
            // First sort by year
            $yearCmp = ($a['year'] ?? 9999) <=> ($b['year'] ?? 9999);
            if ($yearCmp !== 0) {
                return $yearCmp;
            }

            // Then by date string (for same year)
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });

        // Apply limit
        if (count($timeline) > $limit) {
            $timeline = array_slice($timeline, 0, $limit);
        }

        // Get year range statistics
        $years = array_filter(array_column($timeline, 'year'), fn ($y) => $y > 0 && $y < 9999);
        $stats = [
            'total_events' => count($timeline),
            'earliest_year' => $years ? min($years) : null,
            'latest_year' => $years ? max($years) : null,
            'event_counts' => [
                'births' => count(array_filter($timeline, fn ($e) => $e['type'] === 'birth')),
                'deaths' => count(array_filter($timeline, fn ($e) => $e['type'] === 'death')),
                'marriages' => count(array_filter($timeline, fn ($e) => $e['type'] === 'marriage')),
                'other_events' => count(array_filter($timeline, fn ($e) => $e['type'] === 'event')),
            ],
        ];

        return [
            'timeline' => $timeline,
            'stats' => $stats,
            'filters' => [
                'start_year' => $startYear,
                'end_year' => $endYear,
                'event_types' => $eventTypes,
                'surname' => $surnameFilter,
                'limit' => $limit,
            ],
        ];
    }

    /**
     * Rebuild ancestor paths for a tree via BFS upward traversal.
     *
     * Walks from rootPersonId upward through parent relationships stored in
     * genealogy_children + genealogy_families, computing generation distance
     * and bloodline tier for every reachable ancestor. Results stored in
     * genealogy_ancestor_paths and used by refreshPersonCoverage() to assign
     * tier-aware priority scores.
     *
     * Tier assignments:
     *   1 = direct blood ancestor (reachable by going straight up from root)
     *   2 = sibling of a direct ancestor (same family, not on direct line)
     *   3 = other relatives reachable via the tree structure
     *   4 = spouses/married-in with no blood path to root
     *
     * @param  int  $rootPersonId  The tree owner's person record ID
     * @return int Number of ancestor paths written
     */
    public function rebuildAncestorPaths(int $treeId, ?int $rootPersonId = null): int
    {
        // Auto-load root from genealogy_trees if not provided
        if ($rootPersonId === null) {
            $tree = DB::selectOne('SELECT root_person_id FROM genealogy_trees WHERE id = ?', [$treeId]);
            $rootPersonId = $tree?->root_person_id ?? null;
            if (! $rootPersonId) {
                throw new \InvalidArgumentException("Tree {$treeId} has no root_person_id set. Set it via genealogy_trees or pass explicitly.");
            }
        }

        // Clear existing paths for this tree
        DB::delete('DELETE FROM genealogy_ancestor_paths WHERE tree_id = ?', [$treeId]);

        $now = now()->toDateTimeString();
        $treePersonIds = [];
        foreach (DB::select('SELECT id FROM genealogy_persons WHERE tree_id = ?', [$treeId]) as $person) {
            $treePersonIds[(int) $person->id] = true;
        }

        $familyParents = [];
        $parentFamilies = [];
        foreach (DB::select('SELECT id, husband_id, wife_id FROM genealogy_families WHERE tree_id = ?', [$treeId]) as $family) {
            $familyId = (int) $family->id;
            $husbandId = (int) ($family->husband_id ?? 0);
            $wifeId = (int) ($family->wife_id ?? 0);
            $familyParents[$familyId] = [
                'husband_id' => $husbandId > 0 && isset($treePersonIds[$husbandId]) ? $husbandId : null,
                'wife_id' => $wifeId > 0 && isset($treePersonIds[$wifeId]) ? $wifeId : null,
            ];

            foreach ([$husbandId, $wifeId] as $parentId) {
                if ($parentId > 0 && isset($treePersonIds[$parentId])) {
                    $parentFamilies[$parentId][] = $familyId;
                }
            }
        }

        $familyChildren = [];
        $personChildFamily = [];
        $children = DB::select(
            'SELECT c.id, c.family_id, c.person_id
             FROM genealogy_children c
             JOIN genealogy_families f ON f.id = c.family_id
             JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = f.tree_id
             WHERE f.tree_id = ?
             ORDER BY c.id',
            [$treeId]
        );
        foreach ($children as $child) {
            $familyId = (int) $child->family_id;
            $personId = (int) $child->person_id;
            $familyChildren[$familyId][] = $personId;
            $personChildFamily[$personId] ??= $familyId;
        }

        $pathRows = [];
        $recordPath = static function (int $personId, int $generation, array $path, int $tier) use (&$pathRows, $treeId, $rootPersonId, $now): void {
            $existing = $pathRows[$personId] ?? null;
            if ($existing !== null) {
                $existingTier = (int) $existing['bloodline_tier'];
                $existingGeneration = (int) $existing['generation'];
                if ($existingTier < $tier || ($existingTier === $tier && $existingGeneration <= $generation)) {
                    return;
                }
            }

            $pathRows[$personId] = [
                'tree_id' => $treeId,
                'root_person_id' => $rootPersonId,
                'ancestor_id' => $personId,
                'generation' => $generation,
                'path_ids' => json_encode(array_values(array_map('intval', $path))),
                'bloodline_tier' => $tier,
                'rebuilt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        // BFS queue: [person_id, generation, path_ids_array]
        $queue = [[$rootPersonId, 0, [$rootPersonId]]];
        $visited = [$rootPersonId => true];

        // Track all direct ancestors and their generations for sibling detection
        $directAncestors = [$rootPersonId => 0];
        $directAncestorPaths = [$rootPersonId => [$rootPersonId]];

        // Phase 1: walk straight up the bloodline (Tier 1)
        for ($i = 0; $i < count($queue); $i++) {
            [$personId, $generation, $path] = $queue[$i];
            $recordPath((int) $personId, (int) $generation, $path, 1);

            $familyId = $personChildFamily[(int) $personId] ?? null;
            if (! $familyId || ! isset($familyParents[$familyId])) {
                continue;
            }

            foreach (['husband_id', 'wife_id'] as $parentField) {
                $parentId = $familyParents[$familyId][$parentField] ?? null;
                if (! $parentId || isset($visited[$parentId])) {
                    continue;
                }
                $visited[$parentId] = true;
                $directAncestors[$parentId] = $generation + 1;
                $parentPath = array_merge($path, [$parentId]);
                $directAncestorPaths[$parentId] = $parentPath;
                $queue[] = [$parentId, $generation + 1, $parentPath];
            }
        }

        // Phase 2: mark siblings of direct ancestors (Tier 2)
        // For each direct ancestor, find all children of the same family that are NOT direct ancestors
        $collateralSeeds = [];
        foreach (array_keys($directAncestors) as $ancestorId) {
            $familyId = $personChildFamily[$ancestorId] ?? null;
            if (! $familyId || empty($familyChildren[$familyId])) {
                continue;
            }

            $ancestorGen = $directAncestors[$ancestorId];
            $ancestorPath = $directAncestorPaths[$ancestorId] ?? [$ancestorId];
            foreach ($familyChildren[$familyId] as $siblingId) {
                if ($siblingId === $ancestorId || isset($directAncestors[$siblingId])) {
                    continue; // already a direct ancestor
                }

                $siblingPath = $this->buildCollateralSiblingPath($ancestorPath, $siblingId, $rootPersonId);
                $recordPath($siblingId, $ancestorGen, $siblingPath, 2);
                $collateralSeeds[$siblingId] = [
                    'person_id' => $siblingId,
                    'generation' => $ancestorGen,
                    'path' => $siblingPath,
                ];
            }
        }

        // Phase 3: propagate useful tier-3 paths down from tier-2 blood-collateral siblings.
        // This walks only child links from the blood-relative seed and does not include spouses.
        $descendantQueue = array_values($collateralSeeds);
        $visitedCollateral = [];
        foreach ($collateralSeeds as $seedId => $seed) {
            $visitedCollateral[(int) $seedId] = true;
        }

        for ($i = 0; $i < count($descendantQueue); $i++) {
            $current = $descendantQueue[$i];
            $parentId = (int) $current['person_id'];
            $parentGeneration = (int) $current['generation'];
            $parentPath = array_values(array_map('intval', $current['path']));

            foreach ($parentFamilies[$parentId] ?? [] as $familyId) {
                foreach ($familyChildren[$familyId] ?? [] as $childId) {
                    if ($childId <= 0 || isset($directAncestors[$childId]) || isset($visitedCollateral[$childId])) {
                        continue;
                    }

                    $childGeneration = $parentGeneration + 1;
                    $childPath = array_merge($parentPath, [$childId]);
                    $visitedCollateral[$childId] = true;
                    $recordPath($childId, $childGeneration, $childPath, 3);

                    $descendantQueue[] = [
                        'person_id' => $childId,
                        'generation' => $childGeneration,
                        'path' => $childPath,
                    ];
                }
            }
        }

        foreach (array_chunk(array_values($pathRows), 500) as $chunk) {
            DB::table('genealogy_ancestor_paths')->insert($chunk);
        }

        return count($pathRows);
    }

    /**
     * Build a root-context path for a sibling of a direct ancestor.
     *
     * The sibling is at the same generation as the direct ancestor, so the final
     * direct ancestor node is replaced with the sibling. This preserves enough
     * root-side context for cousin-branch work without pretending the sibling is
     * on the direct ancestor chain.
     *
     * @param  list<int>  $ancestorPath
     * @return list<int>
     */
    private function buildCollateralSiblingPath(array $ancestorPath, int $siblingId, int $rootPersonId): array
    {
        $path = array_values(array_map('intval', $ancestorPath));
        if ($path === []) {
            return [$rootPersonId, $siblingId];
        }

        if (count($path) === 1) {
            return [(int) $path[0], $siblingId];
        }

        array_pop($path);
        $path[] = $siblingId;

        return $path;
    }

    /**
     * Refresh the research priority score for every person in a tree.
     *
     * Priority formula:
     *   tier_weight  = Tier1→1.0, Tier2→0.6, Tier3→0.3, Tier4→0.1
     *   staleness    = MIN(days_since_last_search / 180, 1.0) — 1.0 if never searched
     *   base_score   = (tier_weight × 0.50) + (data_gap × 0.30) + (staleness × 0.20)
     *   priority     = base_score × (1 − exhaustion_score × 0.50)
     *
     * Data gap: fraction of (birth_date, birth_place, death_date, death_place) that are NULL.
     * Exhaustion: fraction of recent searches (90 days) that returned negative results.
     *
     * @return int Number of coverage rows upserted
     */
    public function refreshPersonCoverage(int $treeId): int
    {
        $persons = DB::select(
            'SELECT id, birth_date, birth_place, death_date, death_place
             FROM genealogy_persons WHERE tree_id = ?',
            [$treeId]
        );

        DB::delete(
            'DELETE c
             FROM genealogy_person_coverage c
             LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = c.tree_id
             WHERE c.tree_id = ? AND p.id IS NULL',
            [$treeId]
        );

        // Pre-load ancestor paths for tier/generation lookup
        $paths = DB::select(
            'SELECT ancestor_id, generation, bloodline_tier
             FROM genealogy_ancestor_paths WHERE tree_id = ?',
            [$treeId]
        );
        $tierMap = [];
        $genMap = [];
        foreach ($paths as $p) {
            $tierMap[$p->ancestor_id] = (int) $p->bloodline_tier;
            $genMap[$p->ancestor_id] = (int) $p->generation;
        }

        // Pre-load recent search stats (90 days)
        $searchStats = DB::select('
            SELECT l.person_id,
                   COUNT(*) AS search_count,
                   SUM(l.negative_result) AS negative_count,
                   MAX(l.searched_at) AS last_searched_at,
                   SUM(CASE WHEN l.searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS count_30d,
                   SUM(CASE WHEN l.searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND l.negative_result = 1 THEN 1 ELSE 0 END) AS neg_30d
            FROM gps_research_logs l
            JOIN gps_research_tasks t ON t.id = l.task_id
            WHERE t.tree_id = ?
              AND l.searched_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY l.person_id
        ', [$treeId]);
        $searchMap = [];
        foreach ($searchStats as $s) {
            $searchMap[$s->person_id] = $s;
        }

        // Pre-load pending hint counts
        $hintStats = DB::select(
            "SELECT person_id, COUNT(*) AS cnt
             FROM genealogy_research_hints
             WHERE tree_id = ? AND status = 'pending'
             GROUP BY person_id",
            [$treeId]
        );
        $hintMap = [];
        foreach ($hintStats as $h) {
            $hintMap[$h->person_id] = (int) $h->cnt;
        }

        $tierWeights = [1 => 1.0, 2 => 0.6, 3 => 0.3, 4 => 0.1];
        $upserted = 0;
        $now = now()->toDateTimeString();
        $coverageRows = [];

        foreach ($persons as $person) {
            $pid = $person->id;

            // Bloodline tier (default 3 = collateral if not in paths, 4 if married-in only is handled separately)
            $tier = $tierMap[$pid] ?? 3;
            $gen = $genMap[$pid] ?? null;
            $tierWeight = $tierWeights[$tier] ?? 0.1;

            // Data gap score (4 key fields)
            $missingCount = 0;
            if (empty($person->birth_date)) {
                $missingCount++;
            }
            if (empty($person->birth_place)) {
                $missingCount++;
            }
            if (empty($person->death_date)) {
                $missingCount++;
            }
            if (empty($person->death_place)) {
                $missingCount++;
            }
            $gapScore = $missingCount / 4.0;

            // Search stats
            $stats = $searchMap[$pid] ?? null;
            $searchCount = $stats ? (int) $stats->search_count : 0;
            $negCount = $stats ? (int) $stats->negative_count : 0;
            $count30d = $stats ? (int) $stats->count_30d : 0;
            $neg30d = $stats ? (int) $stats->neg_30d : 0;
            $lastSearched = $stats ? $stats->last_searched_at : null;

            // Staleness: 1.0 if never searched, decreases as search recency increases
            if (! $lastSearched) {
                $staleness = 1.0;
            } else {
                $daysSince = (int) ceil((time() - strtotime($lastSearched)) / 86400);
                $staleness = min($daysSince / 180.0, 1.0);
            }

            // Exhaustion: fraction of recent searches that were negative
            $exhaustion = ($searchCount > 0) ? min($negCount / max($searchCount, 1), 1.0) : 0.0;

            // Priority score
            $baseScore = ($tierWeight * 0.50) + ($gapScore * 0.30) + ($staleness * 0.20);
            $priorityScore = $baseScore * (1.0 - ($exhaustion * 0.50));

            $coverageRows[] = [
                'tree_id' => $treeId,
                'person_id' => $pid,
                'bloodline_tier' => $tier,
                'generation_distance' => $gen,
                'data_gap_score' => round($gapScore, 3),
                'research_exhaustion_score' => round($exhaustion, 3),
                'pending_hint_count' => $hintMap[$pid] ?? 0,
                'last_searched_at' => $lastSearched,
                'search_count_30d' => $count30d,
                'negative_count_30d' => $neg30d,
                'priority_score' => round($priorityScore, 4),
                'coverage_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $upserted++;
        }

        foreach (array_chunk($coverageRows, 500) as $chunk) {
            DB::table('genealogy_person_coverage')->upsert(
                $chunk,
                ['tree_id', 'person_id'],
                [
                    'bloodline_tier',
                    'generation_distance',
                    'data_gap_score',
                    'research_exhaustion_score',
                    'pending_hint_count',
                    'last_searched_at',
                    'search_count_30d',
                    'negative_count_30d',
                    'priority_score',
                    'coverage_updated_at',
                    'updated_at',
                ]
            );
        }

        // Update priority_rank within tree using window function
        DB::statement('
            UPDATE genealogy_person_coverage c
            JOIN (
                SELECT person_id,
                       ROW_NUMBER() OVER (PARTITION BY tree_id ORDER BY priority_score DESC) AS rnk
                FROM genealogy_person_coverage
                WHERE tree_id = ?
            ) ranked ON ranked.person_id = c.person_id
            SET c.priority_rank = ranked.rnk
            WHERE c.tree_id = ?
        ', [$treeId, $treeId]);

        return $upserted;
    }

    /**
     * Get a high-level research landscape for a tree.
     *
     * Returns aggregate coverage data: how many persons have been researched
     * recently, which surnames appear most, birth-era distribution, pending
     * hint counts, and research task health. The agent uses this to decide
     * who to research next without needing hardcoded names.
     */
    public function getResearchLandscape(int $treeId): array
    {
        // Surname distribution across tree
        $surnames = DB::select("
            SELECT surname, COUNT(*) AS count
            FROM genealogy_persons
            WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
            GROUP BY surname
            ORDER BY count DESC
            LIMIT 20
        ", [$treeId]);

        // Birth era distribution (century buckets)
        $eras = DB::select("
            SELECT
                CASE
                    WHEN CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED) < 1700 THEN 'pre-1700 (colonial)'
                    WHEN CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED) < 1800 THEN '1700-1799 (revolutionary)'
                    WHEN CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED) < 1900 THEN '1800-1899 (civil-war era)'
                    ELSE '1900+ (modern)'
                END AS era,
                COUNT(*) AS count
            FROM genealogy_persons
            WHERE tree_id = ? AND birth_date IS NOT NULL AND birth_date REGEXP '^[0-9]{4}'
            GROUP BY era
            ORDER BY MIN(CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED))
        ", [$treeId]);

        // Research activity last 30 days (persons researched)
        $recentActivity = DB::select("
            SELECT
                p.id AS person_id,
                CONCAT(p.given_name, ' ', p.surname) AS person_name,
                COUNT(l.id) AS search_count,
                SUM(l.negative_result) AS negative_count,
                MAX(l.searched_at) AS last_searched_at
            FROM gps_research_logs l
            JOIN gps_research_tasks t ON t.id = l.task_id
            JOIN genealogy_persons p ON p.id = l.person_id
            WHERE t.tree_id = ?
              AND l.searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY p.id, p.given_name, p.surname
            ORDER BY last_searched_at DESC
            LIMIT 20
        ", [$treeId]);

        // Persons with NO research history (never searched)
        $unsearched = DB::selectOne('
            SELECT COUNT(*) AS count
            FROM genealogy_persons p
            WHERE p.tree_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM gps_research_tasks t
                  JOIN gps_research_logs l ON l.task_id = t.id
                  WHERE t.tree_id = ? AND l.person_id = p.id
              )
        ', [$treeId, $treeId]);

        // Pending hints by status
        $hints = DB::select('
            SELECT status, COUNT(*) AS count
            FROM genealogy_research_hints
            WHERE tree_id = ?
            GROUP BY status
        ', [$treeId]);

        $hintSummary = [];
        foreach ($hints as $h) {
            $hintSummary[$h->status] = (int) $h->count;
        }

        // Persons missing key data (no birth date or no death date for non-living)
        $missingKey = DB::selectOne("
            SELECT
                SUM(CASE WHEN birth_date IS NULL OR birth_date = '' THEN 1 ELSE 0 END) AS missing_birth,
                SUM(CASE WHEN death_date IS NULL OR death_date = '' THEN 1 ELSE 0 END) AS missing_death,
                SUM(CASE WHEN birth_place IS NULL OR birth_place = '' THEN 1 ELSE 0 END) AS missing_birth_place
            FROM genealogy_persons
            WHERE tree_id = ?
        ", [$treeId]);

        return [
            'tree_id' => $treeId,
            'surname_distribution' => $surnames,
            'birth_era_distribution' => $eras,
            'recently_researched' => $recentActivity,
            'persons_never_searched' => (int) ($unsearched->count ?? 0),
            'hint_summary' => $hintSummary,
            'data_gaps' => [
                'missing_birth_date' => (int) ($missingKey->missing_birth ?? 0),
                'missing_death_date' => (int) ($missingKey->missing_death ?? 0),
                'missing_birth_place' => (int) ($missingKey->missing_birth_place ?? 0),
            ],
        ];
    }

    /**
     * Get priority-ranked persons for the agent to research next.
     *
     * Returns persons from genealogy_person_coverage ordered by priority_score DESC,
     * with tier, exhaustion, staleness, data gaps, and hint counts. The agent uses
     * this to select research targets instead of picking from unranked list_persons.
     *
     * Filters out exhausted persons (exhaustion >= 0.90 AND searched in last 30 days)
     * and persons whose pending hints are all deferred, unless explicitly requested.
     *
     * @param  int  $limit  Max persons to return (default 20)
     * @param  int|null  $tier  Filter to specific bloodline tier (1-4), null = all tiers
     * @param  bool  $includeExhausted  Include persons with high exhaustion scores (default false)
     */
    public function getPriorityPersons(int $treeId, int $limit = 20, ?int $tier = null, bool $includeExhausted = false): array
    {
        // Main query: join coverage with persons for name/dates context
        $params = [$treeId];
        $tierFilter = '';
        if ($tier !== null) {
            $tierFilter = 'AND c.bloodline_tier = ?';
            $params[] = $tier;
        }

        $exhaustionFilter = '';
        if (! $includeExhausted) {
            // Skip persons with >= 90% negative searches AND searched within last 30 days
            $exhaustionFilter = 'AND NOT (c.research_exhaustion_score >= 0.90 AND c.last_searched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))';
        }

        $params[] = $limit;

        $persons = DB::select("
            SELECT
                c.person_id,
                p.given_name,
                p.surname,
                p.sex,
                p.birth_date,
                p.birth_place,
                p.death_date,
                p.death_place,
                c.bloodline_tier,
                c.generation_distance,
                c.priority_score,
                c.priority_rank,
                c.data_gap_score,
                c.research_exhaustion_score,
                c.pending_hint_count,
                c.last_searched_at,
                c.search_count_30d,
                c.negative_count_30d,
                c.coverage_updated_at
            FROM genealogy_person_coverage c
            JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = c.tree_id
            WHERE c.tree_id = ?
              {$tierFilter}
              {$exhaustionFilter}
            ORDER BY c.priority_score DESC, c.priority_rank ASC
            LIMIT ?
        ", $params);

        // Enrich with hint status summary per person (deferred vs pending)
        $personIds = array_map(fn ($p) => $p->person_id, $persons);
        $hintMap = [];
        if (! empty($personIds)) {
            $placeholders = implode(',', array_fill(0, count($personIds), '?'));
            $hintRows = DB::select("
                SELECT person_id, status, COUNT(*) AS cnt
                FROM genealogy_research_hints
                WHERE tree_id = ? AND person_id IN ({$placeholders})
                GROUP BY person_id, status
            ", array_merge([$treeId], $personIds));

            foreach ($hintRows as $h) {
                $hintMap[$h->person_id][$h->status] = (int) $h->cnt;
            }
        }

        // Count tier distribution for context
        $tierCounts = DB::select('
            SELECT bloodline_tier, COUNT(*) AS count
            FROM genealogy_person_coverage
            WHERE tree_id = ?
            GROUP BY bloodline_tier
            ORDER BY bloodline_tier
        ', [$treeId]);

        $result = [];
        foreach ($persons as $p) {
            $hints = $hintMap[$p->person_id] ?? [];
            $allDeferred = ! empty($hints) && count($hints) === 1 && isset($hints['deferred']);

            $result[] = [
                'person_id' => (int) $p->person_id,
                'name' => trim(($p->given_name ?? '').' '.($p->surname ?? '')),
                'sex' => $p->sex,
                'birth_date' => $p->birth_date,
                'birth_place' => $p->birth_place,
                'death_date' => $p->death_date,
                'death_place' => $p->death_place,
                'bloodline_tier' => (int) $p->bloodline_tier,
                'tier_label' => match ((int) $p->bloodline_tier) {
                    1 => 'direct ancestor',
                    2 => 'sibling/child of ancestor',
                    3 => 'collateral relative',
                    4 => 'married-in',
                    default => 'unknown',
                },
                'generation_distance' => $p->generation_distance !== null ? (int) $p->generation_distance : null,
                'priority_score' => (float) $p->priority_score,
                'priority_rank' => (int) $p->priority_rank,
                'data_gap_score' => (float) $p->data_gap_score,
                'research_exhaustion' => (float) $p->research_exhaustion_score,
                'pending_hints' => (int) $p->pending_hint_count,
                'hint_statuses' => $hints,
                'all_hints_deferred' => $allDeferred,
                'last_searched_at' => $p->last_searched_at,
                'searches_30d' => (int) $p->search_count_30d,
                'negative_searches_30d' => (int) $p->negative_count_30d,
            ];
        }

        return [
            'tree_id' => $treeId,
            'tier_distribution' => $tierCounts,
            'persons' => $result,
            'total_returned' => count($result),
            'filters_applied' => [
                'tier' => $tier,
                'include_exhausted' => $includeExhausted,
                'limit' => $limit,
            ],
        ];
    }
}
