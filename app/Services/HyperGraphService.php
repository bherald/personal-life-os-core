<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GR-11: HyperGraph Edges — N-ary Relations for Complex Multi-Entity Facts
 *
 * Standard binary KG triples (subject → predicate → object) lose information
 * when a fact involves 3+ entities simultaneously. For example:
 *   "John Smith married Mary Jones in Ohio in 1880"
 * involves four entities (John, Mary, Ohio, 1880) bound together in a single
 * semantic event. Decomposing to binary triples either duplicates facts or
 * discards role information.
 *
 * This service extracts, stores, and queries hyperedges — N-ary relations
 * where each participant has a typed role (groom, bride, location, year, etc.).
 *
 * Algorithm:
 *   1. LLM extracts N-ary relations from document text
 *   2. Filter: only keep hyperedges with ≥ MIN_PARTICIPANTS entities
 *   3. Store in knowledge_graph_hyperedges (PostgreSQL/pgsql_rag)
 *   4. Query: retrieve hyperedges matching a set of entity names
 *
 * Reference: HyperGraphRAG (NeurIPS 2025)
 */
class HyperGraphService
{
    use RecursionAware;

    /** Minimum number of participants for a valid hyperedge */
    public const MIN_PARTICIPANTS = 3;

    /** Maximum number of hyperedges to extract per document */
    public const MAX_PER_DOC = 10;

    /** Maximum content snippet sent to LLM for extraction */
    public const SNIPPET_CHARS = 2000;

    public function __construct(private readonly AIService $aiService) {}

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Extract hyperedges from a document and persist them.
     *
     * @param  string $content      Document text
     * @param  int    $documentId   rag_documents.id
     * @return array{extracted: int, stored: int}
     */
    public function buildFromDocument(string $content, int $documentId): array
    {
        $hyperedges = $this->extract($content, $documentId);
        $valid      = $this->filterValid($hyperedges);
        $stored     = empty($valid) ? 0 : $this->store($valid);

        return ['extracted' => count($hyperedges), 'stored' => $stored];
    }

    // =========================================================================
    // Extraction (LLM-based)
    // =========================================================================

    /**
     * Extract N-ary relations from document content using a fast LLM call.
     * Returns an array of raw hyperedge candidate arrays.
     * On AI failure, returns [].
     *
     * @return array[]  Each element: {predicate, participants[], raw_text, confidence}
     */
    public function extract(string $content, int $documentId): array
    {
        // RLM: Try recursive hyperedge extraction
        $rlm = $this->tryRecursive('hyper_graph', 'quality_gate_retry', ['content' => $content, 'document_id' => $documentId], function ($ctx) {
            return $this->extract($ctx['content'] ?? $ctx['data'], $ctx['document_id'] ?? 0);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $snippet = mb_substr(trim($content), 0, self::SNIPPET_CHARS);

        $prompt = "Extract complex multi-entity relationships from the following text. "
            . "A hyperedge is a relationship involving 3 or more entities simultaneously "
            . "(e.g., 'John married Mary in Ohio in 1880' involves 4 entities). "
            . "Return a JSON array (max " . self::MAX_PER_DOC . " items). Each item must have:\n"
            . "- \"predicate\": relationship type (e.g., \"marriage\", \"military_service\", \"land_purchase\")\n"
            . "- \"participants\": array of {\"name\": string, \"role\": string} — minimum 3 participants\n"
            . "- \"raw_text\": the exact supporting text snippet\n"
            . "- \"confidence\": 0.0-1.0\n\n"
            . "Only return relationships with 3+ participants. Binary facts (A does B) are NOT hyperedges.\n\n"
            . "Text:\n{$snippet}";

        $result = $this->aiService->process($prompt, ['model_role' => 'fast', 'max_tokens' => 400]);

        if (!($result['success'] ?? false)) {
            return [];
        }

        $raw = trim($result['response'] ?? '');
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/i', '', trim($raw));

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return array_map(
                    fn($h) => array_merge($h, ['source_document_id' => $documentId]),
                    array_slice($decoded, 0, self::MAX_PER_DOC)
                );
            }
        }

        return [];
    }

    // =========================================================================
    // Filtering (pure)
    // =========================================================================

    /**
     * Filter raw LLM output to valid hyperedges only.
     * A valid hyperedge must:
     *   - have a non-empty predicate string
     *   - have at least MIN_PARTICIPANTS participants
     *   - each participant must have a non-empty name
     *
     * @param  array[] $hyperedges  Raw extraction output
     * @return array[]             Validated hyperedges
     */
    public function filterValid(array $hyperedges): array
    {
        return array_values(array_filter($hyperedges, function ($h) {
            if (empty($h['predicate']) || !is_string($h['predicate'])) {
                return false;
            }
            $participants = $h['participants'] ?? [];
            if (!is_array($participants) || count($participants) < self::MIN_PARTICIPANTS) {
                return false;
            }
            foreach ($participants as $p) {
                if (empty($p['name']) || !is_string($p['name'])) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Normalize participant entries: lowercase names, trim whitespace, ensure role key.
     * Pure — no I/O.
     *
     * @param  array[] $participants  Raw participant objects
     * @return array[]               Normalized participant objects
     */
    public function normalizeParticipants(array $participants): array
    {
        return array_map(function ($p) {
            return [
                'name' => mb_strtolower(trim($p['name'] ?? '')),
                'role' => mb_strtolower(trim($p['role'] ?? 'participant')),
            ];
        }, $participants);
    }

    /**
     * Return all participant names from a set of hyperedges.
     * Pure — no I/O.
     *
     * @param  array[] $hyperedges
     * @return string[]  Unique lowercase names
     */
    public function collectEntityNames(array $hyperedges): array
    {
        $names = [];
        foreach ($hyperedges as $h) {
            foreach ($h['participants'] ?? [] as $p) {
                $name = mb_strtolower(trim($p['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Filter hyperedges to those containing at least one of the given entity names.
     * Case-insensitive match against participant names.
     * Pure — no I/O.
     *
     * @param  array[]  $hyperedges
     * @param  string[] $entityNames
     * @return array[]
     */
    public function filterByEntities(array $hyperedges, array $entityNames): array
    {
        if (empty($entityNames)) {
            return [];
        }
        $lower = array_map('mb_strtolower', $entityNames);

        return array_values(array_filter($hyperedges, function ($h) use ($lower) {
            foreach ($h['participants'] ?? [] as $p) {
                if (in_array(mb_strtolower($p['name'] ?? ''), $lower, true)) {
                    return true;
                }
            }
            return false;
        }));
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /**
     * Insert validated hyperedges into knowledge_graph_hyperedges.
     * Returns the number of rows inserted.
     */
    public function store(array $hyperedges): int
    {
        $count = 0;
        foreach ($hyperedges as $h) {
            try {
                $participants = $this->normalizeParticipants($h['participants'] ?? []);
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO knowledge_graph_hyperedges
                        (source_document_id, predicate, participants, raw_text, confidence, metadata, updated_at)
                    VALUES (?, ?, ?::jsonb, ?, ?, ?::jsonb, NOW())
                ", [
                    (int) $h['source_document_id'],
                    mb_substr(trim($h['predicate']), 0, 100),
                    json_encode($participants),
                    mb_substr(trim($h['raw_text'] ?? ''), 0, 2000),
                    min(1.0, max(0.0, (float) ($h['confidence'] ?? 1.0))),
                    json_encode($h['metadata'] ?? null),
                ]);
                $count++;
            } catch (\Exception $e) {
                Log::warning('HyperGraph: Failed to store hyperedge', [
                    'predicate' => $h['predicate'] ?? '?',
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * Retrieve all hyperedges for a given document.
     *
     * @return array[]
     */
    public function getForDocument(int $documentId): array
    {
        $rows = DB::connection('pgsql_rag')->select("
            SELECT id, predicate, participants, raw_text, confidence, created_at
            FROM   knowledge_graph_hyperedges
            WHERE  source_document_id = ?
            ORDER  BY created_at DESC
        ", [$documentId]);

        return array_map(function ($row) {
            return [
                'id'           => $row->id,
                'predicate'    => $row->predicate,
                'participants' => json_decode($row->participants, true) ?? [],
                'raw_text'     => $row->raw_text,
                'confidence'   => (float) $row->confidence,
            ];
        }, $rows);
    }

    /**
     * Find hyperedges containing any of the given entity names (case-insensitive).
     * Uses PostgreSQL JSONB containment for efficient lookup.
     *
     * @param  string[] $entityNames
     * @return array[]
     */
    public function getForEntities(array $entityNames): array
    {
        if (empty($entityNames)) {
            return [];
        }

        // Build OR conditions for each name
        $conditions = [];
        $params = [];
        foreach ($entityNames as $name) {
            $conditions[] = "participants @> ?::jsonb";
            $params[]     = json_encode([['name' => mb_strtolower(trim($name))]]);
        }

        $where = implode(' OR ', $conditions);

        $rows = DB::connection('pgsql_rag')->select("
            SELECT id, source_document_id, predicate, participants, raw_text, confidence
            FROM   knowledge_graph_hyperedges
            WHERE  {$where}
            ORDER  BY confidence DESC
            LIMIT  50
        ", $params);

        return array_map(function ($row) {
            return [
                'id'                 => $row->id,
                'source_document_id' => $row->source_document_id,
                'predicate'          => $row->predicate,
                'participants'       => json_decode($row->participants, true) ?? [],
                'raw_text'           => $row->raw_text,
                'confidence'         => (float) $row->confidence,
            ];
        }, $rows);
    }

    /**
     * Count hyperedges for a document (used in stats).
     */
    public function countForDocument(int $documentId): int
    {
        $row = DB::connection('pgsql_rag')->selectOne(
            'SELECT COUNT(*) AS cnt FROM knowledge_graph_hyperedges WHERE source_document_id = ?',
            [$documentId]
        );
        return (int) ($row->cnt ?? 0);
    }
}
