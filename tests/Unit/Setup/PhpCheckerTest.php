<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\PhpChecker;
use Tests\TestCase;

class PhpCheckerTest extends TestCase
{
    public function test_passes_when_php_meets_recommended_and_extensions_loaded(): void
    {
        $checker = new class extends PhpChecker
        {
            protected function phpVersion(): string
            {
                return '8.3.5';
            }

            protected function extensionLoaded(string $name): bool
            {
                return $name === 'curl';
            }
        };

        $results = $checker->run('core', [
            'min_version' => '8.2.0',
            'recommended_version' => '8.3.0',
            'extensions' => ['core' => ['curl']],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('pass', $results[1]->status);
    }

    public function test_fails_when_php_below_minimum(): void
    {
        $checker = new class extends PhpChecker
        {
            protected function phpVersion(): string
            {
                return '8.1.0';
            }

            protected function extensionLoaded(string $name): bool
            {
                return true;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '8.2.0',
            'recommended_version' => '8.3.0',
            'extensions' => ['core' => []],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_warns_when_php_below_recommended_only(): void
    {
        $checker = new class extends PhpChecker
        {
            protected function phpVersion(): string
            {
                return '8.2.5';
            }

            protected function extensionLoaded(string $name): bool
            {
                return true;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '8.2.0',
            'recommended_version' => '8.3.0',
            'extensions' => ['core' => []],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_required_extension_missing_fails(): void
    {
        $checker = new class extends PhpChecker
        {
            protected function phpVersion(): string
            {
                return '8.3.0';
            }

            protected function extensionLoaded(string $name): bool
            {
                return $name === 'curl';
            }
        };

        $results = $checker->run('core', [
            'min_version' => '8.2.0',
            'recommended_version' => '8.3.0',
            'extensions' => ['core' => ['curl', 'imagick']],
        ]);

        $imagick = collect($results)->firstWhere('name', 'ext.imagick');
        $this->assertNotNull($imagick);
        $this->assertSame('fail', $imagick->status);
    }

    public function test_media_profile_promotes_extra_extensions_to_required(): void
    {
        $checker = new class extends PhpChecker
        {
            protected function phpVersion(): string
            {
                return '8.3.0';
            }

            protected function extensionLoaded(string $name): bool
            {
                return false;
            }
        };

        $results = $checker->run('media', [
            'min_version' => '8.2.0',
            'recommended_version' => '8.3.0',
            'extensions' => [
                'core' => ['curl'],
                'media' => ['gd'],
            ],
        ]);

        $statuses = [];
        foreach ($results as $r) {
            $statuses[$r->name] = $r->status;
        }

        $this->assertSame('fail', $statuses['ext.curl']);
        $this->assertSame('warn', $statuses['ext.gd']);
    }
}
