<?php

namespace App\Services\DevAgent;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TraceEnvelopeService
{
    private const SCHEMA_VERSION = 'plos.dev_agent.trace.v1';

    private const FORBIDDEN_KEYS = [
        'api_key',
        'authorization',
        'bearer',
        'chain_of_thought',
        'cookie',
        'env',
        'environment',
        'file_content',
        'full_diff',
        'password',
        'raw_completion',
        'raw_output',
        'raw_prompt',
        'raw_response',
        'secret',
        'stack_trace',
        'stderr',
        'stdout',
        'token',
        'tool_params',
    ];

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function append(array $event): array
    {
        if (! (bool) config('dev_agent.trace.enabled', true)) {
            return ['success' => false, 'error' => 'trace_disabled'];
        }

        if ($this->containsForbiddenKey($event)) {
            return ['success' => false, 'error' => 'forbidden_raw_field'];
        }

        $dir = $this->traceDir();
        if (! $this->ensureTraceDir($dir)) {
            return ['success' => false, 'error' => 'trace_dir_unavailable'];
        }

        if (($free = @disk_free_space($dir)) !== false
            && $free < (int) config('dev_agent.trace.min_free_bytes', 10 * 1024 * 1024)
        ) {
            return ['success' => false, 'error' => 'trace_disk_low'];
        }

        $now = CarbonImmutable::now('UTC');
        $envelope = $this->buildEnvelope($event, $now);
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return ['success' => false, 'error' => 'trace_json_encode_failed'];
        }

        $maxBytes = max(1024, (int) config('dev_agent.trace.max_event_bytes', 16384));
        if (strlen($json) > $maxBytes) {
            $envelope['result']['status'] = $envelope['result']['status'] ?? 'skipped';
            $envelope['result']['output_hash'] = 'sha256:'.hash('sha256', $json);
            $envelope['result']['stdout_summary'] = '[trace envelope exceeded max_event_bytes]';
            $envelope['request']['prompt_summary'] = isset($envelope['request']['prompt_summary'])
                ? '[truncated sha256:'.hash('sha256', (string) $envelope['request']['prompt_summary']).']'
                : null;
            $envelope['integrity']['event_hash'] = $this->hashEvent($envelope);
            $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($json === false || strlen($json) > $maxBytes) {
            return ['success' => false, 'error' => 'trace_event_too_large'];
        }

        $path = $this->fileForDate($now, $dir);
        $written = @file_put_contents($path, $json.PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return ['success' => false, 'error' => 'trace_write_failed'];
        }

        return [
            'success' => true,
            'trace_id' => $envelope['trace_id'],
            'event_id' => $envelope['event_id'],
            'event' => $envelope,
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function tail(array $filters = []): array
    {
        $events = $this->scan($filters);
        $limit = $this->limit($filters['limit'] ?? 20);

        return [
            'result' => 'ok',
            'limit' => $limit,
            'hours' => $this->hours($filters['since'] ?? null),
            'warnings' => $events['warnings'],
            'events' => array_slice($events['events'], 0, $limit),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function readByTraceId(string $traceId, array $filters = []): array
    {
        $filters['trace'] = $traceId;
        $events = $this->scan($filters);

        return [
            'result' => $events['events'] === [] ? 'not_found' : 'ok',
            'trace_id' => $traceId,
            'hours' => $this->hours($filters['since'] ?? null),
            'warnings' => $events['warnings'],
            'events' => $events['events'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function readByEventId(string $eventId, array $filters = []): ?array
    {
        $events = $this->scan($filters);

        foreach ($events['events'] as $event) {
            if (($event['event_id'] ?? null) === $eventId) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function buildEnvelope(array $event, CarbonImmutable $now): array
    {
        $traceId = $this->cleanId((string) ($event['trace_id'] ?? '')) ?: 'trc_'.Str::uuid()->toString();
        $eventId = $this->cleanId((string) ($event['event_id'] ?? '')) ?: 'evt_'.Str::uuid()->toString();
        $sequence = max(1, (int) ($event['sequence'] ?? 1));
        $maxSummary = max(120, (int) config('dev_agent.trace.max_summary_chars', 500));

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $eventId,
            'trace_id' => $traceId,
            'sequence' => $sequence,
            'event_type' => $this->cleanType((string) ($event['event_type'] ?? 'note')),
            'occurred_at' => $this->isoTime($event['occurred_at'] ?? null) ?? $now->toIso8601String(),
            'recorded_at' => $now->toIso8601String(),
            'surface' => $this->boundedString($event['surface'] ?? 'plos', 80),
            'actor' => [
                'type' => $this->boundedString($event['actor']['type'] ?? 'system', 40),
                'id' => $this->boundedString($event['actor']['id'] ?? null, 120),
            ],
            'policy' => $this->sanitizeArray((array) ($event['policy'] ?? []), $maxSummary),
            'classification' => $this->sanitizeArray((array) ($event['classification'] ?? []), $maxSummary),
            'request' => $this->sanitizeArray((array) ($event['request'] ?? []), $maxSummary),
            'tool' => $this->sanitizeArray((array) ($event['tool'] ?? []), $maxSummary),
            'command' => $this->sanitizeArray((array) ($event['command'] ?? []), $maxSummary),
            'model' => $this->sanitizeArray((array) ($event['model'] ?? []), $maxSummary),
            'result' => $this->sanitizeArray((array) ($event['result'] ?? []), $maxSummary),
            'files' => $this->sanitizeFiles((array) ($event['files'] ?? []), $maxSummary),
            'links' => $this->sanitizeArray((array) ($event['links'] ?? []), $maxSummary),
            'integrity' => [
                'previous_event_hash' => $this->boundedString($event['integrity']['previous_event_hash'] ?? null, 100),
                'event_hash' => null,
                'redaction_rules_version' => (string) config('dev_agent.trace.redaction_rules_version', '2026-05-01'),
            ],
        ];

        $envelope['integrity']['event_hash'] = $this->hashEvent($envelope);

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{events:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    private function scan(array $filters): array
    {
        $dir = $this->traceDir();
        $hours = $this->hours($filters['since'] ?? null);
        $since = CarbonImmutable::now('UTC')->subHours($hours);
        $events = [];
        $warnings = [];

        foreach ($this->candidateFiles($dir, $since) as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                $warnings[] = ['file' => basename($path), 'warning' => 'unreadable'];

                continue;
            }

            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $decoded = json_decode(trim($line), true);
                if (! is_array($decoded)) {
                    $warnings[] = ['file' => basename($path), 'line' => $lineNumber, 'warning' => 'malformed_json'];

                    continue;
                }

                $recordedAt = $this->parseTime($decoded['recorded_at'] ?? null);
                if ($recordedAt !== null && $recordedAt->lessThan($since)) {
                    continue;
                }

                if (! $this->matchesFilters($decoded, $filters)) {
                    continue;
                }

                $events[] = $decoded;
            }

            fclose($handle);
        }

        usort($events, static fn (array $a, array $b): int => strcmp(
            (string) ($b['recorded_at'] ?? ''),
            (string) ($a['recorded_at'] ?? '')
        ));

        return ['events' => $events, 'warnings' => $warnings];
    }

    /**
     * @return list<string>
     */
    private function candidateFiles(string $dir, CarbonImmutable $since): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $days = [];
        $cursor = CarbonImmutable::now('UTC')->startOfDay();
        $stop = $since->startOfDay();

        while ($cursor->greaterThanOrEqualTo($stop)) {
            $path = $dir.'/'.$cursor->format('Y-m-d').'.ndjson';
            if (is_file($path)) {
                $days[] = $path;
            }
            $cursor = $cursor->subDay();
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $event, array $filters): bool
    {
        $map = [
            'trace' => $event['trace_id'] ?? null,
            'type' => $event['event_type'] ?? null,
            'surface' => $event['surface'] ?? null,
            'actor' => $event['actor']['id'] ?? null,
        ];

        foreach ($map as $filter => $value) {
            $expected = trim((string) ($filters[$filter] ?? ''));
            if ($expected !== '' && (string) $value !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $value, int $maxSummary): array
    {
        $sanitized = [];
        foreach ($value as $key => $item) {
            $key = is_string($key) ? $key : (string) $key;
            if ($this->isForbiddenKey($key)) {
                continue;
            }

            $sanitized[$key] = match (true) {
                is_array($item) => $this->sanitizeArray($item, $maxSummary),
                is_string($item) => Str::limit($item, $maxSummary, ''),
                is_scalar($item) || $item === null => $item,
                default => '[non-scalar]',
            };
        }

        return $sanitized;
    }

    /**
     * @param  array<int, mixed>  $files
     * @return list<array<string, mixed>>
     */
    private function sanitizeFiles(array $files, int $maxSummary): array
    {
        $sanitized = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $entry = $this->sanitizeArray($file, $maxSummary);
            if (isset($entry['path']) && is_string($entry['path'])) {
                $entry['path'] = ltrim(str_replace(base_path(), '', $entry['path']), '/');
            }
            $sanitized[] = $entry;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function containsForbiddenKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                return true;
            }

            if (is_array($item) && $this->containsForbiddenKey($item)) {
                return true;
            }
        }

        return false;
    }

    private function isForbiddenKey(string $key): bool
    {
        return in_array(strtolower($key), self::FORBIDDEN_KEYS, true);
    }

    private function hashEvent(array $event): string
    {
        $copy = $event;
        $copy['integrity']['event_hash'] = null;
        $json = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:'.hash('sha256', $json === false ? serialize($copy) : $json);
    }

    private function traceDir(): string
    {
        $configured = (string) config('dev_agent.trace.dir', storage_path('app/dev-agent/traces'));
        $configured = rtrim($configured, '/');
        $defaultRoot = rtrim(storage_path('app/dev-agent/traces'), '/');

        if ($configured === '') {
            return $defaultRoot;
        }

        $normalized = $this->normalizePath($configured);
        $storageRoot = rtrim(storage_path('app'), '/');

        return $normalized !== null && str_starts_with($normalized, $storageRoot.'/')
            ? $normalized
            : $defaultRoot;
    }

    private function ensureTraceDir(string $dir): bool
    {
        $storageRoot = rtrim(storage_path('app'), '/');
        $normalized = $this->normalizePath($dir);

        if ($normalized === null || ! str_starts_with($normalized, $storageRoot.'/')) {
            return false;
        }

        if (! is_dir($normalized) && ! @mkdir($normalized, 0700, true) && ! is_dir($normalized)) {
            return false;
        }

        return is_writable($normalized);
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            $path = storage_path('app/'.$path);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function fileForDate(CarbonImmutable $now, string $dir): string
    {
        return $dir.'/'.$now->format('Y-m-d').'.ndjson';
    }

    private function limit(mixed $value): int
    {
        return max(1, min(200, (int) ($value ?: 20)));
    }

    private function hours(mixed $value): int
    {
        $default = (int) config('dev_agent.trace.scan_hours_default', 24);
        $max = (int) config('dev_agent.trace.scan_hours_max', 168);

        return max(1, min(max(1, $max), (int) ($value ?: $default)));
    }

    private function cleanId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return preg_replace('/[^A-Za-z0-9_.:-]/', '_', Str::limit($value, 120, ''));
    }

    private function cleanType(string $value): string
    {
        $value = strtolower((string) $this->cleanId($value));

        return $value !== '' ? $value : 'note';
    }

    private function boundedString(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function isoTime(mixed $value): ?string
    {
        return $this->parseTime($value)?->toIso8601String();
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
