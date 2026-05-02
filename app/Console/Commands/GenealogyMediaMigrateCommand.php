<?php

namespace App\Console\Commands;

use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migrate genealogy media from the legacy media root to the configured
 * genealogy media root.
 *
 * This command:
 * 1. Validates which files actually exist in Nextcloud
 * 2. Copies existing files to the genealogy folder structure
 * 3. Updates database paths to new location
 * 4. Marks missing files as file_exists=0
 */
class GenealogyMediaMigrateCommand extends Command
{
    protected $signature = 'genealogy:media-migrate
        {--dry-run : Show what would be done without making changes}
        {--validate-only : Only validate file existence, don\'t copy}
        {--tree= : Limit to specific tree ID}
        {--limit=100 : Maximum files to process}
        {--skip-copy : Only update file_exists flags, don\'t copy files}';

    protected $description = 'Migrate genealogy media from legacy media root to configured genealogy folder structure';

    protected NextcloudFileApiService $nextcloudApi;

    protected int $validated = 0;

    protected int $exists = 0;

    protected int $missing = 0;

    protected int $copied = 0;

    protected int $updated = 0;

    protected int $failed = 0;

    public function handle(NextcloudFileApiService $nextcloudApi): int
    {
        $this->nextcloudApi = $nextcloudApi;

        $dryRun = $this->option('dry-run');
        $validateOnly = $this->option('validate-only');
        $skipCopy = $this->option('skip-copy');
        $treeId = $this->option('tree');
        $limit = (int) $this->option('limit');

        $this->info('Genealogy Media Migration');
        $this->info('========================');
        $this->info('Mode: '.($dryRun ? 'DRY RUN' : ($validateOnly ? 'VALIDATE ONLY' : ($skipCopy ? 'UPDATE FLAGS ONLY' : 'FULL MIGRATION'))));

        // Get tree info for destination path
        $trees = DB::select('SELECT id, name FROM genealogy_trees');
        $treeMap = [];
        foreach ($trees as $tree) {
            // Convert tree name to folder name (replace spaces with underscores)
            $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tree->name);
            $treeMap[$tree->id] = [
                'name' => $tree->name,
                'dest_folder' => $this->genealogyRoot()."/{$folderName}/photos",
            ];
        }

        // Get media records with legacy media paths
        $legacyMediaRoot = $this->legacyMediaRoot();
        $query = 'SELECT id, tree_id, nextcloud_path, file_exists
                  FROM genealogy_media
                  WHERE nextcloud_path LIKE ?';
        $params = [$legacyMediaRoot.'/%'];

        if ($treeId) {
            $query .= ' AND tree_id = ?';
            $params[] = $treeId;
        }

        $query .= ' ORDER BY id LIMIT ?';
        $params[] = $limit;

        $media = DB::select($query, $params);
        $total = count($media);

        $this->info("Found {$total} media files to process");
        if ($total === 0) {
            $this->warn("No legacy media rows found under {$legacyMediaRoot}.");
            $this->line('Set GENEALOGY_LEGACY_MEDIA_ROOT if this install uses a different historical media folder.');

            return self::SUCCESS;
        }

        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($media as $m) {
            $this->validated++;

            // Check if file exists in Nextcloud
            $fileExists = $this->nextcloudApi->fileExists($m->nextcloud_path);

            if ($fileExists) {
                $this->exists++;

                if (! $validateOnly && ! $skipCopy) {
                    // Determine destination path
                    $treeInfo = $treeMap[$m->tree_id] ?? null;
                    if (! $treeInfo) {
                        $this->warn("No tree info for tree_id {$m->tree_id}");
                        $this->failed++;
                        $bar->advance();

                        continue;
                    }

                    $filename = basename($m->nextcloud_path);
                    $destPath = $treeInfo['dest_folder'].'/'.$filename;

                    // Check if dest already exists
                    if ($this->nextcloudApi->fileExists($destPath)) {
                        // File already at destination, just update path
                        if (! $dryRun) {
                            DB::update(
                                'UPDATE genealogy_media SET nextcloud_path = ?, file_exists = 1 WHERE id = ?',
                                [$destPath, $m->id]
                            );
                        }
                        $this->updated++;
                    } else {
                        // Copy file (ensureDirectoryExists is called internally by copyFile)
                        if (! $dryRun) {
                            $copyResult = $this->nextcloudApi->copyFile($m->nextcloud_path, $destPath);

                            if ($copyResult['success']) {
                                DB::update(
                                    'UPDATE genealogy_media SET nextcloud_path = ?, file_exists = 1 WHERE id = ?',
                                    [$destPath, $m->id]
                                );
                                $this->copied++;
                            } else {
                                Log::warning('Failed to copy media file', [
                                    'source' => $m->nextcloud_path,
                                    'dest' => $destPath,
                                    'error' => $copyResult['error'] ?? 'unknown',
                                ]);
                                $this->failed++;
                            }
                        } else {
                            $this->copied++;
                        }
                    }
                }
            } else {
                $this->missing++;

                // Mark as not existing
                if (! $dryRun && ! $validateOnly && $m->file_exists) {
                    DB::update(
                        'UPDATE genealogy_media SET file_exists = 0 WHERE id = ?',
                        [$m->id]
                    );
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Results:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Validated', $this->validated],
                ['Files Exist', $this->exists],
                ['Files Missing', $this->missing],
                ['Copied', $this->copied],
                ['Path Updated (exists)', $this->updated],
                ['Failed', $this->failed],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN - No changes were made');
        }

        return self::SUCCESS;
    }

    private function genealogyRoot(): string
    {
        return '/'.trim((string) config('genealogy.nextcloud_root', '/Library/Genealogy'), '/');
    }

    private function legacyMediaRoot(): string
    {
        return '/'.trim((string) config('genealogy.legacy_media_root', '/Library/Media'), '/');
    }
}
