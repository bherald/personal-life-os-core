<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyDocumentIngestionService;
use App\Services\Genealogy\GenealogyTreeRootResolver;
use App\Services\Genealogy\Support\GenealogyDocumentExtensions;
use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Phase 4 — retroactive backfill for file types unlocked by Phase 1.1.
 *
 * Walks a folder and ingests every file whose extension is in the
 * post-sprint allowlist (document ∪ image) BUT was not in the
 * pre-sprint private `DOCUMENT_EXTENSIONS`. Operator-gated: dry-run
 * is the default; writes require --execute.
 *
 * Rollback: every executed run writes the list of inserted media_ids
 * to `storage/logs/genealogy-backfill/{tag}-{timestamp}.json`. Revert
 * with:
 *   DELETE FROM genealogy_media WHERE id IN (<ids from the log>);
 *
 * Per-row cap defaults to 500; tunable via --limit.
 */
class GenealogyIngestBackfillNewlyAllowed extends Command
{
    protected $signature = 'genealogy:ingest-backfill-newly-allowed
                            {--tree=4           : Tree ID to assign backfilled media to}
                            {--folder= : Nextcloud folder to scan (default: configured genealogy root)}
                            {--limit=500        : Max new records to ingest per run}
                            {--dry-run          : Report the target list without writing (default)}
                            {--execute          : Actually ingest rows — dry-run is the default}
                            {--tag=n135-backfill-2026-04-18 : Rollback log tag used in the log filename}';

    protected $description = 'Retroactive backfill for file types newly unlocked by Phase 1.1 (html/htm/odt/xls/xlsx/csv/md/epub/...)';

    /**
     * Pre-sprint allowlist (the private const that existed prior to
     * Phase 1.1). Used to compute the "newly-allowed" diff at runtime.
     *
     * @var array<int, string>
     */
    private const PRE_SPRINT_ALLOWED = [
        'pdf', 'doc', 'docx', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp',
    ];

    public function __construct(
        private NextcloudFileApiService $nc,
        private ?GenealogyTreeRootResolver $treeRootResolver = null,
    ) {
        parent::__construct();
        $this->treeRootResolver ??= app(GenealogyTreeRootResolver::class);
    }

    public function handle(GenealogyDocumentIngestionService $ingester): int
    {
        $treeId = (int) $this->option('tree');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = ! $this->option('execute');
        $tag = (string) $this->option('tag');

        if (! DB::selectOne('SELECT id FROM genealogy_trees WHERE id = ?', [$treeId])) {
            $this->error("Tree #{$treeId} not found");

            return self::FAILURE;
        }

        $folder = $this->treeRootResolver->mediaRoot(
            $treeId,
            $this->option('folder') ? (string) $this->option('folder') : null
        );

        $postSprint = GenealogyDocumentExtensions::allowed();
        $newlyAllowed = array_values(array_diff($postSprint, self::PRE_SPRINT_ALLOWED));
        if ($newlyAllowed === []) {
            $this->warn('No newly-allowed extensions — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info('Retroactive backfill — newly-allowed extensions:');
        $this->line('  '.implode(', ', array_map(fn ($e) => ".{$e}", $newlyAllowed)));
        $this->line("  folder={$folder}  tree={$treeId}  limit={$limit}  mode=".($dryRun ? 'DRY-RUN' : 'EXECUTE'));
        $this->newLine();

        $listing = $this->nc->listFiles($folder, true, 600, 0);
        if (! ($listing['success'] ?? false)) {
            $this->error('Nextcloud listing failed: '.($listing['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $targets = [];
        foreach (($listing['files'] ?? []) as $file) {
            if ($file['is_directory'] ?? false) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, $newlyAllowed, true)) {
                continue;
            }
            $targets[] = ['path' => $path, 'ext' => $ext, 'size' => (int) ($file['size'] ?? 0)];
            if (count($targets) >= $limit) {
                break;
            }
        }

        $perExt = [];
        foreach ($targets as $t) {
            $perExt[$t['ext']] = ($perExt[$t['ext']] ?? 0) + 1;
        }

        $this->info('Target counts by extension:');
        $rows = [];
        foreach ($perExt as $ext => $count) {
            $rows[] = [".{$ext}", number_format($count)];
        }
        $this->table(['Extension', 'Count'], $rows);
        $this->line('Total: '.number_format(count($targets)));
        $this->newLine();

        if ($dryRun) {
            $this->warn('Dry-run — no writes performed. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $this->info('Executing backfill…');
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $insertedIds = [];

        foreach ($targets as $t) {
            $result = $ingester->ingestFile($treeId, $t['path'], false);
            if ($result['success'] ?? false) {
                $mediaId = (int) ($result['media_id'] ?? 0);
                if ($mediaId > 0) {
                    $insertedIds[] = $mediaId;
                }
                $imported++;
            } elseif (($result['reason'] ?? '') === 'already_ingested') {
                $skipped++;
            } else {
                $failed++;
                $reason = (string) ($result['reason'] ?? 'unknown');
                $this->warn("  {$t['path']} — {$reason}");
            }
        }

        $logPath = $this->writeRollbackLog($tag, $treeId, $folder, $insertedIds);

        $this->newLine();
        $this->info("Backfill complete: imported={$imported} skipped={$skipped} failed={$failed}");
        if ($logPath !== null) {
            $this->line("Rollback log: {$logPath}");
            if (! empty($insertedIds)) {
                $sample = implode(',', array_slice($insertedIds, 0, 5));
                $this->line("Rollback SQL: DELETE FROM genealogy_media WHERE id IN (<IDs from log>);  -- e.g. DELETE ... IN ({$sample}, ...)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Persist the list of inserted media_ids so the operator can roll back
     * this backfill run with a single DELETE IN (…) statement. Logged under
     * storage/logs/genealogy-backfill/{tag}-{timestamp}.json.
     */
    private function writeRollbackLog(string $tag, int $treeId, string $folder, array $insertedIds): ?string
    {
        if (empty($insertedIds)) {
            return null;
        }

        $dir = storage_path('logs/genealogy-backfill');
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $fileName = sprintf('%s-%s.json', $tag, now()->format('Y-m-d_His'));
        $path = $dir.'/'.$fileName;
        File::put($path, json_encode([
            'tag' => $tag,
            'tree_id' => $treeId,
            'folder' => $folder,
            'inserted_media_ids' => $insertedIds,
            'generated_at' => now()->toIso8601String(),
            'rollback_sql' => 'DELETE FROM genealogy_media WHERE id IN ('.implode(',', $insertedIds).');',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
