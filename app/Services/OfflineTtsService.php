<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class OfflineTtsService
{
    /**
     * @var list<string>
     */
    private const AUTO_COMMANDS = ['piper', 'espeak-ng', 'espeak', 'flite', 'say'];

    private const ALLOWED_ENGINES = ['auto', 'piper', 'espeak-ng', 'espeak', 'flite', 'say'];

    private ?Closure $runtimePolicyLoader = null;

    /** @var array{status:string,source:string,config:array<string,mixed>}|null */
    private ?array $cachedRuntimePolicy = null;

    public function __construct(?Closure $runtimePolicyLoader = null)
    {
        $this->runtimePolicyLoader = $runtimePolicyLoader;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function capability(array $options = []): array
    {
        $resolved = $this->resolveEngine($options);
        $status = match (true) {
            $resolved['policy_status'] === 'invalid' => 'invalid_runtime_policy',
            $resolved['policy_status'] === 'disabled' => 'disabled_by_policy',
            $resolved['path'] === null && $resolved['configured'] === true => 'configured_missing',
            $resolved['path'] === null => 'not_detected',
            $resolved['engine'] === 'piper' && trim((string) $resolved['model']) === '' => 'blocked_missing_model',
            default => 'available',
        };

        return [
            'status' => $status,
            'provider' => 'local command TTS adapter',
            'engine' => $resolved['engine'],
            'path' => $resolved['path'],
            'configured' => $resolved['configured'],
            'voice' => $resolved['voice'] !== '' ? $resolved['voice'] : null,
            'model' => $resolved['model'] !== '' ? $resolved['model'] : null,
            'timeout_seconds' => $resolved['timeout_seconds'],
            'output_formats' => ['wav'],
            'write_gate' => 'requires audio:tts --execute',
            'table_routed' => str_contains($resolved['routing_source'], 'llm_model_profiles.metadata.runtime'),
            'routing_source' => $resolved['routing_source'],
            'policy_status' => $resolved['policy_status'],
            'note' => 'This local media adapter uses the Speech Generation profile when present; LLM model routing remains in the PLOS AI pipeline.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function synthesize(string $text, string $outputPath, array $options = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                'success' => false,
                'status' => 'invalid_input',
                'error' => 'Text is required for TTS synthesis.',
            ];
        }

        $capability = $this->capability($options);
        if (($capability['status'] ?? '') !== 'available') {
            return [
                'success' => false,
                'status' => $capability['status'] ?? 'not_available',
                'capability' => $capability,
                'error' => 'No available local TTS engine.',
            ];
        }

        $resolved = $this->resolveEngine($options);
        $inputPath = $this->writePrivateInputFile((string) $resolved['engine'], $text);

        try {
            $command = $this->buildCommand($resolved, $text, $outputPath, $inputPath);
            $started = microtime(true);

            $process = new Process(
                $command,
                base_path(),
                null,
                null,
                (float) $resolved['timeout_seconds'],
            );
            $process->run();

            $durationMs = (int) ((microtime(true) - $started) * 1000);

            if (! $process->isSuccessful()) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'capability' => $capability,
                    'command' => $this->redactedCommand($command, $inputPath),
                    'duration_ms' => $durationMs,
                    'error' => mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 1000),
                ];
            }

            return [
                'success' => true,
                'status' => is_file($outputPath) ? 'pass' : 'missing_output',
                'capability' => $capability,
                'command' => $this->redactedCommand($command, $inputPath),
                'duration_ms' => $durationMs,
                'output_path' => $outputPath,
                'bytes' => is_file($outputPath) ? filesize($outputPath) : 0,
            ];
        } finally {
            if ($inputPath !== null) {
                @unlink($inputPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{engine:?string,path:?string,configured:bool,voice:string,model:string,timeout_seconds:int,routing_source:string,policy_status:string}
     */
    private function resolveEngine(array $options): array
    {
        $finder = new ExecutableFinder;
        $runtime = $this->runtimeConfig($options);
        $configuredCommand = trim((string) ($runtime['config']['command'] ?? ''));
        $provider = trim((string) ($runtime['config']['provider'] ?? 'auto'));
        $voice = trim((string) ($runtime['config']['voice'] ?? ''));
        $model = trim((string) ($runtime['config']['model'] ?? ''));
        $timeout = max(1, min(600, (int) ($runtime['config']['timeout_seconds'] ?? 120)));

        if (in_array($runtime['status'], ['invalid', 'disabled'], true)) {
            return [
                'engine' => null,
                'path' => null,
                'configured' => true,
                'voice' => $voice,
                'model' => $model,
                'timeout_seconds' => $timeout,
                'routing_source' => $runtime['source'],
                'policy_status' => $runtime['status'],
            ];
        }

        if ($configuredCommand !== '') {
            $path = is_executable($configuredCommand)
                ? $configuredCommand
                : $finder->find($configuredCommand);

            return [
                'engine' => $this->normalizeEngine($provider !== 'auto' ? $provider : $configuredCommand),
                'path' => $path,
                'configured' => true,
                'voice' => $voice,
                'model' => $model,
                'timeout_seconds' => $timeout,
                'routing_source' => $runtime['source'],
                'policy_status' => $runtime['status'],
            ];
        }

        foreach (self::AUTO_COMMANDS as $command) {
            $path = $finder->find($command);
            if ($path) {
                return [
                    'engine' => $this->normalizeEngine($command),
                    'path' => $path,
                    'configured' => false,
                    'voice' => $voice,
                    'model' => $model,
                    'timeout_seconds' => $timeout,
                    'routing_source' => $runtime['source'],
                    'policy_status' => $runtime['status'],
                ];
            }
        }

        return [
            'engine' => null,
            'path' => null,
            'configured' => false,
            'voice' => $voice,
            'model' => $model,
            'timeout_seconds' => $timeout,
            'routing_source' => $runtime['source'],
            'policy_status' => $runtime['status'],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{status:string,source:string,config:array<string,mixed>}
     */
    private function runtimeConfig(array $options): array
    {
        $explicit = array_intersect_key($options, array_flip([
            'provider',
            'command',
            'voice',
            'model',
            'timeout_seconds',
        ]));
        $policy = $this->profileRuntimePolicy();
        if ($explicit !== []) {
            if (in_array($policy['status'], ['invalid', 'disabled'], true)) {
                return $policy;
            }

            $tableRouted = $policy['status'] !== 'missing';
            $source = $tableRouted
                ? 'explicit_options+llm_model_profiles.metadata.runtime'
                : 'explicit_options+services.tts_bootstrap';
            $explicitLimits = [
                'provider' => 40,
                'command' => 4096,
                'voice' => 128,
                'model' => 4096,
            ];
            foreach ($explicitLimits as $key => $limit) {
                if (array_key_exists($key, $explicit)
                    && $this->boundedString($explicit[$key], $limit) === null) {
                    return $this->invalidRuntimePolicy($source, false);
                }
            }
            if (array_key_exists('provider', $explicit)
                && ! in_array(trim((string) $explicit['provider']), self::ALLOWED_ENGINES, true)) {
                return $this->invalidRuntimePolicy($source, false);
            }
            if (array_key_exists('timeout_seconds', $explicit)
                && filter_var(
                    $explicit['timeout_seconds'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 600]]
                ) === false) {
                return $this->invalidRuntimePolicy($source, false);
            }

            return [
                'status' => 'available',
                'source' => $source,
                'config' => array_replace(
                    $tableRouted ? $policy['config'] : $this->bootstrapRuntimeConfig(),
                    $explicit
                ),
            ];
        }

        if ($policy['status'] !== 'missing') {
            return $policy;
        }

        return [
            'status' => 'available',
            'source' => 'services.tts_bootstrap',
            'config' => $this->bootstrapRuntimeConfig(),
        ];
    }

    /** @return array<string,mixed> */
    private function bootstrapRuntimeConfig(): array
    {
        return [
            'provider' => trim((string) config('services.tts.provider', 'auto')),
            'command' => trim((string) config('services.tts.command', '')),
            'voice' => trim((string) config('services.tts.voice', '')),
            'model' => trim((string) config('services.tts.model', '')),
            'timeout_seconds' => max(1, min(600, (int) config('services.tts.timeout', 120))),
        ];
    }

    /** @return array{status:string,source:string,config:array<string,mixed>} */
    private function profileRuntimePolicy(): array
    {
        if ($this->cachedRuntimePolicy !== null) {
            return $this->cachedRuntimePolicy;
        }

        try {
            $metadata = $this->runtimePolicyLoader !== null
                ? ($this->runtimePolicyLoader)()
                : $this->loadProfileMetadata();
        } catch (\Throwable) {
            return $this->invalidRuntimePolicy('llm_model_profiles.metadata.runtime');
        }

        if (! is_array($metadata) || ! array_key_exists('runtime', $metadata)) {
            return $this->cachedRuntimePolicy = [
                'status' => 'missing',
                'source' => 'llm_model_profiles.metadata.runtime',
                'config' => [],
            ];
        }

        $runtime = $metadata['runtime'];
        if (! is_array($runtime)) {
            return $this->invalidRuntimePolicy('llm_model_profiles.metadata.runtime');
        }

        $enabled = $runtime['enabled'] ?? true;
        if (! is_bool($enabled)) {
            return $this->invalidRuntimePolicy('llm_model_profiles.metadata.runtime');
        }
        if ($enabled === false) {
            return $this->cachedRuntimePolicy = [
                'status' => 'disabled',
                'source' => 'llm_model_profiles.metadata.runtime',
                'config' => [],
            ];
        }

        $schema = $this->boundedString($runtime['schema'] ?? null, 64);
        $outputFormat = $this->boundedString($runtime['output_format'] ?? null, 16);
        $provider = $this->boundedString($runtime['provider'] ?? null, 40);
        $command = $this->boundedString($runtime['command'] ?? '', 4096);
        $voice = $this->boundedString($runtime['voice'] ?? '', 128);
        $model = $this->boundedString($runtime['model'] ?? '', 4096);
        $timeout = filter_var(
            $runtime['timeout_seconds'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 600]]
        );
        if ($schema !== 'plos.tts_runtime.v1'
            || $outputFormat !== 'wav'
            || $provider === null
            || ! in_array($provider, self::ALLOWED_ENGINES, true)
            || $command === null
            || $voice === null
            || $model === null
            || $timeout === false) {
            return $this->invalidRuntimePolicy('llm_model_profiles.metadata.runtime');
        }

        return $this->cachedRuntimePolicy = [
            'status' => 'available',
            'source' => 'llm_model_profiles.metadata.runtime',
            'config' => [
                'provider' => $provider,
                'command' => $command,
                'voice' => $voice,
                'model' => $model,
                'timeout_seconds' => $timeout,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadProfileMetadata(): ?array
    {
        $row = DB::selectOne(
            'SELECT metadata, enabled FROM llm_model_profiles WHERE profile_name = ? LIMIT 1',
            ['media_output_audio']
        );
        if ($row === null) {
            return null;
        }

        $metadata = json_decode((string) ($row->metadata ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($metadata)) {
            throw new \UnexpectedValueException('Speech profile metadata must be a JSON object.');
        }

        if (! (bool) ($row->enabled ?? false)) {
            $runtime = is_array($metadata['runtime'] ?? null) ? $metadata['runtime'] : [];
            $metadata['runtime'] = array_replace($runtime, ['enabled' => false]);
        }

        return $metadata;
    }

    /** @return array{status:string,source:string,config:array<string,mixed>} */
    private function invalidRuntimePolicy(string $source, bool $cache = true): array
    {
        $policy = [
            'status' => 'invalid',
            'source' => $source,
            'config' => [],
        ];

        return $cache ? $this->cachedRuntimePolicy = $policy : $policy;
    }

    private function boundedString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function normalizeEngine(string $value): string
    {
        $name = strtolower(basename($value));

        return match (true) {
            str_contains($name, 'piper') => 'piper',
            str_contains($name, 'espeak-ng') => 'espeak-ng',
            str_contains($name, 'espeak') => 'espeak',
            str_contains($name, 'flite') => 'flite',
            str_contains($name, 'say') => 'say',
            default => $name,
        };
    }

    /**
     * @param  array{engine:?string,path:?string,configured:bool,voice:string,model:string,timeout_seconds:int,routing_source:string,policy_status:string}  $resolved
     * @return list<string>
     */
    private function buildCommand(array $resolved, string $text, string $outputPath, ?string $inputPath): array
    {
        $path = (string) $resolved['path'];
        $engine = (string) $resolved['engine'];
        $voice = (string) $resolved['voice'];
        $model = (string) $resolved['model'];

        return match ($engine) {
            'piper' => [$path, '-m', $model, '-f', $outputPath, '--input-file', (string) $inputPath],
            'espeak-ng', 'espeak' => array_values(array_filter([
                $path,
                $voice !== '' ? '-v' : null,
                $voice !== '' ? $voice : null,
                '-w',
                $outputPath,
                $text,
            ], static fn ($value) => $value !== null)),
            'flite' => [$path, '-o', $outputPath, '-t', $text],
            'say' => array_values(array_filter([
                $path,
                $voice !== '' ? '-v' : null,
                $voice !== '' ? $voice : null,
                '-o',
                $outputPath,
                $text,
            ], static fn ($value) => $value !== null)),
            default => [$path, $text],
        };
    }

    private function writePrivateInputFile(string $engine, string $text): ?string
    {
        if ($engine !== 'piper') {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'plos-piper-input-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary Piper input file.');
        }

        chmod($path, 0600);
        if (file_put_contents($path, $text, LOCK_EX) === false) {
            @unlink($path);

            throw new \RuntimeException('Unable to write the temporary Piper input file.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function redactedCommand(array $command, ?string $inputPath = null): array
    {
        return array_map(
            static function (string $part) use ($inputPath): string {
                if ($inputPath !== null && hash_equals($inputPath, $part)) {
                    return '[private input file]';
                }

                return strlen($part) > 160 ? mb_substr($part, 0, 160).'...' : $part;
            },
            $command,
        );
    }
}
