<?php

namespace App\Services;

use App\Services\Ops\AgentTrajectoryDiagnosticService;
use App\Support\SensitiveTextTelemetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Exceptions\SchemaException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CodexExecConnectorService
{
    public const EXECUTION_GUARD = 'plos.codex_exec_execution_guard';

    private const PROVIDER_ID = 'codex_exec';

    private const DEFAULT_SUPPORTED_EFFORTS = ['low', 'medium', 'high', 'xhigh'];

    private const MAX_TRAJECTORY_TOOL_CALLS = 500;

    private static ?\Closure $outputObserver = null;

    private static bool $manualCompactionOnly = false;

    public static function withManualCompactionOnly(\Closure $operation): mixed
    {
        $previous = self::$manualCompactionOnly;
        self::$manualCompactionOnly = true;
        try {
            return $operation();
        } finally {
            self::$manualCompactionOnly = $previous;
        }
    }

    /** Retain raw process output only in the calling CLI's private artifact scope. */
    public static function withOutputObserver(\Closure $operation, \Closure $observer): mixed
    {
        $previous = self::$outputObserver;
        self::$outputObserver = $observer;
        try {
            return $operation();
        } finally {
            self::$outputObserver = $previous;
        }
    }

    public function __construct(
        private readonly AgentTrajectoryDiagnosticService $trajectoryDiagnostics
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $prompt, array $config = []): array
    {
        $config['prompt_preview'] = $prompt;

        $row = $this->providerRow();
        if ($row === null) {
            return [
                'success' => false,
                'error_type' => 'configuration',
                'error' => 'Codex Exec provider row not configured',
            ];
        }

        $readinessError = $this->providerReadinessError($row);
        if ($readinessError !== null) {
            return [
                'success' => false,
                'error_type' => 'provider_unavailable',
                'error' => $readinessError,
            ];
        }

        try {
            $resolved = $this->resolveExecutionConfig($row, $config);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_type' => 'configuration',
                'error' => $e->getMessage(),
            ];
        }

        if (! empty($config['dry_run'])) {
            return [
                'success' => true,
                'dry_run' => true,
                'provider' => self::PROVIDER_ID,
                'profile' => $resolved['profile'],
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'sandbox' => $resolved['sandbox'],
                'approval_policy' => $resolved['approval_policy'],
                'cwd' => $resolved['cwd'],
                'command' => $this->buildCommand($resolved, '<output-last-message>'),
            ];
        }

        $slotId = null;
        $outputPath = null;
        $process = null;
        $processInput = $prompt;
        $timedOut = false;
        $started = microtime(true);

        try {
            if (app()->bound(self::EXECUTION_GUARD)) {
                $guard = app(self::EXECUTION_GUARD);
                if (! is_callable($guard)) {
                    throw new RuntimeException('The caller Codex execution policy is unavailable.');
                }
                $guard();
            }
        } catch (\Throwable $error) {
            return ['success' => false, 'provider' => self::PROVIDER_ID, 'model' => $resolved['model'],
                'error_type' => 'policy', 'error' => $error->getMessage()];
        }

        try {
            $slot = $this->acquireExecutionSlot($row, $resolved);
            if (! ($slot['success'] ?? false)) {
                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'profile' => $resolved['profile'],
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error_type' => $slot['error_type'] ?? 'capacity',
                    'error' => $slot['error'] ?? 'Codex Exec capacity is unavailable.',
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                ];
            }

            $slotId = (string) $slot['slot_id'];
            $outputPath = $this->newOutputPath();
            $command = $this->buildCommand($resolved, $outputPath);

            if ($resolved['native_turn']) {
                $processInput = json_encode([
                    'executable' => $resolved['native_executable'],
                    'args' => $resolved['strict_config'] ? ['--strict-config'] : [],
                    'cwd' => $resolved['cwd'], 'profile' => $resolved['profile'],
                    'model' => $resolved['model'], 'effort' => $resolved['reasoning_effort'],
                    'sandbox' => $resolved['sandbox'], 'ephemeral' => $resolved['ephemeral'],
                    'prompt' => $prompt, 'developerInstructions' => $resolved['caller_instructions'],
                    'timeoutMs' => $resolved['timeout_seconds'] * 1000, 'outputPath' => $outputPath,
                    ...($resolved['output_schema'] === null ? [] : ['outputSchema' => json_decode(file_get_contents($resolved['output_schema']), false, 512, JSON_THROW_ON_ERROR)]),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $process = new Process($command, $resolved['cwd'], null, $processInput, $resolved['process_timeout_seconds']);
            $process->run();
            if ($resolved['native_turn'] && $process->getExitCode() === 124) {
                throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
            }

            $response = is_file($outputPath)
                ? (string) file_get_contents($outputPath)
                : $process->getOutput();
            $diagnostics = $this->summarizeCodexCliOutput(
                $process->getOutput(),
                $process->getErrorOutput(),
                $response,
            );

            if (! mb_check_encoding($response, 'UTF-8')) {
                return ['success' => false, 'provider' => self::PROVIDER_ID, 'model' => $resolved['model'],
                    'error_type' => 'invalid_response_encoding', 'error' => 'Codex returned non-UTF-8 response bytes.',
                    'exit_code' => $process->getExitCode(), 'diagnostics' => $diagnostics];
            }

            if (! $process->isSuccessful()) {
                $nativeError = null;
                if ($resolved['native_turn']) {
                    $lines = explode("\n", rtrim($process->getOutput(), "\r\n"));
                    $last = json_decode(end($lines), true);
                    if (is_array($last) && ($last['type'] ?? null) === 'plos.native.turn.result'
                        && ($last['success'] ?? null) === false && is_string($last['error'] ?? null)) {
                        $nativeError = $last['error'];
                    }
                }
                $error = $this->shortError($nativeError ?? ($process->getErrorOutput() ?: $process->getOutput() ?: 'codex exec failed'));

                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'profile' => $resolved['profile'],
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error_type' => $this->classifyError($error),
                    'error' => $error,
                    'response' => $response !== '' ? $response : null,
                    'exit_code' => $process->getExitCode(),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                    'diagnostics' => $diagnostics,
                ];
            }

            if (trim($response) === '') {
                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'profile' => $resolved['profile'],
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error_type' => 'empty_response',
                    'error' => 'codex exec returned empty response',
                    'exit_code' => $process->getExitCode(),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                    'diagnostics' => $diagnostics,
                ];
            }

            if ($resolved['output_schema'] !== null) {
                $validation = $this->validateStructuredResponse($response, $resolved['output_schema']);
                $diagnostics['structured_output'] = $validation['diagnostics'];

                if (! $validation['valid']) {
                    return [
                        'success' => false,
                        'provider' => self::PROVIDER_ID,
                        'profile' => $resolved['profile'],
                        'model' => $resolved['model'],
                        'reasoning_effort' => $resolved['reasoning_effort'],
                        'error_type' => $validation['error_type'],
                        'error' => $validation['error'],
                        'exit_code' => $process->getExitCode(),
                        'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                        'diagnostics' => $diagnostics,
                    ];
                }
            }

            return [
                'success' => true,
                'provider' => self::PROVIDER_ID,
                'profile' => $resolved['profile'],
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'sandbox' => $resolved['sandbox'],
                'approval_policy' => $resolved['approval_policy'],
                'response' => $response,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'diagnostics' => $diagnostics,
            ];
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $response = $this->availableResponse($process, $outputPath);

            return [
                'success' => false,
                'provider' => self::PROVIDER_ID,
                'profile' => $resolved['profile'],
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'error_type' => 'timeout',
                'error' => "codex exec timed out after {$resolved['timeout_seconds']}s",
                'response' => $response,
                'partial_content_preserved' => $response !== null && $response !== '',
                'exit_code' => $process?->getExitCode(),
                'diagnostics' => $this->summarizeCodexCliOutput($process?->getOutput() ?? '', $process?->getErrorOutput() ?? '', $response ?? ''),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            Log::warning('CodexExecConnector: execution failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => self::PROVIDER_ID,
                'profile' => $resolved['profile'],
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'error_type' => $this->classifyError($e->getMessage()),
                'error' => $this->shortError($e->getMessage()),
                'response' => $this->availableResponse($process, $outputPath),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } finally {
            try {
                if (self::$outputObserver !== null && $process !== null && $process->isStarted()) {
                    $lastMessage = $outputPath !== null && is_file($outputPath) ? file_get_contents($outputPath) : false;
                    (self::$outputObserver)([
                        'instance_id' => self::PROVIDER_ID, 'success' => ! $timedOut && $process->isSuccessful(),
                        'timed_out' => $timedOut, 'exit_code' => $process->getExitCode(),
                        'error' => $timedOut ? 'timeout' : ($process->isSuccessful() ? null : 'process_exit_'.$process->getExitCode()),
                        'stdin_base64' => base64_encode($processInput), 'stdout_base64' => base64_encode($process->getOutput()),
                        'stderr_base64' => base64_encode($process->getErrorOutput()),
                        'last_message_available' => $lastMessage !== false,
                        'last_message_base64' => base64_encode($lastMessage === false ? '' : $lastMessage),
                    ]);
                }
            } finally {
                try {
                    if ($slotId !== null) {
                        $this->releaseExecutionSlot($slotId, $resolved);
                    }
                } finally {
                    if ($outputPath !== null && is_file($outputPath)) {
                        @unlink($outputPath);
                    }
                }
            }
        }
    }

    private function availableResponse(?Process $process, ?string $outputPath): ?string
    {
        $response = $outputPath !== null && is_file($outputPath) ? file_get_contents($outputPath)
            : ($process !== null && $process->isStarted() ? $process->getOutput() : null);

        return is_string($response) && mb_check_encoding($response, 'UTF-8') ? $response : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveExecutionConfig(object $row, array $config = []): array
    {
        $providerConfig = $this->decodeAssoc($row->config ?? null);
        $role = $this->stringValue($config['model_role'] ?? null, 'standard');
        $models = $this->stringMap($providerConfig['models'] ?? []);
        $supportedModels = $this->stringList($row->supported_models ?? []);
        $explicitModel = $this->stringValue($config['model_override'] ?? null)
            ?? $this->stringValue($config['codex_model'] ?? null);
        $defaultModel = $this->stringValue($providerConfig['default_model'] ?? null);
        $model = $explicitModel
            ?? ($models[$role] ?? null)
            ?? ($models['standard'] ?? null)
            ?? $defaultModel
            ?? ($supportedModels[0] ?? null);

        if ($model === null || $model === '') {
            throw new RuntimeException('No Codex model configured for requested role.');
        }

        $allowLiveExtraModel = $explicitModel !== null
            && (bool) ($providerConfig['allow_live_extra_models'] ?? false);
        if ($supportedModels !== [] && ! in_array($model, $supportedModels, true) && ! $allowLiveExtraModel) {
            throw new InvalidArgumentException("Codex model '{$model}' is not in llm_instances.supported_models.");
        }

        $effortsByRole = $this->stringMap($providerConfig['reasoning_effort'] ?? []);
        $effort = $this->stringValue($config['codex_reasoning_effort'] ?? null)
            ?? $this->stringValue($config['model_reasoning_effort'] ?? null)
            ?? $this->stringValue($config['reasoning_effort'] ?? null)
            ?? ($effortsByRole[$role] ?? null)
            ?? ($effortsByRole['standard'] ?? null)
            ?? $this->stringValue($providerConfig['default_reasoning_effort'] ?? null, 'medium');

        $allowedEfforts = $this->supportedEffortsForModel($providerConfig, $model);
        if (! in_array($effort, $allowedEfforts, true)) {
            throw new InvalidArgumentException("Codex reasoning effort '{$effort}' is not allowed for model '{$model}'.");
        }

        $sandboxByRole = $this->stringMap($providerConfig['sandbox_by_role'] ?? []);
        $sandbox = $this->stringValue($config['codex_sandbox'] ?? null)
            ?? $this->stringValue($config['sandbox'] ?? null)
            ?? ($sandboxByRole[$role] ?? null)
            ?? ($sandboxByRole['standard'] ?? null)
            ?? $this->stringValue($providerConfig['default_sandbox'] ?? null, 'read-only');

        $this->validateSandbox($sandbox, (bool) ($providerConfig['allow_danger_full_access'] ?? false));

        $approvalPolicy = $this->stringValue($providerConfig['default_approval_policy'] ?? null, 'never');
        if ($approvalPolicy !== 'never') {
            throw new InvalidArgumentException('Codex pipeline approval_policy must be never.');
        }

        $runtimePolicy = $this->decodeAssoc($providerConfig['runtime_policy'] ?? null);
        $timeoutMin = $this->requiredPositiveInt($runtimePolicy['timeout_min_seconds'] ?? null, 'runtime_policy.timeout_min_seconds');
        $timeoutMax = $this->requiredPositiveInt($runtimePolicy['timeout_max_seconds'] ?? null, 'runtime_policy.timeout_max_seconds');
        if ($timeoutMin > $timeoutMax) {
            throw new InvalidArgumentException('Codex runtime_policy timeout bounds are invalid.');
        }

        $defaultTimeout = $this->requiredPositiveInt($providerConfig['default_timeout_seconds'] ?? null, 'default_timeout_seconds');
        $timeoutRaw = $config['timeout'] ?? $defaultTimeout;
        $timeout = filter_var($timeoutRaw, FILTER_VALIDATE_INT);
        if ($timeout === false) {
            throw new InvalidArgumentException('Codex timeout must be an integer.');
        }

        if ($timeout < $timeoutMin || $timeout > $timeoutMax) {
            throw new InvalidArgumentException("Codex timeout must be between {$timeoutMin} and {$timeoutMax} seconds.");
        }

        $instructions = $config['system_prompt'] ?? $config['system'] ?? '';
        if (! is_string($instructions) || ! mb_check_encoding($instructions, 'UTF-8')) {
            throw new InvalidArgumentException('Codex caller instructions must be a UTF-8 string.');
        }
        $nativeTurn = self::$manualCompactionOnly || $instructions !== '';
        $processTimeout = $timeout + ($nativeTurn ? 4 : 0);
        $maxPromptBytes = $this->requiredPositiveInt($providerConfig['max_prompt_bytes'] ?? null, 'max_prompt_bytes');
        if (strlen((string) ($config['prompt_preview'] ?? '')) + strlen($instructions) > $maxPromptBytes) {
            throw new InvalidArgumentException('Codex prompt exceeds configured max_prompt_bytes.');
        }

        $cwd = $this->resolveCwd($providerConfig, $config);
        $schema = $this->resolveOutputSchema($providerConfig, $config);
        $slotPolicy = $this->resolveSlotPolicy($providerConfig, $processTimeout);
        $nativeExecutable = $this->stringValue($providerConfig['native_executable'] ?? null)
            ?? $this->stringValue($providerConfig['executable'] ?? null, 'codex');
        // Explicit ./ paths are application-relative, independent of workflow cwd.
        if (str_starts_with($nativeExecutable, './')) {
            $nativeExecutable = base_path(substr($nativeExecutable, 2));
        }

        return [
            'executable' => $this->stringValue($providerConfig['executable'] ?? null, 'codex'),
            'profile' => $this->stringValue($config['codex_profile'] ?? null)
                ?? $this->stringValue($providerConfig['default_profile'] ?? null),
            'model' => $model,
            'reasoning_effort' => $effort,
            'sandbox' => $sandbox,
            'approval_policy' => $approvalPolicy,
            'timeout_seconds' => $timeout,
            'process_timeout_seconds' => $processTimeout,
            'native_turn' => $nativeTurn,
            'caller_instructions' => $instructions,
            'native_executable' => $nativeExecutable,
            'node_executable' => $this->stringValue($runtimePolicy['node_executable'] ?? null, 'node'),
            'cwd' => $cwd,
            'strict_config' => (bool) ($providerConfig['strict_config'] ?? false),
            'ephemeral' => (bool) ($providerConfig['ephemeral'] ?? true),
            'json_events' => (bool) ($providerConfig['json_events'] ?? true),
            'skip_git_repo_check' => (bool) ($providerConfig['skip_git_repo_check'] ?? false),
            'output_schema' => $schema,
            'slot_policy' => $slotPolicy,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function acquireExecutionSlot(object $row, array $resolved): array
    {
        $maxConcurrent = filter_var($row->max_concurrent ?? null, FILTER_VALIDATE_INT);
        if ($maxConcurrent === false || $maxConcurrent < 1) {
            return [
                'success' => false,
                'error_type' => 'configuration',
                'error' => 'Codex Exec max_concurrent must be configured on llm_instances.',
            ];
        }

        $policy = $resolved['slot_policy'];
        $cacheKey = (string) $policy['cache_key'];
        $lockKey = $cacheKey.':lock';
        $lockTtl = (int) $policy['lock_ttl_seconds'];
        $slotTtl = (int) $policy['slot_ttl_seconds'];
        $now = time();

        $result = Cache::lock($lockKey, $lockTtl)->get(function () use ($cacheKey, $maxConcurrent, $slotTtl, $now, $resolved) {
            $slots = Cache::get($cacheKey, []);
            $slots = is_array($slots) ? $slots : [];
            $slots = array_filter($slots, fn ($slot): bool => is_array($slot) && (int) ($slot['expires_at'] ?? 0) > $now);

            if (count($slots) >= $maxConcurrent) {
                Cache::put($cacheKey, $slots, $slotTtl);

                return [
                    'success' => false,
                    'error_type' => 'capacity',
                    'error' => "Codex Exec capacity full ({$maxConcurrent}/{$maxConcurrent} slots active from llm_instances.max_concurrent).",
                    'active' => count($slots),
                    'max' => $maxConcurrent,
                ];
            }

            $slotId = uniqid(self::PROVIDER_ID.'_slot_', true);
            $slots[$slotId] = [
                'slot_id' => $slotId,
                'started_at' => $now,
                'expires_at' => $now + $slotTtl,
                'pid' => getmypid(),
                'model' => $resolved['model'] ?? null,
                'reasoning_effort' => $resolved['reasoning_effort'] ?? null,
            ];

            Cache::put($cacheKey, $slots, $slotTtl);

            return [
                'success' => true,
                'slot_id' => $slotId,
                'active' => count($slots),
                'max' => $maxConcurrent,
            ];
        });

        if (! is_array($result)) {
            return [
                'success' => false,
                'error_type' => 'capacity',
                'error' => 'Codex Exec slot lock is unavailable.',
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function releaseExecutionSlot(string $slotId, array $resolved): void
    {
        $policy = $resolved['slot_policy'] ?? null;
        if (! is_array($policy) || empty($policy['cache_key'])) {
            return;
        }

        $cacheKey = (string) $policy['cache_key'];
        $lockKey = $cacheKey.':lock';
        $lockTtl = filter_var($policy['lock_ttl_seconds'] ?? null, FILTER_VALIDATE_INT);
        $slotTtl = filter_var($policy['slot_ttl_seconds'] ?? null, FILTER_VALIDATE_INT);
        if ($lockTtl === false || $slotTtl === false || $lockTtl < 1 || $slotTtl < 1) {
            return;
        }

        Cache::lock($lockKey, $lockTtl)->get(function () use ($cacheKey, $slotId, $slotTtl): void {
            $slots = Cache::get($cacheKey, []);
            $slots = is_array($slots) ? $slots : [];
            unset($slots[$slotId]);
            Cache::put($cacheKey, $slots, $slotTtl);
        });
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return list<string>
     */
    public function buildCommand(array $resolved, string $outputPath): array
    {
        if (! empty($resolved['native_turn'])) {
            return [(string) $resolved['node_executable'], base_path('scripts/plos-cli/native-turn-worker.mjs')];
        }
        $command = [
            (string) $resolved['executable'],
            'exec',
        ];

        if (! empty($resolved['strict_config'])) {
            $command[] = '--strict-config';
        }

        $command[] = '-C';
        $command[] = (string) $resolved['cwd'];

        if (! empty($resolved['skip_git_repo_check'])) {
            $command[] = '--skip-git-repo-check';
        }

        $command = array_merge($command, [
            '-m',
            (string) $resolved['model'],
            '-s',
            (string) $resolved['sandbox'],
            '--color',
            'never',
            '-c',
            'approval_policy="never"',
            '-c',
            'model_reasoning_effort="'.$resolved['reasoning_effort'].'"',
            '-o',
            $outputPath,
        ]);

        if (! empty($resolved['profile'])) {
            $command[] = '-p';
            $command[] = (string) $resolved['profile'];
        }

        if (! empty($resolved['json_events'])) {
            $command[] = '--json';
        }

        if (! empty($resolved['ephemeral'])) {
            $command[] = '--ephemeral';
        }

        if (! empty($resolved['output_schema'])) {
            $command[] = '--output-schema';
            $command[] = (string) $resolved['output_schema'];
        }

        $command[] = '-';

        return $command;
    }

    public function providerRow(): ?object
    {
        return DB::table('llm_instances')
            ->where('instance_id', self::PROVIDER_ID)
            ->first();
    }

    private function providerReadinessError(object $row): ?string
    {
        if ((int) ($row->is_active ?? 0) !== 1) {
            return 'Codex Exec provider row is inactive';
        }

        if ((int) ($row->is_healthy ?? 0) !== 1) {
            return 'Codex Exec provider row is unhealthy';
        }

        if (isset($row->routability) && (string) $row->routability !== 'allowed') {
            return "Codex Exec provider routability is {$row->routability}";
        }

        return null;
    }

    private function resolveCwd(array $providerConfig, array $config): string
    {
        $requested = $this->stringValue($config['cwd'] ?? null)
            ?? $this->stringValue($providerConfig['default_cwd'] ?? null)
            ?? base_path();
        $real = realpath($requested);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Codex cwd does not exist or is not a directory.');
        }

        $roots = $providerConfig['cwd_roots'] ?? [base_path()];
        $allowedRoots = [];
        foreach ((array) $roots as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $rootReal = realpath($root);
            if ($rootReal !== false && is_dir($rootReal)) {
                $allowedRoots[] = rtrim($rootReal, DIRECTORY_SEPARATOR);
            }
        }

        foreach ($allowedRoots as $root) {
            if ($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        throw new InvalidArgumentException('Codex cwd is outside configured cwd_roots.');
    }

    private function resolveOutputSchema(array $providerConfig, array $config): ?string
    {
        $schema = $this->stringValue($config['output_schema'] ?? null);
        if ($schema === null) {
            return null;
        }

        $policy = $this->decodeAssoc($providerConfig['structured_output'] ?? null);
        if (! ($policy['enabled'] ?? false)) {
            throw new InvalidArgumentException('Codex structured output is disabled by provider policy.');
        }
        if (! ($policy['local_validation'] ?? false) || ! ($policy['fail_closed'] ?? false)) {
            throw new InvalidArgumentException('Codex structured output requires fail-closed local validation.');
        }

        $real = realpath($schema);
        if ($real === false || ! is_file($real)) {
            throw new InvalidArgumentException('Codex output schema does not exist.');
        }

        $maxSchemaBytes = $this->requiredPositiveInt($policy['max_schema_bytes'] ?? null, 'structured_output.max_schema_bytes');
        $schemaBytes = filesize($real);
        if ($schemaBytes === false || $schemaBytes > $maxSchemaBytes) {
            throw new InvalidArgumentException("Codex output schema exceeds configured {$maxSchemaBytes}-byte limit.");
        }

        $schemaJson = file_get_contents($real);
        if ($schemaJson === false) {
            throw new InvalidArgumentException('Codex output schema could not be read.');
        }

        try {
            $decodedSchema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Codex output schema is not valid JSON.');
        }

        if (! is_object($decodedSchema) && ! is_bool($decodedSchema)) {
            throw new InvalidArgumentException('Codex output schema must be a JSON object or boolean schema.');
        }

        $roots = (array) ($policy['schema_file_roots'] ?? []);
        foreach ($roots as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $rootReal = realpath($root);
            if ($rootReal !== false && ($real === $rootReal || str_starts_with($real, rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                return $real;
            }
        }

        throw new InvalidArgumentException('Codex output schema is outside configured schema roots.');
    }

    /**
     * @return array{valid:bool,error_type:?string,error:?string,diagnostics:array<string, mixed>}
     */
    public function validateStructuredResponse(string $response, string $schemaPath): array
    {
        $diagnostics = [
            'requested' => true,
            'validator' => 'opis/json-schema',
            'validation' => 'failed',
        ];

        try {
            $data = json_decode($response, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $diagnostics['failure'] = 'invalid_json';

            return [
                'valid' => false,
                'error_type' => 'structured_output_invalid_json',
                'error' => 'Codex final structured response is not valid JSON.',
                'diagnostics' => $diagnostics,
            ];
        }

        $schemaJson = file_get_contents($schemaPath);
        if ($schemaJson === false) {
            $diagnostics['failure'] = 'schema_unreadable';

            return [
                'valid' => false,
                'error_type' => 'structured_output_schema_invalid',
                'error' => 'Codex output schema could not be read for local validation.',
                'diagnostics' => $diagnostics,
            ];
        }

        try {
            $schema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
            $result = (new CompliantValidator)->validate($data, $schema);
        } catch (JsonException|SchemaException) {
            $diagnostics['failure'] = 'schema_invalid';

            return [
                'valid' => false,
                'error_type' => 'structured_output_schema_invalid',
                'error' => 'Codex output schema is invalid for local validation.',
                'diagnostics' => $diagnostics,
            ];
        }

        if (! $result->isValid()) {
            $diagnostics['failure'] = 'schema_mismatch';

            return [
                'valid' => false,
                'error_type' => 'structured_output_schema_mismatch',
                'error' => 'Codex final response failed local JSON Schema validation.',
                'diagnostics' => $diagnostics,
            ];
        }

        $diagnostics['validation'] = 'passed';

        return [
            'valid' => true,
            'error_type' => null,
            'error' => null,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{cache_key:string,slot_ttl_seconds:int,lock_ttl_seconds:int,source:string}
     */
    private function resolveSlotPolicy(array $providerConfig, int $timeoutSeconds): array
    {
        $policy = $this->decodeAssoc($providerConfig['slot_policy'] ?? null);
        $cacheKey = $this->stringValue($policy['cache_key'] ?? null);
        if ($cacheKey === null) {
            throw new InvalidArgumentException('Codex slot_policy.cache_key must be configured.');
        }

        $slotTtl = $this->requiredPositiveInt($policy['slot_ttl_seconds'] ?? null, 'slot_policy.slot_ttl_seconds');
        if ($slotTtl < $timeoutSeconds) {
            throw new InvalidArgumentException('Codex slot_policy.slot_ttl_seconds must cover the execution timeout.');
        }

        return [
            'cache_key' => $cacheKey,
            'slot_ttl_seconds' => $slotTtl,
            'lock_ttl_seconds' => $this->requiredPositiveInt($policy['lock_ttl_seconds'] ?? null, 'slot_policy.lock_ttl_seconds'),
            'source' => $this->stringValue($policy['source'] ?? null, 'llm_instances.max_concurrent'),
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedEffortsForModel(array $providerConfig, string $model): array
    {
        $configured = $providerConfig['supported_reasoning_efforts'] ?? null;
        if (is_array($configured) && isset($configured[$model]) && is_array($configured[$model])) {
            return $this->stringList($configured[$model]);
        }

        if (is_array($configured) && array_is_list($configured)) {
            $list = $this->stringList($configured);

            return $list === [] ? self::DEFAULT_SUPPORTED_EFFORTS : $list;
        }

        return self::DEFAULT_SUPPORTED_EFFORTS;
    }

    private function validateSandbox(string $sandbox, bool $allowDanger): void
    {
        $allowed = ['read-only', 'workspace-write'];
        if ($allowDanger) {
            $allowed[] = 'danger-full-access';
        }

        if (! in_array($sandbox, $allowed, true)) {
            throw new InvalidArgumentException("Codex sandbox '{$sandbox}' is not allowed for pipeline execution.");
        }
    }

    private function newOutputPath(): string
    {
        $dir = storage_path('app/codex-exec');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create Codex output directory.');
        }

        return $dir.'/codex-last-message-'.bin2hex(random_bytes(12)).'.md';
    }

    private function shortError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        return mb_substr($message, 0, 500);
    }

    public function classifyError(string $message): string
    {
        $normalized = mb_strtolower($message);

        if (preg_match('/\b(?:429|rate[_ -]?limit(?:ed|s)?|too many requests|quota|insufficient_quota|rate_limit_exceeded)\b/i', $normalized) === 1) {
            return 'rate_limited';
        }

        if (preg_match('/\b(?:401|403|unauthorized|forbidden|auth(?:entication)?|api[_ -]?key|credential|not logged in|login required)\b/i', $normalized) === 1) {
            return 'authentication';
        }

        if (preg_match('/\b(?:timeout|timed out|deadline exceeded)\b/i', $normalized) === 1) {
            return 'timeout';
        }

        return 'execution_failed';
    }

    /**
     * Summarize Codex CLI output without retaining raw prompt, repository, or model text.
     *
     * @return array<string,mixed>
     */
    public function summarizeCodexCliOutput(string $stdout, string $stderr, string $response = ''): array
    {
        $combined = $stdout."\n".$stderr."\n".$response;
        $events = $this->summarizeJsonEvents($stdout);

        return [
            'stdout_event_count' => $events['event_count'],
            'agent_message_count' => $events['agent_message_count'],
            'command_execution_count' => $events['command_execution_count'],
            'trajectory_tool_calls' => $events['trajectory_tool_calls'],
            'tool_sequence_truncated' => $events['tool_sequence_truncated'],
            'turn_completed_count' => $events['turn_completed_count'],
            'usage' => $events['usage'],
            'stderr_nonempty' => trim($stderr) !== '',
            'apply_patch_incompatible_payload_count' => substr_count($combined, 'tool apply_patch invoked with incompatible payload'),
            'model_metadata_missing_warning' => str_contains($combined, 'Model metadata for'),
            'path_alias_warning' => str_contains($combined, 'could not create PATH aliases'),
            'skill_context_budget_warning' => str_contains($combined, 'Skill descriptions were shortened'),
        ];
    }

    /**
     * @return array{event_count:int,agent_message_count:int,command_execution_count:int,trajectory_tool_calls:list<array<string,mixed>>,tool_sequence_truncated:bool,turn_completed_count:int,usage:array<string,mixed>|null}
     */
    private function summarizeJsonEvents(string $stdout): array
    {
        $summary = [
            'event_count' => 0,
            'agent_message_count' => 0,
            'command_execution_count' => 0,
            'trajectory_tool_calls' => [],
            'tool_sequence_truncated' => false,
            'turn_completed_count' => 0,
            'usage' => null,
        ];

        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $summary['event_count']++;
            $type = is_string($event['type'] ?? null) ? $event['type'] : null;

            if ($type === 'turn.completed') {
                $summary['turn_completed_count']++;
                $usage = $event['usage'] ?? null;
                $summary['usage'] = is_array($usage) ? $usage : null;
            }

            $item = $event['item'] ?? null;
            if (! is_array($item)) {
                continue;
            }

            if ($type !== 'item.completed') {
                continue;
            }

            if (($item['type'] ?? null) === 'agent_message') {
                $summary['agent_message_count']++;
            }

            if (($item['type'] ?? null) === 'command_execution') {
                $summary['command_execution_count']++;
                if (count($summary['trajectory_tool_calls']) < self::MAX_TRAJECTORY_TOOL_CALLS) {
                    $summary['trajectory_tool_calls'][] = $this->trajectoryToolCall($item);
                } else {
                    $summary['tool_sequence_truncated'] = true;
                }
            }
        }

        return $summary;
    }

    /** @return array{tool:string,canonical_action:string,success:bool,action_fingerprint:?string,pair_fingerprint:string} */
    private function trajectoryToolCall(array $item): array
    {
        $command = is_string($item['command'] ?? null) ? $item['command'] : '';
        $output = is_string($item['aggregated_output'] ?? null) ? $item['aggregated_output'] : '';
        $status = is_string($item['status'] ?? null) ? $item['status'] : 'unknown';
        $exitCode = filter_var($item['exit_code'] ?? null, FILTER_VALIDATE_INT);

        return [
            'tool' => 'codex_command_execution',
            'canonical_action' => $this->trajectoryDiagnostics->canonicalAction(
                'command_execution',
                ['command' => $command]
            ),
            'success' => $status === 'completed' && $exitCode === 0,
            'action_fingerprint' => SensitiveTextTelemetry::content(
                $command,
                'codex_exec_trajectory_action',
                'command'
            )['private_text_fingerprint'],
            'pair_fingerprint' => hash('sha256', implode("\0", [
                $command,
                $output,
                $status,
                $exitCode === false ? 'invalid' : (string) $exitCode,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAssoc(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $out[$key] = trim($value);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }

    private function requiredPositiveInt(mixed $value, string $label): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < 1) {
            throw new InvalidArgumentException("Codex {$label} must be configured as a positive integer.");
        }

        return $int;
    }

    private function stringValue(mixed $value, ?string $default = null): ?string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }
}
