<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class SystemHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:health
                            {--snapshot : Take a health snapshot}
                            {--json : Output as JSON}
                            {--detailed : Show detailed check results}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check overall system health and service status';

    private SystemHealthService $healthService;

    public function __construct(SystemHealthService $healthService)
    {
        parent::__construct();
        $this->healthService = $healthService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏥 System Health Check');
        $this->newLine();

        // Run health check
        $health = $this->healthService->checkHealth();

        // Output as JSON if requested
        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Display health score
        $scoreColor = match (true) {
            $health['health_score'] >= 80 => 'green',
            $health['health_score'] >= 60 => 'yellow',
            default => 'red'
        };

        $statusEmoji = match ($health['health_status']) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            'unhealthy' => '🔴',
            'critical' => '🚨',
            default => '❓'
        };

        $this->line("Overall Health: <fg=$scoreColor;options=bold>{$health['health_score']}/100</> $statusEmoji {$health['health_status']}");
        $this->newLine();

        // Display service checks
        $headers = ['Service', 'Status', 'Score', 'Details'];
        $rows = [];

        foreach ($health['checks'] as $serviceName => $check) {
            $status = $check['healthy'] ? '✅ Up' : '❌ Down';
            $score = $check['score'] ?? 0;

            $details = [];
            if (isset($check['response_time_ms'])) {
                $details[] = "{$check['response_time_ms']}ms";
            }
            if (isset($check['disk_free_gb'])) {
                $details[] = "{$check['disk_free_gb']} GB free";
            }
            if (isset($check['error'])) {
                $details[] = substr($check['error'], 0, 50);
            }
            if (isset($check['active_workflows'])) {
                $details[] = "{$check['active_workflows']} active";
            }
            if (isset($check['error_rate'])) {
                $details[] = "{$check['error_rate']}/hr";
            }

            $rows[] = [
                ucfirst(str_replace('_', ' ', $serviceName)),
                $status,
                "$score/100",
                implode(', ', $details)
            ];
        }

        $this->table($headers, $rows);

        // Show detailed results if requested
        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Detailed Check Results:');
            $this->line(json_encode($health['checks'], JSON_PRETTY_PRINT));
        }

        // Show failed checks
        $failedChecks = count(array_filter($health['checks'], fn($check) => !($check['healthy'] ?? true)));
        if ($failedChecks > 0) {
            $this->newLine();
            $this->error("⚠️  {$failedChecks} service(s) failed health check");
        }

        // Take snapshot if requested
        if ($this->option('snapshot')) {
            $this->newLine();
            $this->info('📸 Taking health snapshot...');
            $snapshotId = $this->healthService->takeSnapshot();
            $this->info("Snapshot saved with ID: $snapshotId");
        }

        // Return appropriate exit code
        return $health['health_score'] >= 50 ? self::SUCCESS : self::FAILURE;
    }
}
