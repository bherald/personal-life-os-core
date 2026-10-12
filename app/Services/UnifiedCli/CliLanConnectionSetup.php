<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Prepare local connection files; never contact a server or grant tool execution. */
final class CliLanConnectionSetup
{
    public function prepare(string $directory, string $controller, string $model, string $instance, string $caFile, string $caHash, string $controllerTokenFile, string $modelTokenFile): array
    {
        if (! str_starts_with($directory, '/') || file_exists($directory) || is_link($directory)
            || ! is_dir(dirname($directory)) || realpath(dirname($directory)) !== dirname($directory)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $instance)) {
            throw new RuntimeException('Use a new absolute connection directory beneath an existing canonical directory and an exact instance ID.');
        }
        clearstatcache(true, dirname($directory));
        $parent = stat(dirname($directory));
        if ($parent === false || ! function_exists('posix_geteuid')
            || $parent['uid'] !== posix_geteuid() || ($parent['mode'] & 0022) !== 0) {
            throw new RuntimeException('The connection parent must be owned by this user and not writable by another user or group.');
        }
        $authority = ['ca_file' => $caFile, 'ca_sha256' => $caHash];
        $origins = [];
        foreach ([$controller, $model] as $endpoint) {
            (new CliNetworkBoundary)->requestOptions($endpoint, 'lan');
            $parts = parse_url($endpoint);
            if ($parts['scheme'] !== 'https' || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new RuntimeException('Use exact HTTPS LAN service origins without API paths.');
            }
            $origins[] = 'https://'.strtolower($parts['host']).':'.($parts['port'] ?? 443);
        }
        $policy = ['require_lan_tls' => true, 'authorities' => array_fill_keys($origins, $authority)];
        $tls = new CliTlsPolicy($policy);
        foreach ($origins as $origin) {
            $tls->routeBinding($origin, 'lan');
        }
        $ca = CliLocalBindings::readPrivateFile($caFile);
        if (! hash_equals($caHash, hash('sha256', $ca))) {
            throw new RuntimeException('CA changed while preparing the connection.');
        }
        $tokens = [];
        foreach (['controller' => $controllerTokenFile, 'model' => $modelTokenFile] as $name => $file) {
            $token = trim(CliLocalBindings::readPrivateFile($file));
            if (strlen($token) < 32 || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException('Connection credential format is invalid; no files were written.');
            }
            $tokens[$name] = $token;
        }
        $controllerRef = 'host:lan-controller:PLOS_MODEL_HOST_CONTROLLER_TOKEN';
        $modelRef = 'llm:'.$instance.':PLOS_LAN_MODEL_TOKEN';
        $bindings = [$controllerRef => ['source' => 'file', 'path' => $directory.'/controller.token'],
            $modelRef => ['source' => 'file', 'path' => $directory.'/model.token']];
        foreach ($policy['authorities'] as &$entry) {
            $entry['ca_file'] = $directory.'/ca.pem';
        }
        unset($entry);
        $settings = ['cli.network' => 'lan', 'cli.controller_url' => $origins[0], 'cli.tls_policy' => $policy,
            'registry.llm_instances.'.$instance.'.base_url' => $origins[1],
            'registry.llm_instances.'.$instance.'.api_key_env' => 'PLOS_LAN_MODEL_TOKEN'];
        $controllerBinding = ['schema' => 'plos.cli.controller-binding.v1', 'base_url' => $origins[0], 'token_reference' => $controllerRef];
        $json = fn ($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $files = ['ca.pem' => $ca, 'controller.token' => $tokens['controller'], 'model.token' => $tokens['model'],
            'bindings.json' => $json($bindings), 'controller-binding.json' => $json($controllerBinding), 'settings.json' => $json($settings)];
        $oldMask = umask(0077);
        try {
            if (! mkdir($directory, 0700)) {
                throw new RuntimeException('Cannot create private connection directory.');
            }
            foreach ($files as $name => $bytes) {
                $handle = fopen($directory.'/'.$name, 'xb');
                if ($handle === false) {
                    throw new RuntimeException('Connection preparation stopped; partial private files were retained.');
                }
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                        throw new RuntimeException('Connection preparation stopped; partial private files were retained.');
                    }
                } finally {
                    fclose($handle);
                }
                if (CliLocalBindings::readPrivateFile($directory.'/'.$name) !== $bytes) {
                    throw new RuntimeException('Private connection file verification failed.');
                }
            }
        } finally {
            umask($oldMask);
        }

        return ['prepared' => true, 'directory' => $directory, 'controller_origin' => $origins[0], 'model_origin' => $origins[1],
            'ca_sha256' => $caHash, 'files' => array_keys($files), 'network_requests' => 0, 'execution_authorized' => false,
            'next_step' => 'Import settings into the selected CLI session and reconcile its published controller-catalog port. Existing model, controller and lease admission remains required.'];
    }
}
