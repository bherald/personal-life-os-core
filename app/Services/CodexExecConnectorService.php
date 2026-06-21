<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CodexExecConnectorService
{
    private const PROVIDER_ID = 'codex_exec';

    private const DEFAULT_SUPPORTED_EFFORTS = ['low', 'medium', 'high', 'xhigh'];

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
        $started = microtime(true);

        try {
            $slot = $this->acquireExecutionSlot($row, $resolved);
            if (! ($slot['success'] ?? false)) {
                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
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

            $process = new Process($command, $resolved['cwd'], null, $prompt, $resolved['timeout_seconds']);
            $process->setTimeout($resolved['timeout_seconds']);
            $process->run();

            $response = is_file($outputPath)
                ? trim((string) file_get_contents($outputPath))
                : trim($process->getOutput());

            if (! $process->isSuccessful()) {
                $error = $this->shortError($process->getErrorOutput() ?: $process->getOutput() ?: 'codex exec failed');

                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error_type' => $this->classifyError($error),
                    'error' => $error,
                    'exit_code' => $process->getExitCode(),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                ];
            }

            if ($response === '') {
                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error_type' => 'empty_response',
                    'error' => 'codex exec returned empty response',
                    'exit_code' => $process->getExitCode(),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                ];
            }

            return [
                'success' => true,
                'provider' => self::PROVIDER_ID,
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'sandbox' => $resolved['sandbox'],
                'approval_policy' => $resolved['approval_policy'],
                'response' => $response,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (ProcessTimedOutException) {
            return [
                'success' => false,
                'provider' => self::PROVIDER_ID,
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'error_type' => 'timeout',
                'error' => "codex exec timed out after {$resolved['timeout_seconds']}s",
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            Log::warning('CodexExecConnector: execution failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => self::PROVIDER_ID,
                'model' => $resolved['model'],
                'reasoning_effort' => $resolved['reasoning_effort'],
                'error_type' => $this->classifyError($e->getMessage()),
                'error' => $this->shortError($e->getMessage()),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } finally {
            if ($slotId !== null) {
                $this->releaseExecutionSlot($slotId, $resolved);
            }

            if (isset($outputPath) && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
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
        $defaultModel = $this->stringValue($providerConfig['default_model'] ?? null);
        $model = $this->stringValue($config['model_override'] ?? null)
            ?? $this->stringValue($config['codex_model'] ?? null)
            ?? ($models[$role] ?? null)
            ?? ($models['standard'] ?? null)
            ?? $defaultModel
            ?? ($supportedModels[0] ?? null);

        if ($model === null || $model === '') {
            throw new RuntimeException('No Codex model configured for requested role.');
        }

        if ($supportedModels !== [] && ! in_array($model, $supportedModels, true)) {
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

        $maxPromptBytes = $this->requiredPositiveInt($providerConfig['max_prompt_bytes'] ?? null, 'max_prompt_bytes');
        if (strlen($this->stringValue($config['prompt_preview'] ?? null, '') ?? '') > $maxPromptBytes) {
            throw new InvalidArgumentException('Codex prompt exceeds configured max_prompt_bytes.');
        }

        $cwd = $this->resolveCwd($providerConfig, $config);
        $schema = $this->resolveOutputSchema($providerConfig, $config);
        $slotPolicy = $this->resolveSlotPolicy($providerConfig, $timeout);

        return [
            'executable' => $this->stringValue($providerConfig['executable'] ?? null, 'codex'),
            'profile' => $this->stringValue($config['codex_profile'] ?? null)
                ?? $this->stringValue($providerConfig['default_profile'] ?? null),
            'model' => $model,
            'reasoning_effort' => $effort,
            'sandbox' => $sandbox,
            'approval_policy' => $approvalPolicy,
            'timeout_seconds' => $timeout,
            'cwd' => $cwd,
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
        $command = [
            (string) $resolved['executable'],
            'exec',
            '-C',
            (string) $resolved['cwd'],
        ];

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

        $real = realpath($schema);
        if ($real === false || ! is_file($real)) {
            throw new InvalidArgumentException('Codex output schema does not exist.');
        }

        $roots = (array) data_get($providerConfig, 'structured_output.schema_file_roots', []);
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
