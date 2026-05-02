<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AIService;

/**
 * Service for AI-powered backfilling of genealogy data relationships
 *
 * Handles:
 * - Auto-linking media to sources based on title/filename matching
 * - Creating citations from GEDCOM source references
 * - AI analysis of media content for source extraction
 * - Linking sources to persons by title/name matching
 */
class GenealogyBackfillService
{
    protected ?AIService $aiService = null;

    public function __construct()
    {
        // AIService is optional - some operations work without it
        try {
            $this->aiService = app(AIService::class);
        } catch (\Exception $e) {
            Log::warning('AIService not available for backfill operations');
        }
    }

    /**
     * Run all backfill operations for a tree
     */
    public function backfillAll(int $treeId, bool $dryRun = false): array
    {
        $results = [
            'media_source_links' => $this->linkMediaToSources($treeId, $dryRun),
            'source_person_links' => $this->linkSourcesToPersonsByTitle($treeId, $dryRun),
            'citations_created' => $this->createCitationsFromGedcom($treeId, $dryRun),
        ];

        return $results;
    }

    /**
     * Link media to sources based on title/filename matching
     *
     * Matches patterns like:
     * - "1920 Census - John Smith" -> "1920 United States Federal Census"
     * - "Birth Certificate Smith" -> sources with "Birth" in title
     * - "Findagrave John Doe" -> FindAGrave source
     */
    public function linkMediaToSources(int $treeId, bool $dryRun = false): array
    {
        $results = ['matched' => 0, 'skipped' => 0, 'details' => []];

        // Get all sources for this tree
        $sources = DB::select("SELECT id, title, gedcom_id FROM genealogy_sources WHERE tree_id = ?", [$treeId]);
        if (empty($sources)) {
            return $results;
        }

        // Build source matching patterns
        $sourcePatterns = $this->buildSourcePatterns($sources);

        // Get all media for this tree
        $mediaItems = DB::select("
            SELECT id, title, original_path, gedcom_id
            FROM genealogy_media
            WHERE tree_id = ?
        ", [$treeId]);

        foreach ($mediaItems as $media) {
            $searchText = strtolower(($media->title ?? '') . ' ' . ($media->original_path ?? ''));

            foreach ($sourcePatterns as $pattern) {
                if ($this->matchesPattern($searchText, $pattern['patterns'])) {
                    // Check if citation already exists
                    $existing = DB::selectOne("
                        SELECT id FROM genealogy_citations
                        WHERE source_id = ? AND media_id = ?
                    ", [$pattern['source_id'], $media->id]);

                    if (!$existing) {
                        if (!$dryRun) {
                            DB::insert("
                                INSERT INTO genealogy_citations (source_id, media_id, fact_type, created_at)
                                VALUES (?, ?, 'media', NOW())
                            ", [$pattern['source_id'], $media->id]);
                        }
                        $results['matched']++;
                        $results['details'][] = [
                            'media_id' => $media->id,
                            'media_title' => $media->title,
                            'source_id' => $pattern['source_id'],
                            'source_title' => $pattern['title'],
                        ];
                    } else {
                        $results['skipped']++;
                    }
                    break; // Only link to first matching source
                }
            }
        }

        return $results;
    }

    /**
     * Build search patterns from source titles
     */
    protected function buildSourcePatterns(array $sources): array
    {
        $patterns = [];

        // Common genealogy source patterns
        $commonPatterns = [
            'census' => ['census', 'enumeration'],
            'birth' => ['birth', 'born', 'nativity'],
            'death' => ['death', 'died', 'mortality', 'obituary', 'obit'],
            'marriage' => ['marriage', 'married', 'wedding', 'matrimony'],
            'findagrave' => ['findagrave', 'find a grave', 'find-a-grave', 'grave'],
            'ancestry' => ['ancestry', 'family tree'],
            'military' => ['military', 'draft', 'enlistment', 'veteran', 'war'],
            'immigration' => ['immigration', 'passenger', 'naturalization', 'ship'],
            'newspaper' => ['newspaper', 'news', 'clipping'],
        ];

        foreach ($sources as $source) {
            $title = strtolower($source->title ?? '');
            $sourcePatterns = [$title];

            // Add year patterns (1920, 1930, etc.)
            if (preg_match('/\b(1[89]\d{2}|20[0-2]\d)\b/', $title, $yearMatch)) {
                $sourcePatterns[] = $yearMatch[1];
            }

            // Add common pattern matches
            foreach ($commonPatterns as $key => $keywords) {
                foreach ($keywords as $keyword) {
                    if (stripos($title, $keyword) !== false) {
                        $sourcePatterns = array_merge($sourcePatterns, $keywords);
                        break;
                    }
                }
            }

            // Extract significant words (3+ chars, not common words)
            $stopWords = ['the', 'and', 'for', 'with', 'from', 'united', 'states', 'federal'];
            $words = preg_split('/\s+/', $title);
            foreach ($words as $word) {
                $word = preg_replace('/[^a-z0-9]/', '', strtolower($word));
                if (strlen($word) >= 4 && !in_array($word, $stopWords)) {
                    $sourcePatterns[] = $word;
                }
            }

            $patterns[] = [
                'source_id' => $source->id,
                'title' => $source->title,
                'patterns' => array_unique($sourcePatterns),
            ];
        }

        return $patterns;
    }

    /**
     * Check if text matches any of the patterns (requires 2+ matches for reliability)
     */
    protected function matchesPattern(string $text, array $patterns): bool
    {
        $matchCount = 0;
        foreach ($patterns as $pattern) {
            if (!empty($pattern) && stripos($text, $pattern) !== false) {
                $matchCount++;
            }
        }
        return $matchCount >= 2; // Require at least 2 pattern matches
    }

    /**
     * Link sources to persons based on title matching
     *
     * Looks for person names in source titles and creates links
     */
    public function linkSourcesToPersonsByTitle(int $treeId, bool $dryRun = false): array
    {
        $results = ['linked' => 0, 'skipped' => 0, 'details' => []];

        // Get all persons with their names
        $persons = DB::select("
            SELECT id, given_name, surname
            FROM genealogy_persons
            WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
        ", [$treeId]);

        // Get all sources for this tree
        $sources = DB::select("SELECT id, title FROM genealogy_sources WHERE tree_id = ?", [$treeId]);

        foreach ($sources as $source) {
            $sourceTitle = strtolower($source->title ?? '');
            if (empty($sourceTitle)) continue;

            foreach ($persons as $person) {
                $surname = strtolower($person->surname ?? '');
                $givenName = strtolower($person->given_name ?? '');

                if (empty($surname)) continue;

                // Check if both surname and given name appear in source title
                $surnameMatch = stripos($sourceTitle, $surname) !== false;
                $givenMatch = !empty($givenName) && stripos($sourceTitle, $givenName) !== false;

                if ($surnameMatch && $givenMatch) {
                    // Check if link already exists
                    $existing = DB::selectOne("
                        SELECT id FROM genealogy_person_sources
                        WHERE person_id = ? AND source_id = ?
                    ", [$person->id, $source->id]);

                    if (!$existing) {
                        if (!$dryRun) {
                            DB::insert("
                                INSERT INTO genealogy_person_sources (person_id, source_id, created_at)
                                VALUES (?, ?, NOW())
                            ", [$person->id, $source->id]);
                        }
                        $results['linked']++;
                        $results['details'][] = [
                            'person_id' => $person->id,
                            'person_name' => "{$person->given_name} {$person->surname}",
                            'source_id' => $source->id,
                            'source_title' => $source->title,
                        ];
                    } else {
                        $results['skipped']++;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Create citations from GEDCOM source references
     *
     * Re-parses GEDCOM to extract SOUR refs within events and creates citations
     */
    public function createCitationsFromGedcom(int $treeId, bool $dryRun = false): array
    {
        $results = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        // Get the tree's source file
        $tree = DB::selectOne("SELECT source_file FROM genealogy_trees WHERE id = ?", [$treeId]);
        if (!$tree || empty($tree->source_file)) {
            $results['errors']++;
            $results['details'][] = 'No GEDCOM source file found for tree';
            return $results;
        }

        // Check if file exists - source_file may be a full path or relative
        $filePath = $tree->source_file;
        if (!str_starts_with($filePath, '/')) {
            $filePath = storage_path('app/' . $filePath);
        }
        if (!file_exists($filePath)) {
            $results['errors']++;
            $results['details'][] = "GEDCOM file not found: {$filePath}";
            return $results;
        }

        // Parse GEDCOM for source references in events
        try {
            $parser = new GedcomParserService($filePath);
            $data = $parser->parse();

            // Build lookup maps
            $personIdMap = $this->buildGedcomIdMap('genealogy_persons', $treeId);
            $sourceIdMap = $this->buildGedcomIdMap('genealogy_sources', $treeId);

            // Process person events with source refs
            foreach ($data['persons'] as $gedcomId => $person) {
                $personId = $personIdMap[$gedcomId] ?? null;
                if (!$personId) continue;

                // Check person-level sources
                foreach ($person['sources'] ?? [] as $sourceRef) {
                    $sourceGedcomId = $this->extractGedcomId($sourceRef);
                    $sourceId = $sourceIdMap[$sourceGedcomId] ?? null;
                    if (!$sourceId) continue;

                    $created = $this->createCitationIfNotExists($sourceId, $personId, null, null, 'person', $dryRun);
                    if ($created) {
                        $results['created']++;
                    } else {
                        $results['skipped']++;
                    }
                }

                // Check events for source refs
                foreach ($person['events'] ?? [] as $event) {
                    foreach ($event['sources'] ?? [] as $sourceRef) {
                        $sourceGedcomId = $this->extractGedcomId($sourceRef);
                        $sourceId = $sourceIdMap[$sourceGedcomId] ?? null;
                        if (!$sourceId) continue;

                        $factType = $event['type'] ?? 'event';
                        $created = $this->createCitationIfNotExists($sourceId, $personId, null, null, $factType, $dryRun);
                        if ($created) {
                            $results['created']++;
                        } else {
                            $results['skipped']++;
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $results['errors']++;
            $results['details'][] = "GEDCOM parse error: {$e->getMessage()}";
        }

        return $results;
    }

    /**
     * Build a map of GEDCOM IDs to database IDs
     */
    protected function buildGedcomIdMap(string $table, int $treeId): array
    {
        $records = DB::select("SELECT id, gedcom_id FROM {$table} WHERE tree_id = ?", [$treeId]);
        $map = [];
        foreach ($records as $record) {
            $map[$record->gedcom_id] = $record->id;
        }
        return $map;
    }

    /**
     * Extract GEDCOM ID from a reference string like @S123@
     */
    protected function extractGedcomId(string $ref): string
    {
        return preg_replace('/^@|@$/', '', $ref);
    }

    /**
     * Create a citation if it doesn't already exist
     */
    protected function createCitationIfNotExists(
        int $sourceId,
        ?int $personId,
        ?int $familyId,
        ?int $mediaId,
        string $factType,
        bool $dryRun
    ): bool {
        // Check for existing citation
        $existing = DB::selectOne("
            SELECT id FROM genealogy_citations
            WHERE source_id = ?
              AND (person_id = ? OR (person_id IS NULL AND ? IS NULL))
              AND (family_id = ? OR (family_id IS NULL AND ? IS NULL))
              AND (media_id = ? OR (media_id IS NULL AND ? IS NULL))
              AND fact_type = ?
        ", [$sourceId, $personId, $personId, $familyId, $familyId, $mediaId, $mediaId, $factType]);

        if ($existing) {
            return false;
        }

        if (!$dryRun) {
            DB::insert("
                INSERT INTO genealogy_citations (source_id, person_id, family_id, media_id, fact_type, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ", [$sourceId, $personId, $familyId, $mediaId, $factType]);
        }

        return true;
    }

    /**
     * Use AI to analyze media content and extract source information
     *
     * This uses OCR/Vision AI to read document images and extract:
     * - Document type (census, certificate, etc.)
     * - Names mentioned
     * - Dates
     * - Locations
     */
    public function analyzeMediaWithAI(int $mediaId, bool $dryRun = false): array
    {
        $results = ['analyzed' => false, 'extracted' => [], 'error' => null];

        if (!$this->aiService) {
            $results['error'] = 'AI Service not available';
            return $results;
        }

        // Get media details
        $media = DB::selectOne("
            SELECT id, title, nextcloud_path, local_filename, media_type, tree_id
            FROM genealogy_media WHERE id = ?
        ", [$mediaId]);

        if (!$media || !$media->nextcloud_path) {
            $results['error'] = 'Media not found or no file path';
            return $results;
        }

        // TODO: Implement AI vision analysis
        // This would:
        // 1. Load the image file
        // 2. Send to AI vision model (Ollama, OpenAI, etc.)
        // 3. Extract document type, names, dates
        // 4. Create/link sources and citations

        $results['analyzed'] = true;
        $results['extracted'] = [
            'status' => 'AI analysis not yet implemented',
            'media_id' => $mediaId,
        ];

        return $results;
    }

    /**
     * Get statistics about current source/media linkage
     */
    public function getBackfillStats(int $treeId): array
    {
        $stats = [];

        // Sources
        $stats['sources_total'] = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM genealogy_sources WHERE tree_id = ?", [$treeId]
        )->cnt;

        // Person-source links
        $stats['person_source_links'] = DB::selectOne("
            SELECT COUNT(*) as cnt FROM genealogy_person_sources ps
            JOIN genealogy_persons p ON p.id = ps.person_id
            WHERE p.tree_id = ?
        ", [$treeId])->cnt;

        // Media
        $stats['media_total'] = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM genealogy_media WHERE tree_id = ?", [$treeId]
        )->cnt;

        // Citations
        $stats['citations_total'] = DB::selectOne("
            SELECT COUNT(*) as cnt FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            WHERE s.tree_id = ?
        ", [$treeId])->cnt;

        // Citations with media
        $stats['citations_with_media'] = DB::selectOne("
            SELECT COUNT(*) as cnt FROM genealogy_citations c
            JOIN genealogy_sources s ON s.id = c.source_id
            WHERE s.tree_id = ? AND c.media_id IS NOT NULL
        ", [$treeId])->cnt;

        // Persons with sources
        $stats['persons_with_sources'] = DB::selectOne("
            SELECT COUNT(DISTINCT ps.person_id) as cnt FROM genealogy_person_sources ps
            JOIN genealogy_persons p ON p.id = ps.person_id
            WHERE p.tree_id = ?
        ", [$treeId])->cnt;

        $stats['persons_total'] = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM genealogy_persons WHERE tree_id = ?", [$treeId]
        )->cnt;

        return $stats;
    }
}
