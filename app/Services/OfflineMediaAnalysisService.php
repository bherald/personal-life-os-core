<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OfflineMediaAnalysisService
{
    public const DEFAULT_MODEL = 'qwen3.5:9b';

    public const DEFAULT_PROMPT = 'Describe this image in detail.';

    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const MAX_IMAGE_PIXELS = 40_000_000;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Validate an image without transmitting its content.
     *
     * @return array<string, int|string>
     */
    public function inspectImage(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new InvalidArgumentException('Image path must identify a local regular file.');
        }

        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved) || is_link($path)) {
            throw new InvalidArgumentException('Image path must identify a readable local regular file.');
        }

        $stat = @stat($resolved);
        $bytes = $stat['size'] ?? false;
        if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Image file must be non-empty and no larger than 20 MiB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($resolved);
        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Image type is not allowed; use JPEG, PNG, or WebP.');
        }

        $dimensions = @getimagesize($resolved);
        if (! is_array($dimensions)
            || $dimensions['mime'] !== $mime
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS) {
            throw new InvalidArgumentException('Image content or dimensions are invalid.');
        }

        $sha256 = hash_file('sha256', $resolved);
        if (! is_string($sha256)) {
            throw new InvalidArgumentException('Image file could not be read safely.');
        }

        return [
            'sha256' => $sha256,
            'bytes' => $bytes,
            'mime_type' => $mime,
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
    }

    /**
     * Validate an entire request without transmitting any content.
     *
     * @return array{image:array<string, int|string>,model:string,prompt:array{sha256:string,bytes:int}}
     */
    public function validateRequest(string $path, string $prompt, string $model): array
    {
        $prompt = $this->validatedPrompt($prompt);

        return [
            'image' => $this->inspectImage($path),
            'model' => $this->validatedModel($model),
            'prompt' => [
                'sha256' => hash('sha256', $prompt),
                'bytes' => strlen($prompt),
            ],
        ];
    }

    /**
     * Probe the native Ollama inventory without transmitting image or prompt content.
     *
     * @return array<string, bool|int|string>
     */
    public function probe(string $model, int $timeoutSeconds = 15): array
    {
        $model = $this->validatedModel($model);
        [$baseUrl, $source] = $this->endpoint($model);

        try {
            $response = Http::connectTimeout(min(5, $timeoutSeconds))
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->get($baseUrl.'/api/tags');
        } catch (ConnectionException) {
            throw new RuntimeException('Local Ollama probe could not connect.');
        } catch (Throwable) {
            throw new RuntimeException('Local Ollama probe failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Local Ollama probe returned HTTP '.$response->status().'.');
        }

        $models = $response->json('models');
        if (! is_array($models)) {
            throw new RuntimeException('Local Ollama probe returned an invalid response.');
        }

        $installed = false;
        foreach ($models as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['name'] ?? null) === $model || ($entry['model'] ?? null) === $model) {
                $installed = true;
                break;
            }
        }

        return [
            'reachable' => true,
            'model' => $model,
            'model_installed' => $installed,
            'installed_model_count' => count($models),
            'endpoint_source' => $source,
        ];
    }

    /**
     * Analyze one validated local image through Ollama's native vision contract.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $path, string $prompt, string $model, int $timeoutSeconds = 180): array
    {
        $image = $this->inspectImage($path);
        $prompt = $this->validatedPrompt($prompt);
        $model = $this->validatedModel($model);
        [$baseUrl, $source] = $this->endpoint($model);

        $resolved = realpath($path);
        $contents = $resolved === false ? false : @file_get_contents($resolved);
        if (! is_string($contents)
            || strlen($contents) !== $image['bytes']
            || ! hash_equals((string) $image['sha256'], hash('sha256', $contents))) {
            throw new InvalidArgumentException('Image file changed or could not be read safely.');
        }

        $endpoint = $baseUrl.'/api/chat';
        $payload = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => $prompt,
                'images' => [base64_encode($contents)],
            ]],
            'stream' => false,
            'think' => false,
        ];
        $settings = array_diff_key($payload, ['messages' => true]);
        $startedAt = hrtime(true);
        try {
            $response = app(ModelHostControllerTransportSupervisor::class)->run(
                'ollama_chat', $endpoint, $payload,
                function () use ($endpoint, $payload, $settings, $timeoutSeconds) {
                    $request = AIService::maintainInferenceHttp(Http::connectTimeout(min(10, $timeoutSeconds))
                        ->timeout($timeoutSeconds)->acceptJson());
                    AIService::assertInferenceTransport('ollama_chat', $endpoint, $settings);

                    $response = $request->post($endpoint, $payload);
                    if (AIService::hasManagedInferenceContract()
                        && (! $response->successful() || $response->json('done') !== true
                            || ! is_string($response->json('message.content')))) {
                        throw new InferenceAdmissionException('Managed vision response did not prove completion; no replay is allowed.');
                    }

                    return $response;
                }
            );
        } catch (InferenceAdmissionException|ModelHostControllerUnavailableException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new RuntimeException('Local Ollama inference could not connect.');
        } catch (Throwable) {
            throw new RuntimeException('Local Ollama inference failed.');
        } finally {
            unset($contents);
        }
        $elapsedMs = round((hrtime(true) - $startedAt) / 1_000_000, 3);

        if (! $response->successful()) {
            throw new RuntimeException('Local Ollama inference returned HTTP '.$response->status().'.');
        }

        $content = $response->json('message.content');
        if (! is_string($content)) {
            throw new RuntimeException('Local Ollama inference returned an invalid response.');
        }

        return [
            'model' => $model,
            'analysis' => $content,
            'image' => $image,
            'prompt' => [
                'sha256' => hash('sha256', $prompt),
                'bytes' => strlen($prompt),
            ],
            'endpoint_source' => $source,
            'metrics' => [
                'client_elapsed_ms' => $elapsedMs,
                'total_duration_ns' => $this->integerMetric($response->json('total_duration')),
                'load_duration_ns' => $this->integerMetric($response->json('load_duration')),
                'prompt_eval_count' => $this->integerMetric($response->json('prompt_eval_count')),
                'prompt_eval_duration_ns' => $this->integerMetric($response->json('prompt_eval_duration')),
                'eval_count' => $this->integerMetric($response->json('eval_count')),
                'eval_duration_ns' => $this->integerMetric($response->json('eval_duration')),
                'done_reason' => is_string($response->json('done_reason')) ? $response->json('done_reason') : null,
            ],
        ];
    }

    private function validatedModel(string $model): string
    {
        $model = trim($model);
        if (strlen($model) > 128
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/-]*(?::[a-zA-Z0-9._-]+)?\z/', $model) !== 1) {
            throw new InvalidArgumentException('Model name is invalid.');
        }

        return $model;
    }

    private function validatedPrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '' || strlen($prompt) > 20_000 || preg_match('//u', $prompt) !== 1) {
            throw new InvalidArgumentException('Prompt must be valid UTF-8 text between 1 and 20000 bytes.');
        }

        return $prompt;
    }

    /** @return array{string,string} */
    private function endpoint(string $model): array
    {
        $registered = $this->registeredEndpoint($model);
        if ($registered === null) {
            throw new InvalidArgumentException('No registered local Ollama model endpoint is admitted.');
        }
        [$url, $source] = $registered;

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Registered Ollama URL is invalid.');
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path === '/v1') {
            $url = substr(rtrim($url, '/'), 0, -3);
        } elseif ($path !== '') {
            throw new InvalidArgumentException('Registered Ollama URL must not contain an API path.');
        }

        return [rtrim($url, '/'), $source];
    }

    /** @return array{string,string}|null */
    private function registeredEndpoint(string $model): ?array
    {
        try {
            if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models')) {
                return null;
            }

            $row = DB::table('llm_instances as instances')
                ->join('ollama_models as models', 'models.instance_id', '=', 'instances.id')
                ->where('instances.instance_type', 'ollama')
                ->where('instances.is_active', 1)
                ->where('instances.routability', 'allowed')
                ->where('instances.is_healthy', 1)
                ->where('models.model_name', $model)
                ->where('models.is_available', 1)
                ->orderBy('instances.priority')
                ->orderBy('instances.id')
                ->first(['instances.instance_id', 'instances.base_url']);
        } catch (Throwable) {
            return null;
        }

        $url = trim((string) ($row->base_url ?? ''));
        $instanceId = trim((string) ($row->instance_id ?? ''));
        if ($url === '' || $instanceId === '') {
            return null;
        }

        return [$url, 'llm_instances:'.$instanceId];
    }

    private function integerMetric(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
