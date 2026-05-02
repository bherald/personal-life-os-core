<?php

namespace App\Console\Commands;

use App\Services\RagBacklogService;
use Illuminate\Console\Command;

class RagBacklogReportCommand extends Command
{
    protected $signature = 'rag:backlog-report
        {--json : Emit machine-readable metrics}';

    protected $description = 'Report downstream RAG/KG backlog, throughput, and ETA';

    public function handle(RagBacklogService $backlog): int
    {
        $metrics = $backlog->getDigestMetrics();

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Downstream RAG/KG backlog');
        $this->line('Documents: '.number_format((int) $metrics['documents']));

        $this->table(
            ['Lane', 'Pending', 'Throughput/day', 'ETA days', 'Details'],
            [
                [
                    'RAPTOR',
                    number_format((int) $metrics['raptor']['pending']),
                    number_format((int) $metrics['raptor']['throughput_per_day']),
                    $this->formatEta($metrics['raptor']['eta_days']),
                    'eligible parent documents',
                ],
                [
                    'Sentence',
                    number_format((int) $metrics['sentence']['pending']),
                    number_format((int) $metrics['sentence']['throughput_per_day']),
                    $this->formatEta($metrics['sentence']['eta_days']),
                    'eligible chunk embeddings',
                ],
                [
                    'Knowledge Graph',
                    number_format((int) $metrics['kg']['pending']),
                    number_format((int) $metrics['kg']['throughput_per_day']),
                    $this->formatEta($metrics['kg']['eta_days']),
                    sprintf(
                        'fresh=%s stale=%s entities=%s',
                        number_format((int) $metrics['kg']['fresh']),
                        number_format((int) $metrics['kg']['stale']),
                        number_format((int) $metrics['kg']['entities'])
                    ),
                ],
            ]
        );

        $this->info('[ITEMS_PROCESSED:'.(int) $metrics['kg']['pending'].']');

        return self::SUCCESS;
    }

    private function formatEta(null|float|int $days): string
    {
        if ($days === null) {
            return 'n/a';
        }

        return number_format((float) $days, 1);
    }
}
