<?php

namespace App\Console\Commands;

use App\Services\RagSourceVersionLineageReportService;
use Illuminate\Console\Command;

class RagSourceVersionLineageReportCommand extends Command
{
    protected $signature = 'rag:source-version-lineage-report
        {--json : Emit machine-readable JSON}
        {--compact : Emit compact JSON; requires --json}';

    protected $description = 'Report aggregate evidence for non-exact file RAG source versions without mutation';

    public function handle(RagSourceVersionLineageReportService $service): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }

        try {
            $payload = $service->report();
        } catch (\Throwable $e) {
            $this->error('RAG source-version lineage report failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $flags = $this->option('compact')
                ? JSON_UNESCAPED_SLASHES
                : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
            $this->line((string) json_encode($payload, $flags | JSON_THROW_ON_ERROR));
        } else {
            $classes = $payload['classifications'];
            $this->line(sprintf(
                'RAG source versions: %s non_exact=%d/%d normalized=%d/%d different_hash=%d/%d incomplete_hash=%d/%d',
                $payload['status'],
                $payload['totals']['non_exact_groups'],
                $payload['totals']['non_exact_extra_documents'],
                $classes['normalized_hash_only']['groups'],
                $classes['normalized_hash_only']['extra_documents'],
                $classes['different_hash']['groups'],
                $classes['different_hash']['extra_documents'],
                $classes['incomplete_hash']['groups'],
                $classes['incomplete_hash']['extra_documents'],
            ));
            $this->line('Disposition: read-only evidence; source-specific operator review required.');
        }

        return self::SUCCESS;
    }
}
