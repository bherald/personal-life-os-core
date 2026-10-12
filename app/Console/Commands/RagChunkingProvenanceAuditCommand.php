<?php

namespace App\Console\Commands;

use App\Services\RagChunkingProvenanceService;
use Illuminate\Console\Command;

class RagChunkingProvenanceAuditCommand extends Command
{
    protected $signature = 'rag:chunking-provenance-audit
        {--limit=20 : Maximum distinct semantic chunking contracts to report}
        {--json : Emit machine-readable output}
        {--compact : Emit aggregate output without contract details}';

    protected $description = 'Audit content-free semantic chunk configuration and output-set provenance';

    public function handle(RagChunkingProvenanceService $provenance): int
    {
        $payload = $provenance->audit((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line(json_encode(
                $this->option('compact') ? $this->compactPayload($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->line($this->compactLine($payload));

            return self::SUCCESS;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $this->info('RAG chunking provenance audit');
        $this->line($this->compactLine($payload));

        $this->table(
            ['Implementation', 'Strategy', 'Chunks', 'Contract'],
            array_map(
                static fn (array $contract): array => [
                    $contract['implementation'] ?? 'unknown',
                    $contract['strategy'] ?? 'unknown',
                    $contract['chunks'] ?? 0,
                    substr((string) ($contract['contract_fingerprint'] ?? ''), 0, 12),
                ],
                is_array($payload['contracts'] ?? null) ? $payload['contracts'] : []
            )
        );

        $this->line(sprintf(
            'Legacy/missing provenance is observation debt only; this command does not read chunk text, rewrite metadata, rechunk, re-embed, or change retrieval for %d row(s).',
            (int) ($summary['legacy_or_missing'] ?? 0)
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? 'plos_rag_chunking_provenance_audit.v1',
            'mode' => 'observe',
            'read_only' => true,
            'status' => $payload['status'] ?? 'unavailable',
            'evidence_error' => $payload['evidence_error'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'contract_count' => is_array($payload['contracts'] ?? null) ? count($payload['contracts']) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function compactLine(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        return sprintf(
            'RAG chunking provenance: %s semantic_chunks=%d captured=%d current=%d legacy_or_missing=%d old_schema=%d incomplete=%d coverage=%s%% contracts=%d error=%s',
            $payload['status'] ?? 'unavailable',
            (int) ($summary['semantic_chunks'] ?? 0),
            (int) ($summary['captured'] ?? 0),
            (int) ($summary['current_schema'] ?? 0),
            (int) ($summary['legacy_or_missing'] ?? 0),
            (int) ($summary['old_schema'] ?? 0),
            (int) ($summary['incomplete'] ?? 0),
            $summary['coverage_percent'] ?? 'n/a',
            is_array($payload['contracts'] ?? null) ? count($payload['contracts']) : 0,
            $payload['evidence_error'] ?? 'none'
        );
    }
}
