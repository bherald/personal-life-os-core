<?php

namespace App\Console\Commands;

use App\Services\FileRegistryTombstonePurgeService;
use Illuminate\Console\Command;

class FileRegistryHardPurgeTombstonesCommand extends Command
{
    private const CONFIRM_TOKEN = 'FILE-TOMBSTONE-PURGE';

    protected $signature = 'files:hard-purge-tombstones
        {--execute : Apply the purge; default is dry-run preview}
        {--force : Ignore the hard-purge retention window for deleted tombstones}
        {--limit=1000 : Maximum tombstones to process in this batch, capped at 5000}
        {--confirm= : Required token when --execute is used}
        {--count-references : Include pre-purge reference counts during execute; dry-runs always count}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Dry-run or purge deleted file_registry tombstones plus derived MySQL/RAG references';

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
            ? $purge->purge($limit, (bool) $this->option('force'), (bool) $this->option('count-references'))
            : $purge->preview($limit, (bool) $this->option('force'));

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
            'file-tombstones: %s selected=%s eligible_total=%s retention_days=%s force=%s cutoff=%s',
            $payload['mode'] ?? 'unknown',
            $payload['selected'] ?? 0,
            $payload['eligible_total'] ?? 0,
            $payload['retention_days'] ?? '-',
            ($payload['force'] ?? false) ? 'yes' : 'no',
            $payload['cutoff'] ?? '-'
        ));

        $this->line(sprintf(
            'rag: documents=%s triples=%s face_embeddings=%s semantic_embeddings=%s',
            $payload['rag']['references']['rag_documents'] ?? 0,
            $payload['rag']['references']['knowledge_graph_active_triples'] ?? 0,
            $payload['rag']['references']['face_embeddings'] ?? 0,
            $payload['rag']['references']['file_semantic_embeddings'] ?? 0
        ));

        $this->line(sprintf(
            'mysql: faces=%s hashes=%s video_hashes=%s genealogy_media=%s registry_deleted=%s',
            $payload['mysql']['references']['file_registry_faces'] ?? 0,
            $payload['mysql']['references']['file_registry_perceptual_hashes'] ?? 0,
            $payload['mysql']['references']['file_registry_video_hashes'] ?? 0,
            $payload['mysql']['references']['genealogy_media'] ?? 0,
            $payload['mysql']['deleted']['file_registry'] ?? 0
        ));

        foreach (($payload['errors'] ?? []) as $error) {
            $this->error($error);
        }
    }
}
