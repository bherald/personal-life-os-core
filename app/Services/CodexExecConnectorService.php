<?php

namespace App\Services;

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
                'error' => 'Codex Exec provider row not configured',
            ];
        }

        $readinessError = $this->providerReadinessError($row);
        if ($readinessError !== null) {
            return [
                'success' => false,
                'error' => $readinessError,
            ];
        }

        try {
            $resolved = $this->resolveExecutionConfig($row, $config);
        } catch (\Throwable $e) {
            return [
                'success' => false,
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

        $outputPath = $this->newOutputPath();
        $command = $this->buildCommand($resolved, $outputPath);
        $started = microtime(true);

        try {
            $process = new Process($command, $resolved['cwd'], null, $prompt, $resolved['timeout_seconds']);
            $process->setTimeout($resolved['timeout_seconds']);
            $process->run();

            $response = is_file($outputPath)
                ? trim((string) file_get_contents($outputPath))
                : trim($process->getOutput());

            if (! $process->isSuccessful()) {
                return [
                    'success' => false,
                    'provider' => self::PROVIDER_ID,
                    'model' => $resolved['model'],
                    'reasoning_effort' => $resolved['reasoning_effort'],
                    'error' => $this->shortError($process->getErrorOutput() ?: $process->getOutput() ?: 'codex exec failed'),
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
                'error' => $this->shortError($e->getMessage()),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } finally {
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

        $timeout = (int) ($config['timeout'] ?? $providerConfig['default_timeout_seconds'] ?? 900);
        if ($timeout < 1 || $timeout > 3600) {
            throw new InvalidArgumentException('Codex timeout must be between 1 and 3600 seconds.');
        }

        $maxPromptBytes = (int) ($providerConfig['max_prompt_bytes'] ?? 200000);
        if (strlen($this->stringValue($config['prompt_preview'] ?? null, '') ?? '') > $maxPromptBytes) {
            throw new InvalidArgumentException('Codex prompt exceeds configured max_prompt_bytes.');
        }

        $cwd = $this->resolveCwd($providerConfig, $config);
        $schema = $this->resolveOutputSchema($providerConfig, $config);

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
        ];
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

    private function stringValue(mixed $value, ?string $default = null): ?string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }
}
