<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Tika Extraction Service
 *
 * Provides document content extraction using Apache Tika Server.
 * Tika supports 1000+ file formats including:
 * - Documents: PDF, DOCX, DOC, RTF, ODT, XLSX, XLS, PPTX, PPT
 * - Media: MP3, MP4, WAV, FLAC (metadata extraction)
 * - Images: JPEG, PNG, TIFF, GIF (EXIF + OCR if configured)
 * - Archives: ZIP, TAR, GZIP (lists contents)
 * - Code: Various source files
 *
 * Tika runs as a local server on port 9998.
 *
 * @see https://tika.apache.org/
 */
class TikaExtractionService
{
    private string $tikaUrl;
    private int $timeout;
    private int $maxFileSize;

    public function __construct()
    {
        $this->tikaUrl = config('services.tika.url', 'http://127.0.0.1:9998');
        $this->timeout = config('services.tika.timeout', 120); // seconds
        $this->maxFileSize = config('services.tika.max_file_size', 100 * 1024 * 1024); // 100MB
    }

    /**
     * Check if Tika server is available
     *
     * @return array Health status
     */
    public function healthCheck(): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(5)->get("{$this->tikaUrl}/version");

            if ($response->successful()) {
                return [
                    'available' => true,
                    'version' => trim($response->body()),
                    'url' => $this->tikaUrl,
                ];
            }

            return [
                'available' => false,
                'error' => 'Tika returned non-success status',
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
                'url' => $this->tikaUrl,
            ];
        }
    }

    /**
     * Extract text content from a file
     *
     * @param string $filePath Path to file (local or can be fetched)
     * @param array $options Extraction options:
     *   - include_metadata: bool (default true)
     *   - ocr_strategy: string (no_ocr, ocr_only, ocr_and_text)
     * @return array Extraction result with text and metadata
     */
    public function extractFromFile(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => "File not found: {$filePath}",
            ];
        }

        $fileSize = filesize($filePath);
        if ($fileSize > $this->maxFileSize) {
            return [
                'success' => false,
                'error' => "File too large: " . $this->formatBytes($fileSize) . " (max: " . $this->formatBytes($this->maxFileSize) . ")",
            ];
        }

        $fileContent = file_get_contents($filePath);
        $mimeType = mime_content_type($filePath);
        $filename = basename($filePath);

        return $this->extractFromContent($fileContent, $mimeType, $filename, $options);
    }

    /**
     * Extract text content from raw file content
     *
     * @param string $content Raw file content
     * @param string|null $mimeType MIME type hint
     * @param string|null $filename Filename hint
     * @param array $options Extraction options
     * @return array Extraction result
     */
    public function extractFromContent(string $content, ?string $mimeType = null, ?string $filename = null, array $options = []): array
    {
        $includeMetadata = $options['include_metadata'] ?? true;
        $ocrStrategy = $options['ocr_strategy'] ?? 'ocr_and_text';

        $startTime = microtime(true);

        try {
            // Build headers
            $headers = [
                'Accept' => 'text/plain',
                'X-Tika-OCRskipOcr' => $ocrStrategy === 'no_ocr' ? 'true' : 'false',
            ];

            if ($mimeType) {
                $headers['Content-Type'] = $mimeType;
            }

            if ($filename) {
                $headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
            }

            // Extract text
            $textResponse = Http::connectTimeout(5)->timeout($this->timeout)
                ->withHeaders($headers)
                ->withBody($content, $mimeType ?? 'application/octet-stream')
                ->put("{$this->tikaUrl}/tika");

            if (!$textResponse->successful()) {
                return [
                    'success' => false,
                    'error' => 'Tika text extraction failed',
                    'status_code' => $textResponse->status(),
                    'body' => substr($textResponse->body(), 0, 500),
                ];
            }

            $extractedText = trim($textResponse->body());

            // Extract metadata if requested
            $metadata = [];
            if ($includeMetadata) {
                $metaResponse = Http::connectTimeout(5)->timeout(30)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => $mimeType ?? 'application/octet-stream',
                    ])
                    ->withBody($content, $mimeType ?? 'application/octet-stream')
                    ->put("{$this->tikaUrl}/meta");

                if ($metaResponse->successful()) {
                    $metadata = $metaResponse->json() ?? [];
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'text' => $extractedText,
                'text_length' => strlen($extractedText),
                'word_count' => str_word_count($extractedText),
                'metadata' => $this->normalizeMetadata($metadata),
                'mime_type' => $mimeType,
                'filename' => $filename,
                'duration_ms' => $duration,
                'extractor' => 'tika',
            ];

        } catch (\Exception $e) {
            Log::error('TikaExtraction: Extraction failed', [
                'filename' => $filename,
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'extractor' => 'tika',
            ];
        }
    }

    /**
     * Extract from URL (Tika fetches the content)
     *
     * @param string $url URL to fetch and extract
     * @param array $options Extraction options
     * @return array Extraction result
     */
    public function extractFromUrl(string $url, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            $headers = [
                'Accept' => 'text/plain',
            ];

            $response = Http::connectTimeout(5)->timeout($this->timeout)
                ->withHeaders($headers)
                ->get("{$this->tikaUrl}/tika", ['fetch' => $url]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Tika URL extraction failed',
                    'status_code' => $response->status(),
                ];
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'text' => trim($response->body()),
                'text_length' => strlen($response->body()),
                'word_count' => str_word_count($response->body()),
                'url' => $url,
                'duration_ms' => $duration,
                'extractor' => 'tika',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => $url,
                'extractor' => 'tika',
            ];
        }
    }

    /**
     * Detect MIME type of content
     *
     * @param string $content File content
     * @param string|null $filename Filename hint
     * @return array Detection result
     */
    public function detectMimeType(string $content, ?string $filename = null): array
    {
        try {
            $headers = ['Accept' => 'text/plain'];
            if ($filename) {
                $headers['Content-Disposition'] = "attachment; filename=\"{$filename}\"";
            }

            $response = Http::connectTimeout(5)->timeout(10)
                ->withHeaders($headers)
                ->withBody($content, 'application/octet-stream')
                ->put("{$this->tikaUrl}/detect/stream");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'mime_type' => trim($response->body()),
                ];
            }

            return [
                'success' => false,
                'error' => 'Detection failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get supported MIME types (cached)
     *
     * @return array List of supported types
     */
    public function getSupportedTypes(): array
    {
        return Cache::remember('tika_supported_types', 3600, function () {
            try {
                $response = Http::connectTimeout(5)->timeout(10)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get("{$this->tikaUrl}/mime-types");

                if ($response->successful()) {
                    return $response->json() ?? [];
                }
            } catch (\Exception $e) {
                Log::warning('TikaExtraction: Failed to get supported types', ['error' => $e->getMessage()]);
            }

            return [];
        });
    }

    /**
     * Check if a MIME type is supported by Tika
     *
     * @param string $mimeType MIME type to check
     * @return bool
     */
    public function isSupported(string $mimeType): bool
    {
        // Common types we know are supported
        $knownSupported = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'text/plain',
            'text/html',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/tiff',
            'image/gif',
            'audio/mpeg',
            'audio/wav',
            'video/mp4',
            'application/zip',
        ];

        if (in_array($mimeType, $knownSupported)) {
            return true;
        }

        // Check against full list if available
        $types = $this->getSupportedTypes();
        return isset($types[$mimeType]);
    }

    /**
     * Extract and analyze document structure
     *
     * Returns structured analysis including:
     * - Document type classification
     * - Key entities (dates, amounts, names, etc.)
     * - Summary of content
     *
     * @param string $filePath Path to file
     * @return array Structured analysis
     */
    public function analyzeDocument(string $filePath): array
    {
        $extraction = $this->extractFromFile($filePath, ['include_metadata' => true]);

        if (!$extraction['success']) {
            return $extraction;
        }

        $analysis = [
            'success' => true,
            'text' => $extraction['text'],
            'text_length' => $extraction['text_length'],
            'word_count' => $extraction['word_count'],
            'metadata' => $extraction['metadata'],
            'extractor' => 'tika',
        ];

        // Extract entities from text
        $analysis['entities'] = $this->extractEntities($extraction['text']);

        // Classify document type based on content and metadata
        $analysis['document_classification'] = $this->classifyDocument(
            $extraction['text'],
            $extraction['metadata'],
            basename($filePath)
        );

        return $analysis;
    }

    /**
     * Extract entities from text (dates, amounts, etc.)
     *
     * @param string $text Extracted text
     * @return array Found entities
     */
    private function extractEntities(string $text): array
    {
        $entities = [];

        // Extract dates (various formats)
        preg_match_all('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $dates);
        preg_match_all('/\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4})\b/i', $text, $longDates);
        $entities['dates'] = array_unique(array_merge($dates[1] ?? [], $longDates[1] ?? []));

        // Extract monetary amounts
        preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $text, $amounts);
        $entities['amounts'] = array_unique($amounts[0] ?? []);

        // Extract email addresses
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $emails);
        $entities['emails'] = array_unique($emails[0] ?? []);

        // Extract phone numbers
        preg_match_all('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $text, $phones);
        $entities['phone_numbers'] = array_unique($phones[0] ?? []);

        // Extract account/reference numbers
        preg_match_all('/\b(?:Account|Acct|Ref|Reference|Invoice|Order)[\s#:]*([A-Z0-9-]{5,20})\b/i', $text, $refs);
        $entities['reference_numbers'] = array_unique($refs[1] ?? []);

        // Extract SSN patterns (redacted for security)
        preg_match_all('/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/', $text, $ssns);
        $entities['has_ssn_pattern'] = !empty($ssns[0]);

        return $entities;
    }

    /**
     * Classify document type based on content analysis
     *
     * @param string $text Extracted text
     * @param array $metadata Document metadata
     * @param string $filename Original filename
     * @return array Classification result
     */
    private function classifyDocument(string $text, array $metadata, string $filename): array
    {
        $textLower = strtolower($text);
        $filenameLower = strtolower($filename);

        $classification = [
            'type' => 'unknown',
            'confidence' => 0,
            'indicators' => [],
        ];

        // Tax document indicators
        $taxIndicators = ['w-2', 'w2', '1099', 'tax return', 'irs', 'federal income', 'form 1040', 'schedule c', 'taxable income'];
        foreach ($taxIndicators as $indicator) {
            if (str_contains($textLower, $indicator) || str_contains($filenameLower, $indicator)) {
                $classification['type'] = 'tax_document';
                $classification['indicators'][] = $indicator;
            }
        }

        // Financial/Bank statement indicators
        $bankIndicators = ['account balance', 'statement period', 'routing number', 'checking', 'savings', 'transaction history', 'direct deposit'];
        foreach ($bankIndicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                if ($classification['type'] === 'unknown') {
                    $classification['type'] = 'bank_statement';
                }
                $classification['indicators'][] = $indicator;
            }
        }

        // Invoice indicators
        $invoiceIndicators = ['invoice', 'bill to', 'amount due', 'payment terms', 'due date', 'subtotal', 'total due'];
        foreach ($invoiceIndicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                if ($classification['type'] === 'unknown') {
                    $classification['type'] = 'invoice';
                }
                $classification['indicators'][] = $indicator;
            }
        }

        // Medical document indicators
        $medicalIndicators = ['patient', 'diagnosis', 'prescription', 'medical record', 'physician', 'healthcare', 'treatment', 'lab results'];
        foreach ($medicalIndicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                if ($classification['type'] === 'unknown') {
                    $classification['type'] = 'medical_document';
                }
                $classification['indicators'][] = $indicator;
            }
        }

        // Legal document indicators
        $legalIndicators = ['hereby', 'whereas', 'agreement', 'contract', 'party', 'witness', 'notary', 'jurisdiction'];
        foreach ($legalIndicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                if ($classification['type'] === 'unknown') {
                    $classification['type'] = 'legal_document';
                }
                $classification['indicators'][] = $indicator;
            }
        }

        // Receipt indicators
        $receiptIndicators = ['receipt', 'thank you for your purchase', 'subtotal', 'change', 'card ending'];
        foreach ($receiptIndicators as $indicator) {
            if (str_contains($textLower, $indicator)) {
                if ($classification['type'] === 'unknown') {
                    $classification['type'] = 'receipt';
                }
                $classification['indicators'][] = $indicator;
            }
        }

        // Calculate confidence based on indicators found
        $classification['confidence'] = min(100, count($classification['indicators']) * 20);
        $classification['indicators'] = array_unique($classification['indicators']);

        return $classification;
    }

    /**
     * Normalize metadata keys to consistent format
     *
     * @param array $metadata Raw metadata from Tika
     * @return array Normalized metadata
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        // Map common Tika metadata keys to normalized names
        $keyMap = [
            'dc:title' => 'title',
            'dc:creator' => 'author',
            'dc:subject' => 'subject',
            'dc:description' => 'description',
            'dcterms:created' => 'created_date',
            'dcterms:modified' => 'modified_date',
            'meta:page-count' => 'page_count',
            'meta:word-count' => 'word_count',
            'meta:character-count' => 'character_count',
            'Content-Type' => 'content_type',
            'pdf:PDFVersion' => 'pdf_version',
            'xmpTPg:NPages' => 'page_count',
            'Last-Modified' => 'modified_date',
            'Creation-Date' => 'created_date',
            'Author' => 'author',
            'title' => 'title',
            'creator' => 'author',
        ];

        foreach ($metadata as $key => $value) {
            $normalizedKey = $keyMap[$key] ?? $this->snakeCase($key);

            // Skip empty values
            if (empty($value) || $value === 'null') {
                continue;
            }

            // Handle arrays (take first value)
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if ($value !== null) {
                $normalized[$normalizedKey] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Convert string to snake_case
     */
    private function snakeCase(string $str): string
    {
        $str = preg_replace('/[^a-zA-Z0-9]/', '_', $str);
        $str = preg_replace('/([a-z])([A-Z])/', '$1_$2', $str);
        return strtolower(trim($str, '_'));
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
