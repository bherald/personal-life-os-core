<?php

namespace App\Console\Commands;

use App\Services\AgentLoopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewResendPushoverCommand extends Command
{
    protected $signature = 'review:resend-pushover
        {--agent= : Filter by agent_id (supports LIKE, e.g. genealogy-%)}
        {--type= : Filter by review_type (e.g. genealogy_finding)}
        {--id= : Re-push a specific review row id}
        {--limit=50 : Maximum rows to process}
        {--dry-run : List targets without sending}';

    protected $description = 'Re-send Pushover notification for pending agent_review_queue items. Rate-limited per NotificationController policy (7/min for agent_approval_review).';

    public function handle(AgentLoopService $loop): int
    {
        $id = $this->option('id');
        $agent = $this->option('agent');
        $type = $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $where = ["status = 'pending'"];
        $params = [];

        if ($id) {
            $where = ['id = ?'];
            $params = [(int) $id];
        } else {
            if ($agent) {
                $where[] = 'agent_id LIKE ?';
                $params[] = $agent;
            }
            if ($type) {
                $where[] = 'review_type = ?';
                $params[] = $type;
            }
        }

        $sql = 'SELECT id, agent_id, review_type, title, confidence FROM agent_review_queue WHERE '
            .implode(' AND ', $where)
            .' ORDER BY created_at ASC LIMIT '.$limit;

        $rows = DB::select($sql, $params);

        if (empty($rows)) {
            $this->info('No pending review items match the filter.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d pending item(s)%s:', count($rows), $dryRun ? ' (dry-run)' : ''));
        foreach ($rows as $row) {
            $this->line(sprintf('  #%d  %s  [%s]  %s  (conf=%s)',
                $row->id,
                str_pad((string) $row->agent_id, 22),
                str_pad((string) $row->review_type, 20),
                mb_substr((string) $row->title, 0, 60),
                $row->confidence ?? 'n/a'
            ));
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $result = $loop->resendReviewPushover((int) $row->id);
            if ($result['success']) {
                $sent++;
                $this->line("  [sent] #{$row->id}");
            } else {
                $skipped++;
                $this->warn("  [skip] #{$row->id}: ".($result['error'] ?? 'unknown'));
            }
        }

        $this->info(sprintf('Done: %d sent, %d skipped.', $sent, $skipped));
        return self::SUCCESS;
    }
}
