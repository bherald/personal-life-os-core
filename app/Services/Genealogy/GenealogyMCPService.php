<?php

namespace App\Services\Genealogy;

use App\Services\FileRegistryLifecycleService;
use App\Services\Genealogy\SourceAudit\SourceAuditWorkbookService;
use App\Services\Review\ReviewContextEnrichmentService;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Genealogy MCP Service
 *
 * MCP wrapper for genealogy operations, enabling AI orchestration
 * of family tree research and data management.
 *
 * Tools provided (66):
 * - gedcom_parse: Parse GEDCOM file -> structured data
 * - gedcom_export: Export tree -> GEDCOM string
 * - source_audit_workbook: Generate dry-run-first source-audit manifest/CSV/DOCX/ODT package
 * - tree_search: Search persons/families/sources
 * - person_search: Tree-scoped person search
 * - person_profile: Complete read-only person profile
 * - name_variant_add: Add a vetted maiden/married/alias/nickname name variant
 * - family_profile: Complete read-only family profile
 * - person_research: AI research suggestions
 * - health_audit: Read-only health audit over selected sections
 * - health_review_packet: Canonical review packets for health-audit issues
 * - health_audit_memory_batch: Feed health-audit findings/fix policies into semantic memory
 * - review_packet_context: Compact read-only genealogy review-packet context by target ref or token
 * - review_packet_decision: Guarded dry-run-first genealogy review-packet decision wrapper
 * - relationship_audit: Read-only relationship integrity audit
 * - tree_stats: Get tree statistics
 * - tree_status: Aggregated tree readiness/status summary
 * - work_status: Compact work counters for batching
 * - coverage_rebuild: Dry-run-first ancestor path and coverage rebuild
 * - schedule_status: Compact read-only genealogy scheduler monitoring
 * - research_task_queue: Compact read-only research task queue by tree
 * - research_task_profile: Read-only detail for one research task
 * - research_task_create: Dry-run-first creation of guarded genealogy research tasks
 * - source_extract: Extract source citations with URLs
 * - source_profile: Read-only source details, citation targets, and media context
 * - person_source_gap_batch: Read-only ranked people lacking direct person-source rows
 * - source_gap_decision_add: Store dry-run-first source-gap review memory
 * - source_gap_decision_lookup: Read source-gap review memory
 * - media_profile: Read-only media details, links, citations, and face hints
 * - media_review_packet: Compact document/OCR/media evidence packet for review
 * - media_intake_memory_batch: Feed saved media-intake run outcomes into semantic memory
 * - media_ocr_escalation_batch: Compact OCR/HTR/vision escalation candidates
 * - media_htr_batch: Bounded HTR transcription status, dry-run, or confirmed batch
 * - person_fact_extract: Read-only fact/relationship extraction from one evidence packet
 * - media_unlinked: List unlinked media candidates with metadata hints
 * - media_triage_batch: Compact unlinked media triage buckets for low-token batch work
 * - media_review_mark: Store non-destructive review decisions for weak/unresolved unlinked media
 * - media_quarantine: Guarded quarantine/delete for unlinked uncited non-FT media
 * - media_duplicate_consolidate: Guarded consolidation for byte-identical duplicate media
 * - person_media_link_retire: Retire bad person-media links and matching imported-media citations
 * - media_rag_batch: Bounded media RAG indexing command wrapper
 * - rag_index_batch: Bounded person/place/source RAG indexing command wrapper
 * - person_embedding_batch: Bounded person embedding command wrapper
 * - media_link_integrity: Audit/repair deterministic missing person-media links
 * - person_source_link_integrity: Audit/repair deterministic missing person-source links
 * - source_citation_link_apply: Dry-run-first bounded source citation/link creation
 * - evidence_capture_plan: Plan capture-ready review evidence media into FT storage
 * - evidence_capture_review: Materialize operator approval rows for evidence media capture
 * - evidence_capture_execute: Preflight or execute approved evidence media capture
 * - evidence_capture_direct: Dry-run-first one-off vetted evidence media capture into FT storage
 * - source_media_backfill: Dry-run-first source URL media backfill into FT storage
 * - nara_placeholder_capture_batch: Dry-run-first NARA placeholder media capture into FT storage
 * - media_attach_proposal: Safely propose attaching a media row to a person or family
 * - media_identity_apply: Apply an operator-confirmed media/person identity with metadata sync
 * - source_add_proposal: Safely propose a vetted person/source attachment
 * - fact_update_proposal: Safely propose a vetted person fact update
 * - relationship_link_proposal: Safely propose linking two existing people
 * - apply_approved_proposal: Apply an already-approved proposal with dry-run preview
 * - person_fact_apply_batch: Apply approved high-confidence source-backed fact proposals
 * - approve_apply_proposal: Confirm, approve, and apply a proposal through canonical review services
 * - proposal_queue: Read-only tree-scoped proposal review queue
 * - review_decision_memory_batch: Feed accepted/rejected proposal decisions into semantic memory
 * - review_packet_memory_batch: Feed accepted/rejected genealogy review-packet outcomes into semantic memory
 * - memory_backfill_batch: Orchestrate bounded Genea learning-memory backfills by tree
 * - lesson_memory_save: Store reusable Genea research/OCR/source-capture lessons
 * - lesson_memory_lookup: Retrieve compact Genea research/OCR/source-capture lessons
 * - lesson_memory_context: Retrieve compact Genea lessons by person/media/source/task context
 * - rag_status: Get person/media RAG and embedding coverage
 * - export_readiness: Get self-contained export readiness checks
 * - export_standalone_status: Summarize standalone GEDZip/tree-folder portability for one or all trees
 * - duplicate_candidates: List read-only duplicate person candidates
 * - family_duplicate_retire: Delete an isolated duplicate family after strict same-spouse/no-reference checks
 * - person_source_link_retire: Retire uncited invalid person-source links after strict checks
 * - non_ft_name_add: Store tree-scoped rejected/non-FT name memory
 * - non_ft_name_lookup: Search rejected/non-FT name memory
 * - research_memo_save: Save a guarded FT-local research memo and optional source-gap memory
 * - memory_report: Compact report of Genea semantic/procedural/episodic memory
 *
 * Uses RAW SQL queries - NO Eloquent models
 */
class GenealogyMCPService
{
    private const FACT_UPDATE_FIELDS = [
        'given_name', 'surname', 'suffix', 'nickname', 'sex',
        'birth_date', 'birth_place', 'birth_lat', 'birth_lon',
        'death_date', 'death_place', 'death_lat', 'death_lon',
        'burial_date', 'burial_place', 'burial_lat', 'burial_lon',
        'occupation', 'education', 'religion', 'notes', 'primary_photo_id',
        'title', 'physical_description', 'nationality', 'ssn', 'id_number', 'property', 'cause_of_death',
    ];

    private const RELATIONSHIP_LINK_TYPES = ['parent', 'child', 'sibling', 'spouse'];

    private const LESSON_MEMORY_TYPES = [
        'research_process_lesson',
        'document_interpretation_lesson',
        'source_capture_lesson',
        'identity_decision_lesson',
        'offline_workflow_lesson',
    ];

    private const MEMORY_BACKFILL_LANES = [
        'canonical_lessons',
        'health_audit',
        'media_intake',
        'source_media_outcomes',
        'review_decisions',
        'review_packets',
    ];

    private const DEFAULT_LESSON_MEMORY_SEEDS = [
        [
            'lesson_type' => 'research_process_lesson',
            'title' => 'Guard conflicts instead of forcing identity changes',
            'lesson' => 'When a new source conflicts with accepted genealogy identity, preserve the conflict as a research task or memo and require source-backed resolution before overwriting accepted person, family, or citation facts.',
            'tags' => ['conflict', 'identity', 'research_task'],
            'confidence' => 0.9,
        ],
        [
            'lesson_type' => 'document_interpretation_lesson',
            'title' => 'Escalate weak OCR and preserve original document context',
            'lesson' => 'For hard-to-read certificates, registers, scans, handwriting, JP2/TIFF derivatives, or noisy OCR/HTR/vision output, keep the original media attached, record text quality, and escalate uncertain fields instead of treating machine text as proof.',
            'tags' => ['ocr', 'htr', 'vision', 'weak_text'],
            'confidence' => 0.9,
        ],
        [
            'lesson_type' => 'source_capture_lesson',
            'title' => 'Capture usable source media into the tree folder',
            'lesson' => 'When a public or local source exposes a usable image, PDF, HTML snapshot, or archival derivative, save the asset under the self-contained family-tree folder, link it to the source/citation target, and keep the remote locator as provenance.',
            'tags' => ['source_media', 'ft_storage', 'offline'],
            'confidence' => 0.9,
        ],
        [
            'lesson_type' => 'identity_decision_lesson',
            'title' => 'Separate non-tree subjects from tree links',
            'lesson' => 'If a media face, caption, filename, or document subject is not part of the current family tree, keep searchable non-FT memory/metadata where useful but do not link it to a person or family in that tree.',
            'tags' => ['non_ft', 'faces', 'metadata'],
            'confidence' => 0.9,
        ],
        [
            'lesson_type' => 'offline_workflow_lesson',
            'title' => 'Prefer local Genea MCP and RAG before raw data access',
            'lesson' => 'For local or off-grid genealogy work, use Genea MCP status/profile/memory/RAG tools first so agents get compact, tree-scoped context, preserve audit trails, and avoid repeated raw schema or table exploration.',
            'tags' => ['offline', 'mcp', 'rag'],
            'confidence' => 0.85,
        ],
        [
            'lesson_type' => 'document_interpretation_lesson',
            'title' => 'Use a local OCR vision ladder for hard media',
            'lesson' => 'When offline or avoiding external LLM load, review media through the smallest useful local path first: existing OCR/RAG text, media_review_packet, HTR/vision escalation candidates, then local Ollama vision or OCR. Treat noisy machine readings as clues until a human-readable field, citation image, or stronger source confirms the fact.',
            'tags' => ['offline', 'ollama', 'ocr', 'vision', 'weak_text'],
            'confidence' => 0.88,
        ],
        [
            'lesson_type' => 'research_process_lesson',
            'title' => 'Save reusable branch decisions as Genea memory',
            'lesson' => 'After a guarded source review, locality split, nickname correction, non-FT identity decision, or repeated source-access blocker, save the decision as tree-scoped Genea lesson or source-gap memory so future agents reuse the rule instead of rediscovering it through raw searches.',
            'tags' => ['memory', 'research_process', 'identity', 'source_gap'],
            'confidence' => 0.88,
        ],
    ];

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
     * @param  string  $file_path  Path to GEDCOM file (absolute or in storage/app/genealogy/)
     * @param  bool  $preview_only  If true, return stats only without full person data
     * @return array Parsed genealogy data
     */
    public function gedcom_parse(string $file_path, bool $preview_only = false): array
    {
        Log::info('GenealogyMCPService: gedcom_parse called', [
            'file_path' => $file_path,
            'preview_only' => $preview_only,
        ]);

        // Handle relative paths
        if (! str_starts_with($file_path, '/')) {
            $file_path = storage_path("app/genealogy/{$file_path}");
        }

        if (! file_exists($file_path)) {
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

            if (! $preview_only) {
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
     * @param  int  $tree_id  Tree ID to export
     * @param  bool  $include_living  Include living persons (defaults true for private local exports)
     * @param  bool  $include_media  Include media object references
     * @return array GEDCOM content and metadata
     */
    public function gedcom_export(
        int $tree_id,
        bool $include_living = true,
        bool $include_media = true,
        string $privacy_context = 'private_local'
    ): array {
        Log::info('GenealogyMCPService: gedcom_export called', [
            'tree_id' => $tree_id,
            'include_living' => $include_living,
            'privacy_context' => $privacy_context,
        ]);

        try {
            $gedcom = $this->exporter->exportTree($tree_id, null, [
                'include_living' => $include_living,
                'include_media' => $include_media,
                'privacy_context' => $privacy_context,
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
     * Generate the FT source-audit workbook manifest or first-slice CSV package.
     */
    public function source_audit_workbook(
        int $tree_id,
        string $format = 'manifest',
        string $privacy_mode = 'private_local',
        string $layout_profile = 'dense_audit_v1',
        bool $include_sources = true,
        bool $include_media = true,
        bool $include_issues = true,
        bool $dry_run = true,
        bool $confirm = false,
        int $prelabel_count = 0,
        string $shard_mode = 'none',
        ?int $branch_person_id = null,
        string $branch_mode = 'descendants'
    ): array {
        Log::info('GenealogyMCPService: source_audit_workbook called', [
            'tree_id' => $tree_id,
            'format' => $format,
            'privacy_mode' => $privacy_mode,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'prelabel_count' => $prelabel_count,
            'shard_mode' => $shard_mode,
            'branch_person_id' => $branch_person_id,
            'branch_mode' => $branch_mode,
        ]);

        try {
            $result = app(SourceAuditWorkbookService::class)->generate(
                treeId: $tree_id,
                format: $format,
                privacyMode: $privacy_mode,
                dryRun: $dry_run,
                confirm: $confirm,
                actor: 'genealogy-mcp-source-audit-workbook',
                layoutProfile: $layout_profile,
                includeSources: $include_sources,
                includeMedia: $include_media,
                includeIssues: $include_issues,
                prelabelCount: $prelabel_count,
                shardMode: $shard_mode,
                branchPersonId: $branch_person_id,
                branchMode: $branch_mode
            );

            if (! $dry_run) {
                $this->logGenealogyWriteAudit(
                    'source_audit_workbook',
                    'generate_source_audit_workbook_package',
                    'genealogy-mcp-source-audit-workbook',
                    (bool) ($result['success'] ?? false),
                    [
                        'tree_id' => $tree_id,
                        'format' => $format,
                        'privacy_mode' => $privacy_mode,
                        'run_id' => $result['run_id'] ?? null,
                        'prelabel_count' => $prelabel_count,
                        'shard_mode' => $shard_mode,
                        'branch_person_id' => $branch_person_id,
                        'branch_mode' => $branch_mode,
                    ],
                    [
                        'row_counts' => $result['row_counts'] ?? $result['counts'] ?? [],
                        'files' => $result['files'] ?? [],
                    ],
                    'Delete the generated source-audit report folder if this export package was created by mistake.',
                    ['dry_run' => false, 'confirm' => $confirm]
                );
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'tool' => 'source_audit_workbook',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Search the genealogy database
     *
     * @param  string  $query  Search query
     * @param  string  $type  Type to search: 'person', 'family', 'source', 'all'
     * @param  int|null  $tree_id  Limit to specific tree
     * @param  int  $limit  Maximum results per type
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
                $sql .= ' AND tree_id = ?';
                $params[] = $tree_id;
            }

            $sql .= ' ORDER BY surname, given_name LIMIT ?';
            $params[] = $limit;

            $results['persons'] = DB::select($sql, $params);
        }

        if ($type === 'family' || $type === 'all') {
            // Search families via person names
            $sql = 'SELECT DISTINCT f.id, f.tree_id, f.marriage_date, f.marriage_place,
                           h.given_name as husband_given, h.surname as husband_surname,
                           w.given_name as wife_given, w.surname as wife_surname
                    FROM genealogy_families f
                    LEFT JOIN genealogy_persons h ON f.husband_id = h.id
                    LEFT JOIN genealogy_persons w ON f.wife_id = w.id
                    WHERE h.given_name LIKE ? OR h.surname LIKE ?
                       OR w.given_name LIKE ? OR w.surname LIKE ?';
            $params = [$queryParam, $queryParam, $queryParam, $queryParam];

            if ($tree_id) {
                $sql .= ' AND f.tree_id = ?';
                $params[] = $tree_id;
            }

            $sql .= ' LIMIT ?';
            $params[] = $limit;

            $results['families'] = DB::select($sql, $params);
        }

        if ($type === 'source' || $type === 'all') {
            $sql = 'SELECT id, tree_id, title, author, publication AS publication_info, repository AS repository_id
                    FROM genealogy_sources
                    WHERE title LIKE ? OR author LIKE ?';
            $params = [$queryParam, $queryParam];

            if ($tree_id) {
                $sql .= ' AND tree_id = ?';
                $params[] = $tree_id;
            }

            $sql .= ' ORDER BY title LIMIT ?';
            $params[] = $limit;

            $results['sources'] = DB::select($sql, $params);
        }

        return $results;
    }

    /**
     * Search people within one tree, with explicit living-person inclusion.
     */
    public function person_search(int $tree_id, string $query, bool $include_living = true, int $limit = 50): array
    {
        Log::info('GenealogyMCPService: person_search called', [
            'tree_id' => $tree_id,
            'query' => $query,
            'include_living' => $include_living,
        ]);

        $limit = max(1, min(100, $limit));
        $query = trim($query);

        if ($query === '') {
            return [
                'tool' => 'person_search',
                'success' => false,
                'error' => 'Query is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'person_search',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $like = "%{$query}%";
            $livingClause = $include_living ? '' : 'AND COALESCE(p.living, 0) = 0';
            $tokens = array_slice(array_values(array_filter(
                preg_split('/\s+/', $query) ?: [],
                static fn (string $token): bool => trim($token) !== ''
            )), 0, 6);
            $tokenClause = '';
            $tokenParams = [];
            if ($tokens !== []) {
                $tokenClause = 'OR ('.implode(' AND ', array_fill(
                    0,
                    count($tokens),
                    "CONCAT_WS(' ', p.given_name, p.nickname, p.surname, p.gedcom_id, gnv.full_name, gnv.given_names, gnv.surname) LIKE ?"
                )).')';
                $tokenParams = array_map(static fn (string $token): string => "%{$token}%", $tokens);
            }

            $rows = DB::select("
                SELECT p.id,
                       p.tree_id,
                       p.gedcom_id,
                       p.given_name,
                       p.surname,
                       p.nickname,
                       p.sex,
                       p.birth_date,
                       p.birth_place,
                       p.death_date,
                       p.death_place,
                       p.living,
                       p.primary_photo_id,
                       COUNT(DISTINCT ps.id) AS source_count,
                       COUNT(DISTINCT pm.id) AS media_count,
                       GROUP_CONCAT(DISTINCT CONCAT(gnv.name_type, ': ', COALESCE(gnv.full_name, TRIM(CONCAT_WS(' ', gnv.given_names, gnv.surname)))) ORDER BY gnv.name_type SEPARATOR '; ') AS name_variants
                FROM genealogy_persons p
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = p.id
                LEFT JOIN genealogy_person_media pm ON pm.person_id = p.id
                LEFT JOIN genealogy_name_variants gnv ON gnv.person_id = p.id
                WHERE p.tree_id = ?
                  {$livingClause}
                  AND (
                    p.given_name LIKE ?
                    OR p.surname LIKE ?
                    OR p.nickname LIKE ?
                    OR p.gedcom_id LIKE ?
                    OR CONCAT_WS(' ', p.given_name, p.surname) LIKE ?
                    OR CONCAT_WS(' ', p.nickname, p.surname) LIKE ?
                    OR gnv.full_name LIKE ?
                    OR CONCAT_WS(' ', gnv.given_names, gnv.surname) LIKE ?
                    OR gnv.surname LIKE ?
                    {$tokenClause}
                  )
                GROUP BY p.id,
                         p.tree_id,
                         p.gedcom_id,
                         p.given_name,
                         p.surname,
                         p.nickname,
                         p.sex,
                         p.birth_date,
                         p.birth_place,
                         p.death_date,
                         p.death_place,
                         p.living,
                         p.primary_photo_id
                ORDER BY
                    CASE
                        WHEN CONCAT_WS(' ', p.given_name, p.surname) = ? THEN 0
                        WHEN p.surname = ? THEN 1
                        WHEN p.given_name = ? THEN 2
                        ELSE 3
                    END,
                    p.surname,
                    p.given_name,
                    p.id
                LIMIT ?
            ", [
                $tree_id,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                ...$tokenParams,
                $query,
                $query,
                $query,
                $limit,
            ]);

            return [
                'tool' => 'person_search',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'query' => $query,
                'include_living' => $include_living,
                'count' => count($rows),
                'persons' => $rows,
                'results' => array_map(fn (object $person): array => $this->buildPersonSearchResult($person), $rows),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_search',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Add a vetted name variant for married names, maiden names, nicknames, or aliases.
     */
    public function name_variant_add(
        int $tree_id,
        int $person_id,
        string $name_type,
        ?string $given_names = null,
        ?string $surname = null,
        ?int $source_id = null,
        ?string $notes = null,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: name_variant_add called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'name_type' => $name_type,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('name_variant_add');
        }

        $nameType = $this->normalizeNameVariantType($name_type);
        $givenNames = trim((string) $given_names);
        $variantSurname = trim((string) $surname);
        $notes = trim((string) $notes);
        $fullName = trim($givenNames.' '.$variantSurname);

        if ($nameType === null) {
            return [
                'tool' => 'name_variant_add',
                'success' => false,
                'error' => 'Invalid name_type. Allowed: birth, married, maiden, alias, nickname, religious, phonetic.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($fullName === '') {
            return [
                'tool' => 'name_variant_add',
                'success' => false,
                'error' => 'At least one of given_names or surname is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'name_variant_add',
                'success' => false,
                'dry_run' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'name_variant_add',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $person = DB::selectOne(
                'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
                [$person_id]
            );
            if (! $person || (int) $person->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'name_variant_add',
                    'success' => false,
                    'error' => 'Person not found in requested tree.',
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($source_id !== null) {
                $source = DB::selectOne(
                    'SELECT id FROM genealogy_sources WHERE id = ? AND tree_id = ?',
                    [$source_id, $requestedTreeId]
                );
                if (! $source) {
                    return [
                        'tool' => 'name_variant_add',
                        'success' => false,
                        'error' => 'Source not found in requested tree.',
                        'tree_id' => $requestedTreeId,
                        'source_id' => $source_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $existing = DB::selectOne(
                "SELECT id
                 FROM genealogy_name_variants
                 WHERE person_id = ?
                   AND name_type = ?
                   AND COALESCE(given_names, '') = ?
                   AND COALESCE(surname, '') = ?
                 LIMIT 1",
                [$person_id, $nameType, $givenNames, $variantSurname]
            );

            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'person_name' => trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
                'name_type' => $nameType,
                'given_names' => $givenNames !== '' ? $givenNames : null,
                'surname' => $variantSurname !== '' ? $variantSurname : null,
                'full_name' => $fullName,
                'source_id' => $source_id,
                'notes' => $notes !== '' ? $this->compactToolText($notes, 600) : null,
                'already_exists' => $existing !== null,
                'existing_variant_id' => $existing ? (int) $existing->id : null,
                'actor' => $actor,
            ];

            if ($dry_run || $existing !== null) {
                return [
                    'tool' => 'name_variant_add',
                    'success' => true,
                    'dry_run' => $dry_run,
                    'applied' => false,
                    'deduplicated' => $existing !== null,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $variantId = app(NameVariantService::class)->addVariant(
                $person_id,
                $nameType,
                $givenNames !== '' ? $givenNames : null,
                $variantSurname !== '' ? $variantSurname : null,
                $source_id,
                $notes !== '' ? $notes : null
            );

            DB::update(
                'UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE id = ? AND tree_id = ?',
                [$person_id, $requestedTreeId]
            );

            $this->logGenealogyWriteAudit(
                'name_variant_add',
                'add_person_name_variant',
                $actor,
                $variantId > 0,
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'name_variant_id' => $variantId,
                ],
                $plan,
                "Delete genealogy_name_variants.id={$variantId} and reindex person {$person_id} if this variant is wrong.",
                ['dry_run' => false]
            );

            return [
                'tool' => 'name_variant_add',
                'success' => $variantId > 0,
                'dry_run' => false,
                'applied' => $variantId > 0,
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'name_variant_id' => $variantId,
                'plan' => $plan,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'name_variant_add',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get AI research suggestions for a person
     *
     * @param  int  $person_id  Person ID to research
     * @param  string  $focus  Research focus: 'ancestry', 'descendants', 'siblings', 'general', 'brick_wall'
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
     * Audit relationship integrity without making link or person changes.
     */
    public function relationship_audit(int $tree_id, int $limit = 50): array
    {
        Log::info('GenealogyMCPService: relationship_audit called', ['tree_id' => $tree_id]);

        $limit = max(1, min(200, $limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'relationship_audit',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $audit = app(GenealogyHealthAuditService::class)->collect(
                treeId: $tree_id,
                root: null,
                limit: $limit,
                sections: ['links', 'dates'],
                dryRun: false,
            );

            return [
                'tool' => 'relationship_audit',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'read_only' => true,
                'mutation_allowed' => false,
                'summary' => $audit['summary'] ?? [],
                'issues' => $audit['issues'] ?? [],
                'next_actions' => $audit['next_actions'] ?? [],
                'posture' => $audit['posture'] ?? [],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'relationship_audit',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Run the read-only genealogy health audit for selected sections.
     */
    public function health_audit(
        int $tree_id,
        array|string|null $sections = null,
        ?string $severity = null,
        int $limit = 50,
        bool $compact = true
    ): array {
        Log::info('GenealogyMCPService: health_audit called', [
            'tree_id' => $tree_id,
            'sections' => $sections,
            'severity' => $severity,
            'compact' => $compact,
        ]);

        $limit = max(1, min(200, $limit));
        $sectionList = $this->normalizeHealthAuditSections($sections);
        $severity = $severity !== null && trim($severity) !== '' ? strtolower(trim($severity)) : null;

        if ($severity !== null && $this->severityRank($severity) === null) {
            return [
                'tool' => 'health_audit',
                'success' => false,
                'error' => "Invalid severity filter: {$severity}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'health_audit',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $auditService = app(GenealogyHealthAuditService::class);
            $payload = $auditService->collect(
                treeId: $tree_id,
                root: null,
                limit: $limit,
                sections: $sectionList,
                dryRun: false,
            );

            if ($severity !== null) {
                $minimumRank = $this->severityRank($severity);
                $payload['issues'] = array_values(array_filter(
                    $payload['issues'] ?? [],
                    fn (array $issue): bool => ($this->severityRank((string) ($issue['severity'] ?? 'info')) ?? 0) >= $minimumRank
                ));
                $payload['summary'] = $this->refreshAuditSummary($payload['summary'] ?? [], $payload['issues']);
            }

            if ($compact) {
                $payload = $auditService->compactPayload($payload);
            }

            return array_merge($payload, [
                'tool' => 'health_audit',
                'success' => true,
                'tree_name' => $tree->name ?? null,
                'severity_filter' => $severity,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return [
                'tool' => 'health_audit',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Materialize canonical read-only review packets from health-audit findings.
     */
    public function health_review_packet(
        int $tree_id,
        array|string|null $sections = null,
        ?string $severity = null,
        int $limit = 25,
        int $issue_limit = 20
    ): array {
        Log::info('GenealogyMCPService: health_review_packet called', [
            'tree_id' => $tree_id,
            'sections' => $sections,
            'severity' => $severity,
            'limit' => $limit,
            'issue_limit' => $issue_limit,
        ]);

        $limit = max(1, min(200, $limit));
        $issueLimit = max(1, min(100, $issue_limit));
        $sectionList = $this->normalizeHealthAuditSections($sections);
        $severity = $severity !== null && trim($severity) !== '' ? strtolower(trim($severity)) : null;

        if ($severity !== null && $this->severityRank($severity) === null) {
            return [
                'tool' => 'health_review_packet',
                'success' => false,
                'error' => "Invalid severity filter: {$severity}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'health_review_packet',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $auditService = app(GenealogyHealthAuditService::class);
            $payload = $auditService->collect(
                treeId: $tree_id,
                root: null,
                limit: $limit,
                sections: $sectionList,
                dryRun: false,
            );

            if ($severity !== null) {
                $minimumRank = $this->severityRank($severity);
                $payload['issues'] = array_values(array_filter(
                    $payload['issues'] ?? [],
                    fn (array $issue): bool => ($this->severityRank((string) ($issue['severity'] ?? 'info')) ?? 0) >= $minimumRank
                ));
                $payload['summary'] = $this->refreshAuditSummary($payload['summary'] ?? [], $payload['issues']);
            }

            $packets = $auditService->reviewPackets($payload, $issueLimit);

            return [
                'tool' => 'health_review_packet',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'severity_filter' => $severity,
                'summary' => $payload['summary'] ?? [],
                'issue_schema' => $auditService->issueSchema(),
                'packet_count' => count($packets),
                'packets' => $packets,
                'write_policy' => 'review_packets_are_read_only_use_proposal_or_deterministic_repair_tools_for_writes',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'health_review_packet',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return compact review-packet context by sanitized target ref or direct token.
     */
    public function review_packet_context(
        string $target_ref_or_token,
        bool $include_details = false
    ): array {
        Log::info('GenealogyMCPService: review_packet_context called', [
            'input_type' => str_contains($target_ref_or_token, ':target-') ? 'target_ref' : 'token',
            'include_details' => $include_details,
        ]);

        try {
            $resolved = $this->resolveGenealogyReviewPacketRow($target_ref_or_token);
            if (! ($resolved['success'] ?? false)) {
                return [
                    'tool' => 'review_packet_context',
                    'success' => false,
                    'error' => $resolved['error'] ?? 'Genealogy review packet not found.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $row = $resolved['row'];
            $context = app(ReviewContextEnrichmentService::class)
                ->getContext('genealogy_review_packet:'.(string) $row->token);

            if ($context === null) {
                return [
                    'tool' => 'review_packet_context',
                    'success' => false,
                    'error' => 'Genealogy review packet context could not be built.',
                    'target_ref' => $resolved['target_ref'],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $details = $this->decodeJsonField($row->details ?? null);
            $details = is_array($details) ? $details : [];
            $item = is_array($context['item'] ?? null) ? $context['item'] : [];

            $response = [
                'tool' => 'review_packet_context',
                'success' => true,
                'target_ref' => $resolved['target_ref'],
                'review_queue_id' => (int) $row->id,
                'status' => (string) ($row->status ?? 'unknown'),
                'packet_status' => (string) ($context['packet_status'] ?? $details['packet_status'] ?? 'pending'),
                'title' => (string) ($row->title ?? ''),
                'summary' => $this->limitMemoryString($row->summary ?? null, 1200),
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
                'created_at' => $row->created_at ?? null,
                'expires_at' => $row->expires_at ?? null,
                'person' => $this->compactReviewPacketPerson($context['person'] ?? null),
                'review_focus' => $this->compactReviewPacketMap($context['review_focus'] ?? null, 1800),
                'packet_outcome' => $this->compactReviewPacketMap($context['packet_outcome'] ?? null, 1400),
                'review_checklist' => $this->compactReviewPacketMap($context['review_checklist'] ?? null, 2000),
                'review_proof' => $this->compactReviewPacketMap($context['review_proof'] ?? null, 1800),
                'evidence_lens' => $this->compactReviewPacketMap($context['evidence_lens'] ?? null, 1800),
                'apply_preview' => $this->compactReviewPacketMap($context['apply_preview'] ?? ($details['apply_preview'] ?? null), 1600),
                'validation' => $this->compactReviewPacketMap($context['validation'] ?? ($details['validation'] ?? null), 1400),
                'claims' => $this->compactReviewPacketClaims($context['claims'] ?? ($details['claims'] ?? [])),
                'sources' => $this->compactReviewPacketSources($context['sources'] ?? ($details['sources'] ?? [])),
                'media_refs' => array_slice(is_array($context['media_refs'] ?? null) ? $context['media_refs'] : [], 0, 10),
                'evidence_asset_candidates' => array_slice(is_array($context['evidence_asset_candidates'] ?? null) ? $context['evidence_asset_candidates'] : [], 0, 10),
                'decision_log_count' => is_array($context['decision_log'] ?? null)
                    ? count($context['decision_log'])
                    : (is_array($details['decision_log'] ?? null) ? count($details['decision_log']) : 0),
                'latest_decision' => $this->latestReviewPacketDecision($context['decision_log'] ?? ($details['decision_log'] ?? [])),
                'write_policy' => 'read_only_context_use_review_packet_decision_for_guarded_outcome_writes',
                'timestamp' => now()->toIso8601String(),
            ];

            if ($include_details) {
                $response['details'] = $this->compactReviewPacketDetails($item['details'] ?? $details);
            }

            return $response;
        } catch (\Throwable $e) {
            return [
                'tool' => 'review_packet_context',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Guarded decision wrapper for genealogy review packets.
     */
    public function review_packet_decision(
        string $target_ref_or_token,
        string $action,
        ?string $reason_code = null,
        ?string $notes = null,
        bool $dry_run = true,
        bool $confirm = false
    ): array {
        $normalizedAction = $this->normalizeReviewPacketDecisionAction($action);
        Log::info('GenealogyMCPService: review_packet_decision called', [
            'input_type' => str_contains($target_ref_or_token, ':target-') ? 'target_ref' : 'token',
            'action' => $normalizedAction,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        if ($normalizedAction === null) {
            return [
                'tool' => 'review_packet_decision',
                'success' => false,
                'error' => 'Invalid action. Use mark_reviewed, approve, reject, clarify, or defer.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (in_array($normalizedAction, ['reject', 'clarify', 'defer'], true)
            && trim((string) $reason_code) === ''
        ) {
            return [
                'tool' => 'review_packet_decision',
                'success' => false,
                'error' => "{$normalizedAction} requires a reason_code.",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $resolved = $this->resolveGenealogyReviewPacketRow($target_ref_or_token, pendingOnly: true);
            if (! ($resolved['success'] ?? false)) {
                return [
                    'tool' => 'review_packet_decision',
                    'success' => false,
                    'error' => $resolved['error'] ?? 'Pending genealogy review packet not found.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $row = $resolved['row'];
            $limitedNotes = $this->limitMemoryString($notes, 2000);

            if ($dry_run) {
                return [
                    'tool' => 'review_packet_decision',
                    'success' => true,
                    'dry_run' => true,
                    'would_update' => true,
                    'target_ref' => $resolved['target_ref'],
                    'review_queue_id' => (int) $row->id,
                    'status_before' => (string) ($row->status ?? 'pending'),
                    'action' => $normalizedAction,
                    'reason_code' => $this->limitMemoryString($reason_code, 80),
                    'notes_present' => $limitedNotes !== null,
                    'write_policy' => 'dry_run_default_set_dry_run_false_and_confirm_true_to_write_packet_outcome',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'review_packet_decision',
                    'success' => false,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'target_ref' => $resolved['target_ref'],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $decisionService = app(GenealogyReviewPacketDecisionService::class);
            $result = match ($normalizedAction) {
                'mark_reviewed' => $decisionService->markReviewed((string) $row->token, $limitedNotes, $reason_code),
                'approve' => $decisionService->approve((string) $row->token, $limitedNotes, $reason_code),
                'reject' => $decisionService->reject((string) $row->token, $limitedNotes, $reason_code),
                'clarify' => $decisionService->clarify((string) $row->token, $limitedNotes, $reason_code),
                'defer' => $decisionService->defer((string) $row->token, $limitedNotes, $reason_code),
            };

            return array_merge([
                'tool' => 'review_packet_decision',
                'target_ref' => $resolved['target_ref'],
                'review_queue_id' => (int) $row->id,
                'dry_run' => false,
                'write_policy' => 'packet_outcome_only_no_canonical_genealogy_fact_mutation',
                'timestamp' => now()->toIso8601String(),
            ], $result);
        } catch (\Throwable $e) {
            return [
                'tool' => 'review_packet_decision',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get a complete read-only person profile for agent review.
     */
    public function person_profile(int $person_id, int $limit = 25): array
    {
        Log::info('GenealogyMCPService: person_profile called', ['person_id' => $person_id]);

        $limit = max(1, min(100, $limit));

        try {
            $person = DB::selectOne('
                SELECT p.*, t.name AS tree_name
                FROM genealogy_persons p
                LEFT JOIN genealogy_trees t ON t.id = p.tree_id
                WHERE p.id = ?
            ', [$person_id]);

            if (! $person) {
                return [
                    'tool' => 'person_profile',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $parents = DB::select('
                SELECT gc.family_id,
                       f.husband_id AS father_id,
                       fp.given_name AS father_given_name,
                       fp.surname AS father_surname,
                       f.wife_id AS mother_id,
                       mp.given_name AS mother_given_name,
                       mp.surname AS mother_surname
                FROM genealogy_children gc
                JOIN genealogy_families f ON f.id = gc.family_id
                LEFT JOIN genealogy_persons fp ON fp.id = f.husband_id
                LEFT JOIN genealogy_persons mp ON mp.id = f.wife_id
                WHERE gc.person_id = ?
                ORDER BY gc.family_id
                LIMIT ?
            ', [$person_id, $limit]);

            $spouseFamilies = DB::select('
                SELECT f.id AS family_id,
                       f.husband_id,
                       hp.given_name AS husband_given_name,
                       hp.surname AS husband_surname,
                       f.wife_id,
                       wp.given_name AS wife_given_name,
                       wp.surname AS wife_surname,
                       f.marriage_date,
                       f.marriage_place
                FROM genealogy_families f
                LEFT JOIN genealogy_persons hp ON hp.id = f.husband_id
                LEFT JOIN genealogy_persons wp ON wp.id = f.wife_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY f.id
                LIMIT ?
            ', [$person_id, $person_id, $limit]);

            $children = DB::select('
                SELECT gc.family_id,
                       cp.id AS person_id,
                       cp.given_name,
                       cp.surname,
                       cp.birth_date,
                       cp.death_date,
                       gc.birth_order
                FROM genealogy_children gc
                JOIN genealogy_families f ON f.id = gc.family_id
                JOIN genealogy_persons cp ON cp.id = gc.person_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY gc.family_id, gc.birth_order, cp.birth_date, cp.id
                LIMIT ?
            ', [$person_id, $person_id, $limit]);

            $media = DB::select('
                SELECT m.id,
                       m.title,
                       m.media_type,
                       m.local_filename,
                       m.nextcloud_path,
                       m.file_exists,
                       m.rag_indexed_at,
                       pm.is_primary,
                       pm.face_confirmed
                FROM genealogy_person_media pm
                JOIN genealogy_media m ON m.id = pm.media_id
                WHERE pm.person_id = ?
                ORDER BY pm.is_primary DESC, m.updated_at DESC, m.id DESC
                LIMIT ?
            ', [$person_id, $limit]);

            $sources = DB::select('
                SELECT s.id,
                       s.title,
                       s.url,
                       s.source_quality,
                       s.information_quality,
                       ps.page,
                       ps.quality
                FROM genealogy_person_sources ps
                JOIN genealogy_sources s ON s.id = ps.source_id
                WHERE ps.person_id = ?
                ORDER BY s.title, ps.id
                LIMIT ?
            ', [$person_id, $limit]);

            $citations = DB::select('
                SELECT c.id,
                       c.fact_type,
                       c.page,
                       c.quality,
                       c.evidence_type,
                       c.information_type,
                       s.title AS source_title,
                       s.url AS source_url
                FROM genealogy_citations c
                LEFT JOIN genealogy_sources s ON s.id = c.source_id
                WHERE c.person_id = ?
                ORDER BY c.fact_type, c.id
                LIMIT ?
            ', [$person_id, $limit]);

            $researchTasks = DB::select('
                SELECT id,
                       task_type,
                       priority,
                       status,
                       outcome_state,
                       LEFT(research_question, 500) AS research_question,
                       updated_at
                FROM genealogy_research_tasks
                WHERE person_id = ?
                ORDER BY FIELD(status, "processing", "queued", "failed", "completed", "cancelled"),
                         FIELD(priority, "urgent", "high", "medium", "low"),
                         updated_at DESC
                LIMIT ?
            ', [$person_id, $limit]);

            $ragDocs = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) AS count
                FROM rag_documents
                WHERE document_type = 'genealogy_person'
                  AND source_type = 'genealogy_person'
                  AND source_id::text = ?
            ", [(string) $person_id]);

            $embeddings = DB::connection('pgsql_rag')->selectOne('
                SELECT COUNT(*) AS count
                FROM genealogy_person_embeddings
                WHERE person_id = ?
            ', [$person_id]);

            return [
                'tool' => 'person_profile',
                'success' => true,
                'person_id' => $person_id,
                'tree_id' => (int) $person->tree_id,
                'tree_name' => $person->tree_name,
                'person' => $person,
                'relationships' => [
                    'parents' => $parents,
                    'spouse_families' => $spouseFamilies,
                    'children' => $children,
                ],
                'media' => $media,
                'sources' => $sources,
                'citations' => $citations,
                'research_tasks' => $researchTasks,
                'semantic_memory' => [
                    'rejected_names' => app(GenealogySemanticMemoryService::class)
                        ->getPersonRejectedNames($person_id, $limit),
                ],
                'rag' => [
                    'person_rag_docs' => (int) ($ragDocs->count ?? 0),
                    'person_embedding_rows' => (int) ($embeddings->count ?? 0),
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_profile',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get a complete read-only family profile for agent review.
     */
    public function family_profile(int $tree_id, int $family_id, int $limit = 25): array
    {
        Log::info('GenealogyMCPService: family_profile called', [
            'tree_id' => $tree_id,
            'family_id' => $family_id,
        ]);

        $limit = max(1, min(100, $limit));

        try {
            $family = DB::selectOne('
                SELECT f.*,
                       t.name AS tree_name,
                       hp.given_name AS husband_given_name,
                       hp.surname AS husband_surname,
                       hp.birth_date AS husband_birth_date,
                       hp.death_date AS husband_death_date,
                       hp.living AS husband_living,
                       wp.given_name AS wife_given_name,
                       wp.surname AS wife_surname,
                       wp.birth_date AS wife_birth_date,
                       wp.death_date AS wife_death_date,
                       wp.living AS wife_living
                FROM genealogy_families f
                LEFT JOIN genealogy_trees t ON t.id = f.tree_id
                LEFT JOIN genealogy_persons hp ON hp.id = f.husband_id
                LEFT JOIN genealogy_persons wp ON wp.id = f.wife_id
                WHERE f.tree_id = ? AND f.id = ?
            ', [$tree_id, $family_id]);

            if (! $family) {
                return [
                    'tool' => 'family_profile',
                    'success' => false,
                    'error' => "Family not found in tree {$tree_id}: {$family_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $children = DB::select('
                SELECT gc.family_id,
                       gc.person_id,
                       cp.given_name,
                       cp.surname,
                       cp.birth_date,
                       cp.birth_place,
                       cp.death_date,
                       cp.death_place,
                       cp.living,
                       gc.father_relationship,
                       gc.mother_relationship,
                       gc.birth_order
                FROM genealogy_children gc
                JOIN genealogy_persons cp ON cp.id = gc.person_id
                WHERE gc.family_id = ?
                ORDER BY gc.birth_order, cp.birth_date, cp.id
                LIMIT ?
            ', [$family_id, $limit]);

            $memberIds = array_values(array_unique(array_filter(array_merge(
                [(int) ($family->husband_id ?? 0), (int) ($family->wife_id ?? 0)],
                array_map(static fn ($child): int => (int) $child->person_id, $children)
            ))));

            $memberMedia = [];
            if ($memberIds !== []) {
                $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                $memberMedia = DB::select("
                    SELECT pm.person_id,
                           m.id,
                           m.title,
                           m.media_type,
                           m.local_filename,
                           m.nextcloud_path,
                           m.file_exists,
                           pm.is_primary,
                           pm.face_confirmed
                    FROM genealogy_person_media pm
                    JOIN genealogy_media m ON m.id = pm.media_id
                    WHERE pm.person_id IN ({$placeholders})
                    ORDER BY pm.person_id, pm.is_primary DESC, m.updated_at DESC, m.id DESC
                    LIMIT ?
                ", array_merge($memberIds, [$limit]));
            }

            $familyMedia = DB::select('
                SELECT m.id,
                       m.title,
                       m.media_type,
                       m.local_filename,
                       m.nextcloud_path,
                       m.file_exists,
                       m.rag_indexed_at
                FROM genealogy_family_media fm
                JOIN genealogy_media m ON m.id = fm.media_id
                WHERE fm.family_id = ?
                ORDER BY m.updated_at DESC, m.id DESC
                LIMIT ?
            ', [$family_id, $limit]);

            $sources = DB::select('
                SELECT s.id,
                       s.title,
                       s.url,
                       s.source_quality,
                       s.information_quality,
                       fs.page,
                       fs.quality
                FROM genealogy_family_sources fs
                JOIN genealogy_sources s ON s.id = fs.source_id
                WHERE fs.family_id = ?
                ORDER BY s.title, fs.id
                LIMIT ?
            ', [$family_id, $limit]);

            $familyCitations = DB::select('
                SELECT c.id,
                       c.fact_type,
                       c.page,
                       c.quality,
                       c.evidence_type,
                       c.information_type,
                       s.title AS source_title,
                       s.url AS source_url
                FROM genealogy_citations c
                LEFT JOIN genealogy_sources s ON s.id = c.source_id
                WHERE c.family_id = ?
                ORDER BY c.fact_type, c.id
                LIMIT ?
            ', [$family_id, $limit]);

            $memberCitations = [];
            if ($memberIds !== []) {
                $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                $memberCitations = DB::select("
                    SELECT c.id,
                           c.person_id,
                           p.given_name,
                           p.surname,
                           c.fact_type,
                           c.page,
                           c.quality,
                           s.title AS source_title,
                           s.url AS source_url
                    FROM genealogy_citations c
                    LEFT JOIN genealogy_sources s ON s.id = c.source_id
                    LEFT JOIN genealogy_persons p ON p.id = c.person_id
                    WHERE c.person_id IN ({$placeholders})
                    ORDER BY c.person_id, c.fact_type, c.id
                    LIMIT ?
                ", array_merge($memberIds, [$limit]));
            }

            return [
                'tool' => 'family_profile',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $family->tree_name,
                'family_id' => $family_id,
                'family' => $family,
                'children' => $children,
                'media' => [
                    'family_media' => $familyMedia,
                    'member_media' => $memberMedia,
                ],
                'sources' => $sources,
                'citations' => [
                    'family_citations' => $familyCitations,
                    'member_citations' => $memberCitations,
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'family_profile',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get tree statistics
     *
     * @param  int  $tree_id  Tree ID
     * @return array Tree statistics
     */
    public function tree_stats(int $tree_id): array
    {
        Log::info('GenealogyMCPService: tree_stats called', ['tree_id' => $tree_id]);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'tree_stats',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            // Get detailed stats
            $personCount = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_persons WHERE tree_id = ?', [$tree_id])->count;
            $familyCount = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_families WHERE tree_id = ?', [$tree_id])->count;
            $sourceCount = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_sources WHERE tree_id = ?', [$tree_id])->count;
            $mediaCount = DB::selectOne('SELECT COUNT(*) as count FROM genealogy_media WHERE tree_id = ?', [$tree_id])->count;

            // Gender breakdown
            $genderStats = DB::select('SELECT sex, COUNT(*) as count FROM genealogy_persons WHERE tree_id = ? GROUP BY sex', [$tree_id]);

            // Date ranges
            $dateRange = DB::selectOne('
                SELECT MIN(birth_date) as earliest_birth, MAX(birth_date) as latest_birth,
                       MIN(death_date) as earliest_death, MAX(death_date) as latest_death
                FROM genealogy_persons WHERE tree_id = ?
            ', [$tree_id]);

            // Generations estimate
            $generations = DB::selectOne('
                SELECT COUNT(DISTINCT generation) as count
                FROM (
                    SELECT FLOOR((YEAR(CURDATE()) - YEAR(birth_date)) / 25) as generation
                    FROM genealogy_persons
                    WHERE tree_id = ? AND birth_date IS NOT NULL
                ) g
            ', [$tree_id])->count ?? 0;

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
     * Return a compact read-only status summary for one tree or every tree.
     */
    public function tree_status(?int $tree_id = null, int $limit = 5): array
    {
        Log::info('GenealogyMCPService: tree_status called', ['tree_id' => $tree_id]);

        $limit = max(1, min(25, $limit));

        try {
            $trees = [];
            if ($tree_id !== null) {
                $tree = $this->genealogy->getTree($tree_id);
                if (! $tree) {
                    return [
                        'tool' => 'tree_status',
                        'success' => false,
                        'error' => "Tree not found: {$tree_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
                $trees[] = (object) ['id' => $tree_id, 'name' => $tree->name ?? null];
            } else {
                $trees = DB::select('SELECT id, name FROM genealogy_trees ORDER BY id');
            }

            $statuses = [];
            foreach ($trees as $tree) {
                $id = (int) $tree->id;
                $stats = $this->tree_stats($id);
                $rag = $this->rag_status($id);
                $health = $this->health_audit($id, ['links', 'dates', 'media', 'rag'], 'medium', $limit, true);
                $export = $this->export_readiness($id, $limit);
                $unlinked = $this->media_unlinked($id, $limit);
                $duplicates = $this->duplicate_candidates($id, 0.6, $limit);

                $exportCount = (int) ($export['issues']['media_paths_not_self_contained']['count'] ?? 0);
                $healthRows = (int) ($health['summary']['issue_rows'] ?? 0);
                $ragMissing = (int) ($rag['persons']['missing_postgres_rag_docs'] ?? 0)
                    + (int) ($rag['persons']['missing_embeddings'] ?? 0)
                    + (int) ($rag['media']['mysql_pending_or_stale'] ?? 0);

                $statuses[] = [
                    'tree_id' => $id,
                    'tree_name' => $tree->name ?? ($stats['tree_name'] ?? null),
                    'status' => $this->treeStatusLabel(
                        healthRows: $healthRows,
                        ragMissing: $ragMissing,
                        exportCount: $exportCount,
                        duplicateCount: (int) ($duplicates['count'] ?? 0)
                    ),
                    'counts' => $stats['counts'] ?? [],
                    'health' => [
                        'issue_count' => (int) ($health['summary']['issue_count'] ?? 0),
                        'issue_rows' => $healthRows,
                        'severity_counts' => $health['summary']['severity_counts'] ?? [],
                    ],
                    'rag' => [
                        'person_missing_docs' => (int) ($rag['persons']['missing_postgres_rag_docs'] ?? 0),
                        'person_missing_embeddings' => (int) ($rag['persons']['missing_embeddings'] ?? 0),
                        'media_pending_or_stale' => (int) ($rag['media']['mysql_pending_or_stale'] ?? 0),
                    ],
                    'media' => [
                        'unlinked' => (int) ($unlinked['count'] ?? 0),
                        'paths_not_self_contained' => $exportCount,
                    ],
                    'duplicates' => [
                        'candidate_count' => (int) ($duplicates['count'] ?? 0),
                        'stats' => $duplicates['stats'] ?? [],
                    ],
                ];
            }

            return [
                'tool' => 'tree_status',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_count' => count($statuses),
                'trees' => $statuses,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'tree_status',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return cheap count-only work status for one tree or every tree.
     */
    public function work_status(?int $tree_id = null): array
    {
        Log::info('GenealogyMCPService: work_status called', ['tree_id' => $tree_id]);

        try {
            $trees = [];
            if ($tree_id !== null) {
                $tree = $this->genealogy->getTree($tree_id);
                if (! $tree) {
                    return [
                        'tool' => 'work_status',
                        'success' => false,
                        'error' => "Tree not found: {$tree_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
                $trees[] = (object) ['id' => $tree_id, 'name' => $tree->name ?? null];
            } else {
                $trees = DB::select('SELECT id, name FROM genealogy_trees ORDER BY id');
            }

            $statuses = [];
            foreach ($trees as $tree) {
                $id = (int) $tree->id;
                $row = DB::selectOne('
                    SELECT
                        (SELECT COUNT(*) FROM genealogy_persons WHERE tree_id = ?) AS persons,
                        (SELECT COUNT(*) FROM genealogy_families WHERE tree_id = ?) AS families,
                        (SELECT COUNT(*) FROM genealogy_sources WHERE tree_id = ?) AS sources,
                        (SELECT COUNT(*) FROM genealogy_citations c
                            LEFT JOIN genealogy_persons p ON p.id = c.person_id
                            LEFT JOIN genealogy_families f ON f.id = c.family_id
                            LEFT JOIN genealogy_media m ON m.id = c.media_id
                            WHERE p.tree_id = ? OR f.tree_id = ? OR m.tree_id = ?) AS citations,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?) AS media_total,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND file_exists = 1) AS media_local_files,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND (file_exists = 0 OR file_exists IS NULL)) AS media_missing_files,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND rag_indexed_at IS NULL) AS media_rag_pending,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at) AS media_rag_stale,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND (rag_indexed_at IS NULL OR updated_at > rag_indexed_at)) AS media_needs_rag,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND (
                            COALESCE(description, "") <> ""
                            OR COALESCE(ai_description, "") <> ""
                            OR COALESCE(transcription_text, "") <> ""
                            OR COALESCE(transcription, "") <> ""
                        )) AS media_with_text,
                        (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ? AND has_faces = 1) AS media_with_faces,
                        (SELECT COUNT(DISTINCT pm.media_id) FROM genealogy_person_media pm
                            JOIN genealogy_persons p ON p.id = pm.person_id
                            WHERE p.tree_id = ?) AS media_linked_to_persons,
                        (SELECT COUNT(DISTINCT fm.media_id) FROM genealogy_family_media fm
                            JOIN genealogy_families f ON f.id = fm.family_id
                            WHERE f.tree_id = ?) AS media_linked_to_families,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL) AS media_unlinked,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            JOIN genealogy_citations c ON c.media_id = gm.id
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL) AS media_citation_only_unlinked,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            JOIN genealogy_citations c ON c.media_id = gm.id
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                              AND NOT EXISTS (
                                  SELECT 1 FROM genealogy_citations c2
                                  WHERE c2.media_id = gm.id
                                    AND (c2.person_id IS NOT NULL OR c2.family_id IS NOT NULL)
                              )) AS media_source_only_citation_unlinked,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            JOIN genealogy_citations c ON c.media_id = gm.id
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                              AND (c.person_id IS NOT NULL OR c.family_id IS NOT NULL)) AS media_targeted_citation_unlinked,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                              AND NOT EXISTS (SELECT 1 FROM genealogy_citations c WHERE c.media_id = gm.id)) AS media_uncited_unlinked,
                        (SELECT COUNT(DISTINCT gm.id) FROM genealogy_media gm
                            JOIN agent_semantic_memory asm ON asm.entity_type = "genealogy_media"
                                AND asm.entity_id = gm.id
                                AND asm.fact_type = "media_triage_review"
                            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
                            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
                            WHERE gm.tree_id = ? AND pm.id IS NULL AND fm.id IS NULL
                              AND NOT EXISTS (SELECT 1 FROM genealogy_citations c WHERE c.media_id = gm.id)) AS media_reviewed_unresolved_unlinked,
                        (SELECT COUNT(*) FROM genealogy_face_match_queue WHERE tree_id = ? AND status = "pending") AS face_matches_pending,
                        (SELECT COUNT(*) FROM genealogy_proposed_changes WHERE tree_id = ? AND status = "pending") AS change_proposals_pending,
                        (SELECT COUNT(*) FROM genealogy_proposed_changes WHERE tree_id = ? AND status = "approved") AS change_proposals_approved,
                        (SELECT COUNT(*) FROM genealogy_proposed_relationships WHERE tree_id = ? AND status = "pending") AS relationship_proposals_pending,
                        (SELECT COUNT(*) FROM genealogy_proposed_relationships WHERE tree_id = ? AND status = "approved") AS relationship_proposals_approved,
                        (SELECT COUNT(*) FROM genealogy_duplicate_pairs WHERE tree_id = ? AND status IN ("pending", "pending_merge")) AS duplicate_candidates_pending
                ', array_fill(0, 28, $id));

                $statuses[] = $this->buildWorkStatusRow($id, $tree->name ?? null, $row);
            }

            return [
                'tool' => 'work_status',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_count' => count($statuses),
                'trees' => $statuses,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'work_status',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Dry-run-first wrapper for ancestor-path and coverage rebuilds.
     */
    public function coverage_rebuild(
        int $tree_id,
        ?int $root_person_id = null,
        bool $dry_run = true,
        bool $confirm = false
    ): array {
        Log::info('GenealogyMCPService: coverage_rebuild called', [
            'tree_id' => $tree_id,
            'root_person_id' => $root_person_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'coverage_rebuild',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rootPersonId = $root_person_id ?: (int) ($tree->root_person_id ?? 0);
            if ($rootPersonId <= 0) {
                $root = DB::selectOne('SELECT root_person_id FROM genealogy_trees WHERE id = ?', [$tree_id]);
                $rootPersonId = (int) ($root->root_person_id ?? 0);
            }
            if ($rootPersonId <= 0) {
                return [
                    'tool' => 'coverage_rebuild',
                    'success' => false,
                    'tree_id' => $tree_id,
                    'error' => 'Tree has no root_person_id; set the tree root before rebuilding coverage.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $before = $this->coverageRebuildStatus($tree_id, $rootPersonId);
            if ($dry_run) {
                return [
                    'tool' => 'coverage_rebuild',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'root_person_id' => $rootPersonId,
                    'before' => $before,
                    'would_run' => [
                        'rebuild_ancestor_paths' => true,
                        'refresh_person_coverage' => true,
                    ],
                    'write_requirements' => [
                        'dry_run' => false,
                        'confirm' => true,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'coverage_rebuild',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'root_person_id' => $rootPersonId,
                    'before' => $before,
                    'error' => 'coverage_rebuild requires confirm=true when dry_run=false.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $started = microtime(true);
            $pathsWritten = $this->genealogy->rebuildAncestorPaths($tree_id, $rootPersonId);
            $coverageRows = $this->genealogy->refreshPersonCoverage($tree_id);
            $after = $this->coverageRebuildStatus($tree_id, $rootPersonId);

            return [
                'tool' => 'coverage_rebuild',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'root_person_id' => $rootPersonId,
                'paths_written' => $pathsWritten,
                'coverage_rows_refreshed' => $coverageRows,
                'seconds' => round(microtime(true) - $started, 3),
                'before' => $before,
                'after' => $after,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'coverage_rebuild',
                'success' => false,
                'tree_id' => $tree_id,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return compact read-only scheduler coverage for genealogy jobs.
     */
    public function schedule_status(
        int $hours = 24,
        bool $include_disabled = true,
        ?string $name_filter = null
    ): array {
        Log::info('GenealogyMCPService: schedule_status called', [
            'hours' => $hours,
            'include_disabled' => $include_disabled,
            'name_filter' => $name_filter,
        ]);

        $hours = max(1, min(168, $hours));
        $nameFilter = trim((string) $name_filter);

        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('scheduled_job_runs')) {
            return [
                'tool' => 'schedule_status',
                'success' => false,
                'error' => 'scheduled_jobs or scheduled_job_runs table is missing.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $expected = $this->expectedGenealogyScheduleJobs();
            $expectedNames = array_keys($expected);
            $placeholders = implode(', ', array_fill(0, count($expectedNames), '?'));

            $params = $expectedNames;
            $filterSql = '';
            if ($nameFilter !== '') {
                $filterSql = ' AND name LIKE ?';
                $params[] = "%{$nameFilter}%";
            }

            $disabledSql = $include_disabled ? '' : ' AND enabled = 1';

            $jobs = DB::select("
                SELECT id,
                       name,
                       description,
                       command,
                       cron_expression,
                       enabled,
                       timeout_minutes,
                       last_run_at,
                       last_completed_at,
                       last_run_status,
                       next_run_at,
                       run_count,
                       fail_count,
                       category,
                       source_module,
                       runtime_mode,
                       workload_family,
                       resource_profile,
                       stall_policy,
                       backlog_metric,
                       notification_mode,
                       updated_at
                FROM scheduled_jobs
                WHERE (
                    name IN ({$placeholders})
                    OR name LIKE 'genealogy_%'
                    OR category = 'Genealogy'
                    OR source_module = 'Genealogy'
                )
                {$disabledSql}
                {$filterSql}
                ORDER BY name
            ", $params);

            $jobIds = array_values(array_filter(
                array_map(static fn (object $job): int => (int) $job->id, $jobs),
                static fn (int $id): bool => $id > 0
            ));

            $recentStats = [];
            if ($jobIds !== []) {
                $runPlaceholders = implode(', ', array_fill(0, count($jobIds), '?'));
                $runParams = array_merge($jobIds, [now()->subHours($hours)->toDateTimeString()]);
                $runRows = DB::select("
                    SELECT scheduled_job_id,
                           COUNT(*) AS runs,
                           SUM(CASE WHEN status IN ('failed', 'timeout') THEN 1 ELSE 0 END) AS failures,
                           MAX(started_at) AS last_started_at,
                           MAX(completed_at) AS last_completed_at,
                           MAX(duration_seconds) AS max_duration_seconds,
                           SUM(COALESCE(items_processed, 0)) AS items_processed
                    FROM scheduled_job_runs
                    WHERE scheduled_job_id IN ({$runPlaceholders})
                      AND started_at >= ?
                    GROUP BY scheduled_job_id
                ", $runParams);

                foreach ($runRows as $row) {
                    $recentStats[(int) $row->scheduled_job_id] = $row;
                }
            }

            $jobsByName = [];
            $jobRows = [];
            foreach ($jobs as $job) {
                $expectedMeta = $expected[$job->name] ?? null;
                $jobsByName[$job->name] = $job;
                $jobRows[] = $this->buildScheduleStatusRow(
                    $job,
                    $recentStats[(int) $job->id] ?? null,
                    $expectedMeta
                );
            }

            $coverage = [];
            foreach ($expected as $name => $meta) {
                $job = $jobsByName[$name] ?? null;
                $coverage[] = [
                    'name' => $name,
                    'lane' => $meta['lane'],
                    'required' => (bool) ($meta['required'] ?? true),
                    'status' => $job === null
                        ? 'missing'
                        : (((int) ($job->enabled ?? 0) === 1) ? 'enabled' : 'disabled'),
                    'command_hint' => $meta['command_hint'],
                ];
            }

            $missingExpected = array_values(array_filter(
                $coverage,
                static fn (array $row): bool => $row['required'] === true && $row['status'] === 'missing'
            ));
            $disabledExpected = array_values(array_filter(
                $coverage,
                static fn (array $row): bool => $row['required'] === true && $row['status'] === 'disabled'
            ));
            $recentFailures = array_sum(array_map(
                static fn (array $row): int => (int) ($row['recent']['failures'] ?? 0),
                $jobRows
            ));
            $unhealthyJobs = array_values(array_filter(
                $jobRows,
                static fn (array $row): bool => in_array($row['status'], ['failed', 'timeout', 'disabled', 'no_next_run', 'degraded'], true)
            ));

            $nextAction = 'monitor';
            if ($missingExpected !== []) {
                $nextAction = 'restore_missing_genealogy_schedules';
            } elseif ($disabledExpected !== []) {
                $nextAction = 'enable_required_genealogy_schedules';
            } elseif ($recentFailures > 0 || $unhealthyJobs !== []) {
                $nextAction = 'review_failed_or_degraded_genealogy_jobs';
            }

            return [
                'tool' => 'schedule_status',
                'success' => true,
                'hours' => $hours,
                'include_disabled' => $include_disabled,
                'name_filter' => $nameFilter !== '' ? $nameFilter : null,
                'next_action' => $nextAction,
                'summary' => [
                    'jobs' => count($jobRows),
                    'enabled' => count(array_filter($jobRows, static fn (array $row): bool => $row['enabled'] === true)),
                    'disabled' => count(array_filter($jobRows, static fn (array $row): bool => $row['enabled'] === false)),
                    'expected_required' => count(array_filter($coverage, static fn (array $row): bool => $row['required'] === true)),
                    'missing_expected' => count($missingExpected),
                    'disabled_expected' => count($disabledExpected),
                    'recent_failures' => $recentFailures,
                    'unhealthy_jobs' => count($unhealthyJobs),
                ],
                'expected_coverage' => $coverage,
                'jobs' => $jobRows,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'schedule_status',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return a compact read-only queue of genealogy research tasks for batching.
     */
    public function research_task_queue(
        int $tree_id,
        ?string $status = 'active',
        ?string $priority = null,
        ?string $task_type = null,
        int $limit = 50
    ): array {
        Log::info('GenealogyMCPService: research_task_queue called', [
            'tree_id' => $tree_id,
            'status' => $status,
            'priority' => $priority,
            'task_type' => $task_type,
            'limit' => $limit,
        ]);

        $limit = max(1, min(200, $limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'research_task_queue',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $statusFilter = $this->normalizeResearchTaskStatusFilter($status);
            if ($statusFilter === false) {
                return [
                    'tool' => 'research_task_queue',
                    'success' => false,
                    'error' => 'Invalid status filter. Use all, active, queued, processing, completed, failed, or cancelled.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $priorityFilter = $this->normalizeResearchTaskPriorityFilter($priority);
            if ($priorityFilter === false) {
                return [
                    'tool' => 'research_task_queue',
                    'success' => false,
                    'error' => 'Invalid priority filter. Use urgent, high, medium, or low.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $taskTypeFilter = $this->normalizeResearchTaskTypeFilter($task_type);
            if ($taskTypeFilter === false) {
                return [
                    'tool' => 'research_task_queue',
                    'success' => false,
                    'error' => 'Invalid task_type filter. Use find_records, verify_facts, find_relatives, analyze_dna, suggest_sources, or transcribe_document.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $statusCounts = DB::select('
                SELECT status, COUNT(*) AS count
                FROM genealogy_research_tasks
                WHERE tree_id = ?
                GROUP BY status
                ORDER BY FIELD(status, "processing", "queued", "failed", "completed", "cancelled")
            ', [$tree_id]);

            $where = ['t.tree_id = ?'];
            $params = [$tree_id];

            if (is_array($statusFilter)) {
                $where[] = 't.status IN ('.implode(', ', array_fill(0, count($statusFilter), '?')).')';
                array_push($params, ...$statusFilter);
            }

            if ($priorityFilter !== null) {
                $where[] = 't.priority = ?';
                $params[] = $priorityFilter;
            }

            if ($taskTypeFilter !== null) {
                $where[] = 't.task_type = ?';
                $params[] = $taskTypeFilter;
            }

            $params[] = $limit;

            $tasks = DB::select('
                SELECT t.id,
                       t.tree_id,
                       t.person_id,
                       t.queue_item_id,
                       t.task_type,
                       t.priority,
                       t.status,
                       t.outcome_state,
                       LEFT(t.research_question, 500) AS research_question,
                       LEFT(t.selection_reason, 300) AS selection_reason,
                       LEFT(t.scope_reason, 300) AS scope_reason,
                       LEFT(t.evidence_summary, 500) AS evidence_summary,
                       LEFT(t.conflicts_found, 300) AS conflicts_found,
                       LEFT(t.outcome_reason, 500) AS outcome_reason,
                       COALESCE(JSON_LENGTH(t.sources_checked), 0) AS sources_checked_count,
                       t.started_at,
                       t.completed_at,
                       t.updated_at,
                       CONCAT_WS(" ", p.given_name, p.surname) AS person_name,
                       p.birth_date,
                       p.death_date,
                       q.status AS queue_status,
                       q.priority_score AS queue_priority_score,
                       q.findings_count AS queue_findings_count,
                       q.review_items_count AS queue_review_items_count
                FROM genealogy_research_tasks t
                LEFT JOIN genealogy_persons p ON p.id = t.person_id
                LEFT JOIN genealogy_research_queue q ON q.id = t.queue_item_id
                WHERE '.implode(' AND ', $where).'
                ORDER BY FIELD(t.status, "processing", "queued", "failed", "completed", "cancelled"),
                         FIELD(t.priority, "urgent", "high", "medium", "low"),
                         t.updated_at DESC,
                         t.id DESC
                LIMIT ?
            ', $params);

            return [
                'tool' => 'research_task_queue',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'filters' => [
                    'status' => $status ?? 'all',
                    'priority' => $priorityFilter,
                    'task_type' => $taskTypeFilter,
                ],
                'status_counts' => $this->formatCountRows($statusCounts, 'status'),
                'count' => count($tasks),
                'tasks' => array_map(fn (object $task): array => $this->buildResearchTaskQueueRow($task), $tasks),
                'next_action' => count($tasks) > 0
                    ? 'open_research_task_profile_for_selected_task'
                    : 'no_matching_research_tasks',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'research_task_queue',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return read-only detail for one genealogy research task.
     */
    public function research_task_profile(int $tree_id, int $task_id): array
    {
        Log::info('GenealogyMCPService: research_task_profile called', [
            'tree_id' => $tree_id,
            'task_id' => $task_id,
        ]);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'research_task_profile',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $task = DB::selectOne('
                SELECT t.*,
                       CONCAT_WS(" ", p.given_name, p.surname) AS person_name,
                       p.gedcom_id AS person_gedcom_id,
                       p.birth_date,
                       p.birth_place,
                       p.death_date,
                       p.death_place,
                       p.living,
                       q.status AS queue_status,
                       q.priority_score AS queue_priority_score,
                       q.priority_reason AS queue_priority_reason,
                       q.question_type AS queue_question_type,
                       q.research_question AS queue_research_question,
                       q.selection_reason AS queue_selection_reason,
                       q.findings_count AS queue_findings_count,
                       q.review_items_count AS queue_review_items_count,
                       q.last_outcome_state AS queue_last_outcome_state,
                       q.last_outcome_reason AS queue_last_outcome_reason,
                       q.notes AS queue_notes,
                       q.updated_at AS queue_updated_at
                FROM genealogy_research_tasks t
                LEFT JOIN genealogy_persons p ON p.id = t.person_id
                LEFT JOIN genealogy_research_queue q ON q.id = t.queue_item_id
                WHERE t.id = ?
                  AND t.tree_id = ?
                LIMIT 1
            ', [$task_id, $tree_id]);

            if (! $task) {
                return [
                    'tool' => 'research_task_profile',
                    'success' => false,
                    'error' => "Research task not found in tree {$tree_id}: {$task_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            return [
                'tool' => 'research_task_profile',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'task_id' => $task_id,
                'task' => $this->buildResearchTaskProfileRow($task),
                'next_action' => $this->researchTaskNextAction($task),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'research_task_profile',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Dry-run-first creation of a guarded genealogy research task.
     *
     * @param  array<int, mixed>  $related_people_used
     * @param  array<int, mixed>  $sources_checked
     * @param  array<string, mixed>  $parameters
     */
    public function research_task_create(
        int $tree_id,
        ?int $person_id,
        string $task_type,
        string $priority,
        string $research_question,
        ?string $selection_reason = null,
        ?string $scope_reason = null,
        array $related_people_used = [],
        array $sources_checked = [],
        ?string $evidence_summary = null,
        ?string $conflicts_found = null,
        ?string $outcome_state = 'needs_research',
        ?string $outcome_reason = null,
        array $parameters = [],
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: research_task_create called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'task_type' => $task_type,
            'priority' => $priority,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('research_task_create');
        }

        $taskType = strtolower(trim($task_type));
        $validTypes = ['find_records', 'verify_facts', 'find_relatives', 'analyze_dna', 'suggest_sources', 'transcribe_document'];
        if (! in_array($taskType, $validTypes, true)) {
            return [
                'tool' => 'research_task_create',
                'success' => false,
                'error' => 'Invalid task_type. Use '.implode(', ', $validTypes).'.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $priority = strtolower(trim($priority));
        if (! in_array($priority, ['urgent', 'high', 'medium', 'low'], true)) {
            return [
                'tool' => 'research_task_create',
                'success' => false,
                'error' => 'Invalid priority. Use urgent, high, medium, or low.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $researchQuestion = trim($research_question);
        if (mb_strlen($researchQuestion) < 20) {
            return [
                'tool' => 'research_task_create',
                'success' => false,
                'error' => 'research_question must be at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'research_task_create',
                'success' => false,
                'dry_run' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'research_task_create',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $person = null;
            if ($person_id !== null) {
                $person = DB::selectOne(
                    'SELECT id, tree_id, given_name, surname, birth_date, death_date
                     FROM genealogy_persons
                     WHERE id = ?',
                    [$person_id]
                );
                if (! $person) {
                    return [
                        'tool' => 'research_task_create',
                        'success' => false,
                        'error' => "Person not found: {$person_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
                if ((int) $person->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'research_task_create',
                        'success' => false,
                        'error' => 'Person is not in the requested tree.',
                        'person_id' => $person_id,
                        'requested_tree_id' => $requestedTreeId,
                        'person_tree_id' => (int) $person->tree_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $relatedPeople = $this->normalizePositiveIdList($related_people_used, 50);
            $sourceIds = $this->normalizePositiveIdList($sources_checked, 50);
            $taskData = [
                'tree_id' => $requestedTreeId,
                'person_id' => $person_id,
                'task_type' => $taskType,
                'priority' => $priority,
                'research_question' => $researchQuestion,
                'selection_reason' => $this->compactToolText((string) $selection_reason, 1000),
                'scope_reason' => $this->compactToolText((string) $scope_reason, 1000),
                'related_people_used' => $relatedPeople,
                'sources_checked' => $sourceIds,
                'evidence_summary' => $this->compactToolText((string) $evidence_summary, 2000),
                'conflicts_found' => $this->compactToolText((string) $conflicts_found, 2000),
                'outcome_state' => $this->compactToolText((string) $outcome_state, 100),
                'outcome_reason' => $this->compactToolText((string) $outcome_reason, 1000),
                'parameters' => array_merge($parameters, [
                    'created_by_tool' => 'research_task_create',
                    'actor' => $actor,
                ]),
                'created_by' => null,
            ];

            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'person_name' => $person ? trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')) : null,
                'task_type' => $taskType,
                'priority' => $priority,
                'research_question' => $researchQuestion,
                'selection_reason' => $taskData['selection_reason'],
                'scope_reason' => $taskData['scope_reason'],
                'related_people_used' => $relatedPeople,
                'sources_checked' => $sourceIds,
                'evidence_summary' => $taskData['evidence_summary'],
                'conflicts_found' => $taskData['conflicts_found'],
                'outcome_state' => $taskData['outcome_state'],
                'outcome_reason' => $taskData['outcome_reason'],
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'research_task_create',
                    'success' => true,
                    'dry_run' => true,
                    'task_created' => false,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $taskId = $this->genealogy->createResearchTask($taskData);
            $this->logGenealogyWriteAudit(
                'research_task_create',
                'create_genealogy_research_task',
                $actor,
                $taskId !== null,
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'task_id' => $taskId,
                    'task_type' => $taskType,
                    'priority' => $priority,
                ],
                $plan,
                $taskId
                    ? "Delete or complete genealogy_research_tasks.id={$taskId} if this task was created in error."
                    : 'No task ID was returned; inspect genealogy_research_tasks and service logs before retrying.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'research_task_create',
                'success' => $taskId !== null,
                'dry_run' => false,
                'task_created' => $taskId !== null,
                'task_id' => $taskId,
                'plan' => $plan,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'research_task_create',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Extract source citations with URLs for media download
     *
     * @param  int  $tree_id  Tree ID
     * @param  int|null  $person_id  Optional: limit to specific person
     * @param  int  $limit  Maximum citations to return
     * @return array Source citations with URLs
     */
    public function source_extract(int $tree_id, ?int $person_id = null, int $limit = 50): array
    {
        Log::info('GenealogyMCPService: source_extract called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
        ]);

        try {
            $sql = '
                SELECT gc.id, gc.source_id, gc.page, gc.quality, gc.text,
                       s.title as source_title, s.author, s.publication AS publication_info,
                       m.nextcloud_path as media_path, m.local_filename as media_url,
                       p.id as person_id, p.given_name, p.surname
                FROM genealogy_citations gc
                JOIN genealogy_sources s ON gc.source_id = s.id
                LEFT JOIN genealogy_media m ON gc.media_id = m.id
                LEFT JOIN genealogy_persons p ON gc.person_id = p.id
                WHERE s.tree_id = ?
            ';
            $params = [$tree_id];

            if ($person_id) {
                $sql .= ' AND gc.person_id = ?';
                $params[] = $person_id;
            }

            $sql .= ' ORDER BY s.title, gc.page LIMIT ?';
            $params[] = $limit;

            $citations = DB::select($sql, $params);

            // Group by source
            $grouped = [];
            foreach ($citations as $c) {
                $sourceId = $c->source_id;
                if (! isset($grouped[$sourceId])) {
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

    /**
     * Return a read-only profile for one source row before evidence review.
     */
    public function source_profile(
        int $tree_id,
        int $source_id,
        int $citation_limit = 50,
        int $media_limit = 25
    ): array {
        Log::info('GenealogyMCPService: source_profile called', [
            'tree_id' => $tree_id,
            'source_id' => $source_id,
            'citation_limit' => $citation_limit,
            'media_limit' => $media_limit,
        ]);

        $citationLimit = max(1, min(200, $citation_limit));
        $mediaLimit = max(1, min(100, $media_limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'source_profile',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $source = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, uid, author, title, publication,
                        repository, repository_address, call_number, url, notes,
                        source_quality, quality_notes, source_category, information_quality,
                        classification_confidence, classification_method, classification_notes,
                        classified_at, rag_indexed_at, created_at, updated_at
                 FROM genealogy_sources
                 WHERE id = ?',
                [$source_id]
            );
            if (! $source) {
                return [
                    'tool' => 'source_profile',
                    'success' => false,
                    'error' => "Source not found: {$source_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $source->tree_id !== $tree_id) {
                return [
                    'tool' => 'source_profile',
                    'success' => false,
                    'error' => 'Source is not in the requested tree.',
                    'source_id' => $source_id,
                    'requested_tree_id' => $tree_id,
                    'source_tree_id' => (int) $source->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $summary = DB::selectOne(
                'SELECT COUNT(*) AS citations,
                        COUNT(DISTINCT p.id) AS linked_persons,
                        COUNT(DISTINCT f.id) AS linked_families,
                        COUNT(DISTINCT m.id) AS linked_media
                 FROM genealogy_citations c
                 LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = ?
                 LEFT JOIN genealogy_families f ON f.id = c.family_id AND f.tree_id = ?
                 LEFT JOIN genealogy_media m ON m.id = c.media_id AND m.tree_id = ?
                 WHERE c.source_id = ?',
                [$tree_id, $tree_id, $tree_id, $source_id]
            );

            $citations = DB::select(
                'SELECT c.id, c.source_id, c.person_id, c.family_id, c.media_id,
                        c.fact_type, c.page, c.quality, c.evidence_type,
                        c.information_type, c.evidence_analysis, c.conclusion_id,
                        c.text, c.created_at,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS person_name,
                        p.birth_date AS person_birth_date,
                        p.death_date AS person_death_date,
                        f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                        TRIM(CONCAT(COALESCE(h.given_name, \'\'), \' \', COALESCE(h.surname, \'\'))) AS husband_name,
                        TRIM(CONCAT(COALESCE(w.given_name, \'\'), \' \', COALESCE(w.surname, \'\'))) AS wife_name,
                        m.title AS media_title, m.media_type, m.media_date,
                        m.nextcloud_path AS media_nextcloud_path,
                        m.local_filename AS media_local_filename,
                        m.file_exists AS media_file_exists,
                        m.rag_indexed_at AS media_rag_indexed_at,
                        m.updated_at AS media_updated_at
                 FROM genealogy_citations c
                 LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = ?
                 LEFT JOIN genealogy_families f ON f.id = c.family_id AND f.tree_id = ?
                 LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                 LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                 LEFT JOIN genealogy_media m ON m.id = c.media_id AND m.tree_id = ?
                 WHERE c.source_id = ?
                 ORDER BY c.id DESC
                 LIMIT ?',
                [$tree_id, $tree_id, $tree_id, $source_id, $citationLimit]
            );

            $linkedPersons = DB::select(
                'SELECT DISTINCT p.id AS person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS person_name,
                        p.birth_date, p.death_date, p.living
                 FROM genealogy_citations c
                 JOIN genealogy_persons p ON p.id = c.person_id
                 WHERE c.source_id = ?
                   AND p.tree_id = ?
                 ORDER BY person_name ASC, p.id ASC
                 LIMIT ?',
                [$source_id, $tree_id, $citationLimit]
            );

            $linkedFamilies = DB::select(
                'SELECT DISTINCT f.id AS family_id,
                        f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                        TRIM(CONCAT(COALESCE(h.given_name, \'\'), \' \', COALESCE(h.surname, \'\'))) AS husband_name,
                        TRIM(CONCAT(COALESCE(w.given_name, \'\'), \' \', COALESCE(w.surname, \'\'))) AS wife_name
                 FROM genealogy_citations c
                 JOIN genealogy_families f ON f.id = c.family_id
                 LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                 LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                 WHERE c.source_id = ?
                   AND f.tree_id = ?
                 ORDER BY f.id ASC
                 LIMIT ?',
                [$source_id, $tree_id, $citationLimit]
            );

            $linkedMedia = DB::select(
                'SELECT DISTINCT m.id AS media_id, m.title, m.media_type, m.media_date,
                        m.nextcloud_path, m.local_filename, m.file_format,
                        m.mime_type, m.file_exists, m.rag_indexed_at, m.updated_at
                 FROM genealogy_citations c
                 JOIN genealogy_media m ON m.id = c.media_id
                 WHERE c.source_id = ?
                   AND m.tree_id = ?
                 ORDER BY m.title ASC, m.id ASC
                 LIMIT ?',
                [$source_id, $tree_id, $mediaLimit]
            );

            return [
                'tool' => 'source_profile',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'source_id' => $source_id,
                'source' => $this->buildSourceProfileRow($source),
                'citations' => $this->excerptSourceCitationRows($citations),
                'linked_persons' => $linkedPersons,
                'linked_families' => $linkedFamilies,
                'linked_media' => array_map(fn (object $media): array => $this->buildSourceProfileMediaRow($media), $linkedMedia),
                'counts' => [
                    'citations' => (int) ($summary->citations ?? 0),
                    'citations_returned' => count($citations),
                    'linked_persons' => (int) ($summary->linked_persons ?? 0),
                    'linked_persons_returned' => count($linkedPersons),
                    'linked_families' => (int) ($summary->linked_families ?? 0),
                    'linked_families_returned' => count($linkedFamilies),
                    'linked_media' => (int) ($summary->linked_media ?? 0),
                    'linked_media_returned' => count($linkedMedia),
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_profile',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return a ranked, compact batch of people lacking direct person-source rows.
     */
    public function person_source_gap_batch(
        int $tree_id,
        int $limit = 50,
        string $focus = 'ranked',
        bool $compact = false,
        bool $include_reviewed = true
    ): array {
        Log::info('GenealogyMCPService: person_source_gap_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'focus' => $focus,
            'compact' => $compact,
            'include_reviewed' => $include_reviewed,
        ]);

        $limit = max(1, min(200, $limit));
        $focus = strtolower(trim($focus));
        if ($focus === '') {
            $focus = 'ranked';
        }
        if (! in_array($focus, ['ranked', 'vitals', 'connected', 'media', 'living'], true)) {
            return [
                'tool' => 'person_source_gap_batch',
                'success' => false,
                'error' => 'Invalid focus. Use ranked, vitals, connected, media, or living.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'person_source_gap_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $summary = DB::selectOne('
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN COALESCE(p.living, 0) = 1 THEN 1 ELSE 0 END) AS living,
                       SUM(CASE WHEN COALESCE(p.living, 0) = 0 THEN 1 ELSE 0 END) AS not_living,
                       SUM(CASE WHEN p.birth_date IS NOT NULL AND p.birth_date <> "" THEN 1 ELSE 0 END) AS with_birth_date,
                       SUM(CASE WHEN p.death_date IS NOT NULL AND p.death_date <> "" THEN 1 ELSE 0 END) AS with_death_date,
                       SUM(CASE
                           WHEN EXISTS (
                               SELECT 1
                               FROM agent_semantic_memory asm
                               WHERE asm.entity_type = "genealogy_person"
                                 AND asm.entity_id = p.id
                                 AND asm.fact_type = "source_gap_decision"
                           )
                           THEN 1 ELSE 0
                       END) AS reviewed_source_gaps,
                       SUM(CASE
                           WHEN NOT EXISTS (
                               SELECT 1
                               FROM agent_semantic_memory asm
                               WHERE asm.entity_type = "genealogy_person"
                                 AND asm.entity_id = p.id
                                 AND asm.fact_type = "source_gap_decision"
                           )
                           THEN 1 ELSE 0
                       END) AS unreviewed_source_gaps
                FROM genealogy_persons p
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = p.id
                WHERE p.tree_id = ?
                  AND ps.id IS NULL
            ', [$tree_id]);

            $order = match ($focus) {
                'vitals' => 'has_vitals DESC, p.living ASC, relationship_count DESC, p.id ASC',
                'connected' => 'relationship_count DESC, has_vitals DESC, p.living ASC, p.id ASC',
                'media' => 'media_count DESC, relationship_count DESC, p.living ASC, p.id ASC',
                'living' => 'p.living DESC, relationship_count DESC, has_vitals DESC, p.id ASC',
                default => 'priority_score DESC, p.living ASC, relationship_count DESC, p.id ASC',
            };
            $reviewedClause = $include_reviewed
                ? ''
                : 'AND NOT EXISTS (
                    SELECT 1
                    FROM agent_semantic_memory asm_gap
                    WHERE asm_gap.entity_type = "genealogy_person"
                      AND asm_gap.entity_id = p.id
                      AND asm_gap.fact_type = "source_gap_decision"
                )';

            $rows = DB::select("
                SELECT p.id AS person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS person_name,
                       p.given_name,
                       p.surname,
                       p.birth_date,
                       p.death_date,
                       p.living,
                       COUNT(DISTINCT pm.media_id) AS media_count,
                       COUNT(DISTINCT c.id) AS citation_count,
                       COUNT(DISTINCT CASE WHEN f_spouse.id IS NOT NULL THEN f_spouse.id END) AS spouse_family_count,
                       COUNT(DISTINCT CASE WHEN gc_child.person_id IS NOT NULL THEN gc_child.person_id END) AS child_count,
                       COUNT(DISTINCT CASE WHEN gc_self.family_id IS NOT NULL THEN gc_self.family_id END) AS parent_family_count,
                       (
                           COUNT(DISTINCT CASE WHEN f_spouse.id IS NOT NULL THEN f_spouse.id END)
                           + COUNT(DISTINCT CASE WHEN gc_child.person_id IS NOT NULL THEN gc_child.person_id END)
                           + COUNT(DISTINCT CASE WHEN gc_self.family_id IS NOT NULL THEN gc_self.family_id END)
                       ) AS relationship_count,
                       (
                           CASE WHEN COALESCE(p.living, 0) = 0 THEN 20 ELSE 0 END
                           + CASE WHEN p.birth_date IS NOT NULL AND p.birth_date <> '' THEN 10 ELSE 0 END
                           + CASE WHEN p.death_date IS NOT NULL AND p.death_date <> '' THEN 10 ELSE 0 END
                           + LEAST(COUNT(DISTINCT pm.media_id), 5)
                           + LEAST(COUNT(DISTINCT c.id), 5)
                           + LEAST(
                               COUNT(DISTINCT CASE WHEN f_spouse.id IS NOT NULL THEN f_spouse.id END)
                               + COUNT(DISTINCT CASE WHEN gc_child.person_id IS NOT NULL THEN gc_child.person_id END)
                               + COUNT(DISTINCT CASE WHEN gc_self.family_id IS NOT NULL THEN gc_self.family_id END),
                               10
                           )
                       ) AS priority_score,
                       CASE
                           WHEN (p.birth_date IS NOT NULL AND p.birth_date <> '')
                             OR (p.death_date IS NOT NULL AND p.death_date <> '')
                           THEN 1 ELSE 0
                       END AS has_vitals
                FROM genealogy_persons p
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = p.id
                LEFT JOIN genealogy_person_media pm ON pm.person_id = p.id
                LEFT JOIN genealogy_citations c ON c.person_id = p.id
                LEFT JOIN genealogy_families f_spouse ON f_spouse.husband_id = p.id OR f_spouse.wife_id = p.id
                LEFT JOIN genealogy_children gc_child ON gc_child.family_id = f_spouse.id
                LEFT JOIN genealogy_children gc_self ON gc_self.person_id = p.id
                WHERE p.tree_id = ?
                  AND ps.id IS NULL
                  {$reviewedClause}
                GROUP BY p.id, p.given_name, p.surname, p.birth_date, p.death_date, p.living
                ORDER BY {$order}
                LIMIT ?
            ", [$tree_id, $limit]);

            $personIds = array_map(static fn (object $row): int => (int) $row->person_id, $rows);
            $relatedSourceHints = $this->relatedSourceHintsForPersonSourceGaps($tree_id, $personIds);
            $sourceGapMemories = $this->sourceGapDecisionMemories($tree_id, $personIds);

            return [
                'tool' => 'person_source_gap_batch',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'focus' => $focus,
                'compact' => $compact,
                'include_reviewed' => $include_reviewed,
                'summary' => [
                    'total' => (int) ($summary->total ?? 0),
                    'living' => (int) ($summary->living ?? 0),
                    'not_living' => (int) ($summary->not_living ?? 0),
                    'with_birth_date' => (int) ($summary->with_birth_date ?? 0),
                    'with_death_date' => (int) ($summary->with_death_date ?? 0),
                    'reviewed_source_gaps' => (int) ($summary->reviewed_source_gaps ?? 0),
                    'unreviewed_source_gaps' => (int) ($summary->unreviewed_source_gaps ?? 0),
                ],
                'candidate_count' => count($rows),
                'candidates' => array_map(
                    fn (object $row): array => $this->buildPersonSourceGapCandidate(
                        $row,
                        $relatedSourceHints[(int) $row->person_id] ?? [],
                        $compact,
                        $sourceGapMemories[(int) $row->person_id] ?? null
                    ),
                    $rows
                ),
                'write_policy' => 'read_only_use_person_source_link_integrity_for_existing_citation_links_or_create_source_backed_proposals_for_new_evidence',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_source_gap_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Create bounded source citations and direct person/family source links for
     * evidence that has already been reviewed.
     *
     * This tool deliberately does not create sources; callers must use an existing
     * same-tree source row so the write stays narrow and auditable.
     *
     * @param  list<int>  $person_ids
     * @param  list<int>  $family_ids
     */
    public function source_citation_link_apply(
        int $tree_id,
        int $source_id,
        array $person_ids = [],
        array $family_ids = [],
        ?int $media_id = null,
        string $fact_type = 'person_source_context',
        ?string $page = null,
        ?int $quality = null,
        string $text = '',
        string $evidence_type = 'direct',
        string $information_type = 'secondary',
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: source_citation_link_apply called', [
            'tree_id' => $tree_id,
            'source_id' => $source_id,
            'person_ids' => $person_ids,
            'family_ids' => $family_ids,
            'media_id' => $media_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $personIds = array_slice(array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $person_ids),
            static fn (int $id): bool => $id > 0
        ))), 0, 20);
        $familyIds = array_slice(array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $family_ids),
            static fn (int $id): bool => $id > 0
        ))), 0, 20);

        $factType = trim($fact_type) !== '' ? trim($fact_type) : 'person_source_context';
        $page = $page !== null && trim($page) !== '' ? trim($page) : null;
        $text = trim($text);
        $evidenceType = strtolower(trim($evidence_type));
        $informationType = strtolower(trim($information_type));
        $quality = $quality === null ? null : max(0, min(100, $quality));

        if ($personIds === [] && $familyIds === []) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => 'At least one person_id or family_id is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (mb_strlen($text) < 20) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => 'text must explain the vetted evidence in at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! in_array($evidenceType, ['direct', 'indirect', 'negative'], true)) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => 'evidence_type must be direct, indirect, or negative.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! in_array($informationType, ['primary', 'secondary', 'indeterminate'], true)) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => 'information_type must be primary, secondary, or indeterminate.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'source_citation_link_apply',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $source = DB::selectOne(
                'SELECT id, tree_id, title FROM genealogy_sources WHERE id = ?',
                [$source_id]
            );
            if (! $source || (int) $source->tree_id !== $tree_id) {
                return [
                    'tool' => 'source_citation_link_apply',
                    'success' => false,
                    'error' => 'Source not found in requested tree.',
                    'source_id' => $source_id,
                    'tree_id' => $tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $media = null;
            if ($media_id !== null) {
                $media = DB::selectOne(
                    'SELECT id, tree_id, title FROM genealogy_media WHERE id = ?',
                    [$media_id]
                );
                if (! $media || (int) $media->tree_id !== $tree_id) {
                    return [
                        'tool' => 'source_citation_link_apply',
                        'success' => false,
                        'error' => 'Media not found in requested tree.',
                        'media_id' => $media_id,
                        'tree_id' => $tree_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $people = $this->loadSameTreePeopleForSourceCitation($tree_id, $personIds);
            $families = $this->loadSameTreeFamiliesForSourceCitation($tree_id, $familyIds);

            $missingPeople = array_values(array_diff($personIds, array_keys($people)));
            $missingFamilies = array_values(array_diff($familyIds, array_keys($families)));
            if ($missingPeople !== [] || $missingFamilies !== []) {
                return [
                    'tool' => 'source_citation_link_apply',
                    'success' => false,
                    'error' => 'One or more targets were not found in the requested tree.',
                    'missing_person_ids' => $missingPeople,
                    'missing_family_ids' => $missingFamilies,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $targets = [];
            foreach ($people as $personId => $person) {
                $targets[] = [
                    'target_type' => 'person',
                    'target_id' => $personId,
                    'name' => trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
                ];
            }
            foreach ($families as $familyId => $family) {
                $targets[] = [
                    'target_type' => 'family',
                    'target_id' => $familyId,
                    'name' => trim((string) ($family->husband_name ?? '').' / '.(string) ($family->wife_name ?? '')),
                ];
            }

            $preview = [
                'source_id' => (int) $source->id,
                'source_title' => $source->title ?? null,
                'media_id' => $media ? (int) $media->id : null,
                'media_title' => $media->title ?? null,
                'fact_type' => $factType,
                'page' => $page,
                'quality' => $quality,
                'evidence_type' => $evidenceType,
                'information_type' => $informationType,
                'text_excerpt' => $this->tailText($text, 500),
                'targets' => $targets,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'source_citation_link_apply',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'target_count' => count($targets),
                    'preview' => $preview,
                    'next_action' => 'review_preview_then_rerun_with_dry_run_false_confirm_true',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $citationRowsInserted = 0;
            $personSourceRowsTouched = 0;
            $familySourceRowsTouched = 0;

            foreach ($people as $personId => $person) {
                $citationRowsInserted += DB::affectingStatement(
                    'INSERT INTO genealogy_citations
                        (source_id, person_id, family_id, media_id, fact_type, page, quality, evidence_type, information_type, evidence_analysis, text, created_at)
                     SELECT ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                     WHERE NOT EXISTS (
                         SELECT 1 FROM genealogy_citations
                         WHERE source_id = ?
                           AND person_id <=> ?
                           AND family_id IS NULL
                           AND media_id <=> ?
                           AND fact_type = ?
                           AND page <=> ?
                     )',
                    [
                        $source_id,
                        $personId,
                        $media_id,
                        $factType,
                        $page,
                        $quality,
                        $evidenceType,
                        $informationType,
                        'Created by Genea MCP from reviewed evidence; do not extend beyond the citation text.',
                        $text,
                        $source_id,
                        $personId,
                        $media_id,
                        $factType,
                        $page,
                    ]
                );

                $personSourceRowsTouched += DB::affectingStatement(
                    'INSERT INTO genealogy_person_sources (person_id, source_id, page, quality, created_at)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE page = VALUES(page), quality = VALUES(quality)',
                    [$personId, $source_id, $page, $quality === null ? null : (string) $quality]
                );
            }

            foreach ($families as $familyId => $family) {
                $citationRowsInserted += DB::affectingStatement(
                    'INSERT INTO genealogy_citations
                        (source_id, person_id, family_id, media_id, fact_type, page, quality, evidence_type, information_type, evidence_analysis, text, created_at)
                     SELECT ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                     WHERE NOT EXISTS (
                         SELECT 1 FROM genealogy_citations
                         WHERE source_id = ?
                           AND person_id IS NULL
                           AND family_id <=> ?
                           AND media_id <=> ?
                           AND fact_type = ?
                           AND page <=> ?
                     )',
                    [
                        $source_id,
                        $familyId,
                        $media_id,
                        $factType,
                        $page,
                        $quality,
                        $evidenceType,
                        $informationType,
                        'Created by Genea MCP from reviewed evidence; do not extend beyond the citation text.',
                        $text,
                        $source_id,
                        $familyId,
                        $media_id,
                        $factType,
                        $page,
                    ]
                );

                $familySourceRowsTouched += DB::affectingStatement(
                    'INSERT INTO genealogy_family_sources (family_id, source_id, page, quality, created_at)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE page = VALUES(page), quality = VALUES(quality)',
                    [$familyId, $source_id, $page, $quality === null ? null : (string) $quality]
                );
            }

            if ($personIds !== []) {
                $placeholders = implode(',', array_fill(0, count($personIds), '?'));
                DB::update(
                    "UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE id IN ({$placeholders})",
                    $personIds
                );
            }
            DB::update('UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW() WHERE id = ?', [$source_id]);

            $this->logGenealogyWriteAudit(
                'source_citation_link_apply',
                'create_reviewed_source_citations',
                $actor,
                true,
                ['tree_id' => $tree_id, 'source_id' => $source_id, 'person_ids' => $personIds, 'family_ids' => $familyIds],
                ['preview' => $preview, 'citation_rows_inserted' => $citationRowsInserted],
                'Delete the created genealogy_citations rows and remove/update genealogy_person_sources or genealogy_family_sources links if this citation was wrong.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'source_citation_link_apply',
                'success' => true,
                'dry_run' => false,
                'applied' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'source_id' => $source_id,
                'target_count' => count($targets),
                'citation_rows_inserted' => $citationRowsInserted,
                'person_source_rows_touched' => $personSourceRowsTouched,
                'family_source_rows_touched' => $familySourceRowsTouched,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_citation_link_apply',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function evidence_capture_plan(
        ?int $tree_id = null,
        int $limit = 50,
        bool $dry_run = false,
        bool $compact = true,
        bool $eligible_only = false
    ): array {
        Log::info('GenealogyMCPService: evidence_capture_plan called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'compact' => $compact,
            'eligible_only' => $eligible_only,
        ]);

        $requestedTreeId = $tree_id !== null ? $this->normalizeRequiredTreeId($tree_id) : null;
        if ($tree_id !== null && $requestedTreeId === null) {
            return $this->treeIdRequiredResponse('evidence_capture_plan');
        }

        try {
            $tree = null;
            if ($requestedTreeId !== null) {
                $tree = $this->genealogy->getTree($requestedTreeId);
                if (! $tree) {
                    return [
                        'tool' => 'evidence_capture_plan',
                        'success' => false,
                        'error' => "Tree not found: {$requestedTreeId}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $planner = app(GenealogyEvidenceAssetCapturePlanService::class);
            $payload = $planner->collect($limit, $dry_run, $compact, $eligible_only, $requestedTreeId);
            if ($compact) {
                $payload = $planner->compactPayload($payload);
            }

            return array_merge([
                'tool' => 'evidence_capture_plan',
                'success' => ($payload['status'] ?? null) !== 'observe_unavailable',
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Exception $e) {
            return [
                'tool' => 'evidence_capture_plan',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function evidence_capture_review(
        int $tree_id,
        int $limit = 50,
        bool $execute = false,
        bool $confirm = false,
        bool $compact = true,
        bool $eligible_only = false
    ): array {
        Log::info('GenealogyMCPService: evidence_capture_review called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'execute' => $execute,
            'confirm' => $confirm,
            'compact' => $compact,
            'eligible_only' => $eligible_only,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('evidence_capture_review');
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'evidence_capture_review',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $reviewer = app(GenealogyEvidenceAssetCaptureReviewService::class);
            $payload = $reviewer->collect($limit, $execute, $confirm, $compact, $eligible_only, $requestedTreeId);
            if ($compact) {
                $payload = $reviewer->compactPayload($payload);
            }

            return array_merge([
                'tool' => 'evidence_capture_review',
                'success' => ! in_array(($payload['status'] ?? null), ['blocked', 'observe_unavailable'], true),
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Exception $e) {
            return [
                'tool' => 'evidence_capture_review',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function evidence_capture_execute(
        int $tree_id,
        int $limit = 25,
        bool $save_preflight = false,
        bool $execute_capture = false,
        bool $confirm_noncanonical_write = false,
        bool $confirm_download = false,
        bool $confirm_storage_write = false,
        bool $confirm_genealogy_link = false,
        ?int $max_bytes = null,
        bool $compact = true
    ): array {
        Log::info('GenealogyMCPService: evidence_capture_execute called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'save_preflight' => $save_preflight,
            'execute_capture' => $execute_capture,
            'confirm_noncanonical_write' => $confirm_noncanonical_write,
            'confirm_download' => $confirm_download,
            'confirm_storage_write' => $confirm_storage_write,
            'confirm_genealogy_link' => $confirm_genealogy_link,
            'max_bytes' => $max_bytes,
            'compact' => $compact,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('evidence_capture_execute');
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'evidence_capture_execute',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $executor = app(GenealogyEvidenceAssetCaptureExecutorService::class);
            $payload = $executor->collect(
                $limit,
                $save_preflight,
                $confirm_noncanonical_write,
                $compact,
                $execute_capture,
                $confirm_download,
                $confirm_storage_write,
                $confirm_genealogy_link,
                $max_bytes,
                $requestedTreeId
            );
            if ($compact) {
                $payload = $executor->compactPayload($payload);
            }

            return array_merge([
                'tool' => 'evidence_capture_execute',
                'success' => ! in_array(($payload['status'] ?? null), ['blocked', 'observe_unavailable'], true),
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Exception $e) {
            return [
                'tool' => 'evidence_capture_execute',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function evidence_capture_direct(
        int $tree_id,
        string $url,
        ?int $source_id = null,
        ?int $person_id = null,
        ?int $family_id = null,
        ?string $label = null,
        ?string $asset_type = null,
        ?string $content_type = null,
        bool $dry_run = true,
        bool $confirm = false,
        bool $confirm_download = false,
        bool $confirm_storage_write = false,
        bool $confirm_genealogy_link = false,
        ?int $max_bytes = null,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: evidence_capture_direct called', [
            'tree_id' => $tree_id,
            'source_id' => $source_id,
            'person_id' => $person_id,
            'family_id' => $family_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'confirm_download' => $confirm_download,
            'confirm_storage_write' => $confirm_storage_write,
            'confirm_genealogy_link' => $confirm_genealogy_link,
            'max_bytes' => $max_bytes,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('evidence_capture_direct');
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url === '' || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [
                'tool' => 'evidence_capture_direct',
                'success' => false,
                'error' => 'url must be a non-empty http or https URL.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($source_id === null && $person_id === null && $family_id === null) {
            return [
                'tool' => 'evidence_capture_direct',
                'success' => false,
                'error' => 'At least one same-tree source_id, person_id, or family_id is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && (! $confirm || ! $confirm_download || ! $confirm_storage_write)) {
            return [
                'tool' => 'evidence_capture_direct',
                'success' => false,
                'error' => 'confirm=true, confirm_download=true, and confirm_storage_write=true are required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'evidence_capture_direct',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $source = null;
            if ($source_id !== null) {
                $source = DB::selectOne(
                    'SELECT id, tree_id, title FROM genealogy_sources WHERE id = ?',
                    [$source_id]
                );
                if (! $source || (int) $source->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'evidence_capture_direct',
                        'success' => false,
                        'error' => 'Source not found in requested tree.',
                        'source_id' => $source_id,
                        'tree_id' => $requestedTreeId,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $person = null;
            if ($person_id !== null) {
                $person = DB::selectOne(
                    'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
                    [$person_id]
                );
                if (! $person || (int) $person->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'evidence_capture_direct',
                        'success' => false,
                        'error' => 'Person not found in requested tree.',
                        'person_id' => $person_id,
                        'tree_id' => $requestedTreeId,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $family = null;
            if ($family_id !== null) {
                $family = DB::selectOne(
                    'SELECT id, tree_id FROM genealogy_families WHERE id = ?',
                    [$family_id]
                );
                if (! $family || (int) $family->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'evidence_capture_direct',
                        'success' => false,
                        'error' => 'Family not found in requested tree.',
                        'family_id' => $family_id,
                        'tree_id' => $requestedTreeId,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $normalizedContentType = $this->evidenceCaptureDirectContentType($content_type, $extension);
            $normalizedAssetType = $this->evidenceCaptureDirectAssetType($asset_type, $normalizedContentType, $extension);
            $capturePolicy = $this->evidenceCaptureDirectPolicy($normalizedContentType, $extension);
            $locatorHash = substr(sha1($url), 0, 16);
            $safeLabel = trim((string) ($label ?? ''));
            if ($safeLabel === '') {
                $safeLabel = trim((string) ($source->title ?? basename($path) ?: 'Evidence asset'));
            }

            $candidate = [
                'download_url' => $url,
                'label' => $safeLabel,
                'asset_type' => $normalizedAssetType,
                'content_type' => $normalizedContentType,
                'extension' => $extension,
                'tree_id' => $requestedTreeId,
                'source_id' => $source_id,
                'person_id' => $person_id,
                'family_id' => $family_id,
            ];

            $plan = [
                'schema' => 'genealogy_evidence_asset_capture_plan.v1',
                'label' => $safeLabel,
                'provider' => $this->evidenceCaptureDirectProvider($host, $url),
                'asset_type' => $normalizedAssetType,
                'capture_policy' => $capturePolicy,
                'locator_hash' => $locatorHash,
                'capture_ready' => true,
                'approval_ready' => true,
                'tree_id' => $requestedTreeId,
                'source_id' => $source_id,
                'person_id' => $person_id,
                'family_id' => $family_id,
            ];

            $reviewDetails = [
                'schema' => 'genealogy_evidence_asset_capture_review.v1',
                'tree_id' => $requestedTreeId,
                'source_target_ref' => 'direct:evidence_capture_direct:'.$locatorHash,
                'capture_plan_count' => 1,
                'target_storage' => 'ft_reference_area',
                'plans' => [$plan],
                'line_item_decisions' => [[
                    'plan_index' => 0,
                    'action' => 'attach',
                    'reason_code' => 'operator_confirmed_direct_capture',
                ]],
                'approval_required_before' => ['download', 'ft_storage_write', 'person_family_source_media_link'],
                'approved_for_executor' => true,
            ];

            $sourceDetails = [
                'tree_id' => $requestedTreeId,
                'source_id' => $source_id,
                'person_id' => $person_id,
                'family_id' => $family_id,
                'evidence_assets' => [$candidate],
            ];

            if ($dry_run) {
                return [
                    'tool' => 'evidence_capture_direct',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'tree_id' => $requestedTreeId,
                    'tree_name' => $tree->name ?? null,
                    'preview' => [
                        'locator_hash' => $locatorHash,
                        'capture_policy' => $capturePolicy,
                        'provider' => $plan['provider'],
                        'asset_type' => $normalizedAssetType,
                        'content_type' => $normalizedContentType,
                        'extension' => $extension,
                        'label' => $safeLabel,
                        'targets' => [
                            'source_id' => $source_id,
                            'person_id' => $person_id,
                            'family_id' => $family_id,
                        ],
                        'will_download' => false,
                        'will_write_ft_storage' => false,
                        'will_link_genealogy' => false,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $storage = app(GenealogyEvidenceAssetCaptureStorageService::class);
            $payload = $storage->captureApprovedReview(
                (object) ['id' => 0],
                $reviewDetails,
                $sourceDetails,
                [
                    'max_bytes' => $max_bytes,
                    'link_confirmed' => $confirm_genealogy_link,
                ]
            );

            $mediaIds = array_values(array_filter(array_map(
                static fn (array $item): ?int => isset($item['media_id']) ? (int) $item['media_id'] : null,
                array_filter($payload['items'] ?? [], 'is_array')
            )));
            $success = (int) ($payload['summary']['failures'] ?? 0) === 0
                && ((int) ($payload['summary']['files_saved'] ?? 0) + (int) ($payload['summary']['media_rows_reused'] ?? 0)) > 0;

            $this->logGenealogyWriteAudit(
                'evidence_capture_direct',
                'capture_direct_evidence_asset',
                $actor,
                $success,
                [
                    'tree_id' => $requestedTreeId,
                    'source_id' => $source_id,
                    'person_id' => $person_id,
                    'family_id' => $family_id,
                    'media_ids' => $mediaIds,
                ],
                [
                    'locator_hash' => $locatorHash,
                    'provider' => $plan['provider'],
                    'capture_policy' => $capturePolicy,
                    'content_type' => $normalizedContentType,
                    'extension' => $extension,
                ],
                'Remove created media rows and person/family/citation links shown in affected_records if this direct capture was incorrect.',
                [
                    'confirm_genealogy_link' => $confirm_genealogy_link,
                    'summary' => $payload['summary'] ?? [],
                ]
            );

            return array_merge([
                'tool' => 'evidence_capture_direct',
                'success' => $success,
                'dry_run' => false,
                'applied' => $success,
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Exception $e) {
            return [
                'tool' => 'evidence_capture_direct',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    private function evidenceCaptureDirectContentType(?string $contentType, string $extension): string
    {
        $normalized = strtolower(trim((string) $contentType));
        if ($normalized !== '') {
            return trim(explode(';', $normalized, 2)[0]);
        }

        return match ($extension) {
            'jpg', 'jpeg', 'jfif' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'jp2', 'j2k', 'jpf', 'jpx' => 'image/jp2',
            'pdf' => 'application/pdf',
            'html', 'htm' => 'text/html',
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            default => 'application/octet-stream',
        };
    }

    private function evidenceCaptureDirectAssetType(?string $assetType, string $contentType, string $extension): string
    {
        $normalized = strtolower(trim((string) $assetType));
        if (in_array($normalized, ['image', 'photo', 'pdf', 'html', 'document', 'audio', 'video'], true)) {
            return $normalized === 'photo' ? 'image' : $normalized;
        }

        if (str_starts_with($contentType, 'image/')
            || in_array($extension, ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'tif', 'tiff', 'jp2', 'j2k', 'jpf', 'jpx'], true)) {
            return 'image';
        }
        if ($contentType === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }
        if (str_contains($contentType, 'html') || in_array($extension, ['html', 'htm'], true)) {
            return 'html';
        }
        if (str_starts_with($contentType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($contentType, 'video/')) {
            return 'video';
        }

        return 'document';
    }

    private function evidenceCaptureDirectPolicy(string $contentType, string $extension): string
    {
        if (str_contains($contentType, 'html') || in_array($extension, ['html', 'htm'], true)) {
            return 'html_snapshot_allowed';
        }

        return 'direct_download_allowed';
    }

    private function evidenceCaptureDirectProvider(string $host, string $url = ''): string
    {
        $locator = strtolower($url);
        if ($host === 'catalog.archives.gov'
            || str_ends_with($host, '.archives.gov')
            || str_contains($host, 'naraprodstorage')
            || str_contains($locator, 'naraprodstorage')) {
            return 'nara';
        }
        if (str_contains($host, 'loc.gov')) {
            return 'loc';
        }
        if (str_contains($host, 'archive.org')) {
            return 'internet_archive';
        }

        return 'web';
    }

    /**
     * @param  array<int, int>|null  $source_ids
     */
    public function source_media_backfill(
        int $tree_id,
        string $since = '14d',
        int $limit = 25,
        string $order = 'oldest',
        ?array $source_ids = null,
        bool $dry_run = true,
        bool $confirm = false,
        bool $confirm_download = false,
        bool $confirm_storage_write = false,
        bool $nara_metadata_snapshot = true,
        bool $retry_blocked = false,
        bool $link_sources = true,
        ?int $max_bytes = null
    ): array {
        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('source_media_backfill');
        }

        if (! $dry_run && (! $confirm || ! $confirm_download || ! $confirm_storage_write)) {
            return [
                'tool' => 'source_media_backfill',
                'success' => false,
                'error' => 'confirm=true, confirm_download=true, and confirm_storage_write=true are required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $tree = $this->genealogy->getTree($requestedTreeId);
        if (! $tree) {
            return [
                'tool' => 'source_media_backfill',
                'success' => false,
                'error' => "Tree not found: {$requestedTreeId}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            is_array($source_ids) ? $source_ids : []
        ), static fn (int $id): bool => $id > 0)));
        $sourceIds = array_slice($sourceIds, 0, 50);

        $params = [
            '--mode' => 'sources',
            '--tree' => $requestedTreeId,
            '--since' => $since,
            '--limit' => max(1, min(100, $limit)),
            '--order' => $order === 'newest' ? 'newest' : 'oldest',
            '--json' => true,
        ];

        foreach ($sourceIds as $sourceId) {
            $params['--source-id'][] = $sourceId;
        }

        if ($dry_run) {
            $params['--dry-run'] = true;
        } else {
            $params['--confirm-download'] = $confirm_download;
            $params['--confirm-storage-write'] = $confirm_storage_write;
        }

        if ($nara_metadata_snapshot) {
            $params['--nara-metadata-snapshot'] = true;
        }
        if ($retry_blocked) {
            $params['--retry-blocked'] = true;
        }
        if (! $link_sources) {
            $params['--skip-link'] = true;
        }
        if ($max_bytes !== null && $max_bytes > 0) {
            $params['--max-bytes'] = $max_bytes;
        }

        try {
            $exit = Artisan::call('genealogy:backfill-source-media', $params);
            $output = trim(Artisan::output());
            $payload = json_decode($output, true);

            if (! is_array($payload)) {
                return [
                    'tool' => 'source_media_backfill',
                    'success' => false,
                    'exit_code' => $exit,
                    'error' => 'Backfill command did not return JSON.',
                    'output_excerpt' => mb_substr($output, 0, 1000),
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            return array_merge([
                'tool' => 'source_media_backfill',
                'success' => $exit === 0 && ! in_array(($payload['status'] ?? null), ['blocked', 'failed'], true),
                'exit_code' => $exit,
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Throwable $e) {
            return [
                'tool' => 'source_media_backfill',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @param  array<int, int>|null  $media_ids
     */
    public function nara_placeholder_capture_batch(
        int $tree_id,
        int $limit = 25,
        ?array $media_ids = null,
        bool $dry_run = true,
        bool $confirm = false,
        bool $confirm_download = false,
        bool $confirm_storage_write = false,
        bool $metadata_snapshot = true,
        bool $compact = true,
        ?int $max_bytes = null
    ): array {
        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('nara_placeholder_capture_batch');
        }

        if (! $dry_run && (! $confirm || ! $confirm_download || ! $confirm_storage_write)) {
            return [
                'tool' => 'nara_placeholder_capture_batch',
                'success' => false,
                'error' => 'confirm=true, confirm_download=true, and confirm_storage_write=true are required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $tree = $this->genealogy->getTree($requestedTreeId);
        if (! $tree) {
            return [
                'tool' => 'nara_placeholder_capture_batch',
                'success' => false,
                'error' => "Tree not found: {$requestedTreeId}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $mediaIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            is_array($media_ids) ? $media_ids : []
        ), static fn (int $id): bool => $id > 0)));
        $mediaIds = array_slice($mediaIds, 0, 100);
        $limit = max(1, min(250, $limit));

        try {
            $capture = app(NaraCatalogMediaCaptureService::class);
            $payload = $capture->collect(
                treeId: $requestedTreeId,
                limit: $limit,
                mediaIds: $mediaIds,
                executeCapture: ! $dry_run,
                downloadConfirmed: $confirm_download,
                storageConfirmed: $confirm_storage_write,
                metadataSnapshot: $metadata_snapshot,
                compact: $compact,
                maxBytes: $max_bytes
            );

            return array_merge([
                'tool' => 'nara_placeholder_capture_batch',
                'success' => ! in_array(($payload['status'] ?? null), ['blocked', 'failed'], true),
                'dry_run' => $dry_run,
                'applied' => ! $dry_run && ! in_array(($payload['status'] ?? null), ['blocked', 'failed'], true),
                'tree_name' => $tree->name ?? null,
                'timestamp' => now()->toIso8601String(),
            ], $payload);
        } catch (\Throwable $e) {
            return [
                'tool' => 'nara_placeholder_capture_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @param  list<int>  $personIds
     * @return array<int, object>
     */
    private function loadSameTreePeopleForSourceCitation(int $treeId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $rows = DB::select(
            "SELECT id, tree_id, given_name, surname
             FROM genealogy_persons
             WHERE tree_id = ? AND id IN ({$placeholders})",
            array_merge([$treeId], $personIds)
        );

        $people = [];
        foreach ($rows as $row) {
            $people[(int) $row->id] = $row;
        }

        return $people;
    }

    /**
     * @param  list<int>  $familyIds
     * @return array<int, object>
     */
    private function loadSameTreeFamiliesForSourceCitation(int $treeId, array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
        $rows = DB::select(
            "SELECT f.id,
                    f.tree_id,
                    TRIM(CONCAT(COALESCE(h.given_name, ''), ' ', COALESCE(h.surname, ''))) AS husband_name,
                    TRIM(CONCAT(COALESCE(w.given_name, ''), ' ', COALESCE(w.surname, ''))) AS wife_name
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
             WHERE f.tree_id = ? AND f.id IN ({$placeholders})",
            array_merge([$treeId], $familyIds)
        );

        $families = [];
        foreach ($rows as $row) {
            $families[(int) $row->id] = $row;
        }

        return $families;
    }

    /**
     * @param  array<int>  $personIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function relatedSourceHintsForPersonSourceGaps(int $treeId, array $personIds): array
    {
        $personIds = array_values(array_unique(array_filter(array_map(
            static fn ($personId): int => (int) $personId,
            $personIds
        ))));

        if ($personIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $params = [
            ...$personIds, $treeId, $treeId,
            ...$personIds, $treeId, $treeId,
            ...$personIds, $treeId, $treeId,
        ];

        $rows = DB::select("
            SELECT *
            FROM (
                SELECT target.id AS target_person_id,
                       CASE WHEN parent.id = family.husband_id THEN 'father' ELSE 'mother' END AS relation,
                       parent.id AS related_person_id,
                       TRIM(CONCAT(COALESCE(parent.given_name, ''), ' ', COALESCE(parent.surname, ''))) AS related_person_name,
                       src.id AS source_id,
                       src.title AS source_title,
                       src.source_quality,
                       src.information_quality,
                       ps.page,
                       ps.quality
                FROM genealogy_persons target
                JOIN genealogy_children child_link ON child_link.person_id = target.id
                JOIN genealogy_families family ON family.id = child_link.family_id
                JOIN genealogy_persons parent ON parent.id IN (family.husband_id, family.wife_id)
                JOIN genealogy_person_sources ps ON ps.person_id = parent.id
                JOIN genealogy_sources src ON src.id = ps.source_id
                WHERE target.id IN ({$placeholders})
                  AND target.tree_id = ?
                  AND src.tree_id = ?

                UNION ALL

                SELECT target.id AS target_person_id,
                       'spouse' AS relation,
                       spouse.id AS related_person_id,
                       TRIM(CONCAT(COALESCE(spouse.given_name, ''), ' ', COALESCE(spouse.surname, ''))) AS related_person_name,
                       src.id AS source_id,
                       src.title AS source_title,
                       src.source_quality,
                       src.information_quality,
                       ps.page,
                       ps.quality
                FROM genealogy_persons target
                JOIN genealogy_families family ON family.husband_id = target.id OR family.wife_id = target.id
                JOIN genealogy_persons spouse ON spouse.id = CASE
                    WHEN family.husband_id = target.id THEN family.wife_id
                    ELSE family.husband_id
                END
                JOIN genealogy_person_sources ps ON ps.person_id = spouse.id
                JOIN genealogy_sources src ON src.id = ps.source_id
                WHERE target.id IN ({$placeholders})
                  AND target.tree_id = ?
                  AND src.tree_id = ?

                UNION ALL

                SELECT target.id AS target_person_id,
                       'child' AS relation,
                       child.id AS related_person_id,
                       TRIM(CONCAT(COALESCE(child.given_name, ''), ' ', COALESCE(child.surname, ''))) AS related_person_name,
                       src.id AS source_id,
                       src.title AS source_title,
                       src.source_quality,
                       src.information_quality,
                       ps.page,
                       ps.quality
                FROM genealogy_persons target
                JOIN genealogy_families family ON family.husband_id = target.id OR family.wife_id = target.id
                JOIN genealogy_children child_link ON child_link.family_id = family.id
                JOIN genealogy_persons child ON child.id = child_link.person_id
                JOIN genealogy_person_sources ps ON ps.person_id = child.id
                JOIN genealogy_sources src ON src.id = ps.source_id
                WHERE target.id IN ({$placeholders})
                  AND target.tree_id = ?
                  AND src.tree_id = ?
            ) hints
            ORDER BY target_person_id,
                     FIELD(relation, 'father', 'mother', 'spouse', 'child'),
                     source_id
        ", $params);

        $hints = [];
        $seen = [];

        foreach ($rows as $row) {
            $targetId = (int) $row->target_person_id;
            $sourceId = (int) $row->source_id;
            $seenKey = $targetId.':'.$sourceId.':'.(string) $row->relation;

            if (isset($seen[$seenKey]) || count($hints[$targetId] ?? []) >= 3) {
                continue;
            }

            $seen[$seenKey] = true;
            $hints[$targetId][] = [
                'relation' => $row->relation,
                'related_person_id' => (int) $row->related_person_id,
                'related_person_name' => $row->related_person_name ?: null,
                'source_id' => $sourceId,
                'source_title' => $this->compactToolText((string) ($row->source_title ?? ''), 180),
                'source_quality' => $row->source_quality ?? null,
                'information_quality' => $row->information_quality ?? null,
                'page' => $this->compactToolText((string) ($row->page ?? ''), 120),
                'quality' => $row->quality ?? null,
            ];
        }

        return $hints;
    }

    /**
     * @param  array<int>  $personIds
     * @return array<int, array<string, mixed>>
     */
    private function sourceGapDecisionMemories(int $treeId, array $personIds): array
    {
        $personIds = array_values(array_unique(array_filter(array_map(
            static fn ($personId): int => (int) $personId,
            $personIds
        ))));

        if ($personIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $rows = DB::select("
            SELECT asm.id AS memory_id,
                   asm.entity_id AS person_id,
                   asm.fact_value,
                   asm.confidence,
                   asm.consensus_status,
                   asm.updated_at
            FROM agent_semantic_memory asm
            JOIN genealogy_persons p ON p.id = asm.entity_id
            WHERE asm.entity_type = 'genealogy_person'
              AND asm.fact_type = 'source_gap_decision'
              AND p.tree_id = ?
              AND asm.entity_id IN ({$placeholders})
            ORDER BY asm.entity_id, asm.updated_at DESC, asm.id DESC
        ", [$treeId, ...$personIds]);

        $memories = [];
        foreach ($rows as $row) {
            $personId = (int) $row->person_id;
            if (isset($memories[$personId])) {
                continue;
            }

            $value = json_decode((string) ($row->fact_value ?? ''), true);
            $value = is_array($value) ? $value : [];
            $memories[$personId] = [
                'memory_id' => (int) $row->memory_id,
                'decision' => $this->compactToolText((string) ($value['decision'] ?? ''), 120),
                'reason' => $this->compactToolText((string) ($value['reason'] ?? ''), 300),
                'source_ids' => array_values(array_filter(array_map(
                    static fn ($sourceId): int => (int) $sourceId,
                    is_array($value['source_ids'] ?? null) ? $value['source_ids'] : []
                ))),
                'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
                'consensus_status' => $row->consensus_status ?? null,
                'reviewed_at' => $value['reviewed_at'] ?? $row->updated_at ?? null,
            ];
        }

        return $memories;
    }

    /**
     * List media rows not linked to a person or family.
     */
    public function media_unlinked(int $tree_id, int $limit = 50, ?string $type = null, ?string $status = null): array
    {
        Log::info('GenealogyMCPService: media_unlinked called', [
            'tree_id' => $tree_id,
            'type' => $type,
            'status' => $status,
        ]);

        $limit = max(1, min(200, $limit));
        $status = $status !== null && trim($status) !== '' ? strtolower(trim($status)) : 'all';

        if (! in_array($status, ['all', 'uncited', 'citation_only'], true)) {
            return [
                'tool' => 'media_unlinked',
                'success' => false,
                'error' => "Invalid status filter: {$status}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_unlinked',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $params = [$tree_id];
            $typeClause = '';
            if ($type !== null && $type !== '') {
                $typeClause = 'AND gm.media_type = ?';
                $params[] = $type;
            }

            $statusClause = match ($status) {
                'uncited' => 'AND gc.id IS NULL',
                'citation_only' => 'AND gc.id IS NOT NULL',
                default => '',
            };

            $params[] = $limit;

            $rows = DB::select("
                SELECT gm.id,
                       gm.title,
                       gm.media_type,
                       gm.media_date,
                       gm.local_filename,
                       gm.nextcloud_path,
                       gm.original_path,
                       gm.description,
                       gm.transcription_text,
                       gm.ai_description,
                       gm.file_exists,
                       gm.has_faces,
                       gm.face_count,
                       gm.rag_indexed_at,
                       gm.updated_at,
                       COUNT(DISTINCT gc.id) AS citation_count,
                       COUNT(DISTINCT gc.source_id) AS citation_source_count,
                       (
                           SELECT COUNT(*)
                           FROM genealogy_face_match_queue q
                           WHERE q.media_id = gm.id
                             AND q.tree_id = gm.tree_id
                       ) AS face_match_count,
                       (
                           SELECT GROUP_CONCAT(DISTINCT CONCAT(
                                      COALESCE(NULLIF(q.face_name, ''), '(unnamed)'),
                                      CASE
                                          WHEN p.id IS NOT NULL THEN CONCAT(
                                              ' -> ',
                                              TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))),
                                              ' #',
                                              p.id
                                          )
                                          ELSE ''
                                      END,
                                      ' [',
                                      q.status,
                                      ']'
                                  )
                                  ORDER BY q.confidence_score DESC, q.id DESC
                                  SEPARATOR ' | ')
                           FROM genealogy_face_match_queue q
                           LEFT JOIN genealogy_persons p ON p.id = q.suggested_person_id
                           WHERE q.media_id = gm.id
                             AND q.tree_id = gm.tree_id
                       ) AS face_match_hints,
                       (
                           SELECT COUNT(DISTINCT CASE
                                      WHEN NULLIF(ff.person_name, '') IS NOT NULL
                                           OR ff.genealogy_person_id IS NOT NULL
                                      THEN CONCAT(COALESCE(NULLIF(ff.person_name, ''), '(unnamed)'), '#', COALESCE(ff.genealogy_person_id, 0))
                                      ELSE NULL
                                  END)
                           FROM file_registry fr
                           JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                           WHERE COALESCE(ff.hidden, 0) = 0
                             AND (
                                  fr.current_path = gm.nextcloud_path
                                  OR fr.current_path = gm.original_path
                                  OR fr.original_path = gm.nextcloud_path
                                  OR fr.original_path = gm.original_path
                             )
                       ) AS registry_named_face_count,
                       (
                           SELECT GROUP_CONCAT(DISTINCT CONCAT(
                                      COALESCE(NULLIF(ff.person_name, ''), '(unnamed)'),
                                      CASE
                                          WHEN ff.genealogy_person_id IS NOT NULL THEN CONCAT(' #', ff.genealogy_person_id)
                                          ELSE ''
                                      END,
                                      CASE
                                          WHEN COALESCE(ff.verified, 0) = 1 THEN ' [verified]'
                                          ELSE ''
                                      END,
                                      CASE
                                          WHEN ff.source IS NOT NULL THEN CONCAT(' [', ff.source, ']')
                                          ELSE ''
                                      END
                                  )
                                  ORDER BY ff.verified DESC, ff.confidence DESC, ff.id DESC
                                  SEPARATOR ' | ')
                           FROM file_registry fr
                           JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                           WHERE COALESCE(ff.hidden, 0) = 0
                             AND (
                                  NULLIF(ff.person_name, '') IS NOT NULL
                                  OR ff.genealogy_person_id IS NOT NULL
                             )
                             AND (
                                  fr.current_path = gm.nextcloud_path
                                  OR fr.current_path = gm.original_path
                                  OR fr.original_path = gm.nextcloud_path
                                  OR fr.original_path = gm.original_path
                             )
                       ) AS registry_face_hints
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id
                WHERE gm.tree_id = ?
                  {$typeClause}
                  {$statusClause}
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
                GROUP BY gm.id,
                         gm.title,
                         gm.media_type,
                         gm.media_date,
                         gm.local_filename,
                         gm.nextcloud_path,
                         gm.original_path,
                         gm.description,
                         gm.transcription_text,
                         gm.ai_description,
                         gm.file_exists,
                         gm.has_faces,
                         gm.face_count,
                         gm.rag_indexed_at,
                         gm.updated_at
                ORDER BY
                  CASE WHEN gm.file_exists = 1 THEN 0 ELSE 1 END,
                  gm.updated_at DESC,
                  gm.id DESC
                LIMIT ?
            ", $params);

            $countParams = [$tree_id];
            if ($typeClause !== '') {
                $countParams[] = $type;
            }

            $count = DB::selectOne("
                SELECT COUNT(DISTINCT gm.id) AS count
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id
                WHERE gm.tree_id = ?
                  {$typeClause}
                  {$statusClause}
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
            ", $countParams);

            $breakdown = DB::selectOne("
                SELECT COUNT(DISTINCT gm.id) AS total,
                       COUNT(DISTINCT CASE WHEN gc.id IS NOT NULL THEN gm.id END) AS citation_only,
                       COUNT(DISTINCT CASE WHEN gc.id IS NULL THEN gm.id END) AS uncited
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id
                WHERE gm.tree_id = ?
                  {$typeClause}
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
            ", $countParams);

            return [
                'tool' => 'media_unlinked',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'type' => $type,
                'status' => $status,
                'count' => (int) ($count->count ?? count($rows)),
                'counts' => [
                    'all' => (int) ($breakdown->total ?? 0),
                    'citation_only' => (int) ($breakdown->citation_only ?? 0),
                    'uncited' => (int) ($breakdown->uncited ?? 0),
                ],
                'sample_count' => count($rows),
                'rows' => $rows,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_unlinked',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return compact action buckets for unlinked media.
     */
    public function media_triage_batch(int $tree_id, int $limit = 50, ?string $status = null, bool $include_paths = false, bool $summary_only = false): array
    {
        Log::info('GenealogyMCPService: media_triage_batch called', [
            'tree_id' => $tree_id,
            'status' => $status,
            'include_paths' => $include_paths,
            'summary_only' => $summary_only,
        ]);

        $limit = max(1, min(200, $limit));
        $status = $status !== null && trim($status) !== '' ? strtolower(trim($status)) : 'uncited';

        if (! in_array($status, ['all', 'uncited', 'citation_only'], true)) {
            return [
                'tool' => 'media_triage_batch',
                'success' => false,
                'error' => "Invalid status filter: {$status}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_triage_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $statusClause = match ($status) {
                'uncited' => 'AND gc.id IS NULL',
                'citation_only' => 'AND gc.id IS NOT NULL',
                default => '',
            };

            $rows = DB::select("
                SELECT gm.id,
                       gm.title,
                       gm.media_type,
                       gm.media_date,
                       gm.local_filename,
                       gm.nextcloud_path,
                       gm.original_path,
                       LEFT(REPLACE(REPLACE(COALESCE(gm.description, ''), CHAR(10), ' '), CHAR(13), ' '), 220) AS description_excerpt,
                       gm.file_exists,
                       gm.has_faces,
                       gm.face_count,
                       gm.rag_indexed_at,
                       gm.updated_at,
                       COUNT(DISTINCT gc.id) AS citation_count,
                       COUNT(DISTINCT gc.source_id) AS citation_source_count,
                       COUNT(DISTINCT CASE WHEN gc.person_id IS NOT NULL THEN gc.id END) AS person_citation_count,
                       COUNT(DISTINCT CASE WHEN gc.family_id IS NOT NULL THEN gc.id END) AS family_citation_count,
                       COUNT(DISTINCT CASE WHEN gc.id IS NOT NULL AND gc.person_id IS NULL AND gc.family_id IS NULL THEN gc.id END) AS source_only_citation_count,
                       (
                           SELECT COUNT(*)
                           FROM genealogy_face_match_queue q
                           WHERE q.media_id = gm.id
                             AND q.tree_id = gm.tree_id
                       ) AS face_match_count,
                       (
                           SELECT COUNT(*)
                           FROM genealogy_face_match_queue q
                           WHERE q.media_id = gm.id
                             AND q.tree_id = gm.tree_id
                             AND q.status IN ('approved', 'auto_linked', 'pending')
                       ) AS positive_face_match_count,
                       (
                           SELECT GROUP_CONCAT(DISTINCT CONCAT(
                                      COALESCE(NULLIF(q.face_name, ''), '(unnamed)'),
                                      CASE
                                          WHEN p.id IS NOT NULL THEN CONCAT(
                                              ' -> ',
                                              TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))),
                                              ' #',
                                              p.id
                                          )
                                          ELSE ''
                                      END,
                                      ' [',
                                      q.status,
                                      ']'
                                  )
                                  ORDER BY q.confidence_score DESC, q.id DESC
                                  SEPARATOR ' | ')
                           FROM genealogy_face_match_queue q
                           LEFT JOIN genealogy_persons p ON p.id = q.suggested_person_id
                           WHERE q.media_id = gm.id
                             AND q.tree_id = gm.tree_id
                       ) AS face_match_hints,
                       (
                           SELECT COUNT(DISTINCT CASE
                                      WHEN NULLIF(ff.person_name, '') IS NOT NULL
                                           OR ff.genealogy_person_id IS NOT NULL
                                      THEN CONCAT(COALESCE(NULLIF(ff.person_name, ''), '(unnamed)'), '#', COALESCE(ff.genealogy_person_id, 0))
                                      ELSE NULL
                                  END)
                           FROM file_registry fr
                           JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                           WHERE COALESCE(ff.hidden, 0) = 0
                             AND (
                                  fr.current_path = gm.nextcloud_path
                                  OR fr.current_path = gm.original_path
                                  OR fr.original_path = gm.nextcloud_path
                                  OR fr.original_path = gm.original_path
                             )
                       ) AS registry_named_face_count,
                       (
                           SELECT GROUP_CONCAT(DISTINCT CONCAT(
                                      COALESCE(NULLIF(ff.person_name, ''), '(unnamed)'),
                                      CASE
                                          WHEN ff.genealogy_person_id IS NOT NULL THEN CONCAT(' #', ff.genealogy_person_id)
                                          ELSE ''
                                      END,
                                      CASE
                                          WHEN COALESCE(ff.verified, 0) = 1 THEN ' [verified]'
                                          ELSE ''
                                      END,
                                      CASE
                                          WHEN ff.source IS NOT NULL THEN CONCAT(' [', ff.source, ']')
                                          ELSE ''
                                      END
                                  )
                                  ORDER BY ff.verified DESC, ff.confidence DESC, ff.id DESC
                                  SEPARATOR ' | ')
                           FROM file_registry fr
                           JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                           WHERE COALESCE(ff.hidden, 0) = 0
                             AND (
                                  NULLIF(ff.person_name, '') IS NOT NULL
                                  OR ff.genealogy_person_id IS NOT NULL
                             )
                             AND (
                                  fr.current_path = gm.nextcloud_path
                                  OR fr.current_path = gm.original_path
                                  OR fr.original_path = gm.nextcloud_path
                                  OR fr.original_path = gm.original_path
                             )
                       ) AS registry_face_hints
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id
                WHERE gm.tree_id = ?
                  {$statusClause}
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
                GROUP BY gm.id,
                         gm.title,
                         gm.media_type,
                         gm.media_date,
                         gm.local_filename,
                         gm.nextcloud_path,
                         gm.original_path,
                         gm.description,
                         gm.file_exists,
                         gm.has_faces,
                         gm.face_count,
                         gm.rag_indexed_at,
                         gm.updated_at
                ORDER BY
                  CASE WHEN gm.file_exists = 1 THEN 0 ELSE 1 END,
                  gm.updated_at DESC,
                  gm.id DESC
                LIMIT ?
            ", [$tree_id, $limit]);

            $exactHits = $this->exactPersonHitsForMediaRows($tree_id, array_map(
                static fn (object $row): int => (int) $row->id,
                $rows
            ));
            $reviewMemories = $this->mediaReviewMemoriesForRows($tree_id, array_map(
                static fn (object $row): int => (int) $row->id,
                $rows
            ));

            $breakdown = DB::selectOne('
                SELECT COUNT(DISTINCT gm.id) AS total,
                       COUNT(DISTINCT CASE WHEN gc.id IS NOT NULL THEN gm.id END) AS citation_only,
                       COUNT(DISTINCT CASE WHEN gc.id IS NULL THEN gm.id END) AS uncited,
                       COUNT(DISTINCT CASE WHEN gc.id IS NOT NULL AND gc.person_id IS NULL AND gc.family_id IS NULL THEN gm.id END) AS source_only_citation,
                       COUNT(DISTINCT CASE WHEN gc.person_id IS NOT NULL THEN gm.id END) AS person_targeted_citation,
                       COUNT(DISTINCT CASE WHEN gc.family_id IS NOT NULL THEN gm.id END) AS family_targeted_citation
                FROM genealogy_media gm
                LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
                LEFT JOIN genealogy_family_media gfm ON gfm.media_id = gm.id
                LEFT JOIN genealogy_citations gc ON gc.media_id = gm.id
                WHERE gm.tree_id = ?
                  AND gpm.id IS NULL
                  AND gfm.id IS NULL
            ', [$tree_id]);

            $buckets = [
                'likely_non_ft' => [],
                'exact_person_name_hits' => [],
                'registry_face_hints' => [],
                'face_match_positive' => [],
                'rejected_face_only' => [],
                'citation_only' => [],
                'source_only_citation' => [],
                'person_citation_link_repair' => [],
                'family_citation_link_review' => [],
                'research_lead_review' => [],
                'reviewed_unresolved' => [],
                'no_hints' => [],
            ];

            foreach ($rows as $row) {
                $mediaId = (int) $row->id;
                $exactHit = $exactHits[$mediaId] ?? null;
                $reviewMemory = $reviewMemories[$mediaId] ?? null;
                if ($reviewMemory !== null) {
                    $bucket = 'reviewed_unresolved';
                    $reason = 'Previously reviewed with no safe automatic link/delete action: '.$reviewMemory['decision'];
                    $priority = 'low';
                } else {
                    [$bucket, $reason, $priority] = $this->classifyUnlinkedMediaTriageRow($row, $exactHit);
                }

                $compact = [
                    'id' => $mediaId,
                    'title' => $row->title ?? null,
                    'type' => $row->media_type ?? null,
                    'date' => $row->media_date ?? null,
                    'file' => $row->local_filename ?? null,
                    'priority' => $priority,
                    'reason' => $reason,
                    'citations' => (int) ($row->citation_count ?? 0),
                    'citation_targets' => array_filter([
                        'person' => (int) ($row->person_citation_count ?? 0),
                        'family' => (int) ($row->family_citation_count ?? 0),
                        'source_only' => (int) ($row->source_only_citation_count ?? 0),
                    ], static fn (int $value): bool => $value > 0),
                    'hints' => array_filter([
                        'exact_persons' => $exactHit,
                        'face_matches' => $this->truncateHintList($row->face_match_hints ?? null),
                        'registry_faces' => $this->truncateHintList($row->registry_face_hints ?? null),
                    ], static fn ($value): bool => $value !== null && $value !== ''),
                    'text' => trim((string) ($row->description_excerpt ?? '')) ?: null,
                ];

                if ($reviewMemory !== null) {
                    $compact['review'] = array_filter([
                        'decision' => $reviewMemory['decision'] ?? null,
                        'reason' => $this->textProfile((string) ($reviewMemory['reason'] ?? ''), 220),
                        'reviewed_at' => $reviewMemory['reviewed_at'] ?? null,
                        'memory_id' => $reviewMemory['memory_id'] ?? null,
                    ], static fn ($value): bool => $value !== null && $value !== '');
                }

                if ($include_paths) {
                    $compact['paths'] = [
                        'nextcloud_path' => $row->nextcloud_path ?? null,
                        'original_path' => $row->original_path ?? null,
                    ];
                }

                $buckets[$bucket][] = $compact;
            }

            $response = [
                'tool' => 'media_triage_batch',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'status' => $status,
                'summary_only' => $summary_only,
                'sample_count' => count($rows),
                'counts' => [
                    'all' => (int) ($breakdown->total ?? 0),
                    'citation_only' => (int) ($breakdown->citation_only ?? 0),
                    'uncited' => (int) ($breakdown->uncited ?? 0),
                    'source_only_citation' => (int) ($breakdown->source_only_citation ?? 0),
                    'person_targeted_citation' => (int) ($breakdown->person_targeted_citation ?? 0),
                    'family_targeted_citation' => (int) ($breakdown->family_targeted_citation ?? 0),
                ],
                'bucket_counts' => array_map('count', $buckets),
                'guidance' => [
                    'likely_non_ft' => 'Quarantine/delete only after confirming no local FT relationship and no citation/person/family links.',
                    'source_only_citation' => 'Source-only citations are not broken person/family media links; keep as source context unless a review packet finds a specific person/family target.',
                    'person_citation_link_repair' => 'Person-targeted citations can usually be repaired through genealogy.media_link_integrity.',
                    'family_citation_link_review' => 'Family-targeted citations should become family-media proposals or direct family-media repair after review.',
                    'exact_person_name_hits' => 'Review context; exact string matches can be false positives in source query notes.',
                    'registry_face_hints' => 'Use as a lead unless verified/manual metadata is present.',
                    'rejected_face_only' => 'Do not link from these rejected or ignored face matches.',
                    'reviewed_unresolved' => 'Already reviewed and intentionally deferred; revisit only with new operator context, OCR, face confirmation, or external evidence.',
                ],
                'timestamp' => now()->toIso8601String(),
            ];

            if (! $summary_only) {
                $response['buckets'] = array_filter($buckets, static fn (array $items): bool => $items !== []);
            }

            return $response;
        } catch (\Exception $e) {
            return [
                'tool' => 'media_triage_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Store a durable, non-destructive review marker for unlinked media that was
     * inspected but cannot be safely linked or quarantined yet.
     *
     * Source-only citation media may be marked as source_context because it is
     * already serving source/citation provenance rather than a person/family link.
     *
     * @param  list<int>  $media_ids
     */
    public function media_review_mark(
        int $tree_id,
        array $media_ids,
        string $decision,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: media_review_mark called', [
            'tree_id' => $tree_id,
            'media_ids' => $media_ids,
            'decision' => $decision,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $mediaIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $media_ids),
            static fn (int $id): bool => $id > 0
        )));
        $mediaIds = array_slice($mediaIds, 0, 50);
        $decision = strtolower(trim($decision));
        $reason = trim($reason);
        $allowedDecisions = [
            'needs_visual_review',
            'needs_research',
            'source_context',
            'future_tree_context',
            'defer_no_safe_action',
        ];

        if ($mediaIds === []) {
            return [
                'tool' => 'media_review_mark',
                'success' => false,
                'error' => 'media_ids must include at least one positive media ID.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! in_array($decision, $allowedDecisions, true)) {
            return [
                'tool' => 'media_review_mark',
                'success' => false,
                'error' => 'Invalid decision. Allowed: '.implode(', ', $allowedDecisions),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '') {
            return [
                'tool' => 'media_review_mark',
                'success' => false,
                'error' => 'reason is required for media review marking.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_review_mark',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_review_mark',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->mediaRowsForQuarantine($tree_id, $mediaIds);
            $rowsById = [];
            foreach ($rows as $row) {
                $rowsById[(int) $row->id] = $row;
            }

            $results = [];
            $marked = [];
            $failed = [];

            foreach ($mediaIds as $mediaId) {
                $row = $rowsById[$mediaId] ?? null;
                if (! $row) {
                    $failed[] = $mediaId;
                    $results[] = [
                        'media_id' => $mediaId,
                        'eligible' => false,
                        'applied' => false,
                        'reason' => 'Media not found in requested tree.',
                    ];

                    continue;
                }

                $links = [
                    'person_links' => (int) ($row->person_links ?? 0),
                    'family_links' => (int) ($row->family_links ?? 0),
                    'citations' => (int) ($row->citations ?? 0),
                    'person_citations' => (int) ($row->person_citations ?? 0),
                    'family_citations' => (int) ($row->family_citations ?? 0),
                    'source_only_citations' => (int) ($row->source_only_citations ?? 0),
                    'face_queue_rows' => (int) ($row->face_queue_rows ?? 0),
                ];

                $hasPersonOrFamilyLinks = $links['person_links'] > 0 || $links['family_links'] > 0;
                $hasSubjectCitations = $links['person_citations'] > 0 || $links['family_citations'] > 0;
                $hasDisallowedCitations = $links['citations'] > 0 && $decision !== 'source_context';

                if ($hasPersonOrFamilyLinks || $hasSubjectCitations || $hasDisallowedCitations) {
                    $failed[] = $mediaId;
                    $results[] = [
                        'media_id' => $mediaId,
                        'title' => $row->title ?? null,
                        'eligible' => false,
                        'applied' => false,
                        'reason' => 'Refusing review mark because this media already has person/family links or subject-targeted citations; only source_context can be marked on source-only citation media.',
                        'links' => $links,
                    ];

                    continue;
                }

                if ($dry_run) {
                    $results[] = [
                        'media_id' => $mediaId,
                        'title' => $row->title ?? null,
                        'eligible' => true,
                        'applied' => false,
                        'decision' => $decision,
                        'reason' => 'Dry run only. Rerun with dry_run=false and confirm=true to store semantic review memory.',
                        'links' => $links,
                    ];

                    continue;
                }

                $memoryId = $this->recordMediaReviewMemory($tree_id, $mediaId, $decision, $reason, $actor);
                $marked[] = $mediaId;
                $results[] = [
                    'media_id' => $mediaId,
                    'title' => $row->title ?? null,
                    'eligible' => true,
                    'applied' => true,
                    'decision' => $decision,
                    'memory_id' => $memoryId,
                ];
            }

            if ($marked !== []) {
                $this->logGenealogyWriteAudit(
                    'media_review_mark',
                    'record_unlinked_media_review_memory',
                    $actor,
                    $failed === [],
                    ['tree_id' => $tree_id, 'media_ids' => $marked],
                    ['decision' => $decision, 'reason' => $reason],
                    'Delete or update the matching agent_semantic_memory rows with fact_type=media_triage_review if this review marker is wrong.',
                    ['dry_run' => false]
                );
            }

            return [
                'tool' => 'media_review_mark',
                'success' => $failed === [],
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => $dry_run,
                'decision' => $decision,
                'requested' => count($mediaIds),
                'eligible_or_applied' => count(array_filter($results, static fn (array $row): bool => (bool) ($row['eligible'] ?? false))),
                'applied_count' => count($marked),
                'failed_count' => count($failed),
                'results' => $results,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_review_mark',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Move unlinked, uncited media files into a tree-local quarantine folder and remove genealogy media rows.
     *
     * @param  list<int>  $media_ids
     */
    public function media_quarantine(
        int $tree_id,
        array $media_ids,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        ?string $bucket = null,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: media_quarantine called', [
            'tree_id' => $tree_id,
            'media_ids' => $media_ids,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'bucket' => $bucket,
            'actor' => $actor,
        ]);

        $mediaIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $media_ids),
            static fn (int $id): bool => $id > 0
        )));
        $mediaIds = array_slice($mediaIds, 0, 50);
        $reason = trim($reason);

        if ($mediaIds === []) {
            return [
                'tool' => 'media_quarantine',
                'success' => false,
                'error' => 'media_ids must include at least one positive media ID.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '') {
            return [
                'tool' => 'media_quarantine',
                'success' => false,
                'error' => 'reason is required for media quarantine.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_quarantine',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_quarantine',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->mediaRowsForQuarantine($tree_id, $mediaIds);
            $rowsById = [];
            foreach ($rows as $row) {
                $rowsById[(int) $row->id] = $row;
            }

            $treeRoot = app(GenealogyTreeRootResolver::class)->mediaRoot($tree_id);
            $quarantineFolder = $this->quarantineFolderPath($treeRoot, $bucket);
            $results = [];
            $applied = [];
            $failed = [];

            foreach ($mediaIds as $mediaId) {
                $row = $rowsById[$mediaId] ?? null;
                if (! $row) {
                    $failed[] = $mediaId;
                    $results[] = [
                        'media_id' => $mediaId,
                        'eligible' => false,
                        'applied' => false,
                        'reason' => 'Media not found in requested tree.',
                    ];

                    continue;
                }

                $eligibility = $this->quarantineEligibility($row, $treeRoot);
                $paths = $this->quarantinePathsForMedia($row, $quarantineFolder);

                if (! $eligibility['eligible']) {
                    $failed[] = $mediaId;
                    $results[] = [
                        'media_id' => $mediaId,
                        'title' => $row->title ?? null,
                        'eligible' => false,
                        'applied' => false,
                        'reason' => $eligibility['reason'],
                        'links' => $eligibility['links'],
                        'planned_nextcloud_path' => $paths['target_nextcloud_path'] ?? null,
                    ];

                    continue;
                }

                if ($dry_run) {
                    $results[] = [
                        'media_id' => $mediaId,
                        'title' => $row->title ?? null,
                        'eligible' => true,
                        'applied' => false,
                        'reason' => 'Dry run only. Rerun with dry_run=false and confirm=true to move file and remove media row.',
                        'planned_nextcloud_path' => $paths['target_nextcloud_path'] ?? null,
                        'file_move' => $paths['file_move'],
                    ];

                    continue;
                }

                $apply = $this->applyMediaQuarantine($tree_id, $row, $paths, $reason, $actor);
                $results[] = $apply;
                if (($apply['applied'] ?? false) === true) {
                    $applied[] = $mediaId;
                } else {
                    $failed[] = $mediaId;
                }
            }

            if ($applied !== []) {
                $this->logGenealogyWriteAudit(
                    'media_quarantine',
                    'quarantine_unlinked_uncited_media',
                    $actor,
                    $failed === [],
                    ['tree_id' => $tree_id, 'media_ids' => $applied],
                    ['reason' => $reason, 'bucket' => $bucket, 'quarantine_folder' => $quarantineFolder],
                    'Files were moved under the tree quarantine folder; restore by moving file paths back and recreating genealogy_media rows from backups/audit if needed.',
                    ['dry_run' => false]
                );
            }

            return [
                'tool' => 'media_quarantine',
                'success' => $failed === [],
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => $dry_run,
                'requested' => count($mediaIds),
                'eligible_or_applied' => count(array_filter($results, static fn (array $row): bool => (bool) ($row['eligible'] ?? false))),
                'applied_count' => count($applied),
                'failed_count' => count($failed),
                'quarantine_folder' => $quarantineFolder,
                'results' => $results,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_quarantine',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Run a bounded genealogy media RAG indexing batch through typed MCP parameters.
     */
    public function media_rag_batch(
        ?int $tree_id = null,
        int $limit = 20,
        int $max_seconds = 45,
        bool $dry_run = true,
        bool $confirm = false,
        bool $stats = false
    ): array {
        Log::info('GenealogyMCPService: media_rag_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'max_seconds' => $max_seconds,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'stats' => $stats,
        ]);

        $treeId = $tree_id !== null && $tree_id > 0 ? $tree_id : null;
        $limit = max(1, min(100, $limit));
        $maxSeconds = max(1, min(120, $max_seconds));

        if (! $stats && ! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_rag_batch',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($tree_id !== null && $treeId === null) {
            return [
                'tool' => 'media_rag_batch',
                'success' => false,
                'error' => 'tree_id must be a positive integer when provided.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            if ($treeId !== null && ! $this->genealogy->getTree($treeId)) {
                return [
                    'tool' => 'media_rag_batch',
                    'success' => false,
                    'error' => "Tree not found: {$treeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $args = [];
            if ($treeId !== null) {
                $args['--tree'] = $treeId;
            }

            if ($stats) {
                $args['--stats'] = true;
            } else {
                $args['--limit'] = $limit;
                $args['--max-seconds'] = $maxSeconds;
                if ($dry_run) {
                    $args['--dry-run'] = true;
                }
            }

            $exitCode = Artisan::call('genealogy:media-rag-index', $args);
            $output = (string) Artisan::output();

            preg_match('/\[ITEMS_PROCESSED:(\d+)\]/', $output, $matches);
            $processed = isset($matches[1]) ? (int) $matches[1] : null;

            return [
                'tool' => 'media_rag_batch',
                'success' => $exitCode === 0,
                'tree_id' => $treeId,
                'dry_run' => $dry_run,
                'confirm' => $confirm,
                'stats' => $stats,
                'limit' => $stats ? null : $limit,
                'max_seconds' => $stats ? null : $maxSeconds,
                'exit_code' => $exitCode,
                'items_processed' => $processed,
                'output_excerpt' => $this->tailText($output, 1600),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_rag_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Run a bounded genealogy person/place/source RAG indexing batch through typed MCP parameters.
     */
    public function rag_index_batch(
        ?int $tree_id = null,
        int $limit = 20,
        string $type = 'persons',
        bool $dry_run = true,
        bool $confirm = false,
        bool $stats = false,
        bool $reindex = false,
        bool $exclude_living = false
    ): array {
        Log::info('GenealogyMCPService: rag_index_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'type' => $type,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'stats' => $stats,
            'reindex' => $reindex,
            'exclude_living' => $exclude_living,
        ]);

        $treeId = $tree_id !== null && $tree_id > 0 ? $tree_id : null;
        $limit = max(1, min(100, $limit));
        $type = strtolower(trim($type));
        $allowedTypes = ['persons', 'places', 'sources', 'all'];

        if (! in_array($type, $allowedTypes, true)) {
            return [
                'tool' => 'rag_index_batch',
                'success' => false,
                'error' => 'Invalid type. Allowed: '.implode(', ', $allowedTypes),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $stats && ! $dry_run && ! $confirm) {
            return [
                'tool' => 'rag_index_batch',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($tree_id !== null && $treeId === null) {
            return [
                'tool' => 'rag_index_batch',
                'success' => false,
                'error' => 'tree_id must be a positive integer when provided.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            if ($treeId !== null && ! $this->genealogy->getTree($treeId)) {
                return [
                    'tool' => 'rag_index_batch',
                    'success' => false,
                    'error' => "Tree not found: {$treeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $args = [];
            if ($treeId !== null) {
                $args['--tree'] = $treeId;
            }

            if ($stats) {
                $args['--stats'] = true;
            } else {
                $args['--limit'] = $limit;
                $args['--type'] = $type;
                if ($dry_run) {
                    $args['--dry-run'] = true;
                }
                if ($reindex) {
                    $args['--reindex'] = true;
                }
                if ($exclude_living) {
                    $args['--exclude-living'] = true;
                }
            }

            $exitCode = Artisan::call('genealogy:rag-index', $args);
            $output = (string) Artisan::output();

            preg_match('/\|\s*Indexed\s*\|\s*([^|]+)\|/i', $output, $indexedMatches);
            preg_match('/\|\s*Failed\s*\|\s*([^|]+)\|/i', $output, $failedMatches);
            preg_match('/Found\s+(\d+)\s+persons?\s+to\s+index/i', $output, $foundPersonsMatches);

            return [
                'tool' => 'rag_index_batch',
                'success' => $exitCode === 0,
                'tree_id' => $treeId,
                'dry_run' => $dry_run,
                'confirm' => $confirm,
                'stats' => $stats,
                'type' => $stats ? null : $type,
                'limit' => $stats ? null : $limit,
                'reindex' => $stats ? null : $reindex,
                'exclude_living' => $stats ? null : $exclude_living,
                'exit_code' => $exitCode,
                'found_persons' => isset($foundPersonsMatches[1]) ? (int) $foundPersonsMatches[1] : null,
                'indexed' => isset($indexedMatches[1]) ? trim($indexedMatches[1]) : null,
                'failed' => isset($failedMatches[1]) ? trim($failedMatches[1]) : null,
                'output_excerpt' => $this->tailText($output, 1600),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'rag_index_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Run a bounded genealogy person embedding batch through typed MCP parameters.
     */
    public function person_embedding_batch(
        ?int $tree_id = null,
        int $limit = 50,
        bool $dry_run = true,
        bool $confirm = false,
        bool $stats = false,
        bool $reindex = false,
        bool $exclude_living = false
    ): array {
        Log::info('GenealogyMCPService: person_embedding_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'stats' => $stats,
            'reindex' => $reindex,
            'exclude_living' => $exclude_living,
        ]);

        $treeId = $tree_id !== null && $tree_id > 0 ? $tree_id : null;
        $limit = max(1, min(500, $limit));

        if (! $stats && ! $dry_run && ! $confirm) {
            return [
                'tool' => 'person_embedding_batch',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($tree_id !== null && $treeId === null) {
            return [
                'tool' => 'person_embedding_batch',
                'success' => false,
                'error' => 'tree_id must be a positive integer when provided.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            if ($treeId !== null && ! $this->genealogy->getTree($treeId)) {
                return [
                    'tool' => 'person_embedding_batch',
                    'success' => false,
                    'error' => "Tree not found: {$treeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($dry_run && ! $stats) {
                $rag = $this->rag_status($treeId);

                return [
                    'tool' => 'person_embedding_batch',
                    'success' => (bool) ($rag['success'] ?? false),
                    'tree_id' => $treeId,
                    'dry_run' => true,
                    'confirm' => $confirm,
                    'stats' => false,
                    'limit' => $limit,
                    'reindex' => $reindex,
                    'exclude_living' => $exclude_living,
                    'preview' => [
                        'command' => 'genealogy:embed-persons',
                        'will_write_embeddings' => false,
                        'missing_embeddings' => $rag['persons']['missing_embeddings'] ?? null,
                        'total_persons' => $rag['persons']['total'] ?? null,
                        'living_persons' => $rag['persons']['living'] ?? null,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $args = [];
            if ($treeId !== null) {
                $args['--tree-id'] = $treeId;
            }

            if ($stats) {
                $args['--stats'] = true;
            } else {
                $args['--limit'] = $limit;
                if ($reindex) {
                    $args['--reindex'] = true;
                }
                if ($exclude_living) {
                    $args['--exclude-living'] = true;
                }
            }

            $exitCode = Artisan::call('genealogy:embed-persons', $args);
            $output = (string) Artisan::output();

            preg_match('/\[ITEMS_PROCESSED:(\d+)\]/', $output, $processedMatches);
            preg_match('/\|\s*Processed this run\s*\|\s*([^|]+)\|/i', $output, $runMatches);
            preg_match('/\|\s*Embedded\s*\|\s*([^|]+)\|/i', $output, $embeddedMatches);
            preg_match('/\|\s*Failed\s*\|\s*([^|]+)\|/i', $output, $failedMatches);

            return [
                'tool' => 'person_embedding_batch',
                'success' => $exitCode === 0,
                'tree_id' => $treeId,
                'dry_run' => $dry_run,
                'confirm' => $confirm,
                'stats' => $stats,
                'limit' => $stats ? null : $limit,
                'reindex' => $stats ? null : $reindex,
                'exclude_living' => $stats ? null : $exclude_living,
                'exit_code' => $exitCode,
                'items_processed' => isset($processedMatches[1]) ? (int) $processedMatches[1] : null,
                'processed_this_run' => isset($runMatches[1]) ? trim($runMatches[1]) : null,
                'embedded' => isset($embeddedMatches[1]) ? trim($embeddedMatches[1]) : null,
                'failed' => isset($failedMatches[1]) ? trim($failedMatches[1]) : null,
                'output_excerpt' => $this->tailText($output, 1600),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_embedding_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function media_htr_batch(
        ?int $tree_id = null,
        int $limit = 10,
        bool $dry_run = true,
        bool $confirm = false,
        bool $status = false
    ): array {
        Log::info('GenealogyMCPService: media_htr_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'status' => $status,
        ]);

        $treeId = $tree_id !== null && $tree_id > 0 ? $tree_id : null;
        $limit = max(1, min(20, $limit));

        if (! $status && ! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_htr_batch',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($tree_id !== null && $treeId === null) {
            return [
                'tool' => 'media_htr_batch',
                'success' => false,
                'error' => 'tree_id must be a positive integer when provided.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            if ($treeId !== null && ! $this->genealogy->getTree($treeId)) {
                return [
                    'tool' => 'media_htr_batch',
                    'success' => false,
                    'error' => "Tree not found: {$treeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $args = [];
            if ($status) {
                $args['--status'] = true;
            } else {
                if ($treeId !== null) {
                    $args['--tree'] = $treeId;
                }
                $args['--limit'] = $limit;
                if ($dry_run) {
                    $args['--dry-run'] = true;
                }
            }

            $exitCode = Artisan::call('genealogy:transcribe-media', $args);
            $output = (string) Artisan::output();

            preg_match('/Found\s+(\d+)\s+eligible media records/i', $output, $eligibleMatches);
            preg_match('/Done\s+.\s+processed:\s+(\d+)\s+\|\s+skipped:\s+(\d+)\s+\|\s+failed:\s+(\d+)/iu', $output, $doneMatches);

            return [
                'tool' => 'media_htr_batch',
                'success' => $exitCode === 0,
                'tree_id' => $treeId,
                'dry_run' => $dry_run,
                'confirm' => $confirm,
                'status' => $status,
                'limit' => $status ? null : $limit,
                'exit_code' => $exitCode,
                'eligible_count' => isset($eligibleMatches[1]) ? (int) $eligibleMatches[1] : null,
                'processed' => isset($doneMatches[1]) ? (int) $doneMatches[1] : null,
                'skipped' => isset($doneMatches[2]) ? (int) $doneMatches[2] : null,
                'failed' => isset($doneMatches[3]) ? (int) $doneMatches[3] : null,
                'output_excerpt' => $this->tailText($output, 1800),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_htr_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Audit and optionally repair deterministic person-media link drift.
     */
    public function media_link_integrity(
        int $tree_id,
        bool $repair = false,
        bool $dry_run = true,
        int $limit = 25
    ): array {
        Log::info('GenealogyMCPService: media_link_integrity called', [
            'tree_id' => $tree_id,
            'repair' => $repair,
            'dry_run' => $dry_run,
        ]);

        $limit = max(0, min(100, $limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_link_integrity',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $before = $this->mediaLinkIntegrityCounts($tree_id);
            $samples = $limit > 0 ? $this->mediaLinkIntegritySamples($tree_id, $limit) : [];
            $applied = false;
            $after = $before;

            if ($repair && ! $dry_run) {
                $this->repairPrimaryPhotoMediaLinks($tree_id);
                $this->repairCitationMediaLinks($tree_id);
                $this->repairApprovedFaceMediaLinks($tree_id);

                $after = $this->mediaLinkIntegrityCounts($tree_id);
                $applied = true;

                $this->logGenealogyWriteAudit(
                    'media_link_integrity',
                    'repair_missing_person_media_links',
                    'genea-mcp',
                    true,
                    ['tree_id' => $tree_id],
                    ['before' => $before, 'after' => $after],
                    'Review genealogy_person_media rows created with Codex repair notes and delete those rows if rollback is needed.',
                    ['dry_run' => false]
                );
            }

            return [
                'tool' => 'media_link_integrity',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => $dry_run,
                'repair_requested' => $repair,
                'applied' => $applied,
                'counts' => [
                    'before' => $before,
                    'after' => $after,
                    'repaired' => $this->diffIntegrityCounts($before, $after),
                ],
                'samples' => $samples,
                'next_action' => $this->mediaLinkIntegrityNextAction($before, $repair, $dry_run),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_link_integrity',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Audit and optionally repair deterministic person-source link drift from existing citations.
     */
    public function person_source_link_integrity(
        int $tree_id,
        bool $repair = false,
        bool $dry_run = true,
        int $limit = 25,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: person_source_link_integrity called', [
            'tree_id' => $tree_id,
            'repair' => $repair,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(0, min(100, $limit));

        if ($repair && ! $dry_run && ! $confirm) {
            return [
                'tool' => 'person_source_link_integrity',
                'success' => false,
                'error' => 'confirm=true is required when repair=true and dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'person_source_link_integrity',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $before = $this->personSourceLinkIntegrityCounts($tree_id);
            $samples = $limit > 0 ? $this->personSourceLinkIntegritySamples($tree_id, $limit, $before) : [];
            $applied = false;
            $after = $before;

            if ($repair && ! $dry_run) {
                if (($before['citation_source_links_missing'] ?? 0) > 0) {
                    $this->repairCitationPersonSourceLinks($tree_id);
                }
                if (($before['family_citation_source_links_missing'] ?? 0) > 0) {
                    $this->repairFamilyCitationPersonSourceLinks($tree_id);
                }
                $after = $this->personSourceLinkIntegrityCounts($tree_id);
                $applied = true;

                $this->logGenealogyWriteAudit(
                    'person_source_link_integrity',
                    'repair_missing_person_source_links',
                    $actor,
                    true,
                    ['tree_id' => $tree_id],
                    ['before' => $before, 'after' => $after],
                    'Review genealogy_person_sources rows created from genealogy_citations and delete those rows if rollback is needed.',
                    ['dry_run' => false]
                );
            }

            return [
                'tool' => 'person_source_link_integrity',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => $dry_run,
                'repair_requested' => $repair,
                'applied' => $applied,
                'counts' => [
                    'before' => $before,
                    'after' => $after,
                    'repaired' => $this->diffIntegrityCounts($before, $after),
                ],
                'samples' => $samples,
                'next_action' => $this->personSourceLinkIntegrityNextAction($before, $repair, $dry_run),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_source_link_integrity',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Consolidate one byte-identical duplicate media row into a retained row.
     */
    public function media_duplicate_consolidate(
        int $tree_id,
        int $drop_media_id,
        int $keep_media_id,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: media_duplicate_consolidate called', [
            'tree_id' => $tree_id,
            'drop_media_id' => $drop_media_id,
            'keep_media_id' => $keep_media_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        if ($drop_media_id === $keep_media_id) {
            return [
                'tool' => 'media_duplicate_consolidate',
                'success' => false,
                'error' => 'drop_media_id and keep_media_id must be different.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (trim($reason) === '') {
            return [
                'tool' => 'media_duplicate_consolidate',
                'success' => false,
                'error' => 'A reason is required for duplicate media consolidation.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_duplicate_consolidate',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_duplicate_consolidate',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $plan = $this->buildMediaDuplicateConsolidationPlan($tree_id, $drop_media_id, $keep_media_id);
            if (! ($plan['eligible'] ?? false)) {
                return [
                    'tool' => 'media_duplicate_consolidate',
                    'success' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'dry_run' => $dry_run,
                    'eligible' => false,
                    'plan' => $plan,
                    'error' => implode('; ', $plan['errors'] ?? ['Duplicate media pair is not eligible.']),
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($dry_run) {
                return [
                    'tool' => 'media_duplicate_consolidate',
                    'success' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'dry_run' => true,
                    'eligible' => true,
                    'plan' => $plan,
                    'next_action' => 'rerun_with_dry_run_false_and_confirm_true_to_apply',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $apply = $this->applyMediaDuplicateConsolidation($tree_id, $drop_media_id, $keep_media_id, $plan);

            $fileDeleted = true;
            $dropPath = (string) ($plan['drop_media']['nextcloud_path'] ?? '');
            $keepPath = (string) ($plan['keep_media']['nextcloud_path'] ?? '');
            if ($dropPath !== '' && $dropPath !== $keepPath && file_exists($dropPath)) {
                $fileDeleted = @unlink($dropPath);
            }

            if ($dropPath !== '') {
                DB::update('
                    UPDATE file_registry
                    SET status = "deleted",
                        quarantine_status = "deleted",
                        quarantine_reason = "duplicate_media_consolidated",
                        quarantine_details = ?,
                        updated_at = NOW()
                    WHERE current_path = ?
                ', [
                    "Duplicate genealogy media {$drop_media_id} consolidated into {$keep_media_id}: {$reason}",
                    $dropPath,
                ]);
            }

            $this->logGenealogyWriteAudit(
                'media_duplicate_consolidate',
                'consolidate_byte_identical_media',
                $actor,
                $fileDeleted,
                [
                    'tree_id' => $tree_id,
                    'drop_media_id' => $drop_media_id,
                    'keep_media_id' => $keep_media_id,
                ],
                [
                    'reason' => $reason,
                    'sha256' => $plan['sha256'] ?? null,
                    'plan' => $plan['summary'] ?? [],
                ],
                "Restore the deleted duplicate file from backup, recreate genealogy_media.id={$drop_media_id}, and move links/citations back from media {$keep_media_id} if rollback is needed.",
                [
                    'dry_run' => false,
                    'file_deleted' => $fileDeleted,
                    'drop_path' => $dropPath,
                ]
            );

            return [
                'tool' => 'media_duplicate_consolidate',
                'success' => $fileDeleted,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => false,
                'eligible' => true,
                'applied' => true,
                'file_deleted' => $fileDeleted,
                'file_exists_after' => $dropPath !== '' ? file_exists($dropPath) : null,
                'plan' => $plan,
                'result' => $apply,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_duplicate_consolidate',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return a read-only profile for one media row before proposing links.
     */
    public function media_profile(int $tree_id, int $media_id, int $face_limit = 25): array
    {
        Log::info('GenealogyMCPService: media_profile called', [
            'tree_id' => $tree_id,
            'media_id' => $media_id,
            'face_limit' => $face_limit,
        ]);

        $faceLimit = max(1, min(100, $face_limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_profile',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $media = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, uid, media_type, title, media_date,
                        description, ai_description, transcription_text, transcription,
                        subject_tags, exif_data, original_path, nextcloud_path, local_filename,
                        file_format, mime_type, file_size, file_exists, width, height,
                        has_faces, face_count, analysis_status, analysis_error,
                        enrichment_status, enrichment_error, rag_indexed_at,
                        analyzed_at, enriched_at, imported_at, created_at, updated_at
                 FROM genealogy_media
                 WHERE id = ?',
                [$media_id]
            );
            if (! $media) {
                return [
                    'tool' => 'media_profile',
                    'success' => false,
                    'error' => "Media not found: {$media_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $media->tree_id !== $tree_id) {
                return [
                    'tool' => 'media_profile',
                    'success' => false,
                    'error' => 'Media is not in the requested tree.',
                    'media_id' => $media_id,
                    'requested_tree_id' => $tree_id,
                    'media_tree_id' => (int) $media->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $linkedPersons = DB::select(
                'SELECT pm.id AS link_id, pm.person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS person_name,
                        p.birth_date, p.death_date, p.living,
                        pm.is_primary, pm.face_confirmed, pm.notes,
                        pm.face_region_x, pm.face_region_y, pm.face_region_w, pm.face_region_h,
                        pm.created_at
                 FROM genealogy_person_media pm
                 JOIN genealogy_persons p ON p.id = pm.person_id
                 WHERE pm.media_id = ?
                   AND p.tree_id = ?
                 ORDER BY pm.is_primary DESC, person_name ASC, pm.id ASC',
                [$media_id, $tree_id]
            );

            $linkedFamilies = DB::select(
                'SELECT fm.id AS link_id, fm.family_id,
                        f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                        TRIM(CONCAT(COALESCE(h.given_name, \'\'), \' \', COALESCE(h.surname, \'\'))) AS husband_name,
                        TRIM(CONCAT(COALESCE(w.given_name, \'\'), \' \', COALESCE(w.surname, \'\'))) AS wife_name,
                        fm.created_at
                 FROM genealogy_family_media fm
                 JOIN genealogy_families f ON f.id = fm.family_id
                 LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                 LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                 WHERE fm.media_id = ?
                   AND f.tree_id = ?
                 ORDER BY fm.id ASC',
                [$media_id, $tree_id]
            );

            $citations = DB::select(
                'SELECT c.id, c.source_id, s.title AS source_title,
                        c.person_id, c.family_id, c.fact_type, c.page, c.quality,
                        c.evidence_type, c.information_type, c.evidence_analysis,
                        c.text, c.created_at
                 FROM genealogy_citations c
                 LEFT JOIN genealogy_sources s ON s.id = c.source_id
                 WHERE c.media_id = ?
                 ORDER BY c.id DESC
                 LIMIT 50',
                [$media_id]
            );

            $faceMatches = DB::select(
                'SELECT q.id, q.face_name, q.suggested_person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS suggested_person_name,
                        p.birth_date AS suggested_person_birth_date,
                        p.death_date AS suggested_person_death_date,
                        q.match_type, q.confidence_score, q.status,
                        q.face_region, q.match_details, q.review_notes,
                        q.created_at, q.updated_at
                 FROM genealogy_face_match_queue q
                 LEFT JOIN genealogy_persons p ON p.id = q.suggested_person_id
                 WHERE q.media_id = ?
                   AND q.tree_id = ?
                 ORDER BY FIELD(q.status, \'pending\', \'approved\', \'auto_linked\', \'rejected\', \'ignored\'),
                          q.confidence_score DESC,
                          q.id DESC
                 LIMIT ?',
                [$media_id, $tree_id, $faceLimit]
            );

            return [
                'tool' => 'media_profile',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'media_id' => $media_id,
                'media' => $this->buildMediaProfileRow($media),
                'linked_persons' => $linkedPersons,
                'linked_families' => $linkedFamilies,
                'citations' => $this->excerptCitationRows($citations),
                'face_matches' => $faceMatches,
                'counts' => [
                    'linked_persons' => count($linkedPersons),
                    'linked_families' => count($linkedFamilies),
                    'citations' => count($citations),
                    'face_matches_returned' => count($faceMatches),
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_profile',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return a compact review packet for one document/media row before OCR, HTR, linking, or fact proposals.
     */
    public function media_review_packet(
        int $tree_id,
        int $media_id,
        int $text_limit = 1600,
        int $hint_limit = 20,
        bool $summary_only = false
    ): array {
        Log::info('GenealogyMCPService: media_review_packet called', [
            'tree_id' => $tree_id,
            'media_id' => $media_id,
            'text_limit' => $text_limit,
            'hint_limit' => $hint_limit,
            'summary_only' => $summary_only,
        ]);

        $textLimit = max(400, min(5000, $text_limit));
        $hintLimit = max(1, min(100, $hint_limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_review_packet',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $media = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, uid, media_type, title, media_date,
                        description, ai_description, transcription_text, transcription,
                        subject_tags, exif_data, original_path, nextcloud_path, local_filename,
                        file_format, mime_type, file_size, file_exists, width, height,
                        has_faces, face_count, analysis_status, analysis_error,
                        enrichment_status, enrichment_error, rag_indexed_at,
                        analyzed_at, enriched_at, imported_at, created_at, updated_at
                 FROM genealogy_media
                 WHERE id = ?',
                [$media_id]
            );

            if (! $media) {
                return [
                    'tool' => 'media_review_packet',
                    'success' => false,
                    'error' => "Media not found: {$media_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $media->tree_id !== $tree_id) {
                return [
                    'tool' => 'media_review_packet',
                    'success' => false,
                    'error' => 'Media is not in the requested tree.',
                    'media_id' => $media_id,
                    'requested_tree_id' => $tree_id,
                    'media_tree_id' => (int) $media->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $linkedPersons = DB::select(
                'SELECT pm.id AS link_id, pm.person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS person_name,
                        p.birth_date, p.death_date, p.living,
                        pm.is_primary, pm.face_confirmed, pm.notes,
                        pm.created_at
                 FROM genealogy_person_media pm
                 JOIN genealogy_persons p ON p.id = pm.person_id
                 WHERE pm.media_id = ?
                   AND p.tree_id = ?
                 ORDER BY pm.is_primary DESC, person_name ASC, pm.id ASC
                 LIMIT 50',
                [$media_id, $tree_id]
            );

            $linkedFamilies = DB::select(
                'SELECT fm.id AS link_id, fm.family_id,
                        f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                        TRIM(CONCAT(COALESCE(h.given_name, \'\'), \' \', COALESCE(h.surname, \'\'))) AS husband_name,
                        TRIM(CONCAT(COALESCE(w.given_name, \'\'), \' \', COALESCE(w.surname, \'\'))) AS wife_name,
                        fm.created_at
                 FROM genealogy_family_media fm
                 JOIN genealogy_families f ON f.id = fm.family_id
                 LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                 LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                 WHERE fm.media_id = ?
                   AND f.tree_id = ?
                 ORDER BY fm.id ASC
                 LIMIT 50',
                [$media_id, $tree_id]
            );

            $citations = DB::select(
                'SELECT c.id, c.source_id, s.title AS source_title, s.url AS source_url,
                        c.person_id, c.family_id, c.fact_type, c.page, c.quality,
                        c.evidence_type, c.information_type, c.evidence_analysis,
                        c.text, c.created_at,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS person_name,
                        TRIM(CONCAT(COALESCE(h.given_name, \'\'), \' \', COALESCE(h.surname, \'\'))) AS husband_name,
                        TRIM(CONCAT(COALESCE(w.given_name, \'\'), \' \', COALESCE(w.surname, \'\'))) AS wife_name
                 FROM genealogy_citations c
                 LEFT JOIN genealogy_sources s ON s.id = c.source_id
                 LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = ?
                 LEFT JOIN genealogy_families f ON f.id = c.family_id AND f.tree_id = ?
                 LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                 LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                 WHERE c.media_id = ?
                 ORDER BY c.id DESC
                 LIMIT 50',
                [$tree_id, $tree_id, $media_id]
            );

            $faceMatches = DB::select(
                'SELECT q.id, q.face_name, q.suggested_person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS suggested_person_name,
                        p.birth_date AS suggested_person_birth_date,
                        p.death_date AS suggested_person_death_date,
                        q.match_type, q.confidence_score, q.status,
                        q.face_region, q.match_details, q.review_notes,
                        q.created_at, q.updated_at
                 FROM genealogy_face_match_queue q
                 LEFT JOIN genealogy_persons p ON p.id = q.suggested_person_id
                 WHERE q.media_id = ?
                   AND q.tree_id = ?
                 ORDER BY FIELD(q.status, \'pending\', \'approved\', \'auto_linked\', \'rejected\', \'ignored\'),
                          q.confidence_score DESC,
                          q.id DESC
                 LIMIT ?',
                [$media_id, $tree_id, $hintLimit]
            );

            $registryFiles = DB::select(
                'SELECT fr.id AS file_registry_id, fr.asset_uuid, fr.filename,
                        fr.current_path, fr.original_path, fr.original_source,
                        fr.mime_type, fr.file_size, fr.extension, fr.title,
                        fr.description, fr.category, fr.tags, fr.ai_tags,
                        fr.ai_description, fr.ai_detected_text, fr.ai_document_type,
                        fr.date_taken, fr.date_taken_source, fr.date_taken_confidence,
                        fr.exif_keywords, fr.exif_caption, fr.gps_latitude,
                        fr.gps_longitude, fr.gps_location, fr.camera_make,
                        fr.camera_model, fr.face_count,
                        fr.status, fr.nextcloud_modified_at, fr.content_hash,
                        fr.created_at, fr.updated_at
                 FROM file_registry fr
                 WHERE fr.current_path = ?
                    OR fr.current_path = ?
                    OR fr.original_path = ?
                    OR fr.original_path = ?
                 ORDER BY FIELD(fr.status, \'active\') DESC, fr.updated_at DESC, fr.id DESC
                 LIMIT 5',
                [
                    $media->nextcloud_path,
                    $media->original_path,
                    $media->nextcloud_path,
                    $media->original_path,
                ]
            );

            $registryFaces = DB::select(
                'SELECT ff.id, ff.file_registry_id, fr.current_path,
                        ff.person_name, ff.genealogy_person_id,
                        TRIM(CONCAT(COALESCE(p.given_name, \'\'), \' \', COALESCE(p.surname, \'\'))) AS genealogy_person_name,
                        p.birth_date AS genealogy_person_birth_date,
                        p.death_date AS genealogy_person_death_date,
                        ff.region_x, ff.region_y, ff.region_w, ff.region_h,
                        ff.confidence, ff.source, ff.verified, ff.hidden,
                        ff.created_at, ff.updated_at
                 FROM file_registry fr
                 JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                 LEFT JOIN genealogy_persons p ON p.id = ff.genealogy_person_id AND p.tree_id = ?
                 WHERE COALESCE(ff.hidden, 0) = 0
                   AND (
                        fr.current_path = ?
                        OR fr.current_path = ?
                        OR fr.original_path = ?
                        OR fr.original_path = ?
                   )
                 ORDER BY ff.verified DESC, ff.confidence DESC, ff.id DESC
                 LIMIT ?',
                [
                    $tree_id,
                    $media->nextcloud_path,
                    $media->original_path,
                    $media->nextcloud_path,
                    $media->original_path,
                    $hintLimit,
                ]
            );

            $exactPersonHits = $this->exactPersonHitsForMediaRows($tree_id, [$media_id])[$media_id] ?? null;
            $textSources = $this->buildMediaReviewTextSources($media, $textLimit, $summary_only);
            $reviewFocus = $this->buildMediaReviewFocus(
                $media,
                $textSources,
                $linkedPersons,
                $linkedFamilies,
                $citations,
                $faceMatches,
                $registryFaces,
                $exactPersonHits
            );

            return [
                'tool' => 'media_review_packet',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'media_id' => $media_id,
                'summary_only' => $summary_only,
                'media' => $this->buildMediaReviewMediaRow($media),
                'review_focus' => $reviewFocus,
                'text_sources' => $textSources,
                'linked_persons' => $this->compactLinkedPersonRows($linkedPersons),
                'linked_families' => $this->compactLinkedFamilyRows($linkedFamilies),
                'citations' => $this->excerptMediaReviewCitationRows($citations),
                'face_matches' => $this->compactFaceMatchRows($faceMatches),
                'file_registry' => array_map(fn (object $row): array => $this->buildMediaReviewRegistryFileRow($row), $registryFiles),
                'registry_faces' => array_map(fn (object $row): array => $this->buildMediaReviewRegistryFaceRow($row), $registryFaces),
                'name_hints' => [
                    'exact_ft_person_hits' => $exactPersonHits,
                    'likely_people' => $this->buildMediaReviewLikelyPeople(
                        $linkedPersons,
                        $citations,
                        $faceMatches,
                        $registryFaces,
                        $exactPersonHits
                    ),
                ],
                'counts' => [
                    'linked_persons' => count($linkedPersons),
                    'linked_families' => count($linkedFamilies),
                    'citations' => count($citations),
                    'face_matches_returned' => count($faceMatches),
                    'file_registry_rows' => count($registryFiles),
                    'registry_faces_returned' => count($registryFaces),
                ],
                'guidance' => [
                    'weak_text' => 'Do not persist weak OCR/HTR into person facts or RAG; escalate to AI vision/manual review first.',
                    'html_htm' => 'HTML and HTM are readable evidence files; extract narrative text before deciding links or fact proposals.',
                    'face_metadata' => 'Treat face and registry names as leads unless verified/manual or supported by source text.',
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_review_packet',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Return compact OCR/HTR/vision escalation candidates without persisting weak text.
     */
    public function media_ocr_escalation_batch(
        int $tree_id,
        int $limit = 50,
        ?string $bucket = null,
        bool $include_paths = false
    ): array {
        Log::info('GenealogyMCPService: media_ocr_escalation_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'bucket' => $bucket,
            'include_paths' => $include_paths,
        ]);

        $limit = max(1, min(200, $limit));
        $bucket = $bucket !== null && trim($bucket) !== '' ? strtolower(trim($bucket)) : 'all';
        $allowedBuckets = [
            'all',
            'weak_text',
            'html_text_extraction',
            'document_text_extraction',
            'image_ocr_or_vision',
            'processing_failed',
        ];

        if (! in_array($bucket, $allowedBuckets, true)) {
            return [
                'tool' => 'media_ocr_escalation_batch',
                'success' => false,
                'error' => "Invalid bucket filter: {$bucket}",
                'allowed_buckets' => $allowedBuckets,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_ocr_escalation_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $candidateSql = "
                FROM genealogy_media gm
                WHERE gm.tree_id = ?
                  AND gm.file_exists = 1
                  AND (
                      gm.media_type IN ('document', 'certificate', 'census', 'military', 'obituary', 'headstone')
                      OR LOWER(COALESCE(gm.file_format, '')) IN ('html', 'htm', 'pdf', 'txt', 'text', 'csv', 'md', 'rtf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx')
                      OR LOWER(COALESCE(gm.mime_type, '')) IN ('text/html', 'application/pdf')
                      OR gm.analysis_status = 'failed'
                      OR gm.enrichment_status = 'failed'
                      OR COALESCE(gm.analysis_error, '') <> ''
                      OR COALESCE(gm.enrichment_error, '') <> ''
                  )
            ";

            $rows = DB::select("
                SELECT gm.id, gm.tree_id, gm.media_type, gm.title, gm.media_date,
                       gm.description, gm.ai_description, gm.transcription_text, gm.transcription,
                       gm.original_path, gm.nextcloud_path, gm.local_filename,
                       gm.file_format, gm.mime_type, gm.file_size, gm.file_exists,
                       gm.width, gm.height, gm.has_faces, gm.face_count,
                       gm.analysis_status, gm.analysis_error,
                       gm.enrichment_status, gm.enrichment_error,
                       gm.rag_indexed_at, gm.updated_at,
                       (SELECT COUNT(*)
                        FROM genealogy_person_media pm
                        JOIN genealogy_persons p ON p.id = pm.person_id
                        WHERE pm.media_id = gm.id AND p.tree_id = gm.tree_id) AS person_links,
                       (SELECT COUNT(*)
                        FROM genealogy_family_media fm
                        JOIN genealogy_families f ON f.id = fm.family_id
                        WHERE fm.media_id = gm.id AND f.tree_id = gm.tree_id) AS family_links,
                       (SELECT COUNT(*) FROM genealogy_citations c WHERE c.media_id = gm.id) AS citations
                {$candidateSql}
                ORDER BY
                    CASE
                        WHEN gm.analysis_status = 'failed' OR gm.enrichment_status = 'failed' THEN 0
                        WHEN COALESCE(gm.analysis_error, '') <> '' OR COALESCE(gm.enrichment_error, '') <> '' THEN 1
                        WHEN gm.media_type IN ('certificate', 'census', 'obituary', 'military') THEN 2
                        ELSE 3
                    END,
                    gm.updated_at DESC,
                    gm.id DESC
                LIMIT ?
            ", [$tree_id, max($limit * 4, $limit)]);

            $roughCount = DB::selectOne("SELECT COUNT(*) AS count {$candidateSql}", [$tree_id]);

            $buckets = [
                'weak_text' => [],
                'html_text_extraction' => [],
                'document_text_extraction' => [],
                'image_ocr_or_vision' => [],
                'processing_failed' => [],
            ];

            foreach ($rows as $row) {
                $candidate = $this->buildOcrEscalationCandidate($row, $include_paths);
                if ($candidate === null) {
                    continue;
                }

                $candidateBucket = $candidate['bucket'];
                if ($bucket !== 'all' && $candidateBucket !== $bucket) {
                    continue;
                }

                $buckets[$candidateBucket][] = $candidate;
                if (array_sum(array_map('count', $buckets)) >= $limit) {
                    break;
                }
            }

            return [
                'tool' => 'media_ocr_escalation_batch',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'bucket_filter' => $bucket,
                'rough_candidate_count' => (int) ($roughCount->count ?? 0),
                'sample_count' => array_sum(array_map('count', $buckets)),
                'bucket_counts' => array_map('count', $buckets),
                'buckets' => array_filter($buckets, static fn (array $items): bool => $items !== []),
                'guidance' => [
                    'weak_text' => 'Do not persist weak OCR/HTR into person facts or RAG; use media_review_packet and AI vision/manual review first.',
                    'html_text_extraction' => 'HTML/HTM is readable evidence; extract narrative text, review, then reindex.',
                    'document_text_extraction' => 'Run bounded text extraction/OCR before fact proposals.',
                    'image_ocr_or_vision' => 'Run OCR first; escalate poor OCR to AI vision/manual review before writing facts.',
                    'processing_failed' => 'Inspect processing errors and retry the appropriate intake lane; do not treat old failed text as evidence.',
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_ocr_escalation_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Extract review-first person fact and relationship candidates from one evidence packet.
     */
    public function person_fact_extract(
        int $tree_id,
        ?int $media_id = null,
        ?int $source_id = null,
        ?int $person_id = null,
        ?string $document_text = null,
        int $text_limit = 5000,
        float $minimum_confidence = 0.50
    ): array {
        Log::info('GenealogyMCPService: person_fact_extract called', [
            'tree_id' => $tree_id,
            'media_id' => $media_id,
            'source_id' => $source_id,
            'person_id' => $person_id,
        ]);

        $packetKinds = array_filter([
            'media' => $media_id !== null,
            'source' => $source_id !== null,
            'document_text' => trim((string) $document_text) !== '',
        ]);

        if (count($packetKinds) !== 1) {
            return [
                'tool' => 'person_fact_extract',
                'success' => false,
                'error' => 'Provide exactly one evidence packet: media_id, source_id, or document_text.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $textLimit = max(800, min(12000, $text_limit));
        $minimumConfidence = max(0.0, min(1.0, $minimum_confidence));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'person_fact_extract',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $packet = $this->buildFactExtractPacket($tree_id, $media_id, $source_id, $document_text, $textLimit);
            if (! ($packet['success'] ?? false)) {
                return array_merge([
                    'tool' => 'person_fact_extract',
                    'success' => false,
                    'timestamp' => now()->toIso8601String(),
                ], $packet);
            }

            $peopleResult = $this->loadFactExtractTargetPeople($tree_id, $person_id, $packet['target_person_ids']);
            if (! ($peopleResult['success'] ?? false)) {
                return [
                    'tool' => 'person_fact_extract',
                    'success' => false,
                    'error' => $peopleResult['error'] ?? 'Unable to load target people for fact extraction.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $people = $peopleResult['people'];
            if ($people === []) {
                return [
                    'tool' => 'person_fact_extract',
                    'success' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'packet' => $packet['packet'],
                    'text_quality' => $packet['text_quality'],
                    'extraction_status' => 'no_target_people',
                    'candidates' => [],
                    'relationship_leads' => [],
                    'guidance' => [
                        'Provide person_id or attach/cite the media/source to a tree person before fact extraction.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($packet['text_quality'] !== 'usable') {
                return [
                    'tool' => 'person_fact_extract',
                    'success' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'packet' => $packet['packet'],
                    'text_quality' => $packet['text_quality'],
                    'extraction_status' => 'blocked_until_text_review',
                    'target_people' => $this->compactFactExtractPeople($people),
                    'candidates' => [],
                    'relationship_leads' => [],
                    'guidance' => [
                        'Do not generate fact proposals from missing or weak OCR/HTR text.',
                        'Run genealogy.media_review_packet or genealogy.media_ocr_escalation_batch first, then retry with reviewed text.',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $facts = $this->extractFactClaimsFromText($packet['text'], $textLimit);
            $relationshipClaims = $this->extractRelationshipClaimsFromText($packet['text'], $textLimit);
            $candidates = [];

            foreach ($people as $person) {
                foreach ($facts as $fact) {
                    $candidate = $this->buildFactExtractCandidate(
                        $tree_id,
                        $person,
                        $fact,
                        $packet,
                        $minimumConfidence
                    );

                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }

            $relationshipLeads = [];
            foreach ($people as $person) {
                foreach ($relationshipClaims as $claim) {
                    $relationshipLeads[] = $this->buildRelationshipExtractLead(
                        $tree_id,
                        $person,
                        $claim,
                        $packet,
                        $minimumConfidence
                    );
                }
            }

            return [
                'tool' => 'person_fact_extract',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'packet' => $packet['packet'],
                'text_quality' => $packet['text_quality'],
                'extraction_status' => 'review_candidates',
                'target_people' => $this->compactFactExtractPeople($people),
                'fact_claim_count' => count($facts),
                'relationship_claim_count' => count($relationshipClaims),
                'candidates' => $candidates,
                'relationship_leads' => $relationshipLeads,
                'guidance' => [
                    'This tool is read-only; apply nothing directly from extraction output.',
                    'Use genealogy.fact_update_proposal or genealogy.relationship_link_proposal only after reviewing evidence excerpts and conflict flags.',
                    'Conflicting, name-only, or relationship-name-only leads stay proposal/review-first.',
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_fact_extract',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Create a review-first proposal to attach media to a person or family.
     */
    public function media_attach_proposal(
        int $media_id,
        ?int $person_id = null,
        ?int $family_id = null,
        mixed $evidence = null,
        float $confidence = 0.75,
        bool $dry_run = true,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: media_attach_proposal called', [
            'media_id' => $media_id,
            'person_id' => $person_id,
            'family_id' => $family_id,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('media_attach_proposal');
        }

        if (($person_id === null && $family_id === null) || ($person_id !== null && $family_id !== null)) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Provide exactly one target: person_id or family_id.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $confidence = max(0.0, min(1.0, $confidence));
        $normalizedEvidence = $this->normalizeMediaAttachEvidence($evidence, $media_id, $confidence);

        if ($normalizedEvidence['summary'] === '') {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Evidence summary is required before proposing a media attachment.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if (mb_strlen($normalizedEvidence['summary']) < 20) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Evidence summary must be at least 20 characters for an agent-emitted media attachment proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['confidence'] < 0.50) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Confidence below 0.50 threshold for a media attachment proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $media = DB::selectOne(
                'SELECT id, tree_id, title, media_type, local_filename FROM genealogy_media WHERE id = ?',
                [$media_id]
            );
            if (! $media) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => false,
                    'error' => "Media not found: {$media_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $media->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => false,
                    'error' => 'Media is not in the requested tree.',
                    'media_id' => $media_id,
                    'requested_tree_id' => $requestedTreeId,
                    'media_tree_id' => (int) $media->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($family_id !== null) {
                return $this->createFamilyMediaAttachProposal(
                    $media,
                    $family_id,
                    $media_id,
                    $requestedTreeId,
                    $normalizedEvidence,
                    $dry_run
                );
            }

            $person = DB::selectOne(
                'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
                [$person_id]
            );
            if (! $person) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $person->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => false,
                    'error' => 'Person is not in the requested tree.',
                    'person_id' => $person_id,
                    'requested_tree_id' => $requestedTreeId,
                    'person_tree_id' => (int) $person->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $person->tree_id !== (int) $media->tree_id) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => false,
                    'error' => 'Media and person are in different trees.',
                    'media_tree_id' => (int) $media->tree_id,
                    'person_tree_id' => (int) $person->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $existingLink = DB::selectOne(
                'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
                [$person_id, $media_id]
            );
            if ($existingLink) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'already_linked' => true,
                    'person_id' => $person_id,
                    'media_id' => $media_id,
                    'link_id' => (int) $existingLink->id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $existingProposal = DB::selectOne(
                "SELECT id, status FROM genealogy_proposed_changes
                 WHERE person_id = ?
                   AND tree_id = ?
                   AND change_type = 'media_link'
                   AND proposed_value = ?
                   AND status IN ('pending', 'approved', 'applied')",
                [$person_id, $requestedTreeId, (string) $media_id]
            );
            if ($existingProposal) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'deduplicated' => true,
                    'proposal_id' => (int) $existingProposal->id,
                    'existing_status' => $existingProposal->status,
                    'person_id' => $person_id,
                    'media_id' => $media_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $preview = [
                'change_type' => 'media_link',
                'person_id' => $person_id,
                'person_name' => trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
                'media_id' => $media_id,
                'media_title' => $media->title ?? $media->local_filename ?? null,
                'tree_id' => $requestedTreeId,
                'proposed_value' => (string) $media_id,
                'evidence_sources' => $normalizedEvidence['sources'],
                'evidence_summary' => $normalizedEvidence['summary'],
                'confidence' => $normalizedEvidence['confidence'],
                'agent_id' => $normalizedEvidence['agent_id'],
            ];

            if ($dry_run) {
                return [
                    'tool' => 'media_attach_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_created' => false,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $result = app(PersonService::class)->proposeChange(
                $person_id,
                'media_link',
                null,
                (string) $media_id,
                $normalizedEvidence['sources'],
                $normalizedEvidence['summary'],
                $normalizedEvidence['confidence'],
                $normalizedEvidence['agent_id'],
                $requestedTreeId
            );

            $proposalId = isset($result['proposal_id']) ? (int) $result['proposal_id'] : null;
            $this->logGenealogyWriteAudit(
                'media_attach_proposal',
                'propose_person_media_link',
                $normalizedEvidence['agent_id'],
                (bool) ($result['success'] ?? false),
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'media_id' => $media_id,
                    'proposal_id' => $proposalId,
                    'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                ],
                [
                    'summary' => $normalizedEvidence['summary'],
                    'sources' => $normalizedEvidence['sources'],
                    'confidence' => $normalizedEvidence['confidence'],
                ],
                $proposalId
                    ? "Reject/delete genealogy_proposed_changes.id={$proposalId} before approval if this proposal is wrong; this tool did not create a direct media link."
                    : 'No proposal ID was returned; inspect genealogy_proposed_changes and service logs before retrying.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'media_attach_proposal',
                'success' => (bool) ($result['success'] ?? false),
                'dry_run' => false,
                'proposal_created' => (bool) ($result['success'] ?? false) && ! ($result['deduplicated'] ?? false),
                'proposal_id' => $proposalId,
                'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                'error' => $result['error'] ?? null,
                'proposal' => $preview,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function media_identity_apply(
        int $tree_id,
        int $media_id,
        int $person_id,
        ?string $identity_label = null,
        ?string $family_label = null,
        mixed $animal_subjects = null,
        mixed $evidence = null,
        float $confidence = 0.95,
        bool $dry_run = true,
        bool $confirm = false,
        ?string $actor = null
    ): array {
        Log::info('GenealogyMCPService: media_identity_apply called', [
            'tree_id' => $tree_id,
            'media_id' => $media_id,
            'person_id' => $person_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('media_identity_apply');
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'media_identity_apply',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $normalizedEvidence = $this->normalizeMediaAttachEvidence($evidence, $media_id, $confidence);
        $agentId = trim((string) ($actor ?? $normalizedEvidence['agent_id'] ?? 'genealogy-mcp-media-identity')) ?: 'genealogy-mcp-media-identity';
        $normalizedEvidence['agent_id'] = $agentId;

        if ($normalizedEvidence['summary'] === '' || mb_strlen($normalizedEvidence['summary']) < 20) {
            return [
                'tool' => 'media_identity_apply',
                'success' => false,
                'error' => 'Evidence summary must be at least 20 characters for an operator-confirmed media identity apply.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $media = DB::selectOne(
                'SELECT id, tree_id, title, description, subject_tags, exif_data, nextcloud_path, original_path, local_filename, rag_indexed_at
                 FROM genealogy_media
                 WHERE id = ?',
                [$media_id]
            );
            if (! $media) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => false,
                    'error' => "Media not found: {$media_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }
            if ((int) $media->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => false,
                    'error' => 'Media is not in the requested tree.',
                    'requested_tree_id' => $requestedTreeId,
                    'media_tree_id' => (int) $media->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $person = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, given_name, surname
                 FROM genealogy_persons
                 WHERE id = ?',
                [$person_id]
            );
            if (! $person) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }
            if ((int) $person->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => false,
                    'error' => 'Person is not in the requested tree.',
                    'requested_tree_id' => $requestedTreeId,
                    'person_tree_id' => (int) $person->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $personName = trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));
            $identityLabel = trim((string) ($identity_label ?? '')) ?: $personName;
            $familyLabel = trim((string) ($family_label ?? '')) ?: null;
            $animalSubjects = $this->normalizeMediaIdentityAnimalSubjects($animal_subjects);
            $matchedRegistryRows = $this->mediaIdentityRegistryRows($media);
            $matchedRegistryIds = array_map(static fn (object $row): int => (int) $row->id, $matchedRegistryRows);
            $visibleFaces = $this->mediaIdentityVisibleFaces($matchedRegistryIds);
            $selectedFace = count($visibleFaces) === 1 ? $visibleFaces[0] : null;

            $preview = [
                'tree_id' => $requestedTreeId,
                'media_id' => $media_id,
                'media_title' => $media->title ?? $media->local_filename ?? null,
                'person_id' => $person_id,
                'person_name' => $personName,
                'identity_label' => $identityLabel,
                'family_label' => $familyLabel,
                'animal_subjects' => $animalSubjects,
                'registry_file_ids' => $matchedRegistryIds,
                'visible_face_count' => count($visibleFaces),
                'selected_face_id' => $selectedFace ? (int) $selectedFace->id : null,
                'face_sync_policy' => $selectedFace ? 'single_visible_face_auto_verified' : 'no_face_or_multiple_faces_metadata_not_auto_verified',
                'evidence_summary' => $normalizedEvidence['summary'],
                'evidence_sources' => $normalizedEvidence['sources'],
                'confidence' => $normalizedEvidence['confidence'],
                'agent_id' => $agentId,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => true,
                    'dry_run' => true,
                    'confirm' => $confirm,
                    'plan' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $proposalResult = $this->media_attach_proposal(
                $media_id,
                $person_id,
                null,
                $normalizedEvidence,
                $normalizedEvidence['confidence'],
                false,
                $requestedTreeId
            );
            if (! ($proposalResult['success'] ?? false)) {
                return [
                    'tool' => 'media_identity_apply',
                    'success' => false,
                    'dry_run' => false,
                    'error' => 'Media link proposal failed.',
                    'proposal_result' => $proposalResult,
                    'plan' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $applyResult = null;
            if (! ($proposalResult['already_linked'] ?? false) && isset($proposalResult['proposal_id'])) {
                $applyResult = $this->approve_apply_proposal(
                    (int) $proposalResult['proposal_id'],
                    'change',
                    'Approved by media_identity_apply from operator-confirmed identity evidence.',
                    false,
                    true,
                    $requestedTreeId
                );
                if (! ($applyResult['success'] ?? false)) {
                    return [
                        'tool' => 'media_identity_apply',
                        'success' => false,
                        'dry_run' => false,
                        'error' => 'Media link proposal apply failed.',
                        'proposal_result' => $proposalResult,
                        'apply_result' => $applyResult,
                        'plan' => $preview,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $description = $this->buildMediaIdentityDescription($media, $identityLabel, $personName, $familyLabel, $animalSubjects);
            $subjectTags = $this->mergeStringTags(
                $this->decodeJsonField($media->subject_tags ?? null),
                array_merge(
                    [$personName, $identityLabel, 'operator verified', 'family photo'],
                    $familyLabel ? [$familyLabel] : [],
                    array_map(static fn (array $animal): string => trim($animal['name'].' '.$animal['type']), $animalSubjects)
                )
            );
            $exifData = $this->decodeJsonField($media->exif_data ?? null);
            $exifData = is_array($exifData) ? $exifData : [];
            $exifData['operator_metadata'] = [
                'verified_at' => now()->toDateString(),
                'verified_by' => $agentId,
                'identification' => $identityLabel,
                'person_id' => $person_id,
                'person_gedcom_id' => $person->gedcom_id ?? null,
                'person_name' => $personName,
                'family_label' => $familyLabel,
                'animal_subjects' => $animalSubjects,
                'evidence_summary' => $normalizedEvidence['summary'],
            ];
            $exifData['source_file_registry_ids'] = $matchedRegistryIds;

            DB::update(
                'UPDATE genealogy_media
                 SET description = ?, subject_tags = ?, exif_data = ?, rag_indexed_at = NULL, updated_at = NOW()
                 WHERE id = ? AND tree_id = ?',
                [$description, json_encode($subjectTags), json_encode($exifData), $media_id, $requestedTreeId]
            );

            $personMediaUpdated = 0;
            if ($selectedFace) {
                $personMediaUpdated = DB::update(
                    'UPDATE genealogy_person_media
                     SET face_region_x = ?, face_region_y = ?, face_region_w = ?, face_region_h = ?,
                         face_confirmed = 1, notes = ?
                     WHERE person_id = ? AND media_id = ?',
                    [
                        $selectedFace->region_x ?? null,
                        $selectedFace->region_y ?? null,
                        $selectedFace->region_w ?? null,
                        $selectedFace->region_h ?? null,
                        'Operator verified '.now()->toDateString().": {$identityLabel}.",
                        $person_id,
                        $media_id,
                    ]
                );
            } else {
                $personMediaUpdated = DB::update(
                    'UPDATE genealogy_person_media
                     SET notes = COALESCE(NULLIF(notes, ""), ?)
                     WHERE person_id = ? AND media_id = ?',
                    ['Operator verified '.now()->toDateString().": {$identityLabel}.", $person_id, $media_id]
                );
            }

            $fileRegistryUpdated = 0;
            if ($matchedRegistryIds !== []) {
                $placeholders = implode(',', array_fill(0, count($matchedRegistryIds), '?'));
                $registryTags = $this->mergeStringTags([], array_merge(['genealogy', 'tree:'.$requestedTreeId, 'person:'.$person_id], $subjectTags));
                $caption = $this->buildMediaIdentityCaption($identityLabel, $animalSubjects);
                $keywords = implode(', ', $subjectTags);
                $fileRegistryUpdated = DB::update(
                    "UPDATE file_registry
                     SET title = COALESCE(title, ?),
                         description = ?,
                         tags = ?,
                         exif_caption = ?,
                         exif_keywords = ?,
                         rag_indexed_at = NULL,
                         semantic_indexed_at = NULL,
                         metadata_synced_at = NOW(),
                         updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    array_merge([
                        $media->title ?? $media->local_filename ?? $identityLabel,
                        $description,
                        json_encode($registryTags),
                        $caption,
                        $keywords,
                    ], $matchedRegistryIds)
                );

                if ($selectedFace) {
                    DB::update(
                        'UPDATE file_registry_faces
                         SET person_name = ?, genealogy_person_id = ?, verified = 1, source = "manual", updated_at = NOW()
                         WHERE id = ?',
                        [$personName, $person_id, (int) $selectedFace->id]
                    );
                    $this->upsertOperatorVerifiedFaceQueue($requestedTreeId, $media_id, $person_id, $personName, $selectedFace, $familyLabel, $animalSubjects);
                }
            }

            $memoryId = $this->recordMediaIdentityMemory($requestedTreeId, $media_id, $person_id, $personName, $identityLabel, $familyLabel, $animalSubjects, $matchedRegistryIds, $normalizedEvidence, $agentId);

            $this->logGenealogyWriteAudit(
                'media_identity_apply',
                'operator_confirmed_media_identity',
                $agentId,
                true,
                [
                    'tree_id' => $requestedTreeId,
                    'media_id' => $media_id,
                    'person_id' => $person_id,
                    'proposal_id' => $proposalResult['proposal_id'] ?? null,
                    'registry_file_ids' => $matchedRegistryIds,
                    'selected_face_id' => $selectedFace ? (int) $selectedFace->id : null,
                    'memory_id' => $memoryId,
                ],
                [
                    'summary' => $normalizedEvidence['summary'],
                    'sources' => $normalizedEvidence['sources'],
                    'confidence' => $normalizedEvidence['confidence'],
                ],
                "Review genealogy_person_media for media_id={$media_id}, genealogy_media.id={$media_id}, file_registry ids [".implode(',', $matchedRegistryIds)."], and agent_semantic_memory.id={$memoryId} to manually revert if needed.",
                ['dry_run' => false]
            );

            return [
                'tool' => 'media_identity_apply',
                'success' => true,
                'dry_run' => false,
                'confirm' => true,
                'plan' => $preview,
                'proposal_result' => $proposalResult,
                'apply_result' => $applyResult,
                'updated' => [
                    'genealogy_media' => 1,
                    'genealogy_person_media' => $personMediaUpdated,
                    'file_registry' => $fileRegistryUpdated,
                    'selected_face_id' => $selectedFace ? (int) $selectedFace->id : null,
                    'memory_id' => $memoryId,
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_identity_apply',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Create a review-first proposal to update a person fact.
     */
    public function fact_update_proposal(
        int $person_id,
        string $field,
        mixed $value,
        mixed $evidence = null,
        float $confidence = 0.75,
        bool $dry_run = true,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: fact_update_proposal called', [
            'person_id' => $person_id,
            'field' => $field,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('fact_update_proposal');
        }

        $field = trim($field);
        if (! in_array($field, self::FACT_UPDATE_FIELDS, true)) {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => "Field is not allowed for fact_update_proposal: {$field}",
                'allowed_fields' => self::FACT_UPDATE_FIELDS,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $proposedValue = $this->normalizeProposedFactValue($value);
        if ($proposedValue === '') {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => 'Proposed value is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $normalizedEvidence = $this->normalizeFactUpdateEvidence($evidence, $confidence);
        if ($normalizedEvidence['summary'] === '') {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => 'Evidence summary is required before proposing a fact update.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if (mb_strlen($normalizedEvidence['summary']) < 20) {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => 'Evidence summary must be at least 20 characters for an agent-emitted fact update proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['sources'] === []) {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => 'At least one evidence source is required for a fact update proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['confidence'] < 0.50) {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => 'Confidence below 0.50 threshold for a fact update proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $person = DB::selectOne(
                "SELECT id, tree_id, given_name, surname, {$field} AS current_value
                 FROM genealogy_persons
                 WHERE id = ?",
                [$person_id]
            );
            if (! $person) {
                return [
                    'tool' => 'fact_update_proposal',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $person->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'fact_update_proposal',
                    'success' => false,
                    'error' => 'Person is not in the requested tree.',
                    'person_id' => $person_id,
                    'requested_tree_id' => $requestedTreeId,
                    'person_tree_id' => (int) $person->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $currentValue = $person->current_value !== null ? (string) $person->current_value : null;
            if ($currentValue !== null && trim($currentValue) === $proposedValue) {
                return [
                    'tool' => 'fact_update_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'already_current' => true,
                    'person_id' => $person_id,
                    'field' => $field,
                    'current_value' => $currentValue,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $queueFormatter = app(GenealogyProposalReviewQueueService::class);
            $reviewFlags = $queueFormatter->buildProposalReviewFlags(
                'fact_update',
                $currentValue,
                $proposedValue,
                $normalizedEvidence['sources'],
                $normalizedEvidence['confidence']
            );
            $reviewRoute = $queueFormatter->buildProposalReviewRoute($reviewFlags);
            $provenance = $this->buildFactUpdateProvenance(
                $evidence,
                $normalizedEvidence,
                $requestedTreeId,
                $person_id,
                $field,
                $currentValue,
                $proposedValue,
                $reviewFlags,
                $reviewRoute,
                $dry_run ? 'dry_run_preview' : 'create_pending_review_proposal'
            );

            $existingProposal = DB::selectOne(
                "SELECT id, status FROM genealogy_proposed_changes
                 WHERE person_id = ?
                   AND tree_id = ?
                   AND change_type = 'fact_update'
                   AND field_name <=> ?
                   AND proposed_value = ?
                   AND status IN ('pending', 'approved', 'applied')",
                [$person_id, $requestedTreeId, $field, $proposedValue]
            );
            if ($existingProposal) {
                return [
                    'tool' => 'fact_update_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'deduplicated' => true,
                    'proposal_id' => (int) $existingProposal->id,
                    'existing_status' => $existingProposal->status,
                    'person_id' => $person_id,
                    'field' => $field,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $preview = [
                'change_type' => 'fact_update',
                'person_id' => $person_id,
                'person_name' => trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
                'tree_id' => $requestedTreeId,
                'field_name' => $field,
                'current_value' => $currentValue,
                'proposed_value' => $proposedValue,
                'evidence_sources' => $normalizedEvidence['sources'],
                'evidence_summary' => $normalizedEvidence['summary'],
                'confidence' => $normalizedEvidence['confidence'],
                'agent_id' => $normalizedEvidence['agent_id'],
                'review_flags' => $reviewFlags,
                'review_route' => $reviewRoute,
                'provenance' => $provenance,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'fact_update_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_created' => false,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $result = app(PersonService::class)->proposeChange(
                $person_id,
                'fact_update',
                $field,
                $proposedValue,
                $normalizedEvidence['sources'],
                $normalizedEvidence['summary'],
                $normalizedEvidence['confidence'],
                $normalizedEvidence['agent_id'],
                $requestedTreeId
            );

            $proposalId = isset($result['proposal_id']) ? (int) $result['proposal_id'] : null;
            $this->persistFactUpdateProvenance($proposalId, $provenance);

            $this->logGenealogyWriteAudit(
                'fact_update_proposal',
                'propose_person_fact_update',
                $normalizedEvidence['agent_id'],
                (bool) ($result['success'] ?? false),
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'field_name' => $field,
                    'proposal_id' => $proposalId,
                    'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                ],
                [
                    'summary' => $normalizedEvidence['summary'],
                    'sources' => $normalizedEvidence['sources'],
                    'confidence' => $normalizedEvidence['confidence'],
                    'provenance' => $provenance,
                ],
                $proposalId
                    ? "Reject/delete genealogy_proposed_changes.id={$proposalId} before approval if this proposal is wrong; this tool did not update the person row directly."
                    : 'No proposal ID was returned; inspect genealogy_proposed_changes and service logs before retrying.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'fact_update_proposal',
                'success' => (bool) ($result['success'] ?? false),
                'dry_run' => false,
                'proposal_created' => (bool) ($result['success'] ?? false) && ! ($result['deduplicated'] ?? false),
                'proposal_id' => $proposalId,
                'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                'error' => $result['error'] ?? null,
                'gate' => $result['gate'] ?? null,
                'severity' => $result['severity'] ?? null,
                'proposal' => $preview,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'fact_update_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Create a review-first proposal to attach a vetted source to a person.
     */
    public function source_add_proposal(
        int $person_id,
        mixed $source_locator,
        mixed $evidence = null,
        float $confidence = 0.75,
        bool $dry_run = true,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: source_add_proposal called', [
            'person_id' => $person_id,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('source_add_proposal');
        }

        $proposedValue = trim((string) $source_locator);
        $hasUrl = (bool) preg_match('/^https?:\/\//i', $proposedValue);
        $hasSourceId = (bool) preg_match('/^\d+$/', $proposedValue);
        if ($proposedValue === '' || (! $hasUrl && ! $hasSourceId)) {
            return [
                'tool' => 'source_add_proposal',
                'success' => false,
                'error' => 'source_locator must be an http(s) URL or numeric genealogy source_id.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $normalizedEvidence = $this->normalizeFactUpdateEvidence($evidence, $confidence, 'genealogy-mcp-source-add');
        $locatorEvidenceSource = $hasSourceId ? 'genealogy_source:'.$proposedValue : $proposedValue;
        if (! in_array($locatorEvidenceSource, $normalizedEvidence['sources'], true)) {
            $normalizedEvidence['sources'][] = $locatorEvidenceSource;
        }

        if ($normalizedEvidence['summary'] === '') {
            return [
                'tool' => 'source_add_proposal',
                'success' => false,
                'error' => 'Evidence summary is required before proposing a source attachment.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if (mb_strlen($normalizedEvidence['summary']) < 20) {
            return [
                'tool' => 'source_add_proposal',
                'success' => false,
                'error' => 'Evidence summary must be at least 20 characters for an agent-emitted source attachment proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['confidence'] < 0.50) {
            return [
                'tool' => 'source_add_proposal',
                'success' => false,
                'error' => 'Confidence below 0.50 threshold for a source attachment proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $person = DB::selectOne(
                'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
                [$person_id]
            );
            if (! $person) {
                return [
                    'tool' => 'source_add_proposal',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $person->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'source_add_proposal',
                    'success' => false,
                    'error' => 'Person is not in the requested tree.',
                    'person_id' => $person_id,
                    'requested_tree_id' => $requestedTreeId,
                    'person_tree_id' => (int) $person->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $source = null;
            if ($hasSourceId) {
                $source = DB::selectOne(
                    'SELECT id, tree_id, title, url FROM genealogy_sources WHERE id = ?',
                    [(int) $proposedValue]
                );
                if (! $source) {
                    return [
                        'tool' => 'source_add_proposal',
                        'success' => false,
                        'error' => "Source not found: {$proposedValue}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }

                if ((int) $source->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'source_add_proposal',
                        'success' => false,
                        'error' => 'Source is not in the requested tree.',
                        'source_id' => (int) $source->id,
                        'requested_tree_id' => $requestedTreeId,
                        'source_tree_id' => (int) $source->tree_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }

                $existingLink = DB::selectOne(
                    'SELECT id FROM genealogy_person_sources WHERE person_id = ? AND source_id = ?',
                    [$person_id, (int) $source->id]
                );
                if ($existingLink) {
                    return [
                        'tool' => 'source_add_proposal',
                        'success' => true,
                        'proposal_created' => false,
                        'already_linked' => true,
                        'person_id' => $person_id,
                        'source_id' => (int) $source->id,
                        'link_id' => (int) $existingLink->id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $existingProposal = DB::selectOne(
                "SELECT id, status FROM genealogy_proposed_changes
                 WHERE person_id = ?
                   AND tree_id = ?
                   AND change_type = 'source_add'
                   AND (proposed_value = ? OR proposed_value LIKE ?)
                   AND status IN ('pending', 'approved', 'applied')",
                [$person_id, $requestedTreeId, $proposedValue, '%'.$proposedValue.'%']
            );
            if ($existingProposal) {
                return [
                    'tool' => 'source_add_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'deduplicated' => true,
                    'proposal_id' => (int) $existingProposal->id,
                    'existing_status' => $existingProposal->status,
                    'person_id' => $person_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $queueFormatter = app(GenealogyProposalReviewQueueService::class);
            $reviewFlags = $queueFormatter->buildProposalReviewFlags(
                'source_add',
                null,
                $proposedValue,
                $normalizedEvidence['sources'],
                $normalizedEvidence['confidence']
            );
            $reviewRoute = $queueFormatter->buildProposalReviewRoute($reviewFlags);
            $preview = [
                'change_type' => 'source_add',
                'person_id' => $person_id,
                'person_name' => trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
                'tree_id' => $requestedTreeId,
                'source_id' => $source ? (int) $source->id : null,
                'source_title' => $source->title ?? null,
                'source_url' => $source->url ?? ($hasUrl ? $proposedValue : null),
                'proposed_value' => $proposedValue,
                'evidence_sources' => $normalizedEvidence['sources'],
                'evidence_summary' => $normalizedEvidence['summary'],
                'confidence' => $normalizedEvidence['confidence'],
                'agent_id' => $normalizedEvidence['agent_id'],
                'review_flags' => $reviewFlags,
                'review_route' => $reviewRoute,
                'write_note' => $hasUrl
                    ? 'Non-dry-run creates a pending source_add proposal and may auto-create/deduplicate a genealogy_sources row for this URL.'
                    : 'Non-dry-run creates a pending source_add proposal that links this existing genealogy source after approval.',
            ];

            if ($dry_run) {
                return [
                    'tool' => 'source_add_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_created' => false,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $result = app(PersonService::class)->proposeChange(
                $person_id,
                'source_add',
                null,
                $proposedValue,
                $normalizedEvidence['sources'],
                $normalizedEvidence['summary'],
                $normalizedEvidence['confidence'],
                $normalizedEvidence['agent_id'],
                $requestedTreeId
            );

            $proposalId = isset($result['proposal_id']) ? (int) $result['proposal_id'] : null;
            $this->logGenealogyWriteAudit(
                'source_add_proposal',
                'propose_person_source_link',
                $normalizedEvidence['agent_id'],
                (bool) ($result['success'] ?? false),
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'source_locator' => $proposedValue,
                    'proposal_id' => $proposalId,
                    'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                ],
                [
                    'summary' => $normalizedEvidence['summary'],
                    'sources' => $normalizedEvidence['sources'],
                    'confidence' => $normalizedEvidence['confidence'],
                ],
                $proposalId
                    ? "Reject/delete genealogy_proposed_changes.id={$proposalId} before approval if this proposal is wrong; this tool did not create a direct person-source link."
                    : 'No proposal ID was returned; inspect genealogy_proposed_changes and service logs before retrying.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'source_add_proposal',
                'success' => (bool) ($result['success'] ?? false),
                'dry_run' => false,
                'proposal_created' => (bool) ($result['success'] ?? false) && ! ($result['deduplicated'] ?? false),
                'proposal_id' => $proposalId,
                'deduplicated' => (bool) ($result['deduplicated'] ?? false),
                'error' => $result['error'] ?? null,
                'gate' => $result['gate'] ?? null,
                'severity' => $result['severity'] ?? null,
                'proposal' => $preview,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_add_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Create a review-first proposal to link two existing people.
     */
    public function relationship_link_proposal(
        int $person_id,
        int $related_person_id,
        string $relationship_type,
        mixed $evidence = null,
        float $confidence = 0.75,
        bool $dry_run = true,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: relationship_link_proposal called', [
            'person_id' => $person_id,
            'related_person_id' => $related_person_id,
            'relationship_type' => $relationship_type,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('relationship_link_proposal');
        }

        $relationshipType = strtolower(trim($relationship_type));
        if (! in_array($relationshipType, self::RELATIONSHIP_LINK_TYPES, true)) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => "Invalid relationship_type: {$relationship_type}.",
                'allowed_relationship_types' => self::RELATIONSHIP_LINK_TYPES,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($person_id === $related_person_id) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => 'Cannot propose linking a person to themself.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $normalizedEvidence = $this->normalizeFactUpdateEvidence(
            $evidence,
            $confidence,
            'genealogy-mcp-relationship-link'
        );
        if ($normalizedEvidence['summary'] === '') {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => 'Evidence summary is required before proposing a relationship link.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if (mb_strlen($normalizedEvidence['summary']) < 20) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => 'Evidence summary must be at least 20 characters for an agent-emitted relationship proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['sources'] === []) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => 'At least one evidence source is required for a relationship proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }
        if ($normalizedEvidence['confidence'] < 0.50) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => 'Confidence below 0.50 threshold for a relationship proposal.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $person = $this->loadRelationshipLinkPerson($person_id);
            if (! $person) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => false,
                    'error' => "Person not found: {$person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $related = $this->loadRelationshipLinkPerson($related_person_id);
            if (! $related) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => false,
                    'error' => "Related person not found: {$related_person_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ((int) $person->tree_id !== $requestedTreeId || (int) $related->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => false,
                    'error' => 'Both people must belong to the requested tree.',
                    'requested_tree_id' => $requestedTreeId,
                    'person_tree_id' => (int) $person->tree_id,
                    'related_person_tree_id' => (int) $related->tree_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $existingLink = $this->existingRelationshipLinkStatus(
                $requestedTreeId,
                $person_id,
                $related_person_id,
                $relationshipType
            );
            if ($existingLink !== null) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'already_linked' => true,
                    'person_id' => $person_id,
                    'related_person_id' => $related_person_id,
                    'relationship_type' => $relationshipType,
                    'family_id' => $existingLink['family_id'],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $existingProposal = DB::selectOne(
                "SELECT id, status FROM genealogy_proposed_relationships
                 WHERE tree_id = ?
                   AND person_id = ?
                   AND relationship_type = ?
                   AND related_person_id = ?
                   AND proposal_mode = 'link_existing'
                   AND status IN ('pending', 'pending_review', 'approved', 'applied')",
                [$requestedTreeId, $person_id, $relationshipType, $related_person_id]
            );
            if ($existingProposal) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => true,
                    'proposal_created' => false,
                    'deduplicated' => true,
                    'proposal_id' => (int) $existingProposal->id,
                    'existing_status' => $existingProposal->status,
                    'person_id' => $person_id,
                    'related_person_id' => $related_person_id,
                    'relationship_type' => $relationshipType,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $preview = [
                'proposal_mode' => 'link_existing',
                'tree_id' => $requestedTreeId,
                'person_id' => $person_id,
                'person_name' => $this->personDisplayName($person),
                'related_person_id' => $related_person_id,
                'related_person_name' => $this->personDisplayName($related),
                'relationship_type' => $relationshipType,
                'evidence_sources' => $normalizedEvidence['sources'],
                'evidence_summary' => $normalizedEvidence['summary'],
                'confidence' => $normalizedEvidence['confidence'],
                'agent_id' => $normalizedEvidence['agent_id'],
            ];

            if ($dry_run) {
                return [
                    'tool' => 'relationship_link_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_created' => false,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            DB::insert(
                "INSERT INTO genealogy_proposed_relationships
                    (tree_id, person_id, relationship_type, related_person_id, proposal_mode,
                     proposed_name, proposed_given_name, proposed_surname, proposed_sex,
                     proposed_birth_date, proposed_birth_place, proposed_death_date, proposed_death_place,
                     evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'link_existing', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                [
                    $requestedTreeId,
                    $person_id,
                    $relationshipType,
                    $related_person_id,
                    $this->personDisplayName($related),
                    $related->given_name ?? null,
                    $related->surname ?? null,
                    $related->sex ?? null,
                    $related->birth_date ?? null,
                    $related->birth_place ?? null,
                    $related->death_date ?? null,
                    $related->death_place ?? null,
                    json_encode($normalizedEvidence['sources'], JSON_UNESCAPED_SLASHES),
                    $normalizedEvidence['summary'],
                    $normalizedEvidence['confidence'],
                    $normalizedEvidence['agent_id'],
                ]
            );
            $row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
            $proposalId = isset($row->id) ? (int) $row->id : null;

            $this->logGenealogyWriteAudit(
                'relationship_link_proposal',
                'propose_existing_person_relationship_link',
                $normalizedEvidence['agent_id'],
                $proposalId !== null,
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'related_person_id' => $related_person_id,
                    'relationship_type' => $relationshipType,
                    'proposal_id' => $proposalId,
                    'deduplicated' => false,
                ],
                [
                    'summary' => $normalizedEvidence['summary'],
                    'sources' => $normalizedEvidence['sources'],
                    'confidence' => $normalizedEvidence['confidence'],
                ],
                $proposalId
                    ? "Reject/delete genealogy_proposed_relationships.id={$proposalId} before approval if this relationship proposal is wrong; this tool did not change family links."
                    : 'No proposal ID was returned; inspect genealogy_proposed_relationships and service logs before retrying.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'relationship_link_proposal',
                'success' => $proposalId !== null,
                'dry_run' => false,
                'proposal_created' => $proposalId !== null,
                'proposal_id' => $proposalId,
                'deduplicated' => false,
                'proposal' => $preview,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'relationship_link_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Preview or apply a proposal that has already been human-approved.
     */
    public function apply_approved_proposal(
        int $proposal_id,
        ?string $proposal_type = null,
        bool $dry_run = true,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: apply_approved_proposal called', [
            'proposal_id' => $proposal_id,
            'proposal_type' => $proposal_type,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('apply_approved_proposal');
        }

        $normalizedType = $this->normalizeApplyProposalType($proposal_type);
        if ($proposal_type !== null && $normalizedType === null) {
            return [
                'tool' => 'apply_approved_proposal',
                'success' => false,
                'error' => "Invalid proposal_type: {$proposal_type}. Use change or relationship.",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $resolved = $this->resolveApplyProposal($proposal_id, $normalizedType, $requestedTreeId);
            if (! ($resolved['success'] ?? false)) {
                return [
                    'tool' => 'apply_approved_proposal',
                    'success' => false,
                    'error' => $resolved['error'] ?? 'Proposal not found.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $type = (string) $resolved['proposal_type'];
            $proposal = $resolved['proposal'];
            $status = (string) ($proposal->status ?? '');
            $preview = $this->buildApplyApprovedProposalPreview($type, $proposal);
            $canApply = $status === 'approved';

            if ($dry_run) {
                return [
                    'tool' => 'apply_approved_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'tree_id' => $requestedTreeId,
                    'status' => $status,
                    'can_apply' => $canApply,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $canApply) {
                return [
                    'tool' => 'apply_approved_proposal',
                    'success' => false,
                    'dry_run' => false,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'tree_id' => $requestedTreeId,
                    'status' => $status,
                    'can_apply' => false,
                    'error' => "Proposal status is '{$status}', not 'approved'. Human approval required before apply.",
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $isFamilyMediaChange = $type === 'change' && $this->familyMediaProposalPayload($proposal) !== null;
            $result = $isFamilyMediaChange
                ? $this->applyFamilyMediaLinkProposal($proposal, $requestedTreeId)
                : ($type === 'change'
                    ? app(PersonService::class)->applyProposedChange($proposal_id)
                    : app(FamilyService::class)->applyProposedRelationship($proposal_id));

            $this->logGenealogyWriteAudit(
                'apply_approved_proposal',
                $isFamilyMediaChange ? 'apply_family_media_link' : ($type === 'change' ? 'apply_person_change' : 'apply_relationship_proposal'),
                (string) ($proposal->agent_id ?? 'genealogy-mcp-apply-approved-proposal'),
                (bool) ($result['success'] ?? false),
                [
                    'tree_id' => $requestedTreeId,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'person_id' => isset($proposal->person_id) ? (int) $proposal->person_id : null,
                    'family_id' => $result['family_id'] ?? null,
                    'media_id' => $result['media_id'] ?? null,
                    'related_person_id' => isset($proposal->related_person_id) ? (int) $proposal->related_person_id : null,
                    'status_before_apply' => $status,
                    'result' => $result,
                ],
                [
                    'summary' => $proposal->evidence_summary ?? null,
                    'sources' => $proposal->evidence_sources ?? null,
                    'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
                ],
                $isFamilyMediaChange
                    ? 'Delete genealogy_family_media.id='.($result['link_id'] ?? 'UNKNOWN').' if this approved family-media link is wrong; inspect genealogy_proposed_changes.id='.$proposal_id.'.'
                    : ($type === 'change'
                    ? "Use the proposal preview/current_value and PersonService apply result to revert person {$proposal->person_id}; inspect genealogy_proposed_changes.id={$proposal_id}."
                    : "Use the proposal preview and FamilyService apply result to revert relationship effects; inspect genealogy_proposed_relationships.id={$proposal_id}."),
                ['dry_run' => false]
            );

            $memoryId = $this->recordProposalDecisionMemory(
                $requestedTreeId,
                $type,
                $proposal_id,
                $proposal,
                'accepted',
                $result,
                (string) ($proposal->agent_id ?? 'genealogy-mcp-apply-approved-proposal')
            );

            return [
                'tool' => 'apply_approved_proposal',
                'success' => (bool) ($result['success'] ?? false),
                'dry_run' => false,
                'proposal_type' => $type,
                'proposal_id' => $proposal_id,
                'tree_id' => $requestedTreeId,
                'status' => $status,
                'applied' => (bool) ($result['success'] ?? false),
                'result' => $result,
                'proposal' => $preview,
                'memory_id' => $memoryId,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'apply_approved_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Preview or apply approved, high-confidence, source-backed person fact proposals in a bounded batch.
     */
    public function person_fact_apply_batch(
        int $tree_id,
        mixed $proposal_ids = null,
        int $limit = 20,
        float $minimum_confidence = 0.80,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: person_fact_apply_batch called', [
            'tree_id' => $tree_id,
            'proposal_ids' => $proposal_ids,
            'limit' => $limit,
            'minimum_confidence' => $minimum_confidence,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(1, min(50, $limit));
        $minimumConfidence = max(0.0, min(1.0, $minimum_confidence));
        $proposalIds = $this->normalizeProposalIdList($proposal_ids);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'person_fact_apply_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->loadPersonFactApplyCandidates($tree_id, $proposalIds, $limit, $minimumConfidence);
            $previews = array_map(
                fn (object $row): array => $this->buildPersonFactApplyPreviewRow($row, $minimumConfidence),
                $rows
            );
            $eligible = array_values(array_filter($previews, static fn (array $row): bool => (bool) ($row['eligible'] ?? false)));
            $blocked = array_values(array_filter($previews, static fn (array $row): bool => ! (bool) ($row['eligible'] ?? false)));

            if ($dry_run) {
                return [
                    'tool' => 'person_fact_apply_batch',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'minimum_confidence' => $minimumConfidence,
                    'requested_ids' => $proposalIds,
                    'loaded_count' => count($rows),
                    'eligible_count' => count($eligible),
                    'blocked_count' => count($blocked),
                    'proposals' => $previews,
                    'write_policy' => 'dry_run_first_confirm_required_approved_source_backed_fact_updates_only',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'person_fact_apply_batch',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'eligible_count' => count($eligible),
                    'blocked_count' => count($blocked),
                    'proposals' => $previews,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $applied = [];
            $failed = [];
            foreach ($eligible as $preview) {
                $result = $this->apply_approved_proposal((int) $preview['proposal_id'], 'change', false, $tree_id);
                $receipt = [
                    'proposal_id' => (int) $preview['proposal_id'],
                    'person_id' => $preview['person_id'],
                    'field_name' => $preview['field_name'],
                    'result' => $result,
                ];

                if (($result['success'] ?? false) && ($result['applied'] ?? false)) {
                    $applied[] = $receipt;
                } else {
                    $failed[] = $receipt;
                }
            }

            $this->logGenealogyWriteAudit(
                'person_fact_apply_batch',
                'apply_approved_source_backed_fact_updates',
                $actor,
                $failed === [],
                [
                    'tree_id' => $tree_id,
                    'requested_ids' => $proposalIds,
                    'eligible_count' => count($eligible),
                    'applied_count' => count($applied),
                    'failed_count' => count($failed),
                    'blocked_count' => count($blocked),
                ],
                [
                    'minimum_confidence' => $minimumConfidence,
                    'source_backed_required' => true,
                ],
                'Use each returned proposal preview/current_value and PersonService apply result to revert any incorrect person fact update; inspect genealogy_proposed_changes ids in applied/failed receipts.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'person_fact_apply_batch',
                'success' => $failed === [],
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'minimum_confidence' => $minimumConfidence,
                'requested_ids' => $proposalIds,
                'loaded_count' => count($rows),
                'eligible_count' => count($eligible),
                'blocked_count' => count($blocked),
                'applied_count' => count($applied),
                'failed_count' => count($failed),
                'blocked' => $blocked,
                'applied' => $applied,
                'failed' => $failed,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_fact_apply_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Approve and apply a proposal through the canonical review service path.
     */
    public function approve_apply_proposal(
        int $proposal_id,
        ?string $proposal_type = null,
        string $reviewer_notes = '',
        bool $dry_run = true,
        bool $confirm = false,
        ?int $tree_id = null
    ): array {
        Log::info('GenealogyMCPService: approve_apply_proposal called', [
            'proposal_id' => $proposal_id,
            'proposal_type' => $proposal_type,
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('approve_apply_proposal');
        }

        $normalizedType = $this->normalizeApplyProposalType($proposal_type);
        if ($proposal_type !== null && $normalizedType === null) {
            return [
                'tool' => 'approve_apply_proposal',
                'success' => false,
                'error' => "Invalid proposal_type: {$proposal_type}. Use change or relationship.",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $notes = trim($reviewer_notes);
        if (! $dry_run && $notes !== '' && mb_strlen($notes) < 20) {
            return [
                'tool' => 'approve_apply_proposal',
                'success' => false,
                'error' => 'reviewer_notes must be at least 20 characters when provided for non-dry-run approval.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $resolved = $this->resolveApplyProposal($proposal_id, $normalizedType, $requestedTreeId);
            if (! ($resolved['success'] ?? false)) {
                return [
                    'tool' => 'approve_apply_proposal',
                    'success' => false,
                    'error' => $resolved['error'] ?? 'Proposal not found.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $type = (string) $resolved['proposal_type'];
            $proposal = $resolved['proposal'];
            $status = (string) ($proposal->status ?? '');
            $canApproveApply = in_array($status, ['pending', 'pending_review', 'approved'], true);
            $preview = $this->buildApplyApprovedProposalPreview($type, $proposal);

            if ($dry_run) {
                return [
                    'tool' => 'approve_apply_proposal',
                    'success' => true,
                    'dry_run' => true,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'tree_id' => $requestedTreeId,
                    'status' => $status,
                    'can_approve_apply' => $canApproveApply,
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'approve_apply_proposal',
                    'success' => false,
                    'dry_run' => false,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'tree_id' => $requestedTreeId,
                    'status' => $status,
                    'can_approve_apply' => $canApproveApply,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $canApproveApply) {
                return [
                    'tool' => 'approve_apply_proposal',
                    'success' => false,
                    'dry_run' => false,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'tree_id' => $requestedTreeId,
                    'status' => $status,
                    'can_approve_apply' => false,
                    'error' => "Proposal status '{$status}' is not approvable.",
                    'proposal' => $preview,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $isFamilyMediaChange = $type === 'change' && $this->familyMediaProposalPayload($proposal) !== null;
            if ($isFamilyMediaChange && $status !== 'approved') {
                DB::update(
                    "UPDATE genealogy_proposed_changes
                     SET status = 'approved', reviewer_notes = COALESCE(?, reviewer_notes), updated_at = NOW()
                     WHERE id = ? AND tree_id = ? AND status IN ('pending', 'pending_review')",
                    [$notes !== '' ? $notes : null, $proposal_id, $requestedTreeId]
                );
                $proposal->status = 'approved';
            }

            $result = $isFamilyMediaChange
                ? $this->applyFamilyMediaLinkProposal($proposal, $requestedTreeId)
                : ($type === 'change'
                    ? app(PersonService::class)->approveAndApplyChange($proposal_id, $notes !== '' ? $notes : null)
                    : app(FamilyService::class)->approveAndApplyRelationship($proposal_id, $notes !== '' ? $notes : null));

            $this->logGenealogyWriteAudit(
                'approve_apply_proposal',
                $isFamilyMediaChange ? 'approve_apply_family_media_link' : ($type === 'change' ? 'approve_apply_person_change' : 'approve_apply_relationship'),
                'genealogy-mcp-approve-apply-proposal',
                (bool) ($result['success'] ?? false),
                [
                    'tree_id' => $requestedTreeId,
                    'proposal_type' => $type,
                    'proposal_id' => $proposal_id,
                    'person_id' => isset($proposal->person_id) ? (int) $proposal->person_id : null,
                    'related_person_id' => isset($proposal->related_person_id) ? (int) $proposal->related_person_id : null,
                    'family_id' => $result['family_id'] ?? null,
                    'status_before_apply' => $status,
                    'result' => $result,
                ],
                [
                    'summary' => $proposal->evidence_summary ?? null,
                    'sources' => $proposal->evidence_sources ?? null,
                    'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
                ],
                $type === 'change'
                    ? "Inspect genealogy_proposed_changes.id={$proposal_id} and the person/media row changed by the approved proposal to manually revert if needed."
                    : "Inspect genealogy_proposed_relationships.id={$proposal_id} and family effects from FamilyService to manually revert if needed.",
                ['dry_run' => false]
            );

            $memoryId = $this->recordProposalDecisionMemory(
                $requestedTreeId,
                $type,
                $proposal_id,
                $proposal,
                'accepted',
                $result,
                'genealogy-mcp-approve-apply-proposal'
            );

            return [
                'tool' => 'approve_apply_proposal',
                'success' => (bool) ($result['success'] ?? false),
                'dry_run' => false,
                'proposal_type' => $type,
                'proposal_id' => $proposal_id,
                'tree_id' => $requestedTreeId,
                'status_before_apply' => $status,
                'applied' => (bool) ($result['success'] ?? false),
                'result' => $result,
                'proposal' => $preview,
                'memory_id' => $memoryId,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'approve_apply_proposal',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get existing genealogy review proposals for a tree without mutating rows.
     */
    public function proposal_queue(
        int $tree_id,
        string $status = 'pending',
        int $limit = 50,
        mixed $agent_ids = null
    ): array {
        Log::info('GenealogyMCPService: proposal_queue called', [
            'tree_id' => $tree_id,
            'status' => $status,
            'limit' => $limit,
        ]);

        $status = strtolower(trim($status));
        $allowedStatuses = ['pending', 'approved', 'rejected', 'applied'];
        if (! in_array($status, $allowedStatuses, true)) {
            return [
                'tool' => 'proposal_queue',
                'success' => false,
                'error' => "Invalid status: {$status}",
                'allowed_statuses' => $allowedStatuses,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'proposal_queue',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $limit = max(1, min(200, $limit));
            $normalizedAgentIds = $this->normalizeStringList($agent_ids);
            $options = ['limit' => $limit];
            if ($normalizedAgentIds !== []) {
                $options['agent_ids'] = $normalizedAgentIds;
            }

            $queue = app(GenealogyProposalReviewQueueService::class)
                ->loadByTreeAndStatus($tree_id, $status, $options);
            if (! ($queue['success'] ?? false)) {
                return [
                    'tool' => 'proposal_queue',
                    'success' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => $queue['error'] ?? 'Proposal queue lookup failed.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            return [
                'tool' => 'proposal_queue',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'status' => $status,
                'limit' => $limit,
                'agent_ids' => $normalizedAgentIds,
                'counts' => $queue['counts'] ?? [],
                'person_changes' => $queue['person_changes'] ?? [],
                'relationships' => $queue['relationships'] ?? [],
                'items' => $this->buildProposalQueueItems($queue['person_changes'] ?? [], $queue['relationships'] ?? []),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'proposal_queue',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get genealogy RAG coverage for a tree or all trees.
     */
    public function rag_status(?int $tree_id = null): array
    {
        Log::info('GenealogyMCPService: rag_status called', ['tree_id' => $tree_id]);

        try {
            $tree = null;
            if ($tree_id !== null) {
                $tree = $this->genealogy->getTree($tree_id);
                if (! $tree) {
                    return [
                        'tool' => 'rag_status',
                        'success' => false,
                        'error' => "Tree not found: {$tree_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $treeClause = $tree_id !== null ? 'WHERE tree_id = ?' : '';
            $params = $tree_id !== null ? [$tree_id] : [];

            $persons = DB::selectOne("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) AS mysql_marked,
                    SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS mysql_pending,
                    SUM(CASE WHEN living = 1 THEN 1 ELSE 0 END) AS living
                FROM genealogy_persons
                {$treeClause}
            ", $params);

            $media = DB::selectOne("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) AS indexed,
                    SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at THEN 1 ELSE 0 END) AS stale,
                    SUM(CASE WHEN (rag_indexed_at IS NULL OR updated_at > rag_indexed_at) THEN 1 ELSE 0 END) AS needs_index
                FROM genealogy_media
                {$treeClause}
            ", $params);

            $sources = DB::selectOne("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) AS indexed,
                    SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at THEN 1 ELSE 0 END) AS stale,
                    SUM(CASE WHEN (rag_indexed_at IS NULL OR updated_at > rag_indexed_at) THEN 1 ELSE 0 END) AS needs_index
                FROM genealogy_sources
                {$treeClause}
            ", $params);

            $pgTreeClause = $tree_id !== null ? "AND metadata->>'tree_id' = ?" : '';
            $pgParams = $tree_id !== null ? [(string) $tree_id] : [];

            $personDocs = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(DISTINCT source_id) AS count
                FROM rag_documents
                WHERE document_type = 'genealogy_person'
                  AND source_type = 'genealogy_person'
                  AND source_id IS NOT NULL
                  {$pgTreeClause}
            ", $pgParams);

            $mediaDocs = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(DISTINCT metadata->>'genealogy_media_id') AS count
                FROM rag_documents
                WHERE jsonb_exists(metadata, 'genealogy_media_id')
                  {$pgTreeClause}
            ", $pgParams);

            $sourceDocs = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(DISTINCT source_id) AS count
                FROM rag_documents
                WHERE document_type = 'genealogy_source'
                  AND source_type = 'genealogy_source'
                  AND source_id IS NOT NULL
                  {$pgTreeClause}
            ", $pgParams);

            $embeddingClause = $tree_id !== null ? 'WHERE tree_id = ?' : '';
            $embeddingParams = $tree_id !== null ? [$tree_id] : [];
            $embeddings = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) AS count
                FROM genealogy_person_embeddings
                {$embeddingClause}
            ", $embeddingParams);

            $personTotal = (int) ($persons->total ?? 0);
            $personRagDocs = (int) ($personDocs->count ?? 0);
            $personEmbeddings = (int) ($embeddings->count ?? 0);
            $sourceTotal = (int) ($sources->total ?? 0);
            $sourceRagDocs = (int) ($sourceDocs->count ?? 0);

            return [
                'tool' => 'rag_status',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'persons' => [
                    'total' => $personTotal,
                    'mysql_marked_indexed' => (int) ($persons->mysql_marked ?? 0),
                    'mysql_pending' => (int) ($persons->mysql_pending ?? 0),
                    'postgres_rag_docs' => $personRagDocs,
                    'missing_postgres_rag_docs' => max(0, $personTotal - $personRagDocs),
                    'embeddings' => $personEmbeddings,
                    'missing_embeddings' => max(0, $personTotal - $personEmbeddings),
                    'living' => (int) ($persons->living ?? 0),
                ],
                'sources' => [
                    'total' => $sourceTotal,
                    'mysql_marked_indexed' => (int) ($sources->indexed ?? 0),
                    'mysql_pending' => (int) ($sources->pending ?? 0),
                    'mysql_stale' => (int) ($sources->stale ?? 0),
                    'mysql_pending_or_stale' => (int) ($sources->needs_index ?? 0),
                    'postgres_rag_docs' => $sourceRagDocs,
                    'missing_postgres_rag_docs' => max(0, $sourceTotal - $sourceRagDocs),
                ],
                'media' => [
                    'total' => (int) ($media->total ?? 0),
                    'mysql_marked_indexed' => (int) ($media->indexed ?? 0),
                    'mysql_pending' => (int) ($media->pending ?? 0),
                    'mysql_stale' => (int) ($media->stale ?? 0),
                    'mysql_pending_or_stale' => (int) ($media->needs_index ?? 0),
                    'postgres_rag_docs' => (int) ($mediaDocs->count ?? 0),
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'rag_status',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    private function buildPersonSearchResult(object $person): array
    {
        $given = trim((string) ($person->given_name ?? ''));
        $surname = trim((string) ($person->surname ?? ''));
        $nickname = trim((string) ($person->nickname ?? ''));
        $displayName = trim($given.' '.$surname);

        return [
            'id' => (int) ($person->id ?? 0),
            'tree_id' => (int) ($person->tree_id ?? 0),
            'gedcom_id' => $person->gedcom_id ?? null,
            'display_name' => $displayName !== '' ? $displayName : null,
            'given_name' => $given !== '' ? $given : null,
            'surname' => $surname !== '' ? $surname : null,
            'nickname' => $nickname !== '' ? $nickname : null,
            'sex' => $person->sex ?? null,
            'birth_date' => $person->birth_date ?? null,
            'birth_place' => $person->birth_place ?? null,
            'death_date' => $person->death_date ?? null,
            'death_place' => $person->death_place ?? null,
            'living' => isset($person->living) ? (bool) $person->living : null,
            'primary_photo_id' => isset($person->primary_photo_id) ? (int) $person->primary_photo_id : null,
            'source_count' => isset($person->source_count) ? (int) $person->source_count : 0,
            'media_count' => isset($person->media_count) ? (int) $person->media_count : 0,
            'name_variants' => $this->splitDelimitedList($person->name_variants ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitDelimitedList(mixed $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(';', $text)),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function normalizeNameVariantType(string $nameType): ?string
    {
        $value = strtolower(trim($nameType));
        $aliases = [
            'aka' => 'alias',
            'census_error' => 'alias',
            'common' => 'alias',
            'immigration' => 'alias',
            'native' => 'alias',
            'official' => 'birth',
            'other' => 'alias',
            'translation' => 'alias',
            'variant' => 'alias',
        ];
        $normalized = $aliases[$value] ?? $value;

        return in_array($normalized, ['birth', 'married', 'maiden', 'alias', 'nickname', 'religious', 'phonetic'], true)
            ? $normalized
            : null;
    }

    private function buildProposalQueueItems(array $personChanges, array $relationships): array
    {
        $items = [];

        foreach ($personChanges as $change) {
            $change = (array) $change;
            $change['proposal_type'] = 'person_change';
            $items[] = $change;
        }

        foreach ($relationships as $relationship) {
            $relationship = (array) $relationship;
            $relationship['proposal_type'] = 'relationship';
            $items[] = $relationship;
        }

        usort($items, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $items;
    }

    /**
     * Get read-only export readiness details for a tree.
     */
    public function export_readiness(int $tree_id, int $limit = 200): array
    {
        Log::info('GenealogyMCPService: export_readiness called', ['tree_id' => $tree_id]);

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'export_readiness',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $mediaPaths = $this->exporter->listNonSelfContainedMediaPaths($tree_id, null, $limit);
            $missingMedia = $this->exporter->listMissingMediaFilesForExport($tree_id, $limit);
            $pathPolicy = $this->exporter->exportPathPolicy($tree_id, $mediaPaths['root'] ?? null);

            $blockingCounts = [
                'media_paths_not_self_contained' => (int) ($mediaPaths['count'] ?? 0),
                'missing_media_files' => (int) ($missingMedia['count'] ?? 0),
            ];

            return [
                'tool' => 'export_readiness',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'media_root' => $mediaPaths['root'],
                'path_policy' => $pathPolicy,
                'issues' => [
                    'media_paths_not_self_contained' => [
                        'count' => $blockingCounts['media_paths_not_self_contained'],
                        'sample_count' => count($mediaPaths['rows']),
                        'rows' => $mediaPaths['rows'],
                    ],
                    'missing_media_files' => [
                        'count' => $blockingCounts['missing_media_files'],
                        'sample_count' => count($missingMedia['rows']),
                        'rows' => $missingMedia['rows'],
                    ],
                ],
                'blocking_counts' => $blockingCounts,
                'ready' => array_sum($blockingCounts) === 0,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'export_readiness',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Summarize whether one tree, or every tree, can stand alone outside PLOS.
     */
    public function export_standalone_status(?int $tree_id = null, int $limit = 25): array
    {
        Log::info('GenealogyMCPService: export_standalone_status called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
        ]);

        $limit = max(1, min(1000, $limit));

        try {
            $trees = [];
            if ($tree_id !== null) {
                $tree = $this->genealogy->getTree($tree_id);
                if (! $tree) {
                    return [
                        'tool' => 'export_standalone_status',
                        'success' => false,
                        'error' => "Tree not found: {$tree_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }

                $trees[] = (object) [
                    'id' => $tree_id,
                    'name' => $tree->name ?? null,
                ];
            } else {
                $trees = DB::select('SELECT id, name FROM genealogy_trees ORDER BY id');
            }

            $statuses = [];
            $readyCount = 0;
            $blockedCount = 0;

            foreach ($trees as $tree) {
                $readiness = $this->export_readiness((int) $tree->id, $limit);
                $status = $this->buildStandaloneExportStatus((int) $tree->id, $tree->name ?? null, $readiness);

                if ($status['standalone_ready']) {
                    $readyCount++;
                } else {
                    $blockedCount++;
                }

                $statuses[] = $status;
            }

            return [
                'tool' => 'export_standalone_status',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_count' => count($statuses),
                'ready_count' => $readyCount,
                'blocked_count' => $blockedCount,
                'all_ready' => $blockedCount === 0,
                'trees' => $statuses,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'export_standalone_status',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * List existing duplicate person candidates for review.
     */
    public function duplicate_candidates(
        int $tree_id,
        float $threshold = 0.6,
        int $limit = 50,
        bool $include_resolved = false
    ): array {
        Log::info('GenealogyMCPService: duplicate_candidates called', [
            'tree_id' => $tree_id,
            'threshold' => $threshold,
            'include_resolved' => $include_resolved,
        ]);

        $threshold = max(0.0, min(1.0, $threshold));
        $limit = max(1, min(200, $limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'duplicate_candidates',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $statusClause = $include_resolved ? '' : "AND dp.status IN ('pending', 'pending_merge')";
            $params = [$tree_id, $threshold, $limit];

            $rows = DB::select("
                SELECT dp.id AS duplicate_pair_id,
                       dp.tree_id,
                       dp.score,
                       dp.status,
                       dp.notes,
                       dp.created_at,
                       dp.updated_at,
                       p1.id AS person1_id,
                       p1.gedcom_id AS person1_gedcom_id,
                       p1.given_name AS person1_given_name,
                       p1.surname AS person1_surname,
                       p1.birth_date AS person1_birth_date,
                       p1.birth_place AS person1_birth_place,
                       p1.death_date AS person1_death_date,
                       p1.death_place AS person1_death_place,
                       p1.sex AS person1_sex,
                       p2.id AS person2_id,
                       p2.gedcom_id AS person2_gedcom_id,
                       p2.given_name AS person2_given_name,
                       p2.surname AS person2_surname,
                       p2.birth_date AS person2_birth_date,
                       p2.birth_place AS person2_birth_place,
                       p2.death_date AS person2_death_date,
                       p2.death_place AS person2_death_place,
                       p2.sex AS person2_sex
                FROM genealogy_duplicate_pairs dp
                JOIN genealogy_persons p1 ON p1.id = dp.person1_id AND p1.tree_id = dp.tree_id
                JOIN genealogy_persons p2 ON p2.id = dp.person2_id AND p2.tree_id = dp.tree_id
                WHERE dp.tree_id = ?
                  AND COALESCE(dp.score, 0) >= ?
                  {$statusClause}
                ORDER BY dp.score DESC,
                         FIELD(dp.status, 'pending_merge', 'pending', 'resolved', 'rejected', 'merged'),
                         dp.updated_at DESC,
                         dp.id DESC
                LIMIT ?
            ", $params);

            $count = DB::selectOne("
                SELECT COUNT(*) AS count
                FROM genealogy_duplicate_pairs dp
                WHERE dp.tree_id = ?
                  AND COALESCE(dp.score, 0) >= ?
                  {$statusClause}
            ", [$tree_id, $threshold]);

            $stats = DB::selectOne('
                SELECT
                    COUNT(*) AS total_pairs,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = "pending_merge" THEN 1 ELSE 0 END) AS pending_merge,
                    SUM(CASE WHEN status = "merged" THEN 1 ELSE 0 END) AS merged,
                    SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) AS resolved
                FROM genealogy_duplicate_pairs
                WHERE tree_id = ?
            ', [$tree_id]);

            return [
                'tool' => 'duplicate_candidates',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'threshold' => $threshold,
                'include_resolved' => $include_resolved,
                'count' => (int) ($count->count ?? count($rows)),
                'sample_count' => count($rows),
                'stats' => [
                    'total_pairs' => (int) ($stats->total_pairs ?? 0),
                    'pending' => (int) ($stats->pending ?? 0),
                    'pending_merge' => (int) ($stats->pending_merge ?? 0),
                    'merged' => (int) ($stats->merged ?? 0),
                    'rejected' => (int) ($stats->rejected ?? 0),
                    'resolved' => (int) ($stats->resolved ?? 0),
                ],
                'person_candidates' => $rows,
                'family_candidates' => [],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'duplicate_candidates',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Store durable tree-scoped semantic memory for names known not to be members of a tree.
     */
    public function non_ft_name_add(
        int $tree_id,
        array|string $names,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp',
        float $confidence = 0.8
    ): array {
        Log::info('GenealogyMCPService: non_ft_name_add called', [
            'tree_id' => $tree_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $nameList = array_slice($this->normalizeStringList($names), 0, 50);
        $reason = trim($reason);
        $confidence = max(0.0, min(1.0, $confidence));

        if ($nameList === []) {
            return [
                'tool' => 'non_ft_name_add',
                'success' => false,
                'error' => 'At least one name is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '') {
            return [
                'tool' => 'non_ft_name_add',
                'success' => false,
                'error' => 'reason is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'non_ft_name_add',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'non_ft_name_add',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($dry_run) {
                return [
                    'tool' => 'non_ft_name_add',
                    'success' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'dry_run' => true,
                    'planned_count' => count($nameList),
                    'planned_names' => $nameList,
                    'reason' => $reason,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $semantic = app(GenealogySemanticMemoryService::class);
            $memoryIds = [];
            foreach ($nameList as $name) {
                $memoryIds[] = $semantic->recordNonFtName($tree_id, $name, $reason, null, $actor, $confidence);
            }

            $this->logGenealogyWriteAudit(
                'non_ft_name_add',
                'record_tree_non_ft_names',
                $actor,
                true,
                ['tree_id' => $tree_id, 'memory_ids' => $memoryIds],
                ['names' => $nameList, 'reason' => $reason, 'confidence' => $confidence],
                'Delete the created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'non_ft_name_add',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'dry_run' => false,
                'recorded_count' => count($memoryIds),
                'memory_ids' => $memoryIds,
                'names' => $nameList,
                'reason' => $reason,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'non_ft_name_add',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Search durable genealogy semantic memory for rejected/non-FT names.
     */
    public function non_ft_name_lookup(string $name, ?int $tree_id = null, int $limit = 50): array
    {
        Log::info('GenealogyMCPService: non_ft_name_lookup called', [
            'name' => $name,
            'tree_id' => $tree_id,
        ]);

        $limit = max(1, min(100, $limit));
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');

        if ($normalized === '') {
            return [
                'tool' => 'non_ft_name_lookup',
                'success' => false,
                'error' => 'Name is required.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $treeClause = '';
            $params = [
                $normalized,
                "%{$normalized}%",
                "%{$normalized}%",
            ];

            if ($tree_id !== null) {
                $treeClause = "AND (p.tree_id = ? OR (asm.entity_type = 'genealogy_tree' AND asm.entity_id = ?))";
                $params[] = $tree_id;
                $params[] = $tree_id;
            }

            $params[] = $normalized;
            $params[] = $limit;

            $rows = DB::select("
                SELECT asm.id AS memory_id,
                       asm.entity_type AS memory_scope,
                       CASE WHEN asm.entity_type = 'genealogy_person' THEN asm.entity_id ELSE NULL END AS person_id,
                       CASE WHEN asm.entity_type = 'genealogy_tree' THEN asm.entity_id ELSE p.tree_id END AS tree_id,
                       p.given_name,
                       p.surname,
                       p.nickname,
                       asm.fact_value AS rejected_name,
                       asm.fact_type,
                       asm.fact_key,
                       asm.confidence,
                       asm.consensus_status,
                       asm.source_count,
                       asm.updated_at,
                       GROUP_CONCAT(DISTINCT afs.source_type ORDER BY afs.source_type SEPARATOR ', ') AS source_types,
                       GROUP_CONCAT(DISTINCT afs.agent_id ORDER BY afs.agent_id SEPARATOR ', ') AS agent_ids
                FROM agent_semantic_memory asm
                LEFT JOIN genealogy_persons p
                  ON p.id = asm.entity_id
                 AND asm.entity_type = 'genealogy_person'
                LEFT JOIN agent_semantic_fact_sources afs ON afs.memory_id = asm.id
                WHERE (
                    (asm.entity_type = 'genealogy_person' AND asm.fact_type = 'rejected_name')
                    OR (asm.entity_type = 'genealogy_tree' AND asm.fact_type = 'non_ft_name')
                  )
                  AND (
                    asm.fact_key = ?
                    OR asm.fact_key LIKE ?
                    OR LOWER(asm.fact_value) LIKE ?
                  )
                  {$treeClause}
                GROUP BY asm.id,
                         asm.entity_type,
                         asm.entity_id,
                         p.tree_id,
                         p.given_name,
                         p.surname,
                         p.nickname,
                         asm.fact_value,
                         asm.fact_type,
                         asm.fact_key,
                         asm.confidence,
                         asm.consensus_status,
                         asm.source_count,
                         asm.updated_at
                ORDER BY CASE WHEN asm.fact_key = ? THEN 0 ELSE 1 END,
                         asm.updated_at DESC,
                         asm.id DESC
                LIMIT ?
            ", $params);

            return [
                'tool' => 'non_ft_name_lookup',
                'success' => true,
                'name' => $name,
                'tree_id' => $tree_id,
                'count' => count($rows),
                'matches' => $rows,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'non_ft_name_lookup',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Store durable source-gap review memory so agents do not re-check known weak/collateral evidence.
     */
    public function source_gap_decision_add(
        int $tree_id,
        int $person_id,
        string $decision,
        string $reason,
        mixed $source_ids = [],
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp',
        float $confidence = 0.8
    ): array {
        Log::info('GenealogyMCPService: source_gap_decision_add called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'decision' => $decision,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $decision = strtolower(trim($decision));
        $reason = trim($reason);
        $sourceIds = array_slice($this->normalizeProposalIdList($source_ids), 0, 20);
        $confidence = max(0.0, min(1.0, $confidence));
        $allowedDecisions = $this->sourceGapDecisionAllowedDecisions();

        if (! in_array($decision, $allowedDecisions, true)) {
            return [
                'tool' => 'source_gap_decision_add',
                'success' => false,
                'error' => 'Invalid decision. Allowed: '.implode(', ', $allowedDecisions),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '' || mb_strlen($reason) < 20) {
            return [
                'tool' => 'source_gap_decision_add',
                'success' => false,
                'error' => 'reason must explain the source-gap decision in at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'source_gap_decision_add',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'source_gap_decision_add',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $person = DB::selectOne(
                'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
                [$person_id]
            );
            if (! $person || (int) $person->tree_id !== $tree_id) {
                return [
                    'tool' => 'source_gap_decision_add',
                    'success' => false,
                    'error' => 'Person not found in requested tree.',
                    'tree_id' => $tree_id,
                    'person_id' => $person_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $personName = trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));
            $plan = [
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'person_name' => $personName,
                'decision' => $decision,
                'reason' => $this->compactToolText($reason, 600),
                'source_ids' => $sourceIds,
                'confidence' => $confidence,
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'source_gap_decision_add',
                    'success' => true,
                    'dry_run' => true,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $memoryId = $this->recordSourceGapDecisionMemory($tree_id, $person_id, $personName, $decision, $reason, $sourceIds, $actor, $confidence);

            $this->logGenealogyWriteAudit(
                'source_gap_decision_add',
                'record_source_gap_decision_memory',
                $actor,
                true,
                ['tree_id' => $tree_id, 'person_id' => $person_id, 'memory_id' => $memoryId],
                $plan,
                'Delete or update the matching agent_semantic_memory row with fact_type=source_gap_decision if this decision was wrong.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'source_gap_decision_add',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'person_name' => $personName,
                'memory_id' => $memoryId,
                'decision' => $decision,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_gap_decision_add',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Read compact source-gap review memory by tree, person, and optional decision.
     */
    public function source_gap_decision_lookup(
        int $tree_id,
        ?int $person_id = null,
        string $decision = 'all',
        int $limit = 50
    ): array {
        Log::info('GenealogyMCPService: source_gap_decision_lookup called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'decision' => $decision,
        ]);

        $limit = max(1, min(100, $limit));
        $decision = strtolower(trim($decision));
        if ($decision === '') {
            $decision = 'all';
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'source_gap_decision_lookup',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $personClause = '';
            $params = [$tree_id];
            if ($person_id !== null && $person_id > 0) {
                $personClause = 'AND asm.entity_id = ?';
                $params[] = $person_id;
            }
            $params[] = $limit * 2;

            $rows = DB::select("
                SELECT asm.id AS memory_id,
                       asm.entity_id AS person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS person_name,
                       asm.fact_value,
                       asm.confidence,
                       asm.consensus_status,
                       asm.updated_at,
                       GROUP_CONCAT(DISTINCT afs.source_type ORDER BY afs.source_type SEPARATOR ', ') AS source_types
                FROM agent_semantic_memory asm
                JOIN genealogy_persons p ON p.id = asm.entity_id
                LEFT JOIN agent_semantic_fact_sources afs ON afs.memory_id = asm.id
                WHERE asm.entity_type = 'genealogy_person'
                  AND asm.fact_type = 'source_gap_decision'
                  AND p.tree_id = ?
                  {$personClause}
                GROUP BY asm.id, asm.entity_id, p.given_name, p.surname, asm.fact_value, asm.confidence, asm.consensus_status, asm.updated_at
                ORDER BY asm.updated_at DESC, asm.id DESC
                LIMIT ?
            ", $params);

            $matches = [];
            foreach ($rows as $row) {
                $value = json_decode((string) ($row->fact_value ?? ''), true);
                $value = is_array($value) ? $value : [];
                $rowDecision = (string) ($value['decision'] ?? '');
                if ($decision !== 'all' && $rowDecision !== $decision) {
                    continue;
                }

                $matches[] = [
                    'memory_id' => (int) $row->memory_id,
                    'person_id' => (int) $row->person_id,
                    'person_name' => $row->person_name ?: null,
                    'decision' => $rowDecision,
                    'reason' => $this->compactToolText((string) ($value['reason'] ?? ''), 500),
                    'source_ids' => is_array($value['source_ids'] ?? null) ? $value['source_ids'] : [],
                    'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
                    'consensus_status' => $row->consensus_status ?? null,
                    'source_types' => $row->source_types ?? null,
                    'reviewed_at' => $value['reviewed_at'] ?? $row->updated_at ?? null,
                ];

                if (count($matches) >= $limit) {
                    break;
                }
            }

            return [
                'tool' => 'source_gap_decision_lookup',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'person_id' => $person_id,
                'decision' => $decision,
                'count' => count($matches),
                'matches' => $matches,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'source_gap_decision_lookup',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Retire an isolated duplicate family row after strict safety checks.
     */
    public function family_duplicate_retire(
        int $tree_id,
        int $keep_family_id,
        int $duplicate_family_id,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: family_duplicate_retire called', [
            'tree_id' => $tree_id,
            'keep_family_id' => $keep_family_id,
            'duplicate_family_id' => $duplicate_family_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('family_duplicate_retire');
        }

        $reason = trim($reason);
        if ($keep_family_id <= 0 || $duplicate_family_id <= 0 || $keep_family_id === $duplicate_family_id) {
            return [
                'tool' => 'family_duplicate_retire',
                'success' => false,
                'error' => 'keep_family_id and duplicate_family_id must be different positive IDs.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '' || mb_strlen($reason) < 20) {
            return [
                'tool' => 'family_duplicate_retire',
                'success' => false,
                'error' => 'reason must explain the duplicate-family cleanup in at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'family_duplicate_retire',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'family_duplicate_retire',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $keep = $this->loadFamilyForDuplicateRetire($keep_family_id);
            $duplicate = $this->loadFamilyForDuplicateRetire($duplicate_family_id);
            if (! $keep || ! $duplicate || (int) $keep->tree_id !== $requestedTreeId || (int) $duplicate->tree_id !== $requestedTreeId) {
                return [
                    'tool' => 'family_duplicate_retire',
                    'success' => false,
                    'error' => 'Both families must exist in the requested tree.',
                    'tree_id' => $requestedTreeId,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $samePair = (int) ($keep->husband_id ?? 0) === (int) ($duplicate->husband_id ?? 0)
                && (int) ($keep->wife_id ?? 0) === (int) ($duplicate->wife_id ?? 0);
            if (! $samePair) {
                return [
                    'tool' => 'family_duplicate_retire',
                    'success' => false,
                    'error' => 'Families do not have the same husband_id and wife_id.',
                    'tree_id' => $requestedTreeId,
                    'keep_family_id' => $keep_family_id,
                    'duplicate_family_id' => $duplicate_family_id,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $referenceCounts = $this->familyReferenceCounts($duplicate_family_id);
            $blockingReferences = array_filter($referenceCounts, static fn (int $count): bool => $count > 0);
            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'keep_family_id' => $keep_family_id,
                'duplicate_family_id' => $duplicate_family_id,
                'husband_id' => isset($keep->husband_id) ? (int) $keep->husband_id : null,
                'wife_id' => isset($keep->wife_id) ? (int) $keep->wife_id : null,
                'duplicate_notes' => $this->compactToolText((string) ($duplicate->notes ?? ''), 300),
                'reference_counts' => $referenceCounts,
                'will_delete_duplicate_family' => $blockingReferences === [],
                'reason' => $this->compactToolText($reason, 500),
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'family_duplicate_retire',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($blockingReferences !== []) {
                return [
                    'tool' => 'family_duplicate_retire',
                    'success' => false,
                    'dry_run' => false,
                    'applied' => false,
                    'error' => 'Duplicate family still has dependent rows and must be merged manually.',
                    'blocking_references' => $blockingReferences,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $deleted = 0;
            DB::transaction(function () use ($requestedTreeId, $duplicate_family_id, &$deleted): void {
                $deleted = DB::delete(
                    'DELETE FROM genealogy_families WHERE id = ? AND tree_id = ?',
                    [$duplicate_family_id, $requestedTreeId]
                );
            });

            $this->logGenealogyWriteAudit(
                'family_duplicate_retire',
                'delete_isolated_duplicate_family',
                $actor,
                $deleted === 1,
                [
                    'tree_id' => $requestedTreeId,
                    'keep_family_id' => $keep_family_id,
                    'duplicate_family_id' => $duplicate_family_id,
                    'deleted_rows' => $deleted,
                ],
                $plan,
                'Recreate the deleted genealogy_families row from audit payload if this duplicate-family cleanup was incorrect.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'family_duplicate_retire',
                'success' => $deleted === 1,
                'dry_run' => false,
                'applied' => $deleted === 1,
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'keep_family_id' => $keep_family_id,
                'duplicate_family_id' => $duplicate_family_id,
                'deleted_rows' => $deleted,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'family_duplicate_retire',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Retire one or more invalid person-media links after strict same-tree checks.
     *
     * Matching imported-media citations are deleted only when they target the same
     * person/media pair and use the legacy imported_media_association fact type.
     *
     * @param  array<int, mixed>  $person_media_ids
     */
    public function person_media_link_retire(
        int $tree_id,
        array $person_media_ids,
        string $reason,
        bool $retire_imported_citations = true,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: person_media_link_retire called', [
            'tree_id' => $tree_id,
            'person_media_ids' => $person_media_ids,
            'retire_imported_citations' => $retire_imported_citations,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('person_media_link_retire');
        }

        $ids = $this->normalizePositiveIdList($person_media_ids, 50);
        $reason = trim($reason);
        if ($ids === []) {
            return [
                'tool' => 'person_media_link_retire',
                'success' => false,
                'error' => 'person_media_ids must contain at least one positive integer ID.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '' || mb_strlen($reason) < 20) {
            return [
                'tool' => 'person_media_link_retire',
                'success' => false,
                'error' => 'reason must explain the media-link cleanup in at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'person_media_link_retire',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'person_media_link_retire',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "SELECT pm.id, pm.person_id, pm.media_id, pm.is_primary, pm.face_confirmed, pm.notes,
                        p.given_name, p.surname, p.primary_photo_id,
                        gm.title AS media_title, gm.local_filename, gm.nextcloud_path, gm.original_path
                 FROM genealogy_person_media pm
                 JOIN genealogy_persons p ON p.id = pm.person_id AND p.tree_id = ?
                 JOIN genealogy_media gm ON gm.id = pm.media_id AND gm.tree_id = ?
                 WHERE pm.id IN ({$placeholders})
                 ORDER BY pm.id ASC",
                array_merge([$requestedTreeId, $requestedTreeId], $ids)
            );

            $foundIds = array_map(static fn (object $row): int => (int) $row->id, $rows);
            sort($foundIds);
            $missingIds = array_values(array_diff($ids, $foundIds));
            $personIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->person_id, $rows)));
            $mediaIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->media_id, $rows)));
            $citationRows = $this->personMediaImportedCitationRows($requestedTreeId, $rows);
            $citationIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->id, $citationRows)));
            $sourceIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->source_id, $citationRows)));

            $rowSummaries = array_map(fn (object $row): array => [
                'person_media_id' => (int) $row->id,
                'person_id' => (int) $row->person_id,
                'person_name' => trim((string) ($row->given_name ?? '').' '.(string) ($row->surname ?? '')) ?: null,
                'media_id' => (int) $row->media_id,
                'media_title' => $this->compactToolText((string) ($row->media_title ?? $row->local_filename ?? ''), 160),
                'is_primary' => (bool) ($row->is_primary ?? false),
                'person_primary_photo_id' => $row->primary_photo_id !== null ? (int) $row->primary_photo_id : null,
                'face_confirmed' => (bool) ($row->face_confirmed ?? false),
                'nextcloud_path' => $row->nextcloud_path ?? null,
                'original_path' => $row->original_path ?? null,
            ], $rows);

            $citationSummaries = array_map(fn (object $row): array => [
                'citation_id' => (int) $row->id,
                'person_id' => (int) $row->person_id,
                'media_id' => (int) $row->media_id,
                'source_id' => (int) $row->source_id,
                'source_title' => $this->compactToolText((string) ($row->source_title ?? ''), 160),
                'fact_type' => $row->fact_type ?? null,
            ], $citationRows);

            $canDelete = $missingIds === [];
            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_media_ids' => $ids,
                'rows' => $rowSummaries,
                'missing_or_wrong_tree_ids' => $missingIds,
                'retire_imported_citations' => $retire_imported_citations,
                'imported_citations' => $citationSummaries,
                'will_delete_person_media_links' => $canDelete,
                'will_delete_imported_citation_ids' => $retire_imported_citations ? $citationIds : [],
                'will_clear_primary_photo_ids' => array_values(array_filter($rowSummaries, static fn (array $row): bool => ($row['person_primary_photo_id'] ?? null) === ($row['media_id'] ?? null))),
                'reason' => $this->compactToolText($reason, 500),
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'person_media_link_retire',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $canDelete) {
                return [
                    'tool' => 'person_media_link_retire',
                    'success' => false,
                    'dry_run' => false,
                    'applied' => false,
                    'error' => 'One or more person-media links are missing or outside the requested tree.',
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $deletedLinks = 0;
            $deletedCitations = 0;
            $clearedPrimaryPhotos = 0;
            DB::transaction(function () use (
                $ids,
                $personIds,
                $mediaIds,
                $sourceIds,
                $citationIds,
                $requestedTreeId,
                $retire_imported_citations,
                &$deletedLinks,
                &$deletedCitations,
                &$clearedPrimaryPhotos
            ): void {
                if ($retire_imported_citations && $citationIds !== []) {
                    $citationPlaceholders = implode(',', array_fill(0, count($citationIds), '?'));
                    $deletedCitations = DB::delete("DELETE FROM genealogy_citations WHERE id IN ({$citationPlaceholders})", $citationIds);
                }

                $linkPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $deletedLinks = DB::delete("DELETE FROM genealogy_person_media WHERE id IN ({$linkPlaceholders})", $ids);

                if ($personIds !== []) {
                    $personPlaceholders = implode(',', array_fill(0, count($personIds), '?'));
                    $mediaPlaceholders = implode(',', array_fill(0, count($mediaIds), '?'));
                    $clearedPrimaryPhotos = DB::update(
                        "UPDATE genealogy_persons
                         SET primary_photo_id = NULL, rag_indexed_at = NULL, updated_at = NOW()
                         WHERE tree_id = ?
                           AND id IN ({$personPlaceholders})
                           AND primary_photo_id IN ({$mediaPlaceholders})",
                        array_merge([$requestedTreeId], $personIds, $mediaIds)
                    );
                    DB::update(
                        "UPDATE genealogy_persons
                         SET rag_indexed_at = NULL, updated_at = NOW()
                         WHERE tree_id = ? AND id IN ({$personPlaceholders})",
                        array_merge([$requestedTreeId], $personIds)
                    );
                }

                if ($mediaIds !== []) {
                    $mediaPlaceholders = implode(',', array_fill(0, count($mediaIds), '?'));
                    DB::update(
                        "UPDATE genealogy_media SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN ({$mediaPlaceholders})",
                        array_merge([$requestedTreeId], $mediaIds)
                    );
                }

                if ($sourceIds !== []) {
                    $sourcePlaceholders = implode(',', array_fill(0, count($sourceIds), '?'));
                    DB::update(
                        "UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN ({$sourcePlaceholders})",
                        array_merge([$requestedTreeId], $sourceIds)
                    );
                }
            });

            $success = $deletedLinks === count($ids)
                && (! $retire_imported_citations || $deletedCitations === count($citationIds));

            $this->logGenealogyWriteAudit(
                'person_media_link_retire',
                'delete_invalid_person_media_links',
                $actor,
                $success,
                [
                    'tree_id' => $requestedTreeId,
                    'person_media_ids' => $ids,
                    'person_ids' => $personIds,
                    'media_ids' => $mediaIds,
                    'deleted_links' => $deletedLinks,
                    'deleted_imported_citations' => $deletedCitations,
                    'cleared_primary_photos' => $clearedPrimaryPhotos,
                ],
                $plan,
                'Recreate deleted genealogy_person_media rows and imported-media genealogy_citations from the audit payload if this cleanup was incorrect.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'person_media_link_retire',
                'success' => $success,
                'dry_run' => false,
                'applied' => $success,
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_media_ids' => $ids,
                'deleted_links' => $deletedLinks,
                'deleted_imported_citations' => $deletedCitations,
                'cleared_primary_photos' => $clearedPrimaryPhotos,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_media_link_retire',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Retire one or more invalid person-source links after strict safety checks.
     *
     * @param  array<int, mixed>  $person_source_ids
     */
    public function person_source_link_retire(
        int $tree_id,
        array $person_source_ids,
        string $reason,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: person_source_link_retire called', [
            'tree_id' => $tree_id,
            'person_source_ids' => $person_source_ids,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('person_source_link_retire');
        }

        $ids = $this->normalizePositiveIdList($person_source_ids, 50);
        $reason = trim($reason);
        if ($ids === []) {
            return [
                'tool' => 'person_source_link_retire',
                'success' => false,
                'error' => 'person_source_ids must contain at least one positive integer ID.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($reason === '' || mb_strlen($reason) < 20) {
            return [
                'tool' => 'person_source_link_retire',
                'success' => false,
                'error' => 'reason must explain the source-link cleanup in at least 20 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'person_source_link_retire',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'person_source_link_retire',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->loadPersonSourceRowsForRetire($ids, $requestedTreeId);
            $foundIds = array_map(static fn (object $row): int => (int) $row->id, $rows);
            sort($foundIds);
            $missingIds = array_values(array_diff($ids, $foundIds));

            $citationCounts = $this->personSourceCitationCounts($rows);
            $blockingRows = array_values(array_filter(
                $citationCounts,
                static fn (array $row): bool => (int) ($row['citation_count'] ?? 0) > 0
            ));
            $canDelete = $missingIds === [] && $blockingRows === [];
            $rowSummaries = array_map(fn (object $row): array => [
                'person_source_id' => (int) $row->id,
                'person_id' => (int) $row->person_id,
                'person_name' => trim((string) ($row->given_name ?? '').' '.(string) ($row->surname ?? '')) ?: null,
                'source_id' => (int) $row->source_id,
                'source_title' => $this->compactToolText((string) ($row->source_title ?? ''), 160),
                'page' => $this->compactToolText((string) ($row->page ?? ''), 160),
                'quality' => $row->quality ?? null,
                'citation_count' => (int) ($citationCounts[(int) $row->id]['citation_count'] ?? 0),
            ], $rows);

            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_source_ids' => $ids,
                'rows' => $rowSummaries,
                'missing_or_wrong_tree_ids' => $missingIds,
                'blocking_citations' => $blockingRows,
                'will_delete_person_source_links' => $canDelete,
                'reason' => $this->compactToolText($reason, 500),
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'person_source_link_retire',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $canDelete) {
                return [
                    'tool' => 'person_source_link_retire',
                    'success' => false,
                    'dry_run' => false,
                    'applied' => false,
                    'error' => 'One or more person-source links are missing, outside the requested tree, or already have citations.',
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $personIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->person_id, $rows)));
            $sourceIds = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->source_id, $rows)));
            $deleted = 0;
            DB::transaction(function () use ($ids, $personIds, $sourceIds, $requestedTreeId, &$deleted): void {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $deleted = DB::delete("DELETE FROM genealogy_person_sources WHERE id IN ({$placeholders})", $ids);

                if ($personIds !== []) {
                    $personPlaceholders = implode(',', array_fill(0, count($personIds), '?'));
                    DB::update(
                        "UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN ({$personPlaceholders})",
                        array_merge([$requestedTreeId], $personIds)
                    );
                }

                if ($sourceIds !== []) {
                    $sourcePlaceholders = implode(',', array_fill(0, count($sourceIds), '?'));
                    DB::update(
                        "UPDATE genealogy_sources SET rag_indexed_at = NULL, updated_at = NOW() WHERE tree_id = ? AND id IN ({$sourcePlaceholders})",
                        array_merge([$requestedTreeId], $sourceIds)
                    );
                }
            });

            $this->logGenealogyWriteAudit(
                'person_source_link_retire',
                'delete_uncited_person_source_links',
                $actor,
                $deleted === count($ids),
                [
                    'tree_id' => $requestedTreeId,
                    'person_source_ids' => $ids,
                    'person_ids' => $personIds,
                    'source_ids' => $sourceIds,
                    'deleted_rows' => $deleted,
                ],
                $plan,
                'Recreate deleted genealogy_person_sources rows from audit payload if this source-link cleanup was incorrect.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'person_source_link_retire',
                'success' => $deleted === count($ids),
                'dry_run' => false,
                'applied' => $deleted === count($ids),
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'person_source_ids' => $ids,
                'deleted_rows' => $deleted,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'person_source_link_retire',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Save a guarded tree-local research memo and optional person/family note.
     */
    public function research_memo_save(
        int $tree_id,
        string $title,
        string $body,
        ?int $person_id = null,
        ?int $family_id = null,
        ?string $relative_path = null,
        ?string $notes_append = null,
        ?string $source_gap_decision = null,
        ?string $source_gap_reason = null,
        mixed $source_ids = [],
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp',
        bool $overwrite = false,
        float $confidence = 0.8
    ): array {
        Log::info('GenealogyMCPService: research_memo_save called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'family_id' => $family_id,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $requestedTreeId = $this->normalizeRequiredTreeId($tree_id);
        if ($requestedTreeId === null) {
            return $this->treeIdRequiredResponse('research_memo_save');
        }

        $title = trim($title);
        $body = trim($body);
        $notesAppend = trim((string) $notes_append);
        $sourceGapDecision = strtolower(trim((string) $source_gap_decision));
        $sourceGapReason = trim((string) $source_gap_reason);
        $sourceIds = array_slice($this->normalizeProposalIdList($source_ids), 0, 20);
        $confidence = max(0.0, min(1.0, $confidence));

        if ($title === '' || mb_strlen($title) < 3) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => 'title must be at least 3 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($body === '' || mb_strlen($body) < 20) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => 'body must contain at least 20 characters of reviewed research context.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($notesAppend !== '' && $person_id === null && $family_id === null) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => 'notes_append requires person_id or family_id.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($sourceGapDecision !== '' && ($person_id === null || $sourceGapReason === '')) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => 'source_gap_decision requires person_id and source_gap_reason.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($sourceGapDecision !== '') {
            $allowedDecisions = $this->sourceGapDecisionAllowedDecisions();
            if (! in_array($sourceGapDecision, $allowedDecisions, true)) {
                return [
                    'tool' => 'research_memo_save',
                    'success' => false,
                    'error' => 'Invalid source_gap_decision. Allowed: '.implode(', ', $allowedDecisions),
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (mb_strlen($sourceGapReason) < 20) {
                return [
                    'tool' => 'research_memo_save',
                    'success' => false,
                    'error' => 'source_gap_reason must explain the source-gap decision in at least 20 characters.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }
        }

        try {
            $tree = $this->genealogy->getTree($requestedTreeId);
            if (! $tree) {
                return [
                    'tool' => 'research_memo_save',
                    'success' => false,
                    'error' => "Tree not found: {$requestedTreeId}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $person = null;
            if ($person_id !== null) {
                $person = DB::selectOne(
                    'SELECT id, tree_id, gedcom_id, given_name, surname, notes FROM genealogy_persons WHERE id = ?',
                    [$person_id]
                );
                if (! $person || (int) $person->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'research_memo_save',
                        'success' => false,
                        'error' => 'Person not found in requested tree.',
                        'tree_id' => $requestedTreeId,
                        'person_id' => $person_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $family = null;
            if ($family_id !== null) {
                $family = DB::selectOne(
                    'SELECT id, tree_id, gedcom_id, notes FROM genealogy_families WHERE id = ?',
                    [$family_id]
                );
                if (! $family || (int) $family->tree_id !== $requestedTreeId) {
                    return [
                        'tool' => 'research_memo_save',
                        'success' => false,
                        'error' => 'Family not found in requested tree.',
                        'tree_id' => $requestedTreeId,
                        'family_id' => $family_id,
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $root = app(GenealogyTreeRootResolver::class)->mediaRoot($requestedTreeId);
            $relativePath = $this->researchMemoRelativePath($relative_path, $title, $person_id, $family_id);
            $targetPath = rtrim($root, '/').'/'.$relativePath;
            $content = $this->researchMemoContent($title, $body, $requestedTreeId, $person, $family, $actor);
            $fileExists = File::exists($targetPath);

            $plan = [
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'media_root' => $root,
                'relative_path' => $relativePath,
                'target_path' => $targetPath,
                'file_exists' => $fileExists,
                'overwrite' => $overwrite,
                'person_id' => $person_id,
                'family_id' => $family_id,
                'will_append_note' => $notesAppend !== '',
                'will_record_source_gap_decision' => $sourceGapDecision !== '',
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'research_memo_save',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if ($fileExists && ! $overwrite) {
                return [
                    'tool' => 'research_memo_save',
                    'success' => false,
                    'dry_run' => false,
                    'applied' => false,
                    'error' => 'Target memo already exists; rerun with overwrite=true or choose another relative_path.',
                    'plan' => $plan,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $personNotesUpdated = 0;
            $familyNotesUpdated = 0;
            $sourceGapResult = null;
            $previousContent = $fileExists ? File::get($targetPath) : null;
            $bytes = false;

            try {
                File::ensureDirectoryExists(dirname($targetPath));
                $bytes = File::put($targetPath, $content);
                if ($bytes === false) {
                    throw new \RuntimeException('Failed to write research memo file.');
                }

                DB::transaction(function () use (
                    $notesAppend,
                    $targetPath,
                    $person,
                    $family,
                    $person_id,
                    $family_id,
                    $requestedTreeId,
                    $sourceGapDecision,
                    $sourceGapReason,
                    $sourceIds,
                    $actor,
                    $confidence,
                    &$personNotesUpdated,
                    &$familyNotesUpdated,
                    &$sourceGapResult
                ): void {
                    if ($notesAppend !== '') {
                        $noteText = $this->researchMemoNoteText($notesAppend, $targetPath);
                        if ($person !== null) {
                            $personNotesUpdated = DB::update(
                                'UPDATE genealogy_persons SET notes = ?, rag_indexed_at = NULL, updated_at = NOW() WHERE id = ? AND tree_id = ?',
                                [$this->appendNoteText($person->notes ?? null, $noteText), $person_id, $requestedTreeId]
                            );
                        }
                        if ($family !== null) {
                            $familyNotesUpdated = DB::update(
                                'UPDATE genealogy_families SET notes = ?, updated_at = NOW() WHERE id = ? AND tree_id = ?',
                                [$this->appendNoteText($family->notes ?? null, $noteText), $family_id, $requestedTreeId]
                            );
                        }
                    } elseif ($person !== null) {
                        DB::update(
                            'UPDATE genealogy_persons SET rag_indexed_at = NULL, updated_at = NOW() WHERE id = ? AND tree_id = ?',
                            [$person_id, $requestedTreeId]
                        );
                    }

                    if ($sourceGapDecision !== '' && $person !== null && $person_id !== null) {
                        $personName = trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));
                        $memoryId = $this->recordSourceGapDecisionMemory(
                            $requestedTreeId,
                            $person_id,
                            $personName,
                            $sourceGapDecision,
                            $sourceGapReason,
                            $sourceIds,
                            $actor,
                            $confidence
                        );
                        $sourceGapResult = [
                            'tool' => 'source_gap_decision_add',
                            'success' => true,
                            'tree_id' => $requestedTreeId,
                            'person_id' => $person_id,
                            'person_name' => $personName,
                            'memory_id' => $memoryId,
                            'decision' => $sourceGapDecision,
                        ];
                    }
                });
            } catch (\Throwable $writeException) {
                if ($bytes !== false) {
                    if ($fileExists && $previousContent !== null) {
                        File::put($targetPath, $previousContent);
                    } else {
                        File::delete($targetPath);
                    }
                }

                throw $writeException;
            }

            if ($sourceGapResult !== null) {
                $this->logGenealogyWriteAudit(
                    'source_gap_decision_add',
                    'record_source_gap_decision_memory',
                    $actor,
                    true,
                    [
                        'tree_id' => $requestedTreeId,
                        'person_id' => $person_id,
                        'memory_id' => $sourceGapResult['memory_id'] ?? null,
                    ],
                    [
                        'tree_id' => $requestedTreeId,
                        'person_id' => $person_id,
                        'decision' => $sourceGapDecision,
                        'reason' => $this->compactToolText($sourceGapReason, 600),
                        'source_ids' => $sourceIds,
                        'confidence' => $confidence,
                        'actor' => $actor,
                    ],
                    'Delete or update the matching agent_semantic_memory row with fact_type=source_gap_decision if this decision was wrong.',
                    ['dry_run' => false]
                );
            }

            $this->logGenealogyWriteAudit(
                'research_memo_save',
                'save_ft_local_research_memo',
                $actor,
                $bytes !== false,
                [
                    'tree_id' => $requestedTreeId,
                    'person_id' => $person_id,
                    'family_id' => $family_id,
                    'target_path' => $targetPath,
                    'bytes' => $bytes,
                    'person_notes_updated' => $personNotesUpdated,
                    'family_notes_updated' => $familyNotesUpdated,
                    'source_gap_memory_id' => $sourceGapResult['memory_id'] ?? null,
                ],
                [
                    'title' => $title,
                    'source_gap_decision' => $sourceGapDecision !== '' ? $sourceGapDecision : null,
                    'source_ids' => $sourceIds,
                    'confidence' => $confidence,
                ],
                'Delete the memo file and remove appended person/family notes or source_gap_decision memory if this research memo was incorrect.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'research_memo_save',
                'success' => true,
                'dry_run' => false,
                'applied' => $bytes !== false,
                'tree_id' => $requestedTreeId,
                'tree_name' => $tree->name ?? null,
                'target_path' => $targetPath,
                'relative_path' => $relativePath,
                'bytes' => $bytes,
                'person_notes_updated' => $personNotesUpdated,
                'family_notes_updated' => $familyNotesUpdated,
                'source_gap_result' => $sourceGapResult,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'research_memo_save',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Backfill accepted/rejected proposal decisions into tree-scoped Genea semantic memory.
     */
    public function review_decision_memory_batch(
        int $tree_id,
        ?string $status = 'applied,rejected',
        int $limit = 50,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: review_decision_memory_batch called', [
            'tree_id' => $tree_id,
            'status' => $status,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(1, min(200, $limit));
        $statuses = $this->normalizeReviewDecisionMemoryStatuses($status);
        if ($statuses === false) {
            return [
                'tool' => 'review_decision_memory_batch',
                'success' => false,
                'error' => 'Invalid status. Use approved, applied, rejected, terminal, or all.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'review_decision_memory_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->loadReviewDecisionMemoryCandidates($tree_id, $statuses, $limit);
            $previews = array_map(fn (object $row): array => $this->buildReviewDecisionMemoryPreview($row), $rows);

            if ($dry_run) {
                return [
                    'tool' => 'review_decision_memory_batch',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'statuses' => $statuses,
                    'candidate_count' => count($previews),
                    'candidates' => $previews,
                    'write_policy' => 'dry_run_first_confirm_required_idempotent_source_memory',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'review_decision_memory_batch',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'candidate_count' => count($previews),
                    'candidates' => $previews,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $semantic = app(GenealogySemanticMemoryService::class);
            $memoryIds = [];
            foreach ($rows as $row) {
                $decision = ((string) $row->status) === 'rejected' ? 'rejected' : 'accepted';
                $memoryIds[] = $semantic->recordReviewDecision(
                    $tree_id,
                    (string) $row->proposal_type,
                    (int) $row->proposal_id,
                    $decision,
                    $this->buildReviewDecisionMemoryPayload($row),
                    $actor !== '' ? $actor : ($row->agent_id ?? null),
                    isset($row->confidence) ? (float) $row->confidence : 0.75
                );
            }

            $this->logGenealogyWriteAudit(
                'review_decision_memory_batch',
                'record_review_decision_memory',
                $actor,
                true,
                [
                    'tree_id' => $tree_id,
                    'statuses' => $statuses,
                    'candidate_count' => count($rows),
                    'memory_ids' => $memoryIds,
                ],
                [
                    'source_tables' => ['genealogy_proposed_changes', 'genealogy_proposed_relationships'],
                ],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'review_decision_memory_batch',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'statuses' => $statuses,
                'recorded_count' => count($memoryIds),
                'memory_ids' => $memoryIds,
                'candidates' => $previews,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'review_decision_memory_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Backfill accepted/rejected genealogy review-packet outcomes into tree-scoped Genea semantic memory.
     */
    public function review_packet_memory_batch(
        int $tree_id,
        ?string $status = 'reviewed,rejected',
        int $limit = 50,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: review_packet_memory_batch called', [
            'tree_id' => $tree_id,
            'status' => $status,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(1, min(200, $limit));
        $statuses = $this->normalizeReviewPacketMemoryStatuses($status);
        if ($statuses === false) {
            return [
                'tool' => 'review_packet_memory_batch',
                'success' => false,
                'error' => 'Invalid status. Use reviewed, rejected, terminal, all, or comma-separated statuses.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'review_packet_memory_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! Schema::hasTable('agent_review_queue')
                || ! Schema::hasTable('agent_semantic_memory')
                || ! Schema::hasTable('agent_semantic_fact_sources')
            ) {
                return [
                    'tool' => 'review_packet_memory_batch',
                    'success' => false,
                    'error' => 'Required review queue or semantic memory tables are not available.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->loadReviewPacketMemoryCandidates($tree_id, $statuses, $limit);
            $previews = array_map(fn (object $row): array => $this->buildReviewPacketMemoryPreview($row), $rows);

            if ($dry_run) {
                return [
                    'tool' => 'review_packet_memory_batch',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'statuses' => $statuses,
                    'candidate_count' => count($previews),
                    'candidates' => $previews,
                    'write_policy' => 'dry_run_first_confirm_required_idempotent_review_packet_memory',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'review_packet_memory_batch',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'candidate_count' => count($previews),
                    'candidates' => $previews,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $semantic = app(GenealogySemanticMemoryService::class);
            $memoryIds = [];
            foreach ($rows as $row) {
                $decision = ((string) $row->status) === 'rejected' ? 'rejected' : 'accepted';
                $memoryIds[] = $semantic->recordReviewDecision(
                    $tree_id,
                    'review_packet',
                    (int) $row->review_queue_id,
                    $decision,
                    $this->buildReviewPacketMemoryPayload($row),
                    $actor !== '' ? $actor : ($row->agent_id ?? null),
                    isset($row->confidence) ? (float) $row->confidence : 0.75
                );
            }

            $this->logGenealogyWriteAudit(
                'review_packet_memory_batch',
                'record_review_packet_memory',
                $actor,
                true,
                [
                    'tree_id' => $tree_id,
                    'statuses' => $statuses,
                    'candidate_count' => count($rows),
                    'memory_ids' => $memoryIds,
                ],
                [
                    'source_table' => 'agent_review_queue',
                    'review_type' => GenealogyReviewPacketAdapterService::REVIEW_TYPE,
                ],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'review_packet_memory_batch',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'statuses' => $statuses,
                'recorded_count' => count($memoryIds),
                'memory_ids' => $memoryIds,
                'candidates' => $previews,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'review_packet_memory_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function lesson_memory_save(
        int $tree_id,
        string $lesson_type,
        string $title,
        string $lesson,
        mixed $tags = [],
        mixed $source_ids = [],
        mixed $person_ids = [],
        mixed $media_ids = [],
        mixed $task_ids = [],
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp',
        float $confidence = 0.8
    ): array {
        Log::info('GenealogyMCPService: lesson_memory_save called', [
            'tree_id' => $tree_id,
            'lesson_type' => $lesson_type,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $lessonType = $this->normalizeLessonMemoryType($lesson_type);
        $title = trim($title);
        $lesson = trim($lesson);
        $tagList = array_slice($this->normalizeStringList($tags), 0, 20);
        $sourceIds = array_slice($this->normalizeProposalIdList($source_ids), 0, 50);
        $personIds = array_slice($this->normalizeProposalIdList($person_ids), 0, 50);
        $mediaIds = array_slice($this->normalizeProposalIdList($media_ids), 0, 50);
        $taskIds = array_slice($this->normalizeProposalIdList($task_ids), 0, 50);
        $confidence = max(0.0, min(1.0, $confidence));

        if ($lessonType === null) {
            return [
                'tool' => 'lesson_memory_save',
                'success' => false,
                'error' => 'Invalid lesson_type. Allowed: '.implode(', ', self::LESSON_MEMORY_TYPES),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (mb_strlen($title) < 3) {
            return [
                'tool' => 'lesson_memory_save',
                'success' => false,
                'error' => 'title must be at least 3 characters.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (mb_strlen($lesson) < 30) {
            return [
                'tool' => 'lesson_memory_save',
                'success' => false,
                'error' => 'lesson must contain at least 30 characters of reviewed guidance.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'lesson_memory_save',
                'success' => false,
                'dry_run' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'lesson_memory_save',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $referenceErrors = $this->validateLessonMemoryReferences($tree_id, $sourceIds, $personIds, $mediaIds, $taskIds);
            if ($referenceErrors !== []) {
                return [
                    'tool' => 'lesson_memory_save',
                    'success' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'One or more referenced genealogy rows are not in the requested tree.',
                    'reference_errors' => $referenceErrors,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $payload = [
                'tags' => $tagList,
                'source_ids' => $sourceIds,
                'person_ids' => $personIds,
                'media_ids' => $mediaIds,
                'task_ids' => $taskIds,
                'actor' => $actor,
                'recorded_at' => now()->toIso8601String(),
            ];

            $plan = [
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'lesson_type' => $lessonType,
                'title' => $title,
                'lesson' => $this->compactToolText($lesson, 1000),
                'tags' => $tagList,
                'source_ids' => $sourceIds,
                'person_ids' => $personIds,
                'media_ids' => $mediaIds,
                'task_ids' => $taskIds,
                'confidence' => $confidence,
                'actor' => $actor,
            ];

            if ($dry_run) {
                return [
                    'tool' => 'lesson_memory_save',
                    'success' => true,
                    'dry_run' => true,
                    'applied' => false,
                    'plan' => $plan,
                    'write_policy' => 'dry_run_first_confirm_required_tree_scoped_lesson_memory',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $memoryId = app(GenealogySemanticMemoryService::class)->recordLessonMemory(
                $tree_id,
                $lessonType,
                $title,
                $lesson,
                $payload,
                $actor,
                $confidence
            );

            $this->logGenealogyWriteAudit(
                'lesson_memory_save',
                'record_genealogy_lesson_memory',
                $actor,
                true,
                ['tree_id' => $tree_id, 'memory_id' => $memoryId],
                $plan,
                'Delete the created agent_semantic_memory row and linked agent_semantic_fact_sources row by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'lesson_memory_save',
                'success' => true,
                'dry_run' => false,
                'applied' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'memory_id' => $memoryId,
                'lesson_type' => $lessonType,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'lesson_memory_save',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function lesson_memory_lookup(
        int $tree_id,
        ?string $lesson_type = 'all',
        ?string $query = null,
        int $limit = 20
    ): array {
        Log::info('GenealogyMCPService: lesson_memory_lookup called', [
            'tree_id' => $tree_id,
            'lesson_type' => $lesson_type,
            'query' => $query,
            'limit' => $limit,
        ]);

        $limit = max(1, min(100, $limit));
        $lessonType = strtolower(trim((string) $lesson_type));
        if ($lessonType === '') {
            $lessonType = 'all';
        }

        if ($lessonType !== 'all') {
            $normalizedLessonType = $this->normalizeLessonMemoryType($lessonType);
            if ($normalizedLessonType === null) {
                return [
                    'tool' => 'lesson_memory_lookup',
                    'success' => false,
                    'error' => 'Invalid lesson_type. Use all or one of: '.implode(', ', self::LESSON_MEMORY_TYPES),
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $lessonType = $normalizedLessonType;
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'lesson_memory_lookup',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $where = [
                "asm.entity_type = 'genealogy_tree'",
                'asm.entity_id = ?',
                'asm.fact_type IN ('.implode(', ', array_fill(0, count(self::LESSON_MEMORY_TYPES), '?')).')',
            ];
            $params = [$tree_id, ...self::LESSON_MEMORY_TYPES];

            if ($lessonType !== 'all') {
                $where[] = 'asm.fact_type = ?';
                $params[] = $lessonType;
            }

            $query = trim((string) $query);
            if ($query !== '') {
                $where[] = '(asm.fact_key LIKE ? OR asm.fact_value LIKE ?)';
                $like = '%'.$query.'%';
                $params[] = $like;
                $params[] = $like;
            }

            $params[] = $limit;

            $rows = DB::select("
                SELECT asm.id AS memory_id,
                       asm.fact_type AS lesson_type,
                       asm.fact_key,
                       asm.fact_value,
                       asm.confidence,
                       asm.consensus_status,
                       asm.updated_at,
                       GROUP_CONCAT(DISTINCT afs.agent_id ORDER BY afs.agent_id SEPARATOR ',') AS agent_ids
                FROM agent_semantic_memory asm
                LEFT JOIN agent_semantic_fact_sources afs ON afs.memory_id = asm.id
                WHERE ".implode(' AND ', $where).'
                GROUP BY asm.id, asm.fact_type, asm.fact_key, asm.fact_value, asm.confidence, asm.consensus_status, asm.updated_at
                ORDER BY asm.updated_at DESC, asm.id DESC
                LIMIT ?
            ', $params);

            return [
                'tool' => 'lesson_memory_lookup',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'lesson_type' => $lessonType,
                'query' => $query !== '' ? $query : null,
                'count' => count($rows),
                'lessons' => array_map(fn (object $row): array => $this->buildLessonMemoryLookupRow($row), $rows),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'lesson_memory_lookup',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    public function lesson_memory_context(
        int $tree_id,
        ?int $person_id = null,
        ?int $media_id = null,
        ?int $source_id = null,
        ?int $task_id = null,
        ?string $query = null,
        ?string $lesson_type = 'all',
        int $limit = 8
    ): array {
        Log::info('GenealogyMCPService: lesson_memory_context called', [
            'tree_id' => $tree_id,
            'person_id' => $person_id,
            'media_id' => $media_id,
            'source_id' => $source_id,
            'task_id' => $task_id,
            'query' => $query,
            'lesson_type' => $lesson_type,
            'limit' => $limit,
        ]);

        $limit = max(1, min(25, $limit));
        $lessonType = strtolower(trim((string) $lesson_type));
        if ($lessonType === '') {
            $lessonType = 'all';
        }
        if ($lessonType !== 'all') {
            $normalizedLessonType = $this->normalizeLessonMemoryType($lessonType);
            if ($normalizedLessonType === null) {
                return [
                    'tool' => 'lesson_memory_context',
                    'success' => false,
                    'error' => 'Invalid lesson_type. Use all or one of: '.implode(', ', self::LESSON_MEMORY_TYPES),
                    'timestamp' => now()->toIso8601String(),
                ];
            }
            $lessonType = $normalizedLessonType;
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'lesson_memory_context',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $terms = $this->lessonContextTerms($query);
            $targets = [];

            if ($person_id !== null && $person_id > 0) {
                $person = DB::selectOne(
                    'SELECT id, given_name, surname, birth_date, birth_place, death_date, death_place, notes
                     FROM genealogy_persons
                     WHERE id = ? AND tree_id = ?',
                    [$person_id, $tree_id]
                );
                if (! $person) {
                    return $this->lessonContextTargetError('person_id', $person_id, $tree_id);
                }
                $targets['person'] = $this->lessonContextTargetSummary($person, ['given_name', 'surname', 'birth_date', 'birth_place', 'death_date', 'death_place']);
                $terms = array_merge($terms, $this->lessonContextTermsFromRow($person, ['given_name', 'surname', 'birth_place', 'death_place', 'notes']));
            }

            if ($media_id !== null && $media_id > 0) {
                $media = DB::selectOne(
                    'SELECT id, title, media_type, file_format, original_path, nextcloud_path, local_filename,
                            description, transcription_text, ai_description, source_folder
                     FROM genealogy_media
                     WHERE id = ? AND tree_id = ?',
                    [$media_id, $tree_id]
                );
                if (! $media) {
                    return $this->lessonContextTargetError('media_id', $media_id, $tree_id);
                }
                $targets['media'] = $this->lessonContextTargetSummary($media, ['title', 'media_type', 'file_format', 'local_filename']);
                $terms = array_merge($terms, $this->lessonContextTermsFromRow($media, ['title', 'media_type', 'file_format', 'original_path', 'nextcloud_path', 'local_filename', 'description', 'ai_description', 'source_folder']));
            }

            if ($source_id !== null && $source_id > 0) {
                $source = DB::selectOne(
                    'SELECT id, title, author, publication, repository, call_number, url,
                            notes, source_quality, information_quality
                     FROM genealogy_sources
                     WHERE id = ? AND tree_id = ?',
                    [$source_id, $tree_id]
                );
                if (! $source) {
                    return $this->lessonContextTargetError('source_id', $source_id, $tree_id);
                }
                $targets['source'] = $this->lessonContextTargetSummary($source, ['title', 'author', 'repository', 'source_quality']);
                $terms = array_merge($terms, $this->lessonContextTermsFromRow($source, ['title', 'author', 'publication', 'repository', 'call_number', 'url', 'notes', 'source_quality', 'information_quality']));
            }

            if ($task_id !== null && $task_id > 0) {
                $task = DB::selectOne(
                    'SELECT id, task_type, research_question, selection_reason, scope_reason,
                            evidence_summary, conflicts_found, outcome_state, outcome_reason, status
                     FROM genealogy_research_tasks
                     WHERE id = ? AND tree_id = ?',
                    [$task_id, $tree_id]
                );
                if (! $task) {
                    return $this->lessonContextTargetError('task_id', $task_id, $tree_id);
                }
                $targets['task'] = $this->lessonContextTargetSummary($task, ['task_type', 'research_question', 'status', 'outcome_state']);
                $terms = array_merge($terms, $this->lessonContextTermsFromRow($task, ['task_type', 'research_question', 'selection_reason', 'scope_reason', 'evidence_summary', 'conflicts_found', 'outcome_state', 'outcome_reason']));
            }

            $terms = $this->normalizeLessonContextTerms($terms);
            $rows = $this->loadLessonMemoryRows($tree_id, $lessonType, $terms, $limit);
            if ($rows === [] && $terms !== []) {
                $rows = $this->loadLessonMemoryRows($tree_id, $lessonType, [], min(3, $limit));
            }

            $lessons = array_map(fn (object $row): array => $this->buildLessonMemoryLookupRow($row), $rows);

            return [
                'tool' => 'lesson_memory_context',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'lesson_type' => $lessonType,
                'targets' => $targets,
                'terms' => $terms,
                'count' => count($lessons),
                'context_text' => $this->buildLessonMemoryContextText($lessons),
                'lessons' => $lessons,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'lesson_memory_context',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Report what Genea has learned without dumping raw memory tables.
     */
    public function memory_report(?int $tree_id = null, int $limit = 20): array
    {
        Log::info('GenealogyMCPService: memory_report called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
        ]);

        $limit = max(1, min(100, $limit));

        try {
            $tree = null;
            if ($tree_id !== null) {
                $tree = $this->genealogy->getTree($tree_id);
                if (! $tree) {
                    return [
                        'tool' => 'memory_report',
                        'success' => false,
                        'error' => "Tree not found: {$tree_id}",
                        'timestamp' => now()->toIso8601String(),
                    ];
                }
            }

            $semanticWhere = "asm.entity_type LIKE 'genealogy_%'";
            $semanticParams = [];
            if ($tree_id !== null) {
                $semanticWhere = "asm.entity_type = 'genealogy_tree' AND asm.entity_id = ?";
                $semanticParams[] = $tree_id;
            }

            $semanticCounts = DB::select("
                SELECT asm.fact_type,
                       asm.consensus_status,
                       COUNT(*) AS count
                FROM agent_semantic_memory asm
                WHERE {$semanticWhere}
                GROUP BY asm.fact_type, asm.consensus_status
                ORDER BY asm.fact_type, asm.consensus_status
            ", $semanticParams);

            $recentSemantic = DB::select("
                SELECT asm.id AS memory_id,
                       asm.entity_type,
                       asm.entity_id,
                       asm.fact_type,
                       asm.fact_key,
                       asm.fact_value,
                       asm.confidence,
                       asm.consensus_status,
                       asm.updated_at,
                       GROUP_CONCAT(DISTINCT afs.source_type ORDER BY afs.source_type SEPARATOR ',') AS source_types,
                       GROUP_CONCAT(DISTINCT afs.agent_id ORDER BY afs.agent_id SEPARATOR ',') AS agent_ids
                FROM agent_semantic_memory asm
                LEFT JOIN agent_semantic_fact_sources afs ON afs.memory_id = asm.id
                WHERE {$semanticWhere}
                GROUP BY asm.id, asm.entity_type, asm.entity_id, asm.fact_type, asm.fact_key, asm.fact_value, asm.confidence, asm.consensus_status, asm.updated_at
                ORDER BY asm.updated_at DESC, asm.id DESC
                LIMIT ?
            ", [...$semanticParams, $limit]);

            $nameGuardrailParams = [];
            $nameGuardrailTreeClause = '';
            if ($tree_id !== null) {
                $nameGuardrailTreeClause = "AND (p.tree_id = ? OR (asm.entity_type = 'genealogy_tree' AND asm.entity_id = ?))";
                $nameGuardrailParams = [$tree_id, $tree_id];
            }

            $nameGuardrailCounts = DB::selectOne("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN asm.entity_type = 'genealogy_person' AND asm.fact_type = 'rejected_name' THEN 1 ELSE 0 END) AS person_rejected_names,
                       SUM(CASE WHEN asm.entity_type = 'genealogy_tree' AND asm.fact_type = 'non_ft_name' THEN 1 ELSE 0 END) AS tree_non_ft_names
                FROM agent_semantic_memory asm
                LEFT JOIN genealogy_persons p
                  ON p.id = asm.entity_id
                 AND asm.entity_type = 'genealogy_person'
                WHERE (
                    (asm.entity_type = 'genealogy_person' AND asm.fact_type = 'rejected_name')
                    OR (asm.entity_type = 'genealogy_tree' AND asm.fact_type = 'non_ft_name')
                  )
                  {$nameGuardrailTreeClause}
            ", $nameGuardrailParams);

            $nameGuardrailSamples = DB::select("
                SELECT asm.id AS memory_id,
                       asm.entity_type AS memory_scope,
                       CASE WHEN asm.entity_type = 'genealogy_person' THEN asm.entity_id ELSE NULL END AS person_id,
                       CASE WHEN asm.entity_type = 'genealogy_tree' THEN asm.entity_id ELSE p.tree_id END AS tree_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS person_name,
                       asm.fact_value AS name,
                       asm.fact_type,
                       asm.fact_key,
                       asm.confidence,
                       asm.consensus_status,
                       asm.updated_at
                FROM agent_semantic_memory asm
                LEFT JOIN genealogy_persons p
                  ON p.id = asm.entity_id
                 AND asm.entity_type = 'genealogy_person'
                WHERE (
                    (asm.entity_type = 'genealogy_person' AND asm.fact_type = 'rejected_name')
                    OR (asm.entity_type = 'genealogy_tree' AND asm.fact_type = 'non_ft_name')
                  )
                  {$nameGuardrailTreeClause}
                ORDER BY asm.updated_at DESC, asm.id DESC
                LIMIT ?
            ", [...$nameGuardrailParams, min(10, $limit)]);

            $procedures = DB::selectOne("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN is_canonical = 1 THEN 1 ELSE 0 END) AS canonical,
                       SUM(CASE WHEN procedure_type = 'success' THEN 1 ELSE 0 END) AS success_patterns,
                       SUM(CASE WHEN procedure_type = 'failure' THEN 1 ELSE 0 END) AS failure_patterns
                FROM agent_procedures
                WHERE is_retired = 0
                  AND (
                    agent_id LIKE '%genea%'
                    OR name LIKE '%genealog%'
                    OR trigger_pattern LIKE '%genealog%'
                    OR strategy_insight LIKE '%genealog%'
                  )
            ");

            $episodes = DB::selectOne("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS success,
                       SUM(CASE WHEN outcome IN ('partial', 'failure', 'error') THEN 1 ELSE 0 END) AS non_success
                FROM agent_episode_summaries
                WHERE is_archived = 0
                  AND (
                    agent_id LIKE '%genea%'
                    OR task LIKE '%genealog%'
                    OR summary LIKE '%genealog%'
                  )
            ");

            $semanticSummary = $this->summarizeGenealogyMemoryCounts($semanticCounts);

            return [
                'tool' => 'memory_report',
                'success' => true,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'semantic_memory' => $semanticSummary,
                'review_signals' => [
                    'accepted_examples' => $semanticSummary['fact_types']['review_accepted'] ?? 0,
                    'rejected_guardrails' => $semanticSummary['fact_types']['review_rejected'] ?? 0,
                ],
                'rejected_names' => [
                    'person_rejected_names' => (int) ($nameGuardrailCounts->person_rejected_names ?? 0),
                    'tree_non_ft_names' => (int) ($nameGuardrailCounts->tree_non_ft_names ?? 0),
                ],
                'name_guardrails' => [
                    'total' => (int) ($nameGuardrailCounts->total ?? 0),
                    'person_rejected_names' => (int) ($nameGuardrailCounts->person_rejected_names ?? 0),
                    'tree_non_ft_names' => (int) ($nameGuardrailCounts->tree_non_ft_names ?? 0),
                    'sample_count' => count($nameGuardrailSamples),
                    'samples' => $nameGuardrailSamples,
                    'lookup_tool' => 'genealogy.non_ft_name_lookup',
                ],
                'learned_procedures' => [
                    'active_total' => (int) ($procedures->total ?? 0),
                    'canonical' => (int) ($procedures->canonical ?? 0),
                    'success_patterns' => (int) ($procedures->success_patterns ?? 0),
                    'failure_patterns' => (int) ($procedures->failure_patterns ?? 0),
                ],
                'episodes' => [
                    'active_total' => (int) ($episodes->total ?? 0),
                    'success' => (int) ($episodes->success ?? 0),
                    'non_success' => (int) ($episodes->non_success ?? 0),
                ],
                'recent_semantic_memory' => $recentSemantic,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'memory_report',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Run compact Genea learning-memory backfills without requiring agents to
     * coordinate several lower-level memory tools or inspect raw tables.
     */
    public function memory_backfill_batch(
        ?int $tree_id = null,
        array|string|null $lanes = 'all',
        int $limit = 25,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: memory_backfill_batch called', [
            'tree_id' => $tree_id,
            'lanes' => $lanes,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
            'actor' => $actor,
        ]);

        $limit = max(1, min(200, $limit));
        $laneList = $this->normalizeMemoryBackfillLanes($lanes);

        if ($laneList === false) {
            return [
                'tool' => 'memory_backfill_batch',
                'success' => false,
                'error' => 'Invalid lanes. Use all or one or more of: '.implode(', ', self::MEMORY_BACKFILL_LANES),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $dry_run && ! $confirm) {
            return [
                'tool' => 'memory_backfill_batch',
                'success' => false,
                'dry_run' => false,
                'error' => 'confirm=true is required when dry_run=false.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $trees = $this->memoryBackfillTrees($tree_id);
            if ($trees === []) {
                return [
                    'tool' => 'memory_backfill_batch',
                    'success' => false,
                    'error' => $tree_id !== null ? "Tree not found: {$tree_id}" : 'No genealogy trees found.',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $runs = [];
            $summary = [
                'tree_count' => count($trees),
                'candidate_count' => 0,
                'recorded_count' => 0,
                'skipped_count' => 0,
                'error_count' => 0,
            ];

            foreach ($trees as $tree) {
                $treeId = (int) $tree->id;
                $treeRun = [
                    'tree_id' => $treeId,
                    'tree_name' => $tree->name ?? null,
                    'lanes' => [],
                    'candidate_count' => 0,
                    'recorded_count' => 0,
                    'skipped_count' => 0,
                    'errors' => [],
                ];

                foreach ($laneList as $lane) {
                    $laneResult = $this->runMemoryBackfillLane($treeId, $lane, $limit, $dry_run, $confirm, $actor);
                    $laneSummary = $this->memoryBackfillLaneSummary($lane, $laneResult);
                    $treeRun['lanes'][$lane] = $laneSummary;

                    $treeRun['candidate_count'] += (int) ($laneSummary['candidate_count'] ?? 0);
                    $treeRun['recorded_count'] += (int) ($laneSummary['recorded_count'] ?? 0);
                    $treeRun['skipped_count'] += (int) ($laneSummary['skipped_count'] ?? 0);

                    if (! ($laneSummary['success'] ?? false)) {
                        $treeRun['errors'][] = [
                            'lane' => $lane,
                            'error' => $laneSummary['error'] ?? 'Unknown lane failure.',
                        ];
                    }
                }

                $summary['candidate_count'] += $treeRun['candidate_count'];
                $summary['recorded_count'] += $treeRun['recorded_count'];
                $summary['skipped_count'] += $treeRun['skipped_count'];
                $summary['error_count'] += count($treeRun['errors']);
                $runs[] = $treeRun;
            }

            return [
                'tool' => 'memory_backfill_batch',
                'success' => $summary['error_count'] === 0,
                'dry_run' => $dry_run,
                'tree_id' => $tree_id,
                'lanes' => $laneList,
                'summary' => $summary,
                'runs' => $runs,
                'write_policy' => 'dry_run_first_confirm_required_tree_scoped_learning_backfill',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'memory_backfill_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Backfill current health-audit findings and recommended fix policy into semantic memory.
     */
    public function health_audit_memory_batch(
        int $tree_id,
        array|string|null $sections = null,
        ?string $severity = 'medium',
        int $limit = 25,
        int $issue_limit = 20,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: health_audit_memory_batch called', [
            'tree_id' => $tree_id,
            'sections' => $sections,
            'severity' => $severity,
            'limit' => $limit,
            'issue_limit' => $issue_limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(1, min(200, $limit));
        $issueLimit = max(1, min(100, $issue_limit));
        $sectionList = $this->normalizeHealthAuditSections($sections);
        $severity = $severity !== null && trim($severity) !== '' ? strtolower(trim($severity)) : null;

        if ($severity !== null && $this->severityRank($severity) === null) {
            return [
                'tool' => 'health_audit_memory_batch',
                'success' => false,
                'error' => "Invalid severity filter: {$severity}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'health_audit_memory_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $auditService = app(GenealogyHealthAuditService::class);
            $payload = $auditService->collect(
                treeId: $tree_id,
                root: null,
                limit: $limit,
                sections: $sectionList,
                dryRun: false,
            );

            if ($severity !== null) {
                $minimumRank = $this->severityRank($severity);
                $payload['issues'] = array_values(array_filter(
                    $payload['issues'] ?? [],
                    fn (array $issue): bool => ($this->severityRank((string) ($issue['severity'] ?? 'info')) ?? 0) >= $minimumRank
                ));
            }

            $existingKeys = $this->existingHealthAuditMemoryKeys($tree_id);
            $issues = array_slice($payload['issues'] ?? [], 0, $issueLimit);
            $candidates = [];
            foreach ($issues as $issue) {
                $code = (string) ($issue['code'] ?? 'unknown_issue');
                $issueKey = $this->healthAuditMemoryKey($issue['issue_id'] ?? $code);
                $legacyCodeKey = $this->healthAuditMemoryKey($code);
                if (isset($existingKeys[$issueKey]) || isset($existingKeys[$legacyCodeKey])) {
                    continue;
                }

                $candidates[] = $this->buildHealthAuditMemoryCandidate($issue);
            }

            if ($dry_run) {
                return [
                    'tool' => 'health_audit_memory_batch',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'sections' => $sectionList,
                    'severity_filter' => $severity,
                    'candidate_count' => count($candidates),
                    'candidates' => $candidates,
                    'write_policy' => 'dry_run_first_confirm_required_issue_code_idempotent_memory',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'health_audit_memory_batch',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'candidate_count' => count($candidates),
                    'candidates' => $candidates,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $semantic = app(GenealogySemanticMemoryService::class);
            $memoryIds = [];
            foreach ($candidates as $candidate) {
                $memoryIds[] = $semantic->recordHealthAuditFinding(
                    $tree_id,
                    (string) ($candidate['issue_id'] ?? $candidate['code']),
                    $candidate,
                    $actor,
                    $this->healthMemoryConfidenceScore($candidate['confidence'] ?? null)
                );
            }

            $this->logGenealogyWriteAudit(
                'health_audit_memory_batch',
                'record_health_audit_memory',
                $actor,
                true,
                [
                    'tree_id' => $tree_id,
                    'sections' => $sectionList,
                    'severity_filter' => $severity,
                    'candidate_count' => count($candidates),
                    'memory_ids' => $memoryIds,
                ],
                [
                    'source_tool' => 'genealogy.health_audit',
                    'issue_codes' => array_column($candidates, 'code'),
                ],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'health_audit_memory_batch',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'recorded_count' => count($memoryIds),
                'memory_ids' => $memoryIds,
                'candidates' => $candidates,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'health_audit_memory_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Backfill saved media-intake run outcomes into Genea semantic memory.
     */
    public function media_intake_memory_batch(
        int $tree_id,
        int $limit = 50,
        bool $dry_run = true,
        bool $confirm = false,
        string $actor = 'genea-mcp'
    ): array {
        Log::info('GenealogyMCPService: media_intake_memory_batch called', [
            'tree_id' => $tree_id,
            'limit' => $limit,
            'dry_run' => $dry_run,
            'confirm' => $confirm,
        ]);

        $limit = max(1, min(200, $limit));

        try {
            $tree = $this->genealogy->getTree($tree_id);
            if (! $tree) {
                return [
                    'tool' => 'media_intake_memory_batch',
                    'success' => false,
                    'error' => "Tree not found: {$tree_id}",
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $rows = $this->loadMediaIntakeMemoryCandidates($tree_id, $limit);
            $candidates = array_map(fn (object $row): array => $this->buildMediaIntakeMemoryCandidate($row), $rows);

            if ($dry_run) {
                return [
                    'tool' => 'media_intake_memory_batch',
                    'success' => true,
                    'dry_run' => true,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'candidate_count' => count($candidates),
                    'candidates' => $candidates,
                    'write_policy' => 'dry_run_first_confirm_required_intake_run_source_idempotent_memory',
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            if (! $confirm) {
                return [
                    'tool' => 'media_intake_memory_batch',
                    'success' => false,
                    'dry_run' => false,
                    'tree_id' => $tree_id,
                    'tree_name' => $tree->name ?? null,
                    'error' => 'confirm=true is required when dry_run=false.',
                    'candidate_count' => count($candidates),
                    'candidates' => $candidates,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $semantic = app(GenealogySemanticMemoryService::class);
            $memoryIds = [];
            foreach ($rows as $index => $row) {
                $candidate = $candidates[$index] ?? $this->buildMediaIntakeMemoryCandidate($row);
                $memoryIds[] = $semantic->recordMediaIntakeOutcome(
                    $tree_id,
                    (int) $row->id,
                    (string) $row->run_key,
                    $candidate,
                    $actor,
                    0.75
                );
            }

            $this->logGenealogyWriteAudit(
                'media_intake_memory_batch',
                'record_media_intake_memory',
                $actor,
                true,
                [
                    'tree_id' => $tree_id,
                    'candidate_count' => count($candidates),
                    'memory_ids' => $memoryIds,
                ],
                [
                    'source_table' => 'genealogy_intake_runs',
                    'run_keys' => array_column($candidates, 'run_key'),
                ],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );

            return [
                'tool' => 'media_intake_memory_batch',
                'success' => true,
                'dry_run' => false,
                'tree_id' => $tree_id,
                'tree_name' => $tree->name ?? null,
                'recorded_count' => count($memoryIds),
                'memory_ids' => $memoryIds,
                'candidates' => $candidates,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'tool' => 'media_intake_memory_batch',
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @param  list<object>  $rows
     * @return array{total: int, fact_types: array<string, int>, consensus: array<string, int>}
     */
    private function summarizeGenealogyMemoryCounts(array $rows): array
    {
        $summary = [
            'total' => 0,
            'fact_types' => [],
            'consensus' => [],
        ];

        foreach ($rows as $row) {
            $count = (int) ($row->count ?? 0);
            $factType = (string) ($row->fact_type ?? 'unknown');
            $consensus = (string) ($row->consensus_status ?? 'unknown');

            $summary['total'] += $count;
            $summary['fact_types'][$factType] = ($summary['fact_types'][$factType] ?? 0) + $count;
            $summary['consensus'][$consensus] = ($summary['consensus'][$consensus] ?? 0) + $count;
        }

        ksort($summary['fact_types']);
        ksort($summary['consensus']);

        return $summary;
    }

    /**
     * @return list<string>|false
     */
    private function normalizeMemoryBackfillLanes(array|string|null $lanes): array|false
    {
        if ($lanes === null || $lanes === '' || $lanes === 'all') {
            return self::MEMORY_BACKFILL_LANES;
        }

        $items = is_array($lanes)
            ? $lanes
            : (preg_split('/[\s,|]+/', strtolower(trim((string) $lanes))) ?: []);

        $normalized = [];
        foreach ($items as $item) {
            $lane = strtolower(trim((string) $item));
            if ($lane === '') {
                continue;
            }

            $lane = match ($lane) {
                'all' => 'all',
                'canonical', 'lessons', 'lesson', 'default_lessons', 'canonical_lesson' => 'canonical_lessons',
                'health', 'audit', 'health_audit_memory' => 'health_audit',
                'intake', 'media', 'media_intake_memory' => 'media_intake',
                'source_media', 'source_media_capture', 'source_backfill', 'source_media_backfill', 'source_media_memory' => 'source_media_outcomes',
                'decisions', 'decision', 'review_decision', 'review_decision_memory' => 'review_decisions',
                'packets', 'packet', 'review_packet', 'review_packet_memory' => 'review_packets',
                default => $lane,
            };

            if ($lane === 'all') {
                return self::MEMORY_BACKFILL_LANES;
            }

            if (! in_array($lane, self::MEMORY_BACKFILL_LANES, true)) {
                return false;
            }

            $normalized[] = $lane;
        }

        return array_values(array_unique($normalized)) ?: self::MEMORY_BACKFILL_LANES;
    }

    /**
     * @return list<object>
     */
    private function memoryBackfillTrees(?int $treeId): array
    {
        if ($treeId !== null && $treeId > 0) {
            $tree = $this->genealogy->getTree($treeId);

            return $tree ? [$tree] : [];
        }

        return $this->genealogy->listTrees();
    }

    /**
     * @return array<string, mixed>
     */
    private function runMemoryBackfillLane(
        int $treeId,
        string $lane,
        int $limit,
        bool $dryRun,
        bool $confirm,
        string $actor
    ): array {
        $schemaBlocker = $this->memoryBackfillLaneSchemaBlocker($lane);
        if ($schemaBlocker !== null) {
            return [
                'tool' => 'memory_backfill_batch',
                'success' => true,
                'dry_run' => $dryRun,
                'candidate_count' => 0,
                'recorded_count' => 0,
                'skipped_count' => 1,
                'skip_reason' => $schemaBlocker,
            ];
        }

        return match ($lane) {
            'canonical_lessons' => $this->canonicalLessonMemoryBatch($treeId, $limit, $dryRun, $confirm, $actor),
            'health_audit' => $this->health_audit_memory_batch($treeId, ['media', 'rag', 'citations', 'duplicates', 'export'], 'medium', min(50, $limit), min(20, $limit), $dryRun, $confirm, $actor),
            'media_intake' => $this->media_intake_memory_batch($treeId, $limit, $dryRun, $confirm, $actor),
            'source_media_outcomes' => $this->sourceMediaOutcomeLessonBatch($treeId, $limit, $dryRun, $confirm, $actor),
            'review_decisions' => $this->review_decision_memory_batch($treeId, 'terminal', $limit, $dryRun, $confirm, $actor),
            'review_packets' => $this->review_packet_memory_batch($treeId, 'terminal', $limit, $dryRun, $confirm, $actor),
            default => [
                'tool' => 'memory_backfill_batch',
                'success' => false,
                'error' => "Unsupported memory backfill lane: {$lane}",
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function memoryBackfillLaneSummary(string $lane, array $result): array
    {
        $candidateCount = (int) ($result['candidate_count'] ?? count((array) ($result['candidates'] ?? [])));
        $recordedCount = (int) ($result['recorded_count'] ?? count((array) ($result['memory_ids'] ?? [])));
        $skippedCount = (int) ($result['skipped_count'] ?? 0);

        return [
            'lane' => $lane,
            'tool' => $result['tool'] ?? $lane,
            'success' => (bool) ($result['success'] ?? false),
            'dry_run' => (bool) ($result['dry_run'] ?? true),
            'candidate_count' => $candidateCount,
            'recorded_count' => $recordedCount,
            'skipped_count' => $skippedCount,
            'memory_ids' => array_slice((array) ($result['memory_ids'] ?? []), 0, 25),
            'skip_reason' => $result['skip_reason'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    private function memoryBackfillLaneSchemaBlocker(string $lane): ?string
    {
        $missing = [];

        $requireTable = static function (string $table) use (&$missing): void {
            if (! Schema::hasTable($table)) {
                $missing[] = "table:{$table}";
            }
        };

        $requireColumn = static function (string $table, string $column) use (&$missing): void {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $missing[] = "column:{$table}.{$column}";
            }
        };

        match ($lane) {
            'canonical_lessons' => [
                $requireTable('agent_semantic_memory'),
                $requireTable('agent_semantic_fact_sources'),
            ],
            'media_intake' => [
                $requireTable('genealogy_intake_runs'),
                $requireTable('agent_semantic_fact_sources'),
            ],
            'source_media_outcomes' => [
                $requireTable('agent_semantic_memory'),
                $requireTable('agent_semantic_fact_sources'),
            ],
            'review_decisions' => [
                $requireTable('genealogy_proposed_changes'),
                $requireTable('genealogy_proposed_relationships'),
                $requireTable('agent_semantic_fact_sources'),
                $requireColumn('genealogy_proposed_changes', 'tree_id'),
                $requireColumn('genealogy_proposed_changes', 'person_id'),
                $requireColumn('genealogy_proposed_changes', 'status'),
                $requireColumn('genealogy_proposed_relationships', 'tree_id'),
                $requireColumn('genealogy_proposed_relationships', 'person_id'),
                $requireColumn('genealogy_proposed_relationships', 'status'),
            ],
            'review_packets' => [
                $requireTable('agent_review_queue'),
                $requireTable('agent_semantic_memory'),
                $requireTable('agent_semantic_fact_sources'),
            ],
            default => null,
        };

        return $missing === []
            ? null
            : 'Skipped optional lane because schema is unavailable: '.implode(', ', array_values(array_unique($missing)));
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalLessonMemoryBatch(
        int $treeId,
        int $limit,
        bool $dryRun,
        bool $confirm,
        string $actor
    ): array {
        if (! Schema::hasTable('agent_semantic_memory') || ! Schema::hasTable('agent_semantic_fact_sources')) {
            return [
                'tool' => 'canonical_lesson_memory_batch',
                'success' => false,
                'error' => 'Semantic memory tables are not available.',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $candidates = [];
        $skipped = 0;
        foreach (array_slice(self::DEFAULT_LESSON_MEMORY_SEEDS, 0, max(1, $limit)) as $seed) {
            $lessonType = (string) $seed['lesson_type'];
            $title = (string) $seed['title'];
            if ($this->lessonMemoryExists($treeId, $lessonType, $title)) {
                $skipped++;

                continue;
            }

            $candidates[] = $seed;
        }

        if ($dryRun) {
            return [
                'tool' => 'canonical_lesson_memory_batch',
                'success' => true,
                'dry_run' => true,
                'tree_id' => $treeId,
                'candidate_count' => count($candidates),
                'skipped_count' => $skipped,
                'candidates' => $candidates,
                'write_policy' => 'dry_run_first_confirm_required_idempotent_canonical_lessons',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $confirm) {
            return [
                'tool' => 'canonical_lesson_memory_batch',
                'success' => false,
                'dry_run' => false,
                'tree_id' => $treeId,
                'error' => 'confirm=true is required when dry_run=false.',
                'candidate_count' => count($candidates),
                'skipped_count' => $skipped,
                'candidates' => $candidates,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $semantic = app(GenealogySemanticMemoryService::class);
        $memoryIds = [];
        foreach ($candidates as $seed) {
            $memoryIds[] = $semantic->recordLessonMemory(
                $treeId,
                (string) $seed['lesson_type'],
                (string) $seed['title'],
                (string) $seed['lesson'],
                [
                    'tags' => $seed['tags'] ?? [],
                    'source' => 'genealogy_memory_backfill_seed.v1',
                    'actor' => $actor,
                    'recorded_at' => now()->toIso8601String(),
                ],
                $actor,
                (float) ($seed['confidence'] ?? 0.85)
            );
        }

        if ($memoryIds !== []) {
            $this->logGenealogyWriteAudit(
                'canonical_lesson_memory_batch',
                'record_canonical_genealogy_lesson_memory',
                $actor,
                true,
                ['tree_id' => $treeId, 'memory_ids' => $memoryIds],
                ['titles' => array_column($candidates, 'title')],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );
        }

        return [
            'tool' => 'canonical_lesson_memory_batch',
            'success' => true,
            'dry_run' => false,
            'tree_id' => $treeId,
            'candidate_count' => count($candidates),
            'recorded_count' => count($memoryIds),
            'skipped_count' => $skipped,
            'memory_ids' => $memoryIds,
            'candidates' => $candidates,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceMediaOutcomeLessonBatch(
        int $treeId,
        int $limit,
        bool $dryRun,
        bool $confirm,
        string $actor
    ): array {
        $outcomes = $this->sourceMediaOutcomeSummary($treeId, max(25, min(500, $limit * 20)));
        $candidates = [];

        $successful = ($outcomes['status_counts']['captured'] ?? 0) + ($outcomes['status_counts']['media_reused'] ?? 0);
        if ($successful > 0 && ! $this->lessonMemoryExists($treeId, 'source_capture_lesson', 'Reuse successful source-media capture outcomes')) {
            $candidates[] = [
                'lesson_type' => 'source_capture_lesson',
                'title' => 'Reuse successful source-media capture outcomes',
                'lesson' => 'When source-media backfill successfully captures or reuses a source asset, keep the local FT path, media ID, source ID, and citation link together so future research can use the stored evidence without re-downloading or trusting URL-only references.',
                'tags' => ['source_media', 'captured', 'citation_link'],
                'confidence' => 0.85,
                'payload' => $outcomes,
            ];
        }

        $blocked = ($outcomes['status_counts']['failed'] ?? 0) + ($outcomes['status_counts']['blocked'] ?? 0) + ($outcomes['status_counts']['skipped'] ?? 0);
        if ($blocked > 0 && ! $this->lessonMemoryExists($treeId, 'source_capture_lesson', 'Keep source-media backfill blockers actionable')) {
            $candidates[] = [
                'lesson_type' => 'source_capture_lesson',
                'title' => 'Keep source-media backfill blockers actionable',
                'lesson' => 'When source-media capture is blocked, preserve the provider/status/blocker reason in memory and leave the source as an actionable retry/manual-review item rather than repeatedly reprocessing the same failing URL.',
                'tags' => ['source_media', 'blocked', 'retry'],
                'confidence' => 0.8,
                'payload' => $outcomes,
            ];
        }

        $candidates = array_slice($candidates, 0, max(1, $limit));

        if ($dryRun) {
            return [
                'tool' => 'source_media_outcome_lesson_batch',
                'success' => true,
                'dry_run' => true,
                'tree_id' => $treeId,
                'candidate_count' => count($candidates),
                'skipped_count' => $outcomes['total'] > 0 && $candidates === [] ? 1 : 0,
                'outcome_summary' => $outcomes,
                'candidates' => $candidates,
                'write_policy' => 'dry_run_first_confirm_required_source_media_outcome_lessons',
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if (! $confirm) {
            return [
                'tool' => 'source_media_outcome_lesson_batch',
                'success' => false,
                'dry_run' => false,
                'tree_id' => $treeId,
                'error' => 'confirm=true is required when dry_run=false.',
                'candidate_count' => count($candidates),
                'outcome_summary' => $outcomes,
                'candidates' => $candidates,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $semantic = app(GenealogySemanticMemoryService::class);
        $memoryIds = [];
        foreach ($candidates as $candidate) {
            $memoryIds[] = $semantic->recordLessonMemory(
                $treeId,
                (string) $candidate['lesson_type'],
                (string) $candidate['title'],
                (string) $candidate['lesson'],
                [
                    'tags' => $candidate['tags'],
                    'source' => 'source_media_backfill_outcome_summary.v1',
                    'outcomes' => $candidate['payload'],
                    'actor' => $actor,
                    'recorded_at' => now()->toIso8601String(),
                ],
                $actor,
                (float) $candidate['confidence']
            );
        }

        if ($memoryIds !== []) {
            $this->logGenealogyWriteAudit(
                'source_media_outcome_lesson_batch',
                'record_source_media_outcome_lesson_memory',
                $actor,
                true,
                ['tree_id' => $treeId, 'memory_ids' => $memoryIds],
                ['outcome_summary' => $outcomes],
                'Delete created agent_semantic_memory rows and linked agent_semantic_fact_sources rows by memory_id if rollback is needed.',
                ['dry_run' => false]
            );
        }

        return [
            'tool' => 'source_media_outcome_lesson_batch',
            'success' => true,
            'dry_run' => false,
            'tree_id' => $treeId,
            'candidate_count' => count($candidates),
            'recorded_count' => count($memoryIds),
            'memory_ids' => $memoryIds,
            'outcome_summary' => $outcomes,
            'candidates' => $candidates,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{total: int, status_counts: array<string, int>, memory_ids: list<int>}
     */
    private function sourceMediaOutcomeSummary(int $treeId, int $limit): array
    {
        $rows = DB::select(
            "SELECT id, fact_value
             FROM agent_semantic_memory
             WHERE fact_type = 'source_media_backfill_outcome'
               AND JSON_VALID(fact_value) = 1
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(fact_value) = 1 THEN fact_value ELSE '{}' END, '$.tree_id')) AS UNSIGNED) = ?
             ORDER BY updated_at DESC, id DESC
             LIMIT ?",
            [$treeId, $limit]
        );

        $summary = [
            'total' => 0,
            'status_counts' => [],
            'memory_ids' => [],
        ];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->fact_value ?? ''), true);
            if (! is_array($payload)) {
                continue;
            }

            $status = strtolower(trim((string) ($payload['status'] ?? 'unknown')));
            $status = $status !== '' ? $status : 'unknown';
            $summary['total']++;
            $summary['status_counts'][$status] = ($summary['status_counts'][$status] ?? 0) + 1;
            $summary['memory_ids'][] = (int) $row->id;
        }

        ksort($summary['status_counts']);
        $summary['memory_ids'] = array_slice($summary['memory_ids'], 0, 25);

        return $summary;
    }

    private function lessonMemoryExists(int $treeId, string $lessonType, string $title): bool
    {
        $row = DB::selectOne(
            "SELECT id
             FROM agent_semantic_memory
             WHERE entity_type = 'genealogy_tree'
               AND entity_id = ?
               AND fact_type = ?
               AND fact_key = ?
             LIMIT 1",
            [$treeId, $lessonType, $this->memoryBackfillFactKey($lessonType.':'.$title)]
        );

        return $row !== null;
    }

    private function memoryBackfillFactKey(string $value): string
    {
        $normalized = Str::of($value)
            ->lower()
            ->squish()
            ->toString();

        if (mb_strlen($normalized) > 100) {
            $normalized = mb_substr($normalized, 0, 83).':'.substr(sha1($normalized), 0, 16);
        }

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @return array<string, true>
     */
    private function existingHealthAuditMemoryKeys(int $treeId): array
    {
        $rows = DB::select(
            "SELECT fact_key
             FROM agent_semantic_memory
             WHERE entity_type = 'genealogy_tree'
               AND entity_id = ?
               AND fact_type = 'health_audit_issue'",
            [$treeId]
        );

        $keys = [];
        foreach ($rows as $row) {
            $key = $this->healthAuditMemoryKey($row->fact_key ?? null);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function buildHealthAuditMemoryCandidate(array $issue): array
    {
        $code = (string) ($issue['code'] ?? 'unknown_issue');
        $autoFixPolicy = is_array($issue['auto_fix_policy'] ?? null) ? $issue['auto_fix_policy'] : null;

        return [
            'issue_id' => $issue['issue_id'] ?? null,
            'code' => $code,
            'title' => $issue['title'] ?? null,
            'section' => $issue['section'] ?? null,
            'severity' => $issue['severity'] ?? null,
            'confidence' => $issue['confidence'] ?? null,
            'entity_type' => $issue['entity_type'] ?? null,
            'entity_id' => $issue['entity_id'] ?? null,
            'review_target' => $issue['review_target'] ?? null,
            'provenance' => $issue['provenance'] ?? null,
            'count' => (int) ($issue['count'] ?? 0),
            'sample_count' => count($issue['samples'] ?? []),
            'suggested_fix' => $issue['suggested_fix'] ?? null,
            'safe_auto_fix' => (bool) ($issue['safe_auto_fix'] ?? false),
            'auto_fix_policy' => $autoFixPolicy,
            'allowed_tool' => $autoFixPolicy['allowed_tool'] ?? null,
        ];
    }

    private function healthAuditMemoryKey(mixed $code): string
    {
        $key = strtolower(trim((string) $code));
        $key = preg_replace('/[^a-z0-9_:-]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }

    private function healthMemoryConfidenceScore(mixed $confidence): float
    {
        return match (strtolower(trim((string) $confidence))) {
            'strong' => 0.9,
            'medium' => 0.75,
            'weak' => 0.5,
            'missing' => 0.3,
            default => 0.65,
        };
    }

    /**
     * @return list<object>
     */
    private function loadMediaIntakeMemoryCandidates(int $treeId, int $limit): array
    {
        return DB::select(
            "SELECT gir.id,
                    gir.run_key,
                    gir.tree_id,
                    gir.root_path,
                    gir.packet_label,
                    gir.status,
                    gir.staged_snapshot,
                    gir.updated_at
             FROM genealogy_intake_runs gir
             WHERE gir.tree_id = ?
               AND NOT EXISTS (
                    SELECT 1
                    FROM agent_semantic_fact_sources afs
                    WHERE afs.source_type = 'media_intake_run'
                      AND afs.source_id = gir.id
               )
             ORDER BY gir.updated_at DESC, gir.id DESC
             LIMIT ?",
            [$treeId, $limit]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMediaIntakeMemoryCandidate(object $row): array
    {
        $snapshot = json_decode((string) ($row->staged_snapshot ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return [
            'run_id' => (int) $row->id,
            'run_key' => (string) $row->run_key,
            'status' => (string) $row->status,
            'root_path' => (string) $row->root_path,
            'root_hash' => substr(sha1((string) $row->root_path), 0, 16),
            'packet_label' => $row->packet_label ?? null,
            'packet_count' => count((array) ($snapshot['packets'] ?? [])),
            'copy_progress' => $this->mediaIntakeCopyProgress($snapshot),
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int>
     */
    private function mediaIntakeCopyProgress(array $snapshot): array
    {
        $progress = [
            'packets_with_execution' => 0,
            'copied' => 0,
            'already_in_place' => 0,
            'blocked_conflicts' => 0,
            'failed' => 0,
        ];

        foreach (array_values((array) ($snapshot['packets'] ?? [])) as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            $summary = (array) (($packet['reference_copy_execution']['execution']['summary'] ?? []));
            if ($summary === []) {
                continue;
            }

            $progress['packets_with_execution']++;
            $progress['copied'] += (int) ($summary['copied'] ?? 0);
            $progress['already_in_place'] += (int) ($summary['already_in_place'] ?? 0);
            $progress['blocked_conflicts'] += (int) ($summary['blocked_conflicts'] ?? 0);
            $progress['failed'] += (int) ($summary['failed'] ?? 0);
        }

        return $progress;
    }

    /**
     * @return array<int, string>|false|null
     */
    private function normalizeReviewDecisionMemoryStatuses(?string $status): array|false
    {
        $status = strtolower(trim((string) $status));
        if ($status === '' || $status === 'terminal') {
            return ['applied', 'rejected'];
        }

        if ($status === 'all') {
            return ['approved', 'applied', 'rejected'];
        }

        $requested = preg_split('/[\s,|]+/', $status) ?: [];
        $valid = ['approved', 'applied', 'rejected'];
        $statuses = [];
        foreach ($requested as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            if (! in_array($item, $valid, true)) {
                return false;
            }

            $statuses[] = $item;
        }

        return array_values(array_unique($statuses)) ?: ['applied', 'rejected'];
    }

    /**
     * @param  list<string>  $statuses
     * @return list<object>
     */
    private function loadReviewDecisionMemoryCandidates(int $treeId, array $statuses, int $limit): array
    {
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

        $changeRows = DB::select("
            SELECT 'change' AS proposal_type,
                   pc.id AS proposal_id,
                   pc.tree_id,
                   pc.person_id,
                   NULL AS related_person_id,
                   pc.status,
                   pc.agent_id,
                   pc.field_name,
                   NULL AS relationship_type,
                   pc.proposed_value,
                   pc.evidence_summary,
                   pc.evidence_sources,
                   pc.confidence,
                   pc.updated_at
            FROM genealogy_proposed_changes pc
            WHERE pc.tree_id = ?
              AND pc.status IN ({$placeholders})
              AND NOT EXISTS (
                SELECT 1
                FROM agent_semantic_fact_sources afs
                WHERE afs.source_id = pc.id
                  AND afs.source_type = CASE
                    WHEN pc.status = 'rejected' THEN 'review_decision:change:rejected'
                    ELSE 'review_decision:change:accepted'
                  END
              )
            ORDER BY pc.updated_at DESC, pc.id DESC
            LIMIT ?
        ", [...[$treeId], ...$statuses, $limit]);

        $remaining = max(0, $limit - count($changeRows));
        $relationshipRows = [];
        if ($remaining > 0) {
            $relationshipRows = DB::select("
                SELECT 'relationship' AS proposal_type,
                       pr.id AS proposal_id,
                       pr.tree_id,
                       pr.person_id,
                       pr.related_person_id,
                       pr.status,
                       pr.agent_id,
                       NULL AS field_name,
                       pr.relationship_type,
                       pr.proposed_name AS proposed_value,
                       pr.evidence_summary,
                       pr.evidence_sources,
                       pr.confidence,
                       pr.updated_at
                FROM genealogy_proposed_relationships pr
                WHERE pr.tree_id = ?
                  AND pr.status IN ({$placeholders})
                  AND NOT EXISTS (
                    SELECT 1
                    FROM agent_semantic_fact_sources afs
                    WHERE afs.source_id = pr.id
                      AND afs.source_type = CASE
                        WHEN pr.status = 'rejected' THEN 'review_decision:relationship:rejected'
                        ELSE 'review_decision:relationship:accepted'
                      END
                  )
                ORDER BY pr.updated_at DESC, pr.id DESC
                LIMIT ?
            ", [...[$treeId], ...$statuses, $remaining]);
        }

        $rows = [...$changeRows, ...$relationshipRows];
        usort($rows, static fn (object $left, object $right): int => strcmp((string) ($right->updated_at ?? ''), (string) ($left->updated_at ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReviewDecisionMemoryPreview(object $row): array
    {
        return [
            'proposal_type' => (string) $row->proposal_type,
            'proposal_id' => (int) $row->proposal_id,
            'status' => (string) $row->status,
            'decision_memory' => ((string) $row->status) === 'rejected' ? 'rejected_guardrail' : 'accepted_example',
            'person_id' => isset($row->person_id) ? (int) $row->person_id : null,
            'related_person_id' => isset($row->related_person_id) ? (int) $row->related_person_id : null,
            'field_name' => $row->field_name ?? null,
            'relationship_type' => $row->relationship_type ?? null,
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReviewDecisionMemoryPayload(object $row): array
    {
        return [
            'status' => (string) $row->status,
            'person_id' => isset($row->person_id) ? (int) $row->person_id : null,
            'related_person_id' => isset($row->related_person_id) ? (int) $row->related_person_id : null,
            'field_name' => $row->field_name ?? null,
            'relationship_type' => $row->relationship_type ?? null,
            'proposed_value' => $this->limitMemoryString($row->proposed_value ?? null),
            'evidence_summary' => $this->limitMemoryString($row->evidence_summary ?? null),
            'evidence_sources' => $this->limitMemoryString($row->evidence_sources ?? null),
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @return array<int, string>|false
     */
    private function normalizeReviewPacketMemoryStatuses(?string $status): array|false
    {
        $status = strtolower(trim((string) $status));
        if ($status === '' || $status === 'terminal') {
            return ['reviewed', 'rejected'];
        }

        if ($status === 'all') {
            return ['reviewed', 'rejected'];
        }

        $requested = preg_split('/[\s,|]+/', $status) ?: [];
        $statuses = [];
        foreach ($requested as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $item = match ($item) {
                'accepted', 'approved', 'mark_reviewed' => 'reviewed',
                default => $item,
            };

            if (! in_array($item, ['reviewed', 'rejected'], true)) {
                return false;
            }

            $statuses[] = $item;
        }

        return array_values(array_unique($statuses)) ?: ['reviewed', 'rejected'];
    }

    /**
     * @param  list<string>  $statuses
     * @return list<object>
     */
    private function loadReviewPacketMemoryCandidates(int $treeId, array $statuses, int $limit): array
    {
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $scanLimit = max($limit, min(1000, $limit * 10));

        $rows = DB::select("
            SELECT q.id AS review_queue_id,
                   q.agent_id,
                   q.title,
                   q.summary,
                   q.details,
                   q.confidence,
                   q.priority,
                   q.status,
                   q.token,
                   q.reviewed_at,
                   q.updated_at,
                   q.created_at
            FROM agent_review_queue q
            WHERE q.review_type = ?
              AND q.status IN ({$placeholders})
              AND NOT EXISTS (
                SELECT 1
                FROM agent_semantic_fact_sources afs
                WHERE afs.source_id = q.id
                  AND afs.source_type = CASE
                    WHEN q.status = 'rejected' THEN 'review_decision:review_packet:rejected'
                    ELSE 'review_decision:review_packet:accepted'
                  END
              )
            ORDER BY q.updated_at DESC, q.id DESC
            LIMIT ?
        ", [...[GenealogyReviewPacketAdapterService::REVIEW_TYPE], ...$statuses, $scanLimit]);

        $candidates = [];
        foreach ($rows as $row) {
            $details = $this->reviewPacketDetailsFromRow($row);
            if (! $this->reviewPacketBelongsToTree($details, $treeId)) {
                continue;
            }

            $row->decoded_details = $details;
            $candidates[] = $row;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReviewPacketMemoryPreview(object $row): array
    {
        $details = is_array($row->decoded_details ?? null)
            ? $row->decoded_details
            : $this->reviewPacketDetailsFromRow($row);

        return [
            'review_queue_id' => (int) $row->review_queue_id,
            'target_ref' => app(ReviewTargetReferenceService::class)->forReviewRow(
                [
                    'id' => $row->review_queue_id,
                    'review_type' => GenealogyReviewPacketAdapterService::REVIEW_TYPE,
                    'token' => $row->token ?? null,
                    'created_at' => $row->created_at ?? null,
                ],
                GenealogyReviewPacketAdapterService::REVIEW_TYPE
            ),
            'status' => (string) $row->status,
            'packet_status' => $details['packet_status'] ?? null,
            'decision_memory' => ((string) $row->status) === 'rejected' ? 'rejected_guardrail' : 'accepted_example',
            'title' => $this->limitMemoryString($row->title ?? null, 240),
            'person_id' => $this->firstReviewPacketPositiveInt($details, ['person_id', 'target_person_id']),
            'source_locator' => $this->limitMemoryString($details['source_locator'] ?? null, 500),
            'claim_count' => is_array($details['claims'] ?? null) ? count($details['claims']) : 0,
            'source_count' => is_array($details['sources'] ?? null) ? count($details['sources']) : 0,
            'latest_decision' => $this->latestReviewPacketDecision($details['decision_log'] ?? []),
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReviewPacketMemoryPayload(object $row): array
    {
        $details = is_array($row->decoded_details ?? null)
            ? $row->decoded_details
            : $this->reviewPacketDetailsFromRow($row);

        return [
            'schema' => 'genealogy_review_packet_decision_memory.v1',
            'review_queue_id' => (int) $row->review_queue_id,
            'target_ref' => app(ReviewTargetReferenceService::class)->forReviewRow(
                [
                    'id' => $row->review_queue_id,
                    'review_type' => GenealogyReviewPacketAdapterService::REVIEW_TYPE,
                    'token' => $row->token ?? null,
                    'created_at' => $row->created_at ?? null,
                ],
                GenealogyReviewPacketAdapterService::REVIEW_TYPE
            ),
            'status' => (string) $row->status,
            'packet_status' => $details['packet_status'] ?? null,
            'title' => $this->limitMemoryString($row->title ?? null, 300),
            'summary' => $this->limitMemoryString($row->summary ?? null, 900),
            'source_locator' => $this->limitMemoryString($details['source_locator'] ?? null, 500),
            'source_locators' => array_slice(is_array($details['source_locators'] ?? null) ? $details['source_locators'] : [], 0, 10),
            'identity' => $this->compactReviewPacketMap($details['identity'] ?? null, 1200),
            'claims' => $this->compactReviewPacketClaims($details['claims'] ?? [], 10),
            'sources' => $this->compactReviewPacketSources($details['sources'] ?? [], 10),
            'latest_decision' => $this->latestReviewPacketDecision($details['decision_log'] ?? []),
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            'reviewed_at' => $row->reviewed_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPacketDetailsFromRow(object $row): array
    {
        $details = json_decode((string) ($row->details ?? ''), true);

        return is_array($details) ? $details : [];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function reviewPacketBelongsToTree(array $details, int $treeId): bool
    {
        $directTreeId = $this->firstReviewPacketPositiveInt($details, ['tree_id']);
        if ($directTreeId !== null) {
            return $directTreeId === $treeId;
        }

        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $packetTreeId = $this->firstReviewPacketPositiveInt($packet, ['tree_id']);
        if ($packetTreeId !== null) {
            return $packetTreeId === $treeId;
        }

        $identity = is_array($details['identity'] ?? null) ? $details['identity'] : [];
        $personIds = [];
        foreach ([$details, $identity] as $source) {
            foreach (['person_id', 'target_person_id'] as $key) {
                $personId = $this->reviewPacketPositiveInt($source[$key] ?? null);
                if ($personId !== null) {
                    $personIds[] = $personId;
                }
            }
        }

        foreach ((array) ($details['claims'] ?? []) as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            foreach (['person_id', 'target_person_id'] as $key) {
                $personId = $this->reviewPacketPositiveInt($claim[$key] ?? null);
                if ($personId !== null) {
                    $personIds[] = $personId;
                }
            }
        }

        $personIds = array_values(array_unique($personIds));
        if ($personIds === [] || ! Schema::hasTable('genealogy_persons')) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $match = DB::selectOne(
            "SELECT COUNT(*) AS count
             FROM genealogy_persons
             WHERE tree_id = ?
               AND id IN ({$placeholders})",
            [...[$treeId], ...$personIds]
        );

        return (int) ($match->count ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstReviewPacketPositiveInt(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->reviewPacketPositiveInt($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function reviewPacketPositiveInt(mixed $value): ?int
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @return array<int, string>|false|null
     */
    private function normalizeResearchTaskStatusFilter(?string $status): array|false|null
    {
        $status = strtolower(trim((string) $status));

        if ($status === '' || $status === 'all') {
            return null;
        }

        if ($status === 'active') {
            return ['processing', 'queued', 'failed'];
        }

        $valid = ['queued', 'processing', 'completed', 'failed', 'cancelled'];

        return in_array($status, $valid, true) ? [$status] : false;
    }

    private function normalizeResearchTaskPriorityFilter(?string $priority): string|false|null
    {
        $priority = strtolower(trim((string) $priority));

        if ($priority === '' || $priority === 'all') {
            return null;
        }

        $valid = ['urgent', 'high', 'medium', 'low'];

        return in_array($priority, $valid, true) ? $priority : false;
    }

    private function normalizeResearchTaskTypeFilter(?string $taskType): string|false|null
    {
        $taskType = strtolower(trim((string) $taskType));

        if ($taskType === '' || $taskType === 'all') {
            return null;
        }

        $valid = ['find_records', 'verify_facts', 'find_relatives', 'analyze_dna', 'suggest_sources', 'transcribe_document'];

        return in_array($taskType, $valid, true) ? $taskType : false;
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, int>
     */
    private function formatCountRows(array $rows, string $keyField): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row->{$keyField} ?? 'unknown'));
            if ($key === '') {
                $key = 'unknown';
            }

            $counts[$key] = (int) ($row->count ?? 0);
        }

        return $counts;
    }

    private function buildResearchTaskQueueRow(object $task): array
    {
        return [
            'id' => (int) $task->id,
            'tree_id' => (int) $task->tree_id,
            'person' => [
                'id' => isset($task->person_id) ? (int) $task->person_id : null,
                'name' => trim((string) ($task->person_name ?? '')) ?: null,
                'birth_date' => $task->birth_date ?? null,
                'death_date' => $task->death_date ?? null,
            ],
            'queue_item_id' => isset($task->queue_item_id) ? (int) $task->queue_item_id : null,
            'task_type' => $task->task_type ?? null,
            'priority' => $task->priority ?? null,
            'status' => $task->status ?? null,
            'outcome_state' => $task->outcome_state ?? null,
            'research_question' => $task->research_question ?? null,
            'selection_reason' => $task->selection_reason ?? null,
            'scope_reason' => $task->scope_reason ?? null,
            'evidence_summary' => $task->evidence_summary ?? null,
            'conflicts_found' => $task->conflicts_found ?? null,
            'outcome_reason' => $task->outcome_reason ?? null,
            'sources_checked_count' => (int) ($task->sources_checked_count ?? 0),
            'queue' => [
                'status' => $task->queue_status ?? null,
                'priority_score' => isset($task->queue_priority_score) ? (float) $task->queue_priority_score : null,
                'findings_count' => isset($task->queue_findings_count) ? (int) $task->queue_findings_count : null,
                'review_items_count' => isset($task->queue_review_items_count) ? (int) $task->queue_review_items_count : null,
            ],
            'started_at' => $task->started_at ?? null,
            'completed_at' => $task->completed_at ?? null,
            'updated_at' => $task->updated_at ?? null,
        ];
    }

    private function buildResearchTaskProfileRow(object $task): array
    {
        return [
            'id' => (int) $task->id,
            'tree_id' => (int) $task->tree_id,
            'person' => [
                'id' => isset($task->person_id) ? (int) $task->person_id : null,
                'gedcom_id' => $task->person_gedcom_id ?? null,
                'name' => trim((string) ($task->person_name ?? '')) ?: null,
                'birth_date' => $task->birth_date ?? null,
                'birth_place' => $task->birth_place ?? null,
                'death_date' => $task->death_date ?? null,
                'death_place' => $task->death_place ?? null,
                'living' => isset($task->living) ? (bool) $task->living : null,
            ],
            'queue_item_id' => isset($task->queue_item_id) ? (int) $task->queue_item_id : null,
            'task_type' => $task->task_type ?? null,
            'priority' => $task->priority ?? null,
            'status' => $task->status ?? null,
            'research_question' => $task->research_question ?? null,
            'selection_reason' => $task->selection_reason ?? null,
            'scope_reason' => $task->scope_reason ?? null,
            'related_people_used' => $this->decodeJsonField($task->related_people_used ?? null),
            'sources_checked' => $this->decodeJsonField($task->sources_checked ?? null),
            'evidence_summary' => $this->textProfile((string) ($task->evidence_summary ?? ''), 1600),
            'conflicts_found' => $this->textProfile((string) ($task->conflicts_found ?? ''), 1200),
            'outcome' => [
                'state' => $task->outcome_state ?? null,
                'reason' => $this->textProfile((string) ($task->outcome_reason ?? ''), 1600),
            ],
            'parameters' => $this->decodeJsonField($task->parameters ?? null),
            'results' => $this->decodeJsonField($task->results ?? null),
            'error_message' => $this->textProfile((string) ($task->error_message ?? ''), 1200),
            'queue' => [
                'status' => $task->queue_status ?? null,
                'priority_score' => isset($task->queue_priority_score) ? (float) $task->queue_priority_score : null,
                'priority_reason' => $task->queue_priority_reason ?? null,
                'question_type' => $task->queue_question_type ?? null,
                'research_question' => $task->queue_research_question ?? null,
                'selection_reason' => $task->queue_selection_reason ?? null,
                'findings_count' => isset($task->queue_findings_count) ? (int) $task->queue_findings_count : null,
                'review_items_count' => isset($task->queue_review_items_count) ? (int) $task->queue_review_items_count : null,
                'last_outcome_state' => $task->queue_last_outcome_state ?? null,
                'last_outcome_reason' => $task->queue_last_outcome_reason ?? null,
                'notes' => $task->queue_notes ?? null,
                'updated_at' => $task->queue_updated_at ?? null,
            ],
            'created_by' => isset($task->created_by) ? (int) $task->created_by : null,
            'started_at' => $task->started_at ?? null,
            'completed_at' => $task->completed_at ?? null,
            'created_at' => $task->created_at ?? null,
            'updated_at' => $task->updated_at ?? null,
        ];
    }

    private function researchTaskNextAction(object $task): string
    {
        $status = (string) ($task->status ?? '');
        $outcome = (string) ($task->outcome_state ?? '');

        if ($status === 'queued') {
            return 'await_agent_processing';
        }

        if ($status === 'processing') {
            return 'monitor_processing_task';
        }

        if ($status === 'failed' || $outcome === 'requeue') {
            return 'review_failure_or_requeue';
        }

        if ($outcome === 'needs_human_review') {
            return 'review_evidence_and_pending_proposals';
        }

        if ($outcome === 'deferred') {
            return 'preserve_as_research_lead';
        }

        return 'review_task_outcome';
    }

    /**
     * @return list<string>
     */
    private function normalizeHealthAuditSections(array|string|null $sections): array
    {
        if ($sections === null || $sections === '') {
            return [];
        }

        if (is_string($sections)) {
            $sections = explode(',', $sections);
        }

        return array_values(array_filter(array_map(
            static fn ($section): string => trim(strtolower((string) $section)),
            $sections
        )));
    }

    private function severityRank(string $severity): ?int
    {
        return [
            'info' => 0,
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
        ][strtolower(trim($severity))] ?? null;
    }

    private function refreshAuditSummary(array $summary, array $issues): array
    {
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'info');
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;
        }

        $summary['issue_count'] = count($issues);
        $summary['issue_rows'] = array_sum(array_map(
            static fn (array $issue): int => (int) ($issue['count'] ?? 0),
            $issues
        ));
        $summary['severity_counts'] = $severityCounts;

        return $summary;
    }

    private function treeStatusLabel(int $healthRows, int $ragMissing, int $exportCount, int $duplicateCount): string
    {
        if ($healthRows === 0 && $ragMissing === 0 && $exportCount === 0 && $duplicateCount === 0) {
            return 'ready';
        }

        if ($healthRows > 0 || $exportCount > 0 || $duplicateCount > 0) {
            return 'needs_review';
        }

        return 'indexing';
    }

    private function buildStandaloneExportStatus(int $treeId, ?string $treeName, array $readiness): array
    {
        if (! ($readiness['success'] ?? false)) {
            return [
                'tree_id' => $treeId,
                'tree_name' => $treeName,
                'standalone_ready' => false,
                'error' => $readiness['error'] ?? 'export_readiness_failed',
                'recommended_action' => 'fix_export_readiness_error',
                'blocking_counts' => [],
                'verification' => [
                    'gedcom_included' => false,
                    'media_bundle_available' => false,
                    'all_media_paths_ft_local' => false,
                    'all_media_files_verified' => false,
                    'tree_folder_can_stand_alone' => false,
                ],
            ];
        }

        $blockingCounts = [
            'media_paths_not_self_contained' => (int) ($readiness['blocking_counts']['media_paths_not_self_contained'] ?? 0),
            'missing_media_files' => (int) ($readiness['blocking_counts']['missing_media_files'] ?? 0),
        ];
        $portableExport = $readiness['path_policy']['portable_export'] ?? [];
        $standaloneReady = (bool) ($readiness['ready'] ?? false);

        return [
            'tree_id' => $treeId,
            'tree_name' => $treeName ?? ($readiness['tree_name'] ?? null),
            'standalone_ready' => $standaloneReady,
            'media_root' => $readiness['media_root'] ?? null,
            'portable_export' => [
                'format' => $portableExport['format'] ?? 'GEDZip',
                'gedcom_included' => (bool) ($portableExport['gedcom_included'] ?? false),
                'supporting_media_included_when_file_verified' => (bool) ($portableExport['supporting_media_included_when_file_verified'] ?? false),
                'ready_when_blocking_counts_are_zero' => (bool) ($portableExport['ready_when_blocking_counts_are_zero'] ?? false),
            ],
            'blocking_counts' => $blockingCounts,
            'verification' => [
                'gedcom_included' => (bool) ($portableExport['gedcom_included'] ?? false),
                'media_bundle_available' => (bool) ($portableExport['supporting_media_included_when_file_verified'] ?? false),
                'all_media_paths_ft_local' => $blockingCounts['media_paths_not_self_contained'] === 0,
                'all_media_files_verified' => $blockingCounts['missing_media_files'] === 0,
                'tree_folder_can_stand_alone' => $standaloneReady,
            ],
            'sample_counts' => [
                'media_paths_not_self_contained' => (int) ($readiness['issues']['media_paths_not_self_contained']['sample_count'] ?? 0),
                'missing_media_files' => (int) ($readiness['issues']['missing_media_files']['sample_count'] ?? 0),
            ],
            'recommended_action' => $this->standaloneExportRecommendedAction($blockingCounts),
        ];
    }

    /**
     * @param  array{media_paths_not_self_contained:int, missing_media_files:int}  $blockingCounts
     */
    private function standaloneExportRecommendedAction(array $blockingCounts): string
    {
        if (($blockingCounts['media_paths_not_self_contained'] ?? 0) > 0) {
            return 'capture_or_repoint_external_legacy_or_out_of_tree_media';
        }

        if (($blockingCounts['missing_media_files'] ?? 0) > 0) {
            return 'verify_or_restore_missing_ft_local_media_files';
        }

        return 'export_gedzip_and_copy_tree_folder';
    }

    private function coverageRebuildStatus(int $treeId, int $rootPersonId): array
    {
        $row = DB::selectOne('
            SELECT
                (SELECT COUNT(*) FROM genealogy_persons WHERE tree_id = ?) AS persons,
                (SELECT COUNT(*) FROM genealogy_person_coverage WHERE tree_id = ?) AS coverage_rows,
                (SELECT COUNT(*)
                 FROM genealogy_person_coverage c
                 LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = c.tree_id
                 WHERE c.tree_id = ? AND p.id IS NULL) AS stale_coverage_rows,
                (SELECT COUNT(*) FROM genealogy_ancestor_paths WHERE tree_id = ?) AS ancestor_paths,
                (SELECT COUNT(*)
                 FROM genealogy_ancestor_paths
                 WHERE tree_id = ? AND JSON_LENGTH(path_ids) = 1 AND ancestor_id <> root_person_id) AS singleton_non_root_paths,
                (SELECT COUNT(*)
                 FROM genealogy_ancestor_paths
                 WHERE tree_id = ? AND root_person_id = ? AND ancestor_id = ?) AS root_path_rows,
                (SELECT MAX(rebuilt_at) FROM genealogy_ancestor_paths WHERE tree_id = ?) AS max_rebuilt_at,
                (SELECT MAX(coverage_updated_at) FROM genealogy_person_coverage WHERE tree_id = ?) AS max_coverage_updated_at
        ', [
            $treeId,
            $treeId,
            $treeId,
            $treeId,
            $treeId,
            $treeId,
            $rootPersonId,
            $rootPersonId,
            $treeId,
            $treeId,
        ]);

        return [
            'persons' => (int) ($row->persons ?? 0),
            'coverage_rows' => (int) ($row->coverage_rows ?? 0),
            'stale_coverage_rows' => (int) ($row->stale_coverage_rows ?? 0),
            'ancestor_paths' => (int) ($row->ancestor_paths ?? 0),
            'singleton_non_root_paths' => (int) ($row->singleton_non_root_paths ?? 0),
            'root_path_rows' => (int) ($row->root_path_rows ?? 0),
            'max_rebuilt_at' => $row->max_rebuilt_at ?? null,
            'max_coverage_updated_at' => $row->max_coverage_updated_at ?? null,
        ];
    }

    private function buildWorkStatusRow(int $treeId, ?string $treeName, ?object $row): array
    {
        $mediaNeedsRag = (int) ($row->media_needs_rag ?? 0);
        $mediaUnlinked = (int) ($row->media_unlinked ?? 0);
        $mediaTargetedCitationUnlinked = (int) ($row->media_targeted_citation_unlinked ?? 0);
        $mediaUncitedUnlinked = (int) ($row->media_uncited_unlinked ?? $mediaUnlinked);
        $mediaReviewedUnresolved = max(0, (int) ($row->media_reviewed_unresolved_unlinked ?? 0));
        $mediaActionableUnlinked = $mediaUncitedUnlinked + $mediaTargetedCitationUnlinked;
        $mediaReviewedUnresolved = min($mediaActionableUnlinked, $mediaReviewedUnresolved);
        $mediaFreshActionableUnlinked = max(0, $mediaActionableUnlinked - $mediaReviewedUnresolved);
        $pendingReview = (int) ($row->change_proposals_pending ?? 0)
            + (int) ($row->relationship_proposals_pending ?? 0)
            + (int) ($row->face_matches_pending ?? 0)
            + (int) ($row->duplicate_candidates_pending ?? 0);
        $approvedApply = (int) ($row->change_proposals_approved ?? 0)
            + (int) ($row->relationship_proposals_approved ?? 0);

        $nextAction = 'ready';
        if ($approvedApply > 0) {
            $nextAction = 'apply_or_review_approved_proposals';
        } elseif ($pendingReview > 0) {
            $nextAction = 'review_pending_items';
        } elseif ($mediaFreshActionableUnlinked > 0) {
            $nextAction = 'triage_unlinked_media';
        } elseif ($mediaNeedsRag > 0) {
            $nextAction = 'run_media_rag_batch';
        }

        return [
            'tree_id' => $treeId,
            'tree_name' => $treeName,
            'next_action' => $nextAction,
            'records' => [
                'persons' => (int) ($row->persons ?? 0),
                'families' => (int) ($row->families ?? 0),
                'sources' => (int) ($row->sources ?? 0),
                'citations' => (int) ($row->citations ?? 0),
            ],
            'media' => [
                'total' => (int) ($row->media_total ?? 0),
                'local_files' => (int) ($row->media_local_files ?? 0),
                'missing_files' => (int) ($row->media_missing_files ?? 0),
                'with_text' => (int) ($row->media_with_text ?? 0),
                'with_faces' => (int) ($row->media_with_faces ?? 0),
                'linked_to_persons' => (int) ($row->media_linked_to_persons ?? 0),
                'linked_to_families' => (int) ($row->media_linked_to_families ?? 0),
                'unlinked' => $mediaUnlinked,
                'citation_only_unlinked' => (int) ($row->media_citation_only_unlinked ?? 0),
                'source_only_citation_unlinked' => (int) ($row->media_source_only_citation_unlinked ?? 0),
                'targeted_citation_unlinked' => $mediaTargetedCitationUnlinked,
                'uncited_unlinked' => $mediaUncitedUnlinked,
                'reviewed_unresolved' => $mediaReviewedUnresolved,
                'actionable_unlinked' => $mediaActionableUnlinked,
                'fresh_actionable_unlinked' => $mediaFreshActionableUnlinked,
            ],
            'rag' => [
                'media_pending' => (int) ($row->media_rag_pending ?? 0),
                'media_stale' => (int) ($row->media_rag_stale ?? 0),
                'media_needs_index' => $mediaNeedsRag,
            ],
            'review' => [
                'face_matches_pending' => (int) ($row->face_matches_pending ?? 0),
                'change_proposals_pending' => (int) ($row->change_proposals_pending ?? 0),
                'change_proposals_approved' => (int) ($row->change_proposals_approved ?? 0),
                'relationship_proposals_pending' => (int) ($row->relationship_proposals_pending ?? 0),
                'relationship_proposals_approved' => (int) ($row->relationship_proposals_approved ?? 0),
                'duplicate_candidates_pending' => (int) ($row->duplicate_candidates_pending ?? 0),
            ],
            'suggested_commands' => [
                'media_rag_batch' => $mediaNeedsRag > 0
                    ? "php artisan genealogy:media-rag-index --tree={$treeId} --limit=20 --max-seconds=45"
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, array{lane: string, command_hint: string, required: bool}>
     */
    private function expectedGenealogyScheduleJobs(): array
    {
        $faceSyncRoot = rtrim((string) config('genealogy.nextcloud_root', '/Library/Genealogy'), '/');

        return [
            'genealogy_health_audit' => [
                'lane' => 'health',
                'command_hint' => 'genealogy:health-audit --all-trees --json --compact --limit=20',
                'required' => true,
            ],
            'genealogy_export_readiness_check' => [
                'lane' => 'export',
                'command_hint' => 'genealogy:health-audit --all-trees --sections=export --json --compact --limit=25',
                'required' => true,
            ],
            'genealogy_unlinked_media_review' => [
                'lane' => 'media_review',
                'command_hint' => 'genealogy:health-audit --all-trees --sections=media --json --compact --limit=50',
                'required' => true,
            ],
            'genealogy_htr_status_check' => [
                'lane' => 'htr',
                'command_hint' => 'genealogy:transcribe-media --status',
                'required' => true,
            ],
            'genealogy_media_enrichment_status' => [
                'lane' => 'media_enrichment',
                'command_hint' => 'genealogy:enrich-media --status --quarantined',
                'required' => true,
            ],
            'genealogy_media_enrich' => [
                'lane' => 'media_enrichment',
                'command_hint' => 'genealogy:enrich-media --limit=5',
                'required' => true,
            ],
            'genealogy_media_rag_index' => [
                'lane' => 'media_rag',
                'command_hint' => 'genealogy:media-rag-index --limit=1500',
                'required' => true,
            ],
            'genealogy_rag_index' => [
                'lane' => 'rag',
                'command_hint' => 'genealogy:rag-index --type=all --limit=1000',
                'required' => true,
            ],
            'genealogy_rag_full_reindex' => [
                'lane' => 'rag_full_reindex',
                'command_hint' => 'genealogy:rag-index --type=all --reindex --limit=0',
                'required' => true,
            ],
            'genealogy_duplicate_candidate_scan' => [
                'lane' => 'duplicates',
                'command_hint' => 'genealogy:duplicate-scan --all-trees --min-score=0.75 --limit=250 --json',
                'required' => true,
            ],
            'genealogy_evidence_score_report' => [
                'lane' => 'evidence',
                'command_hint' => 'genealogy:evidence-score --all-trees --json --compact --limit=100',
                'required' => true,
            ],
            'genealogy_face_sync_101' => [
                'lane' => 'faces',
                'command_hint' => 'genealogy:face-sync --folder='.$faceSyncRoot,
                'required' => true,
            ],
            'genealogy_media_validate' => [
                'lane' => 'media_validate',
                'command_hint' => 'genealogy:media-validate',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array{lane: string, command_hint: string, required: bool}|null  $expectedMeta
     */
    private function buildScheduleStatusRow(object $job, ?object $recent, ?array $expectedMeta): array
    {
        $enabled = (int) ($job->enabled ?? 0) === 1;
        $lastStatus = $job->last_run_status ?? null;
        $recentFailures = (int) ($recent->failures ?? 0);

        $status = 'ok';
        if (! $enabled) {
            $status = (bool) ($expectedMeta['required'] ?? false) ? 'disabled' : 'disabled_optional';
        } elseif (in_array($lastStatus, ['failed', 'timeout'], true)) {
            $status = (string) $lastStatus;
        } elseif ($recentFailures > 0) {
            $status = 'degraded';
        } elseif (empty($job->next_run_at)) {
            $status = 'no_next_run';
        } elseif ($lastStatus === 'running') {
            $status = 'running';
        }

        return [
            'id' => (int) $job->id,
            'name' => $job->name,
            'lane' => $expectedMeta['lane'] ?? ($job->workload_family ?? null),
            'expected' => $expectedMeta !== null,
            'required' => (bool) ($expectedMeta['required'] ?? false),
            'status' => $status,
            'enabled' => $enabled,
            'cron' => $job->cron_expression ?? null,
            'command' => $job->command ?? null,
            'next_run_at' => $job->next_run_at ?? null,
            'last_run_at' => $job->last_run_at ?? null,
            'last_completed_at' => $job->last_completed_at ?? null,
            'last_run_status' => $lastStatus,
            'timeout_minutes' => isset($job->timeout_minutes) ? (int) $job->timeout_minutes : null,
            'run_count' => isset($job->run_count) ? (int) $job->run_count : null,
            'fail_count' => isset($job->fail_count) ? (int) $job->fail_count : null,
            'runtime' => [
                'mode' => $job->runtime_mode ?? null,
                'workload_family' => $job->workload_family ?? null,
                'resource_profile' => $job->resource_profile ?? null,
                'stall_policy' => $job->stall_policy ?? null,
                'backlog_metric' => $job->backlog_metric ?? null,
                'notification_mode' => $job->notification_mode ?? null,
            ],
            'recent' => [
                'runs' => (int) ($recent->runs ?? 0),
                'failures' => $recentFailures,
                'last_started_at' => $recent->last_started_at ?? null,
                'last_completed_at' => $recent->last_completed_at ?? null,
                'max_duration_seconds' => isset($recent->max_duration_seconds) ? (float) $recent->max_duration_seconds : null,
                'items_processed' => (int) ($recent->items_processed ?? 0),
            ],
        ];
    }

    /**
     * @return array{primary_photo_links_missing: int, citation_media_links_missing: int, approved_face_links_missing: int, total: int}
     */
    private function mediaLinkIntegrityCounts(int $treeId): array
    {
        $row = DB::selectOne('
            SELECT
                (SELECT COUNT(*)
                 FROM genealogy_persons p
                 JOIN genealogy_media gm ON gm.id = p.primary_photo_id AND gm.tree_id = p.tree_id
                 LEFT JOIN genealogy_person_media pm ON pm.person_id = p.id AND pm.media_id = p.primary_photo_id
                 WHERE p.tree_id = ?
                   AND p.primary_photo_id IS NOT NULL
                   AND pm.id IS NULL) AS primary_photo_links_missing,
                (SELECT COUNT(*)
                 FROM (
                    SELECT DISTINCT c.person_id, c.media_id
                    FROM genealogy_citations c
                    JOIN genealogy_persons p ON p.id = c.person_id
                    JOIN genealogy_media gm ON gm.id = c.media_id AND gm.tree_id = p.tree_id
                    LEFT JOIN genealogy_person_media pm ON pm.person_id = c.person_id AND pm.media_id = c.media_id
                    WHERE p.tree_id = ?
                      AND c.person_id IS NOT NULL
                      AND c.media_id IS NOT NULL
                      AND pm.id IS NULL
                 ) citation_pairs) AS citation_media_links_missing,
                (SELECT COUNT(*)
                 FROM (
                    SELECT DISTINCT q.suggested_person_id, q.media_id
                    FROM genealogy_face_match_queue q
                    JOIN genealogy_persons p ON p.id = q.suggested_person_id AND p.tree_id = q.tree_id
                    JOIN genealogy_media gm ON gm.id = q.media_id AND gm.tree_id = q.tree_id
                    LEFT JOIN genealogy_person_media pm ON pm.person_id = q.suggested_person_id AND pm.media_id = q.media_id
                    WHERE q.tree_id = ?
                      AND q.status IN ("approved", "auto_linked")
                      AND q.suggested_person_id IS NOT NULL
                      AND q.media_id IS NOT NULL
                      AND pm.id IS NULL
                 ) face_pairs) AS approved_face_links_missing
        ', array_fill(0, 3, $treeId));

        $counts = [
            'primary_photo_links_missing' => (int) ($row->primary_photo_links_missing ?? 0),
            'citation_media_links_missing' => (int) ($row->citation_media_links_missing ?? 0),
            'approved_face_links_missing' => (int) ($row->approved_face_links_missing ?? 0),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function mediaLinkIntegritySamples(int $treeId, int $limit): array
    {
        return [
            'primary_photo_links_missing' => DB::select('
                SELECT p.id AS person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name,
                       p.primary_photo_id AS media_id,
                       gm.title AS media_title,
                       gm.local_filename,
                       gm.nextcloud_path
                FROM genealogy_persons p
                JOIN genealogy_media gm ON gm.id = p.primary_photo_id AND gm.tree_id = p.tree_id
                LEFT JOIN genealogy_person_media pm ON pm.person_id = p.id AND pm.media_id = p.primary_photo_id
                WHERE p.tree_id = ?
                  AND p.primary_photo_id IS NOT NULL
                  AND pm.id IS NULL
                ORDER BY p.id
                LIMIT ?
            ', [$treeId, $limit]),
            'citation_media_links_missing' => DB::select('
                SELECT c.person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name,
                       c.media_id,
                       gm.title AS media_title,
                       MIN(c.id) AS first_citation_id,
                       COUNT(*) AS citation_count
                FROM genealogy_citations c
                JOIN genealogy_persons p ON p.id = c.person_id
                JOIN genealogy_media gm ON gm.id = c.media_id AND gm.tree_id = p.tree_id
                LEFT JOIN genealogy_person_media pm ON pm.person_id = c.person_id AND pm.media_id = c.media_id
                WHERE p.tree_id = ?
                  AND c.person_id IS NOT NULL
                  AND c.media_id IS NOT NULL
                  AND pm.id IS NULL
                GROUP BY c.person_id, person_name, c.media_id, media_title
                ORDER BY first_citation_id
                LIMIT ?
            ', [$treeId, $limit]),
            'approved_face_links_missing' => DB::select('
                SELECT q.id AS queue_id,
                       q.suggested_person_id AS person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name,
                       q.media_id,
                       gm.title AS media_title,
                       q.face_name,
                       q.confidence_score,
                       q.status
                FROM genealogy_face_match_queue q
                JOIN genealogy_persons p ON p.id = q.suggested_person_id AND p.tree_id = q.tree_id
                JOIN genealogy_media gm ON gm.id = q.media_id AND gm.tree_id = q.tree_id
                LEFT JOIN genealogy_person_media pm ON pm.person_id = q.suggested_person_id AND pm.media_id = q.media_id
                WHERE q.tree_id = ?
                  AND q.status IN ("approved", "auto_linked")
                  AND q.suggested_person_id IS NOT NULL
                  AND q.media_id IS NOT NULL
                  AND pm.id IS NULL
                ORDER BY q.reviewed_at DESC, q.id DESC
                LIMIT ?
            ', [$treeId, $limit]),
        ];
    }

    private function repairPrimaryPhotoMediaLinks(int $treeId): void
    {
        DB::insert('
            INSERT IGNORE INTO genealogy_person_media
                (person_id, media_id, is_primary, face_confirmed, notes, created_at)
            SELECT p.id,
                   p.primary_photo_id,
                   1,
                   0,
                   ?,
                   NOW()
            FROM genealogy_persons p
            JOIN genealogy_media gm ON gm.id = p.primary_photo_id AND gm.tree_id = p.tree_id
            LEFT JOIN genealogy_person_media pm ON pm.person_id = p.id AND pm.media_id = p.primary_photo_id
            WHERE p.tree_id = ?
              AND p.primary_photo_id IS NOT NULL
              AND pm.id IS NULL
        ', [
            'Codex MCP repair: restored missing person-media link from genealogy_persons.primary_photo_id. Not a face confirmation.',
            $treeId,
        ]);
    }

    private function repairCitationMediaLinks(int $treeId): void
    {
        DB::insert('
            INSERT IGNORE INTO genealogy_person_media
                (person_id, media_id, face_confirmed, notes, created_at)
            SELECT DISTINCT c.person_id,
                   c.media_id,
                   0,
                   ?,
                   NOW()
            FROM genealogy_citations c
            JOIN genealogy_persons p ON p.id = c.person_id
            JOIN genealogy_media gm ON gm.id = c.media_id AND gm.tree_id = p.tree_id
            LEFT JOIN genealogy_person_media pm ON pm.person_id = c.person_id AND pm.media_id = c.media_id
            WHERE p.tree_id = ?
              AND c.person_id IS NOT NULL
              AND c.media_id IS NOT NULL
              AND pm.id IS NULL
        ', [
            'Codex MCP repair: restored missing person-media link from genealogy_citations.media_id. Not a face confirmation.',
            $treeId,
        ]);
    }

    private function repairApprovedFaceMediaLinks(int $treeId): void
    {
        DB::insert('
            INSERT IGNORE INTO genealogy_person_media
                (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h, face_confirmed, notes, created_at)
            SELECT q.suggested_person_id,
                   q.media_id,
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(q.face_region, "$.x")) AS DECIMAL(5,4)),
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(q.face_region, "$.y")) AS DECIMAL(5,4)),
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(q.face_region, "$.w")) AS DECIMAL(5,4)),
                   CAST(JSON_UNQUOTE(JSON_EXTRACT(q.face_region, "$.h")) AS DECIMAL(5,4)),
                   1,
                   ?,
                   NOW()
            FROM genealogy_face_match_queue q
            JOIN genealogy_persons p ON p.id = q.suggested_person_id AND p.tree_id = q.tree_id
            JOIN genealogy_media gm ON gm.id = q.media_id AND gm.tree_id = q.tree_id
            LEFT JOIN genealogy_person_media pm ON pm.person_id = q.suggested_person_id AND pm.media_id = q.media_id
            WHERE q.tree_id = ?
              AND q.status IN ("approved", "auto_linked")
              AND q.suggested_person_id IS NOT NULL
              AND q.media_id IS NOT NULL
              AND pm.id IS NULL
        ', [
            'Codex MCP repair: restored missing confirmed person-media link from approved face-match queue.',
            $treeId,
        ]);
    }

    /**
     * @return array{citation_source_links_missing: int, family_citation_source_links_missing: int, total: int}
     */
    private function personSourceLinkIntegrityCounts(int $treeId): array
    {
        $row = DB::selectOne('
            SELECT
                (
                    SELECT COUNT(*)
                    FROM (
                        SELECT DISTINCT c.person_id, c.source_id
                        FROM genealogy_citations c
                        JOIN genealogy_persons p ON p.id = c.person_id
                        JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = p.tree_id
                        LEFT JOIN genealogy_person_sources ps ON ps.person_id = c.person_id AND ps.source_id = c.source_id
                        WHERE p.tree_id = ?
                          AND c.person_id IS NOT NULL
                          AND c.source_id IS NOT NULL
                          AND ps.id IS NULL
                    ) person_missing_pairs
                ) AS citation_source_links_missing,
                (
                    SELECT COUNT(*)
                    FROM (
                        SELECT DISTINCT family_member.person_id, c.source_id
                        FROM genealogy_citations c
                        JOIN genealogy_families f ON f.id = c.family_id
                        JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = f.tree_id
                        JOIN (
                            SELECT id AS family_id, husband_id AS person_id, "spouse" AS member_role
                            FROM genealogy_families
                            WHERE husband_id IS NOT NULL
                            UNION ALL
                            SELECT id AS family_id, wife_id AS person_id, "spouse" AS member_role
                            FROM genealogy_families
                            WHERE wife_id IS NOT NULL
                            UNION ALL
                            SELECT family_id, person_id, "child" AS member_role
                            FROM genealogy_children
                            WHERE person_id IS NOT NULL
                        ) family_member ON family_member.family_id = f.id
                        JOIN genealogy_persons p ON p.id = family_member.person_id AND p.tree_id = f.tree_id
                        LEFT JOIN genealogy_person_sources ps ON ps.person_id = family_member.person_id AND ps.source_id = c.source_id
                        WHERE f.tree_id = ?
                          AND c.family_id IS NOT NULL
                          AND c.source_id IS NOT NULL
                          AND (
                              LOWER(c.fact_type) IN ("relationship", "children")
                              OR (
                                  family_member.member_role = "spouse"
                                  AND LOWER(c.fact_type) IN ("spouse_context", "marriage")
                              )
                          )
                          AND ps.id IS NULL
                    ) family_missing_pairs
                ) AS family_citation_source_links_missing
        ', [$treeId, $treeId]);

        $counts = [
            'citation_source_links_missing' => (int) ($row->citation_source_links_missing ?? 0),
            'family_citation_source_links_missing' => (int) ($row->family_citation_source_links_missing ?? 0),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function personSourceLinkIntegritySamples(int $treeId, int $limit, array $counts): array
    {
        $samples = [
            'citation_source_links_missing' => [],
            'family_citation_source_links_missing' => [],
        ];

        if (($counts['citation_source_links_missing'] ?? 0) > 0) {
            $samples['citation_source_links_missing'] = DB::select('
                SELECT c.person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name,
                       c.source_id,
                       s.title AS source_title,
                       MIN(c.id) AS first_citation_id,
                       COUNT(*) AS citation_count,
                       MIN(NULLIF(c.page, "")) AS sample_page,
                       MAX(c.quality) AS max_quality
                FROM genealogy_citations c
                JOIN genealogy_persons p ON p.id = c.person_id
                JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = p.tree_id
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = c.person_id AND ps.source_id = c.source_id
                WHERE p.tree_id = ?
                  AND c.person_id IS NOT NULL
                  AND c.source_id IS NOT NULL
                  AND ps.id IS NULL
                GROUP BY c.person_id, person_name, c.source_id, source_title
                ORDER BY first_citation_id
                LIMIT ?
            ', [$treeId, $limit]);
        }

        if (($counts['family_citation_source_links_missing'] ?? 0) > 0) {
            $samples['family_citation_source_links_missing'] = DB::select('
                SELECT family_member.person_id,
                       TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name,
                       c.source_id,
                       s.title AS source_title,
                       MIN(c.id) AS first_family_citation_id,
                       COUNT(DISTINCT c.id) AS family_citation_count,
                       MIN(NULLIF(c.page, "")) AS sample_page,
                       MAX(c.quality) AS max_quality
                FROM genealogy_citations c
                JOIN genealogy_families f ON f.id = c.family_id
                JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = f.tree_id
                JOIN (
                    SELECT id AS family_id, husband_id AS person_id, "spouse" AS member_role
                    FROM genealogy_families
                    WHERE husband_id IS NOT NULL
                    UNION ALL
                    SELECT id AS family_id, wife_id AS person_id, "spouse" AS member_role
                    FROM genealogy_families
                    WHERE wife_id IS NOT NULL
                    UNION ALL
                    SELECT family_id, person_id, "child" AS member_role
                    FROM genealogy_children
                    WHERE person_id IS NOT NULL
                ) family_member ON family_member.family_id = f.id
                JOIN genealogy_persons p ON p.id = family_member.person_id AND p.tree_id = f.tree_id
                LEFT JOIN genealogy_person_sources ps ON ps.person_id = family_member.person_id AND ps.source_id = c.source_id
                WHERE f.tree_id = ?
                  AND c.family_id IS NOT NULL
                  AND c.source_id IS NOT NULL
                  AND (
                      LOWER(c.fact_type) IN ("relationship", "children")
                      OR (
                          family_member.member_role = "spouse"
                          AND LOWER(c.fact_type) IN ("spouse_context", "marriage")
                      )
                  )
                  AND ps.id IS NULL
                GROUP BY family_member.person_id, person_name, c.source_id, source_title
                ORDER BY first_family_citation_id
                LIMIT ?
            ', [$treeId, $limit]);
        }

        return $samples;
    }

    private function repairCitationPersonSourceLinks(int $treeId): void
    {
        DB::insert('
            INSERT IGNORE INTO genealogy_person_sources
                (person_id, source_id, page, quality, created_at)
            SELECT c.person_id,
                   c.source_id,
                   MIN(NULLIF(c.page, "")) AS page,
                   CAST(MAX(c.quality) AS CHAR) AS quality,
                   NOW()
            FROM genealogy_citations c
            JOIN genealogy_persons p ON p.id = c.person_id
            JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = p.tree_id
            LEFT JOIN genealogy_person_sources ps ON ps.person_id = c.person_id AND ps.source_id = c.source_id
            WHERE p.tree_id = ?
              AND c.person_id IS NOT NULL
              AND c.source_id IS NOT NULL
              AND ps.id IS NULL
            GROUP BY c.person_id, c.source_id
        ', [$treeId]);
    }

    private function repairFamilyCitationPersonSourceLinks(int $treeId): void
    {
        DB::insert('
            INSERT IGNORE INTO genealogy_person_sources
                (person_id, source_id, page, quality, created_at)
            SELECT family_member.person_id,
                   c.source_id,
                   MIN(NULLIF(c.page, "")) AS page,
                   CAST(MAX(c.quality) AS CHAR) AS quality,
                   NOW()
            FROM genealogy_citations c
            JOIN genealogy_families f ON f.id = c.family_id
            JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = f.tree_id
            JOIN (
                SELECT id AS family_id, husband_id AS person_id, "spouse" AS member_role
                FROM genealogy_families
                WHERE husband_id IS NOT NULL
                UNION ALL
                SELECT id AS family_id, wife_id AS person_id, "spouse" AS member_role
                FROM genealogy_families
                WHERE wife_id IS NOT NULL
                UNION ALL
                SELECT family_id, person_id, "child" AS member_role
                FROM genealogy_children
                WHERE person_id IS NOT NULL
            ) family_member ON family_member.family_id = f.id
            JOIN genealogy_persons p ON p.id = family_member.person_id AND p.tree_id = f.tree_id
            LEFT JOIN genealogy_person_sources ps ON ps.person_id = family_member.person_id AND ps.source_id = c.source_id
            WHERE f.tree_id = ?
              AND c.family_id IS NOT NULL
              AND c.source_id IS NOT NULL
              AND (
                  LOWER(c.fact_type) IN ("relationship", "children")
                  OR (
                      family_member.member_role = "spouse"
                      AND LOWER(c.fact_type) IN ("spouse_context", "marriage")
                  )
              )
              AND ps.id IS NULL
            GROUP BY family_member.person_id, c.source_id
        ', [$treeId]);
    }

    private function personSourceLinkIntegrityNextAction(array $before, bool $repair, bool $dryRun): string
    {
        if (($before['total'] ?? 0) < 1) {
            return 'no_missing_person_source_links_detected';
        }

        if (! $repair || $dryRun) {
            return 'rerun_with_repair_true_dry_run_false_confirm_true_to_create_missing_person_source_links_from_existing_citations';
        }

        return 'repair_applied_recheck_health_audit_citations';
    }

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return array<string, int>
     */
    private function diffIntegrityCounts(array $before, array $after): array
    {
        $repaired = [];
        foreach ($before as $key => $count) {
            if ($key === 'total') {
                continue;
            }
            $repaired[$key] = max(0, (int) $count - (int) ($after[$key] ?? 0));
        }
        $repaired['total'] = array_sum($repaired);

        return $repaired;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function mediaLinkIntegrityNextAction(array $counts, bool $repair, bool $dryRun): string
    {
        if (($counts['total'] ?? 0) === 0) {
            return 'ready';
        }

        if (! $repair) {
            return 'review_integrity_counts_then_rerun_with_repair';
        }

        if ($dryRun) {
            return 'rerun_with_dry_run_false_to_apply_deterministic_repairs';
        }

        return 'rerun_integrity_audit_to_confirm_clean';
    }

    private function buildMediaDuplicateConsolidationPlan(int $treeId, int $dropMediaId, int $keepMediaId): array
    {
        $errors = [];

        $drop = DB::selectOne('
            SELECT id, tree_id, title, media_type, local_filename, nextcloud_path, original_path,
                   file_size, file_exists, rag_indexed_at, updated_at
            FROM genealogy_media
            WHERE id = ?
        ', [$dropMediaId]);

        $keep = DB::selectOne('
            SELECT id, tree_id, title, media_type, local_filename, nextcloud_path, original_path,
                   file_size, file_exists, rag_indexed_at, updated_at
            FROM genealogy_media
            WHERE id = ?
        ', [$keepMediaId]);

        if (! $drop) {
            $errors[] = "Drop media not found: {$dropMediaId}";
        }

        if (! $keep) {
            $errors[] = "Keep media not found: {$keepMediaId}";
        }

        if ($drop && (int) $drop->tree_id !== $treeId) {
            $errors[] = "Drop media {$dropMediaId} is not in tree {$treeId}.";
        }

        if ($keep && (int) $keep->tree_id !== $treeId) {
            $errors[] = "Keep media {$keepMediaId} is not in tree {$treeId}.";
        }

        $dropHash = $drop ? $this->mediaFileSha256($drop) : null;
        $keepHash = $keep ? $this->mediaFileSha256($keep) : null;
        if ($drop && $dropHash === null) {
            $errors[] = "Drop media file is missing or unreadable: {$drop->nextcloud_path}";
        }
        if ($keep && $keepHash === null) {
            $errors[] = "Keep media file is missing or unreadable: {$keep->nextcloud_path}";
        }
        if ($dropHash !== null && $keepHash !== null && $dropHash !== $keepHash) {
            $errors[] = 'Media files are not byte-identical.';
        }

        $dropPersonLinks = DB::select('
            SELECT pm.id AS link_id, pm.person_id, pm.is_primary, pm.face_confirmed, pm.notes,
                   TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name
            FROM genealogy_person_media pm
            JOIN genealogy_persons p ON p.id = pm.person_id
            WHERE pm.media_id = ?
              AND p.tree_id = ?
            ORDER BY pm.person_id
        ', [$dropMediaId, $treeId]);

        $keepPersonLinks = DB::select('
            SELECT pm.id AS link_id, pm.person_id, pm.is_primary, pm.face_confirmed, pm.notes,
                   TRIM(CONCAT(COALESCE(p.given_name, ""), " ", COALESCE(p.surname, ""))) AS person_name
            FROM genealogy_person_media pm
            JOIN genealogy_persons p ON p.id = pm.person_id
            WHERE pm.media_id = ?
              AND p.tree_id = ?
            ORDER BY pm.person_id
        ', [$keepMediaId, $treeId]);

        $dropFamilyLinks = DB::select('
            SELECT fm.id AS link_id, fm.family_id
            FROM genealogy_family_media fm
            JOIN genealogy_families f ON f.id = fm.family_id
            WHERE fm.media_id = ?
              AND f.tree_id = ?
            ORDER BY fm.family_id
        ', [$dropMediaId, $treeId]);

        $keepFamilyLinks = DB::select('
            SELECT fm.id AS link_id, fm.family_id
            FROM genealogy_family_media fm
            JOIN genealogy_families f ON f.id = fm.family_id
            WHERE fm.media_id = ?
              AND f.tree_id = ?
            ORDER BY fm.family_id
        ', [$keepMediaId, $treeId]);

        $dropCitations = DB::select('
            SELECT c.id, c.person_id, c.family_id, c.source_id, c.fact_type
            FROM genealogy_citations c
            WHERE c.media_id = ?
            ORDER BY c.id
        ', [$dropMediaId]);

        $primaryRefs = DB::select('
            SELECT id AS person_id,
                   TRIM(CONCAT(COALESCE(given_name, ""), " ", COALESCE(surname, ""))) AS person_name
            FROM genealogy_persons
            WHERE tree_id = ?
              AND primary_photo_id = ?
            ORDER BY id
        ', [$treeId, $dropMediaId]);

        $faceMatches = DB::select('
            SELECT id, face_name, suggested_person_id, status
            FROM genealogy_face_match_queue
            WHERE tree_id = ?
              AND media_id = ?
            ORDER BY id
        ', [$treeId, $dropMediaId]);

        $mediaFiles = DB::select('
            SELECT id, file_path, is_primary
            FROM genealogy_media_files
            WHERE media_id = ?
            ORDER BY id
        ', [$dropMediaId]);

        $registryRows = [];
        if ($drop && ! empty($drop->nextcloud_path)) {
            $registryRows = DB::select('
                SELECT id, current_path, status
                FROM file_registry
                WHERE current_path = ?
                ORDER BY id
                LIMIT 20
            ', [$drop->nextcloud_path]);
        }

        $keepPersonIds = array_map(static fn (object $row): int => (int) $row->person_id, $keepPersonLinks);
        $dropPersonIds = array_map(static fn (object $row): int => (int) $row->person_id, $dropPersonLinks);
        $keepFamilyIds = array_map(static fn (object $row): int => (int) $row->family_id, $keepFamilyLinks);
        $dropFamilyIds = array_map(static fn (object $row): int => (int) $row->family_id, $dropFamilyLinks);

        $summary = [
            'person_links_to_promote' => count(array_intersect($dropPersonIds, $keepPersonIds)),
            'person_links_to_move' => count(array_diff($dropPersonIds, $keepPersonIds)),
            'family_links_to_promote' => count(array_intersect($dropFamilyIds, $keepFamilyIds)),
            'family_links_to_move' => count(array_diff($dropFamilyIds, $keepFamilyIds)),
            'citations_to_repoint' => count($dropCitations),
            'primary_photo_refs_to_repoint' => count($primaryRefs),
            'face_matches_to_repoint' => count($faceMatches),
            'media_file_rows_to_repoint' => count($mediaFiles),
            'file_registry_rows_to_mark_deleted' => count($registryRows),
        ];

        return [
            'eligible' => $errors === [],
            'errors' => $errors,
            'same_hash' => $dropHash !== null && $dropHash === $keepHash,
            'sha256' => $dropHash,
            'drop_media' => $drop ? $this->buildDuplicatePlanMediaRow($drop) : null,
            'keep_media' => $keep ? $this->buildDuplicatePlanMediaRow($keep) : null,
            'summary' => $summary,
            'drop_person_links' => $this->compactPersonMediaLinks($dropPersonLinks),
            'keep_person_links' => $this->compactPersonMediaLinks($keepPersonLinks),
            'drop_family_links' => $this->compactIdRows($dropFamilyLinks, 'family_id'),
            'keep_family_links' => $this->compactIdRows($keepFamilyLinks, 'family_id'),
            'drop_citations' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'person_id' => isset($row->person_id) ? (int) $row->person_id : null,
                'family_id' => isset($row->family_id) ? (int) $row->family_id : null,
                'source_id' => isset($row->source_id) ? (int) $row->source_id : null,
                'fact_type' => $row->fact_type ?? null,
            ], $dropCitations),
            'primary_photo_refs' => array_map(static fn (object $row): array => [
                'person_id' => (int) $row->person_id,
                'person_name' => $row->person_name ?? null,
            ], $primaryRefs),
            'face_matches' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'face_name' => $row->face_name ?? null,
                'suggested_person_id' => isset($row->suggested_person_id) ? (int) $row->suggested_person_id : null,
                'status' => $row->status ?? null,
            ], $faceMatches),
            'media_files' => array_map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'file_path' => $row->file_path ?? null,
                'is_primary' => isset($row->is_primary) ? (bool) $row->is_primary : null,
            ], $mediaFiles),
        ];
    }

    private function applyMediaDuplicateConsolidation(int $treeId, int $dropMediaId, int $keepMediaId, array $plan): array
    {
        $result = [
            'promoted_person_ids' => [],
            'inserted_person_ids' => [],
            'moved_family_ids' => [],
            'deleted_duplicate_family_link_ids' => [],
            'citations_repointed' => 0,
            'primary_photo_refs_repointed' => 0,
            'face_matches_repointed' => 0,
            'media_file_rows_repointed' => 0,
            'media_file_rows_deleted' => 0,
            'drop_person_links_deleted' => 0,
            'drop_media_deleted' => 0,
        ];

        DB::transaction(function () use ($treeId, $dropMediaId, $keepMediaId, $plan, &$result): void {
            $dropLinks = DB::select('
                SELECT pm.id AS link_id, pm.person_id, pm.is_primary, pm.face_confirmed, pm.notes
                FROM genealogy_person_media pm
                JOIN genealogy_persons p ON p.id = pm.person_id
                WHERE pm.media_id = ?
                  AND p.tree_id = ?
                ORDER BY pm.person_id
            ', [$dropMediaId, $treeId]);

            foreach ($dropLinks as $link) {
                $existing = DB::selectOne('
                    SELECT id, is_primary, face_confirmed, notes
                    FROM genealogy_person_media
                    WHERE media_id = ?
                      AND person_id = ?
                    LIMIT 1
                ', [$keepMediaId, $link->person_id]);

                $note = mb_substr(
                    trim(trim((string) ($existing->notes ?? ''))."\n".$this->duplicateConsolidationNote($dropMediaId, $keepMediaId, (string) ($plan['sha256'] ?? ''))),
                    0,
                    500
                );

                if ($existing) {
                    DB::update('
                        UPDATE genealogy_person_media
                        SET is_primary = ?,
                            face_confirmed = ?,
                            notes = ?
                        WHERE id = ?
                    ', [
                        max((int) $existing->is_primary, (int) $link->is_primary),
                        max((int) $existing->face_confirmed, (int) $link->face_confirmed),
                        $note,
                        $existing->id,
                    ]);
                    $result['promoted_person_ids'][] = (int) $link->person_id;
                } else {
                    DB::insert('
                        INSERT INTO genealogy_person_media
                            (person_id, media_id, is_primary, face_confirmed, notes, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ', [
                        $link->person_id,
                        $keepMediaId,
                        (int) $link->is_primary,
                        (int) $link->face_confirmed,
                        $this->duplicateConsolidationNote($dropMediaId, $keepMediaId, (string) ($plan['sha256'] ?? '')),
                    ]);
                    $result['inserted_person_ids'][] = (int) $link->person_id;
                }
            }

            $dropFamilyLinks = DB::select('
                SELECT fm.id AS link_id, fm.family_id
                FROM genealogy_family_media fm
                JOIN genealogy_families f ON f.id = fm.family_id
                WHERE fm.media_id = ?
                  AND f.tree_id = ?
                ORDER BY fm.id
            ', [$dropMediaId, $treeId]);

            foreach ($dropFamilyLinks as $link) {
                $existing = DB::selectOne('
                    SELECT id
                    FROM genealogy_family_media
                    WHERE media_id = ?
                      AND family_id = ?
                    LIMIT 1
                ', [$keepMediaId, $link->family_id]);

                if ($existing) {
                    DB::delete('DELETE FROM genealogy_family_media WHERE id = ?', [$link->link_id]);
                    $result['deleted_duplicate_family_link_ids'][] = (int) $link->link_id;
                } else {
                    DB::update('UPDATE genealogy_family_media SET media_id = ? WHERE id = ?', [$keepMediaId, $link->link_id]);
                    $result['moved_family_ids'][] = (int) $link->family_id;
                }
            }

            $result['citations_repointed'] = DB::update('UPDATE genealogy_citations SET media_id = ? WHERE media_id = ?', [$keepMediaId, $dropMediaId]);
            $result['primary_photo_refs_repointed'] = DB::update('UPDATE genealogy_persons SET primary_photo_id = ? WHERE tree_id = ? AND primary_photo_id = ?', [$keepMediaId, $treeId, $dropMediaId]);
            $result['face_matches_repointed'] = DB::update('UPDATE genealogy_face_match_queue SET media_id = ? WHERE tree_id = ? AND media_id = ?', [$keepMediaId, $treeId, $dropMediaId]);

            $mediaFiles = DB::select('SELECT id, file_path FROM genealogy_media_files WHERE media_id = ? ORDER BY id', [$dropMediaId]);
            foreach ($mediaFiles as $mediaFile) {
                $existing = DB::selectOne('SELECT id FROM genealogy_media_files WHERE media_id = ? AND file_path = ? LIMIT 1', [$keepMediaId, $mediaFile->file_path]);
                if ($existing) {
                    DB::delete('DELETE FROM genealogy_media_files WHERE id = ?', [$mediaFile->id]);
                    $result['media_file_rows_deleted']++;
                } else {
                    DB::update('UPDATE genealogy_media_files SET media_id = ?, updated_at = NOW() WHERE id = ?', [$keepMediaId, $mediaFile->id]);
                    $result['media_file_rows_repointed']++;
                }
            }

            $result['drop_person_links_deleted'] = DB::delete('DELETE FROM genealogy_person_media WHERE media_id = ?', [$dropMediaId]);
            $result['drop_media_deleted'] = DB::delete('DELETE FROM genealogy_media WHERE id = ? AND tree_id = ?', [$dropMediaId, $treeId]);
            DB::update('UPDATE genealogy_media SET updated_at = NOW(), rag_indexed_at = NULL WHERE id = ? AND tree_id = ?', [$keepMediaId, $treeId]);
        });

        return $result;
    }

    private function buildDuplicatePlanMediaRow(object $media): array
    {
        return [
            'id' => (int) $media->id,
            'tree_id' => (int) $media->tree_id,
            'title' => $media->title ?? null,
            'media_type' => $media->media_type ?? null,
            'local_filename' => $media->local_filename ?? null,
            'nextcloud_path' => $media->nextcloud_path ?? null,
            'original_path' => $media->original_path ?? null,
            'file_size' => isset($media->file_size) ? (int) $media->file_size : null,
            'file_exists' => isset($media->file_exists) ? (bool) $media->file_exists : null,
            'rag_indexed_at' => $media->rag_indexed_at ?? null,
            'updated_at' => $media->updated_at ?? null,
        ];
    }

    private function mediaFileSha256(object $media): ?string
    {
        $path = (string) ($media->nextcloud_path ?? '');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactPersonMediaLinks(array $rows): array
    {
        return array_map(static fn (object $row): array => [
            'link_id' => (int) $row->link_id,
            'person_id' => (int) $row->person_id,
            'person_name' => $row->person_name ?? null,
            'is_primary' => isset($row->is_primary) ? (bool) $row->is_primary : null,
            'face_confirmed' => isset($row->face_confirmed) ? (bool) $row->face_confirmed : null,
        ], $rows);
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<int>
     */
    private function compactIdRows(array $rows, string $field): array
    {
        return array_map(static fn (object $row): int => (int) ($row->{$field} ?? 0), $rows);
    }

    private function duplicateConsolidationNote(int $dropMediaId, int $keepMediaId, string $sha256): string
    {
        return "Consolidated duplicate media {$dropMediaId} into {$keepMediaId} on 2026-05-13; identical SHA-256 {$sha256}.";
    }

    /**
     * @return array{summary: string, sources: list<string>, confidence: float, agent_id: string}
     */
    private function normalizeMediaAttachEvidence(mixed $evidence, int $mediaId, float $confidence): array
    {
        $summary = '';
        $sources = [];
        $agentId = 'genealogy-mcp-media-attach';

        if (is_string($evidence)) {
            $summary = trim($evidence);
        } elseif (is_array($evidence)) {
            $summary = trim((string) ($evidence['summary'] ?? $evidence['evidence_summary'] ?? ''));
            $rawSources = $evidence['sources'] ?? $evidence['evidence_sources'] ?? [];
            $sources = is_array($rawSources) ? $rawSources : [$rawSources];
            $agentId = trim((string) ($evidence['agent_id'] ?? $agentId)) ?: $agentId;
            if (isset($evidence['confidence']) && is_numeric($evidence['confidence'])) {
                $confidence = (float) $evidence['confidence'];
            }
        }

        $sources = array_values(array_unique(array_filter(
            array_map(static fn ($source): string => trim((string) $source), $sources),
            static fn (string $source): bool => $source !== ''
        )));

        array_unshift($sources, "genealogy_media:{$mediaId}");
        $sources = array_values(array_unique($sources));

        return [
            'summary' => $summary,
            'sources' => $sources,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'agent_id' => $agentId,
        ];
    }

    /**
     * @return list<array{name: string, type: string, not_genealogy_person: bool}>
     */
    private function normalizeMediaIdentityAnimalSubjects(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        } elseif (! is_array($value)) {
            $value = [$value];
        }

        $subjects = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                $type = trim((string) ($item['type'] ?? 'animal')) ?: 'animal';
            } else {
                $raw = trim((string) $item);
                if ($raw === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $raw) ?: [];
                $name = trim((string) ($parts[0] ?? $raw));
                $type = count($parts) > 1 ? trim(implode(' ', array_slice($parts, 1))) : 'animal';
            }

            if ($name === '') {
                continue;
            }

            $subjects[] = [
                'name' => $name,
                'type' => $type !== '' ? $type : 'animal',
                'not_genealogy_person' => true,
            ];
        }

        return array_values($subjects);
    }

    /**
     * @return list<object>
     */
    private function mediaIdentityRegistryRows(object $media): array
    {
        $paths = array_values(array_unique(array_filter([
            $media->nextcloud_path ?? null,
            $media->original_path ?? null,
        ], static fn ($path): bool => trim((string) $path) !== '')));

        if ($paths === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($paths), '?'));

        return DB::select(
            "SELECT id, current_path, original_path, filename, status
             FROM file_registry
             WHERE status = 'active'
               AND (current_path IN ({$placeholders}) OR original_path IN ({$placeholders}))
             ORDER BY CASE WHEN current_path = ? THEN 0 ELSE 1 END, id",
            array_merge($paths, $paths, [$media->nextcloud_path ?? ''])
        );
    }

    /**
     * @param  list<int>  $registryIds
     * @return list<object>
     */
    private function mediaIdentityVisibleFaces(array $registryIds): array
    {
        if ($registryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($registryIds), '?'));

        return DB::select(
            "SELECT id, file_registry_id, face_index, person_name, genealogy_person_id,
                    region_x, region_y, region_w, region_h, confidence, source, verified
             FROM file_registry_faces
             WHERE file_registry_id IN ({$placeholders})
               AND COALESCE(hidden, 0) = 0
             ORDER BY verified DESC, confidence DESC, id",
            $registryIds
        );
    }

    /**
     * @param  list<string>  $additional
     * @return list<string>
     */
    private function mergeStringTags(mixed $existing, array $additional): array
    {
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            $existing = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\s*,\s*/', $existing);
        }

        if (! is_array($existing)) {
            $existing = [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($tag): string => trim((string) $tag), array_merge($existing, $additional)),
            static fn (string $tag): bool => $tag !== ''
        )));
    }

    /**
     * @param  list<array{name: string, type: string, not_genealogy_person: bool}>  $animalSubjects
     */
    private function buildMediaIdentityDescription(object $media, string $identityLabel, string $personName, ?string $familyLabel, array $animalSubjects): string
    {
        $pieces = ['Operator-verified '.now()->toDateString().": media shows {$identityLabel}"];
        if ($identityLabel !== $personName && $personName !== '') {
            $pieces[] = "canonical person {$personName}";
        }
        if ($familyLabel !== null) {
            $pieces[] = "family label {$familyLabel}";
        }
        if ($animalSubjects !== []) {
            $animals = implode(', ', array_map(static fn (array $animal): string => trim($animal['name'].' the '.$animal['type']), $animalSubjects));
            $pieces[] = "also shown: {$animals}";
        }

        $filename = trim((string) ($media->local_filename ?? ''));
        if ($filename !== '') {
            $pieces[] = "Filename: {$filename}";
        }

        return implode('. ', $pieces).'.';
    }

    /**
     * @param  list<array{name: string, type: string, not_genealogy_person: bool}>  $animalSubjects
     */
    private function buildMediaIdentityCaption(string $identityLabel, array $animalSubjects): string
    {
        if ($animalSubjects === []) {
            return $identityLabel;
        }

        $animals = implode(', ', array_map(static fn (array $animal): string => trim($animal['name'].' the '.$animal['type']), $animalSubjects));

        return "{$identityLabel} with {$animals}";
    }

    /**
     * @param  list<array{name: string, type: string, not_genealogy_person: bool}>  $animalSubjects
     */
    private function upsertOperatorVerifiedFaceQueue(int $treeId, int $mediaId, int $personId, string $personName, object $face, ?string $familyLabel, array $animalSubjects): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_face_match_queue
             WHERE tree_id = ? AND media_id = ? AND file_registry_face_id = ?',
            [$treeId, $mediaId, (int) $face->id]
        );

        $faceRegion = json_encode([
            'x' => isset($face->region_x) ? (float) $face->region_x : null,
            'y' => isset($face->region_y) ? (float) $face->region_y : null,
            'w' => isset($face->region_w) ? (float) $face->region_w : null,
            'h' => isset($face->region_h) ? (float) $face->region_h : null,
        ]);
        $details = json_encode([
            'source' => 'operator confirmation',
            'family_label' => $familyLabel,
            'animal_subjects' => $animalSubjects,
        ]);

        if ($existing) {
            DB::update(
                'UPDATE genealogy_face_match_queue
                 SET face_name = ?, suggested_person_id = ?, match_type = ?, confidence_score = ?,
                     face_region = ?, match_details = ?, status = ?, reviewed_at = NOW(),
                     review_notes = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $personName,
                    $personId,
                    'operator_verified',
                    1.00,
                    $faceRegion,
                    $details,
                    'approved',
                    'Approved from operator-confirmed media identity.',
                    (int) $existing->id,
                ]
            );

            return;
        }

        DB::insert(
            'INSERT INTO genealogy_face_match_queue
             (tree_id, media_id, face_name, suggested_person_id, match_type, confidence_score,
              face_region, match_details, status, reviewed_at, review_notes, file_registry_face_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())',
            [
                $treeId,
                $mediaId,
                $personName,
                $personId,
                'operator_verified',
                1.00,
                $faceRegion,
                $details,
                'approved',
                'Approved from operator-confirmed media identity.',
                (int) $face->id,
            ]
        );
    }

    /**
     * @param  list<array{name: string, type: string, not_genealogy_person: bool}>  $animalSubjects
     * @param  list<int>  $registryIds
     * @param  array{summary: string, sources: list<string>, confidence: float, agent_id: string}  $evidence
     */
    private function recordMediaIdentityMemory(int $treeId, int $mediaId, int $personId, string $personName, string $identityLabel, ?string $familyLabel, array $animalSubjects, array $registryIds, array $evidence, string $agentId): ?int
    {
        $factKey = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower("media {$mediaId} {$personName}")) ?: "media_{$mediaId}", 0, 100);

        $existing = DB::selectOne(
            'SELECT id FROM agent_semantic_memory
             WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?',
            ['genealogy_media', $mediaId, 'media_identity', $factKey]
        );
        if ($existing) {
            return (int) $existing->id;
        }

        DB::insert(
            'INSERT INTO agent_semantic_memory
             (entity_type, entity_id, fact_type, fact_key, fact_value, confidence, consensus_status, source_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                'genealogy_media',
                $mediaId,
                'media_identity',
                $factKey,
                json_encode([
                    'schema' => 'genealogy_media_identity_memory.v1',
                    'tree_id' => $treeId,
                    'media_id' => $mediaId,
                    'person_id' => $personId,
                    'person_name' => $personName,
                    'identity_label' => $identityLabel,
                    'family_label' => $familyLabel,
                    'animal_subjects' => $animalSubjects,
                    'registry_file_ids' => $registryIds,
                    'evidence_summary' => $evidence['summary'],
                    'evidence_sources' => $evidence['sources'],
                ]),
                $evidence['confidence'],
                'agreed',
                1,
            ]
        );

        $memory = DB::selectOne(
            'SELECT id FROM agent_semantic_memory
             WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?
             ORDER BY id DESC LIMIT 1',
            ['genealogy_media', $mediaId, 'media_identity', $factKey]
        );
        if (! $memory) {
            return null;
        }

        DB::insert(
            'INSERT INTO agent_semantic_fact_sources
             (memory_id, source_type, source_id, confidence, agent_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [(int) $memory->id, 'user_confirmed_media_identity', $mediaId, $evidence['confidence'], $agentId]
        );

        return (int) $memory->id;
    }

    /**
     * @param  array{summary: string, sources: list<string>, confidence: float, agent_id: string}  $normalizedEvidence
     */
    private function createFamilyMediaAttachProposal(
        object $media,
        int $familyId,
        int $mediaId,
        int $treeId,
        array $normalizedEvidence,
        bool $dryRun
    ): array {
        $family = DB::selectOne(
            "SELECT f.id, f.tree_id, f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                    TRIM(CONCAT(COALESCE(h.given_name, ''), ' ', COALESCE(h.surname, ''))) AS husband_name,
                    h.tree_id AS husband_tree_id,
                    TRIM(CONCAT(COALESCE(w.given_name, ''), ' ', COALESCE(w.surname, ''))) AS wife_name,
                    w.tree_id AS wife_tree_id
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
             WHERE f.id = ?",
            [$familyId]
        );
        if (! $family) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => "Family not found: {$familyId}",
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ((int) $family->tree_id !== $treeId) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Family is not in the requested tree.',
                'family_id' => $familyId,
                'requested_tree_id' => $treeId,
                'family_tree_id' => (int) $family->tree_id,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $anchorPersonId = $this->familyMediaAnchorPersonId($family, $treeId);
        if ($anchorPersonId === null) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => false,
                'error' => 'Family media proposals require at least one same-tree husband, wife, or child person to anchor the review proposal.',
                'family_id' => $familyId,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $existingLink = DB::selectOne(
            'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?',
            [$familyId, $mediaId]
        );
        if ($existingLink) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => true,
                'proposal_created' => false,
                'already_linked' => true,
                'family_id' => $familyId,
                'media_id' => $mediaId,
                'link_id' => (int) $existingLink->id,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $proposedValue = $this->familyMediaProposalValue($familyId, $mediaId);
        $existingProposal = DB::selectOne(
            "SELECT id, status FROM genealogy_proposed_changes
             WHERE tree_id = ?
               AND change_type = 'media_link'
               AND field_name = 'family_id'
               AND proposed_value = ?
               AND status IN ('pending', 'approved', 'applied')",
            [$treeId, $proposedValue]
        );
        if ($existingProposal) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => true,
                'proposal_created' => false,
                'deduplicated' => true,
                'proposal_id' => (int) $existingProposal->id,
                'existing_status' => $existingProposal->status,
                'family_id' => $familyId,
                'media_id' => $mediaId,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $preview = [
            'change_type' => 'media_link',
            'target_type' => 'family',
            'family_id' => $familyId,
            'family_label' => $this->familyLabel($family),
            'anchor_person_id' => $anchorPersonId,
            'media_id' => $mediaId,
            'media_title' => $media->title ?? $media->local_filename ?? null,
            'tree_id' => $treeId,
            'field_name' => 'family_id',
            'proposed_value' => $proposedValue,
            'evidence_sources' => $normalizedEvidence['sources'],
            'evidence_summary' => $normalizedEvidence['summary'],
            'confidence' => $normalizedEvidence['confidence'],
            'agent_id' => $normalizedEvidence['agent_id'],
        ];

        if ($dryRun) {
            return [
                'tool' => 'media_attach_proposal',
                'success' => true,
                'dry_run' => true,
                'proposal_created' => false,
                'proposal' => $preview,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        DB::insert(
            "INSERT INTO genealogy_proposed_changes
                (tree_id, person_id, change_type, field_name, current_value, proposed_value,
                 evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
             VALUES (?, ?, 'media_link', 'family_id', NULL, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $treeId,
                $anchorPersonId,
                $proposedValue,
                json_encode($normalizedEvidence['sources'], JSON_UNESCAPED_SLASHES),
                $normalizedEvidence['summary'],
                $normalizedEvidence['confidence'],
                $normalizedEvidence['agent_id'],
            ]
        );
        $row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
        $proposalId = isset($row->id) ? (int) $row->id : null;

        $this->logGenealogyWriteAudit(
            'media_attach_proposal',
            'propose_family_media_link',
            $normalizedEvidence['agent_id'],
            $proposalId !== null,
            [
                'tree_id' => $treeId,
                'family_id' => $familyId,
                'anchor_person_id' => $anchorPersonId,
                'media_id' => $mediaId,
                'proposal_id' => $proposalId,
                'deduplicated' => false,
            ],
            [
                'summary' => $normalizedEvidence['summary'],
                'sources' => $normalizedEvidence['sources'],
                'confidence' => $normalizedEvidence['confidence'],
            ],
            $proposalId
                ? "Reject/delete genealogy_proposed_changes.id={$proposalId} before approval if this family media proposal is wrong; this tool did not create a direct media link."
                : 'No proposal ID was returned; inspect genealogy_proposed_changes and service logs before retrying.',
            ['dry_run' => false]
        );

        return [
            'tool' => 'media_attach_proposal',
            'success' => $proposalId !== null,
            'dry_run' => false,
            'proposal_created' => $proposalId !== null,
            'proposal_id' => $proposalId,
            'deduplicated' => false,
            'proposal' => $preview,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function familyMediaAnchorPersonId(object $family, int $treeId): ?int
    {
        if (! empty($family->husband_id) && isset($family->husband_tree_id) && (int) $family->husband_tree_id === $treeId) {
            return (int) $family->husband_id;
        }

        if (! empty($family->wife_id) && isset($family->wife_tree_id) && (int) $family->wife_tree_id === $treeId) {
            return (int) $family->wife_id;
        }

        $child = DB::selectOne(
            'SELECT c.person_id
             FROM genealogy_children c
             JOIN genealogy_persons p ON p.id = c.person_id
             WHERE c.family_id = ?
               AND p.tree_id = ?
             ORDER BY c.birth_order IS NULL, c.birth_order ASC, c.id ASC
             LIMIT 1',
            [(int) $family->id, $treeId]
        );

        return $child ? (int) $child->person_id : null;
    }

    private function familyLabel(object $family): string
    {
        $husband = trim((string) ($family->husband_name ?? ''));
        $wife = trim((string) ($family->wife_name ?? ''));

        return trim(($husband !== '' ? $husband : 'Unknown husband').' + '.($wife !== '' ? $wife : 'Unknown wife'));
    }

    private function personDisplayName(object $person): string
    {
        $name = trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));

        return $name !== '' ? $name : 'Person #'.(int) ($person->id ?? 0);
    }

    private function familyMediaProposalValue(int $familyId, int $mediaId): string
    {
        return json_encode([
            'family_id' => $familyId,
            'media_id' => $mediaId,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{family_id: int, media_id: int}|null
     */
    private function familyMediaProposalPayload(object $proposal): ?array
    {
        if (($proposal->change_type ?? null) !== 'media_link' || ($proposal->field_name ?? null) !== 'family_id') {
            return null;
        }

        $payload = json_decode((string) ($proposal->proposed_value ?? ''), true);
        if (! is_array($payload)) {
            return null;
        }

        $familyId = (int) ($payload['family_id'] ?? 0);
        $mediaId = (int) ($payload['media_id'] ?? 0);
        if ($familyId < 1 || $mediaId < 1) {
            return null;
        }

        return ['family_id' => $familyId, 'media_id' => $mediaId];
    }

    private function applyFamilyMediaLinkProposal(object $proposal, int $treeId): array
    {
        $payload = $this->familyMediaProposalPayload($proposal);
        if ($payload === null) {
            return ['success' => false, 'error' => 'Approved proposal is not a valid family media link payload.'];
        }

        $family = DB::selectOne('SELECT id FROM genealogy_families WHERE id = ? AND tree_id = ?', [
            $payload['family_id'],
            $treeId,
        ]);
        if (! $family) {
            return ['success' => false, 'error' => 'Family not found in requested tree.', 'family_id' => $payload['family_id'], 'media_id' => $payload['media_id']];
        }

        $media = DB::selectOne('SELECT id FROM genealogy_media WHERE id = ? AND tree_id = ?', [
            $payload['media_id'],
            $treeId,
        ]);
        if (! $media) {
            return ['success' => false, 'error' => 'Media not found in requested tree.', 'family_id' => $payload['family_id'], 'media_id' => $payload['media_id']];
        }

        $existing = DB::selectOne(
            'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?',
            [$payload['family_id'], $payload['media_id']]
        );
        if ($existing) {
            DB::update(
                "UPDATE genealogy_proposed_changes SET status = 'applied', applied_at = NOW(), updated_at = NOW() WHERE id = ?",
                [(int) $proposal->id]
            );

            return [
                'success' => true,
                'already_linked' => true,
                'link_id' => (int) $existing->id,
                'family_id' => $payload['family_id'],
                'media_id' => $payload['media_id'],
            ];
        }

        DB::insert(
            'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())',
            [$payload['family_id'], $payload['media_id']]
        );
        $link = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
        DB::update(
            "UPDATE genealogy_proposed_changes SET status = 'applied', applied_at = NOW(), updated_at = NOW() WHERE id = ?",
            [(int) $proposal->id]
        );

        return [
            'success' => true,
            'link_id' => isset($link->id) ? (int) $link->id : null,
            'family_id' => $payload['family_id'],
            'media_id' => $payload['media_id'],
        ];
    }

    private function normalizeProposedFactValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return trim((string) json_encode($value));
    }

    /**
     * @return array{summary: string, sources: list<string>, confidence: float, agent_id: string}
     */
    private function normalizeFactUpdateEvidence(
        mixed $evidence,
        float $confidence,
        string $defaultAgentId = 'genealogy-mcp-fact-update'
    ): array {
        $summary = '';
        $sources = [];
        $agentId = $defaultAgentId;

        if (is_string($evidence)) {
            $summary = trim($evidence);
        } elseif (is_array($evidence)) {
            $summary = trim((string) ($evidence['summary'] ?? $evidence['evidence_summary'] ?? ''));
            $rawSources = $evidence['sources'] ?? $evidence['evidence_sources'] ?? [];
            $sources = is_array($rawSources) ? $rawSources : [$rawSources];
            $agentId = trim((string) ($evidence['agent_id'] ?? $agentId)) ?: $agentId;
            if (isset($evidence['confidence']) && is_numeric($evidence['confidence'])) {
                $confidence = (float) $evidence['confidence'];
            }
        }

        $sources = array_values(array_unique(array_filter(
            array_map(static fn ($source): string => trim((string) $source), $sources),
            static fn (string $source): bool => $source !== ''
        )));

        return [
            'summary' => $summary,
            'sources' => $sources,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'agent_id' => $agentId,
        ];
    }

    /**
     * @param  array{summary: string, sources: list<string>, confidence: float, agent_id: string}  $normalizedEvidence
     * @param  list<string>  $reviewFlags
     * @param  array<string, mixed>  $reviewRoute
     * @return array<string, mixed>
     */
    private function buildFactUpdateProvenance(
        mixed $rawEvidence,
        array $normalizedEvidence,
        int $treeId,
        int $personId,
        string $field,
        ?string $currentValue,
        string $proposedValue,
        array $reviewFlags,
        array $reviewRoute,
        string $toolDecision
    ): array {
        $extractedText = '';
        $sourceMediaIds = [];
        $sourceIds = [];

        if (is_array($rawEvidence)) {
            $extractedText = trim((string) (
                $rawEvidence['extracted_text']
                ?? $rawEvidence['evidence_excerpt']
                ?? $rawEvidence['text_excerpt']
                ?? ''
            ));
            $sourceMediaIds = array_merge(
                $sourceMediaIds,
                $this->normalizeProposalIdList($rawEvidence['source_media_id'] ?? null),
                $this->normalizeProposalIdList($rawEvidence['source_media_ids'] ?? null),
                $this->normalizeProposalIdList($rawEvidence['media_id'] ?? null),
                $this->normalizeProposalIdList($rawEvidence['media_ids'] ?? null)
            );
            $sourceIds = array_merge(
                $sourceIds,
                $this->normalizeProposalIdList($rawEvidence['source_id'] ?? null),
                $this->normalizeProposalIdList($rawEvidence['source_ids'] ?? null)
            );
            $toolDecision = trim((string) ($rawEvidence['tool_decision'] ?? $toolDecision)) ?: $toolDecision;
        }

        foreach ($normalizedEvidence['sources'] as $source) {
            if (preg_match('/(?:genealogy_media|media):(\d+)/i', $source, $match) === 1) {
                $sourceMediaIds[] = (int) $match[1];
            }
            if (preg_match('/(?:genealogy_source|source):(\d+)/i', $source, $match) === 1) {
                $sourceIds[] = (int) $match[1];
            }
        }

        if ($extractedText === '') {
            $extractedText = $normalizedEvidence['summary'];
        }

        return [
            'schema_version' => 1,
            'tool' => 'genealogy.fact_update_proposal',
            'tool_decision' => $toolDecision,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'person_id' => $personId,
            'field_name' => $field,
            'current_value' => $currentValue,
            'proposed_value' => $proposedValue,
            'source_media_ids' => array_values(array_unique(array_filter($sourceMediaIds))),
            'source_ids' => array_values(array_unique(array_filter($sourceIds))),
            'evidence_sources' => $normalizedEvidence['sources'],
            'extracted_text' => mb_substr($extractedText, 0, 1200),
            'confidence' => $normalizedEvidence['confidence'],
            'agent_id' => $normalizedEvidence['agent_id'],
            'review_flags' => $reviewFlags,
            'review_route' => $reviewRoute,
        ];
    }

    private function persistFactUpdateProvenance(?int $proposalId, array $provenance): void
    {
        if ($proposalId === null || $proposalId < 1) {
            return;
        }

        try {
            if (! Schema::hasColumn('genealogy_proposed_changes', 'provenance_json')) {
                return;
            }

            DB::update(
                'UPDATE genealogy_proposed_changes SET provenance_json = ? WHERE id = ?',
                [json_encode($provenance, JSON_UNESCAPED_SLASHES), $proposalId]
            );
        } catch (\Throwable) {
            // Provenance persistence must not block the already guarded proposal path.
        }
    }

    private function loadRelationshipLinkPerson(int $personId): ?object
    {
        return DB::selectOne(
            'SELECT id, tree_id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
             FROM genealogy_persons
             WHERE id = ?
             LIMIT 1',
            [$personId]
        );
    }

    /**
     * @return array{family_id: int}|null
     */
    private function existingRelationshipLinkStatus(
        int $treeId,
        int $personId,
        int $relatedPersonId,
        string $relationshipType
    ): ?array {
        $row = match ($relationshipType) {
            'spouse' => DB::selectOne(
                'SELECT id AS family_id
                 FROM genealogy_families
                 WHERE tree_id = ?
                   AND ((husband_id = ? AND wife_id = ?) OR (husband_id = ? AND wife_id = ?))
                 LIMIT 1',
                [$treeId, $personId, $relatedPersonId, $relatedPersonId, $personId]
            ),
            'parent' => DB::selectOne(
                'SELECT gf.id AS family_id
                 FROM genealogy_children gc
                 JOIN genealogy_families gf ON gf.id = gc.family_id
                 WHERE gf.tree_id = ?
                   AND gc.person_id = ?
                   AND (gf.husband_id = ? OR gf.wife_id = ?)
                 LIMIT 1',
                [$treeId, $personId, $relatedPersonId, $relatedPersonId]
            ),
            'child' => DB::selectOne(
                'SELECT gf.id AS family_id
                 FROM genealogy_children gc
                 JOIN genealogy_families gf ON gf.id = gc.family_id
                 WHERE gf.tree_id = ?
                   AND gc.person_id = ?
                   AND (gf.husband_id = ? OR gf.wife_id = ?)
                 LIMIT 1',
                [$treeId, $relatedPersonId, $personId, $personId]
            ),
            'sibling' => DB::selectOne(
                'SELECT gc1.family_id
                 FROM genealogy_children gc1
                 JOIN genealogy_children gc2 ON gc2.family_id = gc1.family_id
                 JOIN genealogy_families gf ON gf.id = gc1.family_id
                 WHERE gf.tree_id = ?
                   AND gc1.person_id = ?
                   AND gc2.person_id = ?
                 LIMIT 1',
                [$treeId, $personId, $relatedPersonId]
            ),
            default => null,
        };

        return $row ? ['family_id' => (int) $row->family_id] : null;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, array{memory_id: int, decision: string, reason: string, reviewed_at: string|null}>
     */
    private function mediaReviewMemoriesForRows(int $treeId, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $mediaIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($mediaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $rows = DB::select(
            "SELECT id, entity_id, fact_value, updated_at
             FROM agent_semantic_memory
             WHERE entity_type = 'genealogy_media'
               AND fact_type = 'media_triage_review'
               AND entity_id IN ({$placeholders})
             ORDER BY updated_at DESC, id DESC",
            $mediaIds
        );

        $memories = [];
        foreach ($rows as $row) {
            $mediaId = (int) ($row->entity_id ?? 0);
            if ($mediaId < 1 || isset($memories[$mediaId])) {
                continue;
            }

            $value = json_decode((string) ($row->fact_value ?? ''), true);
            if (! is_array($value) || (int) ($value['tree_id'] ?? 0) !== $treeId) {
                continue;
            }

            $memories[$mediaId] = [
                'memory_id' => (int) $row->id,
                'decision' => (string) ($value['decision'] ?? 'reviewed'),
                'reason' => (string) ($value['reason'] ?? ''),
                'reviewed_at' => isset($value['reviewed_at']) ? (string) $value['reviewed_at'] : ($row->updated_at ?? null),
            ];
        }

        return $memories;
    }

    private function recordMediaReviewMemory(int $treeId, int $mediaId, string $decision, string $reason, string $actor): ?int
    {
        $factKey = "media_{$mediaId}_triage_review";
        $payload = json_encode([
            'schema' => 'genealogy_media_triage_review.v1',
            'tree_id' => $treeId,
            'media_id' => $mediaId,
            'decision' => $decision,
            'reason' => $reason,
            'actor' => $actor,
            'reviewed_at' => now()->toIso8601String(),
        ]);

        $existing = DB::selectOne(
            'SELECT id FROM agent_semantic_memory
             WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?
             LIMIT 1',
            ['genealogy_media', $mediaId, 'media_triage_review', $factKey]
        );

        if ($existing) {
            DB::update(
                'UPDATE agent_semantic_memory
                 SET fact_value = ?, confidence = ?, consensus_status = ?, source_count = GREATEST(source_count, 1), updated_at = NOW()
                 WHERE id = ?',
                [$payload, 0.75, 'agreed', (int) $existing->id]
            );

            return (int) $existing->id;
        }

        DB::insert(
            'INSERT INTO agent_semantic_memory
             (entity_type, entity_id, fact_type, fact_key, fact_value, confidence, consensus_status, source_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            ['genealogy_media', $mediaId, 'media_triage_review', $factKey, $payload, 0.75, 'agreed', 1]
        );

        $memory = DB::selectOne(
            'SELECT id FROM agent_semantic_memory
             WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?
             ORDER BY id DESC LIMIT 1',
            ['genealogy_media', $mediaId, 'media_triage_review', $factKey]
        );

        if (! $memory) {
            return null;
        }

        DB::insert(
            'INSERT INTO agent_semantic_fact_sources
             (memory_id, source_type, source_id, confidence, agent_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [(int) $memory->id, 'genealogy_media_review', $mediaId, 0.75, $actor]
        );

        return (int) $memory->id;
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function recordSourceGapDecisionMemory(
        int $treeId,
        int $personId,
        string $personName,
        string $decision,
        string $reason,
        array $sourceIds,
        string $actor,
        float $confidence
    ): ?int {
        $factKey = "tree_{$treeId}_person_{$personId}_source_gap";
        $payload = json_encode([
            'schema' => 'genealogy_source_gap_decision.v1',
            'tree_id' => $treeId,
            'person_id' => $personId,
            'person_name' => $personName,
            'decision' => $decision,
            'reason' => $reason,
            'source_ids' => $sourceIds,
            'actor' => $actor,
            'reviewed_at' => now()->toIso8601String(),
        ]);
        $sourceCount = max(1, count($sourceIds));

        $existing = DB::selectOne(
            'SELECT id FROM agent_semantic_memory
             WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?
             LIMIT 1',
            ['genealogy_person', $personId, 'source_gap_decision', $factKey]
        );

        if ($existing) {
            $memoryId = (int) $existing->id;
            DB::update(
                'UPDATE agent_semantic_memory
                 SET fact_value = ?, confidence = ?, consensus_status = ?, source_count = ?, updated_at = NOW()
                 WHERE id = ?',
                [$payload, $confidence, 'agreed', $sourceCount, $memoryId]
            );
            DB::delete('DELETE FROM agent_semantic_fact_sources WHERE memory_id = ?', [$memoryId]);
        } else {
            DB::insert(
                'INSERT INTO agent_semantic_memory
                 (entity_type, entity_id, fact_type, fact_key, fact_value, confidence, consensus_status, source_count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                ['genealogy_person', $personId, 'source_gap_decision', $factKey, $payload, $confidence, 'agreed', $sourceCount]
            );

            $memory = DB::selectOne(
                'SELECT id FROM agent_semantic_memory
                 WHERE entity_type = ? AND entity_id = ? AND fact_type = ? AND fact_key = ?
                 ORDER BY id DESC LIMIT 1',
                ['genealogy_person', $personId, 'source_gap_decision', $factKey]
            );

            if (! $memory) {
                return null;
            }
            $memoryId = (int) $memory->id;
        }

        $sources = $sourceIds !== [] ? $sourceIds : [$personId];
        foreach ($sources as $sourceId) {
            DB::insert(
                'INSERT INTO agent_semantic_fact_sources
                 (memory_id, source_type, source_id, confidence, agent_id, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [
                    $memoryId,
                    $sourceIds !== [] ? 'genealogy_source_gap_related_source' : 'genealogy_source_gap_review',
                    $sourceId,
                    $confidence,
                    $actor,
                ]
            );
        }

        return $memoryId;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, string>
     */
    private function mediaRowsForQuarantine(int $treeId, array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));

        return DB::select("
            SELECT gm.id,
                   gm.tree_id,
                   gm.title,
                   gm.media_type,
                   gm.nextcloud_path,
                   gm.original_path,
                   gm.local_filename,
                   gm.file_exists,
                   COUNT(DISTINCT pm.id) AS person_links,
                   COUNT(DISTINCT fm.id) AS family_links,
                   COUNT(DISTINCT c.id) AS citations,
                   COUNT(DISTINCT CASE WHEN c.person_id IS NOT NULL THEN c.id END) AS person_citations,
                   COUNT(DISTINCT CASE WHEN c.family_id IS NOT NULL THEN c.id END) AS family_citations,
                   COUNT(DISTINCT CASE WHEN c.id IS NOT NULL AND c.person_id IS NULL AND c.family_id IS NULL THEN c.id END) AS source_only_citations,
                   COUNT(DISTINCT q.id) AS face_queue_rows
            FROM genealogy_media gm
            LEFT JOIN genealogy_person_media pm ON pm.media_id = gm.id
            LEFT JOIN genealogy_family_media fm ON fm.media_id = gm.id
            LEFT JOIN genealogy_citations c ON c.media_id = gm.id
            LEFT JOIN genealogy_face_match_queue q ON q.media_id = gm.id AND q.tree_id = gm.tree_id
            WHERE gm.tree_id = ?
              AND gm.id IN ({$placeholders})
            GROUP BY gm.id,
                     gm.tree_id,
                     gm.title,
                     gm.media_type,
                     gm.nextcloud_path,
                     gm.original_path,
                     gm.local_filename,
                     gm.file_exists
        ", array_merge([$treeId], $mediaIds));
    }

    /**
     * @return array{eligible: bool, reason: string, links: array<string, int>}
     */
    private function quarantineEligibility(object $row, string $treeRoot): array
    {
        $links = [
            'person_links' => (int) ($row->person_links ?? 0),
            'family_links' => (int) ($row->family_links ?? 0),
            'citations' => (int) ($row->citations ?? 0),
            'face_queue_rows' => (int) ($row->face_queue_rows ?? 0),
        ];

        if ($links['person_links'] > 0 || $links['family_links'] > 0 || $links['citations'] > 0) {
            return [
                'eligible' => false,
                'reason' => 'Refusing quarantine because media has person, family, or citation links.',
                'links' => $links,
            ];
        }

        $nextcloudPath = trim((string) ($row->nextcloud_path ?? ''));
        if ($nextcloudPath === '' || preg_match('~^https?://~i', $nextcloudPath) === 1) {
            return [
                'eligible' => false,
                'reason' => 'Refusing quarantine because media does not have a local nextcloud_path.',
                'links' => $links,
            ];
        }

        $normalizedPath = '/'.ltrim(str_replace('\\', '/', $nextcloudPath), '/');
        $normalizedRoot = rtrim('/'.ltrim(str_replace('\\', '/', $treeRoot), '/'), '/');
        if ($normalizedRoot !== '' && ! str_starts_with($normalizedPath.'/', $normalizedRoot.'/')) {
            return [
                'eligible' => false,
                'reason' => 'Refusing quarantine because media path is not under this tree root.',
                'links' => $links,
            ];
        }

        return [
            'eligible' => true,
            'reason' => 'Unlinked, uncited, same-tree media is eligible for quarantine.',
            'links' => $links,
        ];
    }

    private function quarantineFolderPath(string $treeRoot, ?string $bucket): string
    {
        $bucket = $this->safePathSegment($bucket ?: 'reviewed_non_ft');

        return rtrim($treeRoot, '/').'/_removed_non_ft_'.now()->toDateString().'/'.$bucket;
    }

    /**
     * @return array<string, mixed>
     */
    private function quarantinePathsForMedia(object $row, string $quarantineFolder): array
    {
        $sourceNextcloudPath = '/'.ltrim(str_replace('\\', '/', (string) ($row->nextcloud_path ?? '')), '/');
        $filename = $this->safeFilename((string) ($row->local_filename ?: basename($sourceNextcloudPath) ?: ('media-'.$row->id)));
        $targetNextcloudPath = rtrim($quarantineFolder, '/').'/'.$filename;

        $sourceDiskPath = $this->nextcloudDiskPath($sourceNextcloudPath);
        $targetDiskPath = $this->nextcloudDiskPath($targetNextcloudPath);

        if ($targetDiskPath !== null) {
            $targetDiskPath = $this->uniqueDiskPath($targetDiskPath);
            if ($sourceDiskPath !== null) {
                $targetNextcloudPath = $this->diskPathToNextcloudPath($targetDiskPath) ?? $targetNextcloudPath;
            }
        }

        return [
            'source_nextcloud_path' => $sourceNextcloudPath,
            'target_nextcloud_path' => $targetNextcloudPath,
            'source_disk_path' => $sourceDiskPath,
            'target_disk_path' => $targetDiskPath,
            'file_move' => $sourceDiskPath !== null && $targetDiskPath !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array<string, mixed>
     */
    private function applyMediaQuarantine(int $treeId, object $row, array $paths, string $reason, string $actor): array
    {
        $mediaId = (int) $row->id;
        $sourceDiskPath = $paths['source_disk_path'] ?? null;
        $targetDiskPath = $paths['target_disk_path'] ?? null;
        $sourceNextcloudPath = (string) ($paths['source_nextcloud_path'] ?? '');
        $targetNextcloudPath = (string) ($paths['target_nextcloud_path'] ?? '');

        if (! is_string($sourceDiskPath) || ! is_string($targetDiskPath) || $sourceDiskPath === '' || $targetDiskPath === '') {
            return [
                'media_id' => $mediaId,
                'title' => $row->title ?? null,
                'eligible' => true,
                'applied' => false,
                'reason' => 'Cannot map Nextcloud path to local disk path; dry-run only or configure services.nextcloud.data_path.',
                'planned_nextcloud_path' => $targetNextcloudPath,
            ];
        }

        if (! is_file($sourceDiskPath)) {
            if ((int) ($row->file_exists ?? 1) === 0) {
                $markedRegistryRows = 0;

                try {
                    DB::transaction(function () use ($treeId, $mediaId, $sourceNextcloudPath, $reason, $actor, &$markedRegistryRows): void {
                        $markedRegistryRows = $this->markFileRegistryPathDeleted($sourceNextcloudPath, $actor, $reason);
                        $this->deleteUnlinkedMediaDependents($treeId, $mediaId);
                        DB::delete('DELETE FROM genealogy_media WHERE id = ? AND tree_id = ?', [$mediaId, $treeId]);
                    });
                } catch (\Throwable $e) {
                    return [
                        'media_id' => $mediaId,
                        'title' => $row->title ?? null,
                        'eligible' => true,
                        'applied' => false,
                        'reason' => 'Database update failed while deleting missing-file media row.',
                        'error' => $e->getMessage(),
                        'source_nextcloud_path' => $sourceNextcloudPath,
                    ];
                }

                return [
                    'media_id' => $mediaId,
                    'title' => $row->title ?? null,
                    'eligible' => true,
                    'applied' => true,
                    'source_nextcloud_path' => $sourceNextcloudPath,
                    'source_file_missing' => true,
                    'file_registry_rows_marked_deleted' => $markedRegistryRows,
                    'deleted_media_row' => true,
                ];
            }

            return [
                'media_id' => $mediaId,
                'title' => $row->title ?? null,
                'eligible' => true,
                'applied' => false,
                'reason' => 'Source file is not present on disk; refusing to delete media row.',
                'source_nextcloud_path' => $sourceNextcloudPath,
            ];
        }

        $targetDir = dirname($targetDiskPath);
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            return [
                'media_id' => $mediaId,
                'title' => $row->title ?? null,
                'eligible' => true,
                'applied' => false,
                'reason' => 'Could not create quarantine folder on disk.',
                'planned_nextcloud_path' => $targetNextcloudPath,
            ];
        }

        if (! rename($sourceDiskPath, $targetDiskPath)) {
            return [
                'media_id' => $mediaId,
                'title' => $row->title ?? null,
                'eligible' => true,
                'applied' => false,
                'reason' => 'File move failed; media row was not deleted.',
                'source_nextcloud_path' => $sourceNextcloudPath,
                'planned_nextcloud_path' => $targetNextcloudPath,
            ];
        }

        try {
            DB::transaction(function () use ($treeId, $mediaId, $sourceNextcloudPath, $targetNextcloudPath, $reason, $actor): void {
                $this->remapFileRegistryPath($sourceNextcloudPath, $targetNextcloudPath, $actor, $reason);
                $this->deleteUnlinkedMediaDependents($treeId, $mediaId);
                DB::delete('DELETE FROM genealogy_media WHERE id = ? AND tree_id = ?', [$mediaId, $treeId]);
            });
        } catch (\Throwable $e) {
            @rename($targetDiskPath, $sourceDiskPath);

            return [
                'media_id' => $mediaId,
                'title' => $row->title ?? null,
                'eligible' => true,
                'applied' => false,
                'reason' => 'Database update failed after file move; attempted to move file back.',
                'error' => $e->getMessage(),
            ];
        }

        return [
            'media_id' => $mediaId,
            'title' => $row->title ?? null,
            'eligible' => true,
            'applied' => true,
            'source_nextcloud_path' => $sourceNextcloudPath,
            'quarantine_nextcloud_path' => $targetNextcloudPath,
            'deleted_media_row' => true,
        ];
    }

    private function markFileRegistryPathDeleted(string $path, string $actor, string $reason): int
    {
        $files = DB::select(
            'SELECT id, asset_uuid, current_path FROM file_registry WHERE current_path = ?',
            [$path]
        );

        $deleteReason = mb_substr('genealogy missing-file media cleanup by '.$actor.': '.$reason, 0, 255);
        $lifecycle = app(FileRegistryLifecycleService::class);
        $marked = 0;

        foreach ($files as $file) {
            $deleted = $lifecycle->markAsDeleted((string) $file->asset_uuid, $deleteReason);
            if (! $deleted) {
                throw new \RuntimeException('Failed to mark file registry row deleted during missing-file genealogy media cleanup.');
            }

            $marked++;
        }

        return $marked;
    }

    private function remapFileRegistryPath(string $oldPath, string $newPath, string $actor, string $reason): void
    {
        $files = DB::select(
            'SELECT id, asset_uuid, current_path FROM file_registry WHERE current_path = ?',
            [$oldPath]
        );

        $moveReason = mb_substr('genealogy media quarantine by '.$actor.': '.$reason, 0, 255);
        $lifecycle = app(FileRegistryLifecycleService::class);

        foreach ($files as $file) {
            $remapped = $lifecycle->remapFilePath((string) $file->asset_uuid, $newPath, 'ai_reorganize', $moveReason);
            if (! $remapped) {
                throw new \RuntimeException('Failed to remap file registry path during genealogy media quarantine.');
            }
        }
    }

    private function deleteUnlinkedMediaDependents(int $treeId, int $mediaId): void
    {
        $tables = [
            'genealogy_face_match_queue' => ['media_id = ? AND tree_id = ?', [$mediaId, $treeId]],
            'genealogy_media_files' => ['media_id = ?', [$mediaId]],
            'genealogy_media_crops' => ['media_id = ?', [$mediaId]],
            'genealogy_media_scan_log' => ['media_id = ?', [$mediaId]],
        ];

        foreach ($tables as $table => [$where, $params]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'media_id')) {
                DB::delete("DELETE FROM {$table} WHERE {$where}", $params);
            }
        }
    }

    private function nextcloudDiskPath(string $nextcloudPath): ?string
    {
        $dataPath = rtrim((string) config('services.nextcloud.data_path', ''), '/');
        if ($dataPath === '') {
            return null;
        }

        $nextcloudPath = '/'.ltrim(str_replace('\\', '/', $nextcloudPath), '/');

        return $dataPath.$nextcloudPath;
    }

    private function diskPathToNextcloudPath(string $diskPath): ?string
    {
        $dataPath = rtrim((string) config('services.nextcloud.data_path', ''), '/');
        if ($dataPath === '' || ! str_starts_with($diskPath, $dataPath.'/')) {
            return null;
        }

        return '/'.ltrim(substr($diskPath, strlen($dataPath)), '/');
    }

    private function uniqueDiskPath(string $path): string
    {
        if (! file_exists($path)) {
            return $path;
        }

        $directory = dirname($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);

        for ($index = 2; $index < 1000; $index++) {
            $candidate = $directory.'/'.$name.'_'.$index.($extension !== '' ? '.'.$extension : '');
            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        return $directory.'/'.$name.'_'.uniqid().($extension !== '' ? '.'.$extension : '');
    }

    private function safePathSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?: '';
        $value = trim($value, '._-');

        return $value !== '' ? $value : 'reviewed_non_ft';
    }

    private function safeFilename(string $value): string
    {
        $value = trim(str_replace(["\0", '/', '\\'], '_', $value));

        return $value !== '' ? $value : 'media-file';
    }

    /**
     * @param  list<int>  $mediaIds
     * @return array<int, string>
     */
    private function exactPersonHitsForMediaRows(int $treeId, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $mediaIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($mediaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $rows = DB::select("
            SELECT gm.id AS media_id,
                   GROUP_CONCAT(DISTINCT CONCAT(
                       p.id,
                       ':',
                       TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, '')))
                   ) ORDER BY p.surname ASC, p.given_name ASC, p.id ASC SEPARATOR ' | ') AS hits
            FROM genealogy_media gm
            JOIN genealogy_persons p ON p.tree_id = gm.tree_id
            WHERE gm.tree_id = ?
              AND gm.id IN ({$placeholders})
              AND COALESCE(p.given_name, '') <> ''
              AND COALESCE(p.surname, '') <> ''
              AND CHAR_LENGTH(TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, '')))) >= 8
              AND (
                  LOWER(COALESCE(gm.title, '')) LIKE CONCAT('%', LOWER(TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, '')))), '%')
                  OR LOWER(COALESCE(gm.local_filename, '')) LIKE CONCAT('%', LOWER(TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, '')))), '%')
                  OR (
                      LOWER(COALESCE(gm.description, '')) LIKE CONCAT('%', LOWER(TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, '')))), '%')
                      AND LOWER(COALESCE(gm.description, '')) NOT LIKE 'metadata-only update:%'
                      AND LOWER(COALESCE(gm.description, '')) NOT LIKE '% keep folder%'
                  )
              )
            GROUP BY gm.id
        ", array_merge([$treeId], $mediaIds));

        $hits = [];
        foreach ($rows as $row) {
            $mediaId = (int) ($row->media_id ?? 0);
            $value = $this->truncateHintList($row->hits ?? null);
            if ($mediaId > 0 && $value !== null) {
                $hits[$mediaId] = $value;
            }
        }

        return $hits;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function classifyUnlinkedMediaTriageRow(object $row, ?string $exactPersonHits): array
    {
        if ((int) ($row->citation_count ?? 0) > 0) {
            if ((int) ($row->person_citation_count ?? 0) > 0) {
                return ['person_citation_link_repair', 'Has person-targeted citation but no direct person-media link.', 'high'];
            }

            if ((int) ($row->family_citation_count ?? 0) > 0) {
                return ['family_citation_link_review', 'Has family-targeted citation but no direct family-media link.', 'medium'];
            }

            return ['source_only_citation', 'Has source-only citation; not a broken person/family media link.', 'low'];
        }

        $text = strtolower(trim(implode(' ', [
            (string) ($row->title ?? ''),
            (string) ($row->local_filename ?? ''),
            (string) ($row->original_path ?? ''),
            (string) ($row->description_excerpt ?? ''),
        ])));

        if (str_contains($text, 'no matching person record exists in this tree')) {
            return ['likely_non_ft', 'Existing description says no matching person record exists in this tree.', 'high'];
        }

        if ((int) ($row->positive_face_match_count ?? 0) > 0) {
            return ['face_match_positive', 'Has pending/approved/auto-linked face-match queue signal.', 'high'];
        }

        if (str_contains($text, 'catalog.archives.gov') || str_contains($text, 'nara-')) {
            return ['research_lead_review', 'Unlinked NARA/catalog research lead; exact-name hits may come from search-query notes.', 'medium'];
        }

        if ($exactPersonHits !== null && $exactPersonHits !== '') {
            return ['exact_person_name_hits', 'Exact FT person name appears in title, filename, or description.', 'high'];
        }

        if ((int) ($row->registry_named_face_count ?? 0) > 0) {
            return ['registry_face_hints', 'File-registry face metadata has named face hints.', 'medium'];
        }

        if ((int) ($row->face_match_count ?? 0) > 0) {
            return ['rejected_face_only', 'Only rejected or ignored face-match queue signals remain.', 'low'];
        }

        return ['no_hints', 'No compact person, citation, or face hint surfaced.', 'low'];
    }

    private function truncateHintList(mixed $value, int $maxItems = 5, int $maxLength = 500): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $items = array_values(array_filter(
            array_map('trim', explode(' | ', $value)),
            static fn (string $item): bool => $item !== ''
        ));

        if (count($items) > $maxItems) {
            $items = array_slice($items, 0, $maxItems);
            $items[] = '...';
        }

        $value = implode(' | ', $items);
        if (strlen($value) > $maxLength) {
            return substr($value, 0, $maxLength - 3).'...';
        }

        return $value;
    }

    private function tailText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) <= $maxLength) {
            return $value;
        }

        return '...'.substr($value, -($maxLength - 3));
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatedSourceHints
     */
    private function buildPersonSourceGapCandidate(
        object $row,
        array $relatedSourceHints = [],
        bool $compact = false,
        ?array $sourceGapMemory = null
    ): array {
        $citationCount = (int) ($row->citation_count ?? 0);
        $mediaCount = (int) ($row->media_count ?? 0);
        $relationshipCount = (int) ($row->spouse_family_count ?? 0)
            + (int) ($row->child_count ?? 0)
            + (int) ($row->parent_family_count ?? 0);

        $recommendedAction = match (true) {
            $citationCount > 0 => 'run_person_source_link_integrity_or_review_citation_source_links',
            $mediaCount > 0 => 'review_existing_media_for_source_or_fact_proposals',
            $relatedSourceHints !== [] => 'review_related_source_hints_for_reusable_citation_or_family_context',
            $relationshipCount > 0 => 'research_sources_for_connected_person',
            default => 'low_context_research_or_confirm_nonessential_leaf',
        };

        if ($compact) {
            return [
                'person_id' => (int) $row->person_id,
                'person_name' => $row->person_name ?? null,
                'birth_date' => $row->birth_date ?? null,
                'death_date' => $row->death_date ?? null,
                'living' => isset($row->living) ? (bool) $row->living : null,
                'counts' => [
                    'media' => $mediaCount,
                    'citations' => $citationCount,
                    'relationships' => $relationshipCount,
                    'related_source_hints' => count($relatedSourceHints),
                    'source_gap_decisions' => $sourceGapMemory === null ? 0 : 1,
                ],
                'priority_score' => (int) ($row->priority_score ?? 0),
                'source_gap_memory' => $sourceGapMemory,
                'hint_headlines' => array_map(
                    fn (array $hint): array => [
                        'relation' => $hint['relation'] ?? null,
                        'related_person_id' => $hint['related_person_id'] ?? null,
                        'related_person_name' => $hint['related_person_name'] ?? null,
                        'source_id' => $hint['source_id'] ?? null,
                        'source_title' => $hint['source_title'] ?? null,
                    ],
                    array_slice($relatedSourceHints, 0, 3)
                ),
                'recommended_action' => $recommendedAction,
            ];
        }

        return [
            'person_id' => (int) $row->person_id,
            'person_name' => $row->person_name ?? null,
            'given_name' => $row->given_name ?? null,
            'surname' => $row->surname ?? null,
            'birth_date' => $row->birth_date ?? null,
            'death_date' => $row->death_date ?? null,
            'living' => isset($row->living) ? (bool) $row->living : null,
            'counts' => [
                'media' => $mediaCount,
                'citations' => $citationCount,
                'spouse_families' => (int) ($row->spouse_family_count ?? 0),
                'children' => (int) ($row->child_count ?? 0),
                'parent_families' => (int) ($row->parent_family_count ?? 0),
                'relationships' => $relationshipCount,
                'related_source_hints' => count($relatedSourceHints),
                'source_gap_decisions' => $sourceGapMemory === null ? 0 : 1,
            ],
            'priority_score' => (int) ($row->priority_score ?? 0),
            'source_gap_memory' => $sourceGapMemory,
            'related_source_hints' => $relatedSourceHints,
            'recommended_action' => $recommendedAction,
        ];
    }

    private function buildSourceProfileRow(object $source): array
    {
        return [
            'id' => (int) $source->id,
            'tree_id' => (int) $source->tree_id,
            'gedcom_id' => $source->gedcom_id ?? null,
            'uid' => $source->uid ?? null,
            'title' => $source->title ?? null,
            'author' => $source->author ?? null,
            'repository' => [
                'name' => $source->repository ?? null,
                'address' => $this->textProfile((string) ($source->repository_address ?? ''), 800),
                'call_number' => $source->call_number ?? null,
            ],
            'url' => $source->url ?? null,
            'text' => [
                'publication' => $this->textProfile((string) ($source->publication ?? '')),
                'notes' => $this->textProfile((string) ($source->notes ?? '')),
                'quality_notes' => $this->textProfile((string) ($source->quality_notes ?? ''), 800),
                'classification_notes' => $this->textProfile((string) ($source->classification_notes ?? ''), 800),
            ],
            'classification' => [
                'source_quality' => $source->source_quality ?? null,
                'source_category' => $source->source_category ?? null,
                'information_quality' => $source->information_quality ?? null,
                'confidence' => isset($source->classification_confidence)
                    ? (float) $source->classification_confidence
                    : null,
                'method' => $source->classification_method ?? null,
                'classified_at' => $source->classified_at ?? null,
            ],
            'processing' => [
                'rag_indexed_at' => $source->rag_indexed_at ?? null,
                'rag_status' => $this->sourceRagStatus($source),
                'created_at' => $source->created_at ?? null,
                'updated_at' => $source->updated_at ?? null,
            ],
        ];
    }

    private function sourceRagStatus(object $source): string
    {
        if (empty($source->rag_indexed_at)) {
            return 'pending';
        }

        if (! empty($source->updated_at) && strtotime((string) $source->updated_at) > strtotime((string) $source->rag_indexed_at)) {
            return 'stale';
        }

        return 'indexed';
    }

    private function buildSourceProfileMediaRow(object $media): array
    {
        return [
            'media_id' => (int) $media->media_id,
            'title' => $media->title ?? null,
            'media_type' => $media->media_type ?? null,
            'media_date' => $media->media_date ?? null,
            'paths' => [
                'nextcloud_path' => $media->nextcloud_path ?? null,
                'local_filename' => $media->local_filename ?? null,
                'file_exists' => isset($media->file_exists) ? (bool) $media->file_exists : null,
            ],
            'file' => [
                'file_format' => $media->file_format ?? null,
                'mime_type' => $media->mime_type ?? null,
            ],
            'processing' => [
                'rag_indexed_at' => $media->rag_indexed_at ?? null,
                'rag_status' => $this->mediaRagStatus((object) [
                    'rag_indexed_at' => $media->rag_indexed_at ?? null,
                    'updated_at' => $media->updated_at ?? null,
                ]),
                'updated_at' => $media->updated_at ?? null,
            ],
        ];
    }

    private function buildMediaProfileRow(object $media): array
    {
        return [
            'id' => (int) $media->id,
            'tree_id' => (int) $media->tree_id,
            'gedcom_id' => $media->gedcom_id ?? null,
            'uid' => $media->uid ?? null,
            'media_type' => $media->media_type ?? null,
            'title' => $media->title ?? null,
            'media_date' => $media->media_date ?? null,
            'paths' => [
                'local_filename' => $media->local_filename ?? null,
                'nextcloud_path' => $media->nextcloud_path ?? null,
                'original_path' => $media->original_path ?? null,
                'file_exists' => isset($media->file_exists) ? (bool) $media->file_exists : null,
            ],
            'file' => [
                'file_format' => $media->file_format ?? null,
                'mime_type' => $media->mime_type ?? null,
                'file_size' => isset($media->file_size) ? (int) $media->file_size : null,
                'width' => isset($media->width) ? (int) $media->width : null,
                'height' => isset($media->height) ? (int) $media->height : null,
            ],
            'faces' => [
                'has_faces' => isset($media->has_faces) ? (bool) $media->has_faces : null,
                'face_count' => isset($media->face_count) ? (int) $media->face_count : null,
            ],
            'processing' => [
                'analysis_status' => $media->analysis_status ?? null,
                'analysis_error' => $media->analysis_error ?? null,
                'enrichment_status' => $media->enrichment_status ?? null,
                'enrichment_error' => $media->enrichment_error ?? null,
                'rag_indexed_at' => $media->rag_indexed_at ?? null,
                'rag_status' => $this->mediaRagStatus($media),
                'analyzed_at' => $media->analyzed_at ?? null,
                'enriched_at' => $media->enriched_at ?? null,
                'imported_at' => $media->imported_at ?? null,
                'updated_at' => $media->updated_at ?? null,
                'created_at' => $media->created_at ?? null,
            ],
            'text' => [
                'description' => $this->textProfile((string) ($media->description ?? '')),
                'ai_description' => $this->textProfile((string) ($media->ai_description ?? '')),
                'transcription_text' => $this->textProfile((string) ($media->transcription_text ?? '')),
                'transcription' => $this->textProfile((string) ($media->transcription ?? '')),
            ],
            'subject_tags' => $this->decodeJsonField($media->subject_tags ?? null),
            'exif_data' => $this->decodeJsonField($media->exif_data ?? null),
        ];
    }

    private function buildMediaReviewMediaRow(object $media): array
    {
        return [
            'id' => (int) $media->id,
            'tree_id' => (int) $media->tree_id,
            'title' => $media->title ?? null,
            'media_type' => $media->media_type ?? null,
            'media_date' => $media->media_date ?? null,
            'paths' => [
                'local_filename' => $media->local_filename ?? null,
                'nextcloud_path' => $media->nextcloud_path ?? null,
                'original_path' => $media->original_path ?? null,
                'file_exists' => isset($media->file_exists) ? (bool) $media->file_exists : null,
            ],
            'file' => [
                'file_format' => $media->file_format ?? null,
                'mime_type' => $media->mime_type ?? null,
                'file_size' => isset($media->file_size) ? (int) $media->file_size : null,
                'width' => isset($media->width) ? (int) $media->width : null,
                'height' => isset($media->height) ? (int) $media->height : null,
                'format_family' => $this->mediaReviewFormatFamily($media),
                'readable_by_intake' => $this->mediaReviewReadableByIntake($media),
            ],
            'processing' => [
                'analysis_status' => $media->analysis_status ?? null,
                'analysis_error' => $media->analysis_error ?? null,
                'enrichment_status' => $media->enrichment_status ?? null,
                'enrichment_error' => $media->enrichment_error ?? null,
                'rag_indexed_at' => $media->rag_indexed_at ?? null,
                'rag_status' => $this->mediaRagStatus($media),
                'updated_at' => $media->updated_at ?? null,
            ],
            'subject_tags' => $this->decodeJsonField($media->subject_tags ?? null),
            'exif_data' => $this->decodeJsonField($media->exif_data ?? null),
        ];
    }

    /**
     * @return array<string, array{length: int, excerpt: string|null, truncated: bool, quality: string, excerpt_suppressed: bool}>
     */
    private function buildMediaReviewTextSources(object $media, int $limit, bool $summaryOnly = false): array
    {
        $sources = [];
        foreach ([
            'description' => $media->description ?? '',
            'ai_description' => $media->ai_description ?? '',
            'transcription_text' => $media->transcription_text ?? '',
            'transcription' => $media->transcription ?? '',
        ] as $field => $text) {
            $profile = $this->textProfile((string) $text, $limit);
            if ($summaryOnly && (int) $profile['length'] > 0) {
                $profile['excerpt'] = null;
                $profile['truncated'] = true;
                $profile['excerpt_suppressed'] = true;
            } else {
                $profile['excerpt_suppressed'] = false;
            }
            $profile['quality'] = $this->mediaReviewTextQuality((string) $text, $media);
            $sources[$field] = $profile;
        }

        return $sources;
    }

    /**
     * @param  array<string, array{length: int, excerpt: string|null, truncated: bool, quality: string, excerpt_suppressed?: bool}>  $textSources
     * @param  array<int, object>  $linkedPersons
     * @param  array<int, object>  $linkedFamilies
     * @param  array<int, object>  $citations
     * @param  array<int, object>  $faceMatches
     * @param  array<int, object>  $registryFaces
     * @return array<string, mixed>
     */
    private function buildMediaReviewFocus(
        object $media,
        array $textSources,
        array $linkedPersons,
        array $linkedFamilies,
        array $citations,
        array $faceMatches,
        array $registryFaces,
        ?string $exactPersonHits
    ): array {
        $lengths = array_map(static fn (array $profile): int => (int) $profile['length'], $textSources);
        $hasText = max($lengths ?: [0]) >= 40;
        $hasWeakText = in_array('weak', array_column($textSources, 'quality'), true);
        $formatFamily = $this->mediaReviewFormatFamily($media);
        $readableByIntake = $this->mediaReviewReadableByIntake($media);
        $documentLike = $this->mediaReviewIsDocumentLike($media);

        $actions = [];
        if ($hasWeakText) {
            $actions[] = 'escalate_to_ai_vision_or_manual_review_before_fact_updates';
        } elseif ($documentLike && $readableByIntake && ! $hasText) {
            $actions[] = $formatFamily === 'html'
                ? 'extract_html_narrative_text'
                : 'run_ocr_or_document_text_extraction';
        }

        if ($exactPersonHits !== null || $faceMatches !== [] || $registryFaces !== []) {
            $actions[] = 'review_person_media_link_or_fact_proposals';
        }

        if ($citations !== [] && $linkedPersons === [] && $linkedFamilies === []) {
            $actions[] = 'review_citation_target_before_cleanup';
        }

        if (in_array($this->mediaRagStatus($media), ['pending', 'stale'], true) && $hasText && ! $hasWeakText) {
            $actions[] = 'run_media_rag_batch_after_review';
        }

        if ($actions === []) {
            $actions[] = 'review_manually_then_link_quarantine_or_leave_as_source_context';
        }

        return [
            'format_family' => $formatFamily,
            'readable_by_intake' => $readableByIntake,
            'document_like' => $documentLike,
            'has_persisted_text' => $hasText,
            'text_quality' => $hasWeakText ? 'weak' : ($hasText ? 'usable' : 'missing'),
            'link_state' => [
                'has_person_links' => $linkedPersons !== [],
                'has_family_links' => $linkedFamilies !== [],
                'has_citations' => $citations !== [],
            ],
            'has_name_or_face_hints' => $exactPersonHits !== null || $faceMatches !== [] || $registryFaces !== [],
            'recommended_actions' => array_values(array_unique($actions)),
        ];
    }

    private function buildOcrEscalationCandidate(object $media, bool $includePaths): ?array
    {
        $formatFamily = $this->mediaReviewFormatFamily($media);
        $readable = $this->mediaReviewReadableByIntake($media);
        $documentLike = $this->mediaReviewIsDocumentLike($media);
        $textSources = $this->buildMediaReviewTextSources($media, 350);
        $textLengths = array_map(static fn (array $profile): int => (int) $profile['length'], $textSources);
        $hasText = max($textLengths ?: [0]) >= 40;
        $hasWeakText = in_array('weak', array_column($textSources, 'quality'), true);
        $hasProcessingFailure = $this->mediaHasProcessingFailure($media);

        $bucket = null;
        $priority = 'medium';
        $reason = null;
        $action = null;

        if ($hasWeakText) {
            $bucket = 'weak_text';
            $priority = 'high';
            $reason = 'Existing OCR/HTR or processing signal is weak; do not persist or use for fact changes without review.';
            $action = 'media_review_packet_then_ai_vision_or_manual_review';
        } elseif ($hasProcessingFailure) {
            $bucket = 'processing_failed';
            $priority = 'high';
            $reason = 'Analysis or enrichment failed or has an error message.';
            $action = 'inspect_error_then_retry_or_manual_review';
        } elseif (! $readable || (! $documentLike && in_array($formatFamily, ['image', 'photo'], true))) {
            return null;
        } elseif ($hasText) {
            return null;
        } elseif ($formatFamily === 'html') {
            $bucket = 'html_text_extraction';
            $reason = 'HTML/HTM media has no persisted text excerpt.';
            $action = 'extract_html_narrative_text_then_review_and_reindex';
        } elseif (in_array($formatFamily, ['pdf', 'text', 'office'], true)) {
            $bucket = 'document_text_extraction';
            $reason = 'Readable document media has no persisted text excerpt.';
            $action = 'run_document_text_extraction_or_ocr_then_review';
        } elseif (in_array($formatFamily, ['image', 'photo'], true)) {
            $bucket = 'image_ocr_or_vision';
            $reason = 'Document-like image media has no persisted OCR text.';
            $action = 'run_ocr_then_escalate_weak_output_to_ai_vision_or_manual_review';
        } else {
            $bucket = 'document_text_extraction';
            $reason = 'Readable document-like media has no persisted text excerpt.';
            $action = 'run_best_available_text_extraction_then_review';
        }

        $bestText = $this->bestMediaReviewTextExcerpt($textSources);
        $candidate = [
            'id' => (int) $media->id,
            'title' => $media->title ?? null,
            'type' => $media->media_type ?? null,
            'date' => $media->media_date ?? null,
            'file' => $media->local_filename ?? null,
            'bucket' => $bucket,
            'priority' => $priority,
            'reason' => $reason,
            'recommended_action' => $action,
            'format_family' => $formatFamily,
            'text_quality' => $hasWeakText ? 'weak' : ($hasText ? 'usable' : 'missing'),
            'rag_status' => $this->mediaRagStatus($media),
            'links' => [
                'persons' => isset($media->person_links) ? (int) $media->person_links : null,
                'families' => isset($media->family_links) ? (int) $media->family_links : null,
                'citations' => isset($media->citations) ? (int) $media->citations : null,
            ],
            'processing' => [
                'analysis_status' => $media->analysis_status ?? null,
                'analysis_error' => $this->textProfile((string) ($media->analysis_error ?? ''), 350),
                'enrichment_status' => $media->enrichment_status ?? null,
                'enrichment_error' => $this->textProfile((string) ($media->enrichment_error ?? ''), 350),
            ],
            'text_lengths' => $textLengths,
            'text_excerpt' => $bestText,
            'review_packet_call' => [
                'tool' => 'genealogy.media_review_packet',
                'params' => [
                    'tree_id' => (int) $media->tree_id,
                    'media_id' => (int) $media->id,
                    'text_limit' => 1600,
                    'hint_limit' => 20,
                ],
            ],
            'write_policy' => 'review_first_no_low_quality_text_persistence',
        ];

        if ($includePaths) {
            $candidate['paths'] = [
                'nextcloud_path' => $media->nextcloud_path ?? null,
                'original_path' => $media->original_path ?? null,
            ];
        }

        return $candidate;
    }

    private function mediaHasProcessingFailure(object $media): bool
    {
        $status = strtolower(trim(implode(' ', [
            (string) ($media->analysis_status ?? ''),
            (string) ($media->enrichment_status ?? ''),
        ])));
        $errors = trim(implode(' ', [
            (string) ($media->analysis_error ?? ''),
            (string) ($media->enrichment_error ?? ''),
        ]));

        return str_contains($status, 'failed') || $errors !== '';
    }

    /**
     * @param  array<string, array{length: int, excerpt: string|null, truncated: bool, quality: string}>  $textSources
     */
    private function bestMediaReviewTextExcerpt(array $textSources): ?string
    {
        foreach (['transcription_text', 'transcription', 'description', 'ai_description'] as $field) {
            $excerpt = trim((string) ($textSources[$field]['excerpt'] ?? ''));
            if ($excerpt !== '') {
                return $excerpt;
            }
        }

        return null;
    }

    private function buildFactExtractPacket(
        int $treeId,
        ?int $mediaId,
        ?int $sourceId,
        ?string $documentText,
        int $textLimit
    ): array {
        if ($mediaId !== null) {
            $media = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, uid, media_type, title, media_date,
                        description, ai_description, transcription_text, transcription,
                        subject_tags, exif_data, original_path, nextcloud_path, local_filename,
                        file_format, mime_type, file_size, file_exists, width, height,
                        has_faces, face_count, analysis_status, analysis_error,
                        enrichment_status, enrichment_error, rag_indexed_at,
                        analyzed_at, enriched_at, imported_at, created_at, updated_at
                 FROM genealogy_media
                 WHERE id = ?',
                [$mediaId]
            );

            if (! $media) {
                return ['success' => false, 'error' => "Media not found: {$mediaId}"];
            }

            if ((int) $media->tree_id !== $treeId) {
                return [
                    'success' => false,
                    'error' => 'Media is not in the requested tree.',
                    'media_id' => $mediaId,
                    'requested_tree_id' => $treeId,
                    'media_tree_id' => (int) $media->tree_id,
                ];
            }

            $linkedPersons = DB::select(
                'SELECT DISTINCT p.id AS person_id
                 FROM genealogy_person_media pm
                 JOIN genealogy_persons p ON p.id = pm.person_id
                 WHERE pm.media_id = ?
                   AND p.tree_id = ?
                 LIMIT 25',
                [$mediaId, $treeId]
            );
            $citations = DB::select(
                'SELECT DISTINCT c.person_id, c.text, c.evidence_analysis
                 FROM genealogy_citations c
                 JOIN genealogy_persons p ON p.id = c.person_id
                 WHERE c.media_id = ?
                   AND p.tree_id = ?
                 LIMIT 25',
                [$mediaId, $treeId]
            );

            $textSources = $this->buildMediaReviewTextSources($media, $textLimit);
            $text = $this->combineFactExtractText([
                'title' => $media->title ?? '',
                'media_date' => $media->media_date ?? '',
                'transcription_text' => $media->transcription_text ?? '',
                'transcription' => $media->transcription ?? '',
                'description' => $media->description ?? '',
                'ai_description' => $media->ai_description ?? '',
                'citation_text' => implode("\n", array_map(static fn (object $row): string => trim(implode(' ', [
                    (string) ($row->text ?? ''),
                    (string) ($row->evidence_analysis ?? ''),
                ])), $citations)),
            ], $textLimit);

            return [
                'success' => true,
                'packet' => [
                    'kind' => 'media',
                    'id' => $mediaId,
                    'label' => $media->title ?? $media->local_filename ?? "media {$mediaId}",
                    'media_type' => $media->media_type ?? null,
                    'format_family' => $this->mediaReviewFormatFamily($media),
                    'review_packet_call' => [
                        'tool' => 'genealogy.media_review_packet',
                        'params' => ['tree_id' => $treeId, 'media_id' => $mediaId],
                    ],
                ],
                'text' => $text,
                'text_quality' => $this->factExtractQualityFromMediaSources($textSources),
                'evidence_sources' => ["genealogy_media:{$mediaId}"],
                'target_person_ids' => $this->uniqueIntegerList(array_merge(
                    array_map(static fn (object $row): ?int => isset($row->person_id) ? (int) $row->person_id : null, $linkedPersons),
                    array_map(static fn (object $row): ?int => isset($row->person_id) ? (int) $row->person_id : null, $citations)
                )),
            ];
        }

        if ($sourceId !== null) {
            $source = DB::selectOne(
                'SELECT id, tree_id, gedcom_id, uid, author, title, publication,
                        repository, repository_address, call_number, url, notes,
                        source_quality, quality_notes, source_category, information_quality,
                        classification_confidence, classification_method, classification_notes,
                        classified_at, rag_indexed_at, created_at, updated_at
                 FROM genealogy_sources
                 WHERE id = ?',
                [$sourceId]
            );

            if (! $source) {
                return ['success' => false, 'error' => "Source not found: {$sourceId}"];
            }

            if ((int) $source->tree_id !== $treeId) {
                return [
                    'success' => false,
                    'error' => 'Source is not in the requested tree.',
                    'source_id' => $sourceId,
                    'requested_tree_id' => $treeId,
                    'source_tree_id' => (int) $source->tree_id,
                ];
            }

            $citations = DB::select(
                'SELECT DISTINCT c.person_id, c.text, c.evidence_analysis
                 FROM genealogy_citations c
                 LEFT JOIN genealogy_persons p ON p.id = c.person_id AND p.tree_id = ?
                 WHERE c.source_id = ?
                 LIMIT 50',
                [$treeId, $sourceId]
            );
            $text = $this->combineFactExtractText([
                'title' => $source->title ?? '',
                'author' => $source->author ?? '',
                'publication' => $source->publication ?? '',
                'repository' => $source->repository ?? '',
                'notes' => $source->notes ?? '',
                'quality_notes' => $source->quality_notes ?? '',
                'classification_notes' => $source->classification_notes ?? '',
                'citation_text' => implode("\n", array_map(static fn (object $row): string => trim(implode(' ', [
                    (string) ($row->text ?? ''),
                    (string) ($row->evidence_analysis ?? ''),
                ])), $citations)),
            ], $textLimit);

            return [
                'success' => true,
                'packet' => [
                    'kind' => 'source',
                    'id' => $sourceId,
                    'label' => $source->title ?? "source {$sourceId}",
                    'source_category' => $source->source_category ?? null,
                    'source_profile_call' => [
                        'tool' => 'genealogy.source_profile',
                        'params' => ['tree_id' => $treeId, 'source_id' => $sourceId],
                    ],
                ],
                'text' => $text,
                'text_quality' => $this->factExtractTextQuality($text, null),
                'evidence_sources' => ["genealogy_source:{$sourceId}"],
                'target_person_ids' => $this->uniqueIntegerList(array_map(
                    static fn (object $row): ?int => isset($row->person_id) ? (int) $row->person_id : null,
                    $citations
                )),
            ];
        }

        $text = $this->combineFactExtractText(['document_text' => $documentText ?? ''], $textLimit);

        return [
            'success' => true,
            'packet' => [
                'kind' => 'document_text',
                'id' => null,
                'label' => 'operator supplied document text',
            ],
            'text' => $text,
            'text_quality' => $this->factExtractTextQuality($text, null),
            'evidence_sources' => ['operator_supplied_document_text'],
            'target_person_ids' => [],
        ];
    }

    private function combineFactExtractText(array $segments, int $limit): string
    {
        $parts = [];
        foreach ($segments as $label => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }

            $parts[] = "{$label}: {$text}";
        }

        return mb_substr(trim(implode("\n", $parts)), 0, $limit);
    }

    /**
     * @param  array<string, array{length: int, excerpt: string|null, truncated: bool, quality: string}>  $textSources
     */
    private function factExtractQualityFromMediaSources(array $textSources): string
    {
        foreach ($textSources as $profile) {
            if (($profile['quality'] ?? null) === 'usable' && (int) ($profile['length'] ?? 0) >= 40) {
                return 'usable';
            }
        }

        foreach ($textSources as $profile) {
            if (($profile['quality'] ?? null) === 'weak' && (int) ($profile['length'] ?? 0) >= 40) {
                return 'weak';
            }
        }

        return 'missing';
    }

    private function factExtractTextQuality(string $text, ?object $media): string
    {
        $text = trim($text);
        if (mb_strlen($text) < 40) {
            return 'missing';
        }

        if ($media !== null) {
            return $this->mediaReviewTextQuality($text, $media);
        }

        return $this->looksLikeWeakOcrText($text) ? 'weak' : 'usable';
    }

    /**
     * @param  list<int>  $packetPersonIds
     * @return array{success: bool, people?: array<int, object>, error?: string}
     */
    private function loadFactExtractTargetPeople(int $treeId, ?int $personId, array $packetPersonIds): array
    {
        if ($personId !== null) {
            $person = DB::selectOne(
                'SELECT id, tree_id, given_name, surname, suffix, nickname, sex,
                        birth_date, birth_place, death_date, death_place,
                        burial_date, burial_place, occupation, education, religion,
                        notes, title, physical_description, nationality, cause_of_death
                 FROM genealogy_persons
                 WHERE id = ?',
                [$personId]
            );

            if (! $person) {
                return ['success' => false, 'error' => "Person not found: {$personId}"];
            }

            if ((int) $person->tree_id !== $treeId) {
                return [
                    'success' => false,
                    'error' => 'Person is not in the requested tree.',
                ];
            }

            return ['success' => true, 'people' => [$person]];
        }

        $personIds = array_slice($this->uniqueIntegerList($packetPersonIds), 0, 10);
        if ($personIds === []) {
            return ['success' => true, 'people' => []];
        }

        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $rows = DB::select(
            "SELECT id, tree_id, given_name, surname, suffix, nickname, sex,
                    birth_date, birth_place, death_date, death_place,
                    burial_date, burial_place, occupation, education, religion,
                    notes, title, physical_description, nationality, cause_of_death
             FROM genealogy_persons
             WHERE tree_id = ?
               AND id IN ({$placeholders})
             ORDER BY id ASC",
            array_merge([$treeId], $personIds)
        );

        return ['success' => true, 'people' => $rows];
    }

    /**
     * @param  array<int, object>  $people
     * @return array<int, array<string, mixed>>
     */
    private function compactFactExtractPeople(array $people): array
    {
        return array_map(fn (object $person): array => [
            'person_id' => (int) $person->id,
            'name' => $this->factExtractPersonName($person),
            'birth_date' => $person->birth_date ?? null,
            'birth_place' => $person->birth_place ?? null,
            'death_date' => $person->death_date ?? null,
            'death_place' => $person->death_place ?? null,
            'nickname' => $person->nickname ?? null,
        ], $people);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractFactClaimsFromText(string $text, int $limit): array
    {
        $date = $this->factExtractDatePattern();
        $place = '[A-Z0-9][^\\n.;]{2,90}';
        $short = '[A-Z0-9][^\\n.;]{2,120}';
        $name = '[A-Z][A-Za-z\'(). -]{2,80}';
        $claims = [];

        $this->addFactExtractMatches($claims, 'birth_date', 'birth date label', $text, [
            "/\\b(?:date\\s+of\\s+birth|birth\\s+date|dob|born)\\b\\s*(?:[:;\\-]|was|on)?\\s*(?<value>{$date})/iu",
        ], 0.78, $limit);
        $this->addFactExtractMatches($claims, 'death_date', 'death date label', $text, [
            "/\\b(?:date\\s+of\\s+death|death\\s+date|dod|died)\\b\\s*(?:[:;\\-]|was|on)?\\s*(?<value>{$date})/iu",
        ], 0.78, $limit);
        $this->addFactExtractMatches($claims, 'birth_place', 'birth place label', $text, [
            "/\\b(?:place\\s+of\\s+birth|birthplace|born\\s+(?:at|in))\\b\\s*(?:[:;\\-])?\\s*(?<value>{$place})/iu",
        ], 0.72, $limit);
        $this->addFactExtractMatches($claims, 'death_place', 'death place label', $text, [
            "/\\b(?:place\\s+of\\s+death|death\\s+place|died\\s+(?:at|in))\\b\\s*(?:[:;\\-])?\\s*(?<value>{$place})/iu",
        ], 0.72, $limit);
        $this->addFactExtractMatches($claims, 'burial_date', 'burial date label', $text, [
            "/\\b(?:burial\\s+date|interment\\s+date|buried)\\b\\s*(?:[:;\\-]|on)?\\s*(?<value>{$date})/iu",
        ], 0.72, $limit);
        $this->addFactExtractMatches($claims, 'burial_place', 'burial place label', $text, [
            "/\\b(?:burial\\s+place|place\\s+of\\s+burial|buried\\s+(?:at|in)|interment\\s+(?:at|in)|cemetery)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$place})/iu",
        ], 0.68, $limit);
        $this->addFactExtractMatches($claims, 'occupation', 'occupation label', $text, [
            "/\\b(?:occupation|profession|employed\\s+as|worked\\s+as)\\b\\s*(?:[:;\\-]|was|as)?\\s*(?<value>{$short})/iu",
        ], 0.64, $limit);
        $this->addFactExtractMatches($claims, 'education', 'education label', $text, [
            "/\\b(?:education|schooling|highest\\s+grade|graduated\\s+from|attended\\s+school)\\b\\s*(?:[:;\\-]|was|at)?\\s*(?<value>{$short})/iu",
        ], 0.60, $limit);
        $this->addFactExtractMatches($claims, 'religion', 'religion label', $text, [
            "/\\b(?:religion|religious\\s+affiliation|faith|church)\\b\\s*(?:[:;\\-]|was|at)?\\s*(?<value>{$short})/iu",
        ], 0.60, $limit);
        $this->addFactExtractMatches($claims, 'nationality', 'nationality label', $text, [
            "/\\b(?:nationality|citizenship|citizen\\s+of|native\\s+of)\\b\\s*(?:[:;\\-]|was)?\\s*(?<value>{$short})/iu",
        ], 0.60, $limit);
        $this->addFactExtractMatches($claims, 'cause_of_death', 'cause of death label', $text, [
            "/\\b(?:cause\\s+of\\s+death|died\\s+of|death\\s+due\\s+to)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$short})/iu",
        ], 0.70, $limit);
        $this->addFactExtractMatches($claims, 'physical_description', 'physical description label', $text, [
            "/\\b(?:physical\\s+description|height|weight|eye\\s+color|hair\\s+color|complexion)\\b\\s*(?:[:;\\-]|was)?\\s*(?<value>{$short})/iu",
        ], 0.58, $limit);
        $this->addFactExtractMatches($claims, 'notes', 'notes label', $text, [
            "/\\b(?:notes?|remarks?|memo)\\b\\s*(?:[:;\\-])\\s*(?<value>{$short})/iu",
        ], 0.55, $limit);
        $this->addFactExtractMatches($claims, 'nickname', 'nickname label', $text, [
            "/\\b(?:nickname|also\\s+known\\s+as|a\\.?k\\.?a\\.?|called)\\b\\s*(?:[:;\\-])?\\s*[\"']?(?<value>[A-Z][A-Za-z' -]{1,40})/iu",
        ], 0.70, $limit);
        $this->addFactExtractMatches($claims, 'name', 'identity name label', $text, [
            "/\\b(?:full\\s+name|name\\s+of\\s+(?:deceased|person)|decedent(?:'s)?\\s+name)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$name})/iu",
        ], 0.62, $limit);

        return array_values($claims);
    }

    /**
     * @param  array<string, array<string, mixed>>  $claims
     * @param  list<string>  $patterns
     */
    private function addFactExtractMatches(
        array &$claims,
        string $field,
        string $basis,
        string $text,
        array $patterns,
        float $confidence,
        int $limit
    ): void {
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false) {
                foreach ($matches as $match) {
                    $raw = $match['value'][0] ?? '';
                    $offset = $match['value'][1] ?? ($match[0][1] ?? 0);
                    $value = $this->normalizeFactExtractValue((string) $raw, $field);
                    if (! $this->factExtractValueUsable($field, $value)) {
                        continue;
                    }

                    $key = $field.':'.strtolower($value);
                    $claims[$key] = [
                        'field' => $field,
                        'value' => $value,
                        'basis' => $basis,
                        'confidence' => $confidence,
                        'evidence_excerpt' => $this->excerptAroundOffset($text, (int) $offset, min(420, $limit)),
                    ];
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRelationshipClaimsFromText(string $text, int $limit): array
    {
        $name = '[A-Z][A-Za-z\'(). -]{2,80}';
        $patterns = [
            'father' => "/\\b(?:name\\s+of\\s+father|father)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$name})/iu",
            'mother' => "/\\b(?:maiden\\s+name\\s+of\\s+mother|name\\s+of\\s+mother|mother)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$name})/iu",
            'spouse' => "/\\b(?:spouse|wife|husband|married\\s+to)\\b\\s*(?:[:;\\-])?\\s*(?<value>{$name})/iu",
        ];
        $claims = [];

        foreach ($patterns as $role => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $value = $this->normalizeFactExtractValue((string) ($match['value'][0] ?? ''), 'name');
                if (! $this->looksLikeFactExtractPersonName($value)) {
                    continue;
                }

                $key = $role.':'.strtolower($value);
                $claims[$key] = [
                    'role' => $role,
                    'name' => $value,
                    'relationship_type' => $role === 'spouse' ? 'spouse' : 'parent',
                    'confidence' => $role === 'spouse' ? 0.62 : 0.70,
                    'evidence_excerpt' => $this->excerptAroundOffset($text, (int) ($match['value'][1] ?? 0), min(420, $limit)),
                ];
            }
        }

        return array_values($claims);
    }

    private function buildFactExtractCandidate(
        int $treeId,
        object $person,
        array $fact,
        array $packet,
        float $minimumConfidence
    ): ?array {
        $field = (string) $fact['field'];
        $proposedValue = (string) $fact['value'];
        $currentValue = $this->factExtractCurrentValue($person, $field);
        $personName = $this->factExtractPersonName($person);
        $confidence = (float) $fact['confidence'];
        $flags = [];

        if ($currentValue === null || trim($currentValue) === '') {
            $flags[] = 'fills_blank';
        } elseif ($this->factExtractValuesEquivalent($currentValue, $proposedValue)) {
            $flags[] = 'already_current';
        } else {
            $flags[] = 'conflicts_with_current';
            $confidence = min($confidence, 0.60);
        }

        if (! $this->factExtractTextContainsPerson($packet['text'], $person)) {
            $flags[] = 'target_name_not_seen_in_text';
            $confidence = min($confidence, 0.58);
        }

        $belowMinimum = $confidence < $minimumConfidence;
        if ($belowMinimum) {
            $flags[] = 'below_minimum_confidence';
        }

        $proposalCall = null;
        if (in_array($field, self::FACT_UPDATE_FIELDS, true)
            && ! in_array('already_current', $flags, true)
            && ! $belowMinimum
        ) {
            $proposalCall = [
                'tool' => 'genealogy.fact_update_proposal',
                'params' => [
                    'tree_id' => $treeId,
                    'person_id' => (int) $person->id,
                    'field' => $field,
                    'value' => $proposedValue,
                    'evidence' => [
                        'summary' => "Extracted {$field} '{$proposedValue}' for {$personName} from ".$packet['packet']['label'].'.',
                        'sources' => $packet['evidence_sources'],
                        'confidence' => round($confidence, 2),
                        'agent_id' => 'genealogy-mcp-person-fact-extract',
                        'extracted_text' => $fact['evidence_excerpt'],
                        'source_media_id' => $packet['packet']['kind'] === 'media' ? $packet['packet']['id'] : null,
                        'source_id' => $packet['packet']['kind'] === 'source' ? $packet['packet']['id'] : null,
                        'tool_decision' => in_array('conflicts_with_current', $flags, true)
                            ? 'conflict_review_before_fact_update_proposal'
                            : 'review_first_fact_update_proposal',
                    ],
                    'confidence' => round($confidence, 2),
                    'dry_run' => true,
                ],
            ];
        }

        return [
            'person_id' => (int) $person->id,
            'person_name' => $personName,
            'field' => $field,
            'current_value' => $currentValue,
            'proposed_value' => $proposedValue,
            'confidence' => round($confidence, 2),
            'confidence_band' => $this->factExtractConfidenceBand($confidence),
            'basis' => $fact['basis'],
            'evidence_excerpt' => $fact['evidence_excerpt'],
            'evidence_sources' => $packet['evidence_sources'],
            'conflict_flags' => array_values(array_unique($flags)),
            'proposal_call' => $proposalCall,
            'review_action' => $proposalCall === null
                ? 'review_manually_or_create_targeted_proposal_after_verification'
                : 'review_then_call_fact_update_proposal',
        ];
    }

    private function buildRelationshipExtractLead(
        int $treeId,
        object $person,
        array $claim,
        array $packet,
        float $minimumConfidence
    ): array {
        $confidence = (float) $claim['confidence'];
        $matches = $this->findFactExtractPersonCandidatesByName($treeId, (string) $claim['name'], 5);
        $flags = [];
        if ($matches === []) {
            $flags[] = 'no_existing_person_match';
            $confidence = min($confidence, 0.55);
        } elseif (count($matches) > 1) {
            $flags[] = 'multiple_existing_person_matches';
            $confidence = min($confidence, 0.58);
        }

        $proposalCall = null;
        if (count($matches) === 1 && $confidence >= $minimumConfidence) {
            $proposalCall = [
                'tool' => 'genealogy.relationship_link_proposal',
                'params' => [
                    'tree_id' => $treeId,
                    'person_id' => (int) $person->id,
                    'related_person_id' => (int) $matches[0]['person_id'],
                    'relationship_type' => $claim['relationship_type'],
                    'evidence' => [
                        'summary' => "Extracted {$claim['role']} relationship to {$claim['name']} for ".$this->factExtractPersonName($person).' from '.$packet['packet']['label'].'.',
                        'sources' => $packet['evidence_sources'],
                        'confidence' => round($confidence, 2),
                        'agent_id' => 'genealogy-mcp-person-fact-extract',
                    ],
                    'confidence' => round($confidence, 2),
                    'dry_run' => true,
                ],
            ];
        }

        return [
            'person_id' => (int) $person->id,
            'person_name' => $this->factExtractPersonName($person),
            'role' => $claim['role'],
            'relationship_type' => $claim['relationship_type'],
            'extracted_name' => $claim['name'],
            'candidate_people' => $matches,
            'confidence' => round($confidence, 2),
            'confidence_band' => $this->factExtractConfidenceBand($confidence),
            'evidence_excerpt' => $claim['evidence_excerpt'],
            'evidence_sources' => $packet['evidence_sources'],
            'conflict_flags' => array_values(array_unique($flags)),
            'proposal_call' => $proposalCall,
            'review_action' => $proposalCall === null
                ? 'resolve_person_identity_before_relationship_proposal'
                : 'review_then_call_relationship_link_proposal',
        ];
    }

    private function findFactExtractPersonCandidatesByName(int $treeId, string $name, int $limit): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [];
        }

        $given = strtolower((string) $parts[0]);
        $surname = strtolower((string) end($parts));
        $rows = DB::select(
            'SELECT id, given_name, surname, birth_date, death_date, sex
             FROM genealogy_persons
             WHERE tree_id = ?
               AND LOWER(given_name) LIKE ?
               AND LOWER(surname) = ?
             ORDER BY id ASC
             LIMIT ?',
            [$treeId, $given.'%', $surname, max(1, min(10, $limit))]
        );

        return array_map(fn (object $row): array => [
            'person_id' => (int) $row->id,
            'name' => trim((string) ($row->given_name ?? '').' '.(string) ($row->surname ?? '')),
            'birth_date' => $row->birth_date ?? null,
            'death_date' => $row->death_date ?? null,
            'sex' => $row->sex ?? null,
        ], $rows);
    }

    private function factExtractDatePattern(): string
    {
        $months = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';

        return "(?:\\d{1,2}\\s+(?:{$months})\\.?[,]?\\s+\\d{2,4}|(?:{$months})\\.?\\s+\\d{1,2},?\\s+\\d{2,4}|\\d{1,2}[\\/.\\-]\\d{1,2}[\\/.\\-]\\d{2,4}|\\d{4})";
    }

    private function normalizeFactExtractValue(string $value, string $field): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'“”‘’.,;:-");
        $value = preg_replace('/\s+(?:date of|place of|father|mother|spouse|wife|husband|informant|registered)\\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'“”‘’.,;:-");

        if (in_array($field, ['birth_place', 'death_place', 'burial_place'], true)) {
            $value = preg_replace('/\\s+(?:date|age|sex|race)\\b.*$/iu', '', $value) ?? $value;
        }

        return trim($value);
    }

    private function factExtractValueUsable(string $field, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (in_array($field, ['birth_date', 'death_date', 'burial_date'], true)) {
            return preg_match('/\\d/', $value) === 1 && mb_strlen($value) <= 35;
        }

        if ($field === 'nickname') {
            return preg_match('/\\d/', $value) !== 1 && mb_strlen($value) <= 40;
        }

        if ($field === 'name') {
            return $this->looksLikeFactExtractPersonName($value);
        }

        return mb_strlen($value) >= 3 && mb_strlen($value) <= 120;
    }

    private function looksLikeFactExtractPersonName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/\\d/', $name) === 1) {
            return false;
        }

        if (preg_match('/\\b(?:county|state|certificate|department|unknown|none|not stated|date|place)\\b/iu', $name) === 1) {
            return false;
        }

        preg_match_all('/[A-Za-z]{2,}/', $name, $parts);

        return count($parts[0]) >= 2;
    }

    private function excerptAroundOffset(string $text, int $offset, int $limit): string
    {
        $radius = max(80, (int) floor($limit / 2));
        $start = max(0, $offset - $radius);
        $excerpt = mb_substr($text, $start, $limit);

        return trim(preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt);
    }

    private function factExtractCurrentValue(object $person, string $field): ?string
    {
        if ($field === 'name') {
            return $this->factExtractPersonName($person);
        }

        if (! property_exists($person, $field)) {
            return null;
        }

        $value = $person->{$field};

        return $value !== null ? (string) $value : null;
    }

    private function factExtractPersonName(object $person): string
    {
        return trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));
    }

    private function factExtractValuesEquivalent(?string $left, ?string $right): bool
    {
        $normalize = static fn (?string $value): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? '');

        return $normalize($left) === $normalize($right);
    }

    private function factExtractTextContainsPerson(string $text, object $person): bool
    {
        $text = strtolower($text);
        $given = strtolower(trim((string) ($person->given_name ?? '')));
        $surname = strtolower(trim((string) ($person->surname ?? '')));

        if ($surname !== '' && str_contains($text, $surname)) {
            return true;
        }

        return $given !== '' && str_contains($text, $given);
    }

    private function factExtractConfidenceBand(float $confidence): string
    {
        if ($confidence >= 0.80) {
            return 'strong';
        }

        if ($confidence >= 0.65) {
            return 'medium';
        }

        if ($confidence >= 0.50) {
            return 'weak';
        }

        return 'missing';
    }

    /**
     * @param  array<int, int|null>  $values
     * @return list<int>
     */
    private function uniqueIntegerList(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($value): int => (int) $value, $values),
            static fn (int $value): bool => $value > 0
        )));
    }

    private function mediaReviewFormatFamily(object $media): string
    {
        $extension = strtolower((string) ($media->file_format ?: pathinfo((string) ($media->local_filename ?? ''), PATHINFO_EXTENSION)));
        $mimeType = strtolower((string) ($media->mime_type ?? ''));
        $mediaType = strtolower((string) ($media->media_type ?? ''));

        if (in_array($extension, ['html', 'htm'], true) || str_contains($mimeType, 'html')) {
            return 'html';
        }

        if ($extension === 'pdf' || str_contains($mimeType, 'pdf')) {
            return 'pdf';
        }

        if (in_array($extension, ['txt', 'text', 'csv', 'md', 'rtf'], true) || str_starts_with($mimeType, 'text/')) {
            return 'text';
        }

        if (in_array($extension, ['doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx'], true)) {
            return 'office';
        }

        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'webp', 'bmp'], true)) {
            return $mediaType === 'photo' ? 'photo' : 'image';
        }

        return 'unknown';
    }

    private function mediaReviewReadableByIntake(object $media): bool
    {
        return in_array($this->mediaReviewFormatFamily($media), ['html', 'pdf', 'text', 'office', 'image', 'photo'], true);
    }

    private function mediaReviewIsDocumentLike(object $media): bool
    {
        $formatFamily = $this->mediaReviewFormatFamily($media);
        if (in_array($formatFamily, ['html', 'pdf', 'text', 'office'], true)) {
            return true;
        }

        $mediaType = strtolower((string) ($media->media_type ?? ''));
        if (in_array($mediaType, ['photo', 'portrait'], true)) {
            return false;
        }

        return $formatFamily !== 'photo';
    }

    private function mediaReviewTextQuality(string $text, object $media): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'missing';
        }

        $errors = strtolower(trim(implode(' ', [
            (string) ($media->analysis_error ?? ''),
            (string) ($media->enrichment_error ?? ''),
            (string) ($media->analysis_status ?? ''),
            (string) ($media->enrichment_status ?? ''),
        ])));

        if (str_contains($errors, 'low_confidence') || str_contains($errors, 'htr_low_confidence') || str_contains($errors, 'unreadable')) {
            return 'weak';
        }

        return $this->looksLikeWeakOcrText($text) ? 'weak' : 'usable';
    }

    private function looksLikeWeakOcrText(string $text): bool
    {
        $text = trim($text);
        $length = strlen($text);
        if ($length < 80) {
            return false;
        }

        preg_match_all('/[A-Za-z0-9]/', $text, $alnumMatches);
        preg_match_all('/[{}\\[\\]<>|~`^=_]/', $text, $noiseMatches);

        $alnumRatio = count($alnumMatches[0]) / max(1, $length);
        $noiseRatio = count($noiseMatches[0]) / max(1, $length);

        return $alnumRatio < 0.45 || $noiseRatio > 0.18;
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactLinkedPersonRows(array $rows): array
    {
        return array_map(static fn (object $row): array => [
            'link_id' => isset($row->link_id) ? (int) $row->link_id : null,
            'person_id' => isset($row->person_id) ? (int) $row->person_id : null,
            'name' => $row->person_name ?? null,
            'birth_date' => $row->birth_date ?? null,
            'death_date' => $row->death_date ?? null,
            'living' => isset($row->living) ? (bool) $row->living : null,
            'is_primary' => isset($row->is_primary) ? (bool) $row->is_primary : null,
            'face_confirmed' => isset($row->face_confirmed) ? (bool) $row->face_confirmed : null,
            'notes' => $row->notes ?? null,
        ], $rows);
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactLinkedFamilyRows(array $rows): array
    {
        return array_map(static fn (object $row): array => [
            'link_id' => isset($row->link_id) ? (int) $row->link_id : null,
            'family_id' => isset($row->family_id) ? (int) $row->family_id : null,
            'husband_id' => isset($row->husband_id) ? (int) $row->husband_id : null,
            'husband_name' => $row->husband_name ?? null,
            'wife_id' => isset($row->wife_id) ? (int) $row->wife_id : null,
            'wife_name' => $row->wife_name ?? null,
            'marriage_date' => $row->marriage_date ?? null,
            'marriage_place' => $row->marriage_place ?? null,
        ], $rows);
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactFaceMatchRows(array $rows): array
    {
        return array_map(fn (object $row): array => [
            'id' => isset($row->id) ? (int) $row->id : null,
            'face_name' => $row->face_name ?? null,
            'suggested_person_id' => isset($row->suggested_person_id) ? (int) $row->suggested_person_id : null,
            'suggested_person_name' => $row->suggested_person_name ?? null,
            'suggested_person_birth_date' => $row->suggested_person_birth_date ?? null,
            'suggested_person_death_date' => $row->suggested_person_death_date ?? null,
            'match_type' => $row->match_type ?? null,
            'confidence_score' => isset($row->confidence_score) ? (float) $row->confidence_score : null,
            'status' => $row->status ?? null,
            'review_notes' => $this->textProfile((string) ($row->review_notes ?? ''), 500),
        ], $rows);
    }

    /**
     * @param  array<int, object>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function excerptMediaReviewCitationRows(array $citations): array
    {
        return array_map(function (object $citation): array {
            return [
                'id' => (int) ($citation->id ?? 0),
                'source_id' => isset($citation->source_id) ? (int) $citation->source_id : null,
                'source_title' => $citation->source_title ?? null,
                'source_url' => $citation->source_url ?? null,
                'person' => [
                    'person_id' => isset($citation->person_id) ? (int) $citation->person_id : null,
                    'name' => $citation->person_name ?? null,
                ],
                'family' => [
                    'family_id' => isset($citation->family_id) ? (int) $citation->family_id : null,
                    'husband_name' => $citation->husband_name ?? null,
                    'wife_name' => $citation->wife_name ?? null,
                ],
                'fact_type' => $citation->fact_type ?? null,
                'page' => $citation->page ?? null,
                'quality' => isset($citation->quality) ? (int) $citation->quality : null,
                'evidence_type' => $citation->evidence_type ?? null,
                'information_type' => $citation->information_type ?? null,
                'evidence_analysis' => $this->textProfile((string) ($citation->evidence_analysis ?? ''), 800),
                'text' => $this->textProfile((string) ($citation->text ?? ''), 800),
                'created_at' => $citation->created_at ?? null,
            ];
        }, $citations);
    }

    private function buildMediaReviewRegistryFileRow(object $row): array
    {
        return [
            'file_registry_id' => isset($row->file_registry_id) ? (int) $row->file_registry_id : null,
            'asset_uuid' => $row->asset_uuid ?? null,
            'filename' => $row->filename ?? null,
            'current_path' => $row->current_path ?? null,
            'original_path' => $row->original_path ?? null,
            'original_source' => $row->original_source ?? null,
            'mime_type' => $row->mime_type ?? null,
            'file_size' => isset($row->file_size) ? (int) $row->file_size : null,
            'extension' => $row->extension ?? null,
            'title' => $row->title ?? null,
            'description' => $this->textProfile((string) ($row->description ?? ''), 900),
            'category' => $row->category ?? null,
            'tags' => $this->decodeJsonField($row->tags ?? null),
            'ai_tags' => $this->decodeJsonField($row->ai_tags ?? null),
            'ai_document_type' => $row->ai_document_type ?? null,
            'ai_description' => $this->textProfile((string) ($row->ai_description ?? ''), 900),
            'ai_detected_text' => $this->textProfile((string) ($row->ai_detected_text ?? ''), 900),
            'date_taken' => [
                'value' => $row->date_taken ?? null,
                'source' => $row->date_taken_source ?? null,
                'confidence' => isset($row->date_taken_confidence) ? (float) $row->date_taken_confidence : null,
            ],
            'exif' => [
                'keywords' => $this->textProfile((string) ($row->exif_keywords ?? ''), 700),
                'caption' => $this->textProfile((string) ($row->exif_caption ?? ''), 900),
                'camera_make' => $row->camera_make ?? null,
                'camera_model' => $row->camera_model ?? null,
            ],
            'gps' => [
                'latitude' => isset($row->gps_latitude) ? (float) $row->gps_latitude : null,
                'longitude' => isset($row->gps_longitude) ? (float) $row->gps_longitude : null,
                'location' => $row->gps_location ?? null,
            ],
            'face_count' => isset($row->face_count) ? (int) $row->face_count : null,
            'status' => $row->status ?? null,
            'content_hash' => $row->content_hash ?? null,
            'nextcloud_modified_at' => $row->nextcloud_modified_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function buildMediaReviewRegistryFaceRow(object $row): array
    {
        return [
            'id' => isset($row->id) ? (int) $row->id : null,
            'file_registry_id' => isset($row->file_registry_id) ? (int) $row->file_registry_id : null,
            'current_path' => $row->current_path ?? null,
            'person_name' => $row->person_name ?? null,
            'genealogy_person_id' => isset($row->genealogy_person_id) ? (int) $row->genealogy_person_id : null,
            'genealogy_person_name' => $row->genealogy_person_name ?? null,
            'genealogy_person_birth_date' => $row->genealogy_person_birth_date ?? null,
            'genealogy_person_death_date' => $row->genealogy_person_death_date ?? null,
            'region' => [
                'x' => isset($row->region_x) ? (float) $row->region_x : null,
                'y' => isset($row->region_y) ? (float) $row->region_y : null,
                'w' => isset($row->region_w) ? (float) $row->region_w : null,
                'h' => isset($row->region_h) ? (float) $row->region_h : null,
            ],
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            'source' => $row->source ?? null,
            'verified' => isset($row->verified) ? (bool) $row->verified : null,
            'hidden' => isset($row->hidden) ? (bool) $row->hidden : null,
        ];
    }

    /**
     * @param  array<int, object>  $linkedPersons
     * @param  array<int, object>  $citations
     * @param  array<int, object>  $faceMatches
     * @param  array<int, object>  $registryFaces
     * @return array<int, array<string, mixed>>
     */
    private function buildMediaReviewLikelyPeople(
        array $linkedPersons,
        array $citations,
        array $faceMatches,
        array $registryFaces,
        ?string $exactPersonHits
    ): array {
        $people = [];
        $add = static function (?int $personId, ?string $name, string $basis, ?float $confidence = null) use (&$people): void {
            $name = trim((string) $name);
            if ($personId === null && $name === '') {
                return;
            }

            $key = $personId !== null ? "id:{$personId}" : 'name:'.strtolower($name);
            if (! isset($people[$key])) {
                $people[$key] = [
                    'person_id' => $personId,
                    'name' => $name !== '' ? $name : null,
                    'basis' => [],
                    'max_confidence' => $confidence,
                ];
            }

            $people[$key]['basis'][] = $basis;
            if ($confidence !== null) {
                $people[$key]['max_confidence'] = max($people[$key]['max_confidence'] ?? 0.0, $confidence);
            }
        };

        foreach ($linkedPersons as $row) {
            $add(isset($row->person_id) ? (int) $row->person_id : null, $row->person_name ?? null, 'existing_person_media_link');
        }

        foreach ($citations as $row) {
            $add(isset($row->person_id) ? (int) $row->person_id : null, $row->person_name ?? null, 'citation_person_target');
        }

        foreach ($faceMatches as $row) {
            $add(
                isset($row->suggested_person_id) ? (int) $row->suggested_person_id : null,
                $row->suggested_person_name ?: ($row->face_name ?? null),
                'face_match_queue:'.($row->status ?? 'unknown'),
                isset($row->confidence_score) ? (float) $row->confidence_score : null
            );
        }

        foreach ($registryFaces as $row) {
            $add(
                isset($row->genealogy_person_id) ? (int) $row->genealogy_person_id : null,
                $row->genealogy_person_name ?: ($row->person_name ?? null),
                'file_registry_face:'.($row->source ?? 'unknown'),
                isset($row->confidence) ? (float) $row->confidence : null
            );
        }

        foreach (array_filter(array_map('trim', explode(' | ', (string) $exactPersonHits))) as $hit) {
            if (preg_match('/^(\\d+):(.*)$/', $hit, $matches) === 1) {
                $add((int) $matches[1], trim($matches[2]), 'exact_text_name_hit');
            }
        }

        return array_values(array_map(static function (array $row): array {
            $row['basis'] = array_values(array_unique($row['basis']));

            return $row;
        }, $people));
    }

    private function mediaRagStatus(object $media): string
    {
        if (empty($media->rag_indexed_at)) {
            return 'pending';
        }

        if (! empty($media->updated_at) && strtotime((string) $media->updated_at) > strtotime((string) $media->rag_indexed_at)) {
            return 'stale';
        }

        return 'indexed';
    }

    /**
     * @return array{length: int, excerpt: string|null, truncated: bool}
     */
    private function textProfile(string $text, int $limit = 1200): array
    {
        $text = trim($text);
        $length = mb_strlen($text);

        return [
            'length' => $length,
            'excerpt' => $length > 0 ? mb_substr($text, 0, $limit) : null,
            'truncated' => $length > $limit,
        ];
    }

    private function researchMemoRelativePath(?string $relativePath, string $title, ?int $personId, ?int $familyId): string
    {
        $path = trim((string) $relativePath);
        if ($path === '') {
            $slug = Str::slug($title);
            if ($slug === '') {
                $slug = 'research-memo';
            }
            $slug = Str::limit($slug, 120, '');
            $target = $personId !== null
                ? 'person-'.$personId
                : ($familyId !== null ? 'family-'.$familyId : 'tree');
            $hash = substr(sha1($title.'|'.(string) $personId.'|'.(string) $familyId), 0, 8);

            $path = 'research/'.now()->format('Y/m/d').'/'.$target.'-'.$slug.'-'.$hash.'.md';
            if (strlen($path) > 240) {
                throw new \InvalidArgumentException('generated research memo path is too long.');
            }

            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim(preg_replace('#/+#', '/', $path) ?? $path, '/');
        if ($path === ''
            || str_contains($path, "\0")
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1
            || preg_match('/[^A-Za-z0-9._\/ -]/', $path) === 1
        ) {
            throw new \InvalidArgumentException('relative_path must be a safe path below the FT tree root.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                throw new \InvalidArgumentException('relative_path cannot contain blank or hidden path segments.');
            }
        }

        if (! str_ends_with(strtolower($path), '.md')) {
            $path .= '.md';
        }

        if (strlen($path) > 240) {
            throw new \InvalidArgumentException('relative_path is too long.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function sourceGapDecisionAllowedDecisions(): array
    {
        return [
            'skip_collateral_only',
            'skip_weak_evidence',
            'skip_no_direct_match',
            'needs_external_research',
            'defer_private_living',
            'media_identity_only',
            'source_found',
            'revisit_later',
        ];
    }

    private function loadFamilyForDuplicateRetire(int $familyId): ?object
    {
        return DB::selectOne(
            'SELECT id, tree_id, gedcom_id, husband_id, wife_id, marriage_date, marriage_place, divorce_date, divorce_place, annulment_date, notes FROM genealogy_families WHERE id = ?',
            [$familyId]
        );
    }

    /**
     * @return array<string, int>
     */
    private function familyReferenceCounts(int $familyId): array
    {
        $tables = [
            'children' => ['genealogy_children', 'family_id'],
            'events' => ['genealogy_family_events', 'family_id'],
            'media' => ['genealogy_family_media', 'family_id'],
            'sources' => ['genealogy_family_sources', 'family_id'],
            'citations' => ['genealogy_citations', 'family_id'],
        ];

        $counts = [];
        foreach ($tables as $key => [$table, $column]) {
            $counts[$key] = (int) (DB::selectOne("SELECT COUNT(*) AS count FROM {$table} WHERE {$column} = ?", [$familyId])->count ?? 0);
        }

        return $counts;
    }

    /**
     * @param  list<object>  $rows
     * @return list<object>
     */
    private function personMediaImportedCitationRows(int $treeId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $clauses = [];
        $bindings = [$treeId];
        foreach ($rows as $row) {
            $clauses[] = '(c.person_id = ? AND c.media_id = ?)';
            $bindings[] = (int) $row->person_id;
            $bindings[] = (int) $row->media_id;
        }

        return DB::select(
            'SELECT c.id, c.person_id, c.media_id, c.source_id, c.fact_type, s.title AS source_title
             FROM genealogy_citations c
             JOIN genealogy_sources s ON s.id = c.source_id AND s.tree_id = ?
             WHERE c.fact_type = "imported_media_association"
               AND ('.implode(' OR ', $clauses).')
             ORDER BY c.id ASC',
            $bindings
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<object>
     */
    private function loadPersonSourceRowsForRetire(array $ids, int $treeId): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return DB::select(
            "SELECT ps.id, ps.person_id, ps.source_id, ps.page, ps.quality,
                    p.given_name, p.surname, s.title AS source_title
             FROM genealogy_person_sources ps
             JOIN genealogy_persons p ON p.id = ps.person_id AND p.tree_id = ?
             JOIN genealogy_sources s ON s.id = ps.source_id AND s.tree_id = ?
             WHERE ps.id IN ({$placeholders})
             ORDER BY ps.id",
            array_merge([$treeId, $treeId], $ids)
        );
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, array{person_source_id: int, person_id: int, source_id: int, citation_count: int}>
     */
    private function personSourceCitationCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $count = (int) (DB::selectOne(
                'SELECT COUNT(*) AS count FROM genealogy_citations WHERE source_id = ? AND person_id = ?',
                [(int) $row->source_id, (int) $row->person_id]
            )->count ?? 0);

            $counts[(int) $row->id] = [
                'person_source_id' => (int) $row->id,
                'person_id' => (int) $row->person_id,
                'source_id' => (int) $row->source_id,
                'citation_count' => $count,
            ];
        }

        return $counts;
    }

    private function researchMemoContent(string $title, string $body, int $treeId, ?object $person, ?object $family, string $actor): string
    {
        $lines = [
            '# '.$title,
            '',
            'Tree ID: '.$treeId,
            'Saved: '.now()->toIso8601String(),
            'Actor: '.($actor !== '' ? $actor : 'genea-mcp'),
        ];

        if ($person !== null) {
            $personName = trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? ''));
            $lines[] = 'Person: #'.(int) $person->id.($personName !== '' ? ' '.$personName : '');
        }

        if ($family !== null) {
            $lines[] = 'Family: #'.(int) $family->id;
        }

        $lines[] = '';
        $lines[] = '## Memo';
        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function researchMemoNoteText(string $notesAppend, string $targetPath): string
    {
        $notesAppend = trim($notesAppend);
        if (! str_contains($notesAppend, $targetPath)) {
            $notesAppend .= "\nResearch memo: ".$targetPath;
        }

        return $notesAppend;
    }

    private function appendNoteText(mixed $currentNotes, string $noteText): string
    {
        $current = trim((string) $currentNotes);
        $noteText = trim($noteText);

        if ($current !== '' && str_contains($current, $noteText)) {
            return $current;
        }

        return $current === '' ? $noteText : $current."\n\n".$noteText;
    }

    private function compactToolText(string $text, int $limit): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'...' : $text;
    }

    private function normalizeLessonMemoryType(?string $lessonType): ?string
    {
        $value = Str::of((string) $lessonType)
            ->lower()
            ->replace(['-', ' '], '_')
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        return in_array($value, self::LESSON_MEMORY_TYPES, true) ? $value : null;
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  list<int>  $personIds
     * @param  list<int>  $mediaIds
     * @param  list<int>  $taskIds
     * @return array<string, array<string, mixed>>
     */
    private function validateLessonMemoryReferences(
        int $treeId,
        array $sourceIds,
        array $personIds,
        array $mediaIds,
        array $taskIds
    ): array {
        $checks = [
            'source_ids' => ['table' => 'genealogy_sources', 'ids' => $sourceIds],
            'person_ids' => ['table' => 'genealogy_persons', 'ids' => $personIds],
            'media_ids' => ['table' => 'genealogy_media', 'ids' => $mediaIds],
            'task_ids' => ['table' => 'genealogy_research_tasks', 'ids' => $taskIds],
        ];

        $errors = [];
        foreach ($checks as $key => $check) {
            $ids = $check['ids'];
            if ($ids === []) {
                continue;
            }

            $table = (string) $check['table'];
            if (! Schema::hasTable($table)) {
                $errors[$key] = [
                    'reason' => 'table_unavailable',
                    'table' => $table,
                    'ids' => $ids,
                ];

                continue;
            }

            $missing = $this->idsMissingFromTree($table, $treeId, $ids);
            if ($missing !== []) {
                $errors[$key] = [
                    'reason' => 'missing_or_wrong_tree',
                    'table' => $table,
                    'ids' => $missing,
                ];
            }
        }

        return $errors;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function idsMissingFromTree(string $table, int $treeId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT id FROM {$table} WHERE tree_id = ? AND id IN ({$placeholders})",
            array_merge([$treeId], $ids)
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row->id] = true;
        }

        return array_values(array_filter($ids, static fn (int $id): bool => ! isset($found[$id])));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLessonMemoryLookupRow(object $row): array
    {
        $payload = $this->decodeJsonField($row->fact_value ?? null);
        $payload = is_array($payload) ? $payload : [];
        $lessonPayload = $payload['payload'] ?? [];
        $lessonPayload = is_array($lessonPayload) ? $lessonPayload : [];

        return [
            'memory_id' => isset($row->memory_id) ? (int) $row->memory_id : null,
            'lesson_type' => $row->lesson_type ?? ($payload['lesson_type'] ?? null),
            'title' => $payload['title'] ?? ($row->fact_key ?? null),
            'lesson' => $this->compactToolText((string) ($payload['lesson'] ?? $row->fact_value ?? ''), 1400),
            'tags' => $lessonPayload['tags'] ?? [],
            'source_ids' => $lessonPayload['source_ids'] ?? [],
            'person_ids' => $lessonPayload['person_ids'] ?? [],
            'media_ids' => $lessonPayload['media_ids'] ?? [],
            'task_ids' => $lessonPayload['task_ids'] ?? [],
            'confidence' => isset($row->confidence) ? (float) $row->confidence : null,
            'consensus_status' => $row->consensus_status ?? null,
            'agent_ids' => array_values(array_filter(explode(',', (string) ($row->agent_ids ?? '')))),
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    /**
     * @return list<object>
     */
    private function loadLessonMemoryRows(int $treeId, string $lessonType, array $terms, int $limit): array
    {
        $where = [
            "asm.entity_type = 'genealogy_tree'",
            'asm.entity_id = ?',
        ];
        $params = [$treeId];

        if ($lessonType === 'all') {
            $where[] = 'asm.fact_type IN ('.implode(', ', array_fill(0, count(self::LESSON_MEMORY_TYPES), '?')).')';
            $params = array_merge($params, self::LESSON_MEMORY_TYPES);
        } else {
            $where[] = 'asm.fact_type = ?';
            $params[] = $lessonType;
        }

        $terms = array_slice($this->normalizeLessonContextTerms($terms), 0, 10);
        if ($terms !== []) {
            $termClauses = [];
            foreach ($terms as $term) {
                $termClauses[] = '(asm.fact_key LIKE ? OR asm.fact_value LIKE ?)';
                $like = '%'.$term.'%';
                $params[] = $like;
                $params[] = $like;
            }
            $where[] = '('.implode(' OR ', $termClauses).')';
        }

        $params[] = max(1, min(100, $limit));

        return DB::select("
            SELECT asm.id AS memory_id,
                   asm.fact_type AS lesson_type,
                   asm.fact_key,
                   asm.fact_value,
                   asm.confidence,
                   asm.consensus_status,
                   asm.updated_at,
                   GROUP_CONCAT(DISTINCT afs.agent_id ORDER BY afs.agent_id SEPARATOR ',') AS agent_ids
            FROM agent_semantic_memory asm
            LEFT JOIN agent_semantic_fact_sources afs ON afs.memory_id = asm.id
            WHERE ".implode(' AND ', $where).'
            GROUP BY asm.id, asm.fact_type, asm.fact_key, asm.fact_value, asm.confidence, asm.consensus_status, asm.updated_at
            ORDER BY asm.confidence DESC, asm.updated_at DESC, asm.id DESC
            LIMIT ?
        ', $params);
    }

    /**
     * @return list<string>
     */
    private function lessonContextTerms(?string $query): array
    {
        $query = trim((string) $query);
        if ($query === '') {
            return [];
        }

        return array_merge([$query], preg_split('/[\s,;|]+/', $query) ?: []);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function lessonContextTermsFromRow(object $row, array $fields): array
    {
        $terms = [];
        foreach ($fields as $field) {
            $value = $row->{$field} ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            $terms[] = $text;
            foreach (preg_split('/[\s,;|\/\\\\()\\[\\]{}:]+/', $text) ?: [] as $part) {
                $terms[] = $part;
            }
        }

        return $terms;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function normalizeLessonContextTerms(array $terms): array
    {
        return array_slice(array_values(array_unique(array_filter(
            array_map(function (string $term): string {
                $term = trim(preg_replace('/\s+/', ' ', Str::ascii($term)) ?? '');

                return mb_strlen($term) > 80 ? mb_substr($term, 0, 80) : $term;
            }, $terms),
            static fn (string $term): bool => $term !== '' && mb_strlen($term) >= 3
        ))), 0, 30);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function lessonContextTargetSummary(object $row, array $fields): array
    {
        $summary = ['id' => isset($row->id) ? (int) $row->id : null];
        foreach ($fields as $field) {
            $value = $row->{$field} ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $summary[$field] = $this->compactToolText((string) $value, 220);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonContextTargetError(string $field, int $id, int $treeId): array
    {
        return [
            'tool' => 'lesson_memory_context',
            'success' => false,
            'tree_id' => $treeId,
            'error' => "{$field} {$id} was not found in tree {$treeId}.",
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     */
    private function buildLessonMemoryContextText(array $lessons): ?string
    {
        if ($lessons === []) {
            return null;
        }

        $lines = [
            'Genea reusable lessons for this context:',
            'Use as process guardrails only; do not treat as source evidence.',
        ];

        foreach (array_slice($lessons, 0, 10) as $lesson) {
            $title = trim((string) ($lesson['title'] ?? 'Genea lesson'));
            $text = trim((string) ($lesson['lesson'] ?? ''));
            if ($text === '') {
                continue;
            }

            $type = trim((string) ($lesson['lesson_type'] ?? 'lesson'));
            $lines[] = '- ['.$type.'] '.$title.': '.$this->compactToolText($text, 360);
        }

        return count($lines) > 2 ? implode("\n", $lines) : null;
    }

    private function decodeJsonField(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @param  array<int, object>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function excerptCitationRows(array $citations): array
    {
        return array_map(function (object $citation): array {
            return [
                'id' => (int) ($citation->id ?? 0),
                'source_id' => isset($citation->source_id) ? (int) $citation->source_id : null,
                'source_title' => $citation->source_title ?? null,
                'person_id' => isset($citation->person_id) ? (int) $citation->person_id : null,
                'family_id' => isset($citation->family_id) ? (int) $citation->family_id : null,
                'fact_type' => $citation->fact_type ?? null,
                'page' => $citation->page ?? null,
                'quality' => isset($citation->quality) ? (int) $citation->quality : null,
                'evidence_type' => $citation->evidence_type ?? null,
                'information_type' => $citation->information_type ?? null,
                'evidence_analysis' => $this->textProfile((string) ($citation->evidence_analysis ?? ''), 800),
                'text' => $this->textProfile((string) ($citation->text ?? ''), 800),
                'created_at' => $citation->created_at ?? null,
            ];
        }, $citations);
    }

    /**
     * @param  array<int, object>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function excerptSourceCitationRows(array $citations): array
    {
        return array_map(function (object $citation): array {
            return [
                'id' => (int) ($citation->id ?? 0),
                'source_id' => isset($citation->source_id) ? (int) $citation->source_id : null,
                'person' => [
                    'person_id' => isset($citation->person_id) ? (int) $citation->person_id : null,
                    'name' => $citation->person_name ?? null,
                    'birth_date' => $citation->person_birth_date ?? null,
                    'death_date' => $citation->person_death_date ?? null,
                ],
                'family' => [
                    'family_id' => isset($citation->family_id) ? (int) $citation->family_id : null,
                    'husband_id' => isset($citation->husband_id) ? (int) $citation->husband_id : null,
                    'wife_id' => isset($citation->wife_id) ? (int) $citation->wife_id : null,
                    'husband_name' => $citation->husband_name ?? null,
                    'wife_name' => $citation->wife_name ?? null,
                    'marriage_date' => $citation->marriage_date ?? null,
                    'marriage_place' => $citation->marriage_place ?? null,
                ],
                'media' => [
                    'media_id' => isset($citation->media_id) ? (int) $citation->media_id : null,
                    'title' => $citation->media_title ?? null,
                    'media_type' => $citation->media_type ?? null,
                    'media_date' => $citation->media_date ?? null,
                    'nextcloud_path' => $citation->media_nextcloud_path ?? null,
                    'local_filename' => $citation->media_local_filename ?? null,
                    'file_exists' => isset($citation->media_file_exists) ? (bool) $citation->media_file_exists : null,
                    'rag_status' => $this->mediaRagStatus((object) [
                        'rag_indexed_at' => $citation->media_rag_indexed_at ?? null,
                        'updated_at' => $citation->media_updated_at ?? null,
                    ]),
                ],
                'fact_type' => $citation->fact_type ?? null,
                'page' => $citation->page ?? null,
                'quality' => isset($citation->quality) ? (int) $citation->quality : null,
                'evidence_type' => $citation->evidence_type ?? null,
                'information_type' => $citation->information_type ?? null,
                'conclusion_id' => isset($citation->conclusion_id) ? (int) $citation->conclusion_id : null,
                'evidence_analysis' => $this->textProfile((string) ($citation->evidence_analysis ?? ''), 800),
                'text' => $this->textProfile((string) ($citation->text ?? ''), 800),
                'created_at' => $citation->created_at ?? null,
            ];
        }, $citations);
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        } elseif (! is_array($value)) {
            $value = [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /**
     * @return list<int>
     */
    private function normalizeProposalIdList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        } elseif (! is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): int => (int) $item, $value),
            static fn (int $item): bool => $item > 0
        )));
    }

    /**
     * @param  list<int>  $proposalIds
     * @return array<int, object>
     */
    private function loadPersonFactApplyCandidates(int $treeId, array $proposalIds, int $limit, float $minimumConfidence): array
    {
        $baseSql = "SELECT pc.*,
                           TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS person_name,
                           p.tree_id AS person_tree_id
                    FROM genealogy_proposed_changes pc
                    JOIN genealogy_persons p ON p.id = pc.person_id
                    WHERE pc.tree_id = ?
                      AND p.tree_id = pc.tree_id";

        if ($proposalIds !== []) {
            $ids = array_slice($proposalIds, 0, 50);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            return DB::select(
                "{$baseSql}
                   AND pc.id IN ({$placeholders})
                 ORDER BY pc.id ASC
                 LIMIT ?",
                array_merge([$treeId], $ids, [$limit])
            );
        }

        return DB::select(
            "{$baseSql}
               AND pc.change_type = 'fact_update'
               AND pc.status = 'approved'
               AND pc.confidence >= ?
               AND COALESCE(pc.evidence_sources, '') <> ''
             ORDER BY pc.confidence DESC, pc.updated_at ASC, pc.id ASC
             LIMIT ?",
            [$treeId, $minimumConfidence, $limit]
        );
    }

    private function buildPersonFactApplyPreviewRow(object $proposal, float $minimumConfidence): array
    {
        $sources = $this->proposalEvidenceSourceList($proposal->evidence_sources ?? null);
        $confidence = isset($proposal->confidence) ? (float) $proposal->confidence : 0.0;
        $blockers = [];

        if ((string) ($proposal->status ?? '') !== 'approved') {
            $blockers[] = 'status_not_approved';
        }

        if ((string) ($proposal->change_type ?? '') !== 'fact_update') {
            $blockers[] = 'not_fact_update';
        }

        if (! in_array((string) ($proposal->field_name ?? ''), self::FACT_UPDATE_FIELDS, true)) {
            $blockers[] = 'field_not_allowed';
        }

        if ($confidence < $minimumConfidence) {
            $blockers[] = 'below_minimum_confidence';
        }

        if (! $this->proposalHasSourceBackedEvidence($sources)) {
            $blockers[] = 'not_source_backed';
        }

        if ((int) ($proposal->tree_id ?? 0) !== (int) ($proposal->person_tree_id ?? 0)) {
            $blockers[] = 'person_tree_mismatch';
        }

        if (trim((string) ($proposal->proposed_value ?? '')) === '') {
            $blockers[] = 'blank_proposed_value';
        }

        return [
            'proposal_id' => (int) $proposal->id,
            'tree_id' => (int) ($proposal->tree_id ?? 0),
            'person_id' => isset($proposal->person_id) ? (int) $proposal->person_id : null,
            'person_name' => $proposal->person_name ?? null,
            'change_type' => $proposal->change_type ?? null,
            'field_name' => $proposal->field_name ?? null,
            'current_value' => $proposal->current_value ?? null,
            'proposed_value' => $proposal->proposed_value ?? null,
            'status' => $proposal->status ?? null,
            'confidence' => $confidence,
            'evidence_sources' => $sources,
            'evidence_summary' => $proposal->evidence_summary ?? null,
            'eligible' => $blockers === [],
            'blockers' => $blockers,
            'rollback_hint' => "If applied incorrectly, restore person {$proposal->person_id} field {$proposal->field_name} to the preview current_value and inspect genealogy_proposed_changes.id={$proposal->id}.",
        ];
    }

    /**
     * @return list<string>
     */
    private function proposalEvidenceSourceList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = preg_split('/[\n,|]+/', $value) ?: [];
            }
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($source): string => trim((string) $source), $value),
            static fn (string $source): bool => $source !== ''
        )));
    }

    /**
     * @param  list<string>  $sources
     */
    private function proposalHasSourceBackedEvidence(array $sources): bool
    {
        foreach ($sources as $source) {
            $source = strtolower($source);
            if (str_starts_with($source, 'genealogy_media:')
                || str_starts_with($source, 'genealogy_source:')
                || str_starts_with($source, 'media:')
                || str_starts_with($source, 'source:')
                || str_starts_with($source, 'http://')
                || str_starts_with($source, 'https://')
            ) {
                return true;
            }
        }

        return false;
    }

    private function recordProposalDecisionMemory(
        int $treeId,
        string $proposalType,
        int $proposalId,
        object $proposal,
        string $decision,
        array $result,
        string $actor
    ): ?int {
        if (! (bool) ($result['success'] ?? false)) {
            return null;
        }

        try {
            if (! Schema::hasTable('agent_semantic_memory') || ! Schema::hasTable('agent_semantic_fact_sources')) {
                return null;
            }

            return app(GenealogySemanticMemoryService::class)->recordReviewDecision(
                $treeId,
                $proposalType,
                $proposalId,
                $decision,
                [
                    'status_before_apply' => $proposal->status ?? null,
                    'person_id' => isset($proposal->person_id) ? (int) $proposal->person_id : null,
                    'related_person_id' => isset($proposal->related_person_id) ? (int) $proposal->related_person_id : null,
                    'field_name' => $proposal->field_name ?? null,
                    'relationship_type' => $proposal->relationship_type ?? null,
                    'proposed_value' => $this->limitMemoryString($proposal->proposed_value ?? $proposal->proposed_name ?? null),
                    'evidence_summary' => $this->limitMemoryString($proposal->evidence_summary ?? null),
                    'evidence_sources' => $this->limitMemoryString($proposal->evidence_sources ?? null),
                    'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
                    'result' => [
                        'success' => (bool) ($result['success'] ?? false),
                        'message' => $this->limitMemoryString($result['message'] ?? null, 500),
                        'link_id' => $result['link_id'] ?? null,
                        'family_id' => $result['family_id'] ?? null,
                        'media_id' => $result['media_id'] ?? null,
                    ],
                ],
                $actor !== '' ? $actor : null,
                isset($proposal->confidence) ? (float) $proposal->confidence : 0.75
            );
        } catch (\Throwable $e) {
            Log::debug('GenealogyMCPService: proposal decision memory write skipped', [
                'tree_id' => $treeId,
                'proposal_type' => $proposalType,
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{success: bool, row?: object, target_ref?: string, error?: string}
     */
    private function resolveGenealogyReviewPacketRow(string $targetRefOrToken, bool $pendingOnly = false): array
    {
        $value = trim($targetRefOrToken);
        if ($value === '') {
            return ['success' => false, 'error' => 'target_ref_or_token is required.'];
        }

        if (! Schema::hasTable('agent_review_queue')) {
            return ['success' => false, 'error' => 'agent_review_queue table is not available.'];
        }

        $targetReferences = app(ReviewTargetReferenceService::class);
        $normalized = $targetReferences->normalize($value, [GenealogyReviewPacketAdapterService::REVIEW_TYPE]);

        if ($normalized !== null) {
            $query = DB::table('agent_review_queue')
                ->where('review_type', GenealogyReviewPacketAdapterService::REVIEW_TYPE);

            if ($pendingOnly) {
                $query->where('status', 'pending')
                    ->where(function ($nested): void {
                        $nested->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    });
            }

            $rows = $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5000)
                ->get();

            foreach ($rows as $row) {
                $rowTargetRef = $targetReferences->forReviewRow(
                    $row,
                    GenealogyReviewPacketAdapterService::REVIEW_TYPE,
                    isset($row->finding_type) && is_scalar($row->finding_type) ? (string) $row->finding_type : null
                );

                if ($rowTargetRef === $normalized['target_ref']) {
                    return [
                        'success' => true,
                        'row' => $row,
                        'target_ref' => $rowTargetRef,
                    ];
                }
            }

            return [
                'success' => false,
                'error' => $pendingOnly
                    ? 'Pending genealogy review packet not found for target_ref.'
                    : 'Genealogy review packet not found for target_ref.',
            ];
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value) !== 1) {
            return ['success' => false, 'error' => 'Invalid target_ref_or_token format.'];
        }

        $query = DB::table('agent_review_queue')
            ->where('review_type', GenealogyReviewPacketAdapterService::REVIEW_TYPE)
            ->where('token', $value);

        if ($pendingOnly) {
            $query->where('status', 'pending')
                ->where(function ($nested): void {
                    $nested->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                });
        }

        $row = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return [
                'success' => false,
                'error' => $pendingOnly
                    ? 'Pending genealogy review packet not found for token.'
                    : 'Genealogy review packet not found for token.',
            ];
        }

        return [
            'success' => true,
            'row' => $row,
            'target_ref' => $targetReferences->forReviewRow(
                $row,
                GenealogyReviewPacketAdapterService::REVIEW_TYPE,
                isset($row->finding_type) && is_scalar($row->finding_type) ? (string) $row->finding_type : null
            ),
        ];
    }

    private function normalizeReviewPacketDecisionAction(string $action): ?string
    {
        $normalized = strtolower(trim($action));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'mark_reviewed', 'reviewed', 'review', 'mark_reviewed_preview_only' => 'mark_reviewed',
            'approve', 'approved' => 'approve',
            'reject', 'rejected' => 'reject',
            'clarify', 'clarification_requested', 'request_clarification' => 'clarify',
            'defer', 'deferred' => 'defer',
            default => null,
        };
    }

    private function compactReviewPacketPerson(mixed $person): ?array
    {
        if (is_object($person)) {
            $person = (array) $person;
        }

        if (! is_array($person) || $person === []) {
            return null;
        }

        $given = $this->limitMemoryString($person['given_name'] ?? null, 120);
        $surname = $this->limitMemoryString($person['surname'] ?? null, 120);
        $name = $this->limitMemoryString($person['name'] ?? null, 240);
        if ($name === null) {
            $name = trim((string) ($given ?? '').' '.(string) ($surname ?? '')) ?: null;
        }

        return [
            'id' => isset($person['id']) && is_numeric($person['id']) ? (int) $person['id'] : null,
            'name' => $name,
            'given_name' => $given,
            'surname' => $surname,
            'birth_date' => $this->limitMemoryString($person['birth_date'] ?? null, 120),
            'birth_place' => $this->limitMemoryString($person['birth_place'] ?? null, 240),
            'death_date' => $this->limitMemoryString($person['death_date'] ?? null, 120),
            'death_place' => $this->limitMemoryString($person['death_place'] ?? null, 240),
            'living' => $person['living'] ?? null,
        ];
    }

    private function compactReviewPacketMap(mixed $value, int $jsonLimit = 1600): ?array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (is_string($json) && strlen($json) > $jsonLimit) {
            return [
                'truncated' => true,
                'json_excerpt' => $this->limitMemoryString($json, $jsonLimit),
            ];
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compactReviewPacketClaims(mixed $claims, int $limit = 10): array
    {
        if (! is_array($claims)) {
            return [];
        }

        $rows = [];
        foreach ($claims as $claim) {
            if (is_object($claim)) {
                $claim = (array) $claim;
            }
            if (! is_array($claim)) {
                continue;
            }

            $rows[] = [
                'index' => isset($claim['index']) && is_numeric($claim['index']) ? (int) $claim['index'] : count($rows),
                'person_id' => isset($claim['person_id']) && is_numeric($claim['person_id']) ? (int) $claim['person_id'] : null,
                'field_name' => $this->limitMemoryString($claim['field_name'] ?? null, 120),
                'change_type' => $this->limitMemoryString($claim['change_type'] ?? null, 120),
                'relationship_type' => $this->limitMemoryString($claim['relationship_type'] ?? null, 120),
                'claim' => $this->limitMemoryString($claim['claim'] ?? $claim['claim_text'] ?? $claim['text'] ?? null, 500),
                'proposed_value' => $this->limitMemoryString($claim['proposed_value'] ?? null, 300),
                'source_ref' => $this->limitMemoryString($claim['source_ref'] ?? $claim['source_locator'] ?? null, 300),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compactReviewPacketSources(mixed $sources, int $limit = 10): array
    {
        if (! is_array($sources)) {
            return [];
        }

        $rows = [];
        foreach ($sources as $source) {
            if (is_object($source)) {
                $source = (array) $source;
            }
            if (! is_array($source)) {
                continue;
            }

            $rows[] = [
                'label' => $this->limitMemoryString($source['label'] ?? $source['title'] ?? $source['name'] ?? null, 240),
                'locator' => $this->limitMemoryString($source['locator'] ?? $source['url'] ?? $source['path'] ?? null, 500),
                'source_ref' => $this->limitMemoryString($source['source_ref'] ?? null, 240),
                'access_class' => $this->limitMemoryString($source['access_class'] ?? null, 120),
                'citation' => $this->limitMemoryString($source['citation'] ?? null, 500),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function latestReviewPacketDecision(mixed $decisionLog): ?array
    {
        if (! is_array($decisionLog) || $decisionLog === []) {
            return null;
        }

        $latest = $decisionLog[array_key_last($decisionLog)];
        if (is_object($latest)) {
            $latest = (array) $latest;
        }
        if (! is_array($latest)) {
            return null;
        }

        return [
            'action' => $this->limitMemoryString($latest['action'] ?? null, 160),
            'actor' => $this->limitMemoryString($latest['actor'] ?? null, 160),
            'notes' => $this->limitMemoryString($latest['notes'] ?? null, 500),
            'reason_code' => $this->limitMemoryString($latest['reason_code'] ?? ($latest['meta']['reason_code'] ?? null), 120),
            'created_at' => $latest['created_at'] ?? $latest['timestamp'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactReviewPacketDetails(mixed $details): array
    {
        if (is_object($details)) {
            $details = (array) $details;
        }
        if (! is_array($details)) {
            return [];
        }

        return [
            'schema' => $this->limitMemoryString($details['schema'] ?? null, 120),
            'packet_key' => $this->limitMemoryString($details['packet_key'] ?? null, 240),
            'packet_label' => $this->limitMemoryString($details['packet_label'] ?? null, 240),
            'packet_status' => $this->limitMemoryString($details['packet_status'] ?? null, 120),
            'source_locator' => $this->limitMemoryString($details['source_locator'] ?? null, 500),
            'source_locators' => array_slice(is_array($details['source_locators'] ?? null) ? $details['source_locators'] : [], 0, 10),
            'identity' => $this->compactReviewPacketMap($details['identity'] ?? null, 1200),
            'privacy' => $this->compactReviewPacketMap($details['privacy'] ?? null, 800),
            'sprint' => $this->compactReviewPacketMap($details['sprint'] ?? null, 1200),
            'validation' => $this->compactReviewPacketMap($details['validation'] ?? null, 1400),
            'apply_preview' => $this->compactReviewPacketMap($details['apply_preview'] ?? null, 1600),
            'claims' => $this->compactReviewPacketClaims($details['claims'] ?? []),
            'sources' => $this->compactReviewPacketSources($details['sources'] ?? []),
            'decision_log_count' => is_array($details['decision_log'] ?? null) ? count($details['decision_log']) : 0,
            'latest_decision' => $this->latestReviewPacketDecision($details['decision_log'] ?? []),
        ];
    }

    private function limitMemoryString(mixed $value, int $limit = 1000): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return mb_strlen($string) > $limit ? mb_substr($string, 0, $limit) : $string;
    }

    /**
     * @param  array<string, mixed>  $affectedRecords
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $extra
     */
    private function logGenealogyWriteAudit(
        string $tool,
        string $operation,
        string $actor,
        bool $success,
        array $affectedRecords,
        array $evidence,
        string $rollbackHint,
        array $extra = []
    ): void {
        Log::info('GenealogyMCPService: write audit', array_merge([
            'audit_type' => 'genealogy_mcp_write',
            'tool' => $tool,
            'operation' => $operation,
            'actor' => $actor !== '' ? $actor : 'unknown',
            'success' => $success,
            'affected_records' => $affectedRecords,
            'evidence' => $evidence,
            'rollback_hint' => $rollbackHint,
        ], $extra));
    }

    private function normalizeApplyProposalType(?string $proposalType): ?string
    {
        $value = strtolower(trim((string) $proposalType));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'change', 'person_change', 'proposed_change', 'genealogy_proposed_changes' => 'change',
            'relationship', 'proposed_relationship', 'genealogy_proposed_relationships' => 'relationship',
            default => null,
        };
    }

    private function normalizeRequiredTreeId(?int $treeId): ?int
    {
        if ($treeId === null || $treeId < 1) {
            return null;
        }

        return $treeId;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function normalizePositiveIdList(array $values, int $limit): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        $ids = array_values($ids);
        sort($ids);

        return array_slice($ids, 0, max(1, $limit));
    }

    private function treeIdRequiredResponse(string $tool): array
    {
        return [
            'tool' => $tool,
            'success' => false,
            'error' => 'tree_id is required and must be a positive integer for genealogy write-capable tools.',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{success: bool, proposal_type?: string, proposal?: object, error?: string}
     */
    private function resolveApplyProposal(int $proposalId, ?string $proposalType, int $treeId): array
    {
        $change = null;
        $relationship = null;

        if ($proposalType === null || $proposalType === 'change') {
            $change = DB::selectOne(
                "SELECT pc.*,
                        TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS person_name
                 FROM genealogy_proposed_changes pc
                 LEFT JOIN genealogy_persons p ON p.id = pc.person_id
                 WHERE pc.id = ?
                   AND pc.tree_id = ?",
                [$proposalId, $treeId]
            );
        }

        if ($proposalType === null || $proposalType === 'relationship') {
            $relationship = DB::selectOne(
                "SELECT pr.*,
                        TRIM(CONCAT(COALESCE(p.given_name, ''), ' ', COALESCE(p.surname, ''))) AS anchor_person_name
                 FROM genealogy_proposed_relationships pr
                 LEFT JOIN genealogy_persons p ON p.id = pr.person_id
                 WHERE pr.id = ?
                   AND pr.tree_id = ?",
                [$proposalId, $treeId]
            );
        }

        if ($proposalType === null && $change && $relationship) {
            return [
                'success' => false,
                'error' => 'Proposal ID exists in both change and relationship proposal tables; provide proposal_type.',
            ];
        }

        if ($change) {
            return ['success' => true, 'proposal_type' => 'change', 'proposal' => $change];
        }

        if ($relationship) {
            return ['success' => true, 'proposal_type' => 'relationship', 'proposal' => $relationship];
        }

        return ['success' => false, 'error' => "Proposal {$proposalId} not found in tree {$treeId}."];
    }

    private function buildApplyApprovedProposalPreview(string $proposalType, object $proposal): array
    {
        if ($proposalType === 'change') {
            $familyMediaPayload = $this->familyMediaProposalPayload($proposal);
            if ($familyMediaPayload !== null) {
                return [
                    'target_table' => 'genealogy_family_media',
                    'operation' => 'would_apply_family_media_link',
                    'proposal_id' => (int) $proposal->id,
                    'tree_id' => (int) ($proposal->tree_id ?? 0),
                    'anchor_person_id' => (int) ($proposal->person_id ?? 0),
                    'anchor_person_name' => $proposal->person_name ?? null,
                    'family_id' => $familyMediaPayload['family_id'],
                    'media_id' => $familyMediaPayload['media_id'],
                    'change_type' => $proposal->change_type ?? null,
                    'field_name' => $proposal->field_name ?? null,
                    'proposed_value' => $proposal->proposed_value ?? null,
                    'evidence_summary' => $proposal->evidence_summary ?? null,
                    'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
                    'agent_id' => $proposal->agent_id ?? null,
                    'status' => $proposal->status ?? null,
                ];
            }

            return [
                'target_table' => 'genealogy_proposed_changes',
                'operation' => 'would_apply_person_change',
                'proposal_id' => (int) $proposal->id,
                'tree_id' => (int) ($proposal->tree_id ?? 0),
                'person_id' => (int) ($proposal->person_id ?? 0),
                'person_name' => $proposal->person_name ?? null,
                'change_type' => $proposal->change_type ?? null,
                'field_name' => $proposal->field_name ?? null,
                'current_value' => $proposal->current_value ?? null,
                'proposed_value' => $proposal->proposed_value ?? null,
                'evidence_summary' => $proposal->evidence_summary ?? null,
                'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
                'agent_id' => $proposal->agent_id ?? null,
                'status' => $proposal->status ?? null,
            ];
        }

        return [
            'target_table' => 'genealogy_proposed_relationships',
            'operation' => 'would_apply_relationship_proposal',
            'proposal_id' => (int) $proposal->id,
            'tree_id' => (int) ($proposal->tree_id ?? 0),
            'person_id' => (int) ($proposal->person_id ?? 0),
            'anchor_person_name' => $proposal->anchor_person_name ?? null,
            'related_person_id' => isset($proposal->related_person_id) ? (int) $proposal->related_person_id : null,
            'relationship_type' => $proposal->relationship_type ?? null,
            'proposal_mode' => $proposal->proposal_mode ?? null,
            'proposed_name' => $proposal->proposed_name ?? null,
            'evidence_summary' => $proposal->evidence_summary ?? null,
            'confidence' => isset($proposal->confidence) ? (float) $proposal->confidence : null,
            'agent_id' => $proposal->agent_id ?? null,
            'status' => $proposal->status ?? null,
        ];
    }
}
