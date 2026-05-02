<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Migrate 49K+ face embeddings from MySQL JSON to pgvector HNSW-indexed table.
 *
 * file_registry_faces stores embeddings as JSON text in MySQL. This command
 * copies them to face_embeddings (pgvector) with proper vector indexing for
 * fast similarity search, linking back via file_registry_face_id.
 *
 * Stale pgvector data (from earlier experiments) is truncated first.
 * No GPU needed — pure data migration.
 */
class FaceMigrateEmbeddingsCommand extends Command
{
    protected $signature = 'faces:migrate-embeddings
                            {--batch-size=500 : Rows per batch}
                            {--limit=0 : Max rows to migrate (0 = all)}
                            {--dry-run : Show counts without migrating}
                            {--backfill-missing : Re-detect faces missing embeddings}
                            {--skip-truncate : Skip truncating stale pgvector data}
                            {--force : Confirm truncating stale pgvector data when present}';

    protected $description = 'Migrate face embeddings from MySQL JSON to pgvector';

    private int $migrated = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        // Count source data
        $totalFaces = DB::selectOne('SELECT COUNT(*) as cnt FROM file_registry_faces')->cnt;
        $withEmbedding = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry_faces
            WHERE embedding IS NOT NULL AND embedding != '' AND embedding != '[]'
        ")->cnt;
        $withoutEmbedding = $totalFaces - $withEmbedding;

        // Count existing pgvector data
        $existingPg = DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) as cnt FROM face_embeddings')->cnt;
        $existingClusters = DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) as cnt FROM person_clusters')->cnt;

        $this->info("Source: {$totalFaces} faces in MySQL, {$withEmbedding} with embeddings, {$withoutEmbedding} without");
        $this->info("Existing pgvector: {$existingPg} face_embeddings, {$existingClusters} person_clusters");

        if ($dryRun) {
            $this->info('');
            $this->info('[DRY RUN] Would truncate stale pgvector data and migrate '.
                ($limit > 0 ? min($limit, $withEmbedding) : $withEmbedding).' embeddings');

            // Check how many already have pgvector rows (for re-run scenarios)
            $alreadyMigrated = DB::connection('pgsql_rag')->selectOne('
                SELECT COUNT(*) as cnt FROM face_embeddings WHERE file_registry_face_id IS NOT NULL
            ')->cnt;
            if ($alreadyMigrated > 0) {
                $this->info("Already migrated (have file_registry_face_id): {$alreadyMigrated}");
            }

            return Command::SUCCESS;
        }

        // Truncate stale data unless skipped
        if (! $this->option('skip-truncate')) {
            if (($existingPg > 0 || $existingClusters > 0) && ! $this->option('force')) {
                $confirmed = $this->confirm(
                    "Truncate {$existingPg} face_embeddings and {$existingClusters} person_clusters before migrating?",
                    false
                );

                if (! $confirmed) {
                    $this->warn('Migration cancelled. Re-run with --skip-truncate to preserve existing pgvector data or --force to confirm truncation.');

                    return Command::FAILURE;
                }
            }

            $this->truncateStaleData($existingPg, $existingClusters);
        }

        // Migrate embeddings
        $this->info('');
        $this->info('Migrating embeddings from MySQL to pgvector...');

        $startTime = microtime(true);
        $this->migrateEmbeddings($batchSize, $limit);
        $elapsed = round(microtime(true) - $startTime, 1);

        $this->info('');
        $this->info("Migration complete in {$elapsed}s");
        $this->info("  Migrated: {$this->migrated}");
        $this->info("  Skipped (bad data): {$this->skipped}");
        $this->info("  Errors: {$this->errors}");

        // Verify
        $pgCount = DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) as cnt FROM face_embeddings')->cnt;
        $this->info("  pgvector total: {$pgCount}");

        // Handle missing embeddings
        if ($this->option('backfill-missing') && $withoutEmbedding > 0) {
            $this->info('');
            $this->backfillMissing();
        } elseif ($withoutEmbedding > 0) {
            $this->warn("{$withoutEmbedding} faces have no embedding (use --backfill-missing to re-detect)");
        }

        return Command::SUCCESS;
    }

    private function truncateStaleData(int $existingPg, int $existingClusters): void
    {
        if ($existingPg === 0 && $existingClusters === 0) {
            $this->info('No stale pgvector data to clear.');

            return;
        }

        $this->warn("Truncating stale pgvector data: {$existingPg} face_embeddings, {$existingClusters} person_clusters");

        // Truncate in dependency order
        DB::connection('pgsql_rag')->statement('TRUNCATE TABLE face_match_candidates CASCADE');
        DB::connection('pgsql_rag')->statement('TRUNCATE TABLE cluster_merge_history CASCADE');
        DB::connection('pgsql_rag')->statement('TRUNCATE TABLE face_embeddings CASCADE');
        DB::connection('pgsql_rag')->statement('TRUNCATE TABLE person_clusters CASCADE');

        // Also clear cluster_id from MySQL faces (stale references)
        $cleared = DB::update('UPDATE file_registry_faces SET cluster_id = NULL WHERE cluster_id IS NOT NULL');
        if ($cleared > 0) {
            $this->info("  Cleared {$cleared} stale cluster_id refs in MySQL");
        }

        $this->info('  Stale data cleared.');
    }

    private function migrateEmbeddings(int $batchSize, int $limit): void
    {
        $offset = 0;
        $total = $limit > 0 ? $limit : PHP_INT_MAX;

        $bar = null;
        if ($limit === 0) {
            $count = DB::selectOne("
                SELECT COUNT(*) as cnt FROM file_registry_faces
                WHERE embedding IS NOT NULL AND embedding != '' AND embedding != '[]'
            ")->cnt;
            $bar = $this->output->createProgressBar($count);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        }

        while ($this->migrated + $this->skipped < $total) {
            $currentBatch = min($batchSize, $total - $this->migrated - $this->skipped);

            $faces = DB::select("
                SELECT id, file_registry_id, region_x, region_y, region_w, region_h,
                       confidence, embedding, person_name, source
                FROM file_registry_faces
                WHERE embedding IS NOT NULL AND embedding != '' AND embedding != '[]'
                ORDER BY id
                LIMIT ? OFFSET ?
            ", [$currentBatch, $offset]);

            if (empty($faces)) {
                break;
            }

            $offset += count($faces);
            $values = [];
            $params = [];

            foreach ($faces as $face) {
                $embedding = json_decode($face->embedding, true);

                if (! is_array($embedding) || count($embedding) !== 128) {
                    $this->skipped++;
                    $bar?->advance();

                    continue;
                }

                // Validate all values are numeric
                $valid = true;
                foreach ($embedding as $v) {
                    if (! is_numeric($v)) {
                        $valid = false;
                        break;
                    }
                }
                if (! $valid) {
                    $this->skipped++;
                    $bar?->advance();

                    continue;
                }

                $embeddingStr = '['.implode(',', $embedding).']';
                $values[] = '(?, ?, ?::vector, ?, ?, ?, ?, ?, NOW(), NOW())';
                $params[] = $face->file_registry_id;
                $params[] = $face->id; // file_registry_face_id
                $params[] = $embeddingStr;
                $params[] = (float) $face->region_x;
                $params[] = (float) $face->region_y;
                $params[] = (float) $face->region_w;
                $params[] = (float) $face->region_h;
                $params[] = (float) ($face->confidence ?? 0.9);
            }

            if (! empty($values)) {
                try {
                    $sql = 'INSERT INTO face_embeddings
                            (file_registry_id, file_registry_face_id, embedding,
                             region_x, region_y, region_w, region_h, quality_score,
                             created_at, updated_at)
                            VALUES '.implode(', ', $values).'
                            ON CONFLICT (file_registry_face_id)
                            WHERE file_registry_face_id IS NOT NULL
                            DO NOTHING';

                    DB::connection('pgsql_rag')->insert($sql, $params);
                    $inserted = count($values);
                    $this->migrated += $inserted;
                    $bar?->advance($inserted);
                } catch (\Exception $e) {
                    // Fall back to row-by-row on batch failure
                    $this->insertRowByRow($faces, $bar);
                }
            }
        }

        $bar?->finish();
        $this->info('');
    }

    private function insertRowByRow(array $faces, $bar): void
    {
        foreach ($faces as $face) {
            $embedding = json_decode($face->embedding, true);
            if (! is_array($embedding) || count($embedding) !== 128) {
                $this->skipped++;
                $bar?->advance();

                continue;
            }

            $embeddingStr = '['.implode(',', $embedding).']';

            try {
                DB::connection('pgsql_rag')->insert('
                    INSERT INTO face_embeddings
                    (file_registry_id, file_registry_face_id, embedding,
                     region_x, region_y, region_w, region_h, quality_score,
                     created_at, updated_at)
                    VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON CONFLICT (file_registry_face_id)
                    WHERE file_registry_face_id IS NOT NULL
                    DO NOTHING
                ', [
                    $face->file_registry_id,
                    $face->id,
                    $embeddingStr,
                    (float) $face->region_x,
                    (float) $face->region_y,
                    (float) $face->region_w,
                    (float) $face->region_h,
                    (float) ($face->confidence ?? 0.9),
                ]);
                $this->migrated++;
            } catch (\Exception $e) {
                $this->errors++;
                Log::warning('FaceMigrateEmbeddings: row error', [
                    'face_id' => $face->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $bar?->advance();
        }
    }

    private function backfillMissing(): void
    {
        $missing = DB::select("
            SELECT frf.id, frf.file_registry_id, fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE (frf.embedding IS NULL OR frf.embedding = '' OR frf.embedding = '[]')
            AND fr.current_path IS NOT NULL
            LIMIT 100
        ");

        if (empty($missing)) {
            $this->info('No faces missing embeddings with accessible source files.');

            return;
        }

        $this->info('Found '.count($missing).' faces missing embeddings. Re-detecting...');

        $scriptPath = base_path('scripts/face_detector.py');
        if (! file_exists($scriptPath)) {
            $this->error('Python face_detector.py not found');

            return;
        }

        $backfilled = 0;
        $failed = 0;

        foreach ($missing as $face) {
            $path = $face->current_path;
            if (! file_exists($path)) {
                $dataPath = trim((string) config('services.nextcloud.data_path', ''));
                $dataRoot = $dataPath === '' ? '' : rtrim($dataPath, '/').'/';
                if ($dataRoot !== '' && str_starts_with($path, $dataRoot)) {
                    $relativePath = substr($path, strlen($dataPath));
                    $path = '/'.ltrim($relativePath, '/');
                }
            }
            if (! file_exists($path)) {
                $failed++;

                continue;
            }

            $output = Process::timeout(120)->run([
                'python3',
                $scriptPath,
                '--image',
                $path,
            ])->output();

            $result = json_decode($output, true);
            if (! $result || ! ($result['success'] ?? false) || empty($result['faces'])) {
                $failed++;

                continue;
            }

            // Find best matching face by region overlap
            $bestMatch = null;
            $bestIou = 0;

            foreach ($result['faces'] as $detected) {
                $norm = $detected['normalized'] ?? [];
                if (empty($norm) || empty($detected['embedding'])) {
                    continue;
                }

                // Simple distance check (not full IoU, just close enough)
                $dist = abs(($norm['x'] ?? 0) - (float) $face->region_x ?? 0)
                      + abs(($norm['y'] ?? 0) - (float) $face->region_y ?? 0);
                if ($bestMatch === null || $dist < $bestIou) {
                    $bestMatch = $detected;
                    $bestIou = $dist;
                }
            }

            if ($bestMatch && ! empty($bestMatch['embedding']) && count($bestMatch['embedding']) === 128) {
                $embJson = json_encode($bestMatch['embedding']);

                // Update MySQL
                DB::update('
                    UPDATE file_registry_faces SET embedding = ?, updated_at = NOW() WHERE id = ?
                ', [$embJson, $face->id]);

                // Insert into pgvector
                $embStr = '['.implode(',', $bestMatch['embedding']).']';
                $norm = $bestMatch['normalized'];

                try {
                    DB::connection('pgsql_rag')->insert('
                        INSERT INTO face_embeddings
                        (file_registry_id, file_registry_face_id, embedding,
                         region_x, region_y, region_w, region_h,
                         created_at, updated_at)
                        VALUES (?, ?, ?::vector, ?, ?, ?, ?, NOW(), NOW())
                        ON CONFLICT (file_registry_face_id)
                        WHERE file_registry_face_id IS NOT NULL
                        DO NOTHING
                    ', [
                        $face->file_registry_id,
                        $face->id,
                        $embStr,
                        $norm['x'] ?? 0, $norm['y'] ?? 0,
                        $norm['w'] ?? 0, $norm['h'] ?? 0,
                    ]);
                    $backfilled++;
                } catch (\Exception $e) {
                    $failed++;
                }
            } else {
                $failed++;
            }
        }

        $this->info("  Backfilled: {$backfilled}, Failed: {$failed}");
    }
}
