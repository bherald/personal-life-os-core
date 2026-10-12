<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Explicit process isolation. No implicit online escape from LAN/offline. */
final class CliMcpProcessPlan
{
    public function __construct(
        private readonly ?string $bubblewrap = null,
        private readonly ?CliWindowsProcessPlan $windows = null,
        private readonly ?string $applicationRoot = null,
    ) {}

    public function build(array $bound, string $network, string $workspace, int $timeoutMs = 30000, ?array $nativePermissions = null, string $operatorMode = 'operator'): array
    {
        $direct = PHP_OS_FAMILY === 'Linux' && ($nativePermissions['type'] ?? null) === 'dangerFullAccess';
        if ($direct) {
            CliNativeExecution::validatePolicy($nativePermissions, $network);
            CliOperatorModes::validate($operatorMode);
            if (! in_array($operatorMode, ['coding', 'operator', 'autonomous'], true)) {
                throw new RuntimeException('Current operator mode does not permit unrestricted MCP processes.');
            }
        }
        $bubblewrap = PHP_OS_FAMILY === 'Linux' && ! $direct ? ($this->bubblewrap ?? CliLinuxSandbox::executable()) : null;
        if (! in_array(PHP_OS_FAMILY, ['Linux', 'Windows'], true)
            || (PHP_OS_FAMILY === 'Linux' && ! $direct && ! is_executable($bubblewrap))) {
            throw new RuntimeException('MCP process isolation is unavailable on this execution host.');
        }
        $workspace = $workspace === '' ? false : realpath($workspace);
        if ($workspace === false || ! is_dir($workspace) || $workspace === '/') {
            throw new RuntimeException('MCP execution requires an explicit workspace directory.');
        }
        if (! in_array($network, ['offline', 'lan', 'online'], true)) {
            throw new RuntimeException('MCP execution requires an explicit network mode.');
        }
        $required = $bound['network_required'] ?? null;
        if ($network !== 'online' && $required !== 'none') {
            throw new RuntimeException('This MCP server requires a LAN/loopback process network broker that is not yet admitted.');
        }
        if ($direct) {
            // The operator's existing native permission explicitly grants host
            // execution. MCP registry, path, approval and environment checks
            // still run before this planner; offline/LAN never enter this path.
            return ['command' => $bound['command'], 'args' => $bound['args'], 'cwd' => $workspace,
                'env' => $bound['env'] ?? []];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if ($this->windows === null) {
                throw new RuntimeException('Windows MCP isolation requires explicit local adapter bindings.');
            }

            return $this->windows->build($bound, $network !== 'online' || $required === 'none' ? 'offline' : 'online',
                $workspace, $timeoutMs);
        }
        $args = ['--die-with-parent', '--new-session', '--unshare-pid', '--ro-bind', '/', '/',
            '--dev', '/dev', '--proc', '/proc', '--tmpfs', '/tmp'];
        if ($this->applicationRoot !== null) {
            $application = realpath($this->applicationRoot);
            if ($application === false || ! is_dir($application) || in_array($application, ['/', '/tmp'], true)) {
                throw new RuntimeException('MCP application installation is unavailable.');
            }
            // Preserve the selected read-only runtime when private /tmp masks its installation.
            if (str_starts_with($application, '/tmp/')) {
                array_push($args, '--ro-bind', $application, $application);
            }
        }
        array_push($args, '--bind', $workspace, $workspace, '--chdir', $workspace);
        if ($network !== 'online' || $required === 'none') {
            $args[] = '--unshare-net';
        }
        array_push($args, '--', $bound['command'], ...$bound['args']);

        return ['command' => $bubblewrap, 'args' => $args, 'cwd' => $workspace,
            'env' => $bound['env'] ?? []];
    }
}
