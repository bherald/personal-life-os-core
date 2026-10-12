<?php

namespace App\Console\Commands;

use App\Services\ContentExtractionProvenanceService;
use Illuminate\Console\Command;

class ContentExtractionProvenanceAuditCommand extends Command
{
    protected $signature = 'content:extraction-provenance-audit
        {--limit=20 : Maximum distinct extraction contracts to report}
        {--json : Emit machine-readable output}
        {--compact : Emit one-line aggregate output}';

    protected $description = 'Audit content-free extractor, pipeline, confidence, page, and review provenance for file text';

    public function handle(ContentExtractionProvenanceService $provenance): int
    {
        $payload = $provenance->audit((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line(json_encode(
                $this->option('compact') ? $this->compactPayload($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        if (! $this->option('compact')) {
            $this->info('Content extraction provenance audit');
        }
        $this->line($this->compactLine($payload));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? ContentExtractionProvenanceService::AUDIT_SCHEMA,
            'mode' => 'observe',
            'read_only' => true,
            'status' => $payload['status'] ?? 'unavailable',
            'evidence_error' => $payload['evidence_error'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'contract_count' => is_array($payload['contracts'] ?? null) ? count($payload['contracts']) : 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function compactLine(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        return sprintf(
            'Content extraction provenance: %s documents=%d captured=%d current=%d legacy_or_missing=%d incomplete=%d review_required=%d page_complete=%d coverage=%s%% contracts=%d error=%s',
            $payload['status'] ?? 'unavailable',
            (int) ($summary['documents'] ?? 0),
            (int) ($summary['captured'] ?? 0),
            (int) ($summary['current_schema'] ?? 0),
            (int) ($summary['legacy_or_missing'] ?? 0),
            (int) ($summary['incomplete'] ?? 0),
            (int) ($summary['review_required'] ?? 0),
            (int) ($summary['page_complete'] ?? 0),
            $summary['coverage_percent'] ?? 'n/a',
            is_array($payload['contracts'] ?? null) ? count($payload['contracts']) : 0,
            $payload['evidence_error'] ?? 'none'
        );
    }
}
