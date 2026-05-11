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
        private readonly ?HtrTranscriptionService $htr = null,
        private readonly ?GenealogyDocumentTextQualityGateService $qualityGate = null
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
            $sourceMethod = (string) ($result['method'] ?? 'unknown');
            $quality = $this->assessQuality($text, $document, $sourceMethod);
            if (($quality['allow_fact_extraction'] ?? false) || ! $this->shouldQuarantinePrimaryExtraction($document, $sourceMethod)) {
                return $this->successResult($text, $sourceMethod, $quality);
            }
        }

        $storedTranscript = $this->lookupStoredTranscript($sourcePath);
        if ($storedTranscript !== '') {
            $quality = $this->assessQuality($storedTranscript, $document, 'genealogy_media_transcription');
            if ($quality['allow_fact_extraction'] ?? false) {
                return $this->successResult($storedTranscript, 'genealogy_media_transcription', $quality);
            }
        }

        $htrTranscript = $this->extractHandwrittenText($document, $localPath);
        if ($htrTranscript !== '') {
            $quality = $this->assessQuality($htrTranscript, $document, 'htr_transcription');
            if ($quality['allow_fact_extraction'] ?? false) {
                return $this->successResult($htrTranscript, 'htr_transcription', $quality);
            }
        }

        $reason = (string) ($result['error'] ?? 'no_text_extracted');
        if (($result['success'] ?? false) && $text !== '') {
            $reason = 'low_quality_text_requires_field_review';
        }

        return $this->emptyResult($reason);
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

    private function successResult(string $text, string $sourceMethod, ?array $quality = null): array
    {
        $result = [
            'success' => true,
            'summary_base' => $this->summarizeText($text),
            'raw_text' => $text,
            'source_method' => $sourceMethod,
        ];

        if ($quality !== null) {
            $result['text_quality'] = $quality;
        }

        return $result;
    }

    private function htr(): HtrTranscriptionService
    {
        return $this->htr ?? app(HtrTranscriptionService::class);
    }

    private function qualityGate(): GenealogyDocumentTextQualityGateService
    {
        return $this->qualityGate ?? app(GenealogyDocumentTextQualityGateService::class);
    }

    private function assessQuality(string $text, array $document, string $sourceMethod): array
    {
        return $this->qualityGate()->assess($text, [
            'document_type' => (string) ($document['document_type'] ?? 'document'),
            'title' => (string) ($document['source_name'] ?? $document['name'] ?? ''),
            'source_method' => $sourceMethod,
        ]);
    }

    private function shouldQuarantinePrimaryExtraction(array $document, string $sourceMethod): bool
    {
        $documentType = strtolower(trim((string) ($document['document_type'] ?? '')));
        $method = strtolower(trim($sourceMethod));

        return in_array($method, ['tesseract', 'ocr', 'htr', 'htr_transcription', 'vision'], true)
            || in_array($documentType, ['image', 'certificate', 'vital_record'], true);
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
