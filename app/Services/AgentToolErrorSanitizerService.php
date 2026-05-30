<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgentToolErrorSanitizerService
{
    public const SCHEMA = 'plos.agent_tool_error_sanitizer.v1';

    public function sanitize(mixed $error, int $limit = 1000): string
    {
        $text = trim((string) $error);
        if ($text === '') {
            return 'Tool execution failed.';
        }

        $text = $this->redactSecrets($text);
        $text = $this->redactLocalPaths($text);
        $text = $this->redactLikelyPrivateUris($text);

        $text = $this->redactInstructionOpeners($text);

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text === '' ? 'Tool execution failed.' : $text, $limit, '');
    }

    public function errorLine(string $prefix, mixed $error, int $limit = 1000): string
    {
        return rtrim($prefix).': '.$this->sanitize($error, $limit);
    }

    private function redactSecrets(string $text): string
    {
        $text = (string) preg_replace(
            '/\b(?:password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|authorization|client[_-]?secret)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i',
            '[REDACTED_SECRET]',
            $text
        );
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{10,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\b(?:sk|pk|rk|ghp|gho|ghu|github_pat)-[A-Za-z0-9_]{16,}\b/i', '[REDACTED_KEY]', $text);

        return (string) preg_replace('/\b[A-Za-z0-9+\/]{32,}={0,2}\b/', '[REDACTED_TOKEN]', $text);
    }

    private function redactLocalPaths(string $text): string
    {
        $text = (string) preg_replace('~/(?:home|Users)/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('/\b[A-Z]:\\\\(?:[^\\\\\s"\'<>),;]+\\\\?)+/i', '[REDACTED_LOCAL_PATH]', $text);

        return (string) preg_replace('/\b(?:file|sqlite):\/\/[^\\s"\'<>),;]+/i', '[REDACTED_LOCAL_URI]', $text);
    }

    private function redactLikelyPrivateUris(string $text): string
    {
        return (string) preg_replace_callback(
            '/\bhttps?:\/\/([^\/\s"\'<>),;]+)([^\\s"\'<>),;]*)?/i',
            static function (array $matches): string {
                $host = strtolower((string) ($matches[1] ?? ''));
                if ($host === '' || str_contains($host, 'localhost') || preg_match('/^(?:127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/', $host)) {
                    return '[REDACTED_PRIVATE_URL]';
                }

                return 'https://'.$host.'/[redacted-path]';
            },
            $text
        );
    }

    private function redactInstructionOpeners(string $text): string
    {
        $patterns = array_keys((array) config('injection_patterns.patterns', []));
        if ($patterns === []) {
            return $text;
        }

        return (string) preg_replace($patterns, '[REDACTED_TOOL_ERROR_INSTRUCTION]', $text);
    }
}
