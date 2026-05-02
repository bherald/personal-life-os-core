<?php

namespace App\Services\Genealogy;

use App\Services\NextcloudFileApiService;

class GenealogyIntakePacketRegistryService
{
    public function __construct(
        private readonly NextcloudFileApiService $nextcloud,
        private readonly GenealogyIntakeDocumentClassifierService $documentClassifier = new GenealogyIntakeDocumentClassifierService
    ) {}

    public function planPacketRegistration(array $packet, array $context = []): array
    {
        $packetLabel = trim((string) ($packet['packet_label'] ?? 'Untitled packet'));
        $packetKey = trim((string) ($packet['packet_key'] ?? $packetLabel));
        $rootPath = trim((string) ($context['root_path'] ?? $packet['folder'] ?? ''));
        $copyRoot = $this->resolveCopyRoot($rootPath, $packet, $context);
        $documents = [];

        foreach (array_values((array) ($packet['documents'] ?? [])) as $documentIndex => $document) {
            $documents[] = $this->planDocumentRegistration(
                (array) $document,
                $packet,
                $copyRoot,
                $documentIndex + 1
            );
        }

        return [
            'packet_id' => 'pkt:'.substr(sha1($packetKey.'|'.$packetLabel), 0, 12),
            'packet_key' => $packetKey !== '' ? $packetKey : null,
            'packet_label' => $packetLabel,
            'packet_type' => $packet['packet_type'] ?? 'packet',
            'root_path' => $rootPath !== '' ? $rootPath : null,
            'reference_copy_root' => $copyRoot,
            'copy_status' => $this->summarizeCopyStatus($documents),
            'document_count' => count($documents),
            'documents' => $documents,
        ];
    }

    private function planDocumentRegistration(array $document, array $packet, string $copyRoot, int $documentNumber): array
    {
        $path = trim((string) ($document['path'] ?? ''));
        $name = trim((string) ($document['name'] ?? basename($path) ?: sprintf('document-%d', $documentNumber)));
        $documentId = 'doc:'.substr(sha1(($packet['packet_key'] ?? '').'|'.$path.'|'.$name), 0, 12);
        $pageCount = $this->resolvePageCount($document);
        $copyPath = rtrim($copyRoot, '/').'/'.$this->sanitizeFileName($name);
        $duplicateScope = str_contains($path, '/FT/') ? 'ft_self_contained' : 'external_master_or_other';
        $copyPlan = $this->buildCopyPlan($path, $copyPath, $name);
        $classificationInput = [
            'duplicate_scope' => $duplicateScope,
            'already_ingested' => (bool) ($document['already_ingested'] ?? false),
            'copy_plan' => $copyPlan,
            'source_path' => $path,
            'reference_copy_path' => $copyPath,
        ];
        $documentClassificationDetail = $this->documentClassifier->classifyDetailed($classificationInput);
        $documentClassification = (string) ($documentClassificationDetail['primary_classification'] ?? $this->documentClassifier->classify($classificationInput));

        $pages = [];
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $pages[] = [
                'page_id' => sprintf('%s:p%d', $documentId, $pageNumber),
                'page_number' => $pageNumber,
                'anchor_label' => $pageCount > 1 ? sprintf('%s page %d', $name, $pageNumber) : $name,
                'document_id' => $documentId,
                'document_type' => $document['document_type'] ?? 'document',
                'source_name' => $name,
                'source_path' => $path !== '' ? $path : null,
                'reference_copy_path' => $copyPath,
                'duplicate_scope' => $duplicateScope,
                'document_classification' => $documentClassification,
                'document_classification_detail' => $documentClassificationDetail,
                'copy_status' => $copyPlan['status'] ?? 'ready',
            ];
        }

        return [
            'document_id' => $documentId,
            'source_path' => $path !== '' ? $path : null,
            'source_name' => $name,
            'document_type' => $document['document_type'] ?? 'document',
            'reference_copy_path' => $copyPath,
            'copy_plan' => $copyPlan,
            'duplicate_scope' => $duplicateScope,
            'classification' => $documentClassification,
            'classification_detail' => $documentClassificationDetail,
            'already_ingested' => (bool) ($document['already_ingested'] ?? false),
            'page_count' => $pageCount,
            'pages' => $pages,
        ];
    }

    private function resolveCopyRoot(string $rootPath, array $packet, array $context): string
    {
        $explicit = trim((string) ($context['reference_copy_root'] ?? ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $packetLabel = $this->sanitizePathSegment((string) ($packet['packet_label'] ?? 'packet'));

        if ($rootPath !== '' && $this->isFtLocalPath($rootPath)) {
            return rtrim($rootPath, '/').'/__intake/'.$packetLabel;
        }

        $folder = trim((string) ($packet['folder'] ?? ''));
        if ($folder !== '' && $this->isFtLocalPath($folder)) {
            return rtrim($folder, '/').'/__intake/'.$packetLabel;
        }

        return $this->defaultFtReferenceRoot().'/'.$packetLabel;
    }

    private function defaultFtReferenceRoot(): string
    {
        return '/'.trim((string) config('genealogy.ft_reference_root', '/Library/FamilyTree/__intake'), '/');
    }

    private function isFtLocalPath(string $path): bool
    {
        return str_contains($path, '/FT/');
    }

    private function resolvePageCount(array $document): int
    {
        $pages = (int) ($document['estimated_pages'] ?? 0);
        if ($pages > 0) {
            return $pages;
        }

        return ($document['document_type'] ?? null) === 'pdf' ? 3 : 1;
    }

    private function sanitizePathSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'packet';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? 'packet';
        $value = trim($value, '-_.');

        return $value !== '' ? $value : 'packet';
    }

    private function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[?#].*$/', '', $name) ?: 'document';

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = $this->sanitizePathSegment($base);

        if ($extension === '') {
            return $base;
        }

        return $base.'.'.strtolower($extension);
    }

    private function buildCopyPlan(string $sourcePath, string $referenceCopyPath, string $sourceName = ''): array
    {
        if ($sourcePath === '') {
            return [
                'status' => 'missing_source_path',
                'reason' => 'No source path was provided for this staged document.',
                'exists' => false,
                'matches_source' => false,
            ];
        }

        if ($sourcePath === $referenceCopyPath) {
            return [
                'status' => 'already_in_place',
                'reason' => 'Source file already lives at the planned FT reference-copy path.',
                'exists' => true,
                'matches_source' => true,
            ];
        }

        $info = $this->nextcloud->getFileInfo($referenceCopyPath);
        if (! ($info['success'] ?? false)) {
            return [
                'status' => 'ready',
                'reason' => 'Reference-copy target does not exist yet.',
                'exists' => false,
                'matches_source' => false,
            ];
        }

        if (empty($info['size'])) {
            return [
                'status' => 'ready',
                'reason' => 'Reference-copy target exists but is empty (likely incomplete previous copy).',
                'exists' => true,
                'matches_source' => false,
            ];
        }

        $matchesSource = $this->pathsLikelyMatch($sourcePath, $referenceCopyPath, $info, $sourceName);

        return [
            'status' => $matchesSource ? 'already_in_place' : 'conflict',
            'reason' => $matchesSource
                ? 'Reference-copy target already exists and appears to represent the same source document.'
                : 'Reference-copy target already exists with a different source path and needs human review.',
            'exists' => true,
            'matches_source' => $matchesSource,
            'existing_size' => $info['size'] ?? null,
            'existing_mime_type' => $info['mime_type'] ?? null,
        ];
    }

    private function pathsLikelyMatch(string $sourcePath, string $referenceCopyPath, array $info, string $sourceName = ''): bool
    {
        if ($sourcePath === $referenceCopyPath) {
            return true;
        }

        $copyBasename = strtolower((string) pathinfo($referenceCopyPath, PATHINFO_BASENAME));

        // When the document's intended name is known, compare its sanitized form against
        // the reference-copy basename (which was derived from that same name). This handles
        // the common case where an external scan has a generic filename (e.g. scan001.jpg)
        // but was staged under an explicit descriptive name (e.g. Census-1880-page-1.jpg):
        // after the first copy execution the re-plan must recognise the copy as in-place
        // rather than raising a false conflict on the mismatched source basename.
        if ($sourceName !== '') {
            $sanitizedName = strtolower($this->sanitizeFileName($sourceName));

            return $sanitizedName !== '' && $sanitizedName === $copyBasename && ! empty($info['size']);
        }

        $sourceBasename = strtolower((string) pathinfo($sourcePath, PATHINFO_BASENAME));

        return $sourceBasename !== '' && $sourceBasename === $copyBasename && ! empty($info['size']);
    }

    private function summarizeCopyStatus(array $documents): string
    {
        $statuses = array_map(
            static fn (array $document): string => (string) (($document['copy_plan']['status'] ?? 'ready')),
            $documents
        );

        if (in_array('conflict', $statuses, true)) {
            return 'conflict';
        }

        if ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'already_in_place') {
            return 'already_in_place';
        }

        if (in_array('missing_source_path', $statuses, true)) {
            return 'needs_review';
        }

        return 'ready';
    }
}
