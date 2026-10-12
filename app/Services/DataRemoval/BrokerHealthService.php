<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BrokerHealthService
{
    public function __construct(private readonly BrokerHealthPolicyService $brokerHealthPolicy) {}

    public function checkBrokerHealth(int $brokerId): array
    {
        $broker = DB::selectOne('SELECT * FROM data_brokers WHERE id = ?', [$brokerId]);
        if (! $broker) {
            return ['success' => false, 'error' => 'Broker not found'];
        }

        $optoutUrl = $broker->removal_url ?? null;
        if (! $optoutUrl) {
            return ['success' => false, 'error' => 'No opt-out URL configured'];
        }

        $startTime = microtime(true);
        $response = null;
        $httpCode = 0;
        $error = null;

        try {
            $httpResponse = Http::connectTimeout($this->brokerHealthPolicy->connectTimeoutSeconds())
                ->timeout($this->brokerHealthPolicy->requestTimeoutSeconds())
                ->withUserAgent('Mozilla/5.0 (compatible; HealthCheck/1.0)')
                ->get($optoutUrl);
            $response = $httpResponse->body();
            $httpCode = $httpResponse->status();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Determine status
        $probeStatus = 'healthy';
        if ($error || $httpCode === 0) {
            $probeStatus = 'broken';
        } elseif ($httpCode >= 500) {
            $probeStatus = 'broken';
        } elseif ($httpCode >= 400) {
            $probeStatus = 'degraded';
        }

        // Check for page content changes
        $pageChanged = false;
        if ($response && $probeStatus === 'healthy') {
            $pageHash = hash('sha256', $response);
            if ($broker->optout_page_hash && $broker->optout_page_hash !== $pageHash) {
                $probeStatus = 'changed';
                $pageChanged = true;
            }
            DB::table('data_brokers')->where('id', $brokerId)->update(['optout_page_hash' => $pageHash]);
        }

        $status = $this->confirmedCurrentStatus($brokerId, $probeStatus);

        // Check for form presence (basic)
        $hasForm = $response && (stripos($response, '<form') !== false);
        $details = [
            'has_form' => $hasForm,
            'error' => $error ?: null,
            'content_length' => strlen($response ?: ''),
            'page_changed' => $pageChanged,
            'current_status' => $status,
        ];

        // Record health check
        DB::table('broker_health_checks')->insert([
            'data_broker_id' => $brokerId,
            'check_type' => 'optout_page',
            'status' => $probeStatus,
            'response_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'details' => json_encode($details),
            'checked_at' => now(),
        ]);

        // Update broker status
        DB::table('data_brokers')->where('id', $brokerId)->update([
            'health_status' => $status,
            'last_health_check' => now(),
        ]);

        return [
            'success' => true,
            'broker_id' => $brokerId,
            'status' => $status,
            'probe_status' => $probeStatus,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'has_form' => $hasForm,
            'page_changed' => $pageChanged,
        ];
    }

    public function batchHealthCheck(?int $limit = null): array
    {
        $limit = max(1, min(200, $limit ?? $this->brokerHealthPolicy->batchSize()));
        $brokers = DB::select(
            "SELECT id, name, removal_url FROM data_brokers
             WHERE removal_url IS NOT NULL AND removal_url != ''
             AND is_active = 1
             ORDER BY last_health_check IS NULL DESC, last_health_check ASC
             LIMIT ?",
            [$limit]
        );

        $results = ['checked' => 0, 'healthy' => 0, 'degraded' => 0, 'broken' => 0, 'changed' => 0];

        foreach ($brokers as $broker) {
            $result = $this->checkBrokerHealth($broker->id);
            $results['checked']++;
            if (($result['page_changed'] ?? false) === true) {
                $results['changed']++;
            } elseif (isset($result['status'])) {
                $results[$result['status']] = ($results[$result['status']] ?? 0) + 1;
            }
            $delayMs = $this->brokerHealthPolicy->interCheckDelayMs();
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return $results;
    }

    public function detectPageChanges(int $brokerId): array
    {
        $recent = DB::select(
            'SELECT status, details, checked_at FROM broker_health_checks
             WHERE data_broker_id = ? ORDER BY checked_at DESC LIMIT 5',
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
            'SELECT health_status, COUNT(*) as count FROM data_brokers
             WHERE is_active = 1 AND health_status IS NOT NULL GROUP BY health_status'
        );

        $avgResponseTime = DB::selectOne(
            'SELECT AVG(response_time_ms) as avg_ms FROM broker_health_checks
             WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        return [
            'by_status' => array_column(
                array_map(fn ($r) => ['status' => $r->health_status, 'count' => $r->count], $byStatus),
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
             WHERE is_active = 1 AND health_status IN ('degraded', 'broken')
             ORDER BY health_status ASC, name ASC"
        );
    }

    private function confirmedCurrentStatus(int $brokerId, string $probeStatus): string
    {
        if ($probeStatus === 'changed') {
            return 'healthy';
        }

        if ($probeStatus !== 'broken') {
            return $probeStatus;
        }

        $requiredChecks = $this->brokerHealthPolicy->failureConfirmationChecks();
        if ($requiredChecks <= 1) {
            return 'broken';
        }

        $previous = DB::table('broker_health_checks')
            ->where('data_broker_id', $brokerId)
            ->orderByDesc('id')
            ->limit($requiredChecks - 1)
            ->pluck('status')
            ->all();

        return count($previous) === $requiredChecks - 1
            && count(array_filter($previous, static fn (string $status): bool => $status === 'broken')) === $requiredChecks - 1
                ? 'broken'
                : 'degraded';
    }
}
