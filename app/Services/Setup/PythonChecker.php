<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class PythonChecker
{
    private ?string $configuredBinary = null;

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];
        $this->configuredBinary = isset($manifest['binary']) && $manifest['binary'] !== ''
            ? (string) $manifest['binary']
            : null;
        $minVersion = (string) ($manifest['min_version'] ?? '3.10');

        $version = $this->pythonVersion();
        $canProbeImports = $version !== null;
        if ($version === null) {
            $results[] = CheckResult::warn('python', 'interpreter', 'python3 not found on PATH');
        } elseif (version_compare($version, $minVersion, '<')) {
            $results[] = CheckResult::warn('python', 'interpreter', "Python {$version} below recommended {$minVersion}");
        } else {
            $results[] = CheckResult::pass('python', 'interpreter', "Python {$version}");
        }

        $tiers = (array) ($manifest['tiers'] ?? []);
        foreach ($this->tiersFor($profile) as $tierKey) {
            $tier = $tiers[$tierKey] ?? null;
            if (! is_array($tier)) {
                continue;
            }

            $reqFile = (string) ($tier['requirements_file'] ?? '');
            $name = "tier.{$tierKey}";

            if ($reqFile === '') {
                $results[] = CheckResult::skip('python', $name, "no requirements file declared for {$tierKey}");

                continue;
            }

            $absolute = $this->basePath($reqFile);
            $results[] = is_file($absolute)
                ? CheckResult::pass('python', $name, "{$reqFile} present")
                : CheckResult::warn('python', $name, "{$reqFile} missing", ['path' => $absolute]);

            if (! $canProbeImports) {
                continue;
            }

            foreach ((array) ($tier['modules'] ?? []) as $module) {
                $results[] = $this->checkModule((string) $module, true);
            }
            foreach ((array) ($tier['required_modules'] ?? []) as $module) {
                $results[] = $this->checkModule((string) $module, true);
            }
            foreach ((array) ($tier['recommended_modules'] ?? []) as $module) {
                $results[] = $this->checkModule((string) $module, false);
            }
            foreach ((array) ($tier['spacy_models'] ?? []) as $model) {
                if (is_string($model)) {
                    $results[] = $this->checkSpacyModel($model, false);
                } elseif (is_array($model)) {
                    $results[] = $this->checkSpacyModel(
                        (string) ($model['name'] ?? ''),
                        (bool) ($model['required'] ?? false)
                    );
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function tiersFor(string $profile): array
    {
        return match ($profile) {
            'core' => ['core'],
            'media' => ['core', 'media'],
            'gpu' => ['core', 'gpu'],
            'full' => ['core', 'media', 'gpu', 'full'],
            'personal' => ['core', 'media', 'gpu', 'full', 'personal'],
            default => ['core'],
        };
    }

    protected function pythonVersion(): ?string
    {
        $bin = $this->pythonBinary();
        if ($bin === null) {
            return null;
        }

        $output = $this->runVersion($bin);
        if ($output !== null && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $output, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function pythonBinary(): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            $this->configuredBinary,
            'python3',
            'python',
        ])));

        foreach ($candidates as $bin) {
            if ($this->runVersion($bin) !== null) {
                return $bin;
            }
        }

        return null;
    }

    protected function runVersion(string $bin): ?string
    {
        $cmd = escapeshellarg($bin).' --version 2>&1';
        $output = @shell_exec($cmd);
        if (! is_string($output)) {
            return null;
        }
        $output = trim($output);

        return $output === '' ? null : $output;
    }

    private function checkModule(string $module, bool $required): CheckResult
    {
        if ($module === '') {
            return CheckResult::skip('python', 'module.unknown', 'empty Python module name');
        }

        $name = "module.{$module}";
        if ($this->moduleImportable($module)) {
            return CheckResult::pass('python', $name, "Python module {$module} importable");
        }

        $message = "Python module {$module} is not importable";

        return $required
            ? CheckResult::fail('python', $name, $message)
            : CheckResult::warn('python', $name, $message);
    }

    private function checkSpacyModel(string $model, bool $required): CheckResult
    {
        if ($model === '') {
            return CheckResult::skip('python', 'spacy_model.unknown', 'empty spaCy model name');
        }

        $name = "spacy_model.{$model}";
        $hint = "python -m spacy download {$model}";
        if ($this->spacyModelLoadable($model)) {
            return CheckResult::pass('python', $name, "spaCy model {$model} loadable");
        }

        $message = "spaCy model {$model} is not loadable; run {$hint}";
        $context = ['hint' => $hint];

        return $required
            ? CheckResult::fail('python', $name, $message, $context)
            : CheckResult::warn('python', $name, $message, $context);
    }

    protected function moduleImportable(string $module): bool
    {
        return $this->pythonCommandSucceeds('import importlib; importlib.import_module('.var_export($module, true).')');
    }

    protected function spacyModelLoadable(string $model): bool
    {
        return $this->pythonCommandSucceeds('import spacy; spacy.load('.var_export($model, true).')');
    }

    protected function pythonCommandSucceeds(string $script): bool
    {
        $bin = $this->pythonBinary() ?? 'python3';
        $cmd = escapeshellarg($bin).' -c '.escapeshellarg($script).' 2>/dev/null';
        @exec($cmd, $output, $exitCode);

        return $exitCode === 0;
    }

    protected function basePath(string $relative): string
    {
        if (function_exists('base_path')) {
            return base_path($relative);
        }

        return getcwd().DIRECTORY_SEPARATOR.$relative;
    }
}
