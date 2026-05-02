<?php

namespace App\Console\Commands;

use App\Services\Ops\LlmCircuitStateReportService;
use Illuminate\Console\Command;

class LlmCircuitStateCommand extends Command
{
    protected $signature = 'ops:llm-circuit-state
        {--open-minutes=15 : Warn when an active provider circuit has been open this many minutes}
        {--strict : Exit non-zero when provider circuit warnings are present}
        {--details : Include full provider list in JSON output}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only LLM provider circuit, health, and routability state report';

    public function handle(LlmCircuitStateReportService $report): int
    {
        $openMinutes = filter_var($this->option('open-minutes'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($openMinutes === false) {
            $this->error('Invalid --open-minutes. Use a positive integer.');

            return 2;
        }

        $payload = $report->collect(
            openMinutes: $openMinutes,
            strict: (bool) $this->option('strict'),
            details: (bool) $this->option('details')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode LLM circuit state JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return $this->exitCodeForPayload($payload);
        }

        $this->renderText($payload);

        return $this->exitCodeForPayload($payload);
    }

    private function renderText(array $payload): void
    {
        $summary = (array) ($payload['summary'] ?? []);
        $this->line(sprintf(
            'LLM circuit state: %s active=%d healthy=%d open=%d half_open=%d retry_due=%d issues=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($summary['active'] ?? 0),
            (int) ($summary['healthy_active'] ?? 0),
            (int) ($summary['open'] ?? 0),
            (int) ($summary['half_open'] ?? 0),
            (int) ($summary['retry_due_still_open'] ?? 0),
            count((array) ($payload['issues'] ?? []))
        ));

        $instances = (array) ($payload['instances'] ?? $payload['sample_instances'] ?? []);
        $rows = [];
        foreach ($instances as $instance) {
            $rows[] = [
                (string) ($instance['instance_id'] ?? ''),
                (string) ($instance['provider_class'] ?? ''),
                ! empty($instance['active']) ? 'yes' : 'no',
                ! empty($instance['healthy']) ? 'yes' : 'no',
                (string) ($instance['routability'] ?? ''),
                (string) ($instance['compat_status'] ?? ''),
                (string) ($instance['circuit_state'] ?? ''),
                ($instance['open_minutes'] ?? null) === null ? '-' : (string) ((int) $instance['open_minutes']),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Instance', 'Class', 'Active', 'Healthy', 'Routability', 'Compat', 'Circuit', 'Open Min'],
                $rows
            );
        }

        foreach ((array) ($payload['issues'] ?? []) as $issue) {
            $this->warn(($issue['code'] ?? 'issue').': '.($issue['message'] ?? ''));
        }
    }

    private function exitCodeForPayload(array $payload): int
    {
        return ($payload['status'] ?? 'pass') === 'fail'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
