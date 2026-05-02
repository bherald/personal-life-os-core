<?php

namespace App\Console\Commands;

use App\Services\ErrorTrackingService;
use Illuminate\Console\Command;

class SystemErrorsReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:errors
                            {--period=24 hours : Time period for error analysis}
                            {--type= : Filter by error type}
                            {--unresolved : Show only unresolved errors}
                            {--patterns : Show error patterns analysis}
                            {--json : Output as JSON}
                            {--limit=50 : Maximum errors to display}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View system errors and error patterns';

    private ErrorTrackingService $errorTracking;

    public function __construct(ErrorTrackingService $errorTracking)
    {
        parent::__construct();
        $this->errorTracking = $errorTracking;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $period = $this->option('period');
        $errorType = $this->option('type');
        $limit = (int) $this->option('limit');

        $this->info("📊 System Errors Report ($period)");
        $this->newLine();

        // Show error patterns if requested
        if ($this->option('patterns')) {
            return $this->showErrorPatterns($period);
        }

        // Show unresolved errors if requested
        if ($this->option('unresolved')) {
            return $this->showUnresolvedErrors($limit);
        }

        // Get error rate
        $errorRate = $this->errorTracking->getErrorRate($period, $errorType);
        $spikeDetected = $this->errorTracking->detectErrorSpike();

        $this->line("Error Rate: <fg=yellow;options=bold>$errorRate</> errors/hour");
        if ($spikeDetected) {
            $this->error('🚨 ERROR SPIKE DETECTED!');
        }
        $this->newLine();

        // Get top errors
        $topErrors = $this->errorTracking->getTopErrors(10, $period);

        if (empty($topErrors)) {
            $this->info('✅ No errors in this period');
            return self::SUCCESS;
        }

        // Output as JSON if requested
        if ($this->option('json')) {
            $this->line(json_encode([
                'error_rate' => $errorRate,
                'spike_detected' => $spikeDetected,
                'top_errors' => $topErrors,
            ], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Display top errors
        $this->info("Top Errors ($period):");
        $headers = ['Error Type', 'Count', 'Severity'];
        $rows = [];

        foreach ($topErrors as $error) {
            $emoji = match ($error['error_type']) {
                default => match (true) {
                    str_contains($error['error_type'], 'Critical') => '🚨',
                    str_contains($error['error_type'], 'Exception') => '❌',
                    str_contains($error['error_type'], 'Warning') => '⚠️',
                    default => '•'
                }
            };

            $rows[] = [
                $emoji . ' ' . $error['error_type'],
                $error['count'],
                $this->guessErrorSeverity($error['error_type'])
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show error patterns analysis
     */
    private function showErrorPatterns(string $period): int
    {
        $patterns = $this->errorTracking->analyzeErrorPatterns();

        if ($this->option('json')) {
            $this->line(json_encode($patterns, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Error Patterns Analysis');
        $this->newLine();

        $this->line("Total Errors: <fg=yellow>{$patterns['total_errors']}</>");
        $this->line("Error Rate: <fg=yellow>{$patterns['error_rate']}</> errors/hour");
        $this->line("Spike Detected: " . ($patterns['spike_detected'] ? '<fg=red>YES</>' : '<fg=green>NO</>'));
        $this->line("Unresolved: <fg=yellow>{$patterns['unresolved_count']}</>");
        $this->line("Critical: <fg=red>{$patterns['critical_count']}</>");
        $this->newLine();

        // Top errors
        if (!empty($patterns['top_errors'])) {
            $this->info('Top Error Types:');
            $headers = ['Error Type', 'Count'];
            $rows = [];

            foreach ($patterns['top_errors'] as $error) {
                $rows[] = [$error['error_type'], $error['count']];
            }

            $this->table($headers, $rows);
            $this->newLine();
        }

        // Severity distribution
        if (!empty($patterns['severity_distribution'])) {
            $this->info('Severity Distribution:');
            foreach ($patterns['severity_distribution'] as $severity => $count) {
                $emoji = match ($severity) {
                    'critical' => '🚨',
                    'error' => '❌',
                    'warning' => '⚠️',
                    'info' => 'ℹ️',
                    default => '•'
                };
                $this->line("  $emoji " . ucfirst($severity) . ": $count");
            }
            $this->newLine();
        }

        // Source distribution
        if (!empty($patterns['source_distribution'])) {
            $this->info('Source Distribution:');
            foreach ($patterns['source_distribution'] as $source => $count) {
                $this->line("  • " . ucfirst($source) . ": $count");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show unresolved errors
     */
    private function showUnresolvedErrors(int $limit): int
    {
        $errors = $this->errorTracking->getUnresolvedErrors($limit);

        if (empty($errors)) {
            $this->info('✅ No unresolved errors');
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($errors, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->error("⚠️  {count($errors)} Unresolved Errors");
        $this->newLine();

        $headers = ['ID', 'Type', 'Severity', 'Message', 'Occurred'];
        $rows = [];

        foreach ($errors as $error) {
            $emoji = match ($error->error_severity) {
                'critical' => '🚨',
                'error' => '❌',
                'warning' => '⚠️',
                default => 'ℹ️'
            };

            $rows[] = [
                $error->id,
                $emoji . ' ' . class_basename($error->error_type),
                $error->error_severity,
                substr($error->error_message, 0, 50),
                $error->occurred_at,
            ];
        }

        $this->table($headers, $rows);

        return self::FAILURE;
    }

    /**
     * Guess error severity from type name
     */
    private function guessErrorSeverity(string $errorType): string
    {
        return match (true) {
            str_contains($errorType, 'Critical') => '🚨 Critical',
            str_contains($errorType, 'Fatal') => '🚨 Critical',
            str_contains($errorType, 'Exception') => '❌ Error',
            str_contains($errorType, 'Warning') => '⚠️ Warning',
            default => 'ℹ️ Info'
        };
    }
}
