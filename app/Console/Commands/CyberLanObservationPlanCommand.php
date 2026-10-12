<?php

namespace App\Console\Commands;

use App\Services\CyberLanObservationService;
use Illuminate\Console\Command;

class CyberLanObservationPlanCommand extends Command
{
    protected $signature = 'cyber:lan-observation-plan';

    protected $description = 'Read the fixed .252 observation profile; never sends a request or enables execution';

    public function handle(CyberLanObservationService $service): int
    {
        try {
            $result = $service->plan();
        } catch (\Throwable) {
            $result = ['ready' => false, 'reason' => 'reviewed_profile_not_configured', 'requests_sent' => 0];
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
