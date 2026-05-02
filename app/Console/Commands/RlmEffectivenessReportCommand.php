<?php

namespace App\Console\Commands;

use App\Services\Ops\RlmEffectivenessReportService;
use Illuminate\Console\Command;

class RlmEffectivenessReportCommand extends Command
{
    protected $signature = 'ops:rlm-effectiveness
        {--window=24h : Window to inspect, e.g. 24h, 7d, 30d}
        {--service= : Limit report to one recursion_config service_name}
        {--json : Emit machine-readable JSON}
        {--strict : Exit non-zero when the observe report has warnings}';

    protected $description = 'Observe-only RLM effectiveness report by service';

    public function handle(RlmEffectivenessReportService $report): int
    {
        $windowHours = $this->parseWindowHours((string) $this->option('window'));
        if ($windowHours === null) {
            $this->error('Invalid --window. Use Nh or Nd, for example 24h, 7d, or 30d.');

            return 2;
        }

        $payload = $report->collect(
            windowHours: $windowHours,
            serviceName: $this->option('service') !== null ? (string) $this->option('service') : null
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode RLM effectiveness JSON.');

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
            'RLM effectiveness: %s window=%dh services=%d calls=%d move_on_rate=%s',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($payload['window_hours'] ?? 0),
            (int) ($summary['services_seen'] ?? 0),
            (int) ($summary['calls'] ?? 0),
            ($summary['move_on_rate'] ?? null) === null ? '-' : round((float) $summary['move_on_rate'] * 100, 1).'%'
        ));

        $rows = [];
        foreach ((array) ($payload['services'] ?? []) as $service) {
            $calls = (array) ($service['calls'] ?? []);
            $effectiveness = (array) ($service['effectiveness'] ?? []);
            $moveOn = (array) ($service['move_on'] ?? []);
            $config = (array) ($service['config'] ?? []);

            $rows[] = [
                (string) ($service['service_name'] ?? 'unknown'),
                (string) ($service['status'] ?? 'unknown'),
                ! empty($config['enabled']) ? 'yes' : 'no',
                (int) ($calls['total'] ?? 0),
                ($moveOn['rate'] ?? null) === null ? '-' : round((float) $moveOn['rate'] * 100, 1).'%',
                (int) ($effectiveness['runs'] ?? 0),
                ($effectiveness['avg_quality_improvement'] ?? null) === null
                    ? '-'
                    : (string) $effectiveness['avg_quality_improvement'],
                ($moveOn['primary_reason'] ?? null) ?: '-',
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Service', 'Status', 'Enabled', 'Calls', 'Move-On', 'Runs', 'Quality', 'Top Reason'],
                $rows
            );
        }

        foreach ((array) ($payload['warnings'] ?? []) as $warning) {
            $this->warn('warning: '.$warning);
        }

        foreach ((array) ($payload['recommendations'] ?? []) as $recommendation) {
            $this->line('review: '.$recommendation);
        }
    }

    private function parseWindowHours(string $window): ?int
    {
        $window = trim(strtolower($window));
        if (! preg_match('/^(\d+)([hd])$/', $window, $matches)) {
            return null;
        }

        $value = max(1, (int) $matches[1]);

        return $matches[2] === 'd' ? $value * 24 : $value;
    }

    private function exitCodeForPayload(array $payload): int
    {
        if ($this->option('strict') && ($payload['status'] ?? 'healthy') !== 'healthy') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
