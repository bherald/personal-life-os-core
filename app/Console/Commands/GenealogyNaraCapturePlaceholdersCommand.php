<?php

namespace App\Console\Commands;

use App\Services\Genealogy\NaraCatalogMediaCaptureService;
use Illuminate\Console\Command;

class GenealogyNaraCapturePlaceholdersCommand extends Command
{
    protected $signature = 'genealogy:nara-capture-placeholders
        {--tree=4 : Tree ID to operate on}
        {--limit=25 : Maximum URL-only NARA media placeholders to process}
        {--media-id=* : Specific genealogy_media id(s) to process}
        {--dry-run : Plan capture without downloading, writing files, or updating DB rows}
        {--execute-capture : Download/store files and update genealogy_media rows}
        {--confirm-download : Required with --execute-capture before remote downloads}
        {--confirm-storage-write : Required with --execute-capture before FT filesystem and DB writes}
        {--metadata-snapshot : Write vetted NARA API metadata HTML when no digital object is downloadable}
        {--no-metadata-snapshot : Do not write metadata snapshots when no digital object is downloadable}
        {--max-bytes= : Maximum bytes per downloaded NARA object}
        {--json : Emit machine-readable JSON}
        {--compact : Omit detailed item metadata}';

    protected $description = 'Capture NARA Catalog URL-only genealogy_media placeholders into FT filesystem files';

    public function handle(NaraCatalogMediaCaptureService $service): int
    {
        if ($this->option('dry-run') && $this->option('execute-capture')) {
            $this->error('Choose either --dry-run or --execute-capture, not both.');

            return self::FAILURE;
        }

        $payload = $service->collect(
            treeId: (int) $this->option('tree'),
            limit: (int) $this->option('limit'),
            mediaIds: array_map('intval', (array) $this->option('media-id')),
            executeCapture: (bool) $this->option('execute-capture'),
            downloadConfirmed: (bool) $this->option('confirm-download'),
            storageConfirmed: (bool) $this->option('confirm-storage-write'),
            metadataSnapshot: (bool) $this->option('metadata-snapshot') && ! (bool) $this->option('no-metadata-snapshot'),
            compact: (bool) $this->option('compact'),
            maxBytes: $this->option('max-bytes') !== null ? (int) $this->option('max-bytes') : null
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode NARA capture payload.');

                return self::FAILURE;
            }

            $this->line($json);

            return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf(
            'NARA placeholder capture for tree %d [%s]',
            (int) ($payload['tree_id'] ?? 0),
            ($payload['execute_capture'] ?? false) ? 'execute' : 'dry-run'
        ));
        $this->line('Target root: '.($payload['target_root'] ?? 'unknown'));
        $this->newLine();

        $summary = $payload['summary'] ?? [];
        $this->table(['Metric', 'Count'], [
            ['Placeholders seen', $summary['placeholders_seen'] ?? 0],
            ['Planned', $summary['planned'] ?? 0],
            ['Downloaded', $summary['downloaded'] ?? 0],
            ['Metadata snapshots', $summary['metadata_snapshots'] ?? 0],
            ['Media rows updated', $summary['media_rows_updated'] ?? 0],
            ['File registry rows', $summary['file_registry_rows'] ?? 0],
            ['No downloadable object', $summary['no_downloadable_object'] ?? 0],
            ['Blocked', $summary['blocked'] ?? 0],
            ['Failed', $summary['failed'] ?? 0],
        ]);

        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->line(sprintf(
                '[%s] media=%s naid=%s action=%s',
                (string) ($item['status'] ?? 'unknown'),
                (string) ($item['media_id'] ?? 'unknown'),
                (string) ($item['na_id'] ?? 'unknown'),
                (string) ($item['action'] ?? 'none')
            ));

            if (! empty($item['target_path'])) {
                $this->line('  target: '.$item['target_path']);
            }
            if (! empty($item['blockers']) && is_array($item['blockers'])) {
                $this->line('  blockers: '.implode(', ', $item['blockers']));
            }
        }

        if (($payload['status'] ?? null) === 'blocked') {
            foreach ($payload['blockers'] ?? [] as $blocker) {
                $this->error((string) $blocker);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
