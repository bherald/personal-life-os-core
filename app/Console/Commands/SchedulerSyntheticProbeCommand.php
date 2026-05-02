<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SchedulerSyntheticProbeCommand extends Command
{
    protected $signature = 'ops:scheduler-synthetic-probe';

    protected $description = 'Lightweight scheduled probe that proves scheduler execution can run a harmless command end-to-end';

    public function handle(): int
    {
        DB::table('system_configs')->updateOrInsert(
            [
                'section' => 'scheduler',
                'config_key' => 'synthetic_probe_last_success_at',
            ],
            [
                'config_value' => now()->toIso8601String(),
                'data_type' => 'datetime',
                'description' => 'Last successful scheduled synthetic probe run',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->info('Scheduler synthetic probe recorded.');

        return self::SUCCESS;
    }
}
