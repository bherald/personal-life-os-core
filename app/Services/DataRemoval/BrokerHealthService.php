<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BrokerHealthService
{
    public function checkBrokerHealth(int $brokerId): array
    {
        $broker = DB::selectOne("SELECT * FROM data_brokers WHERE id = ?", [$brokerId]);
        if (!$broker) {
            return ['success' => false, 'error' => 'Broker not found'];
        }

        $optoutUrl = $broker->removal_url ?? null;
        if (!$optoutUrl) {
            return ['success' => false, 'error' => 'No opt-out URL configured'];
        }

        $startTime = microtime(true);
        $response = null;
        $httpCode = 0;
        $error = null;

        try {
            $httpResponse = Http::connectTimeout(5)->timeout(15)
                ->withUserAgent('Mozilla/5.0 (compatible; HealthCheck/1.0)')
                ->get($optoutUrl);
            $response = $httpResponse->body();
            $httpCode = $httpResponse->status();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Determine status
        $status = 'healthy';
        if ($error || $httpCode === 0) {
            $status = 'broken';
        } elseif ($httpCode >= 500) {
            $status = 'broken';
        } elseif ($httpCode >= 400) {
            $status = 'degraded';
        }

        // Check for page content changes
        if ($response && $status === 'healthy') {
            $pageHash = hash('sha256', $response);
            if ($broker->optout_page_hash && $broker->optout_page_hash !== $pageHash) {
                $status = 'changed';
            }
            DB::update("UPDATE data_brokers SET optout_page_hash = ? WHERE id = ?", [$pageHash, $brokerId]);
        }

        // Check for form presence (basic)
        $hasForm = $response && (stripos($response, '<form') !== false);
        $details = [
            'has_form' => $hasForm,
            'error' => $error ?: null,
            'content_length' => strlen($response ?: ''),
        ];

        // Record health check
        DB::insert(
            "INSERT INTO broker_health_checks (data_broker_id, check_type, status, response_code, response_time_ms, details, checked_at)
             VALUES (?, 'optout_page', ?, ?, ?, ?, NOW())",
            [$brokerId, $status, $httpCode, $responseTimeMs, json_encode($details)]
        );

        // Update broker status
        DB::update(
            "UPDATE data_brokers SET health_status = ?, last_health_check = NOW() WHERE id = ?",
            [$status, $brokerId]
        );

        return [
            'success' => true,
            'broker_id' => $brokerId,
            'status' => $status,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'has_form' => $hasForm,
        ];
    }

    public function batchHealthCheck(int $limit = 20): array
    {
        $brokers = DB::select(
            "SELECT id, name, removal_url FROM data_brokers
             WHERE removal_url IS NOT NULL AND removal_url != ''
             ORDER BY last_health_check IS NULL DESC, last_health_check ASC
             LIMIT ?",
            [$limit]
        );

        $results = ['checked' => 0, 'healthy' => 0, 'degraded' => 0, 'broken' => 0, 'changed' => 0];

        foreach ($brokers as $broker) {
            $result = $this->checkBrokerHealth($broker->id);
            $results['checked']++;
            if (isset($result['status'])) {
                $results[$result['status']] = ($results[$result['status']] ?? 0) + 1;
            }
            usleep(500000); // 0.5s delay between checks
        }

        return $results;
    }

    public function detectPageChanges(int $brokerId): array
    {
        $recent = DB::select(
            "SELECT status, details, checked_at FROM broker_health_checks
             WHERE data_broker_id = ? ORDER BY checked_at DESC LIMIT 5",
            [$brokerId]
        );

        $changes = [];
        foreach ($recent as $check) {
            if ($check->status === 'changed') {
                $changes[] = [
                    'checked_at' => $check->checked_at,
                    'details' => json_decode($check->details, true),
                ];
            }
        }

        return [
            'broker_id' => $brokerId,
            'recent_checks' => count($recent),
            'changes_detected' => count($changes),
            'changes' => $changes,
        ];
    }

    public function getHealthReport(): array
    {
        $byStatus = DB::select(
            "SELECT health_status, COUNT(*) as count FROM data_brokers
             WHERE health_status IS NOT NULL GROUP BY health_status"
        );

        $avgResponseTime = DB::selectOne(
            "SELECT AVG(response_time_ms) as avg_ms FROM broker_health_checks
             WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'by_status' => array_column(
                array_map(fn($r) => ['status' => $r->health_status, 'count' => $r->count], $byStatus),
                'count', 'status'
            ),
            'avg_response_time_ms' => round($avgResponseTime->avg_ms ?? 0),
        ];
    }

    public function getUnhealthyBrokers(): array
    {
        return DB::select(
            "SELECT id, name, domain, health_status, last_health_check
             FROM data_brokers
             WHERE health_status IN ('degraded', 'broken')
             ORDER BY health_status ASC, name ASC"
        );
    }
}
