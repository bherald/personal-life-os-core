<?php

namespace App\Console\Commands;

use App\Services\RagBacklogService;
use Illuminate\Console\Command;

class RagBacklogReportCommand extends Command
{
    protected $signature = 'rag:backlog-report
        {--json : Emit machine-readable metrics}
        {--compact : Emit routine-check compact output}';

    protected $description = 'Report downstream RAG/KG backlog, throughput, and ETA';

    public function handle(RagBacklogService $backlog): int
    {
        $metrics = $backlog->getDigestMetrics();

        if ($this->option('json')) {
            $metrics['net_burn'] = $backlog->getNetBurn(7);
            $this->line(json_encode(
                $this->option('compact') ? $this->compactPayload($metrics) : $metrics,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $metrics['net_burn'] = $backlog->getNetBurn(7);
            $this->renderCompact($this->compactPayload($metrics));

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

    private function compactPayload(array $metrics): array
    {
        $netBurn = is_array($metrics['net_burn'] ?? null) ? $metrics['net_burn'] : [];
        $netBurnLanes = is_array($netBurn['lanes'] ?? null) ? $netBurn['lanes'] : [];
        $kgNetBurn = is_array($netBurnLanes['kg'] ?? null) ? $netBurnLanes['kg'] : [];
        $raptorNetBurn = is_array($netBurnLanes['raptor'] ?? null) ? $netBurnLanes['raptor'] : [];
        $sentenceNetBurn = is_array($netBurnLanes['sentence'] ?? null) ? $netBurnLanes['sentence'] : [];
        $metricErrors = is_array($metrics['evidence_errors'] ?? null) ? $metrics['evidence_errors'] : [];
        $netBurnErrors = is_array($netBurn['evidence_errors'] ?? null) ? $netBurn['evidence_errors'] : [];
        $evidenceErrorCount = count($metricErrors) + count($netBurnErrors);

        return [
            'version' => 1,
            'mode' => 'observe',
            'compact' => true,
            'status' => $evidenceErrorCount > 0 ? 'observe_warning' : 'observe_ok',
            'documents' => (int) ($metrics['documents'] ?? 0),
            'raptor' => $this->compactLane($metrics['raptor'] ?? []),
            'sentence' => $this->compactLane($metrics['sentence'] ?? []),
            'kg' => [
                ...$this->compactLane($metrics['kg'] ?? []),
                'fresh' => (int) ($metrics['kg']['fresh'] ?? 0),
                'stale' => (int) ($metrics['kg']['stale'] ?? 0),
                'entities' => (int) ($metrics['kg']['entities'] ?? 0),
            ],
            'kg_provenance' => $this->compactKgProvenance($metrics['kg_provenance'] ?? null),
            'net_burn' => [
                'window_days' => (int) ($netBurn['window_days'] ?? 7),
                'kg_net_burn_per_day' => $kgNetBurn['net_burn_per_day'] ?? null,
                'kg_trend' => $kgNetBurn['trend'] ?? null,
                'kg_points' => $kgNetBurn['points'] ?? null,
                'raptor_trend' => $raptorNetBurn['trend'] ?? null,
                'sentence_trend' => $sentenceNetBurn['trend'] ?? null,
            ],
            'evidence_error_count' => $evidenceErrorCount,
        ];
    }

    private function compactKgProvenance(mixed $provenance): ?array
    {
        if (! is_array($provenance)) {
            return null;
        }

        return [
            'snapshot_date' => $provenance['snapshot_date'] ?? null,
            'pending' => (int) ($provenance['pending'] ?? 0),
            'total' => (int) ($provenance['total'] ?? 0),
            'completion_pct' => $provenance['completion_pct'] ?? null,
            'delta_from_prev' => $provenance['delta_from_prev'] ?? null,
        ];
    }

    private function compactLane(mixed $lane): array
    {
        $lane = is_array($lane) ? $lane : [];

        return [
            'pending' => (int) ($lane['pending'] ?? 0),
            'throughput_per_day' => (int) ($lane['throughput_per_day'] ?? 0),
            'eta_days' => $lane['eta_days'] ?? null,
        ];
    }

    private function renderCompact(array $payload): void
    {
        $this->line(sprintf(
            'RAG backlog compact: %s documents=%s kg_pending=%s raptor=%s sentence=%s',
            $payload['status'] ?? 'unknown',
            $payload['documents'] ?? 0,
            $payload['kg']['pending'] ?? 0,
            $payload['raptor']['pending'] ?? 0,
            $payload['sentence']['pending'] ?? 0,
        ));

        $this->line(sprintf(
            'kg: fresh=%s stale=%s entities=%s throughput_per_day=%s eta_days=%s',
            $payload['kg']['fresh'] ?? 0,
            $payload['kg']['stale'] ?? 0,
            $payload['kg']['entities'] ?? 0,
            $payload['kg']['throughput_per_day'] ?? 0,
            $payload['kg']['eta_days'] ?? 'n/a',
        ));

        if (is_array($payload['kg_provenance'] ?? null)) {
            $provenance = $payload['kg_provenance'];
            $this->line(sprintf(
                'kg_provenance: date=%s pending=%s total=%s completion_pct=%s delta=%s',
                $provenance['snapshot_date'] ?? 'n/a',
                $provenance['pending'] ?? 0,
                $provenance['total'] ?? 0,
                $provenance['completion_pct'] ?? 'n/a',
                $provenance['delta_from_prev'] ?? 'n/a',
            ));
        }

        $netBurn = is_array($payload['net_burn'] ?? null) ? $payload['net_burn'] : [];
        $this->line(sprintf(
            'net_burn: window=%sd kg_per_day=%s kg_trend=%s raptor_trend=%s sentence_trend=%s errors=%s',
            $netBurn['window_days'] ?? 7,
            $netBurn['kg_net_burn_per_day'] ?? 'n/a',
            $netBurn['kg_trend'] ?? 'unknown',
            $netBurn['raptor_trend'] ?? 'unknown',
            $netBurn['sentence_trend'] ?? 'unknown',
            $payload['evidence_error_count'] ?? 0,
        ));
    }

    private function formatEta(null|float|int $days): string
    {
        if ($days === null) {
            return 'n/a';
        }

        return number_format((float) $days, 1);
    }
}
