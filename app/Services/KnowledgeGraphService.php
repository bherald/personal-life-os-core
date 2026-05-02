<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge Graph Service
 *
 * Extracts and stores entity relationships as subject-predicate-object triples.
 * Supports graph traversal, entity merging, and relationship discovery.
 *
 * Features:
 * - AI-powered entity extraction from text
 * - Triple storage with provenance tracking
 * - Multi-hop graph traversal
 * - Entity canonicalization and merging
 * - Relationship-based search
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class KnowledgeGraphService
{
    use RecursionAware;

    private const SCHEDULED_BATCH_CHAR_LIMIT = 2500;

    private const SCHEDULED_BATCH_SPACY_ENTITY_LIMIT = 8;

    private const SCHEDULED_BATCH_MAX_TOKENS = 700;

    private const SCHEDULED_BATCH_RETRY_MAX_TOKENS = 500;

    private const SCHEDULED_BATCH_ENTITY_OUTPUT_LIMIT = 6;

    private const SCHEDULED_BATCH_REL_OUTPUT_LIMIT = 6;

    private AIService $aiService;

    private ?SpacyNLPService $spacyService = null;

    /** @var string Database connection name */
    private const CONNECTION = 'pgsql_rag';

    /** @var array Valid entity types */
    private const ENTITY_TYPES = [
        'person', 'organization', 'location', 'concept', 'event',
        'document', 'date', 'product', 'technology', 'other',
        'file', 'genealogy_person', 'face_cluster',
    ];

    /** @var array Common predicate types */
    private const COMMON_PREDICATES = [
        'works_at', 'located_in', 'related_to', 'founded_by', 'part_of',
        'created_by', 'owns', 'member_of', 'born_in', 'died_in',
        'married_to', 'child_of', 'parent_of', 'sibling_of', 'employed_by',
        'studied_at', 'lives_in', 'occurred_on', 'happened_at', 'uses',
        'produces', 'competes_with', 'collaborates_with', 'reports_to',
        'manages', 'associated_with', 'instance_of', 'subclass_of',
    ];

    /** @var array Valid temporal_type enum values */
    private const TEMPORAL_TYPES = ['ongoing', 'point_in_time', 'period', 'unknown'];

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Build temporal filter clause for knowledge_graph queries.
     *
     * @param  array  $options  Temporal filter options:
     *                          - include_expired: bool — skip t_expired filter (show all edges)
     *                          - as_of: string — point-in-time transaction time (show edges that existed at this moment)
     *                          - valid_at: string — point-in-time valid time (show edges valid at this real-world moment)
     * @param  string  $alias  Table alias prefix (e.g. 'kg.' or '')
     * @return array ['clause' => 'AND ...', 'params' => [...]]
     */
    private function buildTemporalFilter(array $options = [], string $alias = ''): array
    {
        $clauses = [];
        $params = [];

        $includeExpired = $options['include_expired'] ?? false;
        $asOf = $options['as_of'] ?? null;
        $validAt = $options['valid_at'] ?? null;

        if (! $includeExpired) {
            if ($asOf) {
                // Transaction time: edge existed at this moment
                $clauses[] = "{$alias}created_at <= ?";
                $params[] = $asOf;
                $clauses[] = "({$alias}t_expired IS NULL OR {$alias}t_expired > ?)";
                $params[] = $asOf;
            } else {
                // Default: active edges only
                $clauses[] = "{$alias}t_expired IS NULL";
            }
        }

        if ($validAt) {
            // Valid time: fact was true at this real-world moment
            $clauses[] = "({$alias}valid_from IS NULL OR {$alias}valid_from <= ?)";
            $params[] = $validAt;
            $clauses[] = "({$alias}valid_until IS NULL OR {$alias}valid_until >= ?)";
            $params[] = $validAt;
        }

        $clause = ! empty($clauses) ? ' AND '.implode(' AND ', $clauses) : '';

        return ['clause' => $clause, 'params' => $params];
    }

    /**
     * Extract entities and relationships from text using AI
     *
     * @param  string  $text  Source text to analyze
     * @param  array  $options  Options:
     *                          - source_document_id: int - Link to source RAG document
     *                          - persist: bool - Save to database (default: true)
     *                          - min_confidence: float - Minimum confidence threshold (default: 0.5)
     * @return array Extracted entities and triples
     */
    public function extractEntities(string $text, array $options = []): array
    {
        if (empty($options['skip_recursive'])) {
            // RLM: Try recursive knowledge graph extraction
            $rlm = $this->tryRecursive('knowledge_graph', 'partition_map', ['text' => $text, 'options' => $options], function ($ctx) {
                return $this->extractEntities($ctx['text'] ?? $ctx['data'], $ctx['options'] ?? []);
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        $startTime = microtime(true);
        $persist = $options['persist'] ?? true;
        $sourceDocId = $options['source_document_id'] ?? null;
        $minConfidence = $options['min_confidence'] ?? 0.5;
        $scheduledBatch = ! empty($options['scheduled_batch']);
        $maxTokens = $scheduledBatch ? self::SCHEDULED_BATCH_MAX_TOKENS : 4000;
        $allowRetryPrompt = ! $scheduledBatch;

        // Routing overrides for scheduled batches: let the caller pin to a specific
        // Ollama instance and/or model role instead of the default local fast combo.
        // Used by the catch-up lane (scheduled_jobs id=133) to spread KG extraction
        // across local Ollama roles so available GPUs work in parallel.
        $preferredInstance = $options['preferred_instance'] ?? 'primary';
        $modelRoleOverride = $options['model_role_override'] ?? null;
        $scheduledAllowedInstances = match ($preferredInstance) {
            'secondary' => ['ollama_secondary'],
            'any' => null,
            default => ['ollama_primary'],
        };
        $scheduledModelRole = $modelRoleOverride ?: 'fast';

        if ($scheduledBatch && strlen($text) > self::SCHEDULED_BATCH_CHAR_LIMIT) {
            $text = substr($text, 0, self::SCHEDULED_BATCH_CHAR_LIMIT)."\n[... truncated for scheduled batch]";
        }

        try {
            // GR-3: Hybrid extraction — SpaCy NER first (fast), then LLM for relationships only.
            // SpaCy: 50-200ms CPU, extracts persons/places/dates/orgs.
            // LLM: focuses on relationship extraction using pre-identified entities.
            // Fallback: LLM-only extraction if SpaCy unavailable.
            $spacyEntities = $this->trySpacyExtraction($text);

            // Build extraction prompt
            $predicateList = implode(', ', self::COMMON_PREDICATES);
            $typeList = implode(', ', self::ENTITY_TYPES);

            if (! empty($spacyEntities)) {
                // Hybrid mode: feed SpaCy entities to LLM for relationship extraction
                if ($scheduledBatch) {
                    $spacyEntities = array_slice($spacyEntities, 0, self::SCHEDULED_BATCH_SPACY_ENTITY_LIMIT);
                }
                $entityList = json_encode($spacyEntities, JSON_UNESCAPED_UNICODE);
                $prompt = $scheduledBatch ? <<<PROMPT
/no_think
Return compact JSON only. No markdown, no prose, no code fences.

PRE-IDENTIFIED ENTITIES:
{$entityList}

TEXT:
{$text}

Return exactly one minified JSON object:
{"entities":[{"name":"string","type":"{$typeList}"}],"relationships":[{"subject":"string","subject_type":"type","predicate":"string","object":"string","object_type":"type","confidence":0.9,"source_snippet":"text"}]}

Rules:
- At most 6 entities and 6 relationships
- Prioritize people, places, events, and explicit family/work/location relationships
- Keep source_snippet under 40 chars
- Use lowercase_underscore predicates
- Skip weak or speculative relationships
- Use no spaces or line breaks unless required inside string values
PROMPT : <<<PROMPT
Given these pre-identified entities and the source text, extract relationships between them.
Also identify any additional entities the pre-extraction may have missed.

PRE-IDENTIFIED ENTITIES:
{$entityList}

TEXT:
{$text}

For each relationship, provide: subject, subject_type, predicate (use: {$predicateList} or descriptive), object, object_type, confidence (0.0-1.0), source_snippet (max 100 chars), valid_from (ISO date/year/null), valid_until (null if current), temporal_type (ongoing|point_in_time|period|unknown).

RESPOND IN JSON FORMAT:
{
  "entities": [{"name": "string", "type": "{$typeList}", "aliases": [], "properties": {}}],
  "relationships": [{"subject": "string", "subject_type": "type", "predicate": "string", "object": "string", "object_type": "type", "confidence": 0.9, "source_snippet": "text", "valid_from": null, "valid_until": null, "temporal_type": "unknown"}]
}

Rules:
- Include the pre-identified entities in your entities list (correct types if needed)
- Add any entities the pre-extraction missed
- Focus on identifying RELATIONSHIPS — entity extraction is mostly done
- Use lowercase_underscore for predicates
- Keep source_snippet short (max 100 chars)
PROMPT;
            } else {
                // LLM-only mode (original behavior)
                $prompt = $scheduledBatch ? <<<PROMPT
/no_think
Return compact JSON only. No markdown, no prose, no code fences.

TEXT:
{$text}

Return exactly one minified JSON object:
{"entities":[{"name":"Entity Name","type":"person|organization|location|concept|event|document|date|product|technology|other"}],"relationships":[{"subject":"Entity Name","subject_type":"type","predicate":"relationship_type","object":"Other Entity","object_type":"type","confidence":0.9,"source_snippet":"text"}]}

Rules:
- At most 6 entities and 6 relationships
- Prefer explicit people, places, dates, and key events
- Use lowercase_underscore predicates
- Keep source_snippet under 40 chars
- Skip low-confidence or redundant relationships
- Use no spaces or line breaks unless required inside string values
PROMPT : <<<PROMPT
Extract named entities and their relationships from this text.

TEXT:
{$text}

For each relationship found, provide:
1. subject: The entity that is the source of the relationship
2. subject_type: One of: {$typeList}
3. predicate: The relationship type (use: {$predicateList} or create descriptive ones)
4. object: The entity that is the target of the relationship
5. object_type: One of: {$typeList}
6. confidence: Your confidence 0.0-1.0 that this relationship exists
7. source_snippet: The exact text snippet supporting this relationship
8. valid_from: When this relationship started (ISO date "2020-01-15" or year "2020" or null if unknown)
9. valid_until: When this relationship ended (null if still true or unknown)
10. temporal_type: One of: ongoing | point_in_time | period | unknown

RESPOND IN JSON FORMAT:
{
  "entities": [
    {
      "name": "Entity Name",
      "type": "person|organization|location|etc",
      "aliases": ["alternative names"],
      "properties": {"key": "value"}
    }
  ],
  "relationships": [
    {
      "subject": "Entity Name",
      "subject_type": "type",
      "predicate": "relationship_type",
      "object": "Other Entity",
      "object_type": "type",
      "confidence": 0.9,
      "source_snippet": "relevant text excerpt",
      "valid_from": null,
      "valid_until": null,
      "temporal_type": "unknown"
    }
  ]
}

Rules:
- Extract ALL entities mentioned (people, places, organizations, concepts)
- Identify relationships even if implicit
- Use lowercase_underscore for predicates
- Include confidence based on how explicit the relationship is
- Keep source_snippet short (max 100 chars)
- Set temporal_type: "ongoing" for current facts (lives_in, works_at), "point_in_time" for events (born_in, died_in), "period" for time-bounded facts with start/end, "unknown" if unclear
- For dates: use ISO format when possible, year-only is fine (e.g. "1985")
PROMPT;
            }

            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => $maxTokens,
                'model_role' => $scheduledBatch ? $scheduledModelRole : 'standard',
                'prefer_external' => false,
                'skip_if_busy' => ! $scheduledBatch,
                'ollama_lock_wait_seconds' => $scheduledBatch ? 15 : 30,
                'allowed_ollama_instance_ids' => $scheduledBatch ? $scheduledAllowedInstances : null,
            ]);

            if (! $result['success']) {
                if ($scheduledBatch) {
                    Log::warning('KnowledgeGraph: Scheduled batch degrading after AI extraction failure', [
                        'source_document_id' => $sourceDocId,
                        'error' => $result['error'] ?? 'AI extraction failed',
                        'spacy_entities' => count($spacyEntities),
                    ]);

                    return [
                        'success' => true,
                        'entities' => $spacyEntities,
                        'relationships' => [],
                        'saved_entity_ids' => [],
                        'saved_triple_ids' => [],
                        'duration_ms' => round((microtime(true) - $startTime) * 1000),
                        'degraded' => true,
                        'error' => $result['error'] ?? 'AI extraction failed',
                    ];
                }

                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI extraction failed',
                    'entities' => [],
                    'relationships' => [],
                ];
            }

            // Parse JSON from response
            $extracted = $this->parseJsonFromResponse($result['response']);

            // If first parse fails, retry with stricter JSON-only prompt
            if (! $extracted && $allowRetryPrompt) {
                Log::debug('KnowledgeGraph: First parse failed, retrying with strict prompt');

                $retryPrompt = <<<RETRY_PROMPT
You must respond with ONLY valid JSON, no text before or after. Extract entities and relationships from this text.

TEXT: {$text}

Output format - respond with ONLY this JSON structure:
{"entities":[{"name":"string","type":"person|organization|location|concept|event|document|date|product|technology|other"}],"relationships":[{"subject":"string","subject_type":"type","predicate":"relationship_type","object":"string","object_type":"type","confidence":0.9}]}

DO NOT include any explanatory text. Only output the JSON object.
RETRY_PROMPT;

                $retryResult = $this->aiService->process($retryPrompt, [
                    'factual_mode' => true,
                    'max_tokens' => $maxTokens,
                ]);

                if ($retryResult['success']) {
                    $extracted = $this->parseJsonFromResponse($retryResult['response']);
                }
            }

            // Scheduled batches normally avoid a full retry to save tokens, but an empty or
            // invalid response drops all relationship extraction quality. Give them one strict,
            // lower-token retry biased toward external providers before degrading to SpaCy only.
            if (! $extracted && $scheduledBatch) {
                Log::debug('KnowledgeGraph: Scheduled batch parse failed, retrying once with strict external prompt', [
                    'source_document_id' => $sourceDocId,
                    'response_length' => strlen($result['response'] ?? ''),
                ]);

                $retryPrompt = <<<RETRY_PROMPT
/no_think
You must respond with ONLY valid minified JSON, no markdown and no explanatory text.

TEXT:
{$text}

Return ONLY this structure:
{"entities":[{"name":"string","type":"person|organization|location|concept|event|document|date|product|technology|other"}],"relationships":[{"subject":"string","subject_type":"type","predicate":"relationship_type","object":"string","object_type":"type","confidence":0.9,"source_snippet":"text"}]}

Rules:
- At most 6 entities and 6 relationships
- Prefer explicit people, places, dates, and key events
- Keep source_snippet under 40 chars
- Use lowercase_underscore predicates
- If no relationships exist, return an empty relationships array
- No spaces or line breaks unless required inside string values
RETRY_PROMPT;

                $retryResult = $this->aiService->process($retryPrompt, [
                    'factual_mode' => true,
                    'max_tokens' => self::SCHEDULED_BATCH_RETRY_MAX_TOKENS,
                    'model_role' => $scheduledModelRole,
                    'prefer_external' => false,
                    'skip_if_busy' => false,
                    'ollama_lock_wait_seconds' => 15,
                    'allowed_ollama_instance_ids' => $scheduledAllowedInstances,
                ]);

                if ($retryResult['success']) {
                    $extracted = $this->parseJsonFromResponse($retryResult['response']);
                }
            }

            if (! $extracted) {
                if ($scheduledBatch) {
                    Log::warning('KnowledgeGraph: Scheduled batch falling back after parse failure', [
                        'source_document_id' => $sourceDocId,
                        'spacy_entities' => count($spacyEntities),
                        'response_length' => strlen($result['response'] ?? ''),
                    ]);

                    $extracted = [
                        'entities' => $spacyEntities,
                        'relationships' => [],
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'Failed to parse extraction response',
                        'raw_response' => $result['response'],
                        'entities' => [],
                        'relationships' => [],
                    ];
                }
            }

            $entities = $extracted['entities'] ?? [];
            $relationships = $extracted['relationships'] ?? [];

            if ($scheduledBatch) {
                $entities = array_slice($entities, 0, self::SCHEDULED_BATCH_ENTITY_OUTPUT_LIMIT);
                $relationships = array_slice($relationships, 0, self::SCHEDULED_BATCH_REL_OUTPUT_LIMIT);
            }

            // Coerce entity names to strings — LLM may return arrays (e.g. ["John Smith"])
            $normalizedEntities = [];
            foreach ($entities as $entity) {
                $name = $entity['name'] ?? '';
                if (is_array($name)) {
                    $name = $name[0] ?? $name['name'] ?? $name['primary'] ?? '';
                }
                $name = is_string($name) ? trim($name) : '';
                if ($name === '') {
                    Log::debug('KnowledgeGraph: Skipping entity with empty/invalid name', ['entity' => $entity]);

                    continue;
                }
                $entity['name'] = $name;
                if ($scheduledBatch && isset($entity['type']) && is_string($entity['type'])) {
                    $entity['type'] = trim($entity['type']);
                }
                $normalizedEntities[] = $entity;
            }
            $entities = $normalizedEntities;

            // Filter by confidence
            $relationships = array_filter($relationships, function ($rel) use ($minConfidence) {
                return ($rel['confidence'] ?? 0) >= $minConfidence;
            });

            // Persist if requested
            $savedEntities = [];
            $savedTriples = [];

            if ($persist) {
                // Save entities first
                foreach ($entities as $entity) {
                    $entityId = $this->getOrCreateEntity(
                        $entity['name'],
                        $entity['type'] ?? 'other',
                        $entity['aliases'] ?? [],
                        $entity['properties'] ?? []
                    );
                    $savedEntities[$entity['name']] = $entityId;
                }

                // Save relationships as triples
                foreach ($relationships as $rel) {
                    // Normalize subject/predicate/object to strings
                    $subject = is_array($rel['subject'] ?? null)
                        ? ($rel['subject']['name'] ?? json_encode($rel['subject']))
                        : ($rel['subject'] ?? null);
                    $predicate = is_array($rel['predicate'] ?? null)
                        ? json_encode($rel['predicate'])
                        : ($rel['predicate'] ?? null);
                    $object = is_array($rel['object'] ?? null)
                        ? ($rel['object']['name'] ?? json_encode($rel['object']))
                        : ($rel['object'] ?? null);

                    // Skip relationships with null/empty subject, predicate, or object
                    if (empty($subject) || empty($predicate) || empty($object)) {
                        Log::debug('KnowledgeGraph: Skipping incomplete relationship', [
                            'subject' => $subject,
                            'predicate' => $predicate,
                            'object' => $object,
                        ]);

                        continue;
                    }

                    // Ensure all values are strings
                    $subject = (string) $subject;
                    $predicate = (string) $predicate;
                    $object = (string) $object;

                    if ($scheduledBatch) {
                        $rel['source_snippet'] = mb_substr((string) ($rel['source_snippet'] ?? ''), 0, 40);
                        $rel['valid_from'] = $rel['valid_from'] ?? null;
                        $rel['valid_until'] = $rel['valid_until'] ?? null;
                        $rel['temporal_type'] = $rel['temporal_type'] ?? 'unknown';
                    }

                    $tripleId = $this->addTriple(
                        $subject,
                        $predicate,
                        $object,
                        [
                            'subject_type' => is_array($rel['subject_type'] ?? 'other') ? 'other' : ($rel['subject_type'] ?? 'other'),
                            'object_type' => is_array($rel['object_type'] ?? 'other') ? 'other' : ($rel['object_type'] ?? 'other'),
                            'confidence' => $rel['confidence'] ?? 0.8,
                            'extracted_from' => is_array($rel['source_snippet'] ?? null)
                                ? implode(' ', array_map('strval', $rel['source_snippet']))
                                : ($rel['source_snippet'] ?? null),
                            'source_document_id' => $sourceDocId,
                            'subject_entity_id' => $savedEntities[$subject] ?? null,
                            'object_entity_id' => $savedEntities[$object] ?? null,
                            'valid_from' => $rel['valid_from'] ?? null,
                            'valid_until' => $rel['valid_until'] ?? null,
                            'temporal_type' => $rel['temporal_type'] ?? 'unknown',
                        ]
                    );
                    $savedTriples[] = $tripleId;
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            Log::info('KnowledgeGraph: Extraction complete', [
                'entities' => count($entities),
                'relationships' => count($relationships),
                'persisted' => $persist,
                'duration_ms' => $duration,
            ]);

            return [
                'success' => true,
                'entities' => $entities,
                'relationships' => array_values($relationships),
                'saved_entity_ids' => $savedEntities,
                'saved_triple_ids' => $savedTriples,
                'duration_ms' => $duration,
                'degraded' => $scheduledBatch && empty($relationships),
            ];

        } catch (Exception $e) {
            Log::error('KnowledgeGraph: Extraction failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'entities' => [],
                'relationships' => [],
            ];
        }
    }

    /**
     * Add a triple to the knowledge graph
     *
     * @param  string  $subject  Subject entity
     * @param  string  $predicate  Relationship type
     * @param  string  $object  Object entity
     * @param  array  $metadata  Additional metadata:
     *                           - subject_type: string
     *                           - object_type: string
     *                           - confidence: float
     *                           - extracted_from: string
     *                           - source_document_id: int
     *                           - subject_entity_id: int
     *                           - object_entity_id: int
     * @return int The created triple ID
     */
    public function addTriple(string $subject, string $predicate, string $object, array $metadata = []): int
    {
        $subjectTypeRaw = $metadata['subject_type'] ?? 'other';
        $subjectType = $this->validateEntityType(is_array($subjectTypeRaw) ? ($subjectTypeRaw['type'] ?? 'other') : (string) $subjectTypeRaw);
        $objectTypeRaw = $metadata['object_type'] ?? 'other';
        $objectType = $this->validateEntityType(is_array($objectTypeRaw) ? ($objectTypeRaw['type'] ?? 'other') : (string) $objectTypeRaw);
        $predicate = $this->normalizePredicate($predicate);

        // Parse temporal data from metadata
        $temporal = $this->parseTemporalData($metadata);

        $sql = '
            INSERT INTO knowledge_graph (
                source_document_id, subject, subject_type, subject_entity_id,
                predicate, object, object_type, object_entity_id,
                confidence, extracted_from, metadata,
                valid_from, valid_until, temporal_type, temporal_confidence,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ';

        $result = DB::connection(self::CONNECTION)->select($sql, [
            $metadata['source_document_id'] ?? null,
            trim($subject),
            $subjectType,
            $metadata['subject_entity_id'] ?? null,
            $predicate,
            trim($object),
            $objectType,
            $metadata['object_entity_id'] ?? null,
            $metadata['confidence'] ?? 1.0,
            $metadata['extracted_from'] ?? null,
            json_encode($metadata['extra'] ?? []),
            $temporal['valid_from'],
            $temporal['valid_until'],
            $temporal['temporal_type'],
            $temporal['temporal_confidence'],
        ]);

        $newId = $result[0]->id;

        // Record creation in edge history
        DB::connection(self::CONNECTION)->insert("
            INSERT INTO knowledge_graph_edge_history
                (triple_id, action, actor, created_at)
            VALUES (?, 'created', 'system', NOW())
        ", [$newId]);

        // Inline contradiction check
        try {
            $conflicts = $this->checkForContradictions($subject, $predicate, $object);
            $newConfidence = (float) ($metadata['confidence'] ?? 1.0);

            foreach ($conflicts as $conflict) {
                $delta = $newConfidence - $conflict['confidence'];

                if ($delta >= 0.3) {
                    // New edge is significantly more confident — auto-invalidate old
                    $this->invalidateTriple($conflict['id'], [
                        'reason' => 'Superseded by higher-confidence triple',
                        'superseded_by' => $newId,
                        'actor' => 'contradiction_detector',
                    ]);
                } else {
                    // Log for review
                    $this->logContradiction($newId, $conflict['id']);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('KnowledgeGraph: Contradiction check failed', [
                'triple_id' => $newId,
                'error' => $e->getMessage(),
            ]);
        }

        return $newId;
    }

    /**
     * Parse and normalize temporal data from relationship metadata.
     *
     * @param  array  $rel  Relationship data from AI extraction or manual input
     * @return array {valid_from, valid_until, temporal_type, temporal_confidence}
     */
    private function parseTemporalData(array $rel): array
    {
        $validFrom = $rel['valid_from'] ?? null;
        $validUntil = $rel['valid_until'] ?? null;
        $temporalType = $rel['temporal_type'] ?? 'unknown';
        $temporalConfidence = null;

        // Normalize date strings
        $validFrom = $this->normalizeTemporalDate($validFrom);
        $validUntil = $this->normalizeTemporalDate($validUntil);

        // Sanitize temporal_type enum
        if (! in_array($temporalType, self::TEMPORAL_TYPES)) {
            $temporalType = 'unknown';
        }

        // Calculate temporal confidence: if AI provided temporal data, set confidence
        if ($validFrom !== null || $validUntil !== null || $temporalType !== 'unknown') {
            $temporalConfidence = (float) ($rel['temporal_confidence'] ?? $rel['confidence'] ?? 0.7);
            $temporalConfidence = max(0.0, min(1.0, $temporalConfidence));
        }

        return [
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'temporal_type' => $temporalType,
            'temporal_confidence' => $temporalConfidence,
        ];
    }

    /**
     * Normalize a date value to ISO timestamp or null.
     * Handles: "2020", "2020-01", "2020-01-15", "March 2020", null, empty string.
     */
    private function normalizeTemporalDate($value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        // Year only: "2020"
        if (preg_match('/^\d{4}$/', $value)) {
            return "{$value}-01-01";
        }

        // Year-month: "2020-01"
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return "{$value}-01";
        }

        // Full ISO date: "2020-01-15"
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }

        // Month Year: "March 2020", "Jan 2020"
        $parsed = strtotime($value);
        if ($parsed !== false) {
            return date('Y-m-d', $parsed);
        }

        return null;
    }

    /**
     * Find all relationships for an entity
     *
     * @param  string  $entity  Entity name to search
     * @param  array  $options  Options:
     *                          - direction: 'outgoing'|'incoming'|'both' (default: 'both')
     *                          - predicates: array - Filter by relationship types
     *                          - min_confidence: float - Minimum confidence
     *                          - limit: int - Max results
     * @return array Matching relationships
     */
    public function findRelationships(string $entity, array $options = []): array
    {
        $direction = $options['direction'] ?? 'both';
        $predicates = $options['predicates'] ?? [];
        $minConfidence = $options['min_confidence'] ?? 0.0;
        $limit = $options['limit'] ?? 100;

        $params = [];
        $conditions = [];

        // Entity matching (case-insensitive)
        if ($direction === 'both') {
            $conditions[] = '(LOWER(subject) = LOWER(?) OR LOWER(object) = LOWER(?))';
            $params[] = $entity;
            $params[] = $entity;
        } elseif ($direction === 'outgoing') {
            $conditions[] = 'LOWER(subject) = LOWER(?)';
            $params[] = $entity;
        } else {
            $conditions[] = 'LOWER(object) = LOWER(?)';
            $params[] = $entity;
        }

        // Confidence filter
        $conditions[] = 'confidence >= ?';
        $params[] = $minConfidence;

        // Predicate filter
        if (! empty($predicates)) {
            $placeholders = implode(',', array_fill(0, count($predicates), '?'));
            $conditions[] = "predicate IN ({$placeholders})";
            $params = array_merge($params, $predicates);
        }

        // Temporal filter (default: active only)
        $temporal = $this->buildTemporalFilter($options);
        $params = array_merge($params, $temporal['params']);

        $params[] = $limit;

        $sql = '
            SELECT id, source_document_id, subject, subject_type, predicate,
                   object, object_type, confidence, extracted_from, metadata,
                   valid_from, valid_until, temporal_type, created_at
            FROM knowledge_graph
            WHERE '.implode(' AND ', $conditions).$temporal['clause'].'
            ORDER BY confidence DESC, created_at DESC
            LIMIT ?
        ';

        $results = DB::connection(self::CONNECTION)->select($sql, $params);

        return array_map(function ($row) use ($entity) {
            return [
                'id' => $row->id,
                'subject' => $row->subject,
                'subject_type' => $row->subject_type,
                'predicate' => $row->predicate,
                'object' => $row->object,
                'object_type' => $row->object_type,
                'confidence' => (float) $row->confidence,
                'extracted_from' => $row->extracted_from,
                'source_document_id' => $row->source_document_id,
                'direction' => strtolower($row->subject) === strtolower($entity) ? 'outgoing' : 'incoming',
                'valid_from' => $row->valid_from,
                'valid_until' => $row->valid_until,
                'temporal_type' => $row->temporal_type,
                'created_at' => $row->created_at,
            ];
        }, $results);
    }

    /**
     * Get entity graph with multi-hop traversal
     *
     * @param  string  $entity  Starting entity
     * @param  int  $depth  Maximum traversal depth (default: 2)
     * @param  array  $options  Options:
     *                          - min_confidence: float
     *                          - max_nodes: int - Maximum total nodes (default: 50)
     *                          - exclude_predicates: array - Predicates to skip
     * @return array Graph structure with nodes and edges
     */
    public function getEntityGraph(string $entity, int $depth = 2, array $options = []): array
    {
        $minConfidence = $options['min_confidence'] ?? 0.5;
        $maxNodes = $options['max_nodes'] ?? 50;
        $excludePredicates = $options['exclude_predicates'] ?? [];

        $nodes = [];
        $edges = [];
        $visited = [];
        $queue = [['entity' => $entity, 'depth' => 0]];

        while (! empty($queue) && count($nodes) < $maxNodes) {
            $current = array_shift($queue);
            $currentEntity = $current['entity'];
            $currentDepth = $current['depth'];

            $normalizedEntity = strtolower($currentEntity);
            if (isset($visited[$normalizedEntity])) {
                continue;
            }
            $visited[$normalizedEntity] = true;

            // Get relationships for current entity
            $relationships = $this->findRelationships($currentEntity, [
                'min_confidence' => $minConfidence,
                'limit' => 20,
            ]);

            // Filter excluded predicates
            if (! empty($excludePredicates)) {
                $relationships = array_filter($relationships, function ($rel) use ($excludePredicates) {
                    return ! in_array($rel['predicate'], $excludePredicates);
                });
            }

            // Add current entity as node if not already present
            if (! isset($nodes[$normalizedEntity])) {
                $entityInfo = $this->getEntityInfo($currentEntity);
                $nodes[$normalizedEntity] = [
                    'id' => $normalizedEntity,
                    'label' => $currentEntity,
                    'type' => $entityInfo['type'] ?? 'other',
                    'depth' => $currentDepth,
                ];
            }

            foreach ($relationships as $rel) {
                // Add edge
                $edgeKey = "{$rel['subject']}|{$rel['predicate']}|{$rel['object']}";
                if (! isset($edges[$edgeKey])) {
                    $edges[$edgeKey] = [
                        'source' => strtolower($rel['subject']),
                        'target' => strtolower($rel['object']),
                        'predicate' => $rel['predicate'],
                        'confidence' => $rel['confidence'],
                    ];
                }

                // Queue connected entity for next depth level
                if ($currentDepth < $depth) {
                    $connectedEntity = $rel['direction'] === 'outgoing' ? $rel['object'] : $rel['subject'];
                    $normalizedConnected = strtolower($connectedEntity);

                    if (! isset($visited[$normalizedConnected])) {
                        $queue[] = ['entity' => $connectedEntity, 'depth' => $currentDepth + 1];

                        // Add connected entity as node
                        if (! isset($nodes[$normalizedConnected])) {
                            $nodes[$normalizedConnected] = [
                                'id' => $normalizedConnected,
                                'label' => $connectedEntity,
                                'type' => $rel['direction'] === 'outgoing' ? $rel['object_type'] : $rel['subject_type'],
                                'depth' => $currentDepth + 1,
                            ];
                        }
                    }
                }
            }
        }

        return [
            'root' => $entity,
            'depth' => $depth,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'total_nodes' => count($nodes),
            'total_edges' => count($edges),
        ];
    }

    /**
     * Merge two entities, transferring all relationships
     *
     * @param  int  $sourceId  Entity ID to merge from (will be deleted)
     * @param  int  $targetId  Entity ID to merge into (will be kept)
     * @return bool Success status
     */
    public function mergeEntities(int $sourceId, int $targetId): bool
    {
        try {
            DB::connection(self::CONNECTION)->beginTransaction();

            // Get source entity info
            $sourceEntity = DB::connection(self::CONNECTION)->select(
                'SELECT canonical_name, aliases, properties FROM knowledge_graph_entities WHERE id = ?',
                [$sourceId]
            );

            if (empty($sourceEntity)) {
                DB::connection(self::CONNECTION)->rollBack();

                return false;
            }

            $source = $sourceEntity[0];
            $sourceAliases = json_decode($source->aliases ?? '[]', true);
            $sourceProps = json_decode($source->properties ?? '{}', true);

            // Add source canonical name to target aliases
            $sourceAliases[] = $source->canonical_name;

            // Merge aliases into target
            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph_entities
                SET aliases = aliases || ?::jsonb,
                    properties = properties || ?::jsonb,
                    updated_at = NOW()
                WHERE id = ?
            ', [
                json_encode(array_values(array_unique($sourceAliases))),
                json_encode($sourceProps),
                $targetId,
            ]);

            // Update triples pointing to source entity (as subject)
            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph
                SET subject_entity_id = ?,
                    updated_at = NOW()
                WHERE subject_entity_id = ?
            ', [$targetId, $sourceId]);

            // Update triples pointing to source entity (as object)
            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph
                SET object_entity_id = ?,
                    updated_at = NOW()
                WHERE object_entity_id = ?
            ', [$targetId, $sourceId]);

            // Delete source entity
            DB::connection(self::CONNECTION)->statement(
                'DELETE FROM knowledge_graph_entities WHERE id = ?',
                [$sourceId]
            );

            DB::connection(self::CONNECTION)->commit();

            Log::info('KnowledgeGraph: Entities merged', [
                'source_id' => $sourceId,
                'target_id' => $targetId,
            ]);

            return true;

        } catch (\Throwable $e) {
            DB::connection(self::CONNECTION)->rollBack();
            Log::error('KnowledgeGraph: Merge failed', [
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Search for triples by predicate and optionally object
     *
     * @param  string  $predicate  Relationship type to search
     * @param  string|null  $object  Optional object to filter by
     * @param  array  $options  Options:
     *                          - min_confidence: float
     *                          - limit: int
     *                          - subject_type: string
     *                          - object_type: string
     * @return array Matching triples
     */
    public function searchByRelationship(string $predicate, ?string $object = null, array $options = []): array
    {
        $minConfidence = $options['min_confidence'] ?? 0.0;
        $limit = $options['limit'] ?? 100;
        $subjectType = $options['subject_type'] ?? null;
        $objectType = $options['object_type'] ?? null;

        $params = [$predicate, $minConfidence];
        $conditions = ['predicate = ?', 'confidence >= ?'];

        if ($object !== null) {
            $conditions[] = 'LOWER(object) = LOWER(?)';
            $params[] = $object;
        }

        if ($subjectType !== null) {
            $conditions[] = 'subject_type = ?';
            $params[] = $subjectType;
        }

        if ($objectType !== null) {
            $conditions[] = 'object_type = ?';
            $params[] = $objectType;
        }

        // Temporal filter (default: active only)
        $temporal = $this->buildTemporalFilter($options);
        $params = array_merge($params, $temporal['params']);

        $params[] = $limit;

        $sql = '
            SELECT id, source_document_id, subject, subject_type, predicate,
                   object, object_type, confidence, extracted_from,
                   valid_from, valid_until, temporal_type, created_at
            FROM knowledge_graph
            WHERE '.implode(' AND ', $conditions).$temporal['clause'].'
            ORDER BY confidence DESC, created_at DESC
            LIMIT ?
        ';

        $results = DB::connection(self::CONNECTION)->select($sql, $params);

        return array_map(function ($row) {
            return [
                'id' => $row->id,
                'subject' => $row->subject,
                'subject_type' => $row->subject_type,
                'predicate' => $row->predicate,
                'object' => $row->object,
                'object_type' => $row->object_type,
                'confidence' => (float) $row->confidence,
                'extracted_from' => $row->extracted_from,
                'source_document_id' => $row->source_document_id,
                'valid_from' => $row->valid_from,
                'valid_until' => $row->valid_until,
                'temporal_type' => $row->temporal_type,
                'created_at' => $row->created_at,
            ];
        }, $results);
    }

    /**
     * Get or create an entity in the entities table
     *
     * @param  string  $name  Canonical entity name
     * @param  string  $type  Entity type
     * @param  array  $aliases  Alternative names
     * @param  array  $properties  Additional attributes
     * @return int Entity ID
     */
    public function getOrCreateEntity(string $name, string $type, array $aliases = [], array $properties = []): int
    {
        $type = $this->validateEntityType($type);
        $normalizedName = trim($name);

        // Check if entity exists
        $existing = DB::connection(self::CONNECTION)->select('
            SELECT id FROM knowledge_graph_entities
            WHERE LOWER(canonical_name) = LOWER(?) AND entity_type = ?
            LIMIT 1
        ', [$normalizedName, $type]);

        if (! empty($existing)) {
            // Update with any new aliases
            if (! empty($aliases)) {
                DB::connection(self::CONNECTION)->statement('
                    UPDATE knowledge_graph_entities
                    SET aliases = (
                        SELECT jsonb_agg(DISTINCT value)
                        FROM (
                            SELECT jsonb_array_elements_text(aliases) AS value
                            UNION
                            SELECT jsonb_array_elements_text(?::jsonb) AS value
                        ) sub
                    ),
                    updated_at = NOW()
                    WHERE id = ?
                ', [json_encode($aliases), $existing[0]->id]);
            }

            return $existing[0]->id;
        }

        // Create new entity
        $result = DB::connection(self::CONNECTION)->select('
            INSERT INTO knowledge_graph_entities
                (canonical_name, entity_type, aliases, properties, created_at, updated_at)
            VALUES (?, ?, ?::jsonb, ?::jsonb, NOW(), NOW())
            RETURNING id
        ', [
            $normalizedName,
            $type,
            json_encode(array_values(array_unique($aliases))),
            json_encode($properties),
        ]);

        return $result[0]->id;
    }

    /**
     * Search entities by name (including aliases)
     *
     * @param  string  $query  Search query
     * @param  array  $options  Options:
     *                          - types: array - Filter by entity types
     *                          - limit: int
     * @return array Matching entities
     */
    public function searchEntities(string $query, array $options = []): array
    {
        $types = $options['types'] ?? [];
        $limit = $options['limit'] ?? 20;

        $params = ["%{$query}%", "%{$query}%"];
        $typeCondition = '';

        if (! empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $typeCondition = "AND entity_type IN ({$placeholders})";
            $params = array_merge($params, $types);
        }

        $params[] = $limit;

        $sql = "
            SELECT id, canonical_name, entity_type, aliases, properties, created_at
            FROM knowledge_graph_entities
            WHERE (
                canonical_name ILIKE ?
                OR aliases::text ILIKE ?
            )
            {$typeCondition}
            ORDER BY canonical_name
            LIMIT ?
        ";

        $results = DB::connection(self::CONNECTION)->select($sql, $params);

        return array_map(function ($row) {
            return [
                'id' => $row->id,
                'canonical_name' => $row->canonical_name,
                'entity_type' => $row->entity_type,
                'aliases' => json_decode($row->aliases ?? '[]', true),
                'properties' => json_decode($row->properties ?? '{}', true),
                'created_at' => $row->created_at,
            ];
        }, $results);
    }

    /**
     * Get entity information by name
     */
    private function getEntityInfo(string $name): array
    {
        $result = DB::connection(self::CONNECTION)->select('
            SELECT id, canonical_name, entity_type, aliases, properties
            FROM knowledge_graph_entities
            WHERE LOWER(canonical_name) = LOWER(?)
            LIMIT 1
        ', [$name]);

        if (empty($result)) {
            return ['type' => 'other'];
        }

        return [
            'id' => $result[0]->id,
            'canonical_name' => $result[0]->canonical_name,
            'type' => $result[0]->entity_type,
            'aliases' => json_decode($result[0]->aliases ?? '[]', true),
            'properties' => json_decode($result[0]->properties ?? '{}', true),
        ];
    }

    /**
     * Validate and normalize entity type
     */
    private function validateEntityType(mixed $type): string
    {
        // LLM sometimes returns arrays ({"type":"person"} or nested arrays)
        if (is_array($type)) {
            $type = $type['type'] ?? 'other';
        }
        if (! is_string($type)) {
            return 'other';
        }
        $type = strtolower(trim($type));

        return in_array($type, self::ENTITY_TYPES) ? $type : 'other';
    }

    /**
     * Normalize predicate to lowercase_underscore format
     */
    private function normalizePredicate(string $predicate): string
    {
        $predicate = trim($predicate);
        $predicate = preg_replace('/[^a-zA-Z0-9_]/', '_', $predicate);
        $predicate = preg_replace('/_+/', '_', $predicate);

        return strtolower(trim($predicate, '_'));
    }

    /**
     * Parse JSON from AI response
     *
     * Handles various AI response formats including:
     * - Pure JSON
     * GR-3: Fast entity pre-extraction via SpaCy NER.
     * Returns entity list for hybrid prompt, or empty array if SpaCy unavailable.
     */
    private function trySpacyExtraction(string $text): array
    {
        try {
            if ($this->spacyService === null) {
                $this->spacyService = app(SpacyNLPService::class);
            }

            if (! $this->spacyService->isAvailable()) {
                return [];
            }

            $extraction = $this->spacyService->extract($text);
            if (! $extraction) {
                return [];
            }

            // Map SpaCy NER output to entity format for the LLM prompt
            $entities = [];
            $seen = [];

            foreach (['persons' => 'person', 'places' => 'location', 'dates' => 'date'] as $key => $type) {
                foreach ($extraction[$key] ?? [] as $name) {
                    $name = trim($name);
                    $lower = strtolower($name);
                    if (empty($name) || isset($seen[$lower])) {
                        continue;
                    }
                    $seen[$lower] = true;
                    $entities[] = ['name' => $name, 'type' => $type];
                }
            }

            // Organizations from SpaCy ORG entities
            foreach ($extraction['organizations'] ?? $extraction['orgs'] ?? [] as $name) {
                $name = trim($name);
                $lower = strtolower($name);
                if (empty($name) || isset($seen[$lower])) {
                    continue;
                }
                $seen[$lower] = true;
                $entities[] = ['name' => $name, 'type' => 'organization'];
            }

            if (! empty($entities)) {
                Log::info('KnowledgeGraph: SpaCy pre-extraction found entities', [
                    'count' => count($entities),
                    'types' => array_count_values(array_column($entities, 'type')),
                ]);
            }

            return $entities;

        } catch (\Throwable $e) {
            Log::debug('KnowledgeGraph: SpaCy extraction failed, falling back to LLM-only', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * - JSON wrapped in markdown code blocks
     * - JSON with explanatory text before/after
     * - JSON with minor syntax issues (trailing commas, etc)
     */
    private function parseJsonFromResponse(string $response): ?array
    {
        $response = trim(preg_replace('/^\xEF\xBB\xBF/', '', $response));
        $response = $this->stripReasoningWrappers($response);

        // Log raw response for debugging
        Log::debug('KnowledgeGraph: Parsing AI response', [
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500),
        ]);

        foreach ($this->buildJsonParseCandidates($response) as $candidate) {
            $parsed = $this->decodeKnowledgeGraphJson($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        Log::warning('KnowledgeGraph: Failed to parse JSON from response', [
            'response_preview' => substr($response, 0, 1000),
            'json_error' => json_last_error_msg(),
        ]);

        return null;
    }

    /**
     * Strip leading reasoning-trace wrappers emitted by thinking-capable models
     * (Qwen3, DeepSeek-R1, etc.) BEFORE the first JSON payload.
     *
     * Safety contract: if the response already begins with a JSON start
     * character (`{` or `[`) the response is returned untouched, so closing
     * reasoning tags embedded in string values of otherwise-valid JSON payloads
     * cannot be mutated.
     */
    private function stripReasoningWrappers(string $response): string
    {
        $response = trim($response);
        if ($response === '') {
            return $response;
        }

        $firstChar = $response[0];
        if ($firstChar === '{' || $firstChar === '[') {
            return $response;
        }

        $openers = [
            '<think>' => '</think>',
            '<thinking>' => '</thinking>',
            '<reasoning>' => '</reasoning>',
            '<|thinking|>' => '<|/thinking|>',
        ];

        foreach ($openers as $open => $close) {
            if (stripos($response, $open) === 0) {
                $endPos = stripos($response, $close, strlen($open));
                if ($endPos === false) {
                    return $response;
                }

                return ltrim(substr($response, $endPos + strlen($close)));
            }
        }

        $firstJsonStart = $this->firstPlausibleJsonStart($response);
        foreach (['</think>', '</thinking>', '</reasoning>', '<|/thinking|>'] as $closer) {
            $pos = stripos($response, $closer);
            if ($pos === false) {
                continue;
            }
            if ($firstJsonStart !== null && $pos >= $firstJsonStart) {
                continue;
            }

            return ltrim(substr($response, $pos + strlen($closer)));
        }

        return $response;
    }

    private function firstPlausibleJsonStart(string $response): ?int
    {
        $length = strlen($response);

        for ($offset = 0; $offset < $length; $offset++) {
            $char = $response[$offset];
            if ($char !== '{' && $char !== '[') {
                continue;
            }

            if ($this->looksLikeJsonStartAt($response, $offset)) {
                return $offset;
            }
        }

        return null;
    }

    private function looksLikeJsonStartAt(string $response, int $offset): bool
    {
        $length = strlen($response);
        $start = $response[$offset];

        for ($index = $offset + 1; $index < $length; $index++) {
            $char = $response[$index];
            if (ctype_space($char)) {
                continue;
            }

            if ($start === '{') {
                return $char === '"' || $char === '}';
            }

            if ($char === '{' || $char === '[' || $char === '"' || $char === ']' || $char === '-') {
                return true;
            }

            if (ctype_digit($char)) {
                return true;
            }

            return str_starts_with(substr($response, $index), 'true')
                || str_starts_with(substr($response, $index), 'false')
                || str_starts_with(substr($response, $index), 'null');
        }

        return false;
    }

    /**
     * Fix common JSON syntax issues from AI responses
     */
    private function fixJsonSyntax(string $json): string
    {
        // Remove JavaScript-style comments first (before other processing)
        // Line comments: // ... until newline
        $json = preg_replace('/\/\/[^\n]*/', '', $json);
        // Block comments: /* ... */
        $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json);

        // Fix unquoted property names (before value fixes)
        $json = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $json);

        // Fix unquoted string values (handles emails, URLs, etc.)
        // Match ": followed by word chars, @, ., -, etc. until comma, newline, } or ]
        $json = preg_replace_callback(
            '/:\s*([a-zA-Z0-9_.@\-\/]+(?:\.[a-zA-Z0-9_@\-\/]+)*)\s*([,}\]\n])/',
            function ($matches) {
                $value = $matches[1];
                $terminator = $matches[2];
                // Don't quote if it's already a number, true, false, or null
                if (is_numeric($value) || in_array(strtolower($value), ['true', 'false', 'null'])) {
                    return ': '.$value.$terminator;
                }

                return ': "'.$value.'"'.$terminator;
            },
            $json
        );

        // Replace single quotes with double quotes for string values
        $json = preg_replace("/:\s*'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", ': "$1"', $json);

        // Remove trailing commas before } or ]
        $json = preg_replace('/,\s*([}\]])/', '$1', $json);

        // Remove control characters except newlines and tabs
        $json = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $json);

        return trim($json);
    }

    /**
     * Build parse candidates from common LLM response wrappers.
     *
     * @return array<int, string>
     */
    private function buildJsonParseCandidates(string $response): array
    {
        $candidates = [];
        $seen = [];

        $push = function (?string $candidate) use (&$candidates, &$seen): void {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate === '' || isset($seen[$candidate])) {
                return;
            }
            $seen[$candidate] = true;
            $candidates[] = $candidate;
        };

        $push($response);
        $push(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $response));

        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $response, $matches)) {
            foreach ($matches[1] as $match) {
                $push($match);
            }
        }

        $object = $this->extractBalancedJsonBlock($response, '{', '}');
        $push($object);

        $array = $this->extractBalancedJsonBlock($response, '[', ']');
        $push($array);

        return $candidates;
    }

    private function decodeKnowledgeGraphJson(string $candidate): ?array
    {
        $json = json_decode($candidate, true);
        $normalized = $this->normalizeDecodedKnowledgeGraphPayload($json);
        if ($normalized !== null) {
            return $normalized;
        }

        $fixed = $this->fixJsonSyntax($candidate);
        if ($fixed !== $candidate) {
            $json = json_decode($fixed, true);
            $normalized = $this->normalizeDecodedKnowledgeGraphPayload($json);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $closed = $this->closeTruncatedJsonStructure($fixed);
        if ($closed !== $fixed) {
            $json = json_decode($closed, true);
            $normalized = $this->normalizeDecodedKnowledgeGraphPayload($json);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeDecodedKnowledgeGraphPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['entities']) || isset($payload['relationships'])) {
            return $payload;
        }

        foreach (['data', 'result', 'output', 'response', 'payload', 'graph'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $nested = $payload[$key];
            if (is_string($nested)) {
                $nested = json_decode($nested, true);
            }

            $normalized = $this->normalizeDecodedKnowledgeGraphPayload($nested);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (array_is_list($payload)) {
            foreach ($payload as $item) {
                $normalized = $this->normalizeDecodedKnowledgeGraphPayload($item);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function extractBalancedJsonBlock(string $response, string $open, string $close): ?string
    {
        $start = strpos($response, $open);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($response);

        for ($i = $start; $i < $length; $i++) {
            $char = $response[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($response, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function closeTruncatedJsonStructure(string $candidate): string
    {
        $trimmed = trim($candidate);
        if ($trimmed === '') {
            return $trimmed;
        }

        $stack = [];
        $inString = false;
        $escape = false;
        $length = strlen($trimmed);

        for ($i = 0; $i < $length; $i++) {
            $char = $trimmed[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $stack[] = '}';
            } elseif ($char === '[') {
                $stack[] = ']';
            } elseif (($char === '}' || $char === ']') && ! empty($stack) && end($stack) === $char) {
                array_pop($stack);
            }
        }

        if ($inString) {
            $trimmed .= '"';
        }

        while (! empty($stack)) {
            $trimmed .= array_pop($stack);
        }

        return $trimmed;
    }

    /**
     * Get statistics about the knowledge graph
     */
    public function getStatistics(): array
    {
        $tripleCounts = DB::connection(self::CONNECTION)->selectOne('
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE t_expired IS NULL) as active,
                COUNT(*) FILTER (WHERE t_expired IS NOT NULL) as expired
            FROM knowledge_graph
        ');

        $entityCount = DB::connection(self::CONNECTION)->select(
            'SELECT COUNT(*) as count FROM knowledge_graph_entities'
        )[0]->count;

        $predicateStats = DB::connection(self::CONNECTION)->select('
            SELECT predicate, COUNT(*) as count
            FROM knowledge_graph
            WHERE t_expired IS NULL
            GROUP BY predicate
            ORDER BY count DESC
            LIMIT 10
        ');

        $typeStats = DB::connection(self::CONNECTION)->select('
            SELECT entity_type, COUNT(*) as count
            FROM knowledge_graph_entities
            GROUP BY entity_type
            ORDER BY count DESC
        ');

        $avgConfidence = DB::connection(self::CONNECTION)->select(
            'SELECT AVG(confidence) as avg FROM knowledge_graph WHERE t_expired IS NULL'
        )[0]->avg;

        return [
            'total_triples' => (int) $tripleCounts->total,
            'active_triples' => (int) $tripleCounts->active,
            'expired_triples' => (int) $tripleCounts->expired,
            'total_entities' => (int) $entityCount,
            'average_confidence' => round((float) ($avgConfidence ?? 0), 3),
            'top_predicates' => array_map(function ($row) {
                return ['predicate' => $row->predicate, 'count' => (int) $row->count];
            }, $predicateStats),
            'entity_types' => array_map(function ($row) {
                return ['type' => $row->entity_type, 'count' => (int) $row->count];
            }, $typeStats),
        ];
    }

    /**
     * Measure knowledge graph quality across accuracy, freshness, and coverage.
     *
     * @param  array  $options  {sample_size: int, persist: bool}
     * @return array {accuracy, freshness, coverage, composite, details}
     */
    public function getQualityMetrics(array $options = []): array
    {
        $startTime = microtime(true);
        $sampleSize = (int) ($options['sample_size'] ?? 50);
        $persist = (bool) ($options['persist'] ?? false);

        try {
            $accuracy = $this->measureAccuracy($sampleSize);
        } catch (\Throwable $e) {
            Log::warning('KG accuracy measurement failed', ['error' => $e->getMessage()]);
            $accuracy = ['score' => 0, 'sampled' => 0, 'verified' => 0, 'error' => $e->getMessage()];
        }

        try {
            $freshness = $this->measureFreshness();
        } catch (\Throwable $e) {
            Log::warning('KG freshness measurement failed', ['error' => $e->getMessage()]);
            $freshness = ['score' => 0, 'linked' => 0, 'stale' => 0, 'error' => $e->getMessage()];
        }

        try {
            $coverage = $this->measureCoverage();
        } catch (\Throwable $e) {
            Log::warning('KG coverage measurement failed', ['error' => $e->getMessage()]);
            $coverage = ['score' => 0, 'eligible_documents' => 0, 'extracted_documents' => 0, 'orphan_entities' => 0, 'error' => $e->getMessage()];
        }

        $composite = round(
            ($accuracy['score'] * 0.4) + ($freshness['score'] * 0.3) + ($coverage['score'] * 0.3),
            4
        );

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $result = [
            'accuracy' => $accuracy,
            'freshness' => $freshness,
            'coverage' => $coverage,
            'composite_score' => $composite,
            'sample_size' => $sampleSize,
            'duration_ms' => $durationMs,
        ];

        if ($persist) {
            try {
                $this->persistQualityRun($result, $sampleSize);
            } catch (\Throwable $e) {
                Log::error('KG quality persist failed', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Sample triples with source docs and verify subject/object appear in content.
     */
    private function measureAccuracy(int $sampleSize): array
    {
        $samples = DB::connection(self::CONNECTION)->select('
            SELECT kg.id, kg.subject, kg.object, kg.predicate, kg.confidence,
                   rd.content, rd.title
            FROM knowledge_graph kg
            JOIN rag_documents rd ON rd.id = kg.source_document_id
            WHERE kg.source_document_id IS NOT NULL
              AND kg.t_expired IS NULL
              AND rd.content IS NOT NULL
              AND LENGTH(rd.content) > 0
            ORDER BY RANDOM()
            LIMIT ?
        ', [$sampleSize]);

        if (empty($samples)) {
            return ['score' => 0, 'sampled' => 0, 'verified' => 0, 'details' => 'No linked triples found'];
        }

        $verified = 0;
        $sampleDetails = [];

        foreach ($samples as $s) {
            $content = strtolower($s->content.' '.($s->title ?? ''));
            $subjectFound = str_contains($content, strtolower($s->subject));
            $objectFound = str_contains($content, strtolower($s->object));
            $bothFound = $subjectFound && $objectFound;

            if ($bothFound) {
                $verified++;
            }

            $sampleDetails[] = [
                'triple_id' => $s->id,
                'subject_found' => $subjectFound,
                'object_found' => $objectFound,
                'both_found' => $bothFound,
            ];
        }

        $actualSampled = count($samples);

        return [
            'score' => round($verified / $actualSampled, 4),
            'sampled' => $actualSampled,
            'verified' => $verified,
            'sample_details' => $sampleDetails,
        ];
    }

    /**
     * Measure freshness: how many linked triples have docs updated after triple creation.
     */
    private function measureFreshness(): array
    {
        $stats = DB::connection(self::CONNECTION)->selectOne('
            SELECT
                COUNT(*) FILTER (WHERE kg.source_document_id IS NOT NULL AND kg.t_expired IS NULL) as linked,
                COUNT(*) FILTER (
                    WHERE kg.source_document_id IS NOT NULL
                      AND kg.t_expired IS NULL
                      AND rd.updated_at > kg.created_at
                ) as stale
            FROM knowledge_graph kg
            LEFT JOIN rag_documents rd ON rd.id = kg.source_document_id
        ');

        $linked = (int) ($stats->linked ?? 0);
        $stale = (int) ($stats->stale ?? 0);

        if ($linked === 0) {
            return ['score' => 1.0, 'linked' => 0, 'stale' => 0, 'age_buckets' => [], 'temporal' => []];
        }

        $ageBuckets = DB::connection(self::CONNECTION)->select("
            SELECT
                CASE
                    WHEN kg.created_at >= NOW() - INTERVAL '7 days' THEN '0-7d'
                    WHEN kg.created_at >= NOW() - INTERVAL '30 days' THEN '8-30d'
                    WHEN kg.created_at >= NOW() - INTERVAL '90 days' THEN '31-90d'
                    ELSE '90d+'
                END as bucket,
                COUNT(*) as count
            FROM knowledge_graph kg
            WHERE kg.source_document_id IS NOT NULL
              AND kg.t_expired IS NULL
            GROUP BY bucket
            ORDER BY bucket
        ");

        // Temporal metrics
        $temporalStats = DB::connection(self::CONNECTION)->selectOne("
            SELECT
                COUNT(*) as active_total,
                COUNT(*) FILTER (WHERE temporal_type != 'unknown') as with_temporal,
                COUNT(*) FILTER (WHERE valid_until IS NOT NULL AND valid_until < NOW() AND t_expired IS NULL) as stale_valid_time,
                COUNT(*) FILTER (WHERE t_expired IS NOT NULL) as expired_total,
                AVG(temporal_confidence) FILTER (WHERE temporal_confidence IS NOT NULL) as avg_temporal_confidence
            FROM knowledge_graph
        ");

        $activeTotal = (int) ($temporalStats->active_total ?? 0);
        $expiredTotal = (int) ($temporalStats->expired_total ?? 0);
        $allTotal = $activeTotal + $expiredTotal;

        return [
            'score' => round(($linked - $stale) / $linked, 4),
            'linked' => $linked,
            'stale' => $stale,
            'age_buckets' => array_map(fn ($b) => ['bucket' => $b->bucket, 'count' => (int) $b->count], $ageBuckets),
            'temporal' => [
                'temporal_coverage' => $activeTotal > 0
                    ? round((int) $temporalStats->with_temporal / $activeTotal, 4)
                    : 0,
                'stale_valid_time' => (int) ($temporalStats->stale_valid_time ?? 0),
                'invalidation_rate' => $allTotal > 0
                    ? round($expiredTotal / $allTotal, 4)
                    : 0,
                'avg_temporal_confidence' => round((float) ($temporalStats->avg_temporal_confidence ?? 0), 3),
            ],
        ];
    }

    /**
     * Measure coverage: what fraction of eligible docs have KG extraction, orphan entities, avg triples/doc.
     */
    private function measureCoverage(): array
    {
        $docStats = DB::connection(self::CONNECTION)->selectOne('
            SELECT
                COUNT(*) FILTER (WHERE LENGTH(content) > 50) as eligible,
                COUNT(*) FILTER (WHERE kg_extracted_at IS NOT NULL) as extracted
            FROM rag_documents
        ');

        $eligible = (int) ($docStats->eligible ?? 0);
        $extracted = (int) ($docStats->extracted ?? 0);

        $orphans = DB::connection(self::CONNECTION)->selectOne('
            SELECT COUNT(*) as count
            FROM knowledge_graph_entities e
            WHERE NOT EXISTS (
                SELECT 1 FROM knowledge_graph kg
                WHERE (kg.subject_entity_id = e.id OR kg.object_entity_id = e.id)
                  AND kg.t_expired IS NULL
            )
        ');
        $orphanCount = (int) ($orphans->count ?? 0);

        $avgTriples = DB::connection(self::CONNECTION)->selectOne('
            SELECT AVG(triple_count) as avg_triples
            FROM (
                SELECT source_document_id, COUNT(*) as triple_count
                FROM knowledge_graph
                WHERE source_document_id IS NOT NULL
                  AND t_expired IS NULL
                GROUP BY source_document_id
            ) sub
        ');

        $score = $eligible > 0 ? round($extracted / $eligible, 4) : 0;

        return [
            'score' => $score,
            'eligible_documents' => $eligible,
            'extracted_documents' => $extracted,
            'orphan_entities' => $orphanCount,
            'avg_triples_per_doc' => round((float) ($avgTriples->avg_triples ?? 0), 1),
        ];
    }

    /**
     * Persist a quality run to kg_quality_runs.
     */
    private function persistQualityRun(array $result, int $sampleSize): void
    {
        $accuracy = $result['accuracy'];
        $freshness = $result['freshness'];
        $coverage = $result['coverage'];
        $temporal = $freshness['temporal'] ?? [];

        $counts = DB::connection(self::CONNECTION)->selectOne('
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE t_expired IS NULL) as active,
                COUNT(*) FILTER (WHERE t_expired IS NOT NULL) as expired
            FROM knowledge_graph
        ');

        DB::connection(self::CONNECTION)->insert('
            INSERT INTO kg_quality_runs
                (accuracy_score, freshness_score, coverage_score, composite_score,
                 sample_size, sample_details, stale_triple_count, orphan_entity_count,
                 total_triples, total_entities, eligible_documents, extracted_documents,
                 temporal_coverage, stale_valid_time_count, invalidation_rate,
                 active_triple_count, expired_triple_count,
                 duration_ms, created_at)
            VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ', [
            $accuracy['score'],
            $freshness['score'],
            $coverage['score'],
            $result['composite_score'],
            $sampleSize,
            json_encode($accuracy['sample_details'] ?? []),
            $freshness['stale'] ?? 0,
            $coverage['orphan_entities'] ?? 0,
            (int) $counts->total,
            (int) DB::connection(self::CONNECTION)->selectOne('SELECT COUNT(*) as c FROM knowledge_graph_entities')->c,
            $coverage['eligible_documents'] ?? 0,
            $coverage['extracted_documents'] ?? 0,
            $temporal['temporal_coverage'] ?? null,
            $temporal['stale_valid_time'] ?? null,
            $temporal['invalidation_rate'] ?? null,
            (int) $counts->active,
            (int) $counts->expired,
            $result['duration_ms'],
        ]);
    }

    /**
     * Build knowledge graph from a specific RAG document.
     *
     * Fetches the document content, extracts entities + relationships, and persists to KG.
     * Designed as an agent tool — knowledge-curator can target specific documents.
     *
     * @param  int  $documentId  RAG document ID
     * @return array {success, document_id, entities_extracted, triples_created, error?}
     */
    public function buildFromDocument(int $documentId): array
    {
        try {
            // Fetch document from RAG
            $doc = DB::connection(self::CONNECTION)->selectOne(
                'SELECT id, title, content, document_type, kg_extracted_at FROM rag_documents WHERE id = ?',
                [$documentId]
            );

            if (! $doc) {
                return ['success' => false, 'error' => "Document {$documentId} not found"];
            }

            $content = $doc->content ?? '';
            if (strlen($content) < 50) {
                return ['success' => false, 'error' => 'Document content too short for entity extraction'];
            }

            // Truncate to avoid token limits
            if (strlen($content) > 8000) {
                $content = substr($content, 0, 8000);
            }

            // Add title context
            $text = $doc->title ? "Title: {$doc->title}\n\n{$content}" : $content;

            $result = $this->extractEntities($text, [
                'source_document_id' => $documentId,
                'persist' => true,
                'min_confidence' => 0.5,
            ]);

            if ($result['success']) {
                // Mark document as KG-extracted
                DB::connection(self::CONNECTION)->update(
                    'UPDATE rag_documents SET kg_extracted_at = NOW() WHERE id = ?',
                    [$documentId]
                );
            }

            return [
                'success' => $result['success'],
                'document_id' => $documentId,
                'title' => $doc->title,
                'entities_extracted' => count($result['entities'] ?? []),
                'triples_created' => count($result['saved_triple_ids'] ?? []),
                'duration_ms' => $result['duration_ms'] ?? 0,
                'error' => $result['error'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('KnowledgeGraph: buildFromDocument failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find potential duplicate entities using fuzzy name matching.
     *
     * Identifies entity pairs with similar canonical names within the same type,
     * or across types where aliases overlap. Returns candidates for merge review.
     *
     * @param  array  $options  {
     *                          similarity_threshold: float (default 0.8 — trigram similarity),
     *                          limit: int (default 20),
     *                          entity_type: string|null (filter to specific type)
     *                          }
     * @return array [{entity_a: {id, name, type}, entity_b: {id, name, type}, similarity: float, shared_aliases: int}]
     */
    public function findDuplicateEntities(array $options = []): array
    {
        $threshold = $options['similarity_threshold'] ?? 0.8;
        $limit = $options['limit'] ?? 20;
        $entityType = $options['entity_type'] ?? null;

        $params = [$threshold];
        $typeFilter = '';
        if ($entityType) {
            $typeFilter = 'AND a.entity_type = ? AND b.entity_type = ?';
            $params[] = $entityType;
            $params[] = $entityType;
        }
        $params[] = $limit;

        // Check if pg_trgm is available for similarity()
        $hasTrgm = DB::connection(self::CONNECTION)->selectOne(
            "SELECT COUNT(*) as c FROM pg_extension WHERE extname = 'pg_trgm'"
        )->c > 0;

        if ($hasTrgm) {
            $results = DB::connection(self::CONNECTION)->select("
                SELECT
                    a.id as id_a, a.canonical_name as name_a, a.entity_type as type_a,
                    b.id as id_b, b.canonical_name as name_b, b.entity_type as type_b,
                    similarity(LOWER(a.canonical_name), LOWER(b.canonical_name)) as name_sim
                FROM knowledge_graph_entities a
                JOIN knowledge_graph_entities b ON b.id > a.id
                WHERE similarity(LOWER(a.canonical_name), LOWER(b.canonical_name)) >= ?
                {$typeFilter}
                ORDER BY name_sim DESC
                LIMIT ?
            ", $params);
        } else {
            // Fallback: exact prefix match + Levenshtein for short names
            $params = [];
            if ($entityType) {
                $params[] = $entityType;
                $params[] = $entityType;
            }
            $params[] = $limit;

            $results = DB::connection(self::CONNECTION)->select("
                SELECT
                    a.id as id_a, a.canonical_name as name_a, a.entity_type as type_a,
                    b.id as id_b, b.canonical_name as name_b, b.entity_type as type_b,
                    1.0 - (levenshtein(LOWER(a.canonical_name), LOWER(b.canonical_name))::float
                        / GREATEST(LENGTH(a.canonical_name), LENGTH(b.canonical_name), 1)) as name_sim
                FROM knowledge_graph_entities a
                JOIN knowledge_graph_entities b ON b.id > a.id
                WHERE LENGTH(a.canonical_name) <= 100 AND LENGTH(b.canonical_name) <= 100
                  AND levenshtein(LOWER(a.canonical_name), LOWER(b.canonical_name)) <=
                      GREATEST(LENGTH(a.canonical_name), LENGTH(b.canonical_name)) * 0.3
                {$typeFilter}
                ORDER BY name_sim DESC
                LIMIT ?
            ", $params);
        }

        return array_map(function ($row) {
            return [
                'entity_a' => ['id' => $row->id_a, 'name' => $row->name_a, 'type' => $row->type_a],
                'entity_b' => ['id' => $row->id_b, 'name' => $row->name_b, 'type' => $row->type_b],
                'similarity' => round((float) $row->name_sim, 3),
            ];
        }, $results);
    }

    /**
     * Soft-delete (invalidate) a triple by setting t_expired.
     *
     * @param  int  $id  Triple ID
     * @param  array  $options  {reason, superseded_by, actor}
     */
    public function invalidateTriple(int $id, array $options = []): bool
    {
        try {
            $reason = $options['reason'] ?? null;
            $supersededBy = $options['superseded_by'] ?? null;
            $actor = $options['actor'] ?? 'system';

            // Capture old values for history
            $old = DB::connection(self::CONNECTION)->selectOne(
                'SELECT t_expired, superseded_by FROM knowledge_graph WHERE id = ?',
                [$id]
            );

            if (! $old) {
                return false;
            }

            if ($old->t_expired !== null) {
                Log::debug('KnowledgeGraph: Triple already invalidated', ['id' => $id]);

                return true;
            }

            DB::connection(self::CONNECTION)->beginTransaction();

            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph
                SET t_expired = NOW(), superseded_by = ?, updated_at = NOW()
                WHERE id = ? AND t_expired IS NULL
            ', [$supersededBy, $id]);

            // Record in edge history
            DB::connection(self::CONNECTION)->insert('
                INSERT INTO knowledge_graph_edge_history
                    (triple_id, action, old_values, reason, caused_by_triple_id, actor, created_at)
                VALUES (?, ?, ?::jsonb, ?, ?, ?, NOW())
            ', [
                $id,
                $supersededBy ? 'superseded' : 'invalidated',
                json_encode(['t_expired' => null, 'superseded_by' => null]),
                $reason,
                $supersededBy,
                $actor,
            ]);

            DB::connection(self::CONNECTION)->commit();

            return true;

        } catch (\Throwable $e) {
            DB::connection(self::CONNECTION)->rollBack();
            Log::error('KnowledgeGraph: Invalidate triple failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Restore a previously invalidated triple.
     *
     * @param  int  $id  Triple ID
     * @param  array  $options  {reason, actor}
     */
    public function restoreTriple(int $id, array $options = []): bool
    {
        try {
            $reason = $options['reason'] ?? null;
            $actor = $options['actor'] ?? 'system';

            $old = DB::connection(self::CONNECTION)->selectOne(
                'SELECT t_expired, superseded_by FROM knowledge_graph WHERE id = ?',
                [$id]
            );

            if (! $old || $old->t_expired === null) {
                return false;
            }

            DB::connection(self::CONNECTION)->beginTransaction();

            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph
                SET t_expired = NULL, superseded_by = NULL, updated_at = NOW()
                WHERE id = ?
            ', [$id]);

            DB::connection(self::CONNECTION)->insert("
                INSERT INTO knowledge_graph_edge_history
                    (triple_id, action, old_values, reason, actor, created_at)
                VALUES (?, 'restored', ?::jsonb, ?, ?, NOW())
            ", [
                $id,
                json_encode(['t_expired' => $old->t_expired, 'superseded_by' => $old->superseded_by]),
                $reason,
                $actor,
            ]);

            DB::connection(self::CONNECTION)->commit();

            return true;

        } catch (\Throwable $e) {
            DB::connection(self::CONNECTION)->rollBack();
            Log::error('KnowledgeGraph: Restore triple failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the full change history for a triple.
     */
    public function getEdgeHistory(int $tripleId): array
    {
        $rows = DB::connection(self::CONNECTION)->select('
            SELECT id, triple_id, action, old_values, reason,
                   caused_by_triple_id, actor, created_at
            FROM knowledge_graph_edge_history
            WHERE triple_id = ?
            ORDER BY created_at ASC
        ', [$tripleId]);

        return array_map(function ($row) {
            return [
                'id' => $row->id,
                'triple_id' => $row->triple_id,
                'action' => $row->action,
                'old_values' => json_decode($row->old_values ?? '{}', true),
                'reason' => $row->reason,
                'caused_by_triple_id' => $row->caused_by_triple_id,
                'actor' => $row->actor,
                'created_at' => $row->created_at,
            ];
        }, $rows);
    }

    /**
     * Get temporal statistics for the knowledge graph.
     */
    public function getTemporalStats(): array
    {
        $stats = DB::connection(self::CONNECTION)->selectOne("
            SELECT
                COUNT(*) FILTER (WHERE t_expired IS NULL) as active,
                COUNT(*) FILTER (WHERE t_expired IS NOT NULL) as expired,
                COUNT(*) FILTER (WHERE t_expired IS NULL AND temporal_type != 'unknown') as with_temporal,
                COUNT(*) FILTER (WHERE t_expired IS NULL AND valid_until IS NOT NULL AND valid_until < NOW()) as stale_candidates,
                AVG(temporal_confidence) FILTER (WHERE temporal_confidence IS NOT NULL) as avg_confidence
            FROM knowledge_graph
        ");

        $typeDist = DB::connection(self::CONNECTION)->select('
            SELECT temporal_type, COUNT(*) as count
            FROM knowledge_graph
            WHERE t_expired IS NULL
            GROUP BY temporal_type
            ORDER BY count DESC
        ');

        $activeCount = (int) ($stats->active ?? 0);

        return [
            'total_active' => $activeCount,
            'total_expired' => (int) ($stats->expired ?? 0),
            'temporal_coverage' => $activeCount > 0
                ? round((int) $stats->with_temporal / $activeCount, 4)
                : 0,
            'type_distribution' => array_map(function ($row) {
                return ['type' => $row->temporal_type, 'count' => (int) $row->count];
            }, $typeDist),
            'stale_candidates' => (int) ($stats->stale_candidates ?? 0),
            'avg_temporal_confidence' => round((float) ($stats->avg_confidence ?? 0), 3),
        ];
    }

    /**
     * Point-in-time query: find relationships as they existed at a specific date.
     */
    public function findRelationshipsAsOf(string $entity, string $asOfDate, array $options = []): array
    {
        $options['as_of'] = $asOfDate;

        return $this->findRelationships($entity, $options);
    }

    /**
     * Check for active edges that contradict a new triple (same subject+predicate, different object).
     *
     * @return array Conflicting triples
     */
    private function checkForContradictions(string $subject, string $predicate, string $object): array
    {
        $results = DB::connection(self::CONNECTION)->select('
            SELECT id, subject, predicate, object, confidence, created_at
            FROM knowledge_graph
            WHERE LOWER(subject) = LOWER(?)
              AND predicate = ?
              AND LOWER(object) != LOWER(?)
              AND t_expired IS NULL
            ORDER BY confidence DESC
            LIMIT 10
        ', [$subject, $predicate, $object]);

        return array_map(function ($row) {
            return [
                'id' => $row->id,
                'subject' => $row->subject,
                'predicate' => $row->predicate,
                'object' => $row->object,
                'confidence' => (float) $row->confidence,
                'created_at' => $row->created_at,
            ];
        }, $results);
    }

    /**
     * Log a detected contradiction to the contradictions table for review.
     */
    private function logContradiction(int $newTripleId, int $oldTripleId, string $reason = 'kg_inline_contradiction'): void
    {
        try {
            // Fetch both triples for text1/text2
            $newTriple = DB::connection(self::CONNECTION)->selectOne(
                'SELECT subject, predicate, object, confidence FROM knowledge_graph WHERE id = ?',
                [$newTripleId]
            );
            $oldTriple = DB::connection(self::CONNECTION)->selectOne(
                'SELECT subject, predicate, object, confidence FROM knowledge_graph WHERE id = ?',
                [$oldTripleId]
            );

            if (! $newTriple || ! $oldTriple) {
                return;
            }

            $text1 = "{$newTriple->subject} {$newTriple->predicate} {$newTriple->object}";
            $text2 = "{$oldTriple->subject} {$oldTriple->predicate} {$oldTriple->object}";

            DB::connection(self::CONNECTION)->insert('
                INSERT INTO contradictions
                    (text1, text2, contradiction_types, severity, severity_label,
                     detection_details, created_at, updated_at)
                VALUES (?, ?, ?::jsonb, ?, ?, ?::jsonb, NOW(), NOW())
            ', [
                $text1,
                $text2,
                json_encode(['semantic']),
                0.5,
                'moderate',
                json_encode([
                    'source' => 'kg_inline',
                    'new_triple_id' => $newTripleId,
                    'old_triple_id' => $oldTripleId,
                    'new_confidence' => (float) $newTriple->confidence,
                    'old_confidence' => (float) $oldTriple->confidence,
                    'reason' => $reason,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('KnowledgeGraph: Failed to log contradiction', [
                'new_triple_id' => $newTripleId,
                'old_triple_id' => $oldTripleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Soft-delete a triple (invalidate). Use hardDeleteTriple() for permanent removal.
     */
    public function deleteTriple(int $id): bool
    {
        return $this->invalidateTriple($id, ['reason' => 'deleted_via_api', 'actor' => 'user']);
    }

    /**
     * Permanently delete a triple from the database.
     */
    public function hardDeleteTriple(int $id): bool
    {
        $result = DB::connection(self::CONNECTION)->delete(
            'DELETE FROM knowledge_graph WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    /**
     * Delete an entity — invalidates its triples, then deletes the entity record.
     */
    public function deleteEntity(int $entityId): bool
    {
        try {
            DB::connection(self::CONNECTION)->beginTransaction();

            // Invalidate (soft-delete) active triples referencing this entity
            DB::connection(self::CONNECTION)->statement('
                UPDATE knowledge_graph
                SET t_expired = NOW(), updated_at = NOW()
                WHERE (subject_entity_id = ? OR object_entity_id = ?)
                  AND t_expired IS NULL
            ', [$entityId, $entityId]);

            // Record invalidation in history for affected triples
            $affectedTriples = DB::connection(self::CONNECTION)->select('
                SELECT id FROM knowledge_graph
                WHERE (subject_entity_id = ? OR object_entity_id = ?)
                  AND t_expired IS NOT NULL
            ', [$entityId, $entityId]);

            foreach ($affectedTriples as $triple) {
                DB::connection(self::CONNECTION)->insert("
                    INSERT INTO knowledge_graph_edge_history
                        (triple_id, action, reason, actor, created_at)
                    VALUES (?, 'invalidated', ?, 'user', NOW())
                ", [$triple->id, "Entity {$entityId} deleted"]);
            }

            // Delete entity record
            DB::connection(self::CONNECTION)->delete(
                'DELETE FROM knowledge_graph_entities WHERE id = ?',
                [$entityId]
            );

            DB::connection(self::CONNECTION)->commit();

            return true;

        } catch (\Throwable $e) {
            DB::connection(self::CONNECTION)->rollBack();
            Log::error('KnowledgeGraph: Delete entity failed', [
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =========================================================================
    // GR-14: Multi-Modal Graph — Bridge genealogy/files/faces to KG
    // =========================================================================

    /**
     * Bridge genealogy persons into the knowledge graph as genealogy_person entities.
     * Creates entities and family relationship edges (parent_of, married_to, sibling_of).
     *
     * @param  int  $treeId  Genealogy tree ID
     * @param  int  $limit  Max persons to process
     * @return array Stats on entities and edges created
     */
    public function bridgeGenealogyPersons(int $treeId, int $limit = 100): array
    {
        $created = 0;
        $edgesCreated = 0;
        $skipped = 0;

        $persons = DB::select("
            SELECT id, given_name, surname, birth_date, death_date, sex
            FROM genealogy_persons
            WHERE tree_id = ? AND given_name IS NOT NULL AND given_name != ''
            ORDER BY id
            LIMIT ?
        ", [$treeId, $limit]);

        foreach ($persons as $person) {
            $fullName = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
            if (empty($fullName)) {
                $skipped++;

                continue;
            }

            $properties = array_filter([
                'genealogy_person_id' => $person->id,
                'tree_id' => $treeId,
                'birth_date' => $person->birth_date,
                'death_date' => $person->death_date,
                'sex' => $person->sex,
            ]);

            $aliases = [];
            if ($person->given_name && $person->surname) {
                $aliases[] = $person->surname.', '.$person->given_name;
            }

            try {
                $entityId = $this->getOrCreateEntity($fullName, 'genealogy_person', $aliases, $properties);
                $created++;

                // Create family relationship edges
                $families = DB::select('
                    SELECT husband_id, wife_id FROM genealogy_families
                    WHERE tree_id = ? AND (husband_id = ? OR wife_id = ?)
                ', [$treeId, $person->id, $person->id]);

                foreach ($families as $family) {
                    if ($family->husband_id && $family->wife_id
                        && $family->husband_id !== $family->wife_id) {
                        $spouse = DB::selectOne('
                            SELECT given_name, surname FROM genealogy_persons
                            WHERE id = ? AND tree_id = ?
                        ', [
                            $family->husband_id == $person->id ? $family->wife_id : $family->husband_id,
                            $treeId,
                        ]);

                        if ($spouse) {
                            $spouseName = trim(($spouse->given_name ?? '').' '.($spouse->surname ?? ''));
                            if (! empty($spouseName)) {
                                $spouseEntityId = $this->getOrCreateEntity($spouseName, 'genealogy_person');
                                $this->addTriple($fullName, 'married_to', $spouseName, [
                                    'subject_type' => 'genealogy_person',
                                    'object_type' => 'genealogy_person',
                                    'confidence' => 0.95,
                                    'subject_entity_id' => $entityId,
                                    'object_entity_id' => $spouseEntityId,
                                ]);
                                $edgesCreated++;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('KG bridge: Failed to bridge genealogy person', [
                    'person_id' => $person->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        Log::info('KG bridge: Genealogy persons bridged', [
            'tree_id' => $treeId,
            'created' => $created,
            'edges' => $edgesCreated,
            'skipped' => $skipped,
        ]);

        return [
            'success' => true,
            'entities_created' => $created,
            'edges_created' => $edgesCreated,
            'skipped' => $skipped,
        ];
    }
}
