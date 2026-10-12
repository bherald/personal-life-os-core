<?php

namespace App\Console\Commands;

use App\Services\SPLADEService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SpladeBatchIndexCommand extends Command
{
    protected $signature = 'rag:splade-index
        {--limit=100 : Maximum documents to process per run}
        {--stats : Show SPLADE indexing statistics only}';

    protected $description = 'Batch index SPLADE sparse embeddings for RAG documents';

    public function handle(SPLADEService $splade): int
    {
        if ($this->option('stats')) {
            return $this->showStats($splade);
        }

        $limit = (int) $this->option('limit');

        $availability = $splade->availabilityStatus(true);
        if (($availability['state'] ?? 'unavailable') !== 'available') {
            $state = (string) ($availability['state'] ?? 'unavailable');
            $this->warn(sprintf(
                'SPLADE deferred: state=%s registered=%d recovery_attempted=%d retry=next_schedule',
                $state,
                (int) ($availability['registered'] ?? 0),
                (int) ($availability['recovery_attempted'] ?? 0)
            ));
            $this->line('[DEFERRED:compute_capability_unavailable]');
            $this->line('[ITEMS_PROCESSED:0]');

            return Command::SUCCESS;
        }

        $this->info("SPLADE batch indexing (limit: {$limit})...");

        $result = $splade->batchIndex($limit);

        $this->info(sprintf(
            'SPLADE batch complete: %d indexed, %d failed, %d skipped',
            $result['indexed'] ?? 0,
            $result['failed'] ?? 0,
            $result['skipped'] ?? 0
        ));

        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $result['indexed'] ?? 0));

        $indexed = $result['indexed'] ?? 0;
        $failed = $result['failed'] ?? 0;

        if ($failed > 0 && $indexed === 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function showStats(SPLADEService $splade): int
    {
        $row = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) AS total,
                COUNT(sparse_embedding) AS indexed,
                COUNT(*) - COUNT(sparse_embedding) AS pending,
                COUNT(splade_indexed_at) AS with_timestamp
            FROM rag_documents
            WHERE content IS NOT NULL AND LENGTH(content) > 50
        ');

        $this->table(['Metric', 'Value'], [
            ['SPLADE Available', $splade->isAvailable() ? 'Yes' : 'No'],
            ['Eligible Documents', number_format($row->total)],
            ['SPLADE Indexed', number_format($row->indexed)],
            ['Pending', number_format($row->pending)],
        ]);

        return Command::SUCCESS;
    }
}
