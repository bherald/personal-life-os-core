<?php

namespace App\Console\Commands;

use App\Services\Ops\LlmProviderModelSyncReviewService;
use Illuminate\Console\Command;

class LlmProviderModelSyncReviewCommand extends Command
{
    protected $signature = 'llm:sync-providers
        {--json : Emit machine-readable JSON}
        {--compact : Omit full model lists and keep counts/samples}
        {--include-inactive : Include disabled provider rows}
        {--no-live : Skip network model-list probes and review DB metadata only}
        {--strict : Exit non-zero when review-needed issues are present}
        {--connect-timeout=5 : Live probe connect timeout in seconds}
        {--timeout=15 : Live probe total timeout in seconds}';

    protected $description = 'Read-only LLM provider model inventory diff and role/capability review';

    public function handle(LlmProviderModelSyncReviewService $review): int
    {
        $connectTimeout = $this->positiveIntOption('connect-timeout');
        $timeout = $this->positiveIntOption('timeout');
        if ($connectTimeout === null || $timeout === null) {
            return 2;
        }

        $payload = $review->collect(
            includeInactive: (bool) $this->option('include-inactive'),
            probeLive: ! (bool) $this->option('no-live'),
            connectTimeout: $connectTimeout,
            timeout: $timeout,
            compact: (bool) $this->option('compact')
        );

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCodeForPayload($payload);
        }

        $this->renderText($payload);

        return $this->exitCodeForPayload($payload);
    }

    private function renderText(array $payload): void
    {
        $summary = (array) ($payload['summary'] ?? []);
        $this->line(sprintf(
            'LLM provider model sync review: %s checked=%d active=%d live_ok=%d new_models=%d deprecated=%d role_gaps=%d capability_gaps=%d pending_reviews=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($summary['instances_checked'] ?? 0),
            (int) ($summary['active_instances'] ?? 0),
            (int) ($summary['live_probe_ok'] ?? 0),
            (int) ($summary['new_model_count'] ?? 0),
            (int) ($summary['deprecated_model_count'] ?? 0),
            (int) ($summary['role_model_mismatch_count'] ?? 0) + (int) ($summary['role_model_live_mismatch_count'] ?? 0),
            (int) ($summary['capability_role_mismatch_count'] ?? 0),
            (int) ($summary['pending_review_items'] ?? 0),
        ));

        foreach ((array) ($payload['issues'] ?? []) as $issue) {
            $context = (array) ($issue['context'] ?? []);
            $this->line(sprintf(
                '[%s] %s %s',
                (string) ($issue['severity'] ?? 'info'),
                (string) ($issue['code'] ?? 'issue'),
                (string) ($context['instance_id'] ?? '')
            ));
        }

        $latest = (array) data_get($payload, 'pending_review.latest', []);
        if ($latest !== []) {
            $this->line(sprintf(
                'Pending ai_model_update review: %s (%s)',
                (string) ($latest['title'] ?? 'unknown'),
                (string) ($latest['updated_at'] ?? 'unknown')
            ));
        }
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($value === false) {
            $this->error("Invalid --{$name}. Use a positive integer.");

            return null;
        }

        return $value;
    }

    private function exitCodeForPayload(array $payload): int
    {
        if (($payload['status'] ?? 'pass') === 'fail') {
            return self::FAILURE;
        }

        if ($this->option('strict') && ($payload['status'] ?? 'pass') !== 'pass') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
