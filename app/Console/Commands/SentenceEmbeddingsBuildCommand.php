<?php

namespace App\Console\Commands;

use App\Services\SentenceWindowRetrievalService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SentenceEmbeddingsBuildCommand extends Command
{
    protected $signature = 'rag:build-sentences {--limit=200} {--force} {--screen} {--stats : Display sentence-indexing backlog statistics without processing}';

    protected $description = 'Generate sentence embeddings for RAG documents';

    public function handle(SentenceWindowRetrievalService $sentenceService): int
    {
        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $screen = $this->option('screen');
        $stats = $this->option('stats');

        if ($stats) {
            $this->printSeStats();

            return Command::SUCCESS;
        }

        if ($screen) {
            return $this->screenDocuments($sentenceService, $limit);
        }

        $this->info('Building sentence embeddings for RAG documents...');

        // N81: filter by se_eligible=1 unless --force
        if ($force) {
            $whereClause = "WHERE (sentence_indexed_at IS NULL AND (embedding_mode IS NULL OR embedding_mode = 'chunk'))";
        } else {
            $whereClause = "WHERE sentence_indexed_at IS NULL
                              AND (embedding_mode IS NULL OR embedding_mode = 'chunk')
                              AND se_eligible = 1";
        }

        $documents = DB::connection('pgsql_rag')->select("
            SELECT id, title, content, document_type
            FROM rag_documents
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT ?
        ", [$limit]);

        if (empty($documents)) {
            $this->info('No documents pending sentence embedding generation.');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Processing %d documents...', count($documents)));
        $bar = $this->output->createProgressBar(count($documents));
        $bar->start();

        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'total_sentences' => 0];

        foreach ($documents as $doc) {
            try {
                $result = $sentenceService->indexDocument($doc->id, $doc->content, true);

                if ($result['success']) {
                    // Mark as processed
                    DB::connection('pgsql_rag')->update(
                        'UPDATE rag_documents SET sentence_indexed_at = NOW() WHERE id = ?',
                        [$doc->id]
                    );

                    $stats['success']++;
                    $stats['total_sentences'] += $result['sentence_count'] ?? 0;
                } else {
                    if ($result['permanent'] ?? false) {
                        // Document has no splittable content — mark permanently, don't retry
                        DB::connection('pgsql_rag')->update(
                            "UPDATE rag_documents SET sentence_indexed_at = NOW(), embedding_mode = 'sentence_failed' WHERE id = ?",
                            [$doc->id]
                        );
                        $stats['skipped']++;
                    } else {
                        $stats['failed']++;
                        // Transient failure — skip without marking, will retry next run
                        $this->newLine();
                        $this->warn("Failed: {$doc->title} - ".($result['error'] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $e) {
                $stats['failed']++;
                $this->newLine();
                $this->error("Error processing {$doc->id}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Sentence Embeddings Build Complete:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Documents Processed', $stats['success']],
                ['Documents Skipped (permanent)', $stats['skipped']],
                ['Documents Failed', $stats['failed']],
                ['Total Sentences Indexed', $stats['total_sentences']],
                ['Avg Sentences/Doc', $stats['success'] > 0 ? round($stats['total_sentences'] / $stats['success'], 1) : 0],
            ]
        );

        // Emit structured items_processed marker for ScheduledJobService (success + skipped = work done)
        $this->line('[ITEMS_PROCESSED:'.($stats['success'] + $stats['skipped']).']');

        // Only fail if all remaining docs had transient failures (skipped = legitimate work done)
        return $stats['success'] === 0 && $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Screen unvetted documents for se_eligible (N81).
     * Sets se_eligible = 1 (eligible) or 0 (ineligible) using heuristics only.
     */
    private function screenDocuments(SentenceWindowRetrievalService $sentenceService, int $limit): int
    {
        $this->info('Screening documents for sentence-indexing eligibility...');

        $documents = DB::connection('pgsql_rag')->select('
            SELECT id, content, document_type
            FROM rag_documents
            WHERE se_eligible IS NULL
            ORDER BY created_at DESC
            LIMIT ?
        ', [$limit]);

        if (empty($documents)) {
            $this->info('No unscreened documents found.');
            $this->printSeStats();

            return Command::SUCCESS;
        }

        $this->info(sprintf('Screening %d documents...', count($documents)));
        $bar = $this->output->createProgressBar(count($documents));
        $bar->start();

        $eligible = 0;
        $ineligible = 0;

        foreach ($documents as $doc) {
            $isEligible = $sentenceService->screenForIndexing($doc) ? 1 : 0;

            DB::connection('pgsql_rag')->update(
                'UPDATE rag_documents SET se_eligible = ? WHERE id = ?',
                [$isEligible, $doc->id]
            );

            $isEligible ? $eligible++ : $ineligible++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Screening Complete:');
        $this->table(
            ['Category', 'Count'],
            [
                ['Eligible (se_eligible=1)', $eligible],
                ['Ineligible (se_eligible=0)', $ineligible],
                ['Total Screened', $eligible + $ineligible],
            ]
        );

        $this->printSeStats();

        $this->line('[ITEMS_PROCESSED:'.($eligible + $ineligible).']');

        return Command::SUCCESS;
    }

    private function printSeStats(): void
    {
        try {
            $stats = DB::connection('pgsql_rag')->selectOne("
                SELECT
                    COUNT(*) FILTER (WHERE se_eligible = 1) as eligible_total,
                    COUNT(*) FILTER (
                        WHERE se_eligible = 1
                          AND sentence_indexed_at IS NULL
                          AND (embedding_mode IS NULL OR embedding_mode = 'chunk')
                    ) as eligible_pending,
                    COUNT(*) FILTER (WHERE se_eligible = 0) as ineligible,
                    COUNT(*) FILTER (WHERE se_eligible IS NULL) as unscreened,
                    COUNT(*) FILTER (WHERE sentence_indexed_at IS NOT NULL) as indexed,
                    COUNT(*) FILTER (WHERE embedding_mode = 'sentence_failed') as permanent_failed
                FROM rag_documents
            ");
        } catch (\Throwable) {
            $this->warn('Sentence eligibility columns are unavailable; showing legacy sentence-index stats.');

            try {
                $legacyStats = DB::connection('pgsql_rag')->selectOne("
                    SELECT
                        COUNT(*) FILTER (
                            WHERE sentence_indexed_at IS NULL
                              AND (embedding_mode IS NULL OR embedding_mode = 'chunk')
                        ) as pending_index,
                        COUNT(*) FILTER (WHERE sentence_indexed_at IS NOT NULL) as indexed,
                        COUNT(*) FILTER (WHERE embedding_mode = 'sentence_failed') as permanent_failed
                    FROM rag_documents
                ");
            } catch (\Throwable) {
                $minimumStats = DB::connection('pgsql_rag')->selectOne('
                    SELECT COUNT(*) as documents
                    FROM rag_documents
                ');

                if ($minimumStats) {
                    $this->table(
                        ['Status', 'Count'],
                        [
                            ['Documents (legacy minimum)', $minimumStats->documents],
                        ]
                    );
                }

                return;
            }

            if ($legacyStats) {
                $this->table(
                    ['Status', 'Count'],
                    [
                        ['Pending index (legacy)', $legacyStats->pending_index],
                        ['Already Indexed', $legacyStats->indexed],
                        ['Permanent Failed', $legacyStats->permanent_failed],
                    ]
                );
            }

            return;
        }

        if ($stats) {
            $this->table(
                ['Status', 'Count'],
                [
                    ['Eligible (total)', $stats->eligible_total],
                    ['Eligible (pending index)', $stats->eligible_pending],
                    ['Ineligible', $stats->ineligible],
                    ['Unscreened (NULL)', $stats->unscreened],
                    ['Already Indexed', $stats->indexed],
                    ['Permanent Failed', $stats->permanent_failed],
                ]
            );
        }
    }
}
