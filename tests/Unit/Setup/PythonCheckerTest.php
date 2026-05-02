<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\PythonChecker;
use Tests\TestCase;

class PythonCheckerTest extends TestCase
{
    public function test_warns_when_python_missing(): void
    {
        $checker = new class extends PythonChecker
        {
            protected function pythonVersion(): ?string
            {
                return null;
            }

            protected function basePath(string $relative): string
            {
                return __DIR__.'/__nope__/'.$relative;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '3.10',
            'tiers' => ['core' => ['requirements_file' => 'requirements-core.txt']],
        ]);

        $interpreter = $results[0];
        $this->assertSame('python', $interpreter->group);
        $this->assertSame('interpreter', $interpreter->name);
        $this->assertSame('warn', $interpreter->status);
    }

    public function test_warns_when_python_below_minimum(): void
    {
        $checker = new class extends PythonChecker
        {
            protected function pythonVersion(): ?string
            {
                return '3.8.10';
            }

            protected function basePath(string $relative): string
            {
                return __DIR__.'/'.$relative;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '3.10',
            'tiers' => [],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('below recommended', $results[0]->message);
    }

    public function test_passes_when_python_meets_minimum(): void
    {
        $checker = new class extends PythonChecker
        {
            protected function pythonVersion(): ?string
            {
                return '3.11.0';
            }

            protected function basePath(string $relative): string
            {
                return __DIR__.'/'.$relative;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '3.10',
            'tiers' => [],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_configured_python_binary_is_preferred(): void
    {
        $checker = new class extends PythonChecker
        {
            public array $versionCalls = [];

            protected function runVersion(string $bin): ?string
            {
                $this->versionCalls[] = $bin;

                return $bin === '.venv/bin/python' ? 'Python 3.11.0' : null;
            }

            protected function basePath(string $relative): string
            {
                return __DIR__.'/'.$relative;
            }
        };

        $results = $checker->run('core', [
            'binary' => '.venv/bin/python',
            'min_version' => '3.10',
            'tiers' => [],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('.venv/bin/python', $checker->versionCalls[0]);
    }

    public function test_tier_passes_when_requirements_file_exists(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plos_req_');
        file_put_contents($tmp, "numpy\n");

        try {
            $checker = new class($tmp) extends PythonChecker
            {
                public function __construct(private string $tmp) {}

                protected function pythonVersion(): ?string
                {
                    return '3.11.0';
                }

                protected function basePath(string $relative): string
                {
                    return $this->tmp;
                }
            };

            $results = $checker->run('core', [
                'min_version' => '3.10',
                'tiers' => ['core' => ['requirements_file' => 'requirements-core.txt']],
            ]);

            $tier = collect($results)->firstWhere('name', 'tier.core');
            $this->assertNotNull($tier);
            $this->assertSame('pass', $tier->status);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_tier_warns_when_requirements_file_missing(): void
    {
        $checker = new class extends PythonChecker
        {
            protected function pythonVersion(): ?string
            {
                return '3.11.0';
            }

            protected function basePath(string $relative): string
            {
                return '/tmp/__plos_no_such_file__/'.$relative;
            }
        };

        $results = $checker->run('core', [
            'min_version' => '3.10',
            'tiers' => ['core' => ['requirements_file' => 'requirements-core.txt']],
        ]);

        $tier = collect($results)->firstWhere('name', 'tier.core');
        $this->assertNotNull($tier);
        $this->assertSame('warn', $tier->status);
    }

    public function test_required_module_missing_fails(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plos_req_');
        file_put_contents($tmp, "numpy\n");

        try {
            $checker = new class($tmp) extends PythonChecker
            {
                public function __construct(private string $tmp) {}

                protected function pythonVersion(): ?string
                {
                    return '3.11.0';
                }

                protected function basePath(string $relative): string
                {
                    return $this->tmp;
                }

                protected function moduleImportable(string $module): bool
                {
                    return false;
                }
            };

            $results = $checker->run('core', [
                'min_version' => '3.10',
                'tiers' => ['core' => ['requirements_file' => 'requirements-core.txt', 'modules' => ['numpy']]],
            ]);

            $module = collect($results)->firstWhere('name', 'module.numpy');
            $this->assertNotNull($module);
            $this->assertSame('fail', $module->status);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_recommended_module_missing_warns(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plos_req_');
        file_put_contents($tmp, "numpy\n");

        try {
            $checker = new class($tmp) extends PythonChecker
            {
                public function __construct(private string $tmp) {}

                protected function pythonVersion(): ?string
                {
                    return '3.11.0';
                }

                protected function basePath(string $relative): string
                {
                    return $this->tmp;
                }

                protected function moduleImportable(string $module): bool
                {
                    return false;
                }
            };

            $results = $checker->run('core', [
                'min_version' => '3.10',
                'tiers' => ['core' => ['requirements_file' => 'requirements-core.txt', 'recommended_modules' => ['spacy']]],
            ]);

            $module = collect($results)->firstWhere('name', 'module.spacy');
            $this->assertNotNull($module);
            $this->assertSame('warn', $module->status);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_spacy_model_missing_warns_with_download_hint(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plos_req_');
        file_put_contents($tmp, "spacy\n");

        try {
            $checker = new class($tmp) extends PythonChecker
            {
                public function __construct(private string $tmp) {}

                protected function pythonVersion(): ?string
                {
                    return '3.11.0';
                }

                protected function basePath(string $relative): string
                {
                    return $this->tmp;
                }

                protected function spacyModelLoadable(string $model): bool
                {
                    return false;
                }
            };

            $results = $checker->run('media', [
                'min_version' => '3.10',
                'tiers' => [
                    'core' => [],
                    'media' => [
                        'requirements_file' => 'requirements-media.txt',
                        'spacy_models' => ['en_core_web_sm'],
                    ],
                ],
            ]);

            $model = collect($results)->firstWhere('name', 'spacy_model.en_core_web_sm');
            $this->assertNotNull($model);
            $this->assertSame('warn', $model->status);
            $this->assertStringContainsString('python -m spacy download en_core_web_sm', $model->message);
        } finally {
            @unlink($tmp);
        }
    }
}
