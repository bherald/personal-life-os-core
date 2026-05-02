<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'file_registry_stats',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getStatistics',
                'description' => 'Get overall file registry statistics including total files, counts by status (active, orphaned, deleted), by extension, and total storage size.',
                'parameters' => '[]',
                'returns_description' => 'Array with total files, by status breakdown, by extension breakdown, storage size totals',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_maintenance_stats',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getMaintenanceStats',
                'description' => 'Get file registry maintenance statistics including counts of orphaned files, deleted records, unverified files, and files needing re-registration.',
                'parameters' => '[]',
                'returns_description' => 'Array with orphaned count, deleted count, unverified count, and maintenance recommendations',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_ai_tag_stats',
                'service_class' => 'App\\Services\\AIAutoTagService',
                'method' => 'getStats',
                'description' => 'Get AI auto-tagging statistics including total analyzed files, pending analysis count, analysis errors, document type breakdown, and top tags.',
                'parameters' => '[]',
                'returns_description' => 'Array with total files, analyzed count, pending count, error count, document types breakdown, top tags',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_thumbnail_stats',
                'service_class' => 'App\\Services\\ThumbnailService',
                'method' => 'getStats',
                'description' => 'Get thumbnail cache statistics including total generated, pending generation, error count, and disk usage by size category.',
                'parameters' => '[]',
                'returns_description' => 'Array with total generated, pending, errors, disk usage by size (small/medium/large)',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_phash_stats',
                'service_class' => 'App\\Services\\PerceptualHashService',
                'method' => 'getStatistics',
                'description' => 'Get perceptual hash statistics including total hashes computed, unique files hashed, similar pairs found by classification (exact, near_duplicate, similar), and pairs pending review.',
                'parameters' => '[]',
                'returns_description' => 'Array with total hashes, unique files, similar pairs by type, pending review count, confirmed duplicates',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_face_stats',
                'service_class' => 'App\\Services\\FaceEmbeddingService',
                'method' => 'getStats',
                'description' => 'Get face detection and clustering statistics including total embeddings, cluster counts by status (unreviewed, confirmed, ignored), and pending AI verification count.',
                'parameters' => '[]',
                'returns_description' => 'Array with total embeddings, clusters by status, unreviewed count, pending AI verification',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_quarantine_stats',
                'service_class' => 'App\\Services\\FileQuarantineService',
                'method' => 'getStats',
                'description' => 'Get file quarantine statistics including counts by status (quarantined, released, deleted), by reason (suspicious, virus, manual), and total quarantine events.',
                'parameters' => '[]',
                'returns_description' => 'Array with counts by status, by reason, total events',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_exif_writeback_stats',
                'service_class' => 'App\\Services\\ExifWritebackService',
                'method' => 'getStats',
                'description' => 'Get EXIF metadata writeback statistics including pending writeback counts for dates, faces, and tags, plus total pending across all types.',
                'parameters' => '[]',
                'returns_description' => 'Array with pending dates, pending faces, pending tags, total pending',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_rag_index_stats',
                'service_class' => 'App\\Services\\FileCategorizationRAGService',
                'method' => 'getStats',
                'description' => 'Get file RAG index statistics including total indexed files, pending indexing count, and breakdown by category and extension.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_indexed, pending_indexing, by_category, by_extension',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'file_verify_batch',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'verifyBatch',
                'description' => 'Verify existence of a batch of registered files in Nextcloud. Marks missing files but does not delete records. Safe maintenance operation.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum files to verify per batch (default 100)'],
                ]),
                'returns_description' => 'Array with verified count, missing count, error count, and list of missing file UUIDs',
                'permissions' => '["file:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'file',
            ],
            [
                'name' => 'file_detect_removed',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'detectRemovedFiles',
                'description' => 'Detect files that have been removed from Nextcloud since last verification. Marks them as missing in the registry without deleting records.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum files to check (default 100)'],
                ]),
                'returns_description' => 'Array with checked count, removed count, and list of removed file paths',
                'permissions' => '["file:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'file',
            ],
            [
                'name' => 'file_cleanup_orphaned',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'cleanupOrphanedFiles',
                'description' => 'Clean up orphaned and deleted file records that have exceeded the failure threshold. Permanently removes records and associated data (thumbnails, hashes, tags). Run only after verifying orphans with file_verify_batch and file_detect_removed first.',
                'parameters' => json_encode([
                    'failureThreshold' => ['type' => 'integer', 'required' => false, 'default' => 3, 'description' => 'Number of failed verifications before cleanup (default 3)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum records to clean up (default 100)'],
                ]),
                'returns_description' => 'Array with cleaned count, freed storage size, and details of removed records',
                'permissions' => '["file:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'file',
            ],
            [
                'name' => 'file_scan_suspicious',
                'service_class' => 'App\\Services\\FileQuarantineService',
                'method' => 'scanForSuspicious',
                'description' => 'Scan file registry for suspicious files including dual-extension files, zero-byte files, and oversized files (>10GB). Does not quarantine — only identifies candidates.',
                'parameters' => json_encode([
                    'path' => ['type' => 'string', 'required' => false, 'default' => '/Library', 'description' => 'Base path to scan (default configured library root)'],
                ]),
                'returns_description' => 'Array of suspicious files with UUID, path, reason (dual_extension, zero_byte, oversized), and details',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_quarantine_pending',
                'service_class' => 'App\\Services\\FileQuarantineService',
                'method' => 'getPendingReview',
                'description' => 'Get quarantined files awaiting human review. Shows files that have been quarantined but not yet released or deleted.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum items to return (default 50)'],
                ]),
                'returns_description' => 'Array of pending quarantine items with ID, file UUID, path, reason, quarantine date, and detected_by',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_thumbnail_cleanup',
                'service_class' => 'App\\Services\\ThumbnailService',
                'method' => 'cleanupOrphaned',
                'description' => 'Delete orphaned thumbnails whose source files have been deleted from the registry. Safe maintenance operation that frees disk space.',
                'parameters' => '[]',
                'returns_description' => 'Array with deleted count and any errors encountered',
                'permissions' => '["file:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'file',
            ],
            [
                'name' => 'file_duplicates_stats',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getDuplicatesStats',
                'description' => 'Get exact duplicate file statistics (same content hash). Shows duplicate counts by category and total storage wasted on duplicates.',
                'parameters' => '[]',
                'returns_description' => 'Array with duplicate counts by category, total duplicate groups, total wasted storage',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_visual_duplicates',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getVisualDuplicatesReport',
                'description' => 'Get visually similar image pairs pending human review. These are images with similar perceptual hashes that may be duplicates or near-duplicates.',
                'parameters' => json_encode([
                    'status' => ['type' => 'string', 'required' => false, 'default' => 'pending_review', 'description' => 'Filter by status: pending_review, confirmed, rejected (default pending_review)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum pairs to return (default 100)'],
                ]),
                'returns_description' => 'Array of similar image pairs with file UUIDs, paths, similarity classification, and hamming distance',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_face_clusters_review',
                'service_class' => 'App\\Services\\FaceEmbeddingService',
                'method' => 'getClustersForReview',
                'description' => 'Get face clusters pending human review. Returns unreviewed clusters sorted by face count (largest first) so the most impactful clusters are reviewed first.',
                'parameters' => json_encode([
                    'status' => ['type' => 'string', 'required' => false, 'default' => 'unreviewed', 'description' => 'Cluster status filter: unreviewed, confirmed, ignored (default unreviewed)'],
                    'minFaces' => ['type' => 'integer', 'required' => false, 'default' => 2, 'description' => 'Minimum faces in cluster to include (default 2)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum clusters to return (default 50)'],
                ]),
                'returns_description' => 'Array of clusters with ID, face count, status, sample face paths, and representative embedding info',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_rag_sync',
                'service_class' => 'App\\Services\\FileCategorizationRAGService',
                'method' => 'syncWithRegistry',
                'description' => 'Sync the file RAG index with the file registry. Indexes new files and removes orphaned index entries. Safe additive operation.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum files to process in this sync (default 100)'],
                ]),
                'returns_description' => 'Array with indexed count, removed count, errors, and sync duration',
                'permissions' => '["file:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'file',
            ],
        ];

        foreach ($tools as $tool) {
            try {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, enabled, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config')
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ]);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // Add scheduled job for file-ops agent
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'file_ops_agent'");
        if (! $exists) {
            DB::insert('
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ', [
                'file_ops_agent',
                'File registry health monitoring: enrichment pipelines, thumbnails, duplicates, faces, quarantine, EXIF writeback, RAG index',
                'file-ops',
                '*/30 * * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }
    }

    public function down(): void
    {
        $toolNames = [
            'file_registry_stats', 'file_maintenance_stats', 'file_ai_tag_stats',
            'file_thumbnail_stats', 'file_phash_stats', 'file_face_stats',
            'file_quarantine_stats', 'file_exif_writeback_stats', 'file_rag_index_stats',
            'file_verify_batch', 'file_detect_removed', 'file_cleanup_orphaned',
            'file_scan_suspicious', 'file_quarantine_pending', 'file_thumbnail_cleanup',
            'file_duplicates_stats', 'file_visual_duplicates', 'file_face_clusters_review',
            'file_rag_sync',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'file_ops_agent'");
    }
};
