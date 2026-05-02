<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\PassportChecker;
use Tests\TestCase;

class PassportCheckerTest extends TestCase
{
    public function test_skips_when_api_guard_is_not_passport(): void
    {
        config()->set('auth.guards.api.driver', 'token');

        $results = (new PassportChecker)->run('core', []);

        $this->assertSame('skip', $results[0]->status);
    }

    public function test_passes_when_passport_key_files_are_present(): void
    {
        config()->set('auth.guards.api.driver', 'passport');
        config()->set('passport.private_key', null);
        config()->set('passport.public_key', null);

        $dir = $this->makeTempDir();
        file_put_contents($dir.'/oauth-private.key', 'private');
        file_put_contents($dir.'/oauth-public.key', 'public');

        $checker = new class($dir) extends PassportChecker
        {
            public function __construct(private string $dir) {}

            protected function keyPath(string $file): string
            {
                return $this->dir.'/'.$file;
            }
        };

        $results = $checker->run('core', []);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('keys', $results[0]->name);
    }

    public function test_passes_when_passport_keys_are_configured_in_environment(): void
    {
        config()->set('auth.guards.api.driver', 'passport');
        config()->set('passport.private_key', 'private-key-body');
        config()->set('passport.public_key', 'public-key-body');

        $results = (new PassportChecker)->run('core', []);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_fails_when_passport_keys_are_missing(): void
    {
        config()->set('auth.guards.api.driver', 'passport');
        config()->set('passport.private_key', null);
        config()->set('passport.public_key', null);

        $dir = $this->makeTempDir();
        $checker = new class($dir) extends PassportChecker
        {
            public function __construct(private string $dir) {}

            protected function keyPath(string $file): string
            {
                return $this->dir.'/'.$file;
            }
        };

        $results = $checker->run('core', []);

        $this->assertSame('fail', $results[0]->status);
        $this->assertStringContainsString('passport:keys', $results[0]->message);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/plos-passport-checker-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }
}
