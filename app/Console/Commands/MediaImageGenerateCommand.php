<?php

namespace App\Console\Commands;

use App\Services\ImageGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class MediaImageGenerateCommand extends Command
{
    protected $signature = 'media:image-generate
        {prompt?* : Image prompt}
        {--prompt-b64= : UTF-8 prompt encoded as base64}
        {--prompt-stdin : Read the UTF-8 prompt from standard input}
        {--expected-sidecar-url-sha256= : Require the exact preflight sidecar endpoint}
        {--model= : Allowlisted image-generator alias; sidecar default when omitted}
        {--width= : Output width, 256-1024 and divisible by 64; model default when omitted}
        {--height= : Output height, 256-1024 and divisible by 64; model default when omitted}
        {--steps= : Generation steps, 1-100; model default when omitted}
        {--guidance-scale= : Guidance scale, 0-30; model default when omitted}
        {--seed= : Optional deterministic seed, 0-4294967295}
        {--execute : Generate and store the image}
        {--dry-run : Validate and plan only; overrides --execute}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Plan or generate an image through the authenticated local image sidecar';

    public function handle(ImageGenerationService $images): int
    {
        $decoded = $this->promptInput();
        if ($decoded['error'] !== null) {
            return $this->finish($this->failurePayload('invalid_input', $decoded['error']));
        }

        $options = [
            'model' => $this->option('model'),
            'width' => $this->option('width'),
            'height' => $this->option('height'),
            'steps' => $this->option('steps'),
            'guidance_scale' => $this->option('guidance-scale'),
            'seed' => $this->option('seed'),
        ];
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');

        try {
            $plan = $images->plan($decoded['prompt'], $options);
            $expectedUrlHash = $this->option('expected-sidecar-url-sha256');
            if ($expectedUrlHash !== null && (! is_string($expectedUrlHash)
                || ! preg_match('/^[a-f0-9]{64}$/D', $expectedUrlHash)
                || ! hash_equals($expectedUrlHash, hash('sha256', (string) ($plan['sidecar_url'] ?? ''))))) {
                return $this->finish($this->failurePayload('invalid_input', 'Image sidecar endpoint changed after preflight.'));
            }
            $base = [
                'schema' => 'plos_media_image_generate.v1',
                'status' => $execute ? 'running' : 'planned',
                'mode' => $execute ? 'execute' : 'dry_run',
                'read_only' => ! $execute,
                'request' => $plan,
            ];

            if (! $execute) {
                return $this->finish($base + [
                    'message' => 'Run with --execute to generate and store the image.',
                ], self::SUCCESS);
            }

            $result = $images->generate($decoded['prompt'], $options);
            $relativePath = $this->relativeOutputPath((string) $result['mime_type']);
            $absolutePath = storage_path('app/'.substr($relativePath, strlen('storage/app/')));
            File::ensureDirectoryExists(dirname($absolutePath), 0750, true);
            if (File::put($absolutePath, $result['content']) === false) {
                throw new \RuntimeException('Generated image could not be stored.');
            }

            $metadata = $result['metadata'];
            unset($metadata['sidecar_url']);

            return $this->finish(array_merge($base, [
                'status' => 'generated',
                'output' => [
                    'path' => $relativePath,
                    'mime_type' => $result['mime_type'],
                    'bytes' => strlen($result['content']),
                    'sha256' => hash('sha256', $result['content']),
                ],
                'metadata' => $metadata,
            ]), self::SUCCESS);
        } catch (InvalidArgumentException $e) {
            return $this->finish($this->failurePayload('invalid_input', $e->getMessage()));
        } catch (Throwable) {
            return $this->finish($this->failurePayload('failed', 'Image generation failed.'));
        }
    }

    /**
     * @return array{prompt:string,error:?string}
     */
    private function promptInput(): array
    {
        if ((bool) $this->option('prompt-stdin')) {
            if ($this->option('prompt-b64') || $this->argument('prompt') !== []) {
                return ['prompt' => '', 'error' => '--prompt-stdin cannot be combined with another prompt input.'];
            }
            $input = stream_get_contents(STDIN, 8193);
            if ($input === false || strlen($input) > 8192 || ! mb_check_encoding($input, 'UTF-8')) {
                return ['prompt' => '', 'error' => '--prompt-stdin requires at most 8192 bytes of UTF-8.'];
            }

            return ['prompt' => trim($input), 'error' => null];
        }
        $encoded = trim((string) ($this->option('prompt-b64') ?: ''));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
                return ['prompt' => '', 'error' => '--prompt-b64 must contain valid base64-encoded UTF-8.'];
            }

            return ['prompt' => $decoded, 'error' => null];
        }

        $parts = $this->argument('prompt');

        return [
            'prompt' => trim(implode(' ', $parts)),
            'error' => null,
        ];
    }

    private function relativeOutputPath(string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return 'storage/app/offline-media/'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(6)).'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    private function failurePayload(string $status, string $error): array
    {
        return [
            'schema' => 'plos_media_image_generate.v1',
            'status' => $status,
            'mode' => (bool) $this->option('execute') && ! (bool) $this->option('dry-run') ? 'execute' : 'dry_run',
            'read_only' => ! ((bool) $this->option('execute') && ! (bool) $this->option('dry-run')),
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exit = self::FAILURE): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(($payload['schema'] ?? 'media:image-generate').': '.($payload['status'] ?? 'unknown'));
        if (isset($payload['message'])) {
            $this->line((string) $payload['message']);
        }
        if (isset($payload['output']['path'])) {
            $this->line('Output: '.$payload['output']['path']);
        }
        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exit;
    }
}
