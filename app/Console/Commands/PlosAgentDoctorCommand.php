<?php

namespace App\Console\Commands;

class PlosAgentDoctorCommand extends AgentDoctorCommand
{
    protected $signature = 'plos:agent-doctor
        {--agent= : Limit diagnostics to one agent id}
        {--quick : Keep output compatible with quicker future probes}
        {--compact : Emit a terse operator status summary without per-agent details}
        {--json : Emit machine-readable JSON}
        {--since=24 : Window size in hours, 1-168}';

    protected $description = 'PLOS operator-facing alias for observe-only agent health diagnostics';
}
