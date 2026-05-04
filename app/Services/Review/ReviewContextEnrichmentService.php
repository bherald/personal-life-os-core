<?php

namespace App\Services\Review;

use App\Services\Genealogy\PersonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 of the Genealogy Review UI redesign.
 *
 * Pre-assembles everything the master/detail review pane needs in one
 * call so the frontend doesn't have to round-trip three endpoints to
 * render a side-by-side compare:
 *
 *   - the raw review item (agent_review_queue row + parsed details JSON)
 *   - the on-file person dossier (PersonService::getPerson) when the
 *     review type carries a person_id
 *   - per-field comparison rows (proposed value vs on-file value with
 *     a coarse match_status: same | match | different | new | missing |
 *     conflict). Phase 1 emits "new" rows for source_add proposals and
 *     diff rows for field_name-bearing proposals; deeper classification
 *     (Mills trio, FAN overlap, agent reasoning) lands in Phase 2.
 *
 * Out of scope this phase: source classification, FAN-cluster overlap,
 * agent reasoning narrative, search-coverage breadcrumb. Those grow
 * the same return shape additively in Phase 2 — the controller
 * contract here is intentionally forward-compatible (keys are added,
 * never removed or repurposed).
 *
 * @see docs/research-reviews/2026-04-23-genealogy-review-ui-redesign.md
 */
class ReviewContextEnrichmentService
{
    /**
     * Date fields where a small numeric delta should be flagged as
     * "conflict" rather than just "different" — operator wants the
     * proximity called out.
     */
    private const DATE_FIELDS = ['birth_date', 'death_date', 'marriage_date', 'burial_date'];

    private const AGENT_QUEUE_TOKEN_LOOKUP_TYPES = [
        'genealogy_finding' => true,
        'genealogy_merge' => true,
        'genealogy_review_packet' => true,
    ];

    /**
     * Phase 2: heuristic source-classification table (Mills trio).
     *
     * Pattern → [source_type, information_type, evidence_type, label].
     * Pattern is matched (case-insensitively) against a concatenation of
     * proposal.proposed_value (URL) plus proposal.evidence_sources joined
     * by space. First match wins; falls through to ['unknown','unknown',
     * 'unknown'] when nothing matches. Refining this is cheaper than
     * round-tripping the LLM for every proposal.
     */
    private const SOURCE_CLASSIFICATION_TABLE = [
        // Vital records — birth/death/marriage certificates
        '~vital(?:_|-|\s)?records?~i' => ['original',   'primary',   'direct',   'Vital records'],
        // US Federal Census — original record, primary info on residence/household, secondary on ages/birthdates
        '~(\d{4})\s*us\s*census|us\s*census|census~i' => ['original',   'primary',   'indirect', 'Census'],
        // National Archives — varies by record type, default to original
        '~national\s*archives?|catalog\.archives\.gov|nara~i' => ['original', 'primary', 'direct', 'NARA'],
        // Library of Congress newspapers — original at time of publication
        '~loc\.gov.*chronam|loc\.gov.*newspapers|library\s+of\s+congress~i' => ['original', 'secondary', 'indirect', 'LoC newspapers'],
        // FindAGrave — derivative transcription of headstones; sometimes carries photos of originals
        '~findagrave|find-a-grave~i' => ['derivative', 'secondary', 'direct',   'FindAGrave'],
        // FamilySearch — varies (some indexes derivative, some image originals)
        '~familysearch|family\s+search~i' => ['derivative', 'secondary', 'direct',   'FamilySearch'],
        // Ancestry.com indexes — derivative
        '~ancestry\.com|ancestry\s+library~i' => ['derivative', 'secondary', 'direct',   'Ancestry'],
        // Newspapers.com — digitized originals (period newspapers)
        '~newspapers\.com|newspaperarchive~i' => ['original',   'secondary', 'indirect', 'Newspaper'],
        // Compiled / authored histories
        '~biograph|compiled|published\s+(?:history|genealogy)~i' => ['authored', 'secondary', 'indirect', 'Compiled history'],
        // Wikipedia / Wikitree — authored
        '~wikipedia|wikitree~i' => ['authored',   'secondary', 'indirect', 'Wiki'],
    ];

    public function __construct(private readonly PersonService $personService) {}

    /**
     * Build the enriched detail-pane payload for a single review item.
     *
     * @return array{
     *     item: array<string, mixed>,
     *     person: array<string, mixed>|null,
     *     comparison: array{field_diffs: array<int, array<string, mixed>>},
     *     proposals_summary: array{total: int, by_change_type: array<string, int>},
     * }|null  null when the review row doesn't exist
     */
    public function getContext(string $unifiedId): ?array
    {
        $parsed = $this->parseUnifiedId($unifiedId);
        if ($parsed === null) {
            return null;
        }
        [$type, $id] = $parsed;

        $row = $this->loadReviewRow($type, $id);
        if ($row === null) {
            return null;
        }

        $details = $this->decodeDetails($row->details ?? null);
        $personId = $this->extractPersonId($details);
        $person = $personId !== null ? $this->personService->getPerson($personId) : null;

        // Phase 4: when the review item is a proposed merge, surface BOTH
        // existing persons + impact counts so Layout B can render side-by-
        // side. Other types keep the single `person` slot.
        $mergeContext = $type === 'genealogy_merge'
            ? $this->buildMergeContext($details)
            : null;

        $context = [
            'item' => [
                'id' => (int) $row->id,
                'unified_id' => $unifiedId,
                'review_type' => (string) $row->review_type,
                'agent_id' => (string) ($row->agent_id ?? ''),
                'title' => (string) ($row->title ?? ''),
                'summary' => (string) ($row->summary ?? ''),
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
                'status' => (string) ($row->status ?? 'pending'),
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                'details' => $details,
            ],
            'person' => $person,
            'comparison' => [
                'field_diffs' => $this->computeFieldDiffs($details, $person),
            ],
            'proposals_summary' => $this->summarizeProposals($details),
            // Phase 2 additions — purely additive on the response shape
            'source_classifications' => $this->classifyProposalSources($details),
            'fan_overlap' => $this->computeFanOverlap($details, $person),
            'agent_reasoning' => $this->buildAgentReasoning($row, $details),
            // Phase 4 additions — merge context (null for non-merge types)
            'merge_context' => $mergeContext,
            // Operator UX gap fix: resolve `media #N` references in
            // proposals/evidence to genealogy_media rows so the detail
            // pane can surface a clickable source link instead of a
            // bare "media #13986" string.
            'media_refs' => $this->resolveMediaReferences($details),
        ];

        if ($type === 'genealogy_review_packet') {
            $context = array_merge($context, $this->buildGenealogyReviewPacketContext($details));
        }

        return $context;
    }

    /**
     * Surface packet-specific details as first-class context keys for
     * the Research Hub detail pane. These are read-only projections of
     * agent_review_queue.details; packet review decisions live elsewhere.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function buildGenealogyReviewPacketContext(array $details): array
    {
        return [
            'packet' => $this->detailArray($details, 'packet'),
            'packet_status' => isset($details['packet_status']) && is_scalar($details['packet_status'])
                ? (string) $details['packet_status']
                : null,
            'claims' => $this->detailArray($details, 'claims'),
            'source_locator' => isset($details['source_locator']) && is_scalar($details['source_locator'])
                ? (string) $details['source_locator']
                : null,
            'source_locators' => $this->detailArray($details, 'source_locators'),
            'sources' => $this->detailArray($details, 'sources'),
            'identity' => $this->detailArray($details, 'identity'),
            'privacy' => $this->detailArray($details, 'privacy'),
            'validation' => $this->detailArray($details, 'validation'),
            'apply_preview' => $this->detailArray($details, 'apply_preview'),
            'decision_log' => $this->detailArray($details, 'decision_log'),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<mixed>
     */
    private function detailArray(array $details, string $key): array
    {
        $value = $details[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Find every `media #N` reference in the proposal payload (evidence
     * summary, proposed_value, summary text) and look up the
     * genealogy_media rows. Returns an array of {id, title, file_format,
     * mime_type, media_type, nextcloud_path, view_url} for each unique
     * id found. The view_url goes through the existing
     * /api/media/file?path=... proxy that already serves Nextcloud files
     * with auth.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{id: int, title: string, file_format: ?string, mime_type: ?string, media_type: ?string, nextcloud_path: ?string, file_exists: bool, view_url: ?string}>
     */
    private function resolveMediaReferences(array $details): array
    {
        $haystack = $this->mediaReferenceTextCandidates($details);
        $haystackText = implode(' ', $haystack);
        if ($haystackText === '') {
            return [];
        }

        if (! preg_match_all('/\bmedia\s*#?\s*(\d{1,9})\b/i', $haystackText, $m)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        if ($ids === []) {
            return [];
        }
        // Cap to avoid runaway IN-list on a malformed payload.
        $ids = array_slice($ids, 0, 25);

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "SELECT id, title, file_format, mime_type, media_type, nextcloud_path, file_exists
                 FROM genealogy_media
                 WHERE id IN ({$placeholders})",
                $ids
            );
        } catch (\Throwable $e) {
            return [];
        }

        $outById = [];
        foreach ($rows as $row) {
            $path = (string) ($row->nextcloud_path ?? '');
            $outById[(int) $row->id] = [
                'id' => (int) $row->id,
                'title' => (string) ($row->title ?? ('Media #'.$row->id)),
                'file_format' => $row->file_format,
                'mime_type' => $row->mime_type,
                'media_type' => $row->media_type,
                'nextcloud_path' => $path !== '' ? $path : null,
                'file_exists' => (bool) $row->file_exists,
                'view_url' => $path !== ''
                    ? '/api/media/file?path='.rawurlencode($path)
                    : null,
            ];
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($outById[$id])) {
                $out[] = $outById[$id];
            }
        }

        return $out;
    }

    /**
     * Collect text fields where an agent may have written `media #N`.
     * Review packets can carry those references inside claims or claim.raw
     * instead of proposals, so this remains deliberately display-only and
     * independent from proposal materialization.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, string>
     */
    private function mediaReferenceTextCandidates(array $details): array
    {
        $haystack = [];

        foreach (['summary', 'source_locator', 'packet_label', 'packet_key'] as $key) {
            $this->appendMediaTextCandidate($haystack, $details[$key] ?? null);
        }

        $packet = $details['packet'] ?? null;
        if (is_array($packet)) {
            foreach (['summary', 'description', 'packet_label', 'packet_key'] as $key) {
                $this->appendMediaTextCandidate($haystack, $packet[$key] ?? null);
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                foreach (['evidence_summary', 'proposed_value', 'summary', 'claim', 'claim_text', 'statement'] as $key) {
                    $this->appendMediaTextCandidate($haystack, $proposal[$key] ?? null);
                }
                $this->appendMediaTextLeaves($haystack, $proposal['evidence_sources'] ?? null);
            }
        }

        $claims = $details['claims'] ?? null;
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach ([
                    'claim',
                    'claim_text',
                    'statement',
                    'extracted_claim',
                    'extracted_text',
                    'text',
                    'source_ref',
                    'source_locator',
                    'evidence_summary',
                    'proposed_value',
                ] as $key) {
                    $this->appendMediaTextCandidate($haystack, $claim[$key] ?? null);
                }
                $this->appendMediaTextLeaves($haystack, $claim['raw'] ?? null);
            }
        }

        return $haystack;
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextCandidate(array &$haystack, mixed $value): void
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $haystack[] = trim((string) $value);
        }
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextLeaves(array &$haystack, mixed $value, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }
        if (is_scalar($value)) {
            $this->appendMediaTextCandidate($haystack, $value);

            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $child) {
            $this->appendMediaTextLeaves($haystack, $child, $depth + 1);
        }
    }

    /**
     * Phase 4: assemble both persons + side-by-side field diffs + impact
     * counts for a genealogy_merge review item.
     *
     * Falls back to a partial payload (with a `warning` key) when one of
     * the persons can't be loaded — better to surface "person 2520 not
     * found" than to silently render an empty pane.
     *
     * @param  array<string, mixed>  $details
     * @return array{
     *     persons: array<int, array<string, mixed>|null>,
     *     person_ids: array<int, int>,
     *     field_diffs: array<int, array<string, mixed>>,
     *     impact: array{sources: array<int, int>, families_as_spouse: array<int, int>, children: array<int, int>, events: array<int, int>},
     *     warning: string|null,
     * }|null
     */
    private function buildMergeContext(array $details): ?array
    {
        $personIds = $details['person_ids'] ?? null;
        if (! is_array($personIds) || count($personIds) !== 2) {
            return null;
        }
        $idA = (int) $personIds[0];
        $idB = (int) $personIds[1];
        $a = $this->personService->getPerson($idA);
        $b = $this->personService->getPerson($idB);

        $warning = null;
        if ($a === null || $b === null) {
            $missing = [];
            if ($a === null) {
                $missing[] = $idA;
            }
            if ($b === null) {
                $missing[] = $idB;
            }
            $warning = 'Could not load person(s): '.implode(', ', $missing);
        }

        return [
            'persons' => [$a, $b],
            'person_ids' => [$idA, $idB],
            'field_diffs' => $this->computeMergeFieldDiffs($a, $b),
            'impact' => [
                'sources' => [count($a['media'] ?? []), count($b['media'] ?? [])],
                'families_as_spouse' => [count($a['families_as_spouse'] ?? []), count($b['families_as_spouse'] ?? [])],
                'children' => [
                    $this->countChildrenAcrossFamilies($a),
                    $this->countChildrenAcrossFamilies($b),
                ],
                'events' => [count($a['events'] ?? []), count($b['events'] ?? [])],
            ],
            'warning' => $warning,
        ];
    }

    /**
     * Side-by-side field diff for a merge — same field on both persons,
     * classified same/different/conflict/etc. so the operator can see at
     * a glance which fields agree and which need a winner pick.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array<int, array<string, mixed>>
     */
    private function computeMergeFieldDiffs(?array $a, ?array $b): array
    {
        if ($a === null || $b === null) {
            return [];
        }
        $fields = ['given_name', 'surname', 'sex', 'birth_date', 'birth_place', 'death_date', 'death_place', 'occupation'];
        $diffs = [];
        foreach ($fields as $f) {
            $valA = $a[$f] ?? null;
            $valB = $b[$f] ?? null;
            $status = $this->classifyDiff($f, $valA, $valB);
            $delta = $this->computeDelta($f, $valA, $valB);
            $diffs[] = [
                'change_type' => 'merge_field',
                'field' => $f,
                'on_file' => $valA,        // person A side
                'proposed' => $valB,       // person B side
                'match_status' => $status,
                'delta' => $delta,
                'confidence' => null,
                'evidence_summary' => null,
                'evidence_sources' => [],
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>|null  $person
     */
    private function countChildrenAcrossFamilies(?array $person): int
    {
        if ($person === null) {
            return 0;
        }
        $families = $person['families_as_spouse'] ?? [];
        if (! is_array($families)) {
            return 0;
        }
        $sum = 0;
        foreach ($families as $f) {
            if (is_array($f) && isset($f['children']) && is_array($f['children'])) {
                $sum += count($f['children']);
            }
        }

        return $sum;
    }

    /**
     * Parse a {type}:{id} unified id. Mirrors ReviewTypeRegistryService
     * but kept local so this service has no upward dependency on the
     * registry.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseUnifiedId(string $unifiedId): ?array
    {
        if (! preg_match('/^([a-z_]+):([A-Za-z0-9_-]+)$/', $unifiedId, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Type-aware review-row loader. Each genealogy review type has a
     * different unified_id key shape (per review_type_registry's
     * field_mapping.unified_id_template):
     *
     *   genealogy_finding       →  agent_review_queue.token (hex string)
     *   genealogy_merge         →  agent_review_queue.token
     *   genealogy_review_packet →  agent_review_queue.token
     *   change_proposal         →  genealogy_proposed_changes.id (numeric)
     *   proposal                →  agent_review_queue.id (numeric)
     *
     * Pre-fix Phase 1 assumed `id` for everything which made the
     * detail pane 404 for every real prod genealogy_finding (since
     * those use token in unified_id). Also missed change_proposal
     * entirely (lives in a different table). Both gaps closed here.
     *
     * For agent_review_queue token lookups, falls back to numeric id
     * lookup so existing test fixtures that pass the numeric id keep
     * working. New code should use token.
     */
    private function loadReviewRow(string $type, string $id): ?object
    {
        try {
            if ($type === 'change_proposal') {
                return $this->loadChangeProposalRow($id);
            }
            if (isset(self::AGENT_QUEUE_TOKEN_LOOKUP_TYPES[$type])) {
                $row = DB::selectOne(
                    'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                            confidence, priority, status, token, created_at, expires_at
                     FROM agent_review_queue
                     WHERE token = ? AND review_type = ?',
                    [$id, $type]
                );
                if ($row !== null) {
                    return $row;
                }
                // Fallback: numeric id (test fixtures, legacy callers).
                if (ctype_digit($id)) {
                    return DB::selectOne(
                        'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                                confidence, priority, status, token, created_at, expires_at
                         FROM agent_review_queue
                         WHERE id = ? AND review_type = ?',
                        [(int) $id, $type]
                    );
                }

                return null;
            }

            // Other agent_review_queue types still use id.
            return DB::selectOne(
                'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                        confidence, priority, status, created_at, expires_at
                 FROM agent_review_queue
                 WHERE id = ? AND review_type = ?',
                [$id, $type]
            );
        } catch (\Throwable $e) {
            Log::warning('ReviewContextEnrichmentService: review row lookup failed', [
                'unified_id' => "{$type}:{$id}",
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * change_proposal items live in genealogy_proposed_changes, which
     * has a different schema than agent_review_queue. Synthesize a
     * shape compatible with the rest of the enrichment flow so the
     * downstream comparison/classification/FAN logic just works
     * without per-type branches everywhere. Each row maps to ONE
     * synthesized proposal (these tables hold one change per row,
     * unlike agent_review_queue.details.proposals which can carry many).
     */
    private function loadChangeProposalRow(string $id): ?object
    {
        if (! ctype_digit($id)) {
            return null;
        }
        $row = DB::selectOne(
            'SELECT id, tree_id, person_id, change_type, field_name, current_value, proposed_value,
                    evidence_sources, evidence_summary, confidence, agent_id, status, created_at
             FROM genealogy_proposed_changes
             WHERE id = ?',
            [(int) $id]
        );
        if (! $row) {
            return null;
        }

        $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
        if (! is_array($evidenceSources)) {
            $evidenceSources = [];
        }

        $titleParts = [(string) $row->change_type];
        if (! empty($row->field_name)) {
            $titleParts[] = (string) $row->field_name;
        }
        $titleParts[] = "person #{$row->person_id}";

        $proposal = [
            'person_id' => (int) $row->person_id,
            'change_type' => (string) $row->change_type,
            'field_name' => $row->field_name,
            'proposed_value' => $row->proposed_value,
            'evidence_summary' => $row->evidence_summary,
            'evidence_sources' => $evidenceSources,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
        ];

        return (object) [
            'id' => $row->id,
            'agent_id' => $row->agent_id,
            'review_type' => 'change_proposal',
            'finding_type' => null,
            'title' => implode(' · ', $titleParts),
            'summary' => $row->evidence_summary,
            'details' => json_encode([
                'person_id' => (int) $row->person_id,
                'tree_id' => (int) $row->tree_id,
                'on_file_value' => $row->current_value,
                'proposals' => [$proposal],
            ]),
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'priority' => null,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'expires_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function extractPersonId(array $details): ?int
    {
        $candidate = $this->positiveInt($details['person_id'] ?? null);
        if ($candidate !== null) {
            return $candidate;
        }

        $identity = $details['identity'] ?? null;
        if (is_array($identity)) {
            foreach (['person_id', 'target_person_id'] as $key) {
                $candidate = $this->positiveInt($identity[$key] ?? null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $claims = $details['claims'] ?? null;
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach (['person_id', 'target_person_id'] as $key) {
                    $candidate = $this->positiveInt($claim[$key] ?? null);
                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $candidate = $this->positiveInt($proposal['person_id'] ?? null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $personIds = $details['person_ids'] ?? null;
        if (is_array($personIds)) {
            foreach ($personIds as $candidate) {
                $personId = $this->positiveInt($candidate);
                if ($personId !== null) {
                    return $personId;
                }
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Compute per-field diff rows from the proposals array.
     *
     * Each proposal becomes one diff row. Three shapes today:
     *   - source_add → match_status "new" (additive, no on-file value)
     *   - field_name set + on-file value present → "match" / "different" / "conflict"
     *   - field_name set + on-file value empty → "new"
     *
     * Operator-found temporal-mismatch defect: the genealogy-records
     * agent searches by surname alone — Mary Billington (1652-1718)
     * received Civil War (1861+) pension proposals because "Billington"
     * matched. Each row now carries a `temporal_mismatch` block when
     * the evidence's year references fall outside the person's
     * lifetime ± 50yr margin. Display-time signal; apply-time backstop
     * lives in PersonService::runSourceAddBackstop.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @return array<int, array<string, mixed>>
     */
    private function computeFieldDiffs(array $details, ?array $person): array
    {
        $proposals = $this->reviewProposalItems($details);
        if ($proposals === []) {
            return [];
        }

        $diffs = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $changeType = (string) ($proposal['change_type'] ?? '');
            $fieldName = $proposal['field_name'] ?? null;
            $proposed = $proposal['proposed_value'] ?? null;
            $confidence = isset($proposal['confidence']) ? (float) $proposal['confidence'] : null;
            $evidenceSummary = isset($proposal['evidence_summary']) ? (string) $proposal['evidence_summary'] : null;
            $evidenceSources = $proposal['evidence_sources'] ?? [];
            if (! is_array($evidenceSources)) {
                $evidenceSources = [];
            }

            $temporalMismatch = $this->detectTemporalMismatch($proposal, $person);

            // source_add — additive, no on-file diff
            if ($changeType === 'source_add') {
                $diffs[] = [
                    'change_type' => 'source_add',
                    'field' => 'sources',
                    'on_file' => null,
                    'on_file_count' => $person !== null ? count($person['media'] ?? []) : null,
                    'proposed' => $proposed,
                    'match_status' => $temporalMismatch !== null ? 'conflict' : 'new',
                    'confidence' => $confidence,
                    'evidence_summary' => $evidenceSummary,
                    'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
                    'temporal_mismatch' => $temporalMismatch,
                ];

                continue;
            }

            // Field-bearing proposal (birth_date, death_place, etc.)
            if (is_string($fieldName) && $fieldName !== '') {
                $onFile = $person[$fieldName] ?? null;
                $matchStatus = $this->classifyDiff($fieldName, $onFile, $proposed);
                $delta = $this->computeDelta($fieldName, $onFile, $proposed);

                $diffs[] = [
                    'change_type' => $changeType ?: 'update',
                    'field' => $fieldName,
                    'on_file' => $onFile,
                    'proposed' => $proposed,
                    'match_status' => $temporalMismatch !== null ? 'conflict' : $matchStatus,
                    'delta' => $delta,
                    'confidence' => $confidence,
                    'evidence_summary' => $evidenceSummary,
                    'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
                    'temporal_mismatch' => $temporalMismatch,
                ];

                continue;
            }

            // Catch-all: unknown proposal shape — surface as "other" so
            // the operator at least sees the proposed value rather than
            // having it silently dropped.
            $diffs[] = [
                'change_type' => $changeType ?: 'other',
                'field' => null,
                'on_file' => null,
                'proposed' => $proposed,
                'match_status' => 'unknown',
                'confidence' => $confidence,
                'evidence_summary' => $evidenceSummary,
                'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    private function reviewProposalItems(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            return array_values(array_filter($proposals, 'is_array'));
        }

        $claims = $details['claims'] ?? null;
        if (! is_array($claims)) {
            return [];
        }

        return $this->packetClaimsAsProposals($claims, $details);
    }

    /**
     * Project review-packet claims into the existing comparison/proposal shape
     * so packet details can render in the current Research Hub diff contract.
     *
     * @param  array<int, mixed>  $claims
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    private function packetClaimsAsProposals(array $claims, array $details): array
    {
        $items = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $raw = $claim['raw'] ?? [];
            if (! is_array($raw)) {
                $raw = [];
            }

            $evidenceSources = [];
            foreach ([
                $claim['source_ref'] ?? null,
                $raw['source_ref'] ?? null,
                $raw['source_locator'] ?? null,
                $details['source_locator'] ?? null,
            ] as $source) {
                if (is_scalar($source) && trim((string) $source) !== '') {
                    $evidenceSources[] = trim((string) $source);
                }
            }
            $sourceLocators = $details['source_locators'] ?? [];
            if (is_array($sourceLocators)) {
                foreach ($sourceLocators as $source) {
                    if (is_scalar($source) && trim((string) $source) !== '') {
                        $evidenceSources[] = trim((string) $source);
                    }
                }
            }

            $items[] = [
                'change_type' => $this->firstScalarText($claim, ['change_type'])
                    ?: $this->firstScalarText($raw, ['change_type'])
                    ?: 'claim',
                'field_name' => $this->firstScalarText($claim, ['field_name'])
                    ?: $this->firstScalarText($raw, ['field_name']),
                'proposed_value' => $this->firstScalarText($claim, ['proposed_value', 'value'])
                    ?: $this->firstScalarText($raw, ['proposed_value', 'value', 'claim', 'claim_text', 'statement', 'extracted_claim'])
                    ?: $this->firstScalarText($claim, ['claim']),
                'confidence' => $claim['confidence'] ?? $raw['confidence'] ?? null,
                'evidence_summary' => $this->firstScalarText($claim, ['claim'])
                    ?: $this->firstScalarText($raw, ['evidence_summary', 'summary', 'claim', 'claim_text', 'statement', 'extracted_claim']),
                'evidence_sources' => array_values(array_unique($evidenceSources)),
                'person_id' => $this->positiveInt($claim['person_id'] ?? null)
                    ?? $this->positiveInt($claim['target_person_id'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstScalarText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Detect when a proposal's evidence references years that fall
     * outside the person's lifetime by more than ±50 years.
     *
     * Reasonable margin:
     *   birth_year - 50  (parents' marriage records, ancestral context)
     *   death_year + 100 (estate, descendants' references citing ancestor)
     *
     * Returns null when:
     *   - person has no birth_date AND no death_date (can't compare)
     *   - evidence has no extractable years (can't fail)
     *   - all extractable years fall within the lifetime + margin
     *
     * Returns {worst_year, person_birth, person_death, gap_years,
     * matched_years, severity} when at least one year is clearly
     * outside the range. severity = 'far' (>100yr off either side) or
     * 'near' (50-100yr).
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>|null  $person
     * @return array{worst_year:int, person_birth:?int, person_death:?int, gap_years:int, matched_years:array<int,int>, severity:string}|null
     */
    private function detectTemporalMismatch(array $proposal, ?array $person): ?array
    {
        if ($person === null) {
            return null;
        }
        $birth = $this->extractYear($person['birth_date'] ?? null);
        $death = $this->extractYear($person['death_date'] ?? null);
        if ($birth === null && $death === null) {
            return null;
        }
        // If we only have one anchor, assume a 100-year lifespan to bound the other.
        $rangeStart = $birth ?? ($death - 100);
        $rangeEnd = $death ?? ($birth + 100);
        // Margin: birth - 50 ≤ allowed ≤ death + 100
        $allowedMin = $rangeStart - 50;
        $allowedMax = $rangeEnd + 100;

        $haystack = trim(
            (string) ($proposal['evidence_summary'] ?? '').' '.
            (string) ($proposal['proposed_value'] ?? '')
        );
        if ($haystack === '') {
            return null;
        }

        // Extract 4-digit years 1500-2099 (genealogy-relevant range).
        if (! preg_match_all('/\b(1[5-9]\d{2}|20\d{2})\b/', $haystack, $m)) {
            return null;
        }
        $years = array_values(array_unique(array_map('intval', $m[1])));
        sort($years);

        // Any year in range = the source has at least some lifetime overlap.
        // Bail (no mismatch) so we don't false-positive on a Civil War source
        // that happens to mention "1730" in a footnote.
        $inRange = array_filter($years, fn (int $y) => $y >= $allowedMin && $y <= $allowedMax);
        if ($inRange !== []) {
            return null;
        }

        // No year in range — pick the year closest to the lifetime as the
        // "worst" and report the gap.
        // Gap is reported relative to the ACTUAL lifetime (birth or
        // death), not the +50/+100 allowed-range edge — operator
        // mental model is "146 years past death," not "46 years past
        // my arbitrary margin." The allowance only gates whether
        // we flag at all.
        $worst = $years[0];
        $gap = 0;
        foreach ($years as $y) {
            $thisGap = ($y < $rangeStart) ? ($rangeStart - $y) : ($y - $rangeEnd);
            if ($thisGap > $gap) {
                $worst = $y;
                $gap = $thisGap;
            }
        }
        // Severity: > 100 actual years off lifetime is "far" (e.g.,
        // Civil War source for someone who died in the 1700s); 50-100
        // is "near" (Revolutionary War for someone who died in the
        // 1720s); under 50 wouldn't have flagged in the first place
        // because of the allowed margin.
        $severity = $gap > 100 ? 'far' : 'near';

        return [
            'worst_year' => $worst,
            'person_birth' => $birth,
            'person_death' => $death,
            'gap_years' => $gap,
            'matched_years' => $years,
            'severity' => $severity,
        ];
    }

    /**
     * Pull the first 4-digit year from a genealogy date string.
     * Handles "1652", "30 SEP 1630", "abt 1700", "1700-1750", etc.
     */
    private function extractYear(mixed $dateStr): ?int
    {
        if (! is_scalar($dateStr)) {
            return null;
        }
        if (! preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', (string) $dateStr, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Coarse match classifier: same | match | different | new | missing | conflict.
     *
     * Phase 1 keeps this deliberately simple — string-equality with case
     * normalization. Date fields trigger "conflict" instead of generic
     * "different" so the conflict bar can highlight them. Phase 2 will
     * grow this with name-variant matching (Soundex etc.) and place
     * proximity.
     */
    private function classifyDiff(string $field, mixed $onFile, mixed $proposed): string
    {
        $onFileEmpty = $onFile === null || $onFile === '' || $onFile === [];
        $proposedEmpty = $proposed === null || $proposed === '' || $proposed === [];

        if ($onFileEmpty && $proposedEmpty) {
            return 'same';
        }
        if ($onFileEmpty) {
            return 'new';
        }
        if ($proposedEmpty) {
            return 'missing';
        }

        $a = is_scalar($onFile) ? strtolower(trim((string) $onFile)) : '';
        $b = is_scalar($proposed) ? strtolower(trim((string) $proposed)) : '';
        if ($a === $b) {
            return 'same';
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return 'conflict';
        }

        return 'different';
    }

    /**
     * For date fields, surface the year delta so the conflict bar can
     * say "Δ2yr" inline. Returns null for non-date fields or when
     * either value is unparseable.
     */
    private function computeDelta(string $field, mixed $onFile, mixed $proposed): ?string
    {
        if (! in_array($field, self::DATE_FIELDS, true)) {
            return null;
        }
        $onFileTs = is_scalar($onFile) ? strtotime((string) $onFile) : false;
        $proposedTs = is_scalar($proposed) ? strtotime((string) $proposed) : false;
        if ($onFileTs === false || $proposedTs === false) {
            return null;
        }
        $diffYears = (int) round(abs($onFileTs - $proposedTs) / (60 * 60 * 24 * 365.25));

        return $diffYears === 0
            ? null
            : "Δ{$diffYears}yr";
    }

    // ============================================================
    // Phase 2 — source classification + FAN overlap + agent reasoning
    // ============================================================

    /**
     * Classify each proposal's source against the Mills trio
     * (source_type / information_type / evidence_type) using the
     * heuristic table. One entry per proposal — index aligns with
     * field_diffs index so the frontend can join them visually.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{
     *     proposal_index: int,
     *     source_type: string,
     *     information_type: string,
     *     evidence_type: string,
     *     label: string,
     *     matched_pattern: string|null,
     *     source_text: string,
     * }>
     */
    private function classifyProposalSources(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals)) {
            return [];
        }

        $out = [];
        foreach ($proposals as $idx => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $sourceText = $this->buildSourceClassificationText($proposal);

            // Follow-up 2: prefer agent-supplied source_classification when
            // the agent emitted a valid trio. Falls back to heuristic when
            // the agent didn't classify (or emitted garbage).
            $agentCls = $this->extractAgentSourceClassification($proposal);
            if ($agentCls !== null) {
                $out[] = [
                    'proposal_index' => $idx,
                    'source_type' => $agentCls['source_type'],
                    'information_type' => $agentCls['information_type'],
                    'evidence_type' => $agentCls['evidence_type'],
                    'label' => $agentCls['label'] ?? 'Agent-classified',
                    'matched_pattern' => null,          // n/a for agent-supplied
                    'source_text' => $sourceText,
                    'source' => 'agent',                // so the UI/future can
                    // distinguish agent-
                    // supplied from heuristic
                ];

                continue;
            }

            [$sourceType, $infoType, $evidenceType, $label, $matched] = $this->classifyText($sourceText);

            $out[] = [
                'proposal_index' => $idx,
                'source_type' => $sourceType,
                'information_type' => $infoType,
                'evidence_type' => $evidenceType,
                'label' => $label,
                'matched_pattern' => $matched,
                'source_text' => $sourceText,
                'source' => 'heuristic',
            ];
        }

        return $out;
    }

    /**
     * Pull a valid Mills trio out of an agent-emitted proposal, if present.
     * Expected shape: proposal.source_classification = { source_type,
     * information_type, evidence_type, label? }.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{source_type: string, information_type: string, evidence_type: string, label?: string}|null
     */
    private function extractAgentSourceClassification(array $proposal): ?array
    {
        $cls = $proposal['source_classification'] ?? null;
        if (! is_array($cls)) {
            return null;
        }
        $src = (string) ($cls['source_type'] ?? '');
        $inf = (string) ($cls['information_type'] ?? '');
        $ev = (string) ($cls['evidence_type'] ?? '');
        $allowedSrc = ['original', 'derivative', 'authored', 'unknown'];
        $allowedInf = ['primary', 'secondary', 'undetermined', 'unknown'];
        $allowedEv = ['direct', 'indirect', 'negative', 'unknown'];
        if (! in_array($src, $allowedSrc, true) || ! in_array($inf, $allowedInf, true) || ! in_array($ev, $allowedEv, true)) {
            return null;
        }
        $out = [
            'source_type' => $src,
            'information_type' => $inf,
            'evidence_type' => $ev,
        ];
        if (isset($cls['label']) && is_string($cls['label']) && $cls['label'] !== '') {
            $out['label'] = $cls['label'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function buildSourceClassificationText(array $proposal): string
    {
        $parts = [];
        if (isset($proposal['proposed_value']) && is_string($proposal['proposed_value'])) {
            $parts[] = $proposal['proposed_value'];
        }
        $sources = $proposal['evidence_sources'] ?? null;
        if (is_array($sources)) {
            foreach ($sources as $s) {
                if (is_string($s)) {
                    $parts[] = $s;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string|null}
     */
    private function classifyText(string $text): array
    {
        if ($text === '') {
            return ['unknown', 'unknown', 'unknown', 'Unknown source', null];
        }
        foreach (self::SOURCE_CLASSIFICATION_TABLE as $pattern => $tuple) {
            if (preg_match($pattern, $text)) {
                return [$tuple[0], $tuple[1], $tuple[2], $tuple[3], $pattern];
            }
        }

        return ['unknown', 'unknown', 'unknown', 'Unclassified', null];
    }

    /**
     * Compute name-based FAN-cluster overlap between names mentioned in
     * the evidence summaries and persons already in the same tree.
     *
     * Heuristic: extract title-cased multi-word tokens from the
     * evidence_summary, match against genealogy_persons by full-name
     * substring within the same tree. False positives are tolerable —
     * the operator will see the matches as "potential overlap" not
     * confirmed identity. Skipped entirely when no person dossier
     * is available (no tree context).
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @return array<int, array{
     *     name: string,
     *     matched_person_id: int,
     *     matched_person_name: string,
     *     mentioned_in: string,
     * }>
     */
    private function computeFanOverlap(array $details, ?array $person): array
    {
        if ($person === null || ! isset($person['tree_id'])) {
            return [];
        }
        $treeId = (int) $person['tree_id'];
        if ($treeId <= 0) {
            return [];
        }

        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals)) {
            return [];
        }

        // F-02 fix: enforce the 25-candidate cap inside both inner
        // loops (not just at the proposal boundary). Pre-fix a single
        // proposal carrying 50 fan_members produced 50 sequential
        // LIKE queries because the cap was only checked at end-of-
        // proposal. Cap unifies agent + heuristic contributions.
        $candidateNames = [];
        $candidateCap = 25;
        foreach ($proposals as $idx => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            // Follow-up 2: prefer agent-supplied FAN list when present.
            // Shape: proposal.fan_members = [{name, role?}, ...]. Names
            // added here skip the heuristic regex extraction step, so
            // the agent's judgment wins over the regex.
            $agentFan = $proposal['fan_members'] ?? null;
            if (is_array($agentFan)) {
                foreach ($agentFan as $m) {
                    if (count($candidateNames) >= $candidateCap) {
                        break;
                    }
                    if (! is_array($m) || ! isset($m['name']) || ! is_string($m['name'])) {
                        continue;
                    }
                    $name = trim($m['name']);
                    if ($name === '' || $this->namesMatch($name, $person)) {
                        continue;
                    }
                    $key = strtolower($name);
                    if (! isset($candidateNames[$key])) {
                        $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : 'mentioned';
                        $candidateNames[$key] = [
                            'name' => $name,
                            'mentioned_in' => "Agent-extracted ({$role})",
                        ];
                    }
                }
            }

            // Heuristic fallback from evidence_summary text.
            if (isset($proposal['evidence_summary'])) {
                $summary = (string) $proposal['evidence_summary'];
                foreach ($this->extractCandidateNames($summary) as $name) {
                    if (count($candidateNames) >= $candidateCap) {
                        break;
                    }
                    if ($this->namesMatch($name, $person)) {
                        continue;
                    }
                    $key = strtolower($name);
                    if (! isset($candidateNames[$key])) {
                        $candidateNames[$key] = ['name' => $name, 'mentioned_in' => $summary];
                    }
                }
            }

            if (count($candidateNames) >= $candidateCap) {
                break;
            }
        }
        if ($candidateNames === []) {
            return [];
        }

        $matches = [];
        try {
            foreach ($candidateNames as $cand) {
                // F-01 fix: escape LIKE metacharacters in the
                // agent/operator-controlled name before binding. The
                // value is parameterized (no SQLi) but `%` and `_` would
                // otherwise act as wildcards — an agent emitting
                // {"name":"%"} would match every tree person up to the
                // LIMIT. Escape backslash first so subsequent escapes
                // aren't double-escaped.
                $likeSafe = str_replace(
                    ['\\', '%', '_'],
                    ['\\\\', '\\%', '\\_'],
                    $cand['name']
                );
                $rows = DB::select(
                    "SELECT id, given_name, surname
                     FROM genealogy_persons
                     WHERE tree_id = ?
                       AND CONCAT_WS(' ', COALESCE(given_name,''), COALESCE(surname,'')) LIKE ?
                     LIMIT 3",
                    [$treeId, '%'.$likeSafe.'%']
                );
                foreach ($rows as $row) {
                    $personName = trim(($row->given_name ?? '').' '.($row->surname ?? ''));
                    if ($personName === '') {
                        continue;
                    }
                    $matches[] = [
                        'name' => $cand['name'],
                        'matched_person_id' => (int) $row->id,
                        'matched_person_name' => $personName,
                        'mentioned_in' => $cand['mentioned_in'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('ReviewContextEnrichmentService: FAN overlap query failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $matches;
    }

    /**
     * Pull title-cased multi-word tokens that look like personal names
     * out of free text. Skips dictionary-common surnames the agent is
     * unlikely to mean ("Library of Congress" → 0 hits, not 1).
     *
     * @return array<int, string>
     */
    private function extractCandidateNames(string $text): array
    {
        // Match Tite Cased pairs/triples: "Jacob Cochran", "Mary O'Brien"
        // Allow apostrophes inside surname.
        if (! preg_match_all(
            "/\b([A-Z][a-z]+(?:\s+(?:[A-Z][a-z]+|[A-Z][a-z']+|[A-Z]\.))+)\b/",
            $text,
            $m
        )) {
            return [];
        }
        $blocked = [
            'Library Of Congress', 'National Archives', 'Family Search',
            'Find A Grave', 'United States', 'New York', 'New Jersey',
            'United Kingdom', 'Family Tree', 'Census Bureau', 'World War',
        ];
        $blockedLc = array_map('strtolower', $blocked);
        $out = [];
        foreach ($m[1] as $match) {
            $clean = trim($match);
            if (in_array(strtolower($clean), $blockedLc, true)) {
                continue;
            }
            // Require at least 2 words and reasonable length
            if (substr_count($clean, ' ') < 1) {
                continue;
            }
            if (mb_strlen($clean) < 6 || mb_strlen($clean) > 60) {
                continue;
            }
            $out[$clean] = true; // dedupe via key
        }

        return array_keys($out);
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function namesMatch(string $candidate, array $person): bool
    {
        $personName = trim(
            ($person['given_name'] ?? '').' '.($person['surname'] ?? '')
        );
        if ($personName === '') {
            return false;
        }

        return strcasecmp(trim($candidate), $personName) === 0;
    }

    /**
     * Build the agent_reasoning panel payload — narrative + confidence
     * drivers + search coverage. Phase 2 derives drivers heuristically
     * from the proposal payload itself; agents will surface richer
     * signals over time and this method becomes a passthrough.
     *
     * Search coverage looks at recent agent_episodes for the same
     * agent_id, capped to 24h, so the operator sees what the agent
     * actually exercised this session even when proposals don't carry
     * the breadcrumb.
     *
     * @param  array<string, mixed>  $details
     * @return array{
     *     narrative: string|null,
     *     confidence_drivers: array<int, array{feature: string, weight: float, note: string}>,
     *     search_coverage: array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int},
     * }
     */
    private function buildAgentReasoning(object $row, array $details): array
    {
        $narrative = $this->extractAgentNarrative($details);
        if ($narrative === null) {
            $summary = trim((string) ($row->summary ?? ''));
            $title = trim((string) ($row->title ?? ''));
            $narrative = $summary !== ''
                ? $summary
                : ($title !== '' ? "Review item submitted by {$row->agent_id}: {$title}." : null);
        }
        $drivers = $this->deriveConfidenceDrivers($details);

        // Follow-up 2: prefer agent-supplied search_coverage when it's
        // present on the payload. The agent knows exactly what it ran
        // this session; the episode query is a retrospective guess.
        $coverage = $this->extractAgentSearchCoverage($details)
            ?? $this->loadSearchCoverage((string) ($row->agent_id ?? ''));

        return [
            'narrative' => $narrative,
            'confidence_drivers' => $drivers,
            'search_coverage' => $coverage,
        ];
    }

    /**
     * Extract agent-supplied search coverage from details.search_coverage.
     * Expected shape: {repositories_consulted: [...], queries_run: [...],
     * gaps: [...], episode_count?: int, window_hours?: int}. Returns null
     * when not supplied so the caller can fall back to the DB scan.
     *
     * @param  array<string, mixed>  $details
     * @return array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int, queries_run?: array<int, string>, gaps?: array<int, string>, source: string}|null
     */
    private function extractAgentSearchCoverage(array $details): ?array
    {
        $cov = $details['search_coverage'] ?? null;
        if (! is_array($cov)) {
            return null;
        }
        $repos = is_array($cov['repositories_consulted'] ?? null)
            ? array_values(array_filter($cov['repositories_consulted'], 'is_string'))
            : null;
        if ($repos === null) {
            return null;
        }
        sort($repos);
        $out = [
            'repositories_consulted' => $repos,
            'episode_count' => (int) ($cov['episode_count'] ?? 0),
            'window_hours' => (int) ($cov['window_hours'] ?? 24),
            'source' => 'agent',
        ];
        if (is_array($cov['queries_run'] ?? null)) {
            $out['queries_run'] = array_values(array_filter($cov['queries_run'], 'is_string'));
        }
        if (is_array($cov['gaps'] ?? null)) {
            $out['gaps'] = array_values(array_filter($cov['gaps'], 'is_string'));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function extractAgentNarrative(array $details): ?string
    {
        // Prefer an explicit reasoning field if the agent provided one.
        foreach (['reasoning', 'agent_reasoning', 'analysis_notes', 'outcome_reason', 'scope_reason'] as $key) {
            if (isset($details[$key]) && is_string($details[$key]) && $details[$key] !== '') {
                return $details[$key];
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $summary = trim((string) ($proposal['evidence_summary'] ?? ''));
                if ($summary !== '') {
                    return $summary;
                }
            }
        }

        // Fallback: synthesize from filtered/raw proposal counts so the
        // operator at least sees what the agent did vs filtered out.
        $raw = isset($details['raw_proposal_count']) ? (int) $details['raw_proposal_count'] : null;
        $filtered = isset($details['filtered_out_count']) ? (int) $details['filtered_out_count'] : null;
        if ($raw === null && $filtered === null) {
            return null;
        }
        $kept = $raw !== null && $filtered !== null ? max(0, $raw - $filtered) : null;
        $parts = [];
        if ($raw !== null) {
            $parts[] = "{$raw} candidate(s) returned by upstream search";
        }
        if ($filtered !== null) {
            $parts[] = "{$filtered} filtered out by relevance/proximity gates";
        }
        if ($kept !== null) {
            $parts[] = "{$kept} surfaced for review";
        }

        return $parts === [] ? null : implode('; ', $parts).'.';
    }

    /**
     * Coarse confidence-driver derivation. Phase 2 emits these from the
     * proposal payload alone; Phase 3+ can replace with agent-supplied
     * features without breaking the response shape.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{feature: string, weight: float, note: string}>
     */
    private function deriveConfidenceDrivers(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals) || $proposals === []) {
            return [];
        }
        $sourceCounts = [];
        $maxConfidence = 0.0;
        $changeTypes = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $maxConfidence = max($maxConfidence, (float) ($proposal['confidence'] ?? 0));
            foreach ((array) ($proposal['evidence_sources'] ?? []) as $s) {
                if (is_string($s)) {
                    $sourceCounts[$s] = ($sourceCounts[$s] ?? 0) + 1;
                }
            }
            $changeTypes[(string) ($proposal['change_type'] ?? 'unknown')] = true;
        }
        $drivers = [];
        if ($sourceCounts !== []) {
            $top = array_key_first($sourceCounts);
            $drivers[] = [
                'feature' => 'source_diversity',
                'weight' => min(1.0, count($sourceCounts) / 5.0),
                'note' => count($sourceCounts).' distinct source(s); top: '.$top,
            ];
        }
        if ($maxConfidence > 0) {
            $drivers[] = [
                'feature' => 'top_proposal_confidence',
                'weight' => $maxConfidence,
                'note' => 'highest individual proposal confidence',
            ];
        }
        if (count($changeTypes) > 1) {
            $drivers[] = [
                'feature' => 'change_type_breadth',
                'weight' => min(1.0, count($changeTypes) / 4.0),
                'note' => 'proposals span '.count($changeTypes).' change type(s)',
            ];
        }

        return $drivers;
    }

    /**
     * Pull recent agent episode coverage for context. Returns counts +
     * a deduped tool/repository list so the panel can show "this agent
     * touched [Library of Congress, NARA, FamilySearch] in the last
     * 24h." Falls back to empty on any query failure.
     *
     * @return array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int}
     */
    private function loadSearchCoverage(string $agentId): array
    {
        $window = 24;
        $coverage = [
            'repositories_consulted' => [],
            'episode_count' => 0,
            'window_hours' => $window,
        ];
        if ($agentId === '') {
            return $coverage;
        }
        try {
            $rows = DB::select(
                'SELECT details FROM agent_episodes
                 WHERE agent_id = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY created_at DESC
                 LIMIT 50',
                [$agentId, $window]
            );
        } catch (\Throwable $e) {
            return $coverage;
        }

        $repos = [];
        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                continue;
            }
            // Look for tool/repo signals in common shapes.
            foreach (['tools_used', 'repositories', 'sources', 'tool_names'] as $key) {
                $val = $details[$key] ?? null;
                if (is_array($val)) {
                    foreach ($val as $v) {
                        if (is_string($v)) {
                            $repos[$v] = true;
                        }
                    }
                }
            }
        }
        $coverage['episode_count'] = count($rows);
        $coverage['repositories_consulted'] = array_keys($repos);
        sort($coverage['repositories_consulted']);

        return $coverage;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{total: int, by_change_type: array<string, int>}
     */
    private function summarizeProposals(array $details): array
    {
        $proposals = $this->reviewProposalItems($details);
        if ($proposals === []) {
            return ['total' => 0, 'by_change_type' => []];
        }
        $byType = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $type = (string) ($proposal['change_type'] ?? 'unknown');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return ['total' => count($proposals), 'by_change_type' => $byType];
    }
}
