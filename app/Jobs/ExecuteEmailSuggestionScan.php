<?php

namespace App\Jobs;

use App\Services\EmailSuggestionService;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteEmailSuggestionScan implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'email.suggestion_scan_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 1800,
        'tries' => 1,
        'queue' => 'long-running',
    ];

    public int $timeout;

    public int $tries;

    private string $runtimePolicySource = 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY;

    public function __construct(
        private string $folder,
        private int $limit
    ) {
        $policy = $this->runtimePolicy();
        $this->timeout = $this->positiveInt($policy['timeout_seconds'] ?? null, self::DEFAULT_RUNTIME_POLICY['timeout_seconds']);
        $this->tries = $this->positiveInt($policy['tries'] ?? null, self::DEFAULT_RUNTIME_POLICY['tries']);
        $this->onQueue($this->stringValue($policy['queue'] ?? null, self::DEFAULT_RUNTIME_POLICY['queue']));
    }

    public function handle(EmailSuggestionService $suggestionService): void
    {
        Log::info('Queued email suggestion scan started', [
            'folder' => $this->folder,
            'limit' => $this->limit,
            'timeout_seconds' => $this->timeout,
            'tries' => $this->tries,
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);

        $result = $suggestionService->scanAndProcess($this->folder, $this->limit);

        Log::info('Queued email suggestion scan completed', [
            'folder' => $this->folder,
            'limit' => $this->limit,
            'result' => $result,
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued email suggestion scan failed', [
            'folder' => $this->folder,
            'limit' => $this->limit,
            'error' => $exception?->getMessage(),
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim($value);
    }
}
