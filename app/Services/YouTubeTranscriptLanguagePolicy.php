<?php

namespace App\Services;

class YouTubeTranscriptLanguagePolicy
{
    private array $allowedLanguages;

    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'German',
        'es' => 'Spanish',
        'zh' => 'Chinese',
    ];

    private const LANGUAGE_ALIASES = [
        'english' => 'en',
        'german' => 'de',
        'deutsch' => 'de',
        'spanish' => 'es',
        'espanol' => 'es',
        'español' => 'es',
    ];

    public function __construct()
    {
        $configured = config('youtube.transcript.allowed_languages', ['en', 'de', 'es']);

        if (! is_array($configured)) {
            $configured = ['en', 'de', 'es'];
        }

        $allowed = [];
        foreach ($configured as $language) {
            $normalized = $this->normalize($language);
            if ($normalized !== null) {
                $allowed[$normalized] = true;
            }
        }

        if ($allowed === []) {
            $allowed = ['en' => true, 'de' => true, 'es' => true];
        }

        $this->allowedLanguages = array_keys($allowed);
    }

    public function normalize(?string $language): ?string
    {
        if (! is_string($language)) {
            return null;
        }

        $language = trim(mb_strtolower($language));
        if ($language === '') {
            return null;
        }

        $asciiLanguage = str_replace('ñ', 'n', $language);
        if (in_array($asciiLanguage, ['missing', 'unknown', 'n/a', 'na'], true)) {
            return null;
        }

        if (isset(self::LANGUAGE_ALIASES[$asciiLanguage])) {
            return self::LANGUAGE_ALIASES[$asciiLanguage];
        }

        $parts = preg_split('/[-_\s]+/', $asciiLanguage);
        $base = $parts[0] ?? $asciiLanguage;

        if (! preg_match('/^[a-z]{2,3}$/', $base)) {
            return null;
        }

        return substr($base, 0, 2);
    }

    public function allowedLanguages(): array
    {
        return $this->allowedLanguages;
    }

    public function isAllowed(?string $language): bool
    {
        $normalized = $this->normalize($language);

        return $normalized !== null && in_array($normalized, $this->allowedLanguages, true);
    }

    public function validateRequestedLanguage(string $language): array
    {
        $normalized = $this->normalize($language);

        if ($normalized === null || ! $this->isAllowed($normalized)) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Unsupported transcript language "%s". Allowed languages: %s.',
                    $language,
                    $this->formatAllowedLanguages()
                ),
                'error_type' => 'UnsupportedTranscriptLanguage',
                'requested_language' => $language,
                'allowed_languages' => $this->allowedLanguages(),
            ];
        }

        return [
            'success' => true,
            'language' => $normalized,
        ];
    }

    public function guardResult(array $result, string $requestedLanguage): array
    {
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $requested = $this->normalize($requestedLanguage);
        if ($requested === null || ! $this->isAllowed($requested)) {
            return [
                'success' => false,
                'video_id' => $result['video_id'] ?? null,
                'error' => sprintf(
                    'Unsupported transcript language "%s". Allowed languages: %s.',
                    $requestedLanguage,
                    $this->formatAllowedLanguages()
                ),
                'error_type' => 'UnsupportedTranscriptLanguage',
                'requested_language' => $requestedLanguage,
                'allowed_languages' => $this->allowedLanguages(),
            ];
        }

        $actualRaw = $result['language'] ?? $requested;
        $actual = $this->normalize((string) $actualRaw);

        if ($actual === null) {
            return [
                'success' => false,
                'video_id' => $result['video_id'] ?? null,
                'error' => sprintf(
                    'Rejected transcript language "%s". Allowed languages: %s.',
                    (string) $actualRaw,
                    $this->formatAllowedLanguages()
                ),
                'error_type' => 'UnsupportedTranscriptLanguage',
                'requested_language' => $requested,
                'actual_language' => $actualRaw,
                'allowed_languages' => $this->allowedLanguages(),
                'method' => $result['method'] ?? null,
            ];
        }

        if ($actual !== $requested) {
            return [
                'success' => false,
                'video_id' => $result['video_id'] ?? null,
                'error' => sprintf(
                    'Transcript language mismatch. Requested %s but provider returned %s.',
                    $this->describe($requested),
                    $this->describe((string) $actualRaw)
                ),
                'error_type' => 'TranscriptLanguageMismatch',
                'requested_language' => $requested,
                'actual_language' => $actualRaw,
                'method' => $result['method'] ?? null,
            ];
        }

        $result['language'] = $requested;

        return $result;
    }

    public function describe(string $language): string
    {
        $normalized = $this->normalize($language);
        if ($normalized === null) {
            return $language;
        }

        $name = self::LANGUAGE_NAMES[$normalized] ?? strtoupper($normalized);

        return sprintf('%s (%s)', $name, $normalized);
    }

    public function formatAllowedLanguages(): string
    {
        return implode(', ', array_map(fn ($language) => $this->describe($language), $this->allowedLanguages));
    }
}
