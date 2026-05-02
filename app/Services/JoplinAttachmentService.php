<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * JoplinAttachmentService
 *
 * Enhanced Joplin attachment extraction with v3 pipeline (E17)
 * Now uses centralized ContentExtractionService and MediaUrlService
 *
 * Features:
 * - Full entity extraction (dates, amounts, names, MICR, barcodes, etc.)
 * - Beautiful Markdown formatting with AI summary prelude
 * - Change detection via content_hash + extraction_version
 * - Queue-based processing via Horizon
 * - Rate limiting and fault tolerance
 * - Nextcloud URL generation for source media links
 *
 * Independent PLOS attachment-processing adapter for an operator-managed sync
 * target; it does not use upstream Joplin application or server source code.
 *
 * Uses raw SQL - no Eloquent models
 */
class JoplinAttachmentService
{
    protected ContentExtractionService $extractionService;

    protected MediaUrlService $mediaUrlService;

    protected AIService $aiService;

    protected string $nextcloudUrl;

    protected string $username;

    protected string $password;

    protected string $joplinPath = '/Joplin-data';

    protected string $extractionVersion;

    protected int $retryDelay;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    public function __construct(
        ContentExtractionService $extractionService,
        MediaUrlService $mediaUrlService,
        AIService $aiService
    ) {
        $this->extractionService = $extractionService;
        $this->mediaUrlService = $mediaUrlService;
        $this->aiService = $aiService;
        $this->nextcloudUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(false);
        $this->extractionVersion = config('services.joplin_attachments.extraction_version', 'v3');
        $this->retryDelay = config('services.joplin_attachments.retry_delay', 5);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Process a single attachment and return formatted Markdown
     *
     * @param  string  $resourceId  Joplin resource ID
     * @param  string  $filename  Original filename
     * @param  string  $noteId  Parent note ID
     * @return array ['success' => bool, 'markdown' => string, 'entities' => array, 'method' => string]
     */
    public function processAttachment(string $resourceId, string $filename, string $noteId): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $startTime = microtime(true);

        Log::channel('single')->info('Processing Joplin attachment', [
            'resource_id' => $resourceId,
            'filename' => $filename,
            'extension' => $extension,
            'note_id' => $noteId,
        ]);

        try {
            // Update status to processing
            $this->updateAttachmentStatus($noteId, $resourceId, 'processing');

            // Fetch attachment content from Nextcloud
            $content = $this->fetchAttachmentContent($resourceId);
            if (empty($content)) {
                throw new \Exception('Failed to fetch attachment content from Nextcloud');
            }

            $fileSize = strlen($content);
            $contentHash = md5($content);

            // Check if already processed with same hash and version
            if ($this->isAlreadyProcessed($noteId, $resourceId, $contentHash)) {
                Log::channel('single')->info('Attachment already processed, skipping', [
                    'resource_id' => $resourceId,
                ]);

                return [
                    'success' => true,
                    'markdown' => null,
                    'entities' => [],
                    'method' => 'cached',
                    'skipped' => true,
                ];
            }

            // Check if supported extension
            $supportedExtensions = config('services.joplin_attachments.supported_extensions', []);
            $placeholderExtensions = config('services.joplin_attachments.placeholder_extensions', []);

            if (in_array($extension, $placeholderExtensions)) {
                $markdown = $this->generatePlaceholderMarkdown($filename, $extension, $fileSize);
                $this->saveAttachmentIndex($noteId, $resourceId, $filename, $extension, $fileSize, $contentHash, 'placeholder', []);

                return [
                    'success' => true,
                    'markdown' => $markdown,
                    'entities' => [],
                    'method' => 'placeholder',
                ];
            }

            if (! isset($supportedExtensions[$extension])) {
                $markdown = $this->generateUnsupportedMarkdown($filename, $extension, $fileSize);
                $this->saveAttachmentIndex($noteId, $resourceId, $filename, $extension, $fileSize, $contentHash, 'unsupported', []);

                return [
                    'success' => true,
                    'markdown' => $markdown,
                    'entities' => [],
                    'method' => 'unsupported',
                ];
            }

            // Run extraction pipeline based on file type
            $extractionResult = $this->runExtractionPipeline($content, $filename, $extension);

            // Format as beautiful Markdown with source link
            $markdown = $this->formatAsMarkdown($filename, $extractionResult, $resourceId);

            // Save to index
            $this->saveAttachmentIndex(
                $noteId,
                $resourceId,
                $filename,
                $extension,
                $fileSize,
                $contentHash,
                $extractionResult['method'] ?? 'unknown',
                $extractionResult['entities'] ?? []
            );

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::channel('single')->info('Attachment processed successfully', [
                'resource_id' => $resourceId,
                'method' => $extractionResult['method'],
                'duration_ms' => $duration,
            ]);

            return [
                'success' => true,
                'markdown' => $markdown,
                'entities' => $extractionResult['entities'] ?? [],
                'method' => $extractionResult['method'] ?? 'unknown',
            ];

        } catch (\Exception $e) {
            Log::channel('single')->error('Attachment processing failed', [
                'resource_id' => $resourceId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            $this->updateAttachmentStatus($noteId, $resourceId, 'error', $e->getMessage());

            return [
                'success' => false,
                'markdown' => $this->generateErrorMarkdown($filename, $e->getMessage()),
                'entities' => [],
                'method' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run the v3 extraction pipeline using ContentExtractionService (E17)
     */
    protected function runExtractionPipeline(string $content, string $filename, string $extension): array
    {
        // Save content to temp file for processing
        $tempFile = $this->saveTempFile($content, $extension);
        $isPdf = $extension === 'pdf';

        try {
            // Use centralized ContentExtractionService for extraction
            $extractResult = $this->extractionService->extract($tempFile, [
                'use_vision' => ! $isPdf,
                'use_ocr' => true,
                'use_claude' => ! $isPdf,
                'extract_entities' => ! $isPdf,
                // Some PDFs can wedge Tika long enough to exhaust the queue job.
                // For Joplin PDFs, prefer text/OCR and skip AI enrichment in the queue path.
                'use_tika' => ! $isPdf,
            ]);

            $text = $extractResult['text'] ?? '';
            $method = $extractResult['method'] ?? 'unknown';
            $extractionDuration = ($extractResult['duration_ms'] ?? 0) / 1000;

            // Entity extraction via AI — skip if content extraction already took >5 min
            // to avoid job timeout. Text content is the priority; entities are supplemental.
            $entities = [];
            $description = '';
            if (! $isPdf && ! empty(trim($text)) && $extractionDuration < 300) {
                try {
                    $entityResult = $this->extractEntitiesWithAI($text, $filename, $extension);
                    $entities = $entityResult['entities'] ?? [];
                    $description = $entityResult['description'] ?? '';
                } catch (\Exception $e) {
                    Log::warning('JoplinAttachmentService: Entity extraction failed, continuing with text only', [
                        'filename' => $filename,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => ! empty($text),
                'description' => $description,
                'text' => $text,
                'entities' => $entities,
                'method' => $method,
            ];

        } finally {
            // Cleanup temp file
            @unlink($tempFile);
        }
    }

    /**
     * Extract structured entities from text using AIService
     */
    protected function extractEntitiesWithAI(string $text, string $filename, string $extension): array
    {
        $prompt = <<<PROMPT
Analyze this document content and extract structured information.

FILENAME: {$filename}
FILE TYPE: {$extension}

CONTENT:
{$text}

EXTRACT (if present):
- document_type: what kind of document (receipt, invoice, letter, etc.)
- document_date: primary date (YYYY-MM-DD format)
- amounts: monetary amounts with context
- names: person/company names
- addresses: full addresses
- phone_numbers, emails, account_numbers
- key_facts: 3-5 important facts for search

RESPOND IN JSON FORMAT:
{
  "description": "1-2 sentence summary",
  "entities": { /* extracted entities */ }
}
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 2000,
            ]);

            if ($result['success'] && ! empty($result['response'])) {
                $json = $this->extractJsonFromOutput($result['response']);
                if ($json) {
                    return [
                        'description' => $json['description'] ?? '',
                        'entities' => $json['entities'] ?? [],
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::channel('single')->warning('Entity extraction failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        return ['description' => "Document: {$filename}", 'entities' => []];
    }

    /**
     * Extract text using pdftotext
     */
    protected function extractWithPdfToText(string $filePath): array
    {
        try {
            $result = Process::timeout(60)->run(['pdftotext', '-layout', $filePath, '-']);

            if ($result->successful()) {
                return [
                    'success' => true,
                    'text' => trim($result->output()),
                ];
            }

            return ['success' => false, 'error' => $result->errorOutput()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract with Tesseract OCR
     */
    protected function extractWithTesseract(string $filePath): array
    {
        try {
            $result = Process::timeout(120)->run([
                'tesseract',
                $filePath,
                'stdout',
                '-l',
                'eng',
            ]);

            if ($result->successful()) {
                return [
                    'success' => true,
                    'text' => trim($result->output()),
                ];
            }

            return ['success' => false, 'error' => $result->errorOutput()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract PDF with Tesseract (convert pages to images first)
     */
    protected function extractPdfWithTesseract(string $pdfPath): array
    {
        $outputDir = storage_path('app/temp/pdf_images/'.uniqid());
        @mkdir($outputDir, 0755, true);

        try {
            // Convert PDF to images
            $result = Process::timeout(120)->run([
                'pdftoppm',
                '-png',
                '-r',
                '150',
                $pdfPath,
                $outputDir.'/page',
            ]);

            if (! $result->successful()) {
                return ['success' => false, 'error' => 'PDF to image conversion failed'];
            }

            $images = glob($outputDir.'/*.png');
            sort($images);

            $allText = [];
            foreach ($images as $index => $imagePath) {
                $ocrResult = $this->extractWithTesseract($imagePath);
                if ($ocrResult['success'] && ! empty($ocrResult['text'])) {
                    $allText[] = '--- Page '.($index + 1)." ---\n".$ocrResult['text'];
                }
                @unlink($imagePath);
            }

            @rmdir($outputDir);

            return [
                'success' => ! empty($allText),
                'text' => implode("\n\n", $allText),
            ];

        } catch (\Exception $e) {
            // Cleanup on error
            array_map('unlink', glob($outputDir.'/*'));
            @rmdir($outputDir);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract Office documents
     */
    protected function extractOfficeDocument(string $filePath, string $extension): array
    {
        try {
            if (in_array($extension, ['docx', 'odt'])) {
                $docxResult = Process::timeout(60)->run(['docx2txt', $filePath, '-']);
                if ($docxResult->successful() && ! empty(trim($docxResult->output()))) {
                    return ['success' => true, 'text' => $docxResult->output()];
                }

                $antiwordResult = Process::timeout(60)->run(['antiword', $filePath]);
                if ($antiwordResult->successful() && ! empty(trim($antiwordResult->output()))) {
                    return ['success' => true, 'text' => $antiwordResult->output()];
                }

                // Fallback: extract from ZIP
                return $this->extractDocxFromZip($filePath);
            }

            if (in_array($extension, ['xlsx', 'xls', 'ods'])) {
                $result = Process::timeout(60)->run([
                    'ssconvert',
                    '--export-type=Gnumeric_stf:stf_csv',
                    $filePath,
                    'fd://1',
                ]);
                if ($result->successful()) {
                    return ['success' => true, 'text' => $result->output()];
                }
            }

            if (in_array($extension, ['pptx', 'ppt', 'odp'])) {
                return $this->extractPptxFromZip($filePath);
            }

            return ['success' => false, 'error' => 'Unsupported office format'];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract DOCX from ZIP structure
     */
    protected function extractDocxFromZip(string $filePath): array
    {
        try {
            $zip = new \ZipArchive;
            if ($zip->open($filePath) !== true) {
                return ['success' => false, 'error' => 'Cannot open as ZIP'];
            }

            $content = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($content === false) {
                return ['success' => false, 'error' => 'No document.xml found'];
            }

            $text = strip_tags($content);
            $text = preg_replace('/\s+/', ' ', $text);

            return ['success' => true, 'text' => trim($text)];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract PPTX from ZIP structure
     */
    protected function extractPptxFromZip(string $filePath): array
    {
        try {
            $zip = new \ZipArchive;
            if ($zip->open($filePath) !== true) {
                return ['success' => false, 'error' => 'Cannot open as ZIP'];
            }

            $text = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/slide\d+\.xml$|content\.xml$/', $name)) {
                    $slideContent = $zip->getFromIndex($i);
                    $text .= ' '.strip_tags($slideContent);
                }
            }
            $zip->close();

            return [
                'success' => ! empty(trim($text)),
                'text' => preg_replace('/\s+/', ' ', trim($text)),
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract with Ollama Vision (fallback)
     * E01 Phase 3: Now uses AIService for resilient vision processing
     */
    protected function extractWithOllamaVision(string $filePath, string $filename, string $extension): array
    {
        try {
            // For PDFs, convert first page to image
            if ($extension === 'pdf') {
                $tempImage = storage_path('app/temp/ollama_'.uniqid().'.png');
                $result = Process::timeout(60)->run(
                    'pdftoppm -png -f 1 -l 1 -r 150 '.escapeshellarg($filePath).' '.escapeshellarg(str_replace('.png', '', $tempImage))
                );

                $imageFiles = glob(str_replace('.png', '', $tempImage).'*.png');
                if (empty($imageFiles)) {
                    return ['success' => false, 'error' => 'PDF to image failed'];
                }
                $filePath = $imageFiles[0];
            }

            $imageData = file_get_contents($filePath);
            if ($imageData === false) {
                return ['success' => false, 'error' => 'Cannot read image file'];
            }

            // Use AIService for resilient vision processing with circuit breaker + retry
            $prompt = 'Describe this image in detail. Extract ALL visible text including any numbers, codes, dates, names, addresses. This is for a personal document archive.';
            $result = $this->aiService->processImage($imageData, $prompt);

            // Cleanup temp image if created
            if ($extension === 'pdf' && isset($imageFiles)) {
                array_map('unlink', $imageFiles);
            }

            if ($result['success']) {
                return [
                    'success' => true,
                    'text' => $result['response'] ?? '',
                    'provider' => $result['provider'] ?? 'unknown',
                ];
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Vision processing failed'];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract JSON from AI output
     */
    protected function extractJsonFromOutput(string $output): ?array
    {
        // Some AI adapters wrap response text in an envelope.
        $envelope = json_decode($output, true);
        if ($envelope && isset($envelope['result'])) {
            $output = $envelope['result'];
        }

        // Try direct parse
        $json = json_decode($output, true);
        if ($json && isset($json['description'])) {
            return $json;
        }

        // Try extracting from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $output, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json) {
                return $json;
            }
        }

        // Try finding balanced JSON object
        $start = strpos($output, '{');
        if ($start !== false) {
            $depth = 0;
            $end = $start;
            for ($i = $start; $i < strlen($output); $i++) {
                if ($output[$i] === '{') {
                    $depth++;
                }
                if ($output[$i] === '}') {
                    $depth--;
                }
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
            $jsonStr = substr($output, $start, $end - $start + 1);
            $json = json_decode($jsonStr, true);
            if ($json && isset($json['description'])) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Format extraction result as beautiful Markdown with media link
     */
    protected function formatAsMarkdown(string $filename, array $result, ?string $resourceId = null): string
    {
        $md = "\n---\n\n";
        $md .= '## '.$filename."\n\n";

        // Add clickable source link if resource ID available
        if ($resourceId) {
            $mediaUrl = $this->mediaUrlService->getJoplinAttachmentUrl($resourceId, $filename);
            $md .= "**Source:** [View Original]({$mediaUrl})\n\n";
        }

        // Summary
        if (! empty($result['description'])) {
            $description = is_array($result['description']) ? json_encode($result['description']) : (string) $result['description'];
            $md .= '**Summary:** '.$description."\n\n";
        }

        // Key Information from entities
        $entities = $result['entities'] ?? [];
        if (! empty($entities) && is_array($entities)) {
            try {
                $md .= "### Key Information\n";

                if (! empty($entities['document_type'])) {
                    $docType = is_array($entities['document_type']) ? json_encode($entities['document_type']) : (string) $entities['document_type'];
                    $md .= '- **Document Type:** '.$docType."\n";
                }
                if (! empty($entities['document_date'])) {
                    $docDate = is_array($entities['document_date']) ? json_encode($entities['document_date']) : (string) $entities['document_date'];
                    $md .= '- **Document Date:** '.$docDate."\n";
                }
                if (! empty($entities['amounts']) && is_array($entities['amounts'])) {
                    $amounts = array_map(fn ($a) => is_array($a) ? (($a['amount'] ?? '').' '.($a['currency'] ?? '').' ('.($a['context'] ?? '').')') : (string) $a, $entities['amounts']);
                    $md .= '- **Amounts:** '.implode(', ', $amounts)."\n";
                }
                if (! empty($entities['names']) && is_array($entities['names'])) {
                    $names = array_map(fn ($n) => is_array($n) ? ($n['name'] ?? json_encode($n)) : (string) $n, $entities['names']);
                    $md .= '- **Names:** '.implode(', ', $names)."\n";
                }
                if (! empty($entities['addresses']) && is_array($entities['addresses'])) {
                    foreach ($entities['addresses'] as $addr) {
                        $addrValue = is_array($addr) ? ($addr['address'] ?? json_encode($addr)) : (string) $addr;
                        $md .= '- **Address:** '.$addrValue."\n";
                    }
                }
                if (! empty($entities['phone_numbers']) && is_array($entities['phone_numbers'])) {
                    $phones = array_map(fn ($p) => is_array($p) ? ($p['number'] ?? json_encode($p)) : (string) $p, $entities['phone_numbers']);
                    $md .= '- **Phone:** '.implode(', ', $phones)."\n";
                }
                if (! empty($entities['emails']) && is_array($entities['emails'])) {
                    $emails = array_map(fn ($e) => is_array($e) ? ($e['email'] ?? json_encode($e)) : (string) $e, $entities['emails']);
                    $md .= '- **Email:** '.implode(', ', $emails)."\n";
                }
                if (! empty($entities['account_numbers']) && is_array($entities['account_numbers'])) {
                    foreach ($entities['account_numbers'] as $acct) {
                        if (is_array($acct)) {
                            $acctType = $acct['type'] ?? 'unknown';
                            $acctNumber = $acct['number'] ?? json_encode($acct);
                            $md .= "- **Account ({$acctType}):** ".$acctNumber."\n";
                        } else {
                            $md .= '- **Account:** '.(string) $acct."\n";
                        }
                    }
                }
                if (! empty($entities['micr_line'])) {
                    $micrValue = is_array($entities['micr_line']) ? json_encode($entities['micr_line']) : $entities['micr_line'];
                    $md .= '- **MICR Line:** '.$micrValue."\n";
                }
                if (! empty($entities['dates']) && is_array($entities['dates'])) {
                    $dates = array_map(fn ($d) => is_array($d) ? ($d['date'] ?? json_encode($d)) : (string) $d, $entities['dates']);
                    $md .= '- **Dates:** '.implode(', ', $dates)."\n";
                }
                if (! empty($entities['key_facts']) && is_array($entities['key_facts'])) {
                    $md .= "- **Key Facts:**\n";
                    foreach ($entities['key_facts'] as $fact) {
                        $factStr = is_array($fact) ? json_encode($fact) : (string) $fact;
                        $md .= '  - '.$factStr."\n";
                    }
                }

                $md .= "\n";
            } catch (\Exception $e) {
                Log::channel('single')->warning('Entity formatting failed', [
                    'error' => $e->getMessage(),
                    'entities' => json_encode($entities),
                ]);
                $md .= "- *Entity extraction available in raw data*\n\n";
            }
        }

        // Content
        if (! empty($result['text'])) {
            $textContent = is_array($result['text']) ? json_encode($result['text']) : (string) $result['text'];
            $md .= "### Content\n";
            $md .= $this->cleanText($textContent)."\n";
        }

        $md .= "\n---\n";

        return $md;
    }

    /**
     * Generate placeholder Markdown for unsupported audio/video
     */
    protected function generatePlaceholderMarkdown(string $filename, string $extension, int $fileSize): string
    {
        $sizeFormatted = $this->formatFileSize($fileSize);

        return "\n---\n\n## ".$filename."\n\n".
               '**Type:** '.strtoupper($extension)." file\n".
               "**Size:** {$sizeFormatted}\n\n".
               "_Content extraction pending - see E17/E18 for future audio/video transcription support._\n\n---\n";
    }

    /**
     * Generate Markdown for unsupported file types
     */
    protected function generateUnsupportedMarkdown(string $filename, string $extension, int $fileSize): string
    {
        $sizeFormatted = $this->formatFileSize($fileSize);

        return "\n---\n\n## ".$filename."\n\n".
               '**Type:** '.strtoupper($extension)." file\n".
               "**Size:** {$sizeFormatted}\n\n".
               "_Binary file - content not extracted._\n\n---\n";
    }

    /**
     * Generate Markdown for extraction errors
     */
    protected function generateErrorMarkdown(string $filename, string $error): string
    {
        return "\n---\n\n## ".$filename."\n\n".
               "**Status:** Extraction failed\n".
               '**Error:** '.$error."\n\n---\n";
    }

    /**
     * Fetch attachment content from Nextcloud
     */
    protected function fetchAttachmentContent(string $resourceId): ?string
    {
        $url = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->username.
               $this->joplinPath.'/.resource/'.$resourceId;

        try {
            $response = $this->http()
                ->timeout(60)
                ->get($url);

            return $response->successful() ? $response->body() : null;

        } catch (\Exception $e) {
            Log::channel('single')->error('Failed to fetch attachment', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save content to temp file
     */
    protected function saveTempFile(string $content, string $extension): string
    {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir.'/joplin_'.uniqid().'.'.$extension;
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /**
     * Check if attachment already processed with same hash
     */
    protected function isAlreadyProcessed(string $noteId, string $resourceId, string $contentHash): bool
    {
        $sql = "SELECT id FROM joplin_attachment_index
                WHERE note_id = ? AND resource_id = ?
                AND content_hash = ? AND extraction_version = ?
                AND sync_status = 'synced'
                LIMIT 1";

        $result = DB::select($sql, [$noteId, $resourceId, $contentHash, $this->extractionVersion]);

        return ! empty($result);
    }

    /**
     * Update attachment status in index
     */
    protected function updateAttachmentStatus(string $noteId, string $resourceId, string $status, ?string $error = null): void
    {
        $sql = "INSERT INTO joplin_attachment_index (note_id, resource_id, filename, sync_status, error_log, created_at, updated_at)
                VALUES (?, ?, '', ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE sync_status = VALUES(sync_status), error_log = VALUES(error_log), updated_at = NOW()";

        DB::statement($sql, [$noteId, $resourceId, $status, $error]);
    }

    /**
     * Save attachment to index table with media URL
     */
    protected function saveAttachmentIndex(
        string $noteId,
        string $resourceId,
        string $filename,
        string $extension,
        int $fileSize,
        string $contentHash,
        string $method,
        array $entities
    ): void {
        // Generate Nextcloud media URL
        $mediaUrl = $this->mediaUrlService->getJoplinAttachmentUrl($resourceId, $filename);

        $sql = "INSERT INTO joplin_attachment_index
                (note_id, resource_id, filename, extension, file_size, content_hash,
                 extraction_version, extraction_method, sync_status, last_processed_at,
                 extracted_entities, media_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced', NOW(), ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    filename = VALUES(filename),
                    extension = VALUES(extension),
                    file_size = VALUES(file_size),
                    content_hash = VALUES(content_hash),
                    extraction_version = VALUES(extraction_version),
                    extraction_method = VALUES(extraction_method),
                    sync_status = 'synced',
                    last_processed_at = NOW(),
                    extracted_entities = VALUES(extracted_entities),
                    media_url = VALUES(media_url),
                    error_log = NULL,
                    updated_at = NOW()";

        DB::statement($sql, [
            $noteId,
            $resourceId,
            $filename,
            $extension,
            $fileSize,
            $contentHash,
            $this->extractionVersion,
            $method,
            json_encode($entities),
            $mediaUrl,
        ]);
    }

    /**
     * Clean text for output
     */
    protected function cleanText(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Format file size for display
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get processing statistics
     */
    public function getStats(): array
    {
        $stats = DB::select('
            SELECT
                sync_status,
                COUNT(*) as count,
                extraction_version
            FROM joplin_attachment_index
            GROUP BY sync_status, extraction_version
        ');

        $byStatus = [];
        $byVersion = [];
        foreach ($stats as $row) {
            $byStatus[$row->sync_status] = ($byStatus[$row->sync_status] ?? 0) + $row->count;
            $byVersion[$row->extraction_version] = ($byVersion[$row->extraction_version] ?? 0) + $row->count;
        }

        $recentErrors = DB::select("
            SELECT filename, error_log, updated_at
            FROM joplin_attachment_index
            WHERE sync_status = 'error'
            ORDER BY updated_at DESC
            LIMIT 10
        ");

        return [
            'by_status' => $byStatus,
            'by_version' => $byVersion,
            'recent_errors' => $recentErrors,
            'current_version' => $this->extractionVersion,
        ];
    }

    /**
     * Get pending attachments for reprocessing
     */
    public function getPendingForReprocess(int $limit = 50, bool $force = false): array
    {
        // rag_documents is on PostgreSQL — cannot JOIN cross-DB
        // Fetch attachment candidates from MySQL first, then enrich with note content from pgsql
        if ($force) {
            $attachments = DB::select('
                SELECT * FROM joplin_attachment_index ORDER BY updated_at ASC LIMIT ?
            ', [$limit]);
        } else {
            $attachments = DB::select("
                SELECT * FROM joplin_attachment_index
                WHERE extraction_version < ? OR sync_status = 'error'
                ORDER BY updated_at ASC LIMIT ?
            ", [$this->extractionVersion, $limit]);
        }

        if (empty($attachments)) {
            return [];
        }

        // Fetch note content from PostgreSQL for matched note_ids
        $noteIds = array_unique(array_column($attachments, 'note_id'));
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $ragDocs = DB::connection('pgsql_rag')->select("
            SELECT source_id, content FROM rag_documents
            WHERE designation = 'joplin_note' AND source_id IN ({$placeholders})
        ", $noteIds);
        $noteContentMap = [];
        foreach ($ragDocs as $rd) {
            $noteContentMap[$rd->source_id] = $rd->content;
        }

        // Enrich attachments with note_content
        foreach ($attachments as $att) {
            $att->note_content = $noteContentMap[$att->note_id] ?? null;
        }

        return $attachments;
    }

    /**
     * Cleanup old joplin_attachment RAG records
     */
    public function cleanupOldRagRecords(): int
    {
        $sql = "DELETE FROM rag_documents WHERE designation = 'joplin_attachment'";

        return DB::connection('pgsql_rag')->delete($sql);
    }
}
