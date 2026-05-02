<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\BrowserChecker;
use Tests\TestCase;

class BrowserCheckerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/plos_browser_'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_playwright_chromium_present_passes(): void
    {
        $chrome = $this->makeExecutable('ms-playwright/chromium/chrome');

        $checker = new class($chrome) extends BrowserChecker
        {
            public function __construct(private string $chrome) {}

            protected function playwrightChromiumPath(): ?string
            {
                return $this->chrome;
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                [
                    'name' => 'playwright.chromium',
                    'engine' => 'playwright',
                    'required' => false,
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_playwright_chromium_missing_warns(): void
    {
        $checker = new class($this->tmpRoot.'/missing/chrome') extends BrowserChecker
        {
            public function __construct(private string $chrome) {}

            protected function playwrightChromiumPath(): ?string
            {
                return $this->chrome;
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                [
                    'name' => 'playwright.chromium',
                    'engine' => 'playwright',
                    'required' => false,
                    'install_hint' => 'npx playwright install --with-deps chromium',
                ],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('npx playwright install --with-deps chromium', $results[0]->message);
    }

    public function test_puppeteer_fallback_path_present_passes(): void
    {
        $chrome = $this->makeExecutable('chrome-linux64/chrome');

        $checker = new BrowserChecker;

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                [
                    'name' => 'puppeteer.chrome',
                    'engine' => 'puppeteer',
                    'required' => false,
                    'fallback_paths' => [$chrome],
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_puppeteer_missing_warns_when_optional(): void
    {
        $checker = new class extends BrowserChecker
        {
            protected function resolveBinary(string $bin): ?string
            {
                return null;
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                [
                    'name' => 'puppeteer.chrome',
                    'engine' => 'puppeteer',
                    'required' => false,
                    'fallback_paths' => [$this->tmpRoot.'/missing/chrome'],
                    'fallback_bins' => ['chrome'],
                ],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    private function makeExecutable(string $relative): string
    {
        $path = $this->tmpRoot.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0700);

        return $path;
    }

    private function rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
