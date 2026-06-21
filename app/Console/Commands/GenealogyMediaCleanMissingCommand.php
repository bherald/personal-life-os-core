<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GenealogyMediaCleanMissingCommand extends Command
{
    protected $signature = 'genealogy:media-clean-missing
                            {--tree=4 : Genealogy tree id}
                            {--execute : Delete eligible missing unlinked media rows}
                            {--limit=100 : Maximum candidate rows selected for this run}
                            {--max-rows=100 : Block execute if total eligible rows exceeds this count}
                            {--json : Emit machine-readable JSON}
                            {--compact : Omit raw ids, paths, and titles}';

    protected $description = 'Dry-run-first cleanup for missing unlinked genealogy_media rows';

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $maxRows = max(1, min(1000, (int) $this->option('max-rows')));
        $execute = (bool) $this->option('execute');

        $missingTables = $this->missingTables();
        if ($missingTables !== []) {
            return $this->emit([
                'status' => 'blocked',
                'error' => 'missing_required_tables',
                'missing_tables' => $missingTables,
                'tree_id' => $treeId,
            ], self::FAILURE);
        }

        $base = $this->eligibleQuery($treeId);
        $candidateCount = (clone $base)->distinct()->count('gm.id');
        $rows = (clone $base)
            ->select(['gm.id', 'gm.nextcloud_path'])
            ->distinct()
            ->orderBy('gm.id')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $pathsById = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $path = is_scalar($row->nextcloud_path ?? null) ? trim((string) $row->nextcloud_path) : '';
            if ($path !== '') {
                $pathsById[$id] = $path;
            }
        }

        $ragCheck = $this->ragBlockedIds($ids);
        $ragBlocked = $ragCheck['blocked_ids'];
        $eligibleIds = array_values(array_diff($ids, $ragBlocked));
        $eligiblePaths = [];
        foreach ($eligibleIds as $id) {
            if (isset($pathsById[$id])) {
                $eligiblePaths[] = $pathsById[$id];
            }
        }
        $eligiblePaths = array_values(array_unique($eligiblePaths));

        $scanLogRefs = $eligiblePaths === [] ? 0 : DB::table('genealogy_media_scan_log')
            ->where('tree_id', $treeId)
            ->whereIn('nextcloud_path', $eligiblePaths)
            ->count();

        $blockers = [];
        if ($candidateCount > $maxRows) {
            $blockers[] = 'candidate_count_exceeds_max_rows';
        }
        if ($ragCheck['status'] !== 'ok') {
            $blockers[] = $ragCheck['status'];
        }
        if ($ids !== [] && $eligibleIds === []) {
            $blockers[] = 'all_selected_rows_blocked_by_rag';
        }

        $deleted = [
            'genealogy_media_scan_log' => 0,
            'genealogy_media' => 0,
        ];
        $treeMediaCount = DB::table('genealogy_media')->where('tree_id', $treeId)->count();
        $status = $execute ? 'blocked' : 'dry_run';

        if ($execute && $blockers === []) {
            DB::transaction(function () use ($treeId, $eligibleIds, $eligiblePaths, &$deleted, &$treeMediaCount): void {
                if ($eligiblePaths !== []) {
                    $deleted['genealogy_media_scan_log'] = DB::table('genealogy_media_scan_log')
                        ->where('tree_id', $treeId)
                        ->whereIn('nextcloud_path', $eligiblePaths)
                        ->delete();
                }

                if ($eligibleIds !== []) {
                    $deleted['genealogy_media'] = DB::table('genealogy_media')
                        ->where('tree_id', $treeId)
                        ->whereIn('id', $eligibleIds)
                        ->delete();
                }

                $treeMediaCount = DB::table('genealogy_media')->where('tree_id', $treeId)->count();
                DB::table('genealogy_trees')->where('id', $treeId)->update([
                    'media_count' => $treeMediaCount,
                    'updated_at' => now(),
                ]);
            });
            $status = 'complete';
        }

        $payload = [
            'version' => 1,
            'command' => 'genealogy:media-clean-missing',
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $status,
            'execute' => $execute,
            'dry_run' => ! $execute,
            'tree_id' => $treeId,
            'limit' => $limit,
            'max_rows' => $maxRows,
            'summary' => [
                'candidate_count' => $candidateCount,
                'selected_count' => count($ids),
                'eligible_selected_count' => count($eligibleIds),
                'rag_blocked_count' => count($ragBlocked),
                'scan_log_refs' => $scanLogRefs,
                'tree_media_count' => $treeMediaCount,
            ],
            'deleted' => $deleted,
            'blockers' => $blockers,
            'safety' => [
                'missing_file_only' => true,
                'unlinked_person_media_only' => true,
                'unlinked_family_media_only' => true,
                'no_citation_refs' => true,
                'no_primary_photo_refs' => true,
                'no_face_queue_refs' => true,
                'no_media_file_or_crop_refs' => true,
                'rag_document_guard' => true,
                'bounded_execute' => true,
            ],
            'posture' => [
                'aggregate_only' => (bool) $this->option('compact'),
                'ids_included' => false,
                'paths_included' => false,
                'titles_included' => false,
                'canonical_fact_mutation_enabled' => false,
                'file_mutation_enabled' => false,
                'database_mutation_enabled' => $execute && $blockers === [],
            ],
        ];

        return $this->emit($payload, $status === 'blocked' && $execute ? self::FAILURE : self::SUCCESS);
    }

    /**
     * @return list<string>
     */
    private function missingTables(): array
    {
        $required = [
            'genealogy_media',
            'genealogy_media_scan_log',
            'genealogy_person_media',
            'genealogy_family_media',
            'genealogy_citations',
            'genealogy_persons',
            'genealogy_face_match_queue',
            'genealogy_media_files',
            'genealogy_media_crops',
            'genealogy_trees',
        ];

        return array_values(array_filter(
            $required,
            static fn (string $table): bool => ! Schema::hasTable($table)
        ));
    }

    private function eligibleQuery(int $treeId): mixed
    {
        return DB::table('genealogy_media as gm')
            ->leftJoin('genealogy_person_media as pm', 'pm.media_id', '=', 'gm.id')
            ->leftJoin('genealogy_family_media as fm', 'fm.media_id', '=', 'gm.id')
            ->leftJoin('genealogy_citations as gc', 'gc.media_id', '=', 'gm.id')
            ->leftJoin('genealogy_persons as gp', 'gp.primary_photo_id', '=', 'gm.id')
            ->leftJoin('genealogy_face_match_queue as fq', 'fq.media_id', '=', 'gm.id')
            ->leftJoin('genealogy_media_files as mf', 'mf.media_id', '=', 'gm.id')
            ->leftJoin('genealogy_media_crops as mc', 'mc.media_id', '=', 'gm.id')
            ->where('gm.tree_id', $treeId)
            ->where('gm.file_exists', 0)
            ->whereNull('pm.media_id')
            ->whereNull('fm.media_id')
            ->whereNull('gc.media_id')
            ->whereNull('gp.primary_photo_id')
            ->whereNull('fq.media_id')
            ->whereNull('mf.media_id')
            ->whereNull('mc.media_id');
    }

    /**
     * @param  list<int>  $ids
     * @return array{status: string, blocked_ids: list<int>}
     */
    private function ragBlockedIds(array $ids): array
    {
        if ($ids === []) {
            return ['status' => 'ok', 'blocked_ids' => []];
        }

        try {
            if (! Schema::connection('pgsql_rag')->hasTable('rag_documents')) {
                return ['status' => 'rag_documents_table_missing', 'blocked_ids' => $ids];
            }

            $sourceIds = array_map(static fn (int $id): string => 'gen_media_'.$id, $ids);
            $blockedSourceIds = DB::connection('pgsql_rag')
                ->table('rag_documents')
                ->where('source_type', 'genealogy_media')
                ->whereIn('source_id', $sourceIds)
                ->distinct()
                ->pluck('source_id')
                ->all();

            $blocked = [];
            foreach ($blockedSourceIds as $sourceId) {
                if (is_scalar($sourceId) && preg_match('/^gen_media_(\d+)$/', (string) $sourceId, $m) === 1) {
                    $blocked[] = (int) $m[1];
                }
            }

            return ['status' => 'ok', 'blocked_ids' => array_values(array_unique($blocked))];
        } catch (Throwable) {
            return ['status' => 'rag_guard_unavailable', 'blocked_ids' => $ids];
        }
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
            '%s: candidates=%d selected=%d deleted=%d scan_log_deleted=%d',
            $payload['status'] ?? 'unknown',
            $payload['summary']['candidate_count'] ?? 0,
            $payload['summary']['selected_count'] ?? 0,
            $payload['deleted']['genealogy_media'] ?? 0,
            $payload['deleted']['genealogy_media_scan_log'] ?? 0,
        ));

        return $exitCode;
    }
}
