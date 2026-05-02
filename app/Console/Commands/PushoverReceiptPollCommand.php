<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;

class PushoverReceiptPollCommand extends Command
{
    protected $signature = 'agent:poll-receipts';
    protected $description = 'Poll Pushover receipts for emergency-priority review acknowledgments';

    public function handle(AgentLoopService $agentLoop): int
    {
        $result = $agentLoop->pollPushoverReceipts();

        $this->line("Checked: {$result['checked']}, Approved: {$result['approved']}");

        return self::SUCCESS;
    }
}
