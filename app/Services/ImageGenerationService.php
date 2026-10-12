<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImageGenerationService
{
    private const MAX_IMAGE_BYTES = 50 * 1024 * 1024;

    /** @return array<string, mixed> */
    public function capability(bool $probe = false): array
    {
        $endpoint = $this->endpoint();
        $declared = $this->declaredModelInventory();
        $host = strtolower((string) (parse_url($endpoint['url'], PHP_URL_HOST) ?: ''));
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        $tokenRequired = $endpoint['url'] !== '' && ! $isLoopback;

        $capability = [
            'status' => $endpoint['url'] === '' ? 'not_configured' : 'configured',
            'provider' => 'PLOS image sidecar',
            'url_configured' => $endpoint['url'] !== '',
            'token_configured' => $endpoint['token'] !== '',
            'token_required' => $tokenRequired,
            'timeout_seconds' => $endpoint['timeout'],
            'default_model' => $declared['default_model'],
            'models' => $declared['models'],
            'probe' => [
                'enabled' => $probe,
                'status' => $probe ? 'not_run' : 'skipped',
            ],
        ];

        if ($endpoint['url'] === '' || ! $probe) {
            return $capability;
        }

        if ($tokenRequired && $endpoint['token'] === '') {
            $capability['status'] = 'blocked_missing_token';
            $capability['probe'] = [
                'enabled' => true,
                'status' => 'blocked',
                'message' => 'Non-loopback image sidecar requires PLOS_IMAGE_SIDECAR_TOKEN.',
            ];

            return $capability;
        }

        try {
            $request = Http::timeout(5);
            if ($endpoint['token'] !== '') {
                $request = $request->withToken($endpoint['token']);
            }

            $response = $request->get($endpoint['url'].'/health');
            $health = $response->successful() ? $response->json() : null;
            $capability['probe'] = [
                'enabled' => true,
                'status' => $response->successful() ? 'pass' : 'fail',
                'http_status' => $response->status(),
            ];
            if (is_array($health)) {
                $capability['default_model'] = is_string($health['default_model'] ?? null) ? $health['default_model'] : null;
                $capability['active_model'] = is_string($health['active_model'] ?? null) ? $health['active_model'] : null;
                $capability['models'] = $this->safeModelInventory($health['models'] ?? []);
            }
            $capability['status'] = $response->successful() ? 'available' : 'probe_failed';
        } catch (Throwable $exception) {
            $capability['status'] = 'probe_failed';
            $capability['probe'] = [
                'enabled' => true,
                'status' => 'fail',
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ];
        }

        return $capability;
    }

    /** @return array{quality_rating:null,status:string,is_available:bool} */
    public function modelCapability(string $model): array
    {
        $alias = $this->modelAlias($model);
        $capability = $this->capability(true);
        foreach ($capability['models'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $alias) {
                $available = ($candidate['installed'] ?? false) === true;

                return [
                    'quality_rating' => null,
                    'status' => $available ? 'available' : 'unavailable',
                    'is_available' => $available,
                ];
            }
        }

        return ['quality_rating' => null, 'status' => 'unavailable', 'is_available' => false];
    }

    /**
     * Validate an image request and return non-secret planning metadata.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(string $prompt, array $options = []): array
    {
        $request = $this->validatedRequest($prompt, $options);
        $endpoint = $this->endpoint();

        return [
            'provider' => 'image_sidecar',
            'configured' => $endpoint['url'] !== '',
            'authenticated' => $endpoint['token'] !== '',
            'sidecar_url' => $endpoint['url'] !== '' ? $endpoint['url'] : null,
            'prompt_sha256' => hash('sha256', $request['prompt']),
            'prompt_chars' => mb_strlen($request['prompt']),
            'model' => $request['model'],
            'width' => $request['width'],
            'height' => $request['height'],
            'steps' => $request['steps'],
            'seed' => $request['seed'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{content:string,filename:string,mime_type:string,metadata:array<string,mixed>}
     */
    public function generate(string $prompt, array $options = []): array
    {
        $request = $this->validatedRequest($prompt, $options);
        $endpoint = $this->endpoint();
        if ($endpoint['url'] === '') {
            throw new RuntimeException('Image sidecar URL is not configured.');
        }

        $host = strtolower((string) (parse_url($endpoint['url'], PHP_URL_HOST) ?: ''));
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($endpoint['token'] === '' && ! $isLoopback) {
            throw new RuntimeException('Image sidecar token is required for non-loopback sidecar URLs.');
        }

        $managed = app(ModelHostControllerResourcePolicyService::class)->managedHostForEndpoint($endpoint['url']) !== null;
        if ($managed) {
            $request = $this->managedRequest($request, $endpoint['url']);
        }
        $url = $endpoint['url'].'/generate';

        return app(ModelHostControllerTransportSupervisor::class)->run(
            'image_generation', $url, $request,
            fn () => $this->performRequest($request, $endpoint, $managed)
        );
    }

    private function managedRequest(array $request, string $endpoint): array
    {
        $provider = app(ModelHostControllerResourcePolicyService::class)->managedProvider($endpoint);
        $config = is_string($provider->config ?? null) ? json_decode($provider->config, true) : ($provider->config ?? []);
        $model = $request['model'] ?? ($config['image_default_model'] ?? null);
        $workload = $config['controller_workloads']['image_generation'][$model ?? ''] ?? null;
        if (! is_string($model) || ! is_array($workload) || ! is_array($workload['defaults'] ?? null)) {
            throw new InferenceAdmissionException('Image generation requires a qualified controller resource profile on this managed host.');
        }
        $request['model'] = $model;
        foreach (['width', 'height', 'steps', 'guidance_scale'] as $key) {
            $request[$key] ??= $workload['defaults'][$key] ?? null;
            if ($request[$key] === null) {
                throw new InferenceAdmissionException('Controller image defaults are incomplete.');
            }
        }
        $request['seed'] ??= random_int(0, 4294967295);

        return $this->validatedRequest($request['prompt'], $request);
    }

    private function performRequest(array $request, array $endpoint, bool $managed): array
    {
        try {
            $http = Http::acceptJson()->timeout($endpoint['timeout']);
            if ($endpoint['token'] !== '') {
                $http = $http->withToken($endpoint['token']);
            }

            AIService::assertInferenceTransport('image_generation', $endpoint['url'].'/generate', ModelHostControllerTransportSupervisor::settings($request));
            $response = AIService::maintainInferenceHttp($http)->post($endpoint['url'].'/generate', array_filter(
                $request,
                static fn (mixed $value): bool => $value !== null
            ));
        } catch (InferenceAdmissionException $error) {
            throw $error;
        } catch (ConnectionException $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }
            throw new RuntimeException('Image sidecar is unavailable.');
        } catch (Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }
            throw new RuntimeException('Image sidecar request failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Image sidecar request failed.');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['ok']) || ! is_string($data['image_base64'] ?? null)) {
            throw new RuntimeException('Image sidecar returned an invalid response.');
        }
        if ($request['model'] !== null && ($data['model'] ?? null) !== $request['model']) {
            throw new RuntimeException('Image sidecar returned an unexpected model.');
        }

        $content = base64_decode($data['image_base64'], true);
        if (! is_string($content) || $content === '' || strlen($content) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Image sidecar returned invalid image data.');
        }

        $mimeType = strtolower(trim((string) ($data['mime_type'] ?? 'image/png')));
        if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('Image sidecar returned an unsupported image type.');
        }
        $detected = @getimagesizefromstring($content);
        if (! is_array($detected) || strtolower($detected['mime']) !== $mimeType) {
            throw new RuntimeException('Image sidecar returned invalid image data.');
        }

        if ($managed) {
            foreach (['width', 'height', 'steps', 'seed', 'guidance_scale'] as $key) {
                if (! is_numeric($data[$key] ?? null) || (float) $data[$key] !== (float) $request[$key]) {
                    throw new RuntimeException('Image sidecar changed the admitted request.');
                }
            }
            if ($detected[0] !== $request['width'] || $detected[1] !== $request['height']) {
                throw new RuntimeException('Image dimensions differ from the admitted request.');
            }
        }

        $filename = $this->safeFilename((string) ($data['filename'] ?? ''), $mimeType);
        $metadata = [
            'provider' => 'image_sidecar',
            'model' => trim((string) ($data['model'] ?? 'unknown')) ?: 'unknown',
            'display_name' => trim((string) ($data['display_name'] ?? '')) ?: null,
            'category' => trim((string) ($data['category'] ?? '')) ?: null,
            'pipeline' => trim((string) ($data['pipeline'] ?? '')) ?: null,
            'sha256' => hash('sha256', $content),
            'seed' => $this->safeResponseNumber($data['seed'] ?? ($request['seed'] ?? null)),
            'width' => $this->safeResponseNumber($data['width'] ?? $request['width']),
            'height' => $this->safeResponseNumber($data['height'] ?? $request['height']),
            'steps' => $this->safeResponseNumber($data['steps'] ?? $request['steps']),
            'guidance_scale' => $this->safeResponseNumber($data['guidance_scale'] ?? $request['guidance_scale']),
            'elapsed_seconds' => $this->safeResponseNumber($data['elapsed_seconds'] ?? null),
            'sidecar_url' => $endpoint['url'],
        ];

        return [
            'content' => $content,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{prompt:string,model:?string,width:?int,height:?int,steps:?int,guidance_scale:?float,seed:?int}
     */
    private function validatedRequest(string $prompt, array $options): array
    {
        if (! mb_check_encoding($prompt, 'UTF-8')) {
            throw new InvalidArgumentException('Image prompt must be valid UTF-8.');
        }
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('Image prompt is required.');
        }
        if (mb_strlen($prompt) > 2000) {
            throw new InvalidArgumentException('Image prompt may not exceed 2000 characters.');
        }

        $model = isset($options['model']) && trim((string) $options['model']) !== ''
            ? $this->modelAlias((string) $options['model'])
            : null;
        $width = $this->validatedOptionalInteger($options['width'] ?? null, 'width', 256, 1024);
        $height = $this->validatedOptionalInteger($options['height'] ?? null, 'height', 256, 1024);
        if (($width !== null && $width % 64 !== 0) || ($height !== null && $height % 64 !== 0)) {
            throw new InvalidArgumentException('Image width and height must be multiples of 64.');
        }

        $steps = $this->validatedOptionalInteger($options['steps'] ?? null, 'steps', 1, 100);
        $seed = $options['seed'] ?? null;
        if ($seed === '') {
            $seed = null;
        }
        if ($seed !== null) {
            $seed = $this->validatedInteger($seed, 'seed', 0, 4294967295);
        }

        $guidance = $options['guidance_scale'] ?? null;
        if ($guidance !== null && $guidance !== ''
            && (! is_numeric($guidance) || ! is_finite((float) $guidance) || (float) $guidance < 0 || (float) $guidance > 30)) {
            throw new InvalidArgumentException('Image guidance_scale must be between 0 and 30.');
        }

        return [
            'prompt' => $prompt,
            'model' => $model,
            'width' => $width,
            'height' => $height,
            'steps' => $steps,
            'guidance_scale' => $guidance === null || $guidance === '' ? null : (float) $guidance,
            'seed' => $seed,
        ];
    }

    private function validatedInteger(mixed $value, string $name, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < $minimum || $validated > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Image %s must be an integer between %d and %d.',
                $name,
                $minimum,
                $maximum
            ));
        }

        return $validated;
    }

    private function validatedOptionalInteger(mixed $value, string $name, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->validatedInteger($value, $name, $minimum, $maximum);
    }

    private function modelAlias(string $model): string
    {
        $model = trim($model);
        foreach (['sidecar:image/', 'sidecar:'] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                $model = substr($model, strlen($prefix));
                break;
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,79}$/', $model) !== 1) {
            throw new InvalidArgumentException('Image model must be a safe allowlisted alias.');
        }

        return $model;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function safeModelInventory(mixed $models): array
    {
        if (! is_array($models)) {
            return [];
        }

        $safe = [];
        foreach ($models as $model) {
            if (! is_array($model) || preg_match('/^[a-z0-9][a-z0-9.-]{0,79}$/', (string) ($model['id'] ?? '')) !== 1) {
                continue;
            }
            $safe[] = [
                'id' => (string) $model['id'],
                'display_name' => mb_substr((string) ($model['display_name'] ?? ''), 0, 120),
                'category' => mb_substr((string) ($model['category'] ?? ''), 0, 80),
                'pipeline' => mb_substr((string) ($model['pipeline'] ?? ''), 0, 80),
                'source' => mb_substr((string) ($model['source'] ?? ''), 0, 160),
                'revision' => mb_substr((string) ($model['revision'] ?? ''), 0, 80),
                'license' => mb_substr((string) ($model['license'] ?? ''), 0, 120),
                'published' => ($model['published'] ?? false) === true,
                'installed' => array_key_exists('installed', $model)
                    ? ($model['installed'] === true)
                    : null,
                'defaults' => is_array($model['defaults'] ?? null) ? $model['defaults'] : [],
            ];
        }

        return $safe;
    }

    /** @return array{default_model:?string,models:array<int,array<string,mixed>>} */
    private function declaredModelInventory(): array
    {
        $registryPath = base_path('scripts/plos-image-models.json');
        if (! is_file($registryPath)) {
            return ['default_model' => null, 'models' => []];
        }

        try {
            $payload = json_decode(
                (string) file_get_contents($registryPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            return ['default_model' => null, 'models' => []];
        }

        $models = [];
        foreach ($payload['models'] ?? [] as $alias => $model) {
            if (is_array($model)) {
                $models[] = ['id' => $alias] + $model;
            }
        }

        return [
            'default_model' => is_string($payload['default_model'] ?? null)
                ? $payload['default_model']
                : null,
            'models' => $this->safeModelInventory($models),
        ];
    }

    /**
     * @return array{url:string,token:string,timeout:int}
     */
    private function endpoint(): array
    {
        $url = $this->environmentValue('PLOS_IMAGE_SIDECAR_URL')
            ?? trim((string) config('services.image_sidecar.url', ''));
        $token = $this->environmentValue('PLOS_IMAGE_SIDECAR_TOKEN') ?? '';
        $environmentTokenFile = $this->environmentValue('PLOS_IMAGE_SIDECAR_TOKEN_FILE');
        if ($token === '' && $environmentTokenFile !== null) {
            $token = $this->tokenFromFile($environmentTokenFile);
        }
        if ($token === '') {
            $token = trim((string) config('services.image_sidecar.token', ''));
        }
        if ($token === '') {
            $token = $this->tokenFromFile((string) config('services.image_sidecar.token_file', ''));
        }
        $timeout = $this->environmentValue('PLOS_IMAGE_SIDECAR_TIMEOUT')
            ?? (string) config('services.image_sidecar.timeout', 240);

        return [
            'url' => rtrim($url, '/'),
            'token' => $token,
            'timeout' => max(1, min(600, (int) $timeout)),
        ];
    }

    private function environmentValue(string $name): ?string
    {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function tokenFromFile(string $configuredPath): string
    {
        $configuredPath = trim($configuredPath);
        if ($configuredPath === '' || str_contains($configuredPath, '://')) {
            return '';
        }

        $path = realpath($configuredPath);
        if ($path === false || is_link($configuredPath) || ! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $permissions = fileperms($path);
        if (! is_int($permissions) || ($permissions & 0077) !== 0) {
            return '';
        }

        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > 16384) {
            return '';
        }

        $contents = file_get_contents($path, false, null, 0, 16384);

        return is_string($contents) ? trim($contents) : '';
    }

    private function safeFilename(string $filename, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $stem = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem), '.-_');
        if ($stem === '') {
            $stem = 'generated-image';
        }

        return mb_substr($stem, 0, 100).'.'.$extension;
    }

    private function safeResponseNumber(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null);
    }
}
