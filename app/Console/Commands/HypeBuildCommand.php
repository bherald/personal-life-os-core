<?php

namespace App\Console\Commands;

use App\Services\HyPEService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RAG-4: HyPE index-time question generation command.
 *
 * Modes:
 *   --stats    Show indexing statistics only
 *   --screen   Evaluate hype_eligible for unscreened documents (heuristics, no LLM)
 *   --document=ID   Index one specific document
 *   (default)  Batch-index all eligible unindexed documents
 *
 * Scheduled as: rag_hype_screen (6h, 10K docs) + rag_hype_build (6h, 200 docs)
 */
class HypeBuildCommand extends Command
{
    protected $signature = 'rag:hype-build
                            {--document= : Index a specific document ID}
                            {--type=     : Filter by document_type}
                            {--limit=200 : Max documents to process per run}
                            {--rebuild   : Re-generate questions for already-indexed docs}
                            {--force     : Process ineligible or error-quarantined docs}
                            {--screen    : Screen unvetted documents for eligibility only}
                            {--timeout=  : Wall-clock timeout in minutes (screen exits 5min early)}
                            {--stats     : Show HyPE statistics only, no processing}';

    protected $description = 'Build HyPE hypothetical question index for RAG documents';

    public function handle(HyPEService $hype): int
    {
        if ($this->option('stats')) {
            return $this->showStats($hype);
        }

        $limit = (int) $this->option('limit');

        if ($this->option('screen')) {
            return $this->screenDocuments($hype, $limit);
        }

        $documentId = $this->option('document');
        if ($documentId) {
            return $this->buildSingleDocument($hype, (int) $documentId);
        }

        return $this->buildBatch($hype, $limit);
    }

    // -------------------------------------------------------------------------

    private function showStats(HyPEService $hype): int
    {
        $stats = $hype->getStats();

        $this->info('HyPE Index Statistics:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total documents (root)',  $stats['total_docs']],
                ['Eligible',               $stats['eligible']],
                ['Ineligible',             $stats['ineligible']],
                ['Unscreened',             $stats['unscreened']],
                ['Indexed',                $stats['indexed']],
                ['Pending indexing',       $stats['pending']],
                ['Total questions stored', $stats['total_questions']],
            ]
        );

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function screenDocuments(HyPEService $hype, int $limit): int
    {
        $this->info('Screening documents for HyPE eligibility...');

        $timeoutMinutes = (int) ($this->option('timeout')
            ?: DB::table('scheduled_jobs')->where('name', 'rag_hype_screen')->value('timeout_minutes')
            ?: 90);
        $startTime   = time();
        $deadlineSec = ($timeoutMinutes * 60) - 300;

        $docs = DB::connection('pgsql_rag')->select("
            SELECT id, title, source_type, content
            FROM rag_documents
            WHERE parent_id IS NULL
              AND hype_eligible IS NULL
            ORDER BY created_at DESC
            LIMIT ?
        ", [$limit]);

        if (empty($docs)) {
            $this->info('No unscreened documents found.');
            $this->info('[ITEMS_PROCESSED:0]');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Screening %d documents...', count($docs)));

        $eligible   = 0;
        $ineligible = 0;
        $processed  = 0;

        foreach ($docs as $doc) {
            if ((time() - $startTime) >= $deadlineSec) {
                $this->warn(sprintf(
                    'Stopping early after %d docs — approaching %dmin timeout (%d remaining)',
                    $processed,
                    $timeoutMinutes,
                    count($docs) - $processed
                ));
                break;
            }

            $isEligible = $hype->screenDocument($doc);

            DB::connection('pgsql_rag')->update(
                "UPDATE rag_documents SET hype_eligible = ? WHERE id = ?",
                [(int) $isEligible, $doc->id]
            );

            $isEligible ? $eligible++ : $ineligible++;
            $processed++;
        }

        $this->info(sprintf(
            'Screening complete: %d eligible, %d ineligible, %d total',
            $eligible,
            $ineligible,
            $processed
        ));
        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $processed));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function buildSingleDocument(HyPEService $hype, int $documentId): int
    {
        $doc = DB::connection('pgsql_rag')->selectOne(
            "SELECT id, title, content FROM rag_documents WHERE id = ?",
            [$documentId]
        );

        if (!$doc) {
            $this->error("Document {$documentId} not found.");
            return Command::FAILURE;
        }

        $this->info("Building HyPE for document {$documentId}: {$doc->title}");

        try {
            $result = $hype->indexDocument($documentId, $doc->content);
            $this->info(sprintf(
                'Done: %d questions generated, %d embedded (%dms)',
                $result['questions_generated'],
                $result['questions_embedded'],
                $result['duration_ms']
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // -------------------------------------------------------------------------

    private function buildBatch(HyPEService $hype, int $limit): int
    {
        $this->info('Building HyPE question index...');

        $force   = $this->option('force');
        $rebuild = $this->option('rebuild');
        $docType = $this->option('type');

        $conditions = ['parent_id IS NULL'];
        $params     = [];

        if (!$force && !$rebuild) {
            $conditions[] = 'hype_eligible = 1';
            $conditions[] = 'hype_indexed_at IS NULL';
            $conditions[] = 'COALESCE(hype_error_count, 0) < 3';
        }

        if ($docType) {
            $conditions[] = 'document_type = ?';
            $params[]     = $docType;
        }

        $where    = 'WHERE ' . implode(' AND ', $conditions);
        $params[] = $limit;

        $docs = DB::connection('pgsql_rag')->select("
            SELECT id, title, document_type, content
            FROM rag_documents
            {$where}
            ORDER BY created_at DESC
            LIMIT ?
        ", $params);

        if (empty($docs)) {
            $this->info('No documents pending HyPE indexing.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Processing %d documents...', count($docs)));
        $bar = $this->output->createProgressBar(count($docs));
        $bar->start();

        $stats = ['success' => 0, 'failed' => 0, 'questions' => 0];
        $startTime = microtime(true);
        $maxSeconds = ($this->option('timeout') ?: 40) * 60 * 0.85; // 85% of timeout
        $deadlineAt = $startTime + $maxSeconds;

        foreach ($docs as $doc) {
            if (microtime(true) >= $deadlineAt) {
                $this->newLine();
                $this->warn('Wall-clock limit reached, stopping early.');
                break;
            }

            if (($deadlineAt - microtime(true)) < 45) {
                $this->newLine();
                $this->warn('Stopping early to stay within the scheduled HyPE runtime budget.');
                break;
            }

            try {
                $result = $hype->indexDocument($doc->id, $doc->content, [
                    'skip_recursive' => true,
                    'scheduled_batch' => true,
                    'question_count' => 1,
                    'title' => $doc->title,
                ]);
                $stats['success']++;
                $stats['questions'] += $result['questions_embedded'];
            } catch (\Throwable $e) {
                $stats['failed']++;
                $hype->recordError($doc->id);
                $this->newLine();
                $this->error("Error on doc {$doc->id} ({$doc->title}): " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('HyPE Build Complete:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Documents Processed', $stats['success']],
                ['Documents Failed',    $stats['failed']],
                ['Questions Stored',    $stats['questions']],
            ]
        );

        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $stats['success']));

        if ($stats['success'] === 0 && $stats['failed'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
