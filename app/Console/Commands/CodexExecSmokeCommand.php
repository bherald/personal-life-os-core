<?php

namespace App\Console\Commands;

use App\Services\CodexExecConnectorService;
use Illuminate\Console\Command;

class CodexExecSmokeCommand extends Command
{
    protected $signature = 'codex:exec-smoke
        {--json : Emit machine-readable JSON}
        {--execute : Actually invoke codex exec. Default is dry-run only}
        {--role=coding : Model role to resolve from llm_instances.config}
        {--model= : Explicit Codex model override}
        {--effort= : Explicit Codex reasoning effort override}
        {--cwd= : Working directory override, must be under configured cwd_roots}
        {--timeout=120 : Timeout in seconds}
        {--prompt=Return exactly: PLOS Codex connector smoke ok : Smoke prompt}';

    protected $description = 'Dry-run or execute the table-backed Codex Exec connector smoke test';

    public function handle(CodexExecConnectorService $connector): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3600],
        ]);

        if ($timeout === false) {
            $this->error('Invalid --timeout. Use an integer from 1 to 3600.');

            return self::FAILURE;
        }

        $prompt = (string) $this->option('prompt');
        $result = $connector->execute($prompt, [
            'dry_run' => ! (bool) $this->option('execute'),
            'model_role' => (string) $this->option('role'),
            'model_override' => $this->option('model') ?: null,
            'codex_reasoning_effort' => $this->option('effort') ?: null,
            'cwd' => $this->option('cwd') ?: null,
            'timeout' => $timeout,
            'prompt_preview' => $prompt,
        ]);

        $payload = $this->payload($result, $prompt);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $status = ($result['success'] ?? false) ? 'PASS' : 'FAIL';
        $this->line(sprintf(
            'Codex Exec connector smoke: %s mode=%s model=%s effort=%s sandbox=%s',
            $status,
            ! empty($result['dry_run']) ? 'dry-run' : 'execute',
            (string) ($result['model'] ?? 'unknown'),
            (string) ($result['reasoning_effort'] ?? 'unknown'),
            (string) ($result['sandbox'] ?? 'unknown'),
        ));

        if (! ($result['success'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'unknown error'));
        }

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $result, string $prompt): array
    {
        $payload = [
            'status' => ($result['success'] ?? false) ? 'pass' : 'fail',
            'provider' => $result['provider'] ?? 'codex_exec',
            'mode' => [
                'dry_run' => (bool) ($result['dry_run'] ?? false),
                'execute' => ! (bool) ($result['dry_run'] ?? false),
                'read_only' => true,
                'writes_review_queue' => false,
            ],
            'prompt_hash' => hash('sha256', $prompt),
            'model' => $result['model'] ?? null,
            'reasoning_effort' => $result['reasoning_effort'] ?? null,
            'sandbox' => $result['sandbox'] ?? null,
            'approval_policy' => $result['approval_policy'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'exit_code' => $result['exit_code'] ?? null,
            'error' => $result['error'] ?? null,
        ];

        if (isset($result['response'])) {
            $response = (string) $result['response'];
            $payload['response_hash'] = hash('sha256', $response);
            $payload['response_sample'] = mb_substr($response, 0, 200);
        }

        if (isset($result['command'])) {
            $payload['command'] = $result['command'];
        }

        return $payload;
    }
}
