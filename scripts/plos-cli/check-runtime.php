<?php

use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;

// Read-only prerequisite probe: no app bootstrap, database, network or state files.
$required = ['curl', 'dom', 'fileinfo', 'mbstring', 'openssl', 'pdo_sqlite'];
$missing = array_values(array_filter($required, fn ($extension) => ! extension_loaded($extension)));
$dependencies = false;
try {
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    if (is_file($autoload)) {
        require $autoload;
        $dependencies = class_exists(Application::class)
            && class_exists(Client::class)
            && class_exists(Process::class);
    }
} catch (Throwable) {
    // Dependency diagnostics can contain local configuration; return status only.
}
$supported = PHP_VERSION_ID >= 80200;
echo json_encode([
    'schema' => 'plos.cli.runtime.v1',
    'version' => PHP_VERSION,
    'versionSupported' => $supported,
    'missingExtensions' => $missing,
    'dependenciesAvailable' => $dependencies,
    'ready' => $supported && $missing === [] && $dependencies,
], JSON_THROW_ON_ERROR)."\n";
