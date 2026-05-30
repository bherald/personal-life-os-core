<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use App\Services\OfflineAuditService;
use App\Services\OfflinePolicyService;
use App\Services\OllamaModelRegistryService;
use App\Services\RagBacklogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

/**
 * ops:daily-report — Consolidated daily Pushover report (5:50 AM + 4 PM midday).
 *
 * Sends 5 HTML messages in reverse order so they display top-to-bottom:
 *   1. System & Services
 *   2. Jobs & Agents
 *   3. File Pipelines
 *   4. RAG & Genealogy
 *   5. Alerts & Actions (emergency priority — requires ACK)
 *
 * Auto-healing (safe/reversible):
 *   1. LLM circuit breakers past retry_at -> half_open
 *   2. Config/route/view cache clear on config error spikes
 *   3. Queue executor restart if depth > 500
 */
class MorningDigestCommand extends Command
{
    protected $signature = 'ops:daily-report
                            {--dry-run : Print output without sending Pushover}
                            {--hours=8 : Hours of overnight history to analyze}
                            {--no-fix : Skip auto-healing actions}
                            {--smoke : Fast validation mode for deploy smoke-tests}';

    protected $description = 'Consolidated daily ops report (5:50 AM)';

    /** @var string[] */
    private array $autoFixed = [];

    /** @var string[] */
    private array $issues = [];

    // HTML color constants for Pushover
    private const GREEN = '#00AA00';

    private const YELLOW = '#CC8800';

    private const RED = '#CC0000';

    private const GRAY = '#888888';

    public function __construct(private readonly RagBacklogService $ragBacklogService)
    {
        parent::__construct();
    }

    private function getStorageRoot(): string
    {
        return rtrim(config('services.storage.root', '/srv/nextcloud'), '/');
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $smoke = (bool) $this->option('smoke');

        try {
            if (! $this->option('no-fix')) {
                $this->runAutoHealing();
            }

            $messages = $this->buildMessages($hours, $smoke);

            // INF-11a: Snapshot pipeline metrics for velocity tracking
            if (! $smoke) {
                $this->savePipelineSnapshot();
            }

            if ($this->option('dry-run')) {
                foreach ($messages as $i => $msg) {
                    $this->line('=== Message '.($i + 1).' of '.count($messages).' ===');
                    $this->line($msg['title']);
                    $this->line(strip_tags($msg['body']));
                    $this->line('');
                }

                return Command::SUCCESS;
            }

            $notifier = app(NotificationController::class);
            $total = count($messages);
            $failedChunks = 0;

            // Send in REVERSE order so Pushover displays them top-to-bottom
            // (Pushover shows newest first)
            for ($i = $total - 1; $i >= 0; $i--) {
                $msg = $messages[$i];
                $title = $msg['title'];
                $body = $msg['body'];
                $isActionMessage = $msg['emergency'] ?? false;

                if ($isActionMessage) {
                    $result = $notifier->sendPushoverWithReceipt([
                        'title' => $title,
                        'message' => $body,
                        'format_type' => 'html',
                        'priority' => 2,
                        'retry' => 1800,
                        'expire' => 7200,
                        'sound' => ! empty($this->issues) ? 'persistent' : 'pushover',
                        'source_group' => 'daily_digests',
                    ]);
                    if (! empty($result['receipt'])) {
                        Cache::put('digest_pushover_receipt', $result['receipt'], 7200);
                    }
                } else {
                    $result = $notifier->send('pushover', [
                        'title' => $title,
                        'message' => $body,
                        'format_type' => 'html',
                        'priority' => 0,
                        'sound' => 'none',
                        'source_group' => 'daily_digests',
                    ]);
                }

                if (empty($result['success'])) {
                    $failedChunks++;
                    Log::warning('Daily report: Pushover message failed', [
                        'msg' => $i + 1, 'total' => $total,
                        'error' => $result['error'] ?? 'unknown',
                    ]);
                }

                // 2s delay between messages for proper ordering
                if ($i > 0) {
                    usleep(2_000_000);
                }
            }

            if ($failedChunks > 0) {
                $this->warn("Daily report: {$failedChunks}/{$total} messages failed to send");
            }
            $this->info("Daily report sent ({$total} msgs, {$failedChunks} failed). [ITEMS_PROCESSED:1]");
        } catch (\Throwable $e) {
            Log::error('ops:daily-report failed', ['error' => $e->getMessage()]);
            $this->error('Failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    // =========================================================================
    // Auto-healing
    // =========================================================================

    private function runAutoHealing(): void
    {
        $this->healCircuitBreakers();
        $this->healCacheIfNeeded();
        $this->healQueueIfNeeded();
    }

    private function healCircuitBreakers(): void
    {
        try {
            $stale = DB::select(
                "SELECT instance_id, instance_name FROM llm_instances
                 WHERE is_active = 1
                   AND circuit_state = 'open'
                   AND circuit_retry_at IS NOT NULL
                   AND circuit_retry_at < NOW()"
            );
            foreach ($stale as $inst) {
                DB::update(
                    "UPDATE llm_instances SET circuit_state = 'half_open', updated_at = NOW() WHERE instance_id = ?",
                    [$inst->instance_id]
                );
                $this->autoFixed[] = "Circuit breaker reset ({$inst->instance_name})";
            }
        } catch (\Throwable) {
        }
    }

    private function healCacheIfNeeded(): void
    {
        try {
            $count = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM system_alerts
                 WHERE triggered_at > DATE_SUB(NOW(), INTERVAL 8 HOUR)
                   AND (title LIKE '%config%' OR title LIKE '%Route%not found%' OR title LIKE '%view%not found%')"
            )?->c ?? 0);
            if ($count >= 4) {
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
                $this->autoFixed[] = "Cache cleared ({$count} config errors)";
            }
        } catch (\Throwable) {
        }
    }

    private function healQueueIfNeeded(): void
    {
        try {
            $depth = array_sum($this->getActiveQueueDepths());
            if ($depth > 500) {
                Artisan::call('horizon:terminate');
                $this->autoFixed[] = "Horizon restart requested (queue depth {$depth})";
            }
        } catch (\Throwable) {
        }
    }

    // =========================================================================
    // Message builders — one per Pushover message
    // =========================================================================

    /**
     * Build all 5 messages. Each returns ['title' => ..., 'body' => ..., 'emergency' => bool].
     */
    private function buildMessages(int $hours, bool $smoke = false): array
    {
        if ($smoke) {
            // Smoke mode is a bounded deploy canary, not the full operator digest.
            // Keep it on the fast system/services slice only and skip heavier
            // analytics that can be delayed by large prod tables.
            return [
                $this->buildMsg1SystemServices($hours, true),
            ];
        }

        return [
            $this->buildMsg1SystemServices($hours, $smoke),
            $this->buildMsg2JobsAgents($hours),
            $this->buildMsg3FilePipelines(),
            $this->buildMsg4RagGenealogy($hours),
            $this->buildMsg5AlertsActions($hours),
        ];
    }

    /**
     * Message 1: System & Services
     */
    private function buildMsg1SystemServices(int $hours, bool $smoke = false): array
    {
        $date = now()->format('l M d g:i A');
        $lines = [];

        // System
        $load = sys_getloadavg();
        $mem = $this->getMemory();
        $rootPct = $this->diskPct('/');

        $lines[] = '<b>SYSTEM</b>';
        $lines[] = "  Load Average     {$this->colorVal($load[0], $load[0] < 8, $load[0] < 16)}  (4 cores)";
        $lines[] = "  Memory           {$mem['used_gb']} / {$mem['total_gb']} GB";
        $lines[] = "  Disk Root        {$this->colorPct($rootPct, true)}  (".$this->freeSpace('/').' free)';
        if (! $smoke) {
            $storageRoot = $this->getStorageRoot();
            $storagePct = $this->diskPct($storageRoot);
            $lines[] = "  Disk Storage     {$this->colorPct($storagePct, true)}  (".$this->freeSpace($storageRoot).' free)';
            if ($storagePct > 90) {
                $this->issues[] = "Storage disk at {$storagePct}%";
            }
        }
        $lines[] = '  Uptime           '.$this->getUptime();

        if ($rootPct > 90) {
            $this->issues[] = "Root disk at {$rootPct}%";
        }

        // Backups and filesystem-backed storage checks are skipped in smoke mode.
        if (! $smoke) {
            $backup = $this->getBackupStatus();
            $lines[] = '';
            $lines[] = '<b>BACKUPS</b>';
            $lines[] = "  MySQL            {$this->c($backup['mysql_color'], $backup['mysql_size'])}   {$backup['mysql_date']}  {$backup['mysql_label']}";
            $lines[] = "  PostgreSQL       {$this->c($backup['pg_color'], $backup['pg_size'])}   {$backup['pg_date']}  {$backup['pg_label']}";
            if ($backup['running']) {
                $lines[] = '  '.$this->c(self::YELLOW, $backup['detail']);
            } elseif ($backup['healthy']) {
                $lines[] = '  '.$this->c(self::GREEN, 'Good');
            } else {
                $lines[] = '  '.$this->c(self::RED, $backup['detail']);
                $this->issues[] = "Backup: {$backup['detail']}";
            }
        }

        // Services
        $services = $this->getServiceStatus();
        $lines[] = '';
        $lines[] = '<b>SERVICES</b>';
        $lines[] = '  Redis            '.$this->statusColor($services['redis'] !== null, $services['redis'] ?? 'DOWN');
        $lines[] = '  Horizon          '.$this->statusColor($services['horizon'] !== null && $services['horizon'] > 0, $services['horizon'] !== null ? "{$services['horizon']} workers" : 'DOWN');
        $lines[] = '  Ollama Primary   '.$this->statusColor($services['ollama_primary'] !== false, $services['ollama_primary'] ?: 'DOWN');
        $lines[] = '  Ollama Secondary '.$this->statusColor($services['ollama_secondary'] !== false, $services['ollama_secondary'] ?: 'DOWN');

        // GPU
        $gpu = $this->getGpuStatus();
        if ($gpu) {
            $lines[] = "  GPU              {$gpu}";
        }

        // Routing (Row 4 — visibility into profile + offline kill switch +
        // circuit-open providers; pre-fix the digest was silent on all three,
        // so operator had no morning signal that we were running degraded).
        // Ollama drift is appended here so the migration's claim that drift
        // surfaces in the daily-report ROUTING section is actually true.
        $routing = $this->getRoutingSnapshot($hours);
        $drift = $smoke ? [] : $this->getOllamaDriftLines();
        if ($routing !== [] || $drift !== []) {
            $lines[] = '';
            $lines[] = '<b>ROUTING</b>';
            foreach ($routing as $l) {
                $lines[] = $l;
            }
            foreach ($drift as $l) {
                $lines[] = $l;
            }
        }

        // Recovery activity (Row 8A follow-up — surface dead-PID
        // reconciliation so operator sees silent self-healing instead of
        // only finding it in a WARN-level log grep).
        $recovery = $this->getRecoveryActivity($hours);
        if ($recovery !== []) {
            $lines[] = '';
            $lines[] = '<b>RECOVERY</b>';
            foreach ($recovery as $l) {
                $lines[] = $l;
            }
        }

        // LLM Providers
        $llm = $this->getLLMUsageExpanded($hours);
        if (! empty($llm)) {
            $lines[] = '';
            $lines[] = '<b>LLM PROVIDERS (last 24h)</b>';
            foreach ($llm as $l) {
                $lines[] = $l;
            }
        }

        $body = implode("\n", $lines);

        return [
            'title' => "📊  PLOS Daily — {$date}",
            'body' => $body,
            'emergency' => false,
        ];
    }

    /**
     * Message 2: Jobs & Agents
     */
    private function buildMsg2JobsAgents(int $hours): array
    {
        $lines = [];

        // Jobs summary
        $failedJobs = $this->getFailedJobs($hours);
        $stalledJobs = $this->getStalledJobs();
        $runningCnt = $this->getRunningJobCount();
        $successCnt = $this->getSuccessJobCount($hours);

        $lines[] = '<b>SCHEDULED JOBS</b>';
        $lines[] = "  Running Now      {$runningCnt}";
        $lines[] = '  Succeeded (24h)  '.$this->c(self::GREEN, (string) $successCnt);
        $failCnt = count($failedJobs);
        $lines[] = '  Failed Now       '.($failCnt > 0 ? $this->c(self::RED, (string) $failCnt) : $this->c(self::GREEN, '0'));
        $stallCnt = count($stalledJobs);
        $lines[] = '  Stalled Now      '.($stallCnt > 0 ? $this->c(self::RED, (string) $stallCnt) : '0');

        // Trailing failure hotspots
        $jobFailRates = $this->getJobFailureRates();
        if (! empty($jobFailRates)) {
            $lines[] = '';
            $lines[] = '<b>24H FAILURE HOTSPOTS (>25%)</b>';
            foreach ($jobFailRates as $jf) {
                $color = $jf->fail_pct >= 75 ? self::RED : self::YELLOW;
                $lines[] = '  '.str_pad($jf->name, 28).$this->c($color, "{$jf->fails}/{$jf->total}  {$jf->fail_pct}%");
            }
        }

        foreach ($failedJobs as $j) {
            $this->issues[] = 'Job failed: '.$this->formatJobIssueLabel($j);
        }
        foreach ($stalledJobs as $j) {
            $this->issues[] = 'Job stalled: '.$this->formatJobIssueLabel($j)." ({$j->running_min}m)";
        }

        // Agent productivity
        $agents = $this->getAgentStats($hours);
        $agentProd = $this->getAgentProductivity($hours);

        $lines[] = '';
        $lines[] = '<b>AGENT PRODUCTIVITY</b>';
        $lines[] = "  Sessions         {$agents['completed']} completed";
        $errLine = $agents['errors'] > 0 ? $this->c(self::RED, "{$agents['errors']} errors") : $this->c(self::GREEN, '0 errors');
        $lines[] = "  Errors           {$errLine}";
        $lines[] = '  Tokens Used      '.$this->shortNum(max(0, $agents['tokens']));

        if ($agents['errors'] > 0) {
            $this->issues[] = "{$agents['errors']} agent errors";
        }

        // Zero-yield and queue depth
        $zeroYield = $this->identifyZeroYieldAgents($agentProd['agents']);
        $highQueue = [];
        foreach ($agentProd['agents'] as $a) {
            if ($a['pending_queue'] >= 10) {
                $highQueue[] = "    {$a['agent_id']}: {$a['pending_queue']} pending";
            }
        }
        if (! empty($zeroYield)) {
            $lines[] = '';
            $lines[] = '  '.$this->c(self::YELLOW, 'Zero-Yield Completed Agents');
            foreach ($zeroYield as $zy) {
                $lines[] = "    {$zy['agent_id']} ({$zy['completed']} completed, {$zy['successful_tool_calls']} successful tool calls, {$zy['review_items']} reviews)";
            }
            $this->issues[] = count($zeroYield).' agents with zero yield';
        }
        if (! empty($highQueue)) {
            $lines[] = '';
            $lines[] = '  <b>Review Queue Depth</b>';
            foreach ($highQueue as $hq) {
                $lines[] = $hq;
            }
        }

        foreach ($this->getReviewerFeedbackLines() as $line) {
            $lines[] = $line;
        }

        return [
            'title' => '📋  PLOS Daily — Jobs & Agents (2/5)',
            'body' => implode("\n", $lines),
            'emergency' => false,
        ];
    }

    /**
     * Phase 3 learning-loop visibility — per-agent acceptance rate from
     * the operator's per-field decisions over the last 30 days. Reads
     * the phase3_partial_apply audit blobs via
     * AgentProceduralMemoryService::getReviewerFeedbackForAllAgents.
     *
     * Emits one digest line per agent ordered by acceptance rate desc,
     * plus a top-reject-reason tag. Pushes a warn-level issue when any
     * agent has acceptance_rate < 0.35 with at least 5 proposals
     * reviewed — that's the threshold where the operator's feedback
     * is stable and the agent is underperforming.
     *
     * @return string[]
     */
    private function getReviewerFeedbackLines(): array
    {
        try {
            $rollup = app(\App\Services\AgentProceduralMemoryService::class)
                ->getReviewerFeedbackForAllAgents(30);
        } catch (\Throwable $e) {
            Log::debug('ops:daily-report: reviewer feedback rollup failed', ['error' => $e->getMessage()]);

            return [];
        }
        if ($rollup === []) {
            return [];
        }

        $lines = [''];
        $lines[] = '  <b>Reviewer Feedback (30d)</b>';
        foreach ($rollup as $r) {
            $rate = $r['acceptance_rate'] ?? null;
            $pct = $rate !== null ? (int) round($rate * 100) : null;
            $color = $pct === null ? self::GRAY : ($pct >= 70 ? self::GREEN : ($pct >= 40 ? self::YELLOW : self::RED));
            $topReason = '';
            if (! empty($r['reject_reason_histogram'])) {
                $firstKey = array_key_first($r['reject_reason_histogram']);
                $topReason = "  top reject: {$firstKey}";
            }
            $label = $pct !== null ? "{$pct}%" : 'n/a';
            $sample = "({$r['accepted_proposals']} ✓ / {$r['rejected_proposals']} ✗)";
            $lines[] = sprintf(
                '    %s  %s %s%s',
                str_pad((string) $r['agent_id'], 22),
                $this->c($color, $label),
                $sample,
                $topReason
            );

            if ($pct !== null && $pct < 35 && ($r['accepted_proposals'] + $r['rejected_proposals']) >= 5) {
                $this->issues[] = sprintf(
                    'Agent "%s" reviewer acceptance %d%% over %d proposals — tune or retire',
                    $r['agent_id'],
                    $pct,
                    $r['accepted_proposals'] + $r['rejected_proposals']
                );
            }
        }

        return $lines;
    }

    /**
     * Message 3: File Pipelines
     */
    private function buildMsg3FilePipelines(): array
    {
        $pipeline = $this->getPipelineBacklog();
        $totalFiles = $this->safeQuery(fn ($s) => (int) (DB::selectOne($s)?->c ?? 0),
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active'");

        $lines = [];
        $lines[] = '<b>ACTIVE FILES: '.number_format($totalFiles).'</b>';

        // Enrichment progress with color coding
        $lines[] = '';
        $lines[] = '<b>ENRICHMENT PROGRESS</b>';

        $enrichItems = [
            'Thumbnails' => $pipeline['thumbs'],
            'Perceptual Hash' => $pipeline['phash'],
            'AI Tagging (Images)' => $pipeline['ai_img'],
            'Face Detection' => $pipeline['faces'],
            'EXIF Metadata' => $pipeline['exif'],
        ];

        foreach ($enrichItems as $label => $data) {
            $pct = $data['total'] > 0 ? round(($data['total'] - $data['pending']) / $data['total'] * 100, 1) : 100;
            $done = $data['total'] - $data['pending'];
            $color = $pct >= 90 ? self::GREEN : ($pct >= 50 ? self::YELLOW : self::RED);
            $lines[] = '  '.str_pad($label, 22).$this->c($color, number_format($done).' done').'  '.number_format($data['pending']).' left  '.$this->c($color, "{$pct}%");
        }

        // EXIF writeback
        $lines[] = '';
        $lines[] = '<b>EXIF WRITEBACK</b>';
        $wbPending = $pipeline['writeback']['pending'];
        $wbColor = $wbPending > 50000 ? self::RED : ($wbPending > 10000 ? self::YELLOW : self::GREEN);
        $lines[] = '  Date Writeback     '.$this->c($wbColor, number_format($wbPending).' pending');

        // File RAG index
        $ragData = $pipeline['rag'];
        $ragPct = $ragData['total'] > 0 ? round(($ragData['total'] - $ragData['pending']) / $ragData['total'] * 100, 1) : 0;
        $ragColor = $ragPct >= 90 ? self::GREEN : ($ragPct >= 50 ? self::YELLOW : self::RED);
        $lines[] = '';
        $lines[] = '<b>FILE RAG INDEX</b>';
        $lines[] = '  Indexed            '.$this->c($ragColor, number_format($ragData['total'] - $ragData['pending'])).' of '.number_format($ragData['total']).'  '.$this->c($ragColor, "{$ragPct}%");
        $lines[] = '  Pending            '.number_format($ragData['pending']);

        // Pipeline velocity
        $throughput = $this->getPipelineThroughput(24);
        if (array_sum($throughput) > 0) {
            $lines[] = '';
            $lines[] = '<b>VELOCITY (last 24h)</b>';
            if ($throughput['ai'] > 0) {
                $lines[] = '  AI Tagged          +'.number_format($throughput['ai']);
            }
            if ($throughput['faces'] > 0) {
                $lines[] = '  Faces Scanned      +'.number_format($throughput['faces']);
            }
            if ($throughput['phash'] > 0) {
                $lines[] = '  Hashes Generated   +'.number_format($throughput['phash']);
            }
        }

        // Stall detection
        foreach (['ai_img' => 'AI Tagging', 'faces' => 'Face Detection', 'phash' => 'Perceptual Hash'] as $key => $label) {
            $p = $pipeline[$key];
            if ($p['pending'] > 100 && $throughput[$this->throughputKey($key)] === 0) {
                $this->issues[] = "Pipeline stalled: {$label} (".number_format($p['pending']).' pending, 0 processed)';
            }
        }

        return [
            'title' => '📁  PLOS Daily — File Pipelines (3/5)',
            'body' => implode("\n", $lines),
            'emergency' => false,
        ];
    }

    /**
     * Message 4: RAG & Genealogy
     */
    private function buildMsg4RagGenealogy(int $hours): array
    {
        $lines = [];
        $ragBacklog = $this->getRagBacklogOverview();
        $ragTotal = $ragBacklog['documents'];

        $lines[] = '<b>RAG DOCUMENTS: '.number_format($ragTotal).'</b>';

        // RAPTOR
        $raptorPending = (int) ($ragBacklog['raptor']['pending'] ?? 0);
        $raptorThroughput = (int) ($ragBacklog['raptor']['throughput_per_day'] ?? 0);
        $raptorEtaDays = $ragBacklog['raptor']['eta_days'] ?? null;
        $raptorColor = $raptorPending > 1000 ? self::RED : ($raptorPending > 100 ? self::YELLOW : self::GREEN);
        $lines[] = '';
        $lines[] = '<b>RAPTOR Summaries</b>';
        $lines[] = '  Backlog            '.$this->c($raptorColor, number_format($raptorPending).' pending');
        if ($raptorPending > 0 && $raptorThroughput > 0 && $raptorEtaDays !== null) {
            $lines[] = '  Burn-down          +'.number_format($raptorThroughput).'/day, ETA '.$this->formatEtaDays((float) $raptorEtaDays);
        }

        // Sentence embeddings
        $sentencePending = (int) ($ragBacklog['sentence']['pending'] ?? 0);
        $sentenceThroughput = (int) ($ragBacklog['sentence']['throughput_per_day'] ?? 0);
        $sentenceEtaDays = $ragBacklog['sentence']['eta_days'] ?? null;
        $seColor = $sentencePending > 5000 ? self::RED : ($sentencePending > 1000 ? self::YELLOW : self::GREEN);
        $lines[] = '';
        $lines[] = '<b>Sentence Embeddings</b>';
        $lines[] = '  Backlog            '.$this->c($seColor, number_format($sentencePending).' pending');
        if ($sentencePending > 0 && $sentenceThroughput > 0 && $sentenceEtaDays !== null) {
            $lines[] = '  Burn-down          +'.number_format($sentenceThroughput).'/day, ETA '.$this->formatEtaDays((float) $sentenceEtaDays);
        }

        // Knowledge graph
        $kgPending = (int) ($ragBacklog['kg']['pending'] ?? 0);
        $kgFresh = (int) ($ragBacklog['kg']['fresh'] ?? 0);
        $kgStale = (int) ($ragBacklog['kg']['stale'] ?? 0);
        $kgThroughput = (int) ($ragBacklog['kg']['throughput_per_day'] ?? 0);
        $kgEtaDays = $ragBacklog['kg']['eta_days'] ?? null;
        $kgColor = $kgPending > 10000 ? self::RED : ($kgPending > 2000 ? self::YELLOW : self::GREEN);
        $lines[] = '';
        $lines[] = '<b>Knowledge Graph</b>';
        $lines[] = '  Backlog            '.$this->c(
            $kgColor,
            number_format($kgPending).' pending ('.number_format($kgFresh).' fresh, '.number_format($kgStale).' stale)'
        );
        $lines[] = '  Entities           '.number_format((int) ($ragBacklog['kg']['entities'] ?? 0));
        if ($kgPending > 0 && $kgThroughput > 0 && $kgEtaDays !== null) {
            $lines[] = '  Burn-down          +'.number_format($kgThroughput).'/day, ETA '.$this->formatEtaDays((float) $kgEtaDays);
        }

        // RLM
        $rlm = $this->getRLMStats($hours);
        if ($rlm['total_calls'] > 0) {
            $lines[] = '';
            $lines[] = '<b>RLM RECURSION (last 24h)</b>';
            $lines[] = '  Calls              '.number_format($rlm['total_calls']);
            $lines[] = '  Tokens             '.$this->shortNum(max(0, $rlm['total_tokens']));
            $lines[] = "  Local Processing   {$rlm['local_pct']}%";
            $lines[] = "  Move-On Nudges     {$rlm['move_ons']}";
            if ($rlm['disabled_services'] > 0) {
                $lines[] = '  '.$this->c(self::YELLOW, "Disabled: {$rlm['disabled_names']}");
                $this->issues[] = "RLM disabled for: {$rlm['disabled_names']}";
            }
        }
        // Master switch
        try {
            $masterEnabled = DB::selectOne(
                "SELECT config_value FROM system_configs WHERE section = 'recursion' AND config_key = 'master_enabled' LIMIT 1"
            );
            if ($masterEnabled && $masterEnabled->config_value === 'false') {
                $lines[] = '  '.$this->c(self::RED, 'MASTER SWITCH OFF — all recursion bypassed');
                $this->issues[] = 'RLM master switch OFF';
            }
        } catch (\Throwable) {
        }

        // Genealogy
        $genea = $this->getGenealogyDigest();
        $lines[] = '';
        $lines[] = '<b>GENEALOGY</b>';
        $lines[] = '  Persons            '.number_format($genea['total_persons']);
        $lines[] = '  Research Queue     '.($genea['pending_review'] > 10
            ? $this->c(self::YELLOW, "{$genea['pending_review']} pending")
            : "{$genea['pending_review']} pending");
        $lines[] = "  Last Research      {$genea['last_run']}";
        if ($genea['total_persons'] > 0) {
            $covPct = round(100 - ($genea['never_searched'] / $genea['total_persons'] * 100));
            $covColor = $covPct >= 80 ? self::GREEN : ($covPct >= 50 ? self::YELLOW : self::RED);
            $lines[] = '  Coverage           '.$this->c($covColor, "{$covPct}%")."  ({$genea['never_searched']} never searched)";
        }

        // RSS
        $rss = $this->getRssHealth();
        $lines[] = '';
        $rssColor = $rss['failing'] > 0 ? self::YELLOW : self::GREEN;
        $lines[] = '<b>RSS FEEDS</b>';
        $lines[] = '  '.$this->c($rssColor, "{$rss['healthy']}/{$rss['total']} healthy").($rss['failing'] > 0 ? '  '.$this->c(self::RED, "{$rss['failing']} failing") : '');

        return [
            'title' => '🔍  PLOS Daily — RAG & Genealogy (4/5)',
            'body' => implode("\n", $lines),
            'emergency' => false,
        ];
    }

    /**
     * Message 5: Alerts & Actions — EMERGENCY PRIORITY (requires ACK)
     */
    private function buildMsg5AlertsActions(int $hours): array
    {
        $lines = [];

        // Review Queue
        $review = $this->getReviewQueue();
        $totalPending = array_sum(array_column($review, 'pending'));
        $lines[] = '<b>REVIEW QUEUE: '.($totalPending > 0 ? $this->c(self::YELLOW, "{$totalPending} pending") : $this->c(self::GREEN, '0 pending')).'</b>';
        foreach ($review as $r) {
            if ($r->pending > 0) {
                $lines[] = '  '.str_pad($r->label, 22).$r->pending;
            }
        }

        // Log errors
        $opsCache = Cache::get('ops_maintenance_report', []);
        $logData = $opsCache['logs'] ?? null;
        if ($logData && ($logData['summary']['total_errors'] ?? 0) > 0) {
            $errCount = $logData['summary']['total_errors'];
            $lines[] = '';
            $lines[] = '<b>LOG ERRORS (last 24h): '.$this->c(self::RED, (string) $errCount).'</b>';
            $topPatterns = array_slice($logData['patterns'] ?? [], 0, 4, true);
            foreach ($topPatterns as $cat => $cnt) {
                $lines[] = "  {$cat}: {$cnt}";
            }
        }

        // System errors from DB
        try {
            $topErrors = app(\App\Services\ErrorTrackingService::class)->getTopErrors(3, '24 hours');
            if (! empty($topErrors)) {
                $lines[] = '';
                $lines[] = '<b>TOP SYSTEM ERRORS</b>';
                foreach ($topErrors as $e) {
                    $lines[] = '  '.class_basename($e['error_type']).": {$e['count']}";
                }
            }
        } catch (\Throwable) {
        }

        // Alerts
        $alerts = $this->getAlerts($hours);
        if ($alerts['total'] > 0) {
            $lines[] = '';
            $aColor = $alerts['critical'] > 0 ? self::RED : self::YELLOW;
            $aDetail = $alerts['critical'] > 0 ? "{$alerts['critical']} critical" : "{$alerts['high']} high";
            $lines[] = '<b>SYSTEM ALERTS: '.$this->c($aColor, "{$alerts['total']} ({$aDetail})").'</b>';
            if ($alerts['critical'] > 0) {
                $this->issues[] = "{$alerts['critical']} CRITICAL alerts";
            } elseif ($alerts['high'] > 0) {
                $this->issues[] = "{$alerts['high']} high-priority alerts";
            }
        }

        // Queue depth
        $queueDepths = $this->getActiveQueueDepths();
        $visibleQueues = array_filter($queueDepths, fn ($depth) => $depth > 10);
        if (! empty($visibleQueues)) {
            $lines[] = '';
            $lines[] = '<b>QUEUE DEPTH</b>';
            foreach ($visibleQueues as $queueName => $depth) {
                $label = ucwords(str_replace('-', ' ', $queueName));
                $lines[] = "  {$label}: {$depth}";
            }
        }

        // Auto-fixes
        if (! empty($this->autoFixed)) {
            $lines[] = '';
            $lines[] = '<b>AUTO-FIXES APPLIED</b>';
            foreach ($this->autoFixed as $fix) {
                $lines[] = '  '.$this->c(self::GREEN, $fix);
            }
        }

        // ACTION ITEMS
        $lines[] = '';
        if (! empty($this->issues)) {
            $lines[] = $this->c(self::RED, '<b>ACTION ITEMS ('.count($this->issues).')</b>');
            foreach (array_slice($this->issues, 0, 8) as $issue) {
                $lines[] = ' → '.$this->c(self::RED, $issue);
            }
            if (count($this->issues) > 8) {
                $lines[] = ' +'.(count($this->issues) - 8).' more';
            }
        } else {
            $lines[] = $this->c(self::GREEN, '<b>ALL CLEAR — No action items</b>');
        }

        return [
            'title' => '🚨  PLOS Daily — Alerts & Actions (5/5)',
            'body' => implode("\n", $lines),
            'emergency' => true,
        ];
    }

    // =========================================================================
    // HTML color helpers
    // =========================================================================

    /** Wrap text in a Pushover HTML font color tag */
    private function c(string $color, string $text): string
    {
        return "<font color=\"{$color}\">{$text}</font>";
    }

    /** Color a percentage — green if good (low for disk), yellow mid, red if bad */
    private function colorPct(int $pct, bool $invertScale = false): string
    {
        if ($invertScale) {
            // For disk: low % = green, high % = red
            $color = $pct < 70 ? self::GREEN : ($pct < 90 ? self::YELLOW : self::RED);
        } else {
            // For completion: high % = green, low % = red
            $color = $pct >= 90 ? self::GREEN : ($pct >= 50 ? self::YELLOW : self::RED);
        }

        return $this->c($color, "{$pct}%");
    }

    /** Color a value based on good/warn thresholds */
    private function colorVal(float $val, bool $isGood, bool $isWarn): string
    {
        $color = $isGood ? self::GREEN : ($isWarn ? self::YELLOW : self::RED);

        return $this->c($color, number_format($val, 1));
    }

    /** Green for online/good, red for down/bad */
    private function statusColor(bool $isGood, string $text): string
    {
        return $this->c($isGood ? self::GREEN : self::RED, $text);
    }

    // =========================================================================
    // Data gathering
    // =========================================================================

    private function getFailedJobs(int $hours): array
    {
        try {
            return DB::select(
                "SELECT sj.name, sj.notes, sj.last_run_status,
                        (
                            SELECT COUNT(*)
                            FROM scheduled_job_runs sjr
                            WHERE sjr.scheduled_job_id = sj.id
                              AND sjr.status IN ('failed', 'timeout')
                              AND sjr.started_at > COALESCE(
                                  (
                                      SELECT MAX(s2.started_at)
                                      FROM scheduled_job_runs s2
                                      WHERE s2.scheduled_job_id = sj.id
                                        AND s2.status = 'success'
                                  ),
                                  '2000-01-01'
                              )
                        ) as consecutive_failures
                 FROM scheduled_jobs sj
                 WHERE sj.enabled = 1
                   AND sj.last_run_status IN ('failed','timeout')
                   AND sj.last_run_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 HAVING consecutive_failures > 0
                 ORDER BY sj.last_run_at DESC LIMIT 10",
                [$hours]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function getStalledJobs(): array
    {
        try {
            return DB::select(
                "SELECT name, notes, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) as running_min
                 FROM scheduled_jobs
                 WHERE last_run_status = 'running' AND stall_exempt = 0
                   AND COALESCE(job_type, '') <> 'agent_task'
                   AND TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) > 60
                 ORDER BY last_run_at ASC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function getRunningJobCount(): int
    {
        try {
            return (int) (DB::selectOne(
                "SELECT COUNT(*) as c
                 FROM scheduled_jobs
                 WHERE last_run_status = 'running'
                   AND stall_exempt = 0
                   AND COALESCE(job_type, '') <> 'agent_task'"
            )?->c ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getSuccessJobCount(int $hours): int
    {
        try {
            return (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM scheduled_job_runs WHERE status = 'success' AND started_at > DATE_SUB(NOW(), INTERVAL ? HOUR)",
                [$hours]
            )?->c ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getJobFailureRates(): array
    {
        try {
            return DB::select("
                SELECT j.name,
                       j.last_run_status,
                       COUNT(*) as total,
                       SUM(CASE WHEN r.status IN ('failed','timeout') THEN 1 ELSE 0 END) as fails,
                       ROUND(SUM(CASE WHEN r.status IN ('failed','timeout') THEN 1 ELSE 0 END) / COUNT(*) * 100) as fail_pct,
                       (
                           SELECT COUNT(*)
                           FROM scheduled_job_runs sjr
                           WHERE sjr.scheduled_job_id = j.id
                             AND sjr.status IN ('failed', 'timeout')
                             AND sjr.started_at > COALESCE(
                                 (
                                     SELECT MAX(s2.started_at)
                                     FROM scheduled_job_runs s2
                                     WHERE s2.scheduled_job_id = j.id
                                       AND s2.status = 'success'
                                 ),
                                 '2000-01-01'
                             )
                       ) as consecutive_failures
                FROM scheduled_job_runs r
                JOIN scheduled_jobs j ON j.id = r.scheduled_job_id
                WHERE r.started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND j.enabled = 1
                GROUP BY j.id, j.name
                HAVING fails > 0
                   AND total >= 2
                   AND fail_pct >= 25
                   AND j.last_run_status IN ('failed', 'timeout')
                   AND consecutive_failures > 0
                ORDER BY fail_pct DESC LIMIT 8
            ");
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAgentStats(int $hours): array
    {
        try {
            $completed = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM agent_episodes WHERE event_type = 'task_completed' AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)", [$hours]
            )?->c ?? 0);
            $errors = (int) (DB::selectOne(
                "SELECT COUNT(*) as c FROM agent_episodes WHERE event_type = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)", [$hours]
            )?->c ?? 0);
            $tokens = (int) (DB::selectOne(
                'SELECT COALESCE(SUM(tokens_used), 0) as t FROM agent_episodes WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours]
            )?->t ?? 0);

            return ['completed' => $completed, 'errors' => $errors, 'tokens' => $tokens];
        } catch (\Throwable) {
            return ['completed' => 0, 'errors' => 0, 'tokens' => 0];
        }
    }

    private function getAgentProductivity(int $hours): array
    {
        try {
            $sessions = DB::select("
                SELECT agent_name AS agent_id, COUNT(*) AS sessions,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                       SUM(CASE WHEN status IN ('failed','expired') THEN 1 ELSE 0 END) AS failed,
                       SUM(CASE
                           WHEN status = 'completed'
                            AND agent_name IN ('ai-ops', 'system-guardian', 'log-analyst')
                            AND COALESCE(message_count, 0) = 0
                            AND COALESCE(total_tokens, 0) = 0
                           THEN 1 ELSE 0
                       END) AS pre_screened_completed
                FROM agent_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR) AND agent_name IS NOT NULL AND agent_name != ''
                GROUP BY agent_name
            ", [$hours]);

            $reviewMap = [];
            foreach (DB::select('SELECT agent_id, COUNT(*) AS items FROM agent_review_queue WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR) GROUP BY agent_id', [$hours]) as $r) {
                $reviewMap[$r->agent_id] = (int) $r->items;
            }
            $pendingMap = [];
            foreach (DB::select("SELECT agent_id, COUNT(*) AS pending FROM agent_review_queue WHERE status = 'pending' GROUP BY agent_id") as $p) {
                $pendingMap[$p->agent_id] = (int) $p->pending;
            }

            $toolStats = [];
            foreach (DB::select("
                SELECT s.agent_name AS agent_id, s.status, e.session_id, e.details
                FROM agent_episodes e
                JOIN agent_sessions s ON s.session_id = e.session_id
                WHERE e.event_type = 'tool_call'
                  AND e.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                  AND s.agent_name IS NOT NULL
                  AND s.agent_name != ''
            ", [$hours]) as $toolCall) {
                $agentId = $toolCall->agent_id;
                $toolStats[$agentId] ??= [
                    'successful_tool_calls' => 0,
                    'productive_sessions' => [],
                ];

                $details = json_decode($toolCall->details ?? '{}', true) ?: [];
                if (($details['success'] ?? false) !== true) {
                    continue;
                }

                $toolStats[$agentId]['successful_tool_calls']++;
                if (($toolCall->status ?? null) === 'completed') {
                    $toolStats[$agentId]['productive_sessions'][$toolCall->session_id] = true;
                }
            }

            $agents = [];
            foreach ($sessions as $s) {
                $productiveSessions = count($toolStats[$s->agent_id]['productive_sessions'] ?? []);
                $completed = (int) $s->completed;
                $preScreenedCompleted = min($completed, (int) ($s->pre_screened_completed ?? 0));
                $yieldEligibleCompleted = max(0, $completed - $preScreenedCompleted);
                $agents[] = [
                    'agent_id' => $s->agent_id,
                    'sessions' => (int) $s->sessions,
                    'completed' => $completed,
                    'pre_screened_completed' => $preScreenedCompleted,
                    'yield_eligible_completed' => $yieldEligibleCompleted,
                    'failed' => (int) $s->failed,
                    'review_items' => $reviewMap[$s->agent_id] ?? 0,
                    'successful_tool_calls' => $toolStats[$s->agent_id]['successful_tool_calls'] ?? 0,
                    'productive_sessions' => $productiveSessions,
                    'yield_rate' => $yieldEligibleCompleted > 0 ? (int) round(($productiveSessions / $yieldEligibleCompleted) * 100) : 0,
                    'pending_queue' => $pendingMap[$s->agent_id] ?? 0,
                ];
            }
            usort($agents, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

            return ['agents' => $agents];
        } catch (\Throwable) {
            return ['agents' => []];
        }
    }

    private function identifyZeroYieldAgents(array $agents): array
    {
        $yieldTracked = [
            'genealogy-researcher', 'factcheck-ops', 'research-analyst',
            'data-removal-ops', 'youtube-ops', 'knowledge-curator',
            'log-analyst', 'file-curator',
        ];

        $zeroYield = [];
        foreach ($agents as $agent) {
            if (! in_array($agent['agent_id'], $yieldTracked, true)) {
                continue;
            }

            // Zero-yield is only meaningful when an agent actually completed
            // LLM/tool work. Monitoring pre-screen all-clear sessions complete
            // without messages, tokens, or tool calls by design.
            $yieldEligibleCompleted = (int) ($agent['yield_eligible_completed'] ?? ($agent['completed'] ?? 0));
            if ($yieldEligibleCompleted <= 0) {
                continue;
            }

            $hasToolWork = ($agent['successful_tool_calls'] ?? 0) > 0;
            $hasReviewOutput = ($agent['review_items'] ?? 0) > 0;

            if (! $hasToolWork && ! $hasReviewOutput) {
                $zeroYield[] = $agent;
            }
        }

        return $zeroYield;
    }

    private function getAlerts(int $hours): array
    {
        try {
            $rows = DB::select(
                'SELECT severity, COUNT(*) as c FROM system_alerts WHERE triggered_at > DATE_SUB(NOW(), INTERVAL ? HOUR) GROUP BY severity',
                [$hours]
            );
            $counts = ['critical' => 0, 'high' => 0, 'normal' => 0, 'total' => 0];
            foreach ($rows as $r) {
                $sev = strtolower($r->severity);
                $counts[$sev] = ($counts[$sev] ?? 0) + (int) $r->c;
                $counts['total'] += (int) $r->c;
            }

            return $counts;
        } catch (\Throwable) {
            return ['critical' => 0, 'high' => 0, 'normal' => 0, 'total' => 0];
        }
    }

    private function getReviewQueue(): array
    {
        try {
            return DB::select(
                "SELECT rtr.label, COALESCE(counts.c, 0) as pending
                 FROM review_type_registry rtr
                 LEFT JOIN (SELECT review_type, COUNT(*) as c FROM agent_review_queue WHERE status = 'pending' GROUP BY review_type) counts
                 ON counts.review_type = rtr.name
                 WHERE rtr.enabled = 1 ORDER BY pending DESC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function getPipelineBacklog(): array
    {
        $imgExts = "'".implode("','", config('file_types.image'))."'";
        $docExts = "'".implode("','", config('file_types.document'))."'";

        $q = fn (string $sql) => $this->safeQuery(fn (string $s) => (int) (DB::selectOne($s)?->c ?? 0), $sql);

        $imgTotal = $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imgExts})");
        $docTotal = $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$docExts})");

        return [
            'ai_img' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND (ai_analysis_version IS NULL OR (ai_analysis_version NOT IN ('skipped','processing') AND ai_analysis_version NOT LIKE 'fail:%')) AND extension IN ({$imgExts})"),
                'total' => $imgTotal,
            ],
            'ai_doc' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND (ai_analysis_version IS NULL OR (ai_analysis_version NOT IN ('skipped','processing') AND ai_analysis_version NOT LIKE 'fail:%')) AND extension IN ({$docExts})"),
                'total' => $docTotal,
            ],
            'faces' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imgExts})"),
                'total' => $imgTotal,
            ],
            'phash' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry fr WHERE fr.status = 'active' AND fr.extension IN ({$imgExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)"),
                'total' => $imgTotal,
            ],
            'thumbs' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL AND extension IN ({$imgExts},'mp4','mov','avi','mkv','webm','wmv','m4v','flv','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp')"),
                'total' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imgExts},'mp4','mov','avi','mkv','webm','wmv','m4v','flv','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp')"),
            ],
            'exif' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (exif_checked IS NULL OR exif_checked = 0) AND extension IN ({$imgExts})"),
                'total' => $imgTotal,
            ],
            'rag' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND rag_indexed_at IS NULL AND (extension IN ({$docExts},'md','html','htm') OR (extension IN ({$imgExts}) AND ai_description IS NOT NULL))"),
                'total' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (extension IN ({$docExts},'md','html','htm') OR (extension IN ({$imgExts}) AND ai_description IS NOT NULL))"),
            ],
            'writeback' => [
                'pending' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imgExts}) AND date_taken IS NOT NULL AND date_taken_source NOT LIKE 'exif_%' AND (exif_written IS NULL OR exif_written = 0) AND date_taken_confidence >= 0.3"),
                'total' => $q("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imgExts}) AND date_taken IS NOT NULL"),
            ],
        ];
    }

    private function getRagBacklogOverview(): array
    {
        return $this->ragBacklogService->getDigestMetrics();
    }

    private function formatEtaDays(float $etaDays): string
    {
        if ($etaDays < 1.0) {
            return 'under 1 day';
        }

        return '~'.number_format($etaDays, 1).' days';
    }

    private function getGenealogyDigest(): array
    {
        $defaults = [
            'last_run' => 'error', 'pending_review' => 0,
            'tree_id' => '?', 'media_total' => 0, 'face_link' => '0/0',
            'enrich_pending' => 0, 'htr_pending' => 0,
            'total_persons' => 0, 'never_searched' => 0,
            'searched_24h' => 0, 'exhausted' => 0,
        ];
        try {
            $lastSession = DB::selectOne("SELECT status, created_at FROM agent_sessions WHERE agent_name = 'genealogy-researcher' ORDER BY created_at DESC LIMIT 1");
            $pendingReview = (int) (DB::selectOne("SELECT COUNT(*) as c FROM agent_review_queue WHERE review_type LIKE 'genealogy%' AND status = 'pending'")?->c ?? 0);
            try {
                $pendingReview += (int) (DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) as c FROM research_facts f JOIN research_missions m ON m.id = f.mission_id WHERE f.review_status = 'pending' AND m.domain_category = 'genealogy'")?->c ?? 0);
            } catch (\Throwable) {
            }

            if (! $lastSession) {
                $lastRun = 'never';
            } else {
                $lastRunAt = now()->parse($lastSession->created_at);
                $status = $lastSession->status === 'completed' ? 'OK' : strtoupper($lastSession->status);
                $lastRun = $lastRunAt->format('M d g:iA').' '.$status;
            }

            $mediaStat = DB::selectOne('SELECT tree_id, COUNT(*) as total FROM genealogy_media GROUP BY tree_id ORDER BY total DESC LIMIT 1');
            $treeId = $mediaStat->tree_id ?? '?';

            $coverage = DB::selectOne(
                'SELECT COUNT(*) AS total_persons, SUM(CASE WHEN last_searched_at IS NULL THEN 1 ELSE 0 END) AS never_searched,
                        SUM(CASE WHEN last_searched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS searched_24h,
                        SUM(CASE WHEN research_exhaustion_score >= 0.90 THEN 1 ELSE 0 END) AS exhausted
                 FROM genealogy_person_coverage WHERE tree_id = ?',
                [$treeId !== '?' ? (int) $treeId : 0]
            );

            return [
                'last_run' => $lastRun, 'pending_review' => $pendingReview,
                'tree_id' => $treeId, 'media_total' => (int) ($mediaStat->total ?? 0),
                'face_link' => '—', 'enrich_pending' => 0, 'htr_pending' => 0,
                'total_persons' => (int) ($coverage->total_persons ?? 0),
                'never_searched' => (int) ($coverage->never_searched ?? 0),
                'searched_24h' => (int) ($coverage->searched_24h ?? 0),
                'exhausted' => (int) ($coverage->exhausted ?? 0),
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function getRssHealth(): array
    {
        try {
            $row = DB::selectOne("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'healthy' THEN 1 ELSE 0 END) as healthy, SUM(CASE WHEN consecutive_failures >= 3 THEN 1 ELSE 0 END) as failing FROM rss_feed_health");

            return ['total' => (int) ($row->total ?? 0), 'healthy' => (int) ($row->healthy ?? 0), 'failing' => (int) ($row->failing ?? 0)];
        } catch (\Throwable) {
            return ['total' => 0, 'healthy' => 0, 'failing' => 0];
        }
    }

    /**
     * Routing snapshot for Message 1:
     *   - active policy profile (red if not 'default')
     *   - offline_mode kill switch (red if active)
     *   - circuit-open / half-open provider count + names
     *
     * Pushes any degraded state into $this->issues so Message 5 surfaces it
     * alongside the other alerts.
     *
     * @return string[] Pre-formatted lines for the digest; empty array on
     *                  total lookup failure (never blocks the report).
     */
    private function getRoutingSnapshot(int $hours = 24): array
    {
        try {
            $policy = app(OfflinePolicyService::class);
            $profile = $policy->activeProfile();
            $offline = $policy->isOfflineModeActive();
        } catch (\Throwable $e) {
            Log::warning('ops:daily-report: routing lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        $lines = [];

        $profileTag = $profile === 'default'
            ? $this->c(self::GREEN, $profile)
            : $this->c(self::YELLOW, $profile);
        $lines[] = '  Profile          '.$profileTag;
        if ($profile !== 'default') {
            $this->issues[] = "Routing profile is '{$profile}' (not default)";
        }

        $offlineTag = $offline
            ? $this->c(self::RED, 'ACTIVE')
            : $this->c(self::GREEN, 'off');
        $lines[] = '  Offline Mode     '.$offlineTag;
        if ($offline) {
            $this->issues[] = 'Offline mode kill switch is ACTIVE — external LLMs blocked';
        }

        try {
            $audit = app(OfflineAuditService::class)->summarizeWindow($hours);
            if (($audit['result'] ?? null) === 'ok') {
                $denied = (int) ($audit['denied'] ?? 0);
                $deniedPerHour = (float) ($audit['denied_per_hour'] ?? 0.0);
                $lines[] = '  Offline Denies   '.($denied > 0
                    ? $this->c(self::YELLOW, number_format($denied).' in '.$hours.'h ('.$deniedPerHour.'/h)')
                    : $this->c(self::GREEN, '0 in '.$hours.'h'));

                $topDenials = $audit['top_denials'] ?? [];
                if ($denied > 0 && is_array($topDenials) && $topDenials !== []) {
                    $top = array_slice(array_map(
                        static fn (array $row) => ($row['operation'] ?? 'unknown').'='.($row['count'] ?? 0),
                        $topDenials
                    ), 0, 3);
                    $lines[] = '  Denial Top       '.implode(', ', $top);
                    $this->issues[] = number_format($denied).' offline policy denial(s) in last '.$hours.'h';
                }
            }
        } catch (\Throwable $e) {
            Log::debug('ops:daily-report: offline audit summary failed', ['error' => $e->getMessage()]);
        }

        try {
            $circuits = DB::select(
                "SELECT instance_name, circuit_state
                 FROM llm_instances
                 WHERE is_active = 1 AND circuit_state IN ('open', 'half_open')
                 ORDER BY circuit_state DESC, instance_name"
            );
        } catch (\Throwable $e) {
            $circuits = [];
        }

        if ($circuits === []) {
            $lines[] = '  Circuits         '.$this->c(self::GREEN, 'all closed');
        } else {
            $open = array_values(array_filter($circuits, static fn ($r) => $r->circuit_state === 'open'));
            $half = array_values(array_filter($circuits, static fn ($r) => $r->circuit_state === 'half_open'));
            $label = $this->c(self::RED, count($open).' open').
                (count($half) > 0 ? '  '.$this->c(self::YELLOW, count($half).' half-open') : '');
            $lines[] = '  Circuits         '.$label;
            $names = array_map(
                static fn ($r) => $r->instance_name.($r->circuit_state === 'half_open' ? ' (half)' : ''),
                $circuits,
            );
            $lines[] = '    '.implode(', ', $names);
            if ($open !== []) {
                $this->issues[] = count($open).' LLM provider(s) circuit-open: '.
                    implode(', ', array_map(static fn ($r) => $r->instance_name, $open));
            }
        }

        return $lines;
    }

    /**
     * Ollama drift snapshot for the ROUTING section.
     *
     * The scheduled `ollama:drift-check` job runs daily at 05:30 with
     * --no-fail (reporting-only, never blocks the digest). Without this
     * method the digest was silent on drift even though the migration
     * promised it would surface here. We call OllamaModelRegistryService
     * directly rather than parsing scheduled_job_runs.output so the
     * snapshot reflects live state at digest-build time.
     *
     * Categories surfaced:
     *   - phantom (in DB, not in live)  → red, push to $this->issues[]
     *   - informational (in live, not in DB) → yellow only
     *   - unreachable host → yellow
     *   - clean → green
     *
     * Returns [] on total service failure so the digest never breaks
     * because of drift-check infrastructure.
     *
     * @return string[]
     */
    private function getOllamaDriftLines(): array
    {
        try {
            $report = app(OllamaModelRegistryService::class)->driftCheck();
        } catch (\Throwable $e) {
            Log::debug('ops:daily-report: ollama drift check failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ($report === []) {
            return [];
        }

        $lines = [];
        $phantomHosts = [];
        $infoHosts = [];
        $totalPhantom = 0;
        $totalInfo = 0;
        $unreachable = [];

        foreach ($report as $row) {
            $instance = (string) ($row['instance_id'] ?? 'unknown');

            if (! empty($row['unreachable'])) {
                $unreachable[] = $instance;

                continue;
            }

            $phantom = is_array($row['in_db_not_in_live'] ?? null) ? $row['in_db_not_in_live'] : [];
            $info = is_array($row['in_live_not_in_db'] ?? null) ? $row['in_live_not_in_db'] : [];

            $totalPhantom += count($phantom);
            $totalInfo += count($info);
            if ($phantom !== []) {
                $phantomHosts[] = $instance.' ('.count($phantom).')';
            }
            if ($info !== []) {
                $infoHosts[] = $instance.' ('.count($info).')';
            }
        }

        if ($totalPhantom === 0 && $totalInfo === 0 && $unreachable === []) {
            $lines[] = '  Ollama Drift     '.$this->c(self::GREEN, 'clean');

            return $lines;
        }

        if ($totalPhantom > 0) {
            $lines[] = '  Ollama Drift     '.$this->c(
                self::RED,
                $totalPhantom.' phantom model(s) — DB lying, routing will fail'
            );
            $lines[] = '    phantom on: '.implode(', ', $phantomHosts);
            $this->issues[] = $totalPhantom.' phantom Ollama model(s) — DB has rows for models not on live host(s): '.
                implode(', ', $phantomHosts);
        } elseif ($totalInfo > 0) {
            $lines[] = '  Ollama Drift     '.$this->c(
                self::YELLOW,
                $totalInfo.' new live model(s) not yet in DB (informational)'
            );
            $lines[] = '    live-only on: '.implode(', ', $infoHosts);
        }

        if ($unreachable !== []) {
            $lines[] = '  Ollama Drift     '.$this->c(
                self::YELLOW,
                count($unreachable).' host(s) unreachable: '.implode(', ', $unreachable)
            );
            $this->issues[] = 'Ollama host(s) unreachable for drift probe: '.implode(', ', $unreachable);
        }

        return $lines;
    }

    /**
     * Recovery activity snapshot — dead-PID reconciliations in the window.
     *
     * reconcileDeadRunningJobs() writes an `[AUTO-RECONCILED] PID N dead` marker
     * into scheduled_job_runs.output when it closes a crashed worker. We
     * query for that marker to tell the operator how many self-heals ran
     * overnight (signal for both silent success and a possibly sick host if
     * the count is spiking).
     *
     * Pushes a warn-level issue when reconciliations exceed 5 in the window,
     * which is usually a symptom of GPU/OOM churn rather than normal
     * scheduler hygiene.
     *
     * @return string[] Pre-formatted lines; empty on total query failure.
     */
    private function getRecoveryActivity(int $hours): array
    {
        try {
            // No SQL LIMIT here — $total and the distinct job count must
            // reflect the FULL aggregate, not just the top-N display row.
            // GROUP BY sj.name caps the row count at the number of jobs
            // that reconciled in the window (realistically < 50 even
            // during heavy GPU/OOM churn), so unbounded is safe.
            $rows = DB::select(
                "SELECT sj.name, COUNT(*) AS n, MAX(sjr.completed_at) AS latest
                 FROM scheduled_job_runs sjr
                 JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                 WHERE sjr.output LIKE '%[AUTO-RECONCILED]%'
                   AND sjr.completed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                 GROUP BY sj.name
                 ORDER BY n DESC, latest DESC",
                [$hours]
            );
        } catch (\Throwable $e) {
            Log::debug('ops:daily-report: recovery activity query failed', ['error' => $e->getMessage()]);

            return [];
        }

        $total = array_sum(array_map(static fn ($r) => (int) $r->n, $rows));
        if ($total === 0) {
            // No silent self-heals in the window — still show the all-clear
            // so the operator knows the signal is wired, not broken.
            return ['  Dead-PID reconciles  '.$this->c(self::GREEN, '0 in last '.$hours.'h')];
        }

        $lines = [];
        $totalColor = $total > 5 ? self::RED : ($total > 2 ? self::YELLOW : self::GREEN);
        $lines[] = sprintf(
            '  Dead-PID reconciles  %s',
            $this->c($totalColor, $total.' in last '.$hours.'h across '.count($rows).' job(s)')
        );

        $names = array_map(
            static fn ($r) => $r->name.' (×'.(int) $r->n.')',
            $rows
        );
        $lines[] = '    '.implode(', ', array_slice($names, 0, 6));

        if ($total > 5) {
            $this->issues[] = $total.' dead-PID reconciles in last '.$hours.'h — possible host churn';
        }

        return $lines;
    }

    private function getLLMUsageExpanded(int $hours): array
    {
        try {
            $rows = DB::select(
                'SELECT instance_name, total_requests, circuit_state, avg_response_ms
                 FROM llm_instances WHERE is_active = 1 AND total_requests > 0 ORDER BY total_requests DESC'
            );
            if (empty($rows)) {
                return [];
            }

            $parts = [];
            foreach ($rows as $r) {
                $reqs = (int) $r->total_requests;
                if ($reqs < 1) {
                    continue;
                }
                $name = str_pad($r->instance_name, 22);
                $circuitFlag = $r->circuit_state !== 'closed' ? $this->c(self::RED, ' OPEN') : '';
                $latency = $r->avg_response_ms > 5000 ? $this->c(self::YELLOW, '  '.round($r->avg_response_ms / 1000, 1).'s avg') : '';
                $parts[] = "  {$name}".number_format($reqs)." calls{$circuitFlag}{$latency}";
            }

            return $parts;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getServiceStatus(): array
    {
        $result = ['redis' => null, 'horizon' => null, 'ollama_primary' => false, 'ollama_secondary' => false];
        $ollamaUrls = $this->getOllamaServiceUrls();

        try {
            $redis = Redis::connection();
            $redis->client()->setOption(\Redis::OPT_READ_TIMEOUT, 5);
            $info = $redis->info('memory');
            $usedMb = round(($info['used_memory'] ?? 0) / 1048576);
            $result['redis'] = "{$usedMb}MB";
        } catch (\Throwable) {
        }

        try {
            $pgrep = \Illuminate\Support\Facades\Process::timeout(5)->run(['pgrep', '-fc', 'horizon:work']);
            $out = trim($pgrep->output());
            $result['horizon'] = $out !== '' ? (int) $out : null;
        } catch (\Throwable) {
        }

        // Primary Ollama
        try {
            if (! empty($ollamaUrls[0])) {
                $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                $resp = @file_get_contents(rtrim($ollamaUrls[0], '/').'/api/tags', false, $ctx);
                if ($resp) {
                    $count = count(json_decode($resp, true)['models'] ?? []);
                    $result['ollama_primary'] = "{$count} models";
                }
            }
        } catch (\Throwable) {
        }
        if (! $result['ollama_primary']) {
            $this->issues[] = 'Ollama Primary down';
        }

        // Secondary Ollama
        try {
            if (! empty($ollamaUrls[1])) {
                $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                $resp = @file_get_contents(rtrim($ollamaUrls[1], '/').'/api/tags', false, $ctx);
                if ($resp) {
                    $count = count(json_decode($resp, true)['models'] ?? []);
                    $result['ollama_secondary'] = "{$count} models";
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    private function getOllamaServiceUrls(): array
    {
        try {
            $urls = collect(DB::select("
                SELECT base_url
                FROM llm_instances
                WHERE instance_type = 'ollama' AND is_active = 1
                ORDER BY priority ASC
                LIMIT 2
            "))
                ->pluck('base_url')
                ->filter(fn ($url) => is_string($url) && $url !== '')
                ->values()
                ->all();

            if (! empty($urls)) {
                return $urls;
            }
        } catch (\Throwable) {
        }

        return [config('services.ollama.api_url', 'http://127.0.0.1:11434')];
    }

    private function getGpuStatus(): ?string
    {
        try {
            $out = \Illuminate\Support\Facades\Process::timeout(5)->run([
                'nvidia-smi',
                '--query-gpu=name,memory.used,memory.total',
                '--format=csv,noheader',
            ])->output();

            return $out ? trim($out) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getUptime(): string
    {
        try {
            $out = Process::timeout(5)->run(['uptime', '-p'])->output();

            return $out ? trim(str_replace('up ', '', $out)) : '?';
        } catch (\Throwable) {
            return '?';
        }
    }

    private function freeSpace(string $path): string
    {
        try {
            $free = @disk_free_space($path);
            if (! $free) {
                return '?';
            }

            return round($free / 1073741824).' GB';
        } catch (\Throwable) {
            return '?';
        }
    }

    private function getPipelineThroughput(int $hours): array
    {
        $result = ['ai' => 0, 'faces' => 0, 'phash' => 0];
        $jobs = ['ai' => 'file_enrich_ai', 'faces' => 'file_enrich_faces', 'phash' => 'file_enrich_phash'];
        foreach ($jobs as $key => $jobName) {
            try {
                $row = DB::selectOne(
                    'SELECT COALESCE(SUM(r.items_processed), 0) as total
                     FROM scheduled_job_runs r JOIN scheduled_jobs j ON j.id = r.scheduled_job_id
                     WHERE j.name = ? AND r.completed_at > DATE_SUB(NOW(), INTERVAL ? HOUR)',
                    [$jobName, $hours]
                );
                $result[$key] = (int) ($row->total ?? 0);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    private function getRLMStats(int $hours): array
    {
        $default = ['total_calls' => 0, 'total_tokens' => 0, 'local_pct' => 0, 'move_ons' => 0, 'disabled_services' => 0, 'disabled_names' => ''];
        try {
            $stats = DB::selectOne('SELECT COUNT(*) as total_calls, COALESCE(SUM(tokens_used), 0) as total_tokens, SUM(CASE WHEN move_on_triggered = 1 THEN 1 ELSE 0 END) as move_ons FROM agent_recursion_calls WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours]);
            $effectiveness = DB::selectOne('SELECT COALESCE(AVG(local_provider_pct), 0) as avg_local_pct FROM recursion_effectiveness WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours]);
            $disabled = DB::select('SELECT service_name FROM recursion_config WHERE enabled = 0 AND disabled_reason IS NOT NULL');

            return [
                'total_calls' => (int) ($stats->total_calls ?? 0),
                'total_tokens' => (int) ($stats->total_tokens ?? 0),
                'local_pct' => round((float) ($effectiveness->avg_local_pct ?? 0), 1),
                'move_ons' => (int) ($stats->move_ons ?? 0),
                'disabled_services' => count($disabled),
                'disabled_names' => implode(', ', array_map(fn ($d) => $d->service_name, $disabled)),
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function getBackupStatus(): array
    {
        $backupPath = storage_path('backups');
        $mysqlInfo = null;
        $pgInfo = null;

        if (is_dir($backupPath)) {
            $files = scandir($backupPath);
            rsort($files);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || str_ends_with($file, '.failed')) {
                    continue;
                }
                $fullPath = $backupPath.'/'.$file;
                $sizeGb = round(filesize($fullPath) / 1073741824, 1);
                $sizeStr = $sizeGb >= 1 ? "{$sizeGb} GB" : round(filesize($fullPath) / 1048576).' MB';

                if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{6})/', $file, $m)) {
                    $backupTime = strtotime($m[1].' '.substr($m[2], 0, 2).':'.substr($m[2], 2, 2));
                    $ageH = $backupTime ? (int) round((time() - $backupTime) / 3600) : null;
                    $dateStr = date('M d g:iA', $backupTime);
                } else {
                    continue;
                }

                if (str_starts_with($file, 'mysql_backup') && $mysqlInfo === null) {
                    $mysqlInfo = [
                        'age_hours' => $ageH,
                        'size' => $sizeStr,
                        'date' => $dateStr,
                    ];
                }
                if (str_starts_with($file, 'postgres_backup') && $pgInfo === null) {
                    $pgInfo = [
                        'age_hours' => $ageH,
                        'size' => $sizeStr,
                        'date' => $dateStr,
                    ];
                }
            }
        }

        $runtimeState = $this->detectBackupRuntimeState();

        return $this->summarizeBackupStatus($mysqlInfo, $pgInfo, $runtimeState);
    }

    private function summarizeBackupStatus(?array $mysqlInfo, ?array $pgInfo, ?string $runtimeState = null): array
    {
        $maxAgeHours = (int) config('app.backup_max_age_hours', 25);

        $mysqlState = $this->classifyBackupState($mysqlInfo, $maxAgeHours);
        $pgState = $this->classifyBackupState($pgInfo, $maxAgeHours);

        $running = false;
        $detail = [];

        if ($runtimeState === 'mysql_running') {
            $running = true;
            $mysqlState = 'running';
            if ($pgState === 'stale') {
                $pgState = 'pending';
            }
            $detail[] = 'Nightly backup in progress: MySQL active, PostgreSQL pending';
        } elseif ($runtimeState === 'postgres_running') {
            $running = true;
            $pgState = 'running';
            $detail[] = 'Nightly backup in progress: PostgreSQL active';
        }

        if (! $running) {
            if ($mysqlState !== 'healthy') {
                $detail[] = 'MySQL '.$this->describeBackupAge($mysqlInfo);
            }
            if ($pgState !== 'healthy') {
                $detail[] = 'PostgreSQL '.$this->describeBackupAge($pgInfo);
            }
        }

        $healthy = ! $running && $mysqlState === 'healthy' && $pgState === 'healthy';

        return [
            'healthy' => $healthy,
            'running' => $running,
            'detail' => implode('; ', $detail) ?: 'Good',
            'mysql_size' => $mysqlInfo['size'] ?? 'NONE',
            'mysql_date' => $mysqlInfo['date'] ?? 'NONE',
            'mysql_label' => $this->backupStateLabel($mysqlState),
            'mysql_color' => $this->backupStateColor($mysqlState),
            'pg_size' => $pgInfo['size'] ?? 'NONE',
            'pg_date' => $pgInfo['date'] ?? 'NONE',
            'pg_label' => $this->backupStateLabel($pgState),
            'pg_color' => $this->backupStateColor($pgState),
        ];
    }

    private function classifyBackupState(?array $info, int $maxAgeHours): string
    {
        if ($info === null) {
            return 'missing';
        }

        $age = $info['age_hours'] ?? null;
        if ($age === null) {
            return 'missing';
        }

        return $age < $maxAgeHours ? 'healthy' : 'stale';
    }

    private function describeBackupAge(?array $info): string
    {
        if ($info === null || ! isset($info['age_hours'])) {
            return 'NONE';
        }

        return "{$info['age_hours']}h old";
    }

    private function backupStateLabel(string $state): string
    {
        return match ($state) {
            'healthy' => '[GOOD]',
            'running' => '[RUNNING]',
            'pending' => '[PENDING]',
            'stale' => '[STALE]',
            default => '[NONE]',
        };
    }

    private function backupStateColor(string $state): string
    {
        return match ($state) {
            'healthy' => self::GREEN,
            'running', 'pending' => self::YELLOW,
            'stale', 'missing' => self::RED,
            default => self::RED,
        };
    }

    private function detectBackupRuntimeState(): ?string
    {
        $processes = Process::timeout(5)->run([
            'ps',
            '-ef',
        ])->output();

        if (str_contains($processes, 'pg_dump')) {
            return 'postgres_running';
        }

        if (str_contains($processes, 'mysqldump')) {
            return 'mysql_running';
        }

        return null;
    }

    // =========================================================================
    // Infrastructure helpers
    // =========================================================================

    private function diskPct(string $path): int
    {
        $oldAlarm = 0;
        if (function_exists('pcntl_alarm')) {
            $oldAlarm = pcntl_alarm(10);
        }
        try {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm($oldAlarm);
            }
        }
        if (! $total || $total == 0) {
            return 0;
        }

        return (int) round(($total - $free) / $total * 100);
    }

    private function getMemory(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (! $meminfo) {
            return ['total_gb' => '?', 'used_gb' => '?'];
        }
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);

        return [
            'total_gb' => round((int) ($total[1] ?? 0) / 1048576),
            'used_gb' => round(((int) ($total[1] ?? 0) - (int) ($avail[1] ?? 0)) / 1048576),
        ];
    }

    private function safeQuery(callable $fn, string $sql = ''): int
    {
        try {
            return $fn($sql);
        } catch (\Throwable $e) {
            Log::warning('MorningDigest: query failed', ['sql' => mb_substr($sql, 0, 100), 'error' => $e->getMessage()]);

            return -1;
        }
    }

    private function queueLen(string $queueName): int
    {
        try {
            $conn = config('queue.connections.redis.connection', 'default');
            $redis = Redis::connection($conn);
            $redis->client()->setOption(\Redis::OPT_READ_TIMEOUT, 5);

            return (int) ($redis->llen("queues:{$queueName}") ?? 0);
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * @return array<string, int>
     */
    private function getActiveQueueDepths(): array
    {
        $queueNames = array_values(array_unique(array_filter([
            config('queue.connections.redis.queue', 'default'),
            'high',
            'default',
            'low',
            'long-running',
            'workflow',
            'speculative',
        ])));

        $depths = [];
        foreach ($queueNames as $queueName) {
            $depths[$queueName] = $this->queueLen($queueName);
        }

        return $depths;
    }

    private function shortNum(int $n): string
    {
        if ($n < 0) {
            return 'ERR';
        }
        if ($n >= 1_000_000) {
            return round($n / 1_000_000, 1).'M';
        }
        if ($n >= 1_000) {
            return round($n / 1_000, 1).'K';
        }

        return (string) $n;
    }

    private function throughputKey(string $key): string
    {
        return match ($key) {
            'ai_img' => 'ai', default => $key
        };
    }

    private function formatJobIssueLabel(object $job): string
    {
        $name = (string) ($job->name ?? 'unknown_job');
        $category = $this->extractJobReportCategory($job);

        if (! $category) {
            return $name;
        }

        return "[{$category}] {$name}";
    }

    private function extractJobReportCategory(object $job): ?string
    {
        $notes = $job->notes ?? null;
        if (! is_string($notes) || trim($notes) === '') {
            return null;
        }

        $decoded = json_decode($notes, true);
        if (! is_array($decoded)) {
            return null;
        }

        $runtime = $decoded['runtime'] ?? null;
        $category = null;

        if (is_array($runtime)) {
            $category = $runtime['report_category'] ?? $runtime['category'] ?? null;
        }

        $category ??= $decoded['report_category'] ?? $decoded['category'] ?? null;

        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        return trim($category);
    }

    /**
     * INF-11a: Save daily pipeline metrics snapshot for velocity tracking.
     */
    private function savePipelineSnapshot(): void
    {
        try {
            $pipeline = array_merge($this->getPipelineBacklog(), $this->getRagSnapshotBacklog());
            $today = now()->toDateString();
            foreach ($pipeline as $name => $data) {
                $pending = $data['pending'] ?? 0;
                $total = $data['total'] ?? 0;
                $pct = $total > 0 ? round((($total - $pending) / $total) * 100, 2) : 0;
                $prev = DB::selectOne('SELECT pending FROM pipeline_metrics_snapshots WHERE pipeline = ? AND snapshot_date < ? ORDER BY snapshot_date DESC LIMIT 1', [$name, $today]);
                $delta = $prev ? ($pending - $prev->pending) : null;
                DB::insert('INSERT IGNORE INTO pipeline_metrics_snapshots (snapshot_date, pipeline, pending, total, completion_pct, delta_from_prev) VALUES (?, ?, ?, ?, ?, ?)',
                    [$today, $name, $pending, $total, $pct, $delta]);
            }
        } catch (\Throwable $e) {
            Log::warning('Pipeline snapshot failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function getRagSnapshotBacklog(): array
    {
        try {
            $metrics = $this->getRagBacklogOverview();
            if (! empty($metrics['evidence_errors'])) {
                return [];
            }

            $documents = (int) ($metrics['documents'] ?? 0);

            return [
                'kg_fresh' => [
                    'pending' => (int) ($metrics['kg']['fresh'] ?? 0),
                    'total' => $documents,
                ],
                'kg_stale' => [
                    'pending' => (int) ($metrics['kg']['stale'] ?? 0),
                    'total' => $documents,
                ],
                'raptor' => [
                    'pending' => (int) ($metrics['raptor']['pending'] ?? 0),
                    'total' => $documents,
                ],
                'sentence' => [
                    'pending' => (int) ($metrics['sentence']['pending'] ?? 0),
                    'total' => $documents,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('RAG pipeline snapshot failed (non-fatal)', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
