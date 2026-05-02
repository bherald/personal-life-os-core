<?php

namespace Tests\Feature\Console;

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
use App\Support\Setup\Report;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetupDoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nextcloud.url', 'http://127.0.0.1:8080');
        config()->set('services.nextcloud.username', 'plos');
        config()->set('services.nextcloud.password', 'test-password');
    }

    public function test_json_output_has_stable_top_level_keys(): void
    {
        $this->bindStubDoctor(fn () => new Report('core', false, [
            CheckResult::pass('env', 'APP_KEY', 'set'),
            CheckResult::pass('php', 'version', 'PHP 8.3'),
        ]));

        $exitCode = Artisan::call('setup:doctor', ['--json' => true, '--profile' => 'core']);
        $this->assertSame(0, $exitCode);

        $output = trim(Artisan::output());
        $payload = json_decode($output, true);
        $this->assertIsArray($payload, 'JSON output failed to decode: '.$output);
        $this->assertSame(['profile', 'strict', 'status', 'totals', 'checks'], array_keys($payload));
        $this->assertSame('core', $payload['profile']);
        $this->assertFalse($payload['strict']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(['pass' => 2, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'total' => 2], $payload['totals']);
        $this->assertCount(2, $payload['checks']);
    }

    public function test_failure_returns_exit_code_one(): void
    {
        $this->bindStubDoctor(fn () => new Report('core', false, [
            CheckResult::fail('env', 'APP_KEY', 'missing'),
        ]));

        $this->artisan('setup:doctor', ['--json' => true])
            ->assertExitCode(1);
    }

    public function test_strict_promotes_warning_to_failure_exit_code(): void
    {
        $this->bindStubDoctor(function (array $options) {
            $strict = (bool) ($options['strict'] ?? false);

            return new Report('core', $strict, [
                CheckResult::warn('binaries', 'ffmpeg', 'missing'),
            ]);
        });

        $this->artisan('setup:doctor', ['--json' => true, '--strict' => true])
            ->assertExitCode(1);
    }

    public function test_warn_only_passes_without_strict(): void
    {
        $this->bindStubDoctor(fn () => new Report('core', false, [
            CheckResult::warn('binaries', 'ffmpeg', 'missing'),
        ]));

        $this->artisan('setup:doctor', ['--json' => true])
            ->assertExitCode(0);
    }

    public function test_skip_services_option_is_forwarded(): void
    {
        $captured = [];
        $this->bindStubDoctor(function (array $options) use (&$captured) {
            $captured = $options;

            return new Report('core', false, [CheckResult::pass('env', 'APP_KEY')]);
        });

        $this->artisan('setup:doctor', ['--json' => true, '--skip-services' => true])
            ->assertExitCode(0);

        $this->assertTrue($captured['skip_services']);
    }

    public function test_only_option_is_parsed_as_list(): void
    {
        $captured = [];
        $this->bindStubDoctor(function (array $options) use (&$captured) {
            $captured = $options;

            return new Report('core', false, [CheckResult::pass('env', 'APP_KEY')]);
        });

        $this->artisan('setup:doctor', ['--json' => true, '--only' => 'env, php , binaries'])
            ->assertExitCode(0);

        $this->assertSame(['env', 'php', 'binaries'], $captured['only']);
    }

    public function test_profile_default_is_core(): void
    {
        $captured = [];
        $this->bindStubDoctor(function (array $options) use (&$captured) {
            $captured = $options;

            return new Report('core', false, [CheckResult::pass('env', 'APP_KEY')]);
        });

        $this->artisan('setup:doctor', ['--json' => true])
            ->assertExitCode(0);

        $this->assertSame('core', $captured['profile']);
    }

    public function test_invalid_profile_normalizes_to_core(): void
    {
        $captured = [];
        $this->bindStubDoctor(function (array $options) use (&$captured) {
            $captured = $options;

            return new Report('core', false, [CheckResult::pass('env', 'APP_KEY')]);
        });

        $this->artisan('setup:doctor', ['--json' => true, '--profile' => 'banana'])
            ->assertExitCode(0);

        $this->assertSame('core', $captured['profile']);
    }

    private function bindStubDoctor(\Closure $producer): void
    {
        $this->app->instance(SetupDoctor::class, new class($producer) extends SetupDoctor
        {
            public function __construct(private \Closure $producer)
            {
                parent::__construct(
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
            }

            public function diagnose(array $options = []): Report
            {
                return ($this->producer)($options);
            }
        });
    }
}
