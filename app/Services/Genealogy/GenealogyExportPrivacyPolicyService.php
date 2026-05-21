<?php

namespace App\Services\Genealogy;

/**
 * Shared policy for export- and readiness-related privacy posture.
 *
 * - private_local / rag: local/private workflows (include living by default)
 * - public_export: privacy-first option (exclude living unless caller explicitly opts in)
 */
class GenealogyExportPrivacyPolicyService
{
    public const CONTEXT_PUBLIC_EXPORT = 'public_export';

    public const CONTEXT_PRIVATE_LOCAL = 'private_local';

    public const CONTEXT_RAG = 'rag';

    /**
     * @var array<string, array<string, bool|string>>
     */
    private const CONTEXT_DEFAULTS = [
        self::CONTEXT_PRIVATE_LOCAL => [
            'include_living' => true,
        ],
        self::CONTEXT_PUBLIC_EXPORT => [
            'include_living' => false,
        ],
        self::CONTEXT_RAG => [
            'include_living' => true,
        ],
    ];

    public function normalizeExportOptions(array $options): array
    {
        $context = $this->normalizeContext($options['privacy_context'] ?? null);
        $defaults = self::CONTEXT_DEFAULTS[$context];

        return array_merge($defaults, $options);
    }

    public function includePersonInExport(object|array $person, bool $includeLiving): bool
    {
        return $includeLiving || ! $this->isLikelyLiving($person);
    }

    public function isLikelyLiving(object|array $person): bool
    {
        $personData = $this->personToArray($person);
        $living = $personData['living'] ?? null;

        if ($living !== null) {
            return (bool) $living;
        }

        if (! empty($personData['death_date'])) {
            return false;
        }

        $threshold = $this->personToInt($personData['living_years_threshold'] ?? 100, 100);
        $birthYear = $this->extractYear((string) ($personData['birth_date'] ?? null));
        if ($birthYear !== null && ((int) date('Y') - $birthYear) > $threshold) {
            return false;
        }

        return true;
    }

    public function hasPrivatePrivacyOverride(object|array $person): bool
    {
        $privacyOverride = strtolower((string) ($this->personToArray($person)['privacy_override'] ?? 'default'));

        return in_array($privacyOverride, ['private', 'restricted'], true);
    }

    public function shouldFlagPrivateOverrideRisk(object|array $person): bool
    {
        return $this->hasPrivatePrivacyOverride($person);
    }

    public function hasPublicExportPrivacyRisk(object|array $person): bool
    {
        return $this->isLikelyLiving($person) || $this->shouldFlagPrivateOverrideRisk($person);
    }

    private function normalizeContext(?string $context): string
    {
        return match ($context) {
            self::CONTEXT_PUBLIC_EXPORT, self::CONTEXT_RAG => $context,
            default => self::CONTEXT_PRIVATE_LOCAL,
        };
    }

    private function personToArray(object|array $person): array
    {
        return is_object($person) ? (array) $person : $person;
    }

    private function personToInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function extractYear(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        if (preg_match('/^(\\d{4})/', $date, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(\\d{4})$/', $date, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
