<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;
use App\Support\Setup\Report;

/**
 * Orchestrates the public-setup health checks. Read-only by contract:
 * never installs, never writes outside report output, never reaches
 * beyond localhost.
 */
class SetupDoctor
{
    public function __construct(
        protected EnvChecker $env,
        protected PhpChecker $php,
        protected BinaryChecker $binaries,
        protected PythonChecker $python,
        protected ServiceChecker $services,
        protected PassportChecker $passport,
        protected DatabaseChecker $database,
        protected BrowserChecker $browser,
        protected AssetChecker $assets,
        protected DockerChecker $docker,
    ) {}

    /**
     * @param  array{profile?: string, strict?: bool, only?: list<string>, skip_services?: bool}  $options
     */
    public function diagnose(array $options = []): Report
    {
        $profile = $this->normalizeProfile((string) ($options['profile'] ?? 'core'));
        $strict = (bool) ($options['strict'] ?? false);
        $skipServices = (bool) ($options['skip_services'] ?? false);
        $only = array_values(array_filter(array_map('strval', $options['only'] ?? [])));

        $manifest = (array) config('setup', []);
        $allowedGroups = (array) ($manifest['groups'] ?? ['env', 'php', 'binaries', 'python', 'services', 'database', 'browser', 'assets', 'docker']);
        $selected = $only === [] ? $allowedGroups : array_values(array_intersect($allowedGroups, $only));

        $results = [];
        foreach ($allowedGroups as $group) {
            if (! in_array($group, $selected, true)) {
                $results[] = CheckResult::skip($group, 'group', "group '{$group}' filtered out by --only");

                continue;
            }

            if (in_array($group, ['services', 'database'], true) && $skipServices) {
                $results[] = CheckResult::skip($group, 'group', "{$group} group skipped via --skip-services");

                continue;
            }

            $groupResults = $this->runGroup($group, $profile, $manifest);
            if ($groupResults === []) {
                $results[] = CheckResult::skip($group, 'group', "no checks declared for {$group}");

                continue;
            }

            foreach ($groupResults as $r) {
                $results[] = $r;
            }
        }

        return new Report($profile, $strict, $results);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    protected function runGroup(string $group, string $profile, array $manifest): array
    {
        return match ($group) {
            'env' => $this->env->run($profile, (array) ($manifest['env'] ?? [])),
            'php' => $this->php->run($profile, (array) ($manifest['php'] ?? [])),
            'binaries' => $this->binaries->run($profile, (array) ($manifest['binaries'] ?? [])),
            'python' => $this->python->run($profile, (array) ($manifest['python'] ?? [])),
            'services' => $this->services->run($profile, (array) ($manifest['services'] ?? [])),
            'passport' => $this->passport->run($profile, (array) ($manifest['passport'] ?? [])),
            'database' => $this->database->run($profile, (array) ($manifest['database'] ?? [])),
            'browser' => $this->browser->run($profile, (array) ($manifest['browser'] ?? [])),
            'assets' => $this->assets->run($profile, (array) ($manifest['assets'] ?? [])),
            'docker' => $this->docker->run($profile, (array) ($manifest['docker'] ?? [])),
            default => [],
        };
    }

    public function normalizeProfile(string $profile): string
    {
        $allowed = ['core', 'media', 'gpu', 'full', 'personal'];

        return in_array($profile, $allowed, true) ? $profile : 'core';
    }
}
