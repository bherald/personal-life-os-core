<?php

namespace App\Nodes;

use App\Services\AIService;
use App\Services\BiasRatingService;
use App\Traits\RecursionAware;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Batch Processor Node
 *
 * Processes large sets of articles in batches to avoid LLM context limits.
 * Each batch is self-contained and goes through bias enrichment + AI formatting.
 *
 * Configuration:
 * - batch_size: (optional) Articles per batch (default: 15)
 * - max_articles: (optional) Maximum total articles to process (default: 60)
 * - ai_prompt: (required) Prompt template for AI formatting
 * - ai_timeout: (optional) Timeout for AI processing (default: 180s)
 * - pushover_format: (optional) Pushover formatting style (html/monospace)
 *
 * Uses AIService for resilience (circuit breaker, retry, fallback).
 */
class BatchProcessor extends BaseNode
{
    use RecursionAware;

    private const STRICT_FORMATTER_SYSTEM_PROMPT = <<<'PROMPT'
You format article data into final notification text. Output only the finished article lines requested by the user prompt. Do not include analysis, chain-of-thought, task restatements, headings that were not requested, prompt text, instructions, rules, examples, context labels, or batch commentary. Use only the supplied article fields.
PROMPT;

    private BiasRatingService $biasService;

    private AIService $aiService;

    public function execute(array $input): array
    {
        $disableRecursion = $this->resolveBooleanConfig('disable_recursion', false);

        // RLM: Try recursive batch processing
        if (! $disableRecursion) {
            $rlm = $this->tryRecursive('batch_processor', 'partition_map', ['input' => $input], function ($ctx) {
                return $this->execute($ctx['input']);
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        try {
            $this->biasService = app(BiasRatingService::class);
            $this->aiService = app(AIService::class);
            $fallbackFormattedText = $this->extractFormattedText($input);

            // Configuration
            $batchSize = (int) $this->getConfigValue('batch_size', 15);
            $maxArticles = (int) $this->getConfigValue('max_articles', 60);
            $aiPrompt = $this->getConfigValue('ai_prompt');
            $aiTimeout = (int) $this->getConfigValue('ai_timeout', 180);
            $pushoverFormat = $this->getConfigValue('pushover_format', 'html');

            if (! $aiPrompt) {
                throw new Exception('ai_prompt configuration is required for BatchProcessor');
            }

            // Extract articles from input
            $articles = $this->extractArticles($input);

            if (empty($articles)) {
                return $this->standardOutput(
                    'No articles found to process.',
                    ['batch_count' => 0, 'total_articles' => 0],
                    null
                );
            }

            // Enforce article limit
            if (count($articles) > $maxArticles) {
                Log::info('BatchProcessor: Limiting articles', [
                    'available' => count($articles),
                    'limit' => $maxArticles,
                ]);
                $articles = array_slice($articles, 0, $maxArticles);
            }

            // Calculate batches
            $batches = array_chunk($articles, $batchSize);
            $batchCount = count($batches);

            Log::info('BatchProcessor: Starting batch processing', [
                'total_articles' => count($articles),
                'batch_size' => $batchSize,
                'batch_count' => $batchCount,
            ]);

            // Process batches sequentially
            $batchResults = [];
            $enrichmentStats = [
                'total_enriched' => 0,
                'total_processed' => 0,
                'sanitized_batches' => 0,
                'fallback_batches' => 0,
                'ai_failed_batches' => 0,
            ];

            foreach ($batches as $batchIndex => $batchArticles) {
                $batchNum = $batchIndex + 1;
                $startTime = microtime(true);

                Log::info("BatchProcessor: Processing batch {$batchNum}/{$batchCount}", [
                    'articles_in_batch' => count($batchArticles),
                ]);

                // Step 1: Enrich with bias ratings
                $enrichedArticles = [];
                $enrichedCount = 0;

                foreach ($batchArticles as $article) {
                    $enriched = $this->biasService->enrichArticle($article);
                    if (isset($enriched['bias_rating'])) {
                        $enrichedCount++;
                    }
                    $enrichedArticles[] = $enriched;
                }

                $enrichmentStats['total_enriched'] += $enrichedCount;
                $enrichmentStats['total_processed'] += count($batchArticles);

                // Step 2: Format articles for AI
                $formattedInput = $this->formatArticlesForAI($enrichedArticles);

                // Step 3: Build AI prompt with batch context
                $batchPrompt = $this->buildBatchPrompt(
                    $aiPrompt,
                    $formattedInput,
                    $batchNum,
                    $batchCount,
                    count($batchArticles),
                    $pushoverFormat
                );

                // Step 4: Process with AI using AIService for resilience
                $aiConfig = [
                    'ai_timeout' => $aiTimeout,
                    'ai_mode' => $this->config['ai_mode'] ?? 'auto',
                    'temperature' => $this->config['temperature'] ?? 0.0,
                    'model_role' => $this->config['model_role'] ?? 'quality',
                    'max_tokens' => (int) ($this->config['max_tokens'] ?? 2048),
                    'use_cache' => false,
                    'dedup' => false,
                    'suppressAlert' => true,
                    'system_prompt' => $this->config['system_prompt'] ?? self::STRICT_FORMATTER_SYSTEM_PROMPT,
                ];

                $aiException = null;
                $formattedText = '';

                try {
                    $result = $this->aiService->process($batchPrompt, $aiConfig);
                } catch (Exception $e) {
                    $result = null;
                    $aiException = $e;
                }

                if ($aiException !== null) {
                    $formattedText = $this->formatArticlesForNotification($enrichedArticles, $pushoverFormat);
                    $enrichmentStats['ai_failed_batches']++;
                    $enrichmentStats['fallback_batches']++;
                    Log::warning('BatchProcessor: Used deterministic notification fallback after AI batch exception', [
                        'batch' => $batchNum,
                        'article_count' => count($batchArticles),
                        'error' => $aiException->getMessage(),
                    ]);
                } elseif (! $result['success']) {
                    $formattedText = $this->formatArticlesForNotification($enrichedArticles, $pushoverFormat);
                    $enrichmentStats['ai_failed_batches']++;
                    $enrichmentStats['fallback_batches']++;
                    Log::warning('BatchProcessor: Used deterministic notification fallback after AI batch failure', [
                        'batch' => $batchNum,
                        'article_count' => count($batchArticles),
                        'error' => $result['error'] ?? 'unknown error',
                    ]);
                } else {
                    $sanitized = $this->sanitizeAiOutput((string) $result['response'], count($batchArticles));
                    $formattedText = $sanitized['text'];

                    if ($sanitized['leak_detected']) {
                        $enrichmentStats['sanitized_batches']++;
                        Log::warning('BatchProcessor: Removed AI prompt leakage from batch output', [
                            'batch' => $batchNum,
                            'article_count' => count($batchArticles),
                            'kept_lines' => $sanitized['line_count'],
                        ]);
                    }

                    if ($formattedText === '' && $fallbackFormattedText !== '') {
                        Log::warning('BatchProcessor: Empty AI output; preserving upstream formatted text fallback', [
                            'batch' => $batchNum,
                            'article_count' => count($batchArticles),
                        ]);
                    } elseif ($formattedText === '' || $sanitized['line_count'] < count($batchArticles)) {
                        $fallbackReason = $formattedText === ''
                            ? 'empty_ai_output'
                            : ($sanitized['leak_detected'] ? 'incomplete_after_prompt_leak' : 'incomplete_ai_output');
                        $formattedText = $this->formatArticlesForNotification($enrichedArticles, $pushoverFormat);
                        $enrichmentStats['fallback_batches']++;
                        Log::warning('BatchProcessor: Used deterministic notification fallback for batch', [
                            'batch' => $batchNum,
                            'article_count' => count($batchArticles),
                            'line_count' => $sanitized['line_count'],
                            'reason' => $fallbackReason,
                        ]);
                    }
                }

                if ($formattedText !== '') {
                    $formattedText = $this->appendPoliticalBiasTags(
                        $formattedText,
                        $enrichedArticles,
                        $pushoverFormat
                    );
                }

                $duration = round((microtime(true) - $startTime) * 1000);

                $batchResults[] = [
                    'batch_number' => $batchNum,
                    'article_count' => count($batchArticles),
                    'enriched_count' => $enrichedCount,
                    'formatted_text' => $formattedText,
                    'duration_ms' => $duration,
                ];

                Log::info("BatchProcessor: Completed batch {$batchNum}/{$batchCount}", [
                    'duration_ms' => $duration,
                    'enriched' => $enrichedCount,
                ]);
            }

            // Combine batch results
            $combinedOutput = $this->combineBatchResults($batchResults);
            if (trim($combinedOutput) === '') {
                $combinedOutput = $fallbackFormattedText !== ''
                    ? $fallbackFormattedText
                    : $this->formatArticlesForAI($articles);
            }

            return $this->standardOutput($combinedOutput, [
                'batch_count' => $batchCount,
                'total_articles' => count($articles),
                'total_enriched' => $enrichmentStats['total_enriched'],
                'sanitized_batches' => $enrichmentStats['sanitized_batches'],
                'fallback_batches' => $enrichmentStats['fallback_batches'],
                'ai_failed_batches' => $enrichmentStats['ai_failed_batches'],
                'enrichment_rate' => count($articles) > 0
                    ? round(($enrichmentStats['total_enriched'] / count($articles)) * 100, 1).'%'
                    : '0%',
                'batch_results' => array_map(function ($batch) {
                    return [
                        'batch' => $batch['batch_number'],
                        'articles' => $batch['article_count'],
                        'enriched' => $batch['enriched_count'],
                        'duration_ms' => $batch['duration_ms'],
                    ];
                }, $batchResults),
            ]);

        } catch (Exception $e) {
            Log::error('BatchProcessor: Error', ['message' => $e->getMessage()]);

            return $this->standardOutput(
                null,
                ['error_message' => $e->getMessage()],
                'Batch processing failed: '.$e->getMessage()
            );
        }
    }

    /**
     * Extract articles from various input formats
     */
    private function extractArticles(array $input): array
    {
        // From structured data
        if (isset($input['data']['articles']) && is_array($input['data']['articles'])) {
            return $input['data']['articles'];
        }

        if (isset($input['articles']) && is_array($input['articles'])) {
            return $input['articles'];
        }

        // From formatted text with articles
        if (isset($input['data']['formatted_text'])) {
            // For now, return empty - we need structured articles
            return [];
        }

        return [];
    }

    private function extractFormattedText(array $input): string
    {
        if (isset($input['data']['formatted_text']) && is_string($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        if (isset($input['formatted_text']) && is_string($input['formatted_text'])) {
            return $input['formatted_text'];
        }

        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        return '';
    }

    /**
     * Format articles for AI processing
     */
    private function formatArticlesForAI(array $articles): string
    {
        $output = [];

        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $output[] = "Article {$num}:";
            $output[] = "Title: {$article['title']}";

            if (! empty($article['description'])) {
                $output[] = "Description: {$article['description']}";
            }

            if (! empty($article['url'])) {
                $output[] = "URL: {$article['url']}";
            }

            // Include bias rating if available
            if (isset($article['bias_rating'])) {
                $rating = $article['bias_rating'];
                $biasInfo = "{$rating['emoji']} {$rating['rating']}";
                if (isset($rating['confidence'])) {
                    $biasInfo .= " ({$rating['confidence']} confidence)";
                }
                $output[] = "Bias: {$biasInfo}";
            }

            // Include polarizing topics if detected
            if (isset($article['polarizing_topics']) && $article['polarizing_topics']['is_polarizing']) {
                $topics = $article['polarizing_topics'];
                $keywords = array_column($topics['detected'], 'keyword');
                $output[] = '⚠️ Polarizing Topics: '.implode(', ', $keywords)." (score: {$topics['score']})";
            }

            // Include emotional language score if sensational
            if (isset($article['emotional_language']) && $article['emotional_language']['is_sensational']) {
                $emotional = $article['emotional_language'];
                $output[] = "🔥 Emotional Language Score: {$emotional['score']}/100 (density: {$emotional['density']}%)";
            }

            if (! empty($article['source'])) {
                $output[] = "Source: {$article['source']}";
            }

            if (! empty($article['pubDate'])) {
                $output[] = "Published: {$article['pubDate']}";
            } elseif (! empty($article['published_at'])) {
                $output[] = "Published: {$article['published_at']}";
            }

            $output[] = ''; // Blank line between articles
        }

        return implode("\n", $output);
    }

    /**
     * Build AI prompt with batch context
     */
    private function buildBatchPrompt(
        string $basePrompt,
        string $articles,
        int $batchNum,
        int $totalBatches,
        int $articleCount,
        string $pushoverFormat
    ): string {
        // Inject dynamic values
        $prompt = str_replace(
            ['{TODAY}', '{TODAY_SHORT}', '{BATCH_NUM}', '{TOTAL_BATCHES}', '{ARTICLE_COUNT}'],
            [now()->format('F j, Y'), now()->format('M j, Y'), $batchNum, $totalBatches, $articleCount],
            $basePrompt
        );

        $prompt .= "\n\nARTICLE DATA START\n".$articles."\nARTICLE DATA END\n\n";

        // Add Pushover formatting instructions
        if ($pushoverFormat === 'html') {
            $prompt .= "FORMAT REQUIREMENTS:\n";
            $prompt .= "• Use HTML tags: <b>bold</b>, <i>italic</i>, <a href=\"URL\">link</a>\n";
            $prompt .= "• Keep each article on ONE line\n";
            $prompt .= "• No blank lines between articles\n";
            $prompt .= "• Format: <b>• Headline</b> - Summary. <i>Source</i>\n";
            $prompt .= "• Summary: 12 words maximum\n\n";
        }

        $prompt .= "Process exactly {$articleCount} articles from this batch.\n";
        $prompt .= "This is batch {$batchNum} of {$totalBatches}.\n";
        $prompt .= "Return only the final formatted article lines. Do not explain your reasoning or restate these instructions.\n";

        return $prompt;
    }

    private function sanitizeAiOutput(string $text, int $expectedArticles): array
    {
        $text = trim($this->stripThinkingBlocks($text));

        if ($text === '') {
            return ['text' => '', 'leak_detected' => false, 'line_count' => 0];
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $kept = [];
        $leakDetected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($this->isPromptLeakLine($trimmed)) {
                $leakDetected = true;
                break;
            }

            $kept[] = $trimmed;
        }

        if (empty($kept) && $this->containsPromptLeak($text)) {
            $leakDetected = true;
        }

        $cleaned = implode("\n", $kept);
        if ($cleaned !== '' && $this->containsPromptLeak($cleaned)) {
            $cleaned = $this->removeTrailingPromptLeak($cleaned);
            $leakDetected = true;
        }

        $lineCount = count(array_filter(
            preg_split('/\R/u', trim($cleaned)) ?: [],
            fn (string $line): bool => trim($line) !== ''
        ));

        if ($expectedArticles > 0 && $lineCount > $expectedArticles) {
            $extra = $lineCount - $expectedArticles;
            if ($extra > 2 && $this->containsPromptLeak($text)) {
                $leakDetected = true;
            }
        }

        return [
            'text' => trim($cleaned),
            'leak_detected' => $leakDetected,
            'line_count' => $lineCount,
        ];
    }

    private function stripThinkingBlocks(string $text): string
    {
        $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text) ?? $text;

        if (str_contains($cleaned, '</think>')) {
            $parts = explode('</think>', $cleaned);
            $cleaned = end($parts) ?: '';
        }

        return $cleaned;
    }

    private function isPromptLeakLine(string $line): bool
    {
        return preg_match('/^(we are given|we need to|the user asks|the task is|steps?:|reasoning:|analysis:|context\/data:|article data start|article data end|format requirements:|output format|rules:|example:|begin:|process exactly |process all \d+ articles|this is batch |return only |now craft|now sort|given the time|but without a library|for each article|color coding:|most important\/recent news first|let\'s|i need to|i will |i\'ll )/iu', $line) === 1;
    }

    private function containsPromptLeak(string $text): bool
    {
        return preg_match('/\b(we are given|we need to|context\/data:|article data start|format requirements:|process exactly \d+ articles|process all \d+ articles|this is batch \d+ of \d+|return only the final formatted article lines|one line per article|color coding:|most important\/recent news first|the user asks|the task is)\b/iu', $text) === 1;
    }

    private function removeTrailingPromptLeak(string $text): string
    {
        $markers = [
            '/\n\s*We are given\b/iu',
            '/\n\s*We need to\b/iu',
            '/\n\s*The user asks\b/iu',
            '/\n\s*The task is\b/iu',
            '/\n\s*Context\/Data:/iu',
            '/\n\s*ARTICLE DATA START\b/iu',
            '/\n\s*FORMAT REQUIREMENTS:/iu',
            '/\n\s*Process exactly \d+ articles\b/iu',
            '/\n\s*Process ALL \d+ articles\b/iu',
            '/\n\s*This is batch \d+ of \d+\b/iu',
            '/\n\s*Color coding:/iu',
            '/\n\s*Most important\/recent news first\b/iu',
            '/\n\s*Given the time\b/iu',
            '/\n\s*But without a library\b/iu',
            '/\n\s*I\'ll\b/iu',
        ];

        foreach ($markers as $marker) {
            if (preg_match($marker, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                return rtrim(substr($text, 0, $match[0][1]));
            }
        }

        return $text;
    }

    private function formatArticlesForNotification(array $articles, string $pushoverFormat): string
    {
        $lines = [];

        foreach ($articles as $article) {
            $title = trim((string) ($article['title'] ?? 'Untitled article'));
            $summary = trim((string) ($article['description'] ?? $article['summary'] ?? ''));
            $url = trim((string) ($article['url'] ?? ''));
            $source = trim((string) ($article['source'] ?? ''));
            if ($source === '' && $url !== '') {
                $host = parse_url($url, PHP_URL_HOST);
                $source = is_string($host) && $host !== '' ? $host : 'Source';
            } elseif ($source === '') {
                $source = 'Source';
            }

            $summary = $this->shortenSummary($summary);
            $indicator = $this->articleIndicators($article, $pushoverFormat);

            if ($pushoverFormat === 'html') {
                $sourceHtml = $url !== ''
                    ? '<a href="'.e($url).'">'.e($source).'</a>'
                    : e($source);
                $line = '<b>• '.e($title).'</b>';
                if ($summary !== '') {
                    $line .= ' - '.e($summary).'.';
                }
                $line .= ' <i>'.$sourceHtml.'</i>'.$indicator;
            } else {
                $line = '• '.$title;
                if ($summary !== '') {
                    $line .= ' - '.$summary.'.';
                }
                $line .= ' ('.$source.')'.$indicator;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function shortenSummary(string $summary): string
    {
        $summary = trim(strip_tags(html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($summary === '') {
            return '';
        }

        $summary = preg_replace('/\s+/u', ' ', $summary) ?? $summary;
        $words = preg_split('/\s+/u', $summary) ?: [];

        if (count($words) <= 12) {
            return rtrim($summary, ". \t\n\r\0\x0B");
        }

        return rtrim(implode(' ', array_slice($words, 0, 12)), ". \t\n\r\0\x0B").'...';
    }

    private function articleIndicators(array $article, string $pushoverFormat): string
    {
        $indicators = [];

        $biasTag = $this->politicalBiasTag($article, $pushoverFormat);
        if ($biasTag !== null) {
            $indicators[] = $biasTag;
        }

        if (($article['polarizing_topics']['is_polarizing'] ?? false) === true) {
            $indicators[] = '⚠️';
        }

        if (($article['emotional_language']['is_sensational'] ?? false) === true) {
            $indicators[] = '🔥';
        }

        return empty($indicators) ? '' : ' '.implode(' ', $indicators);
    }

    private function appendPoliticalBiasTags(string $formattedText, array $articles, string $pushoverFormat): string
    {
        $lines = preg_split('/\R/u', trim($formattedText)) ?: [];
        $taggedLines = [];
        $articleIndex = 0;
        $usedArticleIndexes = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $taggedLines[] = $line;

                continue;
            }

            if (! $this->isArticleOutputLine($trimmed)) {
                $taggedLines[] = $line;

                continue;
            }

            $match = $this->matchArticleForLine($trimmed, $articles, $usedArticleIndexes, $articleIndex);
            $article = $match['article'] ?? null;
            $tag = is_array($article) ? $this->politicalBiasTag($article, $pushoverFormat) : null;

            if ($tag !== null && ! $this->containsPoliticalBiasTag($trimmed)) {
                $line = rtrim($line).' '.$tag;
            }

            if (isset($match['index'])) {
                $usedArticleIndexes[$match['index']] = true;
            }

            $taggedLines[] = $line;
            $articleIndex++;
        }

        return trim(implode("\n", $taggedLines));
    }

    private function isArticleOutputLine(string $line): bool
    {
        $plain = trim(strip_tags(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plain === '') {
            return false;
        }

        if (str_contains($line, '<b>') && (str_contains($line, '</i>') || str_contains($plain, ' - '))) {
            return true;
        }

        if (preg_match('/^\s*(?:[•*-]\s+|\d+[\.)]\s+)/u', $plain) === 1) {
            return true;
        }

        return str_contains($plain, ' - ');
    }

    private function matchArticleForLine(string $line, array $articles, array $usedArticleIndexes, int $fallbackIndex): ?array
    {
        $haystack = $this->normalizeMatchText($line);

        foreach ($articles as $index => $article) {
            if (isset($usedArticleIndexes[$index]) || ! is_array($article)) {
                continue;
            }

            foreach ($this->articleMatchNeedles($article) as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return [
                        'index' => $index,
                        'article' => $article,
                    ];
                }
            }
        }

        $fallback = $articles[$fallbackIndex] ?? null;
        if (is_array($fallback) && ! isset($usedArticleIndexes[$fallbackIndex])) {
            return [
                'index' => $fallbackIndex,
                'article' => $fallback,
            ];
        }

        return null;
    }

    private function articleMatchNeedles(array $article): array
    {
        $needles = [];

        foreach (['source', 'source_name'] as $key) {
            $source = trim((string) ($article[$key] ?? ''));
            if ($source !== '' && mb_strlen($source) >= 3 && strtolower($source) !== 'source') {
                $needles[] = $source;
            }
        }

        $url = trim((string) ($article['url'] ?? ''));
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $needles[] = $this->normalizeHostForMatch($host);
            }
        }

        $feedUrl = trim((string) ($article['feed_url'] ?? ''));
        if ($feedUrl !== '') {
            $host = parse_url($feedUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $needles[] = $this->normalizeHostForMatch($host);
            }
        }

        $title = trim((string) ($article['title'] ?? ''));
        if (mb_strlen($title) >= 12) {
            $needles[] = $title;
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $value): string => $this->normalizeMatchText($value), $needles),
            fn (string $value): bool => $value !== ''
        )));
    }

    private function normalizeHostForMatch(string $host): string
    {
        $host = strtolower(trim($host));

        foreach (['www.', 'feeds.', 'rss.', 'api.', 'news.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    private function normalizeMatchText(string $value): string
    {
        $plain = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = mb_strtolower($plain, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    private function containsPoliticalBiasTag(string $line): bool
    {
        return preg_match('/(\[(?:[^\]]*(?:Left|Left-Center|Center|Right-Center|Right)[^\]]*)\]|\bbias:\s*(?:left|left-center|center|right-center|right)\b)/iu', $line) === 1;
    }

    private function politicalBiasTag(array $article, string $pushoverFormat): ?string
    {
        $rating = $article['bias_rating']['rating'] ?? null;

        if (! is_string($rating)) {
            return null;
        }

        $label = match ($rating) {
            'left' => 'Left',
            'left-center' => 'Left-Center',
            'center' => 'Center',
            'right-center' => 'Right-Center',
            'right' => 'Right',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        $icon = $article['bias_rating']['emoji'] ?? BiasRatingService::ratingEmoji($rating);
        $text = "[{$icon} {$label}]";

        if ($pushoverFormat !== 'html') {
            return $text;
        }

        $color = $article['bias_rating']['color'] ?? BiasRatingService::ratingColor($rating);

        return '<font color="'.e($color).'"><b>'.e($text).'</b></font>';
    }

    /**
     * Combine results from all batches
     */
    private function combineBatchResults(array $batchResults): string
    {
        $combined = [];

        foreach ($batchResults as $result) {
            $batchText = trim($result['formatted_text']);
            if (! empty($batchText)) {
                $combined[] = $batchText;
            }
        }

        return implode("\n", $combined);
    }
}
