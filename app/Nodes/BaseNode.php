<?php

namespace App\Nodes;

abstract class BaseNode
{
    protected array $config = [];
    private ?float $timeLimitStart = null;
    private ?int $timeLimitMax = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    abstract public function execute(array $input): array;

    protected function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Start a wall-clock time limit for loop-based processing.
     * Call before a loop, then check hasTimeRemaining() each iteration.
     */
    protected function initTimeLimit(?int $maxSeconds = null): void
    {
        $this->timeLimitStart = microtime(true);
        $this->timeLimitMax = $maxSeconds ?? (int) $this->getConfigValue('max_seconds', 240);
    }

    /**
     * Check if time remains within the wall-clock limit.
     * Returns true if no limit was set (initTimeLimit not called).
     */
    protected function hasTimeRemaining(): bool
    {
        if ($this->timeLimitStart === null) {
            return true;
        }
        return (microtime(true) - $this->timeLimitStart) < $this->timeLimitMax;
    }

    /**
     * Seconds elapsed since initTimeLimit was called.
     */
    protected function elapsedSeconds(): float
    {
        return $this->timeLimitStart ? microtime(true) - $this->timeLimitStart : 0;
    }

    protected function standardOutput($data, $meta = [], $error = null): array
    {
        return [
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
            ], $meta),
            'error' => $error
        ];
    }

    protected function resolvePositiveIntConfig(string $key, ?int $default = null): ?int
    {
        $value = $this->getConfigValue($key, $default);

        if ($value === null || $value === '') {
            return $default;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    protected function resolveBooleanConfig(string $key, bool $default = false): bool
    {
        $value = $this->getConfigValue($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return (bool) $value;
    }

    protected function mergeArticleCollections(array $existing, array $incoming, ?int $maxArticles = null): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($existing, $incoming) as $article) {
            if (!is_array($article)) {
                continue;
            }

            $dedupeKey = $this->articleDedupeKey($article);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $merged[] = $article;
        }

        usort($merged, function (array $a, array $b) {
            return $this->articleTimestamp($b) <=> $this->articleTimestamp($a);
        });

        if ($maxArticles !== null && count($merged) > $maxArticles) {
            $merged = array_slice($merged, 0, $maxArticles);
        }

        return array_values($merged);
    }

    protected function trimFormattedText(?string $text, ?int $maxChars = null): ?string
    {
        if ($text === null || $maxChars === null || $maxChars <= 0) {
            return $text;
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $suffix = "\n\n[truncated to {$maxChars} chars]";
        $keepLength = max(0, $maxChars - mb_strlen($suffix));

        return rtrim(mb_substr($text, 0, $keepLength)) . $suffix;
    }

    private function articleDedupeKey(array $article): string
    {
        $url = trim((string) ($article['link'] ?? $article['url'] ?? ''));
        if ($url !== '') {
            return 'url:' . mb_strtolower($url);
        }

        $title = trim((string) ($article['title'] ?? ''));
        $published = trim((string) ($article['pubDate'] ?? $article['published'] ?? ''));
        $source = trim((string) ($article['source'] ?? $article['feed_url'] ?? ''));

        return 'fallback:' . mb_strtolower($title . '|' . $published . '|' . $source);
    }

    private function articleTimestamp(array $article): int
    {
        $raw = $article['pubDate'] ?? $article['published'] ?? null;

        if (!$raw) {
            return 0;
        }

        $timestamp = strtotime((string) $raw);

        return $timestamp === false ? 0 : $timestamp;
    }

    protected function multiStreamOutput(array $streams, array $meta = []): array
    {
        return [
            'streams' => $streams,
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
            ], $meta),
        ];
    }
}
