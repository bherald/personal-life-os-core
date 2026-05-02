<?php

namespace App\Services\Genealogy;

use App\Services\ContentExtractionService;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\DB;

class GenealogyIntakePageTextExtractionService
{
    public function __construct(
        private readonly ContentExtractionService $contentExtraction,
        private readonly NextcloudFileApiService $nextcloud,
        private readonly ?HtrTranscriptionService $htr = null
    ) {}

    public function extractDocumentText(array $document): array
    {
        $sourcePath = trim((string) ($document['source_path'] ?? ''));
        if ($sourcePath === '') {
            return $this->emptyResult('missing_source_path');
        }

        $localPath = $this->resolveReadablePath($sourcePath);
        if ($localPath === null) {
            return $this->emptyResult('local_path_unavailable');
        }

        $result = $this->contentExtraction->extract($localPath, [
            'extract_entities' => false,
            'extract_faces' => false,
        ]);

        $text = $this->normalizeText((string) ($result['text'] ?? ''));
        if (($result['success'] ?? false) && $text !== '') {
            return [
                'success' => true,
                'summary_base' => $this->summarizeText($text),
                'raw_text' => $text,
                'source_method' => (string) ($result['method'] ?? 'unknown'),
            ];
        }

        $storedTranscript = $this->lookupStoredTranscript($sourcePath);
        if ($storedTranscript !== '') {
            return $this->successResult($storedTranscript, 'genealogy_media_transcription');
        }

        $htrTranscript = $this->extractHandwrittenText($document, $localPath);
        if ($htrTranscript !== '') {
            return $this->successResult($htrTranscript, 'htr_transcription');
        }

        return $this->emptyResult((string) ($result['error'] ?? 'no_text_extracted'));
    }

    private function resolveReadablePath(string $sourcePath): ?string
    {
        $localPath = $this->nextcloud->localPath($sourcePath);
        if ($localPath) {
            return $localPath;
        }

        if (str_starts_with($sourcePath, '/') && file_exists($sourcePath)) {
            return $sourcePath;
        }

        return null;
    }

    private function summarizeText(string $text): string
    {
        $excerpt = mb_substr($text, 0, 360);

        return rtrim($excerpt, " \t\n\r\0\x0B.;,:").(mb_strlen($text) > 360 ? '…' : '');
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return trim($text);
    }

    private function lookupStoredTranscript(string $sourcePath): string
    {
        if ($sourcePath === '' || ! str_starts_with($sourcePath, '/')) {
            return '';
        }

        $row = DB::selectOne(
            'SELECT transcription_text, transcription
             FROM genealogy_media
             WHERE nextcloud_path = ?
             LIMIT 1',
            [$sourcePath]
        );

        if (! $row) {
            return '';
        }

        return $this->normalizeText((string) ($row->transcription_text ?? $row->transcription ?? ''));
    }

    private function extractHandwrittenText(array $document, string $localPath): string
    {
        if (! $this->isHandwritingEligible($document, $localPath)) {
            return '';
        }

        $result = $this->htr()->transcribe($localPath);

        return $this->normalizeText((string) ($result['text'] ?? ''));
    }

    private function isHandwritingEligible(array $document, string $localPath): bool
    {
        $documentType = strtolower(trim((string) ($document['document_type'] ?? '')));
        if ($documentType === 'image') {
            return true;
        }

        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp', 'gif'], true);
    }

    private function successResult(string $text, string $sourceMethod): array
    {
        return [
            'success' => true,
            'summary_base' => $this->summarizeText($text),
            'raw_text' => $text,
            'source_method' => $sourceMethod,
        ];
    }

    private function htr(): HtrTranscriptionService
    {
        return $this->htr ?? app(HtrTranscriptionService::class);
    }

    private function emptyResult(string $reason): array
    {
        return [
            'success' => false,
            'summary_base' => '',
            'raw_text' => '',
            'source_method' => $reason,
        ];
    }
}
