<?php

namespace App\Nodes;

use App\Services\ContentExtractionService;
use App\Services\FaceRegionService;
use Exception;

/**
 * Content Extraction Node (E17)
 *
 * Extracts text and metadata from files within workflows.
 * Supports: PDF, images, Office documents, audio/video transcription, face regions.
 *
 * Usage in workflows:
 * - Input: file path (local or from previous node)
 * - Output: extracted text, metadata, EXIF data, face regions
 *
 * @see App\Services\ContentExtractionService
 * @see docs/future-enhancements.md E17
 */
class ContentExtractionNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            /** @var ContentExtractionService $service */
            $service = app(ContentExtractionService::class);

            // Try to inject FaceRegionService if available
            try {
                $faceService = app(FaceRegionService::class);
                $service->setFaceRegionService($faceService);
            } catch (\Exception $e) {
                // FaceRegionService not available, continue without it
            }

            // Get file path from config or input
            $filePath = $this->getFilePath($input);

            if (empty($filePath)) {
                return $this->standardOutput(null, [], 'No file path provided');
            }

            if (!file_exists($filePath)) {
                return $this->standardOutput(null, [], "File not found: {$filePath}");
            }

            // Build extraction options from config
            $options = [
                'use_vision' => $this->getConfigValue('use_vision', true),
                'use_ocr' => $this->getConfigValue('use_ocr', true),
                'use_claude' => $this->getConfigValue('use_claude', true),
                'extract_entities' => $this->getConfigValue('extract_entities', false),
                'use_transcription' => $this->getConfigValue('use_transcription', true),
                'extract_faces' => $this->getConfigValue('extract_faces', false),
            ];

            // Perform extraction
            $result = $service->extract($filePath, $options);

            if (!$result['success']) {
                return $this->standardOutput(null, [
                    'file' => basename($filePath),
                    'method' => $result['method'] ?? 'unknown',
                ], $result['error'] ?? 'Extraction failed');
            }

            // Build output data
            $outputData = [
                'text' => $result['text'] ?? '',
                'method' => $result['method'] ?? 'unknown',
                'filename' => $result['filename'] ?? basename($filePath),
                'file_path' => $filePath,
                'extraction_version' => $result['extraction_version'] ?? 'unknown',
            ];

            // Include EXIF if available
            if (!empty($result['exif'])) {
                $outputData['exif'] = $result['exif'];
            }

            // Include face regions if available
            if (!empty($result['faces'])) {
                $outputData['faces'] = $result['faces'];
                $outputData['face_count'] = count($result['faces']);
                $outputData['people'] = array_filter(array_column($result['faces'], 'name'));
            }

            // Include audio/video metadata if available
            if (!empty($result['metadata'])) {
                $outputData['media_metadata'] = $result['metadata'];
            }

            // Include transcription separately if available
            if (!empty($result['transcription'])) {
                $outputData['transcription'] = $result['transcription'];
            }

            // Generate title if requested
            if ($this->getConfigValue('generate_title', false)) {
                $outputData['generated_title'] = $service->generateTitle(
                    $result['text'] ?? '',
                    basename($filePath)
                );
            }

            // Calculate text stats
            $text = $result['text'] ?? '';
            $outputData['stats'] = [
                'char_count' => strlen($text),
                'word_count' => str_word_count($text),
                'line_count' => substr_count($text, "\n") + 1,
            ];

            return $this->standardOutput($outputData, [
                'file' => basename($filePath),
                'method' => $result['method'] ?? 'unknown',
                'has_exif' => !empty($result['exif']),
                'has_faces' => !empty($result['faces']),
                'has_transcription' => !empty($result['transcription']),
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Get file path from config or input
     */
    private function getFilePath(array $input): ?string
    {
        // Try config first
        $configPath = $this->getConfigValue('file_path', '');
        if (!empty($configPath)) {
            return $this->replacePlaceholders($configPath, $input);
        }

        // Try input data
        if (!empty($input['file_path'])) {
            return $input['file_path'];
        }

        if (!empty($input['path'])) {
            return $input['path'];
        }

        // Try data.file_path
        if (!empty($input['data']['file_path'])) {
            return $input['data']['file_path'];
        }

        if (!empty($input['data']['path'])) {
            return $input['data']['path'];
        }

        // Try local_path from download nodes
        if (!empty($input['local_path'])) {
            return $input['local_path'];
        }

        if (!empty($input['data']['local_path'])) {
            return $input['data']['local_path'];
        }

        return null;
    }

    /**
     * Replace placeholders in text with input values
     */
    private function replacePlaceholders(string $text, array $input): string
    {
        // Replace {date} patterns
        $text = str_replace('{date}', now()->format('Y-m-d'), $text);
        $text = str_replace('{datetime}', now()->format('Y-m-d_H-i-s'), $text);

        // Replace input values
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $text = str_replace("{{$key}}", $value, $text);
            }
        }

        // Replace data values
        if (!empty($input['data']) && is_array($input['data'])) {
            foreach ($input['data'] as $key => $value) {
                if (is_string($value)) {
                    $text = str_replace("{data.{$key}}", $value, $text);
                }
            }
        }

        return $text;
    }

    /**
     * Get node definition for workflow designer
     */
    public static function getDefinition(): array
    {
        return [
            'type' => 'content_extraction',
            'name' => 'Content Extraction',
            'description' => 'Extract text and metadata from files (PDF, images, audio, video, Office docs)',
            'category' => 'Processing',
            'icon' => '📄',
            'config' => [
                'file_path' => [
                    'type' => 'string',
                    'label' => 'File Path',
                    'description' => 'Path to file (can use {placeholders} from previous node)',
                    'required' => false,
                    'default' => '',
                    'placeholder' => '/path/to/file.pdf or {data.file_path}',
                ],
                'use_vision' => [
                    'type' => 'boolean',
                    'label' => 'Use Vision AI',
                    'description' => 'Use AI vision for image/PDF analysis',
                    'required' => false,
                    'default' => true,
                ],
                'use_ocr' => [
                    'type' => 'boolean',
                    'label' => 'Use OCR',
                    'description' => 'Use Tesseract OCR for text extraction',
                    'required' => false,
                    'default' => true,
                ],
                'use_transcription' => [
                    'type' => 'boolean',
                    'label' => 'Transcribe Audio/Video',
                    'description' => 'Use Whisper to transcribe audio/video files',
                    'required' => false,
                    'default' => true,
                ],
                'extract_faces' => [
                    'type' => 'boolean',
                    'label' => 'Extract Face Regions',
                    'description' => 'Extract MWG face regions from image metadata',
                    'required' => false,
                    'default' => false,
                ],
                'generate_title' => [
                    'type' => 'boolean',
                    'label' => 'Generate Title',
                    'description' => 'Generate a title from extracted content using AI',
                    'required' => false,
                    'default' => false,
                ],
                'extract_entities' => [
                    'type' => 'boolean',
                    'label' => 'Extract Entities',
                    'description' => 'Extract named entities (names, dates, places) from text',
                    'required' => false,
                    'default' => false,
                ],
            ],
            'inputs' => [
                'file_path' => 'Path to file to extract',
                'path' => 'Alternative: path to file',
                'local_path' => 'Alternative: local path from download node',
            ],
            'outputs' => [
                'text' => 'Extracted text content',
                'method' => 'Extraction method used (pdftotext, tesseract, vision, whisper, etc.)',
                'filename' => 'Original filename',
                'file_path' => 'Full file path',
                'exif' => 'EXIF metadata (for images)',
                'faces' => 'Face regions with names and coordinates',
                'face_count' => 'Number of faces detected',
                'people' => 'List of person names from face regions',
                'transcription' => 'Audio/video transcription',
                'media_metadata' => 'Audio/video metadata (title, artist, duration, etc.)',
                'generated_title' => 'AI-generated title (if enabled)',
                'stats' => 'Text statistics (char_count, word_count, line_count)',
            ],
        ];
    }
}
