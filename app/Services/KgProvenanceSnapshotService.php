<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class KgProvenanceSnapshotService
{
    public const PIPELINE = 'kg_provenance';

    private const ISSUE_KEYS = [
        'triples_missing_source_document_id',
        'triples_orphan_source_document',
        'active_triples_missing_entity_links',
        'active_triples_stale_source_hash',
        'hyperedges_orphan_source_document',
    ];

    /**
     * @return array<string, mixed>
     */
    public function capture(bool $dryRun = false): array
    {
        $audit = $this->runAudit();
        $counts = $audit['counts'];
        $snapshotDate = now()->toDateString();
        $pending = $this->pendingIssueCount($counts);
        $total = max(0, $this->count($counts, 'total_triples') + $this->count($counts, 'total_hyperedges'));
        $previous = $this->previousPending($snapshotDate);

        $row = [
            'snapshot_date' => $snapshotDate,
            'pipeline' => self::PIPELINE,
            'pending' => $pending,
            'total' => $total,
            'completion_pct' => $this->completionPct($pending, $total),
            'delta_from_prev' => $previous === null ? null : $pending - $previous,
            'kg_triples_total' => $this->count($counts, 'total_triples'),
            'kg_triples_active' => $this->count($counts, 'active_triples'),
            'kg_triples_missing_source_document' => $this->count($counts, 'triples_missing_source_document_id'),
            'kg_triples_orphan_source_document' => $this->count($counts, 'triples_orphan_source_document'),
            'kg_active_missing_either_entity' => $this->count($counts, 'active_triples_missing_entity_links'),
            'kg_triples_stale_source_hash' => $this->count($counts, 'active_triples_stale_source_hash'),
            'kg_extracted_documents_without_triples' => $this->count($counts, 'extracted_documents_without_triples'),
            'kg_pending_fresh_documents' => $this->count($counts, 'pending_fresh_documents'),
            'kg_stale_documents' => $this->count($counts, 'stale_documents'),
            'kg_hyperedges_total' => $this->count($counts, 'total_hyperedges'),
            'kg_hyperedges_orphan_source_document' => $this->count($counts, 'hyperedges_orphan_source_document'),
        ];

        if (! $dryRun) {
            DB::table('pipeline_metrics_snapshots')->updateOrInsert(
                [
                    'snapshot_date' => $snapshotDate,
                    'pipeline' => self::PIPELINE,
                ],
                array_merge($row, [
                    'created_at' => now(),
                ])
            );
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'persisted' => ! $dryRun,
            'audit_status' => $audit['status'] ?? null,
            'snapshot' => $row,
            'counts' => $counts,
        ];
    }

    /**
     * @return array{status?: string, counts: array<string, int>}
     */
    private function runAudit(): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:audit-provenance', [
            '--json' => true,
            '--samples' => 0,
        ], $output);

        if ($exitCode !== 0) {
            throw new RuntimeException("graph:audit-provenance failed with exit code {$exitCode}");
        }

        $payload = json_decode($output->fetch(), true);
        if (! is_array($payload) || ! isset($payload['counts']) || ! is_array($payload['counts'])) {
            throw new RuntimeException('graph:audit-provenance did not return a valid JSON counts payload');
        }

        return [
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            'counts' => array_map('intval', $payload['counts']),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function pendingIssueCount(array $counts): int
    {
        $pending = 0;
        foreach (self::ISSUE_KEYS as $key) {
            $pending += $this->count($counts, $key);
        }

        return $pending;
    }

    private function previousPending(string $snapshotDate): ?int
    {
        $row = DB::table('pipeline_metrics_snapshots')
            ->select('pending')
            ->where('pipeline', self::PIPELINE)
            ->where('snapshot_date', '<', $snapshotDate)
            ->orderByDesc('snapshot_date')
            ->first();

        return $row === null ? null : (int) $row->pending;
    }

    private function completionPct(int $pending, int $total): float
    {
        if ($total <= 0) {
            return $pending === 0 ? 100.0 : 0.0;
        }

        return round(max(0.0, min(100.0, (($total - $pending) / $total) * 100)), 2);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function count(array $counts, string $key): int
    {
        return max(0, (int) ($counts[$key] ?? 0));
    }
}
