<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\AssetChecker;
use App\Services\Setup\BinaryChecker;
use App\Services\Setup\BrowserChecker;
use App\Services\Setup\DatabaseChecker;
use App\Services\Setup\DockerChecker;
use App\Services\Setup\EnvChecker;
use App\Services\Setup\PassportChecker;
use App\Services\Setup\PhpChecker;
use App\Services\Setup\PythonChecker;
use App\Services\Setup\ServiceChecker;
use App\Services\Setup\SetupDoctor;
use App\Support\Setup\CheckResult;
use Tests\TestCase;

class SetupDoctorTest extends TestCase
{
    public function test_diagnose_skips_services_when_flag_set(): void
    {
        config()->set('setup', $this->minimalManifest());

        $doctor = $this->makeDoctor(services: [CheckResult::pass('services', 'mysql')]);

        $report = $doctor->diagnose([
            'profile' => 'core',
            'skip_services' => true,
        ]);

        $services = array_values(array_filter($report->checks, fn ($c) => $c->group === 'services'));
        $this->assertCount(1, $services);
        $this->assertSame('skip', $services[0]->status);
        $this->assertStringContainsString('skip-services', $services[0]->message);
    }

    public function test_only_filters_groups(): void
    {
        config()->set('setup', $this->minimalManifest());

        $doctor = $this->makeDoctor(
            env: [CheckResult::pass('env', 'APP_KEY')],
            php: [CheckResult::pass('php', 'version')],
        );

        $report = $doctor->diagnose([
            'profile' => 'core',
            'only' => ['env'],
        ]);

        $groupsRun = array_values(array_unique(array_map(fn ($c) => $c->group, array_filter(
            $report->checks,
            fn ($c) => $c->status !== 'skip'
        ))));

        $this->assertSame(['env'], $groupsRun);
    }

    public function test_strict_promotes_warn_to_failure_in_report(): void
    {
        config()->set('setup', $this->minimalManifest());

        $doctor = $this->makeDoctor(env: [CheckResult::warn('env', 'OPTIONAL', 'unset')]);

        $report = $doctor->diagnose([
            'profile' => 'core',
            'strict' => true,
        ]);

        $this->assertSame('fail', $report->status());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_unknown_profile_normalizes_to_core(): void
    {
        $doctor = new SetupDoctor(
            new EnvChecker,
            new PhpChecker,
            new BinaryChecker,
            new PythonChecker,
            new ServiceChecker,
            new PassportChecker,
            new DatabaseChecker,
            new BrowserChecker,
            new AssetChecker,
            new DockerChecker,
        );

        $this->assertSame('core', $doctor->normalizeProfile('banana'));
        $this->assertSame('media', $doctor->normalizeProfile('media'));
        $this->assertSame('personal', $doctor->normalizeProfile('personal'));
    }

    /**
     * @param  list<CheckResult>  $env
     * @param  list<CheckResult>  $php
     * @param  list<CheckResult>  $binaries
     * @param  list<CheckResult>  $python
     * @param  list<CheckResult>  $services
     * @param  list<CheckResult>  $passport
     * @param  list<CheckResult>  $database
     * @param  list<CheckResult>  $browser
     * @param  list<CheckResult>  $assets
     * @param  list<CheckResult>  $docker
     */
    private function makeDoctor(
        array $env = [],
        array $php = [],
        array $binaries = [],
        array $python = [],
        array $services = [],
        array $passport = [],
        array $database = [],
        array $browser = [],
        array $assets = [],
        array $docker = [],
    ): SetupDoctor {
        return new SetupDoctor(
            $this->stubEnv($env),
            $this->stubPhp($php),
            $this->stubBinaries($binaries),
            $this->stubPython($python),
            $this->stubServices($services),
            $this->stubPassport($passport),
            $this->stubDatabase($database),
            $this->stubBrowser($browser),
            $this->stubAssets($assets),
            $this->stubDocker($docker),
        );
    }

    /** @param list<CheckResult> $results */
    private function stubEnv(array $results): EnvChecker
    {
        return new class($results) extends EnvChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubPhp(array $results): PhpChecker
    {
        return new class($results) extends PhpChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubBinaries(array $results): BinaryChecker
    {
        return new class($results) extends BinaryChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubPython(array $results): PythonChecker
    {
        return new class($results) extends PythonChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubServices(array $results): ServiceChecker
    {
        return new class($results) extends ServiceChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubPassport(array $results): PassportChecker
    {
        return new class($results) extends PassportChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubDatabase(array $results): DatabaseChecker
    {
        return new class($results) extends DatabaseChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubBrowser(array $results): BrowserChecker
    {
        return new class($results) extends BrowserChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubAssets(array $results): AssetChecker
    {
        return new class($results) extends AssetChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /** @param list<CheckResult> $results */
    private function stubDocker(array $results): DockerChecker
    {
        return new class($results) extends DockerChecker
        {
            /** @param list<CheckResult> $results */
            public function __construct(private array $results) {}

            public function run(string $profile, array $manifest): array
            {
                return $this->results;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalManifest(): array
    {
        return [
            'profiles' => ['core', 'media', 'gpu', 'full', 'personal'],
            'groups' => ['env', 'php', 'binaries', 'python', 'services', 'passport', 'database', 'browser', 'assets', 'docker'],
            'env' => [],
            'php' => [],
            'binaries' => [],
            'python' => [],
            'services' => [],
            'passport' => [],
            'database' => [],
            'browser' => [],
            'assets' => [],
            'docker' => [],
        ];
    }
}
