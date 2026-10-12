<?php

namespace App\Services\UnifiedCli;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Explicit full-page rasterization; never replaces the original or queues input. */
final class CliPdfRendering
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly ?Closure $keeper = null,
    ) {}

    public function render(string $hash, string|array $pages, int $dpi): array
    {
        if ($dpi < 72 || $dpi > 1200 || ($pages !== 'all' && (! is_array($pages) || $pages === []
            || count(array_unique($pages, SORT_REGULAR)) !== count($pages)))) {
            throw new InvalidArgumentException('Select all or distinct PDF page numbers and an explicit resolution from 72 to 1200 DPI.');
        }
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (! is_int($page) || $page < 1) {
                    throw new InvalidArgumentException('PDF page numbers must be positive integers.');
                }
            }
        }
        $session = $this->store->session($this->sessionId);
        $source = null;
        foreach ($session['attachments'] ?? [] as $attachment) {
            if (($attachment['sha256'] ?? null) === $hash && ($attachment['mime_type'] ?? null) === 'application/pdf') {
                $source = $attachment;
                break;
            }
        }
        if ($source === null) {
            throw new RuntimeException('Select a captured PDF listed by /files in this session.');
        }
        $run = fn (array $command, int $timeout) => $this->artifacts->inspect($hash,
            fn ($path) => (new CliOfflineMediaProcess)->run($path, $command, $timeout, 2048, $this->keeper));
        $info = $run(['pdfinfo', '/input/source'], 30);
        $receipt = ['source_sha256' => $hash, 'method' => 'pdf-render', 'dpi' => $dpi,
            'requested_pages' => $pages, 'network' => 'offline', 'complete' => false, 'outputs' => [],
            'preflight' => $this->capture($hash, $info, 'PDF page inventory')];
        $this->store->recordEvent($this->sessionId, 'media_render_preflight', $receipt['preflight'] +
            ['source_sha256' => $hash, 'method' => 'pdf-render', 'requested_pages' => $pages, 'dpi' => $dpi]);
        if (! $info['success'] || ! preg_match('/^Pages:\s+([1-9][0-9]*)\s*$/m', $info['stdout'], $match)
            || ($count = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            $receipt['failure'] = 'PDF page inventory failed; complete available diagnostics are retained.';
            $this->store->recordEvent($this->sessionId, 'media_render', $receipt);

            return $receipt;
        }
        if (is_array($pages) && max($pages) > $count) {
            throw new InvalidArgumentException('A selected page exceeds the verified PDF page count. No pages were rendered.');
        }
        $receipt['page_count'] = $count;
        $selected = $pages === 'all' ? (function () use ($count) {
            for ($page = 1; $page <= $count; $page++) {
                yield $page;
            }
        })() : $pages;
        foreach ($selected as $page) {
            $result = $run(['pdftoppm', '-f', (string) $page, '-l', (string) $page,
                '-singlefile', '-r', (string) $dpi, '-png', '/input/source'], 120);
            $capture = $this->capture($hash, $result, 'PDF page '.$page.' at '.$dpi.' DPI');
            $dimensions = @getimagesizefromstring($result['stdout']);
            // Poppler may exit 0 with a 1x1 PNG after an allocation failure.
            // Diagnostics therefore require review even when a PNG was emitted.
            $valid = $result['success'] && $result['stderr'] === '' && ($dimensions[2] ?? null) === IMAGETYPE_PNG;
            $this->store->recordEvent($this->sessionId, 'media_render_page',
                ['source_sha256' => $hash, 'page' => $page, 'dpi' => $dpi, 'complete' => $valid] + $capture);
            if (! $valid) {
                $receipt['failure'] = 'Page '.$page.' failed, was interrupted, or emitted diagnostics requiring review. Earlier pages and complete available output are retained.';
                $receipt['failed_page'] = $page;
                break;
            }
            $output = $capture['output'] + ['name' => ($source['name'] ?? $hash).'.page-'.$page.'.'.$dpi.'dpi.png',
                'renderer' => $result['backend'] ?? 'pdftoppm',
                'page' => $page, 'dpi' => $dpi, 'width' => $dimensions[0], 'height' => $dimensions[1]];
            $receipt['outputs'][] = $output;
            // Persist each completed page so cancellation cannot lose prior work.
            $current = $this->store->session($this->sessionId);
            $current['attachments'][] = $output;
            $this->store->saveSession($current);
        }
        $receipt['complete'] = count($receipt['outputs']) === ($pages === 'all' ? $count : count($pages));
        $this->store->recordEvent($this->sessionId, 'media_render', $receipt);

        return $receipt;
    }

    private function capture(string $hash, array $result, string $operation): array
    {
        return ['success' => $result['success'], 'exit_code' => $result['exit_code'], 'interrupted' => $result['interrupted'],
            'output' => $this->artifacts->derived($hash, $result['stdout'], $operation),
            'diagnostics' => $this->artifacts->derived($hash, $result['stderr'], $operation.' stderr')]
            + array_intersect_key($result, array_flip(['custody_lost', 'backend', 'cleanup_complete', 'private_work_directory', 'memory_limit_mb', 'memory_limit_kind']));
    }
}
