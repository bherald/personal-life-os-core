<?php

namespace App\Services;

/**
 * Data Sanitizer Service
 *
 * Centralized UTF-8 sanitization and data validation to prevent database errors
 * from malformed external data (RSS feeds, API responses, etc.)
 *
 * Common Issues Prevented:
 * - Invalid UTF-8 sequences
 * - NULL bytes
 * - Control characters
 * - Problematic UTF-8 characters (em-dash, smart quotes, etc.)
 * - Oversized data exceeding column limits
 */
class DataSanitizer
{
    /**
     * Clean UTF-8 string to prevent database encoding errors
     *
     * Removes invalid UTF-8 sequences, control characters, and problematic bytes
     * Converts common UTF-8 special characters to ASCII equivalents
     *
     * @param string $text Text to sanitize
     * @param int|null $maxLength Maximum length in characters (UTF-8 aware)
     * @return string Sanitized text
     */
    public static function cleanUtf8(string $text, ?int $maxLength = null): string
    {
        if (empty($text)) {
            return $text;
        }

        // Step 1: Ensure valid UTF-8 encoding by removing invalid sequences
        // This will strip out any malformed UTF-8 bytes
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Step 2: Remove NULL bytes - these always cause database errors
        $text = str_replace("\0", '', $text);

        // Step 3: Remove control characters except tabs, newlines, carriage returns
        // Control chars (0x00-0x1F except 0x09, 0x0A, 0x0D) cause issues in MySQL TEXT fields
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Step 4: Replace problematic UTF-8 characters with ASCII equivalents
        // These are common in RSS feeds and web scraping
        $replacements = [
            "\xE2\x80\x93" => '-',   // en-dash (–)
            "\xE2\x80\x94" => '--',  // em-dash (—)
            "\xE2\x80\x98" => "'",   // left single quote (')
            "\xE2\x80\x99" => "'",   // right single quote (')
            "\xE2\x80\x9A" => "'",   // single low-9 quote (‚)
            "\xE2\x80\x9B" => "'",   // single high-reversed-9 quote (‛)
            "\xE2\x80\x9C" => '"',   // left double quote (")
            "\xE2\x80\x9D" => '"',   // right double quote (")
            "\xE2\x80\x9E" => '"',   // double low-9 quote („)
            "\xE2\x80\x9F" => '"',   // double high-reversed-9 quote (‟)
            "\xE2\x80\xA6" => '...', // ellipsis (…)
            "\xE2\x80\xA2" => '*',   // bullet (•)
            "\xE2\x80\xA8" => "\n",  // line separator
            "\xE2\x80\xA9" => "\n",  // paragraph separator
            "\xC2\xA0" => ' ',       // non-breaking space
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Step 5: Truncate if needed (UTF-8 aware to avoid cutting characters in half)
        if ($maxLength !== null && mb_strlen($text, 'UTF-8') > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
        }

        // Step 6: Final validation - ensure result is valid UTF-8
        // Aggressive fallback: strip to ASCII if still invalid
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        }

        return $text;
    }

    /**
     * Validate that text size is within column byte limit
     *
     * Important: MySQL TEXT/MEDIUMTEXT limits are in BYTES, not characters
     * UTF-8 characters can be 1-4 bytes each
     *
     * @param string $text Text to validate
     * @param int $maxBytes Maximum size in bytes
     * @return bool True if within limit
     */
    public static function validateSize(string $text, int $maxBytes = 65535): bool
    {
        return strlen($text) <= $maxBytes;
    }

    /**
     * Get actual byte size of UTF-8 string
     *
     * @param string $text Text to measure
     * @return int Size in bytes
     */
    public static function getByteSize(string $text): int
    {
        return strlen($text);
    }

    /**
     * Truncate to byte limit (UTF-8 safe)
     *
     * Ensures truncation doesn't cut UTF-8 characters in half
     *
     * @param string $text Text to truncate
     * @param int $maxBytes Maximum bytes
     * @param string $suffix Suffix to add (default: '...')
     * @return string Truncated text
     */
    public static function truncateToBytes(string $text, int $maxBytes, string $suffix = '...'): string
    {
        $suffixBytes = strlen($suffix);

        // Already within limit
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        // Calculate available bytes for content
        $availableBytes = $maxBytes - $suffixBytes;

        if ($availableBytes <= 0) {
            return substr($suffix, 0, $maxBytes);
        }

        // Truncate byte-wise, then ensure UTF-8 safety
        $truncated = substr($text, 0, $availableBytes);

        // Fix potential mid-character truncation
        // UTF-8 continuation bytes start with 10xxxxxx (0x80-0xBF)
        while (strlen($truncated) > 0 && (ord(substr($truncated, -1)) & 0xC0) === 0x80) {
            $truncated = substr($truncated, 0, -1);
        }

        // Check if we cut off at the start of a multi-byte character
        if (strlen($truncated) > 0) {
            $lastByte = ord(substr($truncated, -1));

            // UTF-8 character start bytes: 110xxxxx (2-byte), 1110xxxx (3-byte), 11110xxx (4-byte)
            if (($lastByte & 0xE0) === 0xC0 || // 2-byte char start
                ($lastByte & 0xF0) === 0xE0 || // 3-byte char start
                ($lastByte & 0xF8) === 0xF0) { // 4-byte char start
                // Remove incomplete character
                $truncated = substr($truncated, 0, -1);
            }
        }

        return $truncated . $suffix;
    }

    /**
     * Clean and validate array of strings
     *
     * Useful for batch processing RSS articles, API responses, etc.
     *
     * @param array $data Array of strings or nested arrays
     * @param int|null $maxLength Maximum length per string
     * @return array Sanitized array
     */
    public static function cleanArray(array $data, ?int $maxLength = null): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $cleaned[$key] = self::cleanUtf8($value, $maxLength);
            } elseif (is_array($value)) {
                $cleaned[$key] = self::cleanArray($value, $maxLength);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Sanitize HTML content (strip tags and clean UTF-8)
     *
     * @param string $html HTML content
     * @param int|null $maxLength Maximum length
     * @param array $allowedTags Allowed HTML tags (default: none)
     * @return string Sanitized text
     */
    public static function cleanHtml(string $html, ?int $maxLength = null, array $allowedTags = []): string
    {
        // Strip tags
        if (empty($allowedTags)) {
            $text = strip_tags($html);
        } else {
            $text = strip_tags($html, $allowedTags);
        }

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean UTF-8
        return self::cleanUtf8($text, $maxLength);
    }

    /**
     * Sanitize article data from RSS feeds
     *
     * Comprehensive cleaning for typical RSS article structure
     *
     * @param array $article Article data
     * @return array Sanitized article
     */
    public static function sanitizeArticle(array $article): array
    {
        $sanitized = [];

        // Title: shorter limit, more aggressive
        if (isset($article['title'])) {
            $sanitized['title'] = self::cleanUtf8($article['title'], 500);
        }

        // Description: medium limit
        if (isset($article['description'])) {
            $sanitized['description'] = self::cleanHtml($article['description'], 1000);
        }

        // Content: larger limit
        if (isset($article['content'])) {
            $sanitized['content'] = self::cleanHtml($article['content'], 10000);
        }

        // URL: no cleaning needed, but validate
        if (isset($article['url'])) {
            $sanitized['url'] = filter_var($article['url'], FILTER_SANITIZE_URL);
        }

        // Copy other fields as-is (dates, numbers, etc.)
        $passthrough = ['pubDate', 'author', 'source', 'source_display', 'feed_url'];
        foreach ($passthrough as $field) {
            if (isset($article[$field])) {
                // Still clean strings, but no length limit
                $sanitized[$field] = is_string($article[$field])
                    ? self::cleanUtf8($article[$field])
                    : $article[$field];
            }
        }

        // Preserve structured data (bias ratings, etc.)
        $structured = ['bias_rating', 'polarizing_topics', 'emotional_language', 'article_grade'];
        foreach ($structured as $field) {
            if (isset($article[$field])) {
                $sanitized[$field] = $article[$field];
            }
        }

        return $sanitized;
    }
}
