<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use App\Services\ContentExtractionService;
use App\Services\Genealogy\Support\GenealogyDocumentExtensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N140 — Media → AI Vetting → Person Enrichment Pipeline
 *
 * End-to-end pipeline from genealogy_media to human-review proposals.
 *
 * Stages:
 *   1. Vetting   — select eligible document-type media
 *   2. Extraction — AI vision produces structured JSON facts
 *   3. Matching  — extracted names matched to genealogy_persons
 *   4. Proposals — facts written to genealogy_proposed_changes;
 *                  unmatched persons → genealogy_proposed_relationships
 *   5. Coverage  — SearchCoverageService updated per matched person
 *
 * Confidence floor: 0.65 (AI-assessed). Below this no proposals are created.
 * Nothing auto-applies — all proposals route to human review.
 * Tree-isolated: all queries scoped to tree_id.
 */
class GenealogyMediaEnrichmentService
{
    private const CONFIDENCE_FLOOR = 0.65;

    private const DOCUMENT_TYPES = ['obituary', 'census', 'certificate', 'document', 'military', 'headstone'];

    private const AGENT_ID = 'genealogy-media-enrichment';

    // GEDCOM-mappable fact fields → [change_type, field_name]
    private const GEDCOM_MAP = [
        'birth_date' => ['event_add',   'birth_date'],
        'birth_place' => ['event_add',   'birth_place'],
        'birth_year' => ['event_add',   'birth_date'],
        'death_date' => ['event_add',   'death_date'],
        'death_place' => ['event_add',   'death_place'],
        'death_year' => ['event_add',   'death_date'],
        'burial_place' => ['event_add',   'burial_place'],
        'marriage_date' => ['event_add',   'marriage_date'],
        'marriage_place' => ['event_add',   'marriage_place'],
        'occupation' => ['event_add',   'occupation'],
        'residence' => ['residence_add', 'residence'],
        'immigration_date' => ['event_add',   'immigration_date'],
        'immigration_place' => ['event_add',   'immigration_place'],
        'military_branch' => ['event_add',   'military_branch'],
        'military_rank' => ['event_add',   'military_rank'],
        'enlistment_date' => ['event_add',   'enlistment_date'],
        'discharge_date' => ['event_add',   'discharge_date'],
        'nationality' => ['fact_update', 'nationality'],
        'religion' => ['fact_update', 'religion'],
    ];

    private ?AIService $aiService = null;

    private ?ContentExtractionService $contentExtractionService = null;

    private ?GenealogyLocalDocumentWorkerService $localDocumentWorker = null;

    private ?GenealogyLocalMatchTriageService $localMatchTriage = null;

    private ?GenealogyLocalSourceQualityService $localSourceQuality = null;

    private ?GenealogyPacketIntakeOrchestratorService $packetIntakeOrchestrator = null;

    private ?GenealogyDocumentTextQualityGateService $textQualityGate = null;

    private ?NameVariantService $nameVariantService = null;

    private ?SearchCoverageService $coverageService = null;

    private ?GenealogyLessonPromptContextService $lessonPromptContext = null;

    private function ai(): AIService
    {
        if (! $this->aiService) {
            $this->aiService = app(AIService::class);
        }

        return $this->aiService;
    }

    private function contentExtraction(): ContentExtractionService
    {
        return $this->contentExtractionService ??= app(ContentExtractionService::class);
    }

    /**
     * Test seam: allow a mocked ContentExtractionService to be injected.
     */
    public function setContentExtractionService(ContentExtractionService $service): void
    {
        $this->contentExtractionService = $service;
    }

    private function names(): NameVariantService
    {
        if (! $this->nameVariantService) {
            $this->nameVariantService = app(NameVariantService::class);
        }

        return $this->nameVariantService;
    }

    private function localWorker(): GenealogyLocalDocumentWorkerService
    {
        if (! $this->localDocumentWorker) {
            $this->localDocumentWorker = app(GenealogyLocalDocumentWorkerService::class);
        }

        return $this->localDocumentWorker;
    }

    private function coverage(): SearchCoverageService
    {
        if (! $this->coverageService) {
            $this->coverageService = app(SearchCoverageService::class);
        }

        return $this->coverageService;
    }

    private function matchTriage(): GenealogyLocalMatchTriageService
    {
        if (! $this->localMatchTriage) {
            $this->localMatchTriage = app(GenealogyLocalMatchTriageService::class);
        }

        return $this->localMatchTriage;
    }

    private function sourceQuality(): GenealogyLocalSourceQualityService
    {
        if (! $this->localSourceQuality) {
            $this->localSourceQuality = app(GenealogyLocalSourceQualityService::class);
        }

        return $this->localSourceQuality;
    }

    private function packetIntake(): GenealogyPacketIntakeOrchestratorService
    {
        if (! $this->packetIntakeOrchestrator) {
            $this->packetIntakeOrchestrator = app(GenealogyPacketIntakeOrchestratorService::class);
        }

        return $this->packetIntakeOrchestrator;
    }

    private function textQualityGate(): GenealogyDocumentTextQualityGateService
    {
        return $this->textQualityGate ??= app(GenealogyDocumentTextQualityGateService::class);
    }

    private function lessonContext(): GenealogyLessonPromptContextService
    {
        return $this->lessonPromptContext ??= app(GenealogyLessonPromptContextService::class);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process a single media record through the full pipeline.
     *
     * @param  int  $mediaId  genealogy_media.id
     * @return array ['success'=>bool, 'proposals'=>int, 'relationships'=>int, 'skipped'=>bool, 'reason'=>string]
     */
    public function previewMediaIntakePacket(int $mediaId): array
    {
        $media = DB::selectOne(
            'SELECT gm.*, gp.tree_id AS person_tree_id
             FROM genealogy_media gm
             LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
             LEFT JOIN genealogy_persons gp ON gp.id = gpm.person_id
             WHERE gm.id = ?
             LIMIT 1',
            [$mediaId]
        );

        if (! $media) {
            return ['success' => false, 'reason' => 'not_found'];
        }

        $media = (array) $media;
        $extraction = $this->extractStructuredFacts($media);
        if ($extraction === []) {
            return ['success' => false, 'reason' => 'extraction_failed'];
        }

        $treeId = (int) ($media['tree_id'] ?? $media['person_tree_id'] ?? 0);

        $packet = $this->packetIntake()->orchestratePacket([
            [
                'page_number' => 1,
                'summary' => $this->buildPreviewSummary($media, $extraction),
                'persons' => (array) ($extraction['persons'] ?? []),
            ],
        ], [
            'title' => $media['title'] ?? 'Genealogy document',
            'tree_id' => $treeId ?: null,
            'family_tree_id' => $treeId ?: null,
            'media_id' => $mediaId,
            'media_type' => $media['media_type'] ?? 'document',
            'ft_candidates' => $treeId > 0 ? $this->buildPreviewTreeCandidates($treeId, $extraction) : [],
        ]);

        return [
            'success' => true,
            'media_id' => $mediaId,
            'tree_id' => $treeId ?: null,
            'title' => $media['title'] ?? 'Genealogy document',
            'media_type' => $media['media_type'] ?? 'document',
            'extraction_confidence' => $extraction['confidence'] ?? 0.0,
            'packet' => $packet,
        ];
    }

    public function processMedia(int $mediaId): array
    {
        $media = DB::selectOne(
            'SELECT gm.*, gp.tree_id AS person_tree_id
             FROM genealogy_media gm
             LEFT JOIN genealogy_person_media gpm ON gpm.media_id = gm.id
             LEFT JOIN genealogy_persons gp ON gp.id = gpm.person_id
             WHERE gm.id = ?
             LIMIT 1',
            [$mediaId]
        );

        if (! $media) {
            return ['success' => false, 'skipped' => false, 'reason' => 'not_found'];
        }

        $media = (array) $media;

        if (! $this->isEligible($media)) {
            $this->markStatus($mediaId, 'skipped');

            return ['success' => true, 'skipped' => true, 'reason' => 'not_eligible', 'proposals' => 0, 'relationships' => 0];
        }

        $this->markStatus($mediaId, 'processing');

        try {
            // Stage 2: Extract structured facts via AI vision
            $extraction = $this->extractStructuredFacts($media);

            if (empty($extraction) || ($extraction['confidence'] ?? 0) < self::CONFIDENCE_FLOOR) {
                $reason = empty($extraction) ? 'extraction_failed' : 'below_confidence_floor';
                $this->markStatus($mediaId, 'skipped', $reason);

                return ['success' => true, 'skipped' => true, 'reason' => $reason, 'proposals' => 0, 'relationships' => 0];
            }

            $qualityAssessment = $this->sourceQuality()->assess($extraction, $media);
            if (! ($qualityAssessment['allow_proposals'] ?? false)) {
                $reason = 'source_quality:'.($qualityAssessment['label'] ?? 'weak');
                $this->markStatus($mediaId, 'skipped', $reason);

                Log::info('GenealogyMediaEnrichmentService: source quarantined before proposals', [
                    'media_id' => $mediaId,
                    'assessment' => $qualityAssessment,
                ]);

                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => $reason,
                    'proposals' => 0,
                    'relationships' => 0,
                ];
            }

            $treeId = (int) ($media['tree_id'] ?? $media['person_tree_id']);
            if (! $treeId) {
                $this->markStatus($mediaId, 'skipped', 'no_tree_id');

                return ['success' => true, 'skipped' => true, 'reason' => 'no_tree_id', 'proposals' => 0, 'relationships' => 0];
            }

            // Stage 3: Match extracted names to genealogy_persons
            $personMatches = $this->matchPersonsToTree($extraction['persons'] ?? [], $treeId);

            // Stage 4: Generate proposals
            $proposalCount = 0;
            $relationshipCount = 0;

            foreach ($personMatches['matched'] as $match) {
                $proposalCount += $this->generateProposalsForPerson(
                    (int) $match['person_id'],
                    $mediaId,
                    $treeId,
                    $match['facts'] ?? [],
                    $media,
                    $extraction
                );

                // Link media to this person
                $this->linkMediaToPerson((int) $match['person_id'], $mediaId);

                // Stage 5: Update search coverage
                $repoType = $this->mediaTypeToRepoType($media['media_type']);
                $this->coverage()->updateCoverage(
                    (int) $match['person_id'],
                    $repoType,
                    $media['title'] ?? 'Document (N140 pipeline)',
                    true,
                    "Extracted via AI from media ID {$mediaId}"
                );
            }

            // Link media to family if multiple persons from same family matched
            if (count($personMatches['matched']) > 1) {
                $this->linkMediaToFamily($personMatches['matched'], $mediaId, $treeId);
            }

            // Unmatched persons → proposed relationships
            $anchorPersonId = $personMatches['matched'][0]['person_id'] ?? null;
            foreach ($personMatches['unmatched'] as $unmatched) {
                if ($anchorPersonId && ($unmatched['confidence'] ?? 0) >= self::CONFIDENCE_FLOOR) {
                    $this->createRelationshipProposal((int) $anchorPersonId, $unmatched, $treeId, $mediaId, $extraction);
                    $relationshipCount++;
                }
            }

            $this->markStatus($mediaId, 'completed');

            Log::info('GenealogyMediaEnrichmentService: media processed', [
                'media_id' => $mediaId,
                'tree_id' => $treeId,
                'proposals' => $proposalCount,
                'relationships' => $relationshipCount,
                'matched' => count($personMatches['matched']),
                'unmatched' => count($personMatches['unmatched']),
            ]);

            return [
                'success' => true,
                'skipped' => false,
                'proposals' => $proposalCount,
                'relationships' => $relationshipCount,
                'matched' => count($personMatches['matched']),
                'unmatched' => count($personMatches['unmatched']),
            ];

        } catch (\Exception $e) {
            $this->markStatus($mediaId, 'failed', substr($e->getMessage(), 0, 500));
            Log::error('GenealogyMediaEnrichmentService: pipeline failed', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'skipped' => false, 'reason' => $e->getMessage(), 'proposals' => 0, 'relationships' => 0];
        }
    }

    /**
     * Batch process eligible media for a tree.
     *
     * @param  bool  $dryRun  Return eligible records without processing
     */
    public function processBatch(int $treeId, int $limit = 10, bool $dryRun = false): array
    {
        $typePlaceholders = implode(',', array_fill(0, count(self::DOCUMENT_TYPES), '?'));
        $params = array_merge(self::DOCUMENT_TYPES, [$treeId, $limit]);
        $hasTranscriptSql = "((gm.transcription_text IS NOT NULL AND TRIM(gm.transcription_text) <> '')
              OR (gm.transcription IS NOT NULL AND TRIM(gm.transcription) <> ''))";

        $records = DB::select("
            SELECT gm.id, gm.media_type, gm.title, gm.nextcloud_path,
                   gm.analysis_status, gm.enrichment_status
            FROM genealogy_media gm
            WHERE gm.media_type IN ({$typePlaceholders})
              AND gm.tree_id = ?
              AND gm.file_exists = 1
              AND (gm.analysis_status = 'completed' OR {$hasTranscriptSql})
              AND (gm.enrichment_status IS NULL OR gm.enrichment_status = 'failed')
            ORDER BY
              FIELD(gm.media_type, 'census','certificate','obituary','military','headstone','document'),
              gm.id ASC
            LIMIT ?
        ", $params);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'eligible' => count($records),
                'records' => array_map(fn ($r) => ['id' => $r->id, 'type' => $r->media_type, 'title' => $r->title], $records),
            ];
        }

        $results = ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'proposals' => 0, 'relationships' => 0, 'log' => []];

        foreach ($records as $record) {
            $result = $this->processMedia((int) $record->id);

            if (! $result['success']) {
                $results['errors']++;
            } elseif ($result['skipped']) {
                $results['skipped']++;
            } else {
                $results['processed']++;
                $results['proposals'] += $result['proposals'];
                $results['relationships'] += $result['relationships'];
            }

            $results['log'][] = ['media_id' => $record->id, 'type' => $record->media_type] + $result;
        }

        return $results;
    }

    /**
     * Batch process across all trees (auto-discovers trees with persons).
     */
    public function processBatchAllTrees(int $limit = 10, bool $dryRun = false): array
    {
        $trees = DB::select(
            'SELECT DISTINCT tree_id FROM genealogy_persons GROUP BY tree_id'
        );

        $summary = [];
        foreach ($trees as $tree) {
            $summary[(int) $tree->tree_id] = $this->processBatch((int) $tree->tree_id, $limit, $dryRun);
        }

        return $summary;
    }

    // -------------------------------------------------------------------------
    // Stage 2: AI Vision Extraction
    // -------------------------------------------------------------------------

    private function extractStructuredFacts(array $media): array
    {
        // Priority 1: pre-stored transcription (HTR or page-text extraction already ran)
        $textExtraction = $this->extractStructuredFactsFromTranscript($media);
        if ($textExtraction !== []) {
            return $textExtraction;
        }

        $localPath = $this->resolveLocalPath($media['nextcloud_path'] ?? $media['local_filename'] ?? '');
        if (! $localPath) {
            return [];
        }

        // Priority 2: route text-bearing documents (PDFs with text layer, html,
        // docx, xlsx, odt, md, epub, csv) through ContentExtractionService
        // before falling to vision. Closes the Phase-1.2 gap where .htm
        // Ancestry exports, Office docs, and PDFs with selectable text went
        // straight to AI vision — slow and often unable to read multi-page
        // PDFs beyond page 1. Image-class media skips this step and falls
        // through to vision, which is the correct path for scans.
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        if ($ext !== '' && ! GenealogyDocumentExtensions::isImage($ext)) {
            $contentExtraction = $this->extractStructuredFactsFromContentExtraction($media, $localPath);
            if ($contentExtraction !== []) {
                return $contentExtraction;
            }
        }

        // Priority 3: AI vision fallback. Correct for image scans (certificates,
        // obituaries, census pages) and still a valid last-ditch for documents
        // where ContentExtractionService couldn't pull text.
        $docType = $media['media_type'] ?? 'document';
        $prompt = $this->buildExtractionPrompt($docType, $media['title'] ?? '', $media['ai_description'] ?? '', $media);

        $result = $this->ai()->processImage($prompt, $localPath, [
            'temperature' => 0.1,
            'max_tokens' => 1500,
            'model_role' => 'quality',
            'sensitive_data' => true,
            'data_class' => 'genealogy_media_enrichment_image',
        ]);

        if (! ($result['success'] ?? false)) {
            Log::warning('GenealogyMediaEnrichmentService: vision extraction failed', [
                'media_id' => $media['id'],
                'error' => $result['error'] ?? 'unknown',
            ]);

            return [];
        }

        return $this->parseExtractionResponse($result['response'] ?? '');
    }

    /**
     * Priority 2: extract text with ContentExtractionService (the same v4 Tika
     * pipeline that `file_enrich_ai` uses via AIAutoTagService), then run
     * the same structured-fact extractor the transcript path uses. Win/win
     * with file-scanning: any improvement to ContentExtractionService or its
     * HTR fallback (Phase 2.5) benefits both pipelines automatically.
     */
    private function extractStructuredFactsFromContentExtraction(array $media, string $localPath): array
    {
        // Phase 3.2: reuse the cached extraction that file_enrich_ai has
        // already produced for this file. file_registry.ai_detected_text
        // (NOT ai_description — that column holds an AI-generated summary)
        // stores the raw text from ContentExtractionService, keyed by
        // current_path. We only reuse the cache when the disk mtime hasn't
        // advanced past the registry's nextcloud_modified_at, so edits on
        // disk still trigger re-extraction.
        $text = $this->lookupCachedExtractionText($media, $localPath);
        $method = 'file_registry.ai_detected_text';

        if ($text === null) {
            $result = $this->contentExtraction()->extract($localPath);
            if (! ($result['success'] ?? false)) {
                return [];
            }

            $text = trim((string) ($result['text'] ?? ''));
            $method = (string) ($result['method'] ?? 'unknown');
        }

        if ($text === '') {
            return [];
        }

        $quality = $this->assessTranscriptQuality($text, $media, $method);
        if (! ($quality['allow_fact_extraction'] ?? false)) {
            Log::info('GenealogyMediaEnrichmentService: skipped low-quality content extraction text', [
                'media_id' => $media['id'] ?? null,
                'media_type' => $media['media_type'] ?? 'document',
                'extraction_method' => $method,
                'quality' => $quality,
            ]);

            return [];
        }

        $extraction = $this->localWorker()->extractStructuredFactsFromText($text, [
            'tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'family_tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'media_id' => $media['id'] ?? null,
            'media_type' => $media['media_type'] ?? 'document',
            'title' => $media['title'] ?? 'Genealogy document',
            'source_method' => $method,
            'text_quality' => $quality,
        ]);

        if ($extraction !== []) {
            $extraction['_source_method'] = $method;
            $extraction['_text_quality'] = $quality;

            Log::info('GenealogyMediaEnrichmentService: used ContentExtractionService text extraction', [
                'media_id' => $media['id'] ?? null,
                'media_type' => $media['media_type'] ?? 'document',
                'extraction_method' => $method,
                'text_length' => strlen($text),
                'cached' => $method === 'file_registry.ai_detected_text',
            ]);
        }

        return $extraction;
    }

    private function extractStructuredFactsFromTranscript(array $media): array
    {
        $text = trim((string) ($media['transcription_text'] ?? $media['transcription'] ?? ''));
        if ($text === '') {
            return [];
        }

        $quality = $this->assessTranscriptQuality($text, $media, 'genealogy_media_transcription');
        if (! ($quality['allow_fact_extraction'] ?? false)) {
            Log::info('GenealogyMediaEnrichmentService: skipped low-quality stored transcript', [
                'media_id' => $media['id'] ?? null,
                'media_type' => $media['media_type'] ?? 'document',
                'quality' => $quality,
            ]);

            return [];
        }

        $result = $this->localWorker()->extractStructuredFactsFromText($text, [
            'tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'family_tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'media_id' => $media['id'] ?? null,
            'media_type' => $media['media_type'] ?? 'document',
            'title' => $media['title'] ?? 'Genealogy document',
            'source_method' => 'genealogy_media_transcription',
            'text_quality' => $quality,
        ]);

        if ($result !== []) {
            $result['_source_method'] = 'genealogy_media_transcription';
            $result['_text_quality'] = $quality;

            Log::info('GenealogyMediaEnrichmentService: used text-first extraction', [
                'media_id' => $media['id'] ?? null,
                'media_type' => $media['media_type'] ?? 'document',
            ]);
        }

        return $result;
    }

    private function assessTranscriptQuality(string $text, array $media, ?string $sourceMethod = null): array
    {
        return $this->textQualityGate()->assess($text, [
            'tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'family_tree_id' => $media['tree_id'] ?? $media['person_tree_id'] ?? null,
            'media_id' => $media['id'] ?? null,
            'media_type' => $media['media_type'] ?? 'document',
            'title' => $media['title'] ?? 'Genealogy document',
            'source_method' => $sourceMethod,
        ]);
    }

    private function buildExtractionPrompt(string $docType, string $title, string $aiDescription, array $mediaContext = []): string
    {
        $lessonContext = $this->lessonContext()->build($mediaContext, [
            $docType,
            $title,
            $aiDescription,
            'vision',
            'image',
            'ocr',
            'htr',
            'certificate',
            'source media',
            'field level review',
        ], 4);

        return <<<PROMPT
You are a professional genealogist extracting structured facts from a {$docType} document.

Document title: {$title}
Known description: {$aiDescription}
{$lessonContext}

Extract ALL genealogical facts from this document image. Return ONLY valid JSON in this exact format:
{
  "confidence": 0.85,
  "document_type": "{$docType}",
  "document_year": 1920,
  "persons": [
    {
      "name": "John William Doe",
      "given_name": "John William",
      "surname": "Doe",
      "birth_year": 1875,
      "role": "head",
      "confidence": 0.90,
      "facts": [
        {"field": "birth_place", "value": "Pennsylvania", "confidence": 0.85},
        {"field": "occupation", "value": "farmer", "confidence": 0.90},
        {"field": "residence", "value": "123 Main St, Lancaster, PA", "confidence": 0.80}
      ],
      "relationships": [
        {"type": "spouse", "name": "Mary Doe"},
        {"type": "child", "name": "Robert Doe", "birth_year": 1902}
      ]
    }
  ],
  "notes_remainder": "Any facts that don't fit the structured fields above."
}

Rules:
1. confidence: your overall confidence in the extraction (0.0–1.0)
2. facts.field must be one of: birth_date, birth_year, birth_place, death_date, death_year, death_place, burial_place, marriage_date, marriage_place, occupation, residence, immigration_date, immigration_place, military_branch, military_rank, enlistment_date, discharge_date, nationality, religion, other
3. For "other" field facts, put a descriptive label in the value: "field_name: value"
4. notes_remainder: genealogical narrative style for remaining context
5. If you cannot read a field clearly, omit it rather than guess
6. For certificates and vital records, extract only field values that are visually readable; do not infer values from the title, filename, nearby tree person, or form labels alone.
7. For headstones and cemetery memorials, extract only names, dates, burial/cemetery places, and inscriptions that are visible or present in the memorial text.
8. If a certificate or headstone is hard to read, return lower confidence and put "field_level_review_needed" in notes_remainder.
9. Return ONLY the JSON object, no other text
PROMPT;
    }

    private function parseExtractionResponse(string $response): array
    {
        // Strip markdown code fences if present
        $json = preg_replace('/^```(?:json)?\s*/m', '', trim($response));
        $json = preg_replace('/\s*```$/m', '', $json);

        $data = json_decode(trim($json), true);
        if (! is_array($data)) {
            return [];
        }

        // Ensure required keys
        return array_merge([
            'confidence' => 0.0,
            'document_type' => 'document',
            'document_year' => null,
            'persons' => [],
            'notes_remainder' => '',
        ], $data);
    }

    private function buildPreviewSummary(array $media, array $extraction): string
    {
        $personNames = array_values(array_filter(array_map(
            static fn (array $person): string => trim((string) ($person['name'] ?? '')),
            (array) ($extraction['persons'] ?? [])
        )));

        $summaryParts = [];
        if ($personNames !== []) {
            $summaryParts[] = 'Named people: '.implode(', ', array_slice(array_values(array_unique($personNames)), 0, 5));
        }

        $notes = trim((string) ($extraction['notes_remainder'] ?? ''));
        if ($notes !== '') {
            $summaryParts[] = $notes;
        }

        if ($summaryParts === []) {
            $summaryParts[] = trim((string) ($media['title'] ?? 'Genealogy document'));
        }

        return implode('; ', $summaryParts);
    }

    private function buildPreviewTreeCandidates(int $treeId, array $extraction): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (array $person): string => trim((string) ($person['name'] ?? '')),
            (array) ($extraction['persons'] ?? [])
        ))));

        if ($names === []) {
            return [];
        }

        $candidates = [];

        foreach ($names as $name) {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $surname = trim((string) array_pop($parts));
            $givenName = trim(implode(' ', $parts));
            if ($surname === '' || $givenName === '') {
                continue;
            }

            $rows = DB::select(
                "SELECT id, CONCAT_WS(' ', given_name, surname) AS display_name
                 FROM genealogy_persons
                 WHERE tree_id = ?
                   AND LOWER(given_name) = ?
                   AND LOWER(surname) = ?
                 LIMIT 5",
                [$treeId, mb_strtolower($givenName), mb_strtolower($surname)]
            );

            foreach ($rows as $row) {
                $candidates[] = [
                    'id' => $row->id,
                    'display_name' => $row->display_name,
                ];
            }
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }

    // -------------------------------------------------------------------------
    // Stage 3: Person Matching
    // -------------------------------------------------------------------------

    private function matchPersonsToTree(array $extractedPersons, int $treeId): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($extractedPersons as $person) {
            $person = (array) $person;
            $surname = $person['surname'] ?? '';
            $givenName = $person['given_name'] ?? '';
            $birthYear = isset($person['birth_year']) ? (int) $person['birth_year'] : null;
            $personConf = (float) ($person['confidence'] ?? 0.5);

            if (empty($surname)) {
                $unmatched[] = $person;

                continue;
            }

            // Stage 3a: Exact name match in genealogy_persons
            $match = $this->exactPersonMatch($treeId, $givenName, $surname, $birthYear);

            // Stage 3b: Phonetic match via NameVariantService
            if (! $match) {
                $match = $this->phoneticPersonMatch($treeId, $givenName, $surname, $birthYear);
            }

            if ($match && $personConf >= self::CONFIDENCE_FLOOR && $this->acceptTreeMatch($person, $match, [
                'tree_id' => $treeId,
                'media_type' => 'document',
                'title' => 'Genealogy media enrichment match triage',
            ])) {
                $matched[] = [
                    'person_id' => (int) $match->id,
                    'given_name' => $match->given_name,
                    'surname' => $match->surname,
                    'confidence' => $personConf,
                    'facts' => $person['facts'] ?? [],
                    'relationships' => $person['relationships'] ?? [],
                    'extracted' => $person,
                ];
            } else {
                $unmatched[] = array_merge($person, ['tree_match_attempted' => true]);
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function acceptTreeMatch(array $extractedPerson, object $candidate, array $context): bool
    {
        $triage = $this->matchTriage()->triageCandidate(
            [
                'name' => $extractedPerson['name'] ?? trim(($extractedPerson['given_name'] ?? '').' '.($extractedPerson['surname'] ?? '')),
                'given_name' => $extractedPerson['given_name'] ?? '',
                'surname' => $extractedPerson['surname'] ?? '',
                'birth_year' => $extractedPerson['birth_year'] ?? null,
                'role' => $extractedPerson['role'] ?? 'document_subject',
            ],
            (array) $candidate,
            $context
        );

        if (($triage['label'] ?? 'uncertain') !== 'same_person') {
            Log::info('GenealogyMediaEnrichmentService: tree match rejected by local triage', [
                'tree_id' => $context['tree_id'] ?? null,
                'extracted_name' => trim((string) ($extractedPerson['name'] ?? '')),
                'candidate_id' => $candidate->id ?? null,
                'triage' => $triage,
            ]);

            return false;
        }

        return true;
    }

    private function exactPersonMatch(int $treeId, string $givenName, string $surname, ?int $birthYear): ?object
    {
        $params = [$treeId, strtolower($surname), strtolower($givenName)];
        $yearClause = '';
        if ($birthYear) {
            $yearClause = 'AND (gp.birth_date IS NULL OR ABS(YEAR(gp.birth_date) - ?) <= 10)';
            $params[] = $birthYear;
        }

        return DB::selectOne(
            "SELECT gp.id, gp.given_name, gp.surname, gp.birth_date
             FROM genealogy_persons gp
             WHERE gp.tree_id = ?
               AND LOWER(gp.surname) = ?
               AND LOWER(gp.given_name) LIKE CONCAT(?, '%')
               {$yearClause}
             ORDER BY gp.id ASC
             LIMIT 1",
            $params
        );
    }

    private function phoneticPersonMatch(int $treeId, string $givenName, string $surname, ?int $birthYear): ?object
    {
        $phoneticMatches = $this->names()->findPhoneticMatches($treeId, $surname, 5);
        if (empty($phoneticMatches)) {
            return null;
        }

        // findPhoneticMatches returns surname-level aggregates, not person rows;
        // expand each candidate surname back into actual genealogy_persons rows
        // keyed by (tree_id, surname) before per-candidate filtering.
        $personCandidates = [];
        foreach ($phoneticMatches as $agg) {
            $aggArr = (array) $agg;
            $candidateSurname = trim((string) ($aggArr['surname'] ?? ''));
            if ($candidateSurname === '') {
                continue;
            }
            $persons = DB::select(
                'SELECT id, given_name, surname, birth_date
                   FROM genealogy_persons
                  WHERE tree_id = ? AND LOWER(surname) = ?
                  LIMIT 10',
                [$treeId, strtolower($candidateSurname)]
            );
            foreach ($persons as $p) {
                $personCandidates[] = (array) $p;
            }
        }

        foreach ($personCandidates as $candidate) {
            // Given name similarity check (first 3 chars)
            if (! empty($givenName) && ! empty($candidate['given_name'])) {
                if (substr(strtolower($givenName), 0, 3) !== substr(strtolower($candidate['given_name']), 0, 3)) {
                    continue;
                }
            }
            // Birth year window
            if ($birthYear && ! empty($candidate['birth_date'])) {
                $candidateYear = (int) substr($candidate['birth_date'], 0, 4);
                if ($candidateYear && abs($candidateYear - $birthYear) > 10) {
                    continue;
                }
            }
            // Return first acceptable phonetic match (already hydrated with id/given_name/birth_date)
            $candidate = (object) $candidate;

            $triage = $this->matchTriage()->triageCandidate(
                [
                    'name' => trim($givenName.' '.$surname),
                    'given_name' => $givenName,
                    'surname' => $surname,
                    'birth_year' => $birthYear,
                    'role' => 'document_subject',
                ],
                (array) $candidate,
                ['tree_id' => $treeId, 'media_type' => 'document', 'title' => 'Phonetic genealogy match triage', 'allow_phonetic_surname' => true]
            );

            if (($triage['label'] ?? 'uncertain') !== 'same_person') {
                Log::info('GenealogyMediaEnrichmentService: phonetic match rejected by local triage', [
                    'tree_id' => $treeId,
                    'extracted_name' => trim($givenName.' '.$surname),
                    'candidate_id' => $candidate->id,
                    'triage' => $triage,
                ]);

                continue;
            }

            return $candidate;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Stage 4: Proposal Generation
    // -------------------------------------------------------------------------

    private function generateProposalsForPerson(
        int $personId,
        int $mediaId,
        int $treeId,
        array $facts,
        array $media,
        array $extraction
    ): int {
        $count = 0;
        $notesLines = [];
        $evidenceSrc = "media_id:{$mediaId}";
        $docYear = $extraction['document_year'] ?? null;

        foreach ($facts as $fact) {
            $fact = (array) $fact;
            $field = $fact['field'] ?? '';
            $value = $fact['value'] ?? '';
            $conf = (float) ($fact['confidence'] ?? 0.5);

            if (empty($field) || empty($value) || $conf < self::CONFIDENCE_FLOOR) {
                continue;
            }

            if (isset(self::GEDCOM_MAP[$field])) {
                [$changeType, $fieldName] = self::GEDCOM_MAP[$field];
                $this->insertProposal($personId, $treeId, $changeType, $fieldName, $value, $conf, $evidenceSrc, $media, $extraction);
                $count++;
            } elseif ($field === 'other') {
                $notesLines[] = $value;
            } else {
                // Unknown field — goes to notes
                $notesLines[] = ucfirst(str_replace('_', ' ', $field)).': '.$value;
            }
        }

        // Append non-GEDCOM facts and document notes as a single notes_append proposal
        if (! empty($extraction['notes_remainder'])) {
            $notesLines[] = $extraction['notes_remainder'];
        }
        if (! empty($notesLines)) {
            $year = $docYear ? " ({$docYear})" : '';
            $type = ucfirst($media['media_type'] ?? 'document');
            $noteText = "[{$type}{$year} — media #{$mediaId}] ".implode('. ', $notesLines);
            $this->insertProposal($personId, $treeId, 'notes_append', 'notes', $noteText, 0.70, $evidenceSrc, $media, $extraction);
            $count++;
        }

        return $count;
    }

    private function insertProposal(
        int $personId,
        int $treeId,
        string $changeType,
        string $fieldName,
        string $proposedValue,
        float $confidence,
        string $evidenceSrc,
        array $media,
        array $extraction
    ): void {
        $evidenceSummary = sprintf(
            'Extracted from %s (media #%d, %s). AI confidence: %d%%.',
            $media['title'] ?? 'document',
            $media['id'],
            $media['media_type'] ?? 'document',
            (int) ($confidence * 100)
        );

        DB::insert(
            "INSERT INTO genealogy_proposed_changes
                (tree_id, person_id, change_type, field_name, proposed_value,
                 evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $treeId,
                $personId,
                $changeType,
                $fieldName,
                $proposedValue,
                $evidenceSrc,
                $evidenceSummary,
                round($confidence, 2),
                self::AGENT_ID,
            ]
        );
    }

    private function createRelationshipProposal(
        int $anchorPersonId,
        array $unmatchedPerson,
        int $treeId,
        int $mediaId,
        array $extraction
    ): void {
        $docYear = $extraction['document_year'] ?? null;
        $type = ucfirst($extraction['document_type'] ?? 'document');

        $relType = $unmatchedPerson['role'] ?? 'other';
        if (! in_array($relType, ['parent', 'child', 'spouse', 'sibling', 'other'])) {
            $relType = 'other';
        }

        // Infer relationship from roles in extracted data
        $relationships = $unmatchedPerson['relationships'] ?? [];
        foreach ($relationships as $rel) {
            $rel = (array) $rel;
            if (in_array($rel['type'] ?? '', ['parent', 'child', 'spouse', 'sibling'])) {
                $relType = $rel['type'];
                break;
            }
        }

        $notes = "Person mentioned in {$type}".($docYear ? " ({$docYear})" : '').", media #{$mediaId}. Not matched to existing tree record.";
        if (! empty($extraction['notes_remainder'])) {
            $notes .= ' Context: '.substr($extraction['notes_remainder'], 0, 200);
        }

        $evidence = json_encode([
            'media_id' => $mediaId,
            'anchor_person' => $anchorPersonId,
            'document_type' => $extraction['document_type'] ?? null,
            'document_year' => $docYear,
        ]);

        DB::insert(
            "INSERT INTO genealogy_proposed_relationships
                (tree_id, person_id, relationship_type,
                 proposed_given_name, proposed_surname,
                 proposed_birth_date,
                 proposed_notes, evidence_sources, evidence_summary,
                 confidence, agent_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $treeId,
                $anchorPersonId,
                $relType,
                $unmatchedPerson['given_name'] ?? null,
                $unmatchedPerson['surname'] ?? null,
                isset($unmatchedPerson['birth_year']) ? (string) $unmatchedPerson['birth_year'] : null,
                $notes,
                $evidence,
                "Unmatched person found in {$type} via N140 pipeline.",
                round((float) ($unmatchedPerson['confidence'] ?? 0.65), 2),
                self::AGENT_ID,
            ]
        );
    }

    private function linkMediaToPerson(int $personId, int $mediaId): void
    {
        $exists = DB::selectOne(
            'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $mediaId]
        );
        if (! $exists) {
            DB::insert(
                'INSERT INTO genealogy_person_media (person_id, media_id, is_primary, created_at) VALUES (?, ?, 0, NOW())',
                [$personId, $mediaId]
            );
        }
    }

    private function linkMediaToFamily(array $matchedPersons, int $mediaId, int $treeId): void
    {
        // Find a family where at least 2 matched persons are members
        $personIds = array_column($matchedPersons, 'person_id');
        $placeholders = implode(',', array_fill(0, count($personIds), '?'));

        $family = DB::selectOne("
            SELECT gf.id AS family_id
            FROM genealogy_families gf
            WHERE gf.tree_id = ?
              AND (
                  gf.husband_id IN ({$placeholders})
                  OR gf.wife_id IN ({$placeholders})
                  OR gf.id IN (
                      SELECT gc.family_id FROM genealogy_children gc WHERE gc.person_id IN ({$placeholders})
                  )
              )
            LIMIT 1
        ", array_merge([$treeId], $personIds, $personIds, $personIds));

        if ($family) {
            $exists = DB::selectOne(
                'SELECT id FROM genealogy_family_media WHERE family_id = ? AND media_id = ?',
                [$family->family_id, $mediaId]
            );
            if (! $exists) {
                DB::insert(
                    'INSERT INTO genealogy_family_media (family_id, media_id, created_at) VALUES (?, ?, NOW())',
                    [$family->family_id, $mediaId]
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isEligible(array $media): bool
    {
        return in_array($media['media_type'] ?? '', self::DOCUMENT_TYPES, true)
            && ($media['file_exists'] ?? 0)
            && (($media['analysis_status'] ?? '') === 'completed' || $this->hasTranscript($media))
            && ! in_array($media['enrichment_status'] ?? '', ['completed', 'processing'], true);
    }

    private function hasTranscript(array $media): bool
    {
        return trim((string) ($media['transcription_text'] ?? '')) !== ''
            || trim((string) ($media['transcription'] ?? '')) !== '';
    }

    private function markStatus(int $mediaId, string $status, ?string $error = null): void
    {
        $enrichedAt = $status === 'completed' ? ', enriched_at = NOW()' : '';
        DB::update(
            "UPDATE genealogy_media SET enrichment_status = ?, enrichment_error = ?{$enrichedAt}, updated_at = NOW() WHERE id = ?",
            [$status, $error, $mediaId]
        );
    }

    /**
     * Look up file_registry.ai_detected_text for the media's nextcloud_path and
     * return it when the registry row is not stale vs the disk file's mtime.
     *
     * Returns `null` when no cache is available (no registry row, null column,
     * stale row, or any lookup failure) — caller falls through to a live
     * ContentExtractionService extraction.
     */
    private function lookupCachedExtractionText(array $media, string $localPath): ?string
    {
        $nextcloudPath = (string) ($media['nextcloud_path'] ?? '');
        if ($nextcloudPath === '') {
            return null;
        }

        try {
            $row = DB::selectOne(
                'SELECT ai_detected_text, UNIX_TIMESTAMP(nextcloud_modified_at) AS registry_mtime
                   FROM file_registry
                  WHERE current_path = ?
                  LIMIT 1',
                [$nextcloudPath]
            );
        } catch (\Throwable $e) {
            Log::debug('GenealogyMediaEnrichmentService: file_registry cache lookup failed', [
                'media_id' => $media['id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $row || empty($row->ai_detected_text)) {
            return null;
        }

        $diskMtime = @filemtime($localPath);
        $registryMtime = (int) ($row->registry_mtime ?? 0);
        if ($diskMtime !== false && $registryMtime > 0 && $diskMtime > $registryMtime) {
            Log::info('GenealogyMediaEnrichmentService: file_registry cache stale — re-extracting', [
                'media_id' => $media['id'] ?? null,
                'disk_mtime' => $diskMtime,
                'registry_mtime' => $registryMtime,
            ]);

            return null;
        }

        $text = trim((string) $row->ai_detected_text);

        return $text !== '' ? $text : null;
    }

    private function resolveLocalPath(string $filePath): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        $nextcloudDataPath = config('services.nextcloud.data_path');
        if ($nextcloudDataPath) {
            $local = rtrim($nextcloudDataPath, '/').'/'.ltrim($filePath, '/');
            if (file_exists($local)) {
                return $local;
            }
        }
        if (str_starts_with($filePath, '/') && file_exists($filePath)) {
            return $filePath;
        }

        return null;
    }

    private function mediaTypeToRepoType(string $mediaType): string
    {
        return match ($mediaType) {
            'census' => 'census',
            'certificate' => 'vital_records',
            'obituary' => 'newspaper',
            'military' => 'military',
            'headstone' => 'cemetery',
            default => 'other',
        };
    }
}
