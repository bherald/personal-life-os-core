<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Exact selected-runtime prompt counting; local asset locations are not synced. */
final class CliPromptCounter
{
    public function __construct(
        private readonly ?CliStateStore $store,
        private readonly ?string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly string $pythonExecutable,
        private readonly array $assetPaths,
        private readonly ?string $pythonLibraryPath = null,
    ) {}

    /** Local asset locations only; a binding file can never grant admission. */
    public static function fromBindingsFile(?CliStateStore $store, ?string $sessionId, CliArtifactStore $artifacts, string $path): self
    {
        try {
            $binding = json_decode(CliLocalBindings::readPrivateFile($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($binding) || ($binding['schema'] ?? null) !== 'plos.cli.tokenizer-bindings.v1'
                || array_diff(array_keys($binding), ['schema', 'python', 'python_library_path', 'asset_paths']) !== []
                || ! is_array($binding['asset_paths'] ?? null) || $binding['asset_paths'] === []) {
                throw new RuntimeException('Invalid tokenizer location metadata.');
            }
            $absolute = fn ($value) => is_string($value) && ! str_contains($value, "\0")
                && (str_starts_with($value, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $value));
            if (! $absolute($binding['python'] ?? null) || ! is_executable($binding['python'])) {
                throw new RuntimeException('Python executable is unavailable.');
            }
            $library = $binding['python_library_path'] ?? null;
            if ($library !== null && (! $absolute($library) || ! is_dir($library) || is_link($library))) {
                throw new RuntimeException('Tokenizer libraries are unavailable.');
            }
            foreach ($binding['asset_paths'] as $hash => $asset) {
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || ! $absolute($asset) || is_link($asset) || ! is_file($asset) || ! is_readable($asset)) {
                    throw new RuntimeException('Tokenizer asset location is invalid.');
                }
            }

            return new self($store, $sessionId, $artifacts, $binding['python'], $binding['asset_paths'], $library);
        } catch (Throwable) {
            throw new RuntimeException('Local tokenizer bindings are unavailable or invalid. No runtime admission was granted.');
        }
    }

    public function count(array $route, array $messages, array $tools): int
    {
        $contract = $route['tokenizer'] ?? [];
        if (($route['agent_admitted'] ?? false) !== true || ($contract['driver'] ?? null) !== 'colibri_glm'
            || ! is_bool($route['enable_thinking'] ?? null) || ! is_string($contract['tokenizers_version'] ?? null)
            || ! is_string($route['instance_id'] ?? null) || ! is_string($route['model'] ?? null)) {
            throw new RuntimeException('Exact selected-model tokenizer and explicit thinking settings are required.');
        }
        $assets = [];
        $names = ['renderer', 'tokenizer', 'family_registry', 'v4_dsml'];
        if (array_key_exists('v41_dsml_sha256', $contract)) {
            $names[] = 'v41_dsml';
        }
        foreach ($names as $name) {
            $hash = $contract[$name.'_sha256'] ?? null;
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash) || ! is_string($this->assetPaths[$hash] ?? null)) {
                throw new RuntimeException('Selected runtime asset has no local binding: '.$name);
            }
            $assets[$name] = ['path' => $this->assetPaths[$hash], 'sha256' => $hash];
        }
        $request = ['driver' => $contract['driver'], 'tokenizers_version' => $contract['tokenizers_version'],
            'assets' => $assets, 'enable_thinking' => $route['enable_thinking'],
            'reasoning_effort' => $route['reasoning_effort'] ?? null, 'messages' => $messages, 'tools' => $tools];
        $input = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Explicitly remove inherited model/MCP credentials and Python hooks.
        $environment = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $name) {
            if (is_string(getenv($name))) {
                $environment[$name] = getenv($name);
            }
        }
        $environment['PYTHONDONTWRITEBYTECODE'] = '1';
        $environment['PYTHONNOUSERSITE'] = '1';
        $environment['TOKENIZERS_PARALLELISM'] = 'false';
        if ($this->pythonLibraryPath !== null) {
            $environment['PYTHONPATH'] = $this->pythonLibraryPath;
        }
        $worker = dirname(__DIR__, 3).'/scripts/plos-cli/count-prompt.py';
        $process = new Process([$this->pythonExecutable, $worker], dirname($worker), $environment, $input, 60);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Tokenizer worker failed.');
            }
            $reply = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $prompt = base64_decode($reply['prompt_base64'] ?? '', true);
            if (! is_int($reply['tokens'] ?? null) || $reply['tokens'] < 0 || ! is_string($prompt)
                || ($reply['request_sha256'] ?? null) !== hash('sha256', $input)
                || ($reply['prompt_sha256'] ?? null) !== hash('sha256', $prompt)
                || ($reply['asset_sha256'] ?? null) !== array_map(fn ($asset) => $asset['sha256'], $assets)) {
                throw new RuntimeException('Tokenizer response identity or completeness differs from the request.');
            }
            $artifact = $this->artifacts->captureBytes($prompt);
            $this->store?->recordEvent($this->sessionId, 'prompt_count', ['instance_id' => $route['instance_id'],
                'model' => $route['model'], 'tokens' => $reply['tokens'], 'rendered_prompt' => $artifact,
                'request_sha256' => $reply['request_sha256'], 'asset_sha256' => $reply['asset_sha256']]);

            return $reply['tokens'];
        } catch (Throwable) {
            throw new RuntimeException('Exact selected-model prompt counting failed. No approximate count, inference or fallback was used.');
        }
    }
}
