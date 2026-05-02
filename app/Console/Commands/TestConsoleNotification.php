<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Illuminate\Console\Command;

class TestConsoleNotification extends Command
{
    protected $signature = 'test:console-notification {--type=all : Type of test (all, weather, alert, status)}';

    protected $description = 'Send test console-styled Pushover notifications';

    public function handle(): int
    {
        $type = $this->option('type');
        $controller = new NotificationController;

        $this->info("Sending console-styled test notification(s)...\n");

        $tests = [];

        if ($type === 'all' || $type === 'weather') {
            $tests['weather'] = [
                'title' => 'Weather Update',
                'message' => $this->getWeatherMessage(),
            ];
        }

        if ($type === 'all' || $type === 'alert') {
            $tests['alert'] = [
                'title' => 'System Alert',
                'message' => $this->getAlertMessage(),
            ];
        }

        if ($type === 'all' || $type === 'status') {
            $tests['status'] = [
                'title' => 'Daily Ops Status',
                'message' => $this->getStatusMessage(),
            ];
        }

        foreach ($tests as $name => $test) {
            $this->info("Sending {$name} notification...");

            // Apply compact console formatting manually for test.
            $formatted = $this->applyConsoleFormatting($test['message']);

            $success = $controller->send('pushover', [
                'source_group' => 'test_dev_only',
                'title' => $test['title'],
                'message' => $formatted,
                'priority' => 0,
                'format_type' => 'monospace',
            ]);

            if ($success) {
                $this->info('  ✓ Sent successfully');
            } else {
                $this->error('  ✗ Failed to send');
            }

            // Delay between messages
            if (count($tests) > 1) {
                sleep(2);
            }
        }

        $this->newLine();
        $this->info('Test complete. Check your Pushover app.');

        return 0;
    }

    private function getWeatherMessage(): string
    {
        return 'CONDITIONS
Temperature: 72°F
Humidity: 45%
Wind: 8 mph NW

FORECAST
Today: Partly cloudy, high 75°F
Tonight: Clear skies, low 58°F

UV Index: 6 (High)
Air Quality: Good';
    }

    private function getAlertMessage(): string
    {
        return 'ATTENTION REQUIRED

Email Queue: 3 drafts pending review
File Organizer: 12 files need categorization

SYSTEM STATUS
All services operational
Last backup: 2 hours ago';
    }

    private function getStatusMessage(): string
    {
        return 'OPERATIONS SUMMARY

Workflows: 10 active, 0 failed
Email Queue: 2 pending approval
File Organizer: 5 uncategorized
System Issues: 0 critical

All systems nominal.';
    }

    private function applyConsoleFormatting(string $message): string
    {
        // Bold, compact console blocks.
        $topBlock = '█▓▒░ PLOS ░▒▓█';
        $divider = '▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀';
        $endBlock = '░▒▓████████████▓▒░';

        $lines = explode("\n", $message);
        $formattedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                $formattedLines[] = '';

                continue;
            }

            // Section headers - bold block style
            if (preg_match('/^[A-Z][A-Z0-9\s]+:?$/', $trimmed) ||
                preg_match('/^[A-Z][a-z]+:$/', $trimmed)) {
                $headerText = rtrim($trimmed, ':');
                $formattedLines[] = '';
                $formattedLines[] = '█ '.strtoupper($headerText).' █';
            }
            // Key: value pairs
            elseif (preg_match('/^([^:]+):\s*(.+)$/', $trimmed, $matches)) {
                $formattedLines[] = '▸ '.trim($matches[1]).': '.trim($matches[2]);
            }
            // Bullet items
            elseif (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
                $formattedLines[] = '  • '.trim($matches[1]);
            } else {
                $formattedLines[] = $line;
            }
        }

        $formatted = $topBlock."\n";
        $formatted .= $divider."\n";
        $formatted .= implode("\n", $formattedLines);
        $formatted .= "\n".$divider."\n";
        $formatted .= $endBlock;

        return $formatted;
    }
}
