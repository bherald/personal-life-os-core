<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerSelectionJournal;
use Illuminate\Console\Command;

class MaintenanceAdmissionCheckCommand extends Command
{
    protected $signature = 'ops:maintenance-admission-check {--json : Emit fixed content-free admission clearance}';

    protected $description = 'Read application controller admission clearance while maintenance is active';

    public function handle(ModelHostControllerSelectionJournal $journal): int
    {
        $report = $journal->maintenanceAdmissionCheck();
        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR)
            : ($report['success'] ? 'Application admission clear.' : 'Application admission held: '.$report['reason_code']));

        return $report['success'] ? self::SUCCESS : self::FAILURE;
    }
}
