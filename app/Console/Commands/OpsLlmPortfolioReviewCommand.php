<?php

namespace App\Console\Commands;

use App\Services\Ops\LlmPortfolioReviewService;
use Illuminate\Console\Command;

class OpsLlmPortfolioReviewCommand extends Command
{
    protected $signature = 'ops:llm-portfolio-review
        {--json : Emit machine-readable JSON}
        {--compact : Emit content-free counts only; requires --json}
        {--force : Ignore the fourteen-day due gate}
        {--no-codex : Collect deterministic inventory without external Codex synthesis}
        {--no-live : Skip live provider model catalogs}';

    protected $description = 'Run the due-gated LLM portfolio inventory and read-only Codex recommendation review';

    public function handle(LlmPortfolioReviewService $service): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $payload = $service->run(
            force: (bool) $this->option('force'),
            useCodex: ! (bool) $this->option('no-codex'),
            probeLive: ! (bool) $this->option('no-live'),
        );

        if ($this->option('json')) {
            $this->line(json_encode(
                $this->option('compact') ? $this->compact($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
        } else {
            $this->line(sprintf(
                'LLM portfolio review: %s codex=%s actions=%d review=%s',
                strtoupper((string) ($payload['status'] ?? 'unknown')),
                data_get($payload, 'codex.executed') ? (data_get($payload, 'codex.success') ? 'ok' : 'failed') : 'not-run',
                array_sum((array) ($payload['action_counts'] ?? [])),
                (string) data_get($payload, 'review.status', 'none'),
            ));
        }

        return in_array($payload['status'] ?? '', ['disabled', 'not_due', 'observed', 'no_action', 'review_required'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function compact(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? 'plos.llm_portfolio_review_run.v1',
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'compact' => true,
            'status' => $payload['status'] ?? 'unknown',
            'due' => $payload['due'] ?? null,
            'inventory_status' => $payload['inventory_status'] ?? null,
            'inventory_summary' => $payload['inventory_summary'] ?? [],
            'codex' => $payload['codex'] ?? ['executed' => false],
            'action_counts' => $payload['action_counts'] ?? [],
            'review' => $payload['review'] ?? null,
            'mode' => $payload['mode'] ?? [],
        ];
    }
}
