<?php

namespace App\Console\Commands;

use App\Services\YouTubeApiService;
use Illuminate\Console\Command;

class YouTubeTranscriptHealth extends Command
{
    protected $signature = 'youtube:transcript-health
                            {--reset= : Reset health data for a specific method (or "all")}';

    protected $description = 'Show health status of YouTube transcript methods';

    public function handle(): int
    {
        $youtubeApi = new YouTubeApiService();

        // Handle reset option
        if ($reset = $this->option('reset')) {
            return $this->resetHealth($reset);
        }

        $this->info('YouTube Transcript Method Health Status');
        $this->info('=' . str_repeat('=', 50));
        $this->newLine();

        $health = $youtubeApi->getTranscriptMethodsHealth();

        $rows = [];
        foreach ($health as $method => $data) {
            $status = $data['in_cooldown'] ? '🔴 COOLDOWN' : '🟢 Active';

            $errors = '';
            if (!empty($data['top_errors'])) {
                $errorList = [];
                foreach ($data['top_errors'] as $error => $count) {
                    $errorList[] = "{$error}({$count})";
                }
                $errors = implode(', ', $errorList);
            }

            $rows[] = [
                strtoupper($method),
                $status,
                $data['success_rate'],
                $data['total_calls'],
                $data['consecutive_failures'],
                $data['last_success'] ? \Carbon\Carbon::parse($data['last_success'])->diffForHumans() : 'Never',
                $errors ?: '-'
            ];
        }

        $this->table(
            ['Method', 'Status', 'Success Rate', 'Total Calls', 'Consec. Fails', 'Last Success', 'Top Errors'],
            $rows
        );

        $this->newLine();
        $this->info('Legend:');
        $this->line('  🟢 Active    - Method is available for use');
        $this->line('  🔴 COOLDOWN  - Method temporarily disabled (3+ consecutive failures)');
        $this->newLine();
        $this->info('Methods are automatically reordered by success rate.');
        $this->info('Cooldowns reset after 30-60 minutes.');

        return self::SUCCESS;
    }

    private function resetHealth(string $method): int
    {
        $methods = ['phplib', 'piped', 'direct', 'invidious', 'ytdlp'];

        if ($method === 'all') {
            foreach ($methods as $m) {
                \Illuminate\Support\Facades\Cache::forget("youtube_transcript_health:{$m}");
            }
            $this->info('Reset health data for all methods.');
        } elseif (in_array($method, $methods)) {
            \Illuminate\Support\Facades\Cache::forget("youtube_transcript_health:{$method}");
            $this->info("Reset health data for {$method}.");
        } else {
            $this->error("Unknown method: {$method}");
            $this->info('Valid methods: ' . implode(', ', $methods) . ', or "all"');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
