<?php

namespace App\Nodes;

use App\Services\JoplinSyncService;
use App\Services\RAGService;
use App\Services\AIService;
use Exception;

/**
 * Joplin Sync Node - Enhanced sync with attachment processing
 *
 * Features:
 * - Syncs Joplin notes from Nextcloud to RAG
 * - Processes attachments (PDF, images, text files)
 * - Updates changed notes, adds new ones, removes deleted ones
 * - Tracks sync manifest with content hashing
 *
 * Uses DI container for proper AIService resilience (circuit breaker, retry, fallback).
 */
class JoplinSync extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            // Initialize services via DI for proper AIService injection
            $ragService = app(RAGService::class);
            $aiService = app(AIService::class);
            $syncService = new JoplinSyncService($ragService, $aiService);

            // Run enhanced sync
            $stats = $syncService->sync();

            // Prepare summary for output
            $summary = sprintf(
                "Joplin sync: %d added, %d updated, %d deleted, %d attachments processed (%d errors) in %dms",
                $stats['added'],
                $stats['updated'],
                $stats['deleted'],
                $stats['attachments_processed'],
                $stats['errors'],
                $stats['duration_ms']
            );

            return $this->standardOutput([
                'summary' => $summary,
                'stats' => $stats,
                'success' => $stats['errors'] === 0,
                'processed_formats' => $stats['processed_formats'],
                'unprocessable_formats' => $stats['unprocessable_formats'],
            ], [
                'added' => $stats['added'],
                'updated' => $stats['updated'],
                'deleted' => $stats['deleted'],
                'attachments_processed' => $stats['attachments_processed'],
                'errors' => $stats['errors'],
                'duration_ms' => $stats['duration_ms'],
                'total_notes' => $stats['total_notes'],
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], 'Joplin sync failed: ' . $e->getMessage());
        }
    }
}
