<?php

namespace App\Console\Commands;

use App\Services\JoplinSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class JoplinRagRefreshTouchCommand extends Command
{
    protected $signature = 'joplin:rag-refresh-touch
                            {--execute : Touch verified unchanged stale Joplin RAG documents}
                            {--confirm : Required with --execute}
                            {--limit=50 : Maximum candidate documents to verify in this run}
                            {--max-candidates=200 : Block execute when total refresh candidates exceed this count}
                            {--json : Emit machine-readable JSON}
                            {--compact : With --json, emit aggregate-only JSON without samples or hashes}';

    protected $description = 'Safely advance last_synced_at for unchanged Joplin RAG refresh candidates';

    public function handle(JoplinSyncService $syncService): int
    {
        $limit = $this->positiveIntOption('limit', 50);
        $maxCandidates = $this->positiveIntOption('max-candidates', 200);

        if ($limit === false || $maxCandidates === false) {
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $confirmed = (bool) $this->option('confirm');
        $compact = (bool) $this->option('compact');

        try {
            $report = $this->buildReport($syncService, $limit, $maxCandidates);
            $blockedByThreshold = $execute && ! (bool) ($report['thresholds']['execute_allowed'] ?? true);
            $confirmRequired = $execute && ! $confirmed;

            if ($blockedByThreshold) {
                $report['status'] = 'blocked_by_threshold';
            } elseif ($confirmRequired) {
                $report['status'] = 'confirm_required';
            } elseif ($execute) {
                $report['touched']['rag_documents'] = $this->touchDocuments($report['verified_ids']);
                $report['status'] = 'complete';
            } else {
                $report['status'] = 'dry_run';
            }

            unset($report['verified_ids']);
            $report['mode'] = 'joplin_rag_refresh_touch';
            $report['dry_run'] = ! $execute;
            $report['execute'] = $execute;
            $report['confirmed'] = $confirmed;

            if ($this->option('json')) {
                if ($compact) {
                    $report = $this->compactReport($report);
                }

                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return ($blockedByThreshold || $confirmRequired) ? self::FAILURE : self::SUCCESS;
            }

            $this->renderReport($report);

            return ($blockedByThreshold || $confirmRequired) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $payload = [
                    'mode' => 'joplin_rag_refresh_touch',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                if ($compact) {
                    $payload = [
                        'mode' => 'joplin_rag_refresh_touch',
                        'compact' => true,
                        'status' => 'failed',
                        'error_type' => class_basename($e),
                        'posture' => $this->compactPosture($execute),
                    ];
                }

                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->error('Joplin RAG refresh touch failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function positiveIntOption(string $name, int $default): int|false
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->error("--{$name} must be a positive integer.");

            return false;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(JoplinSyncService $syncService, int $limit, int $maxCandidates): array
    {
        $candidateScan = $this->refreshCandidates();
        $candidates = $candidateScan['candidates'];
        $selected = array_slice($candidates, 0, $limit);
        $sourceIds = array_values(array_map(
            fn (array $candidate): string => (string) $candidate['source_id'],
            $selected
        ));
        $liveHashes = $syncService->currentNoteContentHashes($sourceIds);

        $verified = [];
        $requiresFullSync = [];
        $missingLive = [];
        $missingStoredHash = [];

        foreach ($selected as $candidate) {
            $sourceId = (string) $candidate['source_id'];
            $storedHash = trim((string) ($candidate['content_hash'] ?? ''));

            if ($storedHash === '') {
                $missingStoredHash[] = $candidate;

                continue;
            }

            $liveHash = $liveHashes[$sourceId] ?? null;
            if (! is_string($liveHash) || $liveHash === '') {
                $missingLive[] = $candidate;

                continue;
            }

            if (! hash_equals($storedHash, $liveHash)) {
                $requiresFullSync[] = $candidate;

                continue;
            }

            $verified[] = $candidate;
        }

        $refreshCandidates = count($candidates) + (int) $candidateScan['duplicate_conflicts'];

        return [
            'refresh_candidates' => $refreshCandidates,
            'eligible_candidates' => count($candidates),
            'selected_candidates' => count($selected),
            'limited' => count($candidates) > count($selected),
            'limit' => $limit,
            'breakdown' => [
                'verified_unchanged' => count($verified),
                'requires_full_sync' => count($requiresFullSync),
                'missing_live_note' => count($missingLive),
                'missing_stored_hash' => count($missingStoredHash),
                'duplicate_source_conflicts' => (int) $candidateScan['duplicate_conflicts'],
            ],
            'thresholds' => [
                'refresh_candidates' => [
                    'actual' => $refreshCandidates,
                    'limit' => $maxCandidates,
                    'status' => $refreshCandidates > $maxCandidates ? 'breached' : 'pass',
                ],
                'execute_allowed' => $refreshCandidates <= $maxCandidates,
                'execute_block_reasons' => $refreshCandidates > $maxCandidates
                    ? ['refresh_candidates_exceeds_limit']
                    : [],
            ],
            'samples' => array_slice(array_map(fn (array $candidate): array => $this->sample($candidate), $selected), 0, 10),
            'verified_ids' => array_values(array_map(fn (array $candidate): int => (int) $candidate['id'], $verified)),
            'touched' => [
                'rag_documents' => 0,
            ],
        ];
    }

    /**
     * @return array{candidates: list<array<string, mixed>>, duplicate_conflicts: int}
     */
    private function refreshCandidates(): array
    {
        $documents = DB::connection('pgsql_rag')
            ->table('rag_documents')
            ->select([
                'id',
                'source_id',
                'title',
                'source_type',
                'document_type',
                'designation',
                'content_hash',
                'last_synced_at',
                'updated_at',
            ])
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('source_type', 'joplin')
                        ->where('document_type', 'joplin_note');
                })->orWhere('designation', 'joplin_note');
            })
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->get();

        if ($documents->isEmpty()) {
            return ['candidates' => [], 'duplicate_conflicts' => 0];
        }

        $sourceIds = $documents
            ->map(fn (object $document): string => trim((string) ($document->source_id ?? '')))
            ->filter(fn (string $sourceId): bool => $sourceId !== '')
            ->unique()
            ->values()
            ->all();

        $cacheRows = DB::table('joplin_metadata_cache')
            ->select(['id', 'type', 'is_deleted', 'updated_time'])
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        $bySource = [];
        foreach ($documents as $document) {
            $sourceId = trim((string) ($document->source_id ?? ''));
            if ($sourceId === '') {
                continue;
            }

            $cache = $cacheRows[$sourceId] ?? null;
            if ($cache === null || (int) ($cache->is_deleted ?? 0) === 1 || (int) ($cache->type ?? 0) !== 1) {
                continue;
            }

            if (! $this->sourceNewerThanRag($cache->updated_time ?? null, $document->last_synced_at ?? $document->updated_at ?? null)) {
                continue;
            }

            $bySource[$sourceId] ??= [];
            $bySource[$sourceId][] = [
                'id' => (int) $document->id,
                'source_id' => $sourceId,
                'title' => is_string($document->title ?? null) ? (string) $document->title : null,
                'content_hash' => is_string($document->content_hash ?? null) ? (string) $document->content_hash : null,
            ];
        }

        $candidates = [];
        $duplicateConflicts = 0;
        foreach ($bySource as $sourceCandidates) {
            if (count($sourceCandidates) !== 1) {
                $duplicateConflicts += count($sourceCandidates);

                continue;
            }

            $candidates[] = $sourceCandidates[0];
        }

        usort($candidates, fn (array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));

        return [
            'candidates' => $candidates,
            'duplicate_conflicts' => $duplicateConflicts,
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
     * @param  array<string, mixed>  $candidate
     * @return array{source_ref: string, title_hash: ?string}
     */
    private function sample(array $candidate): array
    {
        $sourceId = (string) ($candidate['source_id'] ?? '');
        $title = is_string($candidate['title'] ?? null) ? (string) $candidate['title'] : null;

        return [
            'source_ref' => substr(hash('sha256', $sourceId), 0, 12),
            'title_hash' => $title === null ? null : substr(hash('sha256', $title), 0, 12),
        ];
    }

    /**
     * @param  list<int>  $documentIds
     */
    private function touchDocuments(array $documentIds): int
    {
        $documentIds = array_values(array_unique(array_filter(
            array_map(fn (int $id): int => $id, $documentIds),
            fn (int $id): bool => $id > 0
        )));

        if ($documentIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));

        return DB::connection('pgsql_rag')->update(
            "UPDATE rag_documents SET last_synced_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})",
            $documentIds
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function compactReport(array $report): array
    {
        return [
            'mode' => (string) ($report['mode'] ?? 'joplin_rag_refresh_touch'),
            'compact' => true,
            'status' => (string) ($report['status'] ?? 'unknown'),
            'dry_run' => (bool) ($report['dry_run'] ?? true),
            'execute' => (bool) ($report['execute'] ?? false),
            'confirmed' => (bool) ($report['confirmed'] ?? false),
            'refresh_candidates' => (int) ($report['refresh_candidates'] ?? 0),
            'eligible_candidates' => (int) ($report['eligible_candidates'] ?? 0),
            'selected_candidates' => (int) ($report['selected_candidates'] ?? 0),
            'limited' => (bool) ($report['limited'] ?? false),
            'limit' => (int) ($report['limit'] ?? 0),
            'breakdown' => is_array($report['breakdown'] ?? null) ? $report['breakdown'] : [],
            'thresholds' => is_array($report['thresholds'] ?? null) ? $report['thresholds'] : [],
            'touched' => is_array($report['touched'] ?? null) ? $report['touched'] : [],
            'posture' => $this->compactPosture((bool) ($report['execute'] ?? false)),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function compactPosture(bool $execute): array
    {
        return [
            'aggregate_only' => true,
            'samples_included' => false,
            'rag_document_ids_included' => false,
            'source_ids_included' => false,
            'source_hashes_included' => false,
            'title_hashes_included' => false,
            'raw_note_content_included' => false,
            'database_mutation_enabled' => $execute,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->line(sprintf(
            'Joplin RAG refresh touch: %s refresh=%d eligible=%d selected=%d verified=%d touched=%d',
            (string) ($report['status'] ?? 'unknown'),
            (int) ($report['refresh_candidates'] ?? 0),
            (int) ($report['eligible_candidates'] ?? 0),
            (int) ($report['selected_candidates'] ?? 0),
            (int) ($report['breakdown']['verified_unchanged'] ?? 0),
            (int) ($report['touched']['rag_documents'] ?? 0),
        ));

        if (! (bool) ($report['execute'] ?? false)) {
            $this->warn('dry-run only; add --execute --confirm to touch verified unchanged rows.');
        }
    }
}
