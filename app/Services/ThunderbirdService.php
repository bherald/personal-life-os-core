<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Thunderbird MCP Service
 *
 * Provides full access to Thunderbird email client via MCP server.
 * Supports reading emails, sending via extension, folder management.
 *
 * Architecture:
 * ThunderbirdService → MCP Server (8766) → Thunderbird Extension (8765) → Thunderbird
 *
 * MCP Tools Available:
 * - listFolders: List all mbox folders with sizes
 * - listMailboxes: List configured email accounts
 * - sendEmail: Send via Thunderbird extension
 * - searchMessages: Search emails by keyword
 * - getRecentMessages: Get recent emails from folder
 * - getStats: Get email/folder statistics
 */
class ThunderbirdService
{
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;

    // Error tracking for diagnostics
    private ?string $lastError = null;
    private ?string $lastErrorCode = null;
    private int $consecutiveFailures = 0;
    private const MAX_CONSECUTIVE_FAILURES = 5;

    public function __construct()
    {
        $this->baseUrl = config('services.thunderbird.url', 'http://127.0.0.1:8766');
        $this->timeout = config('services.thunderbird.timeout', 30);
        $this->connectTimeout = config('services.thunderbird.connect_timeout', 5);
    }

    // =========================================================================
    // CONNECTION & STATUS
    // =========================================================================

    /**
     * Check if Thunderbird MCP server is available
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::connectTimeout($this->connectTimeout)->timeout($this->connectTimeout)->post($this->baseUrl, [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 1,
            ]);

            if ($response->successful()) {
                $this->consecutiveFailures = 0;
                return true;
            }

            $this->recordError('HTTP_ERROR', 'HTTP ' . $response->status());
            return false;

        } catch (Exception $e) {
            $this->recordError('CONNECTION_FAILED', $e->getMessage());
            Log::debug('Thunderbird MCP not available: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get detailed connection status for diagnostics
     */
    public function getConnectionStatus(): array
    {
        $available = $this->isAvailable();

        $status = [
            'available' => $available,
            'server_url' => $this->baseUrl,
            'last_error' => $this->lastError,
            'last_error_code' => $this->lastErrorCode,
            'consecutive_failures' => $this->consecutiveFailures,
            'circuit_open' => $this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES,
        ];

        if ($available) {
            try {
                $stats = $this->getStats();
                $status['stats'] = $stats;
                $status['extension_connected'] = true; // If getStats works, extension is likely connected
            } catch (Exception $e) {
                $status['stats_error'] = $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Get last error information
     */
    public function getLastError(): ?array
    {
        if (!$this->lastError) {
            return null;
        }

        return [
            'message' => $this->lastError,
            'code' => $this->lastErrorCode,
            'consecutive_failures' => $this->consecutiveFailures,
        ];
    }

    // =========================================================================
    // EMAIL READING
    // =========================================================================

    /**
     * List all available mbox folders with sizes
     *
     * @return array Folder information with loaded status
     * @throws Exception on MCP error
     */
    public function listFolders(): array
    {
        $result = $this->callTool('listFolders', []);
        return $this->parseToolContent($result);
    }

    /**
     * List all configured email accounts/mailboxes
     *
     * @return array Mailbox information with SMTP settings
     * @throws Exception on MCP error
     */
    public function listMailboxes(): array
    {
        $result = $this->callTool('listMailboxes', []);
        return $this->parseToolContent($result);
    }

    /**
     * Search emails across loaded folders
     *
     * @param string $query Search query
     * @param string|null $folder Optional folder filter
     * @return array Matching emails (max 20)
     * @throws Exception on MCP error
     */
    public function searchMessages(string $query, ?string $folder = null): array
    {
        $params = ['query' => $query];
        if ($folder) {
            $params['folder'] = $folder;
        }

        $result = $this->callTool('searchMessages', $params);
        return $this->parseToolContent($result);
    }

    /**
     * Get recent messages from a folder
     *
     * @param string $folder Folder name (default: Inbox)
     * @param int $limit Number of messages (default: 10)
     * @return array Recent emails
     * @throws Exception on MCP error
     */
    public function getRecentMessages(string $folder = 'Inbox', int $limit = 10): array
    {
        $result = $this->callTool('getRecentMessages', [
            'folder' => $folder,
            'limit' => $limit,
        ]);
        return $this->parseToolContent($result);
    }

    /**
     * Get statistics about loaded folders and emails
     *
     * @return array Statistics including folder counts, email counts, mailbox info
     * @throws Exception on MCP error
     */
    public function getStats(): array
    {
        $result = $this->callTool('getStats', []);
        return $this->parseToolContent($result);
    }

    // =========================================================================
    // EMAIL SENDING
    // =========================================================================

    /**
     * Send email via Thunderbird extension
     *
     * Requires Thunderbird running with PLOS extension installed.
     * Falls back gracefully with informative error if unavailable.
     *
     * @param array $params Email parameters:
     *   - to: Recipient email (required)
     *   - subject: Email subject (required)
     *   - body: Plain text body (required)
     *   - mailbox: Sender mailbox ID or email (optional, uses default)
     *   - html: HTML body (optional)
     * @return array Send result with status and message ID
     * @throws Exception on send failure
     */
    public function sendEmail(array $params): array
    {
        // Validate required fields
        $required = ['to', 'subject', 'body'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // Validate email format
        if (!filter_var($params['to'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address: {$params['to']}");
        }

        // Use default mailbox if not specified
        if (empty($params['mailbox'])) {
            $mailboxes = $this->listMailboxes();
            if (empty($mailboxes)) {
                throw new Exception('No mailboxes configured in Thunderbird');
            }
            $params['mailbox'] = $mailboxes[0]['email'] ?? $mailboxes[0]['id'];
        }

        $result = $this->callTool('sendEmail', $params);
        $parsed = $this->parseToolContent($result);

        // Check for extension-specific errors
        if (isset($parsed['error'])) {
            throw new Exception($parsed['error']);
        }

        Log::info('Email sent via Thunderbird', [
            'to' => $params['to'],
            'subject' => $params['subject'],
            'mailbox' => $params['mailbox'],
            'result' => $parsed,
        ]);

        return $parsed;
    }

    /**
     * Alias for sendEmail (backwards compatibility)
     */
    public function sendMail(array $params): array
    {
        return $this->sendEmail($params);
    }

    // =========================================================================
    // MCP TOOLS LIST
    // =========================================================================

    /**
     * List available MCP tools
     *
     * @return array Tool definitions
     */
    public function listTools(): array
    {
        return $this->call('tools/list');
    }

    // =========================================================================
    // INTERNAL METHODS
    // =========================================================================

    /**
     * Call an MCP tool
     */
    private function callTool(string $tool, array $params): array
    {
        return $this->call('tools/call', [
            'name' => $tool,
            'arguments' => $params,
        ]);
    }

    /**
     * Make MCP JSON-RPC call with error handling
     */
    private function call(string $method, array $params = []): array
    {
        // Circuit breaker pattern
        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            throw new Exception(
                "Thunderbird MCP circuit open after {$this->consecutiveFailures} failures. " .
                "Last error: {$this->lastError}"
            );
        }

        $requestId = rand(1000, 9999);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($this->baseUrl, [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => $requestId,
                ]);

            if (!$response->successful()) {
                $this->recordError('HTTP_' . $response->status(), 'HTTP error: ' . $response->status());
                throw new Exception('Thunderbird MCP HTTP error: ' . $response->status());
            }

            $data = $response->json();

            // Check for JSON-RPC error
            if (isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'Unknown MCP error';
                $errorCode = $data['error']['code'] ?? 'MCP_ERROR';
                $this->recordError($errorCode, $errorMsg);
                throw new Exception("Thunderbird MCP error: {$errorMsg}");
            }

            // Success - reset failure counter
            $this->consecutiveFailures = 0;

            return $data['result'] ?? [];

        } catch (Exception $e) {
            $this->consecutiveFailures++;

            Log::error('Thunderbird MCP call failed', [
                'method' => $method,
                'params' => $this->sanitizeParams($params),
                'error' => $e->getMessage(),
                'consecutive_failures' => $this->consecutiveFailures,
            ]);

            throw $e;
        }
    }

    /**
     * Parse tool result content (MCP returns JSON in text content)
     */
    private function parseToolContent(array $result): array
    {
        // MCP tools return content as array with type/text
        if (isset($result['content'][0]['text'])) {
            $json = $result['content'][0]['text'];
            $parsed = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to parse Thunderbird MCP response', [
                    'json_error' => json_last_error_msg(),
                    'raw' => substr($json, 0, 500),
                ]);
                return ['raw' => $json];
            }

            return $parsed;
        }

        // Direct result (not in content wrapper)
        return $result;
    }

    /**
     * Record error for diagnostics
     */
    private function recordError(string $code, string $message): void
    {
        $this->lastError = $message;
        $this->lastErrorCode = $code;
        $this->consecutiveFailures++;
    }

    /**
     * Sanitize params for logging (hide sensitive data)
     */
    private function sanitizeParams(array $params): array
    {
        $sanitized = $params;

        // Hide email body content in logs
        if (isset($sanitized['arguments']['body'])) {
            $sanitized['arguments']['body'] = '[BODY HIDDEN - ' .
                strlen($sanitized['arguments']['body']) . ' chars]';
        }

        return $sanitized;
    }

    /**
     * Reset circuit breaker (for manual recovery)
     */
    public function resetCircuit(): void
    {
        $this->consecutiveFailures = 0;
        $this->lastError = null;
        $this->lastErrorCode = null;
        Log::info('Thunderbird MCP circuit breaker reset');
    }
}
