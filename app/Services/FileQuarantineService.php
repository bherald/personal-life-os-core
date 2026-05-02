<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileQuarantineService
{
    public function quarantineFile(int $fileRegistryId, string $reason = 'manual', string $detectedBy = 'manual', ?array $details = null): int
    {
        $assetUuid = DB::selectOne(
            'SELECT asset_uuid FROM file_registry WHERE id = ?',
            [$fileRegistryId]
        );

        DB::insert(
            "INSERT INTO file_quarantine (file_registry_id, asset_uuid, reason, detected_by, details, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'quarantined', NOW(), NOW())",
            [$fileRegistryId, $assetUuid->asset_uuid ?? null, $reason, $detectedBy, $details ? json_encode($details) : null]
        );

        $id = (int) DB::getPdo()->lastInsertId();

        DB::update(
            "UPDATE file_registry SET quarantine_status = 'quarantined' WHERE id = ?",
            [$fileRegistryId]
        );

        Log::info('FileQuarantine: File quarantined', [
            'file_registry_id' => $fileRegistryId,
            'reason' => $reason,
            'detected_by' => $detectedBy,
        ]);

        return $id;
    }

    public function reviewFile(int $quarantineId, string $action, ?string $reviewedBy = null): bool
    {
        $quarantine = DB::selectOne('SELECT * FROM file_quarantine WHERE id = ?', [$quarantineId]);
        if (! $quarantine) {
            return false;
        }

        $newStatus = $action === 'release' ? 'released' : 'deleted';

        DB::update(
            'UPDATE file_quarantine SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$newStatus, $reviewedBy, $quarantineId]
        );

        if ($quarantine->file_registry_id) {
            $regStatus = $action === 'release' ? null : 'deleted';
            DB::update(
                'UPDATE file_registry SET quarantine_status = ? WHERE id = ?',
                [$regStatus, $quarantine->file_registry_id]
            );
        }

        Log::info('FileQuarantine: File reviewed', [
            'quarantine_id' => $quarantineId,
            'action' => $action,
            'reviewed_by' => $reviewedBy,
        ]);

        return true;
    }

    public function getQuarantinedFiles(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $params = [];
        $where = '';
        if ($status) {
            $where = 'WHERE fq.status = ?';
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;

        return DB::select(
            "SELECT fq.*, fr.current_path, fr.filename, fr.mime_type as file_mime_type
             FROM file_quarantine fq
             LEFT JOIN file_registry fr ON fr.id = fq.file_registry_id
             {$where}
             ORDER BY fq.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    public function scanForSuspicious(?string $path = null): array
    {
        $path ??= '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
        $suspicious = [];

        // Check for dual-extension files (e.g., .jpg.exe)
        $dualExt = DB::select(
            "SELECT id, current_path, filename, file_size
             FROM file_registry
             WHERE current_path LIKE ?
             AND filename REGEXP '\\\\.[a-z]{2,4}\\\\.[a-z]{2,4}$'
             AND quarantine_status IS NULL
             LIMIT 100",
            [$path.'%']
        );
        foreach ($dualExt as $file) {
            $suspicious[] = ['file' => $file, 'reason' => 'dual_extension'];
        }

        // Check for zero-byte files
        $zeroBytes = DB::select(
            'SELECT id, current_path, filename
             FROM file_registry
             WHERE current_path LIKE ?
             AND file_size = 0
             AND quarantine_status IS NULL
             LIMIT 100',
            [$path.'%']
        );
        foreach ($zeroBytes as $file) {
            $suspicious[] = ['file' => $file, 'reason' => 'zero_bytes'];
        }

        // Check for suspiciously large files (>10GB)
        $large = DB::select(
            'SELECT id, current_path, filename, file_size
             FROM file_registry
             WHERE current_path LIKE ?
             AND file_size > 10737418240
             AND quarantine_status IS NULL
             LIMIT 100',
            [$path.'%']
        );
        foreach ($large as $file) {
            $suspicious[] = ['file' => $file, 'reason' => 'oversized'];
        }

        return $suspicious;
    }

    public function getStats(): array
    {
        $byStatus = DB::select(
            'SELECT status, COUNT(*) as count FROM file_quarantine GROUP BY status'
        );
        $byReason = DB::select(
            'SELECT reason, COUNT(*) as count FROM file_quarantine GROUP BY reason'
        );

        return [
            'by_status' => array_column(array_map(fn ($r) => ['status' => $r->status, 'count' => $r->count], $byStatus), 'count', 'status'),
            'by_reason' => array_column(array_map(fn ($r) => ['reason' => $r->reason, 'count' => $r->count], $byReason), 'count', 'reason'),
            'total' => array_sum(array_column($byStatus, 'count')),
        ];
    }

    // =========================================================================
    // VIRUS SCANNING INTEGRATION
    // =========================================================================

    public function markVirusScanResult(int $fileRegistryId, bool $clean, ?string $scannerName = null, ?string $details = null): array
    {
        if ($clean) {
            // If previously quarantined, release it
            $quarantine = DB::selectOne(
                "SELECT id FROM file_quarantine WHERE file_registry_id = ? AND status = 'quarantined'",
                [$fileRegistryId]
            );

            if ($quarantine) {
                $this->reviewFile($quarantine->id, 'release', $scannerName ?? 'virus_scanner');

                return ['success' => true, 'action' => 'released', 'message' => 'File cleared by scanner'];
            }

            return ['success' => true, 'action' => 'none', 'message' => 'File is clean'];
        }

        // Quarantine the infected file
        $id = $this->quarantineFile($fileRegistryId, 'virus_detected', $scannerName ?? 'virus_scanner', [
            'scanner' => $scannerName,
            'details' => $details,
            'scanned_at' => now()->toIso8601String(),
        ]);

        return ['success' => true, 'action' => 'quarantined', 'quarantine_id' => $id];
    }

    public function getPendingReview(int $limit = 50): array
    {
        return DB::select(
            "SELECT fq.*, fr.current_path, fr.filename, fr.mime_type as file_mime_type, fr.file_size
             FROM file_quarantine fq
             LEFT JOIN file_registry fr ON fr.id = fq.file_registry_id
             WHERE fq.status = 'quarantined'
             ORDER BY fq.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function getRecentActivity(int $limit = 20): array
    {
        return DB::select(
            'SELECT fq.*, fr.filename
             FROM file_quarantine fq
             LEFT JOIN file_registry fr ON fr.id = fq.file_registry_id
             ORDER BY COALESCE(fq.reviewed_at, fq.created_at) DESC
             LIMIT ?',
            [$limit]
        );
    }
}
