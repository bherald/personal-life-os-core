<?php

namespace App\Services\UnifiedCli;

use App\Services\LocalTextExtractionService;
use Closure;
use RuntimeException;

/** Explicit offline extraction of a captured artifact; originals stay read-only. */
final class CliLocalTextExtraction
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly ?Closure $keeper = null,
    ) {}

    public function extract(string $hash, string $method, string $language = 'eng'): array
    {
        $session = $this->store->session($this->sessionId);
        $attachments = array_values(array_filter($session['attachments'] ?? [], fn ($item) => ($item['sha256'] ?? null) === $hash));
        if ($attachments === []) {
            throw new RuntimeException('Select an artifact listed by /files in this session.');
        }
        $source = $attachments[0];
        if (($method === 'pdf' && ($source['mime_type'] ?? '') !== 'application/pdf')
            || ($method === 'ocr' && ! in_array($source['mime_type'] ?? '',
                ['image/png', 'image/jpeg', 'image/tiff', 'image/bmp', 'image/webp', 'image/x-ms-bmp', 'image/x-portable-pixmap'], true))) {
            throw new RuntimeException('Selected extractor does not accept this captured media type. No conversion was attempted.');
        }
        $result = $this->artifacts->inspect($hash, function ($path) use ($method, $language): array {
            return (new LocalTextExtractionService)->extract($method, '/input/source', $language,
                fn ($command, $timeout) => (new CliOfflineMediaProcess)->run($path, $command, $timeout, keeper: $this->keeper));
        });
        $operation = 'PLOS local '.$method.' extraction'.($method === 'ocr' ? ' language='.$language : '');
        $output = $this->artifacts->derived($hash, $result['stdout'], $operation);
        $diagnostics = $this->artifacts->derived($hash, $result['stderr'], $operation.' stderr');
        $output['name'] = ($source['name'] ?? $hash).'.'.$method.'.txt';
        $receipt = ['source_sha256' => $hash, 'method' => $method, 'language' => $method === 'ocr' ? $language : null,
            'success' => $result['success'], 'exit_code' => $result['exit_code'], 'interrupted' => $result['interrupted'],
            'network' => 'offline', 'output' => $output, 'diagnostics' => $diagnostics];
        $receipt += array_intersect_key($result, array_flip(['custody_lost', 'backend', 'cleanup_complete', 'private_work_directory', 'memory_limit_mb', 'memory_limit_kind']));
        $this->store->recordEvent($this->sessionId, 'media_extraction', $receipt);
        if (! $result['success']) {
            throw new RuntimeException('Local extraction failed or was interrupted. Complete available output and diagnostics remain in the media_extraction event; no fallback or queue change occurred.');
        }

        return $output;
    }
}
