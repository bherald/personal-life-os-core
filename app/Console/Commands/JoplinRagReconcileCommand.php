<?php

namespace App\Console\Commands;

use App\Services\AgentContextReconcileSignalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JoplinRagReconcileCommand extends Command
{
    protected $signature = 'joplin:rag-reconcile
                            {--execute : Delete stale and duplicate Joplin RAG documents}
                            {--limit= : Maximum number of candidate RAG documents to delete}
                            {--max-delete-candidates= : Block execute when total delete candidates exceed this count}
                            {--max-dependent-rows= : Block execute when selected dependent rows exceed this count}
                            {--event-hours=72 : Include open retrieved-context reconcile events from the recent window}
                            {--triggered-only : Select only delete candidates recently surfaced by retrieved-context fencing}
                            {--json : Emit machine-readable JSON}
                            {--compact : With --json, emit aggregate-only scheduled-output JSON without sample rows}';

    protected $description = 'Preview or clean stale Joplin note documents from the RAG index';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = $this->parseLimit();
        $eventHours = $this->parseEventHours();
        $maxDeleteCandidates = $this->parseOptionalPositiveIntOption('max-delete-candidates');
        $maxDependentRows = $this->parseOptionalPositiveIntOption('max-dependent-rows');
        $compact = (bool) $this->option('compact');

        if ($limit === false || $eventHours === false || $maxDeleteCandidates === false || $maxDependentRows === false) {
            return self::FAILURE;
        }

        try {
            $report = $this->buildReport(
                $limit,
                $eventHours,
                (bool) $this->option('triggered-only'),
                $maxDeleteCandidates,
                $maxDependentRows
            );
            $blockedByThreshold = $execute && ! (bool) ($report['thresholds']['execute_allowed'] ?? true);

            if ($blockedByThreshold) {
                $report['status'] = 'blocked_by_threshold';
            } elseif ($execute && $report['selected_candidates'] > 0) {
                $report['deleted'] = $this->deleteDocuments($report['selected_ids']);
                $report['context_reconcile']['resolved_events'] = app(AgentContextReconcileSignalService::class)
                    ->markResolvedByRagDocumentIds($report['selected_ids']);
                $report['status'] = 'complete';
            } else {
                $report['status'] = $execute ? 'complete' : 'dry_run';
            }

            unset($report['selected_ids']);

            $report['mode'] = 'joplin_rag_reconcile';
            $report['dry_run'] = ! $execute;

            if ($this->option('json')) {
                if ($compact) {
                    $report = $this->compactReport($report);
                }

                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $blockedByThreshold ? self::FAILURE : self::SUCCESS;
            }

            $this->renderReport($report);

            return $blockedByThreshold ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $payload = [
                    'mode' => 'joplin_rag_reconcile',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                if ((bool) $this->option('compact')) {
                    $payload = [
                        'mode' => 'joplin_rag_reconcile',
                        'compact' => true,
                        'status' => 'failed',
                        'error_type' => class_basename($e),
                        'posture' => $this->compactPosture(),
                    ];
                }

                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->error('Joplin RAG reconcile failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function compactReport(array $report): array
    {
        $context = is_array($report['context_reconcile'] ?? null) ? $report['context_reconcile'] : [];

        return [
            'mode' => (string) ($report['mode'] ?? 'joplin_rag_reconcile'),
            'compact' => true,
            'status' => (string) ($report['status'] ?? 'unknown'),
            'dry_run' => (bool) ($report['dry_run'] ?? true),
            'rag_joplin_documents' => (int) ($report['rag_joplin_documents'] ?? 0),
            'active_joplin_sources' => (int) ($report['active_joplin_sources'] ?? 0),
            'delete_candidates' => (int) ($report['delete_candidates'] ?? 0),
            'refresh_candidates' => (int) ($report['refresh_candidates'] ?? 0),
            'selected_candidates' => (int) ($report['selected_candidates'] ?? 0),
            'limited' => (bool) ($report['limited'] ?? false),
            'limit' => $report['limit'] ?? null,
            'breakdown' => is_array($report['breakdown'] ?? null) ? $report['breakdown'] : [],
            'dependent_counts' => is_array($report['dependent_counts'] ?? null) ? $report['dependent_counts'] : [],
            'thresholds' => is_array($report['thresholds'] ?? null) ? $report['thresholds'] : [],
            'context_reconcile' => [
                'available' => (bool) ($context['available'] ?? false),
                'event_hours' => (int) ($context['event_hours'] ?? 0),
                'open_events' => (int) ($context['open_events'] ?? 0),
                'triggered_only' => (bool) ($context['triggered_only'] ?? false),
                'delete_candidate_events' => (int) ($context['delete_candidate_events'] ?? 0),
                'refresh_candidate_events' => (int) ($context['refresh_candidate_events'] ?? 0),
                'resolved_events' => (int) ($context['resolved_events'] ?? 0),
                'reason_counts' => is_array($context['reason_counts'] ?? null) ? $context['reason_counts'] : [],
                'source_state_counts' => is_array($context['source_state_counts'] ?? null) ? $context['source_state_counts'] : [],
            ],
            'deleted' => is_array($report['deleted'] ?? null) ? $report['deleted'] : [],
            'posture' => $this->compactPosture(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function compactPosture(): array
    {
        return [
            'aggregate_only' => true,
            'samples_included' => false,
            'rag_document_ids_included' => false,
            'selected_ids_included' => false,
            'source_ids_included' => false,
            'source_hashes_included' => false,
            'title_hashes_included' => false,
            'context_event_refs_included' => false,
            'raw_errors_included' => false,
        ];
    }

    private function parseLimit(): int|false|null
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        if (! is_numeric($limit) || (int) $limit < 1) {
            $this->error('--limit must be a positive integer.');

            return false;
        }

        return (int) $limit;
    }

    private function parseEventHours(): int|false
    {
        $hours = $this->option('event-hours');

        if ($hours === null || $hours === '') {
            return 72;
        }

        if (! is_numeric($hours) || (int) $hours < 1) {
            $this->error('--event-hours must be a positive integer.');

            return false;
        }

        return min(720, (int) $hours);
    }

    private function parseOptionalPositiveIntOption(string $option): int|false|null
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->error('--'.$option.' must be a positive integer.');

            return false;
        }

        return (int) $value;
    }

    /**
     * @param  array<int, string>  $preferred
     * @return array<int, string>
     */
    private function ragDocumentColumns(array $preferred): array
    {
        $columns = array_flip(Schema::connection('pgsql_rag')->getColumnListing('rag_documents'));

        return array_values(array_filter(
            $preferred,
            fn (string $column): bool => isset($columns[$column])
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(
        ?int $limit,
        int $eventHours,
        bool $triggeredOnly,
        ?int $maxDeleteCandidates,
        ?int $maxDependentRows
    ): array {
        $contextSignals = app(AgentContextReconcileSignalService::class)->recentSignals($eventHours);
        $signalsByDocumentId = $this->signalsByDocumentId($contextSignals);
        $hasDesignation = Schema::connection('pgsql_rag')->hasColumn('rag_documents', 'designation');
        $selectColumns = $this->ragDocumentColumns([
            'id',
            'source_id',
            'title',
            'source_type',
            'document_type',
            'created_at',
            'updated_at',
            'last_synced_at',
            'designation',
        ]);

        $documents = DB::connection('pgsql_rag')
            ->table('rag_documents')
            ->select($selectColumns)
            ->where(function ($query) use ($hasDesignation): void {
                $query->where(function ($inner): void {
                    $inner->where('source_type', 'joplin')
                        ->where('document_type', 'joplin_note');
                });

                if ($hasDesignation) {
                    $query->orWhere('designation', 'joplin_note');
                }
            })
            ->get();

        $sourceIds = $documents
            ->pluck('source_id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->unique()
            ->values()
            ->all();

        $cacheRows = collect();
        foreach (array_chunk($sourceIds, 500) as $chunk) {
            $cacheRows = $cacheRows->merge(DB::table('joplin_metadata_cache')
                ->select(['id', 'title', 'type', 'is_deleted', 'updated_time', 'cached_at'])
                ->whereIn('id', $chunk)
                ->get());
        }
        $cacheById = $cacheRows->keyBy('id');

        $missing = [];
        $deleted = [];
        $wrongType = [];
        $activeBySource = [];
        $refreshCandidates = [];

        foreach ($documents as $document) {
            $sourceId = is_string($document->source_id) ? trim($document->source_id) : '';

            if ($sourceId === '') {
                $missing[] = $this->candidate($document, 'missing_source_id');

                continue;
            }

            $cache = $cacheById->get($sourceId);

            if ($cache === null) {
                $missing[] = $this->candidate($document, 'missing_cache_row');

                continue;
            }

            if ((int) $cache->is_deleted === 1) {
                $deleted[] = $this->candidate($document, 'deleted_cache_row');

                continue;
            }

            if ((int) $cache->type !== 1) {
                $wrongType[] = $this->candidate($document, 'cache_row_is_not_note');

                continue;
            }

            $activeBySource[$sourceId] ??= [];
            $activeBySource[$sourceId][] = $document;

            if ($this->sourceNewerThanRag($cache->updated_time ?? null, $document->last_synced_at ?? $document->updated_at ?? null)) {
                $refreshCandidates[] = $this->candidate($document, 'joplin_cache_newer_than_rag_document');
            }
        }

        $duplicates = [];
        foreach ($activeBySource as $sourceDocuments) {
            if (count($sourceDocuments) < 2) {
                continue;
            }

            usort($sourceDocuments, fn ($left, $right): int => [
                $this->freshnessScore($right),
                (int) $right->id,
            ] <=> [
                $this->freshnessScore($left),
                (int) $left->id,
            ]);

            array_shift($sourceDocuments);

            foreach ($sourceDocuments as $duplicate) {
                $duplicates[] = $this->candidate($duplicate, 'duplicate_active_source_id');
            }
        }

        $candidates = $this->uniqueCandidates(array_merge($missing, $deleted, $wrongType, $duplicates));
        $candidates = $this->annotateCandidatesWithSignals($candidates, $signalsByDocumentId, ['omitted']);
        $refreshCandidates = $this->annotateCandidatesWithSignals(
            $this->uniqueCandidates($refreshCandidates),
            $signalsByDocumentId,
            ['stale_source']
        );
        $candidatePool = $triggeredOnly
            ? array_values(array_filter($candidates, fn (array $candidate): bool => (bool) ($candidate['context_event_seen'] ?? false)))
            : $candidates;
        $selected = $limit === null ? $candidatePool : array_slice($candidatePool, 0, $limit);
        $selectedIds = array_values(array_map(fn (array $candidate): int => $candidate['id'], $selected));
        $dependentCounts = $this->dependentCounts($selectedIds);
        $contextReconcile = $this->contextReconcileSummary(
            $contextSignals,
            $candidates,
            $refreshCandidates,
            $triggeredOnly
        );

        return [
            'rag_joplin_documents' => $documents->count(),
            'active_joplin_sources' => count($activeBySource),
            'delete_candidates' => count($candidates),
            'refresh_candidates' => count($refreshCandidates),
            'selected_candidates' => count($selected),
            'limited' => $limit !== null && count($candidatePool) > count($selected),
            'limit' => $limit,
            'breakdown' => [
                'missing_cache_or_source_id' => count($missing),
                'deleted_cache_rows' => count($deleted),
                'cache_rows_not_notes' => count($wrongType),
                'duplicate_active_source_ids' => count($duplicates),
                'refresh_recommended' => count($refreshCandidates),
            ],
            'dependent_counts' => $dependentCounts,
            'thresholds' => $this->thresholds(
                count($candidates),
                array_sum($dependentCounts),
                $maxDeleteCandidates,
                $maxDependentRows
            ),
            'context_reconcile' => $contextReconcile,
            'samples' => array_slice($selected, 0, 10),
            'selected_ids' => $selectedIds,
            'deleted' => [
                'rag_chunk_hypotheticals' => 0,
                'rag_propositions' => 0,
                'rag_dedup_log' => 0,
                'rag_documents' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholds(
        int $deleteCandidates,
        int $selectedDependentRows,
        ?int $maxDeleteCandidates,
        ?int $maxDependentRows
    ): array {
        $deleteCandidateThreshold = $this->thresholdItem($deleteCandidates, $maxDeleteCandidates);
        $dependentRowsThreshold = $this->thresholdItem($selectedDependentRows, $maxDependentRows);
        $blockReasons = [];

        if ($deleteCandidateThreshold['status'] === 'breached') {
            $blockReasons[] = 'delete_candidates_exceeds_limit';
        }

        if ($dependentRowsThreshold['status'] === 'breached') {
            $blockReasons[] = 'selected_dependent_rows_exceeds_limit';
        }

        return [
            'delete_candidates' => $deleteCandidateThreshold,
            'selected_dependent_rows' => $dependentRowsThreshold,
            'execute_allowed' => $blockReasons === [],
            'execute_block_reasons' => $blockReasons,
        ];
    }

    /**
     * @return array{actual: int, limit: ?int, status: string}
     */
    private function thresholdItem(int $actual, ?int $limit): array
    {
        if ($limit === null) {
            return [
                'actual' => $actual,
                'limit' => null,
                'status' => 'not_configured',
            ];
        }

        return [
            'actual' => $actual,
            'limit' => $limit,
            'status' => $actual > $limit ? 'breached' : 'pass',
        ];
    }

    /**
     * @return array{id: int, source_ref: ?string, reason: string, title_hash: ?string}
     */
    private function candidate(object $document, string $reason): array
    {
        $title = is_string($document->title) ? $document->title : null;
        $sourceId = is_string($document->source_id ?? null) ? trim((string) $document->source_id) : '';

        return [
            'id' => (int) $document->id,
            'source_ref' => $sourceId === '' ? null : substr(hash('sha256', $sourceId), 0, 12),
            'reason' => $reason,
            'title_hash' => $title === null ? null : substr(hash('sha256', $title), 0, 12),
        ];
    }

    private function freshnessScore(object $document): int
    {
        foreach (['last_synced_at', 'updated_at', 'created_at'] as $column) {
            $value = $document->{$column} ?? null;

            if ($value !== null && ($timestamp = strtotime((string) $value)) !== false) {
                return $timestamp;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $contextSignals
     * @return array<int, array<string, mixed>>
     */
    private function signalsByDocumentId(array $contextSignals): array
    {
        $indexed = [];

        foreach (($contextSignals['events'] ?? []) as $event) {
            if (! is_array($event) || ! is_numeric($event['rag_document_id'] ?? null)) {
                continue;
            }

            $documentId = (int) $event['rag_document_id'];
            $indexed[$documentId] ??= [];
            $indexed[$documentId][] = $event;
        }

        return $indexed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, array<int, array<string, mixed>>>  $signalsByDocumentId
     * @param  array<int, string>  $allowedStates
     * @return array<int, array<string, mixed>>
     */
    private function annotateCandidatesWithSignals(array $candidates, array $signalsByDocumentId, array $allowedStates): array
    {
        foreach ($candidates as &$candidate) {
            $signals = array_values(array_filter(
                $signalsByDocumentId[(int) $candidate['id']] ?? [],
                fn (array $signal): bool => in_array((string) ($signal['source_state'] ?? ''), $allowedStates, true)
            ));
            $candidate['context_event_seen'] = $signals !== [];
            $candidate['context_event_count'] = array_sum(array_map(
                fn (array $signal): int => (int) ($signal['event_count'] ?? 0),
                $signals
            ));
            $candidate['context_event_ref'] = $signals === [] ? null : (string) ($signals[0]['event_ref'] ?? '');
            $candidate['context_last_seen_at'] = $signals === [] ? null : ($signals[0]['last_seen_at'] ?? null);
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $contextSignals
     * @param  array<int, array<string, mixed>>  $deleteCandidates
     * @param  array<int, array<string, mixed>>  $refreshCandidates
     * @return array<string, mixed>
     */
    private function contextReconcileSummary(
        array $contextSignals,
        array $deleteCandidates,
        array $refreshCandidates,
        bool $triggeredOnly
    ): array {
        $deleteTriggered = count(array_filter(
            $deleteCandidates,
            fn (array $candidate): bool => (bool) ($candidate['context_event_seen'] ?? false)
        ));
        $refreshTriggered = count(array_filter(
            $refreshCandidates,
            fn (array $candidate): bool => (bool) ($candidate['context_event_seen'] ?? false)
        ));

        return [
            'available' => (bool) ($contextSignals['available'] ?? false),
            'event_hours' => (int) ($contextSignals['event_hours'] ?? 0),
            'open_events' => (int) ($contextSignals['open_events'] ?? 0),
            'triggered_only' => $triggeredOnly,
            'delete_candidate_events' => $deleteTriggered,
            'refresh_candidate_events' => $refreshTriggered,
            'resolved_events' => 0,
            'reason_counts' => $contextSignals['reason_counts'] ?? [],
            'source_state_counts' => $contextSignals['source_state_counts'] ?? [],
            'sample_hashes' => $contextSignals['sample_hashes'] ?? [],
        ];
    }

    private function sourceNewerThanRag(mixed $sourceTime, mixed $ragTime): bool
    {
        $sourceTimestamp = $this->timestamp($sourceTime);
        $ragTimestamp = $this->timestamp($ragTime);

        return $sourceTimestamp !== null && $ragTimestamp !== null && $sourceTimestamp > ($ragTimestamp + 60);
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<string, int>
     */
    private function dependentCounts(array $documentIds): array
    {
        return [
            'rag_sentence_embeddings' => $this->countRows('rag_sentence_embeddings', 'document_id', $documentIds),
            'raptor_summaries' => $this->countRows('raptor_summaries', 'document_id', $documentIds),
            'rag_chunk_hypotheticals' => $this->countRows('rag_chunk_hypotheticals', 'document_id', $documentIds),
            'rag_propositions' => $this->countRows('rag_propositions', 'document_id', $documentIds),
            'rag_dedup_log' => $this->countRows('rag_dedup_log', 'matched_document_id', $documentIds),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function countRows(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        if (! Schema::connection('pgsql_rag')->hasTable($table)
            || ! Schema::connection('pgsql_rag')->hasColumn($table, $column)) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $total += (int) DB::connection('pgsql_rag')
                ->table($table)
                ->whereIn($column, $chunk)
                ->count();
        }

        return $total;
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<string, int>
     */
    private function deleteDocuments(array $documentIds): array
    {
        $deleted = [
            'rag_chunk_hypotheticals' => 0,
            'rag_propositions' => 0,
            'rag_dedup_log' => 0,
            'rag_documents' => 0,
        ];

        if ($documentIds === []) {
            return $deleted;
        }

        DB::connection('pgsql_rag')->transaction(function () use ($documentIds, &$deleted): void {
            $deleted['rag_chunk_hypotheticals'] = $this->deleteRows('rag_chunk_hypotheticals', 'document_id', $documentIds);
            $deleted['rag_propositions'] = $this->deleteRows('rag_propositions', 'document_id', $documentIds);
            $deleted['rag_dedup_log'] = $this->deleteRows('rag_dedup_log', 'matched_document_id', $documentIds);
            $deleted['rag_documents'] = $this->deleteRows('rag_documents', 'id', $documentIds);
        });

        return $deleted;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteRows(string $table, string $column, array $ids): int
    {
        $deleted = 0;

        if (! Schema::connection('pgsql_rag')->hasTable($table)
            || ! Schema::connection('pgsql_rag')->hasColumn($table, $column)) {
            return 0;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += (int) DB::connection('pgsql_rag')
                ->table($table)
                ->whereIn($column, $chunk)
                ->delete();
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        if (($report['status'] ?? '') === 'blocked_by_threshold') {
            $this->error('EXECUTION BLOCKED - reconcile thresholds were breached');
        } elseif ($report['dry_run']) {
            $this->warn('DRY RUN MODE - No RAG rows were deleted');
        } else {
            $this->info('Joplin RAG reconcile complete');
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Joplin RAG documents', $report['rag_joplin_documents']],
            ['Active Joplin sources', $report['active_joplin_sources']],
            ['Delete candidates', $report['delete_candidates']],
            ['Refresh candidates', $report['refresh_candidates']],
            ['Selected candidates', $report['selected_candidates']],
            ['Limited', $report['limited'] ? 'Yes' : 'No'],
        ]);

        $this->table(
            ['Reason', 'Count'],
            array_map(
                fn (string $reason, int $count): array => [$reason, $count],
                array_keys($report['breakdown']),
                array_values($report['breakdown'])
            )
        );

        $this->table(
            ['Dependent table', 'Selected rows'],
            array_map(
                fn (string $table, int $count): array => [$table, $count],
                array_keys($report['dependent_counts']),
                array_values($report['dependent_counts'])
            )
        );

        $thresholds = $report['thresholds'] ?? [];
        $this->table(['Threshold', 'Actual', 'Limit', 'Status'], [
            [
                'Delete candidates',
                $thresholds['delete_candidates']['actual'] ?? 0,
                $thresholds['delete_candidates']['limit'] ?? 'not configured',
                $thresholds['delete_candidates']['status'] ?? 'not_configured',
            ],
            [
                'Selected dependent rows',
                $thresholds['selected_dependent_rows']['actual'] ?? 0,
                $thresholds['selected_dependent_rows']['limit'] ?? 'not configured',
                $thresholds['selected_dependent_rows']['status'] ?? 'not_configured',
            ],
            [
                'Execute allowed',
                ! empty($thresholds['execute_allowed']) ? 'Yes' : 'No',
                '',
                empty($thresholds['execute_block_reasons']) ? 'pass' : implode(', ', $thresholds['execute_block_reasons']),
            ],
        ]);

        $context = $report['context_reconcile'] ?? [];
        $this->table(['Context reconcile event', 'Value'], [
            ['Available', ! empty($context['available']) ? 'Yes' : 'No'],
            ['Event hours', $context['event_hours'] ?? 0],
            ['Open events', $context['open_events'] ?? 0],
            ['Triggered only', ! empty($context['triggered_only']) ? 'Yes' : 'No'],
            ['Delete candidate events', $context['delete_candidate_events'] ?? 0],
            ['Refresh candidate events', $context['refresh_candidate_events'] ?? 0],
        ]);

        if (! empty($report['samples'])) {
            $this->table(
                ['Document ID', 'Source Ref', 'Reason', 'Title Hash', 'Context Event'],
                array_map(
                    fn (array $sample): array => [
                        $sample['id'],
                        $sample['source_ref'] ?? '',
                        $sample['reason'],
                        $sample['title_hash'] ?? '',
                        ! empty($sample['context_event_seen']) ? ($sample['context_event_ref'] ?? 'seen') : '',
                    ],
                    $report['samples']
                )
            );
        }

        if (! $report['dry_run']) {
            $this->table(
                ['Deleted table', 'Rows'],
                array_map(
                    fn (string $table, int $count): array => [$table, $count],
                    array_keys($report['deleted']),
                    array_values($report['deleted'])
                )
            );
        }

        $processed = $report['dry_run'] ? 0 : (int) ($report['deleted']['rag_documents'] ?? 0);
        $this->line('[ITEMS_PROCESSED:'.$processed.']');
    }
}
