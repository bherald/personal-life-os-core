<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyMediaRepairEnrichmentStateCommand extends Command
{
    protected $signature = 'genealogy:media-repair-enrichment-state
                            {--tree=4 : Genealogy tree id}
                            {--execute : Repair stale enrichment state rows}
                            {--limit=100 : Maximum candidate rows selected for this run}
                            {--max-rows=100 : Block execute if total eligible rows exceeds this count}
                            {--stale-hours=24 : Minimum age for pending/processing state to be considered stale}
                            {--json : Emit machine-readable JSON}
                            {--compact : Omit raw ids, paths, and titles}';

    protected $description = 'Dry-run-first repair for stale genealogy media enrichment pending/processing states';

    private const SUPPORTED_MEDIA_TYPES = ['obituary', 'census', 'certificate', 'document', 'military', 'headstone'];

    private const REPAIRABLE_STATUSES = ['pending', 'processing'];

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $maxRows = max(1, min(1000, (int) $this->option('max-rows')));
        $staleHours = max(1, min(720, (int) $this->option('stale-hours')));
        $execute = (bool) $this->option('execute');

        if (! Schema::hasTable('genealogy_media')) {
            return $this->emit([
                'status' => 'blocked',
                'error' => 'missing_required_table',
                'missing_tables' => ['genealogy_media'],
                'tree_id' => $treeId,
            ], self::FAILURE);
        }

        $base = $this->candidateQuery($treeId, $staleHours);
        $candidateCount = (clone $base)->count();
        $rows = (clone $base)
            ->select(['gm.id', 'gm.enrichment_status'])
            ->orderByRaw("CASE gm.enrichment_status WHEN 'processing' THEN 0 ELSE 1 END")
            ->orderBy('gm.id')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $pendingIds = [];
        $processingIds = [];
        foreach ($rows as $row) {
            $status = is_scalar($row->enrichment_status ?? null) ? (string) $row->enrichment_status : '';
            if ($status === 'processing') {
                $processingIds[] = (int) $row->id;
            } elseif ($status === 'pending') {
                $pendingIds[] = (int) $row->id;
            }
        }

        $blockers = [];
        if ($candidateCount > $maxRows) {
            $blockers[] = 'candidate_count_exceeds_max_rows';
        }

        $updated = [
            'pending_to_null' => 0,
            'processing_to_failed' => 0,
        ];
        $status = $execute ? 'blocked' : 'dry_run';

        if ($execute && $blockers === []) {
            DB::transaction(function () use ($pendingIds, $processingIds, &$updated): void {
                $stamp = now()->toIso8601String();
                if ($pendingIds !== []) {
                    $updated['pending_to_null'] = DB::table('genealogy_media')
                        ->whereIn('id', $pendingIds)
                        ->update([
                            'enrichment_status' => null,
                            'enrichment_error' => 'stale_pending_reset:'.$stamp,
                            'updated_at' => now(),
                        ]);
                }

                if ($processingIds !== []) {
                    $updated['processing_to_failed'] = DB::table('genealogy_media')
                        ->whereIn('id', $processingIds)
                        ->update([
                            'enrichment_status' => 'failed',
                            'enrichment_error' => 'stale_processing_reset:'.$stamp,
                            'updated_at' => now(),
                        ]);
                }
            });
            $status = 'complete';
        }

        return $this->emit([
            'version' => 1,
            'command' => 'genealogy:media-repair-enrichment-state',
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $status,
            'execute' => $execute,
            'dry_run' => ! $execute,
            'tree_id' => $treeId,
            'limit' => $limit,
            'max_rows' => $maxRows,
            'stale_hours' => $staleHours,
            'summary' => [
                'candidate_count' => $candidateCount,
                'selected_count' => count($ids),
                'pending_selected_count' => count($pendingIds),
                'processing_selected_count' => count($processingIds),
            ],
            'updated' => $updated,
            'blockers' => $blockers,
            'safety' => [
                'supported_genealogy_media_types_only' => true,
                'file_exists_only' => true,
                'analysis_completed_or_transcript_backed_only' => true,
                'stale_pending_or_processing_only' => true,
                'bounded_execute' => true,
            ],
            'posture' => [
                'aggregate_only' => (bool) $this->option('compact'),
                'ids_included' => false,
                'paths_included' => false,
                'titles_included' => false,
                'transcription_mutation_enabled' => false,
                'canonical_fact_mutation_enabled' => false,
                'proposal_generation_enabled' => false,
                'file_mutation_enabled' => false,
                'database_mutation_enabled' => $execute && $blockers === [],
            ],
        ], $status === 'blocked' && $execute ? self::FAILURE : self::SUCCESS);
    }

    private function candidateQuery(int $treeId, int $staleHours): Builder
    {
        $cutoff = now()->subHours($staleHours);

        return DB::table('genealogy_media as gm')
            ->where('gm.tree_id', $treeId)
            ->whereIn('gm.media_type', self::SUPPORTED_MEDIA_TYPES)
            ->where('gm.file_exists', 1)
            ->whereIn('gm.enrichment_status', self::REPAIRABLE_STATUSES)
            ->where(function (Builder $query): void {
                $query->where('gm.analysis_status', 'completed')
                    ->orWhereRaw("TRIM(COALESCE(gm.transcription_text, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(gm.transcription, '')) <> ''");
            })
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('gm.updated_at')
                    ->orWhere('gm.updated_at', '<=', $cutoff);
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->line(sprintf(
            '%s: candidates=%d selected=%d pending=%d processing=%d updated=%d',
            $payload['status'] ?? 'unknown',
            $payload['summary']['candidate_count'] ?? 0,
            $payload['summary']['selected_count'] ?? 0,
            $payload['summary']['pending_selected_count'] ?? 0,
            $payload['summary']['processing_selected_count'] ?? 0,
            array_sum($payload['updated'] ?? []),
        ));

        return $exitCode;
    }
}
