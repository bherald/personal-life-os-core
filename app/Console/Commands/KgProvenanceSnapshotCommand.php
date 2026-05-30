<?php

namespace App\Console\Commands;

use App\Services\KgProvenanceSnapshotService;
use Illuminate\Console\Command;

class KgProvenanceSnapshotCommand extends Command
{
    protected $signature = 'graph:snapshot-provenance
                            {--dry-run : Build the snapshot payload without writing}
                            {--json : Output machine-readable JSON}
                            {--compact : With --json, emit aggregate-only scheduled-output JSON}';

    protected $description = 'Capture daily knowledge-graph provenance audit metrics into pipeline snapshots';

    public function handle(KgProvenanceSnapshotService $service): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option is only supported with --json.');

            return self::FAILURE;
        }

        $payload = $service->capture((bool) $this->option('dry-run'));

        if ($this->option('json')) {
            if ($this->option('compact')) {
                $payload = $this->compactPayload($payload);
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $snapshot = $payload['snapshot'];
        $this->info(sprintf(
            'KG provenance snapshot %s: pending=%d total=%d completion=%s%%',
            $snapshot['snapshot_date'],
            $snapshot['pending'],
            $snapshot['total'],
            number_format((float) $snapshot['completion_pct'], 2)
        ));

        return self::SUCCESS;
    }

    private function compactPayload(array $payload): array
    {
        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

        return [
            'schema' => 'kg_provenance_snapshot.v1',
            'compact' => true,
            'generated_at' => (string) ($payload['generated_at'] ?? now()->toIso8601String()),
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'persisted' => (bool) ($payload['persisted'] ?? false),
            'audit_status' => $this->compactToken($payload['audit_status'] ?? null),
            'pipeline' => $this->compactToken($snapshot['pipeline'] ?? KgProvenanceSnapshotService::PIPELINE),
            'snapshot_date' => $this->compactDate($snapshot['snapshot_date'] ?? null),
            'summary' => [
                'pending' => (int) ($snapshot['pending'] ?? 0),
                'total' => (int) ($snapshot['total'] ?? 0),
                'completion_pct' => (float) ($snapshot['completion_pct'] ?? 0.0),
                'delta_from_prev' => isset($snapshot['delta_from_prev']) ? (int) $snapshot['delta_from_prev'] : null,
            ],
            'triple_counts' => [
                'total' => (int) ($snapshot['kg_triples_total'] ?? $counts['total_triples'] ?? 0),
                'active' => (int) ($snapshot['kg_triples_active'] ?? $counts['active_triples'] ?? 0),
                'missing_source_document' => (int) ($snapshot['kg_triples_missing_source_document'] ?? $counts['triples_missing_source_document_id'] ?? 0),
                'orphan_source_document' => (int) ($snapshot['kg_triples_orphan_source_document'] ?? $counts['triples_orphan_source_document'] ?? 0),
                'active_missing_either_entity' => (int) ($snapshot['kg_active_missing_either_entity'] ?? $counts['active_triples_missing_entity_links'] ?? 0),
                'stale_source_hash' => (int) ($snapshot['kg_triples_stale_source_hash'] ?? $counts['active_triples_stale_source_hash'] ?? 0),
            ],
            'document_counts' => [
                'extracted_without_triples' => (int) ($snapshot['kg_extracted_documents_without_triples'] ?? $counts['extracted_documents_without_triples'] ?? 0),
                'pending_fresh' => (int) ($snapshot['kg_pending_fresh_documents'] ?? $counts['pending_fresh_documents'] ?? 0),
                'stale' => (int) ($snapshot['kg_stale_documents'] ?? $counts['stale_documents'] ?? 0),
            ],
            'hyperedge_counts' => [
                'total' => (int) ($snapshot['kg_hyperedges_total'] ?? $counts['total_hyperedges'] ?? 0),
                'orphan_source_document' => (int) ($snapshot['kg_hyperedges_orphan_source_document'] ?? $counts['hyperedges_orphan_source_document'] ?? 0),
            ],
            'posture' => [
                'aggregate_only' => true,
                'metric_snapshot_write' => (bool) ($payload['persisted'] ?? false),
                'raw_audit_payload_included' => false,
                'sample_rows_included' => false,
                'source_document_ids_included' => false,
                'entity_ids_included' => false,
                'graph_rows_included' => false,
            ],
        ];
    }

    private function compactToken(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'unknown';
        }

        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: '';
        $value = trim($value, '_');

        return $value === '' ? 'unknown' : mb_substr($value, 0, 80);
    }

    private function compactDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
