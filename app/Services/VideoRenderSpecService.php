<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class VideoRenderSpecService
{
    public const INPUT_SCHEMA = 'plos.video_recap_input.v1';

    public const BUNDLE_SCHEMA = 'plos.video_render_bundle.v1';

    /** @return array<string, mixed> */
    public function exampleArtifact(): array
    {
        return [
            'schema' => self::INPUT_SCHEMA,
            'title' => 'PLOS Daily Operations',
            'subtitle' => 'A deterministic, local recap bundle',
            'duration_seconds' => 30,
            'format' => 'landscape',
            'fps' => 30,
            'scenes' => [
                [
                    'kicker' => 'Health',
                    'heading' => 'Core checks passed',
                    'body' => 'Queues, scheduler, and storage checks completed without a critical finding.',
                ],
                [
                    'kicker' => 'Agents',
                    'heading' => 'Work stayed bounded',
                    'body' => 'Agent activity remained inside configured review and privacy gates.',
                ],
                [
                    'kicker' => 'Next',
                    'heading' => 'Review the evidence',
                    'body' => 'Use the source report for full detail before taking an operational action.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function build(array $artifact): array
    {
        $input = $this->validatedInput($artifact);
        $dimensions = $this->dimensions((string) $input['format']);
        $sceneDuration = round((float) $input['duration_seconds'] / count($input['scenes']), 3);
        $scenes = [];

        foreach ($input['scenes'] as $index => $scene) {
            $start = round($index * $sceneDuration, 3);
            $duration = $index === array_key_last($input['scenes'])
                ? round((float) $input['duration_seconds'] - $start, 3)
                : $sceneDuration;
            $scenes[] = $scene + [
                'id' => 'scene-'.($index + 1),
                'start_seconds' => $start,
                'duration_seconds' => $duration,
                'track_index' => $index,
            ];
        }

        $canonicalInput = $input;
        $canonicalInput['scenes'] = array_values($canonicalInput['scenes']);
        $sourceJson = json_encode($canonicalInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $manifest = [
            'schema' => self::BUNDLE_SCHEMA,
            'source_schema' => self::INPUT_SCHEMA,
            'source_sha256' => hash('sha256', $sourceJson),
            'entrypoint' => 'index.html',
            'expected_output' => 'output.mp4',
            'renderer' => [
                'name' => 'HyperFrames',
                'package' => 'hyperframes',
                'reviewed_release' => '0.7.57',
                'reviewed_revision' => 'e05debe1afadb802590ee8a58c55f7494a9229f1',
                'invocation_included' => false,
                'cloud_publish_allowed' => false,
            ],
            'composition' => [
                'id' => 'plos-recap',
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'fps' => $input['fps'],
                'duration_seconds' => $input['duration_seconds'],
                'frame_count' => (int) round($input['duration_seconds'] * $input['fps']),
            ],
            'privacy' => [
                'offline_assets_only' => true,
                'remote_urls_allowed' => false,
                'raw_source_included' => false,
                'operator_review_required_before_render' => true,
            ],
            'scenes' => $scenes,
        ];

        return [
            'manifest' => $manifest,
            'html' => $this->renderHtml($input, $manifest),
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function write(array $bundle, string $bundleKey): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,79}\z/', $bundleKey) !== 1) {
            throw new InvalidArgumentException('Bundle key must use 1-80 lowercase letters, numbers, underscores, or hyphens.');
        }

        $root = storage_path('app/video-render-bundles');
        $directory = $root.DIRECTORY_SEPARATOR.$bundleKey;
        if (File::exists($directory)) {
            throw new InvalidArgumentException('Bundle already exists; choose a new --bundle value.');
        }

        File::ensureDirectoryExists($root, 0750);
        $storageRoot = realpath(storage_path('app'));
        $resolvedRoot = realpath($root);
        if ($storageRoot === false || $resolvedRoot === false || ! str_starts_with($resolvedRoot.DIRECTORY_SEPARATOR, $storageRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Video render bundle root must resolve inside storage/app.');
        }
        if (! File::makeDirectory($directory, 0750)) {
            throw new RuntimeException('Could not create the video render bundle directory.');
        }

        try {
            $html = (string) ($bundle['html'] ?? '');
            $manifest = (array) ($bundle['manifest'] ?? []);
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            $htmlBytes = File::put($directory.DIRECTORY_SEPARATOR.'index.html', $html);
            $manifestBytes = File::put($directory.DIRECTORY_SEPARATOR.'render-spec.json', $manifestJson);
            if ($htmlBytes === false || $manifestBytes === false) {
                throw new RuntimeException('Could not write the complete video render bundle.');
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($directory);

            throw $e;
        }

        return [
            'written' => true,
            'relative_directory' => 'video-render-bundles/'.$bundleKey,
            'files' => [
                'index.html' => [
                    'bytes' => strlen($html),
                    'sha256' => hash('sha256', $html),
                ],
                'render-spec.json' => [
                    'bytes' => strlen($manifestJson),
                    'sha256' => hash('sha256', $manifestJson),
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $artifact */
    private function validatedInput(array $artifact): array
    {
        $this->rejectUnknownKeys($artifact, ['schema', 'title', 'subtitle', 'duration_seconds', 'format', 'fps', 'scenes'], 'artifact');
        if (($artifact['schema'] ?? null) !== self::INPUT_SCHEMA) {
            throw new InvalidArgumentException('Artifact schema must be '.self::INPUT_SCHEMA.'.');
        }

        $title = $this->boundedText($artifact['title'] ?? null, 'title', 1, 160);
        $subtitle = $this->boundedText($artifact['subtitle'] ?? '', 'subtitle', 0, 240);
        $duration = filter_var($artifact['duration_seconds'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 5 || $duration > 120) {
            throw new InvalidArgumentException('duration_seconds must be an integer from 5 through 120.');
        }

        $format = (string) ($artifact['format'] ?? 'landscape');
        if (! in_array($format, ['landscape', 'portrait', 'square'], true)) {
            throw new InvalidArgumentException('format must be landscape, portrait, or square.');
        }

        $fps = filter_var($artifact['fps'] ?? 30, FILTER_VALIDATE_INT);
        if ($fps === false || ! in_array($fps, [24, 25, 30, 60], true)) {
            throw new InvalidArgumentException('fps must be 24, 25, 30, or 60.');
        }

        $rawScenes = $artifact['scenes'] ?? null;
        if (! is_array($rawScenes) || ! array_is_list($rawScenes) || count($rawScenes) < 1 || count($rawScenes) > 12) {
            throw new InvalidArgumentException('scenes must be a list containing 1 through 12 items.');
        }

        $scenes = [];
        foreach ($rawScenes as $index => $scene) {
            if (! is_array($scene)) {
                throw new InvalidArgumentException('Each scene must be an object.');
            }
            $this->rejectUnknownKeys($scene, ['kicker', 'heading', 'body'], "scenes.{$index}");
            $scenes[] = [
                'kicker' => $this->boundedText($scene['kicker'] ?? '', "scenes.{$index}.kicker", 0, 60),
                'heading' => $this->boundedText($scene['heading'] ?? null, "scenes.{$index}.heading", 1, 100),
                'body' => $this->boundedText($scene['body'] ?? null, "scenes.{$index}.body", 1, 500),
            ];
        }

        return [
            'schema' => self::INPUT_SCHEMA,
            'title' => $title,
            'subtitle' => $subtitle,
            'duration_seconds' => $duration,
            'format' => $format,
            'fps' => $fps,
            'scenes' => $scenes,
        ];
    }

    private function boundedText(mixed $value, string $field, int $min, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} must be text.");
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException("{$field} must contain {$min} through {$max} characters.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("{$field} contains unsupported control characters.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $value, array $allowed, string $field): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            sort($unknown);
            throw new InvalidArgumentException($field.' contains unsupported fields: '.implode(', ', $unknown).'.');
        }
    }

    /** @return array{width:int,height:int} */
    private function dimensions(string $format): array
    {
        return match ($format) {
            'portrait' => ['width' => 1080, 'height' => 1920],
            'square' => ['width' => 1080, 'height' => 1080],
            default => ['width' => 1920, 'height' => 1080],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $manifest
     */
    private function renderHtml(array $input, array $manifest): string
    {
        $composition = (array) $manifest['composition'];
        $sceneHtml = '';
        $sceneCss = '';
        $totalDuration = (float) $composition['duration_seconds'];
        foreach ((array) $manifest['scenes'] as $index => $scene) {
            $startPercent = round(((float) $scene['start_seconds'] / $totalDuration) * 100, 4);
            $endPercent = round((((float) $scene['start_seconds'] + (float) $scene['duration_seconds']) / $totalDuration) * 100, 4);
            $visibleStart = min(100, $startPercent + 0.0001);
            $visibleEnd = max($visibleStart, $endPercent - 0.0001);
            $initialOpacity = $index === 0 ? 1 : 0;
            $finalOpacity = $index === array_key_last($manifest['scenes']) ? 1 : 0;
            $startFrames = $startPercent > 0
                ? sprintf('%s%% { opacity: 0; } %s%% { opacity: 1; } ', $startPercent, $visibleStart)
                : '';
            $endFrames = $endPercent < 100
                ? sprintf('%s%% { opacity: 1; } %s%% { opacity: 0; } ', $visibleEnd, $endPercent)
                : '';
            $sceneCss .= sprintf(
                "    #scene-%1\$d { animation-name: plos-scene-%1\$d; }\n    @keyframes plos-scene-%1\$d { 0%% { opacity: %2\$d; } %3\$s%4\$s100%% { opacity: %5\$d; } }\n",
                $index + 1,
                $initialOpacity,
                $startFrames,
                $endFrames,
                $finalOpacity,
            );
            $sceneHtml .= sprintf(
                "    <section id=\"%s\" class=\"clip scene\" data-start=\"%s\" data-duration=\"%s\" data-track-index=\"%d\">\n      <div class=\"scene-inner\"><p class=\"kicker\">%s</p><h2>%s</h2><p class=\"body\">%s</p></div>\n    </section>\n",
                $this->escape((string) $scene['id']),
                $this->escape((string) $scene['start_seconds']),
                $this->escape((string) $scene['duration_seconds']),
                (int) $scene['track_index'],
                $this->escape((string) $scene['kicker']),
                $this->escape((string) $scene['heading']),
                $this->escape((string) $scene['body']),
            );
        }

        $title = $this->escape((string) $input['title']);
        $subtitle = $this->escape((string) $input['subtitle']);
        $width = (int) $composition['width'];
        $height = (int) $composition['height'];
        $duration = $this->escape((string) $composition['duration_seconds']);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width={$width}, height={$height}">
  <meta name="plos-render-bundle" content="{$this->escape(self::BUNDLE_SCHEMA)}">
  <title>{$title}</title>
  <style>
    :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
    * { box-sizing: border-box; }
    html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; background: #101417; color: #f7f7f2; }
    #stage { position: relative; width: {$width}px; height: {$height}px; background: #101417; }
    .brand { position: absolute; z-index: 20; top: 54px; left: 64px; max-width: calc(100% - 128px); }
    .brand h1 { margin: 0; font-size: 34px; line-height: 1.1; font-weight: 720; letter-spacing: 0; }
    .brand p { margin: 10px 0 0; color: #b9c2c7; font-size: 20px; letter-spacing: 0; }
    .scene { position: absolute; inset: 0; display: grid; place-items: center; padding: 150px 9% 90px; opacity: 0; animation-duration: {$duration}s; animation-timing-function: linear; animation-fill-mode: both; animation-play-state: paused; }
{$sceneCss}
    .scene-inner { width: min(1180px, 100%); border-left: 10px solid #e6c84f; padding: 18px 0 24px 42px; }
    .kicker { margin: 0 0 20px; color: #7ed7c4; font-size: 24px; font-weight: 700; text-transform: uppercase; letter-spacing: 0; }
    h2 { margin: 0; max-width: 1100px; font-size: 76px; line-height: 1.04; letter-spacing: 0; overflow-wrap: anywhere; }
    .body { margin: 30px 0 0; max-width: 1050px; color: #dce2e5; font-size: 34px; line-height: 1.35; letter-spacing: 0; overflow-wrap: anywhere; }
    .footer { position: absolute; z-index: 20; right: 64px; bottom: 48px; color: #8f9aa0; font-size: 18px; }
  </style>
</head>
<body>
  <main id="stage" data-composition-id="plos-recap" data-start="0" data-duration="{$duration}" data-width="{$width}" data-height="{$height}">
    <header class="brand"><h1>{$title}</h1><p>{$subtitle}</p></header>
{$sceneHtml}    <footer class="footer">PLOS local render bundle</footer>
  </main>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
