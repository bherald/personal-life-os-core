<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\AssetChecker;
use Tests\TestCase;

class AssetCheckerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/plos_assets_'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        putenv('PLOS_TEST_ASSET_DIR');
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_required_file_missing_fails(): void
    {
        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'required_files' => ['scripts/browser-server/puppeteer-server.cjs'],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_recommended_file_missing_warns(): void
    {
        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'recommended_files' => ['scripts/shape_predictor_68_face_landmarks.dat'],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_media_manifest_warns_for_dlib_face_model_files(): void
    {
        $recommended = config('setup.assets.media.recommended_files', []);

        $this->assertContains('scripts/shape_predictor_68_face_landmarks.dat', $recommended);
        $this->assertContains('scripts/dlib_face_recognition_resnet_model_v1.dat', $recommended);
    }

    public function test_present_file_and_dir_pass(): void
    {
        mkdir($this->tmpRoot.'/scripts/browser-server', 0700, true);
        file_put_contents($this->tmpRoot.'/scripts/browser-server/playwright-server.cjs', "console.log('ok');\n");

        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'required_files' => ['scripts/browser-server/playwright-server.cjs'],
                'required_dirs' => ['scripts/browser-server'],
            ],
        ]);

        $statuses = array_column(array_map(fn ($r) => ['name' => $r->name, 'status' => $r->status], $results), 'status', 'name');

        $this->assertSame('pass', $statuses['scripts/browser-server/playwright-server.cjs']);
        $this->assertSame('pass', $statuses['scripts/browser-server']);
    }

    public function test_required_writable_directory_missing_fails(): void
    {
        $results = $this->makeChecker()->run('core', [
            'core' => [
                'required_writable_dirs' => ['storage/app'],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_present_writable_directory_passes(): void
    {
        mkdir($this->tmpRoot.'/storage/app', 0700, true);

        $results = $this->makeChecker()->run('core', [
            'core' => [
                'required_writable_dirs' => ['storage/app'],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertStringContainsString('writable', $results[0]->message);
    }

    public function test_env_directory_unset_is_skipped(): void
    {
        putenv('PLOS_TEST_ASSET_DIR');

        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'env_dirs' => [
                    ['name' => 'test.data_path', 'env' => 'PLOS_TEST_ASSET_DIR', 'fail_when_set' => true],
                ],
            ],
        ]);

        $this->assertSame('skip', $results[0]->status);
    }

    public function test_env_directory_missing_fails_when_set(): void
    {
        putenv('PLOS_TEST_ASSET_DIR='.$this->tmpRoot.'/missing');

        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'env_dirs' => [
                    ['name' => 'test.data_path', 'env' => 'PLOS_TEST_ASSET_DIR', 'fail_when_set' => true],
                ],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_env_directory_present_passes(): void
    {
        mkdir($this->tmpRoot.'/nextcloud/files', 0700, true);
        putenv('PLOS_TEST_ASSET_DIR='.$this->tmpRoot.'/nextcloud/files');

        $results = $this->makeChecker()->run('media', [
            'core' => [],
            'media' => [
                'env_dirs' => [
                    [
                        'name' => 'test.data_path',
                        'env' => 'PLOS_TEST_ASSET_DIR',
                        'readable' => true,
                        'fail_when_set' => true,
                    ],
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    private function makeChecker(): AssetChecker
    {
        $root = $this->tmpRoot;

        return new class($root) extends AssetChecker
        {
            public function __construct(private string $root) {}

            protected function basePath(string $relative): string
            {
                return rtrim($this->root, '/').'/'.ltrim($relative, '/');
            }
        };
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
