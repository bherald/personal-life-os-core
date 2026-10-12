<?php

namespace App\Services;

use Throwable;

final class RagSourceReferenceService
{
    private string $hashKey;

    public function __construct(?string $hashKey = null)
    {
        $this->hashKey = $hashKey ?? $this->applicationHashKey();
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function reference(object|array|null $document): array
    {
        $documentId = $this->identifier($this->value($document, 'id'));
        $sourceId = $this->identifier($this->value($document, 'source_id'));
        $sourceType = $this->label($this->value($document, 'source_type'), 100);
        $documentType = $this->label($this->value($document, 'document_type'), 100) ?? 'unknown';
        $locator = $this->safeLocator($this->value($document, 'media_url'));
        $scope = $this->scope($this->value($document, 'metadata'));

        $status = match (true) {
            $sourceId !== null && $sourceType !== null => 'source_bound',
            $locator !== null => 'locator_only',
            $sourceType !== null => 'source_type_only',
            default => 'document_only',
        };
        $citationReady = in_array($status, ['source_bound', 'locator_only'], true);
        $basis = match ($status) {
            'source_bound' => $sourceType."\0".$sourceId,
            'locator_only' => $locator,
            'source_type_only' => $sourceType."\0".$documentType."\0".($documentId ?? 'unknown'),
            default => $documentType."\0".($documentId ?? 'unknown'),
        };

        return [
            'schema' => 'plos.rag_source_reference.v1',
            'source_ref' => 'rag_src_'.substr(hash_hmac('sha256', $basis, $this->hashKey), 0, 16),
            'source_type' => $sourceType,
            'source_id_present' => $sourceId !== null,
            'document_type' => $documentType,
            'citation_status' => $status,
            'citation_ready' => $citationReady,
            'scope' => $scope,
            'locator' => $locator,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function summarizeResults(array $results): array
    {
        $summary = $this->emptySummary();
        foreach ($results as $result) {
            $reference = $this->reference($result['document'] ?? null);
            $summary['result_count']++;
            if ($reference['citation_ready']) {
                $summary['citation_ready_count']++;
            }

            $statusKey = match ($reference['citation_status']) {
                'source_bound' => 'source_bound_count',
                'locator_only' => 'locator_only_count',
                'source_type_only' => 'source_type_only_count',
                default => 'document_only_count',
            };
            $summary[$statusKey]++;

            $scope = (string) $reference['scope'];
            $summary['scope_counts'][$scope] = ($summary['scope_counts'][$scope] ?? 0) + 1;
            if ($scope === 'global') {
                $summary['global_scope_count']++;
            } else {
                $summary['scoped_count']++;
            }
        }

        return $this->withRate($summary);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    public function mergeSummaries(array $left, array $right): array
    {
        $merged = $this->emptySummary();
        foreach (array_keys($merged) as $key) {
            if (in_array($key, ['citation_ready_rate', 'scope_counts'], true)) {
                continue;
            }
            $merged[$key] = (int) ($left[$key] ?? 0) + (int) ($right[$key] ?? 0);
        }

        foreach (array_keys($merged['scope_counts']) as $scope) {
            $merged['scope_counts'][$scope] = (int) ($left['scope_counts'][$scope] ?? 0)
                + (int) ($right['scope_counts'][$scope] ?? 0);
        }

        return $this->withRate($merged);
    }

    /** @return array<string, mixed> */
    public function emptySummary(): array
    {
        return [
            'result_count' => 0,
            'citation_ready_count' => 0,
            'citation_ready_rate' => 0.0,
            'source_bound_count' => 0,
            'locator_only_count' => 0,
            'source_type_only_count' => 0,
            'document_only_count' => 0,
            'scoped_count' => 0,
            'global_scope_count' => 0,
            'scope_counts' => [
                'global' => 0,
                'user' => 0,
                'agent' => 0,
                'conversation' => 0,
                'workspace' => 0,
            ],
        ];
    }

    private function applicationHashKey(): string
    {
        try {
            if (function_exists('app')) {
                $application = app();
                if ($application->bound('config')) {
                    $key = $application['config']->get('app.key');
                    if (is_string($key) && $key !== '') {
                        return $key;
                    }
                }
            }
        } catch (Throwable) {
            // Framework-free unit callers receive the deterministic fallback below.
        }

        return 'plos.rag_source_reference.v1';
    }

    private function value(object|array|null $document, string $key): mixed
    {
        if (is_array($document)) {
            return $document[$key] ?? null;
        }

        return is_object($document) ? ($document->{$key} ?? null) : null;
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private function label(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function safeLocator(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return mb_substr($value, 0, 500);
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? mb_substr($value, 0, 500)
            : null;
    }

    private function scope(mixed $metadata): string
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (is_object($metadata)) {
            $metadata = get_object_vars($metadata);
        }
        if (! is_array($metadata)) {
            return 'global';
        }

        return match (true) {
            $this->hasScopeValue($metadata, 'user_id') => 'user',
            $this->hasScopeValue($metadata, 'agent_id') => 'agent',
            $this->hasScopeValue($metadata, 'conversation_id'), $this->hasScopeValue($metadata, 'thread_id') => 'conversation',
            $this->hasScopeValue($metadata, 'workspace_id') => 'workspace',
            default => 'global',
        };
    }

    /** @param array<string, mixed> $metadata */
    private function hasScopeValue(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '';
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    private function withRate(array $summary): array
    {
        $count = (int) ($summary['result_count'] ?? 0);
        $summary['citation_ready_rate'] = $count > 0
            ? round((int) ($summary['citation_ready_count'] ?? 0) / $count, 4)
            : 0.0;

        return $summary;
    }
}
