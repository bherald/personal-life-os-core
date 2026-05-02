<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\EnvChecker;
use Tests\TestCase;

class EnvCheckerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key);
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_required_missing_env_fails(): void
    {
        $this->setEnv('PLOS_TEST_REQUIRED', null);

        $checker = new EnvChecker;
        $results = $checker->run('core', [
            'core' => [
                'required' => ['PLOS_TEST_REQUIRED'],
                'recommended' => [],
            ],
            'placeholders' => ['change-me'],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('fail', $results[0]->status);
        $this->assertSame('PLOS_TEST_REQUIRED', $results[0]->name);
    }

    public function test_placeholder_required_value_fails(): void
    {
        $this->setEnv('PLOS_TEST_REQUIRED', 'change-me');

        $checker = new EnvChecker;
        $results = $checker->run('core', [
            'core' => [
                'required' => ['PLOS_TEST_REQUIRED'],
                'recommended' => [],
            ],
            'placeholders' => ['change-me'],
        ]);

        $this->assertSame('fail', $results[0]->status);
        $this->assertStringContainsString('placeholder', $results[0]->message);
    }

    public function test_core_manifest_requires_real_web_ui_master_password(): void
    {
        $manifest = config('setup.env');

        $this->assertContains('WEB_UI_MASTER_PASSWORD', $manifest['core']['required']);
        $this->assertContains('change-me', $manifest['placeholders']);
    }

    public function test_recommended_missing_env_warns(): void
    {
        $this->setEnv('PLOS_TEST_OPTIONAL', null);

        $checker = new EnvChecker;
        $results = $checker->run('core', [
            'core' => [
                'required' => [],
                'recommended' => ['PLOS_TEST_OPTIONAL'],
            ],
            'placeholders' => [],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_present_value_passes(): void
    {
        $this->setEnv('PLOS_TEST_REQUIRED', 'real-value');

        $checker = new EnvChecker;
        $results = $checker->run('core', [
            'core' => [
                'required' => ['PLOS_TEST_REQUIRED'],
                'recommended' => [],
            ],
            'placeholders' => ['change-me'],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_full_profile_walks_all_tiers(): void
    {
        $this->setEnv('PLOS_CORE_KEY', 'x');
        $this->setEnv('PLOS_MEDIA_KEY', 'y');
        $this->setEnv('PLOS_GPU_KEY', 'z');
        $this->setEnv('PLOS_PERSONAL_KEY', null);

        $checker = new EnvChecker;
        $results = $checker->run('full', [
            'core' => ['required' => ['PLOS_CORE_KEY'], 'recommended' => []],
            'media' => ['required' => ['PLOS_MEDIA_KEY'], 'recommended' => []],
            'gpu' => ['required' => ['PLOS_GPU_KEY'], 'recommended' => []],
            'full' => ['required' => [], 'recommended' => []],
            'personal' => ['required' => ['PLOS_PERSONAL_KEY'], 'recommended' => []],
            'placeholders' => [],
        ]);

        $names = array_map(fn ($r) => $r->name, $results);
        $this->assertContains('PLOS_CORE_KEY', $names);
        $this->assertContains('PLOS_MEDIA_KEY', $names);
        $this->assertContains('PLOS_GPU_KEY', $names);
        $this->assertNotContains('PLOS_PERSONAL_KEY', $names);
    }

    public function test_personal_profile_extends_full_with_personal_tier(): void
    {
        $this->setEnv('PLOS_CORE_KEY', 'x');
        $this->setEnv('PLOS_MEDIA_KEY', 'y');
        $this->setEnv('PLOS_GPU_KEY', 'z');
        $this->setEnv('PLOS_FULL_KEY', 'a');
        $this->setEnv('PLOS_PERSONAL_KEY', 'b');

        $checker = new EnvChecker;
        $results = $checker->run('personal', [
            'core' => ['required' => ['PLOS_CORE_KEY'], 'recommended' => []],
            'media' => ['required' => ['PLOS_MEDIA_KEY'], 'recommended' => []],
            'gpu' => ['required' => ['PLOS_GPU_KEY'], 'recommended' => []],
            'full' => ['required' => ['PLOS_FULL_KEY'], 'recommended' => []],
            'personal' => ['required' => ['PLOS_PERSONAL_KEY'], 'recommended' => []],
            'placeholders' => [],
        ]);

        $names = array_map(fn ($r) => $r->name, $results);
        $this->assertContains('PLOS_CORE_KEY', $names);
        $this->assertContains('PLOS_MEDIA_KEY', $names);
        $this->assertContains('PLOS_GPU_KEY', $names);
        $this->assertContains('PLOS_FULL_KEY', $names);
        $this->assertContains('PLOS_PERSONAL_KEY', $names);
    }
}
