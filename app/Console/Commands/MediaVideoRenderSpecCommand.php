<?php

namespace App\Console\Commands;

use App\Services\VideoRenderSpecService;
use Illuminate\Console\Command;

class MediaVideoRenderSpecCommand extends Command
{
    protected $signature = 'media:video-render-spec
        {--artifact= : storage/app-relative recap JSON artifact}
        {--example : Use a built-in public-safe recap}
        {--write : Write an offline bundle under storage/app/video-render-bundles}
        {--bundle= : New lowercase bundle key (required with --write)}
        {--json : Emit machine-readable JSON}
        {--compact : Omit scene detail and the HTML preview}';

    protected $description = 'Validate a recap and optionally write a local HyperFrames-compatible render bundle';

    public function handle(VideoRenderSpecService $service): int
    {
        try {
            $input = $this->loadInput($service);
            $bundle = $service->build($input['artifact']);
            $result = [
                'schema' => 'plos.video_render_spec_result.v1',
                'status' => 'pass',
                'source' => $input['source'],
                'write_requested' => (bool) $this->option('write'),
                'bundle' => null,
                'manifest' => $bundle['manifest'],
                'html' => $bundle['html'],
                'posture' => [
                    'renderer_invoked' => false,
                    'network_used' => false,
                    'cloud_publish_allowed' => false,
                    'operator_review_required_before_render' => true,
                ],
            ];

            if ($this->option('write')) {
                $bundleKey = trim((string) $this->option('bundle'));
                if ($bundleKey === '') {
                    throw new \InvalidArgumentException('--bundle is required with --write.');
                }
                $result['bundle'] = $service->write($bundle, $bundleKey);
            } elseif (trim((string) $this->option('bundle')) !== '') {
                throw new \InvalidArgumentException('--bundle is only valid with --write.');
            }

            $output = $this->option('compact') ? $this->compact($result) : $result;
            if ($this->option('json')) {
                $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->info('Video render spec: PASS');
                $this->line('  Source: '.$result['source']);
                $this->line('  Scenes: '.count((array) $result['manifest']['scenes']));
                $this->line('  Renderer invoked: no');
                if (is_array($result['bundle'])) {
                    $this->line('  Bundle: storage/app/'.$result['bundle']['relative_directory']);
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema' => 'plos.video_render_spec_result.v1',
                    'status' => 'fail',
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @return array{artifact:array<string,mixed>,source:string} */
    private function loadInput(VideoRenderSpecService $service): array
    {
        $relative = trim((string) $this->option('artifact'));
        $example = (bool) $this->option('example');
        if (($relative === '') === (! $example)) {
            throw new \InvalidArgumentException('Provide exactly one of --example or --artifact.');
        }
        if ($example) {
            return ['artifact' => $service->exampleArtifact(), 'source' => 'built_in_example'];
        }
        if (str_starts_with($relative, '/') || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Artifact path must be storage/app-relative without ..');
        }

        $storageRoot = realpath(storage_path('app'));
        $path = realpath(storage_path('app/'.$relative));
        if ($storageRoot === false || $path === false || ! str_starts_with($path, $storageRoot.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Artifact must resolve inside storage/app.');
        }
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 262144) {
            throw new \InvalidArgumentException('Artifact must be readable JSON no larger than 256 KiB.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Artifact must be a valid JSON object.');
        }

        return ['artifact' => $decoded, 'source' => $relative];
    }

    /** @param array<string,mixed> $result */
    private function compact(array $result): array
    {
        unset($result['html'], $result['manifest']['scenes']);

        return $result;
    }
}
