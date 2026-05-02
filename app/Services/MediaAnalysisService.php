<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Media Analysis Service
 *
 * Provides metadata extraction for video, audio, ebook, email, and archive files.
 * Uses a tiered extraction strategy with multiple fallback options:
 *
 * 1. Apache Tika (primary) - Supports 1000+ formats
 * 2. ExifTool (fallback) - Excellent for media metadata
 * 3. Native PHP functions (last resort) - Basic extraction
 *
 * Supported file types:
 * - Video: mp4, mov, avi, mkv, wmv, mpg, m4v, webm, flv, vob, mts, 3gp
 * - Audio: mp3, wav, flac, aac, ogg, wma, aiff, m4a, opus, mid
 * - Ebook: epub, mobi, azw, azw3, fb2, lit, pdb
 * - Email: msg, eml, mbox, pst
 * - Archive: zip, 7z, rar, tar, gz, bz2, xz
 * - Structured: json, xml, yaml, yml
 *
 * @see https://tika.apache.org/
 * @see https://exiftool.org/
 */
class MediaAnalysisService
{
    private ?TikaExtractionService $tika = null;
    private string $exiftoolPath;
    private int $timeout;

    /** Video extensions with full metadata extraction */
    public const VIDEO_EXTENSIONS = [
        'mp4', 'mov', 'avi', 'mkv', 'wmv', 'mpg', 'mpeg', 'm4v',
        'webm', 'flv', 'vob', 'mts', 'm2ts', '3gp', '3g2', 'ogv',
        'ts', 'divx', 'xvid', 'asf', 'rm', 'rmvb'
    ];

    /** Audio extensions with full metadata extraction */
    public const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'aiff', 'aif',
        'm4a', 'opus', 'mid', 'midi', 'ape', 'alac', 'dsd', 'dsf',
        'wv', 'mka', 'ra', 'amr', 'au', 'snd'
    ];

    /** Ebook/document extensions */
    public const EBOOK_EXTENSIONS = [
        'epub', 'mobi', 'azw', 'azw3', 'fb2', 'lit', 'pdb', 'lrf',
        'tcr', 'djvu', 'djv', 'cbz', 'cbr', 'cb7', 'cbt'
    ];

    /** Email/message extensions */
    public const EMAIL_EXTENSIONS = [
        'msg', 'eml', 'mbox', 'pst', 'ost', 'mht', 'mhtml', 'oft'
    ];

    /** Archive/compression extensions */
    public const ARCHIVE_EXTENSIONS = [
        'zip', '7z', 'rar', 'tar', 'gz', 'bz2', 'xz', 'lzma',
        'cab', 'iso', 'dmg', 'jar', 'war', 'ear', 'apk', 'ipa',
        'deb', 'rpm', 'tgz', 'tbz2', 'txz', 'lz', 'lz4', 'zst'
    ];

    /** Structured data extensions */
    public const STRUCTURED_DATA_EXTENSIONS = [
        'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf',
        'properties', 'env', 'csv', 'tsv'
    ];

    /** Genealogy-specific extensions */
    public const GENEALOGY_EXTENSIONS = [
        'ged', 'gedcom', 'ftm', 'ftmb', 'ftw', 'paf', 'gramps'
    ];

    /** CAD/3D extensions */
    public const CAD_3D_EXTENSIONS = [
        'stl', 'obj', 'fbx', 'dxf', 'dwg', 'skp', '3mf', 'step',
        'stp', 'iges', 'igs', 'blend', 'max', 'c4d', 'dae'
    ];

    /** Legacy document extensions (Tika can handle most) */
    public const LEGACY_DOC_EXTENSIONS = [
        'wpd', 'wps', 'wri', 'wks', 'pub', 'vsd', 'vsdx', 'one',
        'xps', 'oxps'
    ];

    public function __construct(?TikaExtractionService $tika = null)
    {
        $this->tika = $tika;
        $this->exiftoolPath = $this->findExiftool();
        $this->timeout = 60; // seconds
    }

    /**
     * Get or create Tika service
     */
    private function getTika(): ?TikaExtractionService
    {
        if (!$this->tika) {
            try {
                $this->tika = app(TikaExtractionService::class);
            } catch (\Exception $e) {
                Log::debug('MediaAnalysis: Tika service unavailable', ['error' => $e->getMessage()]);
                return null;
            }
        }
        return $this->tika;
    }

    /**
     * Find ExifTool binary path
     */
    private function findExiftool(): string
    {
        $paths = [
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            '/opt/homebrew/bin/exiftool',
            'exiftool', // System PATH
        ];

        foreach ($paths as $path) {
            if ($path === 'exiftool' || (file_exists($path) && is_executable($path))) {
                return $path;
            }
        }

        return 'exiftool';
    }

    /**
     * Check if ExifTool is available
     */
    public function isExiftoolAvailable(): bool
    {
        try {
            $result = Process::timeout(5)->run([$this->exiftoolPath, '-ver']);
            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Analyze a media file (video, audio, ebook, etc.)
     *
     * @param string $filePath Path to the file
     * @param string|array $typeOrOptions Type hint (video, audio, etc.) or options array
     * @return array Analysis result with metadata
     */
    public function analyze(string $filePath, string|array $typeOrOptions = []): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        // Handle both type hint string and options array
        $options = [];
        $typeHint = null;
        if (is_string($typeOrOptions)) {
            $typeHint = $typeOrOptions;
        } else {
            $options = $typeOrOptions;
            $typeHint = $options['type'] ?? null;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $filename = basename($filePath);

        // Determine file category (use hint if provided, otherwise detect from extension)
        $category = $typeHint ?: $this->categorizeFile($extension);

        // Use appropriate extraction method based on category
        $result = match ($category) {
            'video' => $this->analyzeVideo($filePath, $options),
            'audio' => $this->analyzeAudio($filePath, $options),
            'ebook' => $this->analyzeEbook($filePath, $options),
            'email' => $this->analyzeEmail($filePath, $options),
            'archive' => $this->analyzeArchive($filePath, $options),
            'structured' => $this->analyzeStructuredData($filePath, $options),
            'genealogy' => $this->analyzeGenealogy($filePath, $options),
            'cad_3d' => $this->analyzeCad3d($filePath, $options),
            'legacy_doc' => $this->analyzeLegacyDoc($filePath, $options),
            default => $this->analyzeGeneric($filePath, $options),
        };

        // Add common fields
        $result['filename'] = $filename;
        $result['extension'] = $extension;
        $result['category'] = $category;
        $result['file_size'] = filesize($filePath);

        // Add extractor string for reason building
        $result['extractor'] = implode('+', $result['extractors'] ?? ['unknown']);

        return $result;
    }

    /**
     * Categorize file by extension
     */
    public function categorizeFile(string $extension): string
    {
        $extension = strtolower($extension);

        if (in_array($extension, self::VIDEO_EXTENSIONS)) return 'video';
        if (in_array($extension, self::AUDIO_EXTENSIONS)) return 'audio';
        if (in_array($extension, self::EBOOK_EXTENSIONS)) return 'ebook';
        if (in_array($extension, self::EMAIL_EXTENSIONS)) return 'email';
        if (in_array($extension, self::ARCHIVE_EXTENSIONS)) return 'archive';
        if (in_array($extension, self::STRUCTURED_DATA_EXTENSIONS)) return 'structured';
        if (in_array($extension, self::GENEALOGY_EXTENSIONS)) return 'genealogy';
        if (in_array($extension, self::CAD_3D_EXTENSIONS)) return 'cad_3d';
        if (in_array($extension, self::LEGACY_DOC_EXTENSIONS)) return 'legacy_doc';

        return 'unknown';
    }

    /**
     * Check if extension is handled by this service
     */
    public function isHandledExtension(string $extension): bool
    {
        return $this->categorizeFile($extension) !== 'unknown';
    }

    /**
     * Get all handled extensions
     */
    public function getAllHandledExtensions(): array
    {
        return array_merge(
            self::VIDEO_EXTENSIONS,
            self::AUDIO_EXTENSIONS,
            self::EBOOK_EXTENSIONS,
            self::EMAIL_EXTENSIONS,
            self::ARCHIVE_EXTENSIONS,
            self::STRUCTURED_DATA_EXTENSIONS,
            self::GENEALOGY_EXTENSIONS,
            self::CAD_3D_EXTENSIONS,
            self::LEGACY_DOC_EXTENSIONS
        );
    }

    /**
     * Analyze video file
     */
    public function analyzeVideo(string $filePath, array $options = []): array
    {
        $metadata = [];
        $extractors = [];

        // Try Tika first
        $tikaResult = $this->extractWithTika($filePath);
        if ($tikaResult['success']) {
            $metadata = array_merge($metadata, $this->normalizeVideoMetadata($tikaResult['metadata'] ?? []));
            $extractors[] = 'tika';
        }

        // Try ExifTool as supplement/fallback
        $exifResult = $this->extractWithExiftool($filePath);
        if ($exifResult['success']) {
            $exifMeta = $this->normalizeVideoMetadata($exifResult['metadata'] ?? []);
            // Merge, preferring ExifTool for media-specific fields
            foreach ($exifMeta as $key => $value) {
                if (empty($metadata[$key]) && !empty($value)) {
                    $metadata[$key] = $value;
                }
            }
            $extractors[] = 'exiftool';
        }

        if (empty($extractors)) {
            return ['success' => false, 'error' => 'All extractors failed'];
        }

        return [
            'success' => true,
            'type' => 'video',
            'metadata' => $metadata,
            'duration' => $metadata['duration'] ?? null,
            'duration_formatted' => $this->formatDuration($metadata['duration'] ?? 0),
            'resolution' => isset($metadata['width'], $metadata['height'])
                ? "{$metadata['width']}x{$metadata['height']}"
                : null,
            'codec' => $metadata['video_codec'] ?? $metadata['codec'] ?? null,
            'frame_rate' => $metadata['frame_rate'] ?? null,
            'bit_rate' => $metadata['bit_rate'] ?? null,
            'creation_date' => $metadata['creation_date'] ?? null,
            'gps' => $metadata['gps'] ?? null,
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze audio file
     */
    public function analyzeAudio(string $filePath, array $options = []): array
    {
        $metadata = [];
        $extractors = [];

        // Try Tika first
        $tikaResult = $this->extractWithTika($filePath);
        if ($tikaResult['success']) {
            $metadata = array_merge($metadata, $this->normalizeAudioMetadata($tikaResult['metadata'] ?? []));
            $extractors[] = 'tika';
        }

        // Try ExifTool for ID3 tags
        $exifResult = $this->extractWithExiftool($filePath);
        if ($exifResult['success']) {
            $exifMeta = $this->normalizeAudioMetadata($exifResult['metadata'] ?? []);
            foreach ($exifMeta as $key => $value) {
                if (empty($metadata[$key]) && !empty($value)) {
                    $metadata[$key] = $value;
                }
            }
            $extractors[] = 'exiftool';
        }

        if (empty($extractors)) {
            return ['success' => false, 'error' => 'All extractors failed'];
        }

        return [
            'success' => true,
            'type' => 'audio',
            'metadata' => $metadata,
            'duration' => $metadata['duration'] ?? null,
            'duration_formatted' => $this->formatDuration($metadata['duration'] ?? 0),
            'title' => $metadata['title'] ?? null,
            'artist' => $metadata['artist'] ?? null,
            'album' => $metadata['album'] ?? null,
            'genre' => $metadata['genre'] ?? null,
            'year' => $metadata['year'] ?? null,
            'track' => $metadata['track'] ?? null,
            'bit_rate' => $metadata['bit_rate'] ?? null,
            'sample_rate' => $metadata['sample_rate'] ?? null,
            'channels' => $metadata['channels'] ?? null,
            'codec' => $metadata['audio_codec'] ?? $metadata['codec'] ?? null,
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze ebook file
     */
    public function analyzeEbook(string $filePath, array $options = []): array
    {
        $metadata = [];
        $text = '';
        $extractors = [];

        // Try Tika (excellent for EPUB)
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            $metadata = $tikaResult['metadata'] ?? [];
            $text = $tikaResult['text'] ?? '';
            $extractors[] = 'tika';
        }

        // Fallback to ExifTool for metadata
        if (empty($metadata)) {
            $exifResult = $this->extractWithExiftool($filePath);
            if ($exifResult['success']) {
                $metadata = $exifResult['metadata'] ?? [];
                $extractors[] = 'exiftool';
            }
        }

        if (empty($extractors)) {
            return ['success' => false, 'error' => 'All extractors failed'];
        }

        return [
            'success' => true,
            'type' => 'ebook',
            'metadata' => $metadata,
            'title' => $metadata['dc:title'] ?? $metadata['title'] ?? $metadata['Title'] ?? null,
            'author' => $metadata['dc:creator'] ?? $metadata['author'] ?? $metadata['Author'] ?? null,
            'publisher' => $metadata['dc:publisher'] ?? $metadata['publisher'] ?? null,
            'language' => $metadata['dc:language'] ?? $metadata['language'] ?? null,
            'isbn' => $metadata['isbn'] ?? null,
            'publication_date' => $metadata['dc:date'] ?? $metadata['date'] ?? null,
            'text_preview' => substr($text, 0, 1000),
            'word_count' => str_word_count($text),
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze email file (MSG, EML, etc.)
     */
    public function analyzeEmail(string $filePath, array $options = []): array
    {
        $metadata = [];
        $text = '';
        $extractors = [];

        // Tika has excellent MSG/EML support
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            $metadata = $tikaResult['metadata'] ?? [];
            $text = $tikaResult['text'] ?? '';
            $extractors[] = 'tika';
        }

        if (empty($extractors)) {
            return ['success' => false, 'error' => 'Tika extraction failed'];
        }

        return [
            'success' => true,
            'type' => 'email',
            'metadata' => $metadata,
            'subject' => $metadata['dc:subject'] ?? $metadata['subject'] ?? $metadata['Subject'] ?? null,
            'from' => $metadata['Message-From'] ?? $metadata['from'] ?? $metadata['From'] ?? null,
            'to' => $metadata['Message-To'] ?? $metadata['to'] ?? $metadata['To'] ?? null,
            'cc' => $metadata['Message-Cc'] ?? $metadata['cc'] ?? null,
            'date' => $metadata['Creation-Date'] ?? $metadata['date'] ?? $metadata['Date'] ?? null,
            'has_attachments' => !empty($metadata['X-TIKA:embedded_resource_path']),
            'body_preview' => substr($text, 0, 1000),
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze archive file
     */
    public function analyzeArchive(string $filePath, array $options = []): array
    {
        $metadata = [];
        $contents = [];
        $extractors = [];

        // Tika can list archive contents
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            $metadata = $tikaResult['metadata'] ?? [];
            // Parse archive contents from text
            $text = $tikaResult['text'] ?? '';
            if (!empty($text)) {
                $contents = array_filter(array_map('trim', explode("\n", $text)));
            }
            $extractors[] = 'tika';
        }

        // Try native PHP for ZIP files
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === 'zip' && class_exists('ZipArchive')) {
            $zipContents = $this->extractZipContents($filePath);
            if (!empty($zipContents)) {
                $contents = $zipContents;
                $extractors[] = 'php_zip';
            }
        }

        // Extract file types from contents
        $contentTypes = [];
        foreach ($contents as $entry) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext && !in_array($ext, $contentTypes)) {
                $contentTypes[] = $ext;
            }
        }

        return [
            'success' => true,
            'type' => 'archive',
            'metadata' => $metadata,
            'file_count' => count($contents),
            'contents' => array_slice($contents, 0, 100), // Limit to first 100 entries
            'content_types' => $contentTypes,
            'compressed_size' => filesize($filePath),
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze structured data file (JSON, XML, YAML)
     */
    public function analyzeStructuredData(string $filePath, array $options = []): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $content = file_get_contents($filePath);
        $parsed = null;
        $error = null;

        try {
            switch ($extension) {
                case 'json':
                    $parsed = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error = json_last_error_msg();
                        $parsed = null;
                    }
                    break;

                case 'xml':
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($content);
                    if ($xml === false) {
                        $errors = libxml_get_errors();
                        $error = $errors[0]->message ?? 'XML parse error';
                        libxml_clear_errors();
                    } else {
                        $parsed = json_decode(json_encode($xml), true);
                    }
                    break;

                case 'yaml':
                case 'yml':
                    if (function_exists('yaml_parse')) {
                        $parsed = yaml_parse($content);
                    } else {
                        // Basic YAML detection without parsing
                        $parsed = ['_raw' => 'YAML extension not available'];
                    }
                    break;

                case 'csv':
                case 'tsv':
                    $delimiter = $extension === 'tsv' ? "\t" : ',';
                    $lines = str_getcsv($content, "\n");
                    $parsed = [];
                    foreach (array_slice($lines, 0, 100) as $line) {
                        $parsed[] = str_getcsv($line, $delimiter);
                    }
                    break;

                default:
                    $parsed = ['_raw' => substr($content, 0, 1000)];
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'success' => $parsed !== null,
            'type' => 'structured',
            'format' => $extension,
            'parsed' => $parsed,
            'error' => $error,
            'record_count' => is_array($parsed) ? count($parsed) : null,
            'keys' => is_array($parsed) && !empty($parsed)
                ? (is_array(reset($parsed)) ? array_keys(reset($parsed)) : array_keys($parsed))
                : null,
            'extractors' => ['php_native'],
        ];
    }

    /**
     * Analyze genealogy file (GEDCOM, FTM, etc.)
     */
    public function analyzeGenealogy(string $filePath, array $options = []): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // For GEDCOM files, do basic parsing
        if ($extension === 'ged' || $extension === 'gedcom') {
            return $this->parseGedcomBasic($filePath);
        }

        // For FTM and other proprietary formats, use Tika
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            return [
                'success' => true,
                'type' => 'genealogy',
                'format' => $extension,
                'metadata' => $tikaResult['metadata'] ?? [],
                'text_preview' => substr($tikaResult['text'] ?? '', 0, 1000),
                'extractors' => ['tika'],
            ];
        }

        return [
            'success' => false,
            'type' => 'genealogy',
            'error' => 'Unable to parse genealogy file',
        ];
    }

    /**
     * Basic GEDCOM parsing
     */
    private function parseGedcomBasic(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        $stats = [
            'individuals' => 0,
            'families' => 0,
            'sources' => 0,
            'submitter' => null,
            'software' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^0\s+@\w+@\s+INDI/', $line)) {
                $stats['individuals']++;
            } elseif (preg_match('/^0\s+@\w+@\s+FAM/', $line)) {
                $stats['families']++;
            } elseif (preg_match('/^0\s+@\w+@\s+SOUR/', $line)) {
                $stats['sources']++;
            } elseif (preg_match('/^1\s+NAME\s+(.+)/', $line, $matches) && !$stats['submitter']) {
                // First NAME is often the submitter
            } elseif (preg_match('/^2\s+NAME\s+(.+)/', $line, $matches) && !$stats['software']) {
                $stats['software'] = trim($matches[1]);
            }
        }

        return [
            'success' => true,
            'type' => 'genealogy',
            'format' => 'gedcom',
            'individuals' => $stats['individuals'],
            'families' => $stats['families'],
            'sources' => $stats['sources'],
            'software' => $stats['software'],
            'extractors' => ['php_native'],
        ];
    }

    /**
     * Analyze CAD/3D file
     */
    public function analyzeCad3d(string $filePath, array $options = []): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $metadata = [];
        $extractors = [];

        // ExifTool has some 3D format support
        $exifResult = $this->extractWithExiftool($filePath);
        if ($exifResult['success']) {
            $metadata = $exifResult['metadata'] ?? [];
            $extractors[] = 'exiftool';
        }

        // Try Tika as fallback
        if (empty($extractors)) {
            $tikaResult = $this->extractWithTika($filePath);
            if ($tikaResult['success']) {
                $metadata = $tikaResult['metadata'] ?? [];
                $extractors[] = 'tika';
            }
        }

        return [
            'success' => !empty($extractors),
            'type' => 'cad_3d',
            'format' => $extension,
            'metadata' => $metadata,
            'extractors' => $extractors,
        ];
    }

    /**
     * Analyze legacy document format
     */
    public function analyzeLegacyDoc(string $filePath, array $options = []): array
    {
        // Tika handles most legacy formats
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            return [
                'success' => true,
                'type' => 'legacy_doc',
                'metadata' => $tikaResult['metadata'] ?? [],
                'text' => $tikaResult['text'] ?? '',
                'text_preview' => substr($tikaResult['text'] ?? '', 0, 1000),
                'word_count' => str_word_count($tikaResult['text'] ?? ''),
                'extractors' => ['tika'],
            ];
        }

        return ['success' => false, 'error' => 'Tika extraction failed'];
    }

    /**
     * Generic analysis fallback
     */
    public function analyzeGeneric(string $filePath, array $options = []): array
    {
        // Try Tika first
        $tikaResult = $this->extractWithTika($filePath, true);
        if ($tikaResult['success']) {
            return [
                'success' => true,
                'type' => 'generic',
                'metadata' => $tikaResult['metadata'] ?? [],
                'text' => $tikaResult['text'] ?? '',
                'mime_type' => $tikaResult['mime_type'] ?? null,
                'extractors' => ['tika'],
            ];
        }

        // Fallback to ExifTool
        $exifResult = $this->extractWithExiftool($filePath);
        if ($exifResult['success']) {
            return [
                'success' => true,
                'type' => 'generic',
                'metadata' => $exifResult['metadata'] ?? [],
                'extractors' => ['exiftool'],
            ];
        }

        return [
            'success' => false,
            'type' => 'generic',
            'error' => 'All extractors failed',
        ];
    }

    /**
     * Extract with Apache Tika
     */
    private function extractWithTika(string $filePath, bool $includeText = false): array
    {
        $tika = $this->getTika();
        if (!$tika) {
            return ['success' => false, 'error' => 'Tika unavailable'];
        }

        // Check Tika health
        $health = $tika->healthCheck();
        if (!($health['available'] ?? false)) {
            return ['success' => false, 'error' => 'Tika server not available'];
        }

        try {
            $result = $tika->extractFromFile($filePath, [
                'include_metadata' => true,
                'ocr_strategy' => 'no_ocr', // Skip OCR for media files
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::warning('MediaAnalysis: Tika extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract with ExifTool
     */
    private function extractWithExiftool(string $filePath): array
    {
        if (!$this->isExiftoolAvailable()) {
            return ['success' => false, 'error' => 'ExifTool not available'];
        }

        try {
            // Get JSON output from ExifTool
            $result = Process::timeout($this->timeout)
                ->run([$this->exiftoolPath, '-json', '-G1', $filePath]);

            if (!$result->successful()) {
                return ['success' => false, 'error' => $result->errorOutput()];
            }

            $output = $result->output();
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                return ['success' => false, 'error' => 'Failed to parse ExifTool output'];
            }

            // ExifTool returns an array, get first element
            $metadata = $data[0] ?? [];

            return [
                'success' => true,
                'metadata' => $metadata,
            ];
        } catch (\Exception $e) {
            Log::warning('MediaAnalysis: ExifTool extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract ZIP contents using PHP
     */
    private function extractZipContents(string $filePath): array
    {
        $contents = [];

        try {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $contents[] = $stat['name'];
                }
                $zip->close();
            }
        } catch (\Exception $e) {
            Log::debug('MediaAnalysis: ZIP extraction failed', ['error' => $e->getMessage()]);
        }

        return $contents;
    }

    /**
     * Normalize video metadata from various sources
     */
    private function normalizeVideoMetadata(array $raw): array
    {
        $normalized = [];

        // Duration (in seconds)
        $durationKeys = ['xmpDM:duration', 'Duration', 'duration', 'QuickTime:Duration', 'MediaDuration'];
        foreach ($durationKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['duration'] = $this->parseDuration($raw[$key]);
                break;
            }
        }

        // Resolution
        $widthKeys = ['tiff:ImageWidth', 'ImageWidth', 'width', 'SourceImageWidth', 'QuickTime:ImageWidth'];
        $heightKeys = ['tiff:ImageHeight', 'ImageHeight', 'height', 'SourceImageHeight', 'QuickTime:ImageHeight'];
        foreach ($widthKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['width'] = (int) $raw[$key];
                break;
            }
        }
        foreach ($heightKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['height'] = (int) $raw[$key];
                break;
            }
        }

        // Video codec
        $codecKeys = ['xmpDM:videoCompressor', 'VideoCodec', 'Codec', 'CompressorID', 'QuickTime:CompressorID'];
        foreach ($codecKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['video_codec'] = $raw[$key];
                break;
            }
        }

        // Frame rate
        $fpsKeys = ['xmpDM:videoFrameRate', 'VideoFrameRate', 'FrameRate', 'QuickTime:VideoFrameRate'];
        foreach ($fpsKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['frame_rate'] = floatval($raw[$key]);
                break;
            }
        }

        // Bit rate
        $bitrateKeys = ['xmpDM:audioSampleRate', 'BitRate', 'AvgBitrate', 'VideoBitrate'];
        foreach ($bitrateKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['bit_rate'] = $raw[$key];
                break;
            }
        }

        // Creation date
        $dateKeys = ['dcterms:created', 'CreateDate', 'CreationDate', 'DateTimeOriginal', 'MediaCreateDate'];
        foreach ($dateKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['creation_date'] = $raw[$key];
                break;
            }
        }

        // GPS
        if (isset($raw['Composite:GPSPosition']) || isset($raw['GPSLatitude'])) {
            $normalized['gps'] = [
                'latitude' => $raw['GPSLatitude'] ?? null,
                'longitude' => $raw['GPSLongitude'] ?? null,
                'position' => $raw['Composite:GPSPosition'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Normalize audio metadata from various sources
     */
    private function normalizeAudioMetadata(array $raw): array
    {
        $normalized = [];

        // Duration
        $durationKeys = ['xmpDM:duration', 'Duration', 'duration'];
        foreach ($durationKeys as $key) {
            if (isset($raw[$key])) {
                $normalized['duration'] = $this->parseDuration($raw[$key]);
                break;
            }
        }

        // ID3 tags
        $tagMappings = [
            'title' => ['dc:title', 'Title', 'title', 'ID3:Title'],
            'artist' => ['xmpDM:artist', 'Artist', 'artist', 'ID3:Artist', 'Author'],
            'album' => ['xmpDM:album', 'Album', 'album', 'ID3:Album'],
            'genre' => ['xmpDM:genre', 'Genre', 'genre', 'ID3:Genre'],
            'year' => ['xmpDM:releaseDate', 'Year', 'year', 'ID3:Year', 'ReleaseDate'],
            'track' => ['xmpDM:trackNumber', 'Track', 'track', 'ID3:Track'],
        ];

        foreach ($tagMappings as $normalized_key => $source_keys) {
            foreach ($source_keys as $key) {
                if (isset($raw[$key])) {
                    $normalized[$normalized_key] = $raw[$key];
                    break;
                }
            }
        }

        // Technical metadata
        $normalized['sample_rate'] = $raw['xmpDM:audioSampleRate'] ?? $raw['SampleRate'] ?? $raw['AudioSampleRate'] ?? null;
        $normalized['channels'] = $raw['xmpDM:audioChannelType'] ?? $raw['AudioChannels'] ?? $raw['Channels'] ?? null;
        $normalized['bit_rate'] = $raw['AudioBitrate'] ?? $raw['BitRate'] ?? $raw['AvgBitrate'] ?? null;
        $normalized['audio_codec'] = $raw['xmpDM:audioCompressor'] ?? $raw['AudioCodec'] ?? $raw['Codec'] ?? null;

        return $normalized;
    }

    /**
     * Parse duration string to seconds
     */
    private function parseDuration($duration): ?float
    {
        if (is_numeric($duration)) {
            return floatval($duration);
        }

        if (is_string($duration)) {
            // Handle "0:05:23" format
            if (preg_match('/^(\d+):(\d+):(\d+)/', $duration, $matches)) {
                return ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
            }
            // Handle "5:23" format
            if (preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
                return ($matches[1] * 60) + $matches[2];
            }
            // Handle "123.45 s" or "123.45s" format
            if (preg_match('/^([\d.]+)\s*s/i', $duration, $matches)) {
                return floatval($matches[1]);
            }
            // Handle milliseconds
            if (preg_match('/^([\d.]+)\s*ms/i', $duration, $matches)) {
                return floatval($matches[1]) / 1000;
            }
        }

        return null;
    }

    /**
     * Format duration in seconds to human-readable string
     */
    private function formatDuration(?float $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
