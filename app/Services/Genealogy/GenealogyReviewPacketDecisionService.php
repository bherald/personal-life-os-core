<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyReviewPacketDecisionService
{
    private const DECISION_REASON_CODES = [
        'source_verified',
        'missing_source_locator',
        'locator_mismatch',
        'source_needs_review',
        'citation_incomplete',
        'identity_unclear',
        'weak_evidence',
        'privacy_review_needed',
        'duplicate_packet',
        'other',
    ];

    public function __construct(
        private readonly GenealogyReviewPacketDecisionLogService $decisionLog = new GenealogyReviewPacketDecisionLogService,
    ) {}

    public function markReviewed(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'reviewed',
            'reviewed_preview_only',
            'packet_reviewed_preview_only',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet marked reviewed; proposal materialization remains preview-only.',
            requirePreviewOnly: true
        );
    }

    public function approve(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'reviewed',
            'reviewed_preview_only',
            'packet_reviewed_preview_only',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet reviewed; safe research-task previews were materialized where supported.',
            requirePreviewOnly: true,
            materializeSafeResearchTasks: true
        );
    }

    public function reject(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'rejected',
            'rejected',
            'packet_rejected',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet rejected.',
            requiredReasonAction: 'reject'
        );
    }

    public function clarify(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'pending',
            'clarification_requested',
            'packet_clarification_requested',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet clarification requested; proposal materialization remains preview-only.',
            false,
            requiredReasonAction: 'clarify'
        );
    }

    public function defer(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'pending',
            'deferred',
            'packet_deferred',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet deferred; proposal materialization remains preview-only.',
            false,
            requiredReasonAction: 'defer'
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transition(
        string $token,
        string $status,
        string $packetStatus,
        string $action,
        ?string $notes,
        array $meta,
        string $message,
        bool $markReviewedAt = true,
        bool $requirePreviewOnly = false,
        ?string $requiredReasonAction = null,
        bool $materializeSafeResearchTasks = false
    ): array {
        $row = DB::table('agent_review_queue')
            ->where('token', $token)
            ->where('review_type', GenealogyReviewPacketAdapterService::REVIEW_TYPE)
            ->where('status', 'pending')
            ->first();

        if ($row === null) {
            return [
                'success' => false,
                'error' => 'Pending genealogy review packet not found.',
            ];
        }

        $details = json_decode((string) ($row->details ?? '{}'), true);
        if (! is_array($details)) {
            $details = [];
        }

        if ($requiredReasonAction !== null && ! isset($meta['reason_code'])) {
            return [
                'success' => false,
                'error' => "A reason code is required to {$requiredReasonAction} a genealogy review packet.",
            ];
        }

        if ($requirePreviewOnly) {
            $previewGuardError = $this->previewOnlyGuardError($details);
            if ($previewGuardError !== null) {
                return [
                    'success' => false,
                    'error' => $previewGuardError,
                ];
            }

            $validationGuardError = $this->validationGuardError($details);
            if ($validationGuardError !== null) {
                return [
                    'success' => false,
                    'error' => $validationGuardError,
                ];
            }
        }

        return DB::transaction(function () use (
            $row,
            $details,
            $action,
            $notes,
            $meta,
            $status,
            $packetStatus,
            $markReviewedAt,
            $materializeSafeResearchTasks,
            $message
        ): array {
            if ($materializeSafeResearchTasks) {
                $materialization = $this->materializeSafeResearchTasks($row, $details);
                if (! ($materialization['success'] ?? false)) {
                    return [
                        'success' => false,
                        'error' => (string) ($materialization['error'] ?? 'research_task_materialization_failed'),
                    ];
                }

                $meta = array_merge($meta, [
                    'proposal_materialization' => $materialization['status'],
                    'materialized_operation_count' => $materialization['materialized_count'],
                    'reused_operation_count' => $materialization['reused_count'],
                ]);

                if ($materialization['research_task_ids'] !== []) {
                    $meta['research_task_ids'] = $materialization['research_task_ids'];
                }

                if ($materialization['existing_research_task_ids'] !== []) {
                    $meta['existing_research_task_ids'] = $materialization['existing_research_task_ids'];
                }
            }

            $details = $this->decisionLog->append($details, $action, 'operator', $notes, $meta);
            $details['packet_status'] = $packetStatus;
            $reviewerNotes = [
                'action' => $action,
                'notes' => $notes,
                'meta' => $meta,
            ];
            if (isset($meta['reason_code'])) {
                $reviewerNotes['reason_code'] = $meta['reason_code'];
            }

            $updates = [
                'status' => $status,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'reviewer_notes' => json_encode($reviewerNotes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];

            if ($markReviewedAt) {
                $updates['reviewed_at'] = now();
            }

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update($updates);

            $result = [
                'success' => true,
                'message' => $message,
                'status' => $status,
                'packet_status' => $packetStatus,
                'action' => $action,
            ];

            if (isset($meta['reason_code'])) {
                $result['reason_code'] = $meta['reason_code'];
            }

            if (isset($meta['research_task_ids'])) {
                $result['research_task_ids'] = $meta['research_task_ids'];
            }

            if (isset($meta['existing_research_task_ids'])) {
                $result['existing_research_task_ids'] = $meta['existing_research_task_ids'];
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{success: bool, status: string, materialized_count: int, reused_count: int, research_task_ids: list<int>, existing_research_task_ids: list<int>, error?: string}
     */
    private function materializeSafeResearchTasks(object $row, array $details): array
    {
        $operations = $this->safeResearchTaskOperations($details);
        if ($operations === []) {
            return [
                'success' => true,
                'status' => 'not_implemented',
                'materialized_count' => 0,
                'reused_count' => 0,
                'research_task_ids' => [],
                'existing_research_task_ids' => [],
            ];
        }

        if (! Schema::hasTable('genealogy_research_tasks')) {
            return [
                'success' => false,
                'status' => 'blocked',
                'materialized_count' => 0,
                'reused_count' => 0,
                'research_task_ids' => [],
                'existing_research_task_ids' => [],
                'error' => 'genealogy_research_tasks table is missing.',
            ];
        }

        $created = [];
        $reused = [];
        $seenInPacket = [];

        foreach ($operations as $operation) {
            $task = $this->researchTaskPayload($row, $details, $operation);
            if ($task === null) {
                continue;
            }

            $fingerprint = $this->researchTaskFingerprint($task);
            if (isset($seenInPacket[$fingerprint])) {
                continue;
            }
            $seenInPacket[$fingerprint] = true;

            $existing = DB::table('genealogy_research_tasks')
                ->where('queue_item_id', (int) $row->id)
                ->where('research_question', $task['research_question'])
                ->whereIn('status', ['queued', 'processing', 'completed'])
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                $reused[] = (int) $existing->id;

                continue;
            }

            DB::table('genealogy_research_tasks')->insert($task);
            $created[] = (int) DB::getPdo()->lastInsertId();
        }

        if ($created === [] && $reused === []) {
            return [
                'success' => false,
                'status' => 'blocked',
                'materialized_count' => 0,
                'reused_count' => 0,
                'research_task_ids' => [],
                'existing_research_task_ids' => [],
                'error' => 'No supported research-task preview had enough tree/question context to materialize.',
            ];
        }

        return [
            'success' => true,
            'status' => $created !== [] ? 'research_tasks_materialized' : 'research_tasks_reused',
            'materialized_count' => count($created),
            'reused_count' => count($reused),
            'research_task_ids' => $created,
            'existing_research_task_ids' => $reused,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function researchTaskFingerprint(array $task): string
    {
        return hash('sha256', implode('|', [
            (string) ($task['tree_id'] ?? ''),
            (string) ($task['person_id'] ?? ''),
            (string) ($task['task_type'] ?? ''),
            (string) ($task['research_question'] ?? ''),
        ]));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array<string, mixed>>
     */
    private function safeResearchTaskOperations(array $details): array
    {
        $preview = $details['apply_preview'] ?? ($details['packet']['apply_preview'] ?? []);
        $operations = is_array($preview) && is_array($preview['operations'] ?? null) ? $preview['operations'] : [];
        $safe = [];

        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $type = $this->text($operation['operation_type'] ?? $operation['operation'] ?? null);
            if (! in_array($type, ['genealogy_todo_create', 'source_duplicate_cleanup'], true)) {
                continue;
            }

            $operation['operation_type'] = $type;
            $operation['operation_index'] = $index;
            $safe[] = $operation;
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>|null
     */
    private function researchTaskPayload(object $row, array $details, array $operation): ?array
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : $details;
        $currentState = is_array($operation['current_state'] ?? null) ? $operation['current_state'] : [];
        $proposedEffect = is_array($operation['proposed_effect'] ?? null) ? $operation['proposed_effect'] : [];
        $identity = is_array($packet['identity'] ?? null) ? $packet['identity'] : [];

        $treeId = $this->positiveInt($operation['tree_id'] ?? $currentState['tree_id'] ?? $packet['tree_id'] ?? $packet['target_tree_id'] ?? null);
        $question = $this->researchQuestion($row, $operation, $currentState, $proposedEffect);
        if ($treeId === null || $question === null) {
            return null;
        }

        $operationType = (string) $operation['operation_type'];
        $personId = $this->positiveInt($operation['person_id'] ?? $currentState['person_id'] ?? $packet['person_id'] ?? $packet['target_person_id'] ?? $identity['person_id'] ?? null);
        $familyId = $this->positiveInt($operation['family_id'] ?? $currentState['family_id'] ?? $packet['family_id'] ?? $packet['target_family_id'] ?? $packet['suspect_family_id'] ?? null);
        $sourceId = $this->positiveInt($operation['source_id'] ?? $currentState['source_id'] ?? $packet['source_id'] ?? $packet['target_source_id'] ?? null);
        $priority = $this->priority($operation['priority'] ?? $proposedEffect['priority'] ?? $currentState['priority'] ?? null, $operationType);
        $sourceLocators = $this->sourceLocators($details);

        return [
            'tree_id' => $treeId,
            'person_id' => $personId,
            'queue_item_id' => (int) $row->id,
            'task_type' => 'verify_facts',
            'priority' => $priority,
            'status' => 'queued',
            'research_question' => $question,
            'selection_reason' => $this->limitText('Approved source-backed genealogy review packet #'.((int) $row->id).': '.(string) $row->title, 600),
            'scope_reason' => $this->limitText('Materialized from a preview-only genealogy review packet. Do not change accepted person, family, or source facts until the task evidence supports a reviewed correction.', 600),
            'related_people_used' => null,
            'sources_checked' => $sourceLocators !== [] ? json_encode($sourceLocators, JSON_UNESCAPED_SLASHES) : null,
            'evidence_summary' => $this->limitText((string) ($row->summary ?? $question), 4000),
            'conflicts_found' => $this->conflictSummary($operationType, $question, $familyId, $sourceId),
            'outcome_state' => $operationType === 'source_duplicate_cleanup' ? 'needs_source_cleanup' : 'needs_source_review',
            'outcome_reason' => 'Created from approved genealogy review packet; source-backed packet cleared from operator review.',
            'parameters' => json_encode([
                'source' => 'genealogy_review_packet',
                'review_queue_id' => (int) $row->id,
                'token' => (string) $row->token,
                'operation_type' => $operationType,
                'operation_index' => (int) ($operation['operation_index'] ?? 0),
                'family_id' => $familyId,
                'source_id' => $sourceId,
                'packet_key' => $packet['packet_key'] ?? null,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $currentState
     * @param  array<string, mixed>  $proposedEffect
     */
    private function researchQuestion(object $row, array $operation, array $currentState, array $proposedEffect): ?string
    {
        $question = $this->firstText([
            $proposedEffect['research_question'] ?? null,
            $currentState['research_question'] ?? null,
            $operation['research_question'] ?? null,
            $operation['question'] ?? null,
        ]);

        if ($question === null && ($operation['operation_type'] ?? null) === 'source_duplicate_cleanup') {
            $question = 'Review and reconcile duplicate or weak source approvals: '.(string) ($row->summary ?? $row->title ?? '');
        }

        return $this->limitText($question ?? (string) ($row->summary ?? $row->title ?? ''), 1000);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstText(array $values): ?string
    {
        foreach ($values as $value) {
            $text = $this->text($value);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function conflictSummary(string $operationType, string $question, ?int $familyId, ?int $sourceId): string
    {
        $prefix = $operationType === 'source_duplicate_cleanup'
            ? 'Source approval cleanup review is required.'
            : 'Data/source quality review is required.';

        $context = [];
        if ($familyId !== null) {
            $context[] = "family #{$familyId}";
        }
        if ($sourceId !== null) {
            $context[] = "source #{$sourceId}";
        }

        return $this->limitText(trim($prefix.' '.($context !== [] ? 'Context: '.implode(', ', $context).'. ' : '').$question), 4000);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array<string, mixed>>
     */
    private function sourceLocators(array $details): array
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : $details;
        $locators = [];

        foreach ([$details['source_locators'] ?? null, $packet['source_locators'] ?? null] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $locator) {
                $text = $this->text($locator);
                if ($text !== null) {
                    $locators[] = ['locator' => $text];
                }
            }
        }

        foreach ([$details['sources'] ?? null, $packet['sources'] ?? null] as $sources) {
            if (! is_array($sources)) {
                continue;
            }
            foreach ($sources as $source) {
                if (! is_array($source)) {
                    continue;
                }
                $locator = $this->text($source['locator'] ?? $source['url'] ?? null);
                if ($locator !== null) {
                    $locators[] = [
                        'locator' => $locator,
                        'label' => $this->text($source['label'] ?? null),
                    ];
                }
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($locators as $locator) {
            $key = (string) ($locator['locator'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = array_filter($locator, static fn ($value): bool => $value !== null && $value !== '');
        }

        return $deduped;
    }

    private function priority(mixed $value, string $operationType): string
    {
        $value = $this->text($value);
        if (in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
            return $value;
        }

        return $operationType === 'source_duplicate_cleanup' ? 'medium' : 'high';
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function limitText(?string $value, int $limit): ?string
    {
        $value = $this->text($value);
        if ($value === null) {
            return null;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withReasonCode(array $meta, ?string $reasonCode): array
    {
        $normalized = $this->normalizeReasonCode($reasonCode);
        if ($normalized !== null) {
            $meta['reason_code'] = $normalized;
        }

        return $meta;
    }

    private function normalizeReasonCode(?string $reasonCode): ?string
    {
        $normalized = strtolower(trim((string) $reasonCode));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/_+/', '_', $normalized) ?? $normalized, '_-');
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, self::DECISION_REASON_CODES, true) ? $normalized : 'other';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function previewOnlyGuardError(array $details): ?string
    {
        $preview = $details['apply_preview'] ?? null;
        if (! is_array($preview)) {
            return 'Review packet apply preview is missing; approve remains blocked.';
        }

        if (($preview['mutates_accepted_facts'] ?? null) !== false) {
            return 'Review packet apply preview is not preview-only; approve remains blocked.';
        }

        $acceptedFactMutations = $preview['accepted_fact_mutations'] ?? [];
        if ($this->hasAcceptedFactMutations($acceptedFactMutations)) {
            return 'Review packet apply preview lists accepted fact mutations; approve remains blocked.';
        }

        $operations = $preview['operations'] ?? [];
        if (is_array($operations)) {
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                if ($this->previewFlagEnabled($operation['mutates_accepted_facts'] ?? null)) {
                    return 'Review packet apply preview operation mutates accepted facts; approve remains blocked.';
                }

                if ($this->previewFlagEnabled($operation['apply_enabled'] ?? null)) {
                    return 'Review packet apply preview operation is apply-enabled; approve remains blocked.';
                }
            }
        }

        return null;
    }

    private function hasAcceptedFactMutations(mixed $acceptedFactMutations): bool
    {
        if (is_array($acceptedFactMutations)) {
            return $acceptedFactMutations !== [];
        }

        return $acceptedFactMutations !== null
            && $acceptedFactMutations !== false
            && $acceptedFactMutations !== '';
    }

    private function previewFlagEnabled(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === '') {
            return false;
        }

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function validationGuardError(array $details): ?string
    {
        $validation = $details['validation'] ?? null;
        if (! is_array($validation)) {
            return 'Review packet validation is missing; approve remains blocked.';
        }

        $errors = $validation['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            return 'Review packet validation has errors; approve remains blocked.';
        }

        if (($validation['valid'] ?? null) === false) {
            return 'Review packet validation is not valid; approve remains blocked.';
        }

        return null;
    }
}
