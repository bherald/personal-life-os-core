<?php

namespace App\Console\Commands;

use App\Services\HyperGraphService;
use App\Services\KnowledgeGraphService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KnowledgeGraphBuildCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 289;

    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;

    private const FALLBACK_SECONDS_PER_DOCUMENT = 60.0;

    private const GLOBAL_LOCK_KEY = 'knowledge_graph_build:global_lock';

    protected $signature = 'rag:build-knowledge-graph
        {--limit=100 : Maximum documents to process}
        {--force : Re-process already extracted documents}
        {--sleep=2000 : Milliseconds to sleep between documents (rate limiting)}
        {--min-chars=50 : Skip documents shorter than this}
        {--max-chars=8000 : Truncate documents longer than this}
        {--min-confidence=0.5 : Minimum relationship confidence threshold}
        {--type= : Only process documents of this type}
        {--dry-run : Show what would be processed without extracting}
        {--stats : Show extraction statistics and exit}
        {--backlog=all : Which backlog slice to process: all, fresh, or stale}
        {--order=newest : Processing order: newest, oldest, or stale-first}
        {--budget-minutes= : Override the runtime budget in minutes for a manual catch-up run}
        {--prefer-external : Skip Ollama, use external APIs first}
        {--instance=primary : Pin Ollama route: primary local, secondary local, or any}
        {--model-role=fast : Model role to resolve: fast, standard, quality}
        {--with-hyperedges : GR-11: Also extract N-ary hyperedges for each document}';

    protected $description = 'Batch extract knowledge graph entities from RAG documents';

    /** @var int Minimum chars for meaningful entity extraction */
    private const MIN_CHARS_DEFAULT = 50;

    /** @var int Maximum chars to send to LLM (save tokens) */
    private const MAX_CHARS_DEFAULT = 8000;

    public function handle(KnowledgeGraphService $kgService, HyperGraphService $hyperService): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $backlog = strtolower((string) ($this->option('backlog') ?? 'all'));
        if (! in_array($backlog, ['all', 'fresh', 'stale'], true)) {
            $this->error("Invalid --backlog value '{$backlog}'. Expected one of: all, fresh, stale.");

            return Command::FAILURE;
        }

        $order = strtolower((string) ($this->option('order') ?? 'newest'));
        if (! in_array($order, ['newest', 'oldest', 'stale-first'], true)) {
            $this->error("Invalid --order value '{$order}'. Expected one of: newest, oldest, stale-first.");

            return Command::FAILURE;
        }

        $limit = $this->resolveEffectiveLimit((int) $this->option('limit'));
        $force = $this->option('force');
        $sleepMs = (int) $this->option('sleep');
        $minChars = (int) $this->option('min-chars') ?: self::MIN_CHARS_DEFAULT;
        $maxChars = (int) $this->option('max-chars') ?: self::MAX_CHARS_DEFAULT;
        $minConfidence = (float) $this->option('min-confidence');
        $docType = $this->option('type');
        $dryRun = $this->option('dry-run');
        $preferExternal = $this->option('prefer-external');
        $withHyperedges = $this->option('with-hyperedges');
        $instance = strtolower((string) ($this->option('instance') ?: 'primary'));
        if (! in_array($instance, ['primary', 'secondary', 'any'], true)) {
            $this->error("Invalid --instance value '{$instance}'. Expected one of: primary, secondary, any.");

            return Command::FAILURE;
        }
        $modelRole = strtolower((string) ($this->option('model-role') ?: 'fast'));
        if (! in_array($modelRole, ['fast', 'standard', 'quality'], true)) {
            $this->error("Invalid --model-role value '{$modelRole}'. Expected one of: fast, standard, quality.");

            return Command::FAILURE;
        }
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        $this->info('Building knowledge graph from RAG documents...');
        $this->info("  Rate limit: {$sleepMs}ms between documents");
        $this->info("  Content filter: {$minChars}-{$maxChars} chars");
        $this->info("  Backlog slice: {$backlog}");
        $this->info("  Order: {$order}");
        if ($dryRun) {
            $this->warn('  DRY RUN - no extraction will occur');
        }

        // Build query with filters
        $conditions = [];
        $params = [];

        if (! $force || $backlog !== 'all') {
            $conditions[] = match ($backlog) {
                'fresh' => 'kg_extracted_at IS NULL',
                'stale' => '(kg_extracted_at IS NOT NULL AND content_hash IS NOT NULL AND content_hash IS DISTINCT FROM kg_content_hash)',
                default => '(kg_extracted_at IS NULL OR (content_hash IS NOT NULL AND content_hash IS DISTINCT FROM kg_content_hash))',
            };
        }

        if ($docType) {
            $conditions[] = 'document_type = ?';
            $params[] = $docType;
        }

        // Skip tiny documents at query level
        $conditions[] = 'LENGTH(content) >= ?';
        $params[] = $minChars;

        $whereClause = ! empty($conditions) ? 'WHERE '.implode(' AND ', $conditions) : '';
        $orderClause = $this->resolveOrderClause($order);
        $params[] = $limit;

        $documents = DB::connection('pgsql_rag')->select("
            SELECT id, title, content, document_type, content_hash, kg_content_hash, kg_extracted_at, LENGTH(content) as content_length
            FROM rag_documents
            {$whereClause}
            {$orderClause}
            LIMIT ?
        ", $params);

        if (empty($documents)) {
            $this->info('No documents pending knowledge graph extraction.');

            return Command::SUCCESS;
        }

        // Show pending count for context
        $pendingParams = $params;
        array_pop($pendingParams); // remove limit
        $pendingCount = DB::connection('pgsql_rag')->selectOne("
            SELECT COUNT(*) as cnt FROM rag_documents {$whereClause}
        ", $pendingParams)->cnt;

        $this->info(sprintf(
            'Processing %d of %d pending documents (%.1f%%)...',
            count($documents),
            $pendingCount,
            count($documents) / max($pendingCount, 1) * 100
        ));

        if ($dryRun) {
            return $this->showDryRun($documents, $maxChars);
        }

        $lockTtlSeconds = max(300, $deadlineSeconds + self::TIMEOUT_SAFETY_BUFFER_SECONDS);
        $lock = Cache::lock(self::GLOBAL_LOCK_KEY, $lockTtlSeconds);
        if (! $lock->get()) {
            $this->warn('Knowledge graph build refused: another KG build/catch-up run already holds the global lock.');
            $this->info('[ITEMS_PROCESSED:0]');

            return Command::SUCCESS;
        }

        try {
            $bar = $this->output->createProgressBar(count($documents));
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% | E:%entities% R:%rels% F:%failed%');
            $bar->setMessage('0', 'entities');
            $bar->setMessage('0', 'rels');
            $bar->setMessage('0', 'failed');
            $bar->start();

            $stats = [
                'success' => 0,
                'failed' => 0,
                'skipped_short' => 0,
                'truncated' => 0,
                'fresh_processed' => 0,
                'stale_processed' => 0,
                'entities' => 0,
                'relationships' => 0,
                'total_input_chars' => 0,
                'hyperedges_stored' => 0,
            ];

            $startTime = microtime(true);

            foreach ($documents as $i => $doc) {
                if ($this->shouldStopBeforeDocument($startedAt, $deadlineSeconds, $i)) {
                    $this->newLine();
                    $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s)");
                    break;
                }

                try {
                    $content = $doc->content;
                    $wasStale = $this->isStaleDocument($doc);

                    // Truncate oversized documents
                    if (strlen($content) > $maxChars) {
                        $content = substr($content, 0, $maxChars)."\n[... truncated]";
                        $stats['truncated']++;
                    }

                    $stats['total_input_chars'] += strlen($content);

                    $result = $kgService->extractEntities($content, [
                        'source_document_id' => $doc->id,
                        'persist' => true,
                        'min_confidence' => $minConfidence,
                        'prefer_external' => $preferExternal,
                        'skip_recursive' => true,
                        'scheduled_batch' => true,
                        'preferred_instance' => $instance,
                        'model_role_override' => $modelRole,
                    ]);

                    if ($result['success']) {
                        // GR-5: stamp both timestamp and content hash for diff detection
                        DB::connection('pgsql_rag')->update(
                            'UPDATE rag_documents SET kg_extracted_at = NOW(), kg_content_hash = content_hash WHERE id = ?',
                            [$doc->id]
                        );

                        $stats['success']++;
                        if ($wasStale) {
                            $stats['stale_processed']++;
                        } else {
                            $stats['fresh_processed']++;
                        }
                        $stats['entities'] += count($result['entities'] ?? []);
                        $stats['relationships'] += count($result['relationships'] ?? []);

                        // GR-11: HyperGraph — extract N-ary relations alongside binary triples
                        if ($withHyperedges) {
                            try {
                                $hyper = $hyperService->buildFromDocument($content, $doc->id);
                                $stats['hyperedges_stored'] += $hyper['stored'];
                            } catch (\Exception $e) {
                                // Non-fatal — binary KG already stored
                            }
                        }
                    } else {
                        // Do NOT stamp kg_extracted_at on failure — allow retry next run
                        $stats['failed']++;
                    }
                } catch (Exception $e) {
                    // Do NOT stamp kg_extracted_at on exception — allow retry next run
                    $stats['failed']++;
                    $this->newLine();
                    $this->error("Error processing {$doc->id}: ".substr($e->getMessage(), 0, 200));
                }

                $bar->setMessage((string) $stats['entities'], 'entities');
                $bar->setMessage((string) $stats['relationships'], 'rels');
                $bar->setMessage((string) $stats['failed'], 'failed');
                $bar->advance();

                // Rate limiting sleep (skip on last doc)
                if ($sleepMs > 0 && $i < count($documents) - 1) {
                    usleep($sleepMs * 1000);
                }
            }

            $bar->finish();
            $this->newLine(2);

            $elapsed = microtime(true) - $startTime;
            $docsPerMin = $stats['success'] > 0 ? ($stats['success'] / $elapsed) * 60 : 0;

            $this->info('Knowledge Graph Build Complete:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Documents Processed', $stats['success']],
                    ['  Fresh Processed', $stats['fresh_processed']],
                    ['  Stale Re-extracted', $stats['stale_processed']],
                    ['Documents Failed', $stats['failed']],
                    ['Documents Truncated', $stats['truncated']],
                    ['Entities Extracted', $stats['entities']],
                    ['Relationships Extracted', $stats['relationships']],
                    ['Hyperedges Stored', $withHyperedges ? $stats['hyperedges_stored'] : 'N/A (--with-hyperedges not set)'],
                    ['Total Input Chars', number_format($stats['total_input_chars'])],
                    ['Elapsed Time', sprintf('%.1f min', $elapsed / 60)],
                    ['Processing Rate', sprintf('%.1f docs/min', $docsPerMin)],
                    ['Remaining Pending', number_format($pendingCount - count($documents))],
                ]
            );

            if ($pendingCount > count($documents)) {
                $remainingMinutes = ($pendingCount - count($documents)) / max($docsPerMin, 0.1);
                $this->info(sprintf(
                    'Estimated time for remaining %s docs: %.0f hours (%.1f docs/min)',
                    number_format($pendingCount - count($documents)),
                    $remainingMinutes / 60,
                    $docsPerMin
                ));
            }

            $this->info(sprintf('[ITEMS_PROCESSED:%d]', $stats['success']));

            return Command::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }

    private function resolveOrderClause(string $order): string
    {
        return match ($order) {
            'oldest' => 'ORDER BY created_at ASC, id ASC',
            'stale-first' => 'ORDER BY
                CASE
                    WHEN kg_extracted_at IS NOT NULL
                     AND content_hash IS NOT NULL
                     AND content_hash IS DISTINCT FROM kg_content_hash THEN 0
                    ELSE 1
                END ASC,
                COALESCE(kg_extracted_at, created_at) ASC,
                created_at ASC,
                id ASC',
            default => 'ORDER BY created_at DESC, id DESC',
        };
    }

    private function isStaleDocument(object $doc): bool
    {
        return $doc->kg_extracted_at !== null
            && $doc->content_hash !== null
            && $doc->content_hash !== $doc->kg_content_hash;
    }

    private function resolveEffectiveLimit(int $requestedLimit): int
    {
        if ($requestedLimit <= 0) {
            return $requestedLimit;
        }

        $cap = $this->getDynamicLimitCap();
        if ($requestedLimit > $cap) {
            $this->info("  Limit capped from {$requestedLimit} to {$cap} based on runtime budget");

            return $cap;
        }

        return $requestedLimit;
    }

    private function getDynamicLimitCap(): int
    {
        $avgSecondsPerDocument = self::FALLBACK_SECONDS_PER_DOCUMENT;

        try {
            $recent = DB::selectOne("
                SELECT AVG(duration_seconds / items_processed) AS avg_seconds,
                       COUNT(*) AS sample_count
                FROM scheduled_job_runs sjr
                JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                WHERE sj.name = 'knowledge_graph_build'
                  AND sjr.status = 'success'
                  AND sjr.items_processed > 0
                  AND sjr.duration_seconds > 0
                  AND sjr.completed_at > NOW() - INTERVAL 14 DAY
                LIMIT 10
            ");

            if ($recent && $recent->sample_count >= 2 && $recent->avg_seconds > 0) {
                $avgSecondsPerDocument = max((float) $recent->avg_seconds, 10.0);
            }
        } catch (\Throwable) {
            // Use fallback if history lookup fails.
        }

        return max(10, (int) floor($this->resolveDeadlineSeconds() / $avgSecondsPerDocument));
    }

    private function resolveDeadlineSeconds(): int
    {
        $manualBudgetMinutes = (int) ($this->option('budget-minutes') ?: 0);
        if ($manualBudgetMinutes > 0) {
            return max(120, ($manualBudgetMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
        }

        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'knowledge_graph_build' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(120, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
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

    private function showStats(): int
    {
        $stats = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) as total_docs,
                COUNT(CASE WHEN kg_extracted_at IS NOT NULL THEN 1 END) as processed,
                COUNT(CASE WHEN kg_extracted_at IS NULL THEN 1 END) as pending,
                COUNT(CASE WHEN LENGTH(content) >= 50 AND kg_extracted_at IS NULL THEN 1 END) as pending_fresh,
                COUNT(CASE WHEN kg_extracted_at IS NULL AND LENGTH(content) < 50 THEN 1 END) as pending_too_short,
                COUNT(CASE WHEN kg_extracted_at IS NOT NULL AND content_hash IS NOT NULL AND content_hash IS DISTINCT FROM kg_content_hash THEN 1 END) as stale_content,
                COUNT(CASE WHEN LENGTH(content) >= 50 AND (kg_extracted_at IS NULL OR (content_hash IS NOT NULL AND content_hash IS DISTINCT FROM kg_content_hash)) THEN 1 END) as actionable_backlog,
                AVG(LENGTH(content)) as avg_len,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY LENGTH(content)) as median_len,
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY LENGTH(content)) as p95_len
            FROM rag_documents
        ');

        $kgStats = DB::connection('pgsql_rag')->selectOne('
            SELECT
                (SELECT COUNT(*) FROM knowledge_graph_entities) as entities,
                (SELECT COUNT(*) FROM knowledge_graph) as triples,
                (SELECT COALESCE(AVG(confidence), 0) FROM knowledge_graph) as avg_confidence
        ');

        $this->info('Knowledge Graph Build Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total RAG Documents', number_format($stats->total_docs)],
                ['Already Processed', number_format($stats->processed)],
                ['Actionable Backlog', number_format($stats->actionable_backlog)],
                ['Pending (fresh, eligible)', number_format($stats->pending_fresh)],
                ['Stale Content (re-extract pending)', number_format($stats->stale_content)],
                ['Pending (too short <50 chars)', number_format($stats->pending_too_short)],
                ['Avg Document Length', number_format($stats->avg_len).' chars'],
                ['Median Document Length', number_format($stats->median_len).' chars'],
                ['P95 Document Length', number_format($stats->p95_len).' chars'],
                ['---', '---'],
                ['KG Entities', number_format($kgStats->entities)],
                ['KG Triples', number_format($kgStats->triples)],
                ['Avg Triple Confidence', sprintf('%.2f', $kgStats->avg_confidence)],
            ]
        );

        return Command::SUCCESS;
    }

    private function showDryRun(array $documents, int $maxChars): int
    {
        $truncateCount = 0;
        $totalChars = 0;

        foreach ($documents as $doc) {
            $len = $doc->content_length;
            $totalChars += min($len, $maxChars);
            if ($len > $maxChars) {
                $truncateCount++;
            }
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Documents to process', count($documents)],
                ['Would be truncated', $truncateCount],
                ['Total input chars', number_format($totalChars)],
                ['Estimated tokens (input)', number_format($totalChars / 4)],
            ]
        );

        // Show size distribution
        $buckets = ['<100' => 0, '100-500' => 0, '500-2K' => 0, '2K-8K' => 0, '>8K' => 0];
        foreach ($documents as $doc) {
            $len = $doc->content_length;
            if ($len < 100) {
                $buckets['<100']++;
            } elseif ($len < 500) {
                $buckets['100-500']++;
            } elseif ($len < 2000) {
                $buckets['500-2K']++;
            } elseif ($len < 8000) {
                $buckets['2K-8K']++;
            } else {
                $buckets['>8K']++;
            }
        }

        $this->info('Size distribution:');
        $rows = [];
        foreach ($buckets as $range => $count) {
            $rows[] = [$range, $count, sprintf('%.1f%%', $count / max(count($documents), 1) * 100)];
        }
        $this->table(['Size Range', 'Count', 'Pct'], $rows);

        return Command::SUCCESS;
    }
}
