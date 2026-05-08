<?php

namespace App\Nodes;

use App\Controllers\NotificationController;
use App\Exceptions\NodeTimeoutException;
use App\Services\PushoverRateLimitPolicy;
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
            $effectiveTimeoutSeconds = max(1, (int) $this->getConfigValue('effective_timeout_seconds', $timeoutSeconds));
            $partTimestampsEnabled = filter_var(
                $this->getConfigValue('part_timestamps_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            );
            $partHeadersEnabled = filter_var(
                $this->getConfigValue('part_headers_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            );
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

            // Pushover limit: 1024 characters per message. Reserve a little
            // room when multipart headers are enabled so the sent payload still
            // fits after the per-part prefix is added.
            $maxLength = $partHeadersEnabled ? 1000 : 1024;
            $chunks = $this->splitMessageIntoChunks($message, $maxLength);
            $originalTotalChunks = count($chunks);
            $maxDeliveryParts = $this->resolveMaxDeliveryParts();
            $deliveryTruncated = false;

            if ($maxDeliveryParts !== null && $originalTotalChunks > $maxDeliveryParts) {
                $chunks = $this->limitChunksForDelivery($chunks, $maxDeliveryParts, $maxLength);
                $deliveryTruncated = true;
            }

            $controller = new NotificationController;
            $totalChunks = count($chunks);
            $sentCount = 0;
            $suppressedCount = 0;
            $sentParts = [];
            $suppressedParts = [];
            $failedParts = [];
            $partTimestamps = [];
            $partMessageLengths = [];
            $partMessageHashes = [];
            $partResponseRequests = [];
            $partTimestampBase = time();
            $sourceGroup = (string) $this->getConfigValue('source_group', 'workflow_node_notifications');

            if ($totalChunks > 1 && ! PushoverRateLimitPolicy::hasCapacity($sourceGroup, $totalChunks)) {
                $capacity = PushoverRateLimitPolicy::capacity($sourceGroup);

                return $this->standardOutput([
                    'notification_sent' => false,
                    'notification_suppressed' => false,
                    'title' => $title,
                    'message_length' => strlen($message),
                    'original_total_parts' => $originalTotalChunks,
                    'max_delivery_parts' => $maxDeliveryParts,
                    'delivery_truncated' => $deliveryTruncated,
                    'truncated_parts' => max(0, $originalTotalChunks - $totalChunks),
                    'total_parts' => $totalChunks,
                    'parts_sent' => 0,
                    'parts_suppressed' => 0,
                    'part_numbers_sent' => [],
                    'part_numbers_suppressed' => [],
                    'part_numbers_failed' => range($totalChunks, 1),
                    'rate_limit_preflight' => 'insufficient_capacity',
                    'rate_limit_capacity' => [
                        'source_group' => $sourceGroup,
                        'limit' => $capacity['limit'],
                        'current_count' => $capacity['current_count'],
                        'remaining' => $capacity['remaining'],
                        'required' => $totalChunks,
                    ],
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
                ], "Pushover multipart rate-limit capacity insufficient for {$totalChunks} parts in source group {$sourceGroup}.");
            }

            $this->assertMultipartDeliveryBudget(
                $totalChunks,
                $interChunkDelaySeconds,
                $effectiveTimeoutSeconds,
                $timeoutSeconds,
                $nodeStartedAt
            );

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
                $chunkMessage = $chunk;
                if ($totalChunks > 1 && $partHeadersEnabled) {
                    $chunkMessage = "[Part {$partNumber}/{$totalChunks}]\n".$chunk;
                }

                // Build payload with enhanced formatting options
                $payload = [
                    'title' => $chunkTitle,
                    'message' => $chunkMessage,
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

                if ($totalChunks > 1 && $partTimestampsEnabled) {
                    $payload['timestamp'] = $partTimestampBase + ($totalChunks - $partNumber);
                    $partTimestamps[$partNumber] = $payload['timestamp'];
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
                        $partMessageLengths[$partNumber] = strlen($chunkMessage);
                        $partMessageHashes[$partNumber] = hash('sha256', $chunkMessage);
                        if (isset($result['request']) && is_scalar($result['request']) && trim((string) $result['request']) !== '') {
                            $partResponseRequests[$partNumber] = (string) $result['request'];
                        }
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
                'original_total_parts' => $originalTotalChunks,
                'max_delivery_parts' => $maxDeliveryParts,
                'delivery_truncated' => $deliveryTruncated,
                'truncated_parts' => max(0, $originalTotalChunks - $totalChunks),
                'total_parts' => $totalChunks,
                'parts_sent' => $sentCount,
                'parts_suppressed' => $suppressedCount,
                'part_numbers_sent' => $sentParts,
                'part_numbers_suppressed' => $suppressedParts,
                'part_numbers_failed' => $failedParts,
                'part_timestamps_enabled' => $totalChunks > 1 && $partTimestampsEnabled,
                'part_timestamp_strategy' => $totalChunks > 1 && $partTimestampsEnabled ? 'ascending_display_order' : null,
                'part_timestamps' => $partTimestamps,
                'part_headers_enabled' => $totalChunks > 1 && $partHeadersEnabled,
                'part_header_strategy' => $totalChunks > 1 && $partHeadersEnabled ? 'message_prefix' : null,
                'part_message_lengths' => $partMessageLengths,
                'part_message_hashes' => $partMessageHashes,
                'part_response_requests' => $partResponseRequests,
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

    private function assertMultipartDeliveryBudget(
        int $totalChunks,
        int $interChunkDelaySeconds,
        int $effectiveTimeoutSeconds,
        int $configuredTimeoutSeconds,
        float $nodeStartedAt
    ): void {
        if ($totalChunks <= 1) {
            return;
        }

        $sendBudgetSeconds = max(1, (int) $this->getConfigValue('multipart_send_budget_seconds', 2));
        $safetyMarginSeconds = max(0, (int) $this->getConfigValue('multipart_delivery_safety_margin_seconds', 5));
        $requiredSeconds = ($totalChunks * $sendBudgetSeconds)
            + (($totalChunks - 1) * $interChunkDelaySeconds)
            + $safetyMarginSeconds;
        $elapsedSeconds = max(0, (int) ceil(microtime(true) - $nodeStartedAt));
        $remainingSeconds = $effectiveTimeoutSeconds - $elapsedSeconds;

        if ($remainingSeconds >= $requiredSeconds) {
            return;
        }

        throw new NodeTimeoutException(
            'PushoverNotify',
            $configuredTimeoutSeconds,
            max(1, $elapsedSeconds),
            "Node timeout: PushoverNotify insufficient multipart delivery budget; needs {$requiredSeconds}s to send {$totalChunks} parts but only ".max(0, $remainingSeconds).'s remain.'
        );
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

    private function resolveMaxDeliveryParts(): ?int
    {
        $value = $this->getConfigValue('max_delivery_parts', null);

        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function limitChunksForDelivery(array $chunks, int $maxDeliveryParts, int $maxLength): array
    {
        $maxDeliveryParts = max(1, $maxDeliveryParts);

        if (count($chunks) <= $maxDeliveryParts) {
            return $chunks;
        }

        $limited = array_slice($chunks, 0, $maxDeliveryParts);
        $omitted = count($chunks) - count($limited);
        $suffix = "\n\n[Digest truncated: {$omitted} additional Pushover "
            .($omitted === 1 ? 'part was' : 'parts were')
            .' omitted.]';
        $lastIndex = count($limited) - 1;
        $keepLength = max(0, $maxLength - strlen($suffix));
        $limited[$lastIndex] = rtrim(substr((string) $limited[$lastIndex], 0, $keepLength)).$suffix;

        return $limited;
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
