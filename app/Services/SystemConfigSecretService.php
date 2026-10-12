<?php

namespace App\Services;

use InvalidArgumentException;

class SystemConfigSecretService
{
    public function sentinel(): string
    {
        return (string) config('system_configuration.secret_value_sentinel', '__PLOS_REDACTED_SECRET__');
    }

    public function isSecretKey(string $key): bool
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key)) ?? trim($key);
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $snakeCase) ?? $snakeCase);
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, (array) config('system_configuration.secret_key_names', []), true)) {
            return true;
        }

        foreach ((array) config('system_configuration.secret_key_suffixes', []) as $suffix) {
            if (str_ends_with($normalized, (string) $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{value: mixed, is_secret: bool, is_configured: bool, has_redacted_fields: bool}
     */
    public function project(string $key, mixed $value): array
    {
        if ($this->isSecretKey($key)) {
            return [
                'value' => null,
                'is_secret' => true,
                'is_configured' => $this->isConfigured($value),
                'has_redacted_fields' => false,
            ];
        }

        [$projected, $redacted] = $this->redactNested($value);

        return [
            'value' => $projected,
            'is_secret' => false,
            'is_configured' => false,
            'has_redacted_fields' => $redacted,
        ];
    }

    /**
     * @return array{value: mixed, preserved: bool, cleared: bool}
     */
    public function mergeForUpdate(
        string $key,
        mixed $incoming,
        mixed $existing,
        bool $clearSecret = false
    ): array {
        if ($this->isSecretKey($key)) {
            if ($clearSecret) {
                return ['value' => '', 'preserved' => false, 'cleared' => true];
            }

            if ($incoming === null || $incoming === '' || $incoming === $this->sentinel()) {
                return ['value' => $existing, 'preserved' => true, 'cleared' => false];
            }

            return ['value' => $incoming, 'preserved' => false, 'cleared' => false];
        }

        if ($clearSecret) {
            throw new InvalidArgumentException('clear_secret is only valid for whole-value secret configurations.');
        }

        [$merged, $preserved] = $this->mergeNested($incoming, $existing);

        return ['value' => $merged, 'preserved' => $preserved, 'cleared' => false];
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function redactNested(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value, false];
        }

        $redacted = false;
        $projected = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && $this->isSecretKey($key) && $this->isConfigured($child)) {
                $projected[$key] = $this->sentinel();
                $redacted = true;

                continue;
            }

            [$projected[$key], $childRedacted] = $this->redactNested($child);
            $redacted = $redacted || $childRedacted;
        }

        return [$projected, $redacted];
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function mergeNested(mixed $incoming, mixed $existing, ?string $fieldName = null): array
    {
        if ($incoming === $this->sentinel()) {
            if ($fieldName === null || ! $this->isSecretKey($fieldName) || ! $this->isConfigured($existing)) {
                throw new InvalidArgumentException('A redacted secret placeholder has no stored value to preserve.');
            }

            return [$existing, true];
        }

        if (! is_array($incoming)) {
            return [$incoming, false];
        }

        $existingValues = is_array($existing) ? $existing : [];
        $merged = [];
        $preserved = false;
        foreach ($incoming as $key => $child) {
            $existingChild = array_key_exists($key, $existingValues) ? $existingValues[$key] : null;
            [$merged[$key], $childPreserved] = $this->mergeNested(
                $child,
                $existingChild,
                is_string($key) ? $key : null
            );
            $preserved = $preserved || $childPreserved;
        }

        return [$merged, $preserved];
    }

    private function isConfigured(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! is_array($value) || $value !== [];
    }
}
