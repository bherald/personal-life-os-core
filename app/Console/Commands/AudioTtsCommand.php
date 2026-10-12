<?php

namespace App\Console\Commands;

use App\Services\OfflineTtsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AudioTtsCommand extends Command
{
    protected $signature = 'audio:tts
        {text?* : Text to synthesize}
        {--text-b64= : UTF-8 text encoded as base64}
        {--output= : Output audio path for --execute}
        {--voice= : TTS voice override}
        {--model= : TTS model path/name override, required for piper}
        {--format=wav : Output format/extension for generated files}
        {--json : Emit machine-readable JSON}
        {--dry-run : Plan only; do not synthesize audio}
        {--execute : Actually synthesize audio}
        {--probe : Report TTS capability only}';

    protected $description = 'Probe or run local offline text-to-speech through a command sidecar adapter';

    public function handle(OfflineTtsService $tts): int
    {
        $decoded = $this->textInput();
        if ($decoded['error'] !== null) {
            return $this->finish([
                'schema' => 'plos_audio_tts.v1',
                'status' => 'invalid_input',
                'mode' => 'probe',
                'read_only' => true,
                'error' => $decoded['error'],
            ], self::FAILURE);
        }

        $text = trim((string) $decoded['text']);
        $options = array_filter([
            'voice' => (string) ($this->option('voice') ?: ''),
            'model' => (string) ($this->option('model') ?: ''),
        ], static fn (string $value): bool => $value !== '');
        $capability = $tts->capability($options);
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run') && ! (bool) $this->option('probe');

        if ((bool) $this->option('probe') || ($text === '' && ! $execute)) {
            return $this->finish([
                'schema' => 'plos_audio_tts.v1',
                'status' => $capability['status'],
                'mode' => 'probe',
                'read_only' => true,
                'capability' => $capability,
            ]);
        }

        if ($text === '') {
            return $this->finish([
                'schema' => 'plos_audio_tts.v1',
                'status' => 'invalid_input',
                'mode' => $execute ? 'execute' : 'dry_run',
                'read_only' => ! $execute,
                'capability' => $capability,
                'error' => 'Text is required unless --probe is used.',
            ], self::FAILURE);
        }

        $outputPath = $this->outputPath();
        $basePayload = [
            'schema' => 'plos_audio_tts.v1',
            'mode' => $execute ? 'execute' : 'dry_run',
            'read_only' => ! $execute,
            'text_sha256' => hash('sha256', $text),
            'text_bytes' => strlen($text),
            'output_path' => $outputPath,
            'capability' => $capability,
        ];

        if (! $execute) {
            return $this->finish($basePayload + [
                'status' => ($capability['status'] ?? '') === 'available' ? 'planned' : $capability['status'],
                'message' => 'Run with --execute to synthesize audio.',
            ]);
        }

        File::ensureDirectoryExists(dirname($outputPath));
        $result = $tts->synthesize($text, $outputPath, $options);

        return $this->finish($basePayload + $result, ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @return array{text:string,error:?string}
     */
    private function textInput(): array
    {
        $encoded = trim((string) ($this->option('text-b64') ?: ''));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                return ['text' => '', 'error' => '--text-b64 is not valid base64.'];
            }

            return ['text' => $decoded, 'error' => null];
        }

        $parts = $this->argument('text');

        return ['text' => trim(implode(' ', is_array($parts) ? $parts : [])), 'error' => null];
    }

    private function outputPath(): string
    {
        $output = trim((string) ($this->option('output') ?: ''));
        if ($output !== '') {
            return $output;
        }

        $format = preg_replace('/[^a-z0-9]/i', '', (string) ($this->option('format') ?: 'wav')) ?: 'wav';

        return storage_path('app/offline-tts/'.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.'.strtolower($format));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(($payload['schema'] ?? 'audio:tts').': '.($payload['status'] ?? 'unknown'));
        if (isset($payload['message'])) {
            $this->line((string) $payload['message']);
        }
        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exit;
    }
}
