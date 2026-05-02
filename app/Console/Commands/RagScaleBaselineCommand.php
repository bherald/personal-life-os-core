<?php

namespace App\Console\Commands;

use App\Services\RagBacklogService;
use Illuminate\Console\Command;

class RagScaleBaselineCommand extends Command
{
    protected $signature = 'rag:scale-baseline
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}';

    protected $description = 'Observe-only RAG scaling baseline for TODO-018 planning';

    public function handle(RagBacklogService $rag): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $rag->getScaleBaseline();

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($rag->scaleBaselineToMarkdown($payload));

            return self::SUCCESS;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $storage = is_array($payload['storage'] ?? null) ? $payload['storage'] : [];
        $this->line(sprintf(
            'RAG scale baseline: %s docs=%s content_chars=%s avg_chars=%s max_chars=%s compressed=%s contextualized=%s relation_mb=%s captured=%s',
            $payload['status'] ?? 'unknown',
            $summary['documents'] ?? 0,
            $summary['content_chars'] ?? 0,
            $summary['avg_content_chars'] ?? 0,
            $summary['max_content_chars'] ?? 0,
            $summary['compressed_documents'] ?? 0,
            $summary['contextualized_documents'] ?? 0,
            $storage['total_relation_mb'] ?? 0,
            $payload['captured_at'] ?? '-'
        ));

        foreach (array_slice(($payload['document_types'] ?? []), 0, 10) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf(
                'type=%s docs=%s avg_chars=%s total_chars=%s',
                $row['document_type'] ?? 'unknown',
                $row['documents'] ?? 0,
                $row['avg_content_chars'] ?? 0,
                $row['content_chars'] ?? 0
            ));
        }

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn('scale: '.$recommendation);
        }

        return self::SUCCESS;
    }
}
