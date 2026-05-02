<?php

namespace App\Traits;

use App\Controllers\NotificationController;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Fallback Storage Trait
 *
 * Provides fault-tolerant storage for workflow nodes when external services
 * (Joplin, Nextcloud, APIs, etc.) are unreachable.
 *
 * Features:
 * - Automatic detection of connection errors
 * - Local file fallback storage
 * - Pushover notifications when fallback is used
 * - Easy retry/import when services recover
 *
 * Usage:
 * 1. Use this trait in your node class
 * 2. Set $fallbackIdentifier to identify the service (e.g., 'joplin', 'api', 'nextcloud')
 * 3. Call checkAndSwitchToFallback() when you catch an exception
 * 4. Call writeToFallback() to save data when in fallback mode
 * 5. Call sendFallbackNotification() after processing to alert the user
 */
trait HasFallbackStorage
{
    /**
     * Whether we're in fallback mode (service unreachable)
     */
    protected bool $fallbackMode = false;

    /**
     * Identifier for this service's fallback folder
     * Override in your class, e.g., 'joplin', 'api', 'external-service'
     */
    protected string $fallbackIdentifier = 'generic';

    /**
     * Files written to fallback during this execution
     */
    protected array $fallbackFiles = [];

    /**
     * Get the fallback storage path
     *
     * @return string Full path to fallback directory
     */
    protected function getFallbackPath(): string
    {
        return storage_path("app/fallback-{$this->fallbackIdentifier}");
    }

    /**
     * Check if an error message indicates a connection failure
     *
     * @param  string  $error  Error message to check
     * @return bool True if this is a connection error
     */
    protected function isConnectionError(string $error): bool
    {
        $connectionPatterns = [
            'cURL error 7',           // Failed to connect
            'cURL error 28',          // Connection timed out
            'cURL error 6',           // Could not resolve host
            'Connection refused',
            'Failed to connect',
            'Could not resolve host',
            'Network is unreachable',
            'Connection reset',
            'Connection timed out',
            'Operation timed out',
            'SSL connection timeout',
            'ETIMEDOUT',
            'ECONNREFUSED',
            'ENOTFOUND',
            'ECONNRESET',
        ];

        foreach ($connectionPatterns as $pattern) {
            if (stripos($error, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if we should switch to fallback mode based on an error
     *
     * @param  string  $error  Error message
     * @return bool True if switched to fallback mode
     */
    protected function checkAndSwitchToFallback(string $error): bool
    {
        if ($this->isConnectionError($error)) {
            if (! $this->fallbackMode) {
                Log::warning('HasFallbackStorage: Connection error detected, switching to fallback mode', [
                    'service' => $this->fallbackIdentifier,
                    'error' => $error,
                ]);
                $this->fallbackMode = true;
            }

            return true;
        }

        return false;
    }

    /**
     * Write content to the fallback folder
     *
     * @param  string  $filename  Filename (without path)
     * @param  string  $content  File content
     * @param  array  $metadata  Optional metadata for logging
     * @return array Result with success status, filename, and filepath
     */
    protected function writeToFallback(string $filename, string $content, array $metadata = []): array
    {
        try {
            $fallbackPath = $this->getFallbackPath();

            // Ensure fallback directory exists
            if (! File::isDirectory($fallbackPath)) {
                File::makeDirectory($fallbackPath, 0755, true);
            }

            // Clean filename
            $cleanFilename = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $filename);
            $filepath = "{$fallbackPath}/{$cleanFilename}";

            // Handle duplicates
            $counter = 1;
            $originalFilepath = $filepath;
            while (File::exists($filepath)) {
                $pathInfo = pathinfo($originalFilepath);
                $filepath = "{$pathInfo['dirname']}/{$pathInfo['filename']}_{$counter}.{$pathInfo['extension']}";
                $counter++;
            }

            File::put($filepath, $content);

            $actualFilename = basename($filepath);
            $this->fallbackFiles[] = $actualFilename;

            Log::info('HasFallbackStorage: Content saved to fallback', array_merge([
                'service' => $this->fallbackIdentifier,
                'filename' => $actualFilename,
                'size' => strlen($content),
            ], $metadata));

            return [
                'success' => true,
                'filename' => $actualFilename,
                'filepath' => $filepath,
            ];

        } catch (Exception $e) {
            Log::error('HasFallbackStorage: Failed to write to fallback', array_merge([
                'service' => $this->fallbackIdentifier,
                'filename' => $filename ?? 'unknown',
                'error' => $e->getMessage(),
            ], $metadata));

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Write JSON data to the fallback folder
     *
     * @param  string  $filename  Base filename (without extension)
     * @param  array  $data  Data to serialize as JSON
     * @param  array  $metadata  Optional metadata for logging
     * @return array Result with success status, filename, and filepath
     */
    protected function writeJsonToFallback(string $filename, array $data, array $metadata = []): array
    {
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'JSON encoding failed: '.json_last_error_msg(),
            ];
        }

        return $this->writeToFallback("{$filename}.json", $jsonContent, $metadata);
    }

    /**
     * Send a Pushover notification about fallback usage
     *
     * @param  string  $title  Notification title
     * @param  string|null  $customMessage  Custom message (optional)
     */
    protected function sendFallbackNotification(string $title = 'Service Fallback Alert', ?string $customMessage = null): void
    {
        if (empty($this->fallbackFiles)) {
            return; // Nothing to notify about
        }

        try {
            $controller = new NotificationController;

            $message = $customMessage ?? $this->buildDefaultFallbackMessage();

            $controller->send('pushover', [
                'source_group' => 'workflow_node_notifications',
                'title' => $title,
                'message' => $message,
                'priority' => 0,
                'sound' => 'intermission',
            ]);

            Log::info('HasFallbackStorage: Fallback notification sent', [
                'service' => $this->fallbackIdentifier,
                'file_count' => count($this->fallbackFiles),
            ]);

        } catch (Exception $e) {
            Log::error('HasFallbackStorage: Failed to send fallback notification', [
                'service' => $this->fallbackIdentifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the default fallback notification message
     *
     * @return string Message content
     */
    protected function buildDefaultFallbackMessage(): string
    {
        $count = count($this->fallbackFiles);
        $folder = basename($this->getFallbackPath());

        $message = "Service '{$this->fallbackIdentifier}' unreachable\n\n";
        $message .= "{$count} item(s) saved to fallback folder:\n";
        $message .= "storage/app/{$folder}/\n\n";
        $message .= "Files:\n";

        foreach (array_slice($this->fallbackFiles, 0, 10) as $file) {
            $message .= '- '.substr($file, 0, 50)."\n";
        }

        if ($count > 10) {
            $message .= '... and '.($count - 10)." more\n";
        }

        $message .= "\nImport manually when service is back online.";

        return $message;
    }

    /**
     * Get count of items written to fallback
     *
     * @return int Number of fallback files
     */
    protected function getFallbackCount(): int
    {
        return count($this->fallbackFiles);
    }

    /**
     * Get list of fallback files
     *
     * @return array List of filenames
     */
    protected function getFallbackFiles(): array
    {
        return $this->fallbackFiles;
    }

    /**
     * Check if currently in fallback mode
     *
     * @return bool True if in fallback mode
     */
    protected function isInFallbackMode(): bool
    {
        return $this->fallbackMode;
    }

    /**
     * Reset fallback mode (for new execution)
     */
    protected function resetFallbackMode(): void
    {
        $this->fallbackMode = false;
        $this->fallbackFiles = [];
    }
}
