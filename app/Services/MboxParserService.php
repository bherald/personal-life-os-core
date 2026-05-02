<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * DI-6: Mbox file parser for Thunderbird email archives.
 *
 * Parses standard mbox format (RFC 4155) used by Thunderbird.
 * Each message starts with "From " line followed by headers + body.
 * Streams large files without loading entire mbox into memory.
 */
class MboxParserService
{
    /**
     * Parse an mbox file and yield messages one at a time.
     *
     * @param string $mboxPath Path to mbox file
     * @param int $limit Max messages to parse (0 = all)
     * @param int $offset Skip first N messages
     * @return \Generator Yields parsed message arrays
     */
    public function parseFile(string $mboxPath, int $limit = 0, int $offset = 0): \Generator
    {
        if (!file_exists($mboxPath)) {
            Log::warning('MboxParser: File not found', ['path' => $mboxPath]);
            return;
        }

        $handle = fopen($mboxPath, 'r');
        if (!$handle) {
            Log::warning('MboxParser: Cannot open file', ['path' => $mboxPath]);
            return;
        }

        $currentMessage = '';
        $messageCount = 0;
        $yielded = 0;

        while (($line = fgets($handle)) !== false) {
            // Mbox "From " separator line
            if (str_starts_with($line, 'From ') && !empty(trim($currentMessage))) {
                $messageCount++;

                if ($messageCount > $offset) {
                    $parsed = $this->parseMessage($currentMessage);
                    if ($parsed) {
                        yield $parsed;
                        $yielded++;

                        if ($limit > 0 && $yielded >= $limit) {
                            fclose($handle);
                            return;
                        }
                    }
                }

                $currentMessage = '';
            } else {
                $currentMessage .= $line;
            }
        }

        // Last message in file
        if (!empty(trim($currentMessage))) {
            $messageCount++;
            if ($messageCount > $offset) {
                $parsed = $this->parseMessage($currentMessage);
                if ($parsed) {
                    yield $parsed;
                }
            }
        }

        fclose($handle);
    }

    /**
     * Count messages in an mbox file without parsing.
     */
    public function countMessages(string $mboxPath): int
    {
        if (!file_exists($mboxPath)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($mboxPath, 'r');
        if (!$handle) return 0;

        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, 'From ')) {
                $count++;
            }
        }

        fclose($handle);
        return $count;
    }

    /**
     * Parse a single email message (headers + body).
     */
    private function parseMessage(string $raw): ?array
    {
        // Split headers from body (empty line separator)
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerBlock);

        if (empty($headers['subject']) && empty($headers['from'])) {
            return null; // Not a valid email
        }

        // Decode body (handle transfer encoding)
        $encoding = $headers['content-transfer-encoding'] ?? '';
        if (strtolower($encoding) === 'base64') {
            $body = base64_decode($body);
        } elseif (strtolower($encoding) === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        // Strip HTML tags if content-type is HTML
        $contentType = $headers['content-type'] ?? 'text/plain';
        if (str_contains(strtolower($contentType), 'text/html')) {
            $body = strip_tags($body);
        }

        // For multipart messages, extract text/plain part
        if (str_contains(strtolower($contentType), 'multipart')) {
            $textPart = $this->extractTextPart($raw);
            if ($textPart) {
                $body = $textPart;
            }
        }

        // Decode MIME-encoded subject/from
        $subject = $this->decodeMimeHeader($headers['subject'] ?? '');
        $from = $this->decodeMimeHeader($headers['from'] ?? '');

        return [
            'subject' => $subject,
            'from' => $from,
            'to' => $this->decodeMimeHeader($headers['to'] ?? ''),
            'date' => $headers['date'] ?? null,
            'message_id' => $headers['message-id'] ?? null,
            'body' => trim($body),
            'body_length' => strlen(trim($body)),
            'content_type' => $contentType,
        ];
    }

    /**
     * Parse email headers into key-value pairs.
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $currentKey = null;

        foreach (explode("\n", $headerBlock) as $line) {
            $line = rtrim($line, "\r");

            // Continuation line (starts with whitespace)
            if ($currentKey && preg_match('/^\s+(.*)/', $line, $m)) {
                $headers[$currentKey] .= ' ' . trim($m[1]);
                continue;
            }

            // New header
            if (preg_match('/^([A-Za-z][\w-]*)\s*:\s*(.*)/', $line, $m)) {
                $currentKey = strtolower($m[1]);
                $headers[$currentKey] = trim($m[2]);
            }
        }

        return $headers;
    }

    /**
     * Extract text/plain part from a multipart message.
     */
    private function extractTextPart(string $raw): ?string
    {
        // Find boundary
        if (!preg_match('/boundary="?([^";\s]+)"?/i', $raw, $m)) {
            return null;
        }
        $boundary = $m[1];

        $parts = explode('--' . $boundary, $raw);

        foreach ($parts as $part) {
            if (str_contains(strtolower($part), 'content-type: text/plain')
                || str_contains(strtolower($part), 'content-type:text/plain')) {
                // Extract body after headers
                $segments = preg_split('/\r?\n\r?\n/', $part, 2);
                $body = $segments[1] ?? '';

                // Check encoding
                if (preg_match('/content-transfer-encoding:\s*(quoted-printable|base64)/i', $part, $enc)) {
                    if (strtolower($enc[1]) === 'quoted-printable') {
                        $body = quoted_printable_decode($body);
                    } elseif (strtolower($enc[1]) === 'base64') {
                        $body = base64_decode($body);
                    }
                }

                return trim($body);
            }
        }

        return null;
    }

    /**
     * Decode MIME-encoded header values (=?charset?encoding?text?=)
     */
    private function decodeMimeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }

        // Use iconv_mime_decode if available, otherwise regex fallback
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }

        // Regex fallback for =?charset?Q?text?= and =?charset?B?text?=
        return preg_replace_callback(
            '/=\?([^?]+)\?(Q|B)\?([^?]*)\?=/i',
            function ($m) {
                $charset = $m[1];
                $encoding = strtoupper($m[2]);
                $text = $m[3];

                if ($encoding === 'B') {
                    $text = base64_decode($text);
                } elseif ($encoding === 'Q') {
                    $text = str_replace('_', ' ', quoted_printable_decode($text));
                }

                if (strtoupper($charset) !== 'UTF-8') {
                    $text = @iconv($charset, 'UTF-8//IGNORE', $text) ?: $text;
                }

                return $text;
            },
            $value
        ) ?? $value;
    }

    /**
     * Scan a Thunderbird profile for all mbox files.
     *
     * @param string $profilePath Path to Thunderbird profile
     * @return array List of mbox files with path, name, size, modified_at
     */
    public function scanProfile(string $profilePath): array
    {
        $mboxFiles = [];

        $dirs = ['Mail', 'ImapMail'];
        foreach ($dirs as $dir) {
            $mailDir = $profilePath . '/' . $dir;
            if (!is_dir($mailDir)) continue;

            $this->scanMboxDir($mailDir, $mboxFiles, $dir);
        }

        // Favor recently updated mailboxes first so scheduled indexing reaches new
        // messages before spending time on cold archives.
        usort($mboxFiles, function ($a, $b) {
            $mtimeCompare = ($b['modified_at'] ?? 0) <=> ($a['modified_at'] ?? 0);
            if ($mtimeCompare !== 0) {
                return $mtimeCompare;
            }

            return $b['size'] <=> $a['size'];
        });

        return $mboxFiles;
    }

    private function scanMboxDir(string $dir, array &$results, string $prefix): void
    {
        $entries = scandir($dir);
        if (!$entries) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                // .sbd directories contain subfolders
                if (str_ends_with($entry, '.sbd')) {
                    $folderName = substr($entry, 0, -4);
                    $this->scanMboxDir($path, $results, $prefix . '/' . $folderName);
                } else {
                    $this->scanMboxDir($path, $results, $prefix . '/' . $entry);
                }
                continue;
            }

            // Skip .msf (index) and other non-mbox files
            if (str_ends_with($entry, '.msf') || str_ends_with($entry, '.dat')
                || str_ends_with($entry, '.html') || str_contains($entry, '.')) {
                continue;
            }

            // Remaining files without extensions are mbox files
            $size = filesize($path);
            if ($size > 0) {
                $results[] = [
                    'path' => $path,
                    'name' => $prefix . '/' . $entry,
                    'size' => $size,
                    'size_human' => $this->formatBytes($size),
                    'modified_at' => filemtime($path) ?: 0,
                ];
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . 'KB';
        return $bytes . 'B';
    }
}
