<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];
        $seen = [];

        foreach ($this->profilesFor($profile) as $tier) {
            $section = $manifest[$tier] ?? null;
            if (! is_array($section)) {
                continue;
            }

            foreach ((array) ($section['postgres_extensions'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $connection = (string) ($entry['connection'] ?? 'pgsql_rag');
                $extension = (string) ($entry['extension'] ?? '');
                $key = "{$connection}:{$extension}";
                if ($extension === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $results[] = $this->checkPostgresExtension($connection, $extension, (bool) ($entry['required'] ?? false));
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function profilesFor(string $profile): array
    {
        return match ($profile) {
            'core' => ['core'],
            'media' => ['core', 'media'],
            'gpu' => ['core', 'media', 'gpu'],
            'full' => ['core', 'media', 'gpu', 'full'],
            'personal' => ['core', 'media', 'gpu', 'full', 'personal'],
            default => ['core'],
        };
    }

    private function checkPostgresExtension(string $connection, string $extension, bool $required): CheckResult
    {
        $name = "{$connection}.{$extension}";

        try {
            if ($this->postgresExtensionExists($connection, $extension)) {
                return CheckResult::pass('database', $name, "PostgreSQL extension '{$extension}' enabled", [
                    'connection' => $connection,
                    'extension' => $extension,
                ]);
            }
        } catch (Throwable $e) {
            $message = "could not verify PostgreSQL extension '{$extension}' on {$connection}: ".$e->getMessage();

            return $required
                ? CheckResult::fail('database', $name, $message, ['connection' => $connection, 'extension' => $extension])
                : CheckResult::warn('database', $name, $message, ['connection' => $connection, 'extension' => $extension]);
        }

        $message = "PostgreSQL extension '{$extension}' is not enabled on {$connection}";

        return $required
            ? CheckResult::fail('database', $name, $message, ['connection' => $connection, 'extension' => $extension])
            : CheckResult::warn('database', $name, $message, ['connection' => $connection, 'extension' => $extension]);
    }

    protected function postgresExtensionExists(string $connection, string $extension): bool
    {
        $row = DB::connection($connection)->selectOne(
            'SELECT extname FROM pg_extension WHERE extname = ?',
            [$extension]
        );

        return $row !== null;
    }
}
