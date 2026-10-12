<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Bind an installed PLOS development application; production is never overwritten. */
final class CliDevelopmentDatabaseSync
{
    public static function forApplication(string $root): ?Closure
    {
        $configured = getenv('PLOS_CLI_DEV_APP_ROOT');
        if (is_string($configured) && $configured !== '') {
            $root = $configured;
        } elseif (! is_file($root.'/.env')) {
            return null;
        }

        return static function (string $snapshot) use ($root): void {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/plos-cli/sync-dev-database.php',
                $root, $snapshot]);
            $process->setTimeout(180);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) === 'Production database cannot be a development sync target; previous generation retained.'
                    ? 'Production database cannot be a development sync target; previous generation retained.'
                    : 'Development schema/config sync failed; registry generation not activated.');
            }
        };
    }
}
