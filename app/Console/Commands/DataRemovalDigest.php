<?php

namespace App\Console\Commands;

use App\Services\DataRemovalNotificationService;
use Illuminate\Console\Command;

class DataRemovalDigest extends Command
{
    protected $signature = 'data-removal:digest
                            {--force : Send digest even if no notable activity}';

    protected $description = 'Send daily data removal digest notification';

    public function handle(): int
    {
        $this->info('Sending data removal daily digest...');

        try {
            $service = app(DataRemovalNotificationService::class);
            $force = $this->option('force');
            $success = $service->sendDailyDigest($force);

            if ($success) {
                $this->info('Daily digest sent successfully.');
                return Command::SUCCESS;
            } else {
                $this->warn('No digest sent (no notable activity or send failed).');
                return Command::SUCCESS;
            }

        } catch (\Exception $e) {
            $this->error('Failed to send digest: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
