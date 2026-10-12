<?php

namespace App\Console\Commands;

use App\Services\Ops\RepoContextMapGoldenEvalService;
use Illuminate\Console\Command;

class OpsRepoContextMapGoldenEvalCommand extends Command
{
    protected $signature = 'ops:repo-context-map-golden-eval
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing case detail}
        {--fail-on-regression : Return failure when a repository retrieval contract fails}';

    protected $description = 'Run deterministic repository file-localization and hard-distractor fixtures';

    public function handle(RepoContextMapGoldenEvalService $service): int
    {
        $payload = $service->evaluate();
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode repository context-map golden eval JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $this->line(sprintf(
                'Repo context-map golden eval: %s  cases=%d  passed=%d  failed=%d  mrr=%.4f  recall@k=%.4f  hard-neg-ahead=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) data_get($payload, 'summary.cases', 0),
                (int) data_get($payload, 'summary.passed', 0),
                (int) data_get($payload, 'summary.failed', 0),
                (float) data_get($payload, 'metrics.mrr', 0),
                (float) data_get($payload, 'metrics.mean_recall_at_k', 0),
                (int) data_get($payload, 'metrics.hard_negative_ahead_count', 0),
            ));
        }

        return $this->option('fail-on-regression') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
