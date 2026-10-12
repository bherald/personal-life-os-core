<?php

namespace App\Console\Commands;

use App\Services\OfflineMediaAnalysisService;
use Illuminate\Console\Command;
use Throwable;

class MediaOfflineAnalyzeCommand extends Command
{
    protected $signature = 'media:offline-analyze
        {--path= : Required local JPEG, PNG, or WebP image path}
        {--prompt=Describe this image in detail. : Analysis prompt}
        {--prompt-b64= : Base64-encoded UTF-8 prompt override}
        {--model=qwen3.5:9b : Installed Ollama vision model}
        {--execute : Send the image to local Ollama for inference}
        {--probe : Probe Ollama and selected-model availability without sending the image}
        {--dry-run : Validate the request without network or inference}
        {--json : Emit machine-readable JSON}
        {--timeout=180 : HTTP timeout in seconds}';

    protected $description = 'Safely validate, probe, or analyze a local image with an installed offline Ollama vision model';

    public function handle(OfflineMediaAnalysisService $service): int
    {
        try {
            $mode = $this->mode();
            $prompt = $this->prompt();
            $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
            if ($timeout === false || $timeout < 1 || $timeout > 1800) {
                throw new \InvalidArgumentException('Timeout must be an integer between 1 and 1800 seconds.');
            }

            $imagePath = trim((string) $this->option('path'));
            if ($imagePath === '') {
                throw new \InvalidArgumentException('Provide a local image with --path.');
            }
            $model = (string) $this->option('model');
            $request = $service->validateRequest($imagePath, $prompt, $model);

            $payload = [
                'schema' => 'plos.offline_media_analysis.v1',
                'status' => $mode === 'execute' ? 'complete' : ($mode === 'probe' ? 'probed' : 'validated'),
                'mode' => $mode,
                'read_only' => true,
                'model' => $request['model'],
                'image' => $request['image'],
                'prompt' => $request['prompt'],
            ];

            if ($mode === 'probe') {
                $payload['probe'] = $service->probe($model, min($timeout, 60));
            } elseif ($mode === 'execute') {
                $result = $service->analyze($imagePath, $prompt, $model, $timeout);
                $payload['model'] = $result['model'];
                $payload['analysis'] = $result['analysis'];
                $payload['endpoint_source'] = $result['endpoint_source'];
                $payload['metrics'] = $result['metrics'];
            }

            return $this->finish($payload, self::SUCCESS);
        } catch (Throwable $exception) {
            return $this->finish([
                'schema' => 'plos.offline_media_analysis.v1',
                'status' => 'failed',
                'error' => $this->safeError($exception),
            ], self::FAILURE);
        }
    }

    private function mode(): string
    {
        $selected = array_filter([
            'execute' => (bool) $this->option('execute'),
            'probe' => (bool) $this->option('probe'),
            'dry-run' => (bool) $this->option('dry-run'),
        ]);
        if (count($selected) > 1) {
            throw new \InvalidArgumentException('Choose only one of --execute, --probe, or --dry-run.');
        }

        return array_key_first($selected) ?? 'dry-run';
    }

    private function prompt(): string
    {
        $encoded = trim((string) $this->option('prompt-b64'));
        $plain = (string) $this->option('prompt');
        if ($encoded === '') {
            return $plain;
        }
        if ($plain !== OfflineMediaAnalysisService::DEFAULT_PROMPT) {
            throw new \InvalidArgumentException('Use either --prompt or --prompt-b64, not both.');
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || preg_match('//u', $decoded) !== 1) {
            throw new \InvalidArgumentException('Prompt base64 must decode to valid UTF-8 text.');
        }

        return $decoded;
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException) {
            return $exception->getMessage();
        }

        return 'Offline media analysis failed safely.';
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->line($json === false ? '{"schema":"plos.offline_media_analysis.v1","status":"failed","error":"JSON encoding failed."}' : $json);

            return $json === false ? self::FAILURE : $exit;
        }

        if ($exit !== self::SUCCESS) {
            $this->error((string) ($payload['error'] ?? 'Offline media analysis failed.'));

            return $exit;
        }

        $this->info('Offline media analysis: '.strtoupper((string) $payload['status']));
        $this->line(sprintf(
            'Mode=%s model=%s image=%s %dx%d bytes=%d',
            $payload['mode'],
            $payload['model'],
            $payload['image']['mime_type'],
            $payload['image']['width'],
            $payload['image']['height'],
            $payload['image']['bytes'],
        ));
        if (isset($payload['analysis'])) {
            $this->newLine();
            $this->line((string) $payload['analysis']);
        }

        return $exit;
    }
}
