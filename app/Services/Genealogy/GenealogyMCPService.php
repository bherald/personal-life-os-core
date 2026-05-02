<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Genealogy MCP Service
 *
 * MCP wrapper for genealogy operations, enabling AI orchestration
 * of family tree research and data management.
 *
 * Tools provided (6):
 * - gedcom_parse: Parse GEDCOM file -> structured data
 * - gedcom_export: Export tree -> GEDCOM string
 * - tree_search: Search persons/families/sources
 * - person_research: AI research suggestions
 * - tree_stats: Get tree statistics
 * - source_extract: Extract source citations with URLs
 *
 * Uses RAW SQL queries - NO Eloquent models
 */
class GenealogyMCPService
{
    private GenealogyService $genealogy;
    private GedcomExportService $exporter;
    private ?GenealogyAIResearchService $aiResearch = null;

    public function __construct(
        GenealogyService $genealogy,
        GedcomExportService $exporter
    ) {
        $this->genealogy = $genealogy;
        $this->exporter = $exporter;
    }

    /**
     * Parse a GEDCOM file and return structured data
     *
     * @param string $file_path Path to GEDCOM file (absolute or in storage/app/genealogy/)
     * @param bool $preview_only If true, return stats only without full person data
     * @return array Parsed genealogy data
     */
    public function gedcom_parse(string $file_path, bool $preview_only = false): array
    {
        Log::info('GenealogyMCPService: gedcom_parse called', [
            'file_path' => $file_path,
            'preview_only' => $preview_only,
        ]);

        // Handle relative paths
        if (!str_starts_with($file_path, '/')) {
            $file_path = storage_path("app/genealogy/{$file_path}");
        }

        if (!file_exists($file_path)) {
            return [
                'tool' => 'gedcom_parse',
                'success' => false,
                'error' => "File not found: {$file_path}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $parser = new GedcomParserService($file_path);
            $result = $parser->parse();

            $response = [
                'tool' => 'gedcom_parse',
                'success' => true,
                'file' => basename($file_path),
                'stats' => $result['stats'],
                'header' => $result['header'],
                'timestamp' => now()->toIso8601String(),
            ];

            if (!$preview_only) {
                // Limit data size for MCP response
                $response['persons'] = array_slice($result['persons'], 0, 100);
                $response['families'] = array_slice($result['families'], 0, 50);
                $response['sources'] = array_slice($result['sources'], 0, 50);
                $response['truncated'] = count($result['persons']) > 100;
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'tool' => 'gedcom_parse',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Export a tree to GEDCOM format
     *
     * @param int $tree_id Tree ID to export
     * @param bool $include_living Include living persons (privacy consideration)
     * @param bool $include_media Include media object references
     * @return array GEDCOM content and metadata
     */
    public function gedcom_export(
        int $tree_id,
        bool $include_living = false,
        bool $include_media = true
    ): array {
        Log::info('GenealogyMCPService: gedcom_export called', [
            'tree_id' => $tree_id,
            'include_living' => $include_living,
        ]);

        try {
            $gedcom = $this->exporter->exportTree($tree_id, null, [
                'include_living' => $include_living,
                'include_media' => $include_media,
            ]);

            // Get tree info
            $tree = $this->genealogy->getTree($tree_id);

            return [
                'tool' => 'gedcom_export',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? 'Unknown',
                'gedcom' => $gedcom,
                'size_bytes' => strlen($gedcom),
                'line_count' => substr_count($gedcom, "\n"),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'gedcom_export',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Search the genealogy database
     *
     * @param string $query Search query
     * @param string $type Type to search: 'person', 'family', 'source', 'all'
     * @param int|null $tree_id Limit to specific tree
     * @param int $limit Maximum results per type
     * @return array Search results
     */
    public function tree_search(
        string $query,
        string $type = 'all',
        ?int $tree_id = null,
        int $limit = 20
    ): array {
        Log::info('GenealogyMCPService: tree_search called', [
            'query' => $query,
            'type' => $type,
            'tree_id' => $tree_id,
        ]);

        $results = [
            'tool' => 'tree_search',
            'success' => true,
            'query' => $query,
            'type' => $type,
            'timestamp' => now()->toIso8601String(),
        ];

        $queryParam = "%{$query}%";

        if ($type === 'person' || $type === 'all') {
            $sql = "SELECT id, tree_id, given_name, surname, birth_date, death_date, sex
                    FROM genealogy_persons
                    WHERE (given_name LIKE ? OR surname LIKE ? OR CONCAT(given_name, ' ', surname) LIKE ?)";
            $params = [$queryParam, $queryParam, $queryParam];

            if ($tree_id) {
                $sql .= " AND tree_id = ?";
                $params[] = $tree_id;
            }

            $sql .= " ORDER BY surname, given_name LIMIT ?";
            $params[] = $limit;

            $results['persons'] = DB::select($sql, $params);
        }

        if ($type === 'family' || $type === 'all') {
            // Search families via person names
            $sql = "SELECT DISTINCT f.id, f.tree_id, f.marriage_date, f.marriage_place,
                           h.given_name as husband_given, h.surname as husband_surname,
                           w.given_name as wife_given, w.surname as wife_surname
                    FROM genealogy_families f
                    LEFT JOIN genealogy_persons h ON f.husband_id = h.id
                    LEFT JOIN genealogy_persons w ON f.wife_id = w.id
                    WHERE h.given_name LIKE ? OR h.surname LIKE ?
                       OR w.given_name LIKE ? OR w.surname LIKE ?";
            $params = [$queryParam, $queryParam, $queryParam, $queryParam];

            if ($tree_id) {
                $sql .= " AND f.tree_id = ?";
                $params[] = $tree_id;
            }

            $sql .= " LIMIT ?";
            $params[] = $limit;

            $results['families'] = DB::select($sql, $params);
        }

        if ($type === 'source' || $type === 'all') {
            $sql = "SELECT id, tree_id, title, author, publication AS publication_info, repository AS repository_id
                    FROM genealogy_sources
                    WHERE title LIKE ? OR author LIKE ?";
            $params = [$queryParam, $queryParam];

            if ($tree_id) {
                $sql .= " AND tree_id = ?";
                $params[] = $tree_id;
            }

            $sql .= " ORDER BY title LIMIT ?";
            $params[] = $limit;

            $results['sources'] = DB::select($sql, $params);
        }

        return $results;
    }

    /**
     * Get AI research suggestions for a person
     *
     * @param int $person_id Person ID to research
     * @param string $focus Research focus: 'ancestry', 'descendants', 'siblings', 'general', 'brick_wall'
     * @return array Research suggestions
     */
    public function person_research(int $person_id, string $focus = 'general'): array
    {
        Log::info('GenealogyMCPService: person_research called', [
            'person_id' => $person_id,
            'focus' => $focus,
        ]);

        try {
            // Lazy load AI research service
            if ($this->aiResearch === null) {
                $this->aiResearch = app(GenealogyAIResearchService::class);
            }

            if ($focus === 'brick_wall') {
                $result = $this->aiResearch->suggestResearchForBrickWall($person_id);
            } else {
                $result = $this->aiResearch->researchPerson($person_id, ['focus' => $focus]);
            }

            return array_merge(['tool' => 'person_research', 'timestamp' => now()->toIso8601String()], $result);
        } catch (\Exception $e) {
            return [
                'tool' => 'person_research',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get tree statistics
     *
     * @param int $tree_id Tree ID
     * @return array Tree statistics
     */
    public function tree_stats(int $tree_id): array
    {
        Log::info('GenealogyMCPService: tree_stats called', ['tree_id' => $tree_id]);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (!$tree) {
                return [
                    'tool' => 'tree_stats',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            // Get detailed stats
            $personCount = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?", [$tree_id])->count;
            $familyCount = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_families WHERE tree_id = ?", [$tree_id])->count;
            $sourceCount = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_sources WHERE tree_id = ?", [$tree_id])->count;
            $mediaCount = DB::selectOne("SELECT COUNT(*) as count FROM genealogy_media WHERE tree_id = ?", [$tree_id])->count;

            // Gender breakdown
            $genderStats = DB::select("SELECT sex, COUNT(*) as count FROM genealogy_persons WHERE tree_id = ? GROUP BY sex", [$tree_id]);

            // Date ranges
            $dateRange = DB::selectOne("
                SELECT MIN(birth_date) as earliest_birth, MAX(birth_date) as latest_birth,
                       MIN(death_date) as earliest_death, MAX(death_date) as latest_death
                FROM genealogy_persons WHERE tree_id = ?
            ", [$tree_id]);

            // Generations estimate
            $generations = DB::selectOne("
                SELECT COUNT(DISTINCT generation) as count
                FROM (
                    SELECT FLOOR((YEAR(CURDATE()) - YEAR(birth_date)) / 25) as generation
                    FROM genealogy_persons
                    WHERE tree_id = ? AND birth_date IS NOT NULL
                ) g
            ", [$tree_id])->count ?? 0;

            return [
                'tool' => 'tree_stats',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name,
                'description' => $tree->description,
                'counts' => [
                    'persons' => $personCount,
                    'families' => $familyCount,
                    'sources' => $sourceCount,
                    'media' => $mediaCount,
                ],
                'gender' => collect($genderStats)->pluck('count', 'sex')->toArray(),
                'date_range' => [
                    'earliest_birth' => $dateRange->earliest_birth ?? null,
                    'latest_birth' => $dateRange->latest_birth ?? null,
                    'earliest_death' => $dateRange->earliest_death ?? null,
                    'latest_death' => $dateRange->latest_death ?? null,
                ],
                'estimated_generations' => $generations,
                'created_at' => $tree->created_at ?? null,
                'updated_at' => $tree->updated_at ?? null,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'tree_stats',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Extract source citations with URLs for media download
     *
     * @param int $tree_id Tree ID
     * @param int|null $person_id Optional: limit to specific person
     * @param int $limit Maximum citations to return
     * @return array Source citations with URLs
     */
    public function source_extract(int $tree_id, ?int $person_id = null, int $limit = 50): array
    {
        Log::info('GenealogyMCPService: source_extract called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
        ]);

        try {
            $sql = "
                SELECT gc.id, gc.source_id, gc.page, gc.quality, gc.text,
                       s.title as source_title, s.author, s.publication AS publication_info,
                       m.nextcloud_path as media_path, m.local_filename as media_url,
                       p.id as person_id, p.given_name, p.surname
                FROM genealogy_citations gc
                JOIN genealogy_sources s ON gc.source_id = s.id
                LEFT JOIN genealogy_media m ON gc.media_id = m.id
                LEFT JOIN genealogy_persons p ON gc.person_id = p.id
                WHERE s.tree_id = ?
            ";
            $params = [$tree_id];

            if ($person_id) {
                $sql .= " AND gc.person_id = ?";
                $params[] = $person_id;
            }

            $sql .= " ORDER BY s.title, gc.page LIMIT ?";
            $params[] = $limit;

            $citations = DB::select($sql, $params);

            // Group by source
            $grouped = [];
            foreach ($citations as $c) {
                $sourceId = $c->source_id;
                if (!isset($grouped[$sourceId])) {
                    $grouped[$sourceId] = [
                        'source_id' => $sourceId,
                        'title' => $c->source_title,
                        'author' => $c->author,
                        'publication_info' => $c->publication_info,
                        'citations' => [],
                    ];
                }
                $grouped[$sourceId]['citations'][] = [
                    'page' => $c->page,
                    'quality' => $c->quality,
                    'text' => $c->text,
                    'media_url' => $c->media_url,
                    'media_path' => $c->media_path,
                    'person' => $c->person_id ? "{$c->given_name} {$c->surname}" : null,
                ];
            }

            return [
                'tool' => 'source_extract',
                'success' => true,
                'tree_id' => $tree_id,
                'person_id' => $person_id,
                'sources' => array_values($grouped),
                'total_citations' => count($citations),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_extract',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }
}
