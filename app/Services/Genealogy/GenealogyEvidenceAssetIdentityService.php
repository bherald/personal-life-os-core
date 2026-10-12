<?php

namespace App\Services\Genealogy;

class GenealogyEvidenceAssetIdentityService
{
    private const GENERATION_KEYS = [
        'source_generation',
        'source_revision',
        'source_version',
        'object_version',
        'revision_id',
        'source_etag',
    ];

    private const TRACKING_QUERY_KEYS = [
        '_ga',
        '_gl',
        'dclid',
        'fbclid',
        'gclid',
        'mc_cid',
        'mc_eid',
        'msclkid',
        'utm',
    ];

    private const SIGNATURE_QUERY_KEYS = [
        'awsaccesskeyid',
        'expires',
        'key-pair-id',
        'policy',
        'signature',
    ];

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<string, mixed>  $sourceContext
     * @return array{hash:string, mode:string, source_generation_present:bool, volatile_parameters_removed:int, fragment_removed:bool}
     */
    public function fingerprint(string $locator, array $asset = [], array $sourceContext = []): array
    {
        $normalized = $this->normalizeLocator($locator);
        $generation = $this->firstScalar([$asset, $sourceContext], self::GENERATION_KEYS);
        $material = $normalized['locator'];
        $mode = $normalized['changed'] ? 'canonical_locator' : 'exact_locator';

        if ($generation !== null) {
            $material .= "\0plos-source-generation-v1\0".$generation;
            $mode = 'source_generation';
        }

        return [
            'hash' => sha1($material),
            'mode' => $mode,
            'source_generation_present' => $generation !== null,
            'volatile_parameters_removed' => $normalized['volatile_parameters_removed'],
            'fragment_removed' => $normalized['fragment_removed'],
        ];
    }

    /**
     * @return array{locator:string, changed:bool, volatile_parameters_removed:int, fragment_removed:bool}
     */
    private function normalizeLocator(string $locator): array
    {
        $scheme = strtolower((string) (parse_url($locator, PHP_URL_SCHEME) ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return [
                'locator' => $locator,
                'changed' => false,
                'volatile_parameters_removed' => 0,
                'fragment_removed' => false,
            ];
        }

        $fragmentPosition = strpos($locator, '#');
        $fragmentRemoved = $fragmentPosition !== false;
        $withoutFragment = $fragmentRemoved ? substr($locator, 0, $fragmentPosition) : $locator;
        $queryPosition = strpos($withoutFragment, '?');

        if ($queryPosition === false) {
            return [
                'locator' => $withoutFragment,
                'changed' => $fragmentRemoved,
                'volatile_parameters_removed' => 0,
                'fragment_removed' => $fragmentRemoved,
            ];
        }

        $base = substr($withoutFragment, 0, $queryPosition);
        $query = substr($withoutFragment, $queryPosition + 1);
        $pairs = $query === '' ? [] : explode('&', $query);
        $keys = array_map(fn (string $pair): string => $this->queryKey($pair), $pairs);
        $signedQuery = $this->hasSignatureMarker($keys);
        $kept = [];
        $removed = 0;

        foreach ($pairs as $index => $pair) {
            if ($this->isVolatileQueryKey($keys[$index], $signedQuery)) {
                $removed++;

                continue;
            }

            $kept[] = $pair;
        }

        $canonical = $base.($kept === [] ? '' : '?'.implode('&', $kept));

        return [
            'locator' => $canonical,
            'changed' => $fragmentRemoved || $removed > 0,
            'volatile_parameters_removed' => $removed,
            'fragment_removed' => $fragmentRemoved,
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function hasSignatureMarker(array $keys): bool
    {
        foreach ($keys as $key) {
            if (str_starts_with($key, 'x-amz-')
                || str_starts_with($key, 'x-goog-')
                || in_array($key, ['awsaccesskeyid', 'key-pair-id', 'signature'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isVolatileQueryKey(string $key, bool $signedQuery): bool
    {
        if (str_starts_with($key, 'utm_')
            || str_starts_with($key, 'x-amz-')
            || str_starts_with($key, 'x-goog-')
            || in_array($key, self::TRACKING_QUERY_KEYS, true)) {
            return true;
        }

        return $signedQuery && in_array($key, self::SIGNATURE_QUERY_KEYS, true);
    }

    private function queryKey(string $pair): string
    {
        $equals = strpos($pair, '=');
        $raw = $equals === false ? $pair : substr($pair, 0, $equals);

        return strtolower(rawurldecode($raw));
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $keys
     */
    private function firstScalar(array $sources, array $keys): ?string
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;
                if (! is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);
                if ($value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        }

        return null;
    }
}
