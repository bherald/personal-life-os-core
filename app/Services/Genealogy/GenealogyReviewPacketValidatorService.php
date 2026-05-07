<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketValidatorService
{
    private const LOCATOR_KEYS = [
        'locator',
        'source_locator',
        'url',
        'uri',
        'path',
        'source_path',
        'reference_copy_path',
        'catalog_url',
        'catalog_ref',
        'call_number',
        'citation',
        'media_id',
        'source_id',
    ];

    private const CLAIM_TEXT_KEYS = [
        'claim',
        'claim_text',
        'statement',
        'extracted_claim',
        'extracted_text',
        'text',
        'value',
        'proposed_value',
        'proposed_name',
    ];

    private const MANUAL_SOURCE_VALUES = [
        'manual',
        'manual_source',
        'operator_manual',
        'human_manual',
        'unsourced_manual',
    ];

    public function validate(array $packet): array
    {
        $gates = [
            'source_locator' => $this->validateSourceLocator($packet),
            'source_realism' => $this->validateSourceRealism($packet),
            'extracted_claim' => $this->validateExtractedClaim($packet),
            'identity' => $this->validateIdentity($packet),
            'privacy' => $this->validatePrivacy($packet),
            'manual_source' => $this->validateManualSource($packet),
        ];

        $errors = [];
        $warnings = [];

        foreach ($gates as $gate => $result) {
            foreach ($result['errors'] as $error) {
                $errors[] = [
                    'gate' => $gate,
                    'code' => $error['code'],
                    'message' => $error['message'],
                ];
            }

            foreach ($result['warnings'] as $warning) {
                $warnings[] = [
                    'gate' => $gate,
                    'code' => $warning['code'],
                    'message' => $warning['message'],
                ];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'gates' => $gates,
        ];
    }

    private function validateSourceLocator(array $packet): array
    {
        if ($this->collectSourceLocators($packet) !== []) {
            return $this->pass();
        }

        return $this->fail(
            'source_locator_required',
            'At least one source locator, path, citation, media id, or source id is required.'
        );
    }

    private function validateSourceRealism(array $packet): array
    {
        foreach ($this->collectSourceLocators($packet) as $locator) {
            if ($this->isNonPublicNetworkLocator($locator)) {
                return $this->fail(
                    'non_public_source_locator_blocked',
                    'Source locators using fixture, reserved, local, or private-network hosts cannot be counted as source-backed evidence.'
                );
            }
        }

        return $this->pass();
    }

    private function validateExtractedClaim(array $packet): array
    {
        foreach ($this->collectClaims($packet) as $claim) {
            if ($this->firstTextValue($claim, self::CLAIM_TEXT_KEYS) !== '') {
                return $this->pass();
            }
        }

        if ($this->firstTextValue($packet, ['extracted_claim', 'claim_text', 'claim', 'statement']) !== '') {
            return $this->pass();
        }

        return $this->fail(
            'extracted_claim_required',
            'At least one extracted source-backed claim is required.'
        );
    }

    private function validateIdentity(array $packet): array
    {
        $identity = $this->identityPayload($packet);

        if ($this->positiveInt($packet['target_person_id'] ?? null) || $this->positiveInt($packet['person_id'] ?? null)) {
            return $this->pass();
        }

        if ($this->positiveInt($identity['person_id'] ?? null) || $this->positiveInt($identity['target_person_id'] ?? null)) {
            return $this->pass();
        }

        if (($identity['resolved'] ?? null) === true || ($identity['is_resolved'] ?? null) === true) {
            return $this->pass();
        }

        $status = strtolower(trim((string) ($identity['status'] ?? $identity['resolution_status'] ?? '')));
        if (in_array($status, ['resolved', 'matched', 'confirmed', 'accepted'], true)) {
            return $this->pass();
        }

        return $this->fail(
            'identity_resolution_required',
            'The packet must identify a resolved genealogy person before review.'
        );
    }

    private function validatePrivacy(array $packet): array
    {
        $privacy = $this->privacyPayload($packet);

        if ($privacy === []) {
            return $this->fail(
                'privacy_clearance_required',
                'The packet must include an explicit privacy clearance.'
            );
        }

        $status = strtolower(trim((string) ($privacy['status'] ?? $privacy['review_status'] ?? '')));
        $cleared = $this->truthy($privacy['cleared'] ?? null)
            || $this->truthy($privacy['privacy_cleared'] ?? null)
            || in_array($status, ['cleared', 'approved', 'public', 'not_private', 'no_risk'], true);

        $riskKeys = [
            'living',
            'is_living',
            'living_person',
            'living_person_risk',
            'private',
            'is_private',
            'private_person',
            'private_risk',
            'requires_privacy_review',
            'review_required',
        ];

        foreach ($riskKeys as $key) {
            if ($this->truthy($privacy[$key] ?? null) || $this->truthy($packet[$key] ?? null)) {
                return $this->fail(
                    'privacy_risk_blocked',
                    'Living-person or private-person risk must be cleared before review packet materialization.'
                );
            }
        }

        if (! $cleared) {
            return $this->fail(
                'privacy_clearance_required',
                'The packet must include an explicit privacy clearance.'
            );
        }

        return $this->pass();
    }

    private function validateManualSource(array $packet): array
    {
        if ($this->isManualSource($packet)) {
            return $this->fail(
                'manual_source_as_evidence_blocked',
                'Manual sources cannot be used as evidence for a genealogy review packet.'
            );
        }

        foreach ($this->collectSourcePayloads($packet) as $source) {
            if ($this->isManualSource($source)) {
                return $this->fail(
                    'manual_source_as_evidence_blocked',
                    'Manual sources cannot be used as evidence for a genealogy review packet.'
                );
            }
        }

        return $this->pass();
    }

    /**
     * @return string[]
     */
    public function collectSourceLocators(array $packet): array
    {
        $locators = [];

        foreach ($this->collectSourcePayloads($packet) as $source) {
            foreach (self::LOCATOR_KEYS as $key) {
                $value = $source[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $locators[] = trim((string) $value);
                }
            }
        }

        foreach (self::LOCATOR_KEYS as $key) {
            $value = $packet[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $locators[] = trim((string) $value);
            }
        }

        return array_values(array_unique($locators));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectClaims(array $packet): array
    {
        $claims = [];

        foreach (['claims', 'extracted_claims', 'facts', 'proposals'] as $key) {
            foreach ($this->listOfArrays($packet[$key] ?? []) as $claim) {
                $claims[] = $claim;
            }
        }

        if (isset($packet['claim']) || isset($packet['extracted_claim']) || isset($packet['claim_text'])) {
            $claims[] = $packet;
        }

        return $claims;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectSourcePayloads(array $packet): array
    {
        $sources = [];

        foreach (['source', 'source_locator', 'primary_source'] as $key) {
            if (isset($packet[$key])) {
                $sources = array_merge($sources, $this->normalizeSourceValue($packet[$key], $key));
            }
        }

        foreach (['sources', 'source_locators', 'evidence_sources', 'citations', 'media'] as $key) {
            foreach ((array) ($packet[$key] ?? []) as $source) {
                $sources = array_merge($sources, $this->normalizeSourceValue($source, $key));
            }
        }

        foreach ($this->collectClaims($packet) as $claim) {
            foreach (['source', 'source_locator', 'evidence_source', 'citation'] as $key) {
                if (isset($claim[$key])) {
                    $sources = array_merge($sources, $this->normalizeSourceValue($claim[$key], $key));
                }
            }
        }

        return $sources;
    }

    private function isManualSource(array $source): bool
    {
        if ($this->hasManualOnlyLocator($source)) {
            return true;
        }

        foreach (['manual', 'manual_source', 'is_manual', 'manual_only', 'manual_required', 'requires_manual', 'needs_manual'] as $key) {
            if ($this->truthy($source[$key] ?? null)) {
                return true;
            }
        }

        foreach (['type', 'source_type', 'kind', 'method', 'provenance', 'origin'] as $key) {
            $value = strtolower(trim((string) ($source[$key] ?? '')));
            if (in_array($value, self::MANUAL_SOURCE_VALUES, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasManualOnlyLocator(array $source): bool
    {
        foreach (self::LOCATOR_KEYS as $key) {
            $value = $source[$key] ?? null;
            if (is_scalar($value) && $this->isManualOnlyDomainLocator(trim((string) $value))) {
                return true;
            }
        }

        return false;
    }

    private function isManualOnlyDomainLocator(string $locator): bool
    {
        if ($locator === '') {
            return false;
        }

        $host = parse_url($locator, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:[\/?#]|$)/i', $locator) !== 1) {
                return false;
            }

            $host = parse_url('https://'.$locator, PHP_URL_HOST);
        }

        if (! is_string($host) || trim($host) === '') {
            return false;
        }

        $host = strtolower(trim($host));
        foreach ((array) config('scraping.manual_only_domains', []) as $domain) {
            if (! is_scalar($domain)) {
                continue;
            }

            $domain = strtolower(trim((string) $domain));
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function isNonPublicNetworkLocator(string $locator): bool
    {
        $locator = trim($locator);
        if ($locator === '') {
            return false;
        }

        if (preg_match('/^(file|ark|doi|urn):/i', $locator) === 1
            || str_starts_with($locator, '/')
            || str_starts_with($locator, './')
            || str_starts_with($locator, '../')
            || str_starts_with($locator, '~')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $locator) === 1
        ) {
            return false;
        }

        $host = parse_url($locator, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:[\/?#]|$)/i', $locator) !== 1) {
                return false;
            }

            $host = parse_url('https://'.$locator, PHP_URL_HOST);
        }

        if (! is_string($host) || trim($host) === '') {
            return false;
        }

        return $this->isNonPublicHost($host);
    }

    private function isNonPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B."));
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        foreach (['localhost', 'test', 'example', 'invalid'] as $reservedTld) {
            if ($host === $reservedTld || str_ends_with($host, '.'.$reservedTld)) {
                return true;
            }
        }

        foreach (['example.com', 'example.net', 'example.org'] as $reservedDomain) {
            if ($host === $reservedDomain || str_ends_with($host, '.'.$reservedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function identityPayload(array $packet): array
    {
        $identity = $packet['identity'] ?? $packet['target_identity'] ?? $packet['person_identity'] ?? [];

        return is_array($identity) ? $identity : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function privacyPayload(array $packet): array
    {
        $privacy = $packet['privacy'] ?? $packet['privacy_gate'] ?? $packet['privacy_review'] ?? [];

        return is_array($privacy) ? $privacy : [];
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSourceValue($value, string $fallbackKey): array
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                return array_values(array_filter(array_map(
                    fn ($item): ?array => $this->normalizeSingleSource($item, $fallbackKey),
                    $value
                )));
            }

            return [$value];
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return [$this->normalizeScalarSource(trim((string) $value), $fallbackKey)];
        }

        return [];
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>|null
     */
    private function normalizeSingleSource($value, string $fallbackKey): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return $this->normalizeScalarSource(trim((string) $value), $fallbackKey);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeScalarSource(string $value, string $fallbackKey): array
    {
        $source = [$fallbackKey => $value];

        if (in_array($fallbackKey, ['citation', 'citations'], true)) {
            $source['citation'] = $value;
        } elseif (in_array($fallbackKey, ['source_locator', 'source_locators'], true) || $this->looksLikeLocator($value)) {
            $source['locator'] = $value;
        }

        return $source;
    }

    private function looksLikeLocator(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $value) === 1
            || preg_match('/^(ark:|doi:|urn:)/i', $value) === 1
            || str_starts_with($value, '/')
            || str_starts_with($value, './')
            || str_starts_with($value, '../')
            || str_starts_with($value, '~')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $value) === 1
            || preg_match('/\.(pdf|jpg|jpeg|png|tif|tiff|txt|md|csv|json|xml|ged|gedcom|html?)($|[?#])/i', $value) === 1;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function listOfArrays($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if ($value === []) {
            return [];
        }

        if (! $this->isList($value)) {
            return [$value];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function firstTextValue(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param  mixed  $value
     */
    private function positiveInt($value): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    private function truthy(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }

    private function pass(array $warnings = []): array
    {
        return [
            'passed' => true,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    private function fail(string $code, string $message): array
    {
        return [
            'passed' => false,
            'errors' => [
                [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
            'warnings' => [],
        ];
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
