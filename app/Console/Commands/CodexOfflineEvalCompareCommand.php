<?php

namespace App\Console\Commands;

use App\Services\OfflineCodingEvalReceiptService;
use Illuminate\Console\Command;
use Throwable;

class CodexOfflineEvalCompareCommand extends Command
{
    protected $signature = 'codex:offline-eval-compare
        {baseline : Baseline content-free eval receipt}
        {candidate : Candidate content-free eval receipt}
        {--allow-configuration-change : Compare different product configurations without treating the result as a regression gate; harness identity must still match}
        {--json : Emit machine-readable JSON}
        {--compact : Emit content-free aggregate JSON}
        {--fail-on-regression : Fail unless same-harness and same-configuration receipts are comparable, evidence-complete, and regression-free}';

    protected $description = 'Compare harness-bound offline coding eval receipts without reading prompt or response content';

    public function handle(OfflineCodingEvalReceiptService $receipts): int
    {
        try {
            $baseline = $receipts->read((string) $this->argument('baseline'));
            $candidate = $receipts->read((string) $this->argument('candidate'));
            $comparison = $receipts->compare(
                $baseline,
                $candidate,
                (bool) $this->option('allow-configuration-change')
            );
            $payload = $this->option('compact') ? $receipts->compact($comparison) : $comparison;
        } catch (Throwable $e) {
            $payload = [
                'schema' => OfflineCodingEvalReceiptService::COMPARISON_SCHEMA,
                'status' => 'blocked',
                'error' => $e->getMessage(),
                'posture' => [
                    'read_only' => true,
                    'content_included' => false,
                    'llm_calls' => false,
                ],
            ];

            return $this->finish($payload, self::FAILURE);
        }

        $strictFailure = (bool) $this->option('fail-on-regression')
            && (($comparison['gate']['eligible'] ?? false) !== true
                || ($comparison['gate']['pass'] ?? false) !== true);

        return $this->finish($payload, $strictFailure ? self::FAILURE : self::SUCCESS);
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit): int
    {
        if ($this->option('json') || $this->option('compact')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(sprintf(
            'offline coding eval comparison: %s cases=%d regressions=%d gate=%s',
            (string) ($payload['status'] ?? 'unknown'),
            (int) ($payload['comparison']['case_count'] ?? 0),
            (int) ($payload['summary']['regressed_cases'] ?? 0),
            ($payload['gate']['pass'] ?? false) === true ? 'pass' : 'not-pass'
        ));
        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exit;
    }
}
