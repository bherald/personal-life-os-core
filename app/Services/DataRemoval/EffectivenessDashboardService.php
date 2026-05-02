<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class EffectivenessDashboardService
{
    public function calculateEffectiveness(int $brokerId, string $periodStart, string $periodEnd): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(CASE WHEN status = 'confirmed' AND confirmed_at IS NOT NULL
                    THEN DATEDIFF(confirmed_at, created_at) ELSE NULL END) as avg_days,
                SUM(relisting_count) as total_relistings
             FROM removal_requests
             WHERE broker_id = ?
             AND created_at BETWEEN ? AND ?",
            [$brokerId, $periodStart, $periodEnd]
        );

        $total = $stats->total_requests ?? 0;
        $confirmed = $stats->confirmed ?? 0;
        $successRate = $total > 0 ? round(($confirmed / $total) * 100, 2) : null;

        // removal_effectiveness table dropped (D2 decision). No persistence.

        return [
            'broker_id' => $brokerId,
            'period' => "{$periodStart} to {$periodEnd}",
            'requests' => $total,
            'confirmed' => $confirmed,
            'failed' => $stats->failed ?? 0,
            'avg_days_to_removal' => round($stats->avg_days ?? 0, 1),
            'relistings' => $stats->total_relistings ?? 0,
            'success_rate' => $successRate,
        ];
    }

    public function generateReport(?string $startDate = null, ?string $endDate = null): array
    {
        $start = $startDate ?? date('Y-m-d', strtotime('-90 days'));
        $end = $endDate ?? date('Y-m-d');

        $overall = DB::selectOne(
            "SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                AVG(CASE WHEN status = 'confirmed' AND confirmed_at IS NOT NULL
                    THEN DATEDIFF(confirmed_at, created_at) ELSE NULL END) as avg_days
             FROM removal_requests
             WHERE created_at BETWEEN ? AND ?",
            [$start, $end]
        );

        $byBroker = DB::select(
            "SELECT db.name as broker_name, db.domain,
                    COUNT(*) as total,
                    SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    ROUND(SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as success_rate
             FROM removal_requests rr
             JOIN data_brokers db ON db.id = rr.broker_id
             WHERE rr.created_at BETWEEN ? AND ?
             GROUP BY db.id, db.name, db.domain
             ORDER BY success_rate DESC",
            [$start, $end]
        );

        $totalReq = $overall->total_requests ?? 0;
        $confirmed = $overall->confirmed ?? 0;

        return [
            'period' => ['start' => $start, 'end' => $end],
            'overall' => [
                'total_requests' => $totalReq,
                'confirmed' => $confirmed,
                'failed' => $overall->failed ?? 0,
                'pending' => $overall->pending ?? 0,
                'success_rate' => $totalReq > 0 ? round(($confirmed / $totalReq) * 100, 1) : 0,
                'avg_days_to_removal' => round($overall->avg_days ?? 0, 1),
            ],
            'by_broker' => $byBroker,
        ];
    }

    public function getTopPerformingBrokers(int $limit = 10): array
    {
        return DB::select(
            "SELECT db.name, db.domain,
                    COUNT(*) as total,
                    SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    ROUND(SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as success_rate,
                    AVG(CASE WHEN rr.status = 'confirmed' THEN DATEDIFF(rr.confirmed_at, rr.created_at) ELSE NULL END) as avg_days
             FROM removal_requests rr
             JOIN data_brokers db ON db.id = rr.broker_id
             GROUP BY db.id, db.name, db.domain
             HAVING COUNT(*) >= 3
             ORDER BY success_rate DESC, avg_days ASC
             LIMIT ?",
            [$limit]
        );
    }

    public function getWorstPerformingBrokers(int $limit = 10): array
    {
        return DB::select(
            "SELECT db.name, db.domain,
                    COUNT(*) as total,
                    SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                    ROUND(SUM(CASE WHEN rr.status = 'confirmed' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as success_rate,
                    AVG(CASE WHEN rr.status = 'confirmed' THEN DATEDIFF(rr.confirmed_at, rr.created_at) ELSE NULL END) as avg_days
             FROM removal_requests rr
             JOIN data_brokers db ON db.id = rr.broker_id
             GROUP BY db.id, db.name, db.domain
             HAVING COUNT(*) >= 3
             ORDER BY success_rate ASC, avg_days DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function getTrends(int $months = 6): array
    {
        return DB::select(
            "SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                ROUND(SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as success_rate
             FROM removal_requests
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month ASC",
            [$months]
        );
    }
}
