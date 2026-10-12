<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Compatibility surface for the retired framework currency queue producer.
 */
class FrameworkCurrencyCheckCommand extends Command
{
    protected $signature = 'framework:currency-check
        {--dry-run : Retained for compatibility; no scan is run}
        {--notify : Retained for compatibility; no notification is sent}
        {--stats : Retained for compatibility; no legacy cache is read}';

    protected $description = 'Retired: use whiteroom monitoring and the biweekly LLM portfolio review';

    public function handle(): int
    {
        $this->warn('Retired: framework:currency-check no longer scans or writes review items.');
        $this->line('Repository and research drift: whiteroom:monitor --format=json --compact --due --limit=25');
        $this->line('Model and hardware recommendations: ops:llm-portfolio-review --json --compact');
        $this->line('Operator decisions: Review Hub and the 5:00 AM PLOS report.');

        return Command::SUCCESS;
    }
}
