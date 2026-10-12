<?php

use Illuminate\Contracts\Console\Kernel;

// Run through the normal Laravel command so the CLI and dev:setup share one importer.
require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $root = $argv[1] ?? '';
    $snapshot = $argv[2] ?? '';
    if (realpath($root) !== $root || ! is_file($root.'/.env') || ! is_file($snapshot)) {
        throw new RuntimeException('Development application binding is unavailable.');
    }
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if ($app->environment('production')) {
        fwrite(STDERR, "Production database cannot be a development sync target; previous generation retained.\n");
        exit(1);
    }
    $result = $kernel->call('dev:setup', ['--refresh-config' => true, '--snapshot' => $snapshot,
        '--json' => true]);
    echo $kernel->output();
    exit($result);
} catch (Throwable $error) {
    fwrite(STDERR, "Development schema/config sync failed; registry generation not activated.\n");
    exit(1);
}
