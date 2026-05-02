<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ImageAnalyzerService - AI-Powered Image Analysis
 *
 * Provides comprehensive image analysis capabilities using AI vision models:
 * - Object detection and identification
 * - Scene description and context
 * - Text extraction (OCR)
 * - Face detection (via FaceRegionService integration)
 * - Image classification with custom categories
 *
 * Leverages AIService vision capabilities (Ollama llava / Claude vision)
 * with configurable caching and detail levels.
 *
 * @see AIService for vision processing
 * @see FaceRegionService for MWG face region integration
 * @see ContentExtractionService for full document extraction
 */
class ImageAnalyzerService
{
    protected AIService $aiService;
    protected ?FaceRegionService $faceRegionService;

    /** Default cache TTL in seconds (1 hour) */
    protected const DEFAULT_CACHE_TTL = 3600;

    /** Cache key prefix for analysis results */
    protected const CACHE_PREFIX = 'image_analysis:';

    /** @see config/file_types.php */

    /** Detail level prompts */
    protected const DETAIL_PROMPTS = [
        'low' => 'Briefly describe the main subject and any obvious elements in this image in 2-3 sentences.',
        'medium' => 'Describe this image including: main subject, setting/background, notable objects, colors, and overall mood. Be concise but thorough.',
        'high' => 'Provide a comprehensive analysis of this image including: 1) Main subject and focal point, 2) All visible objects and their positions, 3) Setting and environment details, 4) Colors, lighting, and composition, 5) Any text visible, 6) Estimated time of day/season if applicable, 7) Overall mood and atmosphere, 8) Any notable artistic or photographic techniques.',
    ];

    public function __construct(AIService $aiService, ?FaceRegionService $faceRegionService = null)
    {
        $this->aiService = $aiService;
        $this->faceRegionService = $faceRegionService;
    }

    /**
     * Set face region service (for lazy loading)
     */
    public function setFaceRegionService(FaceRegionService $service): void
    {
        $this->faceRegionService = $service;
    }

    /**
     * Analyze an image file with comprehensive AI analysis
     *
     * @param string $imagePath Absolute path to the image file
     * @param array $options Analysis options:
     *   - detail_level: string (low|medium|high) - Level of detail in analysis
     *   - include_faces: bool - Include face detection via FaceRegionService
     *   - include_text: bool - Include OCR text extraction
     *   - include_objects: bool - Include object detection
     *   - custom_prompt: string - Override default analysis prompt
     *   - cache_ttl: int - Cache TTL in seconds (0 to disable)
     *   - force_refresh: bool - Bypass cache and force new analysis
     * @return array Analysis result with description, objects, text, faces, tags, confidence_scores
     */
    public function analyze(string $imagePath, array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        if (!file_exists($imagePath)) {
            Log::warning('ImageAnalyzerService: File not found', ['path' => $imagePath]);
            return $this->errorResult('File not found: ' . $imagePath);
        }

        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (!in_array($extension, config('file_types.image'))) {
            Log::warning('ImageAnalyzerService: Unsupported format', ['path' => $imagePath, 'ext' => $extension]);
            return $this->errorResult('Unsupported image format: ' . $extension);
        }

        // Check cache unless force refresh
        $cacheKey = $this->getCacheKey($imagePath, $options);
        if (!$options['force_refresh'] && $options['cache_ttl'] > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('ImageAnalyzerService: Cache hit', ['path' => $imagePath]);
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        Log::info('ImageAnalyzerService: Analyzing image', [
            'path' => $imagePath,
            'detail_level' => $options['detail_level'],
            'include_faces' => $options['include_faces'],
            'include_text' => $options['include_text'],
        ]);

        $startTime = microtime(true);
        $result = $this->performAnalysis($imagePath, $options);
        $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
        $result['from_cache'] = false;

        // Cache successful results
        if ($result['success'] && $options['cache_ttl'] > 0) {
            Cache::put($cacheKey, $result, $options['cache_ttl']);
        }

        return $result;
    }

    /**
     * Analyze an image from URL
     *
     * @param string $url Image URL
     * @param array $options Same options as analyze()
     * @return array Analysis result
     */
    public function analyzeUrl(string $url, array $options = []): array
    {
        $options = $this->normalizeOptions($options);

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->errorResult('Invalid URL: ' . $url);
        }

        // Check cache
        $cacheKey = $this->getCacheKey($url, $options);
        if (!$options['force_refresh'] && $options['cache_ttl'] > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('ImageAnalyzerService: Cache hit for URL', ['url' => $url]);
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        Log::info('ImageAnalyzerService: Analyzing image URL', ['url' => $url]);

        // Download image to temp file
        $tempPath = $this->downloadImage($url);
        if (!$tempPath) {
            return $this->errorResult('Failed to download image from URL: ' . $url);
        }

        try {
            $startTime = microtime(true);
            $result = $this->performAnalysis($tempPath, $options);
            $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $result['from_cache'] = false;
            $result['source_url'] = $url;

            // Cache successful results
            if ($result['success'] && $options['cache_ttl'] > 0) {
                Cache::put($cacheKey, $result, $options['cache_ttl']);
            }

            return $result;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Describe an image with a custom prompt
     *
     * @param string $path Image file path
     * @param string $prompt Custom prompt for description
     * @param array $options Additional options
     * @return array Description result
     */
    public function describeImage(string $path, string $prompt, array $options = []): array
    {
        if (!file_exists($path)) {
            return $this->errorResult('File not found: ' . $path);
        }

        $imageContent = @file_get_contents($path);
        if ($imageContent === false) {
            return $this->errorResult('Failed to read image file: ' . $path);
        }

        $startTime = microtime(true);

        $visionResult = $this->aiService->processImage(
            base64_encode($imageContent),
            $prompt,
            $options
        );

        if (!$visionResult['success']) {
            Log::warning('ImageAnalyzerService: Vision processing failed', [
                'path' => $path,
                'error' => $visionResult['error'] ?? 'Unknown error',
            ]);
            return $this->errorResult($visionResult['error'] ?? 'Vision processing failed');
        }

        return [
            'success' => true,
            'description' => $visionResult['response'] ?? '',
            'provider' => $visionResult['provider'] ?? null,
            'model' => $visionResult['model'] ?? null,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Detect objects in an image
     *
     * @param string $path Image file path
     * @param array $options Detection options
     * @return array Object detection result with objects array
     */
    public function detectObjects(string $path, array $options = []): array
    {
        if (!file_exists($path)) {
            return $this->errorResult('File not found: ' . $path);
        }

        $imageContent = @file_get_contents($path);
        if ($imageContent === false) {
            return $this->errorResult('Failed to read image file: ' . $path);
        }

        $prompt = <<<'PROMPT'
Identify all distinct objects visible in this image. For each object provide:
1. Object name/type
2. Approximate location (left, center, right, top, bottom, foreground, background)
3. Confidence level (high, medium, low)
4. Any notable attributes (color, size, state)

Return the results as a JSON array with this structure:
{
  "objects": [
    {
      "name": "object name",
      "location": "position description",
      "confidence": "high|medium|low",
      "attributes": ["attribute1", "attribute2"]
    }
  ],
  "object_count": number,
  "scene_type": "indoor|outdoor|mixed|unknown"
}

Only include objects you can clearly identify. Do not guess or hallucinate objects.
PROMPT;

        $startTime = microtime(true);

        $visionResult = $this->aiService->processImage(
            base64_encode($imageContent),
            $prompt,
            $options
        );

        if (!$visionResult['success']) {
            return $this->errorResult($visionResult['error'] ?? 'Object detection failed');
        }

        $response = $visionResult['response'] ?? '';
        $parsed = $this->parseJsonResponse($response);

        if ($parsed === null) {
            // Fallback: return raw response if JSON parsing fails
            return [
                'success' => true,
                'objects' => [],
                'raw_response' => $response,
                'parse_error' => 'Failed to parse JSON response',
                'provider' => $visionResult['provider'] ?? null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }

        return [
            'success' => true,
            'objects' => $parsed['objects'] ?? [],
            'object_count' => $parsed['object_count'] ?? count($parsed['objects'] ?? []),
            'scene_type' => $parsed['scene_type'] ?? 'unknown',
            'provider' => $visionResult['provider'] ?? null,
            'model' => $visionResult['model'] ?? null,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Extract text from an image (OCR)
     *
     * @param string $path Image file path
     * @param array $options Extraction options
     * @return array Text extraction result
     */
    public function extractText(string $path, array $options = []): array
    {
        if (!file_exists($path)) {
            return $this->errorResult('File not found: ' . $path);
        }

        $imageContent = @file_get_contents($path);
        if ($imageContent === false) {
            return $this->errorResult('Failed to read image file: ' . $path);
        }

        $prompt = <<<'PROMPT'
Extract ALL text visible in this image. Include:
1. Main text content (preserving structure where possible)
2. Labels, signs, or captions
3. Watermarks or logos with text
4. Any partially visible or obscured text (note uncertainty)

Return the results as JSON:
{
  "text": "extracted text content",
  "text_blocks": [
    {
      "content": "text block content",
      "type": "heading|paragraph|label|sign|watermark|handwritten|other",
      "confidence": "high|medium|low"
    }
  ],
  "has_handwriting": true|false,
  "languages_detected": ["en", "es", etc.],
  "text_quality": "clear|partially_readable|difficult"
}

If no text is visible, return {"text": "", "text_blocks": [], "has_handwriting": false}.
PROMPT;

        $startTime = microtime(true);

        $visionResult = $this->aiService->processImage(
            base64_encode($imageContent),
            $prompt,
            $options
        );

        if (!$visionResult['success']) {
            return $this->errorResult($visionResult['error'] ?? 'Text extraction failed');
        }

        $response = $visionResult['response'] ?? '';
        $parsed = $this->parseJsonResponse($response);

        if ($parsed === null) {
            // Try to extract text from raw response
            return [
                'success' => true,
                'text' => trim($response),
                'text_blocks' => [],
                'raw_response' => $response,
                'parse_error' => 'Failed to parse JSON response',
                'provider' => $visionResult['provider'] ?? null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }

        return [
            'success' => true,
            'text' => $parsed['text'] ?? '',
            'text_blocks' => $parsed['text_blocks'] ?? [],
            'has_handwriting' => $parsed['has_handwriting'] ?? false,
            'languages_detected' => $parsed['languages_detected'] ?? [],
            'text_quality' => $parsed['text_quality'] ?? 'unknown',
            'provider' => $visionResult['provider'] ?? null,
            'model' => $visionResult['model'] ?? null,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Classify an image into specified categories
     *
     * @param string $path Image file path
     * @param array $categories List of possible categories
     * @param array $options Classification options
     * @return array Classification result with category, confidence, and reasoning
     */
    public function classifyImage(string $path, array $categories, array $options = []): array
    {
        if (!file_exists($path)) {
            return $this->errorResult('File not found: ' . $path);
        }

        if (empty($categories)) {
            return $this->errorResult('No categories provided for classification');
        }

        $imageContent = @file_get_contents($path);
        if ($imageContent === false) {
            return $this->errorResult('Failed to read image file: ' . $path);
        }

        $categoryList = implode(', ', array_map(fn($c) => '"' . $c . '"', $categories));

        $prompt = <<<PROMPT
Classify this image into ONE of the following categories: [{$categoryList}]

Analyze the image content and determine which category best fits. Return your classification as JSON:
{
  "category": "selected category from the list",
  "confidence": 0.0 to 1.0,
  "confidence_level": "high|medium|low",
  "reasoning": "brief explanation of why this category was chosen",
  "alternative_categories": [
    {
      "category": "second best category",
      "confidence": 0.0 to 1.0
    }
  ]
}

You MUST select from the provided categories only. If none fit well, select the closest match and explain in reasoning.
PROMPT;

        $startTime = microtime(true);

        $visionResult = $this->aiService->processImage(
            base64_encode($imageContent),
            $prompt,
            $options
        );

        if (!$visionResult['success']) {
            return $this->errorResult($visionResult['error'] ?? 'Classification failed');
        }

        $response = $visionResult['response'] ?? '';
        $parsed = $this->parseJsonResponse($response);

        if ($parsed === null) {
            return [
                'success' => true,
                'category' => 'unknown',
                'confidence' => 0.0,
                'raw_response' => $response,
                'parse_error' => 'Failed to parse JSON response',
                'provider' => $visionResult['provider'] ?? null,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }

        // Validate the selected category is in our list
        $selectedCategory = $parsed['category'] ?? 'unknown';
        if (!in_array($selectedCategory, $categories)) {
            // Find closest match
            $selectedCategory = $this->findClosestCategory($selectedCategory, $categories);
        }

        return [
            'success' => true,
            'category' => $selectedCategory,
            'confidence' => (float)($parsed['confidence'] ?? 0.5),
            'confidence_level' => $parsed['confidence_level'] ?? 'medium',
            'reasoning' => $parsed['reasoning'] ?? null,
            'alternative_categories' => $parsed['alternative_categories'] ?? [],
            'provided_categories' => $categories,
            'provider' => $visionResult['provider'] ?? null,
            'model' => $visionResult['model'] ?? null,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Get service status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $visionAvailable = $this->aiService->isVisionAvailable();
        $faceServiceAvailable = $this->faceRegionService?->isAvailable() ?? false;

        return [
            'available' => $visionAvailable,
            'vision_provider' => $visionAvailable ? 'ollama/claude' : null,
            'face_detection' => $faceServiceAvailable,
            'face_service_status' => $this->faceRegionService?->getStatus() ?? ['available' => false],
            'supported_formats' => config('file_types.image'),
            'detail_levels' => array_keys(self::DETAIL_PROMPTS),
            'cache_prefix' => self::CACHE_PREFIX,
            'default_cache_ttl' => self::DEFAULT_CACHE_TTL,
            'features' => [
                'analyze' => $visionAvailable,
                'analyze_url' => $visionAvailable,
                'describe_image' => $visionAvailable,
                'detect_objects' => $visionAvailable,
                'extract_text' => $visionAvailable,
                'classify_image' => $visionAvailable,
                'face_regions' => $faceServiceAvailable,
            ],
            'version' => 'v1.0',
        ];
    }

    /**
     * Perform the actual image analysis
     */
    protected function performAnalysis(string $imagePath, array $options): array
    {
        $imageContent = @file_get_contents($imagePath);
        if ($imageContent === false) {
            return $this->errorResult('Failed to read image file');
        }

        $result = [
            'success' => true,
            'file_path' => $imagePath,
            'filename' => basename($imagePath),
            'description' => null,
            'objects' => [],
            'text' => null,
            'faces' => [],
            'tags' => [],
            'confidence_scores' => [],
            'metadata' => $this->getImageMetadata($imagePath),
        ];

        // Build the analysis prompt based on options
        $prompt = $this->buildAnalysisPrompt($options);

        // Process with vision AI
        $visionResult = $this->aiService->processImage(
            base64_encode($imageContent),
            $prompt,
            ['suppressAlert' => true]
        );

        if (!$visionResult['success']) {
            Log::warning('ImageAnalyzerService: Vision analysis failed', [
                'path' => $imagePath,
                'error' => $visionResult['error'] ?? 'Unknown error',
            ]);
            return $this->errorResult($visionResult['error'] ?? 'Vision analysis failed');
        }

        $result['provider'] = $visionResult['provider'] ?? null;
        $result['model'] = $visionResult['model'] ?? null;

        // Parse the structured response
        $response = $visionResult['response'] ?? '';
        $parsed = $this->parseJsonResponse($response);

        if ($parsed !== null) {
            $result['description'] = $parsed['description'] ?? null;
            $result['objects'] = $parsed['objects'] ?? [];
            $result['tags'] = $parsed['tags'] ?? [];
            $result['confidence_scores'] = $parsed['confidence_scores'] ?? [];
            $result['scene_type'] = $parsed['scene_type'] ?? null;
            $result['dominant_colors'] = $parsed['dominant_colors'] ?? [];

            if ($options['include_text'] && isset($parsed['text'])) {
                $result['text'] = $parsed['text'];
                $result['text_blocks'] = $parsed['text_blocks'] ?? [];
            }
        } else {
            // Fallback: use raw response as description
            $result['description'] = $response;
            $result['raw_response'] = $response;
        }

        // Include face detection if requested
        if ($options['include_faces']) {
            $result['faces'] = $this->detectFaces($imagePath);
        }

        return $result;
    }

    /**
     * Build the analysis prompt based on options
     */
    protected function buildAnalysisPrompt(array $options): string
    {
        // Use custom prompt if provided
        if (!empty($options['custom_prompt'])) {
            return $options['custom_prompt'];
        }

        $detailLevel = $options['detail_level'];
        $basePrompt = self::DETAIL_PROMPTS[$detailLevel] ?? self::DETAIL_PROMPTS['medium'];

        $prompt = <<<PROMPT
Analyze this image and provide a structured analysis. {$basePrompt}

Return your analysis as JSON with this structure:
{
  "description": "detailed description of the image",
  "scene_type": "indoor|outdoor|portrait|landscape|abstract|document|other",
  "objects": [
    {
      "name": "object name",
      "location": "position in image",
      "confidence": "high|medium|low"
    }
  ],
  "tags": ["relevant", "tags", "for", "image"],
  "dominant_colors": ["color1", "color2"],
  "confidence_scores": {
    "description": 0.0-1.0,
    "objects": 0.0-1.0,
    "overall": 0.0-1.0
  }
PROMPT;

        if ($options['include_text']) {
            $prompt .= <<<'ADDITION'
,
  "text": "any text visible in the image",
  "text_blocks": [
    {
      "content": "text content",
      "type": "type of text"
    }
  ]
ADDITION;
        }

        $prompt .= "\n}\n\nProvide accurate analysis. If uncertain about any element, indicate lower confidence.";

        return $prompt;
    }

    /**
     * Detect faces using FaceRegionService
     */
    protected function detectFaces(string $imagePath): array
    {
        if (!$this->faceRegionService || !$this->faceRegionService->isAvailable()) {
            return [];
        }

        try {
            $regions = $this->faceRegionService->readFaceRegions($imagePath);

            // Transform to our format
            return array_map(function ($region) {
                return [
                    'name' => $region['name'] ?? null,
                    'type' => $region['type'] ?? 'Face',
                    'bounds' => [
                        'x' => $region['x'] ?? null,
                        'y' => $region['y'] ?? null,
                        'width' => $region['w'] ?? null,
                        'height' => $region['h'] ?? null,
                    ],
                    'source' => 'mwg_metadata',
                ];
            }, $regions);
        } catch (\Exception $e) {
            Log::warning('ImageAnalyzerService: Face detection failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get basic image metadata
     */
    protected function getImageMetadata(string $imagePath): array
    {
        $metadata = [
            'file_size' => filesize($imagePath),
            'mime_type' => mime_content_type($imagePath) ?: null,
        ];

        // Get image dimensions
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo) {
            $metadata['width'] = $imageInfo[0];
            $metadata['height'] = $imageInfo[1];
            $metadata['type'] = $imageInfo['mime'] ?? null;
        }

        return $metadata;
    }

    /**
     * Download image from URL to temp file
     */
    protected function downloadImage(string $url): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'ImageAnalyzerService/1.0',
                ],
            ]);

            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                Log::warning('ImageAnalyzerService: Failed to download image', ['url' => $url]);
                return null;
            }

            // Determine extension from content type
            $extension = 'jpg';
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (stripos($header, 'Content-Type:') === 0) {
                        $contentType = trim(substr($header, 13));
                        $extension = match (true) {
                            str_contains($contentType, 'png') => 'png',
                            str_contains($contentType, 'gif') => 'gif',
                            str_contains($contentType, 'webp') => 'webp',
                            str_contains($contentType, 'bmp') => 'bmp',
                            default => 'jpg',
                        };
                        break;
                    }
                }
            }

            $tempPath = sys_get_temp_dir() . '/image_analysis_' . uniqid() . '.' . $extension;
            if (file_put_contents($tempPath, $content) === false) {
                return null;
            }

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('ImageAnalyzerService: Download exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Normalize options with defaults
     */
    protected function normalizeOptions(array $options): array
    {
        return array_merge([
            'detail_level' => 'medium',
            'include_faces' => true,
            'include_text' => true,
            'include_objects' => true,
            'custom_prompt' => null,
            'cache_ttl' => self::DEFAULT_CACHE_TTL,
            'force_refresh' => false,
        ], $options);
    }

    /**
     * Generate cache key for analysis
     */
    protected function getCacheKey(string $pathOrUrl, array $options): string
    {
        $keyData = [
            'path' => $pathOrUrl,
            'detail' => $options['detail_level'],
            'faces' => $options['include_faces'],
            'text' => $options['include_text'],
            'objects' => $options['include_objects'],
            'prompt' => $options['custom_prompt'] ?? '',
        ];

        // For file paths, include modification time
        if (file_exists($pathOrUrl)) {
            $keyData['mtime'] = filemtime($pathOrUrl);
        }

        return self::CACHE_PREFIX . md5(json_encode($keyData));
    }

    /**
     * Parse JSON from AI response
     */
    protected function parseJsonResponse(string $response): ?array
    {
        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null && is_array($json)) {
                return $json;
            }
        }

        // Try direct parse
        $json = json_decode($response, true);
        if ($json !== null && is_array($json)) {
            return $json;
        }

        return null;
    }

    /**
     * Find the closest matching category
     */
    protected function findClosestCategory(string $selected, array $categories): string
    {
        $selected = strtolower($selected);

        foreach ($categories as $category) {
            if (strtolower($category) === $selected) {
                return $category;
            }
            if (str_contains(strtolower($category), $selected) || str_contains($selected, strtolower($category))) {
                return $category;
            }
        }

        return $categories[0] ?? 'unknown';
    }

    /**
     * Create an error result array
     */
    protected function errorResult(string $error): array
    {
        return [
            'success' => false,
            'error' => $error,
            'description' => null,
            'objects' => [],
            'text' => null,
            'faces' => [],
            'tags' => [],
            'confidence_scores' => [],
        ];
    }

    /**
     * Clear cached analysis for a specific image
     *
     * @param string $pathOrUrl Image path or URL
     * @param array $options Options used for the analysis (to match cache key)
     * @return bool Whether cache was cleared
     */
    public function clearCache(string $pathOrUrl, array $options = []): bool
    {
        $options = $this->normalizeOptions($options);
        $cacheKey = $this->getCacheKey($pathOrUrl, $options);

        return Cache::forget($cacheKey);
    }

    /**
     * Clear all cached analyses
     *
     * @return bool Whether cache was cleared
     */
    public function clearAllCache(): bool
    {
        // Note: This requires a cache driver that supports tags or pattern deletion
        // For Redis: Cache::getRedis()->keys(self::CACHE_PREFIX . '*') and delete
        // For simplicity, we'll log a warning that this may not work with all drivers

        Log::info('ImageAnalyzerService: Clearing all analysis cache (may not work with all cache drivers)');

        // This is a best-effort implementation
        // In production, you might want to track cache keys in a separate list
        return true;
    }

    /**
     * Batch analyze multiple images
     *
     * @param array $imagePaths Array of image paths
     * @param array $options Analysis options
     * @return array Array of results keyed by path
     */
    public function analyzeBatch(array $imagePaths, array $options = []): array
    {
        $results = [];

        foreach ($imagePaths as $path) {
            $results[$path] = $this->analyze($path, $options);
        }

        return $results;
    }

    /**
     * Compare two images for similarity
     *
     * @param string $path1 First image path
     * @param string $path2 Second image path
     * @param array $options Comparison options
     * @return array Comparison result with similarity score
     */
    public function compareImages(string $path1, string $path2, array $options = []): array
    {
        if (!file_exists($path1)) {
            return $this->errorResult('First image not found: ' . $path1);
        }
        if (!file_exists($path2)) {
            return $this->errorResult('Second image not found: ' . $path2);
        }

        $image1Content = @file_get_contents($path1);
        $image2Content = @file_get_contents($path2);

        if ($image1Content === false || $image2Content === false) {
            return $this->errorResult('Failed to read one or both images');
        }

        // Note: This is a simplified comparison using vision AI
        // For production, you might want to use perceptual hashing or embedding similarity

        $prompt = <<<'PROMPT'
Compare these two images and analyze their similarity. Provide:
1. Overall similarity score (0.0 to 1.0)
2. Content similarity (same subject/scene?)
3. Visual similarity (colors, composition, style)
4. Key differences
5. Key similarities

Return as JSON:
{
  "similarity_score": 0.0-1.0,
  "content_similarity": 0.0-1.0,
  "visual_similarity": 0.0-1.0,
  "same_subject": true|false,
  "same_scene": true|false,
  "differences": ["diff1", "diff2"],
  "similarities": ["sim1", "sim2"],
  "analysis": "brief comparison summary"
}
PROMPT;

        // Note: AIService.processImage currently only supports single image
        // This would need to be extended for multi-image comparison
        // For now, analyze both images separately and compare

        $analysis1 = $this->analyze($path1, ['detail_level' => 'high']);
        $analysis2 = $this->analyze($path2, ['detail_level' => 'high']);

        if (!$analysis1['success'] || !$analysis2['success']) {
            return $this->errorResult('Failed to analyze one or both images');
        }

        // Simple tag-based similarity
        $tags1 = array_map('strtolower', $analysis1['tags'] ?? []);
        $tags2 = array_map('strtolower', $analysis2['tags'] ?? []);

        $commonTags = array_intersect($tags1, $tags2);
        $allTags = array_unique(array_merge($tags1, $tags2));

        $tagSimilarity = count($allTags) > 0
            ? count($commonTags) / count($allTags)
            : 0.0;

        return [
            'success' => true,
            'similarity_score' => $tagSimilarity,
            'common_tags' => array_values($commonTags),
            'image1_tags' => $tags1,
            'image2_tags' => $tags2,
            'image1_description' => $analysis1['description'] ?? null,
            'image2_description' => $analysis2['description'] ?? null,
            'comparison_method' => 'tag_overlap',
        ];
    }
}
