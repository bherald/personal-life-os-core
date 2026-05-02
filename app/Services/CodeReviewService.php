<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Code Review MCP Service - AI-Assisted Code Analysis
 *
 * Provides tools for AI-powered code review including:
 * - Security vulnerability detection
 * - Performance analysis
 * - Best practices adherence
 * - Code style consistency
 * - Bug detection
 *
 * Tools provided (5):
 * - code_review: Full code review with configurable focus areas
 * - code_review_file: Review a file by path
 * - code_review_diff: Review a git diff
 * - code_suggest_improvements: Get improvement suggestions for code
 * - code_review_status: Service status and statistics
 */
class CodeReviewService
{
    private AIService $aiService;

    // Cache settings
    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'code_review:';

    // Review types
    public const REVIEW_SECURITY = 'security';
    public const REVIEW_PERFORMANCE = 'performance';
    public const REVIEW_BEST_PRACTICES = 'best_practices';
    public const REVIEW_STYLE = 'style';
    public const REVIEW_BUGS = 'bugs';

    // Severity levels
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    // Supported languages with their file extensions
    private const SUPPORTED_LANGUAGES = [
        'php' => ['.php'],
        'javascript' => ['.js', '.jsx', '.mjs'],
        'typescript' => ['.ts', '.tsx'],
        'python' => ['.py'],
        'vue' => ['.vue'],
        'css' => ['.css', '.scss', '.sass', '.less'],
        'html' => ['.html', '.htm'],
        'sql' => ['.sql'],
        'json' => ['.json'],
        'yaml' => ['.yaml', '.yml'],
        'bash' => ['.sh', '.bash'],
        'go' => ['.go'],
        'rust' => ['.rs'],
        'java' => ['.java'],
        'csharp' => ['.cs'],
    ];

    // Statistics tracking
    private static array $stats = [
        'reviews_performed' => 0,
        'issues_found' => 0,
        'suggestions_made' => 0,
        'cache_hits' => 0,
    ];

    public function __construct(?AIService $aiService = null)
    {
        $this->aiService = $aiService ?? app(AIService::class);
    }

    /**
     * Review code with AI analysis
     *
     * @param string $code The code to review
     * @param string $language Programming language
     * @param array $options Review options
     * @return array Review results
     */
    public function reviewCode(string $code, string $language, array $options = []): array
    {
        $startTime = microtime(true);

        // Parse options
        $focusAreas = $options['focus_areas'] ?? [
            self::REVIEW_SECURITY,
            self::REVIEW_PERFORMANCE,
            self::REVIEW_BEST_PRACTICES,
            self::REVIEW_BUGS,
        ];
        $severityThreshold = $options['severity_threshold'] ?? self::SEVERITY_LOW;
        $includeSuggestions = $options['include_suggestions'] ?? true;
        $useCache = $options['use_cache'] ?? true;

        // Validate language
        $language = $this->normalizeLanguage($language);
        if (!$this->isLanguageSupported($language)) {
            return [
                'success' => false,
                'error' => "Unsupported language: {$language}",
                'supported_languages' => array_keys(self::SUPPORTED_LANGUAGES),
            ];
        }

        // Check cache
        $cacheKey = $this->generateCacheKey($code, $language, $focusAreas);
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                self::$stats['cache_hits']++;
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        try {
            // Build the review prompt
            $prompt = $this->buildReviewPrompt($code, $language, $focusAreas, $includeSuggestions);

            // Call AI service
            $response = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'temperature' => 0.1,
                'system_prompt' => $this->getSystemPrompt($language),
            ]);

            if (empty($response['success']) || empty($response['response'])) {
                throw new Exception('AI service returned empty response');
            }

            // Parse AI response
            $result = $this->parseReviewResponse($response['response'], $severityThreshold);

            // Add metadata
            $result['success'] = true;
            $result['language'] = $language;
            $result['focus_areas'] = $focusAreas;
            $result['code_lines'] = substr_count($code, "\n") + 1;
            $result['code_chars'] = strlen($code);
            $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $result['from_cache'] = false;

            // Update stats
            self::$stats['reviews_performed']++;
            self::$stats['issues_found'] += count($result['issues'] ?? []);
            self::$stats['suggestions_made'] += count($result['suggestions'] ?? []);

            // Cache result
            if ($useCache) {
                Cache::put($cacheKey, $result, self::CACHE_TTL);
            }

            Log::info('CodeReviewService: Review completed', [
                'language' => $language,
                'lines' => $result['code_lines'],
                'issues_found' => count($result['issues'] ?? []),
                'duration_ms' => $result['duration_ms'],
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('CodeReviewService: Review failed', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Code review failed: ' . $e->getMessage(),
                'language' => $language,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Review a file by path
     *
     * @param string $filePath Path to the file
     * @param array $options Review options
     * @return array Review results
     */
    public function reviewFile(string $filePath, array $options = []): array
    {
        // Validate file exists
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => "File not found: {$filePath}",
            ];
        }

        // Check file size (limit to 100KB)
        $fileSize = filesize($filePath);
        if ($fileSize > 100 * 1024) {
            return [
                'success' => false,
                'error' => "File too large for review: " . round($fileSize / 1024, 1) . "KB (max 100KB)",
            ];
        }

        // Read file content
        $code = file_get_contents($filePath);
        if ($code === false) {
            return [
                'success' => false,
                'error' => "Failed to read file: {$filePath}",
            ];
        }

        // Detect language from extension
        $language = $options['language'] ?? $this->detectLanguage($filePath);
        if (!$language) {
            return [
                'success' => false,
                'error' => "Could not detect language for file: {$filePath}",
            ];
        }

        // Perform review
        $result = $this->reviewCode($code, $language, $options);
        $result['file_path'] = $filePath;
        $result['file_size'] = $fileSize;

        return $result;
    }

    /**
     * Review a git diff
     *
     * @param string $diff Git diff content
     * @param array $options Review options
     * @return array Review results
     */
    public function reviewDiff(string $diff, array $options = []): array
    {
        $startTime = microtime(true);

        // Parse diff to extract file changes
        $files = $this->parseDiff($diff);

        if (empty($files)) {
            return [
                'success' => false,
                'error' => 'No valid file changes found in diff',
            ];
        }

        try {
            // Build diff review prompt
            $prompt = $this->buildDiffReviewPrompt($diff, $files);

            // Call AI service
            $response = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'temperature' => 0.1,
                'system_prompt' => $this->getDiffSystemPrompt(),
            ]);

            if (empty($response['success']) || empty($response['response'])) {
                throw new Exception('AI service returned empty response');
            }

            // Parse response
            $result = $this->parseDiffReviewResponse($response['response']);

            // Add metadata
            $result['success'] = true;
            $result['files_changed'] = count($files);
            $result['files'] = array_map(fn($f) => $f['path'], $files);
            $result['additions'] = array_sum(array_column($files, 'additions'));
            $result['deletions'] = array_sum(array_column($files, 'deletions'));
            $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);

            // Update stats
            self::$stats['reviews_performed']++;
            self::$stats['issues_found'] += count($result['issues'] ?? []);

            Log::info('CodeReviewService: Diff review completed', [
                'files_changed' => count($files),
                'issues_found' => count($result['issues'] ?? []),
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('CodeReviewService: Diff review failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Diff review failed: ' . $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Get improvement suggestions for code
     *
     * @param string $code The code to analyze
     * @param string|null $language Programming language (auto-detect if null)
     * @return array Improvement suggestions
     */
    public function suggestImprovements(string $code, ?string $language = null): array
    {
        $startTime = microtime(true);

        // Detect or normalize language
        if (!$language) {
            return [
                'success' => false,
                'error' => 'Language must be specified for improvement suggestions',
            ];
        }

        $language = $this->normalizeLanguage($language);

        try {
            $prompt = $this->buildImprovementPrompt($code, $language);

            $response = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'temperature' => 0.2, // Slightly more creative for suggestions
                'system_prompt' => $this->getImprovementSystemPrompt($language),
            ]);

            if (empty($response['success']) || empty($response['response'])) {
                throw new Exception('AI service returned empty response');
            }

            $result = $this->parseImprovementResponse($response['response']);

            $result['success'] = true;
            $result['language'] = $language;
            $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);

            self::$stats['suggestions_made'] += count($result['suggestions'] ?? []);

            return $result;

        } catch (Exception $e) {
            Log::error('CodeReviewService: Improvement suggestions failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Improvement suggestions failed: ' . $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Get service status and statistics
     *
     * @return array Service status
     */
    public function getStatus(): array
    {
        return [
            'service' => 'CodeReviewService',
            'status' => 'active',
            'timestamp' => now()->toIso8601String(),
            'supported_languages' => array_keys(self::SUPPORTED_LANGUAGES),
            'review_types' => [
                self::REVIEW_SECURITY,
                self::REVIEW_PERFORMANCE,
                self::REVIEW_BEST_PRACTICES,
                self::REVIEW_STYLE,
                self::REVIEW_BUGS,
            ],
            'severity_levels' => [
                self::SEVERITY_CRITICAL,
                self::SEVERITY_HIGH,
                self::SEVERITY_MEDIUM,
                self::SEVERITY_LOW,
                self::SEVERITY_INFO,
            ],
            'statistics' => self::$stats,
            'cache_ttl_seconds' => self::CACHE_TTL,
            'max_file_size_kb' => 100,
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Normalize language identifier
     */
    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));

        // Handle common aliases
        $aliases = [
            'js' => 'javascript',
            'ts' => 'typescript',
            'py' => 'python',
            'sh' => 'bash',
            'yml' => 'yaml',
            'c#' => 'csharp',
        ];

        return $aliases[$language] ?? $language;
    }

    /**
     * Check if language is supported
     */
    private function isLanguageSupported(string $language): bool
    {
        return isset(self::SUPPORTED_LANGUAGES[$language]);
    }

    /**
     * Detect language from file extension
     */
    private function detectLanguage(string $filePath): ?string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $extension = '.' . $extension;

        foreach (self::SUPPORTED_LANGUAGES as $lang => $extensions) {
            if (in_array($extension, $extensions)) {
                return $lang;
            }
        }

        return null;
    }

    /**
     * Generate cache key for review
     */
    private function generateCacheKey(string $code, string $language, array $focusAreas): string
    {
        sort($focusAreas);
        $hash = md5($code . $language . implode(',', $focusAreas));
        return self::CACHE_PREFIX . $hash;
    }

    /**
     * Build the main review prompt
     */
    private function buildReviewPrompt(string $code, string $language, array $focusAreas, bool $includeSuggestions): string
    {
        $focusAreasList = implode(', ', $focusAreas);

        $prompt = <<<PROMPT
Analyze the following {$language} code for potential issues and improvements.

Focus areas: {$focusAreasList}

CODE:
```{$language}
{$code}
```

Provide your analysis in the following JSON format:
{
    "issues": [
        {
            "line": <line_number_or_null>,
            "type": "<security|performance|best_practices|style|bugs>",
            "severity": "<critical|high|medium|low|info>",
            "message": "<description of the issue>",
            "suggestion": "<how to fix it>"
        }
    ],
    "suggestions": [
        "<general improvement suggestion>"
    ],
    "overall_score": <0-100>,
    "summary": "<brief summary of code quality>"
}

Rules:
- Only report issues you are confident about
- Line numbers should reference the actual code lines
- Be specific in your suggestions
- Score 0-100 where 100 is perfect code
- Focus on actionable, practical feedback
PROMPT;

        return $prompt;
    }

    /**
     * Build diff review prompt
     */
    private function buildDiffReviewPrompt(string $diff, array $files): string
    {
        $fileList = implode(', ', array_map(fn($f) => $f['path'], $files));

        return <<<PROMPT
Review the following git diff for potential issues introduced by these changes.

Files changed: {$fileList}

DIFF:
```diff
{$diff}
```

Provide your analysis in JSON format:
{
    "issues": [
        {
            "file": "<filename>",
            "line": <line_number_or_null>,
            "type": "<security|performance|best_practices|style|bugs>",
            "severity": "<critical|high|medium|low|info>",
            "message": "<description of the issue>",
            "suggestion": "<how to fix it>"
        }
    ],
    "summary": "<brief summary of the changes and their quality>",
    "approval_recommendation": "<approve|request_changes|comment>"
}

Focus on:
- Issues INTRODUCED by the changes (not pre-existing)
- Security vulnerabilities in new code
- Performance regressions
- Logic errors in new code
- Breaking changes
PROMPT;
    }

    /**
     * Build improvement suggestions prompt
     */
    private function buildImprovementPrompt(string $code, string $language): string
    {
        return <<<PROMPT
Analyze the following {$language} code and suggest improvements for better code quality, maintainability, and performance.

CODE:
```{$language}
{$code}
```

Provide your suggestions in JSON format:
{
    "suggestions": [
        {
            "category": "<refactoring|performance|readability|maintainability|modernization>",
            "priority": "<high|medium|low>",
            "description": "<detailed description of the improvement>",
            "example": "<code example if applicable>"
        }
    ],
    "summary": "<overall assessment and top priorities>"
}

Focus on:
- Modern {$language} best practices
- Code organization and structure
- Performance optimizations
- Readability improvements
- Reducing technical debt
PROMPT;
    }

    /**
     * Get system prompt for code review
     */
    private function getSystemPrompt(string $language): string
    {
        return <<<PROMPT
You are an expert code reviewer specializing in {$language}. Your role is to:
1. Identify bugs, security vulnerabilities, and performance issues
2. Suggest improvements following industry best practices
3. Be precise about line numbers when reporting issues
4. Provide actionable, specific feedback
5. Always respond in valid JSON format

Be thorough but practical - focus on issues that matter in production code.
PROMPT;
    }

    /**
     * Get system prompt for diff review
     */
    private function getDiffSystemPrompt(): string
    {
        return <<<PROMPT
You are an expert code reviewer performing a pull request review. Your role is to:
1. Focus on issues INTRODUCED by the changes shown in the diff
2. Identify security vulnerabilities, bugs, and performance regressions
3. Consider backward compatibility and breaking changes
4. Be constructive and specific in your feedback
5. Always respond in valid JSON format

Remember: Only flag issues in the NEW code (+ lines), not pre-existing issues.
PROMPT;
    }

    /**
     * Get system prompt for improvement suggestions
     */
    private function getImprovementSystemPrompt(string $language): string
    {
        return <<<PROMPT
You are a senior {$language} developer providing code improvement suggestions. Your role is to:
1. Suggest modernization opportunities
2. Identify refactoring possibilities
3. Recommend performance optimizations
4. Improve code readability and maintainability
5. Always respond in valid JSON format

Focus on practical improvements that provide real value.
PROMPT;
    }

    /**
     * Parse AI review response into structured format
     */
    private function parseReviewResponse(string $response, string $severityThreshold): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($response);

        if (!$json) {
            Log::warning('CodeReviewService: Could not parse JSON from response', [
                'response_preview' => substr($response, 0, 500),
            ]);

            return [
                'issues' => [],
                'suggestions' => [],
                'overall_score' => 50,
                'summary' => 'Unable to parse review response. Please try again.',
                'raw_response' => $response,
            ];
        }

        // Filter issues by severity threshold
        $severityOrder = [
            self::SEVERITY_CRITICAL => 5,
            self::SEVERITY_HIGH => 4,
            self::SEVERITY_MEDIUM => 3,
            self::SEVERITY_LOW => 2,
            self::SEVERITY_INFO => 1,
        ];

        $thresholdLevel = $severityOrder[$severityThreshold] ?? 2;

        $issues = $json['issues'] ?? [];
        $filteredIssues = array_filter($issues, function ($issue) use ($severityOrder, $thresholdLevel) {
            $issueSeverity = $issue['severity'] ?? self::SEVERITY_INFO;
            $issueLevel = $severityOrder[$issueSeverity] ?? 1;
            return $issueLevel >= $thresholdLevel;
        });

        return [
            'issues' => array_values($filteredIssues),
            'suggestions' => $json['suggestions'] ?? [],
            'overall_score' => $json['overall_score'] ?? 50,
            'summary' => $json['summary'] ?? 'Review completed.',
        ];
    }

    /**
     * Parse diff review response
     */
    private function parseDiffReviewResponse(string $response): array
    {
        $json = $this->extractJson($response);

        if (!$json) {
            return [
                'issues' => [],
                'summary' => 'Unable to parse review response.',
                'approval_recommendation' => 'comment',
                'raw_response' => $response,
            ];
        }

        return [
            'issues' => $json['issues'] ?? [],
            'summary' => $json['summary'] ?? 'Review completed.',
            'approval_recommendation' => $json['approval_recommendation'] ?? 'comment',
        ];
    }

    /**
     * Parse improvement suggestions response
     */
    private function parseImprovementResponse(string $response): array
    {
        $json = $this->extractJson($response);

        if (!$json) {
            return [
                'suggestions' => [],
                'summary' => 'Unable to parse suggestions response.',
                'raw_response' => $response,
            ];
        }

        return [
            'suggestions' => $json['suggestions'] ?? [],
            'summary' => $json['summary'] ?? 'Analysis completed.',
        ];
    }

    /**
     * Extract JSON from AI response (handles markdown code blocks)
     */
    private function extractJson(string $response): ?array
    {
        // Try direct JSON parse first
        $decoded = json_decode($response, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        // Try to find JSON object in response
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Parse git diff into file changes
     */
    private function parseDiff(string $diff): array
    {
        $files = [];
        $currentFile = null;
        $additions = 0;
        $deletions = 0;

        foreach (explode("\n", $diff) as $line) {
            // Detect file header
            if (preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $matches)) {
                // Save previous file
                if ($currentFile) {
                    $files[] = [
                        'path' => $currentFile,
                        'additions' => $additions,
                        'deletions' => $deletions,
                    ];
                }

                $currentFile = $matches[2];
                $additions = 0;
                $deletions = 0;
            } elseif ($currentFile) {
                // Count additions and deletions
                if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                    $additions++;
                } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                    $deletions++;
                }
            }
        }

        // Save last file
        if ($currentFile) {
            $files[] = [
                'path' => $currentFile,
                'additions' => $additions,
                'deletions' => $deletions,
            ];
        }

        return $files;
    }

    // ==================== MCP TOOL METHODS ====================

    /**
     * MCP Tool: Review code
     *
     * @param array $params ['code' => string, 'language' => string, 'options' => array]
     * @return array Review results
     */
    public function code_review(array $params): array
    {
        $code = $params['code'] ?? '';
        $language = $params['language'] ?? 'php';
        $options = $params['options'] ?? [];

        if (empty($code)) {
            return [
                'success' => false,
                'error' => 'Code is required',
            ];
        }

        return $this->reviewCode($code, $language, $options);
    }

    /**
     * MCP Tool: Review file
     *
     * @param array $params ['file_path' => string, 'options' => array]
     * @return array Review results
     */
    public function code_review_file(array $params): array
    {
        $filePath = $params['file_path'] ?? '';
        $options = $params['options'] ?? [];

        if (empty($filePath)) {
            return [
                'success' => false,
                'error' => 'File path is required',
            ];
        }

        return $this->reviewFile($filePath, $options);
    }

    /**
     * MCP Tool: Review diff
     *
     * @param array $params ['diff' => string, 'options' => array]
     * @return array Review results
     */
    public function code_review_diff(array $params): array
    {
        $diff = $params['diff'] ?? '';
        $options = $params['options'] ?? [];

        if (empty($diff)) {
            return [
                'success' => false,
                'error' => 'Diff content is required',
            ];
        }

        return $this->reviewDiff($diff, $options);
    }

    /**
     * MCP Tool: Suggest improvements
     *
     * @param array $params ['code' => string, 'language' => string]
     * @return array Improvement suggestions
     */
    public function code_suggest_improvements(array $params): array
    {
        $code = $params['code'] ?? '';
        $language = $params['language'] ?? null;

        if (empty($code)) {
            return [
                'success' => false,
                'error' => 'Code is required',
            ];
        }

        return $this->suggestImprovements($code, $language);
    }

    /**
     * MCP Tool: Get service status
     *
     * @param array $params Unused
     * @return array Service status
     */
    public function code_review_status(array $params = []): array
    {
        return $this->getStatus();
    }
}
