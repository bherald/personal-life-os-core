<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * PhotoDateExtractionService
 *
 * Comprehensive service for extracting photo dates from multiple sources:
 * 1. EXIF metadata (highest confidence)
 * 2. Filename patterns (e.g., IMG_20150325_143022.jpg)
 * 3. Path patterns (e.g., /Photos/2015/Christmas/)
 * 4. AI visual analysis (llava - estimate decade/era)
 * 5. File modified date (fallback)
 *
 * Supports EXIF write-back to physical files using exiftool
 */
class PhotoDateExtractionService
{
    private string $basePath;

    private string $libraryRoot;

    private string $exiftoolPath = '/usr/bin/exiftool';

    public function __construct()
    {
        $dataPath = trim((string) config('services.nextcloud.data_path', ''));
        $this->libraryRoot = '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
        $this->basePath = $dataPath === '' ? '' : rtrim($dataPath, '/').$this->libraryRoot;
    }

    // Path/filename keywords that indicate a scanned physical photograph.
    // When any of these appear in the file path, the EXIF date is the scan date,
    // not the original photo date — so AI visual estimation is needed instead.
    public const SCAN_PATH_KEYWORDS = [
        'slides', 'slide',
        'negatives', 'negative', 'negs',
        'scans', 'scanned', 'scan_', '_scan',
        'digitized', 'digitised',
        '35mm', 'film',
        'prints', 'print_',
        'kodachrome', 'ektachrome', 'velvia',
        'polaroid', 'tintype', 'daguerreotype',
    ];

    // Common filename date patterns
    private array $filenamePatterns = [
        // IMG_20150325_143022.jpg (Android/common)
        '/IMG_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/',
        // 20150325_143022.jpg
        '/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/',
        // 2015-03-25_14-30-22.jpg
        '/(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/',
        // 2015-03-25 14.30.22.jpg
        '/(\d{4})-(\d{2})-(\d{2})\s+(\d{2})\.(\d{2})\.(\d{2})/',
        // IMG-20150325-WA0001.jpg (WhatsApp)
        '/IMG-(\d{4})(\d{2})(\d{2})-WA\d+/',
        // VID_20150325_143022.mp4
        '/VID_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/',
        // Screenshot_20150325-143022.png
        '/Screenshot_(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/',
        // DSC_0001_20150325.jpg (some cameras)
        '/DSC_\d+_(\d{4})(\d{2})(\d{2})/',
        // Photo 2015-03-25.jpg
        '/Photo\s+(\d{4})-(\d{2})-(\d{2})/',
        // 2015-03-25.jpg (date only)
        '/^(\d{4})-(\d{2})-(\d{2})[._\s]/',
        // 20150325.jpg (date only compact)
        '/^(\d{4})(\d{2})(\d{2})[._\s]/',
    ];

    // Path date patterns
    private array $pathPatterns = [
        // /2015/03/25/ or /2015/March/
        '/\/(\d{4})\/(\d{2})\/(\d{2})\//',
        '/\/(\d{4})\/(\d{2})\//',
        '/\/(\d{4})\//',
        // /2015-03-25/
        '/\/(\d{4})-(\d{2})-(\d{2})\//',
        '/\/(\d{4})-(\d{2})\//',
        // /Jan-2001/ or /Jan_2001/ or /Jan 2001/ — abbreviated month name with any separator
        '/\/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[_\-\s]+(\d{4})\//i',
        // /2001-Jan/ or /2001_Jan/ — year-first abbreviated
        '/\/(\d{4})[_\-\s]+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\//i',
        // /March 2015/ or /March_2015/ — full month name with space/underscore
        '/\/(January|February|March|April|May|June|July|August|September|October|November|December)[_\s]+(\d{4})\//i',
        // /2015 Christmas/
        '/\/(\d{4})\s+\w+\//',
        // /Christmas 2015/
        '/\/\w+\s+(\d{4})\//',
    ];

    private array $monthNames = [
        // Full names
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        // Abbreviated (Jan-2001, Feb_1985, etc.)
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Extract date from all available sources for a file
     */
    public function extractDate(int $fileId, string $localPath, string $filename, string $currentPath): array
    {
        $result = [
            'success' => false,
            'date_taken' => null,
            'source' => null,
            'confidence' => 0.0,
            'reasoning' => null,
            'sources_checked' => [],
        ];

        // 1. Try EXIF first (highest confidence)
        $exifResult = $this->extractFromExif($localPath);
        $result['sources_checked']['exif'] = $exifResult;

        if (($exifResult['status'] ?? null) === 'held') {
            return ['success' => false, 'status' => 'held', 'error' => $exifResult['error'] ?? 'Operator-confirmed capture date needs review.',
                'sources_checked' => $result['sources_checked']];
        }

        if ($exifResult['success']) {
            return [
                'success' => true,
                'date_taken' => $exifResult['date'],
                'precision' => $exifResult['precision'] ?? 'exact',
                'time_known' => $exifResult['time_known'] ?? true,
                'requires_operator_review' => (bool) ($exifResult['requires_operator_review'] ?? false),
                'provenance_claim' => $exifResult['provenance_claim'] ?? null,
                'source' => $exifResult['source'],
                'confidence' => $exifResult['confidence'],
                'reasoning' => $exifResult['reasoning'] ?? 'Date extracted from EXIF metadata',
                'sources_checked' => $result['sources_checked'],
            ];
        }

        // 2. Try filename patterns
        $filenameResult = $this->extractFromFilename($filename);
        $result['sources_checked']['filename'] = $filenameResult;

        if ($filenameResult['success']) {
            return [
                'success' => true,
                'date_taken' => $filenameResult['date'],
                'precision' => $filenameResult['precision'],
                'source' => 'filename_extracted',
                'confidence' => $filenameResult['confidence'],
                'reasoning' => "Date extracted from filename pattern: {$filenameResult['pattern_matched']}",
                'sources_checked' => $result['sources_checked'],
            ];
        }

        // 3. Try path patterns
        $pathResult = $this->extractFromPath($currentPath);
        $result['sources_checked']['path'] = $pathResult;

        if ($pathResult['success']) {
            return [
                'success' => true,
                'date_taken' => $pathResult['date'],
                'precision' => $pathResult['precision'],
                'source' => 'path_extracted',
                'confidence' => $pathResult['confidence'],
                'reasoning' => "Date extracted from folder path: {$pathResult['matched_segment']}",
                'sources_checked' => $result['sources_checked'],
            ];
        }

        // 4. File modified date as final fallback (low confidence)
        if (file_exists($localPath)) {
            $mtime = filemtime($localPath);
            if ($mtime) {
                $result['sources_checked']['file_modified'] = [
                    'success' => true,
                    'date' => date('Y-m-d H:i:s', $mtime),
                ];

                return [
                    'success' => true,
                    'date_taken' => date('Y-m-d H:i:s', $mtime),
                    'precision' => 'exact',
                    'source' => 'file_modified',
                    'confidence' => 0.20, // Low confidence - file could have been copied
                    'reasoning' => 'Using file modification date as fallback (low confidence)',
                    'sources_checked' => $result['sources_checked'],
                ];
            }
        }

        return $result;
    }

    /**
     * Extract date from EXIF metadata using exiftool
     */
    public function extractFromExif(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        // Check only the explicit marker written by the verified operator-date path.
        // Unmarked XMP/IPTC dates are not promoted because AI or scanner metadata may use them.
        try {
            $markerResult = Process::timeout(15)->run([
                $this->exiftoolPath, '-json', '-G1', '-a', '-s',
                '-XMP-dc:Source', '-XMP-photoshop:DateCreated', '-IPTC:DateCreated', $filePath,
            ]);
            $markerRows = $markerResult->successful() ? json_decode($markerResult->output(), true) : null;
            if (! is_array($markerRows) || count($markerRows) !== 1 || ! is_array($markerRows[0] ?? null)
                || ($markerRows[0]['SourceFile'] ?? null) !== $filePath
                || isset($markerRows[0]['Error']) || isset($markerRows[0]['Warning'])) {
                return ['success' => false, 'status' => 'held', 'error' => 'Date provenance could not be checked safely.'];
            }
            $operatorDate = $this->operatorDateFromExifMetadata($markerRows[0]);
            if ($operatorDate !== null) {
                return $operatorDate;
            }

            $result = Process::timeout(15)->run([
                $this->exiftoolPath, '-DateTimeOriginal', '-CreateDate', '-ModifyDate', '-j', $filePath,
            ]);

            if ($result->failed()) {
                Log::debug('PhotoDateExtractionService: exiftool read failed', [
                    'path' => $filePath,
                    'exitCode' => $result->exitCode(),
                    'error' => mb_substr($result->errorOutput(), 0, 200),
                ]);

                return ['success' => false, 'error' => 'exiftool failed'];
            }

            $output = $result->output();
        } catch (\Exception $e) {
            Log::warning('PhotoDateExtractionService: exiftool read exception', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'exiftool failed: '.$e->getMessage()];
        }

        if (! $output) {
            return ['success' => false, 'error' => 'exiftool returned empty output'];
        }

        $data = json_decode($output, true);
        if (! $data || empty($data[0])) {
            return ['success' => false, 'error' => 'No EXIF data'];
        }

        $exif = $data[0];

        // Priority order: DateTimeOriginal > CreateDate > ModifyDate
        $dateFields = [
            'DateTimeOriginal' => ['source' => 'exif_original', 'confidence' => 0.98],
            'CreateDate' => ['source' => 'exif_digitized', 'confidence' => 0.95],
            'ModifyDate' => ['source' => 'exif_modified', 'confidence' => 0.70],
        ];

        foreach ($dateFields as $field => $meta) {
            if (! empty($exif[$field])) {
                $parsed = $this->parseExifDate($exif[$field]);
                if ($parsed) {
                    return [
                        'success' => true,
                        'date' => $parsed,
                        'source' => $meta['source'],
                        'confidence' => $meta['confidence'],
                        'raw_value' => $exif[$field],
                    ];
                }
            }
        }

        return ['success' => false, 'error' => 'No valid date fields in EXIF'];
    }

    /** Pure provenance classification shared by bounded readers; a marker is not current approval. */
    public function operatorDateFromExifMetadata(array $metadata): ?array
    {
        $marker = $metadata['XMP-dc:Source'] ?? null;
        if ($marker === 'plos:operator-confirmed-date-only') {
            $xmpDate = $this->parseOperatorDateOnly($metadata['XMP-photoshop:DateCreated'] ?? null);
            $iptcDate = $this->parseOperatorDateOnly($metadata['IPTC:DateCreated'] ?? null);
            if ($xmpDate === null || $iptcDate === null || $xmpDate !== $iptcDate) {
                return ['success' => false, 'status' => 'held', 'error' => 'Operator-confirmed date tags are missing or conflict.'];
            }

            return ['success' => true, 'date' => $xmpDate.' 00:00:00', 'precision' => 'exact',
                'time_known' => false, 'requires_operator_review' => true,
                'provenance_claim' => 'operator_confirmed_date_only_marker', 'source' => 'exif_original', 'confidence' => 0.5,
                'reasoning' => 'Date-only tags carry the PLOS operator-workflow marker; it is a provenance claim, not a current approval receipt. Time is unknown.'];
        }

        return null;
    }

    /** Parse only the full date forms emitted by the verified date-only writer. */
    private function parseOperatorDateOnly(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4})[:\-](\d{2})[:\-](\d{2})\z/', trim($value), $matches)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    /**
     * Parse EXIF date format to standard datetime
     */
    public function parseExifDate(mixed $exifDate): ?string
    {
        if (! is_string($exifDate)) {
            return null;
        }
        $exifDate = trim($exifDate);
        // Preserve the recorded wall clock, as this service already does; do not infer scan time.
        if (! preg_match('/\A(\d{4})[:-](\d{2})[:-](\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)?\z/', $exifDate, $parts)) {
            return null;
        }
        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        [$hour, $minute, $second] = [(int) ($parts[4] ?? 0), (int) ($parts[5] ?? 0), (int) ($parts[6] ?? 0)];
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59
            || $year < 1800 || $year > now()->addYear()->year) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Extract date from filename using common patterns
     */
    public function extractFromFilename(string $filename): array
    {
        foreach ($this->filenamePatterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                $date = $this->buildDateFromMatches($matches);
                if ($date) {
                    return [
                        'success' => true,
                        'date' => $date,
                        'precision' => $this->datePrecisionFromMatches($matches),
                        'confidence' => 0.85, // High confidence for structured filenames
                        'pattern_matched' => $pattern,
                    ];
                }
            }
        }

        return ['success' => false, 'error' => 'No filename pattern matched'];
    }

    /**
     * Extract date from folder path
     */
    public function extractFromPath(string $path): array
    {
        foreach ($this->pathPatterns as $pattern) {
            if (preg_match($pattern, $path, $matches)) {
                $date = $this->buildDateFromPathMatches($matches, $pattern);
                if ($date) {
                    // Find the matched segment for logging
                    $matchedSegment = $matches[0] ?? '';

                    return [
                        'success' => true,
                        'date' => $date,
                        'precision' => $this->datePrecisionFromPathMatches($matches),
                        'confidence' => $this->getPathConfidence($matches),
                        'matched_segment' => $matchedSegment,
                    ];
                }
            }
        }

        return ['success' => false, 'error' => 'No path pattern matched'];
    }

    /**
     * Build datetime from regex matches
     */
    private function buildDateFromMatches(array $matches): ?string
    {
        try {
            // Determine what was captured
            $year = isset($matches[1]) ? (int) $matches[1] : null;
            $month = isset($matches[2]) ? (int) $matches[2] : 1;
            $day = isset($matches[3]) ? (int) $matches[3] : 1;
            $hour = isset($matches[4]) ? (int) $matches[4] : 12;
            $minute = isset($matches[5]) ? (int) $matches[5] : 0;
            $second = isset($matches[6]) ? (int) $matches[6] : 0;

            if (! $year || $year < 1900 || $year > 2030) {
                return null;
            }
            if ($month < 1 || $month > 12) {
                return null;
            }
            if ($day < 1 || $day > 31) {
                return null;
            }

            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        } catch (\Exception $e) {
            Log::debug('PhotoDateExtractionService: filename date construction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Preserve the precision actually present in a filename date match.
     */
    private function datePrecisionFromMatches(array $matches): string
    {
        return ! empty($matches[3]) ? 'exact' : (! empty($matches[2]) ? 'month' : 'year');
    }

    /**
     * Build date from path regex matches
     */
    private function buildDateFromPathMatches(array $matches, string $pattern): ?string
    {
        try {
            $m1 = $matches[1] ?? '';
            $m2 = $matches[2] ?? null;

            // If either captured group is a month name (full or abbreviated), resolve it.
            // Handles: /March 2015/, /Mar-2015/, /Jan_2001/, /2001-Jan/, etc.
            $m1IsMonth = isset($this->monthNames[strtolower($m1)]);
            $m2IsMonth = $m2 !== null && isset($this->monthNames[strtolower($m2)]);

            if ($m1IsMonth) {
                // Month-first: matches[1]=month, matches[2]=year
                $month = $this->monthNames[strtolower($m1)];
                $year = (int) ($m2 ?? 0);
                if ($year < 1900 || $year > 2030) {
                    return null;
                }

                return sprintf('%04d-%02d-01 12:00:00', $year, $month);
            }

            if ($m2IsMonth) {
                // Year-first: matches[1]=year, matches[2]=month
                $year = (int) $m1;
                $month = $this->monthNames[strtolower($m2)];
                if ($year < 1900 || $year > 2030) {
                    return null;
                }

                return sprintf('%04d-%02d-01 12:00:00', $year, $month);
            }

            $year = isset($matches[1]) ? (int) $matches[1] : null;
            $month = isset($matches[2]) ? (int) $matches[2] : 6; // Default to mid-year
            $day = isset($matches[3]) ? (int) $matches[3] : 15; // Default to mid-month

            if (! $year || $year < 1900 || $year > 2030) {
                return null;
            }

            // Validate month/day if they were captured
            if (count($matches) > 2 && ($month < 1 || $month > 12)) {
                return null;
            }
            if (count($matches) > 3 && ($day < 1 || $day > 31)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day);
        } catch (\Exception $e) {
            Log::debug('PhotoDateExtractionService: path date construction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Preserve the calendar precision actually present in a path match.
     */
    private function datePrecisionFromPathMatches(array $matches): string
    {
        $m1 = strtolower((string) ($matches[1] ?? ''));
        $m2 = strtolower((string) ($matches[2] ?? ''));
        $hasNamedMonth = isset($this->monthNames[$m1]) || isset($this->monthNames[$m2]);
        if ($hasNamedMonth || ctype_digit($m2)) {
            return ! empty($matches[3]) ? 'exact' : 'month';
        }

        return ! empty($matches[3]) ? 'exact' : 'year';
    }

    /**
     * Get confidence score based on path specificity
     */
    private function getPathConfidence(array $matches): float
    {
        $parts = count($matches) - 1; // Exclude full match

        return match ($parts) {
            3 => 0.75, // Year/month/day
            2 => 0.60, // Year/month
            1 => 0.45, // Year only
            default => 0.40,
        };
    }

    /**
     * Use AI to estimate date from visual content
     * Uses AIService->processImage() which handles:
     * - Ollama llava first (local GPU, single instance)
     * - Falls back according to AIService provider policy
     */
    public function estimateDateWithAI(
        string $filePath,
        AIService $aiService,
        ?string $filename = null,
        ?string $directoryContext = null
    ): array {
        $context = $this->dateEstimationContext($filePath, $filename, $directoryContext);
        $prompt = <<<PROMPT
Analyze this photograph and estimate when it was taken. Look for visual clues:

1. Technology visible (phones, computers, TVs, cars)
2. Fashion and hairstyles
3. Photo quality and format characteristics (black & white, sepia, color saturation)
4. Architecture and signage styles
5. Any visible dates, calendars, newspapers
6. The file and folder names below, when they contain credible event or date clues

File context: {$context}
Treat scanner/import dates and generic sequence numbers as non-evidence. In the reasoning, distinguish visual clues from filename or folder clues.

Provide your estimate in this exact format:
ESTIMATED_YEAR: [4-digit year or range like "1995-2000"]
CONFIDENCE: [low/medium/high]
REASONING: [Brief explanation of visual clues used]

If you cannot make a reasonable estimate, respond with:
ESTIMATED_YEAR: unknown
CONFIDENCE: none
REASONING: [Why estimation is not possible]
PROMPT;

        return $this->estimateWithVisionPipeline($filePath, $prompt, $aiService);
    }

    /**
     * Estimate a date using the configured vision pipeline.
     */
    private function estimateWithVisionPipeline(string $filePath, string $prompt, AIService $aiService): array
    {
        try {
            if (! file_exists($filePath)) {
                return ['success' => false, 'error' => 'File not found'];
            }

            // Read image content
            $imageContent = file_get_contents($filePath);
            if ($imageContent === false) {
                return ['success' => false, 'error' => 'Could not read file'];
            }

            // Set suppressAlert=true since we're in a batch process with fallback handling
            $result = $aiService->processImage($imageContent, $prompt, ['suppressAlert' => true]);

            if (! $result['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'Vision failed', 'provider' => $result['provider'] ?? 'unknown'];
            }

            // processImage returns 'response' not 'text'
            $responseText = $result['response'] ?? '';
            $provider = $result['provider'] ?? 'unknown';

            return $this->parseAIResponse($responseText, $provider);

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'provider' => 'exception'];
        }
    }

    /**
     * Parse AI response for date estimation
     */
    private function parseAIResponse(string $response, string $provider): array
    {
        if (preg_match('/ESTIMATED_YEAR:\s*(\d{4}(?:-\d{4})?|unknown)/i', $response, $yearMatch)) {
            $yearStr = $yearMatch[1];

            if ($yearStr === 'unknown') {
                return ['success' => false, 'error' => 'AI could not estimate date', 'provider' => $provider];
            }

            $year = null;
            $estimateLabel = null;

            // The database review contract currently stores one catalog date
            // and a precision label, so keep the earliest year as a stable
            // year-only sort value and preserve the full interval in evidence.
            if (preg_match('/(\d{4})-(\d{4})/', $yearStr, $rangeMatch)) {
                $startYear = (int) $rangeMatch[1];
                $endYear = (int) $rangeMatch[2];
                if ($startYear > $endYear) {
                    return ['success' => false, 'error' => 'AI returned an invalid year range', 'provider' => $provider];
                }
                $year = $startYear;
                $estimateLabel = "Estimated year range: {$startYear}-{$endYear}";
                $yearRange = ['start' => $startYear, 'end' => $endYear];
            } else {
                $year = (int) $yearStr;
                $estimateLabel = "Estimated year: {$year}";
                $yearRange = null;
            }

            $confidence = 0.30;
            if (preg_match('/CONFIDENCE:\s*(high)/i', $response)) {
                $confidence = 0.50;
            } elseif (preg_match('/CONFIDENCE:\s*(medium)/i', $response)) {
                $confidence = 0.40;
            }

            $reasoning = '';
            if (preg_match('/REASONING:\s*(.+?)(?:\n|$)/i', $response, $reasonMatch)) {
                $reasoning = trim($reasonMatch[1]);
            }

            return [
                'success' => true,
                'date' => sprintf('%04d-01-01 00:00:00', $year),
                'precision' => 'year',
                'confidence' => $confidence,
                'reasoning' => "{$estimateLabel}; precision: year. AI visual analysis ({$provider}): {$reasoning}",
                'year_range' => $yearRange,
                'raw_response' => $response,
                'provider' => $provider,
            ];
        }

        return ['success' => false, 'error' => 'Could not parse AI response', 'provider' => $provider];
    }

    /**
     * Write date back to EXIF metadata in the physical file
     */
    public function writeExifDate(
        string $filePath,
        string $date,
        string $source = '',
        float $confidence = 0.0,
        string $precision = ''
    ): array {
        if ($precision !== 'exact') {
            return ['success' => false, 'error' => 'Approximate dates cannot be written as confirmed EXIF dates'];
        }
        if ($confidence < 0.70) {
            return ['success' => false, 'error' => 'Confidence too low for direct EXIF write'];
        }
        if ($source === '' || str_starts_with($source, 'ai') || str_starts_with($source, 'exif_')
            || $this->isScanContext($filePath, basename($filePath))) {
            return ['success' => false, 'error' => 'EXIF and scan dates cannot be written as confirmed capture dates'];
        }

        return app(ExifWritebackService::class)->writeDate($filePath, $date, $source, $confidence);
    }

    /**
     * Check whether a file path / filename indicates a scanned physical photograph.
     * Returns the matching keyword (e.g. "path:slides") or false.
     */
    public function isScanContext(string $path, string $filename = ''): string|false
    {
        $searchIn = strtolower($path.'/'.$filename);
        foreach (self::SCAN_PATH_KEYWORDS as $keyword) {
            if (str_contains($searchIn, $keyword)) {
                return 'path:'.$keyword;
            }
        }

        return false;
    }

    /**
     * Detect whether an image looks like an old scanned photograph by checking
     * color channel count via exiftool.
     * ColorComponents = 1 → grayscale (reliable B&W scan indicator)
     * ColorComponents = 3 → color (could still be sepia, but less certain)
     */
    public function detectOldPhotoSignals(string $localPath): array
    {
        $result = ['is_grayscale' => false, 'color_components' => null, 'looks_old' => false];

        if (! file_exists($localPath)) {
            return $result;
        }

        try {
            $procResult = Process::timeout(10)->run([
                $this->exiftoolPath, '-ColorComponents', '-s3', $localPath,
            ]);
            $output = trim($procResult->output());
        } catch (\Exception $e) {
            Log::debug('PhotoDateExtractionService: exiftool color check failed', [
                'path' => $localPath,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        if (is_numeric($output)) {
            $result['color_components'] = (int) $output;
            $result['is_grayscale'] = ($result['color_components'] === 1);
        }

        $result['looks_old'] = $result['is_grayscale'];

        return $result;
    }

    /**
     * AI visual estimation with explicit scan context.
     * Instructs the model to estimate the original photo date based on visual
     * scene content, explicitly ignoring the digitization/scan year.
     */
    public function estimateDateWithAIScanContext(
        string $filePath,
        AIService $aiService,
        ?string $scanDate,
        string $scanContext,
        ?string $filename = null,
        ?string $directoryContext = null
    ): array {
        $meaningfulScanDate = $scanDate && ! str_starts_with($scanDate, '1970-01-01') ? $scanDate : null;
        $scanYear = $meaningfulScanDate ? date('Y', strtotime($meaningfulScanDate)) : 'an unknown date';
        $context = $this->dateEstimationContext($filePath, $filename, $directoryContext);

        $prompt = <<<PROMPT
This image is a scanned photograph. It was digitized at {$scanYear}. Your task is to estimate when the ORIGINAL photograph was taken — NOT when it was scanned.

Look for visual clues in the scene content:
1. Technology visible (phones, computers, TVs, cars, appliances, cameras)
2. Fashion, hairstyles, and clothing styles
3. Photo characteristics (black & white, sepia tone, color saturation, film grain, scan artifacts)
4. Photo format cues (slide mount edges, Polaroid border, print texture, age fading)
5. Architecture, vehicles, and visible signage
6. Any visible dates, newspapers, calendars, or event banners
7. The file and folder names below, when they contain credible event or date clues

File context: {$context}
Treat scanner/import dates and generic sequence numbers as non-evidence. In the reasoning, distinguish visual clues from filename or folder clues.

The digitization date ({$scanYear}) reflects when the photo was scanned — ignore it entirely for your estimate.

Respond in this exact format:
ESTIMATED_YEAR: [4-digit year or range like "1965-1970"]
CONFIDENCE: [low/medium/high]
REASONING: [specific visual clues observed, e.g. "B&W scan, tail-fin car visible, women hairstyles circa 1963"]

If you cannot make a reasonable estimate:
ESTIMATED_YEAR: unknown
CONFIDENCE: none
REASONING: [why estimation is not possible]
PROMPT;

        return $this->estimateWithVisionPipeline($filePath, $prompt, $aiService);
    }

    /**
     * Process a single file: extract date, update DB, optionally write EXIF
     */
    public function processFile(int $fileId, bool $writeExif = false, ?AIService $aiService = null): array
    {
        // Get file info
        $file = DB::selectOne('
            SELECT id, current_path, filename, extension, date_taken, date_taken_source, date_needs_review
            FROM file_registry
            WHERE id = ?
        ', [$fileId]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        // User-set dates are authoritative — never override
        if ($file->date_taken && $file->date_taken_source === 'user_manual') {
            return ['success' => true, 'skipped' => true, 'reason' => 'User-set date preserved'];
        }

        if ((int) ($file->date_needs_review ?? 0) === 1) {
            return ['success' => true, 'skipped' => true, 'reason' => 'Existing date evidence requires operator review'];
        }

        // High-confidence EXIF date: skip UNLESS scan context detected.
        // Scanners write the digitization date to DateTimeOriginal (semantically wrong).
        if ($file->date_taken && in_array($file->date_taken_source, ['exif_original', 'exif_digitized'])) {
            $scanContext = $this->isScanContext($file->current_path, $file->filename);
            if (! $scanContext) {
                return ['success' => true, 'skipped' => true, 'reason' => 'Already has high-confidence EXIF date'];
            }
            // Scan folder detected: move EXIF date to scan_date and fall through to AI estimation
            DB::update("
                UPDATE file_registry
                SET scan_date        = COALESCE(scan_date, ?),
                    is_scan          = 1,
                    scan_context     = COALESCE(scan_context, ?),
                    date_taken       = NULL,
                    date_taken_source = 'scan_exif',
                    updated_at       = NOW()
                WHERE id = ?
            ", [$file->date_taken, $scanContext, $fileId]);
            $file->date_taken = null;
        }

        $localPath = $this->getLocalPath($file->current_path);

        if (! file_exists($localPath)) {
            return ['success' => false, 'error' => 'Physical file not found: '.$localPath];
        }

        // Extract date
        $result = $this->extractDate($fileId, $localPath, $file->filename, $file->current_path);

        // If primary extraction failed and AI service provided, try AI
        if (! $result['success'] && ($result['status'] ?? null) !== 'held' && $aiService) {
            $aiResult = $this->estimateDateWithAI($localPath, $aiService, $file->filename, $file->current_path);
            if ($aiResult['success']) {
                $result = [
                    'success' => true,
                    'date_taken' => $aiResult['date'],
                    'precision' => $aiResult['precision'] ?? 'year',
                    'source' => 'ai_estimated',
                    'confidence' => $aiResult['confidence'],
                    'reasoning' => $aiResult['reasoning'],
                ];
            }
        }

        if (! $result['success']) {
            // Conflicting operator-marker tags need review and must not fall through to AI.
            DB::update('
                UPDATE file_registry
                SET date_extracted_at = NOW(),
                    date_taken_reasoning = ?,
                    date_needs_review = ?
                WHERE id = ?
            ', ['No date applied; retained metadata evidence requires review.', ($result['status'] ?? null) === 'held' ? 1 : 0, $fileId]);

            return $result;
        }

        // Update database
        DB::update('
            UPDATE file_registry
            SET date_taken = ?,
                date_taken_precision = ?,
                date_taken_source = ?,
                date_taken_confidence = ?,
                date_taken_reasoning = ?,
                date_extracted_at = NOW(),
                date_needs_review = ?
            WHERE id = ?
        ', [
            $result['date_taken'],
            $result['precision'] ?? 'exact',
            $result['source'],
            $result['confidence'],
            $result['reasoning'],
            ! empty($result['requires_operator_review']) ? 1 : 0,
            $fileId,
        ]);

        // Optionally write back to EXIF (only for non-EXIF sources with high confidence)
        if ($writeExif
            && ($result['precision'] ?? 'exact') === 'exact'
            && empty($result['requires_operator_review'])
            && ! str_starts_with($result['source'], 'exif_')
            && ! $this->isScanContext($file->current_path, $file->filename)
            && $result['confidence'] >= 0.70
        ) {
            $writeResult = $this->writeExifDate(
                $localPath,
                $result['date_taken'],
                $result['source'],
                (float) $result['confidence'],
                (string) ($result['precision'] ?? 'exact')
            );
            if ($writeResult['success']) {
                DB::update('UPDATE file_registry SET exif_written = 1 WHERE id = ?', [$fileId]);
                $result['exif_written'] = true;
            } else {
                $result['exif_write_error'] = $writeResult['error'];
            }
        }

        return $result;
    }

    /**
     * Get statistics on date extraction status
     */
    public function getStats(): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_images,
                SUM(CASE WHEN date_taken IS NOT NULL THEN 1 ELSE 0 END) as has_date,
                SUM(CASE WHEN date_taken_source = 'exif_original' THEN 1 ELSE 0 END) as exif_original,
                SUM(CASE WHEN date_taken_source = 'exif_digitized' THEN 1 ELSE 0 END) as exif_digitized,
                SUM(CASE WHEN date_taken_source = 'filename_extracted' THEN 1 ELSE 0 END) as filename,
                SUM(CASE WHEN date_taken_source = 'path_extracted' THEN 1 ELSE 0 END) as path,
                SUM(CASE WHEN date_taken_source = 'ai_estimated' THEN 1 ELSE 0 END) as ai_estimated,
                SUM(CASE WHEN date_taken_source = 'file_modified' THEN 1 ELSE 0 END) as file_modified,
                SUM(CASE WHEN date_extracted_at IS NOT NULL AND date_taken IS NULL THEN 1 ELSE 0 END) as no_date_found,
                SUM(CASE WHEN date_extracted_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN exif_written = 1 THEN 1 ELSE 0 END) as exif_written
            FROM file_registry
            WHERE status = 'active'
            AND extension IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff', 'bmp')
        ");

        return (array) $stats;
    }

    /**
     * Get conversion path for local file access
     */
    public function getLocalPath(string $ncPath): string
    {
        if ($this->basePath === '') {
            return $ncPath;
        }

        if (str_starts_with($ncPath, $this->libraryRoot)) {
            return $this->basePath.substr($ncPath, strlen($this->libraryRoot));
        }

        return $this->basePath.'/'.ltrim($ncPath, '/');
    }

    private function dateEstimationContext(string $filePath, ?string $filename, ?string $directoryContext): string
    {
        $name = trim((string) ($filename ?: basename($filePath)));
        $directory = trim((string) ($directoryContext ?: dirname($filePath)), '/');
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $directory))));
        $folders = implode(' / ', array_slice($segments, -3));

        return 'filename="'.mb_substr($name, 0, 240).'"; folders="'.mb_substr($folders, 0, 500).'"';
    }
}
