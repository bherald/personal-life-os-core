<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyEvidenceSprintReadinessService;
use Illuminate\Console\Command;

class GenealogyEvidenceSprintReportCommand extends Command
{
    protected $signature = 'genealogy:evidence-sprint-report
                            {--days=30 : Lookback window in days}
                            {--limit=500 : Maximum review-packet rows to inspect}
                            {--json : Output machine-readable JSON}
                            {--markdown : Output markdown report}';

    protected $description = 'Read-only readiness report for the five-packet genealogy evidence sprint';

    public function handle(GenealogyEvidenceSprintReadinessService $service): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be between 1 and 365');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 25 || $limit > 2000) {
            $this->error('--limit must be between 25 and 2000');

            return self::FAILURE;
        }

        $payload = $service->collect($days, $limit);

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy evidence sprint readiness JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($service->toMarkdown($payload));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Genealogy evidence sprint readiness: %s, last %d day(s)',
            $payload['status'],
            $payload['window_days'],
        ));

        $this->table(
            ['Metric', 'Count'],
            collect($payload['summary'])
                ->reject(fn (mixed $value, string $key): bool => str_starts_with($key, '_'))
                ->map(fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all()
        );

        if (($payload['recommendations'] ?? []) !== []) {
            $this->line('Recommendations:');
            foreach ($payload['recommendations'] as $recommendation) {
                $this->line('- '.$recommendation);
            }
        }

        if (($payload['evidence_errors'] ?? []) !== []) {
            $this->warn('Evidence errors:');
            foreach ($payload['evidence_errors'] as $error) {
                $this->warn('- '.$error);
            }
        }

        return self::SUCCESS;
    }
}
