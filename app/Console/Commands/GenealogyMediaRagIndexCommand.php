<?php

namespace App\Console\Commands;

use App\Services\FileCategorizationRAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyMediaRagIndexCommand extends Command
{
    protected $signature = 'genealogy:media-rag-index
                            {--tree= : Limit to a specific genealogy tree ID}
                            {--limit=100 : Maximum media records to index}
                            {--max-seconds=600 : Stop after this many seconds, after finishing the current media record}
                            {--dry-run : Show eligible records without indexing}
                            {--stats : Show genealogy media RAG indexing status}';

    protected $description = 'Index genealogy_media records into RAG in bounded batches';

    public function handle(FileCategorizationRAGService $rag): int
    {
        $treeId = $this->option('tree') !== null && $this->option('tree') !== ''
            ? (int) $this->option('tree')
            : null;

        if ($this->option('stats')) {
            return $this->showStats($treeId);
        }

        $limit = max(1, (int) $this->option('limit'));
        $records = $this->getEligibleMedia($treeId, $limit);

        if ($records === []) {
            $this->info('No genealogy media records pending RAG indexing.');
            $this->line('[ITEMS_PROCESSED:0]');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d genealogy media records to index%s.',
            count($records),
            $treeId ? " for tree {$treeId}" : ''
        ));

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Tree', 'Type', 'Title'],
                array_map(static fn (object $record): array => [
                    $record->id,
                    $record->tree_id,
                    $record->media_type ?? '',
                    mb_strimwidth((string) ($record->title ?: $record->local_filename ?: ''), 0, 80, '...'),
                ], $records)
            );
            $this->line('[ITEMS_PROCESSED:0]');

            return self::SUCCESS;
        }

        $indexed = 0;
        $errors = 0;
        $startedAt = microtime(true);
        $maxSeconds = max(0, (int) $this->option('max-seconds'));

        foreach ($records as $record) {
            $result = $rag->indexGenealogyMediaFile((int) $record->id);

            if ($result['success'] ?? false) {
                $indexed++;
                $this->line("  OK media {$record->id}");
            } else {
                $errors++;
                $this->warn("  Failed media {$record->id}: ".($result['error'] ?? 'unknown'));
            }

            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                $this->warn('Wall-clock limit reached.');
                break;
            }
        }

        $this->table(['Metric', 'Count'], [
            ['Indexed', $indexed],
            ['Errors', $errors],
        ]);
        $this->line("[ITEMS_PROCESSED:{$indexed}]");

        return $errors > 0 && $indexed === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function showStats(?int $treeId): int
    {
        $treeClause = $treeId ? 'WHERE tree_id = ?' : '';
        $params = $treeId ? [$treeId] : [];

        $stats = DB::selectOne("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) AS indexed,
                SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at THEN 1 ELSE 0 END) AS stale,
                SUM(CASE WHEN (rag_indexed_at IS NULL OR updated_at > rag_indexed_at) THEN 1 ELSE 0 END) AS needs_index,
                SUM(CASE WHEN (rag_indexed_at IS NULL OR updated_at > rag_indexed_at) AND file_exists = 1 THEN 1 ELSE 0 END) AS needs_index_existing_files
            FROM genealogy_media
            {$treeClause}
        ", $params);

        $this->table(['Metric', 'Count'], [
            ['Total media', (int) ($stats->total ?? 0)],
            ['Indexed', (int) ($stats->indexed ?? 0)],
            ['Pending', (int) ($stats->pending ?? 0)],
            ['Stale', (int) ($stats->stale ?? 0)],
            ['Pending or stale', (int) ($stats->needs_index ?? 0)],
            ['Pending/stale with files', (int) ($stats->needs_index_existing_files ?? 0)],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return list<object>
     */
    private function getEligibleMedia(?int $treeId, int $limit): array
    {
        $params = [];
        $treeClause = '';

        if ($treeId) {
            $treeClause = 'AND tree_id = ?';
            $params[] = $treeId;
        }

        $params[] = $limit;

        return DB::select("
            SELECT id, tree_id, media_type, title, local_filename
            FROM genealogy_media
            WHERE (
                rag_indexed_at IS NULL
                OR updated_at > rag_indexed_at
              )
              {$treeClause}
              AND (
                NULLIF(TRIM(COALESCE(title, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(local_filename, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(description, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(ai_description, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(transcription_text, '')), '') IS NOT NULL
                OR NULLIF(TRIM(COALESCE(transcription, '')), '') IS NOT NULL
              )
            ORDER BY
              CASE WHEN file_exists = 1 THEN 0 ELSE 1 END,
              updated_at DESC,
              id DESC
            LIMIT ?
        ", $params);
    }
}
