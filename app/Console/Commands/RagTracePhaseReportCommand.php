<?php

namespace App\Console\Commands;

use App\Services\RAGTracingService;
use Illuminate\Console\Command;

class RagTracePhaseReportCommand extends Command
{
    protected $signature = 'rag:trace-phase-report
        {--hours=24 : Lookback window in hours}
        {--limit=5000 : Maximum receipts to aggregate}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only JSON; requires --json}
        {--fail-on-errors : Return failure when trace storage is unavailable or current receipts failed}';

    protected $description = 'Report content-free embedding, retrieval, hybrid, and reranking phase latency';

    public function handle(RAGTracingService $tracing): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }

        $payload = $tracing->phaseReport((int) $this->option('hours'), (int) $this->option('limit'));

        if ($this->option('json')) {
            $flags = $this->option('compact') ? JSON_UNESCAPED_SLASHES : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
            $json = json_encode($payload, $flags);
            if ($json === false) {
                $this->error('Failed to encode RAG trace phase report JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'RAG trace phases: %s receipts=%d valid=%d legacy=%d failures=%d avg_ms=%.3f p95_ms=%.3f',
                strtoupper((string) ($payload['status'] ?? 'unknown')),
                (int) ($summary['receipts'] ?? 0),
                (int) ($summary['valid_receipts'] ?? 0),
                (int) ($summary['legacy_incomplete_receipts'] ?? 0),
                (int) ($summary['failed_receipts'] ?? 0),
                (float) ($summary['avg_total_ms'] ?? 0),
                (float) ($summary['p95_total_ms'] ?? 0),
            ));
        }

        $failedReceipts = (int) ($payload['summary']['failed_receipts'] ?? 0);

        return $this->option('fail-on-errors')
            && (($payload['status'] ?? null) === 'unavailable' || $failedReceipts > 0)
                ? self::FAILURE
                : self::SUCCESS;
    }
}
