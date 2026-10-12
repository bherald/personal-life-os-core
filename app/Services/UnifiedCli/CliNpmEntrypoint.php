<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Replace an npm shim with an explicit Node/declared-bin launch; admission follows. */
final class CliNpmEntrypoint
{
    public function bind(array $definition, string $root, string $node): array
    {
        $package = $definition['runtime_pin']['package'] ?? null;
        if (($definition['runtime_pin']['type'] ?? null) !== 'npm_lock'
            || ! is_string($package) || ! preg_match('#^(?:@[a-z0-9._-]+/)?[a-z0-9._-]+$#iD', $package)) {
            throw new RuntimeException('MCP Node binding requires an exact npm package pin.');
        }
        $root = realpath($root);
        $node = realpath($node);
        if ($root === false || $node === false || ! is_file($node) || ! is_executable($node)
            || ! in_array(strtolower(basename($node)), ['node', 'node.exe'], true)) {
            throw new RuntimeException('MCP Node runtime is unavailable.');
        }
        $command = str_replace('\\', '/', $definition['command']);
        $prefix = str_replace('\\', '/', $root).'/node_modules/.bin/';
        if (! str_starts_with($command, $prefix)) {
            throw new RuntimeException('MCP npm command must name the installed package bin.');
        }
        $name = substr($command, strlen($prefix));
        if (! preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9._-]*$/D', $name)) {
            throw new RuntimeException('MCP npm command must name one installed package bin.');
        }
        $packageRoot = realpath($root.'/node_modules/'.$package);
        $manifest = $packageRoot === false ? false : @file_get_contents($packageRoot.'/package.json');
        $installed = is_string($manifest) ? json_decode($manifest, true) : null;
        $bins = $installed['bin'] ?? [];
        if (is_string($bins)) {
            $bins = [basename($package) => $bins];
        }
        $relative = is_array($bins) ? ($bins[$name] ?? null) : null;
        $entrypoint = is_string($relative) && ! str_contains($relative, "\0")
            ? realpath($packageRoot.'/'.$relative) : false;
        if ($entrypoint === false || ! is_file($entrypoint) || ! is_readable($entrypoint)
            || ! str_starts_with($entrypoint, $packageRoot.DIRECTORY_SEPARATOR)
            || ! in_array(strtolower(pathinfo($entrypoint, PATHINFO_EXTENSION)), ['js', 'mjs', 'cjs'], true)) {
            throw new RuntimeException('MCP declared Node entrypoint is unavailable or outside its package.');
        }
        $definition['command'] = $node;
        $definition['args'] = [$entrypoint, ...$definition['args']];

        return $definition;
    }
}
