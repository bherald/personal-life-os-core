<?php

namespace App\Console\Commands;

use App\Services\RaptorSummarizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RaptorBuildCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 480;

    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 900;

    protected $signature = 'rag:raptor-build
                            {--document= : Build for a specific document ID}
                            {--type= : Filter by document type (e.g. joplin_note)}
                            {--limit=200 : Max documents to process per run}
                            {--rebuild : Rebuild existing hierarchies (delete + recreate)}
                            {--force : Process already-indexed or quarantined documents}
                            {--screen : Screen unvetted documents for eligibility (no building)}
                            {--rescreen : GR-7: Reset too_short-ineligible docs ≥2000 chars for re-screening}
                            {--timeout= : Wall-clock timeout in minutes (screen mode exits 5min early)}
                            {--stats : Show RAPTOR statistics only, no processing}';

    protected $description = 'Build RAPTOR hierarchical summaries for RAG documents';

    public function handle(RaptorSummarizationService $raptorService): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = (int) $this->option('limit');

        if ($this->option('rescreen')) {
            return $this->rescreenThresholdChange($limit);
        }

        if ($this->option('screen')) {
            return $this->screenDocuments($raptorService, $limit);
        }

        $force = $this->option('force');
        $rebuild = $this->option('rebuild');
        $documentId = $this->option('document');
        $docType = $this->option('type');

        if ($documentId) {
            return $this->buildSingleDocument($raptorService, (int) $documentId, $rebuild);
        }

        $this->info('Building RAPTOR hierarchies for RAG documents...');

        $conditions = ['parent_id IS NULL'];
        $params = [];

        if (! $force && ! $rebuild) {
            $conditions[] = 'raptor_indexed_at IS NULL';
            $conditions[] = 'COALESCE(raptor_error_count, 0) < 3';
            $conditions[] = 'raptor_eligible = 1';
        }

        if ($docType) {
            $conditions[] = 'document_type = ?';
            $params[] = $docType;
        }

        $whereClause = 'WHERE '.implode(' AND ', $conditions);
        $params[] = $limit;

        $documents = DB::connection('pgsql_rag')->select("
            SELECT id, title, document_type, source_type
            FROM rag_documents
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT ?
        ", $params);

        if (empty($documents)) {
            $this->info('No documents pending RAPTOR indexing.');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Processing %d documents...', count($documents)));
        $bar = $this->output->createProgressBar(count($documents));
        $bar->start();
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        $stats = [
            'success' => 0,
            'failed' => 0,
            'total_summaries' => 0,
            'by_level' => ['paragraph' => 0, 'section' => 0, 'document' => 0],
        ];

        foreach ($documents as $doc) {
            if ($this->shouldStopBeforeDocument($startedAt, $deadlineSeconds, $stats['success'] + $stats['failed'])) {
                $this->newLine();
                $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s)");
                break;
            }

            try {
                if ($rebuild) {
                    $raptorService->deleteHierarchy($doc->id);
                }

                $result = $raptorService->buildHierarchy($doc->id);

                $stats['success']++;
                $stats['total_summaries'] += $result['total_summaries'] ?? 0;

                $levels = $result['levels'] ?? [];
                if (isset($levels[RaptorSummarizationService::LEVEL_PARAGRAPH])) {
                    $stats['by_level']['paragraph'] += $levels[RaptorSummarizationService::LEVEL_PARAGRAPH];
                }
                if (isset($levels[RaptorSummarizationService::LEVEL_SECTION])) {
                    $stats['by_level']['section'] += $levels[RaptorSummarizationService::LEVEL_SECTION];
                }
                if (isset($levels[RaptorSummarizationService::LEVEL_DOCUMENT])) {
                    $stats['by_level']['document'] += $levels[RaptorSummarizationService::LEVEL_DOCUMENT];
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $raptorService->recordDocumentError($doc->id);
                $this->newLine();
                $this->error("Error processing {$doc->id} ({$doc->title}): ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('RAPTOR Hierarchy Build Complete:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Documents Processed', $stats['success']],
                ['Documents Failed',    $stats['failed']],
                ['Total Summaries',     $stats['total_summaries']],
                ['Paragraph Summaries', $stats['by_level']['paragraph']],
                ['Section Summaries',   $stats['by_level']['section']],
                ['Document Summaries',  $stats['by_level']['document']],
            ]
        );

        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $stats['success']));

        if ($stats['success'] === 0 && $stats['failed'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolveDeadlineSeconds(): int
    {
        try {
            $timeoutMinutes = (int) ($this->option('timeout')
                ?: DB::table('scheduled_jobs')->where('name', 'raptor_build')->value('timeout_minutes')
                ?: self::DEFAULT_TIMEOUT_MINUTES);
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(300, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    private function shouldStopBeforeDocument(float $startedAt, int $deadlineSeconds, int $processedCount): bool
    {
        if ($deadlineSeconds <= 0 || $processedCount <= 0) {
            return false;
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        $avgSecondsPerDocument = $elapsedSeconds / $processedCount;

        return ($elapsedSeconds + $avgSecondsPerDocument) >= $deadlineSeconds;
    }

    // -------------------------------------------------------------------------

    /**
     * Screen unvetted documents for RAPTOR eligibility.
     *
     * Heuristics (instant, no LLM) handle >99% of noise docs.
     * AI vetting (fast model, ~1–2s) handles borderline 1k–4k char docs.
     * Run via: rag:raptor-build --screen --limit=5000
     */
    private function screenDocuments(RaptorSummarizationService $raptorService, int $limit): int
    {
        $this->info('Screening unvetted documents for RAPTOR eligibility...');

        // Derive timeout from --timeout option, else query scheduled_jobs, else default 90min.
        // Stop 5 minutes before the wall-clock SIGALRM to avoid abrupt termination.
        $timeoutMinutes = (int) ($this->option('timeout')
            ?: DB::table('scheduled_jobs')->where('name', 'raptor_screen')->value('timeout_minutes')
            ?: 90);
        $startTime = time();
        $deadlineSec = ($timeoutMinutes * 60) - 300; // 5-min safety margin

        $documents = DB::connection('pgsql_rag')->select('
            SELECT id, title, source_type, content
            FROM rag_documents
            WHERE parent_id IS NULL
              AND raptor_indexed_at IS NULL
              AND raptor_eligible IS NULL
              AND COALESCE(raptor_error_count, 0) < 3
            ORDER BY created_at DESC
            LIMIT ?
        ', [$limit]);

        if (empty($documents)) {
            $this->info('No unvetted documents found.');
            $this->info('[ITEMS_PROCESSED:0]');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Screening %d documents (timeout budget: %dmin)...', count($documents), $timeoutMinutes));

        $eligible = 0;
        $ineligible = 0;
        $processed = 0;

        foreach ($documents as $doc) {
            // Exit early when approaching the wall-clock timeout
            if ((time() - $startTime) >= $deadlineSec) {
                $this->warn(sprintf(
                    'Stopping early after %d docs — approaching %dmin timeout (%d remaining)',
                    $processed,
                    $timeoutMinutes,
                    count($documents) - $processed
                ));
                break;
            }

            if ($raptorService->screenDocument($doc)) {
                $eligible++;
            } else {
                $ineligible++;
            }
            $processed++;
        }

        $this->info("Screening complete: {$eligible} eligible, {$ineligible} ineligible");
        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $processed));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    /**
     * GR-7: Reset previously-ineligible docs that now meet the raised 2000-char
     * threshold, so they are picked up on the next --screen run.
     *
     * Only affects docs with raptor_eligible=0 and LENGTH(content) >= MIN_CHARS_ELIGIBLE.
     * Docs marked ineligible for other reasons (code, JSON, etc.) are left alone.
     */
    private function rescreenThresholdChange(int $limit): int
    {
        $minChars = RaptorSummarizationService::MIN_CHARS_ELIGIBLE;

        $count = DB::connection('pgsql_rag')->selectOne('
            SELECT COUNT(*) AS cnt
            FROM rag_documents
            WHERE raptor_eligible = 0
              AND LENGTH(content) >= ?
              AND raptor_indexed_at IS NULL
        ', [$minChars])->cnt;

        if ($count === 0) {
            $this->info("No previously-ineligible docs meet the new {$minChars}-char threshold.");
            $this->info('[ITEMS_PROCESSED:0]');

            return Command::SUCCESS;
        }

        $this->info("Resetting {$count} docs (capped at {$limit}) to NULL for re-screening...");

        $updated = DB::connection('pgsql_rag')->affectingStatement('
            UPDATE rag_documents
            SET raptor_eligible = NULL
            WHERE id IN (
                SELECT id FROM rag_documents
                WHERE raptor_eligible = 0
                  AND LENGTH(content) >= ?
                  AND raptor_indexed_at IS NULL
                LIMIT ?
            )
        ', [$minChars, $limit]);

        $this->info("Reset {$updated} docs to unscreened. Run --screen to evaluate them.");
        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $updated));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function buildSingleDocument(RaptorSummarizationService $raptorService, int $documentId, bool $rebuild): int
    {
        $doc = DB::connection('pgsql_rag')->selectOne(
            'SELECT id, title, document_type FROM rag_documents WHERE id = ?',
            [$documentId]
        );

        if (! $doc) {
            $this->error("Document #{$documentId} not found");

            return Command::FAILURE;
        }

        $this->info("Building RAPTOR hierarchy for: {$doc->title} (#{$doc->id})");

        try {
            if ($rebuild) {
                $deleted = $raptorService->deleteHierarchy($documentId);
                $this->info("Deleted {$deleted} existing summaries");
            }

            $result = $raptorService->buildHierarchy($documentId);

            $this->table(
                ['Level', 'Count'],
                array_map(fn ($level, $count) => [
                    RaptorSummarizationService::LEVEL_NAMES[$level] ?? "Level {$level}",
                    $count,
                ], array_keys($result['levels'] ?? []), array_values($result['levels'] ?? []))
            );

            $this->info(sprintf(
                'Total: %d summaries in %dms',
                $result['total_summaries'] ?? 0,
                $result['duration_ms'] ?? 0
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $raptorService->recordDocumentError($documentId);
            $this->error('Failed: '.$e->getMessage());
            $this->warn('Error count incremented. Use --rebuild to reset and retry.');

            return Command::FAILURE;
        }
    }

    // -------------------------------------------------------------------------

    private function showStats(): int
    {
        $this->info('RAPTOR Summary Statistics:');

        $summaryStats = DB::connection('pgsql_rag')->select('
            SELECT level_name, COUNT(*) as count, AVG(token_count) as avg_tokens, COUNT(DISTINCT document_id) as documents
            FROM raptor_summaries
            GROUP BY level_name, level
            ORDER BY level
        ');

        if (! empty($summaryStats)) {
            $this->table(
                ['Level', 'Summaries', 'Avg Tokens', 'Documents'],
                array_map(fn ($s) => [
                    $s->level_name, $s->count, round($s->avg_tokens), $s->documents,
                ], $summaryStats)
            );
        } else {
            $this->warn('No RAPTOR summaries found. Run rag:raptor-build to create them.');
        }

        // Queue breakdown
        $queue = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) FILTER (WHERE raptor_indexed_at IS NOT NULL)                           AS indexed,
                COUNT(*) FILTER (
                    WHERE raptor_indexed_at IS NULL
                      AND raptor_eligible = 1
                      AND COALESCE(raptor_error_count, 0) < 3
                ) AS eligible_pending,
                COUNT(*) FILTER (WHERE raptor_indexed_at IS NULL AND raptor_eligible = 0)        AS ineligible,
                COUNT(*) FILTER (WHERE raptor_indexed_at IS NULL AND raptor_eligible IS NULL)     AS unscreened,
                COUNT(*) FILTER (WHERE raptor_error_count >= 3)                                  AS quarantined,
                COUNT(*) FILTER (
                    WHERE raptor_indexed_at IS NOT NULL
                      AND raptor_eligible = 1
                      AND NOT EXISTS (
                          SELECT 1
                          FROM raptor_summaries rs
                          WHERE rs.document_id = rag_documents.id
                      )
                ) AS indexed_without_summaries
            FROM rag_documents
            WHERE parent_id IS NULL
        ');

        $this->newLine();
        $this->table(
            ['State', 'Count'],
            [
                ['Indexed (done)',       $queue->indexed],
                ['Eligible pending',     $queue->eligible_pending],
                ['Ineligible (skipped)', $queue->ineligible],
                ['Unscreened',          $queue->unscreened],
                ['Quarantined (3+ err)', $queue->quarantined],
                ['Indexed w/o summaries', $queue->indexed_without_summaries],
            ]
        );

        return Command::SUCCESS;
    }
}
