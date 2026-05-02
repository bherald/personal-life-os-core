<?php

namespace App\Console\Commands;

use App\Services\FactCheckPipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FactCheckRunCommand extends Command
{
    protected $signature = 'factcheck:run
        {--limit=5 : Max results to fact-check per run}
        {--result-id= : Specific research_results ID to fact-check}
        {--stats : Show fact-check pipeline stats}';

    protected $description = 'Run approved research results through the 5-stage fact-check pipeline';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($resultId = $this->option('result-id')) {
            return $this->factCheckSingle((int) $resultId);
        }

        return $this->factCheckBatch((int) $this->option('limit'));
    }

    private function showStats(): int
    {
        $claimCount = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) AS total FROM claims"
        );

        $verdictCount = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) AS total FROM verdicts"
        );

        $verdictsByLabel = DB::connection('pgsql_rag')->select(
            "SELECT verdict, COUNT(*) AS cnt FROM verdicts GROUP BY verdict ORDER BY cnt DESC"
        );

        $labelCounts = [];
        foreach ($verdictsByLabel as $row) {
            $labelCounts[$row->verdict] = (int) $row->cnt;
        }

        $this->table(['Metric', 'Count'], [
            ['Total Claims',  (int) ($claimCount->total  ?? 0)],
            ['Verdicts',      (int) ($verdictCount->total ?? 0)],
            ['Supported',     $labelCounts['supported']    ?? 0],
            ['Refuted',       $labelCounts['refuted']      ?? 0],
            ['Inconclusive',  $labelCounts['inconclusive'] ?? 0],
        ]);

        return Command::SUCCESS;
    }

    private function factCheckSingle(int $resultId): int
    {
        $row = DB::connection('pgsql_rag')->selectOne(
            "SELECT id, ai_output FROM research_results WHERE id = ?",
            [$resultId]
        );

        if (!$row) {
            $this->error("research_results ID {$resultId} not found.");
            return Command::FAILURE;
        }

        if (empty($row->ai_output)) {
            $this->warn("Result {$resultId} has no ai_output — skipping.");
            return Command::FAILURE;
        }

        $this->line("Fact-checking result #{$resultId}...");
        $this->processResult($row);

        $this->line('[ITEMS_PROCESSED:1]');
        return Command::SUCCESS;
    }

    private function factCheckBatch(int $limit): int
    {
        $rows = DB::connection('pgsql_rag')->select(
            "SELECT id, ai_output
             FROM research_results
             WHERE status = 'approved'
               AND fact_checked_at IS NULL
               AND ai_output IS NOT NULL
             ORDER BY created_at ASC
             LIMIT ?",
            [$limit]
        );

        if (empty($rows)) {
            $this->info('No approved research results pending fact-check.');
            $this->line('[ITEMS_PROCESSED:0]');
            return Command::SUCCESS;
        }

        $this->info('Fact-checking ' . count($rows) . ' result(s)...');

        $processed = 0;
        foreach ($rows as $row) {
            $this->processResult($row);
            $processed++;
        }

        $this->line("[ITEMS_PROCESSED:{$processed}]");
        return Command::SUCCESS;
    }

    private function processResult(object $row): void
    {
        try {
            $result = app(FactCheckPipelineService::class)->run($row->ai_output, ['persist' => true]);

            $claimCount = count($result['claims'] ?? []);
            $success    = $result['success'] ?? false;

            $status = $success ? 'ok' : 'partial';
            $this->line("  Result #{$row->id}: {$status}, {$claimCount} claim(s) extracted.");

            Log::info("factcheck:run — result #{$row->id} processed, claims={$claimCount}, success=" . ($success ? 'true' : 'false'));
        } catch (\Throwable $e) {
            $this->warn("  Result #{$row->id}: pipeline error — " . $e->getMessage());
            Log::warning("factcheck:run — result #{$row->id} failed: " . $e->getMessage());
        }

        // Always stamp fact_checked_at so broken content is not retried on the next run.
        DB::connection('pgsql_rag')->update(
            "UPDATE research_results SET fact_checked_at = NOW() WHERE id = ?",
            [$row->id]
        );
    }
}
