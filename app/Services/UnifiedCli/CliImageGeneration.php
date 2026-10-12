<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Bridge the existing guarded Laravel image command into private CLI artifacts. */
final class CliImageGeneration
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $applicationRoot,
        private readonly CliArtifactStore $artifacts,
    ) {}

    public function generate(string $model, string $prompt): array
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/D', $model)
            || trim($prompt) === '' || strlen($prompt) > 8192 || ! mb_check_encoding($prompt, 'UTF-8')
            || str_contains($prompt, "\0")) {
            throw new InvalidArgumentException('Use an image model alias and a nonempty UTF-8 prompt of at most 8192 bytes.');
        }

        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($this->sessionId);
        if ($configuration->operationSelection($session, workflow: true)['target'] !== 'local') {
            throw new RuntimeException('Image generation uses this installation. Select /target local before running it.');
        }
        if (isset($session['host_lease'])
            && ! in_array($session['host_lease']['phase'] ?? null, ['released', 'not_started'], true)) {
            throw new RuntimeException('Review and release or reconcile this session model-host lease before image generation.');
        }
        $values = $configuration->effective($session)['values'];
        $plan = $this->command($model, $prompt, false);
        $request = $plan['request'] ?? [];
        if (($request['configured'] ?? false) !== true || ! is_string($request['sidecar_url'] ?? null)) {
            throw new RuntimeException('Image sidecar is not configured on this installation.');
        }
        if (($request['prompt_sha256'] ?? null) !== hash('sha256', $prompt)
            || ($request['model'] ?? null) !== $model) {
            throw new RuntimeException('Image command plan differs from the selected prompt or model.');
        }
        $url = $request['sidecar_url'];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, ['localhost', '::1'], true) && @inet_pton($host) === false) {
            throw new RuntimeException('CLI image generation requires a literal local or LAN sidecar address.');
        }
        (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))
            ->requestOptions($url, $values['cli.network'] ?? 'offline');
        if (($request['authenticated'] ?? false) !== true
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('The selected image sidecar has no configured authentication.');
        }

        $payload = $this->command($model, $prompt, true, hash('sha256', $url));

        $output = $payload['output'] ?? [];
        $relative = $output['path'] ?? null;
        if (! is_string($relative)
            || ! preg_match('~^storage/app/offline-media/[A-Za-z0-9._-]+\.(?:png|jpg|webp)$~D', $relative)) {
            throw new RuntimeException('Image command returned an unsafe output path.');
        }
        $mediaRoot = realpath($this->applicationRoot.'/storage/app/offline-media');
        $path = realpath($this->applicationRoot.'/'.$relative);
        if ($mediaRoot === false || $path === false || dirname($path) !== $mediaRoot || ! is_file($path)) {
            throw new RuntimeException('Generated image is unavailable at its private output path.');
        }
        $before = stat($path);
        $artifact = $this->artifacts->importFile($path, [$mediaRoot]);
        $expectedMime = $output['mime_type'] ?? null;
        if (! is_string($output['sha256'] ?? null)
            || ! hash_equals($output['sha256'], $artifact['sha256'])
            || ($output['bytes'] ?? null) !== $artifact['bytes']
            || ! in_array($expectedMime, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $artifact['mime_type'] !== $expectedMime) {
            throw new RuntimeException('Generated image bytes differ from the guarded command receipt.');
        }
        $after = stat($path);
        if ($before['ino'] !== $after['ino'] || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']
            || ! hash_equals($artifact['sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Generated image changed after private capture; source retained for review.');
        }

        $artifact = array_replace($artifact, ['kind' => 'generated', 'model' => $model]);
        unset($artifact['source_path']);
        $result = ['status' => 'generated', 'model' => $model, 'output' => $artifact,
            'prompt_sha256' => hash('sha256', $prompt)];
        $this->store->recordEvent($this->sessionId, 'media_image_generation', $result);
        unlink($path);

        return $result;
    }

    private function command(string $model, string $prompt, bool $execute, ?string $expectedUrlHash = null): array
    {
        $arguments = [PHP_BINARY, $this->applicationRoot.'/artisan', 'media:image-generate',
            '--prompt-stdin', '--model='.$model, $execute ? '--execute' : '--dry-run', '--json'];
        if ($expectedUrlHash !== null) {
            $arguments[] = '--expected-sidecar-url-sha256='.$expectedUrlHash;
        }
        $process = new Process($arguments, $this->applicationRoot, [
            'HTTP_PROXY' => false, 'HTTPS_PROXY' => false, 'ALL_PROXY' => false,
            'http_proxy' => false, 'https_proxy' => false, 'all_proxy' => false,
            'NO_PROXY' => '*', 'no_proxy' => '*',
        ], $prompt);
        // The image service and managed controller own their request deadlines.
        // This bridge does not retry an uncertain generation outcome.
        $process->setTimeout(null);
        $process->run();
        $payload = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($payload)
            || ($payload['schema'] ?? null) !== 'plos_media_image_generate.v1'
            || ($payload['status'] ?? null) !== ($execute ? 'generated' : 'planned')) {
            throw new RuntimeException('Image generation did not complete. Inspect the application image command and controller receipts before retrying.');
        }

        return $payload;
    }
}
