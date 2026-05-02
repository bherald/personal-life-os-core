<?php

namespace App\Services;

use App\Services\AgentMetrics\AwoDecisionEnvelopeBuilder;
use App\Services\Genealogy\FamilyService;
use App\Services\Genealogy\GenealogyProposalReviewQueueService;
use App\Services\Research\ResearchReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UnifiedReviewService - Central hub for ALL human review queues
 *
 * Aggregates pending items from:
 * - agent_review_queue (agent findings, proposals)
 * - research_facts (research discoveries)
 * - genealogy_research_hints (record hints)
 * - data_removal_requests (privacy removals)
 * - file_quarantine (suspicious files)
 *
 * Provides consistent API for approve/reject across all types.
 * Enables batch operations and unified UX.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class UnifiedReviewService
{
    private ResearchReviewService $researchReviewService;

    private FamilyService $familyService;

    private ?GenealogyProposalReviewQueueService $genealogyProposalQueueService;

    // Category definitions for UI tabs
    public const CATEGORIES = [
        'agent' => [
            'label' => 'Agent Findings',
            'icon' => 'robot',
            'sources' => ['agent_review_queue'],
        ],
        'research' => [
            'label' => 'Research Facts',
            'icon' => 'magnifying-glass',
            'sources' => ['research_facts'],
        ],
        'genealogy' => [
            'label' => 'Genealogy',
            'icon' => 'users',
            'sources' => ['genealogy_proposed_relationships', 'genealogy_proposed_changes'],
        ],
        'faces' => [
            'label' => 'Face Matches',
            'icon' => 'user-circle',
            'sources' => ['genealogy_face_match_queue'],
        ],
        'privacy' => [
            'label' => 'Privacy & Data',
            'icon' => 'shield',
            'sources' => ['removal_requests'],
        ],
    ];

    // Unified status mapping
    private const STATUS_MAP = [
        'pending' => 'pending',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'accepted' => 'approved',  // genealogy_research_hints uses 'accepted'
        'deferred' => 'pending',   // treat deferred as still pending
        'applied' => 'approved',   // proposals that were applied
        'expired' => 'expired',
    ];

    public function __construct(
        ResearchReviewService $researchReviewService,
        FamilyService $familyService,
        ?GenealogyProposalReviewQueueService $genealogyProposalQueueService = null
    ) {
        $this->researchReviewService = $researchReviewService;
        $this->familyService = $familyService;
        $this->genealogyProposalQueueService = $genealogyProposalQueueService;
    }

    /**
     * Get all pending review items across all sources
     *
     * @param  string|null  $category  Filter by category (agent, research, genealogy, privacy, files)
     * @param  string|null  $search  Text search in title/summary
     * @param  string|null  $sortBy  Sort field (created_at, confidence, priority)
     * @param  string  $sortDir  Sort direction (asc, desc)
     * @param  int  $limit  Max items to return
     * @param  int  $offset  Pagination offset
     * @return array{items: array, total: int, stats: array}
     */
    public function getPendingItems(
        ?string $category = null,
        ?string $search = null,
        ?string $sortBy = 'priority',
        string $sortDir = 'desc',
        int $limit = 50,
        int $offset = 0,
        bool $includeExpired = false
    ): array {
        $items = [];
        $stats = [
            'agent' => 0,
            'research' => 0,
            'genealogy' => 0,
            'faces' => 0,
            'privacy' => 0,
            'total' => 0,
        ];

        // Fetch from each source based on category filter
        if (! $category || $category === 'agent') {
            $agentItems = $this->getAgentReviewItems($search, $includeExpired);
            $items = array_merge($items, $agentItems);
            $stats['agent'] = count($agentItems);
        }

        if (! $category || $category === 'research') {
            $researchItems = $this->getResearchItems($search);
            $items = array_merge($items, $researchItems);
            $stats['research'] = count($researchItems);
        }

        if (! $category || $category === 'genealogy') {
            $genealogyItems = $this->getGenealogyItems($search);
            $items = array_merge($items, $genealogyItems);
            $stats['genealogy'] = count($genealogyItems);
        }

        if (! $category || $category === 'faces') {
            $faceItems = $this->getFaceMatchItems($search);
            $items = array_merge($items, $faceItems);
            $stats['faces'] = count($faceItems);
        }

        if (! $category || $category === 'privacy') {
            $privacyItems = $this->getPrivacyItems($search);
            $items = array_merge($items, $privacyItems);
            $stats['privacy'] = count($privacyItems);
        }

        $stats['total'] = count($items);

        // Sort items
        usort($items, function ($a, $b) use ($sortBy, $sortDir) {
            $cmp = $this->comparePendingReviewItems($a, $b, $sortBy, $sortDir);

            if ($cmp !== 0) {
                return $cmp;
            }

            $createdAtCmp = $this->comparePendingReviewItems($a, $b, 'created_at', 'asc');
            if ($createdAtCmp !== 0) {
                return $createdAtCmp;
            }

            return strcmp((string) ($a['unified_id'] ?? ''), (string) ($b['unified_id'] ?? ''));
        });

        // Apply pagination
        $total = count($items);
        $items = array_slice($items, $offset, $limit);

        return [
            'items' => array_values($items),
            'total' => $total,
            'stats' => $stats,
        ];
    }

    /**
     * Get stats summary without fetching all items
     */
    public function getStats(): array
    {
        $stats = [
            'agent' => 0,
            'research' => 0,
            'genealogy' => 0,
            'faces' => 0,
            'privacy' => 0,
            'total' => 0,
            'high_priority' => 0,
            'expiring_soon' => 0,
        ];

        // Agent review queue
        try {
            $agentStats = DB::selectOne("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN priority >= 2 THEN 1 ELSE 0 END) as `high_priority`,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= DATE_ADD(NOW(), INTERVAL 6 HOUR) THEN 1 ELSE 0 END) as `expiring_soon`
                FROM agent_review_queue
                WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stats['agent'] = (int) ($agentStats->total ?? 0);
            $stats['high_priority'] += (int) ($agentStats->high_priority ?? 0);
            $stats['expiring_soon'] += (int) ($agentStats->expiring_soon ?? 0);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get agent stats', ['error' => $e->getMessage()]);
        }

        // Research facts (PostgreSQL)
        try {
            $researchCount = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as total FROM research_facts WHERE review_status = 'pending'
            ");
            $stats['research'] = (int) ($researchCount->total ?? 0);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get research stats', ['error' => $e->getMessage()]);
        }

        // Genealogy proposals (relationships + changes)
        try {
            $relCount = DB::selectOne("
                SELECT COUNT(*) as total FROM genealogy_proposed_relationships WHERE status IN ('pending', 'pending_review')
            ");
            $chgCount = DB::selectOne("
                SELECT COUNT(*) as total FROM genealogy_proposed_changes WHERE status IN ('pending', 'pending_review')
            ");
            $stats['genealogy'] = (int) ($relCount->total ?? 0) + (int) ($chgCount->total ?? 0);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get genealogy stats', ['error' => $e->getMessage()]);
        }

        // Face match queue
        try {
            $faceCount = DB::selectOne("
                SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending'
            ");
            $stats['faces'] = (int) ($faceCount->total ?? 0);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get face stats', ['error' => $e->getMessage()]);
        }

        // Privacy/Data removal
        try {
            $privacyCount = DB::selectOne("
                SELECT COUNT(*) as total FROM removal_requests WHERE requires_review = 1 AND status = 'pending'
            ");
            $stats['privacy'] = (int) ($privacyCount->total ?? 0);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get privacy stats', ['error' => $e->getMessage()]);
        }

        $stats['total'] = $stats['agent'] + $stats['research'] + $stats['genealogy'] + $stats['faces'] + $stats['privacy'];

        return $stats;
    }

    /**
     * Approve a review item by unified ID
     *
     * @param  string  $unifiedId  Format: "source:id" (e.g., "agent:123", "research:abc-123")
     * @param  string|null  $notes  Optional reviewer notes
     * @return array{success: bool, message?: string, error?: string}
     */
    public function approveItem(string $unifiedId, ?string $notes = null): array
    {
        [$source, $id] = $this->parseUnifiedId($unifiedId);

        return match ($source) {
            'agent' => $this->resolveAgentReview($id, true, $notes),
            'research' => $this->approveResearchFact($id, $notes),
            'hint' => $this->updateHintStatus((int) $id, 'accepted', $notes),
            'proposal' => $this->approveProposal((int) $id, $notes),
            'change' => $this->approveChangeProposal((int) $id, $notes),
            'face' => $this->resolveFaceMatch((int) $id, 'approved', $notes),
            'privacy' => $this->resolvePrivacyRequest((int) $id, 'approved', $notes),
            default => ['success' => false, 'error' => "Unknown source: {$source}"],
        };
    }

    /**
     * Reject a review item by unified ID
     *
     * @param  string  $unifiedId  Format: "source:id"
     * @param  string|null  $reason  Rejection reason
     * @return array{success: bool, message?: string, error?: string}
     */
    public function rejectItem(string $unifiedId, ?string $reason = null): array
    {
        [$source, $id] = $this->parseUnifiedId($unifiedId);

        return match ($source) {
            'agent' => $this->resolveAgentReview($id, false, $reason),
            'research' => $this->rejectResearchFact($id, $reason),
            'hint' => $this->updateHintStatus((int) $id, 'rejected', $reason),
            'proposal' => $this->rejectProposal((int) $id, $reason),
            'change' => $this->rejectChangeProposal((int) $id, $reason),
            'face' => $this->resolveFaceMatch((int) $id, 'rejected', $reason),
            'privacy' => $this->resolvePrivacyRequest((int) $id, 'rejected', $reason),
            default => ['success' => false, 'error' => "Unknown source: {$source}"],
        };
    }

    /**
     * Batch approve multiple items
     */
    public function batchApprove(array $unifiedIds, ?string $notes = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($unifiedIds as $id) {
            $result = $this->approveItem($id, $notes);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['id' => $id, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }

        return $results;
    }

    /**
     * Batch reject multiple items
     */
    public function batchReject(array $unifiedIds, ?string $reason = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($unifiedIds as $id) {
            $result = $this->rejectItem($id, $reason);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['id' => $id, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }

        return $results;
    }

    /**
     * Get single item details by unified ID
     */
    public function getItem(string $unifiedId): ?array
    {
        [$source, $id] = $this->parseUnifiedId($unifiedId);

        return match ($source) {
            'agent' => $this->getAgentItemById($id),
            'research' => $this->getResearchItemById($id),
            'hint' => $this->getHintById((int) $id),
            'proposal' => $this->getProposalById((int) $id),
            'change' => $this->getChangeProposalById((int) $id),
            'face' => $this->getFaceMatchById((int) $id),
            'privacy' => $this->getPrivacyItemById((int) $id),
            default => null,
        };
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Private: Fetch methods for each source
    // ───────────────────────────────────────────────────────────────────────────

    private function getAgentReviewItems(?string $search = null, bool $includeExpired = false): array
    {
        if ($includeExpired) {
            $where = "WHERE status IN ('pending', 'expired')";
        } else {
            $where = "WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())";
        }
        $params = [];

        if ($search) {
            $where .= ' AND (title LIKE ? OR summary LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $rows = DB::select("
            SELECT id, agent_id, review_type, title, summary, details, confidence, priority, status, token, expires_at, created_at
            FROM agent_review_queue
            {$where}
            ORDER BY priority DESC, created_at ASC
        ", $params);

        return array_map(function ($row) {
            $details = json_decode($row->details ?? '{}', true);
            $isExpired = $row->status === 'expired' || ($row->expires_at && strtotime($row->expires_at) < time());

            return [
                'unified_id' => "agent:{$row->token}",
                'source' => 'agent',
                'category' => 'agent',
                'id' => $row->id,
                'token' => $row->token,
                'title' => $row->title,
                'summary' => $row->summary,
                'confidence' => $row->confidence,
                'priority' => (int) $row->priority,
                'agent_id' => $row->agent_id,
                'review_type' => $row->review_type,
                'expires_at' => $row->expires_at,
                'created_at' => $row->created_at,
                'is_expired' => $isExpired,
                'details' => $details,
                'context' => $this->extractAgentContext($details, $row->review_type),
            ];
        }, $rows);
    }

    private function getResearchItems(?string $search = null): array
    {
        try {
            $where = "WHERE f.review_status = 'pending'";
            $params = [];

            if ($search) {
                $where .= ' AND f.fact_statement ILIKE ?';
                $params[] = "%{$search}%";
            }

            $rows = DB::connection('pgsql_rag')->select("
                SELECT
                    f.id, f.fact_statement, f.fact_type, f.confidence_score,
                    f.source_urls, f.context_snippet, f.verification_summary,
                    f.external_sources_confirmed, f.external_sources_denied,
                    f.created_at,
                    m.title as mission_title, m.domain_category
                FROM research_facts f
                LEFT JOIN research_missions m ON m.id = f.mission_id
                {$where}
                ORDER BY f.confidence_score DESC
                LIMIT 100
            ", $params);

            return array_map(function ($row) {
                return [
                    'unified_id' => "research:{$row->id}",
                    'source' => 'research',
                    'category' => 'research',
                    'id' => $row->id,
                    'title' => substr($row->fact_statement, 0, 100).(strlen($row->fact_statement) > 100 ? '...' : ''),
                    'summary' => $row->fact_statement,
                    'confidence' => (float) $row->confidence_score,
                    'priority' => $this->confidenceToPriority($row->confidence_score),
                    'fact_type' => $row->fact_type,
                    'mission_title' => $row->mission_title,
                    'domain_category' => $row->domain_category,
                    'created_at' => $row->created_at,
                    'context' => [
                        'source_urls' => json_decode($row->source_urls ?? '[]', true),
                        'context_snippet' => $row->context_snippet,
                        'verification' => [
                            'confirmed' => (int) $row->external_sources_confirmed,
                            'denied' => (int) $row->external_sources_denied,
                        ],
                    ],
                ];
            }, $rows);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to fetch research items', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function getGenealogyItems(?string $search = null): array
    {
        try {
            $queue = $this->genealogyQueue()->loadByProposalIds(
                $this->searchGenealogyChangeIds($search),
                $this->searchGenealogyRelationshipIds($search)
            );

            if (! ($queue['success'] ?? false)) {
                return [];
            }

            $items = [];

            foreach ((array) ($queue['relationships'] ?? []) as $relationship) {
                $items[] = $this->mapRelationshipQueueItemToUnifiedItem($relationship);
            }

            foreach ((array) ($queue['person_changes'] ?? []) as $change) {
                $items[] = $this->mapChangeQueueItemToUnifiedItem($change);
            }

            return $items;
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get genealogy proposal items', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function genealogyQueue(): GenealogyProposalReviewQueueService
    {
        return $this->genealogyProposalQueueService ??= new GenealogyProposalReviewQueueService;
    }

    private function searchGenealogyRelationshipIds(?string $search = null): array
    {
        $where = ["pr.status IN ('pending', 'pending_review')"];
        $params = [];

        if ($search) {
            $where[] = "(pr.proposed_name LIKE ? OR pr.evidence_summary LIKE ? OR CONCAT(p.given_name, ' ', p.surname) LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $rows = DB::select(
            'SELECT pr.id
             FROM genealogy_proposed_relationships pr
             LEFT JOIN genealogy_persons p ON p.id = pr.person_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY pr.confidence DESC, pr.created_at ASC
             LIMIT 100',
            $params
        );

        return array_map(fn ($row) => (int) $row->id, $rows);
    }

    private function searchGenealogyChangeIds(?string $search = null): array
    {
        $where = ["pc.status IN ('pending', 'pending_review')"];
        $params = [];

        if ($search) {
            $where[] = "(pc.proposed_value LIKE ? OR pc.evidence_summary LIKE ? OR CONCAT(p.given_name, ' ', p.surname) LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $rows = DB::select(
            'SELECT pc.id
             FROM genealogy_proposed_changes pc
             LEFT JOIN genealogy_persons p ON p.id = pc.person_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY pc.confidence DESC, pc.created_at ASC
             LIMIT 100',
            $params
        );

        return array_map(fn ($row) => (int) $row->id, $rows);
    }

    private function mapRelationshipQueueItemToUnifiedItem(array $relationship): array
    {
        $relationshipLabel = ucfirst((string) ($relationship['relationship_type'] ?? 'relationship'));
        $statusLabel = (string) ($relationship['status_label'] ?? ucfirst((string) ($relationship['status'] ?? 'pending')));
        $confidenceLabel = $relationship['confidence_label'] ?? null;
        $headline = trim($relationshipLabel.': '.((string) ($relationship['proposed_name'] ?? 'Unknown')));
        $vitalSummary = $this->buildRelationshipVitalSummary($relationship);

        return [
            'unified_id' => 'proposal:'.(int) ($relationship['id'] ?? 0),
            'source' => 'proposal',
            'category' => 'genealogy',
            'id' => (int) ($relationship['id'] ?? 0),
            'title' => 'Propose '.($relationship['relationship_type'] ?? 'relationship').': '.($relationship['proposed_name'] ?? 'Unknown'),
            'summary' => $relationship['evidence_summary'] ?? null,
            'confidence' => isset($relationship['confidence']) ? (float) $relationship['confidence'] : null,
            'priority' => $this->confidenceToPriority(isset($relationship['confidence']) ? (float) $relationship['confidence'] : null),
            'person_id' => (int) ($relationship['person_id'] ?? 0),
            'person_name' => $relationship['person_display_name'] ?? null,
            'tree_id' => (int) ($relationship['tree_id'] ?? 0),
            'agent_id' => $relationship['agent_id'] ?? null,
            'relationship_type' => $relationship['relationship_type'] ?? null,
            'status' => $relationship['status'] ?? null,
            'status_label' => $statusLabel,
            'confidence_label' => $confidenceLabel,
            'relationship_label' => $relationshipLabel,
            'proposed_name' => $relationship['proposed_name'] ?? null,
            'proposed_sex' => $relationship['proposed_sex'] ?? null,
            'proposed_birth_date' => $relationship['proposed_birth_date'] ?? null,
            'proposed_birth_place' => $relationship['proposed_birth_place'] ?? null,
            'proposed_death_date' => $relationship['proposed_death_date'] ?? null,
            'proposed_death_place' => $relationship['proposed_death_place'] ?? null,
            'created_at' => $relationship['created_at'] ?? null,
            'context' => [
                'evidence_sources' => array_values((array) ($relationship['evidence_sources'] ?? [])),
                'intake' => [
                    'is_intake_generated' => is_string($relationship['agent_id'] ?? null) && str_starts_with((string) $relationship['agent_id'], 'genealogy-intake-'),
                    'relationship_label' => $relationshipLabel,
                    'headline' => $headline,
                    'vital_summary' => $vitalSummary,
                    'status_label' => $statusLabel,
                    'confidence_label' => $confidenceLabel,
                ],
                'proposed_details' => [
                    'given_name' => $relationship['proposed_given_name'] ?? null,
                    'surname' => $relationship['proposed_surname'] ?? null,
                    'sex' => $relationship['proposed_sex'] ?? null,
                    'birth_date' => $relationship['proposed_birth_date'] ?? null,
                    'birth_place' => $relationship['proposed_birth_place'] ?? null,
                    'death_date' => $relationship['proposed_death_date'] ?? null,
                    'death_place' => $relationship['proposed_death_place'] ?? null,
                ],
            ],
        ];
    }

    private function mapChangeQueueItemToUnifiedItem(array $change): array
    {
        $changeType = (string) ($change['change_type'] ?? '');
        $fieldName = $change['field_name'] ?? null;
        $changeLabel = match ($changeType) {
            'fact_update' => 'Update '.($fieldName ?? 'fact'),
            'event_add' => 'Add event: '.($fieldName ?? 'event'),
            'source_add' => 'Add source',
            'media_link' => 'Link media',
            default => ucfirst($changeType),
        };
        $statusLabel = (string) ($change['status_label'] ?? ucfirst((string) ($change['status'] ?? 'pending')));
        $confidenceLabel = $change['confidence_label'] ?? null;
        $valueTransition = $this->buildValueTransition(
            $change['current_value_excerpt'] ?? null,
            $change['proposed_value_excerpt'] ?? null
        );

        return [
            'unified_id' => 'change:'.(int) ($change['id'] ?? 0),
            'source' => 'change_proposal',
            'category' => 'genealogy',
            'id' => (int) ($change['id'] ?? 0),
            'title' => $changeLabel.' for '.($change['person_display_name'] ?? 'Unknown person'),
            'summary' => $change['evidence_summary'] ?? null,
            'confidence' => isset($change['confidence']) ? (float) $change['confidence'] : null,
            'priority' => $this->confidenceToPriority(isset($change['confidence']) ? (float) $change['confidence'] : null),
            'person_id' => (int) ($change['person_id'] ?? 0),
            'person_name' => $change['person_display_name'] ?? null,
            'tree_id' => (int) ($change['tree_id'] ?? 0),
            'agent_id' => $change['agent_id'] ?? null,
            'change_type' => $changeType,
            'field_name' => $fieldName,
            'status' => $change['status'] ?? null,
            'status_label' => $statusLabel,
            'confidence_label' => $confidenceLabel,
            'change_label' => $changeLabel,
            'current_value_excerpt' => $change['current_value_excerpt'] ?? null,
            'proposed_value_excerpt' => $change['proposed_value_excerpt'] ?? null,
            'has_truncated_values' => (bool) ($change['has_truncated_values'] ?? false),
            'created_at' => $change['created_at'] ?? null,
            'context' => [
                'evidence_sources' => array_values((array) ($change['evidence_sources'] ?? [])),
                'intake' => [
                    'is_intake_generated' => is_string($change['agent_id'] ?? null) && str_starts_with((string) $change['agent_id'], 'genealogy-intake-'),
                    'change_label' => $changeLabel,
                    'current_value_excerpt' => $change['current_value_excerpt'] ?? null,
                    'proposed_value_excerpt' => $change['proposed_value_excerpt'] ?? null,
                    'value_transition' => $valueTransition,
                    'status_label' => $statusLabel,
                    'confidence_label' => $confidenceLabel,
                    'has_truncated_values' => (bool) ($change['has_truncated_values'] ?? false),
                ],
                'proposed_details' => [
                    'change_type' => $changeType,
                    'field_name' => $fieldName,
                    'current_value' => $change['current_value_excerpt'] ?? null,
                    'proposed_value' => $change['proposed_value_excerpt'] ?? null,
                ],
            ],
        ];
    }

    private function buildValueTransition(mixed $currentValue, mixed $proposedValue): ?string
    {
        $current = trim((string) ($currentValue ?? ''));
        $proposed = trim((string) ($proposedValue ?? ''));

        if ($current !== '' && $proposed !== '') {
            return $current.' -> '.$proposed;
        }

        if ($proposed !== '') {
            return $proposed;
        }

        if ($current !== '') {
            return $current;
        }

        return null;
    }

    private function buildRelationshipVitalSummary(array $relationship): ?string
    {
        $parts = [];

        $birth = trim(implode(' ', array_filter([
            ($relationship['proposed_birth_date'] ?? null),
            ($relationship['proposed_birth_place'] ?? null),
        ], static fn ($value): bool => trim((string) $value) !== '')));
        if ($birth !== '') {
            $parts[] = 'b. '.$birth;
        }

        $death = trim(implode(' ', array_filter([
            ($relationship['proposed_death_date'] ?? null),
            ($relationship['proposed_death_place'] ?? null),
        ], static fn ($value): bool => trim((string) $value) !== '')));
        if ($death !== '') {
            $parts[] = 'd. '.$death;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' | ', $parts);
    }

    private function getPrivacyItems(?string $search = null): array
    {
        try {
            $where = "WHERE requires_review = 1 AND status = 'pending'";
            $params = [];

            if ($search) {
                $where .= ' AND (profile_url LIKE ? OR ai_notes LIKE ?)';
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            // Check if data_removal_brokers table exists before joining
            $brokersExist = DB::select("SHOW TABLES LIKE 'data_removal_brokers'");
            $subjectsExist = DB::select("SHOW TABLES LIKE 'data_removal_subjects'");

            $brokerJoin = $brokersExist ? 'LEFT JOIN data_removal_brokers b ON b.id = r.broker_id' : '';
            $subjectJoin = $subjectsExist ? 'LEFT JOIN data_removal_subjects s ON s.id = r.subject_id' : '';
            $brokerCols = $brokersExist ? 'b.name as broker_name, b.url as broker_url,' : 'NULL as broker_name, NULL as broker_url,';
            $subjectCols = $subjectsExist ? 's.name as subject_name' : 'NULL as subject_name';

            $rows = DB::select("
                SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes, r.review_notes, r.created_at,
                       {$brokerCols}
                       {$subjectCols}
                FROM removal_requests r
                {$brokerJoin}
                {$subjectJoin}
                {$where}
                ORDER BY r.ai_confidence DESC, r.created_at ASC
                LIMIT 100
            ", $params);

            return array_map(function ($row) {
                return [
                    'unified_id' => "privacy:{$row->id}",
                    'source' => 'privacy',
                    'category' => 'privacy',
                    'id' => $row->id,
                    'title' => 'Remove from: '.($row->broker_name ?? 'Unknown Broker'),
                    'summary' => $row->ai_notes ?? "Profile found at {$row->profile_url}",
                    'confidence' => $row->ai_confidence ? (float) $row->ai_confidence : null,
                    'priority' => 1,
                    'broker_name' => $row->broker_name,
                    'broker_url' => $row->broker_url,
                    'subject_name' => $row->subject_name,
                    'profile_url' => $row->profile_url,
                    'created_at' => $row->created_at,
                    'context' => [
                        'ai_notes' => $row->ai_notes,
                        'review_notes' => $row->review_notes,
                    ],
                ];
            }, $rows);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get privacy items', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function getFaceMatchItems(?string $search = null): array
    {
        try {
            $where = "WHERE f.status = 'pending'";
            $params = [];

            if ($search) {
                $where .= " AND (f.face_name LIKE ? OR CONCAT(p.given_name, ' ', p.surname) LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            $rows = DB::select("
                SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id,
                       f.match_type, f.confidence_score, f.face_region, f.match_details, f.created_at,
                       CONCAT(p.given_name, ' ', p.surname) as suggested_person_name,
                       COALESCE(m.nextcloud_path, m.original_path) as media_path
                FROM genealogy_face_match_queue f
                LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id
                LEFT JOIN genealogy_media m ON m.id = f.media_id
                {$where}
                ORDER BY f.confidence_score DESC, f.created_at ASC
                LIMIT 100
            ", $params);

            return array_map(function ($row) {
                $matchDetails = json_decode($row->match_details ?? '{}', true);
                $faceRegion = json_decode($row->face_region ?? '{}', true);

                return [
                    'unified_id' => "face:{$row->id}",
                    'source' => 'face',
                    'category' => 'faces',
                    'id' => $row->id,
                    'title' => 'Face Match: '.($row->face_name ?: 'Unknown'),
                    'summary' => $row->suggested_person_name
                        ? "Suggested: {$row->suggested_person_name} ({$row->match_type})"
                        : "New face detected ({$row->match_type})",
                    'confidence' => $row->confidence_score ? (float) $row->confidence_score / 100 : null,
                    'priority' => $this->confidenceToPriority($row->confidence_score ? (float) $row->confidence_score / 100 : null),
                    'face_name' => $row->face_name,
                    'suggested_person_id' => $row->suggested_person_id,
                    'suggested_person_name' => $row->suggested_person_name,
                    'match_type' => $row->match_type,
                    'tree_id' => $row->tree_id,
                    'media_id' => $row->media_id,
                    'media_path' => $row->media_path,
                    'image_url' => "/api/media/face-match-crop/{$row->id}",
                    'created_at' => $row->created_at,
                    'context' => [
                        'face_region' => $faceRegion,
                        'match_details' => $matchDetails,
                    ],
                ];
            }, $rows);
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: Failed to get face match items', ['error' => $e->getMessage()]);

            return [];
        }
    }

    // Keep for backwards compatibility but return empty
    private function getFileItems(?string $search = null): array
    {
        return []; // file_quarantine table doesn't exist
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Private: Resolution methods
    // ───────────────────────────────────────────────────────────────────────────

    private function resolveAgentReview(string $token, bool $approved, ?string $notes): array
    {
        $item = DB::selectOne(
            "SELECT id, agent_id, details FROM agent_review_queue WHERE token = ? AND status = 'pending'",
            [$token]
        );

        if (! $item) {
            return ['success' => false, 'error' => 'Agent review item not found'];
        }

        $status = $approved ? 'approved' : 'rejected';

        // Rejection flips the parent row immediately — no downstream apply to coordinate.
        // Approval defers the parent-row status update until AFTER the child relationship
        // proposal applies successfully, so a failed apply leaves the row 'pending' for
        // operator retry instead of "approved-but-not-actually-applied" limbo.
        if (! $approved) {
            DB::update(
                'UPDATE agent_review_queue SET status = ?, reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$status, $notes, $item->id]
            );
            $this->recordAwoDecisionIfEnabled($item, $status);
        }

        // Auto-apply relationship proposals on approval
        if ($approved) {
            $details = json_decode($item->details ?? '{}', true);
            if (! empty($details['proposal_id'])) {
                $proposalId = (int) $details['proposal_id'];
                try {
                    DB::update(
                        "UPDATE genealogy_proposed_relationships SET status = 'approved' WHERE id = ? AND status IN ('pending', 'pending_review')",
                        [$proposalId]
                    );
                    $applyResult = $this->familyService->applyProposedRelationship($proposalId);
                    if (empty($applyResult['success'] ?? false)) {
                        Log::error('UnifiedReview: applyProposedRelationship returned failure', [
                            'proposal_id' => $proposalId,
                            'apply_error' => $applyResult['error'] ?? 'unknown',
                        ]);

                        return [
                            'success' => false,
                            'error' => 'Approved but apply failed: '.($applyResult['error'] ?? 'unknown'),
                            'proposal_id' => $proposalId,
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::error('UnifiedReview: Failed to apply proposal', ['error' => $e->getMessage()]);

                    return [
                        'success' => false,
                        'error' => 'Approved but apply threw: '.$e->getMessage(),
                        'proposal_id' => $proposalId,
                    ];
                }
            }

            // Apply (or no-apply-needed) succeeded — safe to finalize the parent row.
            DB::update(
                'UPDATE agent_review_queue SET status = ?, reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$status, $notes, $item->id]
            );
            $this->recordAwoDecisionIfEnabled($item, $status);
        }

        return ['success' => true, 'message' => "Agent review {$status}"];
    }

    private function recordAwoDecisionIfEnabled(object $item, string $status): void
    {
        if (empty($item->agent_id) || ! $this->awoDecisionRecordingEnabled()) {
            return;
        }

        try {
            $row = DB::selectOne(
                'SELECT id, agent_id, review_type, status, details, reviewer_notes, reviewed_at, updated_at, created_at
                 FROM agent_review_queue
                 WHERE id = ? AND status = ?
                 LIMIT 1',
                [$item->id, $status]
            );

            if (! $row) {
                return;
            }

            $details = json_decode($row->details ?? '{}', true);
            if (! is_array($details)) {
                $details = [];
            }

            $details['awo_decision'] = app(AwoDecisionEnvelopeBuilder::class)->fromReviewRow($row);
            $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! is_string($encoded)) {
                throw new \RuntimeException('Failed to encode AWO decision details.');
            }

            DB::update(
                'UPDATE agent_review_queue SET details = ?, updated_at = NOW() WHERE id = ?',
                [$encoded, $item->id]
            );
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: AWO decision recording failed', [
                'review_id' => (int) $item->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function awoDecisionRecordingEnabled(): bool
    {
        try {
            return filter_var(
                app(SystemConfigService::class)->get('awo.recording_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (\Throwable $e) {
            Log::warning('UnifiedReview: AWO recording flag read failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function approveResearchFact(string $factId, ?string $notes): array
    {
        try {
            $result = $this->researchReviewService->approveFact($factId, $notes ?? 'human');

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function rejectResearchFact(string $factId, ?string $reason): array
    {
        try {
            $result = $this->researchReviewService->rejectFact($factId, $reason ?? 'Rejected via unified review');

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function updateHintStatus(int $hintId, string $status, ?string $notes): array
    {
        $updated = DB::update(
            "UPDATE genealogy_research_hints SET status = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'",
            [$status, $hintId]
        );

        if ($updated === 0) {
            return ['success' => false, 'error' => 'Hint not found or already resolved'];
        }

        return ['success' => true, 'message' => "Hint {$status}"];
    }

    private function approveProposal(int $proposalId, ?string $notes): array
    {
        $row = DB::selectOne(
            "SELECT id FROM genealogy_proposed_relationships WHERE id = ? AND status IN ('pending', 'pending_review')",
            [$proposalId]
        );

        if (! $row) {
            return ['success' => false, 'error' => 'Proposal not found or already resolved'];
        }

        DB::update(
            "UPDATE genealogy_proposed_relationships SET status = 'approved', updated_at = NOW() WHERE id = ?",
            [$proposalId]
        );

        try {
            $result = $this->familyService->applyProposedRelationship($proposalId);
            if (empty($result['success'] ?? false)) {
                Log::error('UnifiedReview::approveProposal apply failure', [
                    'proposal_id' => $proposalId,
                    'apply_error' => $result['error'] ?? 'unknown',
                ]);

                return [
                    'success' => false,
                    'error' => 'Approved but apply failed: '.($result['error'] ?? 'unknown'),
                    'proposal_id' => $proposalId,
                ];
            }

            return ['success' => true, 'message' => 'Proposal approved and applied', 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('UnifiedReview::approveProposal apply exception', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => "Approved but failed to apply: {$e->getMessage()}"];
        }
    }

    private function rejectProposal(int $proposalId, ?string $reason): array
    {
        $updated = DB::update(
            "UPDATE genealogy_proposed_relationships SET status = 'rejected', updated_at = NOW() WHERE id = ? AND status IN ('pending', 'pending_review')",
            [$proposalId]
        );

        if ($updated === 0) {
            return ['success' => false, 'error' => 'Proposal not found or already resolved'];
        }

        return ['success' => true, 'message' => 'Proposal rejected'];
    }

    private function approveChangeProposal(int $changeId, ?string $notes): array
    {
        $change = DB::selectOne(
            "SELECT id, person_id, tree_id, change_type, field_name, proposed_value FROM genealogy_proposed_changes WHERE id = ? AND status IN ('pending', 'pending_review')",
            [$changeId]
        );

        if (! $change) {
            return ['success' => false, 'error' => 'Change proposal not found or already resolved'];
        }

        // Flip to approved first (required by PersonService::applyProposedChange guard)
        // but defer applied_at until the apply step actually succeeds. Prior code set
        // applied_at=NOW() unconditionally, so a row could show "approved + applied"
        // even after the apply silently failed.
        DB::update(
            "UPDATE genealogy_proposed_changes SET status = 'approved', reviewer_notes = ?, updated_at = NOW() WHERE id = ?",
            [$notes, $changeId]
        );

        try {
            $personService = app(\App\Services\Genealogy\PersonService::class);
            $result = $personService->applyProposedChange($changeId);
            if (empty($result['success'] ?? false)) {
                Log::error('UnifiedReview::approveChangeProposal apply failure', [
                    'change_id' => $changeId,
                    'apply_error' => $result['error'] ?? 'unknown',
                ]);

                return [
                    'success' => false,
                    'error' => 'Approved but apply failed: '.($result['error'] ?? 'unknown'),
                    'change_id' => $changeId,
                ];
            }

            DB::update(
                'UPDATE genealogy_proposed_changes SET applied_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$changeId]
            );

            return ['success' => true, 'message' => 'Change approved and applied', 'result' => $result];
        } catch (\Throwable $e) {
            Log::error('UnifiedReview::approveChangeProposal apply exception', [
                'change_id' => $changeId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => "Approved but failed to apply: {$e->getMessage()}"];
        }
    }

    private function rejectChangeProposal(int $changeId, ?string $reason): array
    {
        $updated = DB::update(
            "UPDATE genealogy_proposed_changes SET status = 'rejected', reviewer_notes = ?, updated_at = NOW() WHERE id = ? AND status IN ('pending', 'pending_review')",
            [$reason, $changeId]
        );

        if ($updated === 0) {
            return ['success' => false, 'error' => 'Change proposal not found or already resolved'];
        }

        return ['success' => true, 'message' => 'Change proposal rejected'];
    }

    private function resolvePrivacyRequest(int $requestId, string $resolution, ?string $notes): array
    {
        try {
            $status = $resolution === 'approved' ? 'submitted' : 'failed';

            $updated = DB::update(
                'UPDATE removal_requests SET requires_review = 0, status = ?, review_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND requires_review = 1',
                [$status, $notes ?? '', $requestId]
            );

            if ($updated === 0) {
                return ['success' => false, 'error' => 'Privacy request not found or already resolved'];
            }

            return ['success' => true, 'message' => "Privacy request {$status}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resolveFaceMatch(int $matchId, string $status, ?string $notes): array
    {
        try {
            $updated = DB::update(
                "UPDATE genealogy_face_match_queue SET status = ?, review_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'pending'",
                [$status, $notes, $matchId]
            );

            if ($updated === 0) {
                return ['success' => false, 'error' => 'Face match not found or already resolved'];
            }

            // If approved and has suggested_person_id, link the face
            if ($status === 'approved') {
                $match = DB::selectOne('SELECT suggested_person_id, media_id, face_region FROM genealogy_face_match_queue WHERE id = ?', [$matchId]);
                if ($match && $match->suggested_person_id) {
                    // Auto-link could be implemented here if desired
                    Log::info('Face match approved', ['match_id' => $matchId, 'person_id' => $match->suggested_person_id]);
                }
            }

            return ['success' => true, 'message' => "Face match {$status}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Private: Single item fetch methods
    // ───────────────────────────────────────────────────────────────────────────

    private function getAgentItemById(string $token): ?array
    {
        $items = $this->getAgentReviewItems(null);
        foreach ($items as $item) {
            if ($item['token'] === $token) {
                return $item;
            }
        }

        return null;
    }

    private function getResearchItemById(string $id): ?array
    {
        $items = $this->getResearchItems(null);
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function getHintById(int $id): ?array
    {
        $items = $this->getGenealogyItems(null);
        foreach ($items as $item) {
            if ($item['source'] === 'hint' && $item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function getProposalById(int $id): ?array
    {
        $items = $this->getGenealogyItems(null);
        foreach ($items as $item) {
            if ($item['source'] === 'proposal' && $item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function getChangeProposalById(int $id): ?array
    {
        $items = $this->getGenealogyItems(null);
        foreach ($items as $item) {
            if ($item['source'] === 'change_proposal' && $item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function getPrivacyItemById(int $id): ?array
    {
        $items = $this->getPrivacyItems(null);
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function getFaceMatchById(int $id): ?array
    {
        $items = $this->getFaceMatchItems(null);
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    // ───────────────────────────────────────────────────────────────────────────
    // Private: Helpers
    // ───────────────────────────────────────────────────────────────────────────

    private function parseUnifiedId(string $unifiedId): array
    {
        $parts = explode(':', $unifiedId, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid unified ID format: {$unifiedId}");
        }

        return $parts;
    }

    private function comparePendingReviewItems(array $a, array $b, string $sortBy, string $sortDir): int
    {
        $aVal = $a[$sortBy] ?? 0;
        $bVal = $b[$sortBy] ?? 0;

        if ($sortBy === 'created_at') {
            $aVal = strtotime((string) $aVal) ?: 0;
            $bVal = strtotime((string) $bVal) ?: 0;
        }

        $cmp = $aVal <=> $bVal;

        return $sortDir === 'desc' ? -$cmp : $cmp;
    }

    private function confidenceToPriority(?float $confidence): int
    {
        if ($confidence === null) {
            return 0;
        }
        if ($confidence >= 0.9) {
            return 0; // High confidence = low priority (easy to approve)
        }
        if ($confidence >= 0.7) {
            return 1;
        }

        return 2; // Low confidence = high priority (needs careful review)
    }

    private function extractAgentContext(array $details, string $reviewType): array
    {
        $context = [];

        // Extract useful context based on review type
        if ($reviewType === 'finding' && isset($details['proposal_id'])) {
            $context['type'] = 'relationship_proposal';
            $context['proposal_id'] = $details['proposal_id'];
        }

        if (isset($details['evidence'])) {
            $context['evidence'] = $details['evidence'];
        }

        if (isset($details['sources'])) {
            $context['sources'] = $details['sources'];
        }

        return $context;
    }
}
