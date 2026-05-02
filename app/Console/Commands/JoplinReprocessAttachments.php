<?php

namespace App\Console\Commands;

use App\Services\AIService;
use App\Services\JoplinSyncService;
use App\Services\RAGService;
use Illuminate\Console\Command;

/**
 * Reprocess Joplin attachments with AI summarization and vision
 *
 * This command finds RAG entries with raw/placeholder content
 * and reprocesses them using AI to create accurate, concise summaries.
 *
 * Usage:
 *   php artisan joplin:reprocess-attachments
 *   php artisan joplin:reprocess-attachments --limit=100
 *   php artisan joplin:reprocess-attachments --pdf-only
 *   php artisan joplin:reprocess-attachments --no-ai (just clean up text)
 *   php artisan joplin:reprocess-attachments --no-vision (skip AI vision, use OCR)
 */
class JoplinReprocessAttachments extends Command
{
    protected $signature = 'joplin:reprocess-attachments
                            {--limit=50 : Maximum attachments to process}
                            {--pdf-only : Only process PDF attachments}
                            {--no-ai : Skip AI summarization, just clean up text}
                            {--no-ocr : Skip OCR for images}
                            {--no-vision : Skip AI vision model, use OCR fallback}';

    protected $description = 'Reprocess Joplin attachments with AI summarization for accuracy and conciseness';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $pdfOnly = $this->option('pdf-only');
        $noAi = $this->option('no-ai');
        $noOcr = $this->option('no-ocr');
        $noVision = $this->option('no-vision');

        $this->info("Joplin Attachment Reprocessing");
        $this->info("==============================");
        $this->info("Limit: {$limit}");
        $this->info("PDF only: " . ($pdfOnly ? 'Yes' : 'No'));
        $this->info("AI summarization: " . ($noAi ? 'Disabled' : 'Enabled'));
        $this->info("OCR for images: " . ($noOcr ? 'Disabled' : 'Enabled'));
        $this->info("AI vision (llava): " . ($noVision ? 'Disabled' : 'Enabled'));
        $this->newLine();

        // Create services
        $ragService = app(RAGService::class);
        $aiService = $noAi ? null : app(AIService::class);

        $syncService = new JoplinSyncService($ragService, $aiService);
        $syncService->setAISummarization(!$noAi);
        $syncService->setOCR(!$noOcr);
        $syncService->setVision(!$noVision);

        $this->info("Starting reprocessing...");
        $this->newLine();

        $startTime = now();
        $stats = $syncService->reprocessAttachments($limit, $pdfOnly);
        $duration = now()->diffInSeconds($startTime);

        $this->newLine();
        $this->info("Reprocessing Complete");
        $this->info("=====================");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Improved', $stats['improved']],
                ['Skipped', $stats['skipped']],
                ['Failed', $stats['failed']],
                ['Duration', "{$duration}s"],
            ]
        );

        if ($stats['improved'] > 0) {
            $this->info("Successfully improved {$stats['improved']} attachments with AI summarization.");
        }

        if ($stats['failed'] > 0) {
            $this->warn("Failed to process {$stats['failed']} attachments. Check logs for details.");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
