<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenealogyQueueEvidenceCompleteCommand extends Command
{
    /** @var string[] */
    private const VALID_TASK_TYPES = [
        'find_records',
        'verify_facts',
        'find_relatives',
        'analyze_dna',
        'suggest_sources',
        'transcribe_document',
    ];

    protected $signature = 'genealogy:queue-evidence-complete
        {--tree=4 : Genealogy tree id}
        {--queue-id= : genealogy_research_queue id}
        {--person-id= : Expected queue person id}
        {--source-id=* : Source ids used by the completed evidence work}
        {--media-id=* : Media ids used by the completed evidence work}
        {--citation-id=* : Citation ids created or verified by the completed evidence work}
        {--sources-checked=* : Human-readable source labels checked}
        {--evidence-summary= : Concise source-backed evidence summary}
        {--outcome-reason= : Concise completion reason}
        {--conflicts-found=none : Conflict summary}
        {--execute : Write queue/task completion bookkeeping}
        {--confirm : Required with --execute}
        {--json : Emit machine-readable JSON}
        {--compact : Omit raw ids and verbose row details from JSON}';

    protected $description = 'Dry-run-first completion marker for Genea queue items satisfied by direct source-backed evidence';

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $queueId = $this->positiveOption('queue-id');
        $personId = $this->positiveOption('person-id');
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;

        $sourceIds = $this->positiveListOption('source-id');
        $mediaIds = $this->positiveListOption('media-id');
        $citationIds = $this->positiveListOption('citation-id');
        $evidenceSummary = trim((string) $this->option('evidence-summary'));
        $outcomeReason = trim((string) ($this->option('outcome-reason') ?: 'Codex direct source-backed evidence completed this queued research item.'));
        $conflictsFound = trim((string) ($this->option('conflicts-found') ?: 'none'));
        $sourcesChecked = $this->sourceLabels($sourceIds, $mediaIds, $citationIds);

        if ($queueId === null || $personId === null) {
            return $this->failPayload('queue_id_and_person_id_required');
        }

        if ($execute && ! (bool) $this->option('confirm')) {
            return $this->failPayload('execute_requires_confirm');
        }

        if ($evidenceSummary === '') {
            return $this->failPayload('evidence_summary_required');
        }

        if ($sourceIds === [] && $mediaIds === [] && $citationIds === []) {
            return $this->failPayload('evidence_anchor_required');
        }

        $queue = $this->queueRow($treeId, $queueId, $personId);
        if ($queue === null) {
            return $this->failPayload('queue_item_not_found_for_tree_person');
        }

        if ((string) $queue->status !== 'pending') {
            return $this->failPayload('queue_item_must_be_pending');
        }

        $validation = $this->validateEvidenceAnchors($treeId, $personId, $sourceIds, $mediaIds, $citationIds);
        if ($validation['errors'] !== []) {
            return $this->failPayload('evidence_anchor_validation_failed', [
                'validation_errors' => $validation['errors'],
            ]);
        }

        $payload = [
            'command' => 'genealogy:queue-evidence-complete',
            'mode' => $dryRun ? 'dry_run' : 'execute',
            'dry_run' => $dryRun,
            'read_only' => $dryRun,
            'queue_bookkeeping_write_allowed' => $execute && ! $dryRun,
            'captured_at' => now()->toIso8601String(),
            'posture' => [
                'canonical_genealogy_writes' => false,
                'citation_writes' => false,
                'person_media_writes' => false,
                'face_identity_automation' => false,
                'queue_bookkeeping_only' => true,
                'tree_scoped' => true,
                'exact_queue_person_required' => true,
            ],
            'queue' => [
                'id' => $queueId,
                'tree_id' => $treeId,
                'person_id' => $personId,
                'person_name' => (string) $queue->person_name,
                'question_type' => $queue->question_type,
                'research_question' => $queue->research_question,
                'selection_reason' => $queue->selection_reason,
            ],
            'evidence' => [
                'source_ids' => $sourceIds,
                'media_ids' => $mediaIds,
                'citation_ids' => $citationIds,
                'sources_checked' => $sourcesChecked,
                'evidence_summary' => $evidenceSummary,
                'conflicts_found' => $conflictsFound,
                'outcome_reason' => $outcomeReason,
            ],
            'validation' => [
                'source_count' => $validation['source_count'],
                'media_count' => $validation['media_count'],
                'citation_count' => $validation['citation_count'],
                'target_family_ids' => $validation['target_family_ids'],
            ],
            'summary' => [
                'planned_task_rows' => 1,
                'planned_queue_rows' => 1,
                'task_rows_inserted' => 0,
                'queue_rows_updated' => 0,
                'canonical_rows_changed' => 0,
                'face_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'citation_rows_changed' => 0,
            ],
            'next_action' => $dryRun ? 'rerun_with_execute_confirm_to_mark_queue_completed' : 'queue_completion_attempted',
        ];

        if (! $dryRun) {
            try {
                $writeResult = DB::transaction(function () use (
                    $queue,
                    $treeId,
                    $queueId,
                    $personId,
                    $sourceIds,
                    $mediaIds,
                    $citationIds,
                    $sourcesChecked,
                    $evidenceSummary,
                    $conflictsFound,
                    $outcomeReason
                ): array {
                    $taskId = $this->insertCompletionTask(
                        $queue,
                        $treeId,
                        $queueId,
                        $personId,
                        $sourceIds,
                        $mediaIds,
                        $citationIds,
                        $sourcesChecked,
                        $evidenceSummary,
                        $conflictsFound,
                        $outcomeReason
                    );

                    $updated = DB::table('genealogy_research_queue')
                        ->where('id', $queueId)
                        ->where('tree_id', $treeId)
                        ->where('person_id', $personId)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'completed',
                            'completed_at' => now(),
                            'findings_count' => 0,
                            'review_items_count' => 0,
                            'last_task_id' => $taskId,
                            'last_outcome_state' => 'completed',
                            'last_outcome_reason' => $outcomeReason,
                            'notes' => $this->appendQueueNote($queue->notes ?? null, $taskId),
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw new \RuntimeException('queue_completion_update_failed');
                    }

                    return ['task_id' => $taskId, 'queue_rows_updated' => $updated];
                });
            } catch (Throwable $e) {
                return $this->failPayload('queue_completion_write_failed', [
                    'write_error' => $e->getMessage(),
                ]);
            }

            $payload['task'] = ['id' => $writeResult['task_id']];
            $payload['summary']['task_rows_inserted'] = 1;
            $payload['summary']['queue_rows_updated'] = (int) $writeResult['queue_rows_updated'];
            $payload['next_action'] = 'queue_marked_completed_by_source_backed_evidence';
        }

        $this->renderPayload($this->compactIfRequested($payload));

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array<int,int>
     */
    private function positiveListOption(string $name): array
    {
        $values = $this->option($name);
        $values = is_array($values) ? $values : [$values];
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function queueRow(int $treeId, int $queueId, int $personId): ?object
    {
        return DB::table('genealogy_research_queue as q')
            ->join('genealogy_persons as p', function ($join): void {
                $join->on('p.id', '=', 'q.person_id')
                    ->on('p.tree_id', '=', 'q.tree_id');
            })
            ->where('q.id', $queueId)
            ->where('q.tree_id', $treeId)
            ->where('q.person_id', $personId)
            ->select('q.*')
            ->first();
    }

    /**
     * @param  array<int,int>  $sourceIds
     * @param  array<int,int>  $mediaIds
     * @param  array<int,int>  $citationIds
     * @return array<string,mixed>
     */
    private function validateEvidenceAnchors(int $treeId, int $personId, array $sourceIds, array $mediaIds, array $citationIds): array
    {
        $errors = [];
        $sourceCount = 0;
        $mediaCount = 0;
        $citationCount = 0;

        if ($sourceIds !== []) {
            $sourceCount = DB::table('genealogy_sources')
                ->where('tree_id', $treeId)
                ->whereIn('id', $sourceIds)
                ->count();
            if ($sourceCount !== count($sourceIds)) {
                $errors[] = 'source_ids_not_all_in_tree';
            }
        }

        if ($mediaIds !== []) {
            $mediaCount = DB::table('genealogy_media')
                ->where('tree_id', $treeId)
                ->whereIn('id', $mediaIds)
                ->count();
            if ($mediaCount !== count($mediaIds)) {
                $errors[] = 'media_ids_not_all_in_tree';
            }
        }

        $targetFamilyIds = $this->targetFamilyIds($personId);
        if ($citationIds !== []) {
            $citationRows = DB::table('genealogy_citations as c')
                ->join('genealogy_sources as s', 's.id', '=', 'c.source_id')
                ->where('s.tree_id', $treeId)
                ->whereIn('c.id', $citationIds)
                ->select('c.id', 'c.person_id', 'c.family_id')
                ->get();
            $citationCount = $citationRows->count();

            if ($citationCount !== count($citationIds)) {
                $errors[] = 'citation_ids_not_all_in_tree';
            }

            foreach ($citationRows as $row) {
                $rowPersonId = isset($row->person_id) ? (int) $row->person_id : 0;
                $rowFamilyId = isset($row->family_id) ? (int) $row->family_id : 0;
                $personMatches = $rowPersonId === $personId;
                $familyMatches = $rowFamilyId > 0 && in_array($rowFamilyId, $targetFamilyIds, true);

                if (! $personMatches && ! $familyMatches) {
                    $errors[] = 'citation_id_not_anchored_to_target';
                    break;
                }
            }
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'source_count' => $sourceCount,
            'media_count' => $mediaCount,
            'citation_count' => $citationCount,
            'target_family_ids' => $targetFamilyIds,
        ];
    }

    /**
     * @return array<int,int>
     */
    private function targetFamilyIds(int $personId): array
    {
        $childFamilyIds = DB::table('genealogy_children')
            ->where('person_id', $personId)
            ->pluck('family_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $spouseFamilyIds = DB::table('genealogy_families')
            ->where('husband_id', $personId)
            ->orWhere('wife_id', $personId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ids = array_values(array_unique(array_merge($childFamilyIds, $spouseFamilyIds)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  array<int,int>  $sourceIds
     * @param  array<int,int>  $mediaIds
     * @param  array<int,int>  $citationIds
     * @param  array<int,string>  $sourcesChecked
     */
    private function insertCompletionTask(
        object $queue,
        int $treeId,
        int $queueId,
        int $personId,
        array $sourceIds,
        array $mediaIds,
        array $citationIds,
        array $sourcesChecked,
        string $evidenceSummary,
        string $conflictsFound,
        string $outcomeReason
    ): int {
        return (int) DB::table('genealogy_research_tasks')->insertGetId([
            'tree_id' => $treeId,
            'person_id' => $personId,
            'queue_item_id' => $queueId,
            'task_type' => $this->taskType((string) ($queue->question_type ?? 'suggest_sources')),
            'priority' => 'high',
            'status' => 'completed',
            'research_question' => $queue->research_question,
            'selection_reason' => $queue->selection_reason,
            'scope_reason' => 'Direct Codex-supervised source-backed evidence completion for the queued person.',
            'related_people_used' => json_encode([]),
            'sources_checked' => json_encode($sourcesChecked),
            'evidence_summary' => $evidenceSummary,
            'conflicts_found' => $conflictsFound,
            'outcome_state' => 'completed',
            'outcome_reason' => $outcomeReason,
            'parameters' => json_encode([
                'manual_evidence_completion' => true,
                'queue_item_id' => $queueId,
                'source_ids' => $sourceIds,
                'media_ids' => $mediaIds,
                'citation_ids' => $citationIds,
            ]),
            'results' => json_encode([
                'manual_evidence_completion' => true,
                'findings_count' => 0,
                'review_items_count' => 0,
                'source_count' => count($sourceIds),
                'media_count' => count($mediaIds),
                'citation_count' => count($citationIds),
            ]),
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function taskType(string $questionType): string
    {
        $mapped = match ($questionType) {
            'find_record' => 'find_records',
            'verify_fact' => 'verify_facts',
            'negative_search_followup' => 'suggest_sources',
            default => $questionType,
        };

        return in_array($mapped, self::VALID_TASK_TYPES, true) ? $mapped : 'suggest_sources';
    }

    /**
     * @param  array<int,int>  $sourceIds
     * @param  array<int,int>  $mediaIds
     * @param  array<int,int>  $citationIds
     * @return array<int,string>
     */
    private function sourceLabels(array $sourceIds, array $mediaIds, array $citationIds): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($this->option('sources-checked')) ? $this->option('sources-checked') : []
        )));

        if ($explicit !== []) {
            return $explicit;
        }

        $labels = [];
        foreach ($sourceIds as $id) {
            $labels[] = 'source:'.$id;
        }
        foreach ($mediaIds as $id) {
            $labels[] = 'media:'.$id;
        }
        foreach ($citationIds as $id) {
            $labels[] = 'citation:'.$id;
        }

        return $labels;
    }

    private function appendQueueNote(mixed $current, int $taskId): string
    {
        $note = trim((string) $current);
        $suffix = '[codex-source-backed-complete task='.$taskId.']';

        return $note === '' ? $suffix : $note.' '.$suffix;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactIfRequested(array $payload): array
    {
        if (! (bool) $this->option('compact')) {
            return $payload;
        }

        $payload['queue'] = [
            'status' => 'matched_pending_queue_item',
            'question_type' => $payload['queue']['question_type'] ?? null,
        ];
        $payload['evidence'] = [
            'source_count' => count($payload['evidence']['source_ids'] ?? []),
            'media_count' => count($payload['evidence']['media_ids'] ?? []),
            'citation_count' => count($payload['evidence']['citation_ids'] ?? []),
            'sources_checked_count' => count($payload['evidence']['sources_checked'] ?? []),
        ];
        $payload['validation'] = [
            'source_count' => $payload['validation']['source_count'] ?? 0,
            'media_count' => $payload['validation']['media_count'] ?? 0,
            'citation_count' => $payload['validation']['citation_count'] ?? 0,
        ];
        unset($payload['task']);
        $payload['compact'] = true;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function failPayload(string $error, array $extra = []): int
    {
        $payload = array_merge([
            'command' => 'genealogy:queue-evidence-complete',
            'status' => 'blocked',
            'error' => $error,
            'read_only' => true,
            'queue_bookkeeping_write_allowed' => false,
            'summary' => [
                'planned_task_rows' => 0,
                'planned_queue_rows' => 0,
                'task_rows_inserted' => 0,
                'queue_rows_updated' => 0,
                'canonical_rows_changed' => 0,
                'face_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'citation_rows_changed' => 0,
            ],
        ], $extra);

        $this->renderPayload($this->compactIfRequested($payload));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'genealogy queue evidence complete: mode=%s task=%d queue=%d next=%s',
            (string) ($payload['mode'] ?? 'blocked'),
            (int) ($payload['summary']['task_rows_inserted'] ?? 0),
            (int) ($payload['summary']['queue_rows_updated'] ?? 0),
            (string) ($payload['next_action'] ?? $payload['error'] ?? 'unknown')
        ));
    }
}
