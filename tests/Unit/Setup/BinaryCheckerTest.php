<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\BinaryChecker;
use Tests\TestCase;

class BinaryCheckerTest extends TestCase
{
    public function test_required_binary_missing_fails(): void
    {
        $checker = new class(['php']) extends BinaryChecker
        {
            /** @param list<string> $present */
            public function __construct(private array $present) {}

            protected function resolve(string $bin): ?string
            {
                return in_array($bin, $this->present, true) ? "/usr/bin/{$bin}" : null;
            }
        };

        $results = $checker->run('core', [
            'core' => [
                'required' => ['php', 'composer'],
                'recommended' => [],
            ],
        ]);

        $byName = [];
        foreach ($results as $r) {
            $byName[$r->name] = $r->status;
        }

        $this->assertSame('pass', $byName['php']);
        $this->assertSame('fail', $byName['composer']);
    }

    public function test_recommended_binary_missing_warns(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return null;
            }
        };

        $results = $checker->run('core', [
            'core' => [
                'required' => [],
                'recommended' => ['yt-dlp'],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_required_takes_precedence_over_recommended_duplicate(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return null;
            }
        };

        // ffmpeg appears as required in media tier and (hypothetically) again
        // in full tier as recommended — should only appear once, as fail.
        $results = $checker->run('full', [
            'core' => ['required' => [], 'recommended' => []],
            'media' => ['required' => ['ffmpeg'], 'recommended' => []],
            'gpu' => ['required' => [], 'recommended' => []],
            'full' => ['required' => [], 'recommended' => ['ffmpeg']],
        ]);

        $matches = array_values(array_filter($results, fn ($r) => $r->name === 'ffmpeg'));
        $this->assertCount(1, $matches);
        $this->assertSame('fail', $matches[0]->status);
    }

    public function test_required_binary_below_minimum_version_fails(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return "/usr/bin/{$bin}";
            }

            protected function binaryVersion(string $path, array $spec): ?string
            {
                return '3.9';
            }
        };

        $results = $checker->run('media', [
            'core' => ['required' => [], 'recommended' => []],
            'media' => [
                'required' => [
                    ['name' => 'ffmpeg', 'min_version' => '4.4'],
                ],
                'recommended' => [],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
        $this->assertStringContainsString('below required', $results[0]->message);
    }

    public function test_recommended_binary_below_minimum_version_warns(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return "/usr/bin/{$bin}";
            }

            protected function binaryVersion(string $path, array $spec): ?string
            {
                return '8';
            }
        };

        $results = $checker->run('full', [
            'core' => ['required' => [], 'recommended' => []],
            'media' => ['required' => [], 'recommended' => []],
            'gpu' => ['required' => [], 'recommended' => []],
            'full' => [
                'required' => [],
                'recommended' => [
                    ['name' => 'java', 'min_version' => '11'],
                ],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('below recommended', $results[0]->message);
    }

    public function test_binary_meeting_minimum_version_passes_with_context(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return "/usr/bin/{$bin}";
            }

            protected function binaryVersion(string $path, array $spec): ?string
            {
                return '12.76';
            }
        };

        $results = $checker->run('media', [
            'core' => ['required' => [], 'recommended' => []],
            'media' => [
                'required' => [
                    ['name' => 'exiftool', 'min_version' => '12.30'],
                ],
                'recommended' => [],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('12.76', $results[0]->context['version']);
    }

    public function test_unparseable_version_warns(): void
    {
        $checker = new class extends BinaryChecker
        {
            protected function resolve(string $bin): ?string
            {
                return "/usr/bin/{$bin}";
            }

            protected function binaryVersion(string $path, array $spec): ?string
            {
                return null;
            }
        };

        $results = $checker->run('media', [
            'core' => ['required' => [], 'recommended' => []],
            'media' => [
                'required' => [
                    ['name' => 'exiftool', 'min_version' => '12.30'],
                ],
                'recommended' => [],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('could not be parsed', $results[0]->message);
    }
}
