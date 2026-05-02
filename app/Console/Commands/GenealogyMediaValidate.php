<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validates genealogy media file existence on disk.
 *
 * Checks all genealogy_media records with file_exists=1 against the local
 * configured filesystem path. Missing files get file_exists=0 and their
 * person_media links are removed. If a file is later recovered, the normal
 * pipeline (face-sync, media-consolidate) will re-discover and re-link it.
 *
 * Framework never deletes physical files — this only cleans up DB links.
 */
class GenealogyMediaValidate extends Command
{
    protected $signature = 'genealogy:media-validate
                            {--tree-id=4 : Tree ID to validate}
                            {--batch=500 : Records per batch}
                            {--purge : Delete orphaned genealogy_media rows (file_exists=0, no FK links)}
                            {--dry-run : Show what would be cleaned up without changing DB}';

    protected $description = 'Validate genealogy media files exist on disk, clean up missing links';

    public function handle(GenealogyMediaService $mediaService): int
    {
        $treeId = (int) $this->option('tree-id');
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        $mode = $dryRun ? 'DRY RUN' : 'LIVE';
        $this->info("Validating genealogy media for tree {$treeId} [{$mode}]...");

        $stats = $mediaService->validateFileExistence($treeId, $batchSize, $dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Files checked', $stats['checked']],
                ['Missing from disk', $stats['missing']],
                ['Person-media links removed', $stats['links_removed']],
                ['Primary photos cleared', $stats['primary_photos_cleared']],
                ['Pre-existing orphaned links cleaned', $stats['already_missing']],
            ]
        );

        if (! empty($stats['errors'])) {
            $this->warn('Errors:');
            foreach ($stats['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        if ($stats['missing'] > 0) {
            $this->info($dryRun
                ? "Would mark {$stats['missing']} files as missing."
                : "Marked {$stats['missing']} files as missing, cleaned up links."
            );
        } else {
            $this->info('All files validated — no missing files found.');
        }

        // N139: Purge orphaned rows — delete genealogy_media records with
        // file_exists=0 that have no FK references (truly gone, not just moved)
        if ($this->option('purge')) {
            $this->info('Purging orphaned media records (file_exists=0, no links)...');

            $orphans = DB::select(
                'SELECT gm.id, gm.local_filename FROM genealogy_media gm
                 WHERE gm.tree_id = ? AND gm.file_exists = 0
                   AND NOT EXISTS (SELECT 1 FROM genealogy_person_media pm WHERE pm.media_id = gm.id)
                   AND NOT EXISTS (SELECT 1 FROM genealogy_family_media fm WHERE fm.media_id = gm.id)
                   AND NOT EXISTS (SELECT 1 FROM genealogy_citations c WHERE c.media_id = gm.id)
                   AND NOT EXISTS (SELECT 1 FROM genealogy_media_crops mc WHERE mc.media_id = gm.id)
                   AND NOT EXISTS (SELECT 1 FROM genealogy_persons p WHERE p.primary_photo_id = gm.id)',
                [$treeId]
            );

            if (empty($orphans)) {
                $this->info('No orphaned records to purge.');
            } elseif ($dryRun) {
                $this->info('Would purge '.count($orphans).' orphaned media records.');
            } else {
                $ids = array_column($orphans, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // Also clean up media_files links
                DB::delete("DELETE FROM genealogy_media_files WHERE media_id IN ({$placeholders})", $ids);
                $deleted = DB::delete("DELETE FROM genealogy_media WHERE id IN ({$placeholders})", $ids);

                $this->info("Purged {$deleted} orphaned media records.");
                Log::info('genealogy:media-validate purge completed', [
                    'tree_id' => $treeId,
                    'purged' => $deleted,
                ]);
            }
        }

        Log::info('genealogy:media-validate completed', $stats);

        return self::SUCCESS;
    }
}
