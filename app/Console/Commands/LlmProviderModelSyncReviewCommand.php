<?php

namespace App\Console\Commands;

use App\Services\Ops\LlmProviderModelSyncReviewService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;

class LlmProviderModelSyncReviewCommand extends Command
{
    private const POLICY_SOURCE = 'system_configs.ops.llm_portfolio_review_policy';

    protected $signature = 'llm:sync-providers
        {--json : Emit machine-readable JSON}
        {--compact : Omit full model lists and keep counts/samples}
        {--include-inactive : Include disabled provider rows}
        {--no-live : Skip network model-list probes and review DB metadata only}
        {--strict : Exit non-zero when review-needed issues are present}
        {--connect-timeout= : Override the table-owned live probe connect timeout in seconds}
        {--timeout= : Override the table-owned live probe total timeout in seconds}';

    protected $description = 'Read-only LLM provider model inventory diff and role/capability review';

    public function handle(
        LlmProviderModelSyncReviewService $review,
        SystemConfigService $config
    ): int {
        $policy = $config->get('ops.llm_portfolio_review_policy');
        $connectTimeout = $this->positiveIntOption(
            'connect-timeout',
            data_get($policy, 'inventory.connect_timeout_seconds')
        );
        $timeout = $this->positiveIntOption(
            'timeout',
            data_get($policy, 'inventory.timeout_seconds')
        );
        if ($connectTimeout === null || $timeout === null) {
            return 2;
        }
        if ($connectTimeout > $timeout) {
            $this->error('Live probe connect timeout cannot exceed the total timeout.');

            return 2;
        }

        $payload = $review->collect(
            includeInactive: (bool) $this->option('include-inactive'),
            probeLive: ! (bool) $this->option('no-live'),
            connectTimeout: $connectTimeout,
            timeout: $timeout,
            compact: (bool) $this->option('compact')
        );
        $payload['mode'] = array_merge((array) ($payload['mode'] ?? []), [
            'probe_policy_source' => self::POLICY_SOURCE,
            'connect_timeout_seconds' => $connectTimeout,
            'timeout_seconds' => $timeout,
            'connect_timeout_overridden' => $this->hasOptionValue('connect-timeout'),
            'timeout_overridden' => $this->hasOptionValue('timeout'),
        ]);

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

    private function positiveIntOption(string $name, mixed $policyValue): ?int
    {
        $hasOverride = $this->hasOptionValue($name);
        $rawValue = $hasOverride ? $this->option($name) : $policyValue;
        $value = filter_var($rawValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($value === false) {
            $source = $hasOverride
                ? "--{$name}"
                : self::POLICY_SOURCE." {$name}";
            $this->error("Invalid {$source}. Use a positive integer.");

            return null;
        }

        return $value;
    }

    private function hasOptionValue(string $name): bool
    {
        $value = $this->option($name);

        return $value !== null && $value !== '';
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
