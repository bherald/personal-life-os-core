<?php

namespace App\Console\Commands;

use App\Services\Ops\SchedulerOptimizeReportService;
use Illuminate\Console\Command;

class SchedulerOptimizeReportCommand extends Command
{
    protected $signature = 'scheduler:optimize-report
                            {--window=24h : time window, e.g. 60m, 24h, 7d}
                            {--json : Emit machine-readable JSON}
                            {--compact : Emit compact counts and top recommendation classes}';

    protected $description = 'Read-only scheduler optimization recommendations for spacing, timeout, and failure hotspots';

    public function handle(SchedulerOptimizeReportService $reports): int
    {
        $window = $reports->parseWindow((string) $this->option('window'));
        if ($window === null) {
            $this->error('Invalid --window. Use Nm (minutes), Nh (hours), or Nd (days).');

            return 2;
        }

        $payload = $reports->buildPayload($window);
        if ($this->option('compact')) {
            $payload = $reports->compactPayload($payload);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->option('compact')
            ? $this->renderCompactText($payload)
            : $this->renderText($payload);

        return self::SUCCESS;
    }

    private function renderText(array $payload): void
    {
        $this->line(sprintf(
            'scheduler-optimize-report  mode=%s  window=%s  jobs=%d  recommendations=%d',
            $payload['mode'],
            $payload['window'],
            $payload['job_count'],
            $payload['recommendation_count']
        ));

        foreach ($payload['recommendations'] as $recommendation) {
            $this->line(sprintf(
                '[%s] %s: %s',
                strtoupper((string) $recommendation['severity']),
                $recommendation['category'],
                $recommendation['action']
            ));

            if (isset($recommendation['job_name'])) {
                $this->line('  job: '.$recommendation['job_name']);
            }

            $this->line('  reason: '.$recommendation['reason']);
        }
    }

    private function renderCompactText(array $payload): void
    {
        $this->line(sprintf(
            'scheduler-optimize-report  mode=%s  window=%s  jobs=%d  recommendations=%d  compact=yes',
            $payload['mode'],
            $payload['window'],
            $payload['job_count'],
            $payload['recommendation_count']
        ));

        $this->line('severity_counts: '.$this->renderCounts($payload['severity_counts'] ?? []));
        $this->line('category_counts: '.$this->renderCounts($payload['category_counts'] ?? []));

        $topTypes = $payload['top_recommendation_types'] ?? [];
        $this->line('top_recommendation_types: '.($topTypes === [] ? 'none' : implode(', ', $topTypes)));
    }

    private function renderCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (string $key, int $count): string => $key.'='.$count,
            array_keys($counts),
            array_values($counts)
        ));
    }
}
