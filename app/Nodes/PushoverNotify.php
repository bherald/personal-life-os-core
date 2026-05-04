<?php

namespace App\Nodes;

use App\Controllers\NotificationController;
use App\Exceptions\NodeTimeoutException;
use Exception;

class PushoverNotify extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $title = $this->getConfigValue('title', 'Notification');
            $priority = $this->getConfigValue('priority', 0);
            $sound = $this->getConfigValue('sound', null);
            $maxRetriesPerChunk = max(1, (int) $this->getConfigValue('max_retries_per_chunk', 2));
            $retryDelaySeconds = max(0, (int) $this->getConfigValue('retry_delay_seconds', 1));
            $interChunkDelaySeconds = max(0, (int) $this->getConfigValue('inter_chunk_delay_seconds', 2));
            $timeoutSeconds = max(1, (int) $this->getConfigValue('timeout_seconds', 300));
            $nodeStartedAt = microtime(true);

            // Enhanced formatting options
            $formatType = $this->getConfigValue('format_type', 'plain'); // 'plain', 'html', 'monospace'
            $url = $this->getConfigValue('url', null); // Supplementary URL
            $urlTitle = $this->getConfigValue('url_title', null); // URL display title
            $ttl = $this->getConfigValue('ttl', null); // Time-to-live in seconds

            // Extract message - check config first, then input
            $message = $this->getConfigValue('message', null);
            if (! $message) {
                $message = $this->extractMessage($input);
            }

            if (! $message) {
                $upstreamError = $this->extractUpstreamError($input);
                if ($upstreamError !== null) {
                    throw new Exception('Upstream node error: '.$upstreamError);
                }

                throw new Exception('No message content found in input or config');
            }

            // Substitute variables in title and message
            $title = $this->substituteVariables($title, $input);
            $message = $this->substituteVariables($message, $input);

            // Console formatting disabled - text formatting did not render well on mobile.
            // $consoleStyleEnabled = $this->getConfigValue('console_style', true);
            // if ($consoleStyleEnabled && $formatType !== 'plain') {
            //     $message = $this->applyConsoleFormatting($message, $title);
            // }

            // Pushover limit: 1024 characters per message
            $maxLength = 1024;
            $chunks = $this->splitMessageIntoChunks($message, $maxLength);

            $controller = new NotificationController;
            $totalChunks = count($chunks);
            $sentCount = 0;
            $suppressedCount = 0;
            $sentParts = [];
            $suppressedParts = [];
            $failedParts = [];
            $sourceGroup = (string) $this->getConfigValue('source_group', 'workflow_node_notifications');

            // Send chunks in REVERSE order so they appear in correct reading order on Pushover
            // (Pushover shows newest messages first, so send Part 3, then 2, then 1)
            $reversedChunks = array_reverse($chunks); // Don't preserve keys

            foreach ($reversedChunks as $index => $chunk) {
                // Calculate correct part number: if we have 3 parts and are sending reversed
                // index 0 (last chunk) should show "Part 3/3"
                $partNumber = $totalChunks - $index;
                $chunkTitle = $totalChunks > 1
                    ? "$title (Part $partNumber/$totalChunks)"
                    : $title;

                // Build payload with enhanced formatting options
                $payload = [
                    'title' => $chunkTitle,
                    'message' => $chunk,
                    'priority' => $priority,
                    'format_type' => $formatType,
                    'source_group' => $sourceGroup,
                ];

                // Add optional parameters
                if ($sound !== null) {
                    $payload['sound'] = $sound;
                }

                // Only include URL in the first message (Part 1, which is sent last)
                if ($url !== null && $index === count($reversedChunks) - 1) {
                    $payload['url'] = $url;
                    if ($urlTitle !== null) {
                        $payload['url_title'] = $urlTitle;
                    }
                }

                if ($ttl !== null) {
                    $payload['ttl'] = $ttl;
                }

                // Emergency priority parameters
                if ($priority == 2) {
                    $payload['retry'] = (int) $this->getConfigValue('retry', 60);
                    $payload['expire'] = (int) $this->getConfigValue('expire', 3600);
                }

                $result = null;
                for ($attempt = 1; $attempt <= $maxRetriesPerChunk; $attempt++) {
                    $result = $controller->send('pushover', $payload);

                    if ($this->isNodeTimeoutMessage($result['error'] ?? null)) {
                        throw new NodeTimeoutException(
                            'PushoverNotify',
                            $timeoutSeconds,
                            max(1, (int) ceil(microtime(true) - $nodeStartedAt)),
                            (string) $result['error']
                        );
                    }

                    if (! empty($result['success']) && empty($result['suppressed'])) {
                        $sentCount++;
                        $sentParts[] = $partNumber;
                        break;
                    }

                    if (! empty($result['suppressed'])) {
                        $suppressedCount++;
                        $suppressedParts[] = $partNumber;
                        break;
                    }

                    if ($attempt < $maxRetriesPerChunk && $retryDelaySeconds > 0) {
                        sleep($retryDelaySeconds);
                    }
                }

                if (empty($result['success']) && empty($result['suppressed'])) {
                    $failedParts[] = $partNumber;
                }

                // Delay between messages to ensure proper ordering (Pushover shows newest first)
                // We send in reverse order, so delay after each except the last (which is Part 1)
                if ($index < count($reversedChunks) - 1 && $interChunkDelaySeconds > 0) {
                    sleep($interChunkDelaySeconds);
                }
            }

            return $this->standardOutput([
                'notification_sent' => $sentCount === $totalChunks,
                'notification_suppressed' => $suppressedCount > 0,
                'title' => $title,
                'message_length' => strlen($message),
                'total_parts' => $totalChunks,
                'parts_sent' => $sentCount,
                'parts_suppressed' => $suppressedCount,
                'part_numbers_sent' => $sentParts,
                'part_numbers_suppressed' => $suppressedParts,
                'part_numbers_failed' => $failedParts,
                'format_type' => $formatType,
                'has_url' => $url !== null,
                'source_group' => $sourceGroup,
                'inter_chunk_delay_seconds' => $interChunkDelaySeconds,
                'max_retries_per_chunk' => $maxRetriesPerChunk,
            ], [
                'provider' => 'pushover',
                'priority' => $priority,
                'format_type' => $formatType,
                'source_group' => $sourceGroup,
            ]);

        } catch (Exception $e) {
            if ($e instanceof NodeTimeoutException || str_contains($e->getMessage(), 'Node timeout:')) {
                throw $e;
            }

            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function extractMessage(array $input): ?string
    {
        if ($this->isEmptyErrorEnvelope($input)) {
            return null;
        }

        // Check for formatted_text (from AIFormatter)
        if (isset($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        // Check for data string
        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        // Check for message key
        if (isset($input['message'])) {
            return $input['message'];
        }

        // Check for data array
        if (isset($input['data']) && is_array($input['data'])) {
            return json_encode($input['data'], JSON_PRETTY_PRINT);
        }

        // Fallback to entire input
        if (! empty($input)) {
            return json_encode($input, JSON_PRETTY_PRINT);
        }

        return null;
    }

    private function isEmptyErrorEnvelope(array $input): bool
    {
        if ($this->extractUpstreamError($input) === null) {
            return false;
        }

        if (isset($input['message']) && trim((string) $input['message']) !== '') {
            return false;
        }

        if (! array_key_exists('data', $input)) {
            return true;
        }

        $data = $input['data'];

        if ($data === null || $data === '') {
            return true;
        }

        if (is_array($data)) {
            if (isset($data['formatted_text']) && trim((string) $data['formatted_text']) !== '') {
                return false;
            }

            if (isset($data['message']) && trim((string) $data['message']) !== '') {
                return false;
            }

            return $data === [];
        }

        return false;
    }

    private function extractUpstreamError(array $input): ?string
    {
        $error = $input['error'] ?? null;

        if (! is_scalar($error)) {
            return null;
        }

        $error = trim((string) $error);

        return $error === '' ? null : $error;
    }

    private function isNodeTimeoutMessage(mixed $message): bool
    {
        if (! is_scalar($message)) {
            return false;
        }

        $message = (string) $message;

        return str_contains($message, 'Node timeout:')
            || preg_match("/\\bNode '.+' execution timed out after \\d+s \\(limit: \\d+s\\)/", $message) === 1;
    }

    /**
     * Split message into chunks that fit within Pushover's 1024 character limit
     * Splits on logical boundaries (sections, paragraphs, sentences)
     */
    private function splitMessageIntoChunks(string $message, int $maxLength): array
    {
        // If message fits, return as-is
        if (strlen($message) <= $maxLength) {
            return [$message];
        }

        $chunks = [];
        $currentChunk = '';

        // Split by double newlines (sections/paragraphs) first
        $sections = preg_split('/\n\n+/', $message);

        foreach ($sections as $section) {
            $section = trim($section);

            // If adding this section would exceed limit, start new chunk
            if (strlen($currentChunk) + strlen($section) + 2 > $maxLength && ! empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }

            // If section itself is too long, split by newlines
            if (strlen($section) > $maxLength) {
                $lines = explode("\n", $section);
                foreach ($lines as $line) {
                    if (strlen($currentChunk) + strlen($line) + 1 > $maxLength && ! empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                        $currentChunk = '';
                    }

                    // If single line is still too long, hard split
                    if (strlen($line) > $maxLength) {
                        if (! empty($currentChunk)) {
                            $chunks[] = trim($currentChunk);
                            $currentChunk = '';
                        }
                        // Hard split on maxLength
                        $chunks = array_merge($chunks, str_split($line, $maxLength));
                    } else {
                        $currentChunk .= ($currentChunk ? "\n" : '').$line;
                    }
                }
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '').$section;
            }
        }

        // Add remaining chunk
        if (! empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Substitute variables in string with values from input data
     * Supports {variable_name} syntax and nested data structures
     *
     * @param  string  $text  Text containing variables to substitute
     * @param  array  $input  Input data containing values
     * @return string Text with variables substituted
     */
    private function substituteVariables(string $text, array $input): string
    {
        // Find all variables in {curly_braces}
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use ($input) {
            $variableName = $matches[1];

            // Special handling for "count" variable
            if ($variableName === 'count') {
                // Count videos array
                if (isset($input['data']['videos']) && is_array($input['data']['videos'])) {
                    return (string) count($input['data']['videos']);
                }
                if (isset($input['videos']) && is_array($input['videos'])) {
                    return (string) count($input['videos']);
                }
            }

            // Try to find the value in various locations in input
            // 1. Direct key in input
            if (isset($input[$variableName])) {
                return $this->formatValue($input[$variableName]);
            }

            // 2. In data array
            if (isset($input['data'][$variableName])) {
                return $this->formatValue($input['data'][$variableName]);
            }

            // 3. In meta array
            if (isset($input['meta'][$variableName])) {
                return $this->formatValue($input['meta'][$variableName]);
            }

            // 4. In videos array (first video)
            if (isset($input['data']['videos'][0][$variableName])) {
                return $this->formatValue($input['data']['videos'][0][$variableName]);
            }

            if (isset($input['videos'][0][$variableName])) {
                return $this->formatValue($input['videos'][0][$variableName]);
            }

            // Variable not found, return as-is
            return $matches[0];
        }, $text);
    }

    /**
     * Format a value for display in notification
     *
     * @param  mixed  $value  Value to format
     * @return string Formatted value
     */
    private function formatValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    /**
     * Apply compact console formatting to message.
     * Uses Unicode box-drawing and block characters for scan-friendly sections.
     * Preserves original message content, only adds visual framing
     *
     * @param  string  $message  Original message content
     * @param  string  $title  Message title for header
     * @return string Console-formatted message
     */
    private function applyConsoleFormatting(string $message, string $title): string
    {
        // Clean console-inspired design.
        $header = '╭──────────────────────╮';
        $headerBar = '│  ■ ■ ■  PLOS  ■ ■ ■  │';
        $headerClose = '╰──────────────────────╯';
        $sectionBar = '════════════════════════';
        $bullet = '◆';
        $subBullet = '›';

        // Process message lines
        $lines = explode("\n", $message);
        $formattedLines = [];
        $inSection = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                $formattedLines[] = '';

                continue;
            }

            // Detect section headers (ALL CAPS or Title Case with colon)
            if (preg_match('/^[A-Z][A-Z0-9\s]+:?$/', $trimmed) ||
                preg_match('/^[A-Z][a-z]+:$/', $trimmed) ||
                preg_match('/^#+\s/', $trimmed)) {

                $headerText = preg_replace('/^#+\s*/', '', $trimmed);
                $headerText = rtrim($headerText, ':');

                if ($inSection) {
                    $formattedLines[] = '';
                }
                $formattedLines[] = '▌ '.strtoupper($headerText);
                $formattedLines[] = '└'.str_repeat('─', 22);
                $inSection = true;
            }
            // Detect key: value pairs
            elseif (preg_match('/^([^:]+):\s*(.+)$/', $trimmed, $matches)) {
                $formattedLines[] = "  {$bullet} ".trim($matches[1]).': '.trim($matches[2]);
            }
            // Regular bullet items (lines starting with - or *)
            elseif (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
                $formattedLines[] = "  {$subBullet} ".trim($matches[1]);
            }
            // Regular content
            else {
                $formattedLines[] = '  '.$line;
            }
        }

        // Build final message
        $formatted = $header."\n";
        $formatted .= $headerBar."\n";
        $formatted .= $headerClose."\n";
        $formatted .= "\n";
        $formatted .= implode("\n", $formattedLines);
        $formatted .= "\n\n";
        $formatted .= $sectionBar;

        return $formatted;
    }
}
