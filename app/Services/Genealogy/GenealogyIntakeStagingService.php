<?php

namespace App\Services\Genealogy;

use App\Services\Genealogy\Support\GenealogyDocumentExtensions;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\Log;

class GenealogyIntakeStagingService
{
    // Extension allowlist unified through GenealogyDocumentExtensions
    // (union of config('file_types.document') and config('file_types.image')).

    public function __construct(
        private readonly NextcloudFileApiService $nextcloud
    ) {}

    public function stageScope(int $treeId, string $rootPath, int $limit = 100, array $options = []): array
    {
        $packetLabel = trim((string) ($options['packet_label'] ?? ''));
        $unprocessedOnly = (bool) ($options['unprocessed_only'] ?? false);

        $listing = $this->nextcloud->listFiles($rootPath, true, 300, $limit > 0 ? $limit * 5 : 0);
        if (! ($listing['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $listing['error'] ?? 'listing_failed',
                'root_path' => $rootPath,
            ];
        }

        // Partial-scan surfacing (Block 2 high-severity fix):
        // Filesystem/WebDAV scanners now report `complete`, `scan_errors`, `scan_truncated`
        // so staging never silently drops files. Legacy listFiles() responses without these
        // keys default to complete=true (back-compat with existing mocks/callers).
        $scanComplete = array_key_exists('complete', $listing)
            ? (bool) $listing['complete']
            : true;
        $scanErrors = array_values(array_map('strval', (array) ($listing['scan_errors'] ?? [])));
        $scanErrorsCount = (int) ($listing['scan_errors_count'] ?? count($scanErrors));
        $scanTruncated = (bool) ($listing['scan_truncated'] ?? false);
        $scanTruncatedReason = $listing['scan_truncated_reason'] ?? null;
        $symlinkLoopsSkipped = (int) ($listing['symlink_loops_skipped'] ?? 0);
        $scanSource = (string) ($listing['source'] ?? 'unknown');
        $partialScan = (!$scanComplete) || $scanTruncated || $scanErrorsCount > 0;

        $files = [];

        foreach ((array) ($listing['files'] ?? []) as $file) {
            if (! empty($file['is_directory'])) {
                continue;
            }

            $normalized = $this->normalizeFile((array) $file);
            if ($normalized === null) {
                continue;
            }

            if ($unprocessedOnly && ! empty($normalized['already_ingested'])) {
                continue;
            }

            $files[] = $normalized;
        }

        $files = array_slice($files, 0, max(0, $limit));
        $packets = $this->groupIntoPackets($files, $packetLabel);

        $warnings = [];
        if ($partialScan) {
            if ($scanTruncated) {
                $warnings[] = sprintf(
                    'Scan truncated (%s). Results are INCOMPLETE — some packets may be missing. Re-run with a larger limit/timeout or narrower scope.',
                    $scanTruncatedReason ?: 'unknown'
                );
            }
            if ($scanErrorsCount > 0) {
                $warnings[] = sprintf(
                    'Scanner hit %d per-entry error(s) (permission/unreadable/stat failures). Some files may be missing from staging.',
                    $scanErrorsCount
                );
            }
            if ($symlinkLoopsSkipped > 0) {
                $warnings[] = sprintf(
                    'Skipped %d symlink cycle(s) during scan. Review folder layout if this is unexpected.',
                    $symlinkLoopsSkipped
                );
            }

            Log::warning('GenealogyIntakeStaging: partial scan detected', [
                'tree_id' => $treeId,
                'root_path' => $rootPath,
                'scan_source' => $scanSource,
                'scan_complete' => $scanComplete,
                'scan_truncated' => $scanTruncated,
                'scan_truncated_reason' => $scanTruncatedReason,
                'scan_errors_count' => $scanErrorsCount,
                'symlink_loops_skipped' => $symlinkLoopsSkipped,
                'files_staged' => count($files),
            ]);
        }

        return [
            'success' => true,
            'run_key' => $this->buildRunKey($treeId, $rootPath, $packetLabel),
            'tree_id' => $treeId,
            'root_path' => $rootPath,
            'packet_label' => $packetLabel !== '' ? $packetLabel : null,
            'file_count' => count($files),
            'packet_count' => count($packets),
            'packets' => $packets,
            // Partial-scan surfacing (persisted into staged_snapshot JSON via RunStore).
            'partial_scan' => $partialScan,
            'scan_complete' => $scanComplete,
            'scan_truncated' => $scanTruncated,
            'scan_truncated_reason' => $scanTruncatedReason,
            'scan_errors' => $scanErrors,
            'scan_errors_count' => $scanErrorsCount,
            'symlink_loops_skipped' => $symlinkLoopsSkipped,
            'scan_source' => $scanSource,
            'warnings' => $warnings,
        ];
    }

    private function normalizeFile(array $file): ?array
    {
        $path = trim((string) ($file['path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $name = trim((string) ($file['name'] ?? basename($path)));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! GenealogyDocumentExtensions::isAllowed($extension)) {
            return null;
        }

        $mimeType = trim((string) ($file['mime_type'] ?? ''));
        $isPdf = $extension === 'pdf' || $mimeType === 'application/pdf';
        $isImage = str_starts_with($mimeType, 'image/') || GenealogyDocumentExtensions::isImage($extension);

        return [
            'path' => $path,
            'name' => $name,
            'folder' => dirname($path),
            'extension' => $extension,
            'size' => (int) ($file['size'] ?? 0),
            'mime_type' => $mimeType,
            'is_pdf' => $isPdf,
            'is_image' => $isImage,
            'already_ingested' => (bool) ($file['already_ingested'] ?? false),
        ];
    }

    private function groupIntoPackets(array $files, string $packetLabel): array
    {
        $grouped = [];

        foreach ($files as $file) {
            $packetKey = $this->resolvePacketKey($file, $packetLabel);
            if (! isset($grouped[$packetKey])) {
                $grouped[$packetKey] = [
                    'packet_key' => $packetKey,
                    'packet_label' => $this->buildPacketLabel($file, $packetLabel),
                    'packet_type' => $file['is_pdf'] ? 'single_document_packet' : 'folder_packet',
                    'folder' => $file['folder'],
                    'documents' => [],
                    'file_count' => 0,
                    'estimated_pages' => 0,
                ];
            }

            $grouped[$packetKey]['documents'][] = [
                'path' => $file['path'],
                'name' => $file['name'],
                'document_type' => $file['is_pdf'] ? 'pdf' : ($file['is_image'] ? 'image' : 'document'),
                'size' => $file['size'],
                'mime_type' => $file['mime_type'],
                'already_ingested' => $file['already_ingested'],
            ];
            $grouped[$packetKey]['file_count']++;
            $grouped[$packetKey]['estimated_pages'] += $this->estimatePages($file);
        }

        foreach ($grouped as &$packet) {
            usort($packet['documents'], static fn (array $a, array $b): int => [$a['path']] <=> [$b['path']]);
        }
        unset($packet);

        $packets = array_values($grouped);
        usort($packets, static fn (array $a, array $b): int => [$a['packet_label']] <=> [$b['packet_label']]);

        return $packets;
    }

    private function resolvePacketKey(array $file, string $packetLabel): string
    {
        if ($packetLabel !== '') {
            return sprintf('%s:%s', trim($file['folder'], '/'), strtolower($packetLabel));
        }

        if ($file['is_pdf']) {
            return 'pdf:'.$file['path'];
        }

        return 'folder:'.$file['folder'];
    }

    private function buildPacketLabel(array $file, string $packetLabel): string
    {
        if ($packetLabel !== '') {
            return $packetLabel;
        }

        if ($file['is_pdf']) {
            return pathinfo($file['name'], PATHINFO_FILENAME);
        }

        return basename($file['folder']) ?: trim($file['folder'], '/');
    }

    private function estimatePages(array $file): int
    {
        return $file['is_pdf'] ? 3 : 1;
    }

    private function buildRunKey(int $treeId, string $rootPath, string $packetLabel): string
    {
        $seed = implode('|', [$treeId, trim($rootPath), trim($packetLabel)]);

        return 'intake:'.substr(sha1($seed), 0, 12);
    }
}
