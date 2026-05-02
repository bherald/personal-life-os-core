<?php

namespace App\Console\Commands;

use App\Services\FaceMatcherService;
use App\Services\FaceRegionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MediaSyncFacesCommand - Sync faces to genealogy and refresh media
 *
 * Handles:
 * 1. Face name → genealogy_persons linking (auto + queue)
 * 2. Genealogy media refresh when source has more/different faces
 * 3. Bi-directional sync of face names when genealogy persons change
 */
class MediaSyncFacesCommand extends Command
{
    protected $signature = 'media:sync-faces
                            {--refresh-genealogy : Copy updated media from source to genealogy}
                            {--sync-names : Sync genealogy person names back to EXIF}
                            {--limit=100 : Max records to process}
                            {--tree-id=4 : Genealogy tree ID}
                            {--dry-run : Show what would be done without doing it}
                            {--stats : Show sync statistics only}';

    protected $description = 'Sync face names between media files and genealogy';

    public function handle(FaceMatcherService $faceMatcher, FaceRegionService $faceRegion): int
    {
        $limit = (int) $this->option('limit');
        $treeId = (int) $this->option('tree-id');
        $dryRun = $this->option('dry-run');

        if ($this->option('stats')) {
            return $this->showStats($treeId);
        }

        $this->info('Media Face Sync');
        $this->info('===============');

        // Step 1: Always run face matching for unlinked faces
        $this->info('');
        $this->info('Step 1: Linking unmatched faces to genealogy...');
        if (!$dryRun) {
            $matchResults = $faceMatcher->processBatch($limit, $treeId);
            $this->table(['Metric', 'Count'], [
                ['Processed', $matchResults['processed']],
                ['Auto-linked', $matchResults['auto_linked']],
                ['Queued', $matchResults['queued']],
            ]);
        } else {
            $unlinked = DB::selectOne("
                SELECT COUNT(*) as cnt FROM file_registry_faces
                WHERE genealogy_person_id IS NULL
                AND person_name IS NOT NULL AND person_name != '' AND person_name != 'Unknown'
            ");
            $this->warn("Dry run: Would process up to {$limit} of {$unlinked->cnt} unlinked faces");
        }

        // Step 2: Refresh genealogy media if source has updated faces
        if ($this->option('refresh-genealogy')) {
            $this->info('');
            $this->info('Step 2: Checking for genealogy media refresh...');
            $this->refreshGenealogyMedia($faceRegion, $limit, $treeId, $dryRun);
        }

        // Step 3: Sync genealogy person name changes back to EXIF
        if ($this->option('sync-names')) {
            $this->info('');
            $this->info('Step 3: Syncing name changes to EXIF...');
            $this->syncNamesToExif($faceRegion, $limit, $dryRun);
        }

        return Command::SUCCESS;
    }

    /**
     * Refresh genealogy media when source has more/different faces
     */
    private function refreshGenealogyMedia(
        FaceRegionService $faceRegion,
        int $limit,
        int $treeId,
        bool $dryRun
    ): void {
        // Find genealogy_media that might need refresh
        // Compare source (file_registry) face data with genealogy_media
        $candidates = DB::select("
            SELECT
                gm.id as media_id,
                gm.local_filename as genealogy_path,
                gm.nextcloud_path,
                fr.id as file_registry_id,
                fr.current_path as source_path,
                fr.face_count as source_face_count,
                fr.face_scan_at as source_scan_at,
                gm.last_face_sync_at,
                (SELECT COUNT(*) FROM genealogy_person_media gpm WHERE gpm.media_id = gm.id) as genealogy_face_count
            FROM genealogy_media gm
            JOIN file_registry fr ON fr.current_path = gm.nextcloud_path
            WHERE gm.tree_id = ?
            AND fr.face_count > 0
            AND (
                gm.last_face_sync_at IS NULL
                OR fr.face_scan_at > gm.last_face_sync_at
                OR fr.face_count > (SELECT COUNT(*) FROM genealogy_person_media gpm WHERE gpm.media_id = gm.id)
            )
            ORDER BY gm.id ASC
            LIMIT ?
        ", [$treeId, $limit]);

        if (empty($candidates)) {
            $this->info('No genealogy media needs refresh.');
            return;
        }

        $this->info("Found " . count($candidates) . " media items that may need refresh.");

        $stats = ['checked' => 0, 'refreshed' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($candidates as $media) {
            $stats['checked']++;

            // Get faces from source file_registry_faces
            $sourceFaces = DB::select("
                SELECT person_name, genealogy_person_id, region_x, region_y, region_w, region_h
                FROM file_registry_faces
                WHERE file_registry_id = ?
            ", [$media->file_registry_id]);

            // Get faces already in genealogy_person_media
            $genealogyFaces = DB::select("
                SELECT gpm.person_id, gp.given_name, gp.surname
                FROM genealogy_person_media gpm
                JOIN genealogy_persons gp ON gp.id = gpm.person_id
                WHERE gpm.media_id = ?
            ", [$media->media_id]);

            $genealogyPersonIds = array_column($genealogyFaces, 'person_id');

            // Find faces to add
            $facesToAdd = [];
            foreach ($sourceFaces as $face) {
                if ($face->genealogy_person_id && !in_array($face->genealogy_person_id, $genealogyPersonIds)) {
                    $facesToAdd[] = $face;
                }
            }

            if (empty($facesToAdd)) {
                $stats['skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->line("  Would add " . count($facesToAdd) . " faces to media #{$media->media_id}");
                $stats['refreshed']++;
                continue;
            }

            // Add missing faces to genealogy_person_media
            try {
                foreach ($facesToAdd as $face) {
                    // Check if already exists
                    $existing = DB::selectOne("
                        SELECT id FROM genealogy_person_media
                        WHERE person_id = ? AND media_id = ?
                    ", [$face->genealogy_person_id, $media->media_id]);

                    if (!$existing) {
                        DB::insert("
                            INSERT INTO genealogy_person_media
                            (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h,
                             face_confirmed, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                        ", [
                            $face->genealogy_person_id,
                            $media->media_id,
                            $face->region_x,
                            $face->region_y,
                            $face->region_w,
                            $face->region_h,
                        ]);
                    }
                }

                // Update last sync timestamp
                DB::update("
                    UPDATE genealogy_media SET last_face_sync_at = NOW() WHERE id = ?
                ", [$media->media_id]);

                $stats['refreshed']++;

                Log::info('MediaSyncFaces: Refreshed genealogy media', [
                    'media_id' => $media->media_id,
                    'faces_added' => count($facesToAdd),
                ]);

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('MediaSyncFaces: Error refreshing media', [
                    'media_id' => $media->media_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->table(['Metric', 'Count'], [
            ['Checked', $stats['checked']],
            ['Refreshed', $stats['refreshed']],
            ['Skipped', $stats['skipped']],
            ['Errors', $stats['errors']],
        ]);
    }

    /**
     * Sync genealogy person name changes back to EXIF face regions
     *
     * When a person's name changes in genealogy, update the EXIF face name
     */
    private function syncNamesToExif(FaceRegionService $faceRegion, int $limit, bool $dryRun): void
    {
        // Find faces where person_name differs from linked genealogy_person name
        $mismatches = DB::select("
            SELECT
                frf.id as face_id,
                frf.person_name as exif_name,
                CONCAT(gp.given_name, ' ', gp.surname) as genealogy_name,
                fr.current_path,
                fr.asset_uuid
            FROM file_registry_faces frf
            JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.genealogy_person_id IS NOT NULL
            AND frf.person_name != CONCAT(gp.given_name, ' ', gp.surname)
            LIMIT ?
        ", [$limit]);

        if (empty($mismatches)) {
            $this->info('No name mismatches to sync.');
            return;
        }

        $this->info("Found " . count($mismatches) . " name mismatches.");

        $stats = ['updated' => 0, 'errors' => 0];

        foreach ($mismatches as $mismatch) {
            if ($dryRun) {
                $this->line("  Would update: \"{$mismatch->exif_name}\" → \"{$mismatch->genealogy_name}\"");
                $this->line("    File: {$mismatch->current_path}");
                continue;
            }

            try {
                // Update face region in EXIF using FaceRegionService
                $result = $faceRegion->updateFaceRegion(
                    $mismatch->current_path,
                    $mismatch->exif_name,
                    ['name' => $mismatch->genealogy_name]
                );

                if ($result['success']) {
                    // Update database record
                    DB::update("
                        UPDATE file_registry_faces
                        SET person_name = ?, updated_at = NOW()
                        WHERE id = ?
                    ", [$mismatch->genealogy_name, $mismatch->face_id]);

                    $stats['updated']++;

                    Log::info('MediaSyncFaces: Updated face name in EXIF', [
                        'face_id' => $mismatch->face_id,
                        'old_name' => $mismatch->exif_name,
                        'new_name' => $mismatch->genealogy_name,
                    ]);
                } else {
                    $stats['errors']++;
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('MediaSyncFaces: Error updating EXIF', [
                    'face_id' => $mismatch->face_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->table(['Metric', 'Count'], [
                ['Updated', $stats['updated']],
                ['Errors', $stats['errors']],
            ]);
        }
    }

    /**
     * Show sync statistics
     */
    private function showStats(int $treeId): int
    {
        $this->info('Face Sync Statistics');
        $this->info('====================');

        // Linking status
        $linkStats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) as linked,
                SUM(CASE WHEN genealogy_person_id IS NULL THEN 1 ELSE 0 END) as unlinked,
                SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified
            FROM file_registry_faces
        ");

        $this->newLine();
        $this->info('Face Registry Status:');
        $this->table(['Status', 'Count'], [
            ['Total Faces', number_format($linkStats->total ?? 0)],
            ['Linked to Genealogy', number_format($linkStats->linked ?? 0)],
            ['Unlinked', number_format($linkStats->unlinked ?? 0)],
            ['Verified', number_format($linkStats->verified ?? 0)],
        ]);

        // Name mismatches (need EXIF sync)
        try {
            $mismatchCount = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM file_registry_faces frf
                JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                WHERE frf.genealogy_person_id IS NOT NULL
                AND frf.person_name != CONCAT(gp.given_name, ' ', gp.surname)
            ");
        } catch (\Exception $e) {
            $mismatchCount = (object)['cnt' => 0];
        }

        // Genealogy media needing refresh
        try {
            $refreshCount = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM genealogy_media gm
                JOIN file_registry fr ON fr.current_path = gm.nextcloud_path
                WHERE gm.tree_id = ?
                AND fr.face_count > 0
                AND (
                    gm.last_face_sync_at IS NULL
                    OR fr.face_scan_at > gm.last_face_sync_at
                )
            ", [$treeId]);
        } catch (\Exception $e) {
            $refreshCount = (object)['cnt' => 0];
        }

        $this->newLine();
        $this->info('Sync Status:');
        $this->table(['Metric', 'Count'], [
            ['Name Mismatches (need EXIF update)', $mismatchCount->cnt ?? 0],
            ['Genealogy Media (need refresh)', $refreshCount->cnt ?? 0],
        ]);

        // Match queue
        $queueStats = DB::select("
            SELECT status, COUNT(*) as cnt
            FROM genealogy_face_match_queue
            WHERE tree_id = ?
            GROUP BY status
        ", [$treeId]);

        $this->newLine();
        $this->info('Match Queue:');
        $rows = [];
        foreach ($queueStats as $row) {
            $rows[] = [ucfirst($row->status), $row->cnt];
        }
        if (empty($rows)) {
            $rows[] = ['(empty)', 0];
        }
        $this->table(['Status', 'Count'], $rows);

        return Command::SUCCESS;
    }
}
