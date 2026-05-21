<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyTreeRootResolver;
use App\Services\Genealogy\Support\GenealogyDocumentExtensions;
use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Phase 0 of the Ingest + File-Scanning Unification Sprint.
 *
 * Read-only. Walks the configured genealogy folder by default and classifies
 * every file against both the CURRENT ingest allowlist and the PROPOSED
 * post-Phase-1.1 union allowlist (document ∪ image). Emits a JSON report
 * + summary table so the operator can see the exact delta before any
 * code change lands.
 *
 * Outputs
 *   - storage/logs/genealogy-inventory/{date}-{slug}.json  (full record)
 *   - stdout summary (per-extension, per-subfolder, per-bucket)
 *
 * Never mutates. Safe to run on prod.
 */
class GenealogyFileInventory extends Command
{
    protected $signature = 'genealogy:file-inventory
                            {--folder= : Folder to scan (default: configured genealogy root)}
                            {--tree= : Optional tree_id for dedup stats (genealogy_media already-ingested count)}
                            {--limit=0 : Optional cap on file count (0 = unlimited)}
                            {--output-dir=storage/logs/genealogy-inventory : Where to write the JSON report}';

    protected $description = 'Phase 0 inventory: classify every file under a folder against current + proposed ingest allowlists (read-only).';

    /**
     * Ingest's current private allowlist (GenealogyDocumentIngestionService.php:22-24).
     * Hardcoded here so the inventory can measure the exact diff vs the proposed union.
     */
    private const CURRENT_ALLOWLIST = [
        'pdf', 'doc', 'docx', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp',
    ];

    /**
     * Pre-Phase-3.3 skip list — kept here as the "historical" baseline so the
     * inventory can show what would have been skipped under the old defaults
     * vs the post-Phase-3.3 config-driven list. Runtime policy uses
     * config('genealogy.ingest.skip_folders') directly.
     */
    private const HISTORICAL_SKIP_FOLDERS = ['photos', 'portraits', 'faces', 'thumbnails', 'profile', 'headshots'];

    public function __construct(
        private NextcloudFileApiService $nc,
        private ?GenealogyTreeRootResolver $treeRootResolver = null,
    ) {
        parent::__construct();
        $this->treeRootResolver ??= app(GenealogyTreeRootResolver::class);
    }

    public function handle(): int
    {
        $treeId = $this->option('tree');
        $folder = $treeId
            ? $this->treeRootResolver->mediaRoot((int) $treeId, $this->option('folder') ? (string) $this->option('folder') : null)
            : '/'.trim((string) ($this->option('folder') ?: config('genealogy.nextcloud_root', '/Library/Genealogy')), '/');
        $limit = (int) $this->option('limit');
        $outputDir = rtrim((string) $this->option('output-dir'), '/');

        $proposed = GenealogyDocumentExtensions::allowed();

        $this->info('Phase 0 inventory — read-only classification snapshot');
        $this->line("  folder:   {$folder}");
        $this->line('  tree_id:  '.($treeId ?? '(none)'));
        $this->line('  proposed: '.count($proposed).' extensions (document ∪ image)');
        $this->line('  current:  '.count(self::CURRENT_ALLOWLIST).' extensions (pre-sprint private const)');
        $this->line('');

        $listing = $this->nc->listFiles($folder, true, 600, 0);
        if (! ($listing['success'] ?? false)) {
            $this->error('Nextcloud listing failed: '.($listing['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $files = $listing['files'] ?? [];
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        $alreadyIngested = $treeId ? $this->loadAlreadyIngestedPaths((int) $treeId) : [];

        $byExtCurrent = [];
        $byExtProposed = [];
        $byExtSkipped = [];
        $bySubfolder = [];
        $bucketCounts = [
            'current_accept' => 0,
            'proposed_accept' => 0,
            'newly_accepted' => 0,  // in proposed but not current
            'regression_lost' => 0,  // in current but not proposed (should stay 0 — union design)
            'skip_photo_folder' => 0,
            'skip_already_ingested' => 0,
            'skip_ext_neither' => 0,
            'directory' => 0,
        ];

        $newlyAccepted = [];  // sample rows for operator verification
        $regressionLost = [];

        foreach ($files as $file) {
            if ($file['is_directory'] ?? false) {
                $bucketCounts['directory']++;

                continue;
            }

            $path = $file['path'] ?? '';
            $name = $file['name'] ?? basename($path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = (int) ($file['size'] ?? 0);

            $inCurrent = in_array($ext, self::CURRENT_ALLOWLIST, true);
            $inProposed = in_array($ext, $proposed, true);

            $parentFolder = strtolower(basename(dirname($path)));
            $isSkipFolder = in_array($parentFolder, self::HISTORICAL_SKIP_FOLDERS, true);
            $isAlreadyIngested = isset($alreadyIngested[$path]);

            // Per-ext rollups (classify every file, ingest rules applied second)
            if ($inCurrent) {
                $byExtCurrent[$ext] = ($byExtCurrent[$ext] ?? 0) + 1;
            }
            if ($inProposed) {
                $byExtProposed[$ext] = ($byExtProposed[$ext] ?? 0) + 1;
            }
            if (! $inCurrent && ! $inProposed) {
                $byExtSkipped[$ext] = ($byExtSkipped[$ext] ?? 0) + 1;
            }

            // Per-subfolder rollup (one level under the root folder)
            $sub = $this->subfolderKey($folder, $path);
            $bySubfolder[$sub] = ($bySubfolder[$sub] ?? 0) + 1;

            // Bucket classification mimicking the ingest pipeline
            if ($isSkipFolder) {
                $bucketCounts['skip_photo_folder']++;

                continue;
            }
            if ($isAlreadyIngested) {
                $bucketCounts['skip_already_ingested']++;

                continue;
            }

            if ($inCurrent) {
                $bucketCounts['current_accept']++;
            }
            if ($inProposed) {
                $bucketCounts['proposed_accept']++;
            }
            if ($inProposed && ! $inCurrent) {
                $bucketCounts['newly_accepted']++;
                if (count($newlyAccepted) < 50) {
                    $newlyAccepted[] = ['path' => $path, 'ext' => $ext, 'size' => $size];
                }
            }
            if ($inCurrent && ! $inProposed) {
                $bucketCounts['regression_lost']++;
                if (count($regressionLost) < 50) {
                    $regressionLost[] = ['path' => $path, 'ext' => $ext, 'size' => $size];
                }
            }
            if (! $inCurrent && ! $inProposed) {
                $bucketCounts['skip_ext_neither']++;
            }
        }

        ksort($byExtCurrent);
        ksort($byExtProposed);
        ksort($byExtSkipped);
        ksort($bySubfolder);

        $record = [
            'generated_at' => now()->toIso8601String(),
            'folder' => $folder,
            'tree_id' => $treeId ? (int) $treeId : null,
            'file_count_total' => count($files),
            'file_count_directory' => $bucketCounts['directory'],
            'already_ingested_rows' => count($alreadyIngested),
            'allowlists' => [
                'current_ingest_const' => self::CURRENT_ALLOWLIST,
                'proposed_union' => $proposed,
                'newly_accepted_exts' => array_values(array_diff($proposed, self::CURRENT_ALLOWLIST)),
                'regression_lost_exts' => array_values(array_diff(self::CURRENT_ALLOWLIST, $proposed)),
            ],
            'bucket_counts' => $bucketCounts,
            'by_extension' => [
                'current_allowlist_hits' => $byExtCurrent,
                'proposed_allowlist_hits' => $byExtProposed,
                'outside_both' => $byExtSkipped,
            ],
            'by_subfolder' => $bySubfolder,
            'newly_accepted_sample' => $newlyAccepted,
            'regression_lost_sample' => $regressionLost,
        ];

        // Persist the JSON snapshot
        $slug = preg_replace('/[^a-z0-9]+/i', '-', trim($folder, '/'));
        $slug = trim(strtolower((string) $slug), '-');
        $outputPath = base_path($outputDir).'/'.now()->format('Y-m-d').'-'.substr($slug, 0, 80).'.json';

        if (! File::exists(dirname($outputPath))) {
            File::makeDirectory(dirname($outputPath), 0755, true);
        }
        File::put($outputPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Summary to stdout
        $this->newLine();
        $this->info('Bucket counts:');
        foreach ($bucketCounts as $k => $v) {
            $this->line(sprintf('  %-24s %s', $k, number_format($v)));
        }

        $this->newLine();
        $this->info('Newly-accepted extensions (proposed ∖ current):');
        $added = array_values(array_diff($proposed, self::CURRENT_ALLOWLIST));
        foreach ($added as $ext) {
            $count = $byExtProposed[$ext] ?? 0;
            $this->line(sprintf('  .%-6s %s', $ext, number_format($count)));
        }

        $this->newLine();
        $this->info('Regression-lost extensions (current ∖ proposed, should be 0):');
        $lost = array_values(array_diff(self::CURRENT_ALLOWLIST, $proposed));
        if (empty($lost)) {
            $this->line('  (none — union design holds)');
        } else {
            foreach ($lost as $ext) {
                $count = $byExtCurrent[$ext] ?? 0;
                $this->line(sprintf('  .%-6s %s', $ext, number_format($count)));
            }
        }

        $this->newLine();
        $this->info("Report: {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * Load nextcloud_path values already tracked in genealogy_media for a tree.
     * Returned as a map so membership check is O(1).
     */
    private function loadAlreadyIngestedPaths(int $treeId): array
    {
        $rows = DB::select(
            'SELECT nextcloud_path FROM genealogy_media WHERE tree_id = ? AND nextcloud_path IS NOT NULL',
            [$treeId]
        );

        $map = [];
        foreach ($rows as $r) {
            if (! empty($r->nextcloud_path)) {
                $map[$r->nextcloud_path] = true;
            }
        }

        return $map;
    }

    /**
     * Return a stable subfolder key, one level below the root scan folder.
     * Files directly under the root get key '(root)'.
     */
    private function subfolderKey(string $rootFolder, string $filePath): string
    {
        $root = '/'.trim($rootFolder, '/').'/';
        $fp = '/'.ltrim($filePath, '/');

        if (! str_starts_with($fp, $root)) {
            return '(outside root)';
        }

        $rel = substr($fp, strlen($root));
        $first = strpos($rel, '/');
        if ($first === false) {
            return '(root)';
        }

        return substr($rel, 0, $first);
    }
}
