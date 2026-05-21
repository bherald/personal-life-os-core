<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHARED DISPLAY SEAM for genealogy proposal review screens.
 *
 * Read-only service that loads existing genealogy proposal rows
 * (genealogy_proposed_changes and genealogy_proposed_relationships)
 * into a human-readable queue/detail shape suitable for UI display.
 *
 * Three entry points:
 *   loadByRunKey()        — proposals tied to a specific intake run (by tree + time window)
 *   loadByProposalIds()   — explicit list of known proposal IDs
 *   loadByTreeAndStatus() — all proposals for a tree filtered by status
 *
 * Use this service for:
 *   - Run-scoped proposal queues (browsing all proposals in an intake run)
 *   - Tree-scoped proposal queues (pending/approved/rejected/applied across a tree)
 *   - Ad-hoc proposal display from explicit ID lists
 *
 * Do NOT use this service for the generated-proposals packet endpoint.
 * That endpoint uses GenealogyIntakeGeneratedProposalQueryService (read-only),
 * while approve/reject actions flow through the canonical unified review API.
 * Packet-level ownership remains encoded in proposal_generation_state, and the
 * packet endpoint exposes unified_id / evidence_sources fields this service omits.
 *
 * Pure presentation methods (format*, build*, normalize*) are public
 * so unit tests can exercise them without DB access.
 */
class GenealogyProposalReviewQueueService
{
    /** Agent IDs used by the intake workflow when creating proposals. */
    private const INTAKE_AGENT_IDS = [
        'genealogy-intake-proposal-generation',
        'genealogy-intake-approval',
    ];

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    private const VALUE_EXCERPT_LENGTH = 300;

    private const ALLOWED_STATUSES = ['pending', 'approved', 'rejected', 'applied'];

    // ── Public entry points (require DB) ──────────────────────────────

    /**
     * Load proposals associated with a given intake run.
     * Scopes results to rows created at or after the run's created_at
     * with the two intake agent IDs.
     *
     * @param  array{status_filter?: string[], limit?: int}  $options
     */
    public function loadByRunKey(string $runKey, array $options = []): array
    {
        if ($runKey === '') {
            return ['success' => false, 'error' => 'empty_run_key'];
        }

        $run = DB::selectOne(
            'SELECT tree_id, created_at FROM genealogy_intake_runs WHERE run_key = ? LIMIT 1',
            [$runKey]
        );

        if (! $run) {
            return ['success' => false, 'error' => 'run_not_found'];
        }

        $treeId = (int) $run->tree_id;
        $createdAtFloor = (string) $run->created_at;
        $statusFilter = $this->normalizeStatusFilter($options['status_filter'] ?? []);
        $limit = $this->normalizeLimit($options['limit'] ?? self::DEFAULT_LIMIT);

        $personChanges = $this->queryPersonChanges(
            treeId: $treeId,
            agentIds: self::INTAKE_AGENT_IDS,
            createdAtFloor: $createdAtFloor,
            statusFilter: $statusFilter,
            limit: $limit
        );

        $relationships = $this->queryRelationships(
            treeId: $treeId,
            agentIds: self::INTAKE_AGENT_IDS,
            createdAtFloor: $createdAtFloor,
            statusFilter: $statusFilter,
            limit: $limit
        );

        return $this->buildQueueResult(
            'run_key',
            $runKey,
            $personChanges,
            $relationships,
            ['tree_id' => $treeId]
        );
    }

    /**
     * Load a review queue from explicit proposal ID lists.
     * Pass $personChangeIds for genealogy_proposed_changes rows,
     * $relationshipIds for genealogy_proposed_relationships rows.
     *
     * @param  int[]  $personChangeIds
     * @param  int[]  $relationshipIds
     */
    public function loadByProposalIds(
        array $personChangeIds = [],
        array $relationshipIds = [],
        array $options = []
    ): array {
        $personChangeIds = $this->normalizeIdList($personChangeIds);
        $relationshipIds = $this->normalizeIdList($relationshipIds);

        if ($personChangeIds === [] && $relationshipIds === []) {
            return $this->buildQueueResult('proposal_ids', null, [], []);
        }

        $personChanges = $personChangeIds !== []
            ? $this->queryPersonChangesByIds($personChangeIds)
            : [];

        $relationships = $relationshipIds !== []
            ? $this->queryRelationshipsByIds($relationshipIds)
            : [];

        return $this->buildQueueResult('proposal_ids', null, $personChanges, $relationships);
    }

    /**
     * Load proposals for a given tree filtered to a single status.
     * Optionally restricts to specific agent IDs via $options['agent_ids'].
     *
     * @param  array{limit?: int, agent_ids?: string[]}  $options
     */
    public function loadByTreeAndStatus(
        int $treeId,
        string $status = 'pending',
        array $options = []
    ): array {
        if ($treeId < 1) {
            return ['success' => false, 'error' => 'invalid_tree_id'];
        }

        $normalizedStatus = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'pending';
        $limit = $this->normalizeLimit($options['limit'] ?? self::DEFAULT_LIMIT);
        $agentIds = isset($options['agent_ids']) && is_array($options['agent_ids'])
            ? array_values(array_filter(array_map('strval', $options['agent_ids']), fn ($s) => $s !== ''))
            : [];

        $personChanges = $this->queryPersonChanges(
            treeId: $treeId,
            agentIds: $agentIds,
            statusFilter: [$normalizedStatus],
            limit: $limit
        );

        $relationships = $this->queryRelationships(
            treeId: $treeId,
            agentIds: $agentIds,
            statusFilter: [$normalizedStatus],
            limit: $limit
        );

        return $this->buildQueueResult(
            'tree_status',
            (string) $treeId,
            $personChanges,
            $relationships,
            ['status_filter' => $normalizedStatus]
        );
    }

    // ── Pure presentation logic (public for unit-test access) ─────────

    /**
     * Assemble the unified queue result envelope from pre-formatted item arrays.
     */
    public function buildQueueResult(
        string $source,
        ?string $sourceRef,
        array $personChanges,
        array $relationships,
        array $meta = []
    ): array {
        return [
            'success' => true,
            'source' => $source,
            'source_ref' => $sourceRef,
            'meta' => $meta,
            'counts' => $this->buildCounts($personChanges, $relationships),
            'person_changes' => $personChanges,
            'relationships' => $relationships,
        ];
    }

    /**
     * Format one genealogy_proposed_changes DB row into a display item.
     * The row must carry a computed `person_full_name` alias from the JOIN.
     */
    public function formatPersonChangeRow(object $row): array
    {
        $changeType = (string) ($row->change_type ?? '');
        $fieldName = isset($row->field_name) && (string) $row->field_name !== ''
            ? (string) $row->field_name
            : null;
        $currentValue = isset($row->current_value) ? (string) $row->current_value : null;
        $proposedValue = isset($row->proposed_value) ? (string) $row->proposed_value : null;
        $confidence = (float) ($row->confidence ?? 0.0);
        $status = (string) ($row->status ?? 'pending');
        $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
        $evidenceSources = is_array($evidenceSources) ? $evidenceSources : [];
        $provenance = json_decode((string) ($row->provenance_json ?? 'null'), true);
        $provenance = is_array($provenance) ? $provenance : null;
        $reviewFlags = $this->buildProposalReviewFlags($changeType, $currentValue, $proposedValue, $evidenceSources, $confidence);

        return [
            'id' => (int) ($row->id ?? 0),
            'tree_id' => (int) ($row->tree_id ?? 0),
            'person_id' => (int) ($row->person_id ?? 0),
            'person_display_name' => $this->buildPersonDisplayName((string) ($row->person_full_name ?? '')),
            'change_type' => $changeType,
            'field_name' => $fieldName,
            'change_label' => $this->buildChangeLabel($changeType, $fieldName),
            'current_value_excerpt' => $this->buildValueExcerpt($currentValue),
            'proposed_value_excerpt' => $this->buildValueExcerpt($proposedValue),
            'has_truncated_values' => $this->hasTruncatedValues($currentValue, $proposedValue),
            'evidence_sources' => $evidenceSources,
            'evidence_summary' => isset($row->evidence_summary) && (string) $row->evidence_summary !== ''
                ? (string) $row->evidence_summary
                : null,
            'confidence' => $confidence,
            'confidence_label' => $this->buildConfidenceLabel($confidence),
            'status' => $status,
            'status_label' => $this->buildStatusLabel($status),
            'agent_id' => (string) ($row->agent_id ?? ''),
            'reviewer_notes' => isset($row->reviewer_notes) && (string) $row->reviewer_notes !== ''
                ? (string) $row->reviewer_notes
                : null,
            'applied_at' => isset($row->applied_at) ? (string) $row->applied_at : null,
            'created_at' => (string) ($row->created_at ?? ''),
            'age_days' => $this->computeAgeDays((string) ($row->created_at ?? '')),
            'review_flags' => $reviewFlags,
            'review_route' => $this->buildProposalReviewRoute($reviewFlags),
            'provenance' => $provenance,
        ];
    }

    /**
     * Format one genealogy_proposed_relationships DB row into a display item.
     * The row must carry a computed `person_full_name` alias from the JOIN.
     */
    public function formatRelationshipRow(object $row): array
    {
        $relationshipType = (string) ($row->relationship_type ?? '');
        $proposedSex = isset($row->proposed_sex) && (string) $row->proposed_sex !== ''
            ? (string) $row->proposed_sex
            : null;
        $confidence = (float) ($row->confidence ?? 0.0);
        $status = (string) ($row->status ?? 'pending');
        $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
        $evidenceSources = is_array($evidenceSources) ? $evidenceSources : [];
        $relatedPersonName = trim((string) ($row->related_person_full_name ?? '')) !== ''
            ? $this->buildPersonDisplayName((string) ($row->related_person_full_name ?? ''))
            : null;
        $proposedName = trim((string) ($row->proposed_name ?? '')) !== ''
            ? (string) $row->proposed_name
            : ($relatedPersonName ?? '');
        $reviewFlags = $this->buildProposalReviewFlags('relationship', null, $proposedName, $evidenceSources, $confidence);

        return [
            'id' => (int) ($row->id ?? 0),
            'tree_id' => (int) ($row->tree_id ?? 0),
            'person_id' => (int) ($row->person_id ?? 0),
            'person_display_name' => $this->buildPersonDisplayName((string) ($row->person_full_name ?? '')),
            'proposal_mode' => (string) ($row->proposal_mode ?? 'create_person'),
            'related_person_id' => isset($row->related_person_id) && (int) $row->related_person_id > 0
                ? (int) $row->related_person_id
                : null,
            'related_person_display_name' => $relatedPersonName,
            'relationship_type' => $relationshipType,
            'relationship_label' => $this->buildRelationshipLabel($relationshipType),
            'proposed_name' => $proposedName,
            'proposed_given_name' => isset($row->proposed_given_name) && (string) $row->proposed_given_name !== ''
                ? (string) $row->proposed_given_name
                : null,
            'proposed_surname' => isset($row->proposed_surname) && (string) $row->proposed_surname !== ''
                ? (string) $row->proposed_surname
                : null,
            'proposed_sex' => $proposedSex,
            'proposed_sex_label' => $this->buildSexLabel($proposedSex),
            'proposed_birth_date' => isset($row->proposed_birth_date) && (string) $row->proposed_birth_date !== ''
                ? (string) $row->proposed_birth_date
                : null,
            'proposed_birth_place' => isset($row->proposed_birth_place) && (string) $row->proposed_birth_place !== ''
                ? (string) $row->proposed_birth_place
                : null,
            'proposed_death_date' => isset($row->proposed_death_date) && (string) $row->proposed_death_date !== ''
                ? (string) $row->proposed_death_date
                : null,
            'proposed_death_place' => isset($row->proposed_death_place) && (string) $row->proposed_death_place !== ''
                ? (string) $row->proposed_death_place
                : null,
            'proposed_marriage_date' => isset($row->proposed_marriage_date) && (string) $row->proposed_marriage_date !== ''
                ? (string) $row->proposed_marriage_date
                : null,
            'proposed_marriage_place' => isset($row->proposed_marriage_place) && (string) $row->proposed_marriage_place !== ''
                ? (string) $row->proposed_marriage_place
                : null,
            'evidence_sources' => $evidenceSources,
            'evidence_summary' => isset($row->evidence_summary) && (string) $row->evidence_summary !== ''
                ? (string) $row->evidence_summary
                : null,
            'confidence' => $confidence,
            'confidence_label' => $this->buildConfidenceLabel($confidence),
            'status' => $status,
            'status_label' => $this->buildStatusLabel($status),
            'agent_id' => (string) ($row->agent_id ?? ''),
            'applied_person_id' => isset($row->applied_person_id) && (int) $row->applied_person_id > 0
                ? (int) $row->applied_person_id
                : null,
            'applied_family_id' => isset($row->applied_family_id) && (int) $row->applied_family_id > 0
                ? (int) $row->applied_family_id
                : null,
            'applied_at' => isset($row->applied_at) ? (string) $row->applied_at : null,
            'created_at' => (string) ($row->created_at ?? ''),
            'age_days' => $this->computeAgeDays((string) ($row->created_at ?? '')),
            'review_flags' => $reviewFlags,
            'review_route' => $this->buildProposalReviewRoute($reviewFlags),
        ];
    }

    /**
     * @param  list<string>  $evidenceSources
     * @return list<string>
     */
    public function buildProposalReviewFlags(string $changeType, ?string $currentValue, ?string $proposedValue, array $evidenceSources, float $confidence): array
    {
        $flags = [];

        if ($evidenceSources === []) {
            $flags[] = 'missing_evidence_sources';
        }

        if ($confidence < 0.50) {
            $flags[] = 'low_confidence';
        } elseif ($confidence < 0.65) {
            $flags[] = 'weak_confidence';
        } elseif ($confidence < 0.80) {
            $flags[] = 'medium_confidence';
        }

        if ($changeType === 'fact_update'
            && $currentValue !== null
            && trim($currentValue) !== ''
            && $proposedValue !== null
            && trim($proposedValue) !== ''
            && ! $this->proposalValuesEquivalent($currentValue, $proposedValue)
        ) {
            $flags[] = 'conflicts_with_current_fact';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  list<string>  $reviewFlags
     * @return array{queue: string, default_tool: string, default_action: string, approval_ready: bool}
     */
    public function buildProposalReviewRoute(array $reviewFlags): array
    {
        if (in_array('conflicts_with_current_fact', $reviewFlags, true)) {
            return [
                'queue' => 'conflict_review',
                'default_tool' => 'genealogy.proposal_queue',
                'default_action' => 'compare_current_and_proposed_values_against_sources_before_approval',
                'approval_ready' => false,
            ];
        }

        if (array_intersect($reviewFlags, ['missing_evidence_sources', 'low_confidence', 'weak_confidence']) !== []) {
            return [
                'queue' => 'evidence_review',
                'default_tool' => 'genealogy.proposal_queue',
                'default_action' => 'add_or_verify_source_backed_evidence_before_approval',
                'approval_ready' => false,
            ];
        }

        if (in_array('medium_confidence', $reviewFlags, true)) {
            return [
                'queue' => 'medium_evidence_review',
                'default_tool' => 'genealogy.proposal_queue',
                'default_action' => 'review_medium_confidence_source_evidence_before_approval',
                'approval_ready' => false,
            ];
        }

        return [
            'queue' => 'standard_review',
            'default_tool' => 'genealogy.proposal_queue',
            'default_action' => 'review_then_approve_if_evidence_is_sufficient',
            'approval_ready' => true,
        ];
    }

    private function proposalValuesEquivalent(?string $left, ?string $right): bool
    {
        $normalize = static fn (?string $value): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? '');

        return $normalize($left) === $normalize($right);
    }

    /** @return 'High'|'Medium'|'Low' */
    public function buildConfidenceLabel(float $confidence): string
    {
        if ($confidence >= 0.80) {
            return 'High';
        }
        if ($confidence >= 0.50) {
            return 'Medium';
        }

        return 'Low';
    }

    /** Human-readable label for a change_type + optional field_name. */
    public function buildChangeLabel(string $changeType, ?string $fieldName): string
    {
        return match ($changeType) {
            'fact_update' => $fieldName !== null
                ? 'Update '.str_replace('_', ' ', $fieldName)
                : 'Update fact',
            'event_add' => 'Add event',
            'source_add' => 'Add source',
            'media_link' => 'Link media',
            'notes_append' => 'Append notes',
            'residence_add' => 'Add residence',
            'family_event_update' => 'Update family event',
            'external_record_link' => 'Link external record',
            'source_create' => 'Create source',
            'clipping_link' => 'Link clipping',
            'media_metadata_update' => 'Update media metadata',
            default => ucwords(str_replace('_', ' ', $changeType)),
        };
    }

    /** Human-readable label for a proposal status value. */
    public function buildStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'applied' => 'Applied',
            default => ucfirst($status),
        };
    }

    /** Human-readable label for a relationship_type value. */
    public function buildRelationshipLabel(string $relationshipType): string
    {
        return match ($relationshipType) {
            'parent' => 'Parent',
            'child' => 'Child',
            'sibling' => 'Sibling',
            'spouse' => 'Spouse',
            default => ucfirst($relationshipType),
        };
    }

    /**
     * Filter a status list to only recognised values, deduplicated.
     *
     * @param  mixed[]  $statuses
     * @return string[]
     */
    public function normalizeStatusFilter(array $statuses): array
    {
        $normalized = [];
        foreach ($statuses as $status) {
            $value = trim((string) $status);
            if (in_array($value, self::ALLOWED_STATUSES, true) && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /** Clamp $limit to [1, MAX_LIMIT]. */
    public function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    // ── Private helpers ────────────────────────────────────────────────

    private function buildCounts(array $personChanges, array $relationships): array
    {
        $all = array_merge($personChanges, $relationships);

        return [
            'total' => count($all),
            'pending' => count(array_filter($all, fn ($i) => ($i['status'] ?? '') === 'pending')),
            'approved' => count(array_filter($all, fn ($i) => ($i['status'] ?? '') === 'approved')),
            'rejected' => count(array_filter($all, fn ($i) => ($i['status'] ?? '') === 'rejected')),
            'applied' => count(array_filter($all, fn ($i) => ($i['status'] ?? '') === 'applied')),
            'person_changes_total' => count($personChanges),
            'relationships_total' => count($relationships),
        ];
    }

    private function buildPersonDisplayName(string $rawFullName): string
    {
        $name = trim($rawFullName);

        return $name !== '' ? $name : '(unknown)';
    }

    private function buildSexLabel(?string $sex): ?string
    {
        return match ($sex) {
            'M' => 'Male',
            'F' => 'Female',
            default => null,
        };
    }

    private function buildValueExcerpt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) <= self::VALUE_EXCERPT_LENGTH) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, self::VALUE_EXCERPT_LENGTH).'…';
    }

    private function hasTruncatedValues(?string $currentValue, ?string $proposedValue): bool
    {
        $cLen = $currentValue !== null ? mb_strlen(trim($currentValue)) : 0;
        $pLen = $proposedValue !== null ? mb_strlen(trim($proposedValue)) : 0;

        return $cLen > self::VALUE_EXCERPT_LENGTH || $pLen > self::VALUE_EXCERPT_LENGTH;
    }

    private function computeAgeDays(string $createdAt): int
    {
        if ($createdAt === '') {
            return 0;
        }
        try {
            $diff = (new \DateTimeImmutable)->diff(new \DateTimeImmutable($createdAt));

            return max(0, (int) $diff->days);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  mixed[]  $ids
     * @return int[]
     */
    private function normalizeIdList(array $ids): array
    {
        return array_values(
            array_unique(
                array_filter(array_map('intval', $ids), fn ($id) => $id > 0)
            )
        );
    }

    // ── DB query methods ───────────────────────────────────────────────

    /**
     * @param  string[]  $agentIds
     * @param  string[]  $statusFilter
     * @return array<int, array<string, mixed>>
     */
    private function queryPersonChanges(
        int $treeId,
        array $agentIds = [],
        ?string $createdAtFloor = null,
        array $statusFilter = [],
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $where = ['pc.tree_id = ?'];
        $params = [$treeId];

        if ($agentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
            $where[] = "pc.agent_id IN ({$placeholders})";
            $params = array_merge($params, $agentIds);
        }

        if ($createdAtFloor !== null && $createdAtFloor !== '') {
            $where[] = 'pc.created_at >= ?';
            $params[] = $createdAtFloor;
        }

        if ($statusFilter !== []) {
            $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
            $where[] = "pc.status IN ({$placeholders})";
            $params = array_merge($params, $statusFilter);
        }

        $params[] = $limit;
        $provenanceSelect = $this->proposedChangesProvenanceSelect();

        $sql = "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name,
                       pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary,
                       {$provenanceSelect},
                       pc.confidence, pc.agent_id, pc.status, pc.applied_at,
                       pc.reviewer_notes, pc.created_at,
                       TRIM(CONCAT(COALESCE(gp.given_name, ''), ' ', COALESCE(gp.surname, ''))) AS person_full_name
                FROM genealogy_proposed_changes pc
                LEFT JOIN genealogy_persons gp ON gp.id = pc.person_id AND gp.tree_id = pc.tree_id
                WHERE ".implode(' AND ', $where).'
                ORDER BY pc.created_at DESC
                LIMIT ?';

        $rows = DB::select($sql, $params);

        return array_map(fn ($row) => $this->formatPersonChangeRow($row), $rows);
    }

    /**
     * @param  string[]  $agentIds
     * @param  string[]  $statusFilter
     * @return array<int, array<string, mixed>>
     */
    private function queryRelationships(
        int $treeId,
        array $agentIds = [],
        ?string $createdAtFloor = null,
        array $statusFilter = [],
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $where = ['pr.tree_id = ?'];
        $params = [$treeId];

        if ($agentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
            $where[] = "pr.agent_id IN ({$placeholders})";
            $params = array_merge($params, $agentIds);
        }

        if ($createdAtFloor !== null && $createdAtFloor !== '') {
            $where[] = 'pr.created_at >= ?';
            $params[] = $createdAtFloor;
        }

        if ($statusFilter !== []) {
            $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
            $where[] = "pr.status IN ({$placeholders})";
            $params = array_merge($params, $statusFilter);
        }

        $params[] = $limit;

        $rows = DB::select(
            'SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type,
                    pr.related_person_id, pr.proposal_mode,
                    pr.proposed_name, pr.proposed_given_name, pr.proposed_surname,
                    pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place,
                    pr.proposed_death_date, pr.proposed_death_place,
                    pr.proposed_marriage_date, pr.proposed_marriage_place,
                    pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.agent_id, pr.status,
                    pr.applied_person_id, pr.applied_family_id, pr.applied_at, pr.created_at,
                    TRIM(CONCAT(COALESCE(gp.given_name, \'\'), \' \', COALESCE(gp.surname, \'\'))) AS person_full_name,
                    TRIM(CONCAT(COALESCE(rp.given_name, \'\'), \' \', COALESCE(rp.surname, \'\'))) AS related_person_full_name
             FROM genealogy_proposed_relationships pr
             LEFT JOIN genealogy_persons gp ON gp.id = pr.person_id AND gp.tree_id = pr.tree_id
             LEFT JOIN genealogy_persons rp ON rp.id = pr.related_person_id AND rp.tree_id = pr.tree_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY pr.created_at DESC
             LIMIT ?',
            $params
        );

        return array_map(fn ($row) => $this->formatRelationshipRow($row), $rows);
    }

    /**
     * @param  int[]  $ids
     * @return array<int, array<string, mixed>>
     */
    private function queryPersonChangesByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $provenanceSelect = $this->proposedChangesProvenanceSelect();

        $sql = "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name,
                       pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary,
                       {$provenanceSelect},
                       pc.confidence, pc.agent_id, pc.status, pc.applied_at,
                       pc.reviewer_notes, pc.created_at,
                       TRIM(CONCAT(COALESCE(gp.given_name, ''), ' ', COALESCE(gp.surname, ''))) AS person_full_name
                FROM genealogy_proposed_changes pc
                LEFT JOIN genealogy_persons gp ON gp.id = pc.person_id AND gp.tree_id = pc.tree_id
                WHERE pc.id IN ({$placeholders})
                ORDER BY pc.created_at DESC";

        $rows = DB::select($sql, $ids);

        return array_map(fn ($row) => $this->formatPersonChangeRow($row), $rows);
    }

    private function proposedChangesProvenanceSelect(): string
    {
        try {
            if (Schema::hasColumn('genealogy_proposed_changes', 'provenance_json')) {
                return 'pc.provenance_json';
            }
        } catch (\Throwable) {
            // Tests and partial installs may not have a live schema.
        }

        return 'NULL AS provenance_json';
    }

    /**
     * @param  int[]  $ids
     * @return array<int, array<string, mixed>>
     */
    private function queryRelationshipsByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::select(
            'SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type,
                    pr.related_person_id, pr.proposal_mode,
                    pr.proposed_name, pr.proposed_given_name, pr.proposed_surname,
                    pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place,
                    pr.proposed_death_date, pr.proposed_death_place,
                    pr.proposed_marriage_date, pr.proposed_marriage_place,
                    pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.agent_id, pr.status,
                    pr.applied_person_id, pr.applied_family_id, pr.applied_at, pr.created_at,
                    TRIM(CONCAT(COALESCE(gp.given_name, \'\'), \' \', COALESCE(gp.surname, \'\'))) AS person_full_name,
                    TRIM(CONCAT(COALESCE(rp.given_name, \'\'), \' \', COALESCE(rp.surname, \'\'))) AS related_person_full_name
             FROM genealogy_proposed_relationships pr
             LEFT JOIN genealogy_persons gp ON gp.id = pr.person_id AND gp.tree_id = pr.tree_id
             LEFT JOIN genealogy_persons rp ON rp.id = pr.related_person_id AND rp.tree_id = pr.tree_id
             WHERE pr.id IN ('.$placeholders.')
             ORDER BY pr.created_at DESC',
            $ids
        );

        return array_map(fn ($row) => $this->formatRelationshipRow($row), $rows);
    }
}
