<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class PassportChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $driver = (string) config('auth.guards.api.driver', '');
        if ($driver !== 'passport') {
            return [
                CheckResult::skip('passport', 'guard', 'api guard is not configured for Passport'),
            ];
        }

        if ($this->configuredWithEnvKeys()) {
            return [
                CheckResult::pass('passport', 'keys', 'Passport keys configured through environment'),
            ];
        }

        $privateKey = $this->keyPath('oauth-private.key');
        $publicKey = $this->keyPath('oauth-public.key');

        if ($this->present($privateKey) && $this->present($publicKey)) {
            return [
                CheckResult::pass('passport', 'keys', 'Passport key files are present', [
                    'private_key' => $privateKey,
                    'public_key' => $publicKey,
                ]),
            ];
        }

        return [
            CheckResult::fail(
                'passport',
                'keys',
                'Passport keys missing; run `php artisan passport:keys --force` or configure PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY',
                [
                    'private_key' => $privateKey,
                    'public_key' => $publicKey,
                ],
            ),
        ];
    }

    protected function keyPath(string $file): string
    {
        return storage_path($file);
    }

    private function configuredWithEnvKeys(): bool
    {
        return trim((string) config('passport.private_key', '')) !== ''
            && trim((string) config('passport.public_key', '')) !== '';
    }

    private function present(string $path): bool
    {
        return is_readable($path) && filesize($path) > 0;
    }
}
