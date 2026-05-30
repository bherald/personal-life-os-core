<?php

namespace App\Console\Commands;

use App\Services\FileRegistryTombstonePurgeService;
use Illuminate\Console\Command;

class FileRegistryPurgeOrphanRagDocumentsCommand extends Command
{
    private const CONFIRM_TOKEN = 'FILE-RAG-ORPHAN-PURGE';

    protected $signature = 'files:purge-orphan-rag-documents
        {--execute : Apply the purge; default is dry-run preview}
        {--limit=1000 : Maximum orphan RAG documents to process in this batch, capped at 5000}
        {--confirm= : Required token when --execute is used}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Dry-run or purge file RAG documents that no longer resolve to an active file_registry row';

    public function handle(FileRegistryTombstonePurgeService $purge): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 5000) {
            $this->error('--limit must be between 1 and 5000.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && $this->option('confirm') !== self::CONFIRM_TOKEN) {
            $this->error('Execution requires --confirm='.self::CONFIRM_TOKEN);

            return self::FAILURE;
        }

        $payload = $execute
            ? $purge->purgeOrphanRagFileDocuments($limit)
            : $purge->previewOrphanRagFileDocuments($limit);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($payload);
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function renderText(array $payload): void
    {
        $this->line(sprintf(
            'file-rag-orphans: %s selected=%s orphan_total=%s rag_docs_deleted=%s kg_triples_expired=%s',
            $payload['mode'] ?? 'unknown',
            $payload['selected'] ?? 0,
            $payload['orphan_total'] ?? 0,
            $payload['rag']['deleted']['rag_documents'] ?? 0,
            $payload['rag']['updated']['knowledge_graph_active_triples'] ?? 0
        ));

        foreach (($payload['errors'] ?? []) as $error) {
            $this->error($error);
        }
    }
}
