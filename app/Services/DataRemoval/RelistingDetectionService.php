<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class RelistingDetectionService
{
    public function scanForRelisting(int $subjectId): array
    {
        // Get previously completed removal requests for this subject
        $completed = DB::select(
            "SELECT rr.id, rr.broker_id, db.name as broker_name, db.domain, db.removal_url,
                    rr.status, rr.confirmed_at
             FROM removal_requests rr
             JOIN data_brokers db ON db.id = rr.broker_id
             WHERE rr.subject_id = ?
             AND rr.status = 'confirmed'
             ORDER BY rr.confirmed_at DESC",
            [$subjectId]
        );

        $results = ['scanned' => 0, 'relistings' => 0, 'details' => []];

        foreach ($completed as $request) {
            $results['scanned']++;

            // Check if broker has re-listed (would need actual scraping in production)
            // For now, track the framework for manual/automated verification
            $lastVerification = DB::selectOne(
                "SELECT last_verification_at FROM removal_requests WHERE id = ?",
                [$request->id]
            );

            if ($lastVerification && $lastVerification->last_verification_at) {
                $daysSinceVerification = (time() - strtotime($lastVerification->last_verification_at)) / 86400;
                if ($daysSinceVerification < 30) {
                    continue; // Skip if verified recently
                }
            }

            // Mark as needing verification
            $results['details'][] = [
                'request_id' => $request->id,
                'broker' => $request->broker_name,
                'domain' => $request->domain,
                'completed_at' => $request->confirmed_at,
                'needs_verification' => true,
            ];
        }

        return $results;
    }

    public function handleRelisting(int $requestId, int $brokerId): array
    {
        // Increment relisting count on original request
        DB::update(
            "UPDATE removal_requests SET relisting_count = relisting_count + 1, relisting_detected_at = NOW() WHERE id = ?",
            [$requestId]
        );

        // Get original request details
        $original = DB::selectOne(
            "SELECT subject_id, broker_id FROM removal_requests WHERE id = ?",
            [$requestId]
        );

        if (!$original) {
            return ['success' => false, 'error' => 'Original request not found'];
        }

        // Create new removal request
        DB::insert(
            "INSERT INTO removal_requests (subject_id, broker_id, status, automation_tier, ai_notes, created_at, updated_at)
             VALUES (?, ?, 'pending', 2, ?, NOW(), NOW())",
            [
                $original->subject_id,
                $brokerId,
                "Re-listing detected. Follow-up to request #{$requestId}.",
            ]
        );

        $newId = (int) DB::getPdo()->lastInsertId();

        Log::warning('RelistingDetection: Re-listing detected', [
            'original_request' => $requestId,
            'broker_id' => $brokerId,
            'new_request' => $newId,
        ]);

        return ['success' => true, 'new_request_id' => $newId, 'original_request_id' => $requestId];
    }

    public function getRelistingReport(?string $startDate = null, ?string $endDate = null): array
    {
        $params = [];
        $dateFilter = '';
        if ($startDate && $endDate) {
            $dateFilter = 'AND rr.relisting_detected_at BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        }

        $relistings = DB::select(
            "SELECT db.name as broker_name, db.domain,
                    COUNT(*) as relisting_count,
                    MAX(rr.relisting_detected_at) as last_relisting
             FROM removal_requests rr
             JOIN data_brokers db ON db.id = rr.broker_id
             WHERE rr.relisting_count > 0
             {$dateFilter}
             GROUP BY db.id, db.name, db.domain
             ORDER BY relisting_count DESC",
            $params
        );

        $total = array_sum(array_column($relistings, 'relisting_count'));

        return [
            'total_relistings' => $total,
            'brokers_relisting' => count($relistings),
            'by_broker' => $relistings,
        ];
    }

    public function markVerified(int $requestId): void
    {
        DB::update(
            "UPDATE removal_requests SET last_verification_at = NOW() WHERE id = ?",
            [$requestId]
        );
    }
}
