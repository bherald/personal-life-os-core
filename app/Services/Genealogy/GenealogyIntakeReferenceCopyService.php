<?php

namespace App\Services\Genealogy;

use App\Services\NextcloudFileApiService;

class GenealogyIntakeReferenceCopyService
{
    public function __construct(
        private readonly NextcloudFileApiService $nextcloud
    ) {}

    public function executeRegistrationPlan(array $registration, bool $apply = false): array
    {
        $documents = array_values((array) ($registration['documents'] ?? []));
        $results = [];
        $summary = [
            'copied' => 0,
            'already_in_place' => 0,
            'blocked_conflicts' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($documents as $document) {
            $result = $this->processDocument((array) $document, $apply);
            $results[] = $result;

            match ($result['action']) {
                'copied' => $summary['copied']++,
                'already_in_place' => $summary['already_in_place']++,
                'blocked_conflict' => $summary['blocked_conflicts']++,
                'failed' => $summary['failed']++,
                default => $summary['skipped']++,
            };
        }

        return [
            'success' => $summary['failed'] === 0 && $summary['blocked_conflicts'] === 0,
            'apply' => $apply,
            'summary' => $summary,
            'results' => $results,
        ];
    }

    private function processDocument(array $document, bool $apply): array
    {
        $copyPlan = (array) ($document['copy_plan'] ?? []);
        $status = (string) ($copyPlan['status'] ?? 'ready');
        $sourcePath = trim((string) ($document['source_path'] ?? ''));
        $targetPath = trim((string) ($document['reference_copy_path'] ?? ''));
        $base = [
            'document_id' => $document['document_id'] ?? null,
            'source_name' => $document['source_name'] ?? 'document',
            'source_path' => $sourcePath !== '' ? $sourcePath : null,
            'reference_copy_path' => $targetPath !== '' ? $targetPath : null,
            'copy_status' => $status,
        ];

        if ($status === 'conflict') {
            return $base + [
                'action' => 'blocked_conflict',
                'success' => false,
                'message' => $copyPlan['reason'] ?? 'Copy target conflict requires human review.',
            ];
        }

        if ($status === 'already_in_place') {
            return $base + [
                'action' => 'already_in_place',
                'success' => true,
                'message' => $copyPlan['reason'] ?? 'Reference copy already exists.',
            ];
        }

        if ($status !== 'ready') {
            return $base + [
                'action' => 'skipped',
                'success' => false,
                'message' => $copyPlan['reason'] ?? 'Document is not ready for copy.',
            ];
        }

        if (! $apply) {
            return $base + [
                'action' => 'would_copy',
                'success' => true,
                'message' => 'Reference copy target is ready.',
            ];
        }

        if ($sourcePath === '' || $targetPath === '') {
            return $base + [
                'action' => 'failed',
                'success' => false,
                'message' => 'Missing source or target path for copy operation.',
            ];
        }

        $copy = $this->nextcloud->copyFile($sourcePath, $targetPath);
        if (! ($copy['success'] ?? false)) {
            return $base + [
                'action' => 'failed',
                'success' => false,
                'message' => $copy['error'] ?? 'Copy failed.',
            ];
        }

        return $base + [
            'action' => 'copied',
            'success' => true,
            'message' => 'Reference copy created.',
        ];
    }
}
