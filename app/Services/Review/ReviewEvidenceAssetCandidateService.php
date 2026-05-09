<?php

namespace App\Services\Review;

class ReviewEvidenceAssetCandidateService
{
    private const MAX_CANDIDATES = 20;

    /**
     * @return list<array<string, mixed>>
     */
    public function fromDetails(array $details): array
    {
        $personId = $this->positiveInt($details['person_id'] ?? null)
            ?? $this->positiveInt($details['target_person_id'] ?? null)
            ?? $this->positiveInt($details['identity']['person_id'] ?? null)
            ?? $this->positiveInt($details['identity']['target_person_id'] ?? null);

        $candidates = [];

        foreach (['evidence_assets', 'source_assets', 'media_assets', 'assets'] as $key) {
            foreach ($this->arrayItems($details[$key] ?? null) as $asset) {
                $candidate = $this->candidateFromValue($asset, $personId, $key);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($this->arrayItems($details['sources'] ?? null) as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($this->arrayItems($source['assets'] ?? null) as $asset) {
                $candidate = $this->candidateFromValue($asset, $personId, 'sources.assets', $source);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            $candidate = $this->candidateFromValue($source, $personId, 'sources', $source);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $sourceLocator = $this->candidateFromValue($details['source_locator'] ?? null, $personId, 'source_locator');
        if ($sourceLocator !== null) {
            $candidates[] = $sourceLocator;
        }

        foreach ($this->arrayItems($details['source_locators'] ?? null) as $locator) {
            $candidate = $this->candidateFromValue($locator, $personId, 'source_locators');
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $this->dedupeAndLimit($candidates);
    }

    /**
     * @param  array<string, mixed>|null  $sourceContext
     * @return array<string, mixed>|null
     */
    private function candidateFromValue(mixed $value, ?int $personId, string $origin, ?array $sourceContext = null): ?array
    {
        $asset = is_array($value) ? $value : ['url' => $value];
        $locator = $this->firstScalar($asset, [
            'download_url',
            'source_url',
            'url',
            'uri',
            'locator',
            'source_locator',
            'path',
        ]);

        if ($locator === null) {
            return null;
        }

        $locator = trim($locator);
        if ($locator === '' || $this->looksSensitive($locator)) {
            return null;
        }

        $parsed = $this->parseLocator($locator);
        $assetType = $this->assetType($asset, $parsed);
        $provider = $this->provider($parsed);
        $policy = $this->capturePolicy($provider, $assetType, $parsed, $sourceContext ?? $asset);

        return [
            'schema' => 'review_evidence_asset_candidate.v1',
            'origin' => $origin,
            'label' => $this->safeLabel(
                $this->firstScalar($asset, ['label', 'title', 'name', 'filename'])
                    ?? $this->firstScalar($sourceContext ?? [], ['label', 'title', 'name'])
                    ?? $parsed['basename']
                    ?? 'Evidence asset'
            ),
            'provider' => $provider,
            'asset_type' => $assetType,
            'capture_policy' => $policy,
            'capture_actions' => $this->captureActions($policy, $assetType),
            'locator' => $parsed['safe_locator'],
            'locator_hash' => substr(sha1($locator), 0, 16),
            'locator_redacted' => $parsed['redacted'],
            'host' => $parsed['host'],
            'extension' => $parsed['extension'],
            'person_id' => $personId,
            'target_storage' => 'ft_reference_area',
            'download_attempted' => false,
            'mutation_allowed' => false,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function arrayItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstScalar(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array{scheme:?string, host:?string, path:string, basename:?string, extension:?string, safe_locator:string, redacted:bool}
     */
    private function parseLocator(string $locator): array
    {
        $scheme = parse_url($locator, PHP_URL_SCHEME);
        $host = parse_url($locator, PHP_URL_HOST);
        $path = (string) (parse_url($locator, PHP_URL_PATH) ?? '');
        $query = parse_url($locator, PHP_URL_QUERY);
        $fragment = parse_url($locator, PHP_URL_FRAGMENT);
        $redacted = $query !== null || $fragment !== null;

        if ($scheme !== null && $host !== null) {
            $safeLocator = strtolower($scheme).'://'.strtolower($host).$path;
            if ($redacted) {
                $safeLocator .= '?redacted=1';
            }
        } else {
            $safeLocator = $locator;
        }

        $basename = $path !== '' ? basename($path) : null;
        $extension = $basename !== null ? strtolower((string) pathinfo($basename, PATHINFO_EXTENSION)) : null;

        return [
            'scheme' => $scheme !== null ? strtolower($scheme) : null,
            'host' => $host !== null ? strtolower($host) : null,
            'path' => $path,
            'basename' => $basename !== '' ? $basename : null,
            'extension' => $extension !== '' ? $extension : null,
            'safe_locator' => $safeLocator,
            'redacted' => $redacted,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<string, mixed>  $parsed
     */
    private function assetType(array $asset, array $parsed): string
    {
        $explicit = strtolower((string) ($this->firstScalar($asset, ['asset_type', 'type', 'media_type']) ?? ''));
        if (in_array($explicit, ['image', 'pdf', 'audio', 'html', 'webpage'], true)) {
            return $explicit === 'webpage' ? 'html' : $explicit;
        }

        $contentType = strtolower((string) ($this->firstScalar($asset, ['content_type', 'mime_type']) ?? ''));
        if (str_contains($contentType, 'image/')) {
            return 'image';
        }
        if (str_contains($contentType, 'application/pdf')) {
            return 'pdf';
        }
        if (str_contains($contentType, 'audio/')) {
            return 'audio';
        }
        if (str_contains($contentType, 'text/html')) {
            return 'html';
        }

        $extension = $parsed['extension'] ?? null;
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)) {
            return 'image';
        }
        if ($extension === 'pdf') {
            return 'pdf';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true)) {
            return 'audio';
        }
        if (in_array($extension, ['html', 'htm'], true)) {
            return 'html';
        }

        return ($parsed['scheme'] ?? null) !== null ? 'html' : 'local_reference';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function provider(array $parsed): string
    {
        $host = (string) ($parsed['host'] ?? '');
        $path = (string) ($parsed['path'] ?? '');

        if ($host === '') {
            return 'local_reference';
        }
        if (str_ends_with($host, 'archives.gov') || (str_contains($host, 'amazonaws.com') && str_contains($path, 'NARAprodstorage'))) {
            return 'nara';
        }
        if ($host === 'chroniclingamerica.loc.gov' || str_ends_with($host, 'loc.gov')) {
            return 'library_of_congress';
        }
        if ($host === 'archive.org' || str_ends_with($host, '.archive.org')) {
            return 'internet_archive';
        }
        if ($host === 'images.findagrave.com') {
            return 'findagrave_image';
        }
        if ($host === 'findagrave.com' || str_ends_with($host, '.findagrave.com')) {
            return 'findagrave';
        }
        if ($host === 'billiongraves.com' || str_ends_with($host, '.billiongraves.com')) {
            return 'billiongraves';
        }
        if ($host === 'newspapers.com' || str_ends_with($host, '.newspapers.com')) {
            return 'newspapers';
        }
        if ($host === 'familysearch.org' || str_ends_with($host, '.familysearch.org')) {
            return 'familysearch';
        }
        if ($host === 'ancestry.com' || str_ends_with($host, '.ancestry.com')) {
            return 'ancestry';
        }

        return 'generic_web';
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $context
     */
    private function capturePolicy(string $provider, string $assetType, array $parsed, array $context): string
    {
        $scheme = $parsed['scheme'] ?? null;
        if ($provider === 'local_reference') {
            return 'already_local_reference';
        }
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'unsupported_scheme';
        }
        if (in_array($provider, ['familysearch', 'ancestry', 'newspapers', 'findagrave', 'billiongraves'], true)) {
            return 'manual_review_required';
        }
        if (in_array($provider, ['nara', 'library_of_congress', 'internet_archive', 'findagrave_image'], true)) {
            return 'direct_download_allowed';
        }
        if ($assetType === 'html') {
            return 'html_snapshot_allowed';
        }

        $accessClass = strtolower((string) ($this->firstScalar($context, ['source_access_class', 'access_class']) ?? ''));
        if (in_array($accessClass, ['public_archive', 'public_archive_fixture', 'free_public', 'public_domain'], true)) {
            return 'direct_download_allowed';
        }

        return 'review_required';
    }

    /**
     * @return list<string>
     */
    private function captureActions(string $policy, string $assetType): array
    {
        if ($policy === 'direct_download_allowed') {
            return [
                'download_to_ft_reference_area',
                'link_to_review_item',
                'link_to_person_when_present',
                'link_to_source_locator',
            ];
        }

        if ($policy === 'html_snapshot_allowed') {
            return [
                'store_readable_html_snapshot',
                'link_to_review_item',
                'link_to_person_when_present',
                'link_to_source_locator',
            ];
        }

        if ($policy === 'already_local_reference') {
            return [
                'link_existing_ft_asset',
                'link_to_review_item',
                'link_to_person_when_present',
            ];
        }

        if ($policy === 'manual_review_required') {
            return ['queue_manual_capture'];
        }

        return $assetType === 'html' ? ['review_before_html_snapshot'] : ['review_before_download'];
    }

    private function safeLabel(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        if ($value === '' || $this->looksSensitive($value)) {
            return 'Evidence asset';
        }

        return mb_substr($value, 0, 140);
    }

    private function looksSensitive(string $value): bool
    {
        return preg_match('/(?:token|secret|password|api[_-]?key|access[_-]?key|session)=/i', $value) === 1
            || preg_match('/^javascript:/i', trim($value)) === 1;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function dedupeAndLimit(array $candidates): array
    {
        $seen = [];
        $deduped = [];

        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['locator_hash'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $candidate;
            if (count($deduped) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        return $deduped;
    }
}
